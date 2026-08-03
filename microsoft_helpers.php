<?php

require_once __DIR__ . '/payment_helpers.php';
require_once __DIR__ . '/google_helpers.php';

function microsoft_oauth_client_id()
{
    return defined('MICROSOFT_OAUTH_CLIENT_ID') ? trim((string) MICROSOFT_OAUTH_CLIENT_ID) : '';
}

function microsoft_oauth_client_secret()
{
    return defined('MICROSOFT_OAUTH_CLIENT_SECRET') ? trim((string) MICROSOFT_OAUTH_CLIENT_SECRET) : '';
}

function microsoft_oauth_credentials_configured()
{
    return microsoft_oauth_client_id() !== '' && microsoft_oauth_client_secret() !== '';
}

function microsoft_oauth_base_url()
{
    $configured = defined('MICROSOFT_OAUTH_BASE_URL') ? trim((string) MICROSOFT_OAUTH_BASE_URL) : '';
    return $configured !== '' ? rtrim($configured, '/') . '/' : google_oauth_base_url();
}

function microsoft_default_redirect_uri()
{
    return microsoft_oauth_base_url() . 'microsoft_oauth_callback.php';
}

function microsoft_get_settings($mysqli)
{
    $tenant_id = current_tenant_id();
    $res = $mysqli->query("
        SELECT app_name, calendar_provider, microsoft_refresh_token, microsoft_connected_email,
               microsoft_calendar_id
        FROM payment_settings
        WHERE tenant_id = $tenant_id
    ");
    return $res->fetch_assoc() ?: [];
}

function microsoft_scopes()
{
    return ['openid', 'profile', 'email', 'offline_access', 'User.Read', 'Calendars.ReadWrite'];
}

function microsoft_build_auth_url()
{
    if (!microsoft_oauth_credentials_configured()) {
        throw new Exception('Faltan las credenciales Microsoft OAuth en el servidor');
    }
    $state = function_exists('tenant_make_signed_state') ? tenant_make_signed_state(current_tenant_key()) : '';
    if ($state === '') {
        $state = bin2hex(random_bytes(16));
    }
    $_SESSION['microsoft_oauth_state'] = $state;
    return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' . http_build_query([
        'client_id' => microsoft_oauth_client_id(),
        'response_type' => 'code',
        'redirect_uri' => microsoft_default_redirect_uri(),
        'response_mode' => 'query',
        'scope' => implode(' ', microsoft_scopes()),
        'state' => $state,
        'prompt' => 'select_account'
    ]);
}

function microsoft_http_json($method, $url, $payload, $access_token)
{
    $headers = ['Authorization: Bearer ' . $access_token, 'Content-Type: application/json'];
    $options = ['http' => [
        'method' => $method,
        'header' => $headers,
        'content' => $payload === null ? '' : json_encode($payload),
        'ignore_errors' => true
    ]];
    $body = file_get_contents($url, false, stream_context_create($options));
    $status = $http_response_header[0] ?? '';
    return [$status, json_decode((string) $body, true), (string) $body];
}

function microsoft_exchange_code($mysqli, $code)
{
    [$status, $response] = google_http_post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
        'client_id' => microsoft_oauth_client_id(),
        'client_secret' => microsoft_oauth_client_secret(),
        'code' => $code,
        'redirect_uri' => microsoft_default_redirect_uri(),
        'grant_type' => 'authorization_code',
        'scope' => implode(' ', microsoft_scopes())
    ]);
    if (empty($response['access_token']) || empty($response['refresh_token'])) {
        throw new Exception('Microsoft no devolvió los tokens necesarios: ' . ($response['error_description'] ?? $status));
    }
    [, $profile] = microsoft_http_json('GET', 'https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName', null, $response['access_token']);
    $email = trim((string) ($profile['mail'] ?? $profile['userPrincipalName'] ?? ''));
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("UPDATE payment_settings SET microsoft_refresh_token = ?, microsoft_connected_email = ? WHERE tenant_id = ?");
    $stmt->bind_param("ssi", $response['refresh_token'], $email, $tenant_id);
    $stmt->execute();
}

function microsoft_access_token($mysqli)
{
    $settings = microsoft_get_settings($mysqli);
    if (empty($settings['microsoft_refresh_token']) || !microsoft_oauth_credentials_configured()) {
        throw new Exception('La cuenta Microsoft no está conectada');
    }
    [$status, $response] = google_http_post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
        'client_id' => microsoft_oauth_client_id(),
        'client_secret' => microsoft_oauth_client_secret(),
        'refresh_token' => $settings['microsoft_refresh_token'],
        'grant_type' => 'refresh_token',
        'scope' => implode(' ', microsoft_scopes())
    ]);
    if (empty($response['access_token'])) {
        throw new Exception('No se pudo renovar la conexión con Microsoft: ' . ($response['error_description'] ?? $status));
    }
    if (!empty($response['refresh_token']) && $response['refresh_token'] !== $settings['microsoft_refresh_token']) {
        $tenant_id = current_tenant_id();
        $stmt = $mysqli->prepare("UPDATE payment_settings SET microsoft_refresh_token = ? WHERE tenant_id = ?");
        $stmt->bind_param("si", $response['refresh_token'], $tenant_id);
        $stmt->execute();
    }
    return $response['access_token'];
}

function microsoft_create_calendar_event($mysqli, $appointment_id)
{
    $settings = microsoft_get_settings($mysqli);
    if (($settings['calendar_provider'] ?? 'none') !== 'microsoft') return null;
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("
        SELECT a.appointment_date, a.appointment_time, a.consultation_type,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               s.name AS service_name, u.name, u.email, u.phone
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
        WHERE a.tenant_id = ? AND a.id = ?
    ");
    $stmt->bind_param("ii", $tenant_id, $appointment_id);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
    if (!$appointment) return null;

    $timezone = tenant_timezone();
    $start = appointment_datetime_in_timezone($appointment['appointment_date'], $appointment['appointment_time'], $timezone);
    $end = $start->modify('+' . (int) $appointment['duration_minutes'] . ' minutes');
    $startUtc = $start->setTimezone(new DateTimeZone('UTC'));
    $endUtc = $end->setTimezone(new DateTimeZone('UTC'));
    $service = trim((string) ($appointment['service_name'] ?? 'Cita')) ?: 'Cita';
    $description = 'Paciente: ' . $appointment['name'];
    if (!empty($appointment['phone'])) $description .= "\nTeléfono: " . $appointment['phone'];
    if (!empty($appointment['email'])) $description .= "\nEmail: " . $appointment['email'];
    $event = [
        'subject' => $service . ' - ' . $appointment['name'],
        'body' => ['contentType' => 'text', 'content' => $description],
        'start' => ['dateTime' => $startUtc->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
        'end' => ['dateTime' => $endUtc->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC']
    ];
    $calendarId = trim((string) ($settings['microsoft_calendar_id'] ?? ''));
    $url = $calendarId !== ''
        ? 'https://graph.microsoft.com/v1.0/me/calendars/' . rawurlencode($calendarId) . '/events'
        : 'https://graph.microsoft.com/v1.0/me/calendar/events';
    [, $response, $body] = microsoft_http_json('POST', $url, $event, microsoft_access_token($mysqli));
    if (empty($response['id'])) throw new Exception('No se pudo crear el evento en Outlook Calendar: ' . $body);
    $stmt = $mysqli->prepare("UPDATE appointments SET microsoft_calendar_event_id = ? WHERE tenant_id = ? AND id = ?");
    $stmt->bind_param("sii", $response['id'], $tenant_id, $appointment_id);
    $stmt->execute();
    return $response['id'];
}

function microsoft_delete_calendar_event($mysqli, $appointment_id)
{
    $settings = microsoft_get_settings($mysqli);
    if (($settings['calendar_provider'] ?? 'none') !== 'microsoft') return false;
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("SELECT microsoft_calendar_event_id FROM appointments WHERE tenant_id = ? AND id = ?");
    $stmt->bind_param("ii", $tenant_id, $appointment_id);
    $stmt->execute();
    $eventId = $stmt->get_result()->fetch_assoc()['microsoft_calendar_event_id'] ?? '';
    if ($eventId === '') return false;
    $calendarId = trim((string) ($settings['microsoft_calendar_id'] ?? ''));
    $url = $calendarId !== ''
        ? 'https://graph.microsoft.com/v1.0/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId)
        : 'https://graph.microsoft.com/v1.0/me/events/' . rawurlencode($eventId);
    microsoft_http_json('DELETE', $url, null, microsoft_access_token($mysqli));
    return true;
}
