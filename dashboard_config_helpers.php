<?php
require_once __DIR__ . '/app_paths.php';

function dashboard_config_dir()
{
    return app_global_upload_dir('dashboard-config');
}

function dashboard_config_files()
{
    $dir = dashboard_config_dir();
    return [
        'simple' => $dir . '/simple.json',
        'advanced' => $dir . '/advanced.json',
        'custom' => dashboard_config_custom_file()
    ];
}

function dashboard_config_global_custom_file()
{
    return dashboard_config_dir() . '/custom.json';
}

function dashboard_config_custom_file()
{
    return app_tenant_config_file('dashboard-custom.json');
}

function dashboard_config_advanced_defaults()
{
    return [
        'version' => 1,
        'features' => [
            'dashboard.quickAppointments' => true,
            'dashboard.globalSearch' => true,
            'patientPortal.quickAppointment' => true,
            'patientPortal.appointmentHistory' => true,
            'patientPortal.visibleTasks' => true,
            'appointments.upcomingModal' => true,
            'appointments.cancelledAppointments' => true,
            'appointments.sessionWorkspace' => true,
            'appointments.sessionTasks' => true,
            'dashboard.patientPhoto' => true,
            'patientPortal.patientPhoto' => true,
            'patients.moreData' => true,
            'patients.evolution' => true,
            'patients.evolutionTab' => true,
            'patients.workPlan' => true,
            'patients.workPlanTab' => true,
            'patients.tasks' => true,
            'patients.taskTemplates' => true,
            'patients.files' => true,
            'patients.filesTab' => true,
            'patients.bonusesTab' => true,
            'patients.reports' => true,
            'patients.transfer' => true,
            'knowledgeBase.enabled' => true,
            'knowledgeBase.importTasks' => true,
            'questionnaires.enabled' => true,
            'reports.globalReports' => true,
            'patientPortal.enabled' => true,
            'patientPortal.invitations' => true,
            'onlineBooking.enabled' => true,
            'tasks.enabled' => true,
            'closures.enabled' => true,
            'bonuses.enabled' => true,
            'upcomingAppointments.planning' => true,
            'appointments.effectiveDuration' => true,
            'taskTemplates.enabled' => true,
            'onlinePayments.enabled' => true,
            'payments.online' => true,
            'reminders.patient24h' => true,
            'calendarSync.enabled' => true,
            'branding.customLogo' => true,
            'team.enabled' => true,
            'ui.customization' => true,
            'settings.services' => true,
            'settings.bonuses' => true,
            'settings.taskTemplates' => true,
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
        ],
        'texts' => []
    ];
}

function dashboard_config_simple_defaults()
{
    $config = dashboard_config_advanced_defaults();
    foreach (dashboard_config_simple_disabled_features() as $feature) {
        $config['features'][$feature] = false;
    }
    return $config;
}

function dashboard_config_simple_disabled_features()
{
    return [
        'settings.taskTemplates',
        'settings.bonuses',
        'settings.team',
        'patients.workPlan',
        'patients.workPlanTab',
        'patients.evolution',
        'patients.evolutionTab',
        'patients.files',
        'patients.filesTab',
        'patients.bonusesTab',
        'dashboard.patientPhoto',
        'patientPortal.patientPhoto',
        'bonuses.enabled',
        'tasks.enabled',
        'taskTemplates.enabled',
        'patients.tasks',
        'patientPortal.visibleTasks',
        'appointments.sessionTasks',
        'knowledgeBase.enabled',
        'knowledgeBase.importTasks',
        'questionnaires.enabled'
    ];
}

function plan_config_dir()
{
    return app_global_upload_dir('plan-config');
}

function plan_config_normalize_key($plan_key = 'default', $fallback = 'default')
{
    $key = strtolower(trim((string) $plan_key));
    return in_array($key, ['novus', 'magister', 'summum'], true) ? $key : $fallback;
}

function plan_config_file($plan_key = 'default')
{
    $plan_key = plan_config_normalize_key($plan_key, 'default');
    $plan_key = preg_match('/^[a-z0-9_-]{2,32}$/', $plan_key) ? $plan_key : 'default';
    $path = plan_config_dir() . '/' . $plan_key . '.json';
    return is_file($path) ? $path : plan_config_dir() . '/default.json';
}

function plan_config_defaults()
{
    return [
        'version' => 1,
        'plan' => [
            'key' => 'default',
            'label' => 'Plan actual',
            'features' => [
                'patientPortal.enabled' => false,
                'patientPortal.invitations' => false,
                'onlineBooking.enabled' => false,
                'tasks.enabled' => false,
                'closures.enabled' => false,
                'bonuses.enabled' => false,
                'reports.globalReports' => false,
                'upcomingAppointments.planning' => false,
                'appointments.effectiveDuration' => false,
                'taskTemplates.enabled' => false,
                'onlinePayments.enabled' => false,
                'payments.online' => false,
                'knowledgeBase.enabled' => false,
                'knowledgeBase.importTasks' => false,
                'questionnaires.enabled' => false,
                'reminders.patient24h' => false,
                'calendarSync.enabled' => false,
                'branding.customLogo' => false,
                'team.enabled' => false,
                'ui.customization' => false
            ],
            'limits' => [
                'appointmentDurations' => [60, 90, 120]
            ]
        ]
    ];
}

function plan_config_for_key($plan_key = 'default')
{
    $defaults = plan_config_defaults();
    $path = plan_config_file($plan_key);
    $config = dashboard_config_read_file($path);
    $config = dashboard_config_merge_missing($config, $defaults);
    return is_array($config) ? $config : $defaults;
}

function plan_config_for_current()
{
    return plan_config_for_key('default');
}

function dashboard_config_feature_enabled($config, $feature, $default = false)
{
    return isset($config['features'][$feature]) ? (bool) $config['features'][$feature] : (bool) $default;
}

function plan_config_feature_enabled($config, $feature, $default = false)
{
    return isset($config['plan']['features'][$feature]) ? (bool) $config['plan']['features'][$feature] : (bool) $default;
}

function app_feature_enabled($dashboard_config, $plan_config, $feature, $default = false)
{
    return dashboard_config_feature_enabled($dashboard_config, $feature, $default)
        && plan_config_feature_enabled($plan_config, $feature, $default);
}

function app_feature_enabled_from_db($mysqli, $feature, $default = false)
{
    $plan_key = dashboard_config_plan_key_from_db($mysqli);
    return app_feature_enabled(
        dashboard_config_for_mode(dashboard_config_effective_mode_from_db($mysqli, $plan_key)),
        plan_config_for_key($plan_key),
        $feature,
        $default
    );
}

function dashboard_config_plan_key_from_db($mysqli)
{
    if (function_exists('current_tenant')) {
        $tenant = current_tenant();
        $tenant_plan_key = plan_config_normalize_key($tenant['plan_key'] ?? '', '');
        if ($tenant_plan_key !== '') {
            return $tenant_plan_key;
        }
    }

    return 'default';
}

function dashboard_config_effective_mode_for_plan($plan_key)
{
    $plan_key = plan_config_normalize_key($plan_key, 'default');
    $plan_config = plan_config_for_key($plan_key);
    return plan_config_feature_enabled($plan_config, 'ui.customization', false) ? 'custom' : 'advanced';
}

function dashboard_config_effective_mode_from_db($mysqli, $plan_key = null)
{
    $plan_key = plan_config_normalize_key($plan_key ?: dashboard_config_plan_key_from_db($mysqli), 'default');
    $plan_config = plan_config_for_key($plan_key);
    if (!plan_config_feature_enabled($plan_config, 'ui.customization', false)) {
        return 'advanced';
    }

    return dashboard_config_mode_from_db($mysqli) === 'custom' ? 'custom' : 'advanced';
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
    if (isset($config['texts']) && (!is_array($config['texts']) || dashboard_config_is_list_array($config['texts']))) {
        $error = 'La sección opcional "texts" debe ser un objeto.';
        return false;
    }
    return true;
}

function dashboard_config_is_list_array($value)
{
    if (!is_array($value)) {
        return false;
    }
    if (function_exists('array_is_list')) {
        return array_is_list($value);
    }
    return array_keys($value) === range(0, count($value) - 1);
}

function dashboard_config_pretty_json($config)
{
    return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

function dashboard_config_write_file($path, $config)
{
    $dir = dirname($path);
    if (!app_ensure_dir($dir)) {
        return false;
    }
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
    if (!is_array($custom)) {
        $custom = dashboard_config_read_file(dashboard_config_global_custom_file());
    }
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
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("SELECT dashboard_config_mode FROM payment_settings WHERE tenant_id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $mode = $row['dashboard_config_mode'] ?? 'simple';
    return in_array($mode, ['simple', 'advanced', 'custom'], true) ? $mode : 'simple';
}

function dashboard_config_ensure_payment_column($mysqli)
{
    $res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'dashboard_config_mode'");
    if ($res && $res->num_rows === 0) {
        $mysqli->query("ALTER TABLE payment_settings ADD dashboard_config_mode VARCHAR(16) NOT NULL DEFAULT 'simple'");
    }
    $res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'tenant_id'");
    if ($res && $res->num_rows === 0) {
        $mysqli->query("ALTER TABLE payment_settings ADD tenant_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id");
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
