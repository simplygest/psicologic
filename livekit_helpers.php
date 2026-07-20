<?php

function livekit_config_value($key, $fallback = '')
{
    return function_exists('psicologic_config_value')
        ? (string) psicologic_config_value($key, $fallback)
        : (string) $fallback;
}

function livekit_is_configured()
{
    return livekit_config_value('livekit_url') !== ''
        && livekit_config_value('livekit_api_key') !== ''
        && livekit_config_value('livekit_api_secret') !== '';
}

function livekit_base64url($value)
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function livekit_jwt(array $payload, $secret)
{
    $segments = [
        livekit_base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES)),
        livekit_base64url(json_encode($payload, JSON_UNESCAPED_SLASHES))
    ];
    $segments[] = livekit_base64url(hash_hmac('sha256', implode('.', $segments), $secret, true));
    return implode('.', $segments);
}

function livekit_access_token($room, $identity, $name)
{
    $now = time();
    return livekit_jwt([
        'iss' => livekit_config_value('livekit_api_key'),
        'sub' => $identity,
        'name' => $name,
        'nbf' => $now - 10,
        'exp' => $now + 3600,
        'video' => [
            'room' => $room,
            'roomJoin' => true,
            'canPublish' => true,
            'canPublishData' => true,
            'canSubscribe' => true
        ]
    ], livekit_config_value('livekit_api_secret'));
}

function livekit_room_name($tenant_id, $appointment_id)
{
    return 'praxis-t' . max(1, (int) $tenant_id) . '-c' . max(1, (int) $appointment_id);
}

function livekit_enabled_for_professional($mysqli, $professional_id)
{
    if (!livekit_is_configured() || !$mysqli || (int) $professional_id <= 0 || !function_exists('cabinet_get_effective_professional_settings')) {
        return false;
    }
    if (!function_exists('dashboard_config_plan_key_from_db') || !function_exists('plan_config_for_key') || !function_exists('plan_config_feature_enabled')) {
        return false;
    }
    $plan_config = plan_config_for_key(dashboard_config_plan_key_from_db($mysqli));
    if (!plan_config_feature_enabled($plan_config, 'livekit.enabled', false)) {
        return false;
    }
    $settings = cabinet_get_effective_professional_settings($mysqli, (int) $professional_id);
    return (int) ($settings['livekit_enabled'] ?? 1) === 1;
}

function livekit_appointment_enabled($mysqli, array $appointment)
{
    return ($appointment['consultation_type'] ?? 'presencial') === 'online'
        && livekit_enabled_for_professional($mysqli, (int) ($appointment['professional_id'] ?? 0));
}

function livekit_patient_link_expires_at(array $appointment)
{
    $date = trim((string) ($appointment['appointment_date'] ?? ''));
    $time = trim((string) ($appointment['appointment_time'] ?? ''));
    $duration = max(15, (int) ($appointment['duration_minutes'] ?? 60));
    $start = strtotime($date . ' ' . $time);
    return $start ? $start + (($duration + 90) * 60) : time() + 86400;
}

function livekit_ensure_appointment_link_token($mysqli, $appointment_id, $current_token = '', $regenerate = false)
{
    $current_token = trim((string) $current_token);
    if (!$regenerate && preg_match('/^[a-f0-9]{64}$/', $current_token)) {
        return $current_token;
    }

    $token = bin2hex(random_bytes(32));
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare('UPDATE appointments SET livekit_access_token = ? WHERE tenant_id = ? AND id = ?');
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('sii', $token, $tenant_id, $appointment_id);
    return $stmt->execute() ? $token : '';
}

function livekit_patient_link_signature($tenant_id, $appointment_id, $patient_id, $expires, $access_token)
{
    $payload = implode('|', [max(1, (int) $tenant_id), max(1, (int) $appointment_id), max(1, (int) $patient_id), (int) $expires, $access_token]);
    return hash_hmac('sha256', $payload, livekit_config_value('livekit_api_secret'));
}

function livekit_tenant_base_url()
{
    if (function_exists('tenant_public_base_url')) {
        return tenant_public_base_url();
    }

    return '';
}

function livekit_patient_join_url(array $appointment)
{
    if (!livekit_is_configured()) {
        return '';
    }

    $tenant_id = current_tenant_id();
    $appointment_id = (int) ($appointment['id'] ?? 0);
    $patient_id = (int) ($appointment['user_id'] ?? 0);
    $access_token = trim((string) ($appointment['livekit_access_token'] ?? ''));
    if ($appointment_id <= 0 || $patient_id <= 0 || !preg_match('/^[a-f0-9]{64}$/', $access_token)) {
        return '';
    }

    $expires = livekit_patient_link_expires_at($appointment);
    $signature = livekit_patient_link_signature($tenant_id, $appointment_id, $patient_id, $expires, $access_token);
    $base_url = livekit_tenant_base_url();
    if ($base_url === '') {
        return '';
    }
    return $base_url . 'livekit_call.php?appointment_id=' . $appointment_id
        . '&access=patient&expires=' . $expires . '&token=' . $access_token . '&signature=' . $signature;
}

function livekit_patient_link_is_valid($tenant_id, $appointment_id, $patient_id, $expires, $access_token, $signature)
{
    if (!livekit_is_configured() || $expires < time() || !preg_match('/^[a-f0-9]{64}$/', (string) $access_token)) {
        return false;
    }
    $expected = livekit_patient_link_signature($tenant_id, $appointment_id, $patient_id, $expires, $access_token);
    return $signature !== '' && hash_equals($expected, $signature);
}
