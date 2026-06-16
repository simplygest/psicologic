<?php
session_start();

$rootDir = dirname(__DIR__);
require_once $rootDir . '/config.php';
require_once $rootDir . '/cabinet_helpers.php';

$configPath = $rootDir . '/config.local.php';
$installed = file_exists($configPath);
$errors = [];
$success = false;

$defaults = [
    'app_name' => defined('DEFAULT_APP_NAME') ? DEFAULT_APP_NAME : 'PsicoLogic',
    'timezone' => defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Atlantic/Canary',
    'admin_name' => 'Administrador',
    'admin_email' => '',
    'admin_password' => '',
    'db_host' => defined('DB_HOST') ? DB_HOST : 'localhost',
    'db_port' => defined('DB_PORT') ? (string) DB_PORT : '3306',
    'db_name' => defined('DB_NAME') ? DB_NAME : 'psicologic',
    'db_user' => defined('DB_USER') ? DB_USER : '',
    'db_password' => '',
    'db_ssl' => defined('DB_SSL') && DB_SSL ? '1' : '0',
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

function install_base_tables($mysqli)
{
    $mysqli->query("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(150) UNIQUE NULL,
        phone VARCHAR(30) UNIQUE NULL,
        password_hash VARCHAR(255) NULL,
        role ENUM('superadmin','admin','patient') NOT NULL DEFAULT 'patient',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
        patient_type VARCHAR(80) DEFAULT NULL,
        patient_status VARCHAR(20) NOT NULL DEFAULT 'active',
        birth_date DATE DEFAULT NULL,
        referral_source VARCHAR(80) DEFAULT NULL,
        initial_consultation_reason TEXT DEFAULT NULL,
        emergency_contact_name VARCHAR(150) DEFAULT NULL,
        emergency_contact_phone VARCHAR(40) DEFAULT NULL,
        emergency_contact_relation VARCHAR(80) DEFAULT NULL,
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
        user_id INT UNSIGNED NOT NULL,
        appointment_date DATE NOT NULL,
        appointment_time TIME NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'booked',
        payment_status VARCHAR(32) NOT NULL DEFAULT 'pending',
        payment_method VARCHAR(16) DEFAULT NULL,
        paid_at DATETIME DEFAULT NULL,
        payment_updated_at DATETIME DEFAULT NULL,
        payment_updated_by INT UNSIGNED DEFAULT NULL,
        online_session_url VARCHAR(500) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        cancelled_at DATETIME NULL,
        INDEX idx_date_time (appointment_date, appointment_time),
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mysqli->query("CREATE TABLE IF NOT EXISTS closed_days (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        closed_date DATE NOT NULL,
        reason VARCHAR(255) NULL,
        is_global TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_closed_date (closed_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mysqli->query("CREATE TABLE IF NOT EXISTS payment_settings (
        id INT PRIMARY KEY DEFAULT 1,
        app_name VARCHAR(150) NOT NULL DEFAULT 'PsicoLogic',
        site_tagline VARCHAR(255) NULL,
        site_phone VARCHAR(40) NULL,
        admin_notification_email VARCHAR(150) NULL,
        favicon_path VARCHAR(255) NULL,
        show_team_public TINYINT(1) NOT NULL DEFAULT 0,
        show_contact_public TINYINT(1) NOT NULL DEFAULT 0,
        allow_patient_transfer TINYINT(1) NOT NULL DEFAULT 0,
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
        online_payment_enabled TINYINT(1) NOT NULL DEFAULT 0,
        environment ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
        merchant_code VARCHAR(20) NULL,
        terminal VARCHAR(10) NOT NULL DEFAULT '1',
        merchant_key TEXT NULL,
        appointment_price DECIMAL(10,2) NOT NULL DEFAULT 70.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mysqli->query("INSERT IGNORE INTO payment_settings (id, app_name, site_tagline, appointment_price) VALUES (1, 'PsicoLogic', 'Psicología sanitaria y neuropsicología en Santa Cruz de Tenerife', 70.00)");

    ensure_cabinet_schema($mysqli);
}

function install_ensure_payment_settings_columns($mysqli)
{
    $mysqli->query("ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL");
    $mysqli->query("ALTER TABLE users MODIFY role ENUM('superadmin','admin','patient') NOT NULL DEFAULT 'patient'");
    install_add_column_if_missing($mysqli, 'invitations', 'user_id', 'INT UNSIGNED DEFAULT NULL AFTER token');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'patient_status', "VARCHAR(20) NOT NULL DEFAULT 'active' AFTER patient_type");
    install_add_column_if_missing($mysqli, 'patient_profiles', 'birth_date', 'DATE DEFAULT NULL AFTER patient_status');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'referral_source', 'VARCHAR(80) DEFAULT NULL AFTER birth_date');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'initial_consultation_reason', 'TEXT DEFAULT NULL AFTER referral_source');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'emergency_contact_name', 'VARCHAR(150) DEFAULT NULL AFTER initial_consultation_reason');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'emergency_contact_phone', 'VARCHAR(40) DEFAULT NULL AFTER emergency_contact_name');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'emergency_contact_relation', 'VARCHAR(80) DEFAULT NULL AFTER emergency_contact_phone');
    install_add_column_if_missing($mysqli, 'patient_work_plan_tasks', 'appointment_id', 'INT UNSIGNED DEFAULT NULL AFTER patient_id');
    install_add_column_if_missing($mysqli, 'patient_work_plan_tasks', 'visible_to_patient', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER priority');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'document_path', 'VARCHAR(255) DEFAULT NULL AFTER notes');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'photo_path', 'VARCHAR(255) DEFAULT NULL AFTER notes');
    install_add_column_if_missing($mysqli, 'patient_profiles', 'document_name', 'VARCHAR(255) DEFAULT NULL AFTER document_path');
    install_add_column_if_missing($mysqli, 'closed_days', 'is_global', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER reason');
    install_add_column_if_missing($mysqli, 'payment_settings', 'show_team_public', 'TINYINT(1) NOT NULL DEFAULT 0');
    install_add_column_if_missing($mysqli, 'payment_settings', 'show_contact_public', 'TINYINT(1) NOT NULL DEFAULT 0');
    install_add_column_if_missing($mysqli, 'payment_settings', 'allow_patient_transfer', 'TINYINT(1) NOT NULL DEFAULT 0');
    install_add_column_if_missing($mysqli, 'payment_settings', 'dashboard_config_mode', 'VARCHAR(16) NOT NULL DEFAULT "simple"');
    install_add_column_if_missing($mysqli, 'payment_settings', 'sector_texts_key', 'VARCHAR(32) NOT NULL DEFAULT "psicologia" AFTER dashboard_config_mode');
    install_add_column_if_missing($mysqli, 'payment_settings', 'site_tagline', 'VARCHAR(255) NULL AFTER app_name');
    install_add_column_if_missing($mysqli, 'payment_settings', 'site_phone', 'VARCHAR(40) NULL AFTER site_tagline');
    install_add_column_if_missing($mysqli, 'payment_settings', 'favicon_path', 'VARCHAR(255) NULL AFTER profile_image_path');
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
    install_add_column_if_missing($mysqli, 'payment_settings', 'available_session_durations', 'VARCHAR(30) NOT NULL DEFAULT "60"');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = [
        'db_host' => trim($_POST['db_host'] ?? ''),
        'db_port' => (int) ($_POST['db_port'] ?? 3306),
        'db_user' => trim($_POST['db_user'] ?? ''),
        'db_password' => (string) ($_POST['db_password'] ?? ''),
        'db_name' => trim($_POST['db_name'] ?? ''),
        'db_ssl' => !empty($_POST['db_ssl']),
        'db_ssl_cert' => defined('DB_SSL_CERT') ? DB_SSL_CERT : 'mysql.pem',
        'timezone' => trim($_POST['timezone'] ?? 'Atlantic/Canary'),
        'max_booking_days' => defined('MAX_BOOKING_DAYS') ? MAX_BOOKING_DAYS : 40,
        'cron_webhook_token' => defined('CRON_WEBHOOK_TOKEN') && CRON_WEBHOOK_TOKEN !== '' ? CRON_WEBHOOK_TOKEN : bin2hex(random_bytes(32)),
        'fastcron_api_key' => defined('FASTCRON_API_KEY') ? FASTCRON_API_KEY : '',
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
    if ($settings['db_host'] === '' || $settings['db_user'] === '' || $settings['db_name'] === '') {
        $errors[] = 'Indica host, usuario y nombre de la base de datos.';
    }
    if ($settings['db_port'] <= 0) {
        $settings['db_port'] = 3306;
    }

    if (!$errors) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $test = mysqli_init();
            $flags = 0;
            if ($settings['db_ssl']) {
                $sslCert = install_mysql_ssl_cert_path($rootDir);
                if ($sslCert) {
                    $test->ssl_set(null, null, $sslCert, null, null);
                }
                $flags = MYSQLI_CLIENT_SSL;
            }

            $test->real_connect(
                $settings['db_host'],
                $settings['db_user'],
                $settings['db_password'],
                $settings['db_name'],
                $settings['db_port'],
                null,
                $flags
            );

            $test->set_charset('utf8mb4');

            install_base_tables($test);

            require_once $rootDir . '/mail_helpers.php';
            require_once $rootDir . '/settings_helpers.php';
            require_once $rootDir . '/payment_helpers.php';

            ensure_admin_notification_email_column($test);
            ensure_branding_columns($test);
            ensure_appointment_payment_columns($test);
            ensure_appointment_services_tables($test);
            ensure_bonus_tables($test);
            ensure_payment_attempts_table($test);
            ensure_payment_settings_price_columns($test);
            install_ensure_payment_settings_columns($test);

            $defaultTagline = 'Psicología sanitaria y neuropsicología en Santa Cruz de Tenerife';
            $stmt = $test->prepare("UPDATE payment_settings SET app_name = ?, site_tagline = COALESCE(NULLIF(site_tagline, ''), ?), admin_notification_email = ? WHERE id = 1");
            $stmt->bind_param('sss', $appName, $defaultTagline, $adminEmail);
            $stmt->execute();
            $stmt->close();

            $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
            $role = 'superadmin';
            $stmt = $test->prepare("INSERT INTO users (name, email, phone, password_hash, role)
                VALUES (?, ?, NULL, ?, ?)
                ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), role = 'superadmin'");
            $stmt->bind_param('ssss', $adminName, $adminEmail, $passwordHash, $role);
            $stmt->execute();
            $stmt->close();

            ensure_cabinet_schema($test);

            $test->close();

            if (!install_write_config($configPath, $settings)) {
                $errors[] = 'No se pudo crear config.local.php. Revisa los permisos de escritura del servidor.';
            } else {
                $success = true;
                $installed = true;
            }
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
    <title>Instalación - PsicoLogic</title>
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
            border-color: var(--primary-color, #8f7bc0);
            color: var(--primary-color, #8f7bc0);
            background: rgba(143, 123, 192, .08);
        }
        .install-step-panel[hidden] { display: none; }
        .install-actions {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-top: 24px;
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
            <h1 class="h3 mb-2">Instalación de PsicoLogic</h1>
            <p class="text-muted mb-0">Configura los datos básicos para dejar lista esta instalación.</p>

            <?php if ($success): ?>
                <div class="alert alert-success mt-4">
                    Instalación completada correctamente. Ya puedes entrar al dashboard con el usuario administrador.
                </div>
                <a class="btn btn-primary" href="../login.php">Ir al login</a>
            <?php elseif ($installed && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
                <div class="alert alert-info mt-4">
                    Esta instalación ya tiene configuración local. Para repetir el asistente, elimina manualmente el archivo <strong>config.local.php</strong>.
                </div>
                <a class="btn btn-primary" href="../login.php">Ir al login</a>
            <?php else: ?>
                <?php if ($errors): ?>
                    <div class="alert alert-danger mt-4">
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="install-steps" aria-label="Pasos de instalación">
                    <div class="install-step active" data-step-label="1">1. Datos generales</div>
                    <div class="install-step" data-step-label="2">2. Base de datos</div>
                </div>

                <form method="post" id="installForm">
                    <section class="install-step-panel" data-step-panel="1">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label" for="app_name">Titulo de la web</label>
                                <input class="form-control" type="text" id="app_name" name="app_name" value="<?php echo install_value('app_name', $defaults); ?>" required>
                            </div>
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
                            <div class="col-md-8">
                                <label class="form-label" for="db_host">Servidor de base de datos</label>
                                <input class="form-control" type="text" id="db_host" name="db_host" value="<?php echo install_value('db_host', $defaults); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="db_port">Puerto</label>
                                <input class="form-control" type="number" id="db_port" name="db_port" value="<?php echo install_value('db_port', $defaults); ?>" min="1" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="db_name">Nombre de la base de datos</label>
                                <input class="form-control" type="text" id="db_name" name="db_name" value="<?php echo install_value('db_name', $defaults); ?>" required>
                            </div>
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
                        </div>
                        <div class="install-actions">
                            <button class="btn btn-outline-secondary" type="button" data-prev-step>Volver</button>
                            <button class="btn btn-primary" type="submit">Guardar instalación</button>
                        </div>
                    </section>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <script>
        (() => {
            const labels = document.querySelectorAll('[data-step-label]');
            const panels = document.querySelectorAll('[data-step-panel]');
            const nextButton = document.querySelector('[data-next-step]');
            const prevButton = document.querySelector('[data-prev-step]');

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
        })();
    </script>
</body>
</html>
