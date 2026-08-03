<?php

function daily_config_value($key, $fallback = '')
{
    return function_exists('psicologic_config_value')
        ? (string) psicologic_config_value($key, $fallback)
        : (string) $fallback;
}

function daily_domain()
{
    $domain = trim(daily_config_value('daily_domain'));
    if ($domain === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $domain)) {
        $domain = 'https://' . $domain;
    }
    return rtrim($domain, '/');
}

function daily_is_configured()
{
    return daily_domain() !== '' && daily_config_value('daily_api_key') !== '';
}

function daily_api_request($method, $path, array $payload = [])
{
    if (!daily_is_configured() || !function_exists('curl_init')) {
        throw new RuntimeException('Daily no esta configurado correctamente.');
    }
    $curl = curl_init('https://api.daily.co/v1/' . ltrim($path, '/'));
    $headers = [
        'Authorization: Bearer ' . daily_config_value('daily_api_key'),
        'Content-Type: application/json'
    ];
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $headers
    ]);
    if ($payload) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    $data = is_string($body) ? json_decode($body, true) : null;
    if ($body === false || $status < 200 || $status >= 300 || !is_array($data)) {
        $message = is_array($data) ? (string) ($data['info'] ?? $data['error'] ?? '') : '';
        throw new RuntimeException($message ?: ($error ?: 'Daily devolvio un error HTTP ' . $status . '.'));
    }
    return $data;
}

function daily_ensure_room($room_name, $expires)
{
    try {
        $room = daily_api_request('GET', 'rooms/' . rawurlencode($room_name));
    } catch (Throwable $e) {
        $room = daily_api_request('POST', 'rooms', [
            'name' => $room_name,
            'privacy' => 'private',
            'properties' => [
                'exp' => max(time() + 3600, (int) $expires),
                'enable_chat' => true,
                'enable_screenshare' => true,
                'eject_at_room_exp' => true
            ]
        ]);
    }
    return (string) ($room['url'] ?? (daily_domain() . '/' . $room_name));
}

function daily_meeting_token($room_name, $identity, $name, $is_owner, $expires)
{
    $response = daily_api_request('POST', 'meeting-tokens', [
        'properties' => [
            'room_name' => $room_name,
            'user_id' => $identity,
            'user_name' => $name,
            'is_owner' => (bool) $is_owner,
            'exp' => max(time() + 1800, (int) $expires)
        ]
    ]);
    return (string) ($response['token'] ?? '');
}
