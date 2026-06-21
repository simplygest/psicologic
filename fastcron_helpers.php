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

function fastcron_create_planning_cron($api_key, $cron_url, $app_name = '')
{
    $api_key = trim((string) $api_key);
    if ($api_key === '') {
        throw new \Exception('Falta el token API de Fastcron.');
    }

    $params = [
        'token' => $api_key,
        'name' => fastcron_planning_cron_name($cron_url, $app_name),
        'expression' => '0 8,20 * * *',
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

function fastcron_get_cron($api_key, $cron_id)
{
    $api_key = trim((string) $api_key);
    $cron_id = trim((string) $cron_id);
    if ($api_key === '' || $cron_id === '') {
        return null;
    }

    $url = 'https://app.fastcron.com/api/v1/cron_get?' . http_build_query([
        'token' => $api_key,
        'id' => $cron_id
    ]);
    $body = fastcron_http_request($url, 'GET');
    if (!fastcron_response_is_success($body)) {
        return null;
    }

    $response = json_decode($body, true);
    return is_array($response['data'] ?? null) ? $response['data'] : null;
}

function fastcron_find_cron_by_name($api_key, $name)
{
    $api_key = trim((string) $api_key);
    $name = trim((string) $name);
    if ($api_key === '' || $name === '') {
        return null;
    }

    $url = 'https://app.fastcron.com/api/v1/cron_list?' . http_build_query([
        'token' => $api_key,
        'keyword' => $name
    ]);
    $body = fastcron_http_request($url, 'GET');
    if (!fastcron_response_is_success($body)) {
        return null;
    }

    $response = json_decode($body, true);
    $items = $response['data'] ?? [];
    if (!is_array($items)) {
        return null;
    }

    foreach ($items as $item) {
        if (is_array($item) && ($item['name'] ?? '') === $name && !empty($item['id'])) {
            return $item;
        }
    }

    return null;
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

function fastcron_planning_cron_name($cron_url, $app_name = '')
{
    $slug = fastcron_slug($app_name);
    if ($slug === '') {
        $parts = parse_url($cron_url);
        $slug = fastcron_slug($parts['host'] ?? 'app');
    }
    $hash = substr(hash('sha256', $cron_url), 0, 10);

    return substr('psicologic_planning_' . $slug . '_' . $hash, 0, 80);
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

function fastcron_ensure_planning_cron_column($mysqli)
{
    $table = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if (!$table || $table->num_rows === 0) {
        return;
    }

    $column = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'fastcron_planning_cron_id'");
    if ($column && $column->num_rows === 0) {
        $mysqli->query("ALTER TABLE payment_settings ADD fastcron_planning_cron_id VARCHAR(64) DEFAULT NULL AFTER fastcron_reminder_cron_id");
    }
}

function fastcron_effective_api_key($mysqli)
{
    $configured = defined('FASTCRON_API_KEY') ? trim(FASTCRON_API_KEY) : '';
    $stored = '';
    $table = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if ($table && $table->num_rows > 0) {
        $column = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'fastcron_api_key'");
        if ($column && $column->num_rows > 0) {
            $tenant_id = current_tenant_id();
            $res = $mysqli->query("SELECT fastcron_api_key FROM payment_settings WHERE tenant_id = $tenant_id");
            $row = $res ? $res->fetch_assoc() : null;
            $stored = trim($row['fastcron_api_key'] ?? '');
        }
    }

    return $stored !== '' ? $stored : $configured;
}

function fastcron_count_daily_planning_professionals($mysqli)
{
    $table = $mysqli->query("SHOW TABLES LIKE 'professionals'");
    if (!$table || $table->num_rows === 0) {
        return 0;
    }

    $column = $mysqli->query("SHOW COLUMNS FROM professionals LIKE 'appointment_summary_email_mode'");
    if (!$column || $column->num_rows === 0) {
        return 0;
    }

    $res = $mysqli->query("
        SELECT COUNT(*) AS total
        FROM professionals
        WHERE tenant_id = " . current_tenant_id() . "
          AND is_active = 1
          AND appointment_summary_email_mode IN ('today_morning', 'tomorrow_evening')
    ");
    $row = $res ? $res->fetch_assoc() : null;
    return (int) ($row['total'] ?? 0);
}

function fastcron_sync_professional_planning_cron($mysqli, $app_name = '', $fail_on_missing_key = true)
{
    fastcron_ensure_planning_cron_column($mysqli);

    $tenant_id = current_tenant_id();
    $settings_res = $mysqli->query("SELECT fastcron_planning_cron_id FROM payment_settings WHERE tenant_id = $tenant_id");
    $settings = $settings_res ? $settings_res->fetch_assoc() : [];
    $current_cron_id = trim($settings['fastcron_planning_cron_id'] ?? '');
    $daily_professionals = fastcron_count_daily_planning_professionals($mysqli);
    $api_key = fastcron_effective_api_key($mysqli);
    $cron_url = professional_planning_cron_url();
    $cron_name = fastcron_planning_cron_name($cron_url, $app_name);

    if ($api_key !== '' && $current_cron_id !== '') {
        $remote_cron = fastcron_get_cron($api_key, $current_cron_id);
        if (!$remote_cron) {
            $current_cron_id = '';
            $mysqli->query("UPDATE payment_settings SET fastcron_planning_cron_id = NULL WHERE tenant_id = $tenant_id");
        }
    }

    if ($daily_professionals > 0 && $current_cron_id === '') {
        if ($api_key === '') {
            if ($fail_on_missing_key) {
                throw new \Exception('Falta configurar el token API de Fastcron para crear el cron de planning.');
            }
            return ['action' => 'missing_api_key', 'cron_id' => '', 'daily_professionals' => $daily_professionals];
        }

        $existing_cron = fastcron_find_cron_by_name($api_key, $cron_name);
        if ($existing_cron && !empty($existing_cron['id'])) {
            $existing_cron_id = (string) $existing_cron['id'];
            $stmt = $mysqli->prepare("UPDATE payment_settings SET fastcron_planning_cron_id = ? WHERE tenant_id = ?");
            $stmt->bind_param("si", $existing_cron_id, $tenant_id);
            $stmt->execute();
            return ['action' => 'linked_existing', 'cron_id' => $existing_cron_id, 'daily_professionals' => $daily_professionals];
        }

        $new_cron_id = fastcron_create_planning_cron($api_key, $cron_url, $app_name);
        $stmt = $mysqli->prepare("UPDATE payment_settings SET fastcron_planning_cron_id = ? WHERE tenant_id = ?");
        $stmt->bind_param("si", $new_cron_id, $tenant_id);
        $stmt->execute();

        return ['action' => 'created', 'cron_id' => $new_cron_id, 'daily_professionals' => $daily_professionals];
    }

    if ($daily_professionals === 0 && $current_cron_id !== '') {
        if ($api_key === '') {
            if ($fail_on_missing_key) {
                throw new \Exception('Falta configurar el token API de Fastcron para borrar el cron de planning.');
            }
            return ['action' => 'missing_api_key', 'cron_id' => $current_cron_id, 'daily_professionals' => 0];
        }

        fastcron_delete_cron($api_key, $current_cron_id);
        $mysqli->query("UPDATE payment_settings SET fastcron_planning_cron_id = NULL WHERE tenant_id = $tenant_id");

        return ['action' => 'deleted', 'cron_id' => '', 'daily_professionals' => 0];
    }

    return ['action' => 'unchanged', 'cron_id' => $current_cron_id, 'daily_professionals' => $daily_professionals];
}

function reminder_cron_url()
{
    return app_public_base_url() . 'cron_reminders.php?token=' . urlencode(CRON_WEBHOOK_TOKEN);
}

function professional_planning_cron_url()
{
    return app_public_base_url() . 'cron_professional_planning.php?token=' . urlencode(CRON_WEBHOOK_TOKEN);
}
