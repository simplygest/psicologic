<?php
session_start();
require_once '../db.php';
require_once '../mail_helpers.php';
require_once '../settings_helpers.php';
require_once '../payment_helpers.php';
require_once '../fastcron_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$action = $_GET['action'] ?? '';

function ensure_payment_settings_table($mysqli)
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS payment_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            app_name VARCHAR(255) DEFAULT 'PsicoLogic',
            profile_image_path VARCHAR(255) DEFAULT NULL,
            show_profile_image_public TINYINT(1) NOT NULL DEFAULT 0,
            online_payment_enabled TINYINT(1) NOT NULL DEFAULT 0,
            environment ENUM('sandbox', 'real') NOT NULL DEFAULT 'sandbox',
            merchant_code VARCHAR(32) DEFAULT NULL,
            merchant_key VARCHAR(255) DEFAULT NULL,
            terminal VARCHAR(8) DEFAULT NULL,
            appointment_price DECIMAL(10,2) NOT NULL DEFAULT 70.00,
            admin_notification_email VARCHAR(255) DEFAULT NULL,
            appointment_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0,
            min_booking_notice_days INT UNSIGNED NOT NULL DEFAULT 2,
            max_booking_notice_days INT UNSIGNED NOT NULL DEFAULT 40,
            appointment_start_time TIME NOT NULL DEFAULT '10:00:00',
            appointment_end_time TIME NOT NULL DEFAULT '19:00:00',
            break_start_time TIME DEFAULT '15:00:00',
            break_end_time TIME DEFAULT '16:00:00',
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
            google_calendar_enabled TINYINT(1) NOT NULL DEFAULT 0,
            google_calendar_id VARCHAR(255) DEFAULT 'primary',
            fastcron_api_key VARCHAR(255) DEFAULT NULL,
            fastcron_reminder_cron_id VARCHAR(64) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'appointment_price'");
    if ($columns->num_rows === 0) {
        $mysqli->query("ALTER TABLE payment_settings ADD appointment_price DECIMAL(10,2) NOT NULL DEFAULT 70.00 AFTER terminal");
    } else {
        $mysqli->query("ALTER TABLE payment_settings ALTER appointment_price SET DEFAULT 70.00");
    }

    $mysqli->query("
        INSERT IGNORE INTO payment_settings
            (id, online_payment_enabled, environment, appointment_price, min_booking_notice_days)
        VALUES
            (1, 0, 'sandbox', 70.00, 2)
    ");

    ensure_admin_notification_email_column($mysqli);
    ensure_branding_columns($mysqli);

    $columns = [
        'email_provider' => "ALTER TABLE payment_settings ADD email_provider ENUM('phpmailer', 'google') NOT NULL DEFAULT 'phpmailer' AFTER admin_notification_email",
        'app_name' => "ALTER TABLE payment_settings ADD app_name VARCHAR(255) DEFAULT 'PsicoLogic' AFTER id",
        'profile_image_path' => "ALTER TABLE payment_settings ADD profile_image_path VARCHAR(255) DEFAULT NULL AFTER app_name",
        'show_profile_image_public' => "ALTER TABLE payment_settings ADD show_profile_image_public TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_image_path",
        'appointment_reminder_enabled' => "ALTER TABLE payment_settings ADD appointment_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER admin_notification_email",
        'min_booking_notice_days' => "ALTER TABLE payment_settings ADD min_booking_notice_days INT UNSIGNED NOT NULL DEFAULT 2 AFTER admin_notification_email",
        'max_booking_notice_days' => "ALTER TABLE payment_settings ADD max_booking_notice_days INT UNSIGNED NOT NULL DEFAULT 40 AFTER min_booking_notice_days",
        'appointment_start_time' => "ALTER TABLE payment_settings ADD appointment_start_time TIME NOT NULL DEFAULT '10:00:00' AFTER max_booking_notice_days",
        'appointment_end_time' => "ALTER TABLE payment_settings ADD appointment_end_time TIME NOT NULL DEFAULT '19:00:00' AFTER appointment_start_time",
        'break_start_time' => "ALTER TABLE payment_settings ADD break_start_time TIME DEFAULT '15:00:00' AFTER appointment_end_time",
        'break_end_time' => "ALTER TABLE payment_settings ADD break_end_time TIME DEFAULT '16:00:00' AFTER break_start_time",
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
        'google_calendar_enabled' => "ALTER TABLE payment_settings ADD google_calendar_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER google_redirect_uri",
        'google_calendar_id' => "ALTER TABLE payment_settings ADD google_calendar_id VARCHAR(255) DEFAULT 'primary' AFTER google_calendar_enabled",
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

if ($action === 'generate_invite') {
    $token = bin2hex(random_bytes(32));

    $stmt = $mysqli->prepare("INSERT INTO invitations (token) VALUES (?)");
    $stmt->bind_param("s", $token);
    if ($stmt->execute()) {
        // Obtenemos el protocolo y el dominio actual para crear el enlace completo
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $domainName = $_SERVER['HTTP_HOST'];
        $path = dirname(dirname($_SERVER['REQUEST_URI']));

        // Remove trailing slash if exists
        $path = rtrim($path, '/');

        $link = $protocol . $domainName . $path . '/register.php?token=' . $token;
        echo json_encode(['success' => true, 'link' => $link]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Error generando invitación']);
    }
} elseif ($action === 'get_patients') {
    $res = $mysqli->query("SELECT id, name FROM users WHERE role = 'patient' ORDER BY name ASC");
    echo json_encode(['success' => true, 'patients' => $res->fetch_all(MYSQLI_ASSOC)]);
} elseif ($action === 'list_closed_days') {
    $res = $mysqli->query("SELECT * FROM closed_days WHERE closed_date >= CURDATE() ORDER BY closed_date ASC");
    echo json_encode(['success' => true, 'days' => $res->fetch_all(MYSQLI_ASSOC)]);
} elseif ($action === 'add_closed_day') {
    $date = $_POST['date'] ?? ($_POST['start_date'] ?? '');
    $end_date = $_POST['end_date'] ?? $date;
    $reason = $_POST['reason'] ?? 'Descanso';
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
        $stmt = $mysqli->prepare("INSERT IGNORE INTO closed_days (closed_date, reason) VALUES (?, ?)");
        $current = clone $start;

        while ($current <= $end) {
            $current_date = $current->format('Y-m-d');
            $stmt->bind_param("ss", $current_date, $reason);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $inserted++;
            } else {
                $skipped++;
            }

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
} elseif ($action === 'get_payment_settings') {
    ensure_payment_settings_table($mysqli);

    $res = $mysqli->query("
        SELECT app_name, profile_image_path, show_profile_image_public, online_payment_enabled, environment, merchant_code, terminal, appointment_price, admin_notification_email,
               appointment_reminder_enabled,
               min_booking_notice_days, max_booking_notice_days, appointment_start_time, appointment_end_time, break_start_time, break_end_time,
               email_provider, smtp_host, smtp_port, smtp_username, smtp_secure, smtp_from_email, smtp_from_name,
               google_client_id, google_connected_email, google_redirect_uri, google_calendar_enabled, google_calendar_id,
               fastcron_reminder_cron_id,
               merchant_key IS NOT NULL AND merchant_key != '' AS has_merchant_key,
               smtp_password IS NOT NULL AND smtp_password != '' AS has_smtp_password,
               google_client_secret IS NOT NULL AND google_client_secret != '' AS has_google_client_secret,
               google_refresh_token IS NOT NULL AND google_refresh_token != '' AS has_google_refresh_token,
               fastcron_api_key IS NOT NULL AND fastcron_api_key != '' AS has_fastcron_api_key
        FROM payment_settings
        WHERE id = 1
    ");
    $settings = $res->fetch_assoc();

    echo json_encode(['success' => true, 'settings' => $settings]);
} elseif ($action === 'save_payment_settings') {
    ensure_payment_settings_table($mysqli);

    $enabled = isset($_POST['online_payment_enabled']) && $_POST['online_payment_enabled'] === '1' ? 1 : 0;
    $app_name = trim($_POST['app_name'] ?? '');
    $environment = $_POST['environment'] ?? 'sandbox';
    $merchant_code = trim($_POST['merchant_code'] ?? '');
    $merchant_key = trim($_POST['merchant_key'] ?? '');
    $terminal = trim($_POST['terminal'] ?? '');
    $appointment_price = str_replace(',', '.', trim($_POST['appointment_price'] ?? '0'));
    $admin_notification_email = trim($_POST['admin_notification_email'] ?? '');
    $posted_appointment_reminder_enabled = array_key_exists('appointment_reminder_enabled', $_POST)
        ? ($_POST['appointment_reminder_enabled'] === '1' ? 1 : 0)
        : null;
    $min_booking_notice_days = (int) ($_POST['min_booking_notice_days'] ?? 0);
    $max_booking_notice_days = (int) ($_POST['max_booking_notice_days'] ?? 0);
    $appointment_start_time = normalize_time_field($_POST['appointment_start_time'] ?? '', '10:00:00');
    $appointment_end_time = normalize_time_field($_POST['appointment_end_time'] ?? '', '19:00:00');
    $break_start_time = normalize_time_field($_POST['break_start_time'] ?? '', '');
    $break_end_time = normalize_time_field($_POST['break_end_time'] ?? '', '');
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
    $google_calendar_enabled = isset($_POST['google_calendar_enabled']) && $_POST['google_calendar_enabled'] === '1' ? 1 : 0;
    $google_calendar_id = trim($_POST['google_calendar_id'] ?? 'primary');
    $show_profile_image_public = isset($_POST['show_profile_image_public']) && $_POST['show_profile_image_public'] === '1' ? 1 : 0;
    $uploaded_profile_image_path = null;

    if ($app_name === '') {
        $app_name = 'PsicoLogic';
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

    if ($admin_notification_email && !filter_var($admin_notification_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Email de notificaciones inválido']);
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

    $res = $mysqli->query("
        SELECT merchant_key IS NOT NULL AND merchant_key != '' AS has_merchant_key,
               smtp_password IS NOT NULL AND smtp_password != '' AS has_smtp_password,
               google_client_secret IS NOT NULL AND google_client_secret != '' AS has_google_client_secret,
               google_refresh_token IS NOT NULL AND google_refresh_token != '' AS has_google_refresh_token,
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
    $appointment_reminder_enabled = $posted_appointment_reminder_enabled !== null
        ? $posted_appointment_reminder_enabled
        : (int) ($current_settings['appointment_reminder_enabled'] ?? 0);

    if ($enabled && (!$merchant_code || !$terminal || (float) $appointment_price <= 0 || ($merchant_key === '' && !$has_merchant_key))) {
        echo json_encode(['success' => false, 'error' => 'Código de comercio, clave, terminal e importe son obligatorios para activar el pago online.']);
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

    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'No se pudo subir la imagen.']);
            exit;
        }

        if ($_FILES['profile_image']['size'] > 2 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'La imagen no puede superar 2 MB.']);
            exit;
        }

        $image_info = @getimagesize($_FILES['profile_image']['tmp_name']);
        if (!$image_info || !in_array($image_info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            echo json_encode(['success' => false, 'error' => 'Formato de imagen no válido. Usa JPG, PNG, WEBP o GIF.']);
            exit;
        }

        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif'
        ];
        $upload_dir = dirname(__DIR__) . '/uploads/settings';
        if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
            echo json_encode(['success' => false, 'error' => 'No se pudo crear la carpeta de imágenes.']);
            exit;
        }

        $filename = 'profile_' . bin2hex(random_bytes(8)) . '.' . $extensions[$image_info['mime']];
        $destination = $upload_dir . '/' . $filename;
        if (!move_uploaded_file($_FILES['profile_image']['tmp_name'], $destination)) {
            echo json_encode(['success' => false, 'error' => 'No se pudo guardar la imagen.']);
            exit;
        }

        $uploaded_profile_image_path = 'uploads/settings/' . $filename;
    }

    if ($merchant_key !== '') {
        $stmt = $mysqli->prepare("
            UPDATE payment_settings
            SET app_name = ?, online_payment_enabled = ?, environment = ?, merchant_code = ?, merchant_key = ?, terminal = ?, appointment_price = ?, admin_notification_email = ?,
                appointment_reminder_enabled = ?, min_booking_notice_days = ?, max_booking_notice_days = ?,
                email_provider = ?, smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_secure = ?, smtp_from_email = ?, smtp_from_name = ?,
                google_client_id = ?, google_connected_email = ?, google_redirect_uri = ?, google_calendar_enabled = ?, google_calendar_id = ?
            WHERE id = 1
        ");
        bind_params_dynamic($stmt, "sissssdsiiississsssssis", [$app_name, $enabled, $environment, $merchant_code, $merchant_key, $terminal, $appointment_price, $admin_notification_email, $appointment_reminder_enabled, $min_booking_notice_days, $max_booking_notice_days, $email_provider, $smtp_host, $smtp_port, $smtp_username, $smtp_secure, $smtp_from_email, $smtp_from_name, $google_client_id, $google_connected_email, $google_redirect_uri, $google_calendar_enabled, $google_calendar_id]);
    } else {
        $stmt = $mysqli->prepare("
            UPDATE payment_settings
            SET app_name = ?, online_payment_enabled = ?, environment = ?, merchant_code = ?, terminal = ?, appointment_price = ?, admin_notification_email = ?,
                appointment_reminder_enabled = ?, min_booking_notice_days = ?, max_booking_notice_days = ?,
                email_provider = ?, smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_secure = ?, smtp_from_email = ?, smtp_from_name = ?,
                google_client_id = ?, google_connected_email = ?, google_redirect_uri = ?, google_calendar_enabled = ?, google_calendar_id = ?
            WHERE id = 1
        ");
        bind_params_dynamic($stmt, "sisssdsiiississsssssis", [$app_name, $enabled, $environment, $merchant_code, $terminal, $appointment_price, $admin_notification_email, $appointment_reminder_enabled, $min_booking_notice_days, $max_booking_notice_days, $email_provider, $smtp_host, $smtp_port, $smtp_username, $smtp_secure, $smtp_from_email, $smtp_from_name, $google_client_id, $google_connected_email, $google_redirect_uri, $google_calendar_enabled, $google_calendar_id]);
    }

    $stmt->execute();

    $break_start_db = $break_start_time === '' ? null : $break_start_time;
    $break_end_db = $break_end_time === '' ? null : $break_end_time;
    $stmt = $mysqli->prepare("
        UPDATE payment_settings
        SET appointment_start_time = ?, appointment_end_time = ?, break_start_time = ?, break_end_time = ?
        WHERE id = 1
    ");
    $stmt->bind_param("ssss", $appointment_start_time, $appointment_end_time, $break_start_db, $break_end_db);
    $stmt->execute();

    if ($uploaded_profile_image_path !== null) {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET profile_image_path = ?, show_profile_image_public = ? WHERE id = 1");
        $stmt->bind_param("si", $uploaded_profile_image_path, $show_profile_image_public);
        $stmt->execute();
    } else {
        $stmt = $mysqli->prepare("UPDATE payment_settings SET show_profile_image_public = ? WHERE id = 1");
        $stmt->bind_param("i", $show_profile_image_public);
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
