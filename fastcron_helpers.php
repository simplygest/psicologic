<?php

function fastcron_http_request($url, $method = 'GET', $content = '')
{
    $options = [
        'http' => [
            'method' => $method,
            'header' => ['Content-type: application/x-www-form-urlencoded'],
            'content' => $content,
            'ignore_errors' => true
        ]
    ];

    $body = file_get_contents($url, false, stream_context_create($options));
    if ($body === false) {
        throw new \Exception('Error de conexion con el servidor Cron.');
    }

    return $body;
}

function fastcron_response_is_success($body)
{
    $response = json_decode($body, true);
    if (is_array($response)) {
        if (($response['status'] ?? '') === 'success' || ($response['success'] ?? false) === true) {
            return true;
        }

        return false;
    }

    return stripos($body, 'success') !== false;
}

function fastcron_create_reminder_cron($api_key, $cron_url, $app_name = '')
{
    $api_key = trim((string) $api_key);
    if ($api_key === '') {
        throw new \Exception('Falta el token API de Fastcron.');
    }

    $name = fastcron_reminder_cron_name($cron_url, $app_name);

    $params = [
        'token' => $api_key,
        'name' => $name,
        'expression' => '0 * * * *',
        'timezone' => date_default_timezone_get(),
        'url' => $cron_url,
        'httpMethod' => 'GET'
    ];
    $url = 'https://app.fastcron.com/api/v1/cron_add?' . http_build_query($params);
    $body = fastcron_http_request($url, 'POST', http_build_query(['token' => $api_key]));

    if (!fastcron_response_is_success($body)) {
        throw new \Exception($body);
    }

    $response = json_decode($body, true);
    $cron_id = $response['data']['id'] ?? null;
    if (!$cron_id) {
        throw new \Exception('Fastcron creo el cron, pero no devolvio su ID.');
    }

    return (string) $cron_id;
}

function fastcron_reminder_cron_name($cron_url, $app_name = '')
{
    $slug = fastcron_slug($app_name);
    if ($slug === '') {
        $parts = parse_url($cron_url);
        $slug = fastcron_slug($parts['host'] ?? 'app');
    }
    $hash = substr(hash('sha256', $cron_url), 0, 10);

    return substr('psicologic_reminders_' . $slug . '_' . $hash, 0, 80);
}

function fastcron_slug($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($converted !== false) {
        $value = $converted;
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '', $value);

    return $value ?: '';
}

function fastcron_delete_cron($api_key, $cron_id)
{
    $api_key = trim((string) $api_key);
    $cron_id = trim((string) $cron_id);
    if ($api_key === '' || $cron_id === '') {
        return true;
    }

    $url = 'https://app.fastcron.com/api/v1/cron_delete?' . http_build_query([
        'token' => $api_key,
        'id' => $cron_id
    ]);
    $body = fastcron_http_request($url, 'DELETE', http_build_query(['token' => $api_key]));

    if (!fastcron_response_is_success($body)) {
        throw new \Exception($body);
    }

    return true;
}

function reminder_cron_url()
{
    return app_public_base_url() . 'cron_reminders.php?token=' . urlencode(CRON_WEBHOOK_TOKEN);
}
