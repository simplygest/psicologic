<?php

function sector_texts_dir()
{
    return function_exists('app_global_upload_dir')
        ? app_global_upload_dir('sector-texts')
        : __DIR__ . '/uploads/global/sector-texts';
}

function sector_texts_default_key()
{
    return 'psicologia';
}

function sector_texts_builtin_psychology()
{
    return [
        'version' => 1,
        'key' => 'psicologia',
        'name' => 'Psicología',
        'description' => 'Configuración de textos para gabinetes de psicología y terapia.',
        'labels' => [
            'patient' => ['singular' => 'paciente', 'plural' => 'pacientes', 'titleSingular' => 'Paciente', 'titlePlural' => 'Pacientes'],
            'professional' => ['singular' => 'profesional', 'plural' => 'profesionales', 'titleSingular' => 'Profesional', 'titlePlural' => 'Profesionales'],
            'organization' => ['singular' => 'gabinete', 'plural' => 'gabinetes', 'titleSingular' => 'Gabinete', 'titlePlural' => 'Gabinetes'],
            'appointment' => ['singular' => 'cita', 'plural' => 'citas', 'titleSingular' => 'Cita', 'titlePlural' => 'Citas'],
            'session' => ['singular' => 'sesión', 'plural' => 'sesiones', 'titleSingular' => 'Sesión', 'titlePlural' => 'Sesiones'],
            'workPlan' => ['singular' => 'plan de trabajo', 'plural' => 'planes de trabajo', 'titleSingular' => 'Plan de trabajo', 'titlePlural' => 'Planes de trabajo'],
            'task' => ['singular' => 'tarea', 'plural' => 'tareas', 'titleSingular' => 'Tarea', 'titlePlural' => 'Tareas'],
            'evolution' => ['singular' => 'evolución', 'plural' => 'evoluciones', 'titleSingular' => 'Evolución', 'titlePlural' => 'Evolución'],
            'problem' => ['singular' => 'problema o diagnóstico', 'plural' => 'problemas o diagnósticos', 'titleSingular' => 'Problema o diagnóstico', 'titlePlural' => 'Problemas o diagnósticos'],
            'evaluation' => ['singular' => 'cuestionario', 'plural' => 'cuestionarios', 'titleSingular' => 'Cuestionario', 'titlePlural' => 'Cuestionarios'],
            'technique' => ['singular' => 'técnica', 'plural' => 'técnicas', 'titleSingular' => 'Técnica', 'titlePlural' => 'Técnicas'],
            'resource' => ['singular' => 'recurso', 'plural' => 'recursos', 'titleSingular' => 'Recurso', 'titlePlural' => 'Recursos'],
            'goal' => ['singular' => 'objetivo terapéutico', 'plural' => 'objetivos terapéuticos', 'titleSingular' => 'Objetivo terapéutico', 'titlePlural' => 'Objetivos terapéuticos']
        ],
        'clinicalTerms' => [
            'diagnosis' => 'diagnóstico clínico',
            'treatment' => 'intervención terapéutica',
            'exercise' => 'tarea terapéutica',
            'followUp' => 'seguimiento',
            'discharge' => 'alta'
        ],
        'services' => [
            'individual' => 'Individual',
            'couple' => 'Parejas',
            'family' => 'Familiar',
            'group' => 'Grupos'
        ],
        'appointmentServices' => [
            ['key' => 'individual', 'label' => 'Individual', 'name' => 'Sesion individual', 'enabledByDefault' => true],
            ['key' => 'couple', 'label' => 'Parejas', 'name' => 'Sesion de pareja', 'enabledByDefault' => false],
            ['key' => 'family', 'label' => 'Familiar', 'name' => 'Sesion familiar', 'enabledByDefault' => false],
            ['key' => 'group', 'label' => 'Grupos', 'name' => 'Sesion grupal', 'enabledByDefault' => false]
        ],
        'appointmentDurations' => [
            ['minutes' => 60, 'label' => '60 minutos', 'enabledByDefault' => true],
            ['minutes' => 90, 'label' => '90 minutos', 'enabledByDefault' => false],
            ['minutes' => 120, 'label' => '120 minutos', 'enabledByDefault' => false]
        ],
        'portal' => [
            'bookingCta' => 'Reserva tu cita',
            'myAppointments' => 'Mis citas',
            'myTasks' => 'Mis tareas'
        ],
        'reports' => [
            'internalPatientReport' => 'Informe interno de paciente',
            'patientReport' => 'Informe para paciente'
        ],
        'featureHints' => [
            'workPlan' => true,
            'patientPortal' => true,
            'clinicalEvolution' => true,
            'sessionFiles' => true
        ]
    ];
}

function sector_texts_merge_missing($config, $defaults)
{
    if (!is_array($config)) {
        $config = [];
    }
    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $config)) {
            $config[$key] = $value;
        } elseif (is_array($value) && is_array($config[$key])) {
            $config[$key] = sector_texts_merge_missing($config[$key], $value);
        }
    }
    return $config;
}

function sector_texts_is_list_array($value)
{
    if (!is_array($value)) {
        return false;
    }
    if (function_exists('array_is_list')) {
        return array_is_list($value);
    }
    return array_keys($value) === range(0, count($value) - 1);
}

function sector_texts_merge_overrides($base, $overrides)
{
    if (!is_array($base)) {
        $base = [];
    }
    if (!is_array($overrides)) {
        return $base;
    }
    foreach ($overrides as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        if (is_array($value)) {
            if (sector_texts_is_list_array($value)) {
                $base[$key] = $value;
            } else {
                $base[$key] = sector_texts_merge_overrides(
                    isset($base[$key]) && is_array($base[$key]) ? $base[$key] : [],
                    $value
                );
            }
            continue;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
        }
        if (is_scalar($value) || $value === null) {
            $base[$key] = $value;
        }
    }
    return $base;
}

function sector_texts_apply_custom_config($sector_texts, $dashboard_config = null, $plan_config = null)
{
    if (!is_array($sector_texts)) {
        $sector_texts = [];
    }
    if (!is_array($dashboard_config) || !is_array($plan_config)) {
        return $sector_texts;
    }
    if (!function_exists('plan_config_feature_enabled') || !plan_config_feature_enabled($plan_config, 'ui.customization', false)) {
        return $sector_texts;
    }
    $custom_texts = $dashboard_config['texts'] ?? null;
    if (!is_array($custom_texts)) {
        return $sector_texts;
    }
    return sector_texts_merge_overrides($sector_texts, $custom_texts);
}

function sector_appointment_services_from_texts($sector_texts)
{
    $items = [];
    $source = $sector_texts['appointmentServices'] ?? null;
    if (is_array($source)) {
        foreach ($source as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = trim((string) ($item['key'] ?? ''));
            if (!preg_match('/^[a-z0-9_-]{2,32}$/', $key)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? $item['name'] ?? $key));
            $name = trim((string) ($item['name'] ?? $label));
            $items[$key] = [
                'key' => $key,
                'label' => $label !== '' ? $label : ucfirst($key),
                'name' => $name !== '' ? $name : ($label !== '' ? $label : ucfirst($key)),
                'enabledByDefault' => !empty($item['enabledByDefault']),
                'sort' => (int) ($item['sort'] ?? (($index + 1) * 10))
            ];
        }
    }

    if (!$items && isset($sector_texts['services']) && is_array($sector_texts['services'])) {
        $index = 0;
        foreach ($sector_texts['services'] as $key => $label) {
            if (!preg_match('/^[a-z0-9_-]{2,32}$/', (string) $key)) {
                continue;
            }
            $label = trim((string) $label);
            $items[$key] = [
                'key' => $key,
                'label' => $label !== '' ? $label : ucfirst((string) $key),
                'name' => $label !== '' ? $label : ucfirst((string) $key),
                'enabledByDefault' => $index === 0,
                'sort' => ($index + 1) * 10
            ];
            $index++;
        }
    }

    if (!$items) {
        foreach (sector_texts_builtin_psychology()['appointmentServices'] as $item) {
            $items[$item['key']] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'name' => $item['name'],
                'enabledByDefault' => !empty($item['enabledByDefault']),
                'sort' => count($items) * 10 + 10
            ];
        }
    }

    uasort($items, fn($a, $b) => ($a['sort'] <=> $b['sort']) ?: strcmp($a['label'], $b['label']));
    return array_values($items);
}

function sector_appointment_durations_from_texts($sector_texts)
{
    $items = [];
    $source = $sector_texts['appointmentDurations'] ?? null;
    if (is_array($source)) {
        foreach ($source as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $minutes = (int) ($item['minutes'] ?? 0);
            if ($minutes <= 0 || $minutes > 480) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ($minutes . ' minutos')));
            $items[$minutes] = [
                'minutes' => $minutes,
                'label' => $label !== '' ? $label : ($minutes . ' minutos'),
                'enabledByDefault' => !empty($item['enabledByDefault']),
                'sort' => (int) ($item['sort'] ?? (($index + 1) * 10))
            ];
        }
    }

    if (!$items) {
        foreach (sector_texts_builtin_psychology()['appointmentDurations'] as $item) {
            $items[$item['minutes']] = [
                'minutes' => $item['minutes'],
                'label' => $item['label'],
                'enabledByDefault' => !empty($item['enabledByDefault']),
                'sort' => count($items) * 10 + 10
            ];
        }
    }

    uasort($items, fn($a, $b) => ($a['sort'] <=> $b['sort']) ?: ($a['minutes'] <=> $b['minutes']));
    return array_values($items);
}

function sector_default_appointment_service_keys($sector_texts)
{
    $services = sector_appointment_services_from_texts($sector_texts);
    $selected = array_values(array_map(
        fn($item) => $item['key'],
        array_filter($services, fn($item) => !empty($item['enabledByDefault']))
    ));
    return $selected ?: [$services[0]['key']];
}

function sector_default_appointment_duration_minutes($sector_texts)
{
    $durations = sector_appointment_durations_from_texts($sector_texts);
    $selected = array_values(array_map(
        fn($item) => (int) $item['minutes'],
        array_filter($durations, fn($item) => !empty($item['enabledByDefault']))
    ));
    return $selected ?: [(int) $durations[0]['minutes']];
}

function sector_appointment_config_for_db($mysqli)
{
    $sector_texts = sector_texts_for_db($mysqli);
    return [
        'services' => sector_appointment_services_from_texts($sector_texts),
        'durations' => sector_appointment_durations_from_texts($sector_texts)
    ];
}

function sector_texts_validate_key($key)
{
    return is_string($key) && preg_match('/^[a-z0-9_-]{2,32}$/', $key);
}

function sector_texts_read_file($key)
{
    if (!sector_texts_validate_key($key)) {
        return null;
    }
    $path = sector_texts_dir() . '/' . $key . '.json';
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

function sector_texts_for_key($key, $dashboard_config = null, $plan_config = null)
{
    $default_key = sector_texts_default_key();
    $defaults = sector_texts_builtin_psychology();
    $config = sector_texts_read_file($key);
    if (!is_array($config)) {
        $config = sector_texts_read_file($default_key);
    }
    $config = sector_texts_merge_missing($config, $defaults);
    if (!sector_texts_validate_key($config['key'] ?? '')) {
        $config['key'] = $default_key;
    }
    return sector_texts_apply_custom_config($config, $dashboard_config, $plan_config);
}

function sector_texts_available()
{
    $items = [];
    $dir = sector_texts_dir();
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $key = basename($path, '.json');
            if (!sector_texts_validate_key($key)) {
                continue;
            }
            $config = sector_texts_for_key($key);
            $items[$key] = [
                'key' => $key,
                'name' => $config['name'] ?? ucfirst($key),
                'description' => $config['description'] ?? ''
            ];
        }
    }
    if (!isset($items[sector_texts_default_key()])) {
        $fallback = sector_texts_builtin_psychology();
        $items[sector_texts_default_key()] = [
            'key' => sector_texts_default_key(),
            'name' => $fallback['name'],
            'description' => $fallback['description']
        ];
    }
    uasort($items, fn($a, $b) => strcmp($a['name'], $b['name']));
    return array_values($items);
}

function sector_texts_ensure_payment_column($mysqli)
{
    $res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'sector_texts_key'");
    if ($res && $res->num_rows === 0) {
        $mysqli->query("ALTER TABLE payment_settings ADD sector_texts_key VARCHAR(32) NOT NULL DEFAULT 'psicologia' AFTER dashboard_config_mode");
    }
}

function sector_texts_key_from_db($mysqli)
{
    sector_texts_ensure_payment_column($mysqli);
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("SELECT sector_texts_key FROM payment_settings WHERE tenant_id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $key = $row['sector_texts_key'] ?? sector_texts_default_key();
    if (!sector_texts_validate_key($key) || !sector_texts_read_file($key)) {
        return sector_texts_default_key();
    }
    return $key;
}

function sector_texts_for_db($mysqli)
{
    $dashboard_config = null;
    $plan_config = null;
    if (function_exists('dashboard_config_plan_key_from_db') && function_exists('dashboard_config_effective_mode_from_db') && function_exists('dashboard_config_for_mode') && function_exists('plan_config_for_key')) {
        $plan_key = dashboard_config_plan_key_from_db($mysqli);
        $plan_config = plan_config_for_key($plan_key);
        $dashboard_config = dashboard_config_for_mode(dashboard_config_effective_mode_from_db($mysqli, $plan_key));
    }
    return sector_texts_for_key(sector_texts_key_from_db($mysqli), $dashboard_config, $plan_config);
}

function knowledge_base_sector_has_data($mysqli, $sector_key = null)
{
    $sector_key = $sector_key ?: sector_texts_key_from_db($mysqli);
    if (!sector_texts_validate_key($sector_key)) {
        return false;
    }
    $table_res = $mysqli->query("SHOW TABLES LIKE 'knowledge_problems'");
    if (!$table_res || $table_res->num_rows === 0) {
        return false;
    }
    $column_res = $mysqli->query("SHOW COLUMNS FROM knowledge_problems LIKE 'sector_key'");
    if (!$column_res || $column_res->num_rows === 0) {
        return $sector_key === sector_texts_default_key();
    }
    $stmt = $mysqli->prepare("SELECT id FROM knowledge_problems WHERE sector_key = ? LIMIT 1");
    $stmt->bind_param("s", $sector_key);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}
