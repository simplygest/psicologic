<?php
session_start();
require_once '../db.php';
require_once '../mail_helpers.php';
require_once '../sms_helpers.php';
require_once '../settings_helpers.php';
require_once '../dashboard_config_helpers.php';
require_once '../payment_helpers.php';
require_once '../google_helpers.php';
require_once '../livekit_helpers.php';
require_once '../invoice_helpers.php';
require_once '../message_template_helpers.php';
require_once '../app_log_helpers.php';
require_once '../fastcron_helpers.php';
require_once '../urlme_helpers.php';
require_once '../cabinet_helpers.php';
require_once '../workoutx_helpers.php';
header('Content-Type: application/json');

$is_superadmin = ($_SESSION['role'] ?? '') === 'superadmin';
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'superadmin', 'reception', 'administration', 'technical'], true)) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

if (function_exists('app_auto_schema_migrations_enabled') && app_auto_schema_migrations_enabled()) {
    ensure_patient_management_tables($mysqli);
    ensure_patient_evolution_tables($mysqli);
    ensure_patient_document_tables($mysqli);
    ensure_patient_work_plan_tables($mysqli);
    ensure_work_plan_task_template_tables($mysqli);
    ensure_appointment_payment_columns($mysqli);
    ensure_appointment_services_tables($mysqli);
    ensure_appointment_locations_table($mysqli);
    ensure_bonus_tables($mysqli);
    ensure_payment_attempts_table($mysqli);
    ensure_invoice_schema($mysqli);
    ensure_cabinet_schema($mysqli);
    ensure_knowledge_base_sector_schema($mysqli);
}

$action = $_GET['action'] ?? '';
$tenant_id = current_tenant_id();
$member_permissions = cabinet_member_permissions_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0), $_SESSION['role'] ?? '');

function require_member_permission($permission, $error = 'No tienes permiso para realizar esta accion.')
{
    global $is_superadmin, $member_permissions;
    if ($is_superadmin || !empty($member_permissions[$permission])) {
        return;
    }
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

$statistics_actions = ['admin_stats'];
$patient_actions = [
    'get_patients', 'list_patients', 'waiting_list_patients', 'patient_reports', 'create_patient_report',
    'create_custom_patient_report', 'patient_report_suggestions', 'add_suggested_patient_report',
    'update_patient_report', 'patient_report', 'patient_evolution', 'save_patient_evolution',
    'patient_files', 'save_patient_document_file', 'download_patient_document_file',
    'delete_patient_document_file', 'patient_work_plan', 'save_patient_work_plan_task',
    'add_fitness_exercise_to_work_plan', 'set_patient_work_plan_task_status',
    'delete_patient_work_plan_task', 'patient_appointments', 'transfer_patient_professional',
    'send_patient_invite'
];
$appointment_actions = [
    'appointment_payment_detail', 'appointment_session', 'update_appointment_payment',
    'update_appointment_status', 'update_appointment_session_notes', 'update_appointment_online_details',
    'send_appointment_online_link', 'send_manual_appointment_reminder', 'regenerate_appointment_livekit_link', 'quick_appointments',
    'upcoming_appointments', 'list_closed_days', 'add_closed_day', 'delete_closed_day', 'delete_closed_range'
];
$settings_actions = [
    'save_dashboard_custom_config', 'save_bonuses', 'create_service_catalog_item',
    'delete_service_catalog_item', 'create_location_catalog_item', 'delete_location_catalog_item',
    'save_services', 'save_payment_settings', 'get_message_template', 'save_message_template',
    'get_custom_domain', 'save_custom_domain'
];
if (in_array($action, $statistics_actions, true)) {
    require_member_permission('statistics', 'No tienes permiso para acceder a estadisticas.');
}
if (in_array($action, $patient_actions, true)) {
    require_member_permission('patients', 'No tienes permiso para acceder a pacientes.');
}
if (in_array($action, $appointment_actions, true)) {
    require_member_permission('appointments', 'No tienes permiso para acceder a citas.');
}
if (in_array($action, $settings_actions, true)) {
    require_member_permission('settings', 'No tienes permiso para modificar la configuracion.');
}
if ($action === 'generate_invite' || $action === 'send_invite_email') {
    require_member_permission('create_patients', 'No tienes permiso para crear nuevos pacientes.');
}
if ($action === 'list_app_logs' && !$is_superadmin) {
    echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede consultar el log.']);
    exit;
}

function ensure_action_feature($mysqli, $feature, $error)
{
    if (!app_feature_enabled_from_db($mysqli, $feature, false)) {
        echo json_encode(['success' => false, 'error' => $error]);
        exit;
    }
}

function ensure_tenant_domains_runtime_table($mysqli)
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS tenant_domains (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED NOT NULL,
            domain VARCHAR(255) NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_tenant_domains_domain (domain),
            KEY idx_tenant_domains_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function normalize_custom_domain_input($value, &$error = '')
{
    $value = strtolower(trim((string) $value));
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('#^http://#i', $value)) {
        $error = 'Usa un dominio seguro. No introduzcas direcciones con http://.';
        return '';
    }

    if (substr_count(strtolower($value), 'https://') > 1) {
        $error = 'La URL no es valida. Revisa que no hayas pegado https:// dos veces.';
        return '';
    }

    $value = preg_replace('#^https://#i', '', $value);
    $value = trim($value, "/ \t\n\r\0\x0B");

    if ($value === '' || strpos($value, '/') !== false || strpos($value, '?') !== false || strpos($value, '#') !== false) {
        $error = 'Introduce solo el dominio, sin rutas ni parametros.';
        return '';
    }

    if (preg_match('/:\d+$/', $value)) {
        $error = 'Introduce solo el dominio, sin puerto.';
        return '';
    }

    if (!preg_match('/^(?=.{4,255}$)(?!-)(?:[a-z0-9-]{1,63}\.)+[a-z]{2,63}$/', $value)) {
        $error = 'El dominio no parece valido. Ejemplo: tudominio.es';
        return '';
    }

    $domain_candidates = [$value];
    if (strpos($value, 'www.') === 0) {
        $domain_candidates[] = substr($value, 4);
    } else {
        $domain_candidates[] = 'www.' . $value;
    }
    if (array_intersect(array_unique($domain_candidates), tenant_platform_domains())) {
        $error = 'Ese dominio pertenece a la plataforma y no puede configurarse como dominio personalizado.';
        return '';
    }

    return $value;
}

function work_plan_task_status_enabled($mysqli)
{
    $tenant_id = current_tenant_id();
    $res = $mysqli->query("SELECT work_plan_task_status_enabled FROM payment_settings WHERE tenant_id = $tenant_id LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
        return (int) ($row['work_plan_task_status_enabled'] ?? 1) === 1;
    }
    return true;
}

function table_exists($mysqli, $table_name)
{
    $table_name = $mysqli->real_escape_string($table_name);
    $res = $mysqli->query("SHOW TABLES LIKE '$table_name'");
    return $res && $res->num_rows > 0;
}

function column_exists($mysqli, $table_name, $column_name)
{
    $table_name = $mysqli->real_escape_string($table_name);
    $column_name = $mysqli->real_escape_string($column_name);
    $res = $mysqli->query("SHOW COLUMNS FROM `$table_name` LIKE '$column_name'");
    return $res && $res->num_rows > 0;
}

function index_exists($mysqli, $table_name, $index_name)
{
    $table_name = $mysqli->real_escape_string($table_name);
    $index_name = $mysqli->real_escape_string($index_name);
    $res = $mysqli->query("SHOW INDEX FROM `$table_name` WHERE Key_name = '$index_name'");
    return $res && $res->num_rows > 0;
}

function ensure_fitness_exercise_media_columns($mysqli)
{
    if (!table_exists($mysqli, 'fitness_exercises')) {
        return;
    }
    if (!column_exists($mysqli, 'fitness_exercises', 'image_url')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD image_url VARCHAR(500) DEFAULT NULL AFTER source_ids");
    }
    if (!column_exists($mysqli, 'fitness_exercises', 'aliases')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD aliases TEXT DEFAULT NULL AFTER image_url");
    }
    if (!column_exists($mysqli, 'fitness_exercises', 'external_source')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD external_source VARCHAR(80) DEFAULT NULL AFTER aliases");
    }
    if (!column_exists($mysqli, 'fitness_exercises', 'external_id')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD external_id VARCHAR(120) DEFAULT NULL AFTER external_source");
    }
    if (!column_exists($mysqli, 'fitness_exercises', 'workoutx_body_part')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD workoutx_body_part VARCHAR(120) DEFAULT NULL AFTER external_id");
    }
    if (!column_exists($mysqli, 'fitness_exercises', 'workoutx_target')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD workoutx_target VARCHAR(120) DEFAULT NULL AFTER workoutx_body_part");
    }
    if (!column_exists($mysqli, 'fitness_exercises', 'workoutx_equipment')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD workoutx_equipment VARCHAR(120) DEFAULT NULL AFTER workoutx_target");
    }
    if (!column_exists($mysqli, 'fitness_exercises', 'description_en')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD description_en TEXT DEFAULT NULL AFTER description_es");
    }
    if (!column_exists($mysqli, 'fitness_exercises', 'instructions_en')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD instructions_en LONGTEXT DEFAULT NULL AFTER cues_es");
    }
    if (!column_exists($mysqli, 'fitness_exercises', 'instructions_es')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD instructions_es LONGTEXT DEFAULT NULL AFTER instructions_en");
    }
    if (!column_exists($mysqli, 'fitness_exercises', 'secondary_muscles_en')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD secondary_muscles_en TEXT DEFAULT NULL AFTER instructions_es");
    }
    if (!column_exists($mysqli, 'fitness_exercises', 'calories_per_min')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD calories_per_min DECIMAL(6,2) DEFAULT NULL AFTER secondary_muscles_en");
    }
    if (!column_exists($mysqli, 'fitness_exercises', 'workoutx_raw_json')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD workoutx_raw_json LONGTEXT DEFAULT NULL AFTER workoutx_equipment");
    }
    if (!column_exists($mysqli, 'fitness_exercises', 'workoutx_details_synced_at')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD workoutx_details_synced_at DATETIME DEFAULT NULL AFTER workoutx_raw_json");
    }
    if (!index_exists($mysqli, 'fitness_exercises', 'idx_fitness_exercises_external')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD INDEX idx_fitness_exercises_external (external_source, external_id)");
    }
}

function workoutx_remote_value(array $remote, array $keys)
{
    foreach ($keys as $key) {
        if (isset($remote[$key]) && trim((string) $remote[$key]) !== '') {
            $value = $remote[$key];
            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map('strval', $value)));
            }
            return trim((string) $value);
        }
    }
    return '';
}

function workoutx_local_exercise_id_from_remote(array $remote)
{
    $external_id = workoutx_remote_value($remote, ['id', 'exerciseId', 'exercise_id']);
    $seed = $external_id !== '' ? $external_id : workoutx_remote_value($remote, ['name']);
    $seed = preg_replace('/[^a-z0-9]+/i', '_', $seed);
    $seed = trim((string) $seed, '_');
    if ($seed === '') {
        $seed = substr(sha1(json_encode($remote)), 0, 16);
    }
    return 'WX_' . substr($seed, 0, 27);
}

function ensure_workoutx_exercise_local(mysqli $mysqli, array $remote)
{
    if (!table_exists($mysqli, 'fitness_exercises')) {
        return '';
    }
    ensure_fitness_exercise_media_columns($mysqli);

    $external_id = workoutx_remote_value($remote, ['id', 'exerciseId', 'exercise_id']);
    if ($external_id !== '') {
        $stmt = $mysqli->prepare("SELECT exercise_id FROM fitness_exercises WHERE external_source = 'workoutx' AND external_id = ? LIMIT 1");
        $stmt->bind_param("s", $external_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        if ($existing && !empty($existing['exercise_id'])) {
            return (string) $existing['exercise_id'];
        }
    }

    $exercise_id = workoutx_local_exercise_id_from_remote($remote);
    $name = workoutx_remote_value($remote, ['name']);
    if ($name === '') {
        return '';
    }
    $body_part = workoutx_remote_value($remote, ['bodyPart', 'body_part']);
    $target = workoutx_remote_value($remote, ['target', 'targetMuscle', 'target_muscle']);
    $equipment = workoutx_remote_value($remote, ['equipment', 'equipmentName', 'equipment_name']);
    $difficulty = workoutx_remote_value($remote, ['difficulty', 'level']);
    $gif_url = workoutx_remote_value($remote, ['gifUrl', 'gif_url', 'imageUrl', 'image_url']);
    $description = workoutx_remote_value($remote, ['description']);
    if ($description === '' && !empty($remote['instructions']) && is_array($remote['instructions'])) {
        $description = implode("\n", array_filter(array_map('strval', $remote['instructions'])));
    }
    $aliases = $name;
    $source = 'workoutx';

    $stmt = $mysqli->prepare("
        INSERT INTO fitness_exercises
            (exercise_id, name_en, name_es, category, difficulty, description_es, cues_es, image_url, aliases, external_source, external_id, workoutx_body_part, workoutx_target, workoutx_equipment, active)
        VALUES
            (?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            image_url = COALESCE(NULLIF(VALUES(image_url), ''), image_url),
            aliases = COALESCE(NULLIF(VALUES(aliases), ''), aliases),
            external_source = 'workoutx',
            external_id = COALESCE(NULLIF(VALUES(external_id), ''), external_id),
            workoutx_body_part = COALESCE(NULLIF(VALUES(workoutx_body_part), ''), workoutx_body_part),
            workoutx_target = COALESCE(NULLIF(VALUES(workoutx_target), ''), workoutx_target),
            workoutx_equipment = COALESCE(NULLIF(VALUES(workoutx_equipment), ''), workoutx_equipment),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param("sssssssssssss", $exercise_id, $name, $name, $body_part, $difficulty, $description, $gif_url, $aliases, $source, $external_id, $body_part, $target, $equipment);
    $stmt->execute();

    return $exercise_id;
}

function ensure_knowledge_table_sector_key($mysqli, $table_name)
{
    if (!table_exists($mysqli, $table_name)) {
        return;
    }
    if (!column_exists($mysqli, $table_name, 'sector_key')) {
        $position = column_exists($mysqli, $table_name, 'id') ? ' AFTER id' : ' FIRST';
        $mysqli->query("ALTER TABLE `$table_name` ADD sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia'$position");
    }
    $mysqli->query("UPDATE `$table_name` SET sector_key = 'psicologia' WHERE sector_key IS NULL OR sector_key = ''");
    $index_name = 'idx_' . $table_name . '_sector';
    if (!index_exists($mysqli, $table_name, $index_name)) {
        $mysqli->query("ALTER TABLE `$table_name` ADD INDEX `$index_name` (sector_key)");
    }
}

function ensure_knowledge_sector_code_unique($mysqli, $table_name, $code_column)
{
    if (!table_exists($mysqli, $table_name) || !column_exists($mysqli, $table_name, $code_column)) {
        return;
    }
    $table_sql = $mysqli->real_escape_string($table_name);
    $indexes_res = $mysqli->query("SHOW INDEX FROM `$table_sql`");
    if (!$indexes_res) {
        return;
    }
    $indexes = [];
    while ($row = $indexes_res->fetch_assoc()) {
        $key = $row['Key_name'] ?? '';
        if ($key === '' || $key === 'PRIMARY' || (int) ($row['Non_unique'] ?? 1) !== 0) {
            continue;
        }
        $indexes[$key][] = [
            'column' => $row['Column_name'] ?? '',
            'seq' => (int) ($row['Seq_in_index'] ?? 0)
        ];
    }
    foreach ($indexes as $key => $columns) {
        usort($columns, fn($a, $b) => $a['seq'] <=> $b['seq']);
        $column_names = array_map(fn($item) => $item['column'], $columns);
        if ($column_names === [$code_column]) {
            $key_sql = str_replace('`', '``', $key);
            $mysqli->query("ALTER TABLE `$table_name` DROP INDEX `$key_sql`");
        }
    }
    $sector_index = 'uniq_' . $table_name . '_sector_code';
    if (!index_exists($mysqli, $table_name, $sector_index)) {
        $mysqli->query("ALTER TABLE `$table_name` ADD UNIQUE `$sector_index` (sector_key, `$code_column`)");
    }
}

function ensure_knowledge_base_sector_schema($mysqli)
{
    if (function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_areas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            area_code VARCHAR(64) NOT NULL,
            name VARCHAR(180) NOT NULL,
            description TEXT DEFAULT NULL,
            UNIQUE uniq_knowledge_areas_sector_code (sector_key, area_code),
            INDEX idx_knowledge_areas_sector (sector_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_sources (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            source_code VARCHAR(64) NOT NULL,
            name VARCHAR(180) DEFAULT NULL,
            organization VARCHAR(180) DEFAULT NULL,
            title VARCHAR(255) DEFAULT NULL,
            url VARCHAR(500) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            UNIQUE uniq_knowledge_sources_sector_code (sector_key, source_code),
            INDEX idx_knowledge_sources_sector (sector_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_techniques (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            technique_code VARCHAR(64) NOT NULL,
            name VARCHAR(180) NOT NULL,
            description TEXT DEFAULT NULL,
            risk_level VARCHAR(32) DEFAULT NULL,
            UNIQUE uniq_knowledge_techniques_sector_code (sector_key, technique_code),
            INDEX idx_knowledge_techniques_sector (sector_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_problems (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            problem_code VARCHAR(64) NOT NULL,
            area_id INT UNSIGNED NOT NULL,
            name VARCHAR(180) NOT NULL,
            alias VARCHAR(180) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            population VARCHAR(120) DEFAULT NULL,
            risk_level VARCHAR(32) DEFAULT NULL,
            UNIQUE uniq_knowledge_problems_sector_code (sector_key, problem_code),
            INDEX idx_knowledge_problems_sector (sector_key),
            INDEX idx_knowledge_problems_area (area_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_tasks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            task_code VARCHAR(64) NOT NULL,
            technique_id INT UNSIGNED DEFAULT NULL,
            title VARCHAR(220) NOT NULL,
            description TEXT DEFAULT NULL,
            objective TEXT DEFAULT NULL,
            risk_level VARCHAR(32) DEFAULT NULL,
            estimated_duration VARCHAR(120) DEFAULT NULL,
            UNIQUE uniq_knowledge_tasks_sector_code (sector_key, task_code),
            INDEX idx_knowledge_tasks_sector (sector_key),
            INDEX idx_knowledge_tasks_technique (technique_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_problem_techniques (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            problem_id INT UNSIGNED NOT NULL,
            technique_id INT UNSIGNED NOT NULL,
            UNIQUE uniq_knowledge_problem_techniques (sector_key, problem_id, technique_id),
            INDEX idx_knowledge_problem_techniques_sector (sector_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_recommendations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            recommendation_code VARCHAR(64) DEFAULT NULL,
            problem_id INT UNSIGNED NOT NULL,
            technique_id INT UNSIGNED NOT NULL,
            task_id INT UNSIGNED NOT NULL,
            priority VARCHAR(32) DEFAULT NULL,
            clinical_note TEXT DEFAULT NULL,
            UNIQUE uniq_knowledge_recommendations_sector_code (sector_key, recommendation_code),
            INDEX idx_knowledge_recommendations_sector (sector_key),
            INDEX idx_knowledge_recommendations_problem (problem_id),
            INDEX idx_knowledge_recommendations_technique (technique_id),
            INDEX idx_knowledge_recommendations_task (task_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_questionnaires (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            questionnaire_code VARCHAR(64) NOT NULL,
            problem_id INT UNSIGNED NOT NULL,
            name VARCHAR(220) NOT NULL,
            use_area VARCHAR(180) DEFAULT NULL,
            questionnaire_type VARCHAR(120) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            resource_kind VARCHAR(30) NOT NULL DEFAULT 'questionnaire',
            UNIQUE uniq_knowledge_questionnaires_sector_code (sector_key, questionnaire_code),
            INDEX idx_knowledge_questionnaires_sector (sector_key),
            INDEX idx_knowledge_questionnaires_problem (problem_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_problem_sources (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            problem_id INT UNSIGNED NOT NULL,
            source_id INT UNSIGNED NOT NULL,
            UNIQUE uniq_knowledge_problem_sources (sector_key, problem_id, source_id),
            INDEX idx_knowledge_problem_sources_sector (sector_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach ([
        'knowledge_areas',
        'knowledge_problems',
        'knowledge_techniques',
        'knowledge_tasks',
        'knowledge_sources',
        'knowledge_questionnaires',
        'knowledge_recommendations',
        'knowledge_problem_sources'
    ] as $table_name) {
        ensure_knowledge_table_sector_key($mysqli, $table_name);
    }
    ensure_knowledge_sector_code_unique($mysqli, 'knowledge_areas', 'area_code');
    ensure_knowledge_sector_code_unique($mysqli, 'knowledge_problems', 'problem_code');
    ensure_knowledge_sector_code_unique($mysqli, 'knowledge_techniques', 'technique_code');
    ensure_knowledge_sector_code_unique($mysqli, 'knowledge_tasks', 'task_code');
    ensure_knowledge_sector_code_unique($mysqli, 'knowledge_sources', 'source_code');
    ensure_knowledge_sector_code_unique($mysqli, 'knowledge_questionnaires', 'questionnaire_code');
    if (!column_exists($mysqli, 'knowledge_questionnaires', 'resource_kind')) {
        $mysqli->query("ALTER TABLE knowledge_questionnaires ADD resource_kind VARCHAR(30) NOT NULL DEFAULT 'questionnaire' AFTER notes");
    }
}

function current_knowledge_sector_key($mysqli)
{
    $sector_key = sector_texts_key_from_db($mysqli);
    return sector_texts_validate_key($sector_key) ? $sector_key : sector_texts_default_key();
}

function professional_knowledge_sector_columns_available($mysqli)
{
    return column_exists($mysqli, 'professional_settings', 'knowledge_sector_mode')
        && column_exists($mysqli, 'professional_settings', 'knowledge_sector_keys_json');
}

function knowledge_multi_sector_enabled($mysqli)
{
    return app_feature_enabled_from_db($mysqli, 'knowledgeBase.enabled', false)
        && (app_feature_enabled_from_db($mysqli, 'knowledgeBase.multiSector', false)
            || app_feature_enabled_from_db($mysqli, 'ui.customization', false));
}

function knowledge_related_sector_presets()
{
    return [
        'psicologia' => ['psicopedagogia', 'logopedia', 'sexologia'],
        'sexologia' => ['psicologia'],
        'psicopedagogia' => ['psicologia', 'logopedia'],
        'logopedia' => ['psicopedagogia'],
        'fisioterapia' => ['fitness', 'osteopatia', 'quiropractica'],
        'fitness' => ['fisioterapia', 'nutricion'],
        'nutricion' => ['fitness'],
        'osteopatia' => ['fisioterapia', 'quiropractica', 'fitness'],
        'quiropractica' => ['fisioterapia', 'osteopatia', 'fitness'],
        'terapia_ocupacional' => ['fisioterapia', 'psicopedagogia'],
        'preparacion_oposiciones' => ['psicopedagogia'],
        'oposiciones' => ['psicopedagogia']
    ];
}

function knowledge_sector_label_map()
{
    $labels = [];
    foreach (sector_texts_available() as $sector) {
        $key = (string) ($sector['key'] ?? '');
        if ($key !== '') {
            $labels[$key] = (string) ($sector['name'] ?? ucfirst(str_replace('_', ' ', $key)));
        }
    }
    return $labels;
}

function available_knowledge_sectors($mysqli)
{
    $labels = knowledge_sector_label_map();
    $sectors = [];
    $res = $mysqli->query("
        SELECT sector_key, COUNT(*) AS total
        FROM knowledge_problems
        WHERE sector_key IS NOT NULL AND sector_key <> ''
        GROUP BY sector_key
        ORDER BY sector_key ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $key = (string) ($row['sector_key'] ?? '');
            if (!sector_texts_validate_key($key)) {
                continue;
            }
            $sectors[$key] = [
                'key' => $key,
                'name' => $labels[$key] ?? ucfirst(str_replace(['_', '-'], ' ', $key)),
                'total' => (int) ($row['total'] ?? 0)
            ];
        }
    }
    uasort($sectors, fn($a, $b) => strcmp($a['name'], $b['name']));
    return array_values($sectors);
}

function knowledge_sector_names_by_key($mysqli)
{
    $names = [];
    foreach (available_knowledge_sectors($mysqli) as $sector) {
        $names[$sector['key']] = $sector['name'];
    }
    return $names;
}

function normalize_knowledge_sector_keys($keys)
{
    if (is_string($keys)) {
        $decoded = json_decode($keys, true);
        $keys = is_array($decoded) ? $decoded : explode(',', $keys);
    }
    if (!is_array($keys)) {
        return [];
    }
    $normalized = [];
    foreach ($keys as $key) {
        $key = trim((string) $key);
        if (sector_texts_validate_key($key) && !in_array($key, $normalized, true)) {
            $normalized[] = $key;
        }
    }
    return $normalized;
}

function current_professional_knowledge_settings($mysqli, $professional_id = 0)
{
    $professional_id = $professional_id > 0
        ? (int) $professional_id
        : current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
    $settings = [
        'mode' => 'own',
        'keys' => []
    ];
    if ($professional_id <= 0) {
        return $settings;
    }
    if (!professional_knowledge_sector_columns_available($mysqli)) {
        return $settings;
    }
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("
        SELECT knowledge_sector_mode, knowledge_sector_keys_json
        FROM professional_settings
        WHERE tenant_id = ? AND professional_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return $settings;
    }
    $stmt->bind_param("ii", $tenant_id, $professional_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return $settings;
    }
    $mode = (string) ($row['knowledge_sector_mode'] ?? 'own');
    if (!in_array($mode, ['own', 'related', 'custom'], true)) {
        $mode = 'own';
    }
    return [
        'mode' => $mode,
        'keys' => normalize_knowledge_sector_keys($row['knowledge_sector_keys_json'] ?? '')
    ];
}

function allowed_knowledge_sector_keys($mysqli, $professional_id = 0)
{
    $main_sector = current_knowledge_sector_key($mysqli);
    $available = array_column(available_knowledge_sectors($mysqli), 'key');
    if (!in_array($main_sector, $available, true)) {
        $available[] = $main_sector;
    }
    $keys = [$main_sector];
    if (knowledge_multi_sector_enabled($mysqli)) {
        $settings = current_professional_knowledge_settings($mysqli, $professional_id);
        if ($settings['mode'] === 'related') {
            $related = knowledge_related_sector_presets()[$main_sector] ?? [];
            $keys = array_merge($keys, $related);
        } elseif ($settings['mode'] === 'custom') {
            $keys = array_merge($keys, $settings['keys']);
        }
    }
    $keys = array_values(array_unique(array_filter($keys, static function ($key) use ($available) {
        return sector_texts_validate_key($key) && in_array($key, $available, true);
    })));
    return $keys ?: [$main_sector];
}

function knowledge_sector_in_sql($mysqli, array $sector_keys)
{
    $safe = [];
    foreach ($sector_keys as $key) {
        if (sector_texts_validate_key($key)) {
            $safe[] = "'" . $mysqli->real_escape_string($key) . "'";
        }
    }
    return $safe ? implode(',', array_unique($safe)) : "'" . $mysqli->real_escape_string(current_knowledge_sector_key($mysqli)) . "'";
}

function current_professional_id_for_user($mysqli, $user_id)
{
    ensure_cabinet_schema($mysqli);
    $tenant_id = current_tenant_id();
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return 0;
    }
    $stmt = $mysqli->prepare("SELECT id FROM professionals WHERE tenant_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['id'] : 0;
}

function admin_requested_professional_filter($mysqli)
{
    global $is_superadmin;
    $current_professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
    if (!$is_superadmin) {
        return $current_professional_id > 0 ? $current_professional_id : -1;
    }

    $raw = $_GET['professional_id'] ?? $_POST['professional_id'] ?? '';
    if ($raw === 'all') {
        return 0;
    }
    if ($raw === '' || $raw === null) {
        return $current_professional_id;
    }
    return max(0, (int) $raw);
}

function active_professionals_payload($mysqli)
{
    ensure_cabinet_schema($mysqli);
    $tenant_id = current_tenant_id();
    $branding = get_public_branding_settings($mysqli);
    $dashboard_photo = $branding['profile_image_path'] ?? '';
    $rows = [];
    $res = $mysqli->query("
        SELECT p.id, p.display_name, p.public_photo_path, u.role AS user_role, ps.member_permissions_json
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
        LEFT JOIN professional_settings ps ON ps.professional_id = p.id AND ps.tenant_id = p.tenant_id
        WHERE p.tenant_id = $tenant_id
          AND p.is_active = 1
          AND u.role IN ('superadmin', 'admin')
        ORDER BY CASE WHEN u.role = 'superadmin' THEN 0 ELSE 1 END ASC,
                 p.sort_order ASC,
                 p.display_name ASC
    ");
    while ($row = $res->fetch_assoc()) {
        if (!cabinet_member_has_permission($row['member_permissions_json'] ?? null, $row['user_role'] ?? 'admin', 'bookable')) {
            continue;
        }
        $display_photo_path = professional_photo_with_dashboard_fallback($row, $dashboard_photo);
        $effective_settings = cabinet_get_effective_professional_settings($mysqli, (int) $row['id']);
        $rows[] = [
            'id' => (int) $row['id'],
            'display_name' => $row['display_name'],
            'public_photo_path' => $row['public_photo_path'] ?? '',
            'display_photo_path' => $display_photo_path,
            'appointment_delivery_mode' => $effective_settings['appointment_delivery_mode'] ?? 'both'
        ];
    }
    return $rows;
}

function payment_method_label($method)
{
    $labels = [
        'card' => 'Tarjeta online',
        'bizum' => 'Bizum online',
        'bonus' => 'Bono',
        'cash' => 'Efectivo',
        'bank_transfer' => 'Transferencia',
        'other' => 'Otro método',
        'manual' => 'Manual'
    ];
    return $labels[$method] ?? ($method ?: '');
}

function quick_appointment_payload($row, $dashboard_photo = '')
{
    if (!$row) {
        return null;
    }
    $duration = (int) ($row['duration_minutes'] ?? 60);
    $start_time = substr((string) ($row['appointment_time'] ?? ''), 0, 5);
    $end_time = '';
    if (!empty($row['appointment_time'])) {
        $end_time = date('H:i', strtotime((string) $row['appointment_time'] . ' +' . $duration . ' minutes'));
    }
    $row['professional_photo_path'] = professional_photo_with_dashboard_fallback($row, $dashboard_photo);
    return [
        'id' => (int) $row['id'],
        'appointment_date' => $row['appointment_date'],
        'appointment_time' => $start_time,
        'appointment_end_time' => $end_time,
        'duration_minutes' => $duration,
        'patient_id' => (int) ($row['user_id'] ?? 0),
        'patient_name' => $row['name'] ?? '',
        'patient_email' => $row['email'] ?? '',
        'patient_phone' => $row['phone'] ?? '',
        'professional_id' => (int) ($row['professional_id'] ?? 0),
        'professional_name' => $row['professional_name'] ?? '',
        'professional_photo_path' => $row['professional_photo_path'] ?? '',
        'consultation_type' => $row['consultation_type'] ?? 'presencial',
        'online_session_url' => $row['online_session_url'] ?? '',
        'service_label' => appointment_service_option_label($row),
        'payment_status' => $row['payment_status'] ?? 'pending',
        'payment_method' => $row['payment_method'] ?? '',
        'patient_bonus_id' => $row['patient_bonus_id'] ?? null
    ];
}

function dashboard_waiting_list_count($mysqli, $professional_id = 0, $include_unassigned = false)
{
    ensure_patient_management_tables($mysqli);
    $tenant_id = current_tenant_id();
    $professional_id = (int) $professional_id;
    $where = '';
    if ($professional_id > 0) {
        $where = " AND (COALESCE(ppf.professional_id, pp.professional_id) = $professional_id";
        if ($include_unassigned) {
            $where .= " OR COALESCE(ppf.professional_id, pp.professional_id, 0) = 0";
        }
        $where .= ")";
    } elseif ($professional_id < 0) {
        $where = " AND 1 = 0";
    }

    $res = $mysqli->query("
        SELECT COUNT(DISTINCT u.id) AS total
        FROM users u
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id AND pp.tenant_id = u.tenant_id
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.tenant_id = u.tenant_id AND ppf.is_primary = 1
        WHERE u.tenant_id = $tenant_id
          AND u.role = 'patient'
          AND COALESCE(pp.waiting_list, 0) = 1
          $where
    ");
    $row = $res ? $res->fetch_assoc() : null;
    return $row ? (int) ($row['total'] ?? 0) : 0;
}

function dashboard_time_label($minutes)
{
    $minutes = max(0, (int) $minutes);
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

function dashboard_active_weekdays_from_settings($settings)
{
    $days = [];
    foreach (explode(',', (string) ($settings['available_weekdays'] ?? '1,2,3,4,5')) as $day) {
        $day = (int) trim($day);
        if ($day >= 1 && $day <= 7 && !in_array($day, $days, true)) {
            $days[] = $day;
        }
    }
    sort($days);
    return $days ?: [1, 2, 3, 4, 5];
}

function dashboard_professional_has_closed_day($mysqli, $professional_id, $date)
{
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("SELECT id FROM closed_days WHERE tenant_id = ? AND closed_date = ? AND (is_global = 1 OR professional_id = ?) LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("isi", $tenant_id, $date, $professional_id);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

function dashboard_professional_day_appointments($mysqli, $professional_id, $date)
{
    $tenant_id = current_tenant_id();
    $rows = [];
    $stmt = $mysqli->prepare("
        SELECT a.appointment_time, COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        WHERE a.tenant_id = ?
          AND a.professional_id = ?
          AND a.appointment_date = ?
          AND a.status = 'booked'
    ");
    if (!$stmt) {
        return $rows;
    }
    $stmt->bind_param("iis", $tenant_id, $professional_id, $date);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $start = time_to_minutes($row['appointment_time'] ?? '00:00:00');
        $rows[] = [
            'start' => $start,
            'end' => $start + (int) ($row['duration_minutes'] ?? 60)
        ];
    }
    return $rows;
}

function dashboard_slot_overlaps_existing($slot_start, $slot_end, array $appointments)
{
    foreach ($appointments as $appointment) {
        if ($slot_start < (int) $appointment['end'] && $slot_end > (int) $appointment['start']) {
            return true;
        }
    }
    return false;
}

function dashboard_first_waiting_list_slot_for_professional($mysqli, $professional, $settings, $max_days, $duration_minutes = 60)
{
    $professional_id = (int) ($professional['id'] ?? 0);
    if ($professional_id <= 0) {
        return null;
    }

    $weekdays = dashboard_active_weekdays_from_settings($settings);
    $start_minutes = time_to_minutes($settings['appointment_start_time'] ?? '10:00:00');
    $end_minutes = time_to_minutes($settings['appointment_end_time'] ?? '19:00:00');
    $break_start = !empty($settings['break_start_time']) ? time_to_minutes($settings['break_start_time']) : null;
    $break_end = !empty($settings['break_end_time']) ? time_to_minutes($settings['break_end_time']) : null;
    $today = new DateTimeImmutable('today');
    $now_minutes = ((int) date('G') * 60) + (int) date('i');

    for ($offset = 0; $offset <= $max_days; $offset++) {
        $date = $today->modify('+' . $offset . ' days');
        if (!in_array((int) $date->format('N'), $weekdays, true)) {
            continue;
        }
        $date_sql = $date->format('Y-m-d');
        if (dashboard_professional_has_closed_day($mysqli, $professional_id, $date_sql)) {
            continue;
        }

        $day_start = $start_minutes;
        if ($offset === 0) {
            $day_start = max($day_start, (int) (ceil($now_minutes / 30) * 30));
        }
        $appointments = dashboard_professional_day_appointments($mysqli, $professional_id, $date_sql);
        for ($slot_start = $day_start; $slot_start + $duration_minutes <= $end_minutes; $slot_start += 30) {
            $slot_end = $slot_start + $duration_minutes;
            if ($break_start !== null && $break_end !== null && $slot_start < $break_end && $slot_end > $break_start) {
                continue;
            }
            if (dashboard_slot_overlaps_existing($slot_start, $slot_end, $appointments)) {
                continue;
            }
            return [
                'date' => $date_sql,
                'date_label' => $date->format('d/m/Y'),
                'time' => dashboard_time_label($slot_start),
                'end_time' => dashboard_time_label($slot_end),
                'professional_id' => $professional_id,
                'professional_name' => $professional['display_name'] ?? ''
            ];
        }
    }
    return null;
}

function dashboard_waiting_list_summary_payload($mysqli, $current_professional_id)
{
    $visible_waiting_count = dashboard_waiting_list_count($mysqli, (int) $current_professional_id, (int) $current_professional_id > 0);
    if ($visible_waiting_count <= 0) {
        return [
            'count' => 0,
            'has_slot' => false
        ];
    }

    $professionals = [];
    if ((int) $current_professional_id > 0) {
        $res = $mysqli->query("
            SELECT p.id, p.display_name
            FROM professionals p
            WHERE p.tenant_id = " . current_tenant_id() . "
              AND p.id = " . (int) $current_professional_id . "
              AND p.is_active = 1
            LIMIT 1
        ");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row) {
            $professionals[] = $row;
        }
    } else {
        $professionals = cabinet_active_professionals_for_booking($mysqli);
    }

    $best_slot = null;
    $max_horizon_days = 40;
    foreach ($professionals as $professional) {
        $professional_id = (int) ($professional['id'] ?? 0);
        if ($professional_id <= 0) {
            continue;
        }
        $settings = cabinet_get_effective_professional_settings($mysqli, $professional_id);
        $max_days = max(1, min(365, (int) ($settings['max_booking_notice_days'] ?? 40)));
        $max_horizon_days = max($max_horizon_days, $max_days);
        $slot = dashboard_first_waiting_list_slot_for_professional($mysqli, $professional, $settings, $max_days);
        if ($slot && (!$best_slot || ($slot['date'] . ' ' . $slot['time']) < ($best_slot['date'] . ' ' . $best_slot['time']))) {
            $best_slot = $slot;
        }
    }

    if ($best_slot) {
        return [
            'count' => dashboard_waiting_list_count($mysqli, (int) $best_slot['professional_id'], true),
            'has_slot' => true,
            'slot' => $best_slot
        ];
    }

    $until = (new DateTimeImmutable('today'))->modify('+' . ($max_horizon_days + 1) . ' days');
    return [
        'count' => $visible_waiting_count,
        'has_slot' => false,
        'no_slots_until' => $until->format('d/m/Y')
    ];
}

function report_h($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function report_date($value)
{
    if (!$value) {
        return '-';
    }
    $timestamp = strtotime((string) $value);
    return $timestamp ? date('d/m/Y', $timestamp) : (string) $value;
}

function report_datetime($value)
{
    if (!$value) {
        return '-';
    }
    $timestamp = strtotime((string) $value);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : (string) $value;
}

function report_age($birth_date)
{
    if (!$birth_date) {
        return '-';
    }
    try {
        $birth = new DateTime((string) $birth_date);
        $today = new DateTime(date('Y-m-d'));
        return (string) $birth->diff($today)->y;
    } catch (\Exception $e) {
        return '-';
    }
}

function patient_report_definition($report_key)
{
    $definitions = [
        'internal_summary' => [
            'report_type' => 'internal',
            'title' => 'Informe interno',
            'payment_mode' => 'free',
            'payment_status' => 'paid',
            'visibility' => 'internal',
            'implemented' => true
        ],
        'patient_summary' => [
            'report_type' => 'patient',
            'title' => 'Informe paciente',
            'payment_mode' => 'free',
            'payment_status' => 'paid',
            'visibility' => 'internal',
            'implemented' => true
        ],
        'clinical_summary' => [
            'report_type' => 'clinical',
            'title' => 'Informe clínico',
            'payment_mode' => 'paid',
            'payment_status' => 'pending',
            'visibility' => 'internal',
            'implemented' => true
        ],
        'evolution_report' => [
            'report_type' => 'evolution',
            'title' => 'Informe de evolución',
            'payment_mode' => 'included',
            'payment_status' => 'paid',
            'visibility' => 'internal',
            'implemented' => true
        ]
    ];
    $report_key = (string) $report_key;
    return $definitions[$report_key] ?? null;
}

function patient_report_public_url($report)
{
    $patient_id = (int) ($report['patient_id'] ?? 0);
    $report_id = (int) ($report['id'] ?? 0);
    $definition = patient_report_definition($report['report_key'] ?? '');
    if (!$patient_id || !$definition || empty($definition['implemented'])) {
        return '';
    }
    $type = in_array($definition['report_type'], ['patient', 'clinical', 'evolution'], true) ? $definition['report_type'] : 'internal';
    return 'api/admin.php?action=patient_report&patient_id=' . $patient_id . '&type=' . $type . ($report_id ? '&report_id=' . $report_id : '');
}

function patient_status_label($status)
{
    $labels = [
        'active' => 'Activo',
        'paused' => 'En pausa',
        'discharged' => 'Alta',
        'inactive' => 'Inactivo'
    ];
    return $labels[$status] ?? 'Activo';
}

function admin_can_manage_appointment_payment($mysqli, $appointment_id)
{
    global $is_superadmin;
    $tenant_id = current_tenant_id();
    $appointment_id = (int) $appointment_id;
    if ($appointment_id <= 0) {
        return [false, null];
    }

    $session_notes_select = payment_column_exists($mysqli, 'appointments', 'session_notes')
        ? 'a.session_notes'
        : 'NULL AS session_notes';
    $stmt = $mysqli->prepare("
        SELECT a.id, a.user_id, a.professional_id, a.appointment_date, a.appointment_time, a.status,
               a.consultation_type, a.service_type, a.service_option_id, a.location_id, a.online_session_url, a.livekit_access_token,
               a.cancel_token,
               $session_notes_select,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.patient_bonus_id, a.paid_at, a.payment_updated_at, a.payment_updated_by,
               so.price AS service_price, s.name AS service_name,
               u.name AS patient_name, u.email AS patient_email, u.phone AS patient_phone,
               pp.address AS patient_address,
               al.name AS location_name, al.location_type,
               p.display_name AS professional_name
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id AND pp.tenant_id = u.tenant_id
        LEFT JOIN appointment_locations al ON al.id = a.location_id AND al.tenant_id = a.tenant_id
        LEFT JOIN professionals p ON p.id = a.professional_id AND p.tenant_id = a.tenant_id
        WHERE a.tenant_id = ?
          AND a.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $appointment_id);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
    if (!$appointment) {
        return [false, null];
    }

    if ($is_superadmin) {
        return [true, $appointment];
    }

    $current_professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
    $can_manage = $current_professional_id > 0 && (int) ($appointment['professional_id'] ?? 0) === $current_professional_id;
    return [$can_manage, $appointment];
}

function admin_reminder_first_name($name)
{
    $first_name = message_template_first_name($name);
    return $first_name !== '' ? $first_name : 'tu profesional';
}

function admin_reminder_email_body_to_html($body)
{
    $body = (string) $body;
    if ($body !== strip_tags($body)) {
        return $body;
    }
    return nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
}

function admin_reminder_manage_link($mysqli, $tenant_id, array $appointment)
{
    $cancel_token = $appointment['cancel_token'] ?? '';
    if ($cancel_token === '') {
        $cancel_token = bin2hex(random_bytes(32));
        $update_token = $mysqli->prepare("UPDATE appointments SET cancel_token = ? WHERE tenant_id = ? AND id = ?");
        $appointment_id = (int) ($appointment['id'] ?? 0);
        $update_token->bind_param("sii", $cancel_token, $tenant_id, $appointment_id);
        $update_token->execute();
    }

    return urlme_shorten_url(
        app_public_base_url() . 'cancelar_cita.php?t=' . urlencode($cancel_token),
        'Recordatorio cita SimplyGest Praxis'
    );
}

function admin_payment_settings_for_reminder($mysqli)
{
    $tenant_id = current_tenant_id();
    $res = $mysqli->query("
        SELECT app_name, site_phone, online_payment_enabled,
               appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price
        FROM payment_settings
        WHERE tenant_id = $tenant_id
        LIMIT 1
    ");
    return $res ? ($res->fetch_assoc() ?: []) : [];
}

function admin_appointment_reminder_context($mysqli, array $appointment, array $settings, $short_patient_name = false)
{
    $tenant_id = current_tenant_id();
    $manage_link = admin_reminder_manage_link($mysqli, $tenant_id, $appointment);
    $date = date('d/m/Y', strtotime($appointment['appointment_date']));
    $date_short = date('d/m', strtotime($appointment['appointment_date']));
    $time = date('H:i', strtotime($appointment['appointment_time']));
    $end_time = date('H:i', strtotime($appointment['appointment_time'] . ' +' . (int) ($appointment['duration_minutes'] ?? 60) . ' minutes'));
    $consultation_text = appointment_consultation_label($appointment['consultation_type'] ?? 'presencial');
    $service_text = appointment_service_option_label($appointment);
    $online_link = trim((string) ($appointment['online_session_url'] ?? ''));
    if (($appointment['consultation_type'] ?? '') === 'online' && livekit_appointment_enabled($mysqli, $appointment)) {
        $appointment['livekit_access_token'] = livekit_ensure_appointment_link_token(
            $mysqli,
            (int) ($appointment['id'] ?? 0),
            (string) ($appointment['livekit_access_token'] ?? '')
        );
        $online_link = livekit_patient_join_url($appointment);
    }
    $patient_name = trim((string) ($appointment['patient_name'] ?? ''));
    $patient_short = message_template_first_name($patient_name) ?: $patient_name;
    $name_for_template = $short_patient_name ? $patient_short : $patient_name;
    $place = '';
    if (($appointment['consultation_type'] ?? 'presencial') === 'presencial') {
        $place = trim((string) ($appointment['location_name'] ?? ''));
        if ($place === '') {
            $branding = get_public_branding_settings($mysqli);
            $place = trim((string) ($branding['legal_address'] ?? ''));
        }
    }
    $price = format_appointment_price(appointment_price_for_row($settings, $appointment));

    return [
        'vars' => [
            'nombre' => $name_for_template,
            'nombre_completo' => $short_patient_name ? $patient_short : $patient_name,
            'profesional' => $appointment['professional_name'] ?? '',
            'profesional_nombre' => admin_reminder_first_name($appointment['professional_name'] ?? ''),
            'fecha' => $date,
            'fecha_corta' => $date_short,
            'hora' => $time,
            'hora_fin' => $end_time,
            'duracion' => (string) (int) ($appointment['duration_minutes'] ?? 60),
            'modalidad' => $consultation_text,
            'servicio' => $service_text,
            'lugar' => $place,
            'link' => $online_link,
            'enlace_gestion' => $manage_link,
            'nombre_centro' => $settings['app_name'] ?? '',
            'telefono_centro' => $settings['site_phone'] ?? '',
            'importe' => $price . ' EUR'
        ],
        'manage_link' => $manage_link,
        'online_link' => $online_link,
        'consultation_text' => $consultation_text,
        'service_text' => $service_text,
        'price' => $price
    ];
}

function admin_log_manual_reminder($mysqli, array $appointment, $channel, $success, $message, array $metadata = [])
{
    app_log($mysqli, [
        'action' => 'manual_appointment_reminder',
        'channel' => $channel,
        'status' => $success ? 'ok' : 'error',
        'target_type' => 'appointment',
        'target_id' => (int) ($appointment['id'] ?? 0),
        'title' => 'Recordatorio manual de cita',
        'message' => $message,
        'metadata' => array_merge([
            'patient_id' => (int) ($appointment['user_id'] ?? 0),
            'patient_name' => $appointment['patient_name'] ?? '',
            'professional_id' => (int) ($appointment['professional_id'] ?? 0),
            'appointment_date' => $appointment['appointment_date'] ?? '',
            'appointment_time' => $appointment['appointment_time'] ?? ''
        ], $metadata)
    ]);
}

function appointment_patient_knowledge_problem_payload($mysqli, $patient_id)
{
    $patient_id = (int) $patient_id;
    if ($patient_id <= 0 || !app_feature_enabled_from_db($mysqli, 'knowledgeBase.enabled', false)) {
        return [
            'enabled' => false,
            'status' => 'disabled'
        ];
    }

    $allowed_sectors = allowed_knowledge_sector_keys($mysqli);
    if (!$allowed_sectors) {
        return [
            'enabled' => false,
            'status' => 'no_sector_data'
        ];
    }

    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("
        SELECT kp.id, kp.sector_key, kp.name, kp.alias AS short_name, ka.name AS area, kp.population AS category, kp.risk_level, kp.population AS target_population
        FROM patient_profiles pp
        LEFT JOIN knowledge_problems kp
          ON kp.id = pp.knowledge_problem_id
         AND kp.sector_key IN (" . knowledge_sector_in_sql($mysqli, $allowed_sectors) . ")
        LEFT JOIN knowledge_areas ka
          ON ka.id = kp.area_id
         AND ka.sector_key = kp.sector_key
        WHERE pp.tenant_id = ?
          AND pp.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $problem = $stmt->get_result()->fetch_assoc();
    if (!$problem || empty($problem['id'])) {
        return [
            'enabled' => true,
            'status' => 'pending',
            'sector_key' => current_knowledge_sector_key($mysqli),
            'id' => 0,
            'name' => '',
            'short_name' => '',
            'area' => '',
            'category' => '',
            'risk_level' => '',
            'target_population' => ''
        ];
    }

    return [
        'enabled' => true,
        'status' => 'assigned',
        'sector_key' => $problem['sector_key'] ?? current_knowledge_sector_key($mysqli),
        'id' => (int) $problem['id'],
        'name' => $problem['name'] ?? '',
        'short_name' => $problem['short_name'] ?? '',
        'area' => $problem['area'] ?? '',
        'category' => $problem['category'] ?? '',
        'risk_level' => $problem['risk_level'] ?? '',
        'target_population' => $problem['target_population'] ?? ''
    ];
}

function admin_can_access_patient($mysqli, $patient_id)
{
    global $is_superadmin;
    $tenant_id = current_tenant_id();
    $patient_id = (int) $patient_id;
    if ($patient_id <= 0) {
        return false;
    }
    $professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
    if ($is_superadmin) {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE tenant_id = ? AND id = ? AND role = 'patient' LIMIT 1");
        $stmt->bind_param("ii", $tenant_id, $patient_id);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_assoc();
    }

    if ($professional_id <= 0) {
        return false;
    }

    $stmt = $mysqli->prepare("
        SELECT u.id
        FROM users u
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.tenant_id = u.tenant_id AND ppf.is_primary = 1
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id AND pp.tenant_id = u.tenant_id
        WHERE u.tenant_id = ?
          AND u.id = ?
          AND u.role = 'patient'
          AND COALESCE(ppf.professional_id, pp.professional_id) = ?
        LIMIT 1
    ");
    $stmt->bind_param("iii", $tenant_id, $patient_id, $professional_id);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

function knowledge_priority_to_work_plan($priority)
{
    $priority = strtolower(trim((string) $priority));
    if ($priority === 'alta') {
        return 1;
    }
    if ($priority === 'baja') {
        return 3;
    }
    return 2;
}

function import_knowledge_recommendation_task($mysqli, $patient_id, $recommendation_id, $appointment_id = 0)
{
    $tenant_id = current_tenant_id();
    $sector_sql = knowledge_sector_in_sql($mysqli, allowed_knowledge_sector_keys($mysqli));
    $stmt = $mysqli->prepare("
        SELECT r.id, r.priority, r.clinical_note,
               t.title, t.description, t.objective, t.estimated_duration,
               te.name AS technique_name
        FROM knowledge_recommendations r
        INNER JOIN knowledge_tasks t ON t.id = r.task_id
        INNER JOIN knowledge_techniques te ON te.id = r.technique_id
        WHERE r.id = ? AND r.sector_key IN ($sector_sql)
        LIMIT 1
    ");
    $stmt->bind_param("i", $recommendation_id);
    $stmt->execute();
    $rec = $stmt->get_result()->fetch_assoc();
    if (!$rec) {
        return 0;
    }

    $description_parts = [];
    if (!empty($rec['description'])) {
        $description_parts[] = $rec['description'];
    }
    if (!empty($rec['objective'])) {
        $description_parts[] = 'Objetivo: ' . $rec['objective'];
    }
    if (!empty($rec['technique_name'])) {
        $description_parts[] = 'Tecnica: ' . $rec['technique_name'];
    }
    if (!empty($rec['estimated_duration'])) {
        $description_parts[] = 'Duracion estimada: ' . $rec['estimated_duration'];
    }
    if (!empty($rec['clinical_note'])) {
        $description_parts[] = 'Nota clinica: ' . $rec['clinical_note'];
    }

    $title = $rec['title'];
    $description = implode("\n\n", $description_parts);
    $priority = knowledge_priority_to_work_plan($rec['priority'] ?? '');
    $status = 'pending';
    $visible_to_patient = 0;
    $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
    $professional_id = current_professional_id_for_user($mysqli, $session_user_id);
    if ($professional_id <= 0) {
        $professional_id = null;
    }
    $settings_res = $mysqli->query("SELECT patient_tasks_visible_default FROM payment_settings WHERE tenant_id = $tenant_id");
    if ($settings_res && ($settings_row = $settings_res->fetch_assoc())) {
        $visible_to_patient = (int) ($settings_row['patient_tasks_visible_default'] ?? 0) === 1 ? 1 : 0;
    }
    $appointment_id_db = null;
    if ((int) $appointment_id > 0) {
        $appointment_manage_result = admin_can_manage_appointment_payment($mysqli, (int) $appointment_id);
        if (!$appointment_manage_result[0] || (int) ($appointment_manage_result[1]['user_id'] ?? 0) !== (int) $patient_id) {
            return -1;
        }
        $appointment_id_db = (int) $appointment_id;
        if (!empty($appointment_manage_result[1]['professional_id'])) {
            $professional_id = (int) $appointment_manage_result[1]['professional_id'];
        }
    }
    $completed_at = null;
    $completed_by = null;

    $stmt = $mysqli->prepare("
        INSERT INTO patient_work_plan_tasks (tenant_id, patient_id, appointment_id, professional_id, title, description, status, priority, visible_to_patient, created_by, completed_at, completed_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iiiisssiiisi", $tenant_id, $patient_id, $appointment_id_db, $professional_id, $title, $description, $status, $priority, $visible_to_patient, $session_user_id, $completed_at, $completed_by);
    $stmt->execute();
    return (int) $mysqli->insert_id;
}

function global_search_like_term($term)
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
}

function global_search_push(&$results, $type, $title, $subtitle = '', $meta = '', $icon = 'bi-search', $action = null)
{
    $results[] = [
        'type' => $type,
        'title' => $title,
        'subtitle' => $subtitle,
        'meta' => $meta,
        'icon' => $icon,
        'action' => $action
    ];
}

function professional_photo_with_dashboard_fallback($row, $dashboard_photo)
{
    $photo = $row['public_photo_path'] ?? ($row['professional_photo_path'] ?? '');
    return $photo ?: '';
}

function admin_ensure_password_reset_table($mysqli)
{
    if (function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }

    $tenant_id = current_tenant_id();
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_resets_user (user_id),
            INDEX idx_password_resets_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if (!column_exists($mysqli, 'password_resets', 'tenant_id')) {
        $mysqli->query("ALTER TABLE password_resets ADD tenant_id INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
    }
}

function send_professional_password_setup_email($mysqli, $user_id, $name, $email)
{
    $token = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $token);
    $tenant_id = current_tenant_id();

    $stmt = $mysqli->prepare("UPDATE password_resets SET used_at = NOW() WHERE tenant_id = ? AND user_id = ? AND used_at IS NULL");
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();

    $stmt = $mysqli->prepare("INSERT INTO password_resets (tenant_id, user_id, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
    $stmt->bind_param("iis", $tenant_id, $user_id, $token_hash);
    $stmt->execute();

    $reset_link = urlme_shorten_url(
        app_public_base_url() . 'reset_password.php?t=' . urlencode($token),
        'Crear contrasena acceso SimplyGest Praxis',
        date('Y-m-d H:i:s', strtotime('+24 hours'))
    );

    return send_app_email(
        $email,
        'Crea tu contraseña de acceso',
        '<p>Hola ' . htmlspecialchars($name) . ',</p>' .
        '<p>Se ha creado tu acceso como miembro del equipo en ' . htmlspecialchars(get_app_name($mysqli)) . '.</p>' .
        '<p>Para entrar en la web, crea tu contraseña desde este enlace:</p>' .
        '<p><a href="' . htmlspecialchars($reset_link) . '">Crear contraseña de acceso</a></p>' .
        '<p>Este enlace caduca en 24 horas.</p>',
        null,
        $mysqli
    );
}

function ensure_payment_settings_table($mysqli)
{
    if (function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }

    $tenant_id = current_tenant_id();
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS payment_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            app_name VARCHAR(255) DEFAULT 'SimplyGest Praxis',
            site_tagline VARCHAR(255) DEFAULT NULL,
            site_phone VARCHAR(40) DEFAULT NULL,
            profile_image_path VARCHAR(255) DEFAULT NULL,
            landing_image_path VARCHAR(255) DEFAULT NULL,
            favicon_path VARCHAR(255) DEFAULT NULL,
            primary_color VARCHAR(7) NOT NULL DEFAULT '#4285f4',
            show_profile_image_public TINYINT(1) NOT NULL DEFAULT 0,
            public_site_enabled TINYINT(1) NOT NULL DEFAULT 0,
            show_prices_public TINYINT(1) NOT NULL DEFAULT 0,
            show_contact_public TINYINT(1) NOT NULL DEFAULT 0,
            plan_key VARCHAR(32) NOT NULL DEFAULT 'novus',
            online_booking_enabled TINYINT(1) NOT NULL DEFAULT 1,
            patient_registration_mode VARCHAR(16) NOT NULL DEFAULT 'invite',
            dashboard_config_mode VARCHAR(16) NOT NULL DEFAULT 'simple',
            sector_texts_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            patient_tasks_visible_default TINYINT(1) NOT NULL DEFAULT 0,
            work_plan_task_status_enabled TINYINT(1) NOT NULL DEFAULT 1,
            bonuses_enabled TINYINT(1) NOT NULL DEFAULT 0,
            create_compensation_bonus_on_paid_cancel TINYINT(1) NOT NULL DEFAULT 1,
            online_payment_enabled TINYINT(1) NOT NULL DEFAULT 0,
            environment ENUM('sandbox', 'real') NOT NULL DEFAULT 'sandbox',
            merchant_code VARCHAR(32) DEFAULT NULL,
            merchant_key VARCHAR(255) DEFAULT NULL,
            terminal VARCHAR(8) DEFAULT NULL,
            appointment_price DECIMAL(10,2) NOT NULL DEFAULT 70.00,
            online_appointment_price DECIMAL(10,2) NOT NULL DEFAULT 70.00,
            couple_appointment_price DECIMAL(10,2) NOT NULL DEFAULT 90.00,
            online_couple_appointment_price DECIMAL(10,2) NOT NULL DEFAULT 90.00,
            admin_notification_email VARCHAR(255) DEFAULT NULL,
            appointment_delivery_mode ENUM('both', 'presencial', 'online') NOT NULL DEFAULT 'both',
            available_session_types VARCHAR(255) NOT NULL DEFAULT 'individual',
            available_session_durations VARCHAR(100) NOT NULL DEFAULT '60',
            display_effective_duration_enabled TINYINT(1) NOT NULL DEFAULT 0,
            display_duration_offset_minutes TINYINT UNSIGNED NOT NULL DEFAULT 5,
            appointment_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0,
            appointment_second_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0,
            appointment_second_reminder_hours SMALLINT UNSIGNED NOT NULL DEFAULT 48,
            sms_provider VARCHAR(20) NOT NULL DEFAULT 'none',
            sms_sender VARCHAR(40) DEFAULT NULL,
            sms_username VARCHAR(120) DEFAULT NULL,
            sms_password VARCHAR(255) DEFAULT NULL,
            sms_api_key VARCHAR(255) DEFAULT NULL,
            sms_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0,
            sms_reminder_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
            min_booking_notice_days INT UNSIGNED NOT NULL DEFAULT 2,
            max_booking_notice_days INT UNSIGNED NOT NULL DEFAULT 40,
            appointment_start_time TIME NOT NULL DEFAULT '10:00:00',
            appointment_end_time TIME NOT NULL DEFAULT '19:00:00',
            break_start_time TIME DEFAULT '15:00:00',
            break_end_time TIME DEFAULT '16:00:00',
            available_weekdays VARCHAR(32) NOT NULL DEFAULT '1,2,3,4,5',
            email_provider ENUM('phpmailer', 'google') NOT NULL DEFAULT 'phpmailer',
            smtp_host VARCHAR(255) DEFAULT NULL,
            smtp_port INT UNSIGNED DEFAULT 587,
            smtp_username VARCHAR(255) DEFAULT NULL,
            smtp_password VARCHAR(255) DEFAULT NULL,
            smtp_secure ENUM('none', 'tls', 'ssl') NOT NULL DEFAULT 'tls',
            smtp_from_email VARCHAR(255) DEFAULT NULL,
            smtp_from_name VARCHAR(255) DEFAULT NULL,
            google_client_id VARCHAR(255) DEFAULT NULL,
            google_client_secret VARCHAR(255) DEFAULT NULL,
            google_refresh_token TEXT DEFAULT NULL,
            google_connected_email VARCHAR(255) DEFAULT NULL,
            google_redirect_uri VARCHAR(512) DEFAULT NULL,
            calendar_provider VARCHAR(16) NOT NULL DEFAULT 'none',
            google_calendar_enabled TINYINT(1) NOT NULL DEFAULT 0,
            google_calendar_id VARCHAR(255) DEFAULT 'primary',
            icloud_calendar_email VARCHAR(255) DEFAULT NULL,
            icloud_calendar_app_password VARCHAR(255) DEFAULT NULL,
            icloud_calendar_url VARCHAR(512) DEFAULT 'https://caldav.icloud.com',
            send_patient_calendar_link TINYINT(1) NOT NULL DEFAULT 1,
            fastcron_api_key VARCHAR(255) DEFAULT NULL,
            fastcron_reminder_cron_id VARCHAR(64) DEFAULT NULL,
            fastcron_planning_cron_id VARCHAR(64) DEFAULT NULL,
            legal_owner_name VARCHAR(255) DEFAULT NULL,
            legal_nif VARCHAR(50) DEFAULT NULL,
            legal_address VARCHAR(500) DEFAULT NULL,
            legal_email VARCHAR(255) DEFAULT NULL,
            legal_license_number VARCHAR(100) DEFAULT NULL,
            legal_professional_college VARCHAR(255) DEFAULT NULL,
            legal_uses_non_technical_cookies TINYINT NOT NULL DEFAULT 0,
            legal_terms_notes TEXT DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if (!column_exists($mysqli, 'payment_settings', 'tenant_id')) {
        $mysqli->query("ALTER TABLE payment_settings ADD tenant_id INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
    }

    ensure_payment_settings_price_columns($mysqli);

    $mysqli->query("
        INSERT IGNORE INTO payment_settings
            (id, tenant_id, online_payment_enabled, environment, appointment_price, min_booking_notice_days, primary_color)
        VALUES
            ($tenant_id, $tenant_id, 0, 'sandbox', 70.00, 2, '#4285f4')
    ");

    ensure_admin_notification_email_column($mysqli);
    ensure_branding_columns($mysqli);
    ensure_appointment_services_tables($mysqli);
    ensure_appointment_locations_table($mysqli);
    ensure_bonus_tables($mysqli);

    $columns = [
        'email_provider' => "ALTER TABLE payment_settings ADD email_provider ENUM('phpmailer', 'google') NOT NULL DEFAULT 'phpmailer' AFTER admin_notification_email",
        'app_name' => "ALTER TABLE payment_settings ADD app_name VARCHAR(255) DEFAULT 'SimplyGest Praxis' AFTER id",
        'site_tagline' => "ALTER TABLE payment_settings ADD site_tagline VARCHAR(255) DEFAULT NULL AFTER app_name",
        'site_phone' => "ALTER TABLE payment_settings ADD site_phone VARCHAR(40) DEFAULT NULL AFTER site_tagline",
        'profile_image_path' => "ALTER TABLE payment_settings ADD profile_image_path VARCHAR(255) DEFAULT NULL AFTER app_name",
        'landing_image_path' => "ALTER TABLE payment_settings ADD landing_image_path VARCHAR(255) DEFAULT NULL AFTER profile_image_path",
        'favicon_path' => "ALTER TABLE payment_settings ADD favicon_path VARCHAR(255) DEFAULT NULL AFTER profile_image_path",
        'primary_color' => "ALTER TABLE payment_settings ADD primary_color VARCHAR(7) NOT NULL DEFAULT '#4285f4' AFTER landing_image_path",
        'show_profile_image_public' => "ALTER TABLE payment_settings ADD show_profile_image_public TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_image_path",
        'public_site_enabled' => "ALTER TABLE payment_settings ADD public_site_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER show_profile_image_public",
        'show_prices_public' => "ALTER TABLE payment_settings ADD show_prices_public TINYINT(1) NOT NULL DEFAULT 0 AFTER show_profile_image_public",
        'show_contact_public' => "ALTER TABLE payment_settings ADD show_contact_public TINYINT(1) NOT NULL DEFAULT 0 AFTER show_prices_public",
        'plan_key' => "ALTER TABLE payment_settings ADD plan_key VARCHAR(32) NOT NULL DEFAULT 'novus' AFTER show_contact_public",
        'online_booking_enabled' => "ALTER TABLE payment_settings ADD online_booking_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER show_contact_public",
        'patient_registration_mode' => "ALTER TABLE payment_settings ADD patient_registration_mode VARCHAR(16) NOT NULL DEFAULT 'invite' AFTER online_booking_enabled",
        'patient_tasks_visible_default' => "ALTER TABLE payment_settings ADD patient_tasks_visible_default TINYINT(1) NOT NULL DEFAULT 0 AFTER patient_registration_mode",
        'work_plan_task_status_enabled' => "ALTER TABLE payment_settings ADD work_plan_task_status_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER patient_tasks_visible_default",
        'initial_calendar_view' => "ALTER TABLE payment_settings ADD initial_calendar_view VARCHAR(12) NOT NULL DEFAULT 'month' AFTER online_booking_enabled",
        'bonuses_enabled' => "ALTER TABLE payment_settings ADD bonuses_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER show_prices_public",
        'create_compensation_bonus_on_paid_cancel' => "ALTER TABLE payment_settings ADD create_compensation_bonus_on_paid_cancel TINYINT(1) NOT NULL DEFAULT 1 AFTER bonuses_enabled",
        'appointment_delivery_mode' => "ALTER TABLE payment_settings ADD appointment_delivery_mode ENUM('both', 'presencial', 'online') NOT NULL DEFAULT 'both' AFTER admin_notification_email",
        'available_session_types' => "ALTER TABLE payment_settings ADD available_session_types VARCHAR(255) NOT NULL DEFAULT 'individual' AFTER appointment_delivery_mode",
        'available_session_durations' => "ALTER TABLE payment_settings ADD available_session_durations VARCHAR(100) NOT NULL DEFAULT '60' AFTER available_session_types",
        'display_effective_duration_enabled' => "ALTER TABLE payment_settings ADD display_effective_duration_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER available_session_durations",
        'display_duration_offset_minutes' => "ALTER TABLE payment_settings ADD display_duration_offset_minutes TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER display_effective_duration_enabled",
        'appointment_reminder_enabled' => "ALTER TABLE payment_settings ADD appointment_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER admin_notification_email",
        'appointment_second_reminder_enabled' => "ALTER TABLE payment_settings ADD appointment_second_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER appointment_reminder_enabled",
        'appointment_second_reminder_hours' => "ALTER TABLE payment_settings ADD appointment_second_reminder_hours SMALLINT UNSIGNED NOT NULL DEFAULT 48 AFTER appointment_second_reminder_enabled",
        'sms_provider' => "ALTER TABLE payment_settings ADD sms_provider VARCHAR(20) NOT NULL DEFAULT 'none' AFTER appointment_second_reminder_hours",
        'sms_sender' => "ALTER TABLE payment_settings ADD sms_sender VARCHAR(40) DEFAULT NULL AFTER sms_provider",
        'sms_username' => "ALTER TABLE payment_settings ADD sms_username VARCHAR(120) DEFAULT NULL AFTER sms_sender",
        'sms_password' => "ALTER TABLE payment_settings ADD sms_password VARCHAR(255) DEFAULT NULL AFTER sms_username",
        'sms_api_key' => "ALTER TABLE payment_settings ADD sms_api_key VARCHAR(255) DEFAULT NULL AFTER sms_password",
        'sms_reminder_enabled' => "ALTER TABLE payment_settings ADD sms_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER sms_api_key",
        'sms_reminder_hours' => "ALTER TABLE payment_settings ADD sms_reminder_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24 AFTER sms_reminder_enabled",
        'min_booking_notice_days' => "ALTER TABLE payment_settings ADD min_booking_notice_days INT UNSIGNED NOT NULL DEFAULT 2 AFTER admin_notification_email",
        'max_booking_notice_days' => "ALTER TABLE payment_settings ADD max_booking_notice_days INT UNSIGNED NOT NULL DEFAULT 40 AFTER min_booking_notice_days",
        'appointment_start_time' => "ALTER TABLE payment_settings ADD appointment_start_time TIME NOT NULL DEFAULT '10:00:00' AFTER max_booking_notice_days",
        'appointment_end_time' => "ALTER TABLE payment_settings ADD appointment_end_time TIME NOT NULL DEFAULT '19:00:00' AFTER appointment_start_time",
        'break_start_time' => "ALTER TABLE payment_settings ADD break_start_time TIME DEFAULT '15:00:00' AFTER appointment_end_time",
        'break_end_time' => "ALTER TABLE payment_settings ADD break_end_time TIME DEFAULT '16:00:00' AFTER break_start_time",
        'available_weekdays' => "ALTER TABLE payment_settings ADD available_weekdays VARCHAR(32) NOT NULL DEFAULT '1,2,3,4,5' AFTER break_end_time",
        'smtp_host' => "ALTER TABLE payment_settings ADD smtp_host VARCHAR(255) DEFAULT NULL AFTER email_provider",
        'smtp_port' => "ALTER TABLE payment_settings ADD smtp_port INT UNSIGNED DEFAULT 587 AFTER smtp_host",
        'smtp_username' => "ALTER TABLE payment_settings ADD smtp_username VARCHAR(255) DEFAULT NULL AFTER smtp_port",
        'smtp_password' => "ALTER TABLE payment_settings ADD smtp_password VARCHAR(255) DEFAULT NULL AFTER smtp_username",
        'smtp_secure' => "ALTER TABLE payment_settings ADD smtp_secure ENUM('none', 'tls', 'ssl') NOT NULL DEFAULT 'tls' AFTER smtp_password",
        'smtp_from_email' => "ALTER TABLE payment_settings ADD smtp_from_email VARCHAR(255) DEFAULT NULL AFTER smtp_secure",
        'smtp_from_name' => "ALTER TABLE payment_settings ADD smtp_from_name VARCHAR(255) DEFAULT NULL AFTER smtp_from_email",
        'google_client_id' => "ALTER TABLE payment_settings ADD google_client_id VARCHAR(255) DEFAULT NULL AFTER smtp_from_name",
        'google_client_secret' => "ALTER TABLE payment_settings ADD google_client_secret VARCHAR(255) DEFAULT NULL AFTER google_client_id",
        'google_refresh_token' => "ALTER TABLE payment_settings ADD google_refresh_token TEXT DEFAULT NULL AFTER google_client_secret",
        'google_connected_email' => "ALTER TABLE payment_settings ADD google_connected_email VARCHAR(255) DEFAULT NULL AFTER google_refresh_token",
        'google_redirect_uri' => "ALTER TABLE payment_settings ADD google_redirect_uri VARCHAR(512) DEFAULT NULL AFTER google_connected_email",
        'calendar_provider' => "ALTER TABLE payment_settings ADD calendar_provider VARCHAR(16) NOT NULL DEFAULT 'none' AFTER google_redirect_uri",
        'google_calendar_enabled' => "ALTER TABLE payment_settings ADD google_calendar_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER google_redirect_uri",
        'google_calendar_id' => "ALTER TABLE payment_settings ADD google_calendar_id VARCHAR(255) DEFAULT 'primary' AFTER google_calendar_enabled",
        'icloud_calendar_email' => "ALTER TABLE payment_settings ADD icloud_calendar_email VARCHAR(255) DEFAULT NULL AFTER google_calendar_id",
        'icloud_calendar_app_password' => "ALTER TABLE payment_settings ADD icloud_calendar_app_password VARCHAR(255) DEFAULT NULL AFTER icloud_calendar_email",
        'icloud_calendar_url' => "ALTER TABLE payment_settings ADD icloud_calendar_url VARCHAR(512) DEFAULT 'https://caldav.icloud.com' AFTER icloud_calendar_app_password",
        'send_patient_calendar_link' => "ALTER TABLE payment_settings ADD send_patient_calendar_link TINYINT(1) NOT NULL DEFAULT 1 AFTER icloud_calendar_url",
        'fastcron_api_key' => "ALTER TABLE payment_settings ADD fastcron_api_key VARCHAR(255) DEFAULT NULL AFTER google_calendar_id",
        'fastcron_reminder_cron_id' => "ALTER TABLE payment_settings ADD fastcron_reminder_cron_id VARCHAR(64) DEFAULT NULL AFTER fastcron_api_key",
        'fastcron_planning_cron_id' => "ALTER TABLE payment_settings ADD fastcron_planning_cron_id VARCHAR(64) DEFAULT NULL AFTER fastcron_reminder_cron_id",
        'legal_owner_name' => "ALTER TABLE payment_settings ADD legal_owner_name VARCHAR(255) DEFAULT NULL",
        'legal_nif' => "ALTER TABLE payment_settings ADD legal_nif VARCHAR(50) DEFAULT NULL",
        'legal_address' => "ALTER TABLE payment_settings ADD legal_address VARCHAR(500) DEFAULT NULL",
        'legal_email' => "ALTER TABLE payment_settings ADD legal_email VARCHAR(255) DEFAULT NULL",
        'legal_license_number' => "ALTER TABLE payment_settings ADD legal_license_number VARCHAR(100) DEFAULT NULL",
        'legal_professional_college' => "ALTER TABLE payment_settings ADD legal_professional_college VARCHAR(255) DEFAULT NULL",
        'legal_uses_non_technical_cookies' => "ALTER TABLE payment_settings ADD legal_uses_non_technical_cookies TINYINT NOT NULL DEFAULT 0",
        'legal_terms_notes' => "ALTER TABLE payment_settings ADD legal_terms_notes TEXT DEFAULT NULL",
        'billing_enabled' => "ALTER TABLE payment_settings ADD billing_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'billing_country' => "ALTER TABLE payment_settings ADD billing_country VARCHAR(2) NOT NULL DEFAULT 'ES'",
        'billing_province' => "ALTER TABLE payment_settings ADD billing_province VARCHAR(80) DEFAULT NULL",
        'billing_session_concept' => "ALTER TABLE payment_settings ADD billing_session_concept VARCHAR(255) NOT NULL DEFAULT 'Sesion del dia {fecha} de duracion {duracion} minutos'",
        'billing_report_concept' => "ALTER TABLE payment_settings ADD billing_report_concept VARCHAR(255) NOT NULL DEFAULT 'Informe {titulo}'",
        'dashboard_config_mode' => "ALTER TABLE payment_settings ADD dashboard_config_mode VARCHAR(16) NOT NULL DEFAULT 'simple'",
        'sector_texts_key' => "ALTER TABLE payment_settings ADD sector_texts_key VARCHAR(32) NOT NULL DEFAULT 'psicologia' AFTER dashboard_config_mode"
    ];

    foreach ($columns as $column => $sql) {
        $res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE '$column'");
        if ($res->num_rows === 0) {
            $mysqli->query($sql);
        }
    }

    $mysqli->query("ALTER TABLE payment_settings ALTER min_booking_notice_days SET DEFAULT 2");
    $mysqli->query("ALTER TABLE payment_settings ALTER appointment_start_time SET DEFAULT '10:00:00'");
    $mysqli->query("ALTER TABLE payment_settings ALTER appointment_end_time SET DEFAULT '19:00:00'");
    $mysqli->query("ALTER TABLE payment_settings ALTER break_start_time SET DEFAULT '15:00:00'");
    $mysqli->query("
        UPDATE payment_settings
        SET calendar_provider = 'google'
        WHERE tenant_id = $tenant_id
          AND google_calendar_enabled = 1
          AND (calendar_provider IS NULL OR calendar_provider = '' OR calendar_provider = 'none')
    ");
    $mysqli->query("
        UPDATE payment_settings
        SET appointment_start_time = '10:00:00',
            appointment_end_time = '19:00:00',
            break_start_time = '15:00:00',
            break_end_time = '16:00:00'
        WHERE tenant_id = $tenant_id
          AND appointment_start_time = '09:00:00'
          AND appointment_end_time = '18:00:00'
          AND break_start_time = '13:00:00'
          AND break_end_time = '16:00:00'
    ");
}

function bind_params_dynamic($stmt, $types, $values)
{
    $refs = [];
    $refs[] = $types;
    foreach ($values as $key => $value) {
        $refs[] = &$values[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function nullable_decimal_value($value, $max = null)
{
    $value = str_replace(',', '.', trim((string) $value));
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    $number = (float) $value;
    if ($number < 0) {
        return null;
    }
    if ($max !== null && $number > (float) $max) {
        return null;
    }
    return number_format($number, 2, '.', '');
}

function ensure_patient_management_tables($mysqli)
{
    if (function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }

    $res = $mysqli->query("SHOW COLUMNS FROM users LIKE 'password_hash'");
    if ($res && $res->num_rows > 0) {
        $mysqli->query("ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL");
    }

    $res = $mysqli->query("SHOW COLUMNS FROM invitations LIKE 'user_id'");
    if ($res && $res->num_rows === 0) {
        $mysqli->query("ALTER TABLE invitations ADD user_id INT UNSIGNED DEFAULT NULL AFTER token");
        $mysqli->query("ALTER TABLE invitations ADD INDEX idx_invitations_user_id (user_id)");
    }

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS patient_profiles (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            patient_type VARCHAR(80) DEFAULT NULL,
            fiscal_name VARCHAR(180) DEFAULT NULL,
            fiscal_nif VARCHAR(50) DEFAULT NULL,
            invoice_use_alt_data TINYINT(1) NOT NULL DEFAULT 0,
            invoice_name VARCHAR(180) DEFAULT NULL,
            invoice_nif VARCHAR(50) DEFAULT NULL,
            invoice_email VARCHAR(180) DEFAULT NULL,
            invoice_phone VARCHAR(40) DEFAULT NULL,
            invoice_address VARCHAR(255) DEFAULT NULL,
            patient_status VARCHAR(20) NOT NULL DEFAULT 'active',
            waiting_list TINYINT(1) NOT NULL DEFAULT 0,
            birth_date DATE DEFAULT NULL,
            referral_source VARCHAR(80) DEFAULT NULL,
            knowledge_problem_id INT UNSIGNED DEFAULT NULL,
            initial_consultation_reason TEXT DEFAULT NULL,
            background_notes TEXT DEFAULT NULL,
            support_network_notes TEXT DEFAULT NULL,
            emergency_contact_name VARCHAR(150) DEFAULT NULL,
            emergency_contact_phone VARCHAR(40) DEFAULT NULL,
            emergency_contact_relation VARCHAR(80) DEFAULT NULL,
            address VARCHAR(255) DEFAULT NULL,
            admission_date DATE DEFAULT NULL,
            notes LONGTEXT DEFAULT NULL,
            physical_sex VARCHAR(12) DEFAULT NULL,
            weight_kg DECIMAL(6,2) DEFAULT NULL,
            height_cm DECIMAL(6,2) DEFAULT NULL,
            body_fat_percentage DECIMAL(5,2) DEFAULT NULL,
            waist_cm DECIMAL(6,2) DEFAULT NULL,
            hip_cm DECIMAL(6,2) DEFAULT NULL,
            chest_cm DECIMAL(6,2) DEFAULT NULL,
            thigh_cm DECIMAL(6,2) DEFAULT NULL,
            biceps_cm DECIMAL(6,2) DEFAULT NULL,
            calf_cm DECIMAL(6,2) DEFAULT NULL,
            skinfold_triceps_mm DECIMAL(6,2) DEFAULT NULL,
            skinfold_subscapular_mm DECIMAL(6,2) DEFAULT NULL,
            skinfold_suprailiac_mm DECIMAL(6,2) DEFAULT NULL,
            skinfold_abdominal_mm DECIMAL(6,2) DEFAULT NULL,
            skinfold_chest_mm DECIMAL(6,2) DEFAULT NULL,
            skinfold_thigh_mm DECIMAL(6,2) DEFAULT NULL,
            photo_path VARCHAR(255) DEFAULT NULL,
            document_path VARCHAR(255) DEFAULT NULL,
            document_name VARCHAR(255) DEFAULT NULL,
            created_by_admin TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_patient_profiles_type (patient_type),
            INDEX idx_patient_profiles_admission (admission_date),
            INDEX idx_patient_profiles_waiting_list (tenant_id, waiting_list)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = [
        'tenant_id' => "ALTER TABLE patient_profiles ADD tenant_id INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER user_id",
        'patient_status' => "ALTER TABLE patient_profiles ADD patient_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER patient_type",
        'fiscal_name' => "ALTER TABLE patient_profiles ADD fiscal_name VARCHAR(180) DEFAULT NULL AFTER patient_type",
        'fiscal_nif' => "ALTER TABLE patient_profiles ADD fiscal_nif VARCHAR(50) DEFAULT NULL AFTER fiscal_name",
        'invoice_use_alt_data' => "ALTER TABLE patient_profiles ADD invoice_use_alt_data TINYINT(1) NOT NULL DEFAULT 0 AFTER fiscal_nif",
        'invoice_name' => "ALTER TABLE patient_profiles ADD invoice_name VARCHAR(180) DEFAULT NULL AFTER invoice_use_alt_data",
        'invoice_nif' => "ALTER TABLE patient_profiles ADD invoice_nif VARCHAR(50) DEFAULT NULL AFTER invoice_name",
        'invoice_email' => "ALTER TABLE patient_profiles ADD invoice_email VARCHAR(180) DEFAULT NULL AFTER invoice_nif",
        'invoice_phone' => "ALTER TABLE patient_profiles ADD invoice_phone VARCHAR(40) DEFAULT NULL AFTER invoice_email",
        'invoice_address' => "ALTER TABLE patient_profiles ADD invoice_address VARCHAR(255) DEFAULT NULL AFTER invoice_phone",
        'waiting_list' => "ALTER TABLE patient_profiles ADD waiting_list TINYINT(1) NOT NULL DEFAULT 0 AFTER patient_status",
        'birth_date' => "ALTER TABLE patient_profiles ADD birth_date DATE DEFAULT NULL AFTER patient_status",
        'referral_source' => "ALTER TABLE patient_profiles ADD referral_source VARCHAR(80) DEFAULT NULL AFTER birth_date",
        'knowledge_problem_id' => "ALTER TABLE patient_profiles ADD knowledge_problem_id INT UNSIGNED DEFAULT NULL AFTER referral_source",
        'initial_consultation_reason' => "ALTER TABLE patient_profiles ADD initial_consultation_reason TEXT DEFAULT NULL AFTER referral_source",
        'background_notes' => "ALTER TABLE patient_profiles ADD background_notes TEXT DEFAULT NULL AFTER initial_consultation_reason",
        'support_network_notes' => "ALTER TABLE patient_profiles ADD support_network_notes TEXT DEFAULT NULL AFTER background_notes",
        'emergency_contact_name' => "ALTER TABLE patient_profiles ADD emergency_contact_name VARCHAR(150) DEFAULT NULL AFTER initial_consultation_reason",
        'emergency_contact_phone' => "ALTER TABLE patient_profiles ADD emergency_contact_phone VARCHAR(40) DEFAULT NULL AFTER emergency_contact_name",
        'emergency_contact_relation' => "ALTER TABLE patient_profiles ADD emergency_contact_relation VARCHAR(80) DEFAULT NULL AFTER emergency_contact_phone",
        'address' => "ALTER TABLE patient_profiles ADD address VARCHAR(255) DEFAULT NULL AFTER emergency_contact_relation",
        'physical_sex' => "ALTER TABLE patient_profiles ADD physical_sex VARCHAR(12) DEFAULT NULL AFTER notes",
        'weight_kg' => "ALTER TABLE patient_profiles ADD weight_kg DECIMAL(6,2) DEFAULT NULL AFTER physical_sex",
        'height_cm' => "ALTER TABLE patient_profiles ADD height_cm DECIMAL(6,2) DEFAULT NULL AFTER weight_kg",
        'body_fat_percentage' => "ALTER TABLE patient_profiles ADD body_fat_percentage DECIMAL(5,2) DEFAULT NULL AFTER height_cm",
        'waist_cm' => "ALTER TABLE patient_profiles ADD waist_cm DECIMAL(6,2) DEFAULT NULL AFTER body_fat_percentage",
        'hip_cm' => "ALTER TABLE patient_profiles ADD hip_cm DECIMAL(6,2) DEFAULT NULL AFTER waist_cm",
        'chest_cm' => "ALTER TABLE patient_profiles ADD chest_cm DECIMAL(6,2) DEFAULT NULL AFTER hip_cm",
        'thigh_cm' => "ALTER TABLE patient_profiles ADD thigh_cm DECIMAL(6,2) DEFAULT NULL AFTER chest_cm",
        'biceps_cm' => "ALTER TABLE patient_profiles ADD biceps_cm DECIMAL(6,2) DEFAULT NULL AFTER thigh_cm",
        'calf_cm' => "ALTER TABLE patient_profiles ADD calf_cm DECIMAL(6,2) DEFAULT NULL AFTER biceps_cm",
        'skinfold_triceps_mm' => "ALTER TABLE patient_profiles ADD skinfold_triceps_mm DECIMAL(6,2) DEFAULT NULL AFTER calf_cm",
        'skinfold_subscapular_mm' => "ALTER TABLE patient_profiles ADD skinfold_subscapular_mm DECIMAL(6,2) DEFAULT NULL AFTER skinfold_triceps_mm",
        'skinfold_suprailiac_mm' => "ALTER TABLE patient_profiles ADD skinfold_suprailiac_mm DECIMAL(6,2) DEFAULT NULL AFTER skinfold_subscapular_mm",
        'skinfold_abdominal_mm' => "ALTER TABLE patient_profiles ADD skinfold_abdominal_mm DECIMAL(6,2) DEFAULT NULL AFTER skinfold_suprailiac_mm",
        'skinfold_chest_mm' => "ALTER TABLE patient_profiles ADD skinfold_chest_mm DECIMAL(6,2) DEFAULT NULL AFTER skinfold_abdominal_mm",
        'skinfold_thigh_mm' => "ALTER TABLE patient_profiles ADD skinfold_thigh_mm DECIMAL(6,2) DEFAULT NULL AFTER skinfold_chest_mm",
        'photo_path' => "ALTER TABLE patient_profiles ADD photo_path VARCHAR(255) DEFAULT NULL AFTER notes",
        'document_path' => "ALTER TABLE patient_profiles ADD document_path VARCHAR(255) DEFAULT NULL AFTER notes",
        'document_name' => "ALTER TABLE patient_profiles ADD document_name VARCHAR(255) DEFAULT NULL AFTER document_path"
    ];
    foreach ($columns as $column => $sql) {
        $res = $mysqli->query("SHOW COLUMNS FROM patient_profiles LIKE '$column'");
        if ($res && $res->num_rows === 0) {
            $mysqli->query($sql);
        }
    }
    $index_res = $mysqli->query("SHOW INDEX FROM patient_profiles WHERE Key_name = 'idx_patient_profiles_knowledge_problem'");
    if ($index_res && $index_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE patient_profiles ADD INDEX idx_patient_profiles_knowledge_problem (knowledge_problem_id)");
    }
    $index_res = $mysqli->query("SHOW INDEX FROM patient_profiles WHERE Key_name = 'idx_patient_profiles_waiting_list'");
    if ($index_res && $index_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE patient_profiles ADD INDEX idx_patient_profiles_waiting_list (tenant_id, waiting_list)");
    }
}

function ensure_patient_evolution_tables($mysqli)
{
    if (function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }

    $tenant_id = current_tenant_id();
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS patient_evolution_notes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            patient_id INT UNSIGNED NOT NULL,
            appointment_id INT UNSIGNED DEFAULT NULL,
            professional_id INT UNSIGNED DEFAULT NULL,
            note_date DATE NOT NULL,
            title VARCHAR(180) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            observations LONGTEXT DEFAULT NULL,
            next_steps LONGTEXT DEFAULT NULL,
            weight_kg DECIMAL(6,2) DEFAULT NULL,
            height_cm DECIMAL(6,2) DEFAULT NULL,
            body_fat_percentage DECIMAL(5,2) DEFAULT NULL,
            waist_cm DECIMAL(6,2) DEFAULT NULL,
            hip_cm DECIMAL(6,2) DEFAULT NULL,
            chest_cm DECIMAL(6,2) DEFAULT NULL,
            thigh_cm DECIMAL(6,2) DEFAULT NULL,
            biceps_cm DECIMAL(6,2) DEFAULT NULL,
            calf_cm DECIMAL(6,2) DEFAULT NULL,
            skinfold_triceps_mm DECIMAL(6,2) DEFAULT NULL,
            skinfold_subscapular_mm DECIMAL(6,2) DEFAULT NULL,
            skinfold_suprailiac_mm DECIMAL(6,2) DEFAULT NULL,
            skinfold_abdominal_mm DECIMAL(6,2) DEFAULT NULL,
            skinfold_chest_mm DECIMAL(6,2) DEFAULT NULL,
            skinfold_thigh_mm DECIMAL(6,2) DEFAULT NULL,
            created_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_evolution_patient_date (patient_id, note_date),
            INDEX idx_evolution_appointment (appointment_id),
            INDEX idx_evolution_professional (professional_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS patient_evolution_files (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            evolution_note_id INT UNSIGNED NOT NULL,
            patient_id INT UNSIGNED NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(120) DEFAULT NULL,
            file_size INT UNSIGNED DEFAULT NULL,
            uploaded_by INT UNSIGNED DEFAULT NULL,
            uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_evolution_files_note (evolution_note_id),
            INDEX idx_evolution_files_patient (patient_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!column_exists($mysqli, 'patient_evolution_notes', 'tenant_id')) {
        $mysqli->query("ALTER TABLE patient_evolution_notes ADD tenant_id INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
    }
    if (!column_exists($mysqli, 'patient_evolution_files', 'tenant_id')) {
        $mysqli->query("ALTER TABLE patient_evolution_files ADD tenant_id INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
    }
    $columns = [
        'weight_kg' => "ALTER TABLE patient_evolution_notes ADD weight_kg DECIMAL(6,2) DEFAULT NULL AFTER next_steps",
        'height_cm' => "ALTER TABLE patient_evolution_notes ADD height_cm DECIMAL(6,2) DEFAULT NULL AFTER weight_kg",
        'body_fat_percentage' => "ALTER TABLE patient_evolution_notes ADD body_fat_percentage DECIMAL(5,2) DEFAULT NULL AFTER height_cm",
        'waist_cm' => "ALTER TABLE patient_evolution_notes ADD waist_cm DECIMAL(6,2) DEFAULT NULL AFTER body_fat_percentage",
        'hip_cm' => "ALTER TABLE patient_evolution_notes ADD hip_cm DECIMAL(6,2) DEFAULT NULL AFTER waist_cm",
        'chest_cm' => "ALTER TABLE patient_evolution_notes ADD chest_cm DECIMAL(6,2) DEFAULT NULL AFTER hip_cm",
        'thigh_cm' => "ALTER TABLE patient_evolution_notes ADD thigh_cm DECIMAL(6,2) DEFAULT NULL AFTER chest_cm",
        'biceps_cm' => "ALTER TABLE patient_evolution_notes ADD biceps_cm DECIMAL(6,2) DEFAULT NULL AFTER thigh_cm",
        'calf_cm' => "ALTER TABLE patient_evolution_notes ADD calf_cm DECIMAL(6,2) DEFAULT NULL AFTER biceps_cm",
        'skinfold_triceps_mm' => "ALTER TABLE patient_evolution_notes ADD skinfold_triceps_mm DECIMAL(6,2) DEFAULT NULL AFTER calf_cm",
        'skinfold_subscapular_mm' => "ALTER TABLE patient_evolution_notes ADD skinfold_subscapular_mm DECIMAL(6,2) DEFAULT NULL AFTER skinfold_triceps_mm",
        'skinfold_suprailiac_mm' => "ALTER TABLE patient_evolution_notes ADD skinfold_suprailiac_mm DECIMAL(6,2) DEFAULT NULL AFTER skinfold_subscapular_mm",
        'skinfold_abdominal_mm' => "ALTER TABLE patient_evolution_notes ADD skinfold_abdominal_mm DECIMAL(6,2) DEFAULT NULL AFTER skinfold_suprailiac_mm",
        'skinfold_chest_mm' => "ALTER TABLE patient_evolution_notes ADD skinfold_chest_mm DECIMAL(6,2) DEFAULT NULL AFTER skinfold_abdominal_mm",
        'skinfold_thigh_mm' => "ALTER TABLE patient_evolution_notes ADD skinfold_thigh_mm DECIMAL(6,2) DEFAULT NULL AFTER skinfold_chest_mm"
    ];
    foreach ($columns as $column => $sql) {
        if (!column_exists($mysqli, 'patient_evolution_notes', $column)) {
            $mysqli->query($sql);
        }
    }
}

function ensure_patient_document_tables($mysqli)
{
    if (function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }

    $tenant_id = current_tenant_id();
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS patient_documents (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            patient_id INT UNSIGNED NOT NULL,
            professional_id INT UNSIGNED DEFAULT NULL,
            document_type VARCHAR(30) NOT NULL DEFAULT 'file',
            title VARCHAR(180) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            document_date DATE DEFAULT NULL,
            score VARCHAR(80) DEFAULT NULL,
            result_label VARCHAR(120) DEFAULT NULL,
            observations LONGTEXT DEFAULT NULL,
            file_path VARCHAR(500) DEFAULT NULL,
            original_file_name VARCHAR(255) DEFAULT NULL,
            file_size INT UNSIGNED DEFAULT NULL,
            mime_type VARCHAR(120) DEFAULT NULL,
            visible_to_patient TINYINT(1) NOT NULL DEFAULT 0,
            result_visible_to_patient TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'completed',
            created_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_patient_documents_patient (tenant_id, patient_id, document_type),
            INDEX idx_patient_documents_date (tenant_id, patient_id, document_date),
            INDEX idx_patient_documents_portal (tenant_id, patient_id, visible_to_patient)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS patient_document_versions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            document_id INT UNSIGNED NOT NULL,
            version_type VARCHAR(30) NOT NULL DEFAULT 'completed',
            file_path VARCHAR(500) DEFAULT NULL,
            original_file_name VARCHAR(255) DEFAULT NULL,
            file_size INT UNSIGNED DEFAULT NULL,
            mime_type VARCHAR(120) DEFAULT NULL,
            score VARCHAR(80) DEFAULT NULL,
            result_label VARCHAR(120) DEFAULT NULL,
            observations LONGTEXT DEFAULT NULL,
            document_date DATE DEFAULT NULL,
            created_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_document_versions_document (tenant_id, document_id),
            INDEX idx_document_versions_date (tenant_id, document_id, document_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $document_columns = [
        'tenant_id' => "ALTER TABLE patient_documents ADD tenant_id INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id",
        'professional_id' => "ALTER TABLE patient_documents ADD professional_id INT UNSIGNED DEFAULT NULL AFTER patient_id",
        'document_type' => "ALTER TABLE patient_documents ADD document_type VARCHAR(30) NOT NULL DEFAULT 'file' AFTER professional_id",
        'description' => "ALTER TABLE patient_documents ADD description LONGTEXT DEFAULT NULL AFTER title",
        'document_date' => "ALTER TABLE patient_documents ADD document_date DATE DEFAULT NULL AFTER description",
        'score' => "ALTER TABLE patient_documents ADD score VARCHAR(80) DEFAULT NULL AFTER document_date",
        'result_label' => "ALTER TABLE patient_documents ADD result_label VARCHAR(120) DEFAULT NULL AFTER score",
        'observations' => "ALTER TABLE patient_documents ADD observations LONGTEXT DEFAULT NULL AFTER result_label",
        'file_path' => "ALTER TABLE patient_documents ADD file_path VARCHAR(500) DEFAULT NULL AFTER observations",
        'original_file_name' => "ALTER TABLE patient_documents ADD original_file_name VARCHAR(255) DEFAULT NULL AFTER file_path",
        'file_size' => "ALTER TABLE patient_documents ADD file_size INT UNSIGNED DEFAULT NULL AFTER original_file_name",
        'mime_type' => "ALTER TABLE patient_documents ADD mime_type VARCHAR(120) DEFAULT NULL AFTER file_size",
        'visible_to_patient' => "ALTER TABLE patient_documents ADD visible_to_patient TINYINT(1) NOT NULL DEFAULT 0 AFTER mime_type",
        'result_visible_to_patient' => "ALTER TABLE patient_documents ADD result_visible_to_patient TINYINT(1) NOT NULL DEFAULT 0 AFTER visible_to_patient",
        'status' => "ALTER TABLE patient_documents ADD status VARCHAR(30) NOT NULL DEFAULT 'completed' AFTER result_visible_to_patient",
        'created_by' => "ALTER TABLE patient_documents ADD created_by INT UNSIGNED DEFAULT NULL AFTER status"
    ];
    foreach ($document_columns as $column => $sql) {
        if (!column_exists($mysqli, 'patient_documents', $column)) {
            $mysqli->query($sql);
        }
    }

    $version_columns = [
        'tenant_id' => "ALTER TABLE patient_document_versions ADD tenant_id INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id",
        'version_type' => "ALTER TABLE patient_document_versions ADD version_type VARCHAR(30) NOT NULL DEFAULT 'completed' AFTER document_id",
        'score' => "ALTER TABLE patient_document_versions ADD score VARCHAR(80) DEFAULT NULL AFTER mime_type",
        'result_label' => "ALTER TABLE patient_document_versions ADD result_label VARCHAR(120) DEFAULT NULL AFTER score",
        'observations' => "ALTER TABLE patient_document_versions ADD observations LONGTEXT DEFAULT NULL AFTER result_label",
        'document_date' => "ALTER TABLE patient_document_versions ADD document_date DATE DEFAULT NULL AFTER observations",
        'created_by' => "ALTER TABLE patient_document_versions ADD created_by INT UNSIGNED DEFAULT NULL AFTER document_date"
    ];
    foreach ($version_columns as $column => $sql) {
        if (!column_exists($mysqli, 'patient_document_versions', $column)) {
            $mysqli->query($sql);
        }
    }
}

function ensure_patient_work_plan_tables($mysqli)
{
    if (function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }

    $tenant_id = current_tenant_id();
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS patient_work_plan_tasks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            patient_id INT UNSIGNED NOT NULL,
            appointment_id INT UNSIGNED DEFAULT NULL,
            professional_id INT UNSIGNED DEFAULT NULL,
            title VARCHAR(180) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            priority TINYINT UNSIGNED NOT NULL DEFAULT 2,
            visible_to_patient TINYINT(1) NOT NULL DEFAULT 0,
            fitness_exercise_id VARCHAR(40) DEFAULT NULL,
            created_by INT UNSIGNED DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            completed_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_work_plan_appointment (appointment_id),
            INDEX idx_work_plan_patient_status (patient_id, status),
            INDEX idx_work_plan_professional (professional_id),
            INDEX idx_work_plan_fitness_exercise (fitness_exercise_id),
            INDEX idx_work_plan_priority (priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = [
        'tenant_id' => "ALTER TABLE patient_work_plan_tasks ADD tenant_id INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id",
        'appointment_id' => "ALTER TABLE patient_work_plan_tasks ADD appointment_id INT UNSIGNED DEFAULT NULL AFTER patient_id",
        'visible_to_patient' => "ALTER TABLE patient_work_plan_tasks ADD visible_to_patient TINYINT(1) NOT NULL DEFAULT 0 AFTER priority",
        'fitness_exercise_id' => "ALTER TABLE patient_work_plan_tasks ADD fitness_exercise_id VARCHAR(40) DEFAULT NULL AFTER visible_to_patient"
    ];
    foreach ($columns as $column => $sql) {
        $res = $mysqli->query("SHOW COLUMNS FROM patient_work_plan_tasks LIKE '$column'");
        if ($res && $res->num_rows === 0) {
            $mysqli->query($sql);
        }
    }
    $index_res = $mysqli->query("SHOW INDEX FROM patient_work_plan_tasks WHERE Key_name = 'idx_work_plan_appointment'");
    if ($index_res && $index_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE patient_work_plan_tasks ADD INDEX idx_work_plan_appointment (appointment_id)");
    }
    if (!index_exists($mysqli, 'patient_work_plan_tasks', 'idx_work_plan_fitness_exercise')) {
        $mysqli->query("ALTER TABLE patient_work_plan_tasks ADD INDEX idx_work_plan_fitness_exercise (fitness_exercise_id)");
    }
}

function ensure_work_plan_task_template_tables($mysqli)
{
    if (function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }

    $tenant_id = current_tenant_id();
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS work_plan_task_templates (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            professional_id INT UNSIGNED DEFAULT NULL,
            category VARCHAR(120) DEFAULT NULL,
            title VARCHAR(180) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            priority TINYINT UNSIGNED NOT NULL DEFAULT 2,
            is_global TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_task_templates_professional (professional_id),
            INDEX idx_task_templates_category (category),
            INDEX idx_task_templates_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS work_plan_task_template_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            template_id INT UNSIGNED NOT NULL,
            title VARCHAR(180) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            priority TINYINT UNSIGNED NOT NULL DEFAULT 2,
            fitness_exercise_id VARCHAR(40) DEFAULT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_template_items_template (template_id),
            INDEX idx_template_items_sort (template_id, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if (!column_exists($mysqli, 'work_plan_task_templates', 'tenant_id')) {
        $mysqli->query("ALTER TABLE work_plan_task_templates ADD tenant_id INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
    }
    if (!column_exists($mysqli, 'work_plan_task_template_items', 'tenant_id')) {
        $mysqli->query("ALTER TABLE work_plan_task_template_items ADD tenant_id INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
    }
    if (!column_exists($mysqli, 'work_plan_task_template_items', 'fitness_exercise_id')) {
        $mysqli->query("ALTER TABLE work_plan_task_template_items ADD fitness_exercise_id VARCHAR(40) DEFAULT NULL AFTER priority");
    }
}

function patient_has_portal_access($row)
{
    return !empty($row['email']) && !empty($row['password_hash']);
}

function patient_physical_metric_columns()
{
    return [
        'weight_kg',
        'height_cm',
        'body_fat_percentage',
        'waist_cm',
        'hip_cm',
        'chest_cm',
        'thigh_cm',
        'biceps_cm',
        'calf_cm',
        'skinfold_triceps_mm',
        'skinfold_subscapular_mm',
        'skinfold_suprailiac_mm',
        'skinfold_abdominal_mm',
        'skinfold_chest_mm',
        'skinfold_thigh_mm'
    ];
}

function patient_physical_metrics_have_values(array $metrics)
{
    foreach (patient_physical_metric_columns() as $column) {
        if (isset($metrics[$column]) && $metrics[$column] !== null && $metrics[$column] !== '') {
            return true;
        }
    }
    return false;
}

function normalize_patient_physical_value($value)
{
    if ($value === null || $value === '') {
        return '';
    }
    if (is_numeric($value)) {
        $normalized = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        return $normalized === '-0' ? '0' : $normalized;
    }
    return trim((string) $value);
}

function patient_physical_metrics_changed(?array $current, array $metrics, $physical_sex)
{
    if ($current === null) {
        return patient_physical_metrics_have_values($metrics) || normalize_patient_physical_value($physical_sex) !== '';
    }
    if (normalize_patient_physical_value($current['physical_sex'] ?? '') !== normalize_patient_physical_value($physical_sex)) {
        return true;
    }
    foreach (patient_physical_metric_columns() as $column) {
        if (normalize_patient_physical_value($current[$column] ?? '') !== normalize_patient_physical_value($metrics[$column] ?? '')) {
            return true;
        }
    }
    return false;
}

function bind_stmt_dynamic_params($stmt, string $types, array $values)
{
    $refs = [$types];
    foreach ($values as $key => $value) {
        $refs[] = &$values[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function update_patient_profile_physical_metrics($mysqli, $tenant_id, $patient_id, array $metrics, $physical_sex = null)
{
    $sets = [];
    $types = '';
    $values = [];
    if ($physical_sex !== '__keep__') {
        $sets[] = 'physical_sex = ?';
        $types .= 's';
        $values[] = $physical_sex;
    }
    foreach (patient_physical_metric_columns() as $column) {
        $sets[] = "$column = ?";
        $types .= 's';
        $values[] = $metrics[$column] ?? null;
    }
    if (!$sets) {
        return;
    }
    $types .= 'ii';
    $values[] = $tenant_id;
    $values[] = $patient_id;
    $stmt = $mysqli->prepare("UPDATE patient_profiles SET " . implode(', ', $sets) . " WHERE tenant_id = ? AND user_id = ?");
    bind_stmt_dynamic_params($stmt, $types, $values);
    $stmt->execute();
}

function create_initial_patient_evolution_if_needed($mysqli, $tenant_id, $patient_id, $professional_id, $admission_date, array $metrics, $created_by)
{
    if (!patient_physical_metrics_have_values($metrics)) {
        return;
    }
    $stmt = $mysqli->prepare("SELECT id FROM patient_evolution_notes WHERE tenant_id = ? AND patient_id = ? LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        return;
    }
    $note_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $admission_date) ? $admission_date : date('Y-m-d');
    $title = 'Medicion inicial';
    $description = 'Registro inicial creado desde la ficha.';
    $observations = '';
    $next_steps = '';
    $appointment_id = null;
    $professional_id = $professional_id > 0 ? $professional_id : null;
    $stmt = $mysqli->prepare("
        INSERT INTO patient_evolution_notes
            (tenant_id, patient_id, appointment_id, professional_id, note_date, title, description, observations, next_steps,
             weight_kg, height_cm, body_fat_percentage, waist_cm, hip_cm, chest_cm, thigh_cm, biceps_cm, calf_cm,
             skinfold_triceps_mm, skinfold_subscapular_mm, skinfold_suprailiac_mm, skinfold_abdominal_mm, skinfold_chest_mm, skinfold_thigh_mm, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $values = [];
    foreach (patient_physical_metric_columns() as $column) {
        $values[] = $metrics[$column] ?? null;
    }
    $bind_values = array_merge(
        [$tenant_id, $patient_id, $appointment_id, $professional_id, $note_date, $title, $description, $observations, $next_steps],
        $values,
        [$created_by]
    );
    bind_stmt_dynamic_params($stmt, "iiiissssssssssssssssssssi", $bind_values);
    $stmt->execute();
}

function upsert_today_patient_evolution_from_profile_metrics($mysqli, $tenant_id, $patient_id, $professional_id, array $metrics, $created_by)
{
    if (!patient_physical_metrics_have_values($metrics)) {
        return;
    }

    $note_date = date('Y-m-d');
    $stmt = $mysqli->prepare("
        SELECT id
        FROM patient_evolution_notes
        WHERE tenant_id = ?
          AND patient_id = ?
          AND note_date = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param("iis", $tenant_id, $patient_id, $note_date);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $professional_id = $professional_id > 0 ? $professional_id : null;

    if ($existing) {
        $sets = ['professional_id = COALESCE(?, professional_id)'];
        $types = 'i';
        $values = [$professional_id];
        foreach (patient_physical_metric_columns() as $column) {
            $sets[] = "$column = ?";
            $types .= 's';
            $values[] = $metrics[$column] ?? null;
        }
        $types .= 'ii';
        $values[] = $tenant_id;
        $values[] = (int) $existing['id'];
        $stmt = $mysqli->prepare("UPDATE patient_evolution_notes SET " . implode(', ', $sets) . " WHERE tenant_id = ? AND id = ?");
        bind_stmt_dynamic_params($stmt, $types, $values);
        $stmt->execute();
        return;
    }

    $title = 'Medicion de seguimiento';
    $description = 'Registro creado desde la ficha.';
    $observations = '';
    $next_steps = '';
    $appointment_id = null;
    $stmt = $mysqli->prepare("
        INSERT INTO patient_evolution_notes
            (tenant_id, patient_id, appointment_id, professional_id, note_date, title, description, observations, next_steps,
             weight_kg, height_cm, body_fat_percentage, waist_cm, hip_cm, chest_cm, thigh_cm, biceps_cm, calf_cm,
             skinfold_triceps_mm, skinfold_subscapular_mm, skinfold_suprailiac_mm, skinfold_abdominal_mm, skinfold_chest_mm, skinfold_thigh_mm, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $metric_values = [];
    foreach (patient_physical_metric_columns() as $column) {
        $metric_values[] = $metrics[$column] ?? null;
    }
    $bind_values = array_merge(
        [$tenant_id, $patient_id, $appointment_id, $professional_id, $note_date, $title, $description, $observations, $next_steps],
        $metric_values,
        [$created_by]
    );
    bind_stmt_dynamic_params($stmt, "iiiissssssssssssssssssssi", $bind_values);
    $stmt->execute();
}

function create_initial_patient_evolution_from_profile_if_needed($mysqli, $tenant_id, $patient_id, $created_by)
{
    $stmt = $mysqli->prepare("
        SELECT u.created_at AS user_created_at,
               pp.admission_date, pp.created_at AS profile_created_at,
               COALESCE(ppf.professional_id, pp.professional_id) AS professional_id,
               pp.weight_kg, pp.height_cm, pp.body_fat_percentage, pp.waist_cm, pp.hip_cm, pp.chest_cm, pp.thigh_cm, pp.biceps_cm, pp.calf_cm,
               pp.skinfold_triceps_mm, pp.skinfold_subscapular_mm, pp.skinfold_suprailiac_mm,
               pp.skinfold_abdominal_mm, pp.skinfold_chest_mm, pp.skinfold_thigh_mm
        FROM users u
        LEFT JOIN patient_profiles pp
          ON pp.user_id = u.id
         AND pp.tenant_id = u.tenant_id
        LEFT JOIN patient_professionals ppf
          ON ppf.patient_id = u.id
         AND ppf.tenant_id = u.tenant_id
         AND ppf.is_primary = 1
        WHERE u.tenant_id = ?
          AND u.id = ?
          AND u.role = 'patient'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    if (!$profile) {
        return;
    }

    $metrics = [];
    foreach (patient_physical_metric_columns() as $column) {
        $metrics[$column] = $profile[$column] ?? null;
    }
    $admission_date = $profile['admission_date'] ?: substr((string) ($profile['profile_created_at'] ?: $profile['user_created_at'] ?: date('Y-m-d')), 0, 10);
    create_initial_patient_evolution_if_needed(
        $mysqli,
        $tenant_id,
        $patient_id,
        (int) ($profile['professional_id'] ?? 0),
        $admission_date,
        $metrics,
        $created_by
    );
}

function stored_upload_full_path($relative_path)
{
    $relative_path = ltrim((string) $relative_path, '/\\');
    if ($relative_path === '') {
        return '';
    }
    return app_protected_path_from_relative($relative_path);
}

function save_patient_document_upload($file, $patient_id)
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new \Exception('No se pudo subir el archivo.');
    }
    if (($file['size'] ?? 0) > 12 * 1024 * 1024) {
        throw new \Exception('El archivo no puede superar 12 MB.');
    }

    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowed = [
        'pdf' => 'pdf',
        'xls' => 'xls',
        'xlsx' => 'xlsx'
    ];
    if (!isset($allowed[$extension])) {
        throw new \Exception('Formato no valido. Usa PDF, XLS o XLSX.');
    }

    $upload_dir = app_tenant_protected_upload_dir('patients');
    if (!app_ensure_dir($upload_dir)) {
        throw new \Exception('No se pudo crear la carpeta de documentos.');
    }

    $filename = 'patient_' . (int) $patient_id . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$extension];
    $destination = $upload_dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new \Exception('No se pudo guardar el documento.');
    }

    return [
        'path' => app_tenant_protected_upload_relative_path('patients', $filename),
        'name' => basename($file['name'])
    ];
}

function patient_evolution_upload_dir()
{
    return app_tenant_protected_upload_dir('evolution');
}

function normalize_multiple_uploads($files)
{
    if (!$files || empty($files['name'])) {
        return [];
    }
    if (!is_array($files['name'])) {
        return [$files];
    }

    $normalized = [];
    foreach ($files['name'] as $index => $name) {
        $normalized[] = [
            'name' => $name,
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0
        ];
    }
    return $normalized;
}

function save_patient_evolution_uploads($mysqli, $files, $note_id, $patient_id)
{
    $saved = 0;
    $allowed_extensions = [
        'pdf' => 'application/pdf',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif'
    ];
    $upload_dir = patient_evolution_upload_dir();
    if (!app_ensure_dir($upload_dir)) {
        throw new \Exception('No se pudo crear la carpeta protegida de archivos.');
    }

    foreach (normalize_multiple_uploads($files) as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception('No se pudo subir uno de los archivos.');
        }
        if (($file['size'] ?? 0) > 12 * 1024 * 1024) {
            throw new \Exception('Cada archivo debe pesar como máximo 12 MB.');
        }

        $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!isset($allowed_extensions[$extension])) {
            throw new \Exception('Formato no valido. Usa PDF, Excel o imagen.');
        }

        $mime = $allowed_extensions[$extension];
        $stored_name = 'evolution_' . (int) $patient_id . '_' . (int) $note_id . '_' . bin2hex(random_bytes(12)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
        $destination = $upload_dir . '/' . $stored_name;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new \Exception('No se pudo guardar uno de los archivos.');
        }

        $relative_path = app_tenant_protected_upload_relative_path('evolution', $stored_name);
        $original_name = basename($file['name']);
        $file_size = (int) ($file['size'] ?? 0);
        $uploaded_by = (int) ($_SESSION['user_id'] ?? 0);
        $stmt = $mysqli->prepare("
            INSERT INTO patient_evolution_files (tenant_id, evolution_note_id, patient_id, original_name, stored_name, file_path, mime_type, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $tenant_id = current_tenant_id();
        $stmt->bind_param("iiissssii", $tenant_id, $note_id, $patient_id, $original_name, $stored_name, $relative_path, $mime, $file_size, $uploaded_by);
        $stmt->execute();
        $saved++;
    }
    return $saved;
}

function save_patient_document_file_upload($file, $patient_id)
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new \Exception('No se pudo subir el archivo.');
    }
    if (($file['size'] ?? 0) > 12 * 1024 * 1024) {
        throw new \Exception('El archivo no puede superar 12 MB.');
    }

    $allowed_extensions = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif'
    ];

    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!isset($allowed_extensions[$extension])) {
        throw new \Exception('Formato no valido. Usa PDF, DOC, DOCX, Excel o imagen.');
    }

    $upload_dir = app_tenant_protected_upload_dir('documents');
    if (!app_ensure_dir($upload_dir)) {
        throw new \Exception('No se pudo crear la carpeta protegida de documentos.');
    }

    $safe_extension = $extension === 'jpeg' ? 'jpg' : $extension;
    $stored_name = 'document_' . (int) $patient_id . '_' . bin2hex(random_bytes(12)) . '.' . $safe_extension;
    $destination = $upload_dir . '/' . $stored_name;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new \Exception('No se pudo guardar el archivo.');
    }

    return [
        'path' => app_tenant_protected_upload_relative_path('documents', $stored_name),
        'name' => basename($file['name']),
        'size' => (int) ($file['size'] ?? 0),
        'mime' => $allowed_extensions[$extension]
    ];
}

function save_patient_photo_upload($file, $patient_id)
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new \Exception('No se pudo subir la foto del paciente.');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new \Exception('La foto del paciente no puede superar 2 MB.');
    }

    $image_info = @getimagesize($file['tmp_name']);
    if (!$image_info || !in_array($image_info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        throw new \Exception('Formato de foto no valido. Usa JPG, PNG, WEBP o GIF.');
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];
    $upload_dir = app_tenant_public_upload_dir('patients');
    if (!app_ensure_dir($upload_dir)) {
        throw new \Exception('No se pudo crear la carpeta de fotos de pacientes.');
    }

    $filename = 'patient_photo_' . (int) $patient_id . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$image_info['mime']];
    $destination = $upload_dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new \Exception('No se pudo guardar la foto del paciente.');
    }

    return app_tenant_public_upload_relative_path('patients', $filename);
}

function normalize_time_field($value, $default = '')
{
    $value = trim((string) $value);
    if ($value === '') {
        return $default;
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $value)) {
        return null;
    }
    [$hours, $minutes] = array_map('intval', explode(':', $value));
    if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
        return null;
    }
    return sprintf('%02d:%02d:00', $hours, $minutes);
}

function time_to_minutes($time)
{
    [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)));
    return ($hours * 60) + $minutes;
}

function normalize_available_weekdays($value)
{
    $selected = [];
    foreach ((array) $value as $day) {
        $day = (int) $day;
        if ($day >= 1 && $day <= 6 && !in_array($day, $selected, true)) {
            $selected[] = $day;
        }
    }

    sort($selected);
    return $selected ? implode(',', $selected) : '';
}

function normalize_available_session_types($value, $mysqli = null)
{
    $allowed = [];
    if ($mysqli) {
        ensure_appointment_services_tables($mysqli);
        $tenant_id = current_tenant_id();
        $res = $mysqli->query("SELECT service_key FROM appointment_services WHERE tenant_id = $tenant_id");
        while ($res && ($row = $res->fetch_assoc())) {
            $allowed[] = $row['service_key'];
        }
    }
    if (!$allowed) {
        $sector_texts = $mysqli ? sector_texts_for_db($mysqli) : sector_texts_builtin_psychology();
        $allowed = array_column(sector_appointment_services_from_texts($sector_texts), 'key');
    }
    $defaults = array_slice($allowed, 0, 1) ?: sector_default_appointment_service_keys($mysqli ? sector_texts_for_db($mysqli) : sector_texts_builtin_psychology());
    $selected = [];
    foreach ((array) $value as $type) {
        $type = trim((string) $type);
        if (in_array($type, $allowed, true) && !in_array($type, $selected, true)) {
            $selected[] = $type;
        }
    }

    return implode(',', $selected ?: $defaults);
}

function normalize_available_session_durations($value, $mysqli = null)
{
    $defaults = sector_default_appointment_duration_minutes($mysqli ? sector_texts_for_db($mysqli) : sector_texts_builtin_psychology());
    $selected = [];
    foreach ((array) $value as $duration) {
        $duration = (int) $duration;
        if ($duration > 0 && $duration <= 480 && !in_array($duration, $selected, true)) {
            $selected[] = $duration;
        }
    }

    sort($selected);
    return implode(',', $selected ?: $defaults);
}

function normalize_optional_url($value, $label)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $value)) {
        $value = 'https://' . $value;
    }
    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        throw new \Exception($label . ' no tiene una URL valida.');
    }
    return $value;
}

function sync_service_availability($mysqli, $available_session_types, $available_session_durations, $appointment_delivery_mode)
{
    ensure_appointment_services_tables($mysqli);
    $tenant_id = current_tenant_id();

    $active_service_types = array_filter(array_map('trim', explode(',', $available_session_types ?: 'individual')));
    $active_durations = array_map('intval', explode(',', $available_session_durations ?: '60'));
    $services = fetch_appointment_services($mysqli);
    foreach ($services as $service) {
        foreach ($active_durations as $duration) {
            if ($duration <= 0 || $duration > 480) {
                continue;
            }
            foreach (['presencial', 'online'] as $consultation_type) {
                $exists = false;
                foreach ($service['options'] as $option) {
                    if ((int) $option['duration_minutes'] === (int) $duration && $option['consultation_type'] === $consultation_type) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $price = appointment_default_option_price([], $service['service_key'], $consultation_type, $duration);
                    $sort = ($duration * 10) + ($consultation_type === 'online' ? 1 : 0);
                    $stmt = $mysqli->prepare("
                        INSERT IGNORE INTO appointment_service_options (tenant_id, service_id, duration_minutes, consultation_type, price, is_active, sort_order)
                        VALUES (?, ?, ?, ?, ?, 0, ?)
                    ");
                    $stmt->bind_param("iiisdi", $tenant_id, $service['id'], $duration, $consultation_type, $price, $sort);
                    $stmt->execute();
                }
            }
        }
        $service['options'] = [];
        $option_res = $mysqli->query("SELECT id, duration_minutes, consultation_type FROM appointment_service_options WHERE tenant_id = $tenant_id AND service_id = " . (int) $service['id']);
        while ($option_res && ($option_row = $option_res->fetch_assoc())) {
            $service['options'][] = [
                'id' => (int) $option_row['id'],
                'duration_minutes' => (int) $option_row['duration_minutes'],
                'consultation_type' => $option_row['consultation_type']
            ];
        }

        $service_allowed = in_array($service['service_key'], $active_service_types, true);
        $service_active = $service_allowed ? 1 : 0;
        $stmt = $mysqli->prepare("UPDATE appointment_services SET is_active = ? WHERE tenant_id = ? AND id = ?");
        $stmt->bind_param("iii", $service_active, $tenant_id, $service['id']);
        $stmt->execute();

        foreach ($service['options'] as $option) {
            $is_active = $service_allowed
                && in_array((int) $option['duration_minutes'], $active_durations, true)
                && ($appointment_delivery_mode === 'both' || $option['consultation_type'] === $appointment_delivery_mode)
                ? 1
                : 0;
            $stmt = $mysqli->prepare("UPDATE appointment_service_options SET is_active = ? WHERE tenant_id = ? AND id = ?");
            $stmt->bind_param("iii", $is_active, $tenant_id, $option['id']);
            $stmt->execute();
        }
    }
}

function normalize_location_id($mysqli, $location_id, $allow_disabled = false)
{
    $location_id = (int) $location_id;
    if ($location_id <= 0) {
        return default_appointment_location_id($mysqli);
    }
    $location = fetch_appointment_location($mysqli, $location_id);
    if (!$location || (!$allow_disabled && (int) ($location['is_enabled'] ?? 0) !== 1)) {
        return default_appointment_location_id($mysqli);
    }
    return (int) $location['id'];
}

function csv_without_value($csv, $value, $numeric = false)
{
    $value = $numeric ? (string) (int) $value : (string) $value;
    $items = array_filter(array_map('trim', explode(',', (string) $csv)));
    $items = array_values(array_filter($items, static function ($item) use ($value, $numeric) {
        return ($numeric ? (string) (int) $item : (string) $item) !== $value;
    }));
    return implode(',', array_unique($items));
}

function save_uploaded_settings_image($file, $prefix)
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new \Exception('No se pudo subir la imagen.');
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        throw new \Exception('La imagen no puede superar 2 MB.');
    }

    $image_info = @getimagesize($file['tmp_name']);
    if (!$image_info || !in_array($image_info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        throw new \Exception('Formato de imagen no válido. Usa JPG, PNG, WEBP o GIF.');
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];
    $upload_dir = app_tenant_public_upload_dir('settings');
    if (!app_ensure_dir($upload_dir)) {
        throw new \Exception('No se pudo crear la carpeta de imágenes.');
    }

    $filename = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$image_info['mime']];
    $destination = $upload_dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new \Exception('No se pudo guardar la imagen.');
    }

    return app_tenant_public_upload_relative_path('settings', $filename);
}

function save_uploaded_professional_photo($file, $professional_id)
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE => 'La foto supera el limite permitido por el servidor.',
            UPLOAD_ERR_FORM_SIZE => 'La foto supera el limite permitido por el formulario.',
            UPLOAD_ERR_PARTIAL => 'La foto se subio solo parcialmente. Intentalo de nuevo.',
            UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene carpeta temporal para procesar la foto.',
            UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir la foto subida.',
            UPLOAD_ERR_EXTENSION => 'Una extension del servidor bloqueo la subida de la foto.'
        ];
        throw new \Exception($upload_errors[$file['error']] ?? 'No se pudo subir la foto del profesional.');
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new \Exception('La foto del profesional no puede superar 5 MB.');
    }

    $image_info = @getimagesize($file['tmp_name']);
    if (!$image_info || !in_array($image_info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        throw new \Exception('Formato de foto no valido. Usa JPG, PNG, WEBP o GIF.');
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];
    $upload_dir = app_tenant_public_upload_dir('professionals');
    if (!app_ensure_dir($upload_dir)) {
        throw new \Exception('No se pudo crear la carpeta de fotos de profesionales.');
    }

    $filename = 'professional_' . (int) $professional_id . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$image_info['mime']];
    $destination = $upload_dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new \Exception('No se pudo guardar la foto del profesional.');
    }

    return app_tenant_public_upload_relative_path('professionals', $filename);
}

if ($action === 'generate_invite') {
    if (!app_feature_enabled_from_db($mysqli, 'patientPortal.enabled', false) || !app_feature_enabled_from_db($mysqli, 'patientPortal.invitations', false)) {
        echo json_encode(['success' => false, 'error' => 'Las invitaciones del portal no estan disponibles en este plan.']);
        exit;
    }
    $token = bin2hex(random_bytes(32));
    $invite_user_id = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    if ($invite_user_id > 0) {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE tenant_id = ? AND id = ? AND role = 'patient'");
        $stmt->bind_param("ii", $tenant_id, $invite_user_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'Paciente no encontrado.']);
            exit;
        }
    } else {
        $invite_user_id = null;
    }

    $stmt = $mysqli->prepare("INSERT INTO invitations (tenant_id, token, user_id) VALUES (?, ?, ?)");
    $stmt->bind_param("isi", $tenant_id, $token, $invite_user_id);
    if ($stmt->execute()) {
        // Obtenemos el protocolo y el dominio actual para crear el enlace completo
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $domainName = $_SERVER['HTTP_HOST'];
        $path = dirname(dirname($_SERVER['REQUEST_URI']));

        // Remove trailing slash if exists
        $path = rtrim($path, '/');

        $link = $protocol . $domainName . $path . '/register.php?token=' . $token;
        $link = urlme_shorten_url($link, 'Invitacion registro SimplyGest Praxis');
        echo json_encode(['success' => true, 'link' => $link, 'token' => $token]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Error generando invitación']);
    }
} elseif ($action === 'get_patients') {
    $professional_id = $is_superadmin ? 0 : admin_requested_professional_filter($mysqli);
    $branding = get_public_branding_settings($mysqli);
    $dashboard_photo = $branding['profile_image_path'] ?? '';
    $professional_where = $professional_id > 0 ? " AND COALESCE(ppf.professional_id, pp.professional_id) = " . (int) $professional_id : ($professional_id < 0 ? " AND 1 = 0" : "");
    $res = $mysqli->query("
        SELECT u.id, u.name, u.email, u.phone,
               COALESCE(ppf.professional_id, pp.professional_id) AS professional_id,
               p.display_name AS professional_name, p.public_photo_path AS professional_photo_path,
               pu.role AS professional_user_role
        FROM users u
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id AND pp.tenant_id = u.tenant_id
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.tenant_id = u.tenant_id AND ppf.is_primary = 1
        LEFT JOIN professionals p ON p.id = COALESCE(ppf.professional_id, pp.professional_id) AND p.tenant_id = u.tenant_id
        LEFT JOIN users pu ON pu.id = p.user_id AND pu.tenant_id = u.tenant_id
        WHERE u.tenant_id = $tenant_id
          AND u.role = 'patient'
          $professional_where
        ORDER BY u.name ASC
    ");
    $patients = [];
    while ($row = $res->fetch_assoc()) {
        $row['professional_photo_path'] = professional_photo_with_dashboard_fallback($row, $dashboard_photo);
        $patients[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'] ?? '',
            'phone' => $row['phone'] ?? '',
            'professional_id' => (int) ($row['professional_id'] ?? 0),
            'professional_name' => $row['professional_name'] ?? '',
            'professional_photo_path' => $row['professional_photo_path'] ?? ''
        ];
    }
    echo json_encode([
        'success' => true,
        'patients' => $patients,
        'professionals' => $is_superadmin ? active_professionals_payload($mysqli) : [],
        'current_professional_id' => current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0))
    ]);
} elseif ($action === 'list_patients') {
    $professional_id = $is_superadmin ? 0 : admin_requested_professional_filter($mysqli);
    $branding = get_public_branding_settings($mysqli);
    $dashboard_photo = $branding['profile_image_path'] ?? '';
    $professional_where = $professional_id > 0 ? " AND COALESCE(ppf.professional_id, pp.professional_id) = " . (int) $professional_id : ($professional_id < 0 ? " AND 1 = 0" : "");
    $res = $mysqli->query("
        SELECT u.id, u.name, u.email, u.phone, u.created_at, u.password_hash,
               pp.patient_type, pp.fiscal_name, pp.fiscal_nif,
               pp.invoice_use_alt_data, pp.invoice_name, pp.invoice_nif, pp.invoice_email, pp.invoice_phone, pp.invoice_address,
               pp.patient_status, pp.waiting_list, pp.birth_date, pp.referral_source, pp.knowledge_problem_id, pp.initial_consultation_reason,
               pp.background_notes, pp.support_network_notes,
               pp.emergency_contact_name, pp.emergency_contact_phone, pp.emergency_contact_relation, pp.address,
               pp.admission_date, pp.notes, pp.physical_sex,
               COALESCE(pen_latest.weight_kg, pp.weight_kg) AS weight_kg,
               COALESCE(pen_latest.height_cm, pp.height_cm) AS height_cm,
               COALESCE(pen_latest.body_fat_percentage, pp.body_fat_percentage) AS body_fat_percentage,
               COALESCE(pen_latest.waist_cm, pp.waist_cm) AS waist_cm,
               COALESCE(pen_latest.hip_cm, pp.hip_cm) AS hip_cm,
               COALESCE(pen_latest.chest_cm, pp.chest_cm) AS chest_cm,
               COALESCE(pen_latest.thigh_cm, pp.thigh_cm) AS thigh_cm,
               COALESCE(pen_latest.biceps_cm, pp.biceps_cm) AS biceps_cm,
               COALESCE(pen_latest.calf_cm, pp.calf_cm) AS calf_cm,
               COALESCE(pen_latest.skinfold_triceps_mm, pp.skinfold_triceps_mm) AS skinfold_triceps_mm,
               COALESCE(pen_latest.skinfold_subscapular_mm, pp.skinfold_subscapular_mm) AS skinfold_subscapular_mm,
               COALESCE(pen_latest.skinfold_suprailiac_mm, pp.skinfold_suprailiac_mm) AS skinfold_suprailiac_mm,
               COALESCE(pen_latest.skinfold_abdominal_mm, pp.skinfold_abdominal_mm) AS skinfold_abdominal_mm,
               COALESCE(pen_latest.skinfold_chest_mm, pp.skinfold_chest_mm) AS skinfold_chest_mm,
               COALESCE(pen_latest.skinfold_thigh_mm, pp.skinfold_thigh_mm) AS skinfold_thigh_mm,
               pp.photo_path, pp.document_path, pp.document_name, pp.created_by_admin,
               COALESCE(ppf.professional_id, pp.professional_id) AS professional_id,
               p.display_name AS professional_name, p.public_photo_path AS professional_photo_path,
               pu.role AS professional_user_role
        FROM users u
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id AND pp.tenant_id = u.tenant_id
        LEFT JOIN patient_evolution_notes pen_latest
          ON pen_latest.id = (
              SELECT n2.id
              FROM patient_evolution_notes n2
              WHERE n2.tenant_id = u.tenant_id
                AND n2.patient_id = u.id
                AND (
                    n2.weight_kg IS NOT NULL OR n2.height_cm IS NOT NULL OR n2.body_fat_percentage IS NOT NULL OR
                    n2.waist_cm IS NOT NULL OR n2.hip_cm IS NOT NULL OR n2.chest_cm IS NOT NULL OR
                    n2.thigh_cm IS NOT NULL OR n2.biceps_cm IS NOT NULL OR n2.calf_cm IS NOT NULL OR
                    n2.skinfold_triceps_mm IS NOT NULL OR n2.skinfold_subscapular_mm IS NOT NULL OR
                    n2.skinfold_suprailiac_mm IS NOT NULL OR n2.skinfold_abdominal_mm IS NOT NULL OR
                    n2.skinfold_chest_mm IS NOT NULL OR n2.skinfold_thigh_mm IS NOT NULL
                )
              ORDER BY n2.note_date DESC, n2.id DESC
              LIMIT 1
          )
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.tenant_id = u.tenant_id AND ppf.is_primary = 1
        LEFT JOIN professionals p ON p.id = COALESCE(ppf.professional_id, pp.professional_id) AND p.tenant_id = u.tenant_id
        LEFT JOIN users pu ON pu.id = p.user_id AND pu.tenant_id = u.tenant_id
        WHERE u.tenant_id = $tenant_id
          AND u.role = 'patient'
          $professional_where
        ORDER BY u.name ASC
    ");
    $patients = [];
    while ($row = $res->fetch_assoc()) {
        $professional_photo_path = professional_photo_with_dashboard_fallback($row, $dashboard_photo);
        $patients[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'] ?? '',
            'phone' => $row['phone'] ?? '',
            'patient_type' => $row['patient_type'] ?? '',
            'fiscal_name' => $row['fiscal_name'] ?? '',
            'fiscal_nif' => $row['fiscal_nif'] ?? '',
            'invoice_use_alt_data' => (int) ($row['invoice_use_alt_data'] ?? 0),
            'invoice_name' => $row['invoice_name'] ?? '',
            'invoice_nif' => $row['invoice_nif'] ?? '',
            'invoice_email' => $row['invoice_email'] ?? '',
            'invoice_phone' => $row['invoice_phone'] ?? '',
            'invoice_address' => $row['invoice_address'] ?? '',
            'patient_status' => $row['patient_status'] ?? 'active',
            'waiting_list' => (int) ($row['waiting_list'] ?? 0),
            'birth_date' => $row['birth_date'] ?? '',
            'referral_source' => $row['referral_source'] ?? '',
            'knowledge_problem_id' => (int) ($row['knowledge_problem_id'] ?? 0),
            'initial_consultation_reason' => $row['initial_consultation_reason'] ?? '',
            'background_notes' => $row['background_notes'] ?? '',
            'support_network_notes' => $row['support_network_notes'] ?? '',
            'emergency_contact_name' => $row['emergency_contact_name'] ?? '',
            'emergency_contact_phone' => $row['emergency_contact_phone'] ?? '',
            'emergency_contact_relation' => $row['emergency_contact_relation'] ?? '',
            'address' => $row['address'] ?? '',
            'admission_date' => $row['admission_date'] ?? substr((string) $row['created_at'], 0, 10),
            'notes' => $row['notes'] ?? '',
            'physical_sex' => $row['physical_sex'] ?? '',
            'weight_kg' => $row['weight_kg'] ?? '',
            'height_cm' => $row['height_cm'] ?? '',
            'body_fat_percentage' => $row['body_fat_percentage'] ?? '',
            'waist_cm' => $row['waist_cm'] ?? '',
            'hip_cm' => $row['hip_cm'] ?? '',
            'chest_cm' => $row['chest_cm'] ?? '',
            'thigh_cm' => $row['thigh_cm'] ?? '',
            'biceps_cm' => $row['biceps_cm'] ?? '',
            'calf_cm' => $row['calf_cm'] ?? '',
            'skinfold_triceps_mm' => $row['skinfold_triceps_mm'] ?? '',
            'skinfold_subscapular_mm' => $row['skinfold_subscapular_mm'] ?? '',
            'skinfold_suprailiac_mm' => $row['skinfold_suprailiac_mm'] ?? '',
            'skinfold_abdominal_mm' => $row['skinfold_abdominal_mm'] ?? '',
            'skinfold_chest_mm' => $row['skinfold_chest_mm'] ?? '',
            'skinfold_thigh_mm' => $row['skinfold_thigh_mm'] ?? '',
            'photo_path' => $row['photo_path'] ?? '',
            'document_path' => $row['document_path'] ?? '',
            'document_name' => $row['document_name'] ?? '',
            'professional_id' => (int) ($row['professional_id'] ?? 0),
            'professional_name' => $row['professional_name'] ?? '',
            'professional_photo_path' => $professional_photo_path,
            'created_by_admin' => (int) ($row['created_by_admin'] ?? 0),
            'has_portal_access' => patient_has_portal_access($row) ? 1 : 0
        ];
    }
    echo json_encode([
        'success' => true,
        'patients' => $patients,
        'professionals' => $is_superadmin ? active_professionals_payload($mysqli) : [],
        'current_professional_id' => current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0))
    ]);
} elseif ($action === 'waiting_list_patients') {
    ensure_patient_management_tables($mysqli);
    $professional_id = $is_superadmin ? 0 : admin_requested_professional_filter($mysqli);
    $professional_where = $professional_id > 0
        ? " AND COALESCE(ppf.professional_id, pp.professional_id) = " . (int) $professional_id
        : ($professional_id < 0 ? " AND 1 = 0" : "");
    $res = $mysqli->query("
        SELECT u.id, u.name
        FROM users u
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id AND pp.tenant_id = u.tenant_id
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.tenant_id = u.tenant_id AND ppf.is_primary = 1
        WHERE u.tenant_id = $tenant_id
          AND u.role = 'patient'
          AND COALESCE(pp.waiting_list, 0) = 1
          $professional_where
        ORDER BY u.name ASC
    ");
    $patients = [];
    while ($row = $res->fetch_assoc()) {
        $patients[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'] ?? ''
        ];
    }
    echo json_encode([
        'success' => true,
        'count' => count($patients),
        'patients' => $patients
    ]);
} elseif ($action === 'global_search') {
    ensure_patient_management_tables($mysqli);
    ensure_patient_evolution_tables($mysqli);
    ensure_patient_work_plan_tables($mysqli);
    ensure_cabinet_schema($mysqli);
    $query = trim((string) ($_GET['q'] ?? ''));
    if (strlen($query) < 2) {
        echo json_encode(['success' => true, 'query' => $query, 'results' => []]);
        exit;
    }
    $like = global_search_like_term($query);
    $professional_id = $is_superadmin ? 0 : current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
    $professional_join = "
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id AND pp.tenant_id = u.tenant_id
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.tenant_id = u.tenant_id AND ppf.is_primary = 1
        LEFT JOIN professionals p ON p.id = COALESCE(ppf.professional_id, pp.professional_id) AND p.tenant_id = u.tenant_id
    ";
    $professional_where = !$is_superadmin ? " AND COALESCE(ppf.professional_id, pp.professional_id) = ?" : "";
    $results = [
        'patients' => [],
        'professionals' => [],
        'appointments' => [],
        'files' => [],
        'tasks' => []
    ];

    if (!$is_superadmin && $professional_id <= 0) {
        echo json_encode(['success' => true, 'query' => $query, 'results' => $results]);
        exit;
    }

    $sql = "
        SELECT u.id, u.name, u.email, u.phone, pp.patient_type, p.display_name AS professional_name
        FROM users u
        $professional_join
        WHERE u.tenant_id = ?
          AND u.role = 'patient'
          AND (u.name LIKE ? ESCAPE '\\\\' OR u.email LIKE ? ESCAPE '\\\\' OR u.phone LIKE ? ESCAPE '\\\\' OR pp.patient_type LIKE ? ESCAPE '\\\\')
          $professional_where
        ORDER BY u.name ASC
        LIMIT 12
    ";
    $stmt = $mysqli->prepare($sql);
    if ($is_superadmin) {
        $stmt->bind_param("issss", $tenant_id, $like, $like, $like, $like);
    } else {
        $stmt->bind_param("issssi", $tenant_id, $like, $like, $like, $like, $professional_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        global_search_push(
            $results['patients'],
            'patients',
            $row['name'] ?? 'Paciente',
            trim(($row['email'] ?? '') . (($row['email'] ?? '') && ($row['phone'] ?? '') ? ' · ' : '') . ($row['phone'] ?? '')),
            trim(($row['patient_type'] ?? '') . (($row['patient_type'] ?? '') && ($row['professional_name'] ?? '') ? ' · ' : '') . ($row['professional_name'] ?? '')),
            'bi-person',
            ['kind' => 'patient', 'id' => (int) $row['id']]
        );
    }

    if ($is_superadmin) {
        $stmt = $mysqli->prepare("
            SELECT p.id, p.display_name, p.public_email, p.public_phone, u.email AS login_email
            FROM professionals p
            LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
            WHERE p.tenant_id = ?
              AND (p.display_name LIKE ? ESCAPE '\\\\'
               OR p.public_email LIKE ? ESCAPE '\\\\'
               OR p.public_phone LIKE ? ESCAPE '\\\\'
               OR u.email LIKE ? ESCAPE '\\\\')
            ORDER BY p.display_name ASC
            LIMIT 8
        ");
        $stmt->bind_param("issss", $tenant_id, $like, $like, $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            global_search_push(
                $results['professionals'],
                'professionals',
                $row['display_name'] ?? 'Profesional',
                trim(($row['public_email'] ?: $row['login_email'] ?: '') . (($row['public_phone'] ?? '') ? ' · ' . $row['public_phone'] : '')),
                '',
                'bi-person-badge',
                ['kind' => 'professional', 'id' => (int) $row['id']]
            );
        }
    }

    $appointment_professional_where = !$is_superadmin ? " AND a.professional_id = ?" : "";
    $sql = "
        SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.consultation_type,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               s.name AS service_name,
               u.id AS patient_id, u.name AS patient_name, u.email AS patient_email, u.phone AS patient_phone,
               p.display_name AS professional_name
        FROM appointments a
        JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        LEFT JOIN professionals p ON p.id = a.professional_id AND p.tenant_id = a.tenant_id
        WHERE a.tenant_id = ?
          AND (u.name LIKE ? ESCAPE '\\\\' OR u.email LIKE ? ESCAPE '\\\\' OR u.phone LIKE ? ESCAPE '\\\\'
               OR p.display_name LIKE ? ESCAPE '\\\\' OR s.name LIKE ? ESCAPE '\\\\'
               OR a.appointment_date LIKE ? ESCAPE '\\\\')
          $appointment_professional_where
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
        LIMIT 12
    ";
    $stmt = $mysqli->prepare($sql);
    if ($is_superadmin) {
        $stmt->bind_param("issssss", $tenant_id, $like, $like, $like, $like, $like, $like);
    } else {
        $stmt->bind_param("issssssi", $tenant_id, $like, $like, $like, $like, $like, $like, $professional_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $date_label = trim(report_datetime(trim(($row['appointment_date'] ?? '') . ' ' . ($row['appointment_time'] ?? ''))));
        global_search_push(
            $results['appointments'],
            'appointments',
            ($row['patient_name'] ?? 'Cita') . ' · ' . $date_label,
            appointment_service_option_label($row) . ' · ' . (($row['consultation_type'] ?? 'presencial') === 'online' ? 'Online' : 'Presencial'),
            trim(($row['professional_name'] ?? '') . (($row['status'] ?? '') ? ' · ' . ($row['status'] ?? '') : '')),
            'bi-calendar-check',
            ['kind' => 'appointment', 'id' => (int) $row['id'], 'patient_id' => (int) $row['patient_id']]
        );
    }

    $file_professional_where = !$is_superadmin ? " AND COALESCE(ppf.professional_id, pp.professional_id) = ?" : "";
    $sql = "
        SELECT f.id, f.original_name, f.uploaded_at, n.title AS note_title, u.id AS patient_id, u.name AS patient_name
        FROM patient_evolution_files f
        JOIN patient_evolution_notes n ON n.id = f.evolution_note_id AND n.tenant_id = f.tenant_id
        JOIN users u ON u.id = f.patient_id AND u.tenant_id = f.tenant_id
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id AND pp.tenant_id = u.tenant_id
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.tenant_id = u.tenant_id AND ppf.is_primary = 1
        WHERE (f.original_name LIKE ? ESCAPE '\\\\' OR n.title LIKE ? ESCAPE '\\\\' OR u.name LIKE ? ESCAPE '\\\\')
          AND f.tenant_id = ?
          $file_professional_where
        ORDER BY f.uploaded_at DESC, f.id DESC
        LIMIT 12
    ";
    $stmt = $mysqli->prepare($sql);
    if ($is_superadmin) {
        $stmt->bind_param("sssi", $like, $like, $like, $tenant_id);
    } else {
        $stmt->bind_param("sssii", $like, $like, $like, $tenant_id, $professional_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        global_search_push(
            $results['files'],
            'files',
            $row['original_name'] ?? 'Archivo',
            ($row['patient_name'] ?? '') . (($row['note_title'] ?? '') ? ' · ' . $row['note_title'] : ''),
            report_datetime($row['uploaded_at'] ?? ''),
            'bi-paperclip',
            ['kind' => 'file', 'id' => (int) $row['id'], 'patient_id' => (int) $row['patient_id']]
        );
    }

    $task_professional_where = !$is_superadmin ? " AND COALESCE(ppf.professional_id, pp.professional_id) = ?" : "";
    $sql = "
        SELECT t.id, t.title, t.description, t.status, t.completed_at, u.id AS patient_id, u.name AS patient_name
        FROM patient_work_plan_tasks t
        JOIN users u ON u.id = t.patient_id AND u.tenant_id = t.tenant_id
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id AND pp.tenant_id = u.tenant_id
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.tenant_id = u.tenant_id AND ppf.is_primary = 1
        WHERE (t.title LIKE ? ESCAPE '\\\\' OR t.description LIKE ? ESCAPE '\\\\' OR u.name LIKE ? ESCAPE '\\\\')
          AND t.tenant_id = ?
          $task_professional_where
        ORDER BY t.updated_at DESC, t.id DESC
        LIMIT 12
    ";
    $stmt = $mysqli->prepare($sql);
    if ($is_superadmin) {
        $stmt->bind_param("sssi", $like, $like, $like, $tenant_id);
    } else {
        $stmt->bind_param("sssii", $like, $like, $like, $tenant_id, $professional_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        global_search_push(
            $results['tasks'],
            'tasks',
            $row['title'] ?? 'Tarea',
            $row['patient_name'] ?? '',
            ($row['status'] ?? '') === 'completed' ? 'Completada' : 'Pendiente',
            'bi-list-check',
            ['kind' => 'patient', 'id' => (int) $row['patient_id']]
        );
    }

    echo json_encode(['success' => true, 'query' => $query, 'results' => $results]);
} elseif ($action === 'list_app_logs') {
    ensure_app_logs_table($mysqli);
    $search = trim((string) ($_GET['search'] ?? ''));
    $date_to = trim((string) ($_GET['date_to'] ?? ''));
    $date_from = trim((string) ($_GET['date_from'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $date_to = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $date_from = date('Y-m-d', strtotime('-30 days'));
    }
    if ($date_from > $date_to) {
        [$date_from, $date_to] = [$date_to, $date_from];
    }

    $params = [$tenant_id, $date_from . ' 00:00:00', $date_to . ' 23:59:59'];
    $types = 'iss';
    $where = "l.tenant_id = ? AND l.created_at BETWEEN ? AND ?";
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where .= " AND (l.action LIKE ? OR l.channel LIKE ? OR l.status LIKE ? OR l.title LIKE ? OR l.message LIKE ? OR u.name LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like, $like);
        $types .= 'ssssss';
    }

    $stmt = $mysqli->prepare("
        SELECT l.id, l.created_at, l.user_id, l.target_type, l.target_id, l.action, l.channel, l.status,
               l.title, l.message, u.name AS user_name
        FROM app_logs l
        LEFT JOIN users u ON u.id = l.user_id AND u.tenant_id = l.tenant_id
        WHERE $where
        ORDER BY l.created_at DESC, l.id DESC
        LIMIT 300
    ");
    bind_params_dynamic($stmt, $types, $params);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int) $row['id'],
            'created_at' => $row['created_at'],
            'user_id' => (int) ($row['user_id'] ?? 0),
            'user_name' => $row['user_name'] ?? '',
            'target_type' => $row['target_type'] ?? '',
            'target_id' => (int) ($row['target_id'] ?? 0),
            'action' => $row['action'] ?? '',
            'channel' => $row['channel'] ?? '',
            'status' => $row['status'] ?? '',
            'title' => $row['title'] ?? '',
            'message' => $row['message'] ?? ''
        ];
    }
    echo json_encode(['success' => true, 'logs' => $rows, 'date_from' => $date_from, 'date_to' => $date_to]);
} elseif ($action === 'appointment_payment_detail') {
    ensure_appointment_payment_columns($mysqli);
    ensure_appointment_services_tables($mysqli);
    $appointment_id = (int) ($_GET['appointment_id'] ?? 0);
    [$can_manage, $appointment] = admin_can_manage_appointment_payment($mysqli, $appointment_id);
    if (!$can_manage || !$appointment) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para ver esta cita.']);
        exit;
    }

    $method = $appointment['payment_method'] ?? '';
    $professional_settings = cabinet_get_effective_professional_settings($mysqli, (int) ($appointment['professional_id'] ?? 0));
    $professional_delivery_mode = $professional_settings['appointment_delivery_mode'] ?? 'both';
    $livekit_for_professional = livekit_enabled_for_professional($mysqli, (int) ($appointment['professional_id'] ?? 0));
    $branding_settings = get_public_branding_settings($mysqli);
    $knowledge_problem = appointment_patient_knowledge_problem_payload($mysqli, (int) ($appointment['user_id'] ?? 0));
    echo json_encode([
        'success' => true,
        'appointment' => [
            'id' => (int) $appointment['id'],
            'patient_id' => (int) $appointment['user_id'],
            'patient_name' => $appointment['patient_name'] ?? '',
            'patient_email' => $appointment['patient_email'] ?? '',
            'patient_phone' => $appointment['patient_phone'] ?? '',
            'patient_address' => $appointment['patient_address'] ?? '',
            'professional_id' => (int) ($appointment['professional_id'] ?? 0),
            'professional_name' => $appointment['professional_name'] ?? '',
            'appointment_date' => $appointment['appointment_date'],
            'appointment_time' => substr((string) $appointment['appointment_time'], 0, 5),
            'status' => $appointment['status'] ?? '',
            'consultation_type' => $appointment['consultation_type'] ?? 'presencial',
            'location_id' => (int) ($appointment['location_id'] ?? 0),
            'location_name' => $appointment['location_name'] ?? '',
            'location_type' => $appointment['location_type'] ?? '',
            'online_session_url' => $appointment['online_session_url'] ?? '',
            'session_notes' => $appointment['session_notes'] ?? '',
            'livekit_enabled' => $livekit_for_professional ? 1 : 0,
            'default_appointment_location' => trim((string) ($professional_settings['default_appointment_location'] ?? '')),
            'default_location_id' => (int) ($professional_settings['default_location_id'] ?? 0),
            'locations' => fetch_appointment_locations($mysqli, true),
            'center_address' => trim((string) ($branding_settings['legal_address'] ?? '')),
            'can_online_appointment' => in_array($professional_delivery_mode, ['both', 'online'], true) ? 1 : 0,
            'can_presential_appointment' => in_array($professional_delivery_mode, ['both', 'presencial'], true) ? 1 : 0,
            'duration_minutes' => (int) ($appointment['duration_minutes'] ?? 60),
            'service_label' => appointment_service_option_label($appointment),
            'payment_status' => $appointment['payment_status'] ?? 'pending',
            'payment_method' => $method,
            'payment_method_label' => payment_method_label($method),
            'patient_bonus_id' => $appointment['patient_bonus_id'],
            'paid_at' => $appointment['paid_at'],
            'payment_updated_at' => $appointment['payment_updated_at'] ?? null,
            'is_bonus_payment' => ($method === 'bonus' || !empty($appointment['patient_bonus_id'])) ? 1 : 0,
            'knowledge_problem' => $knowledge_problem
        ]
    ]);
} elseif ($action === 'appointment_session') {
    ensure_patient_work_plan_tables($mysqli);
    ensure_patient_evolution_tables($mysqli);
    $appointment_id = (int) ($_GET['appointment_id'] ?? 0);
    [$can_manage, $appointment] = admin_can_manage_appointment_payment($mysqli, $appointment_id);
    if (!$can_manage || !$appointment) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para ver esta sesion.']);
        exit;
    }
    $patient_id = (int) ($appointment['user_id'] ?? 0);

    $stmt = $mysqli->prepare("
        SELECT id, patient_id, appointment_id, professional_id, title, description, status, priority, visible_to_patient,
               completed_at, created_at, updated_at
        FROM patient_work_plan_tasks
        WHERE tenant_id = ?
          AND patient_id = ?
        ORDER BY
            CASE WHEN status = 'pending' THEN 0 ELSE 1 END,
            priority ASC,
            updated_at DESC,
            id DESC
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $tasks_res = $stmt->get_result();
    $tasks = [];
    while ($row = $tasks_res->fetch_assoc()) {
        $tasks[] = [
            'id' => (int) $row['id'],
            'patient_id' => (int) $row['patient_id'],
            'appointment_id' => (int) ($row['appointment_id'] ?? 0),
            'professional_id' => (int) ($row['professional_id'] ?? 0),
            'title' => $row['title'],
            'description' => $row['description'] ?? '',
            'status' => $row['status'],
            'priority' => (int) ($row['priority'] ?? 2),
            'visible_to_patient' => (int) ($row['visible_to_patient'] ?? 0),
            'completed_at' => $row['completed_at'] ?? '',
            'created_at' => $row['created_at'] ?? '',
            'updated_at' => $row['updated_at'] ?? ''
        ];
    }

    $stmt = $mysqli->prepare("
        SELECT n.id, n.patient_id, n.appointment_id, n.professional_id, n.note_date, n.title,
               n.description, n.observations, n.next_steps, n.created_at, n.updated_at,
               COUNT(f.id) AS file_count
        FROM patient_evolution_notes n
        LEFT JOIN patient_evolution_files f ON f.evolution_note_id = n.id AND f.tenant_id = n.tenant_id
        WHERE n.tenant_id = ?
          AND n.patient_id = ? AND n.appointment_id = ?
        GROUP BY n.id
        ORDER BY n.note_date DESC, n.id DESC
    ");
    $stmt->bind_param("iii", $tenant_id, $patient_id, $appointment_id);
    $stmt->execute();
    $notes_res = $stmt->get_result();
    $notes = [];
    while ($row = $notes_res->fetch_assoc()) {
        $notes[] = [
            'id' => (int) $row['id'],
            'patient_id' => (int) $row['patient_id'],
            'appointment_id' => (int) ($row['appointment_id'] ?? 0),
            'professional_id' => (int) ($row['professional_id'] ?? 0),
            'note_date' => $row['note_date'],
            'title' => $row['title'],
            'description' => $row['description'] ?? '',
            'observations' => $row['observations'] ?? '',
            'next_steps' => $row['next_steps'] ?? '',
            'file_count' => (int) ($row['file_count'] ?? 0),
            'files' => [],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }

    $stmt = $mysqli->prepare("
        SELECT f.id, f.evolution_note_id, f.original_name, f.file_size, f.uploaded_at, n.title
        FROM patient_evolution_files f
        JOIN patient_evolution_notes n ON n.id = f.evolution_note_id AND n.tenant_id = f.tenant_id
        WHERE f.tenant_id = ?
          AND f.patient_id = ? AND n.appointment_id = ?
        ORDER BY f.uploaded_at DESC, f.id DESC
    ");
    $stmt->bind_param("iii", $tenant_id, $patient_id, $appointment_id);
    $stmt->execute();
    $files_res = $stmt->get_result();
    $files = [];
    while ($row = $files_res->fetch_assoc()) {
        $file_payload = [
            'id' => (int) $row['id'],
            'note_id' => (int) ($row['evolution_note_id'] ?? 0),
            'name' => $row['original_name'],
            'source' => $row['title'] ?: 'Nota de sesion',
            'date' => $row['uploaded_at'],
            'size' => (int) ($row['file_size'] ?? 0),
            'url' => 'api/admin.php?action=download_evolution_file&id=' . (int) $row['id']
        ];
        $files[] = $file_payload;
        foreach ($notes as &$note) {
            if ((int) $note['id'] === (int) $file_payload['note_id']) {
                $note['files'][] = $file_payload;
                break;
            }
        }
        unset($note);
    }

    echo json_encode([
        'success' => true,
        'appointment_id' => $appointment_id,
        'patient_id' => $patient_id,
        'tasks' => $tasks,
        'notes' => $notes,
        'files' => $files
    ]);
} elseif ($action === 'update_appointment_payment') {
    ensure_appointment_payment_columns($mysqli);
    ensure_appointment_services_tables($mysqli);
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    $payment_status = $_POST['payment_status'] ?? 'pending';
    $payment_method = trim($_POST['payment_method'] ?? '');
    $session_notes_was_posted = array_key_exists('session_notes', $_POST);
    $session_notes = trim((string) ($_POST['session_notes'] ?? ''));
    [$can_manage, $appointment] = admin_can_manage_appointment_payment($mysqli, $appointment_id);
    if (!$can_manage || !$appointment) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para actualizar esta cita.']);
        exit;
    }
    if (($appointment['payment_method'] ?? '') === 'bonus' || !empty($appointment['patient_bonus_id'])) {
        echo json_encode(['success' => false, 'error' => 'Esta cita fue pagada con bono y no es posible modificarlo desde aqui.']);
        exit;
    }
    if (($appointment['status'] ?? '') === 'cancelled') {
        echo json_encode(['success' => false, 'error' => 'No se puede modificar el pago de una cita cancelada.']);
        exit;
    }
    if (!in_array($payment_status, ['pending', 'paid'], true)) {
        echo json_encode(['success' => false, 'error' => 'Estado de pago no válido.']);
        exit;
    }

    $allowed_methods = ['card', 'bizum', 'cash', 'bank_transfer', 'other', 'manual'];
    $current_status = $appointment['payment_status'] ?? 'pending';
    $marking_as_paid = $payment_status === 'paid' && $current_status !== 'paid';
    $billing_enabled = invoice_billing_enabled($mysqli);
    if ($billing_enabled && $marking_as_paid && !invoice_manual_confirmation_received()) {
        echo json_encode(['success' => false, 'error' => 'Confirma la emision de la factura para marcar esta cita como pagada.']);
        exit;
    }
    if ($billing_enabled && $marking_as_paid) {
        [$recipient_ok, $recipient_error] = invoice_recipient_for_user($mysqli, (int) ($appointment['user_id'] ?? 0));
        if (!$recipient_ok) {
            echo json_encode(['success' => false, 'error' => $recipient_error]);
            exit;
        }
    }
    $existing_invoice = invoice_existing_for_origin($mysqli, 'appointment', $appointment_id);
    if ($existing_invoice) {
        $current_method = $appointment['payment_method'] ?? '';
        if ($payment_status !== $current_status || ($payment_status === 'paid' && $payment_method !== $current_method)) {
            echo json_encode(['success' => false, 'error' => 'Esta cita ya tiene factura emitida y no se puede modificar el cobro.']);
            exit;
        }
    }
    if ($payment_status === 'paid' && !in_array($payment_method, $allowed_methods, true)) {
        echo json_encode(['success' => false, 'error' => 'Indica una forma de pago valida.']);
        exit;
    }

    $mysqli->begin_transaction();
    try {
    if ($payment_status === 'paid') {
        if (!in_array($payment_method, $allowed_methods, true)) {
            echo json_encode(['success' => false, 'error' => 'Indica una forma de pago válida.']);
            exit;
        }
        $stmt = $mysqli->prepare("
            UPDATE appointments
            SET payment_status = 'paid',
                payment_method = ?,
                paid_at = COALESCE(paid_at, NOW()),
                payment_updated_at = NOW(),
                payment_updated_by = ?
            WHERE tenant_id = ? AND id = ?
        ");
        $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
        $stmt->bind_param("siii", $payment_method, $session_user_id, $tenant_id, $appointment_id);
    } else {
        $stmt = $mysqli->prepare("
            UPDATE appointments
            SET payment_status = 'pending',
                payment_method = NULL,
                paid_at = NULL,
                payment_updated_at = NOW(),
                payment_updated_by = ?
            WHERE tenant_id = ? AND id = ?
        ");
        $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
        $stmt->bind_param("iii", $session_user_id, $tenant_id, $appointment_id);
    }

    if (!$stmt->execute()) {
        throw new Exception('No se pudo actualizar el pago.');
    }

    if ($session_notes_was_posted) {
        $stmt = $mysqli->prepare("UPDATE appointments SET session_notes = ? WHERE tenant_id = ? AND id = ?");
        $stmt->bind_param("sii", $session_notes, $tenant_id, $appointment_id);
        if (!$stmt->execute()) {
            throw new Exception('No se pudieron guardar las notas de la sesion.');
        }
    }

    $invoice_warning = '';
    $invoice_number = '';
    if ($payment_status === 'paid') {
        $invoice_result = invoice_emit_for_appointment($mysqli, $appointment_id, $payment_method);
        if (!empty($invoice_result['success'])) {
            $invoice_number = $invoice_result['invoice_number'] ?? '';
        } else {
            throw new Exception($invoice_result['error'] ?? 'No se pudo emitir la factura.');
        }
    }
        $mysqli->commit();
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }

    $previous_method = $appointment['payment_method'] ?? '';
    if ($payment_status !== $current_status || ($payment_status === 'paid' && $payment_method !== $previous_method)) {
        app_log($mysqli, [
            'action' => 'appointment_payment_updated',
            'status' => 'ok',
            'target_type' => 'appointment',
            'target_id' => $appointment_id,
            'title' => 'Pago de cita actualizado',
            'message' => 'Pago actualizado de ' . ($current_status ?: 'pending') . ' a ' . $payment_status . '.',
            'metadata' => [
                'patient_id' => (int) ($appointment['user_id'] ?? 0),
                'patient_name' => $appointment['patient_name'] ?? '',
                'professional_id' => (int) ($appointment['professional_id'] ?? 0),
                'appointment_date' => $appointment['appointment_date'] ?? '',
                'appointment_time' => $appointment['appointment_time'] ?? '',
                'previous_payment_status' => $current_status,
                'new_payment_status' => $payment_status,
                'previous_payment_method' => $previous_method,
                'new_payment_method' => $payment_status === 'paid' ? $payment_method : '',
                'invoice_number' => $invoice_number
            ]
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => $invoice_warning ? 'Cambios guardados, pero no se pudo emitir la factura: ' . $invoice_warning : 'Cambios guardados correctamente.',
        'invoice_number' => $invoice_number,
        'invoice_warning' => $invoice_warning,
        'session_notes' => $session_notes_was_posted ? $session_notes : ($appointment['session_notes'] ?? '')
    ]);
} elseif ($action === 'update_appointment_status') {
    ensure_appointment_payment_columns($mysqli);
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    $status = $_POST['status'] ?? 'booked';
    [$can_manage, $appointment] = admin_can_manage_appointment_payment($mysqli, $appointment_id);
    if (!$can_manage || !$appointment) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para actualizar esta cita.']);
        exit;
    }
    if (!in_array($status, ['booked', 'completed', 'no_show'], true)) {
        echo json_encode(['success' => false, 'error' => 'Estado de asistencia no valido.']);
        exit;
    }
    if (($appointment['status'] ?? '') === 'cancelled') {
        echo json_encode(['success' => false, 'error' => 'No se puede modificar el estado de una cita cancelada.']);
        exit;
    }
    try {
        $stmt = $mysqli->prepare("UPDATE appointments SET status = ? WHERE tenant_id = ? AND id = ?");
        $stmt->bind_param("sii", $status, $tenant_id, $appointment_id);
        $stmt->execute();
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'No se pudo actualizar el estado de asistencia: ' . $e->getMessage()]);
        exit;
    }
    $labels = [
        'booked' => 'reservada',
        'completed' => 'realizada',
        'no_show' => 'no asistida'
    ];
    if (($appointment['status'] ?? '') !== $status) {
        app_log($mysqli, [
            'action' => 'appointment_attendance_updated',
            'status' => 'ok',
            'target_type' => 'appointment',
            'target_id' => $appointment_id,
            'title' => 'Estado de asistencia actualizado',
            'message' => 'Cita marcada como ' . ($labels[$status] ?? 'actualizada') . '.',
            'metadata' => [
                'patient_id' => (int) ($appointment['user_id'] ?? 0),
                'patient_name' => $appointment['patient_name'] ?? '',
                'professional_id' => (int) ($appointment['professional_id'] ?? 0),
                'appointment_date' => $appointment['appointment_date'] ?? '',
                'appointment_time' => $appointment['appointment_time'] ?? '',
                'previous_status' => $appointment['status'] ?? '',
                'new_status' => $status
            ]
        ]);
    }
    echo json_encode([
        'success' => true,
        'status' => $status,
        'message' => 'Cita marcada como ' . ($labels[$status] ?? 'actualizada') . '.'
    ]);
} elseif ($action === 'update_appointment_session_notes') {
    ensure_appointment_payment_columns($mysqli);
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    $session_notes = trim((string) ($_POST['session_notes'] ?? ''));
    [$can_manage, $appointment] = admin_can_manage_appointment_payment($mysqli, $appointment_id);
    if (!$can_manage || !$appointment) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para actualizar esta cita.']);
        exit;
    }
    try {
        $stmt = $mysqli->prepare("UPDATE appointments SET session_notes = ? WHERE tenant_id = ? AND id = ?");
        $stmt->bind_param("sii", $session_notes, $tenant_id, $appointment_id);
        $stmt->execute();
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'No se pudieron guardar las notas de la sesion: ' . $e->getMessage()]);
        exit;
    }
    echo json_encode([
        'success' => true,
        'session_notes' => $session_notes,
        'message' => 'Notas de la sesion guardadas.'
    ]);
} elseif ($action === 'update_appointment_online_details') {
    ensure_appointment_payment_columns($mysqli);
    ensure_appointment_services_tables($mysqli);
    ensure_appointment_locations_table($mysqli);
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    $consultation_type = $_POST['consultation_type'] ?? 'presencial';
    $online_session_url = trim($_POST['online_session_url'] ?? '');
    $location_id = (int) ($_POST['location_id'] ?? 0);
    [$can_manage, $appointment] = admin_can_manage_appointment_payment($mysqli, $appointment_id);
    if (!$can_manage || !$appointment) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para actualizar esta cita.']);
        exit;
    }
    if (($appointment['status'] ?? '') === 'cancelled') {
        echo json_encode(['success' => false, 'error' => 'No se puede modificar una cita cancelada.']);
        exit;
    }
    if (!in_array($consultation_type, ['presencial', 'online'], true)) {
        echo json_encode(['success' => false, 'error' => 'Modalidad no valida.']);
        exit;
    }
    $professional_settings = cabinet_get_effective_professional_settings($mysqli, (int) ($appointment['professional_id'] ?? 0));
    $professional_delivery_mode = $professional_settings['appointment_delivery_mode'] ?? 'both';
    $uses_livekit = $consultation_type === 'online' && livekit_enabled_for_professional($mysqli, (int) ($appointment['professional_id'] ?? 0));
    if ($consultation_type === 'online' && !in_array($professional_delivery_mode, ['both', 'online'], true)) {
        echo json_encode(['success' => false, 'error' => 'Este profesional no admite citas online.']);
        exit;
    }
    if ($consultation_type === 'presencial' && !in_array($professional_delivery_mode, ['both', 'presencial'], true)) {
        echo json_encode(['success' => false, 'error' => 'Este profesional no admite citas presenciales.']);
        exit;
    }
    if ($uses_livekit && !livekit_is_configured()) {
        echo json_encode(['success' => false, 'error' => 'LiveKit no está configurado en el servidor.']);
        exit;
    }
    if ($uses_livekit) {
        $online_session_url = '';
    } elseif ($consultation_type === 'online' && $online_session_url !== '' && !filter_var($online_session_url, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'error' => 'Indica un enlace valido para la videollamada.']);
        exit;
    }
    $location = null;
    if ($consultation_type === 'presencial') {
        $location_id = normalize_location_id($mysqli, $location_id);
        $location = fetch_appointment_location($mysqli, $location_id);
        $online_session_url = appointment_location_display_name($location);
    } else {
        $location_id = null;
    }

    $stmt = $mysqli->prepare("UPDATE appointments SET consultation_type = ?, location_id = ?, online_session_url = ? WHERE tenant_id = ? AND id = ?");
    $stmt->bind_param("sisii", $consultation_type, $location_id, $online_session_url, $tenant_id, $appointment_id);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'No se pudo guardar la modalidad de la cita.']);
        exit;
    }

    $livekit_link_ready = false;
    if ($uses_livekit) {
        $livekit_link_ready = livekit_ensure_appointment_link_token(
            $mysqli,
            $appointment_id,
            (string) ($appointment['livekit_access_token'] ?? '')
        ) !== '';
    }

    echo json_encode([
        'success' => true,
        'message' => $uses_livekit && $livekit_link_ready
            ? 'Modalidad de la cita actualizada. Enlace LiveKit preparado.'
            : 'Modalidad de la cita actualizada.',
        'consultation_type' => $consultation_type,
        'location_id' => $location_id ?: 0,
        'location_name' => $location['name'] ?? '',
        'location_type' => $location['location_type'] ?? '',
        'online_session_url' => $online_session_url,
        'livekit_enabled' => livekit_enabled_for_professional($mysqli, (int) ($appointment['professional_id'] ?? 0)) ? 1 : 0,
        'livekit_link_ready' => $livekit_link_ready ? 1 : 0
    ]);
} elseif ($action === 'send_appointment_online_link') {
    ensure_appointment_payment_columns($mysqli);
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    [$can_manage, $appointment] = admin_can_manage_appointment_payment($mysqli, $appointment_id);
    if (!$can_manage || !$appointment) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para enviar este enlace.']);
        exit;
    }
    if (($appointment['status'] ?? '') !== 'booked') {
        echo json_encode(['success' => false, 'error' => 'Solo se puede enviar el enlace de una cita reservada.']);
        exit;
    }
    if (($appointment['consultation_type'] ?? 'presencial') !== 'online') {
        echo json_encode(['success' => false, 'error' => 'La cita no esta marcada como online.']);
        exit;
    }
    $uses_livekit = livekit_appointment_enabled($mysqli, $appointment);
    if ($uses_livekit) {
        $appointment['livekit_access_token'] = livekit_ensure_appointment_link_token(
            $mysqli,
            $appointment_id,
            (string) ($appointment['livekit_access_token'] ?? '')
        );
    }
    $online_session_url = $uses_livekit ? livekit_patient_join_url($appointment) : trim($appointment['online_session_url'] ?? '');
    if ($online_session_url === '') {
        echo json_encode(['success' => false, 'error' => 'Guarda primero el enlace de videollamada.']);
        exit;
    }
    if (empty($appointment['patient_email']) || !filter_var($appointment['patient_email'], FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'El paciente no tiene un email valido.']);
        exit;
    }

    $appointment_text = appointment_label($appointment['appointment_date'], $appointment['appointment_time']);
    $service_text = appointment_service_option_label($appointment);
    $professional_line = !empty($appointment['professional_name'])
        ? '<p><b>Profesional:</b> ' . htmlspecialchars($appointment['professional_name']) . '</p>'
        : '';
    $sent = send_app_email(
        $appointment['patient_email'],
        'Enlace para tu cita online',
        '<p>Hola ' . htmlspecialchars($appointment['patient_name'] ?? 'Paciente') . ',</p>' .
        '<p>Te enviamos el enlace para tu cita online del ' . htmlspecialchars($appointment_text) . '.</p>' .
        $professional_line .
        '<p><b>Servicio:</b> ' . htmlspecialchars($service_text) . '</p>' .
        '<p><a href="' . htmlspecialchars($online_session_url) . '">Acceder a la videollamada</a></p>' .
        '<p>Si el boton no funciona, copia y pega este enlace en tu navegador:<br>' . htmlspecialchars($online_session_url) . '</p>',
        null,
        $mysqli
    );

    echo json_encode($sent
        ? ['success' => true, 'message' => 'Enlace enviado al paciente.']
        : ['success' => false, 'error' => 'No se pudo enviar el email al paciente.']);
} elseif ($action === 'send_manual_appointment_reminder') {
    ensure_appointment_payment_columns($mysqli);
    ensure_appointment_services_tables($mysqli);
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    $channel = strtolower(trim((string) ($_POST['channel'] ?? 'email')));
    if (!in_array($channel, ['email', 'sms'], true)) {
        echo json_encode(['success' => false, 'error' => 'Canal de recordatorio no valido.']);
        exit;
    }
    if ($channel === 'email' && !app_feature_enabled_from_db($mysqli, 'reminders.patient24h', false)) {
        echo json_encode(['success' => false, 'error' => 'Los recordatorios por email no estan disponibles en este plan.']);
        exit;
    }
    if ($channel === 'sms' && !app_feature_enabled_from_db($mysqli, 'reminders.sms', false)) {
        echo json_encode(['success' => false, 'error' => 'Los recordatorios por SMS no estan disponibles en este plan.']);
        exit;
    }
    [$can_manage, $appointment] = admin_can_manage_appointment_payment($mysqli, $appointment_id);
    if (!$can_manage || !$appointment) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para enviar este recordatorio.']);
        exit;
    }
    if (($appointment['status'] ?? '') !== 'booked') {
        $message = 'Solo se pueden enviar recordatorios de citas reservadas.';
        admin_log_manual_reminder($mysqli, $appointment, $channel, false, $message);
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }

    $settings = admin_payment_settings_for_reminder($mysqli);
    if ($channel === 'email') {
        if (empty($appointment['patient_email']) || !filter_var($appointment['patient_email'], FILTER_VALIDATE_EMAIL)) {
            $message = 'El paciente no tiene un email valido.';
            admin_log_manual_reminder($mysqli, $appointment, 'email', false, $message);
            echo json_encode(['success' => false, 'error' => $message]);
            exit;
        }
        $template = message_template_get($mysqli, 'appointment_email_reminder');
        $context = admin_appointment_reminder_context($mysqli, $appointment, $settings, false);
        $vars = $context['vars'];
        $body = admin_reminder_email_body_to_html(message_template_render($template['body'] ?? message_template_default_body('appointment_email_reminder'), $vars));

        $online_link_note = '';
        if (($appointment['consultation_type'] ?? '') === 'online' && !empty($context['online_link'])) {
            $online_link_note =
                '<p><b>Enlace de videollamada:</b><br>' .
                '<a href="' . htmlspecialchars($context['online_link']) . '">Acceder a la cita online</a></p>' .
                '<p>Si el boton no funciona, copia y pega este enlace en tu navegador:<br>' . htmlspecialchars($context['online_link']) . '</p>';
        }
        $payment_note = '';
        if ((int) ($settings['online_payment_enabled'] ?? 0) === 1 && ($appointment['payment_status'] ?? '') !== 'paid') {
            $payment_note = '<p>Si no has hecho aun el pago, puedes realizar el pago con tarjeta o Bizum desde el mismo enlace.</p>';
        }
        $body .=
            '<p><b>Servicio:</b> ' . htmlspecialchars($context['service_text']) . '</p>' .
            '<p><b>Modalidad:</b> ' . htmlspecialchars($context['consultation_text']) . '</p>' .
            $online_link_note .
            '<p><b>Importe:</b> ' . htmlspecialchars($context['price']) . ' &euro;</p>' .
            '<p><a href="' . htmlspecialchars($context['manage_link']) . '">Gestionar/cancelar reserva</a></p>' .
            $payment_note;
        $subject = message_template_render($template['subject'] ?: message_template_default_subject('appointment_email_reminder'), $vars);
        $sent = send_app_email($appointment['patient_email'], $subject, $body, null, $mysqli);
        $email_error = $sent ? '' : (function_exists('get_app_email_last_error') ? get_app_email_last_error() : '');
        $message = $sent
            ? 'Recordatorio enviado por email a ' . $appointment['patient_email'] . '.'
            : 'No se pudo enviar el recordatorio por email.' . ($email_error !== '' ? ' Motivo: ' . $email_error : '');
        admin_log_manual_reminder($mysqli, $appointment, 'email', $sent, $message, [
            'recipient' => $appointment['patient_email'] ?? '',
            'error' => $email_error
        ]);
        echo json_encode($sent
            ? ['success' => true, 'message' => 'Recordatorio enviado por email.']
            : ['success' => false, 'error' => 'No se pudo enviar el recordatorio por email.' . ($email_error !== '' ? ' ' . $email_error : '')]);
        exit;
    }

    if (empty($appointment['patient_phone'])) {
        $message = 'El paciente no tiene telefono guardado.';
        admin_log_manual_reminder($mysqli, $appointment, 'sms', false, $message);
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }
    $sms_settings = sms_get_settings($mysqli);
    $settings = array_merge($settings, $sms_settings);
    $template = message_template_get($mysqli, 'appointment_sms_reminder');
    $context = admin_appointment_reminder_context($mysqli, $appointment, $settings, true);
    $message_body = message_template_render($template['body'] ?? message_template_default_body('appointment_sms_reminder'), $context['vars']);
    if (strpos($message_body, $context['manage_link']) === false) {
        $message_body .= ' Gestionar/cancelar: ' . $context['manage_link'];
    }
    try {
        $sms_result = sms_send_with_settings($settings, $appointment['patient_phone'], $message_body, [
            'custom' => 'manual-reminder-' . $tenant_id . '-' . (int) $appointment['id']
        ]);
    } catch (Throwable $e) {
        $sms_result = ['success' => false, 'error' => $e->getMessage()];
    }
    $sent = !empty($sms_result['success']);
    $message = $sent
        ? 'Recordatorio enviado por SMS a ' . $appointment['patient_phone'] . '.'
        : ('No se pudo enviar el recordatorio por SMS: ' . ($sms_result['error'] ?? 'Error desconocido.'));
    admin_log_manual_reminder($mysqli, $appointment, 'sms', $sent, $message, [
        'recipient' => $appointment['patient_phone'] ?? '',
        'provider' => $sms_settings['sms_provider'] ?? '',
        'sms_id' => $sms_result['id'] ?? ''
    ]);
    echo json_encode($sent
        ? ['success' => true, 'message' => 'Recordatorio enviado por SMS.']
        : ['success' => false, 'error' => $sms_result['error'] ?? 'No se pudo enviar el recordatorio por SMS.']);
} elseif ($action === 'regenerate_appointment_livekit_link') {
    ensure_appointment_payment_columns($mysqli);
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    [$can_manage, $appointment] = admin_can_manage_appointment_payment($mysqli, $appointment_id);
    if (!$can_manage || !$appointment) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para regenerar este enlace.']);
        exit;
    }
    if (($appointment['status'] ?? '') !== 'booked' || !livekit_appointment_enabled($mysqli, $appointment)) {
        echo json_encode(['success' => false, 'error' => 'Esta cita no tiene una videollamada LiveKit activa.']);
        exit;
    }
    $token = livekit_ensure_appointment_link_token($mysqli, $appointment_id, '', true);
    if ($token === '') {
        echo json_encode(['success' => false, 'error' => 'No se pudo regenerar el enlace de videollamada.']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'message' => 'Enlace LiveKit regenerado. El enlace anterior ya no es válido.'
    ]);
} elseif ($action === 'download_patient_document') {
    $patient_id = (int) ($_GET['patient_id'] ?? 0);
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        http_response_code(403);
        echo 'No autorizado';
        exit;
    }
    $stmt = $mysqli->prepare("SELECT document_path, document_name FROM patient_profiles WHERE tenant_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc();
    $path = $document['document_path'] ?? '';
    $full_path = $path ? stored_upload_full_path($path) : '';
    if (!$document || !$path || !is_file($full_path)) {
        http_response_code(404);
        echo 'Archivo no encontrado';
        exit;
    }
    header_remove('Content-Type');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . addslashes($document['document_name'] ?: basename($full_path)) . '"');
    header('Content-Length: ' . filesize($full_path));
    readfile($full_path);
    exit;
} elseif ($action === 'download_evolution_file') {
    $file_id = (int) ($_GET['id'] ?? 0);
    $stmt = $mysqli->prepare("
        SELECT f.id, f.patient_id, f.original_name, f.file_path, f.mime_type, f.file_size
        FROM patient_evolution_files f
        WHERE f.tenant_id = ?
          AND f.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $file_id);
    $stmt->execute();
    $file = $stmt->get_result()->fetch_assoc();
    if (!$file || !admin_can_access_patient($mysqli, (int) $file['patient_id'])) {
        http_response_code(403);
        echo 'No autorizado';
        exit;
    }
    $full_path = stored_upload_full_path($file['file_path']);
    if (!is_file($full_path)) {
        http_response_code(404);
        echo 'Archivo no encontrado';
        exit;
    }
    header_remove('Content-Type');
    header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes($file['original_name'] ?: basename($full_path)) . '"');
    header('Content-Length: ' . filesize($full_path));
    readfile($full_path);
    exit;
} elseif ($action === 'delete_patient_evolution_note') {
    $note_id = (int) ($_POST['note_id'] ?? 0);
    $stmt = $mysqli->prepare("SELECT id, patient_id FROM patient_evolution_notes WHERE tenant_id = ? AND id = ? LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $note_id);
    $stmt->execute();
    $note = $stmt->get_result()->fetch_assoc();
    if (!$note || !admin_can_access_patient($mysqli, (int) $note['patient_id'])) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para eliminar esta nota.']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT id, file_path FROM patient_evolution_files WHERE tenant_id = ? AND evolution_note_id = ?");
    $stmt->bind_param("ii", $tenant_id, $note_id);
    $stmt->execute();
    $files_res = $stmt->get_result();
    $file_paths = [];
    while ($file = $files_res->fetch_assoc()) {
        $file_paths[] = $file['file_path'] ?? '';
    }

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("DELETE FROM patient_evolution_files WHERE tenant_id = ? AND evolution_note_id = ?");
        $stmt->bind_param("ii", $tenant_id, $note_id);
        $stmt->execute();

        $stmt = $mysqli->prepare("DELETE FROM patient_evolution_notes WHERE tenant_id = ? AND id = ?");
        $stmt->bind_param("ii", $tenant_id, $note_id);
        $stmt->execute();

        $mysqli->commit();
        foreach ($file_paths as $path) {
            $full_path = stored_upload_full_path($path);
            if ($full_path && is_file($full_path)) {
                @unlink($full_path);
            }
        }
        echo json_encode(['success' => true, 'message' => 'Nota eliminada correctamente.']);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => 'No se pudo eliminar la nota.']);
    }
} elseif ($action === 'patient_reports') {
    $patient_id = (int) ($_GET['patient_id'] ?? 0);
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para ver los informes.']);
        exit;
    }

    $stmt = $mysqli->prepare("
        SELECT r.id, r.patient_id, r.professional_id, r.report_key, r.source_type, r.source_document_id, r.title, r.status, r.visibility,
               r.payment_mode, r.payment_status, r.price, r.paid_at, r.generated_at, r.final_document_id, r.official_document_id,
               COALESCE(r.portal_available, 0) AS portal_available,
               r.created_at, r.updated_at,
               d.original_file_name AS final_document_name,
               d.file_size AS final_document_size,
               sd.original_file_name AS source_document_name,
               sd.file_size AS source_document_size
        FROM patient_reports r
        LEFT JOIN patient_documents d ON d.id = COALESCE(r.official_document_id, r.final_document_id) AND d.tenant_id = r.tenant_id AND d.patient_id = r.patient_id
        LEFT JOIN patient_documents sd ON sd.id = r.source_document_id AND sd.tenant_id = r.tenant_id AND sd.patient_id = r.patient_id
        WHERE r.tenant_id = ?
          AND r.patient_id = ?
        ORDER BY COALESCE(r.generated_at, r.created_at) DESC, r.id DESC
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $reports = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['patient_id'] = (int) $row['patient_id'];
        $row['professional_id'] = $row['professional_id'] !== null ? (int) $row['professional_id'] : null;
        $row['source_document_id'] = $row['source_document_id'] !== null ? (int) $row['source_document_id'] : null;
        $row['final_document_id'] = $row['final_document_id'] !== null ? (int) $row['final_document_id'] : null;
        $row['official_document_id'] = $row['official_document_id'] !== null ? (int) $row['official_document_id'] : null;
        $row['portal_available'] = (int) ($row['portal_available'] ?? 0);
        $row['price'] = $row['price'] !== null ? (float) $row['price'] : null;
        $row['url'] = patient_report_public_url($row);
        $download_document_id = !empty($row['official_document_id']) ? $row['official_document_id'] : $row['final_document_id'];
        $row['final_url'] = !empty($download_document_id) ? 'api/admin.php?action=download_patient_document_file&id=' . (int) $download_document_id : '';
        $row['source_url'] = !empty($row['source_document_id']) ? 'api/admin.php?action=download_patient_document_file&id=' . (int) $row['source_document_id'] : '';
        $reports[] = $row;
    }
    echo json_encode(['success' => true, 'reports' => $reports]);
} elseif ($action === 'create_patient_report') {
    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $report_key = trim((string) ($_POST['report_key'] ?? ''));
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para generar informes.']);
        exit;
    }

    $definition = patient_report_definition($report_key);
    if (!$definition) {
        echo json_encode(['success' => false, 'error' => 'Tipo de informe no reconocido.']);
        exit;
    }
    if (empty($definition['implemented'])) {
        echo json_encode(['success' => false, 'error' => 'Este informe todavia esta en preparacion.']);
        exit;
    }

    $professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
    if ($professional_id <= 0) {
        $professional_id = null;
    }
    $title = $definition['title'];
    if ($report_key === 'patient_summary' && function_exists('sector_texts_for_db')) {
        $sector_texts = sector_texts_for_db($mysqli);
        $patient_label = $sector_texts['labels']['patient']['singular'] ?? 'paciente';
        $title = 'Informe ' . $patient_label;
    }
    $status = 'generated';
    $visibility = $definition['visibility'];
    $source_type = 'app_generated';
    $payment_mode = $definition['payment_mode'];
    $payment_status = $definition['payment_status'];
    $price = null;
    $created_by = (int) ($_SESSION['user_id'] ?? 0);
    $snapshot = json_encode([
        'report_key' => $report_key,
        'generated_at' => date('c'),
        'generated_by' => $created_by
    ], JSON_UNESCAPED_UNICODE);

    $stmt = $mysqli->prepare("
        SELECT id
        FROM patient_reports
        WHERE tenant_id = ?
          AND patient_id = ?
          AND report_key = ?
        ORDER BY COALESCE(generated_at, created_at) DESC, id DESC
        LIMIT 1
    ");
    $stmt->bind_param("iis", $tenant_id, $patient_id, $report_key);
    $stmt->execute();
    $existing_report = $stmt->get_result()->fetch_assoc();

    try {
        if ($existing_report) {
            $report_id = (int) $existing_report['id'];
            $stmt = $mysqli->prepare("
                UPDATE patient_reports
                SET professional_id = ?,
                    source_type = ?,
                    title = ?,
                    status = CASE WHEN COALESCE(official_document_id, final_document_id) IS NULL THEN ? ELSE 'final_uploaded' END,
                    visibility = ?,
                    generated_at = NOW(),
                    source_snapshot_json = ?,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE tenant_id = ?
                  AND id = ?
            ");
            if (!$stmt) {
                throw new \Exception($mysqli->error ?: 'No se pudo preparar la actualizacion del informe.');
            }
            $stmt->bind_param(
                "isssssiii",
                $professional_id,
                $source_type,
                $title,
                $status,
                $visibility,
                $snapshot,
                $created_by,
                $tenant_id,
                $report_id
            );
        } else {
            $stmt = $mysqli->prepare("
                INSERT INTO patient_reports
                    (tenant_id, patient_id, professional_id, report_key, source_type, title, status, visibility,
                     payment_mode, payment_status, price, generated_at, source_snapshot_json, created_by, updated_by)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
            ");
            if (!$stmt) {
                throw new \Exception($mysqli->error ?: 'No se pudo preparar la consulta del informe.');
            }
            $stmt->bind_param(
                "iiisssssssdsii",
                $tenant_id,
                $patient_id,
                $professional_id,
                $report_key,
                $source_type,
                $title,
                $status,
                $visibility,
                $payment_mode,
                $payment_status,
                $price,
                $snapshot,
                $created_by,
                $created_by
            );
        }
        if (!$stmt->execute()) {
            throw new \Exception($stmt->error ?: 'No se pudo registrar el informe.');
        }
        if (!$existing_report) {
            $report_id = (int) $stmt->insert_id;
        }
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'No se pudo registrar el informe.']);
        exit;
    }
    $report = [
        'id' => $report_id,
        'patient_id' => $patient_id,
        'report_key' => $report_key
    ];
    echo json_encode([
        'success' => true,
        'message' => 'Informe generado correctamente.',
        'report_id' => $report_id,
        'url' => patient_report_public_url($report)
    ]);
} elseif ($action === 'create_custom_patient_report') {
    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para crear informes.']);
        exit;
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    if ($title === '') {
        echo json_encode(['success' => false, 'error' => 'Indica un titulo para el informe.']);
        exit;
    }

    $requested_payment_mode = trim((string) ($_POST['payment_mode'] ?? 'free'));
    $requested_payment_status = trim((string) ($_POST['payment_status'] ?? 'not_required'));
    if ($requested_payment_mode === 'paid' && $requested_payment_status === 'paid' && invoice_billing_enabled($mysqli) && !invoice_manual_confirmation_received()) {
        echo json_encode(['success' => false, 'error' => 'Confirma la emision de la factura para marcar este informe como pagado.']);
        exit;
    }

    $uploaded = save_patient_document_file_upload($_FILES['source_document'] ?? null, $patient_id);
    if (!$uploaded) {
        echo json_encode(['success' => false, 'error' => 'Sube el archivo del informe propio.']);
        exit;
    }

    $payment_mode = trim((string) ($_POST['payment_mode'] ?? 'free'));
    if (!in_array($payment_mode, ['free', 'included', 'paid'], true)) {
        $payment_mode = 'free';
    }
    $payment_status = trim((string) ($_POST['payment_status'] ?? 'not_required'));
    if (!in_array($payment_status, ['not_required', 'pending', 'paid'], true)) {
        $payment_status = $payment_mode === 'paid' ? 'pending' : 'not_required';
    }
    if ($payment_mode !== 'paid' && $payment_status === 'pending') {
        $payment_status = 'not_required';
    }
    if ($payment_mode === 'paid' && $payment_status === 'paid' && invoice_billing_enabled($mysqli) && !invoice_manual_confirmation_received()) {
        echo json_encode(['success' => false, 'error' => 'Confirma la emision de la factura para marcar este informe como pagado.']);
        exit;
    }
    $price_raw = str_replace(',', '.', trim((string) ($_POST['price'] ?? '')));
    $price = $payment_mode === 'paid' && $price_raw !== '' ? max(0, (float) $price_raw) : null;
    $portal_available = !empty($_POST['portal_available']) ? 1 : 0;
    $document_portal_visible = ($portal_available === 1 && !($payment_mode === 'paid' && $payment_status !== 'paid')) ? 1 : 0;
    $created_by = (int) ($_SESSION['user_id'] ?? 0);
    $professional_id = current_professional_id_for_user($mysqli, $created_by);
    if ($professional_id <= 0) {
        $professional_id = null;
    }
    $paid_at_sql = $payment_status === 'paid' ? 'NOW()' : 'NULL';

    $mysqli->begin_transaction();
    try {
        $document_type = 'file';
        $description = 'Plantilla de informe subida por el profesional.';
        $document_date = date('Y-m-d');
        $score = '';
        $result_label = '';
        $observations = '';
        $document_status = 'completed';
        $stmt = $mysqli->prepare("
            INSERT INTO patient_documents
                (tenant_id, patient_id, professional_id, document_type, title, description, document_date,
                 score, result_label, observations, file_path, original_file_name, file_size, mime_type,
                 visible_to_patient, result_visible_to_patient, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
        ");
        $stmt->bind_param(
            "iiissssssssisisii",
            $tenant_id,
            $patient_id,
            $professional_id,
            $document_type,
            $title,
            $description,
            $document_date,
            $score,
            $result_label,
            $observations,
            $uploaded['path'],
            $uploaded['name'],
            $uploaded['size'],
            $uploaded['mime'],
            $document_portal_visible,
            $document_status,
            $created_by
        );
        $stmt->execute();
        $source_document_id = (int) $stmt->insert_id;

        $report_key = 'custom_upload';
        $source_type = 'custom_upload';
        $status = 'generated';
        $visibility = 'internal';
        $snapshot = json_encode([
            'source' => 'custom_upload',
            'uploaded_at' => date('c'),
            'uploaded_by' => $created_by,
            'source_document_id' => $source_document_id
        ], JSON_UNESCAPED_UNICODE);
        $stmt = $mysqli->prepare("
            INSERT INTO patient_reports
                (tenant_id, patient_id, professional_id, report_key, source_type, source_document_id, title, status, visibility,
                 payment_mode, payment_status, price, paid_at, portal_available, generated_at, source_snapshot_json, created_by, updated_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, $paid_at_sql, ?, NOW(), ?, ?, ?)
        ");
        $stmt->bind_param(
            "iiississsssdiiii",
            $tenant_id,
            $patient_id,
            $professional_id,
            $report_key,
            $source_type,
            $source_document_id,
            $title,
            $status,
            $visibility,
            $payment_mode,
            $payment_status,
            $price,
            $portal_available,
            $snapshot,
            $created_by,
            $created_by
        );
        $stmt->execute();
        $report_id = (int) $stmt->insert_id;
        if ($payment_status === 'paid') {
            $invoice_result = invoice_emit_for_patient_report($mysqli, $report_id);
            if (empty($invoice_result['success'])) {
                throw new Exception($invoice_result['error'] ?? 'No se pudo emitir la factura del informe.');
            }
        }
        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => 'Plantilla de informe subida correctamente.', 'report_id' => $report_id]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => 'No se pudo subir la plantilla de informe.']);
    }
} elseif ($action === 'patient_report_suggestions') {
    $patient_id = (int) ($_GET['patient_id'] ?? 0);
    $knowledge_problem_id = (int) ($_GET['knowledge_problem_id'] ?? 0);
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para ver informes sugeridos.']);
        exit;
    }

    if ($knowledge_problem_id <= 0) {
        $stmt = $mysqli->prepare("
            SELECT knowledge_problem_id
            FROM patient_profiles
            WHERE tenant_id = ?
              AND user_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $tenant_id, $patient_id);
        $stmt->execute();
        $patient_profile = $stmt->get_result()->fetch_assoc();
        $knowledge_problem_id = (int) ($patient_profile['knowledge_problem_id'] ?? 0);
    }
    if ($knowledge_problem_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Selecciona un diagnóstico/objetivo para ver informes sugeridos.']);
        exit;
    }

    $stmt = $mysqli->prepare("
        SELECT MAX(r.id) AS id,
               r.report_key,
               r.source_type,
               r.source_document_id,
               r.title,
               r.payment_mode,
               r.payment_status,
               r.price,
               COUNT(DISTINCT r.patient_id) AS used_count,
               MAX(COALESCE(r.generated_at, r.created_at)) AS last_used_at,
               sd.original_file_name AS source_document_name
        FROM patient_reports r
        INNER JOIN patient_profiles pp
          ON pp.tenant_id = r.tenant_id
         AND pp.user_id = r.patient_id
         AND pp.knowledge_problem_id = ?
        LEFT JOIN patient_documents sd
          ON sd.id = r.source_document_id
         AND sd.tenant_id = r.tenant_id
         AND sd.patient_id = r.patient_id
        LEFT JOIN patient_reports existing
          ON existing.tenant_id = r.tenant_id
         AND existing.patient_id = ?
         AND (
              (r.report_key <> 'custom_upload' AND existing.report_key = r.report_key)
              OR
              (r.report_key = 'custom_upload' AND existing.report_key = 'custom_upload' AND LOWER(existing.title) = LOWER(r.title))
         )
        WHERE r.tenant_id = ?
          AND r.patient_id <> ?
          AND r.status <> 'cancelled'
          AND existing.id IS NULL
          AND (r.report_key <> 'custom_upload' OR r.source_document_id IS NOT NULL)
        GROUP BY r.report_key, r.source_type, r.source_document_id, r.title, r.payment_mode, r.payment_status, r.price, sd.original_file_name
        ORDER BY used_count DESC, last_used_at DESC
        LIMIT 25
    ");
    $stmt->bind_param("iiii", $knowledge_problem_id, $patient_id, $tenant_id, $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $suggestions = [];
    while ($row = $res->fetch_assoc()) {
        $suggestions[] = [
            'id' => (int) $row['id'],
            'report_key' => $row['report_key'] ?? '',
            'source_type' => $row['source_type'] ?? '',
            'source_document_id' => $row['source_document_id'] !== null ? (int) $row['source_document_id'] : null,
            'title' => $row['title'] ?? 'Informe',
            'payment_mode' => $row['payment_mode'] ?? 'free',
            'payment_status' => $row['payment_status'] ?? 'not_required',
            'price' => $row['price'] !== null ? (float) $row['price'] : null,
            'used_count' => (int) ($row['used_count'] ?? 0),
            'last_used_at' => $row['last_used_at'] ?? '',
            'source_document_name' => $row['source_document_name'] ?? ''
        ];
    }
    echo json_encode(['success' => true, 'suggestions' => $suggestions]);
} elseif ($action === 'add_suggested_patient_report') {
    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $source_report_id = (int) ($_POST['source_report_id'] ?? 0);
    $knowledge_problem_id = (int) ($_POST['knowledge_problem_id'] ?? 0);
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para anadir informes sugeridos.']);
        exit;
    }

    if ($knowledge_problem_id <= 0) {
        $stmt = $mysqli->prepare("
            SELECT knowledge_problem_id
            FROM patient_profiles
            WHERE tenant_id = ?
              AND user_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $tenant_id, $patient_id);
        $stmt->execute();
        $patient_profile = $stmt->get_result()->fetch_assoc();
        $knowledge_problem_id = (int) ($patient_profile['knowledge_problem_id'] ?? 0);
    }
    if ($knowledge_problem_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Selecciona un diagnóstico/objetivo para añadir el informe sugerido.']);
        exit;
    }

    $stmt = $mysqli->prepare("
        SELECT r.*, pp.knowledge_problem_id
        FROM patient_reports r
        INNER JOIN patient_profiles pp
          ON pp.tenant_id = r.tenant_id
         AND pp.user_id = r.patient_id
        WHERE r.tenant_id = ?
          AND r.id = ?
          AND r.patient_id <> ?
          AND pp.knowledge_problem_id = ?
          AND r.status <> 'cancelled'
        LIMIT 1
    ");
    $stmt->bind_param("iiii", $tenant_id, $source_report_id, $patient_id, $knowledge_problem_id);
    $stmt->execute();
    $source_report = $stmt->get_result()->fetch_assoc();
    if (!$source_report) {
        echo json_encode(['success' => false, 'error' => 'No se pudo localizar la plantilla sugerida.']);
        exit;
    }

    $report_key = (string) ($source_report['report_key'] ?? '');
    $source_type = (string) ($source_report['source_type'] ?? '');
    $title = trim((string) ($source_report['title'] ?? 'Informe'));
    if ($title === '') {
        $title = 'Informe';
    }
    $created_by = (int) ($_SESSION['user_id'] ?? 0);
    $professional_id = current_professional_id_for_user($mysqli, $created_by);
    if ($professional_id <= 0) {
        $professional_id = null;
    }

    if ($report_key === 'custom_upload') {
        $stmt = $mysqli->prepare("
            SELECT id
            FROM patient_reports
            WHERE tenant_id = ?
              AND patient_id = ?
              AND report_key = 'custom_upload'
              AND LOWER(title) = LOWER(?)
            LIMIT 1
        ");
        $stmt->bind_param("iis", $tenant_id, $patient_id, $title);
    } else {
        $stmt = $mysqli->prepare("
            SELECT id
            FROM patient_reports
            WHERE tenant_id = ?
              AND patient_id = ?
              AND report_key = ?
            LIMIT 1
        ");
        $stmt->bind_param("iis", $tenant_id, $patient_id, $report_key);
    }
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'error' => 'Este informe ya esta asignado al paciente.']);
        exit;
    }

    $mysqli->begin_transaction();
    try {
        $source_document_id = null;
        if ($report_key === 'custom_upload') {
            $original_document_id = (int) ($source_report['source_document_id'] ?? 0);
            if ($original_document_id <= 0) {
                throw new \Exception('La plantilla no tiene documento base.');
            }
            $stmt = $mysqli->prepare("
                SELECT document_type, title, description, document_date, score, result_label, observations,
                       file_path, original_file_name, file_size, mime_type, status, created_by
                FROM patient_documents
                WHERE tenant_id = ?
                  AND patient_id = ?
                  AND id = ?
                LIMIT 1
            ");
            $source_patient_id = (int) ($source_report['patient_id'] ?? 0);
            $stmt->bind_param("iii", $tenant_id, $source_patient_id, $original_document_id);
            $stmt->execute();
            $document = $stmt->get_result()->fetch_assoc();
            if (!$document) {
                throw new \Exception('No se encontro el documento base de la plantilla.');
            }
            $document_type = $document['document_type'] ?? 'file';
            $document_title = $document['title'] ?? $title;
            $description = $document['description'] ?? 'Plantilla de informe sugerida.';
            $document_date = date('Y-m-d');
            $score = '';
            $result_label = '';
            $observations = $document['observations'] ?? '';
            $visible_to_patient = 0;
            $result_visible_to_patient = 0;
            $status = $document['status'] ?? 'completed';
            $stmt = $mysqli->prepare("
                INSERT INTO patient_documents
                    (tenant_id, patient_id, professional_id, document_type, title, description, document_date,
                     score, result_label, observations, file_path, original_file_name, file_size, mime_type,
                     visible_to_patient, result_visible_to_patient, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "iiisssssssssisiisi",
                $tenant_id,
                $patient_id,
                $professional_id,
                $document_type,
                $document_title,
                $description,
                $document_date,
                $score,
                $result_label,
                $observations,
                $document['file_path'],
                $document['original_file_name'],
                $document['file_size'],
                $document['mime_type'],
                $visible_to_patient,
                $result_visible_to_patient,
                $status,
                $created_by
            );
            $stmt->execute();
            $source_document_id = (int) $stmt->insert_id;
        }

        $payment_mode = $source_report['payment_mode'] ?? 'free';
        if (!in_array($payment_mode, ['free', 'included', 'paid'], true)) {
            $payment_mode = 'free';
        }
        $payment_status = $payment_mode === 'paid' ? 'pending' : 'not_required';
        if (($source_report['payment_status'] ?? '') === 'paid' && $payment_mode === 'paid') {
            $payment_status = 'pending';
        }
        $price = $payment_mode === 'paid' && $source_report['price'] !== null ? max(0, (float) $source_report['price']) : null;
        $portal_available = 0;
        $status = $report_key === 'custom_upload' ? 'generated' : 'draft';
        $visibility = $source_report['visibility'] ?? 'internal';
        $snapshot = json_encode([
            'source' => 'suggested_report',
            'source_report_id' => $source_report_id,
            'source_patient_id' => (int) ($source_report['patient_id'] ?? 0),
            'copied_at' => date('c'),
            'copied_by' => $created_by
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $mysqli->prepare("
            INSERT INTO patient_reports
                (tenant_id, patient_id, professional_id, report_key, source_type, source_document_id, title, status, visibility,
                 payment_mode, payment_status, price, portal_available, generated_at, source_snapshot_json, created_by, updated_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
        ");
        $stmt->bind_param(
            "iiississsssdisii",
            $tenant_id,
            $patient_id,
            $professional_id,
            $report_key,
            $source_type,
            $source_document_id,
            $title,
            $status,
            $visibility,
            $payment_mode,
            $payment_status,
            $price,
            $portal_available,
            $snapshot,
            $created_by,
            $created_by
        );
        $stmt->execute();
        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => 'Informe sugerido anadido correctamente.']);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => 'No se pudo anadir el informe sugerido.']);
    }
} elseif ($action === 'update_patient_report') {
    $report_id = (int) ($_POST['report_id'] ?? 0);
    $stmt = $mysqli->prepare("
        SELECT id, patient_id, title, source_document_id, final_document_id, official_document_id,
               payment_mode, payment_status, price
        FROM patient_reports
        WHERE tenant_id = ? AND id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $report_id);
    $stmt->execute();
    $report_row = $stmt->get_result()->fetch_assoc();
    if (!$report_row || !admin_can_access_patient($mysqli, (int) $report_row['patient_id'])) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para modificar este informe.']);
        exit;
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    if ($title === '') {
        $title = (string) ($report_row['title'] ?: 'Informe');
    }
    $payment_mode = trim((string) ($_POST['payment_mode'] ?? 'free'));
    if (!in_array($payment_mode, ['free', 'included', 'paid'], true)) {
        $payment_mode = 'free';
    }
    $payment_status = trim((string) ($_POST['payment_status'] ?? 'not_required'));
    if (!in_array($payment_status, ['not_required', 'pending', 'paid'], true)) {
        $payment_status = $payment_mode === 'paid' ? 'pending' : 'not_required';
    }
    if ($payment_mode !== 'paid' && $payment_status === 'pending') {
        $payment_status = 'not_required';
    }
    $marking_report_as_paid = $payment_mode === 'paid'
        && $payment_status === 'paid'
        && ($report_row['payment_status'] ?? 'not_required') !== 'paid';
    if ($marking_report_as_paid && invoice_billing_enabled($mysqli) && !invoice_manual_confirmation_received()) {
        echo json_encode(['success' => false, 'error' => 'Confirma la emision de la factura para marcar este informe como pagado.']);
        exit;
    }
    $price_raw = str_replace(',', '.', trim((string) ($_POST['price'] ?? '')));
    $price = $payment_mode === 'paid' && $price_raw !== '' ? max(0, (float) $price_raw) : null;
    $existing_invoice = invoice_existing_for_origin($mysqli, 'patient_report', $report_id);
    if ($existing_invoice) {
        $current_price = $report_row['price'] === null ? null : round((float) $report_row['price'], 2);
        $new_price = $price === null ? null : round((float) $price, 2);
        if (
            $title !== (string) ($report_row['title'] ?: 'Informe')
            || $payment_mode !== ($report_row['payment_mode'] ?? 'free')
            || $payment_status !== ($report_row['payment_status'] ?? 'not_required')
            || $new_price !== $current_price
        ) {
            echo json_encode(['success' => false, 'error' => 'Este informe ya tiene factura emitida y no se pueden modificar sus datos economicos.']);
            exit;
        }
    }
    $portal_available = !empty($_POST['portal_available']) ? 1 : 0;
    $document_portal_visible = ($portal_available === 1 && !($payment_mode === 'paid' && $payment_status !== 'paid')) ? 1 : 0;
    $updated_by = (int) ($_SESSION['user_id'] ?? 0);
    $paid_at_sql = $payment_status === 'paid' ? 'COALESCE(paid_at, NOW())' : 'NULL';

    $mysqli->begin_transaction();
    try {
        $final_document_id = null;
        $uploaded = save_patient_document_file_upload($_FILES['final_document'] ?? null, (int) $report_row['patient_id']);
        if ($uploaded) {
            $document_type = 'file';
            $document_title = 'Informe final - ' . ($report_row['title'] ?: 'Informe');
            $description = 'Versión final/oficial asociada al informe.';
            $document_date = date('Y-m-d');
            $score = '';
            $result_label = '';
            $observations = '';
            $status = 'completed';
            $stmt = $mysqli->prepare("
                INSERT INTO patient_documents
                    (tenant_id, patient_id, professional_id, document_type, title, description, document_date,
                     score, result_label, observations, file_path, original_file_name, file_size, mime_type,
                     visible_to_patient, result_visible_to_patient, status, created_by)
                VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
            ");
            $stmt->bind_param(
                "iisssssssssisisi",
                $tenant_id,
                $report_row['patient_id'],
                $document_type,
                $document_title,
                $description,
                $document_date,
                $score,
                $result_label,
                $observations,
                $uploaded['path'],
                $uploaded['name'],
                $uploaded['size'],
                $uploaded['mime'],
                $document_portal_visible,
                $status,
                $updated_by
            );
            $stmt->execute();
            $final_document_id = (int) $stmt->insert_id;
        }

        if ($final_document_id) {
            $stmt = $mysqli->prepare("
                UPDATE patient_reports
                SET title = ?, payment_mode = ?, payment_status = ?, price = ?, paid_at = $paid_at_sql,
                    portal_available = ?, final_document_id = ?, official_document_id = ?, status = 'final_uploaded',
                    updated_by = ?, updated_at = NOW()
                WHERE tenant_id = ? AND id = ?
            ");
            $stmt->bind_param("sssdiiiiii", $title, $payment_mode, $payment_status, $price, $portal_available, $final_document_id, $final_document_id, $updated_by, $tenant_id, $report_id);
        } else {
            $stmt = $mysqli->prepare("
                UPDATE patient_reports
                SET title = ?, payment_mode = ?, payment_status = ?, price = ?, paid_at = $paid_at_sql,
                    portal_available = ?, updated_by = ?, updated_at = NOW()
                WHERE tenant_id = ? AND id = ?
            ");
            $stmt->bind_param("sssdiiii", $title, $payment_mode, $payment_status, $price, $portal_available, $updated_by, $tenant_id, $report_id);
        }
        $stmt->execute();
        $document_ids_to_sync = array_values(array_filter(array_unique([
            (int) ($final_document_id ?: 0),
            (int) ($report_row['source_document_id'] ?? 0),
            (int) ($report_row['final_document_id'] ?? 0),
            (int) ($report_row['official_document_id'] ?? 0)
        ])));
        if ($document_ids_to_sync) {
            $stmt = $mysqli->prepare("
                UPDATE patient_documents
                SET visible_to_patient = ?
                WHERE tenant_id = ?
                  AND patient_id = ?
                  AND id = ?
            ");
            foreach ($document_ids_to_sync as $document_id_to_sync) {
                $stmt->bind_param("iiii", $document_portal_visible, $tenant_id, $report_row['patient_id'], $document_id_to_sync);
                $stmt->execute();
            }
        }
        if ($payment_status === 'paid') {
            $invoice_result = invoice_emit_for_patient_report($mysqli, $report_id);
            if (empty($invoice_result['success'])) {
                throw new Exception($invoice_result['error'] ?? 'No se pudo emitir la factura del informe.');
            }
        }
        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => 'Informe actualizado correctamente.']);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'patient_report') {
    $patient_id = (int) ($_GET['patient_id'] ?? 0);
    $report_id = (int) ($_GET['report_id'] ?? 0);
    $report_type = (string) ($_GET['type'] ?? 'internal');
    $report_sector_key = function_exists('current_knowledge_sector_key') ? current_knowledge_sector_key($mysqli) : 'psicologia';
    $support_network_label = in_array($report_sector_key, ['psicologia', 'sexologia', 'psicopedagogia'], true)
        ? 'Red de apoyo y contexto vital'
        : 'Situación familiar, laboral, etc.';
    if (!in_array($report_type, ['internal', 'patient', 'clinical', 'evolution'], true)) {
        $report_type = 'internal';
    }
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        http_response_code(403);
        header_remove('Content-Type');
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'No autorizado';
        exit;
    }
    if ($report_id > 0) {
        $stmt = $mysqli->prepare("SELECT id FROM patient_reports WHERE tenant_id = ? AND id = ? AND patient_id = ? LIMIT 1");
        $stmt->bind_param("iii", $tenant_id, $report_id, $patient_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            http_response_code(404);
            header_remove('Content-Type');
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Informe no encontrado';
            exit;
        }
    }

    $stmt = $mysqli->prepare("
        SELECT u.id, u.name, u.email, u.phone, u.created_at,
               pp.patient_type, pp.patient_status, pp.birth_date, pp.referral_source, pp.knowledge_problem_id, pp.initial_consultation_reason,
               pp.background_notes, pp.support_network_notes,
               pp.emergency_contact_name, pp.emergency_contact_phone, pp.emergency_contact_relation,
               pp.admission_date, pp.notes, pp.document_name,
               p.display_name AS professional_name, p.professional_title, p.license_number
        FROM users u
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id AND pp.tenant_id = u.tenant_id
        LEFT JOIN professionals p ON p.id = COALESCE(pp.professional_id, (
            SELECT professional_id
            FROM patient_professionals
            WHERE tenant_id = u.tenant_id AND patient_id = u.id AND is_primary = 1
            ORDER BY assigned_at DESC, id DESC
            LIMIT 1
        )) AND p.tenant_id = u.tenant_id
        WHERE u.tenant_id = ?
          AND u.id = ? AND u.role = 'patient'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    if (!$patient) {
        http_response_code(404);
        header_remove('Content-Type');
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Paciente no encontrado';
        exit;
    }

    $knowledge_problem = null;
    $knowledge_recommendations = [];
    if (!empty($patient['knowledge_problem_id']) && app_feature_enabled_from_db($mysqli, 'knowledgeBase.enabled', false)) {
        $sector_sql = knowledge_sector_in_sql($mysqli, allowed_knowledge_sector_keys($mysqli));
        $stmt = $mysqli->prepare("
            SELECT kp.id, kp.sector_key, kp.name, kp.alias, kp.description, kp.population, kp.risk_level,
                   ka.name AS area_name
            FROM knowledge_problems kp
            LEFT JOIN knowledge_areas ka ON ka.id = kp.area_id AND ka.sector_key = kp.sector_key
            WHERE kp.id = ? AND kp.sector_key IN ($sector_sql)
            LIMIT 1
        ");
        $knowledge_problem_id = (int) $patient['knowledge_problem_id'];
        $stmt->bind_param("i", $knowledge_problem_id);
        $stmt->execute();
        $knowledge_problem = $stmt->get_result()->fetch_assoc();
        if ($knowledge_problem) {
            $sector_key = (string) ($knowledge_problem['sector_key'] ?? current_knowledge_sector_key($mysqli));
            $stmt = $mysqli->prepare("
                SELECT te.name AS technique_name, te.description AS technique_description,
                       t.title AS task_title, t.description AS task_description, t.objective, t.estimated_duration AS duration, t.risk_level AS task_risk_level,
                       r.priority, r.clinical_note
                FROM knowledge_recommendations r
                INNER JOIN knowledge_techniques te ON te.id = r.technique_id AND te.sector_key = r.sector_key
                INNER JOIN knowledge_tasks t ON t.id = r.task_id AND t.sector_key = r.sector_key
                WHERE r.problem_id = ? AND r.sector_key = ?
                ORDER BY te.name ASC, FIELD(r.priority, 'alta', 'media', 'baja') ASC, t.title ASC
                LIMIT 160
            ");
            $stmt->bind_param("is", $knowledge_problem_id, $sector_key);
            $stmt->execute();
            $recommendations_res = $stmt->get_result();
            while ($row = $recommendations_res->fetch_assoc()) {
                $knowledge_recommendations[] = $row;
            }
        }
    }

    $report_session_notes_select = payment_column_exists($mysqli, 'appointments', 'session_notes')
        ? 'a.session_notes'
        : 'NULL AS session_notes';
    $stmt = $mysqli->prepare("
        SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.cancelled_at,
               a.consultation_type, a.service_type, a.online_session_url,
               $report_session_notes_select,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.paid_at,
               s.name AS service_name,
               p.display_name AS professional_name
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        LEFT JOIN professionals p ON p.id = a.professional_id AND p.tenant_id = a.tenant_id
        WHERE a.tenant_id = ?
          AND a.user_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC, a.id DESC
        LIMIT 120
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $appointments_res = $stmt->get_result();
    $appointments = [];
    while ($row = $appointments_res->fetch_assoc()) {
        $appointments[] = $row;
    }

    $stmt = $mysqli->prepare("
        SELECT t.title, t.description, t.status, t.priority, t.completed_at, t.created_at,
               p.display_name AS professional_name
        FROM patient_work_plan_tasks t
        LEFT JOIN professionals p ON p.id = t.professional_id AND p.tenant_id = t.tenant_id
        WHERE t.tenant_id = ?
          AND t.patient_id = ?
        ORDER BY CASE WHEN t.status = 'pending' THEN 0 ELSE 1 END, t.priority ASC, t.updated_at DESC
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $tasks_res = $stmt->get_result();
    $tasks = [];
    while ($row = $tasks_res->fetch_assoc()) {
        $tasks[] = $row;
    }

    $stmt = $mysqli->prepare("
        SELECT n.id, n.note_date, n.title, n.description, n.observations, n.next_steps, n.created_at, n.updated_at,
               a.appointment_date, a.appointment_time,
               p.display_name AS professional_name
        FROM patient_evolution_notes n
        LEFT JOIN appointments a ON a.id = n.appointment_id AND a.tenant_id = n.tenant_id
        LEFT JOIN professionals p ON p.id = n.professional_id AND p.tenant_id = n.tenant_id
        WHERE n.tenant_id = ?
          AND n.patient_id = ?
        ORDER BY n.note_date DESC, n.id DESC
        LIMIT 120
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $evolution_res = $stmt->get_result();
    $evolution_notes = [];
    while ($row = $evolution_res->fetch_assoc()) {
        $evolution_notes[] = $row;
    }

    $evolution_files_by_note = [];
    $stmt = $mysqli->prepare("
        SELECT evolution_note_id, original_name, uploaded_at
        FROM patient_evolution_files
        WHERE tenant_id = ?
          AND patient_id = ?
        ORDER BY uploaded_at DESC, id DESC
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $files_res = $stmt->get_result();
    while ($file = $files_res->fetch_assoc()) {
        $note_id = (int) $file['evolution_note_id'];
        if (!isset($evolution_files_by_note[$note_id])) {
            $evolution_files_by_note[$note_id] = [];
        }
        $evolution_files_by_note[$note_id][] = $file;
    }

    $patient_documents = [];
    $stmt = $mysqli->prepare("
        SELECT document_type, title, description, document_date, score, result_label, observations,
               original_file_name, status, updated_at
        FROM patient_documents
        WHERE tenant_id = ?
          AND patient_id = ?
        ORDER BY COALESCE(document_date, DATE(updated_at), DATE(created_at)) DESC, updated_at DESC, id DESC
        LIMIT 120
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $documents_res = $stmt->get_result();
    while ($row = $documents_res->fetch_assoc()) {
        $patient_documents[] = $row;
    }

    $stmt = $mysqli->prepare("
        SELECT pb.total_sessions, pb.remaining_sessions, pb.status, pb.purchased_at, pb.expires_at,
               b.name
        FROM patient_bonuses pb
        JOIN appointment_bonuses b ON b.id = pb.bonus_id AND b.tenant_id = pb.tenant_id
        WHERE pb.tenant_id = ?
          AND pb.user_id = ?
        ORDER BY pb.purchased_at DESC, pb.id DESC
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $bonuses_res = $stmt->get_result();
    $bonuses = [];
    while ($row = $bonuses_res->fetch_assoc()) {
        $bonuses[] = $row;
    }

    $status_labels = [
        'booked' => 'Reservada',
        'cancelled' => 'Cancelada',
        'completed' => 'Realizada',
        'no_show' => 'No asistió'
    ];
    $task_priority_labels = [1 => 'Alta', 2 => 'Normal', 3 => 'Baja'];
    $app_name = get_app_name($mysqli);
    $completed_tasks = array_values(array_filter($tasks, fn($task) => ($task['status'] ?? '') === 'completed'));
    $pending_tasks = array_values(array_filter($tasks, fn($task) => ($task['status'] ?? '') !== 'completed'));
    $completed_appointments_count = count(array_filter($appointments, function ($appointment) {
        if (($appointment['status'] ?? '') === 'cancelled') {
            return false;
        }
        $date_time = trim(($appointment['appointment_date'] ?? '') . ' ' . ($appointment['appointment_time'] ?? ''));
        return $date_time !== '' && strtotime($date_time) <= time();
    }));
    $upcoming_appointments_count = count(array_filter($appointments, function ($appointment) {
        if (($appointment['status'] ?? '') !== 'booked') {
            return false;
        }
        $date_time = trim(($appointment['appointment_date'] ?? '') . ' ' . ($appointment['appointment_time'] ?? ''));
        return $date_time !== '' && strtotime($date_time) > time();
    }));

    if ($report_type === 'patient') {
        header_remove('Content-Type');
        header('Content-Type: text/html; charset=UTF-8');
        ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Informe para paciente - <?= report_h($patient['name']) ?></title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; font-family: Arial, sans-serif; color: #1f2933; background: #f5f7fb; }
        main { max-width: 860px; margin: 0 auto; padding: 28px 18px 48px; }
        .report-toolbar { display: flex; justify-content: flex-end; gap: 8px; margin-bottom: 16px; }
        button { border: 1px solid #6f5fa8; background: #6f5fa8; color: #fff; border-radius: 6px; padding: 8px 12px; cursor: pointer; }
        .report-sheet { background: #fff; border: 1px solid #dfe5ef; border-radius: 8px; padding: 28px; }
        h1 { margin: 0 0 6px; font-size: 26px; }
        h2 { margin: 28px 0 12px; font-size: 18px; border-bottom: 1px solid #dfe5ef; padding-bottom: 8px; }
        .meta { color: #6b7280; font-size: 13px; }
        .grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-top: 18px; }
        .metric { border: 1px solid #e5e9f0; border-radius: 8px; padding: 12px; }
        .metric span { display: block; color: #6b7280; font-size: 12px; }
        .metric strong { display: block; font-size: 22px; margin-top: 4px; }
        .task { border: 1px solid #e5e9f0; border-radius: 6px; padding: 12px; margin-bottom: 10px; }
        .task strong { display: block; }
        .preline { white-space: pre-wrap; }
        .empty { color: #6b7280; font-style: italic; }
        @media (max-width: 760px) { .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media print {
            body { background: #fff; }
            main { max-width: none; padding: 0; }
            .report-toolbar { display: none; }
            .report-sheet { border: 0; border-radius: 0; padding: 0; }
            .task { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<main>
    <div class="report-toolbar">
        <button type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
    </div>
    <article class="report-sheet">
        <h1>Informe para paciente</h1>
        <div class="meta"><?= report_h($app_name) ?> · Generado el <?= report_datetime(date('Y-m-d H:i:s')) ?></div>
        <div class="meta">Paciente: <?= report_h($patient['name']) ?><?= $patient['professional_name'] ? ' · Profesional: ' . report_h($patient['professional_name']) : '' ?></div>

        <section>
            <h2>Resumen</h2>
            <div class="grid">
                <div class="metric"><span>Fecha de alta</span><strong><?= report_date($patient['admission_date']) ?></strong></div>
                <div class="metric"><span>Citas realizadas</span><strong><?= (int) $completed_appointments_count ?></strong></div>
                <div class="metric"><span>Próximas citas</span><strong><?= (int) $upcoming_appointments_count ?></strong></div>
                <div class="metric"><span>Tareas completadas</span><strong><?= count($completed_tasks) ?></strong></div>
            </div>
        </section>

        <section>
            <h2>Tareas completadas</h2>
            <?php if ($completed_tasks): ?>
                <?php foreach ($completed_tasks as $task): ?>
                    <div class="task">
                        <strong><?= report_h($task['title']) ?></strong>
                        <div class="meta">Completada: <?= report_datetime($task['completed_at']) ?></div>
                        <?php if (!empty($task['description'])): ?><div class="preline"><?= nl2br(report_h($task['description'])) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="empty">No hay tareas completadas registradas.</p>
            <?php endif; ?>
        </section>

        <section>
            <h2>Tareas pendientes</h2>
            <?php if ($pending_tasks): ?>
                <?php foreach ($pending_tasks as $task): ?>
                    <div class="task">
                        <strong><?= report_h($task['title']) ?></strong>
                        <?php if (!empty($task['description'])): ?><div class="preline"><?= nl2br(report_h($task['description'])) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="empty">No hay tareas pendientes registradas.</p>
            <?php endif; ?>
        </section>
    </article>
</main>
</body>
</html>
        <?php
        exit;
    }

    $report_heading = [
        'clinical' => 'Informe clínico',
        'evolution' => 'Informe de evolución',
        'internal' => 'Informe interno de paciente'
    ][$report_type] ?? 'Informe interno de paciente';

    header_remove('Content-Type');
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= report_h($report_heading) ?> - <?= report_h($patient['name']) ?></title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; font-family: Arial, sans-serif; color: #1f2933; background: #f5f7fb; }
        main { max-width: 980px; margin: 0 auto; padding: 28px 18px 48px; }
        .report-toolbar { display: flex; justify-content: flex-end; gap: 8px; margin-bottom: 16px; }
        button { border: 1px solid #6f5fa8; background: #6f5fa8; color: #fff; border-radius: 6px; padding: 8px 12px; cursor: pointer; }
        .report-sheet { background: #fff; border: 1px solid #dfe5ef; border-radius: 8px; padding: 28px; }
        h1 { margin: 0 0 6px; font-size: 26px; }
        h2 { margin: 28px 0 12px; font-size: 18px; border-bottom: 1px solid #dfe5ef; padding-bottom: 8px; }
        h3 { margin: 18px 0 6px; font-size: 15px; }
        h4 { margin: 14px 0 6px; font-size: 14px; }
        .meta { color: #6b7280; font-size: 13px; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 18px; margin-top: 18px; }
        .field span { display: block; color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
        .field strong { display: block; margin-top: 3px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 13px; }
        th, td { border-bottom: 1px solid #e5e9f0; text-align: left; vertical-align: top; padding: 8px; }
        th { background: #f8fafc; color: #526071; }
        .note, .task, .summary-box { border: 1px solid #e5e9f0; border-radius: 6px; padding: 12px; margin-bottom: 10px; }
        .summary-box { background: #f8fafc; }
        .tag { display: inline-block; border-radius: 999px; background: #eef2ff; color: #4f46e5; font-size: 12px; font-weight: 700; padding: 3px 8px; margin: 0 5px 5px 0; }
        .section-note { color: #6b7280; font-size: 13px; margin-top: 4px; }
        .preline { white-space: pre-wrap; }
        .empty { color: #6b7280; font-style: italic; }
        @media print {
            body { background: #fff; }
            main { max-width: none; padding: 0; }
            .report-toolbar { display: none; }
            .report-sheet { border: 0; border-radius: 0; padding: 0; }
            h2 { page-break-after: avoid; }
            .note, .task, tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<main>
    <div class="report-toolbar">
        <button type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
    </div>
    <article class="report-sheet">
        <h1><?= report_h($report_heading) ?></h1>
        <div class="meta"><?= report_h($app_name) ?> · Generado el <?= report_datetime(date('Y-m-d H:i:s')) ?></div>

        <section>
            <h2>Datos del paciente</h2>
            <div class="grid">
                <div class="field"><span>Nombre</span><strong><?= report_h($patient['name']) ?></strong></div>
                <div class="field"><span>Tipo</span><strong><?= report_h($patient['patient_type'] ?: '-') ?></strong></div>
                <div class="field"><span>Estado</span><strong><?= report_h(patient_status_label($patient['patient_status'] ?? 'active')) ?></strong></div>
                <div class="field"><span>Fecha de nacimiento</span><strong><?= report_date($patient['birth_date']) ?><?= !empty($patient['birth_date']) ? ' · ' . report_age($patient['birth_date']) . ' años' : '' ?></strong></div>
                <div class="field"><span>Email</span><strong><?= report_h($patient['email'] ?: '-') ?></strong></div>
                <div class="field"><span>Teléfono</span><strong><?= report_h($patient['phone'] ?: '-') ?></strong></div>
                <div class="field"><span>Fecha de alta</span><strong><?= report_date($patient['admission_date']) ?></strong></div>
                <div class="field"><span>Profesional</span><strong><?= report_h($patient['professional_name'] ?: '-') ?></strong></div>
                <div class="field"><span>Fuente / derivación</span><strong><?= report_h($patient['referral_source'] ?: '-') ?></strong></div>
                <div class="field"><span>Contacto de emergencia</span><strong><?= report_h(trim(($patient['emergency_contact_name'] ?? '') . ' ' . ($patient['emergency_contact_phone'] ?? '')) ?: '-') ?></strong></div>
                <div class="field"><span>Documento adjunto</span><strong><?= report_h($patient['document_name'] ?: '-') ?></strong></div>
                <div class="field"><span>Creado</span><strong><?= report_datetime($patient['created_at']) ?></strong></div>
            </div>
            <?php if (!empty($patient['initial_consultation_reason'])): ?>
                <h3>Motivo inicial de consulta</h3>
                <div class="preline"><?= nl2br(report_h($patient['initial_consultation_reason'])) ?></div>
            <?php endif; ?>
            <?php if (!empty($patient['background_notes'])): ?>
                <h3>Antecedentes</h3>
                <div class="preline"><?= nl2br(report_h($patient['background_notes'])) ?></div>
            <?php endif; ?>
            <?php if (!empty($patient['support_network_notes'])): ?>
                <h3><?= report_h($support_network_label) ?></h3>
                <div class="preline"><?= nl2br(report_h($patient['support_network_notes'])) ?></div>
            <?php endif; ?>
            <?php if ($report_type === 'internal' && !empty($patient['notes'])): ?>
                <h3>Notas internas</h3>
                <div class="preline"><?= nl2br(report_h($patient['notes'])) ?></div>
            <?php endif; ?>
            <?php if ($report_type === 'clinical'): ?>
                <h3>Finalidad del borrador</h3>
                <div class="preline">Este documento reune la informacion registrada en la aplicacion para servir como base de trabajo al profesional. La interpretacion clinica, conclusiones, recomendaciones y firma deben ser revisadas y completadas por el terapeuta antes de emitir cualquier version oficial.</div>
            <?php endif; ?>
        </section>

        <?php if ($report_type === 'clinical' || $knowledge_problem || $knowledge_recommendations): ?>
        <section>
            <h2>Diagnostico / problema asociado</h2>
            <?php if ($knowledge_problem): ?>
                <div class="summary-box">
                    <?php if (!empty($knowledge_problem['area_name'])): ?><span class="tag"><?= report_h($knowledge_problem['area_name']) ?></span><?php endif; ?>
                    <?php if (!empty($knowledge_problem['risk_level'])): ?><span class="tag">Riesgo: <?= report_h($knowledge_problem['risk_level']) ?></span><?php endif; ?>
                    <?php if (!empty($knowledge_problem['population'])): ?><span class="tag"><?= report_h($knowledge_problem['population']) ?></span><?php endif; ?>
                    <h3><?= report_h($knowledge_problem['name'] ?? '-') ?></h3>
                    <?php if (!empty($knowledge_problem['alias'])): ?><div class="meta"><?= report_h($knowledge_problem['alias']) ?></div><?php endif; ?>
                    <?php if (!empty($knowledge_problem['description'])): ?><div class="preline"><?= nl2br(report_h($knowledge_problem['description'])) ?></div><?php endif; ?>
                </div>
            <?php else: ?>
                <p class="empty">No hay diagnostico o problema asociado en la ficha.</p>
            <?php endif; ?>

            <?php if ($knowledge_recommendations): ?>
                <h3>Tecnicas, tareas y pautas recomendadas</h3>
                <div class="section-note">Contenido obtenido de la base de conocimiento asociada al problema seleccionado.</div>
                <?php $last_technique = null; ?>
                <?php foreach ($knowledge_recommendations as $recommendation): ?>
                    <?php if ($last_technique !== ($recommendation['technique_name'] ?? '')): ?>
                        <?php $last_technique = $recommendation['technique_name'] ?? ''; ?>
                        <h4><?= report_h($last_technique ?: 'Tecnica sin nombre') ?></h4>
                        <?php if (!empty($recommendation['technique_description'])): ?><div class="preline"><?= nl2br(report_h($recommendation['technique_description'])) ?></div><?php endif; ?>
                    <?php endif; ?>
                    <div class="task">
                        <strong><?= report_h($recommendation['task_title'] ?? '-') ?></strong>
                        <div class="meta">
                            <?= !empty($recommendation['priority']) ? 'Prioridad: ' . report_h($recommendation['priority']) : '' ?>
                            <?= !empty($recommendation['task_risk_level']) ? ' · Riesgo: ' . report_h($recommendation['task_risk_level']) : '' ?>
                            <?= !empty($recommendation['duration']) ? ' · Duracion: ' . report_h($recommendation['duration']) : '' ?>
                        </div>
                        <?php if (!empty($recommendation['objective'])): ?><div class="meta">Objetivo: <?= report_h($recommendation['objective']) ?></div><?php endif; ?>
                        <?php if (!empty($recommendation['task_description'])): ?><div class="preline"><?= nl2br(report_h($recommendation['task_description'])) ?></div><?php endif; ?>
                        <?php if (!empty($recommendation['clinical_note'])): ?><div class="section-note"><?= nl2br(report_h($recommendation['clinical_note'])) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($report_type === 'clinical'): ?>
        <section>
            <h2>Cuestionarios y documentacion</h2>
            <?php if ($patient_documents): ?>
                <table>
                    <thead><tr><th>Fecha</th><th>Tipo</th><th>Documento</th><th>Resultado / estado</th><th>Observaciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($patient_documents as $document): ?>
                        <tr>
                            <td><?= report_date($document['document_date'] ?: $document['updated_at']) ?></td>
                            <td><?= ($document['document_type'] ?? '') === 'questionnaire' ? 'Cuestionario' : 'Archivo' ?></td>
                            <td>
                                <strong><?= report_h($document['title'] ?: ($document['original_file_name'] ?: '-')) ?></strong>
                                <?php if (!empty($document['description'])): ?><br><span class="meta"><?= report_h($document['description']) ?></span><?php endif; ?>
                            </td>
                            <td>
                                <?= report_h($document['score'] ?: '-') ?>
                                <?= !empty($document['result_label']) ? '<br><span class="meta">' . report_h($document['result_label']) . '</span>' : '' ?>
                                <?= !empty($document['status']) ? '<br><span class="meta">Estado: ' . report_h($document['status']) . '</span>' : '' ?>
                            </td>
                            <td><?= !empty($document['observations']) ? nl2br(report_h($document['observations'])) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty">No hay cuestionarios ni documentos registrados.</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section>
            <h2>Historial de citas</h2>
            <?php if ($appointments): ?>
                <table>
                    <thead><tr><th>Fecha</th><th>Estado</th><th>Profesional</th><th>Servicio</th><th>Modalidad</th><th>Pago</th></tr></thead>
                    <tbody>
                    <?php foreach ($appointments as $appointment): ?>
                        <tr>
                            <td><?= report_date($appointment['appointment_date']) ?> <?= report_h(substr((string) $appointment['appointment_time'], 0, 5)) ?></td>
                            <td><?= report_h($status_labels[$appointment['status']] ?? $appointment['status']) ?><?= $appointment['cancelled_at'] ? '<br><span class="meta">Cancelada: ' . report_datetime($appointment['cancelled_at']) . '</span>' : '' ?></td>
                            <td><?= report_h($appointment['professional_name'] ?: '-') ?></td>
                            <td><?= report_h(appointment_service_option_label($appointment)) ?></td>
                            <td><?= ($appointment['consultation_type'] ?? '') === 'online' ? 'Online' : 'Presencial' ?></td>
                            <td><?= report_h(($appointment['payment_status'] ?? 'pending') === 'paid' ? 'Pagada' : 'Pendiente') ?><?= $appointment['payment_method'] ? '<br><span class="meta">' . report_h(payment_method_label($appointment['payment_method'])) . '</span>' : '' ?></td>
                        </tr>
                        <?php if ($report_type === 'internal' && trim((string) ($appointment['session_notes'] ?? '')) !== ''): ?>
                            <tr>
                                <td></td>
                                <td colspan="5">
                                    <strong>Notas privadas de la sesión</strong>
                                    <div class="preline"><?= nl2br(report_h($appointment['session_notes'])) ?></div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty">No hay citas registradas.</p>
            <?php endif; ?>
        </section>

        <section>
            <h2>Plan de trabajo</h2>
            <?php if ($tasks): ?>
                <?php foreach ($tasks as $task): ?>
                    <div class="task">
                        <strong><?= report_h($task['title']) ?></strong>
                        <div class="meta">Estado: <?= $task['status'] === 'completed' ? 'Completada' : 'Pendiente' ?> · Prioridad: <?= report_h($task_priority_labels[(int) ($task['priority'] ?? 2)] ?? 'Normal') ?><?= $task['completed_at'] ? ' · Completada: ' . report_datetime($task['completed_at']) : '' ?></div>
                        <?php if (!empty($task['description'])): ?><div class="preline"><?= nl2br(report_h($task['description'])) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="empty">No hay tareas en el plan de trabajo.</p>
            <?php endif; ?>
        </section>

        <section>
            <h2>Evolución</h2>
            <?php if ($evolution_notes): ?>
                <?php foreach ($evolution_notes as $note): ?>
                    <div class="note">
                        <strong><?= report_date($note['note_date']) ?> · <?= report_h($note['title']) ?></strong>
                        <div class="meta">
                            <?= report_h($note['professional_name'] ?: 'Sin profesional') ?>
                            <?= $note['appointment_date'] ? ' · Cita ' . report_date($note['appointment_date']) . ' ' . report_h(substr((string) $note['appointment_time'], 0, 5)) : ' · Nota general' ?>
                        </div>
                        <?php if (!empty($note['description'])): ?><h3>Descripción</h3><div class="preline"><?= nl2br(report_h($note['description'])) ?></div><?php endif; ?>
                        <?php if (!empty($note['observations'])): ?><h3>Observaciones</h3><div class="preline"><?= nl2br(report_h($note['observations'])) ?></div><?php endif; ?>
                        <?php if (!empty($note['next_steps'])): ?><h3>Pendientes / próxima cita</h3><div class="preline"><?= nl2br(report_h($note['next_steps'])) ?></div><?php endif; ?>
                        <?php $note_files = $evolution_files_by_note[(int) $note['id']] ?? []; ?>
                        <?php if ($note_files): ?>
                            <div class="meta">Archivos: <?= report_h(implode(', ', array_map(fn($file) => $file['original_name'], $note_files))) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="empty">No hay registros de evolución.</p>
            <?php endif; ?>
        </section>

        <section>
            <h2>Bonos</h2>
            <?php if ($bonuses): ?>
                <table>
                    <thead><tr><th>Bono</th><th>Sesiones</th><th>Estado</th><th>Compra</th><th>Caducidad</th></tr></thead>
                    <tbody>
                    <?php foreach ($bonuses as $bonus): ?>
                        <tr>
                            <td><?= report_h($bonus['name']) ?></td>
                            <td><?= (int) $bonus['remaining_sessions'] ?> / <?= (int) $bonus['total_sessions'] ?></td>
                            <td><?= report_h($bonus['status']) ?></td>
                            <td><?= report_datetime($bonus['purchased_at']) ?></td>
                            <td><?= report_date($bonus['expires_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty">No hay bonos registrados.</p>
            <?php endif; ?>
        </section>

        <?php if ($report_type === 'clinical'): ?>
        <section>
            <h2>Interpretacion clinica</h2>
            <p class="empty">Espacio reservado para que el profesional complete la interpretacion clinica a partir de la historia registrada, resultados de cuestionarios, evolucion observada y criterio profesional.</p>
        </section>

        <section>
            <h2>Conclusiones y recomendaciones</h2>
            <p class="empty">Espacio reservado para conclusiones, recomendaciones, plan de seguimiento, derivaciones o indicaciones finales antes de emitir la version oficial.</p>
        </section>

        <section>
            <h2>Firma profesional</h2>
            <div class="grid">
                <div class="field"><span>Profesional</span><strong><?= report_h($patient['professional_name'] ?: '-') ?></strong></div>
                <div class="field"><span>Titulacion / cargo</span><strong><?= report_h($patient['professional_title'] ?: '-') ?></strong></div>
                <div class="field"><span>Nº colegiado</span><strong><?= report_h($patient['license_number'] ?: '-') ?></strong></div>
            </div>
        </section>
        <?php endif; ?>
    </article>
</main>
</body>
</html>
    <?php
    exit;
} elseif ($action === 'patient_evolution') {
    $patient_id = (int) ($_GET['patient_id'] ?? 0);
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para ver la evolucion.']);
        exit;
    }
    create_initial_patient_evolution_from_profile_if_needed($mysqli, $tenant_id, $patient_id, (int) ($_SESSION['user_id'] ?? 0));

    $branding = get_public_branding_settings($mysqli);
    $dashboard_photo = $branding['profile_image_path'] ?? '';
    $stmt = $mysqli->prepare("
        SELECT n.id, n.patient_id, n.appointment_id, n.professional_id, n.note_date, n.title,
               n.description, n.observations, n.next_steps, n.created_at, n.updated_at,
               n.weight_kg, n.height_cm, n.body_fat_percentage, n.waist_cm, n.hip_cm, n.chest_cm, n.thigh_cm, n.biceps_cm, n.calf_cm,
               n.skinfold_triceps_mm, n.skinfold_subscapular_mm, n.skinfold_suprailiac_mm,
               n.skinfold_abdominal_mm, n.skinfold_chest_mm, n.skinfold_thigh_mm,
               a.appointment_date, a.appointment_time,
               p.display_name AS professional_name, p.public_photo_path AS professional_photo_path,
               pu.role AS professional_user_role,
               COUNT(f.id) AS file_count
        FROM patient_evolution_notes n
        LEFT JOIN appointments a ON a.id = n.appointment_id AND a.tenant_id = n.tenant_id
        LEFT JOIN professionals p ON p.id = n.professional_id AND p.tenant_id = n.tenant_id
        LEFT JOIN users pu ON pu.id = p.user_id AND pu.tenant_id = p.tenant_id
        LEFT JOIN patient_evolution_files f ON f.evolution_note_id = n.id AND f.tenant_id = n.tenant_id
        WHERE n.tenant_id = ?
          AND n.patient_id = ?
        GROUP BY n.id
        ORDER BY n.note_date DESC, n.id DESC
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $notes = [];
    while ($row = $res->fetch_assoc()) {
        $row['professional_photo_path'] = professional_photo_with_dashboard_fallback($row, $dashboard_photo);
        $notes[] = [
            'id' => (int) $row['id'],
            'patient_id' => (int) $row['patient_id'],
            'appointment_id' => (int) ($row['appointment_id'] ?? 0),
            'professional_id' => (int) ($row['professional_id'] ?? 0),
            'professional_name' => $row['professional_name'] ?? '',
            'professional_photo_path' => $row['professional_photo_path'] ?? '',
            'note_date' => $row['note_date'],
            'title' => $row['title'],
            'description' => $row['description'] ?? '',
            'observations' => $row['observations'] ?? '',
            'next_steps' => $row['next_steps'] ?? '',
            'weight_kg' => $row['weight_kg'] ?? '',
            'height_cm' => $row['height_cm'] ?? '',
            'body_fat_percentage' => $row['body_fat_percentage'] ?? '',
            'waist_cm' => $row['waist_cm'] ?? '',
            'hip_cm' => $row['hip_cm'] ?? '',
            'chest_cm' => $row['chest_cm'] ?? '',
            'thigh_cm' => $row['thigh_cm'] ?? '',
            'biceps_cm' => $row['biceps_cm'] ?? '',
            'calf_cm' => $row['calf_cm'] ?? '',
            'skinfold_triceps_mm' => $row['skinfold_triceps_mm'] ?? '',
            'skinfold_subscapular_mm' => $row['skinfold_subscapular_mm'] ?? '',
            'skinfold_suprailiac_mm' => $row['skinfold_suprailiac_mm'] ?? '',
            'skinfold_abdominal_mm' => $row['skinfold_abdominal_mm'] ?? '',
            'skinfold_chest_mm' => $row['skinfold_chest_mm'] ?? '',
            'skinfold_thigh_mm' => $row['skinfold_thigh_mm'] ?? '',
            'appointment_date' => $row['appointment_date'] ?? '',
            'appointment_time' => $row['appointment_time'] ? substr((string) $row['appointment_time'], 0, 5) : '',
            'file_count' => (int) ($row['file_count'] ?? 0),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }

    $stmt = $mysqli->prepare("
        SELECT a.id, a.appointment_date, a.appointment_time,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               s.name AS service_name
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        WHERE a.tenant_id = ?
          AND a.user_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $appointments_res = $stmt->get_result();
    $appointments = [];
    while ($row = $appointments_res->fetch_assoc()) {
        $duration = (int) ($row['duration_minutes'] ?? 60);
        $service = trim((string) ($row['service_name'] ?? 'Cita'));
        $appointments[] = [
            'id' => (int) $row['id'],
            'label' => date('d/m/Y', strtotime($row['appointment_date'])) . ' ' . substr((string) $row['appointment_time'], 0, 5) . ' - ' . $service . ' (' . $duration . ' min)'
        ];
    }

    echo json_encode(['success' => true, 'notes' => $notes, 'appointments' => $appointments]);
} elseif ($action === 'save_patient_evolution') {
    $note_id = (int) ($_POST['note_id'] ?? 0);
    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    $note_date = trim($_POST['note_date'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $observations = trim($_POST['observations'] ?? '');
    $next_steps = trim($_POST['next_steps'] ?? '');
    $weight_kg = nullable_decimal_value($_POST['weight_kg'] ?? '', 500);
    $height_cm = nullable_decimal_value($_POST['height_cm'] ?? '', 280);
    $body_fat_percentage = nullable_decimal_value($_POST['body_fat_percentage'] ?? '', 80);
    $waist_cm = nullable_decimal_value($_POST['waist_cm'] ?? '', 300);
    $hip_cm = nullable_decimal_value($_POST['hip_cm'] ?? '', 300);
    $chest_cm = nullable_decimal_value($_POST['chest_cm'] ?? '', 300);
    $thigh_cm = nullable_decimal_value($_POST['thigh_cm'] ?? '', 200);
    $biceps_cm = nullable_decimal_value($_POST['biceps_cm'] ?? '', 120);
    $calf_cm = nullable_decimal_value($_POST['calf_cm'] ?? '', 120);
    $skinfold_triceps_mm = nullable_decimal_value($_POST['skinfold_triceps_mm'] ?? '', 120);
    $skinfold_subscapular_mm = nullable_decimal_value($_POST['skinfold_subscapular_mm'] ?? '', 120);
    $skinfold_suprailiac_mm = nullable_decimal_value($_POST['skinfold_suprailiac_mm'] ?? '', 120);
    $skinfold_abdominal_mm = nullable_decimal_value($_POST['skinfold_abdominal_mm'] ?? '', 120);
    $skinfold_chest_mm = nullable_decimal_value($_POST['skinfold_chest_mm'] ?? '', 120);
    $skinfold_thigh_mm = nullable_decimal_value($_POST['skinfold_thigh_mm'] ?? '', 120);
    $physical_metrics = [
        'weight_kg' => $weight_kg,
        'height_cm' => $height_cm,
        'body_fat_percentage' => $body_fat_percentage,
        'waist_cm' => $waist_cm,
        'hip_cm' => $hip_cm,
        'chest_cm' => $chest_cm,
        'thigh_cm' => $thigh_cm,
        'biceps_cm' => $biceps_cm,
        'calf_cm' => $calf_cm,
        'skinfold_triceps_mm' => $skinfold_triceps_mm,
        'skinfold_subscapular_mm' => $skinfold_subscapular_mm,
        'skinfold_suprailiac_mm' => $skinfold_suprailiac_mm,
        'skinfold_abdominal_mm' => $skinfold_abdominal_mm,
        'skinfold_chest_mm' => $skinfold_chest_mm,
        'skinfold_thigh_mm' => $skinfold_thigh_mm
    ];

    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para guardar esta evolucion.']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $note_date)) {
        echo json_encode(['success' => false, 'error' => 'Indica una fecha valida.']);
        exit;
    }
    if ($title === '') {
        echo json_encode(['success' => false, 'error' => 'Indica un titulo.']);
        exit;
    }

    $professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
    if ($appointment_id > 0) {
        $stmt = $mysqli->prepare("SELECT professional_id, appointment_date FROM appointments WHERE tenant_id = ? AND id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param("iii", $tenant_id, $appointment_id, $patient_id);
        $stmt->execute();
        $appointment = $stmt->get_result()->fetch_assoc();
        if (!$appointment) {
            echo json_encode(['success' => false, 'error' => 'La cita vinculada no existe.']);
            exit;
        }
        $appointment_manage_result = admin_can_manage_appointment_payment($mysqli, $appointment_id);
        if (!$appointment_manage_result[0] && !$is_superadmin) {
            echo json_encode(['success' => false, 'error' => 'No autorizado para vincular esa cita.']);
            exit;
        }
        $professional_id = (int) ($appointment['professional_id'] ?? $professional_id);
    }
    if ($professional_id <= 0) {
        $professional_id = null;
    }

    $created_by = (int) ($_SESSION['user_id'] ?? 0);
    $appointment_id_db = $appointment_id > 0 ? $appointment_id : null;
    $mysqli->begin_transaction();
    try {
        if ($note_id > 0) {
            $stmt = $mysqli->prepare("SELECT patient_id FROM patient_evolution_notes WHERE tenant_id = ? AND id = ? LIMIT 1");
            $stmt->bind_param("ii", $tenant_id, $note_id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            if (!$existing || (int) $existing['patient_id'] !== $patient_id) {
                throw new \Exception('No se encontro el registro de evolucion.');
            }
            $stmt = $mysqli->prepare("
                UPDATE patient_evolution_notes
                SET appointment_id = ?, professional_id = ?, note_date = ?, title = ?, description = ?, observations = ?, next_steps = ?,
                    weight_kg = ?, height_cm = ?, body_fat_percentage = ?, waist_cm = ?, hip_cm = ?, chest_cm = ?, thigh_cm = ?, biceps_cm = ?, calf_cm = ?,
                    skinfold_triceps_mm = ?, skinfold_subscapular_mm = ?, skinfold_suprailiac_mm = ?, skinfold_abdominal_mm = ?, skinfold_chest_mm = ?, skinfold_thigh_mm = ?
                WHERE tenant_id = ? AND id = ? AND patient_id = ?
            ");
            $stmt->bind_param("iissssssssssssssssssssiii", $appointment_id_db, $professional_id, $note_date, $title, $description, $observations, $next_steps, $weight_kg, $height_cm, $body_fat_percentage, $waist_cm, $hip_cm, $chest_cm, $thigh_cm, $biceps_cm, $calf_cm, $skinfold_triceps_mm, $skinfold_subscapular_mm, $skinfold_suprailiac_mm, $skinfold_abdominal_mm, $skinfold_chest_mm, $skinfold_thigh_mm, $tenant_id, $note_id, $patient_id);
            $stmt->execute();
        } else {
            $stmt = $mysqli->prepare("
                INSERT INTO patient_evolution_notes
                    (tenant_id, patient_id, appointment_id, professional_id, note_date, title, description, observations, next_steps,
                     weight_kg, height_cm, body_fat_percentage, waist_cm, hip_cm, chest_cm, thigh_cm, biceps_cm, calf_cm,
                     skinfold_triceps_mm, skinfold_subscapular_mm, skinfold_suprailiac_mm, skinfold_abdominal_mm, skinfold_chest_mm, skinfold_thigh_mm, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iiiissssssssssssssssssssi", $tenant_id, $patient_id, $appointment_id_db, $professional_id, $note_date, $title, $description, $observations, $next_steps, $weight_kg, $height_cm, $body_fat_percentage, $waist_cm, $hip_cm, $chest_cm, $thigh_cm, $biceps_cm, $calf_cm, $skinfold_triceps_mm, $skinfold_subscapular_mm, $skinfold_suprailiac_mm, $skinfold_abdominal_mm, $skinfold_chest_mm, $skinfold_thigh_mm, $created_by);
            $stmt->execute();
            $note_id = $mysqli->insert_id;
        }
        $saved_files = save_patient_evolution_uploads($mysqli, $_FILES['evolution_files'] ?? null, $note_id, $patient_id);
        if (patient_physical_metrics_have_values($physical_metrics)) {
            $stmt = $mysqli->prepare("SELECT id FROM patient_evolution_notes WHERE tenant_id = ? AND patient_id = ? ORDER BY note_date DESC, id DESC LIMIT 1");
            $stmt->bind_param("ii", $tenant_id, $patient_id);
            $stmt->execute();
            $latest_note = $stmt->get_result()->fetch_assoc();
            if ($latest_note && (int) $latest_note['id'] === (int) $note_id) {
                update_patient_profile_physical_metrics($mysqli, $tenant_id, $patient_id, $physical_metrics, '__keep__');
            }
        }
        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => 'Evolucion guardada correctamente.', 'note_id' => $note_id, 'files_saved' => $saved_files]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'patient_files') {
    $patient_id = (int) ($_GET['patient_id'] ?? 0);
    $filter_type = trim((string) ($_GET['type'] ?? 'all'));
    if (!in_array($filter_type, ['all', 'file', 'questionnaire'], true)) {
        $filter_type = 'all';
    }
    $questionnaires_enabled = app_feature_enabled_from_db($mysqli, 'questionnaires.enabled', false);
    if ($filter_type === 'questionnaire' && !$questionnaires_enabled) {
        echo json_encode(['success' => false, 'error' => 'Los cuestionarios no estan disponibles en este plan.']);
        exit;
    }
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para ver archivos.']);
        exit;
    }
    $files = [];
    if ($filter_type !== 'questionnaire') {
        $stmt = $mysqli->prepare("SELECT document_path, document_name, updated_at FROM patient_profiles WHERE tenant_id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param("ii", $tenant_id, $patient_id);
        $stmt->execute();
        if ($profile = $stmt->get_result()->fetch_assoc()) {
            if (!empty($profile['document_path'])) {
                $files[] = [
                    'type' => 'file',
                    'legacy_type' => 'patient_document',
                    'id' => 0,
                    'name' => $profile['document_name'] ?: 'Documento del paciente',
                    'source' => 'Ficha del paciente',
                    'date' => $profile['updated_at'] ?? '',
                    'url' => 'api/admin.php?action=download_patient_document&patient_id=' . $patient_id,
                    'can_delete' => false
                ];
            }
        }
        $stmt = $mysqli->prepare("
            SELECT f.id, f.original_name, f.file_size, f.uploaded_at, n.title
            FROM patient_evolution_files f
            JOIN patient_evolution_notes n ON n.id = f.evolution_note_id AND n.tenant_id = f.tenant_id
            WHERE f.tenant_id = ?
              AND f.patient_id = ?
            ORDER BY f.uploaded_at DESC, f.id DESC
        ");
        $stmt->bind_param("ii", $tenant_id, $patient_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $files[] = [
                'type' => 'file',
                'legacy_type' => 'evolution_file',
                'id' => (int) $row['id'],
                'name' => $row['original_name'],
                'source' => $row['title'] ?: 'Evolucion',
                'date' => $row['uploaded_at'],
                'size' => (int) ($row['file_size'] ?? 0),
                'url' => 'api/admin.php?action=download_evolution_file&id=' . (int) $row['id'],
                'can_delete' => false
            ];
        }
    }

    if ($filter_type !== 'all') {
        $stmt = $mysqli->prepare("
            SELECT d.*,
                   (SELECT COUNT(*) FROM patient_document_versions v WHERE v.tenant_id = d.tenant_id AND v.document_id = d.id) AS version_count
            FROM patient_documents d
            WHERE d.tenant_id = ?
              AND d.patient_id = ?
              AND d.document_type = ?
            ORDER BY COALESCE(d.document_date, DATE(d.updated_at), DATE(d.created_at)) DESC, d.updated_at DESC, d.id DESC
        ");
        $stmt->bind_param("iis", $tenant_id, $patient_id, $filter_type);
    } else {
        $stmt = $mysqli->prepare("
            SELECT d.*,
                   (SELECT COUNT(*) FROM patient_document_versions v WHERE v.tenant_id = d.tenant_id AND v.document_id = d.id) AS version_count
            FROM patient_documents d
            WHERE d.tenant_id = ?
              AND d.patient_id = ?
            ORDER BY COALESCE(d.document_date, DATE(d.updated_at), DATE(d.created_at)) DESC, d.updated_at DESC, d.id DESC
        ");
        $stmt->bind_param("ii", $tenant_id, $patient_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $document_type = $row['document_type'] === 'questionnaire' ? 'questionnaire' : 'file';
        if ($document_type === 'questionnaire' && !$questionnaires_enabled) {
            continue;
        }
        $files[] = [
            'type' => $document_type,
            'legacy_type' => '',
            'id' => (int) $row['id'],
            'name' => $row['title'] ?: ($row['original_file_name'] ?: ($document_type === 'questionnaire' ? 'Cuestionario' : 'Archivo')),
            'file_name' => $row['original_file_name'] ?: '',
            'source' => $document_type === 'questionnaire' ? 'Cuestionario' : 'Archivo',
            'date' => $row['document_date'] ?: $row['updated_at'],
            'size' => (int) ($row['file_size'] ?? 0),
            'url' => $row['file_path'] ? 'api/admin.php?action=download_patient_document_file&id=' . (int) $row['id'] : '',
            'description' => $row['description'] ?? '',
            'score' => $row['score'] ?? '',
            'result_label' => $row['result_label'] ?? '',
            'observations' => $row['observations'] ?? '',
            'visible_to_patient' => (int) ($row['visible_to_patient'] ?? 0),
            'result_visible_to_patient' => (int) ($row['result_visible_to_patient'] ?? 0),
            'status' => $row['status'] ?? 'completed',
            'version_count' => (int) ($row['version_count'] ?? 0),
            'can_delete' => true
        ];
    }
    usort($files, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
    echo json_encode(['success' => true, 'files' => $files]);
} elseif ($action === 'save_patient_document_file') {
    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $document_id = (int) ($_POST['document_id'] ?? 0);
    $document_type = trim((string) ($_POST['document_type'] ?? 'file'));
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $document_date = trim((string) ($_POST['document_date'] ?? ''));
    $score = trim((string) ($_POST['score'] ?? ''));
    $result_label = trim((string) ($_POST['result_label'] ?? ''));
    $observations = trim((string) ($_POST['observations'] ?? ''));
    $status = trim((string) ($_POST['status'] ?? 'completed'));
    $version_type = trim((string) ($_POST['version_type'] ?? 'completed'));
    $visible_to_patient = !empty($_POST['visible_to_patient']) ? 1 : 0;

    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para guardar documentacion.']);
        exit;
    }
    if (!in_array($document_type, ['file', 'questionnaire'], true)) {
        $document_type = 'file';
    }
    if ($document_type === 'questionnaire' && !app_feature_enabled_from_db($mysqli, 'questionnaires.enabled', false)) {
        echo json_encode(['success' => false, 'error' => 'Los cuestionarios no estan disponibles en este plan.']);
        exit;
    }
    $result_visible_to_patient = ($document_type === 'questionnaire' && !empty($_POST['result_visible_to_patient'])) ? 1 : 0;
    if (!in_array($status, ['pending', 'completed', 'reviewed'], true)) {
        $status = 'completed';
    }
    if (!in_array($version_type, ['template', 'completed', 'revision'], true)) {
        $version_type = 'completed';
    }
    if ($document_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $document_date)) {
        echo json_encode(['success' => false, 'error' => 'Indica una fecha valida.']);
        exit;
    }
    if ($document_date === '') {
        $document_date = null;
    }

    $transaction_started = false;
    try {
        $uploaded = save_patient_document_file_upload($_FILES['document_file'] ?? null, $patient_id);
        if ($title === '' && $uploaded) {
            $title = pathinfo($uploaded['name'], PATHINFO_FILENAME);
        }
        if ($title === '') {
            $title = $document_type === 'questionnaire' ? 'Cuestionario' : 'Archivo';
        }

        $professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
        $professional_id = $professional_id > 0 ? $professional_id : null;
        $created_by = (int) ($_SESSION['user_id'] ?? 0);
        $file_path = $uploaded['path'] ?? null;
        $original_file_name = $uploaded['name'] ?? null;
        $file_size = $uploaded['size'] ?? null;
        $mime_type = $uploaded['mime'] ?? null;

        $mysqli->begin_transaction();
        $transaction_started = true;
        if ($document_id > 0) {
            $stmt = $mysqli->prepare("SELECT id FROM patient_documents WHERE tenant_id = ? AND id = ? AND patient_id = ? LIMIT 1");
            $stmt->bind_param("iii", $tenant_id, $document_id, $patient_id);
            $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) {
                throw new \Exception('No se encontro el documento.');
            }

            if ($uploaded) {
                $stmt = $mysqli->prepare("
                    UPDATE patient_documents
                    SET professional_id = ?, document_type = ?, title = ?, description = ?, document_date = ?, score = ?, result_label = ?, observations = ?,
                        file_path = ?, original_file_name = ?, file_size = ?, mime_type = ?, visible_to_patient = ?, result_visible_to_patient = ?, status = ?
                    WHERE tenant_id = ? AND id = ? AND patient_id = ?
                ");
                $types = "isssssssssisiisiii";
                $stmt->bind_param($types, $professional_id, $document_type, $title, $description, $document_date, $score, $result_label, $observations, $file_path, $original_file_name, $file_size, $mime_type, $visible_to_patient, $result_visible_to_patient, $status, $tenant_id, $document_id, $patient_id);
                $stmt->execute();
            } else {
                $stmt = $mysqli->prepare("
                    UPDATE patient_documents
                    SET professional_id = ?, document_type = ?, title = ?, description = ?, document_date = ?, score = ?, result_label = ?, observations = ?,
                        visible_to_patient = ?, result_visible_to_patient = ?, status = ?
                    WHERE tenant_id = ? AND id = ? AND patient_id = ?
                ");
                $types = "isssssssiisiii";
                $stmt->bind_param($types, $professional_id, $document_type, $title, $description, $document_date, $score, $result_label, $observations, $visible_to_patient, $result_visible_to_patient, $status, $tenant_id, $document_id, $patient_id);
                $stmt->execute();
            }
        } else {
            $stmt = $mysqli->prepare("
                INSERT INTO patient_documents
                    (tenant_id, patient_id, professional_id, document_type, title, description, document_date, score, result_label, observations,
                     file_path, original_file_name, file_size, mime_type, visible_to_patient, result_visible_to_patient, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $types = "iii" . str_repeat("s", 9) . "isiisi";
            $stmt->bind_param($types, $tenant_id, $patient_id, $professional_id, $document_type, $title, $description, $document_date, $score, $result_label, $observations, $file_path, $original_file_name, $file_size, $mime_type, $visible_to_patient, $result_visible_to_patient, $status, $created_by);
            $stmt->execute();
            $document_id = $mysqli->insert_id;
        }

        if ($uploaded) {
            $stmt = $mysqli->prepare("
                INSERT INTO patient_document_versions
                    (tenant_id, document_id, version_type, file_path, original_file_name, file_size, mime_type, score, result_label, observations, document_date, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $types = "iisssisssssi";
            $stmt->bind_param($types, $tenant_id, $document_id, $version_type, $file_path, $original_file_name, $file_size, $mime_type, $score, $result_label, $observations, $document_date, $created_by);
            $stmt->execute();
        }
        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => $document_type === 'questionnaire' ? 'Cuestionario guardado correctamente.' : 'Archivo guardado correctamente.', 'document_id' => $document_id]);
    } catch (\Exception $e) {
        if ($transaction_started) {
            $mysqli->rollback();
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'download_patient_document_file') {
    $document_id = (int) ($_GET['id'] ?? 0);
    $stmt = $mysqli->prepare("
        SELECT id, patient_id, file_path, original_file_name, mime_type, file_size
        FROM patient_documents
        WHERE tenant_id = ? AND id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $document_id);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc();
    if (!$document || !admin_can_access_patient($mysqli, (int) $document['patient_id'])) {
        http_response_code(403);
        echo 'No autorizado';
        exit;
    }
    $full_path = stored_upload_full_path($document['file_path'] ?? '');
    if (!$full_path || !is_file($full_path)) {
        http_response_code(404);
        echo 'Archivo no encontrado';
        exit;
    }
    header_remove('Content-Type');
    header('Content-Type: ' . ($document['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes($document['original_file_name'] ?: basename($full_path)) . '"');
    header('Content-Length: ' . filesize($full_path));
    readfile($full_path);
    exit;
} elseif ($action === 'delete_patient_document_file') {
    $document_id = (int) ($_POST['document_id'] ?? 0);
    $stmt = $mysqli->prepare("SELECT id, patient_id, file_path FROM patient_documents WHERE tenant_id = ? AND id = ? LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $document_id);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc();
    if (!$document || !admin_can_access_patient($mysqli, (int) $document['patient_id'])) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para eliminar este documento.']);
        exit;
    }
    $paths = [];
    if (!empty($document['file_path'])) {
        $paths[] = $document['file_path'];
    }
    $stmt = $mysqli->prepare("SELECT file_path FROM patient_document_versions WHERE tenant_id = ? AND document_id = ?");
    $stmt->bind_param("ii", $tenant_id, $document_id);
    $stmt->execute();
    $versions = $stmt->get_result();
    while ($version = $versions->fetch_assoc()) {
        if (!empty($version['file_path'])) {
            $paths[] = $version['file_path'];
        }
    }
    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("DELETE FROM patient_document_versions WHERE tenant_id = ? AND document_id = ?");
        $stmt->bind_param("ii", $tenant_id, $document_id);
        $stmt->execute();
        $stmt = $mysqli->prepare("DELETE FROM patient_documents WHERE tenant_id = ? AND id = ?");
        $stmt->bind_param("ii", $tenant_id, $document_id);
        $stmt->execute();
        $mysqli->commit();
        foreach (array_unique($paths) as $path) {
            $full_path = stored_upload_full_path($path);
            if ($full_path && is_file($full_path)) {
                @unlink($full_path);
            }
        }
        echo json_encode(['success' => true, 'message' => 'Documento eliminado correctamente.']);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => 'No se pudo eliminar el documento.']);
    }
} elseif ($action === 'knowledge_problems') {
    if (!app_feature_enabled_from_db($mysqli, 'knowledgeBase.enabled', false)) {
        echo json_encode(['success' => false, 'error' => 'La base de conocimiento no esta disponible en este plan.']);
        exit;
    }
    $sector_keys = allowed_knowledge_sector_keys($mysqli);
    $sector_sql = knowledge_sector_in_sql($mysqli, $sector_keys);
    $sector_names = knowledge_sector_names_by_key($mysqli);
    $main_sector = current_knowledge_sector_key($mysqli);
    $stmt = $mysqli->prepare("
        SELECT p.id, p.sector_key, p.problem_code, p.name, p.alias, p.risk_level,
               a.id AS area_id, a.name AS area_name
        FROM knowledge_problems p
        INNER JOIN knowledge_areas a ON a.id = p.area_id
        WHERE p.sector_key IN ($sector_sql)
        ORDER BY CASE WHEN p.sector_key = '" . $mysqli->real_escape_string($main_sector) . "' THEN 0 ELSE 1 END ASC,
                 a.name ASC, p.name ASC
    ");
    $stmt->execute();
    $res = $stmt->get_result();
    $problems = [];
    $seen_problem_names = [];
    while ($row = $res->fetch_assoc()) {
        $problem_name_for_dedupe = trim((string) ($row['name'] ?? ''));
        $dedupe_key = function_exists('mb_strtolower') ? mb_strtolower($problem_name_for_dedupe, 'UTF-8') : strtolower($problem_name_for_dedupe);
        if ($dedupe_key !== '' && isset($seen_problem_names[$dedupe_key])) {
            continue;
        }
        if ($dedupe_key !== '') {
            $seen_problem_names[$dedupe_key] = true;
        }
        $sector_key = (string) ($row['sector_key'] ?? '');
        $problems[] = [
            'id' => (int) $row['id'],
            'sector_key' => $sector_key,
            'sector_label' => $sector_names[$sector_key] ?? ucfirst(str_replace(['_', '-'], ' ', $sector_key)),
            'code' => $row['problem_code'],
            'name' => $row['name'],
            'alias' => $row['alias'] ?? '',
            'risk_level' => $row['risk_level'] ?? '',
            'area_id' => (int) $row['area_id'],
            'area_name' => $row['area_name']
        ];
    }
    echo json_encode(['success' => true, 'problems' => array_values($problems), 'sectors' => $sector_keys]);
} elseif ($action === 'knowledge_import_options') {
    if (!app_feature_enabled_from_db($mysqli, 'knowledgeBase.enabled', false) || !app_feature_enabled_from_db($mysqli, 'knowledgeBase.importTasks', false)) {
        echo json_encode(['success' => true, 'options' => []]);
        exit;
    }
    $sector_keys = allowed_knowledge_sector_keys($mysqli);
    $sector_sql = knowledge_sector_in_sql($mysqli, $sector_keys);
    $sector_names = knowledge_sector_names_by_key($mysqli);
    $stmt = $mysqli->prepare("
        SELECT p.id AS problem_id, p.sector_key, p.name AS problem_name,
               a.name AS area_name,
               te.id AS technique_id, te.name AS technique_name,
               COUNT(r.id) AS task_count
        FROM knowledge_recommendations r
        INNER JOIN knowledge_problems p ON p.id = r.problem_id
        INNER JOIN knowledge_areas a ON a.id = p.area_id
        INNER JOIN knowledge_techniques te ON te.id = r.technique_id
        WHERE r.sector_key IN ($sector_sql)
        GROUP BY p.id, p.sector_key, p.name, a.name, te.id, te.name
        HAVING task_count > 0
        ORDER BY p.sector_key ASC, a.name ASC, p.name ASC, te.name ASC
    ");
    $stmt->execute();
    $res = $stmt->get_result();
    $options = [];
    while ($row = $res->fetch_assoc()) {
        $sector_key = (string) ($row['sector_key'] ?? '');
        $options[] = [
            'problem_id' => (int) $row['problem_id'],
            'sector_key' => $sector_key,
            'sector_label' => $sector_names[$sector_key] ?? ucfirst(str_replace(['_', '-'], ' ', $sector_key)),
            'problem_name' => $row['problem_name'],
            'area_name' => $row['area_name'] ?? '',
            'technique_id' => (int) $row['technique_id'],
            'technique_name' => $row['technique_name'],
            'task_count' => (int) $row['task_count']
        ];
    }
    echo json_encode(['success' => true, 'options' => $options]);
} elseif ($action === 'knowledge_problem_detail') {
    if (!app_feature_enabled_from_db($mysqli, 'knowledgeBase.enabled', false)) {
        echo json_encode(['success' => false, 'error' => 'La base de conocimiento no esta disponible en este plan.']);
        exit;
    }
    $problem_id = (int) ($_GET['problem_id'] ?? 0);
    $sector_sql = knowledge_sector_in_sql($mysqli, allowed_knowledge_sector_keys($mysqli));
    $sector_names = knowledge_sector_names_by_key($mysqli);
    $stmt = $mysqli->prepare("
        SELECT p.id, p.sector_key, p.problem_code, p.name, p.alias, p.description, p.population, p.risk_level,
               a.name AS area_name
        FROM knowledge_problems p
        INNER JOIN knowledge_areas a ON a.id = p.area_id
        WHERE p.id = ? AND p.sector_key IN ($sector_sql)
        LIMIT 1
    ");
    $stmt->bind_param("i", $problem_id);
    $stmt->execute();
    $problem = $stmt->get_result()->fetch_assoc();
    if (!$problem) {
        echo json_encode(['success' => false, 'error' => 'No se encontro el problema o diagnostico.']);
        exit;
    }
    $sector_key = (string) ($problem['sector_key'] ?? current_knowledge_sector_key($mysqli));

    $stmt = $mysqli->prepare("
        SELECT r.id AS recommendation_id, r.priority, r.clinical_note,
               te.id AS technique_id, te.technique_code, te.name AS technique_name, te.description AS technique_description, te.risk_level AS technique_risk,
               t.id AS task_id, t.task_code, t.title AS task_title, t.description AS task_description, t.objective, t.risk_level AS task_risk, t.estimated_duration
        FROM knowledge_recommendations r
        INNER JOIN knowledge_techniques te ON te.id = r.technique_id
        INNER JOIN knowledge_tasks t ON t.id = r.task_id
        WHERE r.problem_id = ? AND r.sector_key = ?
        ORDER BY te.name ASC,
                 FIELD(r.priority, 'alta', 'media', 'baja') ASC,
                 t.title ASC
    ");
    $stmt->bind_param("is", $problem_id, $sector_key);
    $stmt->execute();
    $res = $stmt->get_result();
    $techniques = [];
    while ($row = $res->fetch_assoc()) {
        $technique_id = (int) $row['technique_id'];
        if (!isset($techniques[$technique_id])) {
            $techniques[$technique_id] = [
                'id' => $technique_id,
                'code' => $row['technique_code'],
                'name' => $row['technique_name'],
                'description' => $row['technique_description'] ?? '',
                'risk_level' => $row['technique_risk'] ?? '',
                'recommendations' => []
            ];
        }
        $techniques[$technique_id]['recommendations'][] = [
            'id' => (int) $row['recommendation_id'],
            'priority' => $row['priority'] ?? '',
            'clinical_note' => $row['clinical_note'] ?? '',
            'task' => [
                'id' => (int) $row['task_id'],
                'code' => $row['task_code'],
                'title' => $row['task_title'],
                'description' => $row['task_description'] ?? '',
                'objective' => $row['objective'] ?? '',
                'risk_level' => $row['task_risk'] ?? '',
                'estimated_duration' => $row['estimated_duration'] ?? ''
            ]
        ];
    }

    $questionnaires = [];
    if (app_feature_enabled_from_db($mysqli, 'questionnaires.enabled', false)) {
        $stmt = $mysqli->prepare("
            SELECT q.id, q.questionnaire_code, q.name, q.use_area, q.questionnaire_type, q.notes, q.resource_kind
            FROM knowledge_questionnaires q
            WHERE q.problem_id = ? AND q.sector_key = ?
            ORDER BY q.resource_kind ASC, q.name ASC
        ");
        $stmt->bind_param("is", $problem_id, $sector_key);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $questionnaires[] = [
                'id' => (int) $row['id'],
                'code' => $row['questionnaire_code'],
                'name' => $row['name'],
                'use_area' => $row['use_area'] ?? '',
                'type' => $row['questionnaire_type'] ?? '',
                'notes' => $row['notes'] ?? '',
                'resource_kind' => ($row['resource_kind'] ?? '') === 'document' ? 'document' : 'questionnaire'
            ];
        }
    }

    $stmt = $mysqli->prepare("
        SELECT s.source_code, s.name, s.organization, s.title, s.url, s.notes
        FROM knowledge_problem_sources ps
        INNER JOIN knowledge_sources s ON s.id = ps.source_id
        WHERE ps.problem_id = ? AND ps.sector_key = ?
        ORDER BY s.name ASC
    ");
    $stmt->bind_param("is", $problem_id, $sector_key);
    $stmt->execute();
    $res = $stmt->get_result();
    $sources = [];
    while ($row = $res->fetch_assoc()) {
        $sources[] = [
            'code' => $row['source_code'],
            'name' => $row['name'],
            'organization' => $row['organization'] ?? '',
            'title' => $row['title'] ?? '',
            'url' => $row['url'] ?? '',
            'notes' => $row['notes'] ?? ''
        ];
    }

    echo json_encode([
        'success' => true,
        'problem' => [
            'id' => (int) $problem['id'],
            'sector_key' => $sector_key,
            'sector_label' => $sector_names[$sector_key] ?? ucfirst(str_replace(['_', '-'], ' ', $sector_key)),
            'code' => $problem['problem_code'],
            'name' => $problem['name'],
            'alias' => $problem['alias'] ?? '',
            'description' => $problem['description'] ?? '',
            'population' => $problem['population'] ?? '',
            'risk_level' => $problem['risk_level'] ?? '',
            'area_name' => $problem['area_name'] ?? ''
        ],
        'techniques' => array_values($techniques),
        'questionnaires' => $questionnaires,
        'sources' => $sources
    ]);
} elseif ($action === 'body_map_recommendations') {
    if (!app_feature_enabled_from_db($mysqli, 'knowledgeBase.enabled', false)) {
        echo json_encode(['success' => false, 'error' => 'La base de conocimiento no esta disponible en este plan.']);
        exit;
    }
    $sector_key = current_knowledge_sector_key($mysqli);
    $body_map_sectors = ['fitness', 'fisioterapia', 'quiropractica', 'osteopatia'];
    if (!in_array($sector_key, $body_map_sectors, true)) {
        echo json_encode(['success' => false, 'error' => 'El mapa muscular no esta disponible para este sector.']);
        exit;
    }
    $muscles = array_values(array_unique(array_filter(array_map(static function ($value) {
        $value = trim((string) $value);
        return preg_match('/^[a-z0-9_-]+$/i', $value) ? $value : '';
    }, explode(',', $_GET['muscles'] ?? '')))));
    if (!$muscles) {
        echo json_encode(['success' => true, 'sector' => $sector_key, 'muscles' => [], 'exercises' => []]);
        exit;
    }
    if (!table_exists($mysqli, 'praxis_bodymuscles_regions')) {
        echo json_encode(['success' => false, 'error' => 'No se encontro la tabla de regiones musculares.']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($muscles), '?'));
    $types = str_repeat('s', count($muscles));
    $stmt = $mysqli->prepare("
        SELECT bodymuscles_id, name_es, group_es, view_es, side_es
        FROM praxis_bodymuscles_regions
        WHERE bodymuscles_id IN ($placeholders)
        ORDER BY group_es ASC, name_es ASC
    ");
    $stmt->bind_param($types, ...$muscles);
    $stmt->execute();
    $res = $stmt->get_result();
    $muscle_rows = [];
    while ($row = $res->fetch_assoc()) {
        $muscle_rows[$row['bodymuscles_id']] = [
            'bodymuscles_id' => $row['bodymuscles_id'],
            'name_es' => $row['name_es'] ?? '',
            'group_es' => $row['group_es'] ?? '',
            'view_es' => $row['view_es'] ?? '',
            'side_es' => $row['side_es'] ?? ''
        ];
    }

    if ($sector_key !== 'fitness') {
        echo json_encode([
            'success' => true,
            'sector' => $sector_key,
            'muscles' => array_values($muscle_rows),
            'exercises' => [],
            'message' => 'Este sector ya tiene el mapa preparado. Falta importar recomendaciones vinculadas a musculos.'
        ]);
        exit;
    }
    foreach (['fitness_exercises', 'fitness_exercise_regions'] as $required_table) {
        if (!table_exists($mysqli, $required_table)) {
            echo json_encode([
                'success' => true,
                'sector' => $sector_key,
                'muscles' => array_values($muscle_rows),
                'exercises' => [],
                'message' => 'Todavia no hay ejercicios fitness importados.'
            ]);
            exit;
        }
    }
    ensure_fitness_exercise_media_columns($mysqli);

    $stmt = $mysqli->prepare("
        SELECT er.bodymuscles_id, er.role, er.intensity,
               br.name_es AS muscle_name_es,
               e.exercise_id, e.name_en, e.name_es, e.equipment_id, e.category, e.difficulty,
               e.mechanics, e.movement_pattern, e.description_es, e.cues_es,
               e.image_url, e.aliases, e.external_source, e.external_id,
               e.workoutx_body_part, e.workoutx_target, e.workoutx_equipment,
               eq.nombre_es AS equipment_name
        FROM fitness_exercise_regions er
        INNER JOIN fitness_exercises e ON e.exercise_id = er.exercise_id AND e.active = 1
        LEFT JOIN praxis_bodymuscles_regions br ON br.bodymuscles_id = er.bodymuscles_id
        LEFT JOIN fitness_equipment eq ON eq.equipment_id = e.equipment_id
        WHERE er.bodymuscles_id IN ($placeholders)
        ORDER BY FIELD(er.role, 'primary', 'secondary', 'stabilizer') ASC,
                 er.intensity DESC,
                 e.name_es ASC
    ");
    $stmt->bind_param($types, ...$muscles);
    $stmt->execute();
    $res = $stmt->get_result();
    $exercises = [];
    while ($row = $res->fetch_assoc()) {
        $exercise_id = $row['exercise_id'];
        if (!isset($exercises[$exercise_id])) {
            $exercises[$exercise_id] = [
                'exercise_id' => $exercise_id,
                'name_en' => $row['name_en'] ?? '',
                'name_es' => $row['name_es'] ?? '',
                'equipment_id' => $row['equipment_id'] ?? '',
                'equipment_name' => $row['equipment_name'] ?? '',
                'category' => $row['category'] ?? '',
                'difficulty' => $row['difficulty'] ?? '',
                'mechanics' => $row['mechanics'] ?? '',
                'movement_pattern' => $row['movement_pattern'] ?? '',
                'description_es' => $row['description_es'] ?? '',
                'cues_es' => $row['cues_es'] ?? '',
                'image_url' => $row['image_url'] ?? '',
                'aliases' => $row['aliases'] ?? '',
                'external_source' => $row['external_source'] ?? '',
                'external_id' => $row['external_id'] ?? '',
                'workoutx_body_part' => $row['workoutx_body_part'] ?? '',
                'workoutx_target' => $row['workoutx_target'] ?? '',
                'workoutx_equipment' => $row['workoutx_equipment'] ?? '',
                'regions' => []
            ];
        }
        $exercises[$exercise_id]['regions'][] = [
            'bodymuscles_id' => $row['bodymuscles_id'],
            'name_es' => $row['muscle_name_es'] ?? '',
            'role' => $row['role'] ?? '',
            'intensity' => (int) ($row['intensity'] ?? 0)
        ];
    }
    echo json_encode([
        'success' => true,
        'sector' => $sector_key,
        'muscles' => array_values($muscle_rows),
        'exercises' => array_values($exercises)
    ]);
} elseif ($action === 'workoutx_exercise_gif') {
    if (!app_feature_enabled_from_db($mysqli, 'knowledgeBase.enabled', false) || !workoutx_available()) {
        http_response_code(404);
        exit;
    }
    if (!table_exists($mysqli, 'fitness_exercises')) {
        http_response_code(404);
        exit;
    }
    ensure_fitness_exercise_media_columns($mysqli);
    $exercise_id = trim((string) ($_GET['exercise_id'] ?? ''));
    if ($exercise_id === '') {
        http_response_code(400);
        exit;
    }
    $stmt = $mysqli->prepare("
        SELECT external_id
        FROM fitness_exercises
        WHERE exercise_id = ?
          AND active = 1
          AND external_source = 'workoutx'
          AND external_id IS NOT NULL
          AND external_id <> ''
        LIMIT 1
    ");
    $stmt->bind_param("s", $exercise_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        http_response_code(404);
        exit;
    }
    $gif = workoutx_fetch_gif($row['external_id']);
    if (empty($gif['success'])) {
        http_response_code((int) ($gif['status'] ?? 502) ?: 502);
        exit;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . ($gif['content_type'] ?? 'image/gif'));
    header('Cache-Control: private, max-age=86400');
    echo $gif['body'];
    exit;
} elseif ($action === 'workoutx_exercise_media') {
    if (!app_feature_enabled_from_db($mysqli, 'knowledgeBase.enabled', false)) {
        echo json_encode(['success' => false, 'error' => 'La base de conocimiento no esta disponible en este plan.']);
        exit;
    }
    if (!table_exists($mysqli, 'fitness_exercises')) {
        echo json_encode(['success' => false, 'error' => 'No hay ejercicios fitness importados.']);
        exit;
    }
    ensure_fitness_exercise_media_columns($mysqli);
    $exercise_id = trim((string) ($_GET['exercise_id'] ?? ''));
    $workoutx_external_id = trim((string) ($_GET['workoutx_external_id'] ?? ''));
    if ($exercise_id === '' && $workoutx_external_id === '') {
        echo json_encode(['success' => false, 'error' => 'Falta el ejercicio.']);
        exit;
    }
    if ($exercise_id !== '') {
        $stmt = $mysqli->prepare("
            SELECT exercise_id, name_en, name_es, description_es, description_en, cues_es,
                   instructions_en, instructions_es, secondary_muscles_en, calories_per_min,
                   aliases, image_url, external_source, external_id,
                   workoutx_body_part, workoutx_target, workoutx_equipment, difficulty
            FROM fitness_exercises
            WHERE exercise_id = ?
              AND active = 1
            LIMIT 1
        ");
        $stmt->bind_param("s", $exercise_id);
    } else {
        $stmt = $mysqli->prepare("
            SELECT exercise_id, name_en, name_es, description_es, description_en, cues_es,
                   instructions_en, instructions_es, secondary_muscles_en, calories_per_min,
                   aliases, image_url, external_source, external_id,
                   workoutx_body_part, workoutx_target, workoutx_equipment, difficulty
            FROM fitness_exercises
            WHERE external_source = 'workoutx'
              AND external_id = ?
              AND active = 1
            LIMIT 1
        ");
        $stmt->bind_param("s", $workoutx_external_id);
    }
    $stmt->execute();
    $local_exercise = $stmt->get_result()->fetch_assoc();
    if (!$local_exercise && $exercise_id === '' && $workoutx_external_id !== '' && workoutx_available()) {
        $remote_by_id = workoutx_exercise_by_id($workoutx_external_id);
        if (!empty($remote_by_id['success']) && is_array($remote_by_id['data'] ?? null)) {
            $exercise_id = ensure_workoutx_exercise_local($mysqli, $remote_by_id['data']);
            $stmt = $mysqli->prepare("
                SELECT exercise_id, name_en, name_es, description_es, description_en, cues_es,
                       instructions_en, instructions_es, secondary_muscles_en, calories_per_min,
                       aliases, image_url, external_source, external_id,
                       workoutx_body_part, workoutx_target, workoutx_equipment, difficulty
                FROM fitness_exercises
                WHERE exercise_id = ?
                  AND active = 1
                LIMIT 1
            ");
            $stmt->bind_param("s", $exercise_id);
            $stmt->execute();
            $local_exercise = $stmt->get_result()->fetch_assoc();
        }
    }
    if (!$local_exercise) {
        echo json_encode(['success' => false, 'error' => 'No se encontro el ejercicio local.']);
        exit;
    }
    $exercise_id = (string) ($local_exercise['exercise_id'] ?? $exercise_id);

    $cached_instructions = [];
    $instructions_json = trim((string) ($local_exercise['instructions_es'] ?: $local_exercise['instructions_en'] ?: ''));
    if ($instructions_json !== '') {
        $decoded = json_decode($instructions_json, true);
        if (is_array($decoded)) {
            $cached_instructions = array_values(array_filter(array_map('strval', $decoded)));
        } else {
            $cached_instructions = array_values(array_filter(array_map('trim', preg_split('/\R+/', $instructions_json))));
        }
    }
    if ($cached_instructions) {
        $secondary_muscles = [];
        $secondary_json = trim((string) ($local_exercise['secondary_muscles_en'] ?? ''));
        if ($secondary_json !== '') {
            $decoded_secondary = json_decode($secondary_json, true);
            if (is_array($decoded_secondary)) {
                $secondary_muscles = array_values(array_filter(array_map('strval', $decoded_secondary)));
            }
        }
        echo json_encode([
            'success' => true,
            'exercise' => [
                'localExerciseId' => $exercise_id,
                'id' => $local_exercise['external_id'] ?? '',
                'name' => $local_exercise['name_en'] ?? '',
                'localNameEs' => $local_exercise['name_es'] ?? '',
                'descriptionEs' => $local_exercise['description_es'] ?? '',
                'descriptionEn' => $local_exercise['description_en'] ?? '',
                'cuesEs' => $local_exercise['cues_es'] ?? '',
                'bodyPart' => $local_exercise['workoutx_body_part'] ?? '',
                'target' => $local_exercise['workoutx_target'] ?? '',
                'equipment' => $local_exercise['workoutx_equipment'] ?? '',
                'difficulty' => $local_exercise['difficulty'] ?? '',
                'mechanic' => '',
                'force' => '',
                'gifUrl' => !empty($local_exercise['external_id']) ? 'api/admin.php?action=workoutx_exercise_gif&exercise_id=' . rawurlencode($exercise_id) : '',
                'remoteGifUrl' => $local_exercise['image_url'] ?? '',
                'instructions' => $cached_instructions,
                'secondaryMuscles' => $secondary_muscles,
                'caloriesPerMinute' => $local_exercise['calories_per_min'] ?? null
            ],
            'cached' => true,
            'headers' => []
        ]);
        exit;
    }

    if (!workoutx_available()) {
        echo json_encode([
            'success' => true,
            'exercise' => [
                'localExerciseId' => $exercise_id,
                'id' => $local_exercise['external_id'] ?? '',
                'name' => $local_exercise['name_en'] ?? '',
                'localNameEs' => $local_exercise['name_es'] ?? '',
                'descriptionEs' => $local_exercise['description_es'] ?? '',
                'cuesEs' => $local_exercise['cues_es'] ?? '',
                'bodyPart' => $local_exercise['workoutx_body_part'] ?? '',
                'target' => $local_exercise['workoutx_target'] ?? '',
                'equipment' => $local_exercise['workoutx_equipment'] ?? '',
                'difficulty' => $local_exercise['difficulty'] ?? '',
                'mechanic' => '',
                'force' => '',
                'gifUrl' => '',
                'remoteGifUrl' => $local_exercise['image_url'] ?? '',
                'instructions' => [],
                'secondaryMuscles' => [],
                'caloriesPerMinute' => $local_exercise['calories_per_min'] ?? null
            ],
            'warning' => 'No hay instrucciones locales para este ejercicio.',
            'headers' => []
        ]);
        exit;
    }

    $match = workoutx_match_exercise($local_exercise);
    if (empty($match['success']) || !is_array($match['data'] ?? null)) {
        echo json_encode([
            'success' => true,
            'exercise' => [
                'localExerciseId' => $exercise_id,
                'id' => $local_exercise['external_id'] ?? '',
                'name' => $local_exercise['name_en'] ?? '',
                'localNameEs' => $local_exercise['name_es'] ?? '',
                'descriptionEs' => $local_exercise['description_es'] ?? '',
                'cuesEs' => $local_exercise['cues_es'] ?? '',
                'bodyPart' => '',
                'target' => '',
                'equipment' => '',
                'difficulty' => '',
                'mechanic' => '',
                'force' => '',
                'gifUrl' => '',
                'remoteGifUrl' => '',
                'instructions' => [],
                'secondaryMuscles' => [],
                'caloriesPerMinute' => null
            ],
            'warning' => $match['error'] ?? 'No se encontro informacion adicional del ejercicio.',
            'headers' => $match['headers'] ?? []
        ]);
        exit;
    }

    $remote = $match['data'];
    $external_id = (string) ($remote['id'] ?? '');
    $gif_url = (string) ($remote['gifUrl'] ?? '');
    $local_gif_url = $external_id !== ''
        ? 'api/admin.php?action=workoutx_exercise_gif&exercise_id=' . rawurlencode($exercise_id)
        : '';
    $aliases = trim((string) ($local_exercise['aliases'] ?? ''));
    $remote_name = trim((string) ($remote['name'] ?? ''));
    if ($remote_name !== '' && stripos(',' . $aliases . ',', ',' . $remote_name . ',') === false) {
        $aliases = trim($aliases !== '' ? $aliases . ', ' . $remote_name : $remote_name);
    }

    if ($external_id !== '' || $gif_url !== '') {
        $source = 'workoutx';
        $stmt = $mysqli->prepare("
            UPDATE fitness_exercises
            SET external_source = ?,
                external_id = COALESCE(NULLIF(?, ''), external_id),
                image_url = COALESCE(NULLIF(?, ''), image_url),
                aliases = COALESCE(NULLIF(?, ''), aliases),
                workoutx_body_part = COALESCE(NULLIF(?, ''), workoutx_body_part),
                workoutx_target = COALESCE(NULLIF(?, ''), workoutx_target),
                workoutx_equipment = COALESCE(NULLIF(?, ''), workoutx_equipment)
            WHERE exercise_id = ?
            LIMIT 1
        ");
        $body_part = (string) ($remote['bodyPart'] ?? '');
        $target = (string) ($remote['target'] ?? '');
        $equipment = (string) ($remote['equipment'] ?? '');
        $stmt->bind_param("ssssssss", $source, $external_id, $gif_url, $aliases, $body_part, $target, $equipment, $exercise_id);
        $stmt->execute();
    }

    echo json_encode([
        'success' => true,
        'exercise' => [
            'localExerciseId' => $exercise_id,
            'id' => $external_id,
            'name' => $remote['name'] ?? '',
            'localNameEs' => $local_exercise['name_es'] ?? '',
            'descriptionEs' => $local_exercise['description_es'] ?? '',
            'cuesEs' => $local_exercise['cues_es'] ?? '',
            'bodyPart' => $remote['bodyPart'] ?? '',
            'target' => $remote['target'] ?? '',
            'equipment' => $remote['equipment'] ?? '',
            'difficulty' => $remote['difficulty'] ?? '',
            'mechanic' => $remote['mechanic'] ?? '',
            'force' => $remote['force'] ?? '',
            'gifUrl' => $local_gif_url,
            'remoteGifUrl' => $gif_url,
            'instructions' => $remote['instructions'] ?? [],
            'secondaryMuscles' => $remote['secondaryMuscles'] ?? [],
            'caloriesPerMinute' => $remote['caloriesPerMinute'] ?? null
        ],
        'matched_by' => $match['matched_by'] ?? '',
        'headers' => $match['headers'] ?? []
    ]);
} elseif ($action === 'workoutx_generate_plan') {
    ensure_action_feature($mysqli, 'tasks.enabled', 'Las tareas no estan disponibles en este plan.');
    if (!app_feature_enabled_from_db($mysqli, 'knowledgeBase.enabled', false)) {
        echo json_encode(['success' => false, 'error' => 'La base de conocimiento no esta disponible en este plan.']);
        exit;
    }
    if (!workoutx_available()) {
        echo json_encode(['success' => false, 'error' => 'El generador de rutinas no esta configurado.']);
        exit;
    }
    if (!table_exists($mysqli, 'fitness_exercises')) {
        echo json_encode(['success' => false, 'error' => 'No hay ejercicios fitness importados.']);
        exit;
    }

    $goal = trim((string) ($_GET['goal'] ?? 'muscle_gain'));
    $duration = max(20, min(120, (int) ($_GET['duration'] ?? 45)));
    $level = trim((string) ($_GET['level'] ?? 'intermediate'));
    $split = trim((string) ($_GET['split'] ?? 'full_body'));
    $equipment = trim((string) ($_GET['equipment'] ?? ''));
    $body_focus = trim((string) ($_GET['bodyFocus'] ?? ''));
    $seed = trim((string) ($_GET['seed'] ?? ''));

    $allowed_goals = ['muscle_gain', 'strength', 'fat_loss', 'endurance', 'mobility'];
    $allowed_levels = ['beginner', 'intermediate', 'advanced'];
    $allowed_splits = ['full_body', 'upper', 'lower', 'push', 'pull', 'legs', 'push_pull_legs', 'upper_lower', 'core'];
    if (!in_array($goal, $allowed_goals, true)) {
        $goal = 'muscle_gain';
    }
    if (!in_array($level, $allowed_levels, true)) {
        $level = 'intermediate';
    }
    if (!in_array($split, $allowed_splits, true)) {
        $split = 'full_body';
    }

    $params = [
        'goal' => $goal,
        'duration' => (string) $duration,
        'level' => $level,
        'split' => $split
    ];
    if ($equipment !== '') {
        $params['equipment'] = $equipment;
    }
    if ($body_focus !== '') {
        $params['bodyFocus'] = $body_focus;
    }
    if ($seed !== '') {
        $params['seed'] = $seed;
    }

    $response = workoutx_generate_workout($params);
    if (empty($response['success']) || !is_array($response['data'] ?? null)) {
        echo json_encode([
            'success' => false,
            'error' => $response['error'] ?? 'No se pudo generar la rutina.',
            'headers' => $response['headers'] ?? []
        ]);
        exit;
    }

    $plan = $response['data'];
    if (!empty($plan['exercises']) && is_array($plan['exercises'])) {
        foreach ($plan['exercises'] as &$plan_exercise) {
            if (!is_array($plan_exercise)) {
                continue;
            }
            $remote_exercise = is_array($plan_exercise['exercise'] ?? null) ? $plan_exercise['exercise'] : $plan_exercise;
            $external_id = workoutx_remote_value($remote_exercise, ['id', 'exerciseId', 'exercise_id']);
            $local_id = '';
            $local_name_es = '';
            $local_name_en = '';
            if ($external_id !== '') {
                $stmt = $mysqli->prepare("SELECT exercise_id, name_es, name_en FROM fitness_exercises WHERE external_source = 'workoutx' AND external_id = ? LIMIT 1");
                $stmt->bind_param("s", $external_id);
                $stmt->execute();
                $local = $stmt->get_result()->fetch_assoc();
                $local_id = (string) ($local['exercise_id'] ?? '');
                $local_name_es = (string) ($local['name_es'] ?? '');
                $local_name_en = (string) ($local['name_en'] ?? '');
            }
            $plan_exercise['localExerciseId'] = $local_id;
            $plan_exercise['workoutxExternalId'] = $external_id;
            $plan_exercise['localNameEs'] = $local_name_es;
            $plan_exercise['localNameEn'] = $local_name_en;
        }
        unset($plan_exercise);
    }

    echo json_encode([
        'success' => true,
        'plan' => $plan,
        'headers' => $response['headers'] ?? []
    ]);
} elseif ($action === 'import_knowledge_recommendation_task') {
    ensure_action_feature($mysqli, 'tasks.enabled', 'Las tareas no estan disponibles en este plan.');
    if (!app_feature_enabled_from_db($mysqli, 'knowledgeBase.enabled', false) || !app_feature_enabled_from_db($mysqli, 'knowledgeBase.importTasks', false)) {
        echo json_encode(['success' => false, 'error' => 'La importacion de tareas recomendadas no esta disponible en este plan.']);
        exit;
    }
    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $recommendation_id = (int) ($_POST['recommendation_id'] ?? 0);
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para modificar este paciente.']);
        exit;
    }
    ensure_patient_work_plan_tables($mysqli);
    $task_id = import_knowledge_recommendation_task($mysqli, $patient_id, $recommendation_id, $appointment_id);
    if ($task_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'No se pudo importar la tarea recomendada.']);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Tarea añadida al plan de trabajo.', 'task_id' => $task_id]);
} elseif ($action === 'import_knowledge_problem_tasks') {
    ensure_action_feature($mysqli, 'tasks.enabled', 'Las tareas no estan disponibles en este plan.');
    if (!app_feature_enabled_from_db($mysqli, 'knowledgeBase.enabled', false) || !app_feature_enabled_from_db($mysqli, 'knowledgeBase.importTasks', false)) {
        echo json_encode(['success' => false, 'error' => 'La importacion de tareas recomendadas no esta disponible en este plan.']);
        exit;
    }
    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $problem_id = (int) ($_POST['problem_id'] ?? 0);
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para modificar este paciente.']);
        exit;
    }
    ensure_patient_work_plan_tables($mysqli);
    $sector_sql = knowledge_sector_in_sql($mysqli, allowed_knowledge_sector_keys($mysqli));
    $stmt = $mysqli->prepare("SELECT id FROM knowledge_recommendations WHERE problem_id = ? AND sector_key IN ($sector_sql) ORDER BY FIELD(priority, 'alta', 'media', 'baja') ASC, id ASC");
    $stmt->bind_param("i", $problem_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $count = 0;
    while ($row = $res->fetch_assoc()) {
        if (import_knowledge_recommendation_task($mysqli, $patient_id, (int) $row['id'], $appointment_id) > 0) {
            $count++;
        }
    }
    echo json_encode(['success' => true, 'message' => $count . ' tareas añadidas al plan de trabajo.', 'count' => $count]);
} elseif ($action === 'import_knowledge_technique_tasks') {
    ensure_action_feature($mysqli, 'tasks.enabled', 'Las tareas no estan disponibles en este plan.');
    if (!app_feature_enabled_from_db($mysqli, 'knowledgeBase.enabled', false) || !app_feature_enabled_from_db($mysqli, 'knowledgeBase.importTasks', false)) {
        echo json_encode(['success' => false, 'error' => 'La importacion de tareas recomendadas no esta disponible en este plan.']);
        exit;
    }
    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $problem_id = (int) ($_POST['problem_id'] ?? 0);
    $technique_id = (int) ($_POST['technique_id'] ?? 0);
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para modificar este paciente.']);
        exit;
    }
    ensure_patient_work_plan_tables($mysqli);
    $sector_sql = knowledge_sector_in_sql($mysqli, allowed_knowledge_sector_keys($mysqli));
    $stmt = $mysqli->prepare("
        SELECT id
        FROM knowledge_recommendations
        WHERE problem_id = ? AND technique_id = ? AND sector_key IN ($sector_sql)
        ORDER BY FIELD(priority, 'alta', 'media', 'baja') ASC, id ASC
    ");
    $stmt->bind_param("ii", $problem_id, $technique_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $count = 0;
    while ($row = $res->fetch_assoc()) {
        if (import_knowledge_recommendation_task($mysqli, $patient_id, (int) $row['id'], $appointment_id) > 0) {
            $count++;
        }
    }
    echo json_encode(['success' => true, 'message' => $count . ' tareas de la tecnica anadidas al plan de trabajo.', 'count' => $count]);
} elseif ($action === 'patient_work_plan') {
    ensure_action_feature($mysqli, 'tasks.enabled', 'Las tareas no estan disponibles en este plan.');
    $patient_id = (int) ($_GET['patient_id'] ?? 0);
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para ver el plan de trabajo.']);
        exit;
    }

    $has_fitness_exercises = table_exists($mysqli, 'fitness_exercises');
    $fitness_select = $has_fitness_exercises
        ? "fe.name_es AS fitness_name_es, fe.name_en AS fitness_name_en, fe.image_url AS fitness_image_url,
               fe.external_source AS fitness_external_source, fe.external_id AS fitness_external_id"
        : "NULL AS fitness_name_es, NULL AS fitness_name_en, NULL AS fitness_image_url,
               NULL AS fitness_external_source, NULL AS fitness_external_id";
    $fitness_join = $has_fitness_exercises
        ? "LEFT JOIN fitness_exercises fe ON fe.exercise_id = t.fitness_exercise_id AND fe.active = 1"
        : "";
    $stmt = $mysqli->prepare("
        SELECT t.id, t.patient_id, t.appointment_id, t.professional_id, t.title, t.description, t.status, t.priority, t.visible_to_patient,
               t.fitness_exercise_id,
               t.completed_at, t.created_at, t.updated_at,
               p.display_name AS professional_name,
               $fitness_select
        FROM patient_work_plan_tasks t
        LEFT JOIN professionals p ON p.id = t.professional_id AND p.tenant_id = t.tenant_id
        $fitness_join
        WHERE t.tenant_id = ?
          AND t.patient_id = ?
        ORDER BY
            CASE WHEN t.status = 'pending' THEN 0 ELSE 1 END,
            t.priority ASC,
            t.updated_at DESC,
            t.id DESC
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $tasks = [];
    while ($row = $res->fetch_assoc()) {
        $tasks[] = [
            'id' => (int) $row['id'],
            'patient_id' => (int) $row['patient_id'],
            'appointment_id' => (int) ($row['appointment_id'] ?? 0),
            'professional_id' => (int) ($row['professional_id'] ?? 0),
            'professional_name' => $row['professional_name'] ?? '',
            'title' => $row['title'],
            'description' => $row['description'] ?? '',
            'status' => $row['status'],
            'priority' => (int) ($row['priority'] ?? 2),
            'visible_to_patient' => (int) ($row['visible_to_patient'] ?? 0),
            'fitness_exercise_id' => $row['fitness_exercise_id'] ?? '',
            'fitness_exercise' => !empty($row['fitness_exercise_id']) ? [
                'exercise_id' => $row['fitness_exercise_id'] ?? '',
                'name_es' => $row['fitness_name_es'] ?? '',
                'name_en' => $row['fitness_name_en'] ?? '',
                'image_url' => $row['fitness_image_url'] ?? '',
                'external_source' => $row['fitness_external_source'] ?? '',
                'external_id' => $row['fitness_external_id'] ?? ''
            ] : null,
            'completed_at' => $row['completed_at'] ?? '',
            'created_at' => $row['created_at'] ?? '',
            'updated_at' => $row['updated_at'] ?? ''
        ];
    }
    echo json_encode(['success' => true, 'tasks' => $tasks]);
} elseif ($action === 'save_patient_work_plan_task') {
    ensure_action_feature($mysqli, 'tasks.enabled', 'Las tareas no estan disponibles en este plan.');
    $task_id = (int) ($_POST['task_id'] ?? 0);
    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $appointment_was_posted = array_key_exists('appointment_id', $_POST);
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = (int) ($_POST['priority'] ?? 2);
    $task_status_enabled = work_plan_task_status_enabled($mysqli);
    $status = $task_status_enabled && ($_POST['status'] ?? '') === 'completed' ? 'completed' : 'pending';
    $visible_to_patient = (int) ($_POST['visible_to_patient'] ?? 0) === 1 ? 1 : 0;

    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para guardar esta tarea.']);
        exit;
    }
    if ($title === '') {
        echo json_encode(['success' => false, 'error' => 'Indica un titulo para la tarea.']);
        exit;
    }
    if (!in_array($priority, [1, 2, 3], true)) {
        $priority = 2;
    }

    $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
    $professional_id = current_professional_id_for_user($mysqli, $session_user_id);
    $completed_at = $status === 'completed' ? date('Y-m-d H:i:s') : null;
    $completed_by = $status === 'completed' ? $session_user_id : null;
    $appointment_id_db = null;
    if ($appointment_was_posted && $appointment_id > 0) {
        $appointment_manage_result = admin_can_manage_appointment_payment($mysqli, $appointment_id);
        if (!$appointment_manage_result[0] || (int) ($appointment_manage_result[1]['user_id'] ?? 0) !== $patient_id) {
            echo json_encode(['success' => false, 'error' => 'No autorizado para vincular esa cita.']);
            exit;
        }
        $appointment_id_db = $appointment_id;
        if (!empty($appointment_manage_result[1]['professional_id'])) {
            $professional_id = (int) $appointment_manage_result[1]['professional_id'];
        }
    }

    if ($task_id > 0) {
        $stmt = $mysqli->prepare("SELECT patient_id, appointment_id, professional_id, status FROM patient_work_plan_tasks WHERE tenant_id = ? AND id = ? LIMIT 1");
        $stmt->bind_param("ii", $tenant_id, $task_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        if (!$existing || (int) $existing['patient_id'] !== $patient_id) {
            echo json_encode(['success' => false, 'error' => 'No se encontro la tarea.']);
            exit;
        }
        if (!$task_status_enabled) {
            $status = ($existing['status'] ?? '') === 'completed' ? 'completed' : 'pending';
        }
        $existing_professional_id = (int) ($existing['professional_id'] ?? 0);
        if ($professional_id <= 0) {
            $professional_id = $existing_professional_id > 0 ? $existing_professional_id : null;
        }
        if (!$appointment_was_posted) {
            $appointment_id_db = !empty($existing['appointment_id']) ? (int) $existing['appointment_id'] : null;
        }
        if ($status === 'completed' && ($existing['status'] ?? '') === 'completed') {
            $completed_at = null;
            $completed_by = null;
            $stmt = $mysqli->prepare("
                UPDATE patient_work_plan_tasks
                SET appointment_id = ?, title = ?, description = ?, priority = ?, visible_to_patient = ?, status = ?
                WHERE tenant_id = ? AND id = ? AND patient_id = ?
            ");
            $stmt->bind_param("issiisiii", $appointment_id_db, $title, $description, $priority, $visible_to_patient, $status, $tenant_id, $task_id, $patient_id);
        } else {
            $stmt = $mysqli->prepare("
                UPDATE patient_work_plan_tasks
                SET appointment_id = ?, professional_id = ?, title = ?, description = ?, priority = ?, visible_to_patient = ?, status = ?,
                    completed_at = ?, completed_by = ?
                WHERE tenant_id = ? AND id = ? AND patient_id = ?
            ");
            $stmt->bind_param("iissiissiiii", $appointment_id_db, $professional_id, $title, $description, $priority, $visible_to_patient, $status, $completed_at, $completed_by, $tenant_id, $task_id, $patient_id);
        }
        $stmt->execute();
    } else {
        if ($professional_id <= 0) {
            $professional_id = null;
        }
        $stmt = $mysqli->prepare("
            INSERT INTO patient_work_plan_tasks (tenant_id, patient_id, appointment_id, professional_id, title, description, status, priority, visible_to_patient, created_by, completed_at, completed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iiiisssiiisi", $tenant_id, $patient_id, $appointment_id_db, $professional_id, $title, $description, $status, $priority, $visible_to_patient, $session_user_id, $completed_at, $completed_by);
        $stmt->execute();
        $task_id = $mysqli->insert_id;
    }
    echo json_encode(['success' => true, 'message' => 'Plan de trabajo guardado correctamente.', 'task_id' => $task_id]);
} elseif ($action === 'add_fitness_exercise_to_work_plan') {
    ensure_action_feature($mysqli, 'tasks.enabled', 'Las tareas no estan disponibles en este plan.');
    ensure_patient_work_plan_tables($mysqli);
    ensure_fitness_exercise_media_columns($mysqli);

    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    $exercise_id = trim((string) ($_POST['exercise_id'] ?? ''));
    $workoutx_external_id = trim((string) ($_POST['workoutx_external_id'] ?? ''));
    $visible_to_patient = (int) ($_POST['visible_to_patient'] ?? 0) === 1 ? 1 : 0;
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para asignar ejercicios a este paciente.']);
        exit;
    }
    if ($exercise_id === '' && $workoutx_external_id !== '' && workoutx_available()) {
        $remote = workoutx_exercise_by_id($workoutx_external_id);
        if (!empty($remote['success']) && is_array($remote['data'] ?? null)) {
            $exercise_id = ensure_workoutx_exercise_local($mysqli, $remote['data']);
        }
    }
    if ($exercise_id === '' || !table_exists($mysqli, 'fitness_exercises')) {
        echo json_encode(['success' => false, 'error' => 'No se pudo identificar el ejercicio.']);
        exit;
    }

    $stmt = $mysqli->prepare("
        SELECT exercise_id, name_es, name_en, description_es, cues_es
        FROM fitness_exercises
        WHERE exercise_id = ?
          AND active = 1
        LIMIT 1
    ");
    $stmt->bind_param("s", $exercise_id);
    $stmt->execute();
    $exercise = $stmt->get_result()->fetch_assoc();
    if (!$exercise) {
        echo json_encode(['success' => false, 'error' => 'No se encontro el ejercicio.']);
        exit;
    }

    $appointment_id_db = null;
    $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
    $professional_id = current_professional_id_for_user($mysqli, $session_user_id);
    if ($appointment_id > 0) {
        $appointment_manage_result = admin_can_manage_appointment_payment($mysqli, $appointment_id);
        if (!$appointment_manage_result[0] || (int) ($appointment_manage_result[1]['user_id'] ?? 0) !== $patient_id) {
            echo json_encode(['success' => false, 'error' => 'No autorizado para vincular esa cita.']);
            exit;
        }
        $appointment_id_db = $appointment_id;
        if (!empty($appointment_manage_result[1]['professional_id'])) {
            $professional_id = (int) $appointment_manage_result[1]['professional_id'];
        }
    }
    if ($professional_id <= 0) {
        $professional_id = null;
    }

    $title = trim((string) ($exercise['name_es'] ?: $exercise['name_en'] ?: 'Ejercicio'));
    $description_parts = array_filter([
        trim((string) ($exercise['description_es'] ?? '')),
        trim((string) ($exercise['cues_es'] ?? ''))
    ]);
    $description = implode("\n\n", $description_parts);
    $priority = 2;
    $stmt = $mysqli->prepare("
        INSERT INTO patient_work_plan_tasks
            (tenant_id, patient_id, appointment_id, professional_id, title, description, status, priority, visible_to_patient, fitness_exercise_id, created_by)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)
    ");
    $stmt->bind_param("iiiissiisi", $tenant_id, $patient_id, $appointment_id_db, $professional_id, $title, $description, $priority, $visible_to_patient, $exercise_id, $session_user_id);
    $stmt->execute();
    echo json_encode([
        'success' => true,
        'message' => 'Ejercicio agregado al plan de trabajo.',
        'task_id' => $mysqli->insert_id
    ]);
} elseif ($action === 'set_patient_work_plan_task_status') {
    ensure_action_feature($mysqli, 'tasks.enabled', 'Las tareas no estan disponibles en este plan.');
    if (!work_plan_task_status_enabled($mysqli)) {
        echo json_encode(['success' => false, 'error' => 'El cambio de estado de tareas esta desactivado.']);
        exit;
    }
    $task_id = (int) ($_POST['task_id'] ?? 0);
    $status = ($_POST['status'] ?? '') === 'completed' ? 'completed' : 'pending';
    $stmt = $mysqli->prepare("SELECT patient_id FROM patient_work_plan_tasks WHERE tenant_id = ? AND id = ? LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $task_id);
    $stmt->execute();
    $task = $stmt->get_result()->fetch_assoc();
    if (!$task || !admin_can_access_patient($mysqli, (int) $task['patient_id'])) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para actualizar esta tarea.']);
        exit;
    }

    if ($status === 'completed') {
        $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
        $completed_at_response = date('Y-m-d H:i:s');
        $stmt = $mysqli->prepare("UPDATE patient_work_plan_tasks SET status = 'completed', completed_at = ?, completed_by = ? WHERE tenant_id = ? AND id = ?");
        $stmt->bind_param("siii", $completed_at_response, $session_user_id, $tenant_id, $task_id);
    } else {
        $completed_at_response = null;
        $stmt = $mysqli->prepare("UPDATE patient_work_plan_tasks SET status = 'pending', completed_at = NULL, completed_by = NULL WHERE tenant_id = ? AND id = ?");
        $stmt->bind_param("ii", $tenant_id, $task_id);
    }
    $stmt->execute();
    echo json_encode([
        'success' => true,
        'message' => $status === 'completed' ? 'Tarea completada.' : 'Tarea marcada como pendiente.',
        'completed_at' => $completed_at_response
    ]);
} elseif ($action === 'delete_patient_work_plan_task') {
    ensure_action_feature($mysqli, 'tasks.enabled', 'Las tareas no estan disponibles en este plan.');
    $task_id = (int) ($_POST['task_id'] ?? 0);
    $stmt = $mysqli->prepare("SELECT patient_id FROM patient_work_plan_tasks WHERE tenant_id = ? AND id = ? LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $task_id);
    $stmt->execute();
    $task = $stmt->get_result()->fetch_assoc();
    if (!$task || !admin_can_access_patient($mysqli, (int) $task['patient_id'])) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para eliminar esta tarea.']);
        exit;
    }
    $stmt = $mysqli->prepare("DELETE FROM patient_work_plan_tasks WHERE tenant_id = ? AND id = ?");
    $stmt->bind_param("ii", $tenant_id, $task_id);
    $stmt->execute();
    echo json_encode(['success' => true, 'message' => 'Tarea eliminada correctamente.']);
} elseif ($action === 'work_plan_task_templates') {
    ensure_action_feature($mysqli, 'taskTemplates.enabled', 'Las plantillas de tareas no estan disponibles en este plan.');
    ensure_work_plan_task_template_tables($mysqli);
    $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
    $current_professional_id = current_professional_id_for_user($mysqli, $session_user_id);
    if ($is_superadmin) {
        $stmt = $mysqli->prepare("
            SELECT t.id, t.professional_id, t.category, t.title, t.description, t.priority, t.is_global, t.is_active,
                   t.created_at, t.updated_at, p.display_name AS professional_name,
                   (SELECT COUNT(*) FROM work_plan_task_template_items i WHERE i.tenant_id = t.tenant_id AND i.template_id = t.id) AS item_count
            FROM work_plan_task_templates t
            LEFT JOIN professionals p ON p.id = t.professional_id AND p.tenant_id = t.tenant_id
            WHERE t.tenant_id = ?
            ORDER BY COALESCE(NULLIF(t.category, ''), 'Sin categoria') ASC, t.title ASC
        ");
        $stmt->bind_param("i", $tenant_id);
    } else {
        $stmt = $mysqli->prepare("
            SELECT t.id, t.professional_id, t.category, t.title, t.description, t.priority, t.is_global, t.is_active,
                   t.created_at, t.updated_at, p.display_name AS professional_name,
                   (SELECT COUNT(*) FROM work_plan_task_template_items i WHERE i.tenant_id = t.tenant_id AND i.template_id = t.id) AS item_count
            FROM work_plan_task_templates t
            LEFT JOIN professionals p ON p.id = t.professional_id AND p.tenant_id = t.tenant_id
            WHERE t.tenant_id = ?
              AND t.is_active = 1
              AND (t.is_global = 1 OR t.professional_id = ?)
            ORDER BY COALESCE(NULLIF(t.category, ''), 'Sin categoria') ASC, t.title ASC
        ");
        $stmt->bind_param("ii", $tenant_id, $current_professional_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $templates = [];
    while ($row = $res->fetch_assoc()) {
        $templates[] = [
            'id' => (int) $row['id'],
            'professional_id' => (int) ($row['professional_id'] ?? 0),
            'professional_name' => $row['professional_name'] ?? '',
            'category' => $row['category'] ?? '',
            'title' => $row['title'] ?? '',
            'description' => $row['description'] ?? '',
            'priority' => (int) ($row['priority'] ?? 2),
            'is_global' => (int) ($row['is_global'] ?? 0),
            'is_active' => (int) ($row['is_active'] ?? 1),
            'item_count' => (int) ($row['item_count'] ?? 0),
            'created_at' => $row['created_at'] ?? '',
            'updated_at' => $row['updated_at'] ?? '',
            'items' => []
        ];
    }
    if ($templates) {
        $ids = array_map(fn($template) => (int) $template['id'], $templates);
        $ids_sql = implode(',', $ids);
        $items_res = $mysqli->query("
            SELECT id, template_id, title, description, priority, fitness_exercise_id, sort_order
            FROM work_plan_task_template_items
            WHERE tenant_id = $tenant_id AND template_id IN ($ids_sql)
            ORDER BY template_id ASC, sort_order ASC, id ASC
        ");
        $items_by_template = [];
        while ($item = $items_res->fetch_assoc()) {
            $items_by_template[(int) $item['template_id']][] = [
                'id' => (int) $item['id'],
                'template_id' => (int) $item['template_id'],
                'title' => $item['title'] ?? '',
                'description' => $item['description'] ?? '',
                'priority' => (int) ($item['priority'] ?? 2),
                'fitness_exercise_id' => $item['fitness_exercise_id'] ?? '',
                'sort_order' => (int) ($item['sort_order'] ?? 0)
            ];
        }
        foreach ($templates as &$template) {
            $template['items'] = $items_by_template[(int) $template['id']] ?? [];
        }
        unset($template);
    }
    echo json_encode(['success' => true, 'templates' => $templates, 'can_manage_global' => $is_superadmin ? 1 : 0]);
} elseif ($action === 'save_work_plan_task_template') {
    ensure_action_feature($mysqli, 'taskTemplates.enabled', 'Las plantillas de tareas no estan disponibles en este plan.');
    ensure_work_plan_task_template_tables($mysqli);
    $template_id = (int) ($_POST['template_id'] ?? 0);
    $category = trim($_POST['category'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = 2;
    $is_global = ($is_superadmin && ($_POST['is_global'] ?? '') === '1') ? 1 : 0;
    $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
    $professional_id = $is_global ? null : current_professional_id_for_user($mysqli, $session_user_id);

    if ($title === '') {
        echo json_encode(['success' => false, 'error' => 'Indica un titulo para la plantilla.']);
        exit;
    }
    if (!in_array($priority, [1, 2, 3], true)) {
        $priority = 2;
    }
    if ($category === '') {
        $category = null;
    }
    if (!$is_global && (!$professional_id || $professional_id <= 0)) {
        echo json_encode(['success' => false, 'error' => 'No se ha podido identificar el profesional.']);
        exit;
    }

    if ($template_id > 0) {
        $stmt = $mysqli->prepare("SELECT professional_id, is_global FROM work_plan_task_templates WHERE tenant_id = ? AND id = ? LIMIT 1");
        $stmt->bind_param("ii", $tenant_id, $template_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        if (!$existing) {
            echo json_encode(['success' => false, 'error' => 'No se encontro la plantilla.']);
            exit;
        }
        $existing_professional_id = (int) ($existing['professional_id'] ?? 0);
        if (!$is_superadmin && $existing_professional_id !== (int) $professional_id) {
            echo json_encode(['success' => false, 'error' => 'No autorizado para editar esta plantilla.']);
            exit;
        }
        if ($is_global) {
            $stmt = $mysqli->prepare("
                UPDATE work_plan_task_templates
                SET professional_id = NULL, category = ?, title = ?, description = ?, priority = ?, is_global = 1, is_active = 1
                WHERE tenant_id = ? AND id = ?
            ");
            $stmt->bind_param("sssiii", $category, $title, $description, $priority, $tenant_id, $template_id);
        } else {
            $stmt = $mysqli->prepare("
                UPDATE work_plan_task_templates
                SET professional_id = ?, category = ?, title = ?, description = ?, priority = ?, is_global = 0, is_active = 1
                WHERE tenant_id = ? AND id = ?
            ");
            $stmt->bind_param("isssiii", $professional_id, $category, $title, $description, $priority, $tenant_id, $template_id);
        }
        $stmt->execute();
    } else {
        if ($is_global) {
            $stmt = $mysqli->prepare("
                INSERT INTO work_plan_task_templates (tenant_id, professional_id, category, title, description, priority, is_global, is_active, created_by)
                VALUES (?, NULL, ?, ?, ?, ?, 1, 1, ?)
            ");
            $stmt->bind_param("isssii", $tenant_id, $category, $title, $description, $priority, $session_user_id);
        } else {
            $stmt = $mysqli->prepare("
                INSERT INTO work_plan_task_templates (tenant_id, professional_id, category, title, description, priority, is_global, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?, 0, 1, ?)
            ");
            $stmt->bind_param("iisssii", $tenant_id, $professional_id, $category, $title, $description, $priority, $session_user_id);
        }
        $stmt->execute();
        $template_id = $mysqli->insert_id;
    }
    echo json_encode(['success' => true, 'message' => 'Plantilla guardada correctamente.', 'template_id' => $template_id]);
} elseif ($action === 'save_work_plan_task_template_item') {
    ensure_action_feature($mysqli, 'taskTemplates.enabled', 'Las plantillas de tareas no estan disponibles en este plan.');
    ensure_work_plan_task_template_tables($mysqli);
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $template_id = (int) ($_POST['template_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = (int) ($_POST['priority'] ?? 2);
    $fitness_exercise_id = trim((string) ($_POST['fitness_exercise_id'] ?? ''));
    $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
    $current_professional_id = current_professional_id_for_user($mysqli, $session_user_id);

    if ($title === '') {
        echo json_encode(['success' => false, 'error' => 'Indica un titulo para la tarea.']);
        exit;
    }
    if (!in_array($priority, [1, 2, 3], true)) {
        $priority = 2;
    }

    $stmt = $mysqli->prepare("SELECT professional_id FROM work_plan_task_templates WHERE tenant_id = ? AND id = ? LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $template_id);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    if (!$template) {
        echo json_encode(['success' => false, 'error' => 'No se encontro la plantilla.']);
        exit;
    }
    if (!$is_superadmin && (int) ($template['professional_id'] ?? 0) !== (int) $current_professional_id) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para editar esta plantilla.']);
        exit;
    }

    if ($item_id > 0) {
        $stmt = $mysqli->prepare("UPDATE work_plan_task_template_items SET title = ?, description = ?, priority = ?, fitness_exercise_id = NULLIF(?, '') WHERE tenant_id = ? AND id = ? AND template_id = ?");
        $stmt->bind_param("ssisiii", $title, $description, $priority, $fitness_exercise_id, $tenant_id, $item_id, $template_id);
        $stmt->execute();
    } else {
        $sort_order = 0;
        $stmt = $mysqli->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 AS next_sort FROM work_plan_task_template_items WHERE tenant_id = ? AND template_id = ?");
        $stmt->bind_param("ii", $tenant_id, $template_id);
        $stmt->execute();
        $sort_row = $stmt->get_result()->fetch_assoc();
        $sort_order = (int) ($sort_row['next_sort'] ?? 10);
        $stmt = $mysqli->prepare("INSERT INTO work_plan_task_template_items (tenant_id, template_id, title, description, priority, fitness_exercise_id, sort_order) VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), ?)");
        $stmt->bind_param("iissisi", $tenant_id, $template_id, $title, $description, $priority, $fitness_exercise_id, $sort_order);
        $stmt->execute();
        $item_id = $mysqli->insert_id;
    }
    echo json_encode(['success' => true, 'message' => 'Tarea de plantilla guardada correctamente.', 'item_id' => $item_id]);
} elseif ($action === 'delete_work_plan_task_template_item') {
    ensure_action_feature($mysqli, 'taskTemplates.enabled', 'Las plantillas de tareas no estan disponibles en este plan.');
    ensure_work_plan_task_template_tables($mysqli);
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
    $current_professional_id = current_professional_id_for_user($mysqli, $session_user_id);
    $stmt = $mysqli->prepare("
        SELECT i.template_id, t.professional_id
        FROM work_plan_task_template_items i
        INNER JOIN work_plan_task_templates t ON t.id = i.template_id AND t.tenant_id = i.tenant_id
        WHERE i.tenant_id = ?
          AND i.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $item_id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    if (!$item) {
        echo json_encode(['success' => false, 'error' => 'No se encontro la tarea de plantilla.']);
        exit;
    }
    if (!$is_superadmin && (int) ($item['professional_id'] ?? 0) !== (int) $current_professional_id) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para editar esta plantilla.']);
        exit;
    }
    $stmt = $mysqli->prepare("DELETE FROM work_plan_task_template_items WHERE tenant_id = ? AND id = ?");
    $stmt->bind_param("ii", $tenant_id, $item_id);
    $stmt->execute();
    echo json_encode(['success' => true, 'message' => 'Tarea de plantilla eliminada correctamente.']);
} elseif ($action === 'import_work_plan_task_template') {
    ensure_action_feature($mysqli, 'tasks.enabled', 'Las tareas no estan disponibles en este plan.');
    ensure_action_feature($mysqli, 'taskTemplates.enabled', 'Las plantillas de tareas no estan disponibles en este plan.');
    ensure_work_plan_task_template_tables($mysqli);
    $template_id = (int) ($_POST['template_id'] ?? 0);
    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para importar tareas a este paciente.']);
        exit;
    }
    $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
    $current_professional_id = current_professional_id_for_user($mysqli, $session_user_id);
    $stmt = $mysqli->prepare("SELECT professional_id, is_global FROM work_plan_task_templates WHERE tenant_id = ? AND id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $template_id);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    if (!$template || (!$is_superadmin && (int) ($template['is_global'] ?? 0) !== 1 && (int) ($template['professional_id'] ?? 0) !== (int) $current_professional_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para usar esta plantilla.']);
        exit;
    }
    $appointment_id_db = null;
    $professional_id = $current_professional_id > 0 ? $current_professional_id : null;
    $visible_to_patient_default = 0;
    $settings_res = $mysqli->query("SELECT patient_tasks_visible_default FROM payment_settings WHERE tenant_id = $tenant_id");
    if ($settings_res && ($settings_row = $settings_res->fetch_assoc())) {
        $visible_to_patient_default = (int) ($settings_row['patient_tasks_visible_default'] ?? 0) === 1 ? 1 : 0;
    }
    if ($appointment_id > 0) {
        $appointment_manage_result = admin_can_manage_appointment_payment($mysqli, $appointment_id);
        if (!$appointment_manage_result[0] || (int) ($appointment_manage_result[1]['user_id'] ?? 0) !== $patient_id) {
            echo json_encode(['success' => false, 'error' => 'No autorizado para vincular esa cita.']);
            exit;
        }
        $appointment_id_db = $appointment_id;
        if (!empty($appointment_manage_result[1]['professional_id'])) {
            $professional_id = (int) $appointment_manage_result[1]['professional_id'];
        }
    }
    $stmt = $mysqli->prepare("SELECT title, description, priority, fitness_exercise_id FROM work_plan_task_template_items WHERE tenant_id = ? AND template_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->bind_param("ii", $tenant_id, $template_id);
    $stmt->execute();
    $items_res = $stmt->get_result();
    $insert = $mysqli->prepare("
        INSERT INTO patient_work_plan_tasks (tenant_id, patient_id, appointment_id, professional_id, title, description, status, priority, visible_to_patient, fitness_exercise_id, created_by)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, NULLIF(?, ''), ?)
    ");
    $inserted = 0;
    while ($item = $items_res->fetch_assoc()) {
        $title = $item['title'] ?? '';
        if ($title === '') {
            continue;
        }
        $description = $item['description'] ?? '';
        $priority = (int) ($item['priority'] ?? 2);
        $fitness_exercise_id = $item['fitness_exercise_id'] ?? '';
        $insert->bind_param("iiiissiisi", $tenant_id, $patient_id, $appointment_id_db, $professional_id, $title, $description, $priority, $visible_to_patient_default, $fitness_exercise_id, $session_user_id);
        $insert->execute();
        $inserted++;
    }
    if ($inserted === 0) {
        echo json_encode(['success' => false, 'error' => 'La plantilla no tiene tareas para importar.']);
        exit;
    }
    echo json_encode(['success' => true, 'message' => "Se han importado $inserted tareas.", 'inserted' => $inserted]);
} elseif ($action === 'delete_work_plan_task_template') {
    ensure_action_feature($mysqli, 'taskTemplates.enabled', 'Las plantillas de tareas no estan disponibles en este plan.');
    ensure_work_plan_task_template_tables($mysqli);
    $template_id = (int) ($_POST['template_id'] ?? 0);
    $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
    $current_professional_id = current_professional_id_for_user($mysqli, $session_user_id);
    $stmt = $mysqli->prepare("SELECT professional_id FROM work_plan_task_templates WHERE tenant_id = ? AND id = ? LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $template_id);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    if (!$template) {
        echo json_encode(['success' => false, 'error' => 'No se encontro la plantilla.']);
        exit;
    }
    if (!$is_superadmin && (int) ($template['professional_id'] ?? 0) !== (int) $current_professional_id) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para eliminar esta plantilla.']);
        exit;
    }
    $stmt = $mysqli->prepare("DELETE FROM work_plan_task_template_items WHERE tenant_id = ? AND template_id = ?");
    $stmt->bind_param("ii", $tenant_id, $template_id);
    $stmt->execute();
    $stmt = $mysqli->prepare("DELETE FROM work_plan_task_templates WHERE tenant_id = ? AND id = ?");
    $stmt->bind_param("ii", $tenant_id, $template_id);
    $stmt->execute();
    echo json_encode(['success' => true, 'message' => 'Plantilla eliminada correctamente.']);
} elseif ($action === 'patient_appointments') {
    ensure_appointment_payment_columns($mysqli);
    ensure_appointment_services_tables($mysqli);
    ensure_cabinet_schema($mysqli);
    $patient_id = (int) ($_GET['patient_id'] ?? 0);
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para ver este historial.']);
        exit;
    }

    $branding = get_public_branding_settings($mysqli);
    $dashboard_photo = $branding['profile_image_path'] ?? '';
    $stmt = $mysqli->prepare("
        SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.consultation_type, a.service_type,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.patient_bonus_id, a.paid_at, a.created_at,
               s.name AS service_name,
               p.id AS professional_id, p.display_name AS professional_name, p.public_photo_path AS professional_photo_path,
               pu.role AS professional_user_role
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        LEFT JOIN professionals p ON p.id = a.professional_id AND p.tenant_id = a.tenant_id
        LEFT JOIN users pu ON pu.id = p.user_id AND pu.tenant_id = p.tenant_id
        WHERE a.tenant_id = ?
          AND a.user_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC, a.id DESC
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $appointments = [];
    while ($row = $res->fetch_assoc()) {
        $row['professional_photo_path'] = professional_photo_with_dashboard_fallback($row, $dashboard_photo);
        $appointments[] = [
            'id' => (int) $row['id'],
            'appointment_date' => $row['appointment_date'],
            'appointment_time' => substr((string) $row['appointment_time'], 0, 5),
            'status' => $row['status'] ?? '',
            'consultation_type' => $row['consultation_type'] ?? 'presencial',
            'duration_minutes' => (int) ($row['duration_minutes'] ?? 60),
            'service_label' => appointment_service_option_label($row),
            'payment_status' => $row['payment_status'] ?? 'pending',
            'payment_method' => $row['payment_method'] ?? '',
            'patient_bonus_id' => $row['patient_bonus_id'],
            'paid_at' => $row['paid_at'],
            'created_at' => $row['created_at'],
            'professional_id' => (int) ($row['professional_id'] ?? 0),
            'professional_name' => $row['professional_name'] ?? '',
            'professional_photo_path' => $row['professional_photo_path'] ?? ''
        ];
    }
    echo json_encode(['success' => true, 'appointments' => $appointments]);
} elseif ($action === 'save_patient') {
    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    if ($patient_id <= 0) {
        require_member_permission('create_patients', 'No tienes permiso para crear nuevos pacientes.');
    }
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $patient_type = trim($_POST['patient_type'] ?? '');
    $fiscal_name = trim($_POST['fiscal_name'] ?? '');
    $fiscal_nif = strtoupper(trim($_POST['fiscal_nif'] ?? ''));
    $invoice_use_alt_data = isset($_POST['invoice_use_alt_data']) && $_POST['invoice_use_alt_data'] === '1' ? 1 : 0;
    $invoice_name = trim($_POST['invoice_name'] ?? '');
    $invoice_nif = strtoupper(trim($_POST['invoice_nif'] ?? ''));
    $invoice_email = trim($_POST['invoice_email'] ?? '');
    $invoice_phone = trim($_POST['invoice_phone'] ?? '');
    $invoice_address = trim($_POST['invoice_address'] ?? '');
    $patient_status = trim($_POST['patient_status'] ?? 'active');
    $waiting_list = isset($_POST['waiting_list']) && $_POST['waiting_list'] === '1' ? 1 : 0;
    $birth_date = trim($_POST['birth_date'] ?? '');
    $referral_source = trim($_POST['referral_source'] ?? '');
    $knowledge_problem_was_posted = array_key_exists('knowledge_problem_id', $_POST);
    $knowledge_problem_id = max(0, (int) ($_POST['knowledge_problem_id'] ?? 0));
    $initial_consultation_reason = trim($_POST['initial_consultation_reason'] ?? '');
    $background_notes = trim($_POST['background_notes'] ?? '');
    $support_network_notes = trim($_POST['support_network_notes'] ?? '');
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');
    $emergency_contact_relation = trim($_POST['emergency_contact_relation'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $admission_date = trim($_POST['admission_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $physical_sex = trim((string) ($_POST['physical_sex'] ?? ''));
    $weight_kg = nullable_decimal_value($_POST['weight_kg'] ?? '', 500);
    $height_cm = nullable_decimal_value($_POST['height_cm'] ?? '', 280);
    $body_fat_percentage = nullable_decimal_value($_POST['body_fat_percentage'] ?? '', 80);
    $waist_cm = nullable_decimal_value($_POST['waist_cm'] ?? '', 300);
    $hip_cm = nullable_decimal_value($_POST['hip_cm'] ?? '', 300);
    $chest_cm = nullable_decimal_value($_POST['chest_cm'] ?? '', 300);
    $thigh_cm = nullable_decimal_value($_POST['thigh_cm'] ?? '', 200);
    $biceps_cm = nullable_decimal_value($_POST['biceps_cm'] ?? '', 120);
    $calf_cm = nullable_decimal_value($_POST['calf_cm'] ?? '', 120);
    $skinfold_triceps_mm = nullable_decimal_value($_POST['skinfold_triceps_mm'] ?? '', 120);
    $skinfold_subscapular_mm = nullable_decimal_value($_POST['skinfold_subscapular_mm'] ?? '', 120);
    $skinfold_suprailiac_mm = nullable_decimal_value($_POST['skinfold_suprailiac_mm'] ?? '', 120);
    $skinfold_abdominal_mm = nullable_decimal_value($_POST['skinfold_abdominal_mm'] ?? '', 120);
    $skinfold_chest_mm = nullable_decimal_value($_POST['skinfold_chest_mm'] ?? '', 120);
    $skinfold_thigh_mm = nullable_decimal_value($_POST['skinfold_thigh_mm'] ?? '', 120);
    $physical_metrics = [
        'weight_kg' => $weight_kg,
        'height_cm' => $height_cm,
        'body_fat_percentage' => $body_fat_percentage,
        'waist_cm' => $waist_cm,
        'hip_cm' => $hip_cm,
        'chest_cm' => $chest_cm,
        'thigh_cm' => $thigh_cm,
        'biceps_cm' => $biceps_cm,
        'calf_cm' => $calf_cm,
        'skinfold_triceps_mm' => $skinfold_triceps_mm,
        'skinfold_subscapular_mm' => $skinfold_subscapular_mm,
        'skinfold_suprailiac_mm' => $skinfold_suprailiac_mm,
        'skinfold_abdominal_mm' => $skinfold_abdominal_mm,
        'skinfold_chest_mm' => $skinfold_chest_mm,
        'skinfold_thigh_mm' => $skinfold_thigh_mm
    ];
    $professional_was_posted = array_key_exists('professional_id', $_POST);
    if ($is_superadmin && $professional_was_posted) {
        $selected_professional_id = max(0, (int) ($_POST['professional_id'] ?? 0));
    } else {
        $selected_professional_id = admin_requested_professional_filter($mysqli);
        if ($selected_professional_id <= 0) {
            $selected_professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
        }
    }
    if ($patient_id > 0 && !$professional_was_posted) {
        $selected_professional_id = cabinet_patient_primary_professional_id($mysqli, $patient_id);
    }

    $email = $email !== '' ? $email : null;
    $phone = $phone !== '' ? $phone : null;
    $patient_type = $patient_type !== '' ? $patient_type : null;
    $fiscal_name = $fiscal_name !== '' ? $fiscal_name : null;
    $fiscal_nif = $fiscal_nif !== '' ? $fiscal_nif : null;
    if (!$invoice_use_alt_data) {
        $invoice_name = '';
        $invoice_nif = '';
        $invoice_email = '';
        $invoice_phone = '';
        $invoice_address = '';
    }
    $invoice_name = $invoice_name !== '' ? $invoice_name : null;
    $invoice_nif = $invoice_nif !== '' ? $invoice_nif : null;
    $invoice_email = $invoice_email !== '' ? $invoice_email : null;
    $invoice_phone = $invoice_phone !== '' ? $invoice_phone : null;
    $invoice_address = $invoice_address !== '' ? $invoice_address : null;
    if (!in_array($patient_status, ['active', 'paused', 'discharged', 'inactive'], true)) {
        $patient_status = 'active';
    }
    $birth_date = $birth_date !== '' ? $birth_date : null;
    $referral_source = $referral_source !== '' ? $referral_source : null;
    $knowledge_base_available = app_feature_enabled_from_db($mysqli, 'knowledgeBase.enabled', false);
    if (!$knowledge_base_available) {
        $knowledge_problem_was_posted = false;
    }
    if (!$knowledge_problem_was_posted && $patient_id > 0) {
        $stmt = $mysqli->prepare("SELECT knowledge_problem_id FROM patient_profiles WHERE tenant_id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param("ii", $tenant_id, $patient_id);
        $stmt->execute();
        $existing_knowledge = $stmt->get_result()->fetch_assoc();
        $knowledge_problem_id = !empty($existing_knowledge['knowledge_problem_id']) ? (int) $existing_knowledge['knowledge_problem_id'] : 0;
    } elseif (!$knowledge_base_available) {
        $knowledge_problem_id = 0;
    }
    if ($knowledge_problem_id > 0) {
        $sector_sql = knowledge_sector_in_sql($mysqli, allowed_knowledge_sector_keys($mysqli));
        $stmt = $mysqli->prepare("SELECT id FROM knowledge_problems WHERE id = ? AND sector_key IN ($sector_sql) LIMIT 1");
        $stmt->bind_param("i", $knowledge_problem_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'El problema o diagnostico seleccionado no existe.']);
            exit;
        }
    } else {
        $knowledge_problem_id = null;
    }
    $initial_consultation_reason = $initial_consultation_reason !== '' ? $initial_consultation_reason : null;
    $background_notes = $background_notes !== '' ? $background_notes : null;
    $support_network_notes = $support_network_notes !== '' ? $support_network_notes : null;
    $emergency_contact_name = $emergency_contact_name !== '' ? $emergency_contact_name : null;
    $emergency_contact_phone = $emergency_contact_phone !== '' ? $emergency_contact_phone : null;
    $emergency_contact_relation = $emergency_contact_relation !== '' ? $emergency_contact_relation : null;
    $address = $address !== '' ? $address : null;
    $admission_date = $admission_date !== '' ? $admission_date : date('Y-m-d');
    if (!in_array($physical_sex, ['male', 'female'], true)) {
        $physical_sex = null;
    }

    if ($name === '') {
        echo json_encode(['success' => false, 'error' => 'Indica el nombre del paciente.']);
        exit;
    }
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Email no valido.']);
        exit;
    }
    if ($invoice_email !== null && !filter_var($invoice_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Email de facturacion no valido.']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $admission_date)) {
        echo json_encode(['success' => false, 'error' => 'Fecha de alta no valida.']);
        exit;
    }
    if ($birth_date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
        echo json_encode(['success' => false, 'error' => 'Fecha de nacimiento no valida.']);
        exit;
    }

    if ($email !== null) {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE tenant_id = ? AND email = ? AND id <> ?");
        $stmt->bind_param("isi", $tenant_id, $email, $patient_id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'Ya existe otro paciente con ese email.']);
            exit;
        }
    }

    if ($phone !== null) {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE tenant_id = ? AND phone = ? AND id <> ?");
        $stmt->bind_param("isi", $tenant_id, $phone, $patient_id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'Ya existe otro paciente con ese telefono.']);
            exit;
        }
    }

    $creating_patient = $patient_id <= 0;
    $password_setup_users = [];
    $mysqli->begin_transaction();
    $password_setup_users = [];
    try {
        $previous_physical_metrics = null;
        $previous_waiting_list = null;
        $previous_patient_name = '';
        if (!$creating_patient) {
            $stmt = $mysqli->prepare("
                SELECT physical_sex, weight_kg, height_cm, body_fat_percentage, waist_cm, hip_cm, chest_cm, thigh_cm, biceps_cm, calf_cm,
                       skinfold_triceps_mm, skinfold_subscapular_mm, skinfold_suprailiac_mm,
                       skinfold_abdominal_mm, skinfold_chest_mm, skinfold_thigh_mm,
                       waiting_list
                FROM patient_profiles
                WHERE tenant_id = ? AND user_id = ?
                LIMIT 1
            ");
            $stmt->bind_param("ii", $tenant_id, $patient_id);
            $stmt->execute();
            $previous_physical_metrics = $stmt->get_result()->fetch_assoc();
            $previous_waiting_list = isset($previous_physical_metrics['waiting_list']) ? (int) $previous_physical_metrics['waiting_list'] : 0;
            $stmt = $mysqli->prepare("SELECT name FROM users WHERE tenant_id = ? AND id = ? AND role = 'patient' LIMIT 1");
            $stmt->bind_param("ii", $tenant_id, $patient_id);
            $stmt->execute();
            $previous_user = $stmt->get_result()->fetch_assoc();
            $previous_patient_name = $previous_user['name'] ?? '';
        }

        if ($patient_id > 0) {
            $stmt = $mysqli->prepare("UPDATE users SET name = ?, email = ?, phone = ? WHERE tenant_id = ? AND id = ? AND role = 'patient'");
            $stmt->bind_param("sssii", $name, $email, $phone, $tenant_id, $patient_id);
            $stmt->execute();
            if ($stmt->affected_rows < 0) {
                throw new \Exception('No se pudo actualizar el paciente.');
            }
        } else {
            $stmt = $mysqli->prepare("INSERT INTO users (tenant_id, name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, NULL, 'patient')");
            $stmt->bind_param("isss", $tenant_id, $name, $email, $phone);
            $stmt->execute();
            $patient_id = $mysqli->insert_id;
        }

        $uploaded_photo_path = save_patient_photo_upload($_FILES['patient_photo'] ?? null, $patient_id);
        $uploaded_document = save_patient_document_upload($_FILES['patient_document'] ?? null, $patient_id);

        $profile_professional_id = $selected_professional_id > 0 ? $selected_professional_id : null;
        $stmt = $mysqli->prepare("
            INSERT INTO patient_profiles (
                tenant_id, user_id, professional_id, patient_type, fiscal_name, fiscal_nif, patient_status, waiting_list, birth_date, referral_source, knowledge_problem_id,
                invoice_use_alt_data, invoice_name, invoice_nif, invoice_email, invoice_phone, invoice_address,
                initial_consultation_reason, background_notes, support_network_notes, emergency_contact_name, emergency_contact_phone, emergency_contact_relation, address,
                admission_date, notes, created_by_admin
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                professional_id = VALUES(professional_id),
                patient_type = VALUES(patient_type),
                fiscal_name = VALUES(fiscal_name),
                fiscal_nif = VALUES(fiscal_nif),
                invoice_use_alt_data = VALUES(invoice_use_alt_data),
                invoice_name = VALUES(invoice_name),
                invoice_nif = VALUES(invoice_nif),
                invoice_email = VALUES(invoice_email),
                invoice_phone = VALUES(invoice_phone),
                invoice_address = VALUES(invoice_address),
                patient_status = VALUES(patient_status),
                waiting_list = VALUES(waiting_list),
                birth_date = VALUES(birth_date),
                referral_source = VALUES(referral_source),
                knowledge_problem_id = VALUES(knowledge_problem_id),
                initial_consultation_reason = VALUES(initial_consultation_reason),
                background_notes = VALUES(background_notes),
                support_network_notes = VALUES(support_network_notes),
                emergency_contact_name = VALUES(emergency_contact_name),
                emergency_contact_phone = VALUES(emergency_contact_phone),
                emergency_contact_relation = VALUES(emergency_contact_relation),
                address = VALUES(address),
                admission_date = VALUES(admission_date),
                notes = VALUES(notes)
        ");
        $stmt->bind_param(
            "iiissssissiissssssssssssss",
            $tenant_id,
            $patient_id,
            $profile_professional_id,
            $patient_type,
            $fiscal_name,
            $fiscal_nif,
            $patient_status,
            $waiting_list,
            $birth_date,
            $referral_source,
            $knowledge_problem_id,
            $invoice_use_alt_data,
            $invoice_name,
            $invoice_nif,
            $invoice_email,
            $invoice_phone,
            $invoice_address,
            $initial_consultation_reason,
            $background_notes,
            $support_network_notes,
            $emergency_contact_name,
            $emergency_contact_phone,
            $emergency_contact_relation,
            $address,
            $admission_date,
            $notes
        );
        $stmt->execute();
        $stmt = $mysqli->prepare("
            UPDATE patient_profiles
            SET physical_sex = ?,
                weight_kg = ?,
                height_cm = ?,
                body_fat_percentage = ?,
                waist_cm = ?,
                hip_cm = ?,
                chest_cm = ?,
                thigh_cm = ?,
                biceps_cm = ?,
                calf_cm = ?,
                skinfold_triceps_mm = ?,
                skinfold_subscapular_mm = ?,
                skinfold_suprailiac_mm = ?,
                skinfold_abdominal_mm = ?,
                skinfold_chest_mm = ?,
                skinfold_thigh_mm = ?
            WHERE tenant_id = ? AND user_id = ?
        ");
        $stmt->bind_param(
            "ssssssssssssssssii",
            $physical_sex,
            $weight_kg,
            $height_cm,
            $body_fat_percentage,
            $waist_cm,
            $hip_cm,
            $chest_cm,
            $thigh_cm,
            $biceps_cm,
            $calf_cm,
            $skinfold_triceps_mm,
            $skinfold_subscapular_mm,
            $skinfold_suprailiac_mm,
            $skinfold_abdominal_mm,
            $skinfold_chest_mm,
            $skinfold_thigh_mm,
            $tenant_id,
            $patient_id
        );
        $stmt->execute();
        if ($selected_professional_id > 0) {
            $stmt = $mysqli->prepare("UPDATE patient_professionals SET is_primary = 0 WHERE tenant_id = ? AND patient_id = ?");
            $stmt->bind_param("ii", $tenant_id, $patient_id);
            $stmt->execute();
            $stmt = $mysqli->prepare("
                INSERT INTO patient_professionals (tenant_id, patient_id, professional_id, is_primary, notes)
                VALUES (?, ?, ?, 1, 'Asignación desde ficha')
                ON DUPLICATE KEY UPDATE is_primary = 1
            ");
            $stmt->bind_param("iii", $tenant_id, $patient_id, $selected_professional_id);
            $stmt->execute();
        } elseif ($is_superadmin && $professional_was_posted) {
            $stmt = $mysqli->prepare("DELETE FROM patient_professionals WHERE tenant_id = ? AND patient_id = ? AND is_primary = 1");
            $stmt->bind_param("ii", $tenant_id, $patient_id);
            $stmt->execute();
        }

        if ($uploaded_document !== null) {
            $stmt = $mysqli->prepare("UPDATE patient_profiles SET document_path = ?, document_name = ? WHERE tenant_id = ? AND user_id = ?");
            $stmt->bind_param("ssii", $uploaded_document['path'], $uploaded_document['name'], $tenant_id, $patient_id);
            $stmt->execute();
        }
        if ($uploaded_photo_path !== null) {
            $stmt = $mysqli->prepare("UPDATE patient_profiles SET photo_path = ? WHERE tenant_id = ? AND user_id = ?");
            $stmt->bind_param("sii", $uploaded_photo_path, $tenant_id, $patient_id);
            $stmt->execute();
        }
        if ($creating_patient) {
            create_initial_patient_evolution_if_needed($mysqli, $tenant_id, $patient_id, $profile_professional_id, $admission_date, $physical_metrics, (int) ($_SESSION['user_id'] ?? 0));
        } elseif (patient_physical_metrics_changed($previous_physical_metrics, $physical_metrics, $physical_sex)) {
            upsert_today_patient_evolution_from_profile_metrics($mysqli, $tenant_id, $patient_id, $profile_professional_id, $physical_metrics, (int) ($_SESSION['user_id'] ?? 0));
        }
        $mysqli->commit();
        app_log($mysqli, [
            'action' => $creating_patient ? 'patient_created' : 'patient_updated',
            'status' => 'ok',
            'target_type' => 'patient',
            'target_id' => $patient_id,
            'title' => $creating_patient ? 'Paciente creado' : 'Paciente actualizado',
            'message' => ($creating_patient ? 'Paciente creado: ' : 'Paciente actualizado: ') . $name . '.',
            'metadata' => [
                'patient_id' => $patient_id,
                'patient_name' => $name,
                'previous_patient_name' => $previous_patient_name,
                'professional_id' => $profile_professional_id ? (int) $profile_professional_id : 0,
                'patient_status' => $patient_status,
                'waiting_list' => $waiting_list
            ]
        ]);
        if (!$creating_patient && $previous_waiting_list !== null && (int) $previous_waiting_list !== (int) $waiting_list) {
            app_log($mysqli, [
                'action' => 'patient_waiting_list_updated',
                'status' => 'ok',
                'target_type' => 'patient',
                'target_id' => $patient_id,
                'title' => 'Lista de espera actualizada',
                'message' => $name . ($waiting_list ? ' marcado en lista de espera.' : ' eliminado de la lista de espera.'),
                'metadata' => [
                    'patient_id' => $patient_id,
                    'patient_name' => $name,
                    'previous_waiting_list' => (int) $previous_waiting_list,
                    'new_waiting_list' => (int) $waiting_list,
                    'professional_id' => $profile_professional_id ? (int) $profile_professional_id : 0
                ]
            ]);
        }
        echo json_encode(['success' => true, 'message' => 'Paciente guardado correctamente.', 'patient_id' => $patient_id]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'transfer_patient_professional') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede realizar traspasos.']);
        exit;
    }
    ensure_cabinet_schema($mysqli);
    $settings_res = $mysqli->query("SELECT allow_patient_transfer FROM payment_settings WHERE tenant_id = $tenant_id");
    $settings = $settings_res ? $settings_res->fetch_assoc() : ['allow_patient_transfer' => 0];

    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $target_professional_id = (int) ($_POST['professional_id'] ?? 0);
    if ($patient_id <= 0 || $target_professional_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Selecciona paciente y profesional.']);
        exit;
    }
    $stmt = $mysqli->prepare("SELECT id FROM users WHERE tenant_id = ? AND id = ? AND role = 'patient' LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'error' => 'Paciente no encontrado.']);
        exit;
    }
    $stmt = $mysqli->prepare("SELECT id, display_name FROM professionals WHERE tenant_id = ? AND id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $target_professional_id);
    $stmt->execute();
    $target_professional = $stmt->get_result()->fetch_assoc();
    if (!$target_professional) {
        echo json_encode(['success' => false, 'error' => 'Profesional no valido o inactivo.']);
        exit;
    }
    $current_professional_id = cabinet_patient_primary_professional_id($mysqli, $patient_id);
    if ($current_professional_id > 0 && (int) ($settings['allow_patient_transfer'] ?? 0) !== 1) {
        echo json_encode(['success' => false, 'error' => 'El traspaso no esta activado en configuracion.']);
        exit;
    }
    if ($current_professional_id === $target_professional_id) {
        echo json_encode(['success' => false, 'error' => 'El paciente ya esta asignado a ese profesional.']);
        exit;
    }

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("UPDATE patient_professionals SET is_primary = 0 WHERE tenant_id = ? AND patient_id = ?");
        $stmt->bind_param("ii", $tenant_id, $patient_id);
        $stmt->execute();

        $notes = $current_professional_id > 0 ? 'Traspaso manual desde ficha' : 'Asignacion manual desde ficha';
        $stmt = $mysqli->prepare("
            INSERT INTO patient_professionals (tenant_id, patient_id, professional_id, is_primary, assigned_at, transferred_at, notes)
            VALUES (?, ?, ?, 1, NOW(), NOW(), ?)
            ON DUPLICATE KEY UPDATE is_primary = 1, transferred_at = NOW(), notes = VALUES(notes)
        ");
        $stmt->bind_param("iiis", $tenant_id, $patient_id, $target_professional_id, $notes);
        $stmt->execute();

        $stmt = $mysqli->prepare("
            INSERT INTO patient_profiles (tenant_id, user_id, professional_id, created_by_admin)
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE professional_id = VALUES(professional_id)
        ");
        $stmt->bind_param("iii", $tenant_id, $patient_id, $target_professional_id);
        $stmt->execute();

        $stmt = $mysqli->prepare("
            UPDATE appointments
            SET professional_id = ?
            WHERE tenant_id = ?
              AND user_id = ?
              AND status = 'booked'
              AND CONCAT(appointment_date, ' ', appointment_time) >= NOW()
        ");
        $stmt->bind_param("iii", $target_professional_id, $tenant_id, $patient_id);
        $stmt->execute();
        $moved_appointments = $stmt->affected_rows;

        $mysqli->commit();
        echo json_encode([
            'success' => true,
            'message' => $current_professional_id > 0 ? 'Paciente traspasado correctamente.' : 'Profesional asignado correctamente.',
            'professional_id' => $target_professional_id,
            'professional_name' => $target_professional['display_name'] ?? '',
            'moved_appointments' => $moved_appointments
        ]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => 'No se pudo completar el traspaso.']);
    }
} elseif ($action === 'send_patient_invite') {
    if (!app_feature_enabled_from_db($mysqli, 'patientPortal.enabled', false) || !app_feature_enabled_from_db($mysqli, 'patientPortal.invitations', false)) {
        echo json_encode(['success' => false, 'error' => 'Las invitaciones del portal no estan disponibles en este plan.']);
        exit;
    }
    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $stmt = $mysqli->prepare("SELECT id, name, email, password_hash FROM users WHERE tenant_id = ? AND id = ? AND role = 'patient'");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    if (!$patient) {
        echo json_encode(['success' => false, 'error' => 'Paciente no encontrado.']);
        exit;
    }
    if (patient_has_portal_access($patient)) {
        echo json_encode(['success' => false, 'error' => 'Este paciente ya tiene acceso web.']);
        exit;
    }
    if (empty($patient['email']) || !filter_var($patient['email'], FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'El paciente necesita un email para enviar la invitacion.']);
        exit;
    }

    $token = bin2hex(random_bytes(32));
    $stmt = $mysqli->prepare("INSERT INTO invitations (tenant_id, token, user_id) VALUES (?, ?, ?)");
    $stmt->bind_param("isi", $tenant_id, $token, $patient_id);
    $stmt->execute();
    $link = urlme_shorten_url(app_public_base_url() . 'register.php?token=' . urlencode($token), 'Invitacion registro SimplyGest Praxis');

    $sent = send_app_email(
        $patient['email'],
        'Invitacion para crear tu cuenta',
        '<p>Hola ' . htmlspecialchars($patient['name']) . ',</p>' .
        '<p>Te enviamos la invitaci&oacute;n para crear tu cuenta y poder acceder a la web para gestionar tus citas.</p>' .
        '<p><a href="' . htmlspecialchars($link) . '">Crear mi cuenta</a></p>' .
        '<p>Si el boton no funciona, copia y pega este enlace en tu navegador:<br>' . htmlspecialchars($link) . '</p>',
        null,
        $mysqli
    );

    echo json_encode($sent
        ? ['success' => true, 'message' => 'Invitacion enviada correctamente.', 'link' => $link]
        : ['success' => false, 'error' => 'No se pudo enviar el email de invitacion.']);
} elseif ($action === 'send_invite_email') {
    if (!app_feature_enabled_from_db($mysqli, 'patientPortal.enabled', false) || !app_feature_enabled_from_db($mysqli, 'patientPortal.invitations', false)) {
        echo json_encode(['success' => false, 'error' => 'Las invitaciones del portal no estan disponibles en este plan.']);
        exit;
    }
    $email = trim($_POST['email'] ?? '');
    $posted_link = trim($_POST['link'] ?? '');
    $posted_token = trim($_POST['token'] ?? '');
    $parts = parse_url($posted_link);
    parse_str($parts['query'] ?? '', $query);
    $token = $posted_token ?: ($query['token'] ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Indica un email valido.']);
        exit;
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        echo json_encode(['success' => false, 'error' => 'El enlace de invitacion no es valido.']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT id FROM invitations WHERE token = ? AND used = 0");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'error' => 'La invitacion no existe o ya fue usada.']);
        exit;
    }

    $link = urlme_shorten_url(app_public_base_url() . 'register.php?token=' . urlencode($token), 'Invitacion registro SimplyGest Praxis');
    $sent = send_app_email(
        $email,
        'Invitacion para crear tu cuenta',
        '<p>Hola,</p>' .
        '<p>Te enviamos la invitaci&oacute;n para crear tu cuenta y poder acceder a la web para gestionar tus citas.</p>' .
        '<p><a href="' . htmlspecialchars($link) . '">Crear mi cuenta</a></p>' .
        '<p>Si el boton no funciona, copia y pega este enlace en tu navegador:<br>' . htmlspecialchars($link) . '</p>',
        null,
        $mysqli
    );

    echo json_encode($sent
        ? ['success' => true, 'message' => 'Invitacion enviada correctamente.']
        : ['success' => false, 'error' => 'No se pudo enviar el email de invitacion.']);
} elseif ($action === 'quick_appointments') {
    ensure_appointment_payment_columns($mysqli);
    ensure_appointment_services_tables($mysqli);
    ensure_cabinet_schema($mysqli);
    $branding = get_public_branding_settings($mysqli);
    $dashboard_photo = $branding['profile_image_path'] ?? '';
    $current_professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
    $professional_filter = $current_professional_id > 0
        ? " AND a.professional_id = " . (int) $current_professional_id
        : " AND 1 = 0";

    $base_select = "
        SELECT a.id, a.user_id, a.professional_id, a.appointment_date, a.appointment_time,
               a.consultation_type, a.service_type, a.online_session_url,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.patient_bonus_id,
               s.name AS service_name,
               u.name, u.email, u.phone,
               p.display_name AS professional_name, p.public_photo_path AS professional_photo_path,
               pu.role AS professional_user_role
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
        LEFT JOIN professionals p ON p.id = a.professional_id AND p.tenant_id = a.tenant_id
        LEFT JOIN users pu ON pu.id = p.user_id AND pu.tenant_id = p.tenant_id
        WHERE a.tenant_id = $tenant_id
          AND a.status = 'booked'
          $professional_filter
    ";
    $today_sql = $mysqli->real_escape_string(date('Y-m-d'));
    $now_time_sql = $mysqli->real_escape_string(date('H:i:s'));

    $current_res = $mysqli->query("
        $base_select
          AND a.appointment_date = '$today_sql'
          AND a.appointment_time <= '$now_time_sql'
          AND ADDTIME(a.appointment_time, SEC_TO_TIME(COALESCE(a.duration_minutes, so.duration_minutes, 60) * 60)) > '$now_time_sql'
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
        LIMIT 1
    ");
    $current = $current_res ? $current_res->fetch_assoc() : null;
    $exclude_current = $current ? " AND a.id <> " . (int) $current['id'] : '';
    $next_res = $mysqli->query("
        $base_select
          AND (a.appointment_date > '$today_sql' OR (a.appointment_date = '$today_sql' AND a.appointment_time > '$now_time_sql'))
          $exclude_current
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
        LIMIT 1
    ");
    $next = $next_res ? $next_res->fetch_assoc() : null;
    $waiting_list_payload = ($is_superadmin || !empty($member_permissions['patients']))
        ? dashboard_waiting_list_summary_payload($mysqli, $current_professional_id)
        : ['count' => 0, 'has_slot' => false];

    echo json_encode([
        'success' => true,
        'current' => quick_appointment_payload($current, $dashboard_photo),
        'next' => quick_appointment_payload($next, $dashboard_photo),
        'waiting_list' => $waiting_list_payload,
        'current_professional_id' => $current_professional_id
    ]);
} elseif ($action === 'upcoming_appointments') {
    ensure_appointment_payment_columns($mysqli);
    ensure_appointment_services_tables($mysqli);
    ensure_cabinet_schema($mysqli);
    $branding = get_public_branding_settings($mysqli);
    $dashboard_photo = $branding['profile_image_path'] ?? '';
    $scope = $_GET['scope'] ?? 'limit10';
    $planning_scope = $_GET['planning_scope'] ?? '3days';
    $requested_professional_raw = $_GET['professional_id'] ?? '';
    $requested_professional_id = (int) $requested_professional_raw;
    $where_extra = '';
    $professional_filter = '';
    $limit_sql = 'LIMIT 10';
    if ($scope === '3days') {
        $where_extra = " AND a.appointment_date < DATE_ADD(CURDATE(), INTERVAL 3 DAY)";
        $limit_sql = '';
    } elseif ($scope === '7days') {
        $where_extra = " AND a.appointment_date < DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        $limit_sql = '';
    } elseif ($scope === '14days') {
        $where_extra = " AND a.appointment_date < DATE_ADD(CURDATE(), INTERVAL 14 DAY)";
        $limit_sql = '';
    } elseif ($scope === 'all') {
        $limit_sql = '';
    }

    $current_professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
    if ($is_superadmin) {
        if ($requested_professional_raw === 'current') {
            $requested_professional_id = $current_professional_id;
        }
        if ($requested_professional_raw !== 'all' && $requested_professional_id > 0) {
            $professional_filter = " AND a.professional_id = " . $requested_professional_id;
        }
    } elseif (!$is_superadmin) {
        $professional_filter = $current_professional_id > 0 ? " AND a.professional_id = " . $current_professional_id : " AND 1 = 0";
    }

    $res = $mysqli->query("
        SELECT a.id, a.appointment_date, a.appointment_time, a.consultation_type, a.service_type, a.online_session_url,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               s.name AS service_name,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.patient_bonus_id,
               u.name, u.email, u.phone,
               p.id AS professional_id, p.display_name AS professional_name, p.public_photo_path AS professional_photo_path,
               pu.role AS professional_user_role
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        LEFT JOIN professionals p ON p.id = a.professional_id AND p.tenant_id = a.tenant_id
        LEFT JOIN users pu ON pu.id = p.user_id AND pu.tenant_id = p.tenant_id
        JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
        WHERE a.tenant_id = $tenant_id
          AND a.status = 'booked'
          AND CONCAT(a.appointment_date, ' ', a.appointment_time) >= NOW()
          $where_extra
          $professional_filter
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
        $limit_sql
    ");

    $appointments = [];
    while ($row = $res->fetch_assoc()) {
        $professional_photo_path = professional_photo_with_dashboard_fallback($row, $dashboard_photo);
        $appointments[] = [
            'id' => (int) $row['id'],
            'appointment_date' => $row['appointment_date'],
            'appointment_time' => substr($row['appointment_time'], 0, 5),
            'patient_name' => $row['name'],
            'patient_email' => $row['email'],
            'patient_phone' => $row['phone'],
            'professional_id' => (int) ($row['professional_id'] ?? 0),
            'professional_name' => $row['professional_name'] ?? '',
            'professional_photo_path' => $professional_photo_path,
            'consultation_type' => $row['consultation_type'] ?? 'presencial',
            'online_session_url' => $row['online_session_url'] ?? '',
            'service_label' => appointment_service_option_label($row),
            'payment_status' => $row['payment_status'] ?? 'pending',
            'payment_method' => $row['payment_method'],
            'patient_bonus_id' => $row['patient_bonus_id']
        ];
    }

    $cancelled_res = $mysqli->query("
        SELECT a.id, a.appointment_date, a.appointment_time, a.cancelled_at, a.consultation_type, a.service_type, a.online_session_url,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               s.name AS service_name,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.patient_bonus_id,
               u.name, u.email, u.phone,
               p.id AS professional_id, p.display_name AS professional_name, p.public_photo_path AS professional_photo_path,
               pu.role AS professional_user_role
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        LEFT JOIN professionals p ON p.id = a.professional_id AND p.tenant_id = a.tenant_id
        LEFT JOIN users pu ON pu.id = p.user_id AND pu.tenant_id = p.tenant_id
        JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
        WHERE a.tenant_id = $tenant_id
          AND a.status = 'cancelled'
          $professional_filter
        ORDER BY COALESCE(a.cancelled_at, a.appointment_date) DESC, a.appointment_date DESC, a.appointment_time DESC
        LIMIT 50
    ");

    $cancelled_appointments = [];
    while ($row = $cancelled_res->fetch_assoc()) {
        $professional_photo_path = professional_photo_with_dashboard_fallback($row, $dashboard_photo);
        $cancelled_appointments[] = [
            'id' => (int) $row['id'],
            'appointment_date' => $row['appointment_date'],
            'appointment_time' => substr($row['appointment_time'], 0, 5),
            'cancelled_at' => $row['cancelled_at'] ?? '',
            'patient_name' => $row['name'],
            'patient_email' => $row['email'],
            'patient_phone' => $row['phone'],
            'professional_id' => (int) ($row['professional_id'] ?? 0),
            'professional_name' => $row['professional_name'] ?? '',
            'professional_photo_path' => $professional_photo_path,
            'consultation_type' => $row['consultation_type'] ?? 'presencial',
            'online_session_url' => $row['online_session_url'] ?? '',
            'service_label' => appointment_service_option_label($row),
            'payment_status' => $row['payment_status'] ?? 'pending',
            'payment_method' => $row['payment_method'],
            'patient_bonus_id' => $row['patient_bonus_id']
        ];
    }

    $professionals = [];
    if ($is_superadmin) {
        $professionals = active_professionals_payload($mysqli);
    }

    $planning_days = 7;
    $planning_start_offset = 0;
    if ($planning_scope === 'today') {
        $planning_days = 1;
    } elseif ($planning_scope === 'tomorrow') {
        $planning_days = 1;
        $planning_start_offset = 1;
    } elseif ($planning_scope === '3days') {
        $planning_days = 3;
    }
    $planning_professional_id = $current_professional_id;
    $planning_settings = cabinet_get_effective_professional_settings($mysqli, $planning_professional_id);
    $planning_settings = [
        'professional_id' => (int) $planning_professional_id,
        'appointment_start_time' => substr($planning_settings['appointment_start_time'] ?? '10:00:00', 0, 5),
        'appointment_end_time' => substr($planning_settings['appointment_end_time'] ?? '19:00:00', 0, 5),
        'break_start_time' => !empty($planning_settings['break_start_time']) ? substr($planning_settings['break_start_time'], 0, 5) : '',
        'break_end_time' => !empty($planning_settings['break_end_time']) ? substr($planning_settings['break_end_time'], 0, 5) : '',
        'available_weekdays' => $planning_settings['available_weekdays'] ?? '1,2,3,4,5',
        'start_offset' => $planning_start_offset,
        'days' => $planning_days
    ];

    $closed_where = "(cd.is_global = 1 OR cd.professional_id = " . (int) $planning_professional_id . " OR (cd.professional_id IS NULL AND cd.is_global = 0))";
    $closed_res = $mysqli->query("
        SELECT cd.closed_date, cd.reason, cd.is_global, cd.professional_id
        FROM closed_days cd
        WHERE cd.closed_date >= DATE_ADD(CURDATE(), INTERVAL " . (int) $planning_start_offset . " DAY)
          AND cd.tenant_id = $tenant_id
          AND cd.closed_date < DATE_ADD(CURDATE(), INTERVAL " . (int) ($planning_start_offset + $planning_days) . " DAY)
          AND $closed_where
        ORDER BY cd.closed_date ASC
    ");
    $closed_days = [];
    while ($row = $closed_res->fetch_assoc()) {
        $closed_days[] = [
            'date' => $row['closed_date'],
            'reason' => $row['reason'] ?? '',
            'is_global' => (int) ($row['is_global'] ?? 0),
            'professional_id' => (int) ($row['professional_id'] ?? 0)
        ];
    }

    $planning_appointments = [];
    if ($planning_professional_id > 0) {
        $planning_res = $mysqli->query("
            SELECT a.id, a.appointment_date, a.appointment_time, a.consultation_type, a.service_type, a.online_session_url,
                   COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
                   s.name AS service_name,
                   COALESCE(a.payment_status, 'pending') AS payment_status,
                   a.payment_method, a.patient_bonus_id,
                   u.name, u.email, u.phone
            FROM appointments a
            LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
            LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
            JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
            WHERE a.tenant_id = $tenant_id
              AND a.status = 'booked'
              AND a.appointment_date >= DATE_ADD(CURDATE(), INTERVAL " . (int) $planning_start_offset . " DAY)
              AND a.appointment_date < DATE_ADD(CURDATE(), INTERVAL " . (int) ($planning_start_offset + $planning_days) . " DAY)
              AND (a.professional_id = " . (int) $planning_professional_id . " OR a.professional_id IS NULL)
            ORDER BY a.appointment_date ASC, a.appointment_time ASC
        ");
        while ($row = $planning_res->fetch_assoc()) {
            $planning_appointments[] = [
                'id' => (int) $row['id'],
                'appointment_date' => $row['appointment_date'],
                'appointment_time' => substr($row['appointment_time'], 0, 5),
                'patient_name' => $row['name'],
                'patient_email' => $row['email'],
                'patient_phone' => $row['phone'],
                'consultation_type' => $row['consultation_type'] ?? 'presencial',
                'online_session_url' => $row['online_session_url'] ?? '',
                'service_label' => appointment_service_option_label($row),
                'duration_minutes' => (int) ($row['duration_minutes'] ?? 60),
                'payment_status' => $row['payment_status'] ?? 'pending',
                'payment_method' => $row['payment_method'],
                'patient_bonus_id' => $row['patient_bonus_id']
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'appointments' => $appointments,
        'cancelled_appointments' => $cancelled_appointments,
        'planning_appointments' => $planning_appointments,
        'professionals' => $professionals,
        'current_professional_id' => $current_professional_id,
        'planning_settings' => $planning_settings,
        'closed_days' => $closed_days
    ]);
} elseif ($action === 'admin_stats') {
    ensure_action_feature($mysqli, 'reports.globalReports', 'Los informes y estadisticas no estan disponibles en este plan.');
    ensure_appointment_payment_columns($mysqli);
    ensure_appointment_services_tables($mysqli);
    ensure_bonus_tables($mysqli);
    ensure_payment_attempts_table($mysqli);
    ensure_cabinet_schema($mysqli);
    $branding = get_public_branding_settings($mysqli);
    $dashboard_photo = $branding['profile_image_path'] ?? '';
    $professional_id = admin_requested_professional_filter($mysqli);
    $appointment_filter = " AND tenant_id = $tenant_id" . ($professional_id > 0 ? " AND professional_id = " . (int) $professional_id : ($professional_id < 0 ? " AND 1 = 0" : ""));
    $appointment_filter_a = " AND a.tenant_id = $tenant_id" . ($professional_id > 0 ? " AND a.professional_id = " . (int) $professional_id : ($professional_id < 0 ? " AND 1 = 0" : ""));
    $patient_join = "LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.tenant_id = u.tenant_id AND ppf.is_primary = 1 LEFT JOIN patient_profiles pp ON pp.user_id = u.id AND pp.tenant_id = u.tenant_id";
    $patient_filter = $professional_id > 0 ? " AND COALESCE(ppf.professional_id, pp.professional_id) = " . (int) $professional_id : ($professional_id < 0 ? " AND 1 = 0" : "");

    $stats = [
        'upcoming_count' => 0,
        'today_count' => 0,
        'month_count' => 0,
        'patient_count' => 0,
        'online_revenue_month' => '0.00',
        'payment_revenue_month' => [
            'card' => '0.00',
            'bizum' => '0.00',
            'cash' => '0.00',
            'bank_transfer' => '0.00',
            'other' => '0.00',
            'manual' => '0.00',
            'bonus' => '0.00'
        ],
        'pending_payment_count' => 0,
        'active_bonus_count' => 0,
        'active_bonus_sessions' => 0,
        'top_patients' => [],
        'professional_summary' => [],
        'reports' => [
            'patients_without_upcoming' => [],
            'recent_cancellations' => [],
            'pending_payments' => []
        ]
    ];

    $res = $mysqli->query("SELECT COUNT(*) AS total FROM appointments WHERE status = 'booked' AND CONCAT(appointment_date, ' ', appointment_time) >= NOW() $appointment_filter");
    if ($row = $res->fetch_assoc()) {
        $stats['upcoming_count'] = (int) $row['total'];
    }

    $res = $mysqli->query("SELECT COUNT(*) AS total FROM appointments WHERE status = 'booked' AND appointment_date = CURDATE() $appointment_filter");
    if ($row = $res->fetch_assoc()) {
        $stats['today_count'] = (int) $row['total'];
    }

    $res = $mysqli->query("
        SELECT COUNT(*) AS total
        FROM appointments
        WHERE status = 'booked'
          AND appointment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND appointment_date < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
          $appointment_filter
    ");
    if ($row = $res->fetch_assoc()) {
        $stats['month_count'] = (int) $row['total'];
    }

    $res = $mysqli->query("
        SELECT COUNT(DISTINCT u.id) AS total
        FROM users u
        $patient_join
        WHERE u.tenant_id = $tenant_id
          AND u.role = 'patient'
          $patient_filter
    ");
    if ($row = $res->fetch_assoc()) {
        $stats['patient_count'] = (int) $row['total'];
    }

    $res = $mysqli->query("
        SELECT COALESCE(SUM(amount_cents), 0) AS cents
        FROM payment_attempts
        WHERE tenant_id = $tenant_id
          AND status = 'OK'
          AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND created_at < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
          " . ($professional_id > 0 ? " AND professional_id = " . (int) $professional_id : ($professional_id < 0 ? " AND 1 = 0" : "")) . "
    ");
    if ($row = $res->fetch_assoc()) {
        $stats['online_revenue_month'] = number_format(((int) $row['cents']) / 100, 2, '.', '');
    }

    $res = $mysqli->query("
        SELECT COALESCE(payment_method, 'pending') AS payment_method, COUNT(*) AS total
        FROM appointments
        WHERE status = 'booked'
          AND COALESCE(payment_status, 'pending') <> 'paid'
          AND appointment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND appointment_date < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
          $appointment_filter
        GROUP BY COALESCE(payment_method, 'pending')
    ");
    while ($row = $res->fetch_assoc()) {
        $stats['pending_payment_count'] += (int) $row['total'];
    }

    $res = $mysqli->query("
        SELECT COALESCE(a.payment_method, 'manual') AS payment_method,
               COALESCE(SUM(COALESCE(aso.price, 0)), 0) AS amount
        FROM appointments a
        LEFT JOIN appointment_service_options aso ON aso.id = a.service_option_id AND aso.tenant_id = a.tenant_id
        WHERE a.status = 'booked'
          AND COALESCE(a.payment_status, 'pending') = 'paid'
          AND a.appointment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND a.appointment_date < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
          $appointment_filter_a
        GROUP BY COALESCE(a.payment_method, 'manual')
    ");
    while ($row = $res->fetch_assoc()) {
        $method = $row['payment_method'] ?: 'manual';
        if (array_key_exists($method, $stats['payment_revenue_month'])) {
            $stats['payment_revenue_month'][$method] = number_format((float) $row['amount'], 2, '.', '');
        }
    }

    $res = $mysqli->query("
        SELECT COUNT(*) AS total, COALESCE(SUM(remaining_sessions), 0) AS sessions
        FROM patient_bonuses
        WHERE tenant_id = $tenant_id
          AND status = 'active'
          AND remaining_sessions > 0
          AND (expires_at IS NULL OR expires_at >= CURDATE())
          " . ($professional_id > 0 ? " AND professional_id = " . (int) $professional_id : ($professional_id < 0 ? " AND 1 = 0" : "")) . "
    ");
    if ($row = $res->fetch_assoc()) {
        $stats['active_bonus_count'] = (int) $row['total'];
        $stats['active_bonus_sessions'] = (int) $row['sessions'];
    }

    $res = $mysqli->query("
        SELECT u.name, u.email, COUNT(*) AS sessions
        FROM appointments a
        JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
        WHERE a.status = 'booked'
          $appointment_filter_a
        GROUP BY a.user_id, u.name, u.email
        ORDER BY sessions DESC, u.name ASC
        LIMIT 5
    ");
    while ($row = $res->fetch_assoc()) {
        $stats['top_patients'][] = [
            'name' => $row['name'],
            'email' => $row['email'],
            'sessions' => (int) $row['sessions']
        ];
    }

    $res = $mysqli->query("
        SELECT u.id, u.name, u.email, u.phone,
               MAX(CASE WHEN a.status = 'booked' AND CONCAT(a.appointment_date, ' ', a.appointment_time) < NOW() THEN CONCAT(a.appointment_date, ' ', a.appointment_time) ELSE NULL END) AS last_appointment_at,
               SUM(CASE WHEN a.status = 'booked' AND CONCAT(a.appointment_date, ' ', a.appointment_time) >= NOW() THEN 1 ELSE 0 END) AS future_count
        FROM users u
        $patient_join
        LEFT JOIN appointments a ON a.user_id = u.id AND a.tenant_id = u.tenant_id
        WHERE u.tenant_id = $tenant_id
          AND u.role = 'patient'
          $patient_filter
        GROUP BY u.id, u.name, u.email, u.phone
        HAVING future_count = 0
        ORDER BY last_appointment_at IS NULL ASC, last_appointment_at DESC, u.name ASC
        LIMIT 10
    ");
    while ($row = $res->fetch_assoc()) {
        $stats['reports']['patients_without_upcoming'][] = [
            'patient_id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'last_appointment_at' => $row['last_appointment_at'] ?? ''
        ];
    }

    $res = $mysqli->query("
        SELECT a.id, a.appointment_date, a.appointment_time, a.cancelled_at,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               s.name AS service_name,
               u.name AS patient_name, u.email AS patient_email, u.phone AS patient_phone,
               p.display_name AS professional_name
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
        LEFT JOIN professionals p ON p.id = a.professional_id AND p.tenant_id = a.tenant_id
        WHERE a.status = 'cancelled'
          AND COALESCE(a.cancelled_at, CONCAT(a.appointment_date, ' ', a.appointment_time)) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          $appointment_filter_a
        ORDER BY COALESCE(a.cancelled_at, CONCAT(a.appointment_date, ' ', a.appointment_time)) DESC
        LIMIT 10
    ");
    while ($row = $res->fetch_assoc()) {
        $stats['reports']['recent_cancellations'][] = [
            'id' => (int) $row['id'],
            'appointment_date' => $row['appointment_date'],
            'appointment_time' => substr((string) $row['appointment_time'], 0, 5),
            'cancelled_at' => $row['cancelled_at'] ?? '',
            'patient_name' => $row['patient_name'],
            'patient_email' => $row['patient_email'],
            'patient_phone' => $row['patient_phone'],
            'professional_name' => $row['professional_name'] ?? '',
            'service_label' => appointment_service_option_label($row),
            'duration_minutes' => (int) ($row['duration_minutes'] ?? 60)
        ];
    }

    $res = $mysqli->query("
        SELECT a.id, a.appointment_date, a.appointment_time, a.consultation_type, a.service_type,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method,
               s.name AS service_name,
               u.name AS patient_name, u.email AS patient_email, u.phone AS patient_phone,
               p.display_name AS professional_name
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
        LEFT JOIN professionals p ON p.id = a.professional_id AND p.tenant_id = a.tenant_id
        WHERE a.status = 'booked'
          AND COALESCE(a.payment_status, 'pending') <> 'paid'
          $appointment_filter_a
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
        LIMIT 10
    ");
    while ($row = $res->fetch_assoc()) {
        $stats['reports']['pending_payments'][] = [
            'id' => (int) $row['id'],
            'appointment_date' => $row['appointment_date'],
            'appointment_time' => substr((string) $row['appointment_time'], 0, 5),
            'patient_name' => $row['patient_name'],
            'patient_email' => $row['patient_email'],
            'patient_phone' => $row['patient_phone'],
            'professional_name' => $row['professional_name'] ?? '',
            'service_label' => appointment_service_option_label($row),
            'consultation_type' => $row['consultation_type'] ?? 'presencial',
            'payment_status' => $row['payment_status'] ?? 'pending',
            'payment_method' => $row['payment_method'] ?? ''
        ];
    }

    if ($is_superadmin) {
        $res = $mysqli->query("
            SELECT p.id, p.display_name, p.public_photo_path, u.role AS user_role,
                   COUNT(DISTINCT COALESCE(ppf.patient_id, pp.user_id)) AS patient_count,
                   COUNT(DISTINCT CASE WHEN a.status = 'booked' AND CONCAT(a.appointment_date, ' ', a.appointment_time) >= NOW() THEN a.id END) AS upcoming_count
            FROM professionals p
            LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
            LEFT JOIN patient_professionals ppf ON ppf.professional_id = p.id AND ppf.tenant_id = p.tenant_id AND ppf.is_primary = 1
            LEFT JOIN patient_profiles pp ON pp.professional_id = p.id AND pp.tenant_id = p.tenant_id
            LEFT JOIN appointments a ON a.professional_id = p.id AND a.tenant_id = p.tenant_id
            WHERE p.tenant_id = $tenant_id
              AND p.is_active = 1
              AND u.role IN ('superadmin', 'admin')
            GROUP BY p.id, p.display_name, p.public_photo_path, u.role
            ORDER BY CASE WHEN u.role = 'superadmin' THEN 0 ELSE 1 END ASC,
                     p.sort_order ASC,
                     p.display_name ASC
        ");
        while ($row = $res->fetch_assoc()) {
            $photo_path = professional_photo_with_dashboard_fallback($row, $dashboard_photo);
            $stats['professional_summary'][] = [
                'id' => (int) $row['id'],
                'display_name' => $row['display_name'],
                'public_photo_path' => $photo_path,
                'patient_count' => (int) $row['patient_count'],
                'upcoming_count' => (int) $row['upcoming_count']
            ];
        }
    }

    echo json_encode(['success' => true, 'stats' => $stats]);
} elseif ($action === 'list_closed_days') {
    ensure_action_feature($mysqli, 'closures.enabled', 'Los cierres y vacaciones no estan disponibles en este plan.');
    ensure_cabinet_schema($mysqli);
    $branding = get_public_branding_settings($mysqli);
    $dashboard_photo = $branding['profile_image_path'] ?? '';
    $current_professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
    $effective_professional_expr = "CASE WHEN cd.is_global = 0 AND cd.professional_id IS NULL THEN " . (int) $current_professional_id . " ELSE cd.professional_id END";
    $where = $is_superadmin
        ? "1 = 1"
        : "($effective_professional_expr = " . (int) $current_professional_id . " OR cd.is_global = 1)";
    $res = $mysqli->query("
        SELECT cd.*, $effective_professional_expr AS effective_professional_id,
               p.display_name AS professional_name, p.public_photo_path AS professional_photo_path,
               pu.role AS professional_user_role
        FROM closed_days cd
        LEFT JOIN professionals p ON p.id = $effective_professional_expr AND p.tenant_id = cd.tenant_id
        LEFT JOIN users pu ON pu.id = p.user_id AND pu.tenant_id = p.tenant_id
        WHERE cd.tenant_id = $tenant_id
          AND cd.closed_date >= CURDATE()
          AND $where
        ORDER BY cd.closed_date ASC, cd.is_global DESC, p.sort_order ASC, p.display_name ASC
    ");
    $days = [];
    while ($row = $res->fetch_assoc()) {
        $row['professional_id'] = (int) ($row['effective_professional_id'] ?? 0);
        $row['professional_photo_path'] = professional_photo_with_dashboard_fallback($row, $dashboard_photo);
        $days[] = $row;
    }
    echo json_encode(['success' => true, 'days' => $days, 'show_professionals' => $is_superadmin ? 1 : 0]);
} elseif ($action === 'add_closed_day') {
    ensure_action_feature($mysqli, 'closures.enabled', 'Los cierres y vacaciones no estan disponibles en este plan.');
    $date = $_POST['date'] ?? ($_POST['start_date'] ?? '');
    $end_date = $_POST['end_date'] ?? $date;
    $reason = $_POST['reason'] ?? 'Descanso';
    $is_global = ($is_superadmin && isset($_POST['is_global']) && $_POST['is_global'] === '1') ? 1 : 0;
    $professional_id = $is_global ? null : current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
    if (!$date) {
        echo json_encode(['success' => false, 'error' => 'Fecha inválida']);
        exit;
    }

    if (!$end_date) {
        $end_date = $date;
    }

    try {
        $start = new DateTime($date);
        $end = new DateTime($end_date);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Rango de fechas inválido']);
        exit;
    }

    if ($end < $start) {
        echo json_encode(['success' => false, 'error' => 'La fecha final no puede ser anterior a la inicial']);
        exit;
    }

    if ($start->diff($end)->days > 370) {
        echo json_encode(['success' => false, 'error' => 'El intervalo no puede superar 370 días']);
        exit;
    }

    try {
        $inserted = 0;
        $skipped = 0;
        $stmt = $mysqli->prepare("INSERT INTO closed_days (tenant_id, closed_date, professional_id, reason, is_global) VALUES (?, ?, ?, ?, ?)");
        $current = clone $start;

        while ($current <= $end) {
            $current_date = $current->format('Y-m-d');
            if ($is_global) {
                $exists_stmt = $mysqli->prepare("SELECT id FROM closed_days WHERE tenant_id = ? AND closed_date = ? AND is_global = 1 AND reason = ? LIMIT 1");
                $exists_stmt->bind_param("iss", $tenant_id, $current_date, $reason);
            } else {
                $exists_stmt = $mysqli->prepare("SELECT id FROM closed_days WHERE tenant_id = ? AND closed_date = ? AND is_global = 0 AND professional_id = ? AND reason = ? LIMIT 1");
                $exists_stmt->bind_param("isis", $tenant_id, $current_date, $professional_id, $reason);
            }
            $exists_stmt->execute();
            if ($exists_stmt->get_result()->fetch_assoc()) {
                $skipped++;
                $current->modify('+1 day');
                continue;
            }

            $stmt->bind_param("isisi", $tenant_id, $current_date, $professional_id, $reason, $is_global);
            $stmt->execute();

            $inserted++;

            $current->modify('+1 day');
        }

        // Also we might want to cancel existing appointments on that day, but for simplicity, we just block new ones
        // In a real scenario we could delete or mark them as cancelled. 
        echo json_encode(['success' => true, 'inserted' => $inserted, 'skipped' => $skipped]);
    } catch (\Exception $e) {
        // If duplicate
        echo json_encode(['success' => false, 'error' => 'No se pudo guardar el descanso.']);
    }
} elseif ($action === 'delete_closed_day') {
    ensure_action_feature($mysqli, 'closures.enabled', 'Los cierres y vacaciones no estan disponibles en este plan.');
    $id = $_POST['id'] ?? 0;
    $stmt = $mysqli->prepare("DELETE FROM closed_days WHERE tenant_id = ? AND id = ?");
    $stmt->bind_param("ii", $tenant_id, $id);
    $stmt->execute();
    echo json_encode(['success' => true]);
} elseif ($action === 'delete_closed_range') {
    ensure_action_feature($mysqli, 'closures.enabled', 'Los cierres y vacaciones no estan disponibles en este plan.');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? $start_date;
    $reason = $_POST['reason'] ?? '';
    $is_global = isset($_POST['is_global']) && $_POST['is_global'] === '1' ? 1 : 0;
    $professional_id = isset($_POST['professional_id']) && $_POST['professional_id'] !== '' ? (int) $_POST['professional_id'] : 0;

    if (!$start_date || !$end_date || $reason === '') {
        echo json_encode(['success' => false, 'error' => 'Rango invalido']);
        exit;
    }

    if ($is_superadmin) {
        if ($is_global) {
            $stmt = $mysqli->prepare("DELETE FROM closed_days WHERE tenant_id = ? AND closed_date BETWEEN ? AND ? AND reason = ? AND is_global = 1");
            $stmt->bind_param("isss", $tenant_id, $start_date, $end_date, $reason);
        } elseif ($professional_id <= 0) {
            $stmt = $mysqli->prepare("DELETE FROM closed_days WHERE tenant_id = ? AND closed_date BETWEEN ? AND ? AND reason = ? AND is_global = 0 AND professional_id IS NULL");
            $stmt->bind_param("isss", $tenant_id, $start_date, $end_date, $reason);
        } else {
            $current_professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
            $stmt = $mysqli->prepare("
                DELETE FROM closed_days
                WHERE tenant_id = ?
                  AND closed_date BETWEEN ? AND ?
                  AND reason = ?
                  AND is_global = 0
                  AND (professional_id = ? OR (professional_id IS NULL AND ? = ?))
            ");
            $stmt->bind_param("isssiii", $tenant_id, $start_date, $end_date, $reason, $professional_id, $professional_id, $current_professional_id);
        }
    } else {
        $current_professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
        $stmt = $mysqli->prepare("DELETE FROM closed_days WHERE tenant_id = ? AND closed_date BETWEEN ? AND ? AND reason = ? AND is_global = 0 AND professional_id = ?");
        $stmt->bind_param("isssi", $tenant_id, $start_date, $end_date, $reason, $current_professional_id);
    }
    $stmt->execute();
    echo json_encode(['success' => true, 'deleted' => $stmt->affected_rows]);
} elseif ($action === 'get_cabinet_settings') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede gestionar el equipo.']);
        exit;
    }
    ensure_payment_settings_table($mysqli);
    ensure_cabinet_schema($mysqli);

    $settings_res = $mysqli->query("SELECT show_team_public, allow_patient_transfer, new_patient_booking_mode, new_patient_fixed_professional_id FROM payment_settings WHERE tenant_id = $tenant_id");
    $settings = $settings_res ? $settings_res->fetch_assoc() : ['show_team_public' => 0, 'allow_patient_transfer' => 0, 'new_patient_booking_mode' => 'day_first', 'new_patient_fixed_professional_id' => null];
    $knowledge_columns_available = professional_knowledge_sector_columns_available($mysqli);
    $knowledge_settings_select = $knowledge_columns_available
        ? "ps.knowledge_sector_mode, ps.knowledge_sector_keys_json"
        : "'own' AS knowledge_sector_mode, NULL AS knowledge_sector_keys_json";
    $member_permissions_select = column_exists($mysqli, 'professional_settings', 'member_permissions_json')
        ? "ps.member_permissions_json"
        : "NULL AS member_permissions_json";
    $res = $mysqli->query("
        SELECT p.id, p.user_id, p.display_name, p.professional_title, p.license_number, p.professional_specialty, p.public_bio,
               p.public_photo_path, p.public_email, p.public_phone, p.instagram_url, p.facebook_url, p.tiktok_url,
               p.appointment_summary_email_mode,
               p.is_active, u.email AS login_email, u.role,
               ps.available_session_types, ps.available_session_durations, ps.default_appointment_location, ps.default_location_id, ps.livekit_enabled, $member_permissions_select,
               $knowledge_settings_select
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
        LEFT JOIN professional_settings ps ON ps.professional_id = p.id AND ps.tenant_id = p.tenant_id
        WHERE p.tenant_id = $tenant_id
        ORDER BY p.sort_order ASC, p.display_name ASC
    ");
    $professionals = [];
    while ($row = $res->fetch_assoc()) {
        $is_current_user = (int) ($row['user_id'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0) ? 1 : 0;
        $photo_path = $row['public_photo_path'] ?? '';
        $professionals[] = [
            'id' => (int) $row['id'],
            'user_id' => (int) ($row['user_id'] ?? 0),
            'display_name' => $row['display_name'] ?? '',
            'professional_title' => $row['professional_title'] ?? '',
            'license_number' => $row['license_number'] ?? '',
            'professional_specialty' => $row['professional_specialty'] ?? '',
            'public_bio' => $row['public_bio'] ?? '',
            'public_photo_path' => $photo_path,
            'display_photo_path' => $photo_path,
            'email' => $row['login_email'] ?: ($row['public_email'] ?? ''),
            'public_phone' => $row['public_phone'] ?? '',
            'instagram_url' => $row['instagram_url'] ?? '',
            'facebook_url' => $row['facebook_url'] ?? '',
            'tiktok_url' => $row['tiktok_url'] ?? '',
            'appointment_summary_email_mode' => $row['appointment_summary_email_mode'] ?? 'on_booking',
            'available_session_types' => $row['available_session_types'] ?? '',
            'available_session_durations' => $row['available_session_durations'] ?? '',
            'default_appointment_location' => $row['default_appointment_location'] ?? '',
            'default_location_id' => (int) ($row['default_location_id'] ?? 0),
            'livekit_enabled' => (int) ($row['livekit_enabled'] ?? 1),
            'knowledge_sector_mode' => in_array($row['knowledge_sector_mode'] ?? 'own', ['own', 'related', 'custom'], true) ? $row['knowledge_sector_mode'] : 'own',
            'knowledge_sector_keys' => normalize_knowledge_sector_keys($row['knowledge_sector_keys_json'] ?? ''),
            'role' => in_array($row['role'] ?? 'admin', ['superadmin', 'admin', 'reception', 'administration', 'technical'], true) ? $row['role'] : 'admin',
            'member_permissions' => cabinet_normalize_member_permissions($row['member_permissions_json'] ?? null, $row['role'] ?? 'admin'),
            'is_active' => (int) ($row['is_active'] ?? 1),
            'is_current_user' => $is_current_user
        ];
    }

    $team_limit_config = plan_config_for_key(dashboard_config_plan_key_from_db($mysqli));
    echo json_encode([
        'success' => true,
        'settings' => $settings,
        'professionals' => $professionals,
        'limits' => [
            'teamMembers' => plan_config_limit_value($team_limit_config, 'teamMembers', null)
        ]
    ]);
} elseif ($action === 'knowledge_sector_options') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede configurar el equipo.']);
        exit;
    }
    if (!app_feature_enabled_from_db($mysqli, 'knowledgeBase.enabled', false)) {
        echo json_encode(['success' => true, 'enabled' => false, 'multi_sector_enabled' => false, 'sectors' => []]);
        exit;
    }
    $main_sector = current_knowledge_sector_key($mysqli);
    $available = available_knowledge_sectors($mysqli);
    $available_keys = array_column($available, 'key');
    if (!in_array($main_sector, $available_keys, true)) {
        $labels = knowledge_sector_label_map();
        $available[] = [
            'key' => $main_sector,
            'name' => $labels[$main_sector] ?? ucfirst(str_replace(['_', '-'], ' ', $main_sector)),
            'total' => 0
        ];
    }
    $related = array_values(array_filter(knowledge_related_sector_presets()[$main_sector] ?? [], static function ($key) use ($available_keys) {
        return in_array($key, $available_keys, true);
    }));
    echo json_encode([
        'success' => true,
        'enabled' => true,
        'multi_sector_enabled' => knowledge_multi_sector_enabled($mysqli),
        'main_sector' => $main_sector,
        'related_sectors' => $related,
        'sectors' => $available
    ]);
} elseif ($action === 'check_professional_delete') {
    ensure_action_feature($mysqli, 'team.enabled', 'El equipo de trabajo no esta disponible en este plan.');
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede gestionar el equipo.']);
        exit;
    }
    ensure_cabinet_schema($mysqli);

    $professional_id = (int) ($_POST['professional_id'] ?? 0);
    if ($professional_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Miembro invalido.']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT id, user_id, display_name FROM professionals WHERE tenant_id = ? AND id = ? LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $professional_id);
    $stmt->execute();
    $professional = $stmt->get_result()->fetch_assoc();
    if (!$professional) {
        echo json_encode(['success' => false, 'error' => 'No se encontro el miembro del equipo.']);
        exit;
    }
    if ((int) ($professional['user_id'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0)) {
        echo json_encode(['success' => false, 'error' => 'No puedes borrar tu propio usuario administrador.']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM appointments WHERE tenant_id = ? AND professional_id = ? AND status <> 'cancelled' AND appointment_date >= CURDATE()");
    $stmt->bind_param("ii", $tenant_id, $professional_id);
    $stmt->execute();
    $pending_appointments = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    $stmt = $mysqli->prepare("
        SELECT COUNT(*) AS total FROM (
            SELECT user_id AS patient_id FROM patient_profiles WHERE tenant_id = ? AND professional_id = ?
            UNION
            SELECT patient_id FROM patient_professionals WHERE tenant_id = ? AND professional_id = ?
        ) assigned_patients
    ");
    $stmt->bind_param("iiii", $tenant_id, $professional_id, $tenant_id, $professional_id);
    $stmt->execute();
    $assigned_patients = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    $linked_records = 0;
    $tables_to_check = [
        'appointments',
        'closed_days',
        'invitations',
        'patient_profiles',
        'patient_bonuses',
        'payment_attempts',
        'appointment_services',
        'appointment_service_options',
        'appointment_bonuses'
    ];
    foreach ($tables_to_check as $table) {
        $exists = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($table) . "'");
        if (!$exists || $exists->num_rows === 0) {
            continue;
        }
        $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM `$table` WHERE tenant_id = ? AND professional_id = ?");
        $stmt->bind_param("ii", $tenant_id, $professional_id);
        $stmt->execute();
        $linked_records += (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    }
    $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM patient_professionals WHERE tenant_id = ? AND professional_id = ?");
    $stmt->bind_param("ii", $tenant_id, $professional_id);
    $stmt->execute();
    $linked_records += (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    $targets = [];
    $stmt = $mysqli->prepare("
        SELECT p.id, p.display_name
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
        WHERE p.tenant_id = ?
          AND p.id <> ?
          AND p.is_active = 1
          AND u.role IN ('superadmin', 'admin')
        ORDER BY p.sort_order ASC, p.display_name ASC
    ");
    $stmt->bind_param("ii", $tenant_id, $professional_id);
    $stmt->execute();
    $target_res = $stmt->get_result();
    while ($row = $target_res->fetch_assoc()) {
        $targets[] = ['id' => (int) $row['id'], 'display_name' => $row['display_name']];
    }

    echo json_encode([
        'success' => true,
        'professional' => [
            'id' => (int) $professional['id'],
            'display_name' => $professional['display_name']
        ],
        'usage' => [
            'pending_appointments' => $pending_appointments,
            'assigned_patients' => $assigned_patients,
            'linked_records' => $linked_records,
            'requires_transfer' => $linked_records > 0 ? 1 : 0
        ],
        'targets' => $targets
    ]);
} elseif ($action === 'delete_professional') {
    ensure_action_feature($mysqli, 'team.enabled', 'El equipo de trabajo no esta disponible en este plan.');
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede gestionar el equipo.']);
        exit;
    }
    ensure_cabinet_schema($mysqli);

    $professional_id = (int) ($_POST['professional_id'] ?? 0);
    if ($professional_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Miembro invalido.']);
        exit;
    }

    $stmt = $mysqli->prepare("
        SELECT p.user_id, p.display_name, u.role AS user_role
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
        WHERE p.tenant_id = ?
          AND p.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $professional_id);
    $stmt->execute();
    $professional = $stmt->get_result()->fetch_assoc();
    if (!$professional) {
        echo json_encode(['success' => false, 'error' => 'No se encontro el miembro del equipo.']);
        exit;
    }
    if ((int) ($professional['user_id'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0)) {
        echo json_encode(['success' => false, 'error' => 'No puedes borrar tu propio usuario administrador.']);
        exit;
    }
    if (($professional['user_role'] ?? '') === 'superadmin') {
        echo json_encode(['success' => false, 'error' => 'No se puede borrar el usuario superadmin.']);
        exit;
    }

    $target_professional_id = (int) ($_POST['target_professional_id'] ?? 0);
    $tables_to_transfer = [
        'appointments',
        'closed_days',
        'invitations',
        'patient_profiles',
        'patient_bonuses',
        'payment_attempts',
        'appointment_services',
        'appointment_service_options',
        'appointment_bonuses'
    ];

    $usage_total = 0;
    foreach ($tables_to_transfer as $table) {
        $exists = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($table) . "'");
        if (!$exists || $exists->num_rows === 0) {
            continue;
        }
        $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM `$table` WHERE tenant_id = ? AND professional_id = ?");
        $stmt->bind_param("ii", $tenant_id, $professional_id);
        $stmt->execute();
        $usage_total += (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    }

    $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM patient_professionals WHERE tenant_id = ? AND professional_id = ?");
    $stmt->bind_param("ii", $tenant_id, $professional_id);
    $stmt->execute();
    $usage_total += (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    if ($usage_total > 0) {
        if ($target_professional_id <= 0 || $target_professional_id === $professional_id) {
            echo json_encode(['success' => false, 'error' => 'Elige otro profesional para traspasar citas y registros asignados antes de borrar.']);
            exit;
        }
        $stmt = $mysqli->prepare("
            SELECT p.id
            FROM professionals p
            LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
            WHERE p.tenant_id = ?
              AND p.id = ?
              AND p.is_active = 1
              AND u.role IN ('superadmin', 'admin')
            LIMIT 1
        ");
        $stmt->bind_param("ii", $tenant_id, $target_professional_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'El profesional de destino no es valido.']);
            exit;
        }
    }

    $password_setup_users = [];
    $photo_index = (int) ($_POST['professional_photo_index'] ?? -1);
    $mysqli->begin_transaction();
    try {
        if ($usage_total > 0) {
            foreach ($tables_to_transfer as $table) {
                $exists = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($table) . "'");
                if (!$exists || $exists->num_rows === 0) {
                    continue;
                }
                $stmt = $mysqli->prepare("UPDATE `$table` SET professional_id = ? WHERE tenant_id = ? AND professional_id = ?");
                $stmt->bind_param("iii", $target_professional_id, $tenant_id, $professional_id);
                $stmt->execute();
            }

            $stmt = $mysqli->prepare("
                INSERT IGNORE INTO patient_professionals (tenant_id, patient_id, professional_id, is_primary, assigned_at, transferred_at, notes)
                SELECT tenant_id, patient_id, ?, is_primary, assigned_at, NOW(), notes
                FROM patient_professionals
                WHERE tenant_id = ? AND professional_id = ?
            ");
            $stmt->bind_param("iii", $target_professional_id, $tenant_id, $professional_id);
            $stmt->execute();

            $stmt = $mysqli->prepare("DELETE FROM patient_professionals WHERE tenant_id = ? AND professional_id = ?");
            $stmt->bind_param("ii", $tenant_id, $professional_id);
            $stmt->execute();
        }

        $stmt = $mysqli->prepare("DELETE FROM professionals WHERE tenant_id = ? AND id = ?");
        $stmt->bind_param("ii", $tenant_id, $professional_id);
        $stmt->execute();

        $stmt = $mysqli->prepare("DELETE FROM professional_settings WHERE tenant_id = ? AND professional_id = ?");
        $stmt->bind_param("ii", $tenant_id, $professional_id);
        $stmt->execute();

        $linked_user_id = (int) ($professional['user_id'] ?? 0);
        if ($linked_user_id > 0 && in_array($professional['user_role'] ?? '', ['admin', 'reception', 'administration', 'technical'], true)) {
            $password_resets_exists = $mysqli->query("SHOW TABLES LIKE 'password_resets'");
            if ($password_resets_exists && $password_resets_exists->num_rows > 0) {
                $stmt = $mysqli->prepare("DELETE FROM password_resets WHERE tenant_id = ? AND user_id = ?");
                $stmt->bind_param("ii", $tenant_id, $linked_user_id);
                $stmt->execute();
            }
            $stmt = $mysqli->prepare("DELETE FROM users WHERE tenant_id = ? AND id = ? AND role IN ('admin', 'reception', 'administration', 'technical')");
            $stmt->bind_param("ii", $tenant_id, $linked_user_id);
            $stmt->execute();
        }

        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => $usage_total > 0 ? 'Traspaso realizado, miembro y cuenta de acceso borrados correctamente.' : 'Miembro y cuenta de acceso borrados correctamente.']);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'save_cabinet_settings') {
    ensure_action_feature($mysqli, 'team.enabled', 'El equipo de trabajo no esta disponible en este plan.');
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede gestionar el equipo.']);
        exit;
    }
    ensure_payment_settings_table($mysqli);
    ensure_cabinet_schema($mysqli);
    admin_ensure_password_reset_table($mysqli);
    $cabinet_plan_config = plan_config_for_key(dashboard_config_plan_key_from_db($mysqli));
    $cabinet_plan_allows_livekit = plan_config_feature_enabled($cabinet_plan_config, 'livekit.enabled', false);
    $cabinet_plan_allows_member_types = plan_config_feature_enabled($cabinet_plan_config, 'team.memberTypes', false);
    $cabinet_plan_allows_member_permissions = plan_config_feature_enabled($cabinet_plan_config, 'team.permissions', false);

    $show_team_public = isset($_POST['show_team_public']) && $_POST['show_team_public'] === '1' ? 1 : 0;
    $allow_patient_transfer = isset($_POST['allow_patient_transfer']) && $_POST['allow_patient_transfer'] === '1' ? 1 : 0;
    $new_patient_booking_mode = $_POST['new_patient_booking_mode'] ?? 'day_first';
    if (!in_array($new_patient_booking_mode, ['day_first', 'professional_first', 'fixed_professional'], true)) {
        $new_patient_booking_mode = 'day_first';
    }
    $new_patient_fixed_professional_id = (int) ($_POST['new_patient_fixed_professional_id'] ?? 0);
    if ($new_patient_booking_mode !== 'fixed_professional') {
        $new_patient_fixed_professional_id = 0;
    } elseif ($new_patient_fixed_professional_id <= 0) {
        $new_patient_fixed_professional_id = cabinet_superadmin_professional_id($mysqli);
        if ($new_patient_fixed_professional_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'No se pudo localizar el profesional administrador para derivar nuevos registros.']);
            exit;
        }
    } else {
        $stmt = $mysqli->prepare("
            SELECT p.id
            FROM professionals p
            LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
            WHERE p.tenant_id = ?
              AND p.id = ?
              AND p.is_active = 1
              AND u.role IN ('superadmin', 'admin')
            LIMIT 1
        ");
        $stmt->bind_param("ii", $tenant_id, $new_patient_fixed_professional_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'El profesional de derivacion no esta activo o no existe.']);
            exit;
        }
    }
    $professionals = json_decode($_POST['professionals_json'] ?? '[]', true);
    if (!is_array($professionals)) {
        echo json_encode(['success' => false, 'error' => 'Listado de profesionales invalido.']);
        exit;
    }
    $team_limit_config = plan_config_for_key(dashboard_config_plan_key_from_db($mysqli));
    $team_member_limit = plan_config_limit_value($team_limit_config, 'teamMembers', null);
    if ($team_member_limit !== null && (int) $team_member_limit > 0) {
        $team_member_limit = max(1, (int) $team_member_limit);
        $team_members = 0;
        foreach ($professionals as $professional) {
            $team_members++;
        }
        if ($team_members > $team_member_limit) {
            $limit_text = $team_member_limit === 0
                ? 'Este plan no tiene limite de miembros del equipo.'
                : 'Este plan permite un maximo de ' . $team_member_limit . ' miembro' . ($team_member_limit === 1 ? '' : 's') . ' del equipo.';
            echo json_encode(['success' => false, 'error' => $limit_text]);
            exit;
        }
    }

    $photo_index = (int) ($_POST['professional_photo_index'] ?? -1);
    $password_setup_users = [];
    $planning_cron_message = '';
    $mysqli->begin_transaction();
    try {
        $mysqli->query("INSERT IGNORE INTO payment_settings (id, tenant_id, primary_color) VALUES ($tenant_id, $tenant_id, '#4285f4')");
        $stmt = $mysqli->prepare("
            INSERT INTO payment_settings (id, tenant_id, show_team_public, allow_patient_transfer, new_patient_booking_mode, new_patient_fixed_professional_id)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                show_team_public = VALUES(show_team_public),
                allow_patient_transfer = VALUES(allow_patient_transfer),
                new_patient_booking_mode = VALUES(new_patient_booking_mode),
                new_patient_fixed_professional_id = VALUES(new_patient_fixed_professional_id)
        ");
        $fixed_professional_db = $new_patient_fixed_professional_id > 0 ? $new_patient_fixed_professional_id : null;
        $stmt->bind_param("iiiisi", $tenant_id, $tenant_id, $show_team_public, $allow_patient_transfer, $new_patient_booking_mode, $fixed_professional_db);
        $stmt->execute();

        foreach ($professionals as $index => $professional) {
            $professional_id = (int) ($professional['id'] ?? 0);
            $user_id = (int) ($professional['user_id'] ?? 0);
            $display_name = trim((string) ($professional['display_name'] ?? ''));
            $title = trim((string) ($professional['professional_title'] ?? ''));
            $license_number = trim((string) ($professional['license_number'] ?? ''));
            $specialty = trim((string) ($professional['professional_specialty'] ?? ''));
            $public_bio = trim((string) ($professional['public_bio'] ?? ''));
            $current_photo_path = trim((string) ($professional['public_photo_path'] ?? ''));
            $email = trim((string) ($professional['email'] ?? ''));
            $public_phone = trim((string) ($professional['public_phone'] ?? ''));
            $instagram_url = normalize_optional_url($professional['instagram_url'] ?? '', 'Instagram');
            $facebook_url = normalize_optional_url($professional['facebook_url'] ?? '', 'Facebook');
            $tiktok_url = normalize_optional_url($professional['tiktok_url'] ?? '', 'TikTok');
            $summary_mode = (string) ($professional['appointment_summary_email_mode'] ?? 'on_booking');
            if (!in_array($summary_mode, ['disabled', 'tomorrow_evening', 'today_morning', 'on_booking'], true)) {
                $summary_mode = 'on_booking';
            }
            $professional_session_types = normalize_available_session_types(explode(',', (string) ($professional['available_session_types'] ?? '')), $mysqli);
            $professional_session_durations = normalize_available_session_durations(explode(',', (string) ($professional['available_session_durations'] ?? '')), $mysqli);
            $default_appointment_location = trim((string) ($professional['default_appointment_location'] ?? ''));
            $default_location_id = normalize_location_id($mysqli, $professional['default_location_id'] ?? 0);
            $livekit_enabled = $cabinet_plan_allows_livekit && !empty($professional['livekit_enabled']) ? 1 : 0;
            $knowledge_sector_mode = (string) ($professional['knowledge_sector_mode'] ?? 'own');
            if (!knowledge_multi_sector_enabled($mysqli) || !in_array($knowledge_sector_mode, ['own', 'related', 'custom'], true)) {
                $knowledge_sector_mode = 'own';
            }
            $knowledge_sector_keys = normalize_knowledge_sector_keys($professional['knowledge_sector_keys'] ?? []);
            $available_knowledge_keys = array_column(available_knowledge_sectors($mysqli), 'key');
            $main_knowledge_sector = current_knowledge_sector_key($mysqli);
            $knowledge_sector_keys = array_values(array_unique(array_filter(array_merge([$main_knowledge_sector], $knowledge_sector_keys), static function ($key) use ($available_knowledge_keys, $main_knowledge_sector) {
                return $key === $main_knowledge_sector || in_array($key, $available_knowledge_keys, true);
            })));
            $knowledge_sector_keys_json = $knowledge_sector_mode === 'custom' ? json_encode($knowledge_sector_keys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            $role = (string) ($professional['role'] ?? 'admin');
            if (!in_array($role, ['superadmin', 'admin', 'reception', 'administration', 'technical'], true)) {
                $role = 'admin';
            }
            if (!$cabinet_plan_allows_member_types && $role !== 'superadmin') {
                $role = 'admin';
            }
            $member_permissions_source = $cabinet_plan_allows_member_permissions
                ? ($professional['member_permissions'] ?? [])
                : cabinet_default_member_permissions_for_role($role);
            $member_permissions_json = cabinet_member_permissions_json($member_permissions_source, $role);
            $is_active = !empty($professional['is_active']) ? 1 : 0;

            if ($display_name === '') {
                throw new \Exception('Hay un miembro sin nombre.');
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception('Hay un miembro sin email valido.');
            }

            if ($user_id <= 0) {
                $stmt = $mysqli->prepare("SELECT id, password_hash FROM users WHERE tenant_id = ? AND email = ? LIMIT 1");
                $stmt->bind_param("is", $tenant_id, $email);
                $stmt->execute();
                $existing_user = $stmt->get_result()->fetch_assoc();
                if ($existing_user) {
                    $user_id = (int) $existing_user['id'];
                    if (empty($existing_user['password_hash'])) {
                        $password_setup_users[$user_id] = ['name' => $display_name, 'email' => $email];
                    }
                } else {
                    $stmt = $mysqli->prepare("INSERT INTO users (tenant_id, name, email, phone, password_hash, role) VALUES (?, ?, ?, NULL, NULL, ?)");
                    $stmt->bind_param("isss", $tenant_id, $display_name, $email, $role);
                    $stmt->execute();
                    $user_id = $mysqli->insert_id;
                    $password_setup_users[$user_id] = ['name' => $display_name, 'email' => $email];
                }
            }

            if ($user_id === (int) $_SESSION['user_id']) {
                $role = 'superadmin';
                $is_active = 1;
            }

            $stmt = $mysqli->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE tenant_id = ? AND id = ?");
            $stmt->bind_param("sssii", $display_name, $email, $role, $tenant_id, $user_id);
            $stmt->execute();

            $slug = cabinet_slugify($display_name . '-' . $user_id);
            $sort_order = ($index + 1) * 10;
            if ($professional_id > 0) {
                $stmt = $mysqli->prepare("
                    UPDATE professionals
                    SET user_id = ?, display_name = ?, public_slug = ?, professional_title = ?, license_number = ?, professional_specialty = ?, public_bio = ?, public_photo_path = ?, public_email = ?, public_phone = ?, instagram_url = ?, facebook_url = ?, tiktok_url = ?, appointment_summary_email_mode = ?, is_active = ?, sort_order = ?
                    WHERE tenant_id = ? AND id = ?
                ");
                $stmt->bind_param("isssssssssssssiiii", $user_id, $display_name, $slug, $title, $license_number, $specialty, $public_bio, $current_photo_path, $email, $public_phone, $instagram_url, $facebook_url, $tiktok_url, $summary_mode, $is_active, $sort_order, $tenant_id, $professional_id);
            } else {
                $stmt = $mysqli->prepare("
                    INSERT INTO professionals (tenant_id, user_id, display_name, public_slug, professional_title, license_number, professional_specialty, public_bio, public_photo_path, public_email, public_phone, instagram_url, facebook_url, tiktok_url, appointment_summary_email_mode, is_active, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), professional_title = VALUES(professional_title), license_number = VALUES(license_number), professional_specialty = VALUES(professional_specialty), public_bio = VALUES(public_bio), public_photo_path = VALUES(public_photo_path), public_email = VALUES(public_email), public_phone = VALUES(public_phone), instagram_url = VALUES(instagram_url), facebook_url = VALUES(facebook_url), tiktok_url = VALUES(tiktok_url), appointment_summary_email_mode = VALUES(appointment_summary_email_mode), is_active = VALUES(is_active), sort_order = VALUES(sort_order)
                ");
                $stmt->bind_param("iisssssssssssssii", $tenant_id, $user_id, $display_name, $slug, $title, $license_number, $specialty, $public_bio, $current_photo_path, $email, $public_phone, $instagram_url, $facebook_url, $tiktok_url, $summary_mode, $is_active, $sort_order);
            }
            $stmt->execute();
            $saved_professional_id = $professional_id > 0 ? $professional_id : (int) $mysqli->insert_id;
            if ($saved_professional_id <= 0) {
                $stmt = $mysqli->prepare("SELECT id FROM professionals WHERE tenant_id = ? AND user_id = ? LIMIT 1");
                $stmt->bind_param("ii", $tenant_id, $user_id);
                $stmt->execute();
                $saved_row = $stmt->get_result()->fetch_assoc();
                $saved_professional_id = $saved_row ? (int) $saved_row['id'] : 0;
            }
            if ($saved_professional_id > 0) {
                cabinet_seed_professional_settings_from_superadmin($mysqli, $saved_professional_id);
                $stmt = $mysqli->prepare("
                    UPDATE professional_settings
                    SET available_session_types = ?, available_session_durations = ?, default_appointment_location = ?, default_location_id = ?, livekit_enabled = ?, member_permissions_json = ?
                    WHERE tenant_id = ? AND professional_id = ?
                ");
                $stmt->bind_param("sssiisii", $professional_session_types, $professional_session_durations, $default_appointment_location, $default_location_id, $livekit_enabled, $member_permissions_json, $tenant_id, $saved_professional_id);
                $stmt->execute();
                if (professional_knowledge_sector_columns_available($mysqli)) {
                    $stmt = $mysqli->prepare("
                        UPDATE professional_settings
                        SET knowledge_sector_mode = ?, knowledge_sector_keys_json = ?
                        WHERE tenant_id = ? AND professional_id = ?
                    ");
                    $stmt->bind_param("ssii", $knowledge_sector_mode, $knowledge_sector_keys_json, $tenant_id, $saved_professional_id);
                    $stmt->execute();
                }
            }

            if ($photo_index === $index && isset($_FILES['professional_photo']) && ($_FILES['professional_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ($saved_professional_id <= 0) {
                    throw new \Exception('No se pudo localizar el profesional para guardar la foto.');
                }
                $uploaded_photo_path = save_uploaded_professional_photo($_FILES['professional_photo'], $saved_professional_id);
                $stmt = $mysqli->prepare("UPDATE professionals SET public_photo_path = ? WHERE tenant_id = ? AND id = ?");
                $stmt->bind_param("sii", $uploaded_photo_path, $tenant_id, $saved_professional_id);
                $stmt->execute();
            }
        }

        foreach ($password_setup_users as $setup_user_id => $setup_user) {
            if (!send_professional_password_setup_email($mysqli, (int) $setup_user_id, $setup_user['name'], $setup_user['email'])) {
                throw new \Exception('No se pudo enviar el email para crear la contraseña del miembro. Revisa la configuración de email.');
            }
        }
        $app_name_res = $mysqli->query("SELECT app_name FROM payment_settings WHERE tenant_id = $tenant_id");
        $app_name_row = $app_name_res ? $app_name_res->fetch_assoc() : null;
        $planning_sync = fastcron_sync_professional_planning_cron($mysqli, $app_name_row['app_name'] ?? '');
        if (($planning_sync['action'] ?? '') === 'created') {
            $planning_cron_message = ' Cron de planning creado.';
        } elseif (($planning_sync['action'] ?? '') === 'linked_existing') {
            $planning_cron_message = ' Cron de planning vinculado.';
        } elseif (($planning_sync['action'] ?? '') === 'deleted') {
            $planning_cron_message = ' Cron de planning eliminado.';
        }
        $mysqli->commit();
        $settings_res = $mysqli->query("SELECT show_team_public, allow_patient_transfer, new_patient_booking_mode, new_patient_fixed_professional_id FROM payment_settings WHERE tenant_id = $tenant_id");
        $saved_settings = $settings_res ? $settings_res->fetch_assoc() : ['show_team_public' => $show_team_public, 'allow_patient_transfer' => $allow_patient_transfer, 'new_patient_booking_mode' => $new_patient_booking_mode, 'new_patient_fixed_professional_id' => $new_patient_fixed_professional_id];
        echo json_encode([
            'success' => true,
            'message' => trim('Equipo guardado correctamente.' . $planning_cron_message),
            'settings' => [
                'show_team_public' => (int) ($saved_settings['show_team_public'] ?? 0),
                'allow_patient_transfer' => (int) ($saved_settings['allow_patient_transfer'] ?? 0),
                'new_patient_booking_mode' => $saved_settings['new_patient_booking_mode'] ?? 'day_first',
                'new_patient_fixed_professional_id' => (int) ($saved_settings['new_patient_fixed_professional_id'] ?? 0)
            ]
        ]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'get_payment_settings') {
    ensure_payment_settings_table($mysqli);
    ensure_cabinet_schema($mysqli);
    dashboard_config_ensure_files();
    sector_texts_ensure_payment_column($mysqli);
    $sms_columns_available = column_exists($mysqli, 'payment_settings', 'sms_provider')
        && column_exists($mysqli, 'payment_settings', 'sms_reminder_hours');
    $sms_settings_select = $sms_columns_available
        ? "sms_provider, sms_sender, sms_username, sms_reminder_enabled, sms_reminder_hours"
        : "'none' AS sms_provider, '' AS sms_sender, '' AS sms_username, 0 AS sms_reminder_enabled, 24 AS sms_reminder_hours";
    $sms_secret_select = $sms_columns_available
        ? "sms_password IS NOT NULL AND sms_password != '' AS has_sms_password,
               sms_api_key IS NOT NULL AND sms_api_key != '' AS has_sms_api_key"
        : "0 AS has_sms_password,
               0 AS has_sms_api_key";

    $res = $mysqli->query("
        SELECT app_name, site_tagline, site_phone, profile_image_path, landing_image_path, primary_color, show_profile_image_public, show_prices_public, show_contact_public, plan_key, online_booking_enabled, patient_registration_mode, patient_tasks_visible_default, work_plan_task_status_enabled, initial_calendar_view, bonuses_enabled, create_compensation_bonus_on_paid_cancel, online_payment_enabled, environment, merchant_code, terminal,
               appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price, admin_notification_email,
               appointment_delivery_mode, available_session_types, available_session_durations, display_effective_duration_enabled, display_duration_offset_minutes,
               appointment_reminder_enabled, appointment_second_reminder_enabled, appointment_second_reminder_hours,
               min_booking_notice_days, max_booking_notice_days, appointment_start_time, appointment_end_time, break_start_time, break_end_time,
               available_weekdays,
               email_provider, smtp_host, smtp_port, smtp_username, smtp_secure, smtp_from_email, smtp_from_name,
               $sms_settings_select,
               google_connected_email, google_redirect_uri, calendar_provider, google_calendar_enabled, google_calendar_id,
               icloud_calendar_email, icloud_calendar_url, send_patient_calendar_link,
               fastcron_reminder_cron_id,
               legal_owner_name, legal_nif, legal_address, legal_email, legal_license_number, legal_professional_college, legal_uses_non_technical_cookies, legal_terms_notes,
               billing_enabled, billing_country, billing_province, billing_session_concept, billing_report_concept,
               allow_patient_transfer, dashboard_config_mode, sector_texts_key,
               merchant_key IS NOT NULL AND merchant_key != '' AS has_merchant_key,
               smtp_password IS NOT NULL AND smtp_password != '' AS has_smtp_password,
               $sms_secret_select,
               google_refresh_token IS NOT NULL AND google_refresh_token != '' AS has_google_refresh_token,
               icloud_calendar_app_password IS NOT NULL AND icloud_calendar_app_password != '' AS has_icloud_calendar_app_password,
               fastcron_api_key IS NOT NULL AND fastcron_api_key != '' AS has_fastcron_api_key
        FROM payment_settings
        WHERE tenant_id = $tenant_id
    ");
    $settings = $res->fetch_assoc();
    $current_professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
    if ($current_professional_id > 0) {
        $professional_settings = cabinet_get_effective_professional_settings($mysqli, $current_professional_id);
        foreach ($professional_settings as $key => $value) {
            $settings[$key] = $value;
        }
    }
    $settings['current_professional_id'] = $current_professional_id;
    $tenant = function_exists('current_tenant') ? current_tenant() : null;
    $tenant_plan_key = plan_config_normalize_key(is_array($tenant) ? ($tenant['plan_key'] ?? '') : '', '');
    $settings['plan_key'] = $tenant_plan_key !== '' ? $tenant_plan_key : 'novus';
    $dashboard_config_mode = dashboard_config_effective_mode_from_db($mysqli, $settings['plan_key']);
    $settings['dashboard_config_mode'] = $dashboard_config_mode;
    $settings['dashboard_config'] = dashboard_config_for_mode($dashboard_config_mode);
    $settings['plan_config'] = plan_config_for_key($settings['plan_key']);
    if (!plan_config_feature_enabled($settings['plan_config'], 'billing.enabled', false)) {
        $settings['billing_enabled'] = 0;
    }
    $settings['livekit_plan_enabled'] = plan_config_feature_enabled($settings['plan_config'], 'livekit.enabled', false) ? 1 : 0;
    if (!$settings['livekit_plan_enabled']) {
        $settings['livekit_enabled'] = 0;
    }
    $settings['custom_domain_plan_enabled'] = plan_config_feature_enabled($settings['plan_config'], 'branding.customDomain', false) ? 1 : 0;
    $settings['custom_domain'] = '';
    if ($settings['custom_domain_plan_enabled']) {
        ensure_tenant_domains_runtime_table($mysqli);
        $stmt = $mysqli->prepare("SELECT domain FROM tenant_domains WHERE tenant_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1");
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $settings['custom_domain'] = tenant_domain_normalize($stmt->get_result()->fetch_assoc()['domain'] ?? '');
    }
    $settings['google_oauth_configured'] = google_oauth_credentials_configured() ? 1 : 0;
    $sector_texts_key = sector_texts_validate_key($settings['sector_texts_key'] ?? '') ? $settings['sector_texts_key'] : sector_texts_default_key();
    $settings['sector_texts_key'] = sector_texts_read_file($sector_texts_key) ? $sector_texts_key : sector_texts_default_key();
    $settings['knowledge_base_has_sector_data'] = knowledge_base_sector_has_data($mysqli, $settings['sector_texts_key']) ? 1 : 0;
    $settings['sector_texts'] = sector_texts_for_key($settings['sector_texts_key'], $settings['dashboard_config'], $settings['plan_config']);
    $settings['sector_texts_options'] = sector_texts_available();

    echo json_encode([
        'success' => true,
        'settings' => $settings,
        'message_templates' => message_templates_get_all($mysqli),
        'message_template_variables' => message_template_available_variables(),
        'services' => fetch_appointment_services($mysqli),
        'locations' => fetch_appointment_locations($mysqli),
        'bonuses' => fetch_appointment_bonuses($mysqli)
    ]);
} elseif ($action === 'get_message_template') {
    ensure_message_templates_table($mysqli);
    $template_key = trim((string) ($_GET['template_key'] ?? ''));
    $template = message_template_get($mysqli, $template_key);
    if (!$template) {
        echo json_encode(['success' => false, 'error' => 'Plantilla no valida.']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'template' => $template,
        'variables' => message_template_available_variables()
    ]);
} elseif ($action === 'save_message_template') {
    ensure_message_templates_table($mysqli);
    try {
        $template_key = trim((string) ($_POST['template_key'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($template_key === 'appointment_email_reminder' && $subject === '') {
            $subject = message_template_default_subject($template_key);
        }
        message_template_save($mysqli, $template_key, $subject, $body);
        echo json_encode([
            'success' => true,
            'message' => 'Plantilla guardada correctamente.',
            'template' => message_template_get($mysqli, $template_key)
        ]);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'get_dashboard_custom_config') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede editar la configuracion personalizada.']);
        exit;
    }
    dashboard_config_ensure_files();
    $files = dashboard_config_files();
    $raw = file_get_contents($files['custom']);
    echo json_encode(['success' => true, 'json' => $raw === false ? '' : $raw]);
} elseif ($action === 'get_custom_domain') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede configurar dominios personalizados.']);
        exit;
    }
    $enabled = app_feature_enabled_from_db($mysqli, 'branding.customDomain', false);
    ensure_tenant_domains_runtime_table($mysqli);
    $domain = '';
    if ($enabled) {
        $stmt = $mysqli->prepare("SELECT domain FROM tenant_domains WHERE tenant_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1");
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $domain = tenant_domain_normalize($stmt->get_result()->fetch_assoc()['domain'] ?? '');
    }
    echo json_encode([
        'success' => true,
        'enabled' => $enabled ? 1 : 0,
        'domain' => $domain
    ]);
} elseif ($action === 'save_custom_domain') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede configurar dominios personalizados.']);
        exit;
    }
    if (!app_feature_enabled_from_db($mysqli, 'branding.customDomain', false)) {
        echo json_encode(['success' => false, 'error' => 'El dominio personalizado solo esta disponible en el plan Summum.']);
        exit;
    }

    ensure_tenant_domains_runtime_table($mysqli);
    $error = '';
    $domain = normalize_custom_domain_input($_POST['domain'] ?? '', $error);
    if ($error !== '') {
        echo json_encode(['success' => false, 'error' => $error]);
        exit;
    }

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("DELETE FROM tenant_domains WHERE tenant_id = ?");
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();

        if ($domain !== '') {
            $stmt = $mysqli->prepare("INSERT INTO tenant_domains (tenant_id, domain, is_primary) VALUES (?, ?, 1)");
            $stmt->bind_param("is", $tenant_id, $domain);
            $stmt->execute();
        }

        $mysqli->commit();
        echo json_encode([
            'success' => true,
            'domain' => $domain,
            'message' => $domain !== '' ? 'Dominio personalizado guardado.' : 'Dominio personalizado eliminado.'
        ]);
    } catch (\mysqli_sql_exception $e) {
        $mysqli->rollback();
        $message = ((int) $e->getCode() === 1062)
            ? 'Ese dominio ya esta asignado a otro tenant.'
            : 'No se pudo guardar el dominio personalizado.';
        echo json_encode(['success' => false, 'error' => $message]);
    }
} elseif ($action === 'list_invoices') {
    ensure_invoice_schema($mysqli);
    if (!invoice_billing_enabled($mysqli)) {
        echo json_encode(['success' => false, 'error' => 'La facturacion no esta activada.']);
        exit;
    }

    $search = trim((string) ($_GET['search'] ?? ''));
    $date_from = trim((string) ($_GET['date_from'] ?? ''));
    $date_to = trim((string) ($_GET['date_to'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $date_to = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $date_from = date('Y-m-d', strtotime($date_to . ' -30 days'));
    }
    if ($date_from > $date_to) {
        [$date_from, $date_to] = [$date_to, $date_from];
    }

    $where = "tenant_id = ? AND tipo_movim = 'factura' AND fecha BETWEEN ? AND ?";
    $types = "iss";
    $params = [$tenant_id, $date_from, $date_to];
    if ($search !== '') {
        $where .= " AND (numero_factura LIKE ? OR destinatario_nombre LIKE ? OR destinatario_nif LIKE ? OR concepto LIKE ?)";
        $like = '%' . $search . '%';
        $types .= "ssss";
        array_push($params, $like, $like, $like, $like);
    }

    $rows = [];
    $stmt = $mysqli->prepare("
        SELECT id, numero_factura, fecha, destinatario_nombre, destinatario_nif, concepto,
               base_imponible, iva_porcentaje, iva_importe, total, forma_pago, verifactu_estado, created_at
        FROM movim
        WHERE $where
        ORDER BY fecha DESC, id DESC
        LIMIT 200
    ");
    bind_params_dynamic($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int) $row['id'],
            'numero_factura' => $row['numero_factura'],
            'fecha' => $row['fecha'],
            'destinatario_nombre' => $row['destinatario_nombre'],
            'destinatario_nif' => $row['destinatario_nif'],
            'concepto' => $row['concepto'],
            'base_imponible' => number_format((float) $row['base_imponible'], 2, '.', ''),
            'iva_porcentaje' => number_format((float) $row['iva_porcentaje'], 2, '.', ''),
            'iva_importe' => number_format((float) $row['iva_importe'], 2, '.', ''),
            'total' => number_format((float) $row['total'], 2, '.', ''),
            'forma_pago' => $row['forma_pago'] ?? '',
            'verifactu_estado' => $row['verifactu_estado'] ?? '',
            'created_at' => $row['created_at']
        ];
    }

    echo json_encode(['success' => true, 'invoices' => $rows, 'date_from' => $date_from, 'date_to' => $date_to]);
} elseif ($action === 'save_dashboard_custom_config') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede editar la configuracion personalizada.']);
        exit;
    }
    if (!app_feature_enabled_from_db($mysqli, 'ui.customization', false)) {
        echo json_encode(['success' => false, 'error' => 'La personalizacion de interfaz no esta disponible en este plan.']);
        exit;
    }
    $json = $_POST['json'] ?? '';
    $error = '';
    if (!dashboard_config_save_custom_json($json, $error)) {
        echo json_encode(['success' => false, 'error' => $error ?: 'No se pudo guardar la configuracion personalizada.']);
        exit;
    }
    $stmt = $mysqli->prepare("UPDATE payment_settings SET dashboard_config_mode = 'custom' WHERE tenant_id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    echo json_encode(['success' => true, 'message' => 'Configuracion personalizada guardada. Se refrescara la ventana para cargar la nueva configuracion.']);
} elseif ($action === 'save_bonuses') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede modificar bonos globales.']);
        exit;
    }
    if (!app_feature_enabled_from_db($mysqli, 'bonuses.enabled', false)) {
        echo json_encode(['success' => false, 'error' => 'Los bonos no estan disponibles en este plan.']);
        exit;
    }
    ensure_payment_settings_table($mysqli);
    ensure_bonus_tables($mysqli);

    $bonuses_enabled = isset($_POST['bonuses_enabled']) && $_POST['bonuses_enabled'] === '1' ? 1 : 0;
    $create_compensation_bonus = isset($_POST['create_compensation_bonus_on_paid_cancel']) && $_POST['create_compensation_bonus_on_paid_cancel'] === '1' ? 1 : 0;
    $bonuses_json = $_POST['bonuses_json'] ?? '';
    $bonuses = json_decode($bonuses_json, true);
    if (!is_array($bonuses)) {
        echo json_encode(['success' => false, 'error' => 'Configuracion de bonos invalida']);
        exit;
    }

    $mysqli->begin_transaction();
    try {
        foreach ($bonuses as $bonus) {
            $bonus_id = (int) ($bonus['id'] ?? 0);
            $name = trim((string) ($bonus['name'] ?? ''));
            $session_count = (int) ($bonus['session_count'] ?? 0);
            $price = str_replace(',', '.', trim((string) ($bonus['price'] ?? '')));
            $is_active = !empty($bonus['is_active']) ? 1 : 0;

            if ($bonus_id <= 0 || $name === '' || !in_array($session_count, [4, 10], true)) {
                throw new \Exception('Hay un bono sin nombre, sesiones o identificador valido.');
            }
            if (!is_numeric($price) || (float) $price < 0) {
                throw new \Exception('Hay un precio de bono no valido.');
            }
            if (!$bonuses_enabled) {
                $is_active = 0;
            }

            $price = (float) $price;
            $stmt = $mysqli->prepare("
                UPDATE appointment_bonuses
                SET name = ?, session_count = ?, price = ?, is_active = ?
                WHERE tenant_id = ? AND id = ?
            ");
            $stmt->bind_param("sidiii", $name, $session_count, $price, $is_active, $tenant_id, $bonus_id);
            $stmt->execute();
        }

        $stmt = $mysqli->prepare("UPDATE payment_settings SET bonuses_enabled = ?, create_compensation_bonus_on_paid_cancel = ? WHERE tenant_id = $tenant_id");
        $stmt->bind_param("ii", $bonuses_enabled, $create_compensation_bonus);
        $stmt->execute();

        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => 'Bonos guardados correctamente.', 'bonuses' => fetch_appointment_bonuses($mysqli)]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'create_service_catalog_item') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede modificar servicios globales.']);
        exit;
    }
    ensure_payment_settings_table($mysqli);
    ensure_appointment_services_tables($mysqli);

    $item_type = $_POST['item_type'] ?? '';
    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
    $settings_res = $mysqli->query("SELECT available_session_types, available_session_durations, appointment_delivery_mode, appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price FROM payment_settings WHERE tenant_id = $tenant_id");
    $settings_row = $settings_res ? ($settings_res->fetch_assoc() ?: []) : [];
    $available_session_types = $settings_row['available_session_types'] ?? 'individual';
    $available_session_durations = $settings_row['available_session_durations'] ?? '60';
    $appointment_delivery_mode = $settings_row['appointment_delivery_mode'] ?? 'both';

    $mysqli->begin_transaction();
    try {
        if ($item_type === 'service') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                throw new \Exception('Indica el nombre del servicio.');
            }
            $base_key = preg_replace('/[^a-z0-9_]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name));
            $base_key = trim($base_key, '_');
            if ($base_key === '' || strlen($base_key) < 2) {
                $base_key = 'servicio';
            }
            $base_key = substr($base_key, 0, 24);
            $service_key = $base_key;
            $suffix = 2;
            while (true) {
                $stmt = $mysqli->prepare("SELECT id FROM appointment_services WHERE tenant_id = ? AND service_key = ? LIMIT 1");
                $stmt->bind_param("is", $tenant_id, $service_key);
                $stmt->execute();
                if (!$stmt->get_result()->fetch_assoc()) {
                    break;
                }
                $service_key = substr($base_key . '_' . $suffix++, 0, 32);
            }
            $sort_res = $mysqli->query("SELECT COALESCE(MAX(sort_order), 0) + 10 AS next_sort FROM appointment_services WHERE tenant_id = $tenant_id");
            $sort_order = (int) (($sort_res ? $sort_res->fetch_assoc() : null)['next_sort'] ?? 999);
            $is_active = $enabled ? 1 : 0;
            $stmt = $mysqli->prepare("INSERT INTO appointment_services (tenant_id, service_key, name, is_active, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issii", $tenant_id, $service_key, $name, $is_active, $sort_order);
            $stmt->execute();
            $service_id = (int) $mysqli->insert_id;
            $durations = array_filter(array_map('intval', explode(',', $available_session_durations ?: '60')));
            if (!$durations) {
                $durations = [60];
            }
            foreach ($durations as $duration) {
                foreach (['presencial', 'online'] as $consultation_type) {
                    $option_active = $enabled && ($appointment_delivery_mode === 'both' || $appointment_delivery_mode === $consultation_type) ? 1 : 0;
                    $price = appointment_default_option_price($settings_row, $service_key, $consultation_type, $duration);
                    $sort = ($duration * 10) + ($consultation_type === 'online' ? 1 : 0);
                    $stmt = $mysqli->prepare("INSERT INTO appointment_service_options (tenant_id, service_id, duration_minutes, consultation_type, price, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiisdii", $tenant_id, $service_id, $duration, $consultation_type, $price, $option_active, $sort);
                    $stmt->execute();
                }
            }
            if ($enabled) {
                $types = array_filter(array_map('trim', explode(',', $available_session_types)));
                $types[] = $service_key;
                $available_session_types = implode(',', array_unique($types));
                $stmt = $mysqli->prepare("UPDATE payment_settings SET available_session_types = ? WHERE tenant_id = $tenant_id");
                $stmt->bind_param("s", $available_session_types);
                $stmt->execute();
            }
            $message = 'Servicio creado.';
        } elseif ($item_type === 'duration') {
            $duration = (int) ($_POST['minutes'] ?? 0);
            if ($duration <= 0 || $duration > 480) {
                throw new \Exception('Indica una duración válida entre 1 y 480 minutos.');
            }
            $exists = $mysqli->query("SELECT id FROM appointment_service_options WHERE tenant_id = $tenant_id AND duration_minutes = $duration LIMIT 1");
            if ($exists && $exists->num_rows > 0) {
                throw new \Exception('Esa duración ya existe.');
            }
            $services = fetch_appointment_services($mysqli);
            foreach ($services as $service) {
                foreach (['presencial', 'online'] as $consultation_type) {
                    $service_enabled = (int) ($service['is_active'] ?? 0) === 1;
                    $option_active = $enabled && $service_enabled && ($appointment_delivery_mode === 'both' || $appointment_delivery_mode === $consultation_type) ? 1 : 0;
                    $price = appointment_default_option_price($settings_row, $service['service_key'], $consultation_type, $duration);
                    $sort = ($duration * 10) + ($consultation_type === 'online' ? 1 : 0);
                    $stmt = $mysqli->prepare("INSERT INTO appointment_service_options (tenant_id, service_id, duration_minutes, consultation_type, price, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiisdii", $tenant_id, $service['id'], $duration, $consultation_type, $price, $option_active, $sort);
                    $stmt->execute();
                }
            }
            if ($enabled) {
                $durations = array_filter(array_map('intval', explode(',', $available_session_durations)));
                $durations[] = $duration;
                $durations = array_values(array_unique($durations));
                sort($durations);
                $available_session_durations = implode(',', $durations);
                $stmt = $mysqli->prepare("UPDATE payment_settings SET available_session_durations = ? WHERE tenant_id = $tenant_id");
                $stmt->bind_param("s", $available_session_durations);
                $stmt->execute();
            }
            $message = 'Duración creada.';
        } else {
            throw new \Exception('Elemento no válido.');
        }

        sync_service_availability($mysqli, $available_session_types, $available_session_durations, $appointment_delivery_mode);
        $mysqli->commit();
        app_log($mysqli, [
            'action' => 'patient_professional_transferred',
            'status' => 'ok',
            'target_type' => 'patient',
            'target_id' => $patient_id,
            'title' => $current_professional_id > 0 ? 'Paciente traspasado' : 'Profesional asignado',
            'message' => ($current_professional_id > 0 ? 'Paciente traspasado' : 'Profesional asignado') . ' a ' . ($target_professional['display_name'] ?? '') . '.',
            'metadata' => [
                'patient_id' => $patient_id,
                'previous_professional_id' => (int) $current_professional_id,
                'new_professional_id' => (int) $target_professional_id,
                'new_professional_name' => $target_professional['display_name'] ?? '',
                'moved_appointments' => (int) $moved_appointments
            ]
        ]);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'services' => fetch_appointment_services($mysqli),
            'available_session_types' => $available_session_types,
            'available_session_durations' => $available_session_durations
        ]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'delete_service_catalog_item') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede modificar servicios globales.']);
        exit;
    }
    ensure_payment_settings_table($mysqli);
    ensure_appointment_services_tables($mysqli);

    $item_type = $_POST['item_type'] ?? '';
    $item_key = trim((string) ($_POST['item_key'] ?? ''));
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $sector_config = sector_appointment_config_for_db($mysqli);
    $sector_service_keys = array_column($sector_config['services'], 'key');
    $sector_durations = array_map(fn($item) => (int) $item['minutes'], $sector_config['durations']);

    $mysqli->begin_transaction();
    try {
        if ($item_type === 'service') {
            if ($item_key === '' || in_array($item_key, $sector_service_keys, true)) {
                throw new \Exception('Este servicio pertenece al sector y no se puede eliminar. Puedes desactivarlo.');
            }
            $stmt = $mysqli->prepare("SELECT id FROM appointment_services WHERE tenant_id = ? AND service_key = ? LIMIT 1");
            $stmt->bind_param("is", $tenant_id, $item_key);
            $stmt->execute();
            $service = $stmt->get_result()->fetch_assoc();
            if (!$service) {
                throw new \Exception('No se encontró el servicio.');
            }
            $service_id = (int) $service['id'];
            $stmt = $mysqli->prepare("
                SELECT COUNT(*) AS total
                FROM appointments a
                JOIN appointment_service_options o ON o.id = a.service_option_id AND o.tenant_id = a.tenant_id
                WHERE a.tenant_id = ? AND o.service_id = ?
            ");
            $stmt->bind_param("ii", $tenant_id, $service_id);
            $stmt->execute();
            $used = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            if ($used > 0) {
                $stmt = $mysqli->prepare("UPDATE appointment_services SET is_active = 0 WHERE tenant_id = ? AND id = ?");
                $stmt->bind_param("ii", $tenant_id, $service_id);
                $stmt->execute();
                $stmt = $mysqli->prepare("UPDATE appointment_service_options SET is_active = 0 WHERE tenant_id = ? AND service_id = ?");
                $stmt->bind_param("ii", $tenant_id, $service_id);
                $stmt->execute();
                $message = 'El servicio tiene citas asociadas, así que se ha desactivado.';
            } else {
                $stmt = $mysqli->prepare("DELETE FROM appointment_service_options WHERE tenant_id = ? AND service_id = ?");
                $stmt->bind_param("ii", $tenant_id, $service_id);
                $stmt->execute();
                $stmt = $mysqli->prepare("DELETE FROM appointment_services WHERE tenant_id = ? AND id = ?");
                $stmt->bind_param("ii", $tenant_id, $service_id);
                $stmt->execute();
                $message = 'Servicio eliminado.';
            }
            $settings_res = $mysqli->query("SELECT available_session_types FROM payment_settings WHERE tenant_id = $tenant_id");
            $settings_row = $settings_res ? $settings_res->fetch_assoc() : [];
            $available_session_types = csv_without_value($settings_row['available_session_types'] ?? '', $item_key);
            if ($available_session_types === '') {
                $fallback = $mysqli->query("SELECT service_key FROM appointment_services WHERE tenant_id = $tenant_id AND is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1");
                $fallback_row = $fallback ? $fallback->fetch_assoc() : null;
                $available_session_types = $fallback_row['service_key'] ?? 'individual';
            }
            $stmt = $mysqli->prepare("UPDATE payment_settings SET available_session_types = ? WHERE tenant_id = $tenant_id");
            $stmt->bind_param("s", $available_session_types);
            $stmt->execute();
            $stmt = $mysqli->prepare("UPDATE professional_settings SET available_session_types = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', available_session_types, ','), ?, ',')) WHERE tenant_id = ?");
            $needle = ',' . $item_key . ',';
            $stmt->bind_param("si", $needle, $tenant_id);
            $stmt->execute();
        } elseif ($item_type === 'duration') {
            $duration = (int) $item_key;
            if ($duration <= 0 || in_array($duration, $sector_durations, true)) {
                throw new \Exception('Esta duración pertenece al sector y no se puede eliminar. Puedes desactivarla.');
            }
            $stmt = $mysqli->prepare("
                SELECT COUNT(*) AS total
                FROM appointments a
                JOIN appointment_service_options o ON o.id = a.service_option_id AND o.tenant_id = a.tenant_id
                WHERE a.tenant_id = ? AND o.duration_minutes = ?
            ");
            $stmt->bind_param("ii", $tenant_id, $duration);
            $stmt->execute();
            $used = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            if ($used > 0) {
                $stmt = $mysqli->prepare("UPDATE appointment_service_options SET is_active = 0 WHERE tenant_id = ? AND duration_minutes = ?");
                $stmt->bind_param("ii", $tenant_id, $duration);
                $stmt->execute();
                $message = 'La duración tiene citas asociadas, así que se ha desactivado.';
            } else {
                $stmt = $mysqli->prepare("DELETE FROM appointment_service_options WHERE tenant_id = ? AND duration_minutes = ?");
                $stmt->bind_param("ii", $tenant_id, $duration);
                $stmt->execute();
                $message = 'Duración eliminada.';
            }
            $settings_res = $mysqli->query("SELECT available_session_durations FROM payment_settings WHERE tenant_id = $tenant_id");
            $settings_row = $settings_res ? $settings_res->fetch_assoc() : [];
            $available_session_durations = csv_without_value($settings_row['available_session_durations'] ?? '', $duration, true);
            if ($available_session_durations === '') {
                $available_session_durations = '60';
            }
            $stmt = $mysqli->prepare("UPDATE payment_settings SET available_session_durations = ? WHERE tenant_id = $tenant_id");
            $stmt->bind_param("s", $available_session_durations);
            $stmt->execute();
            $stmt = $mysqli->prepare("UPDATE professional_settings SET available_session_durations = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', available_session_durations, ','), ?, ',')) WHERE tenant_id = ?");
            $needle = ',' . $duration . ',';
            $stmt->bind_param("si", $needle, $tenant_id);
            $stmt->execute();
        } else {
            throw new \Exception('Elemento no válido.');
        }

        $settings_res = $mysqli->query("SELECT available_session_types, available_session_durations, appointment_delivery_mode FROM payment_settings WHERE tenant_id = $tenant_id");
        $settings_row = $settings_res ? $settings_res->fetch_assoc() : [];
        sync_service_availability($mysqli, $settings_row['available_session_types'] ?? 'individual', $settings_row['available_session_durations'] ?? '60', $settings_row['appointment_delivery_mode'] ?? 'both');
        $mysqli->commit();
        echo json_encode([
            'success' => true,
            'message' => $message,
            'services' => fetch_appointment_services($mysqli),
            'available_session_types' => $settings_row['available_session_types'] ?? '',
            'available_session_durations' => $settings_row['available_session_durations'] ?? ''
        ]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'create_location_catalog_item') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede modificar ubicaciones globales.']);
        exit;
    }
    ensure_payment_settings_table($mysqli);
    ensure_appointment_locations_table($mysqli);

    $name = trim((string) ($_POST['name'] ?? ''));
    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1' ? 1 : 0;
    if ($name === '') {
        echo json_encode(['success' => false, 'error' => 'Indica el nombre de la ubicacion.']);
        exit;
    }

    $base_key = preg_replace('/[^a-z0-9_]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name));
    $base_key = trim($base_key, '_');
    if ($base_key === '' || strlen($base_key) < 2) {
        $base_key = 'ubicacion';
    }
    $base_key = substr($base_key, 0, 64);
    $location_key = $base_key;
    $suffix = 2;
    while (true) {
        $stmt = $mysqli->prepare("SELECT id FROM appointment_locations WHERE tenant_id = ? AND location_key = ? LIMIT 1");
        $stmt->bind_param("is", $tenant_id, $location_key);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            break;
        }
        $location_key = substr($base_key . '_' . $suffix++, 0, 80);
    }
    $sort_res = $mysqli->query("SELECT COALESCE(MAX(sort_order), 0) + 10 AS next_sort FROM appointment_locations WHERE tenant_id = $tenant_id");
    $sort_order = (int) (($sort_res ? $sort_res->fetch_assoc() : null)['next_sort'] ?? 999);
    $stmt = $mysqli->prepare("
        INSERT INTO appointment_locations (tenant_id, location_key, name, location_type, is_enabled, is_system, sort_order)
        VALUES (?, ?, ?, 'custom', ?, 0, ?)
    ");
    $stmt->bind_param("issii", $tenant_id, $location_key, $name, $enabled, $sort_order);
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'message' => 'Ubicacion creada.',
        'locations' => fetch_appointment_locations($mysqli)
    ]);
} elseif ($action === 'delete_location_catalog_item') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede modificar ubicaciones globales.']);
        exit;
    }
    ensure_payment_settings_table($mysqli);
    ensure_appointment_locations_table($mysqli);

    $location_id = (int) ($_POST['location_id'] ?? 0);
    $stmt = $mysqli->prepare("SELECT id, name, is_system FROM appointment_locations WHERE tenant_id = ? AND id = ? LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $location_id);
    $stmt->execute();
    $location = $stmt->get_result()->fetch_assoc();
    if (!$location) {
        echo json_encode(['success' => false, 'error' => 'No se encontro la ubicacion.']);
        exit;
    }
    if ((int) ($location['is_system'] ?? 0) === 1) {
        echo json_encode(['success' => false, 'error' => 'Esta ubicacion pertenece al sistema y no se puede eliminar. Puedes desactivarla si procede.']);
        exit;
    }

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM appointments WHERE tenant_id = ? AND location_id = ?");
        $stmt->bind_param("ii", $tenant_id, $location_id);
        $stmt->execute();
        $used_appointments = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM professional_settings WHERE tenant_id = ? AND default_location_id = ?");
        $stmt->bind_param("ii", $tenant_id, $location_id);
        $stmt->execute();
        $used_professionals = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);

        if ($used_appointments > 0 || $used_professionals > 0) {
            $stmt = $mysqli->prepare("UPDATE appointment_locations SET is_enabled = 0 WHERE tenant_id = ? AND id = ?");
            $stmt->bind_param("ii", $tenant_id, $location_id);
            $stmt->execute();
            $message = 'La ubicacion esta en uso, asi que se ha desactivado.';
        } else {
            $stmt = $mysqli->prepare("DELETE FROM appointment_locations WHERE tenant_id = ? AND id = ?");
            $stmt->bind_param("ii", $tenant_id, $location_id);
            $stmt->execute();
            $message = 'Ubicacion eliminada.';
        }
        $mysqli->commit();
        echo json_encode([
            'success' => true,
            'message' => $message,
            'locations' => fetch_appointment_locations($mysqli)
        ]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'save_services') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede modificar precios globales.']);
        exit;
    }
    ensure_payment_settings_table($mysqli);
    ensure_appointment_services_tables($mysqli);

    $services_json = $_POST['services_json'] ?? '';
    $services = json_decode($services_json, true);
    $available_session_durations = normalize_available_session_durations($_POST['available_session_durations'] ?? [], $mysqli);
    $active_durations = array_map('intval', explode(',', $available_session_durations));
    $appointment_delivery_mode = $_POST['appointment_delivery_mode'] ?? 'both';
    if (!in_array($appointment_delivery_mode, ['both', 'presencial', 'online'], true)) {
        $appointment_delivery_mode = 'both';
    }
    if (!is_array($services)) {
        echo json_encode(['success' => false, 'error' => 'Configuracion de servicios invalida']);
        exit;
    }

    $mysqli->begin_transaction();
    try {
        foreach ($services as $service) {
            $service_id = (int) ($service['id'] ?? 0);
            $service_key = trim((string) ($service['service_key'] ?? ''));
            if (!preg_match('/^[a-z0-9_-]{2,32}$/', $service_key)) {
                $service_key = 'servicio_' . substr(bin2hex(random_bytes(6)), 0, 12);
            }
            $name = trim((string) ($service['name'] ?? ''));
            $is_active = !empty($service['is_active']) ? 1 : 0;

            if ($name === '') {
                throw new \Exception('Hay un servicio sin nombre o identificador valido.');
            }

            if ($service_id > 0) {
                $stmt = $mysqli->prepare("UPDATE appointment_services SET name = ?, is_active = ? WHERE tenant_id = ? AND id = ?");
                $stmt->bind_param("siii", $name, $is_active, $tenant_id, $service_id);
                $stmt->execute();
            } else {
                $sort_order = ((int) ($service['sort_order'] ?? 0)) ?: 999;
                $stmt = $mysqli->prepare("
                    INSERT INTO appointment_services (tenant_id, service_key, name, is_active, sort_order)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = VALUES(is_active)
                ");
                $stmt->bind_param("issii", $tenant_id, $service_key, $name, $is_active, $sort_order);
                $stmt->execute();
                $service_id = (int) $mysqli->insert_id;
                if ($service_id <= 0) {
                    $stmt = $mysqli->prepare("SELECT id FROM appointment_services WHERE tenant_id = ? AND service_key = ? LIMIT 1");
                    $stmt->bind_param("is", $tenant_id, $service_key);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $service_id = (int) ($row['id'] ?? 0);
                }
            }

            foreach (($service['options'] ?? []) as $option) {
                $option_id = (int) ($option['id'] ?? 0);
                $duration = (int) ($option['duration_minutes'] ?? 0);
                $consultation_type = $option['consultation_type'] ?? '';
                $price = str_replace(',', '.', trim((string) ($option['price'] ?? '')));
                $option_active = !empty($option['is_active']) ? 1 : 0;

                if ($duration <= 0 || $duration > 480 || !in_array($consultation_type, ['presencial', 'online'], true)) {
                    throw new \Exception('Hay una opcion de servicio no valida.');
                }
                if (!is_numeric($price) || (float) $price < 0) {
                    throw new \Exception('Hay un precio de servicio no valido.');
                }

                $price = (float) $price;
                if (!in_array($duration, $active_durations, true) || ($appointment_delivery_mode !== 'both' && $consultation_type !== $appointment_delivery_mode)) {
                    $option_active = 0;
                }
                if ($option_id > 0) {
                    $stmt = $mysqli->prepare("
                        UPDATE appointment_service_options
                        SET duration_minutes = ?, consultation_type = ?, price = ?, is_active = ?
                        WHERE tenant_id = ? AND id = ? AND service_id = ?
                    ");
                    $stmt->bind_param("isdiiii", $duration, $consultation_type, $price, $option_active, $tenant_id, $option_id, $service_id);
                } else {
                    $sort_order = ($duration * 10) + ($consultation_type === 'online' ? 1 : 0);
                    $stmt = $mysqli->prepare("
                        INSERT INTO appointment_service_options (tenant_id, service_id, duration_minutes, consultation_type, price, is_active, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE price = VALUES(price), is_active = VALUES(is_active)
                    ");
                    $stmt->bind_param("iiisdii", $tenant_id, $service_id, $duration, $consultation_type, $price, $option_active, $sort_order);
                }
                $stmt->execute();
            }
        }

        $active_keys = [];
        $res = $mysqli->query("SELECT service_key FROM appointment_services WHERE tenant_id = $tenant_id AND is_active = 1");
        while ($row = $res->fetch_assoc()) {
            $service_key = trim((string) ($row['service_key'] ?? ''));
            if ($service_key !== '') {
                $active_keys[] = $service_key;
            }
        }
        if (!$active_keys) {
            $fallback = $mysqli->query("SELECT service_key FROM appointment_services WHERE tenant_id = $tenant_id ORDER BY sort_order ASC, id ASC LIMIT 1");
            $fallback_row = $fallback ? $fallback->fetch_assoc() : null;
            $active_keys = $fallback_row ? [$fallback_row['service_key']] : sector_default_appointment_service_keys(sector_texts_for_db($mysqli));
        }
        $available_session_types = implode(',', array_unique($active_keys));
        $stmt = $mysqli->prepare("UPDATE payment_settings SET available_session_types = ? WHERE tenant_id = $tenant_id");
        $stmt->bind_param("s", $available_session_types);
        $stmt->execute();

        $stmt = $mysqli->prepare("UPDATE payment_settings SET available_session_durations = ? WHERE tenant_id = $tenant_id");
        $stmt->bind_param("s", $available_session_durations);
        $stmt->execute();
        sync_service_availability($mysqli, $available_session_types, $available_session_durations, $appointment_delivery_mode);

        $mysqli->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Servicios guardados correctamente.',
            'services' => fetch_appointment_services($mysqli),
            'available_session_types' => $available_session_types,
            'available_session_durations' => $available_session_durations
        ]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'save_payment_settings') {
    ensure_payment_settings_table($mysqli);
    ensure_cabinet_schema($mysqli);

    $settings_section = $_POST['settings_section'] ?? '';
    $enabled = isset($_POST['online_payment_enabled']) && $_POST['online_payment_enabled'] === '1' ? 1 : 0;
    $app_name = trim($_POST['app_name'] ?? '');
    $site_tagline = trim($_POST['site_tagline'] ?? '');
    $site_phone = trim($_POST['site_phone'] ?? '');
    $legal_owner_name = trim($_POST['legal_owner_name'] ?? '');
    $legal_nif = trim($_POST['legal_nif'] ?? '');
    $legal_address = trim($_POST['legal_address'] ?? '');
    $legal_email = trim($_POST['legal_email'] ?? '');
    $legal_license_number = trim($_POST['legal_license_number'] ?? '');
    $legal_professional_college = trim($_POST['legal_professional_college'] ?? '');
    $legal_uses_non_technical_cookies = isset($_POST['legal_uses_non_technical_cookies']) && $_POST['legal_uses_non_technical_cookies'] === '1' ? 1 : 0;
    $legal_terms_notes = trim($_POST['legal_terms_notes'] ?? '');
    $billing_enabled = isset($_POST['billing_enabled']) && $_POST['billing_enabled'] === '1' ? 1 : 0;
    $billing_country = strtoupper(trim($_POST['billing_country'] ?? 'ES'));
    $billing_province = trim($_POST['billing_province'] ?? '');
    $billing_session_concept = trim($_POST['billing_session_concept'] ?? '');
    $billing_report_concept = trim($_POST['billing_report_concept'] ?? '');
    $primary_color = trim($_POST['primary_color'] ?? '#4285f4');
    $dashboard_config_mode = 'advanced';
    $sector_texts_key = sector_texts_key_from_db($mysqli);
    if (isset($_POST['sector_texts_key'])) {
        $posted_sector_texts_key = $_POST['sector_texts_key'];
        if (sector_texts_validate_key($posted_sector_texts_key) && sector_texts_read_file($posted_sector_texts_key)) {
            $sector_texts_key = $posted_sector_texts_key;
        }
    }
    $initial_calendar_view = $_POST['initial_calendar_view'] ?? 'month';
    $environment = $_POST['environment'] ?? 'sandbox';
    $merchant_code = trim($_POST['merchant_code'] ?? '');
    $merchant_key = trim($_POST['merchant_key'] ?? '');
    $terminal = trim($_POST['terminal'] ?? '');
    $appointment_price = str_replace(',', '.', trim($_POST['appointment_price'] ?? '0'));
    $online_appointment_price = str_replace(',', '.', trim($_POST['online_appointment_price'] ?? '70'));
    $couple_appointment_price = str_replace(',', '.', trim($_POST['couple_appointment_price'] ?? '90'));
    $online_couple_appointment_price = str_replace(',', '.', trim($_POST['online_couple_appointment_price'] ?? '90'));
    $admin_notification_email = trim($_POST['admin_notification_email'] ?? '');
    $appointment_delivery_mode = $_POST['appointment_delivery_mode'] ?? 'both';
    $available_session_types = normalize_available_session_types($_POST['available_session_types'] ?? [], $mysqli);
    $available_session_durations = normalize_available_session_durations($_POST['available_session_durations'] ?? [], $mysqli);
    $locations_json = $_POST['locations_json'] ?? '';
    $posted_locations = json_decode($locations_json, true);
    if (!is_array($posted_locations)) {
        $posted_locations = [];
    }
    $display_effective_duration_enabled = isset($_POST['display_effective_duration_enabled']) && $_POST['display_effective_duration_enabled'] === '1' ? 1 : 0;
    $display_duration_offset_minutes = (int) ($_POST['display_duration_offset_minutes'] ?? 5);
    $display_duration_offset_minutes = max(0, min(30, $display_duration_offset_minutes));
    $posted_appointment_reminder_enabled = array_key_exists('appointment_reminder_enabled', $_POST)
        ? ($_POST['appointment_reminder_enabled'] === '1' ? 1 : 0)
        : null;
    $posted_appointment_second_reminder_enabled = array_key_exists('appointment_second_reminder_enabled', $_POST)
        ? ($_POST['appointment_second_reminder_enabled'] === '1' ? 1 : 0)
        : null;
    $posted_appointment_second_reminder_hours = array_key_exists('appointment_second_reminder_hours', $_POST)
        ? (int) ($_POST['appointment_second_reminder_hours'] ?? 48)
        : null;
    if ($posted_appointment_second_reminder_hours !== null) {
        $posted_appointment_second_reminder_hours = max(1, min(168, $posted_appointment_second_reminder_hours));
    }
    $min_booking_notice_days = (int) ($_POST['min_booking_notice_days'] ?? 0);
    $max_booking_notice_days = (int) ($_POST['max_booking_notice_days'] ?? 0);
    $appointment_start_time = normalize_time_field($_POST['appointment_start_time'] ?? '', '10:00:00');
    $appointment_end_time = normalize_time_field($_POST['appointment_end_time'] ?? '', '19:00:00');
    $break_start_time = normalize_time_field($_POST['break_start_time'] ?? '', '');
    $break_end_time = normalize_time_field($_POST['break_end_time'] ?? '', '');
    $available_weekdays = normalize_available_weekdays($_POST['available_weekdays'] ?? []);
    $email_provider = $_POST['email_provider'] ?? 'phpmailer';
    $smtp_host = trim($_POST['smtp_host'] ?? '');
    $smtp_port = (int) ($_POST['smtp_port'] ?? 587);
    $smtp_username = trim($_POST['smtp_username'] ?? '');
    $smtp_password = trim($_POST['smtp_password'] ?? '');
    $smtp_secure = $_POST['smtp_secure'] ?? 'tls';
    $smtp_from_email = trim($_POST['smtp_from_email'] ?? '');
    $smtp_from_name = trim($_POST['smtp_from_name'] ?? '');
    $sms_provider = strtolower(trim($_POST['sms_provider'] ?? 'none'));
    if (!in_array($sms_provider, ['none', 'mundosms', 'smsup', 'smsapi'], true)) {
        $sms_provider = 'none';
    }
    $sms_sender = trim($_POST['sms_sender'] ?? '');
    $sms_username = trim($_POST['sms_username'] ?? '');
    $sms_password = trim($_POST['sms_password'] ?? '');
    $sms_api_key = trim($_POST['sms_api_key'] ?? '');
    $sms_reminder_enabled = isset($_POST['sms_reminder_enabled']) && $_POST['sms_reminder_enabled'] === '1' ? 1 : 0;
    $sms_reminder_hours = max(1, min(168, (int) ($_POST['sms_reminder_hours'] ?? 24)));
    $google_refresh_token = trim($_POST['google_refresh_token'] ?? '');
    $google_connected_email = trim($_POST['google_connected_email'] ?? '');
    $google_redirect_uri = function_exists('google_default_redirect_uri') ? google_default_redirect_uri() : trim($_POST['google_redirect_uri'] ?? '');
    $calendar_provider = $_POST['calendar_provider'] ?? 'none';
    if (!in_array($calendar_provider, ['none', 'google', 'icloud'], true)) {
        $calendar_provider = 'none';
    }
    $google_calendar_enabled = $calendar_provider === 'google' ? 1 : 0;
    $google_calendar_id = trim($_POST['google_calendar_id'] ?? 'primary');
    $icloud_calendar_email = trim($_POST['icloud_calendar_email'] ?? '');
    $icloud_calendar_app_password = trim($_POST['icloud_calendar_app_password'] ?? '');
    $icloud_calendar_url = trim($_POST['icloud_calendar_url'] ?? '');
    if ($icloud_calendar_url === '') {
        $icloud_calendar_url = 'https://caldav.icloud.com';
    }
    $send_patient_calendar_link = isset($_POST['send_patient_calendar_link']) && $_POST['send_patient_calendar_link'] === '1' ? 1 : 0;
    if ($email_provider === 'google') {
        $admin_notification_email = $google_connected_email;
    } else {
        $admin_notification_email = $smtp_from_email;
    }
    $show_profile_image_public = isset($_POST['show_profile_image_public']) && $_POST['show_profile_image_public'] === '1' ? 1 : 0;
    $show_prices_public = isset($_POST['show_prices_public']) && $_POST['show_prices_public'] === '1' ? 1 : 0;
    $show_contact_public = isset($_POST['show_contact_public']) && $_POST['show_contact_public'] === '1' ? 1 : 0;
    $online_booking_enabled = isset($_POST['online_booking_enabled']) && $_POST['online_booking_enabled'] === '1' ? 1 : 0;
    $patient_tasks_visible_default = isset($_POST['patient_tasks_visible_default']) && $_POST['patient_tasks_visible_default'] === '1' ? 1 : 0;
    $work_plan_task_status_enabled = isset($_POST['work_plan_task_status_enabled']) && $_POST['work_plan_task_status_enabled'] === '1' ? 1 : 0;
    $patient_registration_mode = $_POST['patient_registration_mode'] ?? 'invite';
    if (!in_array($patient_registration_mode, ['invite', 'open'], true)) {
        $patient_registration_mode = 'invite';
    }

    $current_branding_settings = get_public_branding_settings($mysqli);
    $current_plan_config = plan_config_for_key($current_branding_settings['plan_key'] ?? 'novus');
    $plan_allows_patient_portal = plan_config_feature_enabled($current_plan_config, 'patientPortal.enabled', false)
        && plan_config_feature_enabled($current_plan_config, 'onlineBooking.enabled', false);
    $plan_allows_tasks = plan_config_feature_enabled($current_plan_config, 'tasks.enabled', false);
    $plan_allows_payments = plan_config_feature_enabled($current_plan_config, 'onlinePayments.enabled', false)
        && plan_config_feature_enabled($current_plan_config, 'payments.online', false);
    $plan_allows_calendar_sync = plan_config_feature_enabled($current_plan_config, 'calendarSync.enabled', false);
    $plan_allows_reminders = plan_config_feature_enabled($current_plan_config, 'reminders.patient24h', false);
    $plan_allows_custom_logo = plan_config_feature_enabled($current_plan_config, 'branding.customLogo', false);
    $plan_allows_ui_customization = plan_config_feature_enabled($current_plan_config, 'ui.customization', false);
    $plan_allows_effective_duration = plan_config_feature_enabled($current_plan_config, 'appointments.effectiveDuration', false);
    $plan_allows_billing = plan_config_feature_enabled($current_plan_config, 'billing.enabled', false);

    if (!$plan_allows_patient_portal) {
        $online_booking_enabled = 0;
        $patient_registration_mode = 'invite';
        $patient_tasks_visible_default = 0;
    } elseif (!$plan_allows_tasks) {
        $patient_tasks_visible_default = 0;
    }
    if (!$plan_allows_payments) {
        $enabled = 0;
    }
    if (!$plan_allows_calendar_sync) {
        $calendar_provider = 'none';
        $google_calendar_enabled = 0;
        $send_patient_calendar_link = 0;
    }
    if (!$plan_allows_reminders) {
        $posted_appointment_reminder_enabled = 0;
        $posted_appointment_second_reminder_enabled = 0;
    }
    if (!$plan_allows_custom_logo) {
        $show_profile_image_public = 0;
        unset($_FILES['profile_image']);
    }
    $dashboard_config_mode = $plan_allows_ui_customization
        ? dashboard_config_effective_mode_from_db($mysqli, $current_branding_settings['plan_key'] ?? 'novus')
        : 'advanced';
    if (!$plan_allows_effective_duration) {
        $display_effective_duration_enabled = 0;
    }
    if (!$plan_allows_billing) {
        $billing_enabled = 0;
    }

    foreach ($posted_locations as $posted_location) {
        $location_id = (int) ($posted_location['id'] ?? 0);
        if ($location_id <= 0) {
            continue;
        }
        $is_enabled = !empty($posted_location['is_enabled']) ? 1 : 0;
        $stmt = $mysqli->prepare("SELECT location_key, location_type FROM appointment_locations WHERE tenant_id = ? AND id = ? LIMIT 1");
        $stmt->bind_param("ii", $tenant_id, $location_id);
        $stmt->execute();
        $location_row = $stmt->get_result()->fetch_assoc();
        if (!$location_row) {
            continue;
        }
        if (($location_row['location_key'] ?? '') === 'default' || ($location_row['location_type'] ?? '') === 'default') {
            $is_enabled = 1;
        }
        $stmt = $mysqli->prepare("UPDATE appointment_locations SET is_enabled = ? WHERE tenant_id = ? AND id = ?");
        $stmt->bind_param("iii", $is_enabled, $tenant_id, $location_id);
        $stmt->execute();
    }

    $uploaded_profile_image_path = null;
    $uploaded_landing_image_path = null;
    $uploaded_favicon_path = null;

    if ($app_name === '') {
        $app_name = 'SimplyGest Praxis';
    }

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary_color)) {
        echo json_encode(['success' => false, 'error' => 'Color principal inválido']);
        exit;
    }
    $primary_color = strtolower($primary_color);
    if (!preg_match('/^[A-Z]{2}$/', $billing_country)) {
        echo json_encode(['success' => false, 'error' => 'Pais de facturacion no valido']);
        exit;
    }
    if ($billing_session_concept === '') {
        $billing_session_concept = 'Sesion del dia {fecha} de duracion {duracion} minutos';
    }
    if ($billing_report_concept === '') {
        $billing_report_concept = 'Informe {titulo}';
    }
    if (!in_array($initial_calendar_view, ['week', 'month', 'patients', 'upcoming'], true)) {
        $initial_calendar_view = 'month';
    }

    if (!in_array($environment, ['sandbox', 'real'], true)) {
        echo json_encode(['success' => false, 'error' => 'Modo de pasarela inválido']);
        exit;
    }

    if (!is_numeric($appointment_price) || (float) $appointment_price < 0) {
        echo json_encode(['success' => false, 'error' => 'Importe de cita inválido']);
        exit;
    }
    $appointment_price = (float) $appointment_price;

    if (!is_numeric($online_appointment_price) || (float) $online_appointment_price < 0) {
        echo json_encode(['success' => false, 'error' => 'Importe de cita online inválido']);
        exit;
    }
    $online_appointment_price = (float) $online_appointment_price;

    if (!is_numeric($couple_appointment_price) || (float) $couple_appointment_price < 0) {
        echo json_encode(['success' => false, 'error' => 'Importe de cita de pareja inválido']);
        exit;
    }
    $couple_appointment_price = (float) $couple_appointment_price;

    if (!is_numeric($online_couple_appointment_price) || (float) $online_couple_appointment_price < 0) {
        echo json_encode(['success' => false, 'error' => 'Importe de cita online de pareja inválido']);
        exit;
    }
    $online_couple_appointment_price = (float) $online_couple_appointment_price;

    if ($admin_notification_email && !filter_var($admin_notification_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Email de notificaciones inválido']);
        exit;
    }

    if ($legal_email && !filter_var($legal_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Email legal inválido']);
        exit;
    }

    if (!in_array($appointment_delivery_mode, ['both', 'presencial', 'online'], true)) {
        echo json_encode(['success' => false, 'error' => 'Modalidad de citas inválida']);
        exit;
    }

    if ($min_booking_notice_days < 0 || $max_booking_notice_days < 0) {
        echo json_encode(['success' => false, 'error' => 'Los límites de antelación no pueden ser negativos']);
        exit;
    }

    if ($max_booking_notice_days > 0 && $min_booking_notice_days > $max_booking_notice_days) {
        echo json_encode(['success' => false, 'error' => 'El mínimo de días no puede ser mayor que el máximo']);
        exit;
    }

    if ($appointment_start_time === null || $appointment_end_time === null || $break_start_time === null || $break_end_time === null) {
        echo json_encode(['success' => false, 'error' => 'Horario de reservas no válido']);
        exit;
    }

    if (time_to_minutes($appointment_start_time) > time_to_minutes($appointment_end_time)) {
        echo json_encode(['success' => false, 'error' => 'La primera cita no puede ser posterior a la última']);
        exit;
    }

    if (($break_start_time === '') !== ($break_end_time === '')) {
        echo json_encode(['success' => false, 'error' => 'Indica inicio y fin del descanso, o deja ambos campos vacíos']);
        exit;
    }

    if ($break_start_time !== '' && time_to_minutes($break_start_time) >= time_to_minutes($break_end_time)) {
        echo json_encode(['success' => false, 'error' => 'El inicio del descanso debe ser anterior al fin']);
        exit;
    }

    if ($available_weekdays === '') {
        echo json_encode(['success' => false, 'error' => 'Selecciona al menos un día disponible para consulta']);
        exit;
    }

    $current_professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
    $current_professional_settings = $current_professional_id > 0
        ? cabinet_get_effective_professional_settings($mysqli, $current_professional_id)
        : [];

    $private_settings_payload = [
        'appointment_delivery_mode' => $appointment_delivery_mode,
        'available_session_types' => $available_session_types,
        'available_session_durations' => $available_session_durations,
        'appointment_start_time' => $appointment_start_time,
        'appointment_end_time' => $appointment_end_time,
        'break_start_time' => $break_start_time === '' ? null : $break_start_time,
        'break_end_time' => $break_end_time === '' ? null : $break_end_time,
        'available_weekdays' => $available_weekdays,
        'default_appointment_location' => trim((string) ($current_professional_settings['default_appointment_location'] ?? '')),
        'default_location_id' => !empty($current_professional_settings['default_location_id']) ? (int) $current_professional_settings['default_location_id'] : null,
        'livekit_enabled' => !empty($current_professional_settings['livekit_enabled']) ? 1 : 0,
        'min_booking_notice_days' => $min_booking_notice_days,
        'max_booking_notice_days' => $max_booking_notice_days
    ];

    if (!$is_superadmin) {
        if (!in_array($settings_section, ['general', 'booking'], true)) {
            echo json_encode(['success' => false, 'error' => 'No tienes permiso para modificar esta seccion de configuracion.']);
            exit;
        }
        if ($current_professional_id <= 0 || !cabinet_upsert_professional_settings($mysqli, $current_professional_id, $private_settings_payload)) {
            echo json_encode(['success' => false, 'error' => 'No se pudo guardar la configuracion privada del profesional.']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Cambios guardados']);
        exit;
    }

    if (!in_array($email_provider, ['phpmailer', 'google'], true)) {
        echo json_encode(['success' => false, 'error' => 'Proveedor de email inválido']);
        exit;
    }

    if (!in_array($smtp_secure, ['none', 'tls', 'ssl'], true)) {
        echo json_encode(['success' => false, 'error' => 'Cifrado SMTP inválido']);
        exit;
    }

    if ($smtp_from_email && !filter_var($smtp_from_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Email remitente SMTP inválido']);
        exit;
    }

    if ($google_connected_email && !filter_var($google_connected_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Email de Google inválido']);
        exit;
    }

    if ($google_redirect_uri && !filter_var($google_redirect_uri, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'error' => 'Redirect URI de Google inválida']);
        exit;
    }

    if ($icloud_calendar_email && !filter_var($icloud_calendar_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Email de iCloud invalido']);
        exit;
    }

    if ($icloud_calendar_url && !filter_var($icloud_calendar_url, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'error' => 'URL de calendario iCloud invalida']);
        exit;
    }

    $sms_columns_available = column_exists($mysqli, 'payment_settings', 'sms_provider')
        && column_exists($mysqli, 'payment_settings', 'sms_reminder_hours');
    $sms_secret_select = $sms_columns_available
        ? "sms_password IS NOT NULL AND sms_password != '' AS has_sms_password,
               sms_api_key IS NOT NULL AND sms_api_key != '' AS has_sms_api_key"
        : "0 AS has_sms_password,
               0 AS has_sms_api_key";
    $res = $mysqli->query("
        SELECT merchant_key IS NOT NULL AND merchant_key != '' AS has_merchant_key,
               smtp_password IS NOT NULL AND smtp_password != '' AS has_smtp_password,
               $sms_secret_select,
               google_refresh_token IS NOT NULL AND google_refresh_token != '' AS has_google_refresh_token,
               icloud_calendar_app_password IS NOT NULL AND icloud_calendar_app_password != '' AS has_icloud_calendar_app_password,
               fastcron_api_key,
               fastcron_reminder_cron_id,
               appointment_reminder_enabled,
               appointment_second_reminder_enabled,
               appointment_second_reminder_hours
        FROM payment_settings
        WHERE tenant_id = $tenant_id
    ");
    $current_settings = $res->fetch_assoc();
    $has_merchant_key = $current_settings && (int) $current_settings['has_merchant_key'] === 1;
    $has_smtp_password = $current_settings && (int) $current_settings['has_smtp_password'] === 1;
    $has_sms_password = $current_settings && (int) $current_settings['has_sms_password'] === 1;
    $has_sms_api_key = $current_settings && (int) $current_settings['has_sms_api_key'] === 1;
    $has_google_refresh_token = $current_settings && (int) $current_settings['has_google_refresh_token'] === 1;
    $has_icloud_calendar_app_password = $current_settings && (int) $current_settings['has_icloud_calendar_app_password'] === 1;
    $appointment_reminder_enabled = $posted_appointment_reminder_enabled !== null
        ? $posted_appointment_reminder_enabled
        : (int) ($current_settings['appointment_reminder_enabled'] ?? 0);
    $appointment_second_reminder_enabled = $posted_appointment_second_reminder_enabled !== null
        ? $posted_appointment_second_reminder_enabled
        : (int) ($current_settings['appointment_second_reminder_enabled'] ?? 0);
    $appointment_second_reminder_hours = $posted_appointment_second_reminder_hours !== null
        ? $posted_appointment_second_reminder_hours
        : (int) ($current_settings['appointment_second_reminder_hours'] ?? 48);
    $appointment_second_reminder_hours = max(1, min(168, $appointment_second_reminder_hours));
    if ($appointment_second_reminder_enabled && $appointment_second_reminder_hours === 24) {
        echo json_encode(['success' => false, 'error' => 'El segundo recordatorio debe usar un valor distinto de 24 horas para evitar duplicados.']);
        exit;
    }
    $any_appointment_reminder_enabled = $appointment_reminder_enabled || $appointment_second_reminder_enabled;

    $requires_online_price = in_array($appointment_delivery_mode, ['both', 'online'], true);
    $requires_couple_price = strpos($available_session_types, 'couple') !== false;
    if ($enabled && (!$merchant_code || !$terminal || (float) $appointment_price <= 0 || ($requires_online_price && (float) $online_appointment_price <= 0) || ($requires_couple_price && (float) $couple_appointment_price <= 0) || ($requires_online_price && $requires_couple_price && (float) $online_couple_appointment_price <= 0) || ($merchant_key === '' && !$has_merchant_key))) {
        echo json_encode(['success' => false, 'error' => 'Código de comercio, clave, terminal e importes son obligatorios para activar el pago online.']);
        exit;
    }

    $has_partial_smtp = $smtp_host || $smtp_username || $smtp_password || $smtp_from_email;
    if ($email_provider === 'phpmailer' && $has_partial_smtp && (!$smtp_host || !$smtp_port || !$smtp_from_email)) {
        echo json_encode(['success' => false, 'error' => 'Host, puerto y remitente SMTP son obligatorios si configuras SMTP.']);
        exit;
    }

    $any_sms_reminder_enabled = $sms_reminder_enabled;
    $any_scheduled_reminder_enabled = $any_appointment_reminder_enabled || $any_sms_reminder_enabled;
    if ($any_sms_reminder_enabled) {
        if (!$sms_columns_available) {
            echo json_encode(['success' => false, 'error' => 'Antes de activar recordatorios SMS debes aplicar la migracion de campos SMS.']);
            exit;
        }
        if ($sms_provider === 'none' || $sms_sender === '') {
            echo json_encode(['success' => false, 'error' => 'Proveedor y remitente SMS son obligatorios para activar recordatorios por SMS.']);
            exit;
        }
        if ($sms_provider === 'mundosms' && (!$sms_username || ($sms_password === '' && !$has_sms_password))) {
            echo json_encode(['success' => false, 'error' => 'Usuario y contrasena de MundoSMS son obligatorios para activar recordatorios por SMS.']);
            exit;
        }
        if (in_array($sms_provider, ['smsup', 'smsapi'], true) && ($sms_api_key === '' && !$has_sms_api_key)) {
            echo json_encode(['success' => false, 'error' => 'La API Key del proveedor SMS es obligatoria para activar recordatorios por SMS.']);
            exit;
        }
    }

    if ($email_provider === 'google' && !google_oauth_credentials_configured()) {
        echo json_encode(['success' => false, 'error' => 'Faltan las credenciales globales de Google OAuth en el servidor.']);
        exit;
    }

    if ($google_calendar_enabled && (!google_oauth_credentials_configured() || !$google_calendar_id)) {
        echo json_encode(['success' => false, 'error' => 'Para sincronizar Calendario debes configurar Google OAuth global y un Calendar ID.']);
        exit;
    }

    if ($calendar_provider === 'icloud' && (!$icloud_calendar_email || ($icloud_calendar_app_password === '' && !$has_icloud_calendar_app_password) || !$icloud_calendar_url)) {
        echo json_encode(['success' => false, 'error' => 'Para sincronizar iCloud Calendar debes configurar Apple ID, contrasena especifica de app y URL del calendario.']);
        exit;
    }

    $configured_fastcron_api_key = defined('FASTCRON_API_KEY') ? trim(FASTCRON_API_KEY) : '';
    $stored_fastcron_api_key = trim($current_settings['fastcron_api_key'] ?? '');
    $effective_fastcron_api_key = $stored_fastcron_api_key !== '' ? $stored_fastcron_api_key : $configured_fastcron_api_key;
    if ($any_scheduled_reminder_enabled && $effective_fastcron_api_key === '') {
        echo json_encode(['success' => false, 'error' => 'No se pudo habilitar la opcion de recordatorio de cita por un motivo externo: falta configurar el token API de Fastcron.']);
        exit;
    }

    $current_cron_id = trim($current_settings['fastcron_reminder_cron_id'] ?? '');
    if (!$any_scheduled_reminder_enabled && $current_cron_id !== '' && $effective_fastcron_api_key === '') {
        echo json_encode(['success' => false, 'error' => 'No se pudo deshabilitar la opcion de recordatorio de cita por un motivo externo: falta configurar el token API de Fastcron para borrar el cron existente.']);
        exit;
    }

    $new_cron_id = null;
    $clear_cron_id = false;
    $cron_message = '';

    try {
        if ($any_scheduled_reminder_enabled && $current_cron_id === '') {
            $new_cron_id = fastcron_create_reminder_cron($effective_fastcron_api_key, reminder_cron_url(), $app_name);
            $cron_message = ' Cron de Fastcron creado.';
        } elseif (!$any_scheduled_reminder_enabled && $current_cron_id !== '') {
            fastcron_delete_cron($effective_fastcron_api_key, $current_cron_id);
            $clear_cron_id = true;
            $cron_message = ' Cron de Fastcron eliminado.';
        }
    } catch (\Exception $e) {
        $action_error = $any_scheduled_reminder_enabled ? 'habilitar' : 'deshabilitar';
        echo json_encode(['success' => false, 'error' => 'No se pudo ' . $action_error . ' la opcion de recordatorio de cita por un motivo externo: ' . $e->getMessage()]);
        exit;
    }

    try {
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploaded_profile_image_path = save_uploaded_settings_image($_FILES['profile_image'], 'profile');
            $uploaded_favicon_path = generate_favicon_from_public_image($uploaded_profile_image_path, true);
        }
        if (isset($_FILES['landing_image']) && $_FILES['landing_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploaded_landing_image_path = save_uploaded_settings_image($_FILES['landing_image'], 'landing');
        }
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }

    if ($merchant_key !== '') {
        $stmt = $mysqli->prepare("
            UPDATE payment_settings
            SET app_name = ?, site_tagline = ?, site_phone = ?, online_payment_enabled = ?, environment = ?, merchant_code = ?, merchant_key = ?, terminal = ?, appointment_price = ?, online_appointment_price = ?, couple_appointment_price = ?, online_couple_appointment_price = ?, admin_notification_email = ?,
                appointment_reminder_enabled = ?, min_booking_notice_days = ?, max_booking_notice_days = ?,
                email_provider = ?, smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_secure = ?, smtp_from_email = ?, smtp_from_name = ?,
                google_connected_email = ?, google_redirect_uri = ?, google_calendar_enabled = ?, google_calendar_id = ?
            WHERE tenant_id = $tenant_id
        ");
        bind_params_dynamic($stmt, "sssissssddddsiiississssssis", [$app_name, $site_tagline, $site_phone, $enabled, $environment, $merchant_code, $merchant_key, $terminal, $appointment_price, $online_appointment_price, $couple_appointment_price, $online_couple_appointment_price, $admin_notification_email, $appointment_reminder_enabled, $min_booking_notice_days, $max_booking_notice_days, $email_provider, $smtp_host, $smtp_port, $smtp_username, $smtp_secure, $smtp_from_email, $smtp_from_name, $google_connected_email, $google_redirect_uri, $google_calendar_enabled, $google_calendar_id]);
    } else {
        $stmt = $mysqli->prepare("
            UPDATE payment_settings
            SET app_name = ?, site_tagline = ?, site_phone = ?, online_payment_enabled = ?, environment = ?, merchant_code = ?, terminal = ?, appointment_price = ?, online_appointment_price = ?, couple_appointment_price = ?, online_couple_appointment_price = ?, admin_notification_email = ?,
                appointment_reminder_enabled = ?, min_booking_notice_days = ?, max_booking_notice_days = ?,
                email_provider = ?, smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_secure = ?, smtp_from_email = ?, smtp_from_name = ?,
                google_connected_email = ?, google_redirect_uri = ?, google_calendar_enabled = ?, google_calendar_id = ?
            WHERE tenant_id = $tenant_id
        ");
        bind_params_dynamic($stmt, "sssisssddddsiiississssssis", [$app_name, $site_tagline, $site_phone, $enabled, $environment, $merchant_code, $terminal, $appointment_price, $online_appointment_price, $couple_appointment_price, $online_couple_appointment_price, $admin_notification_email, $appointment_reminder_enabled, $min_booking_notice_days, $max_booking_notice_days, $email_provider, $smtp_host, $smtp_port, $smtp_username, $smtp_secure, $smtp_from_email, $smtp_from_name, $google_connected_email, $google_redirect_uri, $google_calendar_enabled, $google_calendar_id]);
    }

    $stmt->execute();

    $stmt = $mysqli->prepare("UPDATE payment_settings SET appointment_second_reminder_enabled = ?, appointment_second_reminder_hours = ? WHERE tenant_id = $tenant_id");
    $stmt->bind_param("ii", $appointment_second_reminder_enabled, $appointment_second_reminder_hours);
    $stmt->execute();

    if ($sms_columns_available) {
        $stmt = $mysqli->prepare("
            UPDATE payment_settings
            SET sms_provider = ?, sms_sender = ?, sms_username = ?, sms_reminder_enabled = ?, sms_reminder_hours = ?
            WHERE tenant_id = $tenant_id
        ");
        $stmt->bind_param("sssii", $sms_provider, $sms_sender, $sms_username, $sms_reminder_enabled, $sms_reminder_hours);
        $stmt->execute();

        if ($sms_password !== '') {
            $stmt = $mysqli->prepare("UPDATE payment_settings SET sms_password = ? WHERE tenant_id = $tenant_id");
            $stmt->bind_param("s", $sms_password);
            $stmt->execute();
        }
        if ($sms_api_key !== '') {
            $stmt = $mysqli->prepare("UPDATE payment_settings SET sms_api_key = ? WHERE tenant_id = $tenant_id");
            $stmt->bind_param("s", $sms_api_key);
            $stmt->execute();
        }
    }

    $stmt = $mysqli->prepare("UPDATE payment_settings SET calendar_provider = ?, google_calendar_enabled = ?, google_calendar_id = ?, icloud_calendar_email = ?, icloud_calendar_url = ?, send_patient_calendar_link = ? WHERE tenant_id = $tenant_id");
    $stmt->bind_param("sisssi", $calendar_provider, $google_calendar_enabled, $google_calendar_id, $icloud_calendar_email, $icloud_calendar_url, $send_patient_calendar_link);
    $stmt->execute();

    $stmt = $mysqli->prepare("UPDATE payment_settings SET primary_color = ? WHERE tenant_id = $tenant_id");
    $stmt->bind_param("s", $primary_color);
    $stmt->execute();

    $stmt = $mysqli->prepare("UPDATE payment_settings SET dashboard_config_mode = ?, sector_texts_key = ? WHERE tenant_id = $tenant_id");
    $stmt->bind_param("ss", $dashboard_config_mode, $sector_texts_key);
    $stmt->execute();

    $stmt = $mysqli->prepare("
        UPDATE payment_settings
        SET legal_owner_name = ?, legal_nif = ?, legal_address = ?, legal_email = ?, legal_license_number = ?, legal_professional_college = ?, legal_uses_non_technical_cookies = ?, legal_terms_notes = ?
        WHERE tenant_id = $tenant_id
    ");
    $stmt->bind_param("ssssssis", $legal_owner_name, $legal_nif, $legal_address, $legal_email, $legal_license_number, $legal_professional_college, $legal_uses_non_technical_cookies, $legal_terms_notes);
    $stmt->execute();

    $stmt = $mysqli->prepare("
        UPDATE payment_settings
        SET billing_enabled = ?, billing_country = ?, billing_province = ?, billing_session_concept = ?, billing_report_concept = ?
        WHERE tenant_id = $tenant_id
    ");
    $stmt->bind_param("issss", $billing_enabled, $billing_country, $billing_province, $billing_session_concept, $billing_report_concept);
    $stmt->execute();

    $stmt = $mysqli->prepare("UPDATE payment_settings SET appointment_delivery_mode = ? WHERE tenant_id = $tenant_id");
    $stmt->bind_param("s", $appointment_delivery_mode);
    $stmt->execute();

    $stmt = $mysqli->prepare("UPDATE payment_settings SET available_session_types = ? WHERE tenant_id = $tenant_id");
    $stmt->bind_param("s", $available_session_types);
    $stmt->execute();

    $stmt = $mysqli->prepare("UPDATE payment_settings SET available_session_durations = ? WHERE tenant_id = $tenant_id");
    $stmt->bind_param("s", $available_session_durations);
    $stmt->execute();
    sync_service_availability($mysqli, $available_session_types, $available_session_durations, $appointment_delivery_mode);

    $stmt = $mysqli->prepare("UPDATE payment_settings SET display_effective_duration_enabled = ?, display_duration_offset_minutes = ? WHERE tenant_id = $tenant_id");
    $stmt->bind_param("ii", $display_effective_duration_enabled, $display_duration_offset_minutes);
    $stmt->execute();

    $break_start_db = $break_start_time === '' ? null : $break_start_time;
    $break_end_db = $break_end_time === '' ? null : $break_end_time;
    $stmt = $mysqli->prepare("
        UPDATE payment_settings
        SET appointment_start_time = ?, appointment_end_time = ?, break_start_time = ?, break_end_time = ?, available_weekdays = ?
        WHERE tenant_id = $tenant_id
    ");
    $stmt->bind_param("sssss", $appointment_start_time, $appointment_end_time, $break_start_db, $break_end_db, $available_weekdays);
    $stmt->execute();

    if ($current_professional_id > 0) {
        cabinet_upsert_professional_settings($mysqli, $current_professional_id, $private_settings_payload);
    }

    if ($uploaded_profile_image_path !== null) {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET profile_image_path = ?, show_profile_image_public = ?, show_prices_public = ?, show_contact_public = ?, online_booking_enabled = ?, patient_tasks_visible_default = ?, work_plan_task_status_enabled = ?, patient_registration_mode = ?, initial_calendar_view = ? WHERE tenant_id = $tenant_id");
        $stmt->bind_param("siiiiiiss", $uploaded_profile_image_path, $show_profile_image_public, $show_prices_public, $show_contact_public, $online_booking_enabled, $patient_tasks_visible_default, $work_plan_task_status_enabled, $patient_registration_mode, $initial_calendar_view);
        $stmt->execute();
    } else {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET show_profile_image_public = ?, show_prices_public = ?, show_contact_public = ?, online_booking_enabled = ?, patient_tasks_visible_default = ?, work_plan_task_status_enabled = ?, patient_registration_mode = ?, initial_calendar_view = ? WHERE tenant_id = $tenant_id");
        $stmt->bind_param("iiiiiiss", $show_profile_image_public, $show_prices_public, $show_contact_public, $online_booking_enabled, $patient_tasks_visible_default, $work_plan_task_status_enabled, $patient_registration_mode, $initial_calendar_view);
        $stmt->execute();
    }

    if ($uploaded_landing_image_path !== null) {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET landing_image_path = ? WHERE tenant_id = $tenant_id");
        $stmt->bind_param("s", $uploaded_landing_image_path);
        $stmt->execute();
    }

    if ($uploaded_favicon_path !== null && $uploaded_favicon_path !== '') {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET favicon_path = ? WHERE tenant_id = $tenant_id");
        $stmt->bind_param("s", $uploaded_favicon_path);
        $stmt->execute();
    }

    if ($smtp_password !== '') {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET smtp_password = ? WHERE tenant_id = $tenant_id");
        $stmt->bind_param("s", $smtp_password);
        $stmt->execute();
    }

    if ($google_refresh_token !== '') {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET google_refresh_token = ? WHERE tenant_id = $tenant_id");
        $stmt->bind_param("s", $google_refresh_token);
        $stmt->execute();
    }

    if ($icloud_calendar_app_password !== '') {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET icloud_calendar_app_password = ? WHERE tenant_id = $tenant_id");
        $stmt->bind_param("s", $icloud_calendar_app_password);
        $stmt->execute();
    }

    if ($stored_fastcron_api_key === '' && $configured_fastcron_api_key !== '') {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET fastcron_api_key = ? WHERE tenant_id = $tenant_id");
        $stmt->bind_param("s", $configured_fastcron_api_key);
        $stmt->execute();
    }

    if ($new_cron_id !== null) {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET fastcron_reminder_cron_id = ? WHERE tenant_id = $tenant_id");
        $stmt->bind_param("s", $new_cron_id);
        $stmt->execute();
    } elseif ($clear_cron_id) {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET fastcron_reminder_cron_id = NULL WHERE tenant_id = $tenant_id");
        $stmt->execute();
    }

    echo json_encode([
        'success' => true,
        'message' => trim('Configuracion guardada correctamente.' . $cron_message),
        'locations' => fetch_appointment_locations($mysqli)
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Acción inválida']);
}
