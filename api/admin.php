<?php
session_start();
require_once '../db.php';
require_once '../mail_helpers.php';
require_once '../settings_helpers.php';
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
        $rows[] = [
            'id' => (int) $row['id'],
            'display_name' => $row['display_name'],
            'public_photo_path' => $row['public_photo_path'] ?? '',
            'display_photo_path' => $display_photo_path
        ];
    }
    return $rows;
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
            primary_color VARCHAR(7) NOT NULL DEFAULT '#8f7fba',
            show_profile_image_public TINYINT(1) NOT NULL DEFAULT 0,
            show_prices_public TINYINT(1) NOT NULL DEFAULT 0,
            online_booking_enabled TINYINT(1) NOT NULL DEFAULT 1,
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
        'primary_color' => "ALTER TABLE payment_settings ADD primary_color VARCHAR(7) NOT NULL DEFAULT '#8f7fba' AFTER landing_image_path",
        'show_profile_image_public' => "ALTER TABLE payment_settings ADD show_profile_image_public TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_image_path",
        'show_prices_public' => "ALTER TABLE payment_settings ADD show_prices_public TINYINT(1) NOT NULL DEFAULT 0 AFTER show_profile_image_public",
        'online_booking_enabled' => "ALTER TABLE payment_settings ADD online_booking_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER show_prices_public",
        'bonuses_enabled' => "ALTER TABLE payment_settings ADD bonuses_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER show_prices_public",
        'create_compensation_bonus_on_paid_cancel' => "ALTER TABLE payment_settings ADD create_compensation_bonus_on_paid_cancel TINYINT(1) NOT NULL DEFAULT 1 AFTER bonuses_enabled",
        'appointment_delivery_mode' => "ALTER TABLE payment_settings ADD appointment_delivery_mode ENUM('both', 'presencial', 'online') NOT NULL DEFAULT 'both' AFTER admin_notification_email",
        'available_session_types' => "ALTER TABLE payment_settings ADD available_session_types VARCHAR(32) NOT NULL DEFAULT 'individual' AFTER appointment_delivery_mode",
        'available_session_durations' => "ALTER TABLE payment_settings ADD available_session_durations VARCHAR(16) NOT NULL DEFAULT '60' AFTER available_session_types",
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
        'fastcron_reminder_cron_id' => "ALTER TABLE payment_settings ADD fastcron_reminder_cron_id VARCHAR(64) DEFAULT NULL AFTER fastcron_api_key"
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
            admission_date DATE DEFAULT NULL,
            notes LONGTEXT DEFAULT NULL,
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

function patient_has_portal_access($row)
{
    return !empty($row['email']) && !empty($row['password_hash']);
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

    $upload_dir = dirname(__DIR__) . '/uploads/patients';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        throw new \Exception('No se pudo crear la carpeta de documentos.');
    }

    $filename = 'patient_' . (int) $patient_id . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$extension];
    $destination = $upload_dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new \Exception('No se pudo guardar el documento.');
    }

    return [
        'path' => 'uploads/patients/' . $filename,
        'name' => basename($file['name'])
    ];
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
    foreach ((array) $value as $type) {
        $type = trim((string) $type);
        if ($type === 'couple' && !in_array('couple', $selected, true)) {
            $selected[] = 'couple';
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

function sync_service_availability($mysqli, $available_session_types, $available_session_durations, $appointment_delivery_mode)
{
    ensure_appointment_services_tables($mysqli);

    $show_couple = strpos($available_session_types, 'couple') !== false;
    $stmt = $mysqli->prepare("UPDATE appointment_services SET is_active = CASE WHEN service_key = 'couple' THEN ? ELSE 1 END");
    $couple_active = $show_couple ? 1 : 0;
    $stmt->bind_param("i", $couple_active);
    $stmt->execute();

    $active_durations = array_map('intval', explode(',', $available_session_durations ?: '60'));
    $services = fetch_appointment_services($mysqli);
    foreach ($services as $service) {
        $service_allowed = $service['service_key'] !== 'couple' || $show_couple;
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
        throw new \Exception('No se pudo subir la foto del profesional.');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new \Exception('La foto del profesional no puede superar 2 MB.');
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
               pp.patient_type, pp.admission_date, pp.notes, pp.document_path, pp.document_name, pp.created_by_admin,
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
            'admission_date' => $row['admission_date'] ?? substr((string) $row['created_at'], 0, 10),
            'notes' => $row['notes'] ?? '',
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
    $admission_date = trim($_POST['admission_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $selected_professional_id = admin_requested_professional_filter($mysqli);
    if ($selected_professional_id <= 0) {
        $selected_professional_id = current_professional_id_for_user($mysqli, (int) ($_SESSION['user_id'] ?? 0));
    }

    $email = $email !== '' ? $email : null;
    $phone = $phone !== '' ? $phone : null;
    $patient_type = $patient_type !== '' ? $patient_type : null;
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

        $uploaded_document = save_patient_document_upload($_FILES['patient_document'] ?? null, $patient_id);

        $stmt = $mysqli->prepare("
            INSERT INTO patient_profiles (user_id, professional_id, patient_type, admission_date, notes, created_by_admin)
            VALUES (?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE professional_id = VALUES(professional_id), patient_type = VALUES(patient_type), admission_date = VALUES(admission_date), notes = VALUES(notes)
        ");
        $stmt->bind_param("iisss", $patient_id, $selected_professional_id, $patient_type, $admission_date, $notes);
        $stmt->execute();
        if ($selected_professional_id > 0) {
            $stmt = $mysqli->prepare("
                INSERT INTO patient_professionals (patient_id, professional_id, is_primary, notes)
                VALUES (?, ?, 1, 'Asignación desde ficha')
                ON DUPLICATE KEY UPDATE is_primary = 1
            ");
            $stmt->bind_param("ii", $patient_id, $selected_professional_id);
            $stmt->execute();
        }

        if ($uploaded_document !== null) {
            $stmt = $mysqli->prepare("UPDATE patient_profiles SET document_path = ?, document_name = ? WHERE user_id = ?");
            $stmt->bind_param("ssi", $uploaded_document['path'], $uploaded_document['name'], $patient_id);
            $stmt->execute();
        }

        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => 'Paciente guardado correctamente.', 'patient_id' => $patient_id]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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
} elseif ($action === 'upcoming_appointments') {
    ensure_appointment_payment_columns($mysqli);
    ensure_appointment_services_tables($mysqli);
    ensure_cabinet_schema($mysqli);
    $branding = get_public_branding_settings($mysqli);
    $dashboard_photo = $branding['profile_image_path'] ?? '';
    $scope = $_GET['scope'] ?? 'limit10';
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

    $professionals = [];
    if ($is_superadmin) {
        $professionals = active_professionals_payload($mysqli);
    }

    echo json_encode([
        'success' => true,
        'appointments' => $appointments,
        'professionals' => $professionals,
        'current_professional_id' => $current_professional_id
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
    $patient_join = "LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.is_primary = 1 LEFT JOIN patient_profiles pp ON pp.user_id = u.id";
    $patient_filter = $professional_id > 0 ? " AND COALESCE(ppf.professional_id, pp.professional_id) = " . (int) $professional_id : ($professional_id < 0 ? " AND 1 = 0" : "");

    $stats = [
        'upcoming_count' => 0,
        'today_count' => 0,
        'month_count' => 0,
        'patient_count' => 0,
        'online_revenue_month' => '0.00',
        'active_bonus_count' => 0,
        'active_bonus_sessions' => 0,
        'top_patients' => [],
        'professional_summary' => []
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
          $appointment_filter
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

    $settings_res = $mysqli->query("SELECT show_team_public, allow_patient_transfer, profile_image_path FROM payment_settings WHERE id = 1");
    $settings = $settings_res ? $settings_res->fetch_assoc() : ['show_team_public' => 0, 'allow_patient_transfer' => 0];
    $dashboard_photo_path = $settings['profile_image_path'] ?? '';
    $res = $mysqli->query("
        SELECT p.id, p.user_id, p.display_name, p.professional_title, p.license_number, p.professional_specialty, p.public_photo_path, p.public_email, p.public_phone,
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
            'public_photo_path' => $photo_path,
            'display_photo_path' => $display_photo_path,
            'email' => $row['login_email'] ?: ($row['public_email'] ?? ''),
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
    $professionals = json_decode($_POST['professionals_json'] ?? '[]', true);
    if (!is_array($professionals)) {
        echo json_encode(['success' => false, 'error' => 'Listado de profesionales invalido.']);
        exit;
    }

    $photo_index = (int) ($_POST['professional_photo_index'] ?? -1);
    $password_setup_users = [];
    $mysqli->begin_transaction();
    try {
        $mysqli->query("INSERT IGNORE INTO payment_settings (id) VALUES (1)");
        $stmt = $mysqli->prepare("
            INSERT INTO payment_settings (id, show_team_public, allow_patient_transfer)
            VALUES (1, ?, ?)
            ON DUPLICATE KEY UPDATE
                show_team_public = VALUES(show_team_public),
                allow_patient_transfer = VALUES(allow_patient_transfer)
        ");
        $stmt->bind_param("ii", $show_team_public, $allow_patient_transfer);
        $stmt->execute();

        foreach ($professionals as $index => $professional) {
            $professional_id = (int) ($professional['id'] ?? 0);
            $user_id = (int) ($professional['user_id'] ?? 0);
            $display_name = trim((string) ($professional['display_name'] ?? ''));
            $title = trim((string) ($professional['professional_title'] ?? ''));
            $license_number = trim((string) ($professional['license_number'] ?? ''));
            $specialty = trim((string) ($professional['professional_specialty'] ?? ''));
            $current_photo_path = trim((string) ($professional['public_photo_path'] ?? ''));
            $email = trim((string) ($professional['email'] ?? ''));
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
                    SET user_id = ?, display_name = ?, public_slug = ?, professional_title = ?, license_number = ?, professional_specialty = ?, public_photo_path = ?, public_email = ?, is_active = ?, sort_order = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("isssssssiii", $user_id, $display_name, $slug, $title, $license_number, $specialty, $current_photo_path, $email, $is_active, $sort_order, $professional_id);
            } else {
                $stmt = $mysqli->prepare("
                    INSERT INTO professionals (user_id, display_name, public_slug, professional_title, license_number, professional_specialty, public_photo_path, public_email, is_active, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), professional_title = VALUES(professional_title), license_number = VALUES(license_number), professional_specialty = VALUES(professional_specialty), public_photo_path = VALUES(public_photo_path), public_email = VALUES(public_email), is_active = VALUES(is_active), sort_order = VALUES(sort_order)
                ");
                $stmt->bind_param("isssssssii", $user_id, $display_name, $slug, $title, $license_number, $specialty, $current_photo_path, $email, $is_active, $sort_order);
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
        $mysqli->commit();
        $settings_res = $mysqli->query("SELECT show_team_public, allow_patient_transfer FROM payment_settings WHERE id = 1");
        $saved_settings = $settings_res ? $settings_res->fetch_assoc() : ['show_team_public' => $show_team_public, 'allow_patient_transfer' => $allow_patient_transfer];
        echo json_encode([
            'success' => true,
            'message' => 'Equipo guardado correctamente.',
            'settings' => [
                'show_team_public' => (int) ($saved_settings['show_team_public'] ?? 0),
                'allow_patient_transfer' => (int) ($saved_settings['allow_patient_transfer'] ?? 0)
            ]
        ]);
    } catch (\Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'get_payment_settings') {
    ensure_payment_settings_table($mysqli);
    ensure_cabinet_schema($mysqli);

    $res = $mysqli->query("
        SELECT app_name, site_tagline, site_phone, profile_image_path, landing_image_path, primary_color, show_profile_image_public, show_prices_public, online_booking_enabled, bonuses_enabled, create_compensation_bonus_on_paid_cancel, online_payment_enabled, environment, merchant_code, terminal,
               appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price, admin_notification_email,
               appointment_delivery_mode, available_session_types, available_session_durations,
               appointment_reminder_enabled,
               min_booking_notice_days, max_booking_notice_days, appointment_start_time, appointment_end_time, break_start_time, break_end_time,
               available_weekdays,
               email_provider, smtp_host, smtp_port, smtp_username, smtp_secure, smtp_from_email, smtp_from_name,
               google_client_id, google_connected_email, google_redirect_uri, calendar_provider, google_calendar_enabled, google_calendar_id,
               icloud_calendar_email, icloud_calendar_url, send_patient_calendar_link,
               fastcron_reminder_cron_id,
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

    echo json_encode(['success' => true, 'settings' => $settings, 'services' => fetch_appointment_services($mysqli), 'bonuses' => fetch_appointment_bonuses($mysqli)]);
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
        $res = $mysqli->query("SELECT service_key FROM appointment_services WHERE is_active = 1");
        while ($row = $res->fetch_assoc()) {
            if ($row['service_key'] === 'couple') {
                $active_keys[] = 'couple';
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
    $primary_color = trim($_POST['primary_color'] ?? '#8f7fba');
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
    $show_profile_image_public = isset($_POST['show_profile_image_public']) && $_POST['show_profile_image_public'] === '1' ? 1 : 0;
    $show_prices_public = isset($_POST['show_prices_public']) && $_POST['show_prices_public'] === '1' ? 1 : 0;
    $online_booking_enabled = isset($_POST['online_booking_enabled']) && $_POST['online_booking_enabled'] === '1' ? 1 : 0;
    $uploaded_profile_image_path = null;
    $uploaded_landing_image_path = null;

    if ($app_name === '') {
        $app_name = 'PsicoLogic';
    }

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary_color)) {
        echo json_encode(['success' => false, 'error' => 'Color principal inválido']);
        exit;
    }
    $primary_color = strtolower($primary_color);

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
        'max_booking_notice_days' => $max_booking_notice_days,
        'bonuses_enabled' => isset($_POST['bonuses_enabled']) && $_POST['bonuses_enabled'] === '1' ? 1 : 0,
        'create_compensation_bonus_on_paid_cancel' => isset($_POST['create_compensation_bonus_on_paid_cancel']) && $_POST['create_compensation_bonus_on_paid_cancel'] === '1' ? 1 : 0
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
        $stmt = $mysqli->prepare("UPDATE payment_settings SET profile_image_path = ?, show_profile_image_public = ?, show_prices_public = ?, online_booking_enabled = ? WHERE id = 1");
        $stmt->bind_param("siii", $uploaded_profile_image_path, $show_profile_image_public, $show_prices_public, $online_booking_enabled);
        $stmt->execute();
    } else {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET show_profile_image_public = ?, show_prices_public = ?, online_booking_enabled = ? WHERE id = 1");
        $stmt->bind_param("iii", $show_profile_image_public, $show_prices_public, $online_booking_enabled);
        $stmt->execute();
    }

    if ($uploaded_landing_image_path !== null) {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET landing_image_path = ? WHERE id = 1");
        $stmt->bind_param("s", $uploaded_landing_image_path);
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
