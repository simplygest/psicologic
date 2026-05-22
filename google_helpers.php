<?php

require_once __DIR__ . '/mail_helpers.php';
require_once __DIR__ . '/payment_helpers.php';

function google_get_settings($mysqli)
{
    ensure_admin_notification_email_column($mysqli);

    $res = $mysqli->query("
        SELECT app_name, smtp_from_name, google_client_id, google_client_secret, google_refresh_token, google_connected_email,
               google_redirect_uri, google_calendar_enabled, google_calendar_id
        FROM payment_settings
        WHERE id = 1
    ");

    return $res->fetch_assoc() ?: [];
}

function google_default_redirect_uri()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? null) == 443) ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return $protocol . $host . ($path ? $path . '/' : '/') . 'google_oauth_callback.php';
}

function google_redirect_uri($settings)
{
    return !empty($settings['google_redirect_uri']) ? $settings['google_redirect_uri'] : google_default_redirect_uri();
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
        throw new \Exception('Falta Google Client ID');
    }

    $_SESSION['google_oauth_state'] = bin2hex(random_bytes(16));

    $params = [
        'client_id' => $settings['google_client_id'],
        'redirect_uri' => google_redirect_uri($settings),
        'response_type' => 'code',
        'scope' => implode(' ', google_scopes()),
        'access_type' => 'offline',
        'prompt' => 'consent',
        'state' => $_SESSION['google_oauth_state']
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
        throw new \Exception('Faltan credenciales Google');
    }

    [$status, $response] = google_http_post('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => $settings['google_client_id'],
        'client_secret' => $settings['google_client_secret'],
        'redirect_uri' => google_redirect_uri($settings),
        'grant_type' => 'authorization_code'
    ]);

    if (empty($response['access_token'])) {
        throw new \Exception('Google no devolvió access token');
    }

    if (!empty($response['refresh_token'])) {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET google_refresh_token = ? WHERE id = 1");
        $stmt->bind_param("s", $response['refresh_token']);
        $stmt->execute();
    }

    $email = google_fetch_user_email($response['access_token']);
    if ($email) {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET google_connected_email = ? WHERE id = 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
    }

    return true;
}

function google_access_token($mysqli)
{
    $settings = google_get_settings($mysqli);
    if (empty($settings['google_client_id']) || empty($settings['google_client_secret']) || empty($settings['google_refresh_token'])) {
        throw new \Exception('Faltan credenciales Google');
    }

    [$status, $response] = google_http_post('https://oauth2.googleapis.com/token', [
        'client_id' => $settings['google_client_id'],
        'client_secret' => $settings['google_client_secret'],
        'refresh_token' => $settings['google_refresh_token'],
        'grant_type' => 'refresh_token'
    ]);

    if (empty($response['access_token'])) {
        throw new \Exception('No se pudo obtener access token de Google');
    }

    return $response['access_token'];
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

function google_send_email($mysqli, $to, $subject, $html_body, $reply_to = null)
{
    $settings = google_get_settings($mysqli);
    $from = $settings['google_connected_email'] ?? '';
    if (!$from) {
        throw new \Exception('No hay cuenta Google conectada');
    }

    $display_name = trim($settings['smtp_from_name'] ?? '') ?: (trim($settings['app_name'] ?? '') ?: 'PsicoLogic');
    $from_name = '=?UTF-8?B?' . base64_encode($display_name) . '?=';
    $headers = [
        'From: ' . $from_name . ' <' . $from . '>',
        'To: ' . $to,
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8'
    ];

    if ($reply_to && filter_var($reply_to, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $reply_to;
    }

    $raw = implode("\r\n", $headers) . "\r\n\r\n" . $html_body;
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

    $settings = google_get_settings($mysqli);
    if ((int) ($settings['google_calendar_enabled'] ?? 0) !== 1) {
        return null;
    }

    $stmt = $mysqli->prepare("
        SELECT a.appointment_date, a.appointment_time, u.name, u.email, u.phone
        FROM appointments a
        JOIN users u ON u.id = a.user_id
        WHERE a.id = ?
    ");
    $stmt->bind_param("i", $appointment_id);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();

    if (!$appointment) {
        return null;
    }

    $start = new DateTime($appointment['appointment_date'] . ' ' . $appointment['appointment_time']);
    $end = clone $start;
    $end->modify('+1 hour');

    $description = 'Paciente: ' . $appointment['name'];
    if (!empty($appointment['phone'])) {
        $description .= "\nTeléfono: " . $appointment['phone'];
    }
    if (!empty($appointment['email'])) {
        $description .= "\nEmail: " . $appointment['email'];
    }

    $event = [
        'summary' => 'Cita - ' . $appointment['name'],
        'description' => $description,
        'start' => [
            'dateTime' => $start->format(DateTime::RFC3339),
            'timeZone' => date_default_timezone_get()
        ],
        'end' => [
            'dateTime' => $end->format(DateTime::RFC3339),
            'timeZone' => date_default_timezone_get()
        ]
    ];

    $calendar_id = $settings['google_calendar_id'] ?: 'primary';
    $access_token = google_access_token($mysqli);
    [$status, $response, $body] = google_http_json('POST', 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendar_id) . '/events', $event, $access_token);

    if (empty($response['id'])) {
        throw new \Exception('No se pudo crear evento en Google Calendar: ' . $body);
    }

    $event_id = $response['id'];
    $stmt = $mysqli->prepare("UPDATE appointments SET google_calendar_event_id = ? WHERE id = ?");
    $stmt->bind_param("si", $event_id, $appointment_id);
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

    $stmt = $mysqli->prepare("SELECT google_calendar_event_id FROM appointments WHERE id = ?");
    $stmt->bind_param("i", $appointment_id);
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
