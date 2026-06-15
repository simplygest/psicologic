<?php
session_start();
require_once '../db.php';
require_once '../mail_helpers.php';
require_once '../settings_helpers.php';
require_once '../dashboard_config_helpers.php';
require_once '../payment_helpers.php';
require_once '../fastcron_helpers.php';
require_once '../urlme_helpers.php';
require_once '../cabinet_helpers.php';
header('Content-Type: application/json');

$is_superadmin = ($_SESSION['role'] ?? '') === 'superadmin';
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'], true)) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

ensure_patient_management_tables($mysqli);
ensure_patient_evolution_tables($mysqli);
ensure_patient_work_plan_tables($mysqli);
ensure_work_plan_task_template_tables($mysqli);
ensure_appointment_payment_columns($mysqli);
ensure_appointment_services_tables($mysqli);
ensure_bonus_tables($mysqli);
ensure_payment_attempts_table($mysqli);
ensure_cabinet_schema($mysqli);

$action = $_GET['action'] ?? '';

function current_professional_id_for_user($mysqli, $user_id)
{
    ensure_cabinet_schema($mysqli);
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return 0;
    }
    $stmt = $mysqli->prepare("SELECT id FROM professionals WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
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
    $branding = get_public_branding_settings($mysqli);
    $dashboard_photo = $branding['profile_image_path'] ?? '';
    $rows = [];
    $res = $mysqli->query("
        SELECT p.id, p.display_name, p.public_photo_path, u.role AS user_role
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id
        WHERE p.is_active = 1
        ORDER BY CASE WHEN u.role = 'superadmin' THEN 0 ELSE 1 END ASC,
                 p.sort_order ASC,
                 p.display_name ASC
    ");
    while ($row = $res->fetch_assoc()) {
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
        'service_label' => appointment_service_option_label($row),
        'payment_status' => $row['payment_status'] ?? 'pending',
        'payment_method' => $row['payment_method'] ?? '',
        'patient_bonus_id' => $row['patient_bonus_id'] ?? null
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
    $appointment_id = (int) $appointment_id;
    if ($appointment_id <= 0) {
        return [false, null];
    }

    $stmt = $mysqli->prepare("
        SELECT a.id, a.user_id, a.professional_id, a.appointment_date, a.appointment_time, a.status,
               a.consultation_type, a.service_type, a.service_option_id, a.online_session_url,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.patient_bonus_id, a.paid_at, a.payment_updated_at, a.payment_updated_by,
               s.name AS service_name,
               u.name AS patient_name, u.email AS patient_email, u.phone AS patient_phone,
               p.display_name AS professional_name
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
        LEFT JOIN appointment_services s ON s.id = so.service_id
        JOIN users u ON u.id = a.user_id
        LEFT JOIN professionals p ON p.id = a.professional_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $appointment_id);
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

function admin_can_access_patient($mysqli, $patient_id)
{
    global $is_superadmin;
    $patient_id = (int) $patient_id;
    if ($patient_id <= 0) {
        return false;
    }
    if ($is_superadmin) {
        return true;
    }

    $professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
    if ($professional_id <= 0) {
        return false;
    }

    $stmt = $mysqli->prepare("
        SELECT u.id
        FROM users u
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.is_primary = 1
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id
        WHERE u.id = ?
          AND u.role = 'patient'
          AND COALESCE(ppf.professional_id, pp.professional_id) = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $patient_id, $professional_id);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
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
    if (!$photo && ($row['user_role'] ?? $row['professional_user_role'] ?? '') === 'superadmin') {
        return $dashboard_photo;
    }
    return $photo ?: '';
}

function admin_ensure_password_reset_table($mysqli)
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_resets_user (user_id),
            INDEX idx_password_resets_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function send_professional_password_setup_email($mysqli, $user_id, $name, $email)
{
    $token = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $token);

    $stmt = $mysqli->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $stmt = $mysqli->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
    $stmt->bind_param("is", $user_id, $token_hash);
    $stmt->execute();

    $reset_link = urlme_shorten_url(
        app_public_base_url() . 'reset_password.php?t=' . urlencode($token),
        'Crear contrasena profesional PsicoLogic',
        date('Y-m-d H:i:s', strtotime('+24 hours'))
    );

    return send_app_email(
        $email,
        'Crea tu contraseña de acceso',
        '<p>Hola ' . htmlspecialchars($name) . ',</p>' .
        '<p>Se ha creado tu acceso profesional en ' . htmlspecialchars(get_app_name($mysqli)) . '.</p>' .
        '<p>Para entrar en la web, crea tu contraseña desde este enlace:</p>' .
        '<p><a href="' . htmlspecialchars($reset_link) . '">Crear contraseña de acceso</a></p>' .
        '<p>Este enlace caduca en 24 horas.</p>',
        null,
        $mysqli
    );
}

function ensure_payment_settings_table($mysqli)
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS payment_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            app_name VARCHAR(255) DEFAULT 'PsicoLogic',
            site_tagline VARCHAR(255) DEFAULT NULL,
            site_phone VARCHAR(40) DEFAULT NULL,
            profile_image_path VARCHAR(255) DEFAULT NULL,
            landing_image_path VARCHAR(255) DEFAULT NULL,
            favicon_path VARCHAR(255) DEFAULT NULL,
            primary_color VARCHAR(7) NOT NULL DEFAULT '#8f7fba',
            show_profile_image_public TINYINT(1) NOT NULL DEFAULT 0,
            show_prices_public TINYINT(1) NOT NULL DEFAULT 0,
            show_contact_public TINYINT(1) NOT NULL DEFAULT 0,
            online_booking_enabled TINYINT(1) NOT NULL DEFAULT 1,
            patient_registration_mode VARCHAR(16) NOT NULL DEFAULT 'invite',
            dashboard_config_mode VARCHAR(16) NOT NULL DEFAULT 'simple',
            patient_tasks_visible_default TINYINT(1) NOT NULL DEFAULT 0,
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
            available_session_types VARCHAR(32) NOT NULL DEFAULT 'individual',
            available_session_durations VARCHAR(16) NOT NULL DEFAULT '60',
            display_effective_duration_enabled TINYINT(1) NOT NULL DEFAULT 0,
            display_duration_offset_minutes TINYINT UNSIGNED NOT NULL DEFAULT 5,
            appointment_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0,
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

    ensure_payment_settings_price_columns($mysqli);

    $mysqli->query("
        INSERT IGNORE INTO payment_settings
            (id, online_payment_enabled, environment, appointment_price, min_booking_notice_days)
        VALUES
            (1, 0, 'sandbox', 70.00, 2)
    ");

    ensure_admin_notification_email_column($mysqli);
    ensure_branding_columns($mysqli);
    ensure_appointment_services_tables($mysqli);
    ensure_bonus_tables($mysqli);

    $columns = [
        'email_provider' => "ALTER TABLE payment_settings ADD email_provider ENUM('phpmailer', 'google') NOT NULL DEFAULT 'phpmailer' AFTER admin_notification_email",
        'app_name' => "ALTER TABLE payment_settings ADD app_name VARCHAR(255) DEFAULT 'PsicoLogic' AFTER id",
        'site_tagline' => "ALTER TABLE payment_settings ADD site_tagline VARCHAR(255) DEFAULT NULL AFTER app_name",
        'site_phone' => "ALTER TABLE payment_settings ADD site_phone VARCHAR(40) DEFAULT NULL AFTER site_tagline",
        'profile_image_path' => "ALTER TABLE payment_settings ADD profile_image_path VARCHAR(255) DEFAULT NULL AFTER app_name",
        'landing_image_path' => "ALTER TABLE payment_settings ADD landing_image_path VARCHAR(255) DEFAULT NULL AFTER profile_image_path",
        'favicon_path' => "ALTER TABLE payment_settings ADD favicon_path VARCHAR(255) DEFAULT NULL AFTER profile_image_path",
        'primary_color' => "ALTER TABLE payment_settings ADD primary_color VARCHAR(7) NOT NULL DEFAULT '#8f7fba' AFTER landing_image_path",
        'show_profile_image_public' => "ALTER TABLE payment_settings ADD show_profile_image_public TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_image_path",
        'show_prices_public' => "ALTER TABLE payment_settings ADD show_prices_public TINYINT(1) NOT NULL DEFAULT 0 AFTER show_profile_image_public",
        'show_contact_public' => "ALTER TABLE payment_settings ADD show_contact_public TINYINT(1) NOT NULL DEFAULT 0 AFTER show_prices_public",
        'online_booking_enabled' => "ALTER TABLE payment_settings ADD online_booking_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER show_contact_public",
        'patient_registration_mode' => "ALTER TABLE payment_settings ADD patient_registration_mode VARCHAR(16) NOT NULL DEFAULT 'invite' AFTER online_booking_enabled",
        'patient_tasks_visible_default' => "ALTER TABLE payment_settings ADD patient_tasks_visible_default TINYINT(1) NOT NULL DEFAULT 0 AFTER patient_registration_mode",
        'initial_calendar_view' => "ALTER TABLE payment_settings ADD initial_calendar_view VARCHAR(12) NOT NULL DEFAULT 'month' AFTER online_booking_enabled",
        'bonuses_enabled' => "ALTER TABLE payment_settings ADD bonuses_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER show_prices_public",
        'create_compensation_bonus_on_paid_cancel' => "ALTER TABLE payment_settings ADD create_compensation_bonus_on_paid_cancel TINYINT(1) NOT NULL DEFAULT 1 AFTER bonuses_enabled",
        'appointment_delivery_mode' => "ALTER TABLE payment_settings ADD appointment_delivery_mode ENUM('both', 'presencial', 'online') NOT NULL DEFAULT 'both' AFTER admin_notification_email",
        'available_session_types' => "ALTER TABLE payment_settings ADD available_session_types VARCHAR(32) NOT NULL DEFAULT 'individual' AFTER appointment_delivery_mode",
        'available_session_durations' => "ALTER TABLE payment_settings ADD available_session_durations VARCHAR(16) NOT NULL DEFAULT '60' AFTER available_session_types",
        'display_effective_duration_enabled' => "ALTER TABLE payment_settings ADD display_effective_duration_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER available_session_durations",
        'display_duration_offset_minutes' => "ALTER TABLE payment_settings ADD display_duration_offset_minutes TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER display_effective_duration_enabled",
        'appointment_reminder_enabled' => "ALTER TABLE payment_settings ADD appointment_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER admin_notification_email",
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
        'dashboard_config_mode' => "ALTER TABLE payment_settings ADD dashboard_config_mode VARCHAR(16) NOT NULL DEFAULT 'simple'"
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
        WHERE id = 1
          AND google_calendar_enabled = 1
          AND (calendar_provider IS NULL OR calendar_provider = '' OR calendar_provider = 'none')
    ");
    $mysqli->query("
        UPDATE payment_settings
        SET appointment_start_time = '10:00:00',
            appointment_end_time = '19:00:00',
            break_start_time = '15:00:00',
            break_end_time = '16:00:00'
        WHERE id = 1
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

function ensure_patient_management_tables($mysqli)
{
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = [
        'patient_status' => "ALTER TABLE patient_profiles ADD patient_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER patient_type",
        'birth_date' => "ALTER TABLE patient_profiles ADD birth_date DATE DEFAULT NULL AFTER patient_status",
        'referral_source' => "ALTER TABLE patient_profiles ADD referral_source VARCHAR(80) DEFAULT NULL AFTER birth_date",
        'initial_consultation_reason' => "ALTER TABLE patient_profiles ADD initial_consultation_reason TEXT DEFAULT NULL AFTER referral_source",
        'emergency_contact_name' => "ALTER TABLE patient_profiles ADD emergency_contact_name VARCHAR(150) DEFAULT NULL AFTER initial_consultation_reason",
        'emergency_contact_phone' => "ALTER TABLE patient_profiles ADD emergency_contact_phone VARCHAR(40) DEFAULT NULL AFTER emergency_contact_name",
        'emergency_contact_relation' => "ALTER TABLE patient_profiles ADD emergency_contact_relation VARCHAR(80) DEFAULT NULL AFTER emergency_contact_phone",
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
}

function ensure_patient_evolution_tables($mysqli)
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS patient_evolution_notes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            patient_id INT UNSIGNED NOT NULL,
            appointment_id INT UNSIGNED DEFAULT NULL,
            professional_id INT UNSIGNED DEFAULT NULL,
            note_date DATE NOT NULL,
            title VARCHAR(180) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            observations LONGTEXT DEFAULT NULL,
            next_steps LONGTEXT DEFAULT NULL,
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
}

function ensure_patient_work_plan_tables($mysqli)
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS patient_work_plan_tasks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_work_plan_appointment (appointment_id),
            INDEX idx_work_plan_patient_status (patient_id, status),
            INDEX idx_work_plan_professional (professional_id),
            INDEX idx_work_plan_priority (priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = [
        'appointment_id' => "ALTER TABLE patient_work_plan_tasks ADD appointment_id INT UNSIGNED DEFAULT NULL AFTER patient_id",
        'visible_to_patient' => "ALTER TABLE patient_work_plan_tasks ADD visible_to_patient TINYINT(1) NOT NULL DEFAULT 0 AFTER priority"
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
}

function ensure_work_plan_task_template_tables($mysqli)
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS work_plan_task_templates (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
            template_id INT UNSIGNED NOT NULL,
            title VARCHAR(180) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            priority TINYINT UNSIGNED NOT NULL DEFAULT 2,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_template_items_template (template_id),
            INDEX idx_template_items_sort (template_id, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function patient_has_portal_access($row)
{
    return !empty($row['email']) && !empty($row['password_hash']);
}

function stored_upload_full_path($relative_path)
{
    $relative_path = ltrim((string) $relative_path, '/\\');
    if ($relative_path === '') {
        return '';
    }
    if (substr($relative_path, 0, 11) === '_protected/') {
        return dirname(__DIR__, 2) . '/' . $relative_path;
    }
    return dirname(__DIR__) . '/' . $relative_path;
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

    $upload_dir = dirname(__DIR__, 2) . '/_protected/uploads/psicologic/patients';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        throw new \Exception('No se pudo crear la carpeta de documentos.');
    }

    $filename = 'patient_' . (int) $patient_id . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$extension];
    $destination = $upload_dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new \Exception('No se pudo guardar el documento.');
    }

    return [
        'path' => '_protected/uploads/psicologic/patients/' . $filename,
        'name' => basename($file['name'])
    ];
}

function patient_evolution_upload_dir()
{
    return dirname(__DIR__, 2) . '/_protected/uploads/psicologic/evolution';
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
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
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

        $relative_path = '_protected/uploads/psicologic/evolution/' . $stored_name;
        $original_name = basename($file['name']);
        $file_size = (int) ($file['size'] ?? 0);
        $uploaded_by = (int) ($_SESSION['user_id'] ?? 0);
        $stmt = $mysqli->prepare("
            INSERT INTO patient_evolution_files (evolution_note_id, patient_id, original_name, stored_name, file_path, mime_type, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iissssii", $note_id, $patient_id, $original_name, $stored_name, $relative_path, $mime, $file_size, $uploaded_by);
        $stmt->execute();
        $saved++;
    }
    return $saved;
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
    $upload_dir = dirname(__DIR__) . '/uploads/patients';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        throw new \Exception('No se pudo crear la carpeta de fotos de pacientes.');
    }

    $filename = 'patient_photo_' . (int) $patient_id . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$image_info['mime']];
    $destination = $upload_dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new \Exception('No se pudo guardar la foto del paciente.');
    }

    return 'uploads/patients/' . $filename;
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

function normalize_available_session_types($value)
{
    $selected = ['individual'];
    $allowed = ['couple', 'family', 'group'];
    foreach ((array) $value as $type) {
        $type = trim((string) $type);
        if (in_array($type, $allowed, true) && !in_array($type, $selected, true)) {
            $selected[] = $type;
        }
    }

    return implode(',', $selected);
}

function normalize_available_session_durations($value)
{
    $selected = [];
    foreach ((array) $value as $duration) {
        $duration = (int) $duration;
        if (in_array($duration, [60, 90, 120], true) && !in_array($duration, $selected, true)) {
            $selected[] = $duration;
        }
    }

    sort($selected);
    return $selected ? implode(',', $selected) : '60';
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

    $active_service_types = array_filter(array_map('trim', explode(',', $available_session_types ?: 'individual')));
    $active_durations = array_map('intval', explode(',', $available_session_durations ?: '60'));
    $services = fetch_appointment_services($mysqli);
    foreach ($services as $service) {
        $service_allowed = in_array($service['service_key'], $active_service_types, true);
        $service_active = $service_allowed ? 1 : 0;
        $stmt = $mysqli->prepare("UPDATE appointment_services SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $service_active, $service['id']);
        $stmt->execute();

        foreach ($service['options'] as $option) {
            $is_active = $service_allowed
                && in_array((int) $option['duration_minutes'], $active_durations, true)
                && ($appointment_delivery_mode === 'both' || $option['consultation_type'] === $appointment_delivery_mode)
                ? 1
                : 0;
            $stmt = $mysqli->prepare("UPDATE appointment_service_options SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $is_active, $option['id']);
            $stmt->execute();
        }
    }
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
    $upload_dir = dirname(__DIR__) . '/uploads/settings';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        throw new \Exception('No se pudo crear la carpeta de imágenes.');
    }

    $filename = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$image_info['mime']];
    $destination = $upload_dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new \Exception('No se pudo guardar la imagen.');
    }

    return 'uploads/settings/' . $filename;
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
    $upload_dir = dirname(__DIR__) . '/uploads/professionals';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        throw new \Exception('No se pudo crear la carpeta de fotos de profesionales.');
    }

    $filename = 'professional_' . (int) $professional_id . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$image_info['mime']];
    $destination = $upload_dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new \Exception('No se pudo guardar la foto del profesional.');
    }

    return 'uploads/professionals/' . $filename;
}

if ($action === 'generate_invite') {
    $token = bin2hex(random_bytes(32));
    $invite_user_id = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    if ($invite_user_id > 0) {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE id = ? AND role = 'patient'");
        $stmt->bind_param("i", $invite_user_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'Paciente no encontrado.']);
            exit;
        }
    } else {
        $invite_user_id = null;
    }

    $stmt = $mysqli->prepare("INSERT INTO invitations (token, user_id) VALUES (?, ?)");
    $stmt->bind_param("si", $token, $invite_user_id);
    if ($stmt->execute()) {
        // Obtenemos el protocolo y el dominio actual para crear el enlace completo
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $domainName = $_SERVER['HTTP_HOST'];
        $path = dirname(dirname($_SERVER['REQUEST_URI']));

        // Remove trailing slash if exists
        $path = rtrim($path, '/');

        $link = $protocol . $domainName . $path . '/register.php?token=' . $token;
        $link = urlme_shorten_url($link, 'Invitacion registro PsicoLogic');
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
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.is_primary = 1
        LEFT JOIN professionals p ON p.id = COALESCE(ppf.professional_id, pp.professional_id)
        LEFT JOIN users pu ON pu.id = p.user_id
        WHERE u.role = 'patient'
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
               pp.patient_type, pp.patient_status, pp.birth_date, pp.referral_source, pp.initial_consultation_reason,
               pp.emergency_contact_name, pp.emergency_contact_phone, pp.emergency_contact_relation,
               pp.admission_date, pp.notes, pp.photo_path, pp.document_path, pp.document_name, pp.created_by_admin,
               COALESCE(ppf.professional_id, pp.professional_id) AS professional_id,
               p.display_name AS professional_name, p.public_photo_path AS professional_photo_path,
               pu.role AS professional_user_role
        FROM users u
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.is_primary = 1
        LEFT JOIN professionals p ON p.id = COALESCE(ppf.professional_id, pp.professional_id)
        LEFT JOIN users pu ON pu.id = p.user_id
        WHERE u.role = 'patient'
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
            'patient_status' => $row['patient_status'] ?? 'active',
            'birth_date' => $row['birth_date'] ?? '',
            'referral_source' => $row['referral_source'] ?? '',
            'initial_consultation_reason' => $row['initial_consultation_reason'] ?? '',
            'emergency_contact_name' => $row['emergency_contact_name'] ?? '',
            'emergency_contact_phone' => $row['emergency_contact_phone'] ?? '',
            'emergency_contact_relation' => $row['emergency_contact_relation'] ?? '',
            'admission_date' => $row['admission_date'] ?? substr((string) $row['created_at'], 0, 10),
            'notes' => $row['notes'] ?? '',
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
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.is_primary = 1
        LEFT JOIN professionals p ON p.id = COALESCE(ppf.professional_id, pp.professional_id)
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
        WHERE u.role = 'patient'
          AND (u.name LIKE ? ESCAPE '\\\\' OR u.email LIKE ? ESCAPE '\\\\' OR u.phone LIKE ? ESCAPE '\\\\' OR pp.patient_type LIKE ? ESCAPE '\\\\')
          $professional_where
        ORDER BY u.name ASC
        LIMIT 12
    ";
    $stmt = $mysqli->prepare($sql);
    if ($is_superadmin) {
        $stmt->bind_param("ssss", $like, $like, $like, $like);
    } else {
        $stmt->bind_param("ssssi", $like, $like, $like, $like, $professional_id);
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
            LEFT JOIN users u ON u.id = p.user_id
            WHERE p.display_name LIKE ? ESCAPE '\\\\'
               OR p.public_email LIKE ? ESCAPE '\\\\'
               OR p.public_phone LIKE ? ESCAPE '\\\\'
               OR u.email LIKE ? ESCAPE '\\\\'
            ORDER BY p.display_name ASC
            LIMIT 8
        ");
        $stmt->bind_param("ssss", $like, $like, $like, $like);
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
        JOIN users u ON u.id = a.user_id
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
        LEFT JOIN appointment_services s ON s.id = so.service_id
        LEFT JOIN professionals p ON p.id = a.professional_id
        WHERE (u.name LIKE ? ESCAPE '\\\\' OR u.email LIKE ? ESCAPE '\\\\' OR u.phone LIKE ? ESCAPE '\\\\'
               OR p.display_name LIKE ? ESCAPE '\\\\' OR s.name LIKE ? ESCAPE '\\\\'
               OR a.appointment_date LIKE ? ESCAPE '\\\\')
          $appointment_professional_where
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
        LIMIT 12
    ";
    $stmt = $mysqli->prepare($sql);
    if ($is_superadmin) {
        $stmt->bind_param("ssssss", $like, $like, $like, $like, $like, $like);
    } else {
        $stmt->bind_param("ssssssi", $like, $like, $like, $like, $like, $like, $professional_id);
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
        JOIN patient_evolution_notes n ON n.id = f.evolution_note_id
        JOIN users u ON u.id = f.patient_id
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.is_primary = 1
        WHERE (f.original_name LIKE ? ESCAPE '\\\\' OR n.title LIKE ? ESCAPE '\\\\' OR u.name LIKE ? ESCAPE '\\\\')
          $file_professional_where
        ORDER BY f.uploaded_at DESC, f.id DESC
        LIMIT 12
    ";
    $stmt = $mysqli->prepare($sql);
    if ($is_superadmin) {
        $stmt->bind_param("sss", $like, $like, $like);
    } else {
        $stmt->bind_param("sssi", $like, $like, $like, $professional_id);
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
        JOIN users u ON u.id = t.patient_id
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.is_primary = 1
        WHERE (t.title LIKE ? ESCAPE '\\\\' OR t.description LIKE ? ESCAPE '\\\\' OR u.name LIKE ? ESCAPE '\\\\')
          $task_professional_where
        ORDER BY t.updated_at DESC, t.id DESC
        LIMIT 12
    ";
    $stmt = $mysqli->prepare($sql);
    if ($is_superadmin) {
        $stmt->bind_param("sss", $like, $like, $like);
    } else {
        $stmt->bind_param("sssi", $like, $like, $like, $professional_id);
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
    echo json_encode([
        'success' => true,
        'appointment' => [
            'id' => (int) $appointment['id'],
            'patient_id' => (int) $appointment['user_id'],
            'patient_name' => $appointment['patient_name'] ?? '',
            'patient_email' => $appointment['patient_email'] ?? '',
            'patient_phone' => $appointment['patient_phone'] ?? '',
            'professional_id' => (int) ($appointment['professional_id'] ?? 0),
            'professional_name' => $appointment['professional_name'] ?? '',
            'appointment_date' => $appointment['appointment_date'],
            'appointment_time' => substr((string) $appointment['appointment_time'], 0, 5),
            'status' => $appointment['status'] ?? '',
            'consultation_type' => $appointment['consultation_type'] ?? 'presencial',
            'online_session_url' => $appointment['online_session_url'] ?? '',
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
            'is_bonus_payment' => ($method === 'bonus' || !empty($appointment['patient_bonus_id'])) ? 1 : 0
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
        WHERE patient_id = ?
        ORDER BY
            CASE WHEN status = 'pending' THEN 0 ELSE 1 END,
            priority ASC,
            updated_at DESC,
            id DESC
    ");
    $stmt->bind_param("i", $patient_id);
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
        LEFT JOIN patient_evolution_files f ON f.evolution_note_id = n.id
        WHERE n.patient_id = ? AND n.appointment_id = ?
        GROUP BY n.id
        ORDER BY n.note_date DESC, n.id DESC
    ");
    $stmt->bind_param("ii", $patient_id, $appointment_id);
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
        JOIN patient_evolution_notes n ON n.id = f.evolution_note_id
        WHERE f.patient_id = ? AND n.appointment_id = ?
        ORDER BY f.uploaded_at DESC, f.id DESC
    ");
    $stmt->bind_param("ii", $patient_id, $appointment_id);
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
            WHERE id = ?
        ");
        $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
        $stmt->bind_param("sii", $payment_method, $session_user_id, $appointment_id);
    } else {
        $stmt = $mysqli->prepare("
            UPDATE appointments
            SET payment_status = 'pending',
                payment_method = NULL,
                paid_at = NULL,
                payment_updated_at = NOW(),
                payment_updated_by = ?
            WHERE id = ?
        ");
        $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
        $stmt->bind_param("ii", $session_user_id, $appointment_id);
    }

    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'No se pudo actualizar el pago.']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Pago actualizado correctamente.']);
} elseif ($action === 'update_appointment_online_details') {
    ensure_appointment_payment_columns($mysqli);
    ensure_appointment_services_tables($mysqli);
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    $consultation_type = $_POST['consultation_type'] ?? 'presencial';
    $online_session_url = trim($_POST['online_session_url'] ?? '');
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
    if ($consultation_type === 'online' && !in_array($professional_delivery_mode, ['both', 'online'], true)) {
        echo json_encode(['success' => false, 'error' => 'Este profesional no admite citas online.']);
        exit;
    }
    if ($consultation_type === 'presencial' && !in_array($professional_delivery_mode, ['both', 'presencial'], true)) {
        echo json_encode(['success' => false, 'error' => 'Este profesional no admite citas presenciales.']);
        exit;
    }
    if ($consultation_type !== 'online') {
        $online_session_url = '';
    } elseif ($online_session_url !== '' && !filter_var($online_session_url, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'error' => 'Indica un enlace valido para la videollamada.']);
        exit;
    }

    $stmt = $mysqli->prepare("UPDATE appointments SET consultation_type = ?, online_session_url = ? WHERE id = ?");
    $stmt->bind_param("ssi", $consultation_type, $online_session_url, $appointment_id);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'No se pudo guardar la modalidad de la cita.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Modalidad de la cita actualizada.',
        'consultation_type' => $consultation_type,
        'online_session_url' => $online_session_url
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
    $online_session_url = trim($appointment['online_session_url'] ?? '');
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
} elseif ($action === 'download_patient_document') {
    $patient_id = (int) ($_GET['patient_id'] ?? 0);
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        http_response_code(403);
        echo 'No autorizado';
        exit;
    }
    $stmt = $mysqli->prepare("SELECT document_path, document_name FROM patient_profiles WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $patient_id);
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
        WHERE f.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $file_id);
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
    $stmt = $mysqli->prepare("SELECT id, patient_id FROM patient_evolution_notes WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $note_id);
    $stmt->execute();
    $note = $stmt->get_result()->fetch_assoc();
    if (!$note || !admin_can_access_patient($mysqli, (int) $note['patient_id'])) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para eliminar esta nota.']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT id, file_path FROM patient_evolution_files WHERE evolution_note_id = ?");
    $stmt->bind_param("i", $note_id);
    $stmt->execute();
    $files_res = $stmt->get_result();
    $file_paths = [];
    while ($file = $files_res->fetch_assoc()) {
        $file_paths[] = $file['file_path'] ?? '';
    }

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("DELETE FROM patient_evolution_files WHERE evolution_note_id = ?");
        $stmt->bind_param("i", $note_id);
        $stmt->execute();

        $stmt = $mysqli->prepare("DELETE FROM patient_evolution_notes WHERE id = ?");
        $stmt->bind_param("i", $note_id);
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
} elseif ($action === 'patient_report') {
    $patient_id = (int) ($_GET['patient_id'] ?? 0);
    $report_type = ($_GET['type'] ?? 'internal') === 'patient' ? 'patient' : 'internal';
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        http_response_code(403);
        header_remove('Content-Type');
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'No autorizado';
        exit;
    }

    $stmt = $mysqli->prepare("
        SELECT u.id, u.name, u.email, u.phone, u.created_at,
               pp.patient_type, pp.patient_status, pp.birth_date, pp.referral_source, pp.initial_consultation_reason,
               pp.emergency_contact_name, pp.emergency_contact_phone, pp.emergency_contact_relation,
               pp.admission_date, pp.notes, pp.document_name,
               p.display_name AS professional_name, p.professional_title, p.license_number
        FROM users u
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id
        LEFT JOIN professionals p ON p.id = COALESCE(pp.professional_id, (
            SELECT professional_id
            FROM patient_professionals
            WHERE patient_id = u.id AND is_primary = 1
            ORDER BY assigned_at DESC, id DESC
            LIMIT 1
        ))
        WHERE u.id = ? AND u.role = 'patient'
        LIMIT 1
    ");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    if (!$patient) {
        http_response_code(404);
        header_remove('Content-Type');
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Paciente no encontrado';
        exit;
    }

    $stmt = $mysqli->prepare("
        SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.cancelled_at,
               a.consultation_type, a.service_type,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.paid_at,
               s.name AS service_name,
               p.display_name AS professional_name
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
        LEFT JOIN appointment_services s ON s.id = so.service_id
        LEFT JOIN professionals p ON p.id = a.professional_id
        WHERE a.user_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC, a.id DESC
        LIMIT 120
    ");
    $stmt->bind_param("i", $patient_id);
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
        LEFT JOIN professionals p ON p.id = t.professional_id
        WHERE t.patient_id = ?
        ORDER BY CASE WHEN t.status = 'pending' THEN 0 ELSE 1 END, t.priority ASC, t.updated_at DESC
    ");
    $stmt->bind_param("i", $patient_id);
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
        LEFT JOIN appointments a ON a.id = n.appointment_id
        LEFT JOIN professionals p ON p.id = n.professional_id
        WHERE n.patient_id = ?
        ORDER BY n.note_date DESC, n.id DESC
        LIMIT 120
    ");
    $stmt->bind_param("i", $patient_id);
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
        WHERE patient_id = ?
        ORDER BY uploaded_at DESC, id DESC
    ");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $files_res = $stmt->get_result();
    while ($file = $files_res->fetch_assoc()) {
        $note_id = (int) $file['evolution_note_id'];
        if (!isset($evolution_files_by_note[$note_id])) {
            $evolution_files_by_note[$note_id] = [];
        }
        $evolution_files_by_note[$note_id][] = $file;
    }

    $stmt = $mysqli->prepare("
        SELECT pb.total_sessions, pb.remaining_sessions, pb.status, pb.purchased_at, pb.expires_at,
               b.name
        FROM patient_bonuses pb
        JOIN appointment_bonuses b ON b.id = pb.bonus_id
        WHERE pb.user_id = ?
        ORDER BY pb.purchased_at DESC, pb.id DESC
    ");
    $stmt->bind_param("i", $patient_id);
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

    header_remove('Content-Type');
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Informe interno de paciente - <?= report_h($patient['name']) ?></title>
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
        .meta { color: #6b7280; font-size: 13px; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 18px; margin-top: 18px; }
        .field span { display: block; color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
        .field strong { display: block; margin-top: 3px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 13px; }
        th, td { border-bottom: 1px solid #e5e9f0; text-align: left; vertical-align: top; padding: 8px; }
        th { background: #f8fafc; color: #526071; }
        .note, .task { border: 1px solid #e5e9f0; border-radius: 6px; padding: 12px; margin-bottom: 10px; }
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
        <h1>Informe interno de paciente</h1>
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
            <?php if (!empty($patient['notes'])): ?>
                <h3>Notas internas</h3>
                <div class="preline"><?= nl2br(report_h($patient['notes'])) ?></div>
            <?php endif; ?>
        </section>

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

    $branding = get_public_branding_settings($mysqli);
    $dashboard_photo = $branding['profile_image_path'] ?? '';
    $stmt = $mysqli->prepare("
        SELECT n.id, n.patient_id, n.appointment_id, n.professional_id, n.note_date, n.title,
               n.description, n.observations, n.next_steps, n.created_at, n.updated_at,
               a.appointment_date, a.appointment_time,
               p.display_name AS professional_name, p.public_photo_path AS professional_photo_path,
               pu.role AS professional_user_role,
               COUNT(f.id) AS file_count
        FROM patient_evolution_notes n
        LEFT JOIN appointments a ON a.id = n.appointment_id
        LEFT JOIN professionals p ON p.id = n.professional_id
        LEFT JOIN users pu ON pu.id = p.user_id
        LEFT JOIN patient_evolution_files f ON f.evolution_note_id = n.id
        WHERE n.patient_id = ?
        GROUP BY n.id
        ORDER BY n.note_date DESC, n.id DESC
    ");
    $stmt->bind_param("i", $patient_id);
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
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
        LEFT JOIN appointment_services s ON s.id = so.service_id
        WHERE a.user_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmt->bind_param("i", $patient_id);
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
        $stmt = $mysqli->prepare("SELECT professional_id, appointment_date FROM appointments WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param("ii", $appointment_id, $patient_id);
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
            $stmt = $mysqli->prepare("SELECT patient_id FROM patient_evolution_notes WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $note_id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            if (!$existing || (int) $existing['patient_id'] !== $patient_id) {
                throw new \Exception('No se encontro el registro de evolucion.');
            }
            $stmt = $mysqli->prepare("
                UPDATE patient_evolution_notes
                SET appointment_id = ?, professional_id = ?, note_date = ?, title = ?, description = ?, observations = ?, next_steps = ?
                WHERE id = ? AND patient_id = ?
            ");
            $stmt->bind_param("iisssssii", $appointment_id_db, $professional_id, $note_date, $title, $description, $observations, $next_steps, $note_id, $patient_id);
            $stmt->execute();
        } else {
            $stmt = $mysqli->prepare("
                INSERT INTO patient_evolution_notes (patient_id, appointment_id, professional_id, note_date, title, description, observations, next_steps, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iiisssssi", $patient_id, $appointment_id_db, $professional_id, $note_date, $title, $description, $observations, $next_steps, $created_by);
            $stmt->execute();
            $note_id = $mysqli->insert_id;
        }
        $saved_files = save_patient_evolution_uploads($mysqli, $_FILES['evolution_files'] ?? null, $note_id, $patient_id);
        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => 'Evolucion guardada correctamente.', 'note_id' => $note_id, 'files_saved' => $saved_files]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'patient_files') {
    $patient_id = (int) ($_GET['patient_id'] ?? 0);
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para ver archivos.']);
        exit;
    }
    $files = [];
    $stmt = $mysqli->prepare("SELECT document_path, document_name, updated_at FROM patient_profiles WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    if ($profile = $stmt->get_result()->fetch_assoc()) {
        if (!empty($profile['document_path'])) {
            $files[] = [
                'type' => 'patient_document',
                'id' => 0,
                'name' => $profile['document_name'] ?: 'Documento del paciente',
                'source' => 'Ficha del paciente',
                'date' => $profile['updated_at'] ?? '',
                'url' => 'api/admin.php?action=download_patient_document&patient_id=' . $patient_id
            ];
        }
    }
    $stmt = $mysqli->prepare("
        SELECT f.id, f.original_name, f.file_size, f.uploaded_at, n.title
        FROM patient_evolution_files f
        JOIN patient_evolution_notes n ON n.id = f.evolution_note_id
        WHERE f.patient_id = ?
        ORDER BY f.uploaded_at DESC, f.id DESC
    ");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $files[] = [
            'type' => 'evolution_file',
            'id' => (int) $row['id'],
            'name' => $row['original_name'],
            'source' => $row['title'] ?: 'Evolucion',
            'date' => $row['uploaded_at'],
            'size' => (int) ($row['file_size'] ?? 0),
            'url' => 'api/admin.php?action=download_evolution_file&id=' . (int) $row['id']
        ];
    }
    usort($files, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
    echo json_encode(['success' => true, 'files' => $files]);
} elseif ($action === 'patient_work_plan') {
    $patient_id = (int) ($_GET['patient_id'] ?? 0);
    if (!admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para ver el plan de trabajo.']);
        exit;
    }

    $stmt = $mysqli->prepare("
        SELECT t.id, t.patient_id, t.appointment_id, t.professional_id, t.title, t.description, t.status, t.priority, t.visible_to_patient,
               t.completed_at, t.created_at, t.updated_at,
               p.display_name AS professional_name
        FROM patient_work_plan_tasks t
        LEFT JOIN professionals p ON p.id = t.professional_id
        WHERE t.patient_id = ?
        ORDER BY
            CASE WHEN t.status = 'pending' THEN 0 ELSE 1 END,
            t.priority ASC,
            t.updated_at DESC,
            t.id DESC
    ");
    $stmt->bind_param("i", $patient_id);
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
            'completed_at' => $row['completed_at'] ?? '',
            'created_at' => $row['created_at'] ?? '',
            'updated_at' => $row['updated_at'] ?? ''
        ];
    }
    echo json_encode(['success' => true, 'tasks' => $tasks]);
} elseif ($action === 'save_patient_work_plan_task') {
    $task_id = (int) ($_POST['task_id'] ?? 0);
    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $appointment_was_posted = array_key_exists('appointment_id', $_POST);
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = (int) ($_POST['priority'] ?? 2);
    $status = ($_POST['status'] ?? '') === 'completed' ? 'completed' : 'pending';
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
        $stmt = $mysqli->prepare("SELECT patient_id, appointment_id, professional_id, status FROM patient_work_plan_tasks WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $task_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        if (!$existing || (int) $existing['patient_id'] !== $patient_id) {
            echo json_encode(['success' => false, 'error' => 'No se encontro la tarea.']);
            exit;
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
                WHERE id = ? AND patient_id = ?
            ");
            $stmt->bind_param("issiisii", $appointment_id_db, $title, $description, $priority, $visible_to_patient, $status, $task_id, $patient_id);
        } else {
            $stmt = $mysqli->prepare("
                UPDATE patient_work_plan_tasks
                SET appointment_id = ?, professional_id = ?, title = ?, description = ?, priority = ?, visible_to_patient = ?, status = ?,
                    completed_at = ?, completed_by = ?
                WHERE id = ? AND patient_id = ?
            ");
            $stmt->bind_param("iissiissiii", $appointment_id_db, $professional_id, $title, $description, $priority, $visible_to_patient, $status, $completed_at, $completed_by, $task_id, $patient_id);
        }
        $stmt->execute();
    } else {
        if ($professional_id <= 0) {
            $professional_id = null;
        }
        $stmt = $mysqli->prepare("
            INSERT INTO patient_work_plan_tasks (patient_id, appointment_id, professional_id, title, description, status, priority, visible_to_patient, created_by, completed_at, completed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iiisssiiisi", $patient_id, $appointment_id_db, $professional_id, $title, $description, $status, $priority, $visible_to_patient, $session_user_id, $completed_at, $completed_by);
        $stmt->execute();
        $task_id = $mysqli->insert_id;
    }
    echo json_encode(['success' => true, 'message' => 'Plan de trabajo guardado correctamente.', 'task_id' => $task_id]);
} elseif ($action === 'set_patient_work_plan_task_status') {
    $task_id = (int) ($_POST['task_id'] ?? 0);
    $status = ($_POST['status'] ?? '') === 'completed' ? 'completed' : 'pending';
    $stmt = $mysqli->prepare("SELECT patient_id FROM patient_work_plan_tasks WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    $task = $stmt->get_result()->fetch_assoc();
    if (!$task || !admin_can_access_patient($mysqli, (int) $task['patient_id'])) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para actualizar esta tarea.']);
        exit;
    }

    if ($status === 'completed') {
        $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
        $stmt = $mysqli->prepare("UPDATE patient_work_plan_tasks SET status = 'completed', completed_at = NOW(), completed_by = ? WHERE id = ?");
        $stmt->bind_param("ii", $session_user_id, $task_id);
    } else {
        $stmt = $mysqli->prepare("UPDATE patient_work_plan_tasks SET status = 'pending', completed_at = NULL, completed_by = NULL WHERE id = ?");
        $stmt->bind_param("i", $task_id);
    }
    $stmt->execute();
    echo json_encode(['success' => true, 'message' => $status === 'completed' ? 'Tarea completada.' : 'Tarea marcada como pendiente.']);
} elseif ($action === 'delete_patient_work_plan_task') {
    $task_id = (int) ($_POST['task_id'] ?? 0);
    $stmt = $mysqli->prepare("SELECT patient_id FROM patient_work_plan_tasks WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    $task = $stmt->get_result()->fetch_assoc();
    if (!$task || !admin_can_access_patient($mysqli, (int) $task['patient_id'])) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para eliminar esta tarea.']);
        exit;
    }
    $stmt = $mysqli->prepare("DELETE FROM patient_work_plan_tasks WHERE id = ?");
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    echo json_encode(['success' => true, 'message' => 'Tarea eliminada correctamente.']);
} elseif ($action === 'work_plan_task_templates') {
    ensure_work_plan_task_template_tables($mysqli);
    $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
    $current_professional_id = current_professional_id_for_user($mysqli, $session_user_id);
    if ($is_superadmin) {
        $stmt = $mysqli->prepare("
            SELECT t.id, t.professional_id, t.category, t.title, t.description, t.priority, t.is_global, t.is_active,
                   t.created_at, t.updated_at, p.display_name AS professional_name,
                   (SELECT COUNT(*) FROM work_plan_task_template_items i WHERE i.template_id = t.id) AS item_count
            FROM work_plan_task_templates t
            LEFT JOIN professionals p ON p.id = t.professional_id
            ORDER BY COALESCE(NULLIF(t.category, ''), 'Sin categoria') ASC, t.title ASC
        ");
    } else {
        $stmt = $mysqli->prepare("
            SELECT t.id, t.professional_id, t.category, t.title, t.description, t.priority, t.is_global, t.is_active,
                   t.created_at, t.updated_at, p.display_name AS professional_name,
                   (SELECT COUNT(*) FROM work_plan_task_template_items i WHERE i.template_id = t.id) AS item_count
            FROM work_plan_task_templates t
            LEFT JOIN professionals p ON p.id = t.professional_id
            WHERE t.is_active = 1
              AND (t.is_global = 1 OR t.professional_id = ?)
            ORDER BY COALESCE(NULLIF(t.category, ''), 'Sin categoria') ASC, t.title ASC
        ");
        $stmt->bind_param("i", $current_professional_id);
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
            SELECT id, template_id, title, description, priority, sort_order
            FROM work_plan_task_template_items
            WHERE template_id IN ($ids_sql)
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
        $stmt = $mysqli->prepare("SELECT professional_id, is_global FROM work_plan_task_templates WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $template_id);
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
                WHERE id = ?
            ");
            $stmt->bind_param("sssii", $category, $title, $description, $priority, $template_id);
        } else {
            $stmt = $mysqli->prepare("
                UPDATE work_plan_task_templates
                SET professional_id = ?, category = ?, title = ?, description = ?, priority = ?, is_global = 0, is_active = 1
                WHERE id = ?
            ");
            $stmt->bind_param("isssii", $professional_id, $category, $title, $description, $priority, $template_id);
        }
        $stmt->execute();
    } else {
        if ($is_global) {
            $stmt = $mysqli->prepare("
                INSERT INTO work_plan_task_templates (professional_id, category, title, description, priority, is_global, is_active, created_by)
                VALUES (NULL, ?, ?, ?, ?, 1, 1, ?)
            ");
            $stmt->bind_param("sssii", $category, $title, $description, $priority, $session_user_id);
        } else {
            $stmt = $mysqli->prepare("
                INSERT INTO work_plan_task_templates (professional_id, category, title, description, priority, is_global, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, 0, 1, ?)
            ");
            $stmt->bind_param("isssii", $professional_id, $category, $title, $description, $priority, $session_user_id);
        }
        $stmt->execute();
        $template_id = $mysqli->insert_id;
    }
    echo json_encode(['success' => true, 'message' => 'Plantilla guardada correctamente.', 'template_id' => $template_id]);
} elseif ($action === 'save_work_plan_task_template_item') {
    ensure_work_plan_task_template_tables($mysqli);
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $template_id = (int) ($_POST['template_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = (int) ($_POST['priority'] ?? 2);
    $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
    $current_professional_id = current_professional_id_for_user($mysqli, $session_user_id);

    if ($title === '') {
        echo json_encode(['success' => false, 'error' => 'Indica un titulo para la tarea.']);
        exit;
    }
    if (!in_array($priority, [1, 2, 3], true)) {
        $priority = 2;
    }

    $stmt = $mysqli->prepare("SELECT professional_id FROM work_plan_task_templates WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $template_id);
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
        $stmt = $mysqli->prepare("UPDATE work_plan_task_template_items SET title = ?, description = ?, priority = ? WHERE id = ? AND template_id = ?");
        $stmt->bind_param("ssiii", $title, $description, $priority, $item_id, $template_id);
        $stmt->execute();
    } else {
        $sort_order = 0;
        $stmt = $mysqli->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 AS next_sort FROM work_plan_task_template_items WHERE template_id = ?");
        $stmt->bind_param("i", $template_id);
        $stmt->execute();
        $sort_row = $stmt->get_result()->fetch_assoc();
        $sort_order = (int) ($sort_row['next_sort'] ?? 10);
        $stmt = $mysqli->prepare("INSERT INTO work_plan_task_template_items (template_id, title, description, priority, sort_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issii", $template_id, $title, $description, $priority, $sort_order);
        $stmt->execute();
        $item_id = $mysqli->insert_id;
    }
    echo json_encode(['success' => true, 'message' => 'Tarea de plantilla guardada correctamente.', 'item_id' => $item_id]);
} elseif ($action === 'delete_work_plan_task_template_item') {
    ensure_work_plan_task_template_tables($mysqli);
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
    $current_professional_id = current_professional_id_for_user($mysqli, $session_user_id);
    $stmt = $mysqli->prepare("
        SELECT i.template_id, t.professional_id
        FROM work_plan_task_template_items i
        INNER JOIN work_plan_task_templates t ON t.id = i.template_id
        WHERE i.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $item_id);
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
    $stmt = $mysqli->prepare("DELETE FROM work_plan_task_template_items WHERE id = ?");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    echo json_encode(['success' => true, 'message' => 'Tarea de plantilla eliminada correctamente.']);
} elseif ($action === 'import_work_plan_task_template') {
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
    $stmt = $mysqli->prepare("SELECT professional_id, is_global FROM work_plan_task_templates WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param("i", $template_id);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    if (!$template || (!$is_superadmin && (int) ($template['is_global'] ?? 0) !== 1 && (int) ($template['professional_id'] ?? 0) !== (int) $current_professional_id)) {
        echo json_encode(['success' => false, 'error' => 'No autorizado para usar esta plantilla.']);
        exit;
    }
    $appointment_id_db = null;
    $professional_id = $current_professional_id > 0 ? $current_professional_id : null;
    $visible_to_patient_default = 0;
    $settings_res = $mysqli->query("SELECT patient_tasks_visible_default FROM payment_settings WHERE id = 1");
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
    $stmt = $mysqli->prepare("SELECT title, description, priority FROM work_plan_task_template_items WHERE template_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->bind_param("i", $template_id);
    $stmt->execute();
    $items_res = $stmt->get_result();
    $insert = $mysqli->prepare("
        INSERT INTO patient_work_plan_tasks (patient_id, appointment_id, professional_id, title, description, status, priority, visible_to_patient, created_by)
        VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?)
    ");
    $inserted = 0;
    while ($item = $items_res->fetch_assoc()) {
        $title = $item['title'] ?? '';
        if ($title === '') {
            continue;
        }
        $description = $item['description'] ?? '';
        $priority = (int) ($item['priority'] ?? 2);
        $insert->bind_param("iiissiii", $patient_id, $appointment_id_db, $professional_id, $title, $description, $priority, $visible_to_patient_default, $session_user_id);
        $insert->execute();
        $inserted++;
    }
    if ($inserted === 0) {
        echo json_encode(['success' => false, 'error' => 'La plantilla no tiene tareas para importar.']);
        exit;
    }
    echo json_encode(['success' => true, 'message' => "Se han importado $inserted tareas.", 'inserted' => $inserted]);
} elseif ($action === 'delete_work_plan_task_template') {
    ensure_work_plan_task_template_tables($mysqli);
    $template_id = (int) ($_POST['template_id'] ?? 0);
    $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
    $current_professional_id = current_professional_id_for_user($mysqli, $session_user_id);
    $stmt = $mysqli->prepare("SELECT professional_id FROM work_plan_task_templates WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $template_id);
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
    $stmt = $mysqli->prepare("DELETE FROM work_plan_task_template_items WHERE template_id = ?");
    $stmt->bind_param("i", $template_id);
    $stmt->execute();
    $stmt = $mysqli->prepare("DELETE FROM work_plan_task_templates WHERE id = ?");
    $stmt->bind_param("i", $template_id);
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
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
        LEFT JOIN appointment_services s ON s.id = so.service_id
        LEFT JOIN professionals p ON p.id = a.professional_id
        LEFT JOIN users pu ON pu.id = p.user_id
        WHERE a.user_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC, a.id DESC
    ");
    $stmt->bind_param("i", $patient_id);
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
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $patient_type = trim($_POST['patient_type'] ?? '');
    $patient_status = trim($_POST['patient_status'] ?? 'active');
    $birth_date = trim($_POST['birth_date'] ?? '');
    $referral_source = trim($_POST['referral_source'] ?? '');
    $initial_consultation_reason = trim($_POST['initial_consultation_reason'] ?? '');
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');
    $emergency_contact_relation = trim($_POST['emergency_contact_relation'] ?? '');
    $admission_date = trim($_POST['admission_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
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
    if (!in_array($patient_status, ['active', 'paused', 'discharged', 'inactive'], true)) {
        $patient_status = 'active';
    }
    $birth_date = $birth_date !== '' ? $birth_date : null;
    $referral_source = $referral_source !== '' ? $referral_source : null;
    $initial_consultation_reason = $initial_consultation_reason !== '' ? $initial_consultation_reason : null;
    $emergency_contact_name = $emergency_contact_name !== '' ? $emergency_contact_name : null;
    $emergency_contact_phone = $emergency_contact_phone !== '' ? $emergency_contact_phone : null;
    $emergency_contact_relation = $emergency_contact_relation !== '' ? $emergency_contact_relation : null;
    $admission_date = $admission_date !== '' ? $admission_date : date('Y-m-d');

    if ($name === '') {
        echo json_encode(['success' => false, 'error' => 'Indica el nombre del paciente.']);
        exit;
    }
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Email no valido.']);
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
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
        $stmt->bind_param("si", $email, $patient_id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'Ya existe otro paciente con ese email.']);
            exit;
        }
    }

    if ($phone !== null) {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE phone = ? AND id <> ?");
        $stmt->bind_param("si", $phone, $patient_id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'error' => 'Ya existe otro paciente con ese telefono.']);
            exit;
        }
    }

    $password_setup_users = [];
    $mysqli->begin_transaction();
    $password_setup_users = [];
    try {
        if ($patient_id > 0) {
            $stmt = $mysqli->prepare("UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ? AND role = 'patient'");
            $stmt->bind_param("sssi", $name, $email, $phone, $patient_id);
            $stmt->execute();
            if ($stmt->affected_rows < 0) {
                throw new \Exception('No se pudo actualizar el paciente.');
            }
        } else {
            $stmt = $mysqli->prepare("INSERT INTO users (name, email, phone, password_hash, role) VALUES (?, ?, ?, NULL, 'patient')");
            $stmt->bind_param("sss", $name, $email, $phone);
            $stmt->execute();
            $patient_id = $mysqli->insert_id;
        }

        $uploaded_photo_path = save_patient_photo_upload($_FILES['patient_photo'] ?? null, $patient_id);
        $uploaded_document = save_patient_document_upload($_FILES['patient_document'] ?? null, $patient_id);

        $profile_professional_id = $selected_professional_id > 0 ? $selected_professional_id : null;
        $stmt = $mysqli->prepare("
            INSERT INTO patient_profiles (
                user_id, professional_id, patient_type, patient_status, birth_date, referral_source,
                initial_consultation_reason, emergency_contact_name, emergency_contact_phone, emergency_contact_relation,
                admission_date, notes, created_by_admin
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                professional_id = VALUES(professional_id),
                patient_type = VALUES(patient_type),
                patient_status = VALUES(patient_status),
                birth_date = VALUES(birth_date),
                referral_source = VALUES(referral_source),
                initial_consultation_reason = VALUES(initial_consultation_reason),
                emergency_contact_name = VALUES(emergency_contact_name),
                emergency_contact_phone = VALUES(emergency_contact_phone),
                emergency_contact_relation = VALUES(emergency_contact_relation),
                admission_date = VALUES(admission_date),
                notes = VALUES(notes)
        ");
        $stmt->bind_param(
            "iissssssssss",
            $patient_id,
            $profile_professional_id,
            $patient_type,
            $patient_status,
            $birth_date,
            $referral_source,
            $initial_consultation_reason,
            $emergency_contact_name,
            $emergency_contact_phone,
            $emergency_contact_relation,
            $admission_date,
            $notes
        );
        $stmt->execute();
        if ($selected_professional_id > 0) {
            $stmt = $mysqli->prepare("UPDATE patient_professionals SET is_primary = 0 WHERE patient_id = ?");
            $stmt->bind_param("i", $patient_id);
            $stmt->execute();
            $stmt = $mysqli->prepare("
                INSERT INTO patient_professionals (patient_id, professional_id, is_primary, notes)
                VALUES (?, ?, 1, 'Asignación desde ficha')
                ON DUPLICATE KEY UPDATE is_primary = 1
            ");
            $stmt->bind_param("ii", $patient_id, $selected_professional_id);
            $stmt->execute();
        } elseif ($is_superadmin && $professional_was_posted) {
            $stmt = $mysqli->prepare("DELETE FROM patient_professionals WHERE patient_id = ? AND is_primary = 1");
            $stmt->bind_param("i", $patient_id);
            $stmt->execute();
        }

        if ($uploaded_document !== null) {
            $stmt = $mysqli->prepare("UPDATE patient_profiles SET document_path = ?, document_name = ? WHERE user_id = ?");
            $stmt->bind_param("ssi", $uploaded_document['path'], $uploaded_document['name'], $patient_id);
            $stmt->execute();
        }
        if ($uploaded_photo_path !== null) {
            $stmt = $mysqli->prepare("UPDATE patient_profiles SET photo_path = ? WHERE user_id = ?");
            $stmt->bind_param("si", $uploaded_photo_path, $patient_id);
            $stmt->execute();
        }
        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => 'Paciente guardado correctamente.', 'patient_id' => $patient_id]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'transfer_patient_professional') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede traspasar pacientes.']);
        exit;
    }
    ensure_cabinet_schema($mysqli);
    $settings_res = $mysqli->query("SELECT allow_patient_transfer FROM payment_settings WHERE id = 1");
    $settings = $settings_res ? $settings_res->fetch_assoc() : ['allow_patient_transfer' => 0];

    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $target_professional_id = (int) ($_POST['professional_id'] ?? 0);
    if ($patient_id <= 0 || $target_professional_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Selecciona paciente y profesional.']);
        exit;
    }
    $stmt = $mysqli->prepare("SELECT id FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'error' => 'Paciente no encontrado.']);
        exit;
    }
    $stmt = $mysqli->prepare("SELECT id, display_name FROM professionals WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param("i", $target_professional_id);
    $stmt->execute();
    $target_professional = $stmt->get_result()->fetch_assoc();
    if (!$target_professional) {
        echo json_encode(['success' => false, 'error' => 'Profesional no valido o inactivo.']);
        exit;
    }
    $current_professional_id = cabinet_patient_primary_professional_id($mysqli, $patient_id);
    if ($current_professional_id > 0 && (int) ($settings['allow_patient_transfer'] ?? 0) !== 1) {
        echo json_encode(['success' => false, 'error' => 'El traspaso de pacientes no esta activado en configuracion.']);
        exit;
    }
    if ($current_professional_id === $target_professional_id) {
        echo json_encode(['success' => false, 'error' => 'El paciente ya esta asignado a ese profesional.']);
        exit;
    }

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("UPDATE patient_professionals SET is_primary = 0 WHERE patient_id = ?");
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();

        $notes = $current_professional_id > 0 ? 'Traspaso manual desde ficha' : 'Asignacion manual desde ficha';
        $stmt = $mysqli->prepare("
            INSERT INTO patient_professionals (patient_id, professional_id, is_primary, assigned_at, transferred_at, notes)
            VALUES (?, ?, 1, NOW(), NOW(), ?)
            ON DUPLICATE KEY UPDATE is_primary = 1, transferred_at = NOW(), notes = VALUES(notes)
        ");
        $stmt->bind_param("iis", $patient_id, $target_professional_id, $notes);
        $stmt->execute();

        $stmt = $mysqli->prepare("
            INSERT INTO patient_profiles (user_id, professional_id, created_by_admin)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE professional_id = VALUES(professional_id)
        ");
        $stmt->bind_param("ii", $patient_id, $target_professional_id);
        $stmt->execute();

        $stmt = $mysqli->prepare("
            UPDATE appointments
            SET professional_id = ?
            WHERE user_id = ?
              AND status = 'booked'
              AND CONCAT(appointment_date, ' ', appointment_time) >= NOW()
        ");
        $stmt->bind_param("ii", $target_professional_id, $patient_id);
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
    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $stmt = $mysqli->prepare("SELECT id, name, email, password_hash FROM users WHERE id = ? AND role = 'patient'");
    $stmt->bind_param("i", $patient_id);
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
    $stmt = $mysqli->prepare("INSERT INTO invitations (token, user_id) VALUES (?, ?)");
    $stmt->bind_param("si", $token, $patient_id);
    $stmt->execute();
    $link = urlme_shorten_url(app_public_base_url() . 'register.php?token=' . urlencode($token), 'Invitacion registro PsicoLogic');

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

    $link = urlme_shorten_url(app_public_base_url() . 'register.php?token=' . urlencode($token), 'Invitacion registro PsicoLogic');
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
               a.consultation_type, a.service_type,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.patient_bonus_id,
               s.name AS service_name,
               u.name, u.email, u.phone,
               p.display_name AS professional_name, p.public_photo_path AS professional_photo_path,
               pu.role AS professional_user_role
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
        LEFT JOIN appointment_services s ON s.id = so.service_id
        JOIN users u ON u.id = a.user_id
        LEFT JOIN professionals p ON p.id = a.professional_id
        LEFT JOIN users pu ON pu.id = p.user_id
        WHERE a.status = 'booked'
          $professional_filter
    ";

    $current_res = $mysqli->query("
        $base_select
          AND CONCAT(a.appointment_date, ' ', a.appointment_time) <= NOW()
          AND DATE_ADD(CONCAT(a.appointment_date, ' ', a.appointment_time), INTERVAL COALESCE(a.duration_minutes, so.duration_minutes, 60) MINUTE) > NOW()
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
        LIMIT 1
    ");
    $current = $current_res ? $current_res->fetch_assoc() : null;
    $exclude_current = $current ? " AND a.id <> " . (int) $current['id'] : '';
    $next_res = $mysqli->query("
        $base_select
          AND CONCAT(a.appointment_date, ' ', a.appointment_time) > NOW()
          $exclude_current
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
        LIMIT 1
    ");
    $next = $next_res ? $next_res->fetch_assoc() : null;

    echo json_encode([
        'success' => true,
        'current' => quick_appointment_payload($current, $dashboard_photo),
        'next' => quick_appointment_payload($next, $dashboard_photo),
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
        SELECT a.id, a.appointment_date, a.appointment_time, a.consultation_type, a.service_type,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               s.name AS service_name,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.patient_bonus_id,
               u.name, u.email, u.phone,
               p.id AS professional_id, p.display_name AS professional_name, p.public_photo_path AS professional_photo_path,
               pu.role AS professional_user_role
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
        LEFT JOIN appointment_services s ON s.id = so.service_id
        LEFT JOIN professionals p ON p.id = a.professional_id
        LEFT JOIN users pu ON pu.id = p.user_id
        JOIN users u ON u.id = a.user_id
        WHERE a.status = 'booked'
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
            'service_label' => appointment_service_option_label($row),
            'payment_status' => $row['payment_status'] ?? 'pending',
            'payment_method' => $row['payment_method'],
            'patient_bonus_id' => $row['patient_bonus_id']
        ];
    }

    $cancelled_res = $mysqli->query("
        SELECT a.id, a.appointment_date, a.appointment_time, a.cancelled_at, a.consultation_type, a.service_type,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               s.name AS service_name,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.patient_bonus_id,
               u.name, u.email, u.phone,
               p.id AS professional_id, p.display_name AS professional_name, p.public_photo_path AS professional_photo_path,
               pu.role AS professional_user_role
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
        LEFT JOIN appointment_services s ON s.id = so.service_id
        LEFT JOIN professionals p ON p.id = a.professional_id
        LEFT JOIN users pu ON pu.id = p.user_id
        JOIN users u ON u.id = a.user_id
        WHERE a.status = 'cancelled'
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
            SELECT a.id, a.appointment_date, a.appointment_time, a.consultation_type, a.service_type,
                   COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
                   s.name AS service_name,
                   COALESCE(a.payment_status, 'pending') AS payment_status,
                   a.payment_method, a.patient_bonus_id,
                   u.name, u.email, u.phone
            FROM appointments a
            LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
            LEFT JOIN appointment_services s ON s.id = so.service_id
            JOIN users u ON u.id = a.user_id
            WHERE a.status = 'booked'
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
    ensure_appointment_payment_columns($mysqli);
    ensure_appointment_services_tables($mysqli);
    ensure_bonus_tables($mysqli);
    ensure_payment_attempts_table($mysqli);
    ensure_cabinet_schema($mysqli);
    $branding = get_public_branding_settings($mysqli);
    $dashboard_photo = $branding['profile_image_path'] ?? '';
    $professional_id = admin_requested_professional_filter($mysqli);
    $appointment_filter = $professional_id > 0 ? " AND professional_id = " . (int) $professional_id : ($professional_id < 0 ? " AND 1 = 0" : "");
    $appointment_filter_a = $professional_id > 0 ? " AND a.professional_id = " . (int) $professional_id : ($professional_id < 0 ? " AND 1 = 0" : "");
    $patient_join = "LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.is_primary = 1 LEFT JOIN patient_profiles pp ON pp.user_id = u.id";
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
        WHERE u.role = 'patient'
          $patient_filter
    ");
    if ($row = $res->fetch_assoc()) {
        $stats['patient_count'] = (int) $row['total'];
    }

    $res = $mysqli->query("
        SELECT COALESCE(SUM(amount_cents), 0) AS cents
        FROM payment_attempts
        WHERE status = 'OK'
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
        LEFT JOIN appointment_service_options aso ON aso.id = a.service_option_id
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
        WHERE status = 'active'
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
        JOIN users u ON u.id = a.user_id
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
        LEFT JOIN appointments a ON a.user_id = u.id
        WHERE u.role = 'patient'
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
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
        LEFT JOIN appointment_services s ON s.id = so.service_id
        JOIN users u ON u.id = a.user_id
        LEFT JOIN professionals p ON p.id = a.professional_id
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
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
        LEFT JOIN appointment_services s ON s.id = so.service_id
        JOIN users u ON u.id = a.user_id
        LEFT JOIN professionals p ON p.id = a.professional_id
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
            LEFT JOIN users u ON u.id = p.user_id
            LEFT JOIN patient_professionals ppf ON ppf.professional_id = p.id AND ppf.is_primary = 1
            LEFT JOIN patient_profiles pp ON pp.professional_id = p.id
            LEFT JOIN appointments a ON a.professional_id = p.id
            WHERE p.is_active = 1
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
        LEFT JOIN professionals p ON p.id = $effective_professional_expr
        LEFT JOIN users pu ON pu.id = p.user_id
        WHERE cd.closed_date >= CURDATE()
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
        $stmt = $mysqli->prepare("INSERT INTO closed_days (closed_date, professional_id, reason, is_global) VALUES (?, ?, ?, ?)");
        $current = clone $start;

        while ($current <= $end) {
            $current_date = $current->format('Y-m-d');
            if ($is_global) {
                $exists_stmt = $mysqli->prepare("SELECT id FROM closed_days WHERE closed_date = ? AND is_global = 1 AND reason = ? LIMIT 1");
                $exists_stmt->bind_param("ss", $current_date, $reason);
            } else {
                $exists_stmt = $mysqli->prepare("SELECT id FROM closed_days WHERE closed_date = ? AND is_global = 0 AND professional_id = ? AND reason = ? LIMIT 1");
                $exists_stmt->bind_param("sis", $current_date, $professional_id, $reason);
            }
            $exists_stmt->execute();
            if ($exists_stmt->get_result()->fetch_assoc()) {
                $skipped++;
                $current->modify('+1 day');
                continue;
            }

            $stmt->bind_param("sisi", $current_date, $professional_id, $reason, $is_global);
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
    $id = $_POST['id'] ?? 0;
    $stmt = $mysqli->prepare("DELETE FROM closed_days WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    echo json_encode(['success' => true]);
} elseif ($action === 'delete_closed_range') {
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
            $stmt = $mysqli->prepare("DELETE FROM closed_days WHERE closed_date BETWEEN ? AND ? AND reason = ? AND is_global = 1");
            $stmt->bind_param("sss", $start_date, $end_date, $reason);
        } elseif ($professional_id <= 0) {
            $stmt = $mysqli->prepare("DELETE FROM closed_days WHERE closed_date BETWEEN ? AND ? AND reason = ? AND is_global = 0 AND professional_id IS NULL");
            $stmt->bind_param("sss", $start_date, $end_date, $reason);
        } else {
            $current_professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
            $stmt = $mysqli->prepare("
                DELETE FROM closed_days
                WHERE closed_date BETWEEN ? AND ?
                  AND reason = ?
                  AND is_global = 0
                  AND (professional_id = ? OR (professional_id IS NULL AND ? = ?))
            ");
            $stmt->bind_param("sssiii", $start_date, $end_date, $reason, $professional_id, $professional_id, $current_professional_id);
        }
    } else {
        $current_professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
        $stmt = $mysqli->prepare("DELETE FROM closed_days WHERE closed_date BETWEEN ? AND ? AND reason = ? AND is_global = 0 AND professional_id = ?");
        $stmt->bind_param("sssi", $start_date, $end_date, $reason, $current_professional_id);
    }
    $stmt->execute();
    echo json_encode(['success' => true, 'deleted' => $stmt->affected_rows]);
} elseif ($action === 'get_cabinet_settings') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede gestionar el modo gabinete.']);
        exit;
    }
    ensure_payment_settings_table($mysqli);
    ensure_cabinet_schema($mysqli);

    $settings_res = $mysqli->query("SELECT show_team_public, allow_patient_transfer, new_patient_booking_mode, new_patient_fixed_professional_id, profile_image_path FROM payment_settings WHERE id = 1");
    $settings = $settings_res ? $settings_res->fetch_assoc() : ['show_team_public' => 0, 'allow_patient_transfer' => 0, 'new_patient_booking_mode' => 'day_first', 'new_patient_fixed_professional_id' => null];
    $dashboard_photo_path = $settings['profile_image_path'] ?? '';
    $res = $mysqli->query("
        SELECT p.id, p.user_id, p.display_name, p.professional_title, p.license_number, p.professional_specialty, p.public_bio,
               p.public_photo_path, p.public_email, p.public_phone, p.instagram_url, p.facebook_url, p.tiktok_url,
               p.appointment_summary_email_mode,
               p.is_active, u.email AS login_email, u.role
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id
        ORDER BY p.sort_order ASC, p.display_name ASC
    ");
    $professionals = [];
    while ($row = $res->fetch_assoc()) {
        $is_current_user = (int) ($row['user_id'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0) ? 1 : 0;
        $photo_path = $row['public_photo_path'] ?? '';
        $display_photo_path = $photo_path ?: ($is_current_user && ($row['role'] ?? '') === 'superadmin' ? $dashboard_photo_path : '');
        $professionals[] = [
            'id' => (int) $row['id'],
            'user_id' => (int) ($row['user_id'] ?? 0),
            'display_name' => $row['display_name'] ?? '',
            'professional_title' => $row['professional_title'] ?? '',
            'license_number' => $row['license_number'] ?? '',
            'professional_specialty' => $row['professional_specialty'] ?? '',
            'public_bio' => $row['public_bio'] ?? '',
            'public_photo_path' => $photo_path,
            'display_photo_path' => $display_photo_path,
            'email' => $row['login_email'] ?: ($row['public_email'] ?? ''),
            'public_phone' => $row['public_phone'] ?? '',
            'instagram_url' => $row['instagram_url'] ?? '',
            'facebook_url' => $row['facebook_url'] ?? '',
            'tiktok_url' => $row['tiktok_url'] ?? '',
            'appointment_summary_email_mode' => $row['appointment_summary_email_mode'] ?? 'on_booking',
            'role' => in_array($row['role'] ?? 'admin', ['superadmin', 'admin'], true) ? $row['role'] : 'admin',
            'is_active' => (int) ($row['is_active'] ?? 1),
            'is_current_user' => $is_current_user
        ];
    }

    echo json_encode(['success' => true, 'settings' => $settings, 'professionals' => $professionals]);
} elseif ($action === 'check_professional_delete') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede gestionar el modo gabinete.']);
        exit;
    }
    ensure_cabinet_schema($mysqli);

    $professional_id = (int) ($_POST['professional_id'] ?? 0);
    if ($professional_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Profesional invalido.']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT id, user_id, display_name FROM professionals WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $professional_id);
    $stmt->execute();
    $professional = $stmt->get_result()->fetch_assoc();
    if (!$professional) {
        echo json_encode(['success' => false, 'error' => 'No se encontro el profesional.']);
        exit;
    }
    if ((int) ($professional['user_id'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0)) {
        echo json_encode(['success' => false, 'error' => 'No puedes borrar tu propio usuario administrador.']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM appointments WHERE professional_id = ? AND status <> 'cancelled' AND appointment_date >= CURDATE()");
    $stmt->bind_param("i", $professional_id);
    $stmt->execute();
    $pending_appointments = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    $stmt = $mysqli->prepare("
        SELECT COUNT(*) AS total FROM (
            SELECT user_id AS patient_id FROM patient_profiles WHERE professional_id = ?
            UNION
            SELECT patient_id FROM patient_professionals WHERE professional_id = ?
        ) assigned_patients
    ");
    $stmt->bind_param("ii", $professional_id, $professional_id);
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
        $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM `$table` WHERE professional_id = ?");
        $stmt->bind_param("i", $professional_id);
        $stmt->execute();
        $linked_records += (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    }
    $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM patient_professionals WHERE professional_id = ?");
    $stmt->bind_param("i", $professional_id);
    $stmt->execute();
    $linked_records += (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    $targets = [];
    $stmt = $mysqli->prepare("
        SELECT id, display_name
        FROM professionals
        WHERE id <> ? AND is_active = 1
        ORDER BY sort_order ASC, display_name ASC
    ");
    $stmt->bind_param("i", $professional_id);
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
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede gestionar el modo gabinete.']);
        exit;
    }
    ensure_cabinet_schema($mysqli);

    $professional_id = (int) ($_POST['professional_id'] ?? 0);
    if ($professional_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Profesional invalido.']);
        exit;
    }

    $stmt = $mysqli->prepare("
        SELECT p.user_id, p.display_name, u.role AS user_role
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $professional_id);
    $stmt->execute();
    $professional = $stmt->get_result()->fetch_assoc();
    if (!$professional) {
        echo json_encode(['success' => false, 'error' => 'No se encontro el profesional.']);
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
        $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM `$table` WHERE professional_id = ?");
        $stmt->bind_param("i", $professional_id);
        $stmt->execute();
        $usage_total += (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    }

    $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM patient_professionals WHERE professional_id = ?");
    $stmt->bind_param("i", $professional_id);
    $stmt->execute();
    $usage_total += (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    if ($usage_total > 0) {
        if ($target_professional_id <= 0 || $target_professional_id === $professional_id) {
            echo json_encode(['success' => false, 'error' => 'Elige otro profesional para traspasar citas y pacientes antes de borrar.']);
            exit;
        }
        $stmt = $mysqli->prepare("SELECT id FROM professionals WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param("i", $target_professional_id);
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
                $stmt = $mysqli->prepare("UPDATE `$table` SET professional_id = ? WHERE professional_id = ?");
                $stmt->bind_param("ii", $target_professional_id, $professional_id);
                $stmt->execute();
            }

            $stmt = $mysqli->prepare("
                INSERT IGNORE INTO patient_professionals (patient_id, professional_id, is_primary, assigned_at, transferred_at, notes)
                SELECT patient_id, ?, is_primary, assigned_at, NOW(), notes
                FROM patient_professionals
                WHERE professional_id = ?
            ");
            $stmt->bind_param("ii", $target_professional_id, $professional_id);
            $stmt->execute();

            $stmt = $mysqli->prepare("DELETE FROM patient_professionals WHERE professional_id = ?");
            $stmt->bind_param("i", $professional_id);
            $stmt->execute();
        }

        $stmt = $mysqli->prepare("DELETE FROM professionals WHERE id = ?");
        $stmt->bind_param("i", $professional_id);
        $stmt->execute();

        $stmt = $mysqli->prepare("DELETE FROM professional_settings WHERE professional_id = ?");
        $stmt->bind_param("i", $professional_id);
        $stmt->execute();

        $linked_user_id = (int) ($professional['user_id'] ?? 0);
        if ($linked_user_id > 0 && ($professional['user_role'] ?? '') === 'admin') {
            $password_resets_exists = $mysqli->query("SHOW TABLES LIKE 'password_resets'");
            if ($password_resets_exists && $password_resets_exists->num_rows > 0) {
                $stmt = $mysqli->prepare("DELETE FROM password_resets WHERE user_id = ?");
                $stmt->bind_param("i", $linked_user_id);
                $stmt->execute();
            }
            $stmt = $mysqli->prepare("DELETE FROM users WHERE id = ? AND role = 'admin'");
            $stmt->bind_param("i", $linked_user_id);
            $stmt->execute();
        }

        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => $usage_total > 0 ? 'Traspaso realizado, profesional y cuenta de acceso borrados correctamente.' : 'Profesional y cuenta de acceso borrados correctamente.']);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'save_cabinet_settings') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede gestionar el modo gabinete.']);
        exit;
    }
    ensure_payment_settings_table($mysqli);
    ensure_cabinet_schema($mysqli);
    admin_ensure_password_reset_table($mysqli);

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
            echo json_encode(['success' => false, 'error' => 'No se pudo localizar el profesional administrador para derivar nuevos pacientes.']);
            exit;
        }
    } else {
        $stmt = $mysqli->prepare("SELECT id FROM professionals WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param("i", $new_patient_fixed_professional_id);
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

    $photo_index = (int) ($_POST['professional_photo_index'] ?? -1);
    $password_setup_users = [];
    $planning_cron_message = '';
    $mysqli->begin_transaction();
    try {
        $mysqli->query("INSERT IGNORE INTO payment_settings (id) VALUES (1)");
        $stmt = $mysqli->prepare("
            INSERT INTO payment_settings (id, show_team_public, allow_patient_transfer, new_patient_booking_mode, new_patient_fixed_professional_id)
            VALUES (1, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                show_team_public = VALUES(show_team_public),
                allow_patient_transfer = VALUES(allow_patient_transfer),
                new_patient_booking_mode = VALUES(new_patient_booking_mode),
                new_patient_fixed_professional_id = VALUES(new_patient_fixed_professional_id)
        ");
        $fixed_professional_db = $new_patient_fixed_professional_id > 0 ? $new_patient_fixed_professional_id : null;
        $stmt->bind_param("iisi", $show_team_public, $allow_patient_transfer, $new_patient_booking_mode, $fixed_professional_db);
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
            $role = ($professional['role'] ?? 'admin') === 'superadmin' ? 'superadmin' : 'admin';
            $is_active = !empty($professional['is_active']) ? 1 : 0;

            if ($display_name === '') {
                throw new \Exception('Hay un profesional sin nombre.');
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception('Hay un profesional sin email valido.');
            }

            if ($user_id <= 0) {
                $stmt = $mysqli->prepare("SELECT id, password_hash FROM users WHERE email = ? LIMIT 1");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $existing_user = $stmt->get_result()->fetch_assoc();
                if ($existing_user) {
                    $user_id = (int) $existing_user['id'];
                    if (empty($existing_user['password_hash'])) {
                        $password_setup_users[$user_id] = ['name' => $display_name, 'email' => $email];
                    }
                } else {
                    $stmt = $mysqli->prepare("INSERT INTO users (name, email, phone, password_hash, role) VALUES (?, ?, NULL, NULL, ?)");
                    $stmt->bind_param("sss", $display_name, $email, $role);
                    $stmt->execute();
                    $user_id = $mysqli->insert_id;
                    $password_setup_users[$user_id] = ['name' => $display_name, 'email' => $email];
                }
            }

            if ($user_id === (int) $_SESSION['user_id']) {
                $role = 'superadmin';
                $is_active = 1;
            }

            $stmt = $mysqli->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?");
            $stmt->bind_param("sssi", $display_name, $email, $role, $user_id);
            $stmt->execute();

            $slug = cabinet_slugify($display_name . '-' . $user_id);
            $sort_order = ($index + 1) * 10;
            if ($professional_id > 0) {
                $stmt = $mysqli->prepare("
                    UPDATE professionals
                    SET user_id = ?, display_name = ?, public_slug = ?, professional_title = ?, license_number = ?, professional_specialty = ?, public_bio = ?, public_photo_path = ?, public_email = ?, public_phone = ?, instagram_url = ?, facebook_url = ?, tiktok_url = ?, appointment_summary_email_mode = ?, is_active = ?, sort_order = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("isssssssssssssiii", $user_id, $display_name, $slug, $title, $license_number, $specialty, $public_bio, $current_photo_path, $email, $public_phone, $instagram_url, $facebook_url, $tiktok_url, $summary_mode, $is_active, $sort_order, $professional_id);
            } else {
                $stmt = $mysqli->prepare("
                    INSERT INTO professionals (user_id, display_name, public_slug, professional_title, license_number, professional_specialty, public_bio, public_photo_path, public_email, public_phone, instagram_url, facebook_url, tiktok_url, appointment_summary_email_mode, is_active, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), professional_title = VALUES(professional_title), license_number = VALUES(license_number), professional_specialty = VALUES(professional_specialty), public_bio = VALUES(public_bio), public_photo_path = VALUES(public_photo_path), public_email = VALUES(public_email), public_phone = VALUES(public_phone), instagram_url = VALUES(instagram_url), facebook_url = VALUES(facebook_url), tiktok_url = VALUES(tiktok_url), appointment_summary_email_mode = VALUES(appointment_summary_email_mode), is_active = VALUES(is_active), sort_order = VALUES(sort_order)
                ");
                $stmt->bind_param("isssssssssssssii", $user_id, $display_name, $slug, $title, $license_number, $specialty, $public_bio, $current_photo_path, $email, $public_phone, $instagram_url, $facebook_url, $tiktok_url, $summary_mode, $is_active, $sort_order);
            }
            $stmt->execute();
            $saved_professional_id = $professional_id > 0 ? $professional_id : (int) $mysqli->insert_id;
            if ($saved_professional_id <= 0) {
                $stmt = $mysqli->prepare("SELECT id FROM professionals WHERE user_id = ? LIMIT 1");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $saved_row = $stmt->get_result()->fetch_assoc();
                $saved_professional_id = $saved_row ? (int) $saved_row['id'] : 0;
            }
            if ($saved_professional_id > 0) {
                cabinet_seed_professional_settings_from_superadmin($mysqli, $saved_professional_id);
            }

            if ($photo_index === $index && isset($_FILES['professional_photo']) && ($_FILES['professional_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ($saved_professional_id <= 0) {
                    throw new \Exception('No se pudo localizar el profesional para guardar la foto.');
                }
                $uploaded_photo_path = save_uploaded_professional_photo($_FILES['professional_photo'], $saved_professional_id);
                $stmt = $mysqli->prepare("UPDATE professionals SET public_photo_path = ? WHERE id = ?");
                $stmt->bind_param("si", $uploaded_photo_path, $saved_professional_id);
                $stmt->execute();
            }
        }

        foreach ($password_setup_users as $setup_user_id => $setup_user) {
            if (!send_professional_password_setup_email($mysqli, (int) $setup_user_id, $setup_user['name'], $setup_user['email'])) {
                throw new \Exception('No se pudo enviar el email para crear la contraseña del profesional. Revisa la configuración de email.');
            }
        }
        $app_name_res = $mysqli->query("SELECT app_name FROM payment_settings WHERE id = 1");
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
        $settings_res = $mysqli->query("SELECT show_team_public, allow_patient_transfer, new_patient_booking_mode, new_patient_fixed_professional_id FROM payment_settings WHERE id = 1");
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

    $res = $mysqli->query("
        SELECT app_name, site_tagline, site_phone, profile_image_path, landing_image_path, primary_color, show_profile_image_public, show_prices_public, show_contact_public, online_booking_enabled, patient_registration_mode, patient_tasks_visible_default, initial_calendar_view, bonuses_enabled, create_compensation_bonus_on_paid_cancel, online_payment_enabled, environment, merchant_code, terminal,
               appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price, admin_notification_email,
               appointment_delivery_mode, available_session_types, available_session_durations, display_effective_duration_enabled, display_duration_offset_minutes,
               appointment_reminder_enabled,
               min_booking_notice_days, max_booking_notice_days, appointment_start_time, appointment_end_time, break_start_time, break_end_time,
               available_weekdays,
               email_provider, smtp_host, smtp_port, smtp_username, smtp_secure, smtp_from_email, smtp_from_name,
               google_client_id, google_connected_email, google_redirect_uri, calendar_provider, google_calendar_enabled, google_calendar_id,
               icloud_calendar_email, icloud_calendar_url, send_patient_calendar_link,
               fastcron_reminder_cron_id,
               legal_owner_name, legal_nif, legal_address, legal_email, legal_license_number, legal_professional_college, legal_uses_non_technical_cookies, legal_terms_notes,
               allow_patient_transfer, dashboard_config_mode,
               merchant_key IS NOT NULL AND merchant_key != '' AS has_merchant_key,
               smtp_password IS NOT NULL AND smtp_password != '' AS has_smtp_password,
               google_client_secret IS NOT NULL AND google_client_secret != '' AS has_google_client_secret,
               google_refresh_token IS NOT NULL AND google_refresh_token != '' AS has_google_refresh_token,
               icloud_calendar_app_password IS NOT NULL AND icloud_calendar_app_password != '' AS has_icloud_calendar_app_password,
               fastcron_api_key IS NOT NULL AND fastcron_api_key != '' AS has_fastcron_api_key
        FROM payment_settings
        WHERE id = 1
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
    $dashboard_config_mode = in_array(($settings['dashboard_config_mode'] ?? ''), ['simple', 'advanced', 'custom'], true) ? $settings['dashboard_config_mode'] : 'simple';
    $settings['dashboard_config_mode'] = $dashboard_config_mode;
    $settings['dashboard_config'] = dashboard_config_for_mode($dashboard_config_mode);

    echo json_encode(['success' => true, 'settings' => $settings, 'services' => fetch_appointment_services($mysqli), 'bonuses' => fetch_appointment_bonuses($mysqli)]);
} elseif ($action === 'get_dashboard_custom_config') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede editar la configuracion personalizada.']);
        exit;
    }
    dashboard_config_ensure_files();
    $files = dashboard_config_files();
    $raw = file_get_contents($files['custom']);
    echo json_encode(['success' => true, 'json' => $raw === false ? '' : $raw]);
} elseif ($action === 'save_dashboard_custom_config') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede editar la configuracion personalizada.']);
        exit;
    }
    $json = $_POST['json'] ?? '';
    $error = '';
    if (!dashboard_config_save_custom_json($json, $error)) {
        echo json_encode(['success' => false, 'error' => $error ?: 'No se pudo guardar la configuracion personalizada.']);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Configuracion personalizada guardada. Se refrescara la ventana para cargar la nueva configuracion.']);
} elseif ($action === 'save_bonuses') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede modificar bonos globales.']);
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
                WHERE id = ?
            ");
            $stmt->bind_param("sidii", $name, $session_count, $price, $is_active, $bonus_id);
            $stmt->execute();
        }

        $stmt = $mysqli->prepare("UPDATE payment_settings SET bonuses_enabled = ?, create_compensation_bonus_on_paid_cancel = ? WHERE id = 1");
        $stmt->bind_param("ii", $bonuses_enabled, $create_compensation_bonus);
        $stmt->execute();

        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => 'Bonos guardados correctamente.', 'bonuses' => fetch_appointment_bonuses($mysqli)]);
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
    $available_session_durations = normalize_available_session_durations($_POST['available_session_durations'] ?? ['60']);
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
            $name = trim((string) ($service['name'] ?? ''));
            $is_active = !empty($service['is_active']) ? 1 : 0;

            if ($service_id <= 0 || $name === '') {
                throw new \Exception('Hay un servicio sin nombre o identificador valido.');
            }

            $stmt = $mysqli->prepare("UPDATE appointment_services SET name = ?, is_active = ? WHERE id = ?");
            $stmt->bind_param("sii", $name, $is_active, $service_id);
            $stmt->execute();

            foreach (($service['options'] ?? []) as $option) {
                $option_id = (int) ($option['id'] ?? 0);
                $duration = (int) ($option['duration_minutes'] ?? 0);
                $consultation_type = $option['consultation_type'] ?? '';
                $price = str_replace(',', '.', trim((string) ($option['price'] ?? '')));
                $option_active = !empty($option['is_active']) ? 1 : 0;

                if ($option_id <= 0 || !in_array($duration, [60, 90, 120], true) || !in_array($consultation_type, ['presencial', 'online'], true)) {
                    throw new \Exception('Hay una opcion de servicio no valida.');
                }
                if (!is_numeric($price) || (float) $price < 0) {
                    throw new \Exception('Hay un precio de servicio no valido.');
                }

                $price = (float) $price;
                if (!in_array($duration, $active_durations, true) || ($appointment_delivery_mode !== 'both' && $consultation_type !== $appointment_delivery_mode)) {
                    $option_active = 0;
                }
                $stmt = $mysqli->prepare("
                    UPDATE appointment_service_options
                    SET duration_minutes = ?, consultation_type = ?, price = ?, is_active = ?
                    WHERE id = ? AND service_id = ?
                ");
                $stmt->bind_param("isdiii", $duration, $consultation_type, $price, $option_active, $option_id, $service_id);
                $stmt->execute();
            }
        }

        $active_keys = ['individual'];
        $allowed_service_keys = ['couple', 'family', 'group'];
        $res = $mysqli->query("SELECT service_key FROM appointment_services WHERE is_active = 1");
        while ($row = $res->fetch_assoc()) {
            $service_key = trim((string) ($row['service_key'] ?? ''));
            if (in_array($service_key, $allowed_service_keys, true)) {
                $active_keys[] = $service_key;
            }
        }
        $available_session_types = implode(',', array_unique($active_keys));
        $stmt = $mysqli->prepare("UPDATE payment_settings SET available_session_types = ? WHERE id = 1");
        $stmt->bind_param("s", $available_session_types);
        $stmt->execute();

        $stmt = $mysqli->prepare("UPDATE payment_settings SET available_session_durations = ? WHERE id = 1");
        $stmt->bind_param("s", $available_session_durations);
        $stmt->execute();
        sync_service_availability($mysqli, $available_session_types, $available_session_durations, $appointment_delivery_mode);

        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => 'Precios guardados correctamente.', 'services' => fetch_appointment_services($mysqli)]);
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
    $primary_color = trim($_POST['primary_color'] ?? '#8f7fba');
    $dashboard_config_mode = $_POST['dashboard_config_mode'] ?? 'simple';
    if (!in_array($dashboard_config_mode, ['simple', 'advanced', 'custom'], true)) {
        $dashboard_config_mode = 'simple';
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
    $available_session_types = normalize_available_session_types($_POST['available_session_types'] ?? []);
    $available_session_durations = normalize_available_session_durations($_POST['available_session_durations'] ?? ['60']);
    $display_effective_duration_enabled = isset($_POST['display_effective_duration_enabled']) && $_POST['display_effective_duration_enabled'] === '1' ? 1 : 0;
    $display_duration_offset_minutes = (int) ($_POST['display_duration_offset_minutes'] ?? 5);
    $display_duration_offset_minutes = max(0, min(30, $display_duration_offset_minutes));
    $posted_appointment_reminder_enabled = array_key_exists('appointment_reminder_enabled', $_POST)
        ? ($_POST['appointment_reminder_enabled'] === '1' ? 1 : 0)
        : null;
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
    $google_client_id = trim($_POST['google_client_id'] ?? '');
    $google_client_secret = trim($_POST['google_client_secret'] ?? '');
    $google_refresh_token = trim($_POST['google_refresh_token'] ?? '');
    $google_connected_email = trim($_POST['google_connected_email'] ?? '');
    $google_redirect_uri = trim($_POST['google_redirect_uri'] ?? '');
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
    $patient_registration_mode = $_POST['patient_registration_mode'] ?? 'invite';
    if (!in_array($patient_registration_mode, ['invite', 'open'], true)) {
        $patient_registration_mode = 'invite';
    }
    $uploaded_profile_image_path = null;
    $uploaded_landing_image_path = null;
    $uploaded_favicon_path = null;

    if ($app_name === '') {
        $app_name = 'PsicoLogic';
    }

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary_color)) {
        echo json_encode(['success' => false, 'error' => 'Color principal inválido']);
        exit;
    }
    $primary_color = strtolower($primary_color);
    if (!in_array($initial_calendar_view, ['week', 'month'], true)) {
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

    $private_settings_payload = [
        'appointment_delivery_mode' => $appointment_delivery_mode,
        'available_session_types' => $available_session_types,
        'available_session_durations' => $available_session_durations,
        'appointment_start_time' => $appointment_start_time,
        'appointment_end_time' => $appointment_end_time,
        'break_start_time' => $break_start_time === '' ? null : $break_start_time,
        'break_end_time' => $break_end_time === '' ? null : $break_end_time,
        'available_weekdays' => $available_weekdays,
        'min_booking_notice_days' => $min_booking_notice_days,
        'max_booking_notice_days' => $max_booking_notice_days
    ];

    $current_professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
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

    $res = $mysqli->query("
        SELECT merchant_key IS NOT NULL AND merchant_key != '' AS has_merchant_key,
               smtp_password IS NOT NULL AND smtp_password != '' AS has_smtp_password,
               google_client_secret IS NOT NULL AND google_client_secret != '' AS has_google_client_secret,
               google_refresh_token IS NOT NULL AND google_refresh_token != '' AS has_google_refresh_token,
               icloud_calendar_app_password IS NOT NULL AND icloud_calendar_app_password != '' AS has_icloud_calendar_app_password,
               fastcron_api_key,
               fastcron_reminder_cron_id
        FROM payment_settings
        WHERE id = 1
    ");
    $current_settings = $res->fetch_assoc();
    $has_merchant_key = $current_settings && (int) $current_settings['has_merchant_key'] === 1;
    $has_smtp_password = $current_settings && (int) $current_settings['has_smtp_password'] === 1;
    $has_google_client_secret = $current_settings && (int) $current_settings['has_google_client_secret'] === 1;
    $has_google_refresh_token = $current_settings && (int) $current_settings['has_google_refresh_token'] === 1;
    $has_icloud_calendar_app_password = $current_settings && (int) $current_settings['has_icloud_calendar_app_password'] === 1;
    $appointment_reminder_enabled = $posted_appointment_reminder_enabled !== null
        ? $posted_appointment_reminder_enabled
        : (int) ($current_settings['appointment_reminder_enabled'] ?? 0);

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

    if ($email_provider === 'google' && (!$google_client_id || ($google_client_secret === '' && !$has_google_client_secret))) {
        echo json_encode(['success' => false, 'error' => 'Client ID y Client Secret son obligatorios para usar Google.']);
        exit;
    }

    if ($google_calendar_enabled && (!$google_client_id || ($google_client_secret === '' && !$has_google_client_secret) || !$google_calendar_id)) {
        echo json_encode(['success' => false, 'error' => 'Para sincronizar Calendario debes configurar credenciales Google y un Calendar ID.']);
        exit;
    }

    if ($calendar_provider === 'icloud' && (!$icloud_calendar_email || ($icloud_calendar_app_password === '' && !$has_icloud_calendar_app_password) || !$icloud_calendar_url)) {
        echo json_encode(['success' => false, 'error' => 'Para sincronizar iCloud Calendar debes configurar Apple ID, contrasena especifica de app y URL del calendario.']);
        exit;
    }

    $configured_fastcron_api_key = defined('FASTCRON_API_KEY') ? trim(FASTCRON_API_KEY) : '';
    $stored_fastcron_api_key = trim($current_settings['fastcron_api_key'] ?? '');
    $effective_fastcron_api_key = $stored_fastcron_api_key !== '' ? $stored_fastcron_api_key : $configured_fastcron_api_key;
    if ($appointment_reminder_enabled && $effective_fastcron_api_key === '') {
        echo json_encode(['success' => false, 'error' => 'No se pudo habilitar la opcion de recordatorio de cita por un motivo externo: falta configurar el token API de Fastcron.']);
        exit;
    }

    $current_cron_id = trim($current_settings['fastcron_reminder_cron_id'] ?? '');
    if (!$appointment_reminder_enabled && $current_cron_id !== '' && $effective_fastcron_api_key === '') {
        echo json_encode(['success' => false, 'error' => 'No se pudo deshabilitar la opcion de recordatorio de cita por un motivo externo: falta configurar el token API de Fastcron para borrar el cron existente.']);
        exit;
    }

    $new_cron_id = null;
    $clear_cron_id = false;
    $cron_message = '';

    try {
        if ($appointment_reminder_enabled && $current_cron_id === '') {
            $new_cron_id = fastcron_create_reminder_cron($effective_fastcron_api_key, reminder_cron_url(), $app_name);
            $cron_message = ' Cron de Fastcron creado.';
        } elseif (!$appointment_reminder_enabled && $current_cron_id !== '') {
            fastcron_delete_cron($effective_fastcron_api_key, $current_cron_id);
            $clear_cron_id = true;
            $cron_message = ' Cron de Fastcron eliminado.';
        }
    } catch (\Exception $e) {
        $action_error = $appointment_reminder_enabled ? 'habilitar' : 'deshabilitar';
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
                google_client_id = ?, google_connected_email = ?, google_redirect_uri = ?, google_calendar_enabled = ?, google_calendar_id = ?
            WHERE id = 1
        ");
        bind_params_dynamic($stmt, "sssissssddddsiiississsssssis", [$app_name, $site_tagline, $site_phone, $enabled, $environment, $merchant_code, $merchant_key, $terminal, $appointment_price, $online_appointment_price, $couple_appointment_price, $online_couple_appointment_price, $admin_notification_email, $appointment_reminder_enabled, $min_booking_notice_days, $max_booking_notice_days, $email_provider, $smtp_host, $smtp_port, $smtp_username, $smtp_secure, $smtp_from_email, $smtp_from_name, $google_client_id, $google_connected_email, $google_redirect_uri, $google_calendar_enabled, $google_calendar_id]);
    } else {
        $stmt = $mysqli->prepare("
            UPDATE payment_settings
            SET app_name = ?, site_tagline = ?, site_phone = ?, online_payment_enabled = ?, environment = ?, merchant_code = ?, terminal = ?, appointment_price = ?, online_appointment_price = ?, couple_appointment_price = ?, online_couple_appointment_price = ?, admin_notification_email = ?,
                appointment_reminder_enabled = ?, min_booking_notice_days = ?, max_booking_notice_days = ?,
                email_provider = ?, smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_secure = ?, smtp_from_email = ?, smtp_from_name = ?,
                google_client_id = ?, google_connected_email = ?, google_redirect_uri = ?, google_calendar_enabled = ?, google_calendar_id = ?
            WHERE id = 1
        ");
        bind_params_dynamic($stmt, "sssisssddddsiiississsssssis", [$app_name, $site_tagline, $site_phone, $enabled, $environment, $merchant_code, $terminal, $appointment_price, $online_appointment_price, $couple_appointment_price, $online_couple_appointment_price, $admin_notification_email, $appointment_reminder_enabled, $min_booking_notice_days, $max_booking_notice_days, $email_provider, $smtp_host, $smtp_port, $smtp_username, $smtp_secure, $smtp_from_email, $smtp_from_name, $google_client_id, $google_connected_email, $google_redirect_uri, $google_calendar_enabled, $google_calendar_id]);
    }

    $stmt->execute();

    $stmt = $mysqli->prepare("UPDATE payment_settings SET calendar_provider = ?, google_calendar_enabled = ?, google_calendar_id = ?, icloud_calendar_email = ?, icloud_calendar_url = ?, send_patient_calendar_link = ? WHERE id = 1");
    $stmt->bind_param("sisssi", $calendar_provider, $google_calendar_enabled, $google_calendar_id, $icloud_calendar_email, $icloud_calendar_url, $send_patient_calendar_link);
    $stmt->execute();

    $stmt = $mysqli->prepare("UPDATE payment_settings SET primary_color = ? WHERE id = 1");
    $stmt->bind_param("s", $primary_color);
    $stmt->execute();

    $stmt = $mysqli->prepare("UPDATE payment_settings SET dashboard_config_mode = ? WHERE id = 1");
    $stmt->bind_param("s", $dashboard_config_mode);
    $stmt->execute();

    $stmt = $mysqli->prepare("
        UPDATE payment_settings
        SET legal_owner_name = ?, legal_nif = ?, legal_address = ?, legal_email = ?, legal_license_number = ?, legal_professional_college = ?, legal_uses_non_technical_cookies = ?, legal_terms_notes = ?
        WHERE id = 1
    ");
    $stmt->bind_param("ssssssis", $legal_owner_name, $legal_nif, $legal_address, $legal_email, $legal_license_number, $legal_professional_college, $legal_uses_non_technical_cookies, $legal_terms_notes);
    $stmt->execute();

    $stmt = $mysqli->prepare("UPDATE payment_settings SET appointment_delivery_mode = ? WHERE id = 1");
    $stmt->bind_param("s", $appointment_delivery_mode);
    $stmt->execute();

    $stmt = $mysqli->prepare("UPDATE payment_settings SET available_session_types = ? WHERE id = 1");
    $stmt->bind_param("s", $available_session_types);
    $stmt->execute();

    $stmt = $mysqli->prepare("UPDATE payment_settings SET available_session_durations = ? WHERE id = 1");
    $stmt->bind_param("s", $available_session_durations);
    $stmt->execute();
    sync_service_availability($mysqli, $available_session_types, $available_session_durations, $appointment_delivery_mode);

    $stmt = $mysqli->prepare("UPDATE payment_settings SET display_effective_duration_enabled = ?, display_duration_offset_minutes = ? WHERE id = 1");
    $stmt->bind_param("ii", $display_effective_duration_enabled, $display_duration_offset_minutes);
    $stmt->execute();

    $break_start_db = $break_start_time === '' ? null : $break_start_time;
    $break_end_db = $break_end_time === '' ? null : $break_end_time;
    $stmt = $mysqli->prepare("
        UPDATE payment_settings
        SET appointment_start_time = ?, appointment_end_time = ?, break_start_time = ?, break_end_time = ?, available_weekdays = ?
        WHERE id = 1
    ");
    $stmt->bind_param("sssss", $appointment_start_time, $appointment_end_time, $break_start_db, $break_end_db, $available_weekdays);
    $stmt->execute();

    if ($current_professional_id > 0) {
        cabinet_upsert_professional_settings($mysqli, $current_professional_id, $private_settings_payload);
    }

    if ($uploaded_profile_image_path !== null) {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET profile_image_path = ?, show_profile_image_public = ?, show_prices_public = ?, show_contact_public = ?, online_booking_enabled = ?, patient_tasks_visible_default = ?, patient_registration_mode = ?, initial_calendar_view = ? WHERE id = 1");
        $stmt->bind_param("siiiiiss", $uploaded_profile_image_path, $show_profile_image_public, $show_prices_public, $show_contact_public, $online_booking_enabled, $patient_tasks_visible_default, $patient_registration_mode, $initial_calendar_view);
        $stmt->execute();
    } else {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET show_profile_image_public = ?, show_prices_public = ?, show_contact_public = ?, online_booking_enabled = ?, patient_tasks_visible_default = ?, patient_registration_mode = ?, initial_calendar_view = ? WHERE id = 1");
        $stmt->bind_param("iiiiiss", $show_profile_image_public, $show_prices_public, $show_contact_public, $online_booking_enabled, $patient_tasks_visible_default, $patient_registration_mode, $initial_calendar_view);
        $stmt->execute();
    }

    if ($uploaded_landing_image_path !== null) {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET landing_image_path = ? WHERE id = 1");
        $stmt->bind_param("s", $uploaded_landing_image_path);
        $stmt->execute();
    }

    if ($uploaded_favicon_path !== null && $uploaded_favicon_path !== '') {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET favicon_path = ? WHERE id = 1");
        $stmt->bind_param("s", $uploaded_favicon_path);
        $stmt->execute();
    }

    if ($smtp_password !== '') {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET smtp_password = ? WHERE id = 1");
        $stmt->bind_param("s", $smtp_password);
        $stmt->execute();
    }

    if ($google_client_secret !== '') {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET google_client_secret = ? WHERE id = 1");
        $stmt->bind_param("s", $google_client_secret);
        $stmt->execute();
    }

    if ($google_refresh_token !== '') {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET google_refresh_token = ? WHERE id = 1");
        $stmt->bind_param("s", $google_refresh_token);
        $stmt->execute();
    }

    if ($icloud_calendar_app_password !== '') {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET icloud_calendar_app_password = ? WHERE id = 1");
        $stmt->bind_param("s", $icloud_calendar_app_password);
        $stmt->execute();
    }

    if ($stored_fastcron_api_key === '' && $configured_fastcron_api_key !== '') {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET fastcron_api_key = ? WHERE id = 1");
        $stmt->bind_param("s", $configured_fastcron_api_key);
        $stmt->execute();
    }

    if ($new_cron_id !== null) {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET fastcron_reminder_cron_id = ? WHERE id = 1");
        $stmt->bind_param("s", $new_cron_id);
        $stmt->execute();
    } elseif ($clear_cron_id) {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET fastcron_reminder_cron_id = NULL WHERE id = 1");
        $stmt->execute();
    }

    echo json_encode(['success' => true, 'message' => trim('Configuracion guardada correctamente.' . $cron_message)]);
} else {
    echo json_encode(['success' => false, 'error' => 'Acción inválida']);
}
