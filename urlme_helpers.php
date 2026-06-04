<?php

function urlme_api_key()
{
    return defined('URLME_API_KEY') ? trim((string) URLME_API_KEY) : '';
}

function urlme_is_enabled()
{
    return urlme_api_key() !== '' && function_exists('curl_init');
}

function urlme_shorten_url($url, $title = '', $expires_at = null)
{
    $url = trim((string) $url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        return $url;
    }

    if (!urlme_is_enabled()) {
        return $url;
    }

    $payload = ['url' => $url];
    if (trim((string) $title) !== '') {
        $payload['title'] = trim((string) $title);
    }
    if ($expires_at) {
        $payload['expires_at'] = $expires_at;
    }

    $ch = curl_init('https://urlme.es/api/links');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Api-Key: ' . urlme_api_key()
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    if ($body === false) {
        error_log('URLME error: ' . curl_error($ch));
        curl_close($ch);
        return $url;
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        error_log('URLME HTTP ' . $status . ': ' . substr($body, 0, 500));
        return $url;
    }

    $response = json_decode($body, true);
    $short_url = $response['data']['short_url'] ?? '';
    if (!$short_url || !filter_var($short_url, FILTER_VALIDATE_URL)) {
        error_log('URLME respuesta inesperada: ' . substr($body, 0, 500));
        return $url;
    }

    return $short_url;
}
