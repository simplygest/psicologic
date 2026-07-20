<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
if (function_exists('set_time_limit')) {
    @set_time_limit(600);
}

$rootDir = dirname(__DIR__);
require_once $rootDir . '/app_paths.php';
require_once $rootDir . '/config.php';
require_once $rootDir . '/tenant_helpers.php';
require_once $rootDir . '/cabinet_helpers.php';
require_once $rootDir . '/payment_helpers.php';
require_once $rootDir . '/invoice_helpers.php';
require_once $rootDir . '/sector_text_helpers.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$errors = [];
$success = false;
$sectorTextOptions = sector_texts_available();
$officialBrandLogoUrl = app_official_brand_logo_url('../');
$installTenantKey = tenant_key_from_request();
$installTenant = null;
$installed = false;

function install_open_db()
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $mysqli = mysqli_init();
    if (!$mysqli) {
        throw new RuntimeException('No se pudo inicializar MySQL.');
    }
    $flags = 0;
    if (defined('DB_SSL') && DB_SSL) {
        $cert_name = defined('DB_SSL_CERT') ? DB_SSL_CERT : 'mysql.pem';
        $cert_path = $cert_name;
        if (!preg_match('/^(?:[a-zA-Z]:[\/\\\\]|\/)/', $cert_name)) {
            $rootDir = dirname(__DIR__);
            $cert_candidates = [
                $rootDir . '/' . ltrim($cert_name, '/\\'),
                ($_SERVER['DOCUMENT_ROOT'] ?? $rootDir) . '/' . ltrim($cert_name, '/\\'),
            ];
            $cert_path = $cert_candidates[0];
            foreach ($cert_candidates as $candidate) {
                if (file_exists($candidate)) {
                    $cert_path = $candidate;
                    break;
                }
            }
        }
        if (file_exists($cert_path)) {
            $mysqli->ssl_set(null, null, $cert_path, null, null);
        }
        $flags = MYSQLI_CLIENT_SSL;
    }
    $mysqli->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, defined('DB_PORT') ? DB_PORT : 3306, null, $flags);
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

try {
    if ($installTenantKey === '') {
        $errors[] = 'La URL no contiene un tenant valido.';
    } else {
        $installDb = install_open_db();
        tenant_ensure_table($installDb);
        $installTenant = tenant_fetch_by_key($installDb, $installTenantKey);
        if (!$installTenant) {
            $errors[] = 'No existe ningun tenant configurado para "' . $installTenantKey . '".';
        } else {
            if (!defined('CURRENT_TENANT_ID')) {
                define('CURRENT_TENANT_ID', (int) $installTenant['id']);
                define('CURRENT_TENANT_KEY', tenant_normalize_key($installTenant['tenant_key']));
            }
            $GLOBALS['current_tenant'] = $installTenant;
            $_SESSION['tenant_id'] = CURRENT_TENANT_ID;
            $_SESSION['tenant_key'] = CURRENT_TENANT_KEY;
            $status = strtolower((string) ($installTenant['status'] ?? 'pending'));
            $installed = $status === 'active';
        }
        $installDb->close();
    }
} catch (Throwable $e) {
    $errors[] = 'No se pudo leer la configuracion del tenant: ' . $e->getMessage();
}

function install_tenant_config_paths($rootDir)
{
    $tenantKey = function_exists('tenant_key_from_request') ? tenant_key_from_request() : basename($rootDir);
    $parentDir = dirname($rootDir);

    return [
        $parentDir . '/tenant-install/' . $tenantKey . '.php',
        $parentDir . '/tenant-install.php',
        $rootDir . '/tenant-install.php',
        __DIR__ . '/tenant-install.php'
    ];
}

function install_read_tenant_config($rootDir, &$error = '')
{
    foreach (install_tenant_config_paths($rootDir) as $path) {
        if (!is_file($path)) {
            continue;
        }
        $decoded = require $path;
        if (!is_array($decoded)) {
            $error = 'tenant-install.php debe devolver un array de configuración válido.';
            return [];
        }
        $decoded['_path'] = $path;
        return $decoded;
    }
    $error = 'No se encuentra la información para la instalación.';
    return [];
}

$tenantConfigError = '';
$tenantConfig = install_read_tenant_config($rootDir, $tenantConfigError);
$tenantDatabaseConfig = is_array($tenantConfig['database'] ?? null) ? $tenantConfig['database'] : [];
$tenantInstallerConfig = is_array($tenantConfig['installation'] ?? null) ? $tenantConfig['installation'] : [];
$hasTenantConfig = true;
$configuredSectorTextsKey = trim((string) ($tenantInstallerConfig['sector_texts_key'] ?? ($installTenant['sector_texts_key'] ?? '')));
$sectorIsPreconfigured = $configuredSectorTextsKey !== '';
$defaultSectorTextsKey = $sectorIsPreconfigured ? $configuredSectorTextsKey : sector_texts_default_key();
$configuredDbName = trim((string) ($tenantDatabaseConfig['name'] ?? ''));
$defaultDbName = $configuredDbName !== '' ? $configuredDbName : install_default_database_name($rootDir, $defaultSectorTextsKey);
$tenantDefaultAppName = trim((string) ($installTenant['app_name'] ?? ''));
$tenantDefaultTimezone = trim((string) ($installTenant['timezone'] ?? ''));

$defaults = [
    'app_name' => $tenantInstallerConfig['app_name'] ?? ($tenantDefaultAppName !== '' ? $tenantDefaultAppName : (defined('DEFAULT_APP_NAME') ? DEFAULT_APP_NAME : 'SimplyGest Praxis')),
    'timezone' => $tenantInstallerConfig['timezone'] ?? ($tenantDefaultTimezone !== '' ? $tenantDefaultTimezone : (defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Atlantic/Canary')),
    'admin_name' => 'Administrador',
    'admin_email' => '',
    'admin_password' => '',
    'db_host' => $tenantDatabaseConfig['host'] ?? (defined('DB_HOST') ? DB_HOST : 'localhost'),
    'db_port' => (string) ($tenantDatabaseConfig['port'] ?? (defined('DB_PORT') ? DB_PORT : '3306')),
    'db_name' => defined('DB_NAME') ? DB_NAME : ($hasTenantConfig ? $defaultDbName : 'sgpraxis'),
    'db_user' => $tenantDatabaseConfig['user'] ?? (defined('DB_USER') ? DB_USER : ''),
    'db_password' => $tenantDatabaseConfig['password'] ?? '',
    'db_ssl' => array_key_exists('ssl', $tenantDatabaseConfig) ? ((bool) $tenantDatabaseConfig['ssl'] ? '1' : '0') : (defined('DB_SSL') && DB_SSL ? '1' : '0'),
    'sector_texts_key' => $defaultSectorTextsKey,
];

function install_value($key, $defaults)
{
    $value = $_POST[$key] ?? ($defaults[$key] ?? '');
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function install_column_exists($mysqli, $table, $column)
{
    $sql = "SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $exists = $row && (int) $row['total'] > 0;
    $stmt->close();
    return $exists;
}

function install_add_column_if_missing($mysqli, $table, $column, $definition)
{
    if (!install_column_exists($mysqli, $table, $column)) {
        $mysqli->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function install_validate_database_name($name)
{
    return is_string($name) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $name);
}

function install_slug_part($value, $fallback = 'tenant')
{
    $value = strtolower(trim((string) $value));
    $value = strtr($value, [
        'á' => 'a',
        'à' => 'a',
        'ä' => 'a',
        'â' => 'a',
        'é' => 'e',
        'è' => 'e',
        'ë' => 'e',
        'ê' => 'e',
        'í' => 'i',
        'ì' => 'i',
        'ï' => 'i',
        'î' => 'i',
        'ó' => 'o',
        'ò' => 'o',
        'ö' => 'o',
        'ô' => 'o',
        'ú' => 'u',
        'ù' => 'u',
        'ü' => 'u',
        'û' => 'u',
        'ñ' => 'n',
        'ç' => 'c',
    ]);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim((string) $value, '_');

    return $value !== '' ? $value : $fallback;
}

function install_default_database_name($rootDir, $sectorKey)
{
    $tenantKey = install_slug_part(basename($rootDir), 'tenant');
    $sectorKey = install_slug_part($sectorKey, sector_texts_default_key());
    return substr($tenantKey . '_' . $sectorKey, 0, 64);
}

function install_quote_identifier($name)
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function install_database_exists($mysqli, $dbName)
{
    $stmt = $mysqli->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1");
    $stmt->bind_param('s', $dbName);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function install_base_tables($mysqli)
{
    $mysqli->query("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NULL,
        phone VARCHAR(30) NULL,
        password_hash VARCHAR(255) NULL,
        role ENUM('superadmin','admin','patient') NOT NULL DEFAULT 'patient',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_users_tenant_email (tenant_id, email),
        UNIQUE KEY uniq_users_tenant_phone (tenant_id, phone),
        INDEX idx_users_tenant_role (tenant_id, role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mysqli->query("CREATE TABLE IF NOT EXISTS invitations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(64) NOT NULL UNIQUE,
        user_id INT UNSIGNED DEFAULT NULL,
        used TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        used_at DATETIME NULL,
        INDEX idx_invitations_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mysqli->query("CREATE TABLE IF NOT EXISTS patient_profiles (
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
        birth_date DATE DEFAULT NULL,
        referral_source VARCHAR(80) DEFAULT NULL,
        initial_consultation_reason TEXT DEFAULT NULL,
        emergency_contact_name VARCHAR(150) DEFAULT NULL,
        emergency_contact_phone VARCHAR(40) DEFAULT NULL,
        emergency_contact_relation VARCHAR(80) DEFAULT NULL,
        address VARCHAR(255) DEFAULT NULL,
        admission_date DATE DEFAULT NULL,
        notes LONGTEXT DEFAULT NULL,
        photo_path VARCHAR(255) DEFAULT NULL,
        document_path VARCHAR(255) DEFAULT NULL,
        document_name VARCHAR(255) DEFAULT NULL,
        created_by_admin TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_patient_profiles_type (patient_type),
        INDEX idx_patient_profiles_admission (admission_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mysqli->query("CREATE TABLE IF NOT EXISTS patient_evolution_notes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        patient_id INT UNSIGNED NOT NULL,
        appointment_id INT UNSIGNED DEFAULT NULL,
        professional_id INT UNSIGNED DEFAULT NULL,
        note_date DATE NOT NULL,
        title VARCHAR(180) NOT NULL,
        description LONGTEXT DEFAULT NULL,
        observations LONGTEXT DEFAULT NULL,
        next_steps LONGTEXT DEFAULT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_evolution_patient_date (patient_id, note_date),
        INDEX idx_evolution_appointment (appointment_id),
        INDEX idx_evolution_professional (professional_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mysqli->query("CREATE TABLE IF NOT EXISTS patient_evolution_files (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        evolution_note_id INT UNSIGNED NOT NULL,
        patient_id INT UNSIGNED NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        mime_type VARCHAR(120) DEFAULT NULL,
        file_size INT UNSIGNED DEFAULT NULL,
        uploaded_by INT UNSIGNED DEFAULT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_evolution_files_note (evolution_note_id),
        INDEX idx_evolution_files_patient (patient_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mysqli->query("CREATE TABLE IF NOT EXISTS patient_work_plan_tasks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        patient_id INT UNSIGNED NOT NULL,
        appointment_id INT UNSIGNED DEFAULT NULL,
        professional_id INT UNSIGNED DEFAULT NULL,
        title VARCHAR(180) NOT NULL,
        description LONGTEXT DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        priority TINYINT UNSIGNED NOT NULL DEFAULT 2,
        visible_to_patient TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT UNSIGNED DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        completed_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_work_plan_appointment (appointment_id),
        INDEX idx_work_plan_patient_status (patient_id, status),
        INDEX idx_work_plan_professional (professional_id),
        INDEX idx_work_plan_priority (priority)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mysqli->query("CREATE TABLE IF NOT EXISTS work_plan_task_templates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        professional_id INT UNSIGNED DEFAULT NULL,
        category VARCHAR(120) DEFAULT NULL,
        title VARCHAR(180) NOT NULL,
        description LONGTEXT DEFAULT NULL,
        priority TINYINT UNSIGNED NOT NULL DEFAULT 2,
        is_global TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_task_templates_professional (professional_id),
        INDEX idx_task_templates_category (category),
        INDEX idx_task_templates_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mysqli->query("CREATE TABLE IF NOT EXISTS work_plan_task_template_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_id INT UNSIGNED NOT NULL,
        title VARCHAR(180) NOT NULL,
        description LONGTEXT DEFAULT NULL,
        priority TINYINT UNSIGNED NOT NULL DEFAULT 2,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_template_items_template (template_id),
        INDEX idx_template_items_sort (template_id, sort_order, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mysqli->query("CREATE TABLE IF NOT EXISTS appointments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
        user_id INT UNSIGNED NOT NULL,
        professional_id INT UNSIGNED DEFAULT NULL,
        appointment_date DATE NOT NULL,
        appointment_time TIME NOT NULL,
        consultation_type VARCHAR(20) NOT NULL DEFAULT 'presencial',
        location_id INT UNSIGNED DEFAULT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'booked',
        payment_status VARCHAR(32) NOT NULL DEFAULT 'pending',
        payment_method VARCHAR(16) DEFAULT NULL,
        paid_at DATETIME DEFAULT NULL,
        payment_updated_at DATETIME DEFAULT NULL,
        payment_updated_by INT UNSIGNED DEFAULT NULL,
        online_session_url VARCHAR(500) DEFAULT NULL,
        sms_reminder_sent_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        cancelled_at DATETIME NULL,
        INDEX idx_date_time (appointment_date, appointment_time),
        INDEX idx_user_id (user_id),
        INDEX idx_calendar_professional (tenant_id, professional_id, status, appointment_date, appointment_time),
        INDEX idx_calendar_patient (tenant_id, user_id, status, appointment_date, appointment_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mysqli->query("CREATE TABLE IF NOT EXISTS appointment_locations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
        location_key VARCHAR(80) DEFAULT NULL,
        name VARCHAR(120) NOT NULL,
        location_type VARCHAR(24) NOT NULL DEFAULT 'custom',
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_appointment_locations_key (tenant_id, location_key),
        INDEX idx_appointment_locations_enabled (tenant_id, is_enabled, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mysqli->query("INSERT INTO appointment_locations (tenant_id, location_key, name, location_type, is_enabled, is_system, sort_order)
        VALUES (1, 'default', 'Predeterminada', 'default', 1, 1, 10)
        ON DUPLICATE KEY UPDATE name = VALUES(name), location_type = VALUES(location_type), is_enabled = 1, is_system = 1, sort_order = VALUES(sort_order)");
    $mysqli->query("INSERT INTO appointment_locations (tenant_id, location_key, name, location_type, is_enabled, is_system, sort_order)
        VALUES (1, 'home', 'A domicilio', 'home', 0, 1, 20)
        ON DUPLICATE KEY UPDATE name = VALUES(name), location_type = VALUES(location_type), is_system = 1, sort_order = VALUES(sort_order)");

    $mysqli->query("CREATE TABLE IF NOT EXISTS closed_days (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
        professional_id INT UNSIGNED DEFAULT NULL,
        closed_date DATE NOT NULL,
        reason VARCHAR(255) NULL,
        is_global TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_closed_date (closed_date),
        INDEX idx_closed_calendar (tenant_id, closed_date, is_global, professional_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mysqli->query("CREATE TABLE IF NOT EXISTS payment_settings (
        id INT PRIMARY KEY DEFAULT 1,
        app_name VARCHAR(150) NOT NULL DEFAULT 'SimplyGest Praxis',
        site_tagline VARCHAR(255) NULL,
        site_phone VARCHAR(40) NULL,
        admin_notification_email VARCHAR(150) NULL,
        favicon_path VARCHAR(255) NULL,
        primary_color VARCHAR(7) NOT NULL DEFAULT '#4285f4',
        show_team_public TINYINT(1) NOT NULL DEFAULT 0,
        public_site_enabled TINYINT(1) NOT NULL DEFAULT 0,
        show_contact_public TINYINT(1) NOT NULL DEFAULT 0,
        allow_patient_transfer TINYINT(1) NOT NULL DEFAULT 0,
        plan_key VARCHAR(32) NOT NULL DEFAULT 'novus',
        online_booking_enabled TINYINT(1) NOT NULL DEFAULT 1,
        patient_registration_mode VARCHAR(16) NOT NULL DEFAULT 'invite',
        patient_tasks_visible_default TINYINT(1) NOT NULL DEFAULT 0,
        dashboard_config_mode VARCHAR(16) NOT NULL DEFAULT 'simple',
        sector_texts_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
        legal_owner_name VARCHAR(255) NULL,
        legal_nif VARCHAR(50) NULL,
        legal_address VARCHAR(500) NULL,
        legal_email VARCHAR(255) NULL,
        legal_license_number VARCHAR(100) NULL,
        legal_professional_college VARCHAR(255) NULL,
        legal_uses_non_technical_cookies TINYINT NOT NULL DEFAULT 0,
        legal_terms_notes TEXT NULL,
        billing_enabled TINYINT(1) NOT NULL DEFAULT 0,
        billing_country VARCHAR(2) NOT NULL DEFAULT 'ES',
        billing_province VARCHAR(80) NULL,
        billing_session_concept VARCHAR(255) NOT NULL DEFAULT 'Sesion del dia {fecha} de duracion {duracion} minutos',
        billing_report_concept VARCHAR(255) NOT NULL DEFAULT 'Informe {titulo}',
        online_payment_enabled TINYINT(1) NOT NULL DEFAULT 0,
        environment ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
        merchant_code VARCHAR(20) NULL,
        terminal VARCHAR(10) NOT NULL DEFAULT '1',
        merchant_key TEXT NULL,
        appointment_price DECIMAL(10,2) NOT NULL DEFAULT 70.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mysqli->query("INSERT IGNORE INTO payment_settings (id, app_name, site_tagline, appointment_price) VALUES (1, 'SimplyGest Praxis', '', 70.00)");

    ensure_invoice_schema($mysqli);
    ensure_cabinet_schema($mysqli);
}

function install_ensure_payment_settings_columns($mysqli)
{
    $mysqli->query("ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL");
    $mysqli->query("ALTER TABLE users MODIFY role ENUM('superadmin','admin','patient') NOT NULL DEFAULT 'patient'");
    install_add_column_if_missing($mysqli, 'invitations', 'user_id', 'INT UNSIGNED DEFAULT NULL AFTER token');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'tenant_id', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER user_id');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'patient_status', "VARCHAR(20) NOT NULL DEFAULT 'active' AFTER patient_type");
    install_add_column_if_missing($mysqli, 'patient_profiles', 'fiscal_name', 'VARCHAR(180) DEFAULT NULL AFTER patient_type');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'fiscal_nif', 'VARCHAR(50) DEFAULT NULL AFTER fiscal_name');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_use_alt_data', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER fiscal_nif');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_name', 'VARCHAR(180) DEFAULT NULL AFTER invoice_use_alt_data');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_nif', 'VARCHAR(50) DEFAULT NULL AFTER invoice_name');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_email', 'VARCHAR(180) DEFAULT NULL AFTER invoice_nif');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_phone', 'VARCHAR(40) DEFAULT NULL AFTER invoice_email');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_address', 'VARCHAR(255) DEFAULT NULL AFTER invoice_phone');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'birth_date', 'DATE DEFAULT NULL AFTER patient_status');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'referral_source', 'VARCHAR(80) DEFAULT NULL AFTER birth_date');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'knowledge_problem_id', 'INT UNSIGNED DEFAULT NULL AFTER referral_source');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'initial_consultation_reason', 'TEXT DEFAULT NULL AFTER referral_source');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'emergency_contact_name', 'VARCHAR(150) DEFAULT NULL AFTER initial_consultation_reason');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'emergency_contact_phone', 'VARCHAR(40) DEFAULT NULL AFTER emergency_contact_name');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'emergency_contact_relation', 'VARCHAR(80) DEFAULT NULL AFTER emergency_contact_phone');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'address', 'VARCHAR(255) DEFAULT NULL AFTER emergency_contact_relation');
    install_add_column_if_missing($mysqli, 'patient_work_plan_tasks', 'appointment_id', 'INT UNSIGNED DEFAULT NULL AFTER patient_id');
    install_add_column_if_missing($mysqli, 'patient_work_plan_tasks', 'visible_to_patient', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER priority');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'document_path', 'VARCHAR(255) DEFAULT NULL AFTER notes');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'photo_path', 'VARCHAR(255) DEFAULT NULL AFTER notes');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'document_name', 'VARCHAR(255) DEFAULT NULL AFTER document_path');
    install_add_column_if_missing($mysqli, 'closed_days', 'is_global', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER reason');
    install_add_column_if_missing($mysqli, 'payment_settings', 'show_team_public', 'TINYINT(1) NOT NULL DEFAULT 0');
    install_add_column_if_missing($mysqli, 'payment_settings', 'public_site_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER show_team_public');
    install_add_column_if_missing($mysqli, 'payment_settings', 'show_contact_public', 'TINYINT(1) NOT NULL DEFAULT 0');
    install_add_column_if_missing($mysqli, 'payment_settings', 'allow_patient_transfer', 'TINYINT(1) NOT NULL DEFAULT 0');
    install_add_column_if_missing($mysqli, 'payment_settings', 'plan_key', 'VARCHAR(32) NOT NULL DEFAULT "novus" AFTER allow_patient_transfer');
    install_add_column_if_missing($mysqli, 'payment_settings', 'dashboard_config_mode', 'VARCHAR(16) NOT NULL DEFAULT "simple"');
    install_add_column_if_missing($mysqli, 'payment_settings', 'sector_texts_key', 'VARCHAR(32) NOT NULL DEFAULT "psicologia" AFTER dashboard_config_mode');
    install_add_column_if_missing($mysqli, 'payment_settings', 'site_tagline', 'VARCHAR(255) NULL AFTER app_name');
    install_add_column_if_missing($mysqli, 'payment_settings', 'site_phone', 'VARCHAR(40) NULL AFTER site_tagline');
    install_add_column_if_missing($mysqli, 'payment_settings', 'favicon_path', 'VARCHAR(255) NULL AFTER profile_image_path');
    install_add_column_if_missing($mysqli, 'payment_settings', 'primary_color', 'VARCHAR(7) NOT NULL DEFAULT "#4285f4" AFTER favicon_path');
    install_add_column_if_missing($mysqli, 'payment_settings', 'legal_owner_name', 'VARCHAR(255) NULL');
    install_add_column_if_missing($mysqli, 'payment_settings', 'legal_nif', 'VARCHAR(50) NULL');
    install_add_column_if_missing($mysqli, 'payment_settings', 'legal_address', 'VARCHAR(500) NULL');
    install_add_column_if_missing($mysqli, 'payment_settings', 'legal_email', 'VARCHAR(255) NULL');
    install_add_column_if_missing($mysqli, 'payment_settings', 'legal_license_number', 'VARCHAR(100) NULL');
    install_add_column_if_missing($mysqli, 'payment_settings', 'legal_professional_college', 'VARCHAR(255) NULL');
    install_add_column_if_missing($mysqli, 'payment_settings', 'legal_uses_non_technical_cookies', 'TINYINT NOT NULL DEFAULT 0');
    install_add_column_if_missing($mysqli, 'payment_settings', 'legal_terms_notes', 'TEXT NULL');

    install_add_column_if_missing($mysqli, 'payment_settings', 'min_booking_notice_days', 'INT NOT NULL DEFAULT 2');
    install_add_column_if_missing($mysqli, 'payment_settings', 'max_booking_notice_days', 'INT NOT NULL DEFAULT 40');
    install_add_column_if_missing($mysqli, 'payment_settings', 'appointment_start_time', 'TIME NOT NULL DEFAULT "10:00:00"');
    install_add_column_if_missing($mysqli, 'payment_settings', 'appointment_end_time', 'TIME NOT NULL DEFAULT "19:00:00"');
    install_add_column_if_missing($mysqli, 'payment_settings', 'break_start_time', 'TIME NOT NULL DEFAULT "15:00:00"');
    install_add_column_if_missing($mysqli, 'payment_settings', 'break_end_time', 'TIME NOT NULL DEFAULT "16:00:00"');
    install_add_column_if_missing($mysqli, 'payment_settings', 'available_weekdays', 'VARCHAR(30) NOT NULL DEFAULT "1,2,3,4,5"');
    install_add_column_if_missing($mysqli, 'payment_settings', 'appointment_delivery_mode', 'VARCHAR(20) NOT NULL DEFAULT "both"');
    install_add_column_if_missing($mysqli, 'payment_settings', 'available_session_types', 'VARCHAR(100) NOT NULL DEFAULT "individual"');
    install_add_column_if_missing($mysqli, 'payment_settings', 'available_session_durations', 'VARCHAR(50) NOT NULL DEFAULT "60"');
    install_add_column_if_missing($mysqli, 'payment_settings', 'display_effective_duration_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
    install_add_column_if_missing($mysqli, 'payment_settings', 'display_duration_offset_minutes', 'TINYINT UNSIGNED NOT NULL DEFAULT 5');
    install_add_column_if_missing($mysqli, 'payment_settings', 'online_booking_enabled', 'TINYINT(1) NOT NULL DEFAULT 1');
    install_add_column_if_missing($mysqli, 'payment_settings', 'patient_registration_mode', 'VARCHAR(16) NOT NULL DEFAULT "invite"');
    install_add_column_if_missing($mysqli, 'payment_settings', 'patient_tasks_visible_default', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER patient_registration_mode');
    install_add_column_if_missing($mysqli, 'payment_settings', 'initial_calendar_view', 'VARCHAR(12) NOT NULL DEFAULT "month"');
    install_add_column_if_missing($mysqli, 'payment_settings', 'calendar_provider', 'VARCHAR(16) NOT NULL DEFAULT "none"');
    install_add_column_if_missing($mysqli, 'payment_settings', 'icloud_calendar_email', 'VARCHAR(255) NULL');
    install_add_column_if_missing($mysqli, 'payment_settings', 'icloud_calendar_app_password', 'VARCHAR(255) NULL');
    install_add_column_if_missing($mysqli, 'payment_settings', 'icloud_calendar_url', 'VARCHAR(512) NULL DEFAULT "https://caldav.icloud.com"');
    install_add_column_if_missing($mysqli, 'payment_settings', 'send_patient_calendar_link', 'TINYINT(1) NOT NULL DEFAULT 1');
    install_add_column_if_missing($mysqli, 'payment_settings', 'fastcron_planning_cron_id', 'VARCHAR(64) NULL');
}

function install_write_config($path, $settings)
{
    $contents = "<?php\n";
    $contents .= "// Configuración local generada por el instalador.\n";
    $contents .= "return " . var_export($settings, true) . ";\n";

    return file_put_contents($path, $contents, LOCK_EX) !== false;
}

function install_mysql_ssl_cert_path($rootDir)
{
    $certName = defined('DB_SSL_CERT') ? DB_SSL_CERT : 'mysql.pem';
    $certName = ltrim($certName, '/\\');
    $candidates = [
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/' . $certName,
        $rootDir . '/' . $certName,
        dirname($rootDir) . '/' . $certName,
    ];

    foreach ($candidates as $candidate) {
        if ($candidate && file_exists($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function install_dashboard_config_mode($value)
{
    $value = strtolower(trim((string) $value));
    $aliases = [
        'simple' => 'simple',
        'sencillo' => 'simple',
        'advanced' => 'advanced',
        'avanzado' => 'advanced',
        'completo' => 'advanced',
        'custom' => 'custom',
        'personalizado' => 'custom',
    ];

    return $aliases[$value] ?? 'advanced';
}

if ($requestMethod === 'POST') {
    $sectorTextsKey = $sectorIsPreconfigured ? $defaultSectorTextsKey : ($_POST['sector_texts_key'] ?? sector_texts_default_key());
    $dashboardConfigMode = install_dashboard_config_mode($tenantInstallerConfig['dashboard_config_mode'] ?? 'advanced');
    $publicSiteEnabled = !empty($tenantInstallerConfig['public_site_enabled']) ? 1 : 0;
    $tenantDbName = trim((string) ($tenantDatabaseConfig['name'] ?? ''));
    $resolvedDbName = defined('DB_NAME') ? DB_NAME : ($tenantDbName !== '' ? $tenantDbName : 'sgpraxis');

    $settings = [
        'db_host' => trim((string) ($hasTenantConfig ? $defaults['db_host'] : ($_POST['db_host'] ?? ''))),
        'db_port' => (int) ($hasTenantConfig ? $defaults['db_port'] : ($_POST['db_port'] ?? 3306)),
        'db_user' => trim((string) ($hasTenantConfig ? $defaults['db_user'] : ($_POST['db_user'] ?? ''))),
        'db_password' => (string) ($hasTenantConfig ? $defaults['db_password'] : ($_POST['db_password'] ?? '')),
        'db_name' => $resolvedDbName,
        'db_ssl' => $hasTenantConfig ? $defaults['db_ssl'] === '1' : !empty($_POST['db_ssl']),
        'db_ssl_cert' => $tenantDatabaseConfig['ssl_cert'] ?? (defined('DB_SSL_CERT') ? DB_SSL_CERT : 'mysql.pem'),
        'timezone' => trim($_POST['timezone'] ?? 'Atlantic/Canary'),
        'max_booking_days' => defined('MAX_BOOKING_DAYS') ? MAX_BOOKING_DAYS : 40,
        'cron_webhook_token' => defined('CRON_WEBHOOK_TOKEN') && CRON_WEBHOOK_TOKEN !== '' ? CRON_WEBHOOK_TOKEN : bin2hex(random_bytes(32)),
        'fastcron_api_key' => defined('FASTCRON_API_KEY') ? FASTCRON_API_KEY : '',
        'workoutx_api_key' => defined('WORKOUTX_API_KEY') ? WORKOUTX_API_KEY : '',
    ];

    $appName = trim($_POST['app_name'] ?? '');
    $adminName = trim($_POST['admin_name'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPassword = (string) ($_POST['admin_password'] ?? '');

    if ($appName === '') {
        $errors[] = 'Indica el nombre o título del sitio.';
    }
    if ($adminName === '') {
        $errors[] = 'Indica el nombre del usuario administrador.';
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Indica un email válido para el administrador.';
    }
    if (strlen($adminPassword) < 6) {
        $errors[] = 'La contraseña del administrador debe tener al menos 6 caracteres.';
    }
    if (!sector_texts_validate_key($sectorTextsKey) || !sector_texts_read_file($sectorTextsKey)) {
        $errors[] = 'Selecciona un sector válido para esta instalación.';
    }
    if ($settings['db_host'] === '' || $settings['db_user'] === '' || $settings['db_name'] === '') {
        $errors[] = 'Indica host, usuario y nombre de la base de datos.';
    }
    if ($settings['db_name'] !== '' && !install_validate_database_name($settings['db_name'])) {
        $errors[] = 'El nombre de la base de datos solo puede contener letras, números y guiones bajos.';
    }
    if ($settings['db_port'] <= 0) {
        $settings['db_port'] = 3306;
    }
    if (!$installTenant) {
        $errors[] = 'No se pudo identificar el tenant que se va a instalar.';
    }
    if ($installed) {
        $errors[] = 'Este tenant ya esta instalado.';
    }

    if (!$errors) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $test = install_open_db();

            require_once $rootDir . '/mail_helpers.php';
            require_once $rootDir . '/settings_helpers.php';
            require_once $rootDir . '/payment_helpers.php';

            ensure_current_tenant_payment_settings($test);

            $defaultTagline = '';
            $defaultPrimaryColor = '#4285f4';
            $tenantId = (int) $installTenant['id'];
            $stmt = $test->prepare("UPDATE payment_settings SET app_name = ?, site_tagline = COALESCE(NULLIF(site_tagline, ''), ?), admin_notification_email = ?, sector_texts_key = ?, dashboard_config_mode = ?, public_site_enabled = ?, primary_color = ? WHERE tenant_id = ?");
            $stmt->bind_param('sssssisi', $appName, $defaultTagline, $adminEmail, $sectorTextsKey, $dashboardConfigMode, $publicSiteEnabled, $defaultPrimaryColor, $tenantId);
            $stmt->execute();
            $stmt->close();
            seed_default_appointment_services($test);

            $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
            $role = 'superadmin';
            $stmt = $test->prepare("SELECT id FROM users WHERE tenant_id = ? AND email = ? LIMIT 1");
            $stmt->bind_param('is', $tenantId, $adminEmail);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $adminUserId = (int) ($row['id'] ?? 0);
            $stmt->close();

            if ($adminUserId > 0) {
                $stmt = $test->prepare("UPDATE users SET name = ?, password_hash = ?, role = 'superadmin' WHERE tenant_id = ? AND id = ?");
                $stmt->bind_param('ssii', $adminName, $passwordHash, $tenantId, $adminUserId);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $test->prepare("INSERT INTO users (tenant_id, name, email, phone, password_hash, role) VALUES (?, ?, ?, NULL, ?, ?)");
                $stmt->bind_param('issss', $tenantId, $adminName, $adminEmail, $passwordHash, $role);
                $stmt->execute();
                $adminUserId = (int) $test->insert_id;
                $stmt->close();
            }

            if ($adminUserId > 0 && function_exists('seed_default_professional')) {
                seed_default_professional($test);
            }

            if (!is_dir(app_tenant_public_uploads_dir())) {
                @mkdir(app_tenant_public_uploads_dir(), 0775, true);
            }

            $stmt = $test->prepare("
                UPDATE tenants
                SET tenant_name = ?, app_name = ?, sector_texts_key = ?, dashboard_config_mode = ?, public_site_enabled = ?, db_name = ?, status = 'active', installed_at = COALESCE(installed_at, NOW())
                WHERE id = ?
            ");
            $stmt->bind_param('ssssisi', $appName, $appName, $sectorTextsKey, $dashboardConfigMode, $publicSiteEnabled, $settings['db_name'], $tenantId);
            $stmt->execute();
            $stmt->close();

            $test->close();

            $success = true;
            $installed = true;
        } catch (Throwable $e) {
            $errors[] = 'No se pudo completar la instalación: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Instalación - SimplyGest Praxis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        body { background: #f6f7fb; }
        .install-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
        }
        .install-card {
            width: min(760px, 100%);
            background: #fff;
            border: 1px solid #e1e5ec;
            border-radius: 10px;
            box-shadow: 0 18px 45px rgba(30, 35, 50, .08);
            padding: 28px;
        }
        .install-card > .install-brand-logo,
        .install-card > h1,
        .install-card > p {
            text-align: center;
        }
        .install-brand-logo {
            display: block;
            width: min(220px, 72%);
            height: auto;
            margin: 0 auto 22px;
        }
        .install-steps {
            display: flex;
            gap: 10px;
            margin: 24px 0;
        }
        .install-step {
            flex: 1;
            border: 1px solid #d9dfe8;
            border-radius: 8px;
            padding: 12px;
            color: #687286;
            font-weight: 600;
        }
        .install-step.active {
            border-color: var(--primary-color, #4285f4);
            color: var(--primary-color, #4285f4);
            background: rgba(143, 123, 192, .08);
        }
        .install-step-panel[hidden] { display: none; }
        .install-actions {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-top: 24px;
        }
        .install-progress-overlay {
            position: fixed;
            inset: 0;
            z-index: 1080;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(20, 24, 32, .56);
            backdrop-filter: blur(2px);
            padding: 20px;
        }
        .install-progress-overlay.show {
            display: flex;
        }
        .install-progress-box {
            width: min(420px, 100%);
            border-radius: 12px;
            background: #fff;
            border: 1px solid #e1e5ec;
            box-shadow: 0 18px 45px rgba(15, 20, 30, .18);
            padding: 28px;
            text-align: center;
        }
        .install-legal-footer {
            margin-top: 18px;
            text-align: center;
            color: #7b8495;
            font-size: .86rem;
        }
        @media (max-width: 576px) {
            .install-card { padding: 20px; }
            .install-steps { flex-direction: column; }
            .install-actions { flex-direction: column; }
        }
    </style>
</head>
<body>
    <main class="install-shell">
        <div class="install-card">
            <img src="<?php echo htmlspecialchars($officialBrandLogoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="SimplyGest Praxis" class="install-brand-logo">
            <h1 class="h3 mb-2">Instalación de SimplyGest Praxis</h1>
            <p class="text-muted mb-0">Configura los datos básicos para dejar lista esta instalación.</p>

            <?php if ($success): ?>
                <div class="alert alert-success mt-4">
                    Instalación completada correctamente. Ya puedes entrar al dashboard con el usuario administrador.
                </div>
                <a class="btn btn-primary" href="../login.php">Ir al login</a>
            <?php elseif ($installed && $requestMethod !== 'POST'): ?>
                <div class="alert alert-info mt-4">
                    Este tenant ya est&aacute; instalado. Para cambiar su configuraci&oacute;n, entra al dashboard o edita la fila correspondiente en <strong>tenants</strong>.
                </div>
                <a class="btn btn-primary" href="../login.php">Ir al login</a>
            <?php elseif ($tenantConfigError !== '' && !$installTenant && $requestMethod !== 'POST'): ?>
                <div class="alert alert-danger mt-4">
                    <?php echo htmlspecialchars($tenantConfigError, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php else: ?>
                <?php if ($errors): ?>
                    <div class="alert alert-danger mt-4">
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($tenantConfigError !== '' && !$installTenant && !$errors): ?>
                    <div class="alert alert-danger mt-4">
                        <?php echo htmlspecialchars($tenantConfigError, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <div class="install-steps" aria-label="Pasos de instalación">
                    <div class="install-step active" data-step-label="1">1. Datos generales</div>
                    <div class="install-step" data-step-label="2">2. Instalación</div>
                </div>

                <form method="post" id="installForm">
                    <section class="install-step-panel" data-step-panel="1">
                        <div class="row g-3">
                            <?php if ($hasTenantConfig): ?>
                                <div class="col-12">
                                    <div class="alert alert-info mb-0">
                                        Esta instalación tiene la configuración técnica predefinida por SimplyGest.
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="col-12">
                                <label class="form-label" for="app_name">Título de la web</label>
                                <input class="form-control" type="text" id="app_name" name="app_name" value="<?php echo install_value('app_name', $defaults); ?>" required>
                            </div>
                            <?php if ($sectorIsPreconfigured): ?>
                                <input type="hidden" id="sector_texts_key" name="sector_texts_key" value="<?php echo htmlspecialchars($defaultSectorTextsKey, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php else: ?>
                            <div class="col-md-6">
                                <label class="form-label" for="sector_texts_key">Sector</label>
                                <select class="form-select" id="sector_texts_key" name="sector_texts_key" required>
                                    <?php
                                    $selectedSector = $_POST['sector_texts_key'] ?? $defaults['sector_texts_key'];
                                    foreach ($sectorTextOptions as $sectorOption):
                                        $sectorKey = $sectorOption['key'] ?? sector_texts_default_key();
                                    ?>
                                        <option value="<?php echo htmlspecialchars($sectorKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selectedSector === $sectorKey ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sectorOption['name'] ?? $sectorKey, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-6">
                                <label class="form-label" for="timezone">Zona horaria</label>
                                <select class="form-select" id="timezone" name="timezone" required>
                                    <?php
                                    $timezones = ['Atlantic/Canary', 'Europe/Madrid', 'UTC'];
                                    $selectedTimezone = $_POST['timezone'] ?? $defaults['timezone'];
                                    foreach ($timezones as $timezone):
                                    ?>
                                        <option value="<?php echo $timezone; ?>" <?php echo $selectedTimezone === $timezone ? 'selected' : ''; ?>><?php echo $timezone; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="admin_name">Nombre del administrador</label>
                                <input class="form-control" type="text" id="admin_name" name="admin_name" value="<?php echo install_value('admin_name', $defaults); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="admin_email">Email del administrador</label>
                                <input class="form-control" type="email" id="admin_email" name="admin_email" value="<?php echo install_value('admin_email', $defaults); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="admin_password">Contraseña del administrador</label>
                                <input class="form-control" type="password" id="admin_password" name="admin_password" minlength="6" required>
                            </div>
                        </div>
                        <div class="install-actions justify-content-end">
                            <button class="btn btn-primary" type="button" data-next-step>Continuar</button>
                        </div>
                    </section>

                    <section class="install-step-panel" data-step-panel="2" hidden>
                        <div class="row g-3">
                            <?php if ($hasTenantConfig): ?>
                                <div class="col-12">
                                    <div class="alert alert-light border mb-0">
                                        Pulsa <strong>Guardar instalación</strong> para continuar.
                                    </div>
                                </div>
                            <?php else: ?>
                            <div class="col-md-8">
                                <label class="form-label" for="db_host">Servidor de base de datos</label>
                                <input class="form-control" type="text" id="db_host" name="db_host" value="<?php echo install_value('db_host', $defaults); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="db_port">Puerto</label>
                                <input class="form-control" type="number" id="db_port" name="db_port" value="<?php echo install_value('db_port', $defaults); ?>" min="1" required>
                            </div>
                            <?php endif; ?>
                            <input type="hidden" id="db_name" value="<?php echo install_value('db_name', $defaults); ?>" data-derived-db-name="<?php echo empty($tenantDatabaseConfig['name']) ? '1' : '0'; ?>">
                            <?php if (!$hasTenantConfig): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="db_user">Usuario</label>
                                <input class="form-control" type="text" id="db_user" name="db_user" value="<?php echo install_value('db_user', $defaults); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="db_password">Contraseña</label>
                                <input class="form-control" type="password" id="db_password" name="db_password" value="<?php echo install_value('db_password', $defaults); ?>">
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="db_ssl" name="db_ssl" value="1" <?php echo install_value('db_ssl', $defaults) === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="db_ssl">Usar conexión SSL con MySQL</label>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="install-actions">
                            <button class="btn btn-outline-secondary" type="button" data-prev-step>Volver</button>
                            <button class="btn btn-primary" type="submit">Guardar instalación</button>
                        </div>
                    </section>
                </form>
            <?php endif; ?>
            <footer class="install-legal-footer">
                <span class="legal-brand-line">
                    <img src="<?php echo htmlspecialchars($officialBrandLogoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="legal-brand-mark">
                    <span><?php echo htmlspecialchars(app_legal_footer_text(), ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
            </footer>
        </div>
    </main>
    <div class="install-progress-overlay" id="installProgressOverlay" aria-live="polite" aria-modal="true" role="dialog">
        <div class="install-progress-box">
            <div class="spinner-border text-primary mb-3" role="status" aria-hidden="true"></div>
            <h2 class="h5 mb-2">Preparando tu entorno</h2>
            <p class="text-muted mb-0">Estamos preparando la instalaci&oacute;n y creando la configuraci&oacute;n inicial. Espera unos segundos, por favor.</p>
        </div>
    </div>

    <script>
        (() => {
            const labels = document.querySelectorAll('[data-step-label]');
            const panels = document.querySelectorAll('[data-step-panel]');
            const nextButton = document.querySelector('[data-next-step]');
            const prevButton = document.querySelector('[data-prev-step]');
            const sectorSelect = document.getElementById('sector_texts_key');
            const dbNameInput = document.getElementById('db_name');
            const installForm = document.getElementById('installForm');
            const installOverlay = document.getElementById('installProgressOverlay');
            const tenantKey = <?php echo json_encode(install_slug_part(basename($rootDir), 'tenant')); ?>;

            function slugPart(value, fallback) {
                return String(value || '')
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '_')
                    .replace(/^_+|_+$/g, '') || fallback;
            }

            function updateDerivedDbName() {
                if (!sectorSelect || !dbNameInput || dbNameInput.dataset.derivedDbName !== '1') {
                    return;
                }
                dbNameInput.value = `${tenantKey}_${slugPart(sectorSelect.value, 'psicologia')}`.slice(0, 64);
            }

            function showStep(step) {
                labels.forEach(label => label.classList.toggle('active', label.dataset.stepLabel === String(step)));
                panels.forEach(panel => {
                    panel.hidden = panel.dataset.stepPanel !== String(step);
                });
            }

            nextButton?.addEventListener('click', () => {
                const firstPanel = document.querySelector('[data-step-panel="1"]');
                const fields = firstPanel.querySelectorAll('input, select, textarea');
                for (const field of fields) {
                    if (!field.checkValidity()) {
                        field.reportValidity();
                        return;
                    }
                }
                showStep(2);
            });

            prevButton?.addEventListener('click', () => showStep(1));
            sectorSelect?.addEventListener('change', updateDerivedDbName);
            installForm?.addEventListener('submit', () => {
                const submitButton = installForm.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Instalando...';
                }
                installOverlay?.classList.add('show');
            });
            updateDerivedDbName();
        })();
    </script>
</body>
</html>
