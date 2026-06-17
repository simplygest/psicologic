<?php

function sector_texts_dir()
{
    return __DIR__ . '/sector-texts';
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

function sector_texts_for_key($key)
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
    return $config;
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
    $res = $mysqli->query("SELECT sector_texts_key FROM payment_settings WHERE id = 1");
    $row = $res ? $res->fetch_assoc() : null;
    $key = $row['sector_texts_key'] ?? sector_texts_default_key();
    if (!sector_texts_validate_key($key) || !sector_texts_read_file($key)) {
        return sector_texts_default_key();
    }
    return $key;
}

function sector_texts_for_db($mysqli)
{
    return sector_texts_for_key(sector_texts_key_from_db($mysqli));
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
