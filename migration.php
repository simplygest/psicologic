<?php
session_start();

$is_cli = PHP_SAPI === 'cli';

require_once __DIR__ . '/config.php';

$provided_token = trim((string) ($_GET['token'] ?? ($_POST['token'] ?? '')));
$expected_token = function_exists('psicologic_config_value') ? (string) psicologic_config_value('migration_token', '') : '';
if ($expected_token === '' && defined('CRON_WEBHOOK_TOKEN')) {
    $expected_token = (string) CRON_WEBHOOK_TOKEN;
}
$token_allowed = $expected_token !== '' && $provided_token !== '' && hash_equals($expected_token, $provided_token);

if (!$is_cli && (($_SESSION['role'] ?? '') !== 'superadmin') && !$token_allowed) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "No autorizado.\n";
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_helpers.php';
require_once __DIR__ . '/cabinet_helpers.php';
require_once __DIR__ . '/message_template_helpers.php';
require_once __DIR__ . '/app_log_helpers.php';

if (!$is_cli) {
    header('Content-Type: text/html; charset=UTF-8');
}

$migration_results = [];

function migration_log($status, $message)
{
    global $migration_results;
    $migration_results[] = ['status' => $status, 'message' => $message];
}

function migration_table_exists($mysqli, $table)
{
    $table = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

function migration_column_exists($mysqli, $table, $column)
{
    if (!migration_table_exists($mysqli, $table)) {
        return false;
    }
    $table = $mysqli->real_escape_string($table);
    $column = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function migration_index_exists($mysqli, $table, $index)
{
    if (!migration_table_exists($mysqli, $table)) {
        return false;
    }
    $table = $mysqli->real_escape_string($table);
    $index = $mysqli->real_escape_string($index);
    $res = $mysqli->query("SHOW INDEX FROM `$table` WHERE Key_name = '$index'");
    return $res && $res->num_rows > 0;
}

function migration_run($mysqli, $label, $sql)
{
    try {
        $mysqli->query($sql);
        migration_log('ok', $label);
    } catch (Throwable $e) {
        migration_log('error', $label . ': ' . $e->getMessage());
    }
}

function migration_add_column_if_missing($mysqli, $table, $column, $definition)
{
    if (migration_column_exists($mysqli, $table, $column)) {
        migration_log('skip', "$table.$column ya existe");
        return;
    }
    migration_run($mysqli, "ADD COLUMN $table.$column", "ALTER TABLE `$table` ADD `$column` $definition");
}

function migration_add_index_if_missing($mysqli, $table, $index, $definition)
{
    if (migration_index_exists($mysqli, $table, $index)) {
        migration_log('skip', "$table.$index ya existe");
        return;
    }
    migration_run($mysqli, "ADD INDEX $table.$index", "ALTER TABLE `$table` ADD $definition");
}

$tenant_id = current_tenant_id();

migration_run($mysqli, 'MODIFY users.role team member roles', "ALTER TABLE users MODIFY role ENUM('superadmin','admin','reception','administration','technical','patient') NOT NULL DEFAULT 'patient'");
migration_add_column_if_missing($mysqli, 'professional_settings', 'member_permissions_json', 'TEXT DEFAULT NULL AFTER livekit_enabled');

try {
    ensure_message_templates_table($mysqli);
    migration_log('ok', 'CREATE TABLE message_templates');
} catch (Throwable $e) {
    migration_log('error', 'message_templates: ' . $e->getMessage());
}

try {
    ensure_app_logs_table($mysqli);
    migration_log('ok', 'CREATE TABLE app_logs');
} catch (Throwable $e) {
    migration_log('error', 'app_logs: ' . $e->getMessage());
}

migration_run($mysqli, 'CREATE TABLE tenant_domains', "
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

migration_run($mysqli, 'CREATE TABLE patient_profiles', "
    CREATE TABLE IF NOT EXISTS patient_profiles (
        user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL DEFAULT $tenant_id,
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

migration_add_column_if_missing($mysqli, 'patient_profiles', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER user_id");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'fiscal_name', "VARCHAR(180) DEFAULT NULL AFTER patient_type");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'fiscal_nif', "VARCHAR(50) DEFAULT NULL AFTER fiscal_name");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_use_alt_data', "TINYINT(1) NOT NULL DEFAULT 0 AFTER fiscal_nif");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_name', "VARCHAR(180) DEFAULT NULL AFTER invoice_use_alt_data");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_nif', "VARCHAR(50) DEFAULT NULL AFTER invoice_name");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_email', "VARCHAR(180) DEFAULT NULL AFTER invoice_nif");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_phone', "VARCHAR(40) DEFAULT NULL AFTER invoice_email");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_address', "VARCHAR(255) DEFAULT NULL AFTER invoice_phone");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'waiting_list', "TINYINT(1) NOT NULL DEFAULT 0 AFTER patient_status");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'address', "VARCHAR(255) DEFAULT NULL AFTER emergency_contact_relation");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'knowledge_problem_id', "INT UNSIGNED DEFAULT NULL AFTER referral_source");
if (!migration_column_exists($mysqli, 'patient_profiles', 'initial_consultation_reason')) {
    migration_add_column_if_missing($mysqli, 'patient_profiles', 'initial_consultation_reason', "TEXT DEFAULT NULL AFTER knowledge_problem_id");
}
if (migration_column_exists($mysqli, 'patient_profiles', 'initial_consultation_reason')) {
    migration_add_column_if_missing($mysqli, 'patient_profiles', 'background_notes', "TEXT DEFAULT NULL AFTER initial_consultation_reason");
} else {
    migration_add_column_if_missing($mysqli, 'patient_profiles', 'background_notes', "TEXT DEFAULT NULL AFTER knowledge_problem_id");
}
if (migration_column_exists($mysqli, 'patient_profiles', 'background_notes')) {
    migration_add_column_if_missing($mysqli, 'patient_profiles', 'support_network_notes', "TEXT DEFAULT NULL AFTER background_notes");
} else {
    migration_add_column_if_missing($mysqli, 'patient_profiles', 'support_network_notes', "TEXT DEFAULT NULL AFTER initial_consultation_reason");
}
migration_add_index_if_missing($mysqli, 'patient_profiles', 'idx_patient_profiles_knowledge_problem', "INDEX idx_patient_profiles_knowledge_problem (knowledge_problem_id)");
migration_add_index_if_missing($mysqli, 'patient_profiles', 'idx_patient_profiles_waiting_list', "INDEX idx_patient_profiles_waiting_list (tenant_id, waiting_list)");

migration_add_column_if_missing($mysqli, 'appointments', 'online_session_url', "VARCHAR(500) DEFAULT NULL AFTER payment_updated_by");
migration_add_column_if_missing($mysqli, 'appointments', 'livekit_access_token', "CHAR(64) DEFAULT NULL AFTER online_session_url");
if (migration_column_exists($mysqli, 'appointments', 'livekit_access_token')) {
    migration_add_column_if_missing($mysqli, 'appointments', 'session_notes', "TEXT DEFAULT NULL AFTER livekit_access_token");
} else {
    migration_add_column_if_missing($mysqli, 'appointments', 'session_notes', "TEXT DEFAULT NULL AFTER online_session_url");
}
migration_add_column_if_missing($mysqli, 'appointments', 'reminder_sent_at', "DATETIME DEFAULT NULL");
migration_add_column_if_missing($mysqli, 'appointments', 'second_reminder_sent_at', "DATETIME DEFAULT NULL AFTER reminder_sent_at");
migration_add_column_if_missing($mysqli, 'appointments', 'sms_reminder_sent_at', "DATETIME DEFAULT NULL AFTER second_reminder_sent_at");
migration_add_column_if_missing($mysqli, 'appointments', 'consultation_type', "VARCHAR(20) NOT NULL DEFAULT 'presencial' AFTER appointment_time");
migration_add_column_if_missing($mysqli, 'appointments', 'location_id', "INT UNSIGNED DEFAULT NULL AFTER consultation_type");
migration_add_column_if_missing($mysqli, 'appointments', 'service_type', "VARCHAR(20) DEFAULT NULL AFTER consultation_type");
migration_add_column_if_missing($mysqli, 'appointments', 'service_option_id', "INT UNSIGNED DEFAULT NULL AFTER service_type");
migration_add_column_if_missing($mysqli, 'appointments', 'duration_minutes', "SMALLINT UNSIGNED DEFAULT NULL AFTER service_option_id");
migration_run($mysqli, 'MODIFY appointments.status', "ALTER TABLE appointments MODIFY status VARCHAR(32) NOT NULL DEFAULT 'booked'");

try {
    ensure_cabinet_schema($mysqli);
    migration_log('ok', 'Equipo: professionals/professional_settings');
} catch (Throwable $e) {
    migration_log('error', 'Equipo: ' . $e->getMessage());
}
migration_add_column_if_missing($mysqli, 'professional_settings', 'default_appointment_location', "VARCHAR(255) DEFAULT NULL AFTER available_weekdays");
migration_add_column_if_missing($mysqli, 'professional_settings', 'default_location_id', "INT UNSIGNED DEFAULT NULL AFTER default_appointment_location");
migration_add_column_if_missing($mysqli, 'professional_settings', 'livekit_enabled', "TINYINT(1) NOT NULL DEFAULT 1 AFTER default_appointment_location");

migration_run($mysqli, 'CREATE TABLE appointment_locations', "
    CREATE TABLE IF NOT EXISTS appointment_locations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id INT UNSIGNED NOT NULL DEFAULT $tenant_id,
        location_key VARCHAR(80) DEFAULT NULL,
        name VARCHAR(120) NOT NULL,
        location_type VARCHAR(24) NOT NULL DEFAULT 'custom',
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_appointment_locations_key (tenant_id, location_key),
        INDEX idx_appointment_locations_enabled (tenant_id, is_enabled, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
migration_add_column_if_missing($mysqli, 'appointment_locations', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
migration_add_column_if_missing($mysqli, 'appointment_locations', 'location_key', "VARCHAR(80) DEFAULT NULL AFTER tenant_id");
migration_add_column_if_missing($mysqli, 'appointment_locations', 'location_type', "VARCHAR(24) NOT NULL DEFAULT 'custom' AFTER name");
migration_add_column_if_missing($mysqli, 'appointment_locations', 'is_enabled', "TINYINT(1) NOT NULL DEFAULT 1 AFTER location_type");
migration_add_column_if_missing($mysqli, 'appointment_locations', 'is_system', "TINYINT(1) NOT NULL DEFAULT 0 AFTER is_enabled");
migration_add_column_if_missing($mysqli, 'appointment_locations', 'sort_order', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_system");
migration_run($mysqli, 'SEED appointment_locations.default', "
    INSERT INTO appointment_locations (tenant_id, location_key, name, location_type, is_enabled, is_system, sort_order)
    VALUES ($tenant_id, 'default', 'Predeterminada', 'default', 1, 1, 10)
    ON DUPLICATE KEY UPDATE name = VALUES(name), location_type = VALUES(location_type), is_enabled = 1, is_system = 1, sort_order = VALUES(sort_order)
");
migration_run($mysqli, 'SEED appointment_locations.home', "
    INSERT INTO appointment_locations (tenant_id, location_key, name, location_type, is_enabled, is_system, sort_order)
    VALUES ($tenant_id, 'home', 'A domicilio', 'home', 0, 1, 20)
    ON DUPLICATE KEY UPDATE name = VALUES(name), location_type = VALUES(location_type), is_system = 1, sort_order = VALUES(sort_order)
");

try {
    ensure_invoice_schema($mysqli);
    migration_log('ok', 'Facturacion: movim/payment_settings/patient fiscal fields');
} catch (Throwable $e) {
    migration_log('error', 'Facturacion: ' . $e->getMessage());
}

migration_add_column_if_missing($mysqli, 'payment_settings', 'appointment_second_reminder_enabled', "TINYINT(1) NOT NULL DEFAULT 0 AFTER appointment_reminder_enabled");
migration_add_column_if_missing($mysqli, 'payment_settings', 'appointment_second_reminder_hours', "SMALLINT UNSIGNED NOT NULL DEFAULT 48 AFTER appointment_second_reminder_enabled");
migration_add_column_if_missing($mysqli, 'payment_settings', 'sms_provider', "VARCHAR(20) NOT NULL DEFAULT 'none' AFTER appointment_second_reminder_hours");
migration_add_column_if_missing($mysqli, 'payment_settings', 'sms_sender', "VARCHAR(40) DEFAULT NULL AFTER sms_provider");
migration_add_column_if_missing($mysqli, 'payment_settings', 'sms_username', "VARCHAR(120) DEFAULT NULL AFTER sms_sender");
migration_add_column_if_missing($mysqli, 'payment_settings', 'sms_password', "VARCHAR(255) DEFAULT NULL AFTER sms_username");
migration_add_column_if_missing($mysqli, 'payment_settings', 'sms_api_key', "VARCHAR(255) DEFAULT NULL AFTER sms_password");
migration_add_column_if_missing($mysqli, 'payment_settings', 'sms_reminder_enabled', "TINYINT(1) NOT NULL DEFAULT 0 AFTER sms_api_key");
migration_add_column_if_missing($mysqli, 'payment_settings', 'sms_reminder_hours', "SMALLINT UNSIGNED NOT NULL DEFAULT 24 AFTER sms_reminder_enabled");
migration_run($mysqli, 'MODIFY payment_settings.available_session_types', "ALTER TABLE payment_settings MODIFY available_session_types VARCHAR(255) NOT NULL DEFAULT 'individual'");
migration_run($mysqli, 'MODIFY payment_settings.available_session_durations', "ALTER TABLE payment_settings MODIFY available_session_durations VARCHAR(100) NOT NULL DEFAULT '60'");
migration_run($mysqli, 'MODIFY professional_settings.available_session_types', "ALTER TABLE professional_settings MODIFY available_session_types VARCHAR(255) DEFAULT NULL");
migration_run($mysqli, 'MODIFY professional_settings.available_session_durations', "ALTER TABLE professional_settings MODIFY available_session_durations VARCHAR(100) DEFAULT NULL");

$has_errors = count(array_filter($migration_results, fn($row) => $row['status'] === 'error')) > 0;
$summary_status = $has_errors ? 'Error' : 'OK';

if ($is_cli) {
    echo $summary_status . PHP_EOL;
    foreach ($migration_results as $row) {
        echo '[' . strtoupper($row['status']) . '] ' . $row['message'] . PHP_EOL;
    }
    exit($has_errors ? 1 : 0);
}

if ($has_errors && !headers_sent()) {
    http_response_code(500);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($summary_status, ENT_QUOTES, 'UTF-8') ?> - Migraciones SimplyGest Praxis</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f6f7fb; color: #263238; margin: 0; padding: 32px; }
        main { max-width: 900px; margin: 0 auto; background: #fff; border: 1px solid #dfe4ea; border-radius: 10px; padding: 24px; }
        h1 { margin-top: 0; }
        .summary { border-radius: 8px; font-size: 1.1rem; font-weight: 700; margin: 0 0 18px; padding: 12px 14px; }
        .summary.ok { background: #e8f5ee; color: #087f23; }
        .summary.error { background: #fdecec; color: #b00020; }
        .ok { color: #087f23; }
        .skip { color: #66737d; }
        .error { color: #b00020; }
        li { margin: 6px 0; }
    </style>
</head>
<body>
<main>
    <h1>Migraciones SimplyGest Praxis</h1>
    <p class="summary <?= $has_errors ? 'error' : 'ok' ?>"><?= htmlspecialchars($summary_status, ENT_QUOTES, 'UTF-8') ?></p>
    <p><?= $has_errors ? 'Se han producido errores. Revisa los pasos marcados como Error.' : 'Migraciones completadas. Los pasos Skip ya existian.' ?></p>
    <ul>
        <?php foreach ($migration_results as $row): ?>
            <li class="<?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?>">
                [<?= htmlspecialchars(strtoupper($row['status']), ENT_QUOTES, 'UTF-8') ?>]
                <?= htmlspecialchars($row['message'], ENT_QUOTES, 'UTF-8') ?>
            </li>
        <?php endforeach; ?>
    </ul>
</main>
</body>
</html>
