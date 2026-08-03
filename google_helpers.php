<?php

require_once __DIR__ . '/mail_helpers.php';
require_once __DIR__ . '/payment_helpers.php';

function google_get_settings($mysqli)
{
    ensure_admin_notification_email_column($mysqli);
    $tenant_id = current_tenant_id();

    $res = $mysqli->query("
        SELECT app_name, smtp_from_name, google_refresh_token, google_connected_email,
               google_redirect_uri, google_calendar_enabled, google_calendar_id
        FROM payment_settings
        WHERE tenant_id = $tenant_id
    ");

    $settings = $res->fetch_assoc() ?: [];
    $settings['google_client_id'] = google_oauth_client_id();
    $settings['google_client_secret'] = google_oauth_client_secret();

    return $settings;
}

function google_oauth_client_id()
{
    return defined('GOOGLE_OAUTH_CLIENT_ID') ? trim((string) GOOGLE_OAUTH_CLIENT_ID) : '';
}

function google_oauth_client_secret()
{
    return defined('GOOGLE_OAUTH_CLIENT_SECRET') ? trim((string) GOOGLE_OAUTH_CLIENT_SECRET) : '';
}

function google_oauth_credentials_configured()
{
    return google_oauth_client_id() !== '' && google_oauth_client_secret() !== '';
}

function google_default_redirect_uri()
{
    return google_oauth_base_url() . 'google_oauth_callback.php';
}

function google_oauth_base_url()
{
    $configured = defined('GOOGLE_OAUTH_BASE_URL') ? trim((string) GOOGLE_OAUTH_BASE_URL) : '';
    if ($configured !== '') {
        return rtrim($configured, '/') . '/';
    }

    return google_current_origin() . google_app_base_url_path();
}

function google_current_origin()
{
    $forwarded_proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $protocol = ($forwarded_proto === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? null) == 443) ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . $host;
}

function google_app_base_url_path()
{
    $base_path = function_exists('tenant_app_base_path') ? tenant_app_base_path() : (defined('APP_BASE_PATH') ? APP_BASE_PATH : '');
    $base_path = trim((string) $base_path, '/');
    return '/' . ($base_path !== '' ? $base_path . '/' : '');
}

function google_tenant_dashboard_url()
{
    $tenant_key = function_exists('current_tenant_key') ? current_tenant_key() : '';
    $tenant_key = trim((string) $tenant_key, '/');
    if (function_exists('tenant_canonical_base_url')) {
        return tenant_canonical_base_url() . 'dashboard.php';
    }
    return google_current_origin() . google_app_base_url_path() . ($tenant_key !== '' ? rawurlencode($tenant_key) . '/' : '') . 'dashboard.php';
}

function google_redirect_uri($settings)
{
    return google_default_redirect_uri();
}

function google_scopes()
{
    return [
        'https://www.googleapis.com/auth/gmail.send',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/userinfo.email'
    ];
}

function google_build_auth_url($mysqli)
{
    $settings = google_get_settings($mysqli);
    if (empty($settings['google_client_id'])) {
        throw new \Exception('Falta configurar Google OAuth Client ID en el servidor');
    }

    $state = function_exists('tenant_make_signed_state')
        ? tenant_make_signed_state(current_tenant_key())
        : '';
    if ($state === '') {
        $state = bin2hex(random_bytes(16));
    }
    $_SESSION['google_oauth_state'] = $state;

    $params = [
        'client_id' => $settings['google_client_id'],
        'redirect_uri' => google_redirect_uri($settings),
        'response_type' => 'code',
        'scope' => implode(' ', google_scopes()),
        'access_type' => 'offline',
        'prompt' => 'consent',
        'state' => $state
    ];

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

function google_http_post($url, $data, $headers = [])
{
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers),
            'content' => is_array($data) ? http_build_query($data) : $data,
            'ignore_errors' => true
        ]
    ];

    $body = file_get_contents($url, false, stream_context_create($options));
    $status = $http_response_header[0] ?? '';

    return [$status, json_decode($body, true), $body];
}

function google_http_json($method, $url, $payload, $access_token)
{
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token
    ];

    $options = [
        'http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $payload === null ? '' : json_encode($payload),
            'ignore_errors' => true
        ]
    ];

    $body = file_get_contents($url, false, stream_context_create($options));
    $status = $http_response_header[0] ?? '';

    return [$status, json_decode($body, true), $body];
}

function google_exchange_code($mysqli, $code)
{
    $settings = google_get_settings($mysqli);
    if (empty($settings['google_client_id']) || empty($settings['google_client_secret'])) {
        throw new \Exception('Faltan credenciales Google OAuth en el servidor');
    }

    [$status, $response] = google_http_post('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => $settings['google_client_id'],
        'client_secret' => $settings['google_client_secret'],
        'redirect_uri' => google_redirect_uri($settings),
        'grant_type' => 'authorization_code'
    ]);

    if (empty($response['access_token'])) {
        $detail = google_token_error_detail($status, $response);
        throw new \Exception('Google no devolvió access token' . ($detail ? ': ' . $detail : ''));
    }

    if (empty($response['refresh_token'])) {
        $tenant_id = current_tenant_id();
        $stmt = $mysqli->prepare("UPDATE payment_settings SET google_refresh_token = NULL WHERE tenant_id = ?");
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        throw new \Exception('Google no devolvió refresh token nuevo. Revoca el acceso anterior de la app en tu cuenta de Google y vuelve a conectar.');
    }

    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("UPDATE payment_settings SET google_refresh_token = ? WHERE tenant_id = ?");
    $stmt->bind_param("si", $response['refresh_token'], $tenant_id);
    $stmt->execute();

    $email = google_fetch_user_email($response['access_token']);
    if ($email) {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET google_connected_email = ? WHERE tenant_id = ?");
        $stmt->bind_param("si", $email, $tenant_id);
        $stmt->execute();
    }

    return true;
}

function google_access_token($mysqli)
{
    $settings = google_get_settings($mysqli);
    if (empty($settings['google_client_id']) || empty($settings['google_client_secret']) || empty($settings['google_refresh_token'])) {
        throw new \Exception('Faltan credenciales Google o la cuenta no esta conectada');
    }

    [$status, $response] = google_http_post('https://oauth2.googleapis.com/token', [
        'client_id' => $settings['google_client_id'],
        'client_secret' => $settings['google_client_secret'],
        'refresh_token' => $settings['google_refresh_token'],
        'grant_type' => 'refresh_token'
    ]);

    if (empty($response['access_token'])) {
        $detail = google_token_error_detail($status, $response);
        throw new \Exception('No se pudo obtener access token de Google' . ($detail ? ': ' . $detail : ''));
    }

    return $response['access_token'];
}

function google_token_error_detail($status, $response)
{
    $parts = [];
    if ($status) {
        $parts[] = $status;
    }
    if (is_array($response)) {
        if (!empty($response['error'])) {
            $parts[] = $response['error'];
        }
        if (!empty($response['error_description'])) {
            $parts[] = $response['error_description'];
        }
    }

    return implode(' - ', $parts);
}

function google_fetch_user_email($access_token)
{
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => ['Authorization: Bearer ' . $access_token],
            'ignore_errors' => true
        ]
    ];

    $body = file_get_contents('https://www.googleapis.com/oauth2/v2/userinfo', false, stream_context_create($options));
    $response = json_decode($body, true);

    return $response['email'] ?? '';
}

function google_base64url($value)
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function google_send_email($mysqli, $to, $subject, $html_body, $reply_to = null, array $attachments = [])
{
    $settings = google_get_settings($mysqli);
    $from = $settings['google_connected_email'] ?? '';
    if (!$from) {
        throw new \Exception('No hay cuenta Google conectada');
    }

    $display_name = trim($settings['smtp_from_name'] ?? '') ?: (trim($settings['app_name'] ?? '') ?: 'SimplyGest Praxis');
    $from_name = '=?UTF-8?B?' . base64_encode($display_name) . '?=';
    $headers = [
        'From: ' . $from_name . ' <' . $from . '>',
        'To: ' . $to,
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0'
    ];

    if ($reply_to && filter_var($reply_to, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $reply_to;
    }

    if ($attachments) {
        $boundary = 'sgpraxis_' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $parts = [
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $html_body
        ];
        foreach ($attachments as $attachment) {
            $name = str_replace(['"', "\r", "\n"], '', (string) ($attachment['name'] ?? 'documento.pdf'));
            $mime = trim((string) ($attachment['mime'] ?? 'application/octet-stream')) ?: 'application/octet-stream';
            $parts[] = '--' . $boundary;
            $parts[] = 'Content-Type: ' . $mime . '; name="' . $name . '"';
            $parts[] = 'Content-Disposition: attachment; filename="' . $name . '"';
            $parts[] = 'Content-Transfer-Encoding: base64';
            $parts[] = '';
            $parts[] = chunk_split(base64_encode((string) ($attachment['content'] ?? '')));
        }
        $parts[] = '--' . $boundary . '--';
        $raw = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
    } else {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $raw = implode("\r\n", $headers) . "\r\n\r\n" . $html_body;
    }
    $access_token = google_access_token($mysqli);
    [$status, $response, $body] = google_http_json('POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
        'raw' => google_base64url($raw)
    ], $access_token);

    if (empty($response['id'])) {
        throw new \Exception('No se pudo enviar email por Gmail API: ' . $body);
    }

    return true;
}

function google_create_calendar_event($mysqli, $appointment_id)
{
    ensure_appointment_payment_columns($mysqli);
    ensure_appointment_services_tables($mysqli);

    $settings = google_get_settings($mysqli);
    if ((int) ($settings['google_calendar_enabled'] ?? 0) !== 1) {
        return null;
    }

    $stmt = $mysqli->prepare("
        SELECT a.appointment_date, a.appointment_time, a.consultation_type, a.service_type,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               s.name AS service_name,
               u.name, u.email, u.phone
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
        WHERE a.tenant_id = ?
          AND a.id = ?
    ");
    $tenant_id = current_tenant_id();
    $stmt->bind_param("ii", $tenant_id, $appointment_id);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();

    if (!$appointment) {
        return null;
    }

    $timezone = tenant_timezone();
    $start = appointment_datetime_in_timezone($appointment['appointment_date'], $appointment['appointment_time'], $timezone);
    $end = $start->modify('+' . (int) ($appointment['duration_minutes'] ?? 60) . ' minutes');

    $consultation_text = appointment_consultation_label($appointment['consultation_type'] ?? 'presencial');
    $service_text = appointment_service_option_label($appointment);
    $description = 'Paciente: ' . $appointment['name'] . "\nServicio: " . $service_text . "\nModalidad: " . $consultation_text;
    if (!empty($appointment['phone'])) {
        $description .= "\nTeléfono: " . $appointment['phone'];
    }
    if (!empty($appointment['email'])) {
        $description .= "\nEmail: " . $appointment['email'];
    }

    $event = [
        'summary' => 'Cita ' . $service_text . ' ' . $consultation_text . ' - ' . $appointment['name'],
        'description' => $description,
        'start' => [
            'dateTime' => $start->format(DateTime::RFC3339),
            'timeZone' => $timezone
        ],
        'end' => [
            'dateTime' => $end->format(DateTime::RFC3339),
            'timeZone' => $timezone
        ]
    ];

    $calendar_id = $settings['google_calendar_id'] ?: 'primary';
    $access_token = google_access_token($mysqli);
    [$status, $response, $body] = google_http_json('POST', 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendar_id) . '/events', $event, $access_token);

    if (empty($response['id'])) {
        throw new \Exception('No se pudo crear evento en Google Calendar: ' . $body);
    }

    $event_id = $response['id'];
    $stmt = $mysqli->prepare("UPDATE appointments SET google_calendar_event_id = ? WHERE tenant_id = ? AND id = ?");
    $stmt->bind_param("sii", $event_id, $tenant_id, $appointment_id);
    $stmt->execute();

    return $event_id;
}

function ensure_google_calendar_event_column($mysqli)
{
    $res = $mysqli->query("SHOW COLUMNS FROM appointments LIKE 'google_calendar_event_id'");
    if ($res->num_rows === 0) {
        $mysqli->query("ALTER TABLE appointments ADD google_calendar_event_id VARCHAR(255) DEFAULT NULL");
    }
}

function google_delete_calendar_event($mysqli, $appointment_id)
{
    ensure_google_calendar_event_column($mysqli);
    $settings = google_get_settings($mysqli);
    if ((int) ($settings['google_calendar_enabled'] ?? 0) !== 1) {
        return false;
    }

    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("SELECT google_calendar_event_id FROM appointments WHERE tenant_id = ? AND id = ?");
    $stmt->bind_param("ii", $tenant_id, $appointment_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (empty($row['google_calendar_event_id'])) {
        return false;
    }

    $calendar_id = $settings['google_calendar_id'] ?: 'primary';
    $access_token = google_access_token($mysqli);
    google_http_json('DELETE', 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendar_id) . '/events/' . rawurlencode($row['google_calendar_event_id']), null, $access_token);

    return true;
}
