<?php

function dashboard_config_dir()
{
    return __DIR__ . '/dashboard-config';
}

function dashboard_config_files()
{
    $dir = dashboard_config_dir();
    return [
        'simple' => $dir . '/simple.json',
        'advanced' => $dir . '/advanced.json',
        'custom' => $dir . '/custom.json'
    ];
}

function dashboard_config_advanced_defaults()
{
    return [
        'version' => 1,
        'features' => [
            'dashboard.quickAppointments' => true,
            'appointments.upcomingModal' => true,
            'appointments.cancelledAppointments' => true,
            'appointments.sessionWorkspace' => true,
            'patients.moreData' => true,
            'patients.evolution' => true,
            'patients.workPlan' => true,
            'patients.taskTemplates' => true,
            'patients.files' => true,
            'patients.reports' => true,
            'patients.transfer' => true,
            'reports.globalReports' => true,
            'settings.services' => true,
            'settings.bonuses' => true,
            'settings.onlinePayments' => true,
            'settings.email' => true,
            'settings.calendarSync' => true,
            'settings.legal' => true,
            'settings.team' => true,
            'public.teamPage' => true,
            'public.pricesPage' => true,
            'integrations.googleCalendar' => true,
            'integrations.googleEmail' => true,
            'integrations.icloudCalendar' => true
        ]
    ];
}

function dashboard_config_simple_defaults()
{
    return dashboard_config_advanced_defaults();
}

function dashboard_config_merge_missing($config, $defaults)
{
    if (!is_array($config)) {
        $config = [];
    }
    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $config)) {
            $config[$key] = $value;
        } elseif (is_array($value) && is_array($config[$key])) {
            $config[$key] = dashboard_config_merge_missing($config[$key], $value);
        }
    }
    return $config;
}

function dashboard_config_validate($config, &$error = '')
{
    if (!is_array($config)) {
        $error = 'El JSON debe contener un objeto.';
        return false;
    }
    if (!isset($config['features']) || !is_array($config['features'])) {
        $error = 'El JSON debe incluir el objeto "features".';
        return false;
    }
    foreach ($config['features'] as $key => $value) {
        if (!is_string($key) || $key === '') {
            $error = 'Todas las claves de "features" deben tener nombre.';
            return false;
        }
        if (!is_bool($value)) {
            $error = 'Todas las opciones de "features" deben ser true o false.';
            return false;
        }
    }
    return true;
}

function dashboard_config_pretty_json($config)
{
    return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

function dashboard_config_write_file($path, $config)
{
    return file_put_contents($path, dashboard_config_pretty_json($config), LOCK_EX) !== false;
}

function dashboard_config_read_file($path)
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function dashboard_config_ensure_files()
{
    $dir = dashboard_config_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $files = dashboard_config_files();
    $advanced = dashboard_config_advanced_defaults();
    $simple = dashboard_config_simple_defaults();

    $advanced_file = dashboard_config_read_file($files['advanced']);
    $advanced_file = dashboard_config_merge_missing($advanced_file, $advanced);
    dashboard_config_write_file($files['advanced'], $advanced_file);

    $simple_file = dashboard_config_read_file($files['simple']);
    $simple_file = dashboard_config_merge_missing($simple_file, $simple);
    dashboard_config_write_file($files['simple'], $simple_file);

    $custom = dashboard_config_read_file($files['custom']);
    $custom = dashboard_config_merge_missing($custom, $advanced);
    $error = '';
    if (!dashboard_config_validate($custom, $error)) {
        $custom = $advanced;
    }
    dashboard_config_write_file($files['custom'], $custom);
}

function dashboard_config_for_mode($mode)
{
    dashboard_config_ensure_files();
    $files = dashboard_config_files();
    if (!isset($files[$mode])) {
        $mode = 'simple';
    }
    $config = dashboard_config_read_file($files[$mode]);
    $defaults = $mode === 'simple' ? dashboard_config_simple_defaults() : dashboard_config_advanced_defaults();
    $config = dashboard_config_merge_missing($config, $defaults);
    $error = '';
    if (!dashboard_config_validate($config, $error)) {
        $config = $defaults;
    }
    return $config;
}

function dashboard_config_mode_from_db($mysqli)
{
    dashboard_config_ensure_payment_column($mysqli);
    $res = $mysqli->query("SELECT dashboard_config_mode FROM payment_settings WHERE id = 1");
    $row = $res ? $res->fetch_assoc() : null;
    $mode = $row['dashboard_config_mode'] ?? 'simple';
    return in_array($mode, ['simple', 'advanced', 'custom'], true) ? $mode : 'simple';
}

function dashboard_config_ensure_payment_column($mysqli)
{
    $res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'dashboard_config_mode'");
    if ($res && $res->num_rows === 0) {
        $mysqli->query("ALTER TABLE payment_settings ADD dashboard_config_mode VARCHAR(16) NOT NULL DEFAULT 'simple'");
    }
}

function dashboard_config_save_custom_json($json, &$error = '')
{
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $error = 'JSON invalido: ' . json_last_error_msg();
        return false;
    }
    if (!dashboard_config_validate($decoded, $error)) {
        return false;
    }
    $decoded = dashboard_config_merge_missing($decoded, dashboard_config_advanced_defaults());
    $files = dashboard_config_files();
    dashboard_config_ensure_files();
    if (!dashboard_config_write_file($files['custom'], $decoded)) {
        $error = 'No se pudo guardar el archivo custom.json.';
        return false;
    }
    return true;
}
