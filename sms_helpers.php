<?php
require_once __DIR__ . '/settings_helpers.php';

function sms_supported_providers()
{
    return ['mundosms', 'smsup', 'smsapi'];
}

function sms_get_settings($mysqli)
{
    $tenant_id = current_tenant_id();
    $res = $mysqli->query("
        SELECT sms_provider, sms_sender, sms_username, sms_password, sms_api_key,
               sms_reminder_enabled, sms_reminder_hours
        FROM payment_settings
        WHERE tenant_id = $tenant_id
        LIMIT 1
    ");
    $settings = $res ? ($res->fetch_assoc() ?: []) : [];
    $settings['sms_provider'] = strtolower(trim((string) ($settings['sms_provider'] ?? 'none')));
    if (!in_array($settings['sms_provider'], sms_supported_providers(), true)) {
        $settings['sms_provider'] = 'none';
    }
    $settings['sms_sender'] = trim((string) ($settings['sms_sender'] ?? ''));
    $settings['sms_username'] = trim((string) ($settings['sms_username'] ?? ''));
    $settings['sms_password'] = trim((string) ($settings['sms_password'] ?? ''));
    $settings['sms_api_key'] = trim((string) ($settings['sms_api_key'] ?? ''));
    $settings['sms_reminder_enabled'] = (int) ($settings['sms_reminder_enabled'] ?? 0);
    $settings['sms_reminder_hours'] = max(1, min(168, (int) ($settings['sms_reminder_hours'] ?? 24)));
    return $settings;
}

function sms_is_configured($mysqli)
{
    $settings = sms_get_settings($mysqli);
    $provider = $settings['sms_provider'] ?? 'none';
    if (empty($settings['sms_sender'])) return false;
    if ($provider === 'mundosms') return !empty($settings['sms_username']) && !empty($settings['sms_password']);
    if (in_array($provider, ['smsup', 'smsapi'], true)) return !empty($settings['sms_api_key']);
    return false;
}

function sms_provider_label($provider)
{
    $labels = [
        'mundosms' => 'MundoSMS',
        'smsup' => 'SMSUp',
        'smsapi' => 'SMSAPI',
        'none' => 'Sin proveedor'
    ];
    return $labels[strtolower((string) $provider)] ?? (string) $provider;
}

function sms_sanitize_message($message)
{
    $message = str_replace(["\r\n", "\r"], "\n", (string) $message);
    $message = strip_tags(str_replace(['<br />', '<br>', '<br/>'], "\n", $message));
    $message = str_replace("\xc2\xa0", ' ', $message);
    $message = preg_replace('/[ \t]+/', ' ', $message);
    $message = preg_replace("/\n{3,}/", "\n\n", $message);
    return trim($message);
}

function sms_is_gsm0338($message)
{
    static $allowed = null;
    if ($allowed === null) {
        $basic = json_decode('"@\\u00a3$\\u00a5\\u00e8\\u00e9\\u00f9\\u00ec\\u00f2\\u00c7\\n\\u00d8\\u00f8\\r\\u00c5\\u00e5\\u0394_\\u03a6\\u0393\\u039b\\u03a9\\u03a0\\u03a8\\u03a3\\u0398\\u039e\\u00c6\\u00e6\\u00df\\u00c9 !\\"#\\u00a4%&\'()*+,-./0123456789:;<=>?\\u00a1ABCDEFGHIJKLMNOPQRSTUVWXYZ\\u00c4\\u00d6\\u00d1\\u00dc\\u00a7\\u00bfabcdefghijklmnopqrstuvwxyz\\u00e4\\u00f6\\u00f1\\u00fc\\u00e0"');
        $extended = json_decode('"^{}\\\\[~]|\\u20ac"');
        $allowed = [];
        foreach ([$basic, $extended] as $set) {
            $set_chars = preg_split('//u', (string) $set, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($set_chars ?: [] as $char) {
                $allowed[$char] = true;
            }
        }
    }
    $chars = preg_split('//u', (string) $message, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false) {
        return false;
    }
    foreach ($chars as $char) {
        if (!isset($allowed[$char])) {
            return false;
        }
    }
    return true;
}

function sms_encoding_for_message($message)
{
    return sms_is_gsm0338($message) ? 'GSM7' : 'UCS2';
}

function sms_normalize_recipient($phone, $country_code = '34')
{
    $phone = trim((string) $phone);
    if ($phone === '') {
        return '';
    }
    $phone = preg_replace('/[^\d+]/', '', $phone);
    if (strpos($phone, '00') === 0) {
        $phone = '+' . substr($phone, 2);
    }
    if (strpos($phone, '+') === 0) {
        return preg_replace('/\D/', '', $phone);
    }
    $digits = preg_replace('/\D/', '', $phone);
    if ($country_code === '34' && strlen($digits) === 9) {
        return '34' . $digits;
    }
    return $digits;
}

function sms_normalize_recipients($recipients)
{
    $items = is_array($recipients) ? $recipients : explode(',', (string) $recipients);
    $normalized = [];
    foreach ($items as $recipient) {
        $phone = sms_normalize_recipient($recipient);
        if ($phone !== '' && !in_array($phone, $normalized, true)) {
            $normalized[] = $phone;
        }
    }
    return $normalized;
}

function sms_result($success, $provider, $message_id = '', $error = '', $raw = null, array $extra = [])
{
    return array_merge([
        'success' => (bool) $success,
        'provider' => $provider,
        'message_id' => (string) $message_id,
        'error' => (string) $error,
        'raw' => $raw
    ], $extra);
}

function sms_http_post_form($url, array $params, array $headers = [])
{
    return sms_http_request($url, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_HTTPHEADER => $headers
    ]);
}

function sms_http_post_json($url, array $payload, array $headers = [])
{
    $headers[] = 'Content-Type: application/json';
    return sms_http_request($url, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => $headers
    ]);
}

function sms_http_request($url, array $options = [])
{
    if (!function_exists('curl_init')) {
        throw new Exception('La extension cURL no esta disponible.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ] + $options);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false) {
        throw new Exception($error ?: 'No se pudo conectar con el proveedor SMS.');
    }
    return ['status' => $status, 'body' => $body];
}

function sms_send_with_settings(array $settings, $recipients, $message, array $options = [])
{
    $provider = strtolower(trim((string) ($settings['sms_provider'] ?? 'none')));
    $sender = trim((string) ($settings['sms_sender'] ?? ''));
    $message = sms_sanitize_message($message);
    $phones = sms_normalize_recipients($recipients);

    if (!in_array($provider, sms_supported_providers(), true)) {
        return sms_result(false, $provider ?: 'none', '', 'Proveedor SMS no configurado.');
    }
    if ($sender === '') {
        return sms_result(false, $provider, '', 'Remitente SMS no configurado.');
    }
    if (!$phones) {
        return sms_result(false, $provider, '', 'Destinatario SMS no valido.');
    }
    if ($message === '') {
        return sms_result(false, $provider, '', 'Mensaje SMS vacio.');
    }

    if ($provider === 'mundosms') {
        return sms_send_mundosms($settings, $phones, $sender, $message, $options);
    }
    if ($provider === 'smsup') {
        return sms_send_smsup($settings, $phones, $sender, $message, $options);
    }
    return sms_send_smsapi($settings, $phones, $sender, $message, $options);
}

function sms_send_mundosms(array $settings, array $phones, $sender, $message, array $options = [])
{
    $username = trim((string) ($settings['sms_username'] ?? ''));
    $password = trim((string) ($settings['sms_password'] ?? ''));
    if ($username === '' || $password === '') {
        return sms_result(false, 'mundosms', '', 'Usuario o contrasena de MundoSMS no configurados.');
    }
    $callback = trim((string) ($options['callback_url'] ?? ''));
    $results = [];
    foreach ($phones as $phone) {
        $response = sms_http_post_form('https://www.mundosms.es/APIv2/sendsms.php', [
            'destino' => $phone,
            'mensaje' => $message,
            'username' => $username,
            'callback' => $callback,
            'password' => $password,
            'remitente' => $sender
        ]);
        $body = trim((string) $response['body']);
        if ($body !== '' && ($body[0] === '0' || $body[0] === '1')) {
            $parts = explode('|', $body);
            $results[] = sms_result(true, 'mundosms', $parts[2] ?? '', '', $body, ['recipient' => $phone]);
        } else {
            $parts = explode('|', $body);
            $results[] = sms_result(false, 'mundosms', '', $parts[1] ?? ($body ?: 'Respuesta no valida de MundoSMS.'), $body, ['recipient' => $phone]);
        }
    }
    return sms_compact_results('mundosms', $results);
}

function sms_send_smsapi(array $settings, array $phones, $sender, $message, array $options = [])
{
    $token = trim((string) ($settings['sms_api_key'] ?? ''));
    if ($token === '') {
        return sms_result(false, 'smsapi', '', 'API Key de SMSAPI no configurada.');
    }
    $notify_url = trim((string) ($options['callback_url'] ?? ''));
    $idx = trim((string) ($options['custom'] ?? current_tenant_id()));
    $results = [];
    foreach ($phones as $phone) {
        $params = [
            'to' => $phone,
            'from' => $sender,
            'message' => $message,
            'idx' => $idx,
            'format' => 'json'
        ];
        if ($notify_url !== '') {
            $params['notify_url'] = $notify_url;
        }
        $response = smsapi_request_with_backup('sms.do', $params, $token);
        $json = json_decode($response['body'], true);
        if (is_array($json) && !empty($json['error'])) {
            $error = (int) $json['error'] === 14
                ? "NO has dado de alta el remitente en SMSAPI. Debes hacerlo antes de poder enviar SMS."
                : 'Error ' . $json['error'] . ': ' . ($json['message'] ?? 'SMSAPI rechazo el envio.');
            $results[] = sms_result(false, 'smsapi', '', $error, $response['body'], ['recipient' => $phone]);
        } elseif (is_array($json) && !empty($json['count']) && !empty($json['list'][0]['id'])) {
            $results[] = sms_result(true, 'smsapi', $json['list'][0]['id'], '', $response['body'], ['recipient' => $phone]);
        } else {
            $results[] = sms_result(false, 'smsapi', '', 'Respuesta no valida de SMSAPI.', $response['body'], ['recipient' => $phone]);
        }
    }
    return sms_compact_results('smsapi', $results);
}

function smsapi_request_with_backup($path, array $params, $token, $backup = false)
{
    $base = $backup ? 'https://api2.smsapi.com/' : 'https://api.smsapi.com/';
    $response = sms_http_post_form($base . ltrim($path, '/'), $params, ['Authorization: Bearer ' . $token]);
    if ((int) $response['status'] !== 200 && !$backup) {
        return smsapi_request_with_backup($path, $params, $token, true);
    }
    return $response;
}

function sms_send_smsup(array $settings, array $phones, $sender, $message, array $options = [])
{
    $api_key = trim((string) ($settings['sms_api_key'] ?? ''));
    if ($api_key === '') {
        return sms_result(false, 'smsup', '', 'API Key de SMSUp no configurada.');
    }
    $callback = trim((string) ($options['callback_url'] ?? ''));
    $custom = trim((string) ($options['custom'] ?? current_tenant_id()));
    $send_at = trim((string) ($options['send_at'] ?? ''));
    $messages = [];
    foreach ($phones as $phone) {
        $item = [
            'from' => $sender,
            'to' => $phone,
            'text' => $message,
            'custom' => $custom
        ];
        if ($send_at !== '') {
            $item['send_at'] = $send_at;
        }
        $messages[] = $item;
    }
    $payload = [
        'api_key' => $api_key,
        'encoding' => sms_encoding_for_message($message),
        'fake' => !empty($options['fake']),
        'messages' => $messages
    ];
    if ($callback !== '') {
        $payload['report_url'] = $callback;
    }
    $response = sms_http_post_json('https://api.gateway360.com/api/3.0/sms/send', $payload);
    $json = json_decode($response['body'], true);
    if (!is_array($json)) {
        return sms_result(false, 'smsup', '', 'Respuesta no valida de SMSUp.', $response['body']);
    }
    if (($json['status'] ?? '') === 'error') {
        return sms_result(false, 'smsup', '', $json['error_msg'] ?? 'SMSUp rechazo el envio.', $response['body']);
    }
    if (($json['status'] ?? '') !== 'ok') {
        return sms_result(false, 'smsup', '', 'Respuesta no reconocida de SMSUp.', $response['body']);
    }
    $results = [];
    foreach (($json['result'] ?? []) as $index => $item) {
        $phone = $phones[$index] ?? '';
        if (!empty($item['error_msg'])) {
            $results[] = sms_result(false, 'smsup', '', $item['error_msg'], $response['body'], ['recipient' => $phone]);
        } else {
            $results[] = sms_result(true, 'smsup', $item['sms_id'] ?? '', '', $response['body'], ['recipient' => $phone]);
        }
    }
    return sms_compact_results('smsup', $results ?: [sms_result(true, 'smsup', '', '', $response['body'])]);
}

function sms_compact_results($provider, array $results)
{
    $failed = array_values(array_filter($results, static fn($row) => empty($row['success'])));
    $sent = array_values(array_filter($results, static fn($row) => !empty($row['success'])));
    return sms_result(
        count($failed) === 0 && count($sent) > 0,
        $provider,
        $sent[0]['message_id'] ?? '',
        $failed[0]['error'] ?? '',
        null,
        [
            'sent' => count($sent),
            'failed' => count($failed),
            'results' => $results
        ]
    );
}

function sms_get_balance_with_settings(array $settings)
{
    $provider = strtolower(trim((string) ($settings['sms_provider'] ?? 'none')));
    if ($provider === 'mundosms') {
        return sms_get_balance_mundosms($settings);
    }
    if ($provider === 'smsup') {
        return sms_get_balance_smsup($settings);
    }
    if ($provider === 'smsapi') {
        return sms_get_balance_smsapi($settings);
    }
    return sms_result(false, $provider ?: 'none', '', 'Proveedor SMS no configurado.');
}

function sms_get_balance_mundosms(array $settings)
{
    $username = trim((string) ($settings['sms_username'] ?? ''));
    $password = trim((string) ($settings['sms_password'] ?? ''));
    $sender = trim((string) ($settings['sms_sender'] ?? ''));
    if ($username === '' || $password === '' || $sender === '') {
        return sms_result(false, 'mundosms', '', 'Credenciales de MundoSMS incompletas.');
    }
    $response = sms_http_post_form('https://www.mundosms.es/APIv2/quotesms.php', [
        'destino' => '34600000000',
        'mensaje' => 'SALDO',
        'username' => $username,
        'password' => $password,
        'remitente' => $sender
    ]);
    $body = trim((string) $response['body']);
    if ($body === '' || ($body[0] !== '0' && $body[0] !== '1')) {
        return sms_result(false, 'mundosms', '', 'No se pudo consultar saldo en MundoSMS.', $body);
    }
    $parts = explode('|', $body);
    return sms_result(true, 'mundosms', '', '', $body, ['balance' => $parts[3] ?? '']);
}

function sms_get_balance_smsapi(array $settings)
{
    $token = trim((string) ($settings['sms_api_key'] ?? ''));
    if ($token === '') {
        return sms_result(false, 'smsapi', '', 'API Key de SMSAPI no configurada.');
    }
    $response = sms_http_request('https://api.smsapi.com/profile', [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]
    ]);
    $json = json_decode($response['body'], true);
    if (!is_array($json) || !array_key_exists('points', $json)) {
        return sms_result(false, 'smsapi', '', 'No se pudo consultar saldo en SMSAPI.', $response['body']);
    }
    return sms_result(true, 'smsapi', '', '', $response['body'], ['balance' => $json['points']]);
}

function sms_get_balance_smsup(array $settings)
{
    $api_key = trim((string) ($settings['sms_api_key'] ?? ''));
    if ($api_key === '') {
        return sms_result(false, 'smsup', '', 'API Key de SMSUp no configurada.');
    }
    $response = sms_http_post_json('https://api.gateway360.com/api/3.0/account/get-balance', [
        'api_key' => $api_key
    ]);
    $json = json_decode($response['body'], true);
    if (!is_array($json) || ($json['status'] ?? '') !== 'ok') {
        return sms_result(false, 'smsup', '', $json['error_msg'] ?? 'No se pudo consultar saldo en SMSUp.', $response['body']);
    }
    return sms_result(true, 'smsup', '', '', $response['body'], [
        'balance' => $json['result']['balance'] ?? '',
        'currency' => $json['result']['currency'] ?? ''
    ]);
}
