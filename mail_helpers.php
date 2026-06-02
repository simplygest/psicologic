<?php

function ensure_admin_notification_email_column($mysqli)
{
    $res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if ($res->num_rows === 0) {
        $mysqli->query("
            CREATE TABLE IF NOT EXISTS payment_settings (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                app_name VARCHAR(255) DEFAULT 'PsicoLogic',
                online_payment_enabled TINYINT(1) NOT NULL DEFAULT 0,
                environment ENUM('sandbox', 'real') NOT NULL DEFAULT 'sandbox',
                merchant_code VARCHAR(32) DEFAULT NULL,
                merchant_key VARCHAR(255) DEFAULT NULL,
                terminal VARCHAR(8) DEFAULT NULL,
                appointment_price DECIMAL(10,2) NOT NULL DEFAULT 70.00,
                admin_notification_email VARCHAR(255) DEFAULT NULL,
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
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    $columns = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'admin_notification_email'");
    if ($columns->num_rows === 0) {
        $mysqli->query("ALTER TABLE payment_settings ADD admin_notification_email VARCHAR(255) DEFAULT NULL AFTER appointment_price");
    }

    $columns = [
        'app_name' => "ALTER TABLE payment_settings ADD app_name VARCHAR(255) DEFAULT 'PsicoLogic' AFTER id",
        'email_provider' => "ALTER TABLE payment_settings ADD email_provider ENUM('phpmailer', 'google') NOT NULL DEFAULT 'phpmailer' AFTER admin_notification_email",
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
        'google_calendar_id' => "ALTER TABLE payment_settings ADD google_calendar_id VARCHAR(255) DEFAULT 'primary' AFTER google_calendar_enabled"
    ];

    foreach ($columns as $column => $sql) {
        $res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE '$column'");
        if ($res->num_rows === 0) {
            $mysqli->query($sql);
        }
    }

    $mysqli->query("
        INSERT IGNORE INTO payment_settings
            (id, online_payment_enabled, environment, appointment_price)
        VALUES
            (1, 0, 'sandbox', 70.00)
    ");
}

function get_admin_notification_email($mysqli)
{
    ensure_admin_notification_email_column($mysqli);

    $res = $mysqli->query("SELECT admin_notification_email FROM payment_settings WHERE id = 1");
    $row = $res->fetch_assoc();
    return trim($row['admin_notification_email'] ?? '');
}

function get_email_settings($mysqli)
{
    ensure_admin_notification_email_column($mysqli);

    $res = $mysqli->query("
        SELECT app_name, email_provider, smtp_host, smtp_port, smtp_username, smtp_password, smtp_secure,
               smtp_from_email, smtp_from_name, google_connected_email
        FROM payment_settings
        WHERE id = 1
    ");
    return $res->fetch_assoc() ?: [];
}

function get_app_name($mysqli)
{
    ensure_admin_notification_email_column($mysqli);
    $res = $mysqli->query("SELECT app_name FROM payment_settings WHERE id = 1");
    $row = $res->fetch_assoc();
    return trim($row['app_name'] ?? '') ?: 'PsicoLogic';
}

function load_phpmailer()
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }

    if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        $base = __DIR__ . '/phpmailer/src';
        if (file_exists($base . '/PHPMailer.php')) {
            require_once $base . '/Exception.php';
            require_once $base . '/PHPMailer.php';
            require_once $base . '/SMTP.php';
        }
    }

    if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        $base = __DIR__ . '/phpmailer';
        if (file_exists($base . '/PHPMailer.php')) {
            if (file_exists($base . '/Exception.php')) {
                require_once $base . '/Exception.php';
            }
            require_once $base . '/PHPMailer.php';
            if (file_exists($base . '/SMTP.php')) {
                require_once $base . '/SMTP.php';
            }
        }
    }

    return class_exists('\PHPMailer\PHPMailer\PHPMailer');
}

function default_from_email()
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = preg_replace('/:\d+$/', '', $host);
    return 'no-reply@' . $host;
}

function send_app_email($to, $subject, $html_body, $reply_to = null, $mysqli = null)
{
    $to = trim((string) $to);
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $settings = $mysqli ? get_email_settings($mysqli) : [];
    $provider = $settings['email_provider'] ?? 'phpmailer';

    if ($provider === 'google') {
        require_once __DIR__ . '/google_helpers.php';
        try {
            return google_send_email($mysqli, $to, $subject, $html_body, $reply_to);
        } catch (\Exception $e) {
            error_log('Error enviando email con Gmail API: ' . $e->getMessage());
            return false;
        }
    }

    if (!load_phpmailer()) {
        error_log('PHPMailer no está instalado. No se pudo enviar: ' . $subject);
        return false;
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->CharSet = 'UTF-8';
        if (!empty($settings['smtp_host'])) {
            $mail->isSMTP();
            $mail->Host = $settings['smtp_host'];
            $mail->Port = (int) ($settings['smtp_port'] ?? 587);
            $mail->SMTPAuth = !empty($settings['smtp_username']);
            $mail->Username = $settings['smtp_username'] ?? '';
            $mail->Password = $settings['smtp_password'] ?? '';

            if (($settings['smtp_secure'] ?? 'tls') === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } elseif (($settings['smtp_secure'] ?? 'tls') === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            }
        } else {
            $mail->isMail();
        }

        $from_email = $settings['smtp_from_email'] ?: default_from_email();
        $from_name = $settings['smtp_from_name'] ?: ($settings['app_name'] ?? 'PsicoLogic');
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($to);

        if ($reply_to && filter_var($reply_to, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($reply_to);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html_body;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html_body)));

        return $mail->send();
    } catch (\Exception $e) {
        error_log('Error enviando email: ' . $mail->ErrorInfo);
        return false;
    }
}

function notify_admin($mysqli, $subject, $html_body, $reply_to = null)
{
    $admin_email = get_admin_notification_email($mysqli);
    if (!$admin_email) {
        return false;
    }

    return send_app_email($admin_email, $subject, $html_body, $reply_to, $mysqli);
}

function appointment_label($date, $time)
{
    return date('d/m/Y', strtotime($date)) . ' a las ' . date('H:i', strtotime($time));
}

function appointment_consultation_label($consultation_type)
{
    return $consultation_type === 'online' ? 'Online' : 'Presencial';
}

function appointment_payment_label($payment_status)
{
    if ($payment_status === 'paid') {
        return 'Pagada';
    }

    if ($payment_status === 'failed') {
        return 'Pago fallido';
    }

    return 'No pagada online';
}

function appointment_cancel_payment_label($appointment)
{
    if (($appointment['payment_method'] ?? '') === 'bonus' && !empty($appointment['patient_bonus_id'])) {
        return 'Pagada con bono';
    }

    return appointment_payment_label($appointment['payment_status'] ?? 'pending');
}

function notify_appointment_cancelled($mysqli, $appointment)
{
    if (!$appointment) {
        return;
    }

    $patient_name = $appointment['name'] ?? '';
    $patient_email = $appointment['email'] ?? '';
    $appointment_text = appointment_label($appointment['appointment_date'], $appointment['appointment_time']);
    $consultation_text = appointment_consultation_label($appointment['consultation_type'] ?? 'presencial');
    $service_text = function_exists('appointment_service_option_label')
        ? appointment_service_option_label($appointment)
        : appointment_service_label($appointment['service_type'] ?? 'individual');
    $payment_status = $appointment['payment_status'] ?? 'pending';
    $is_bonus_payment = ($appointment['payment_method'] ?? '') === 'bonus' && !empty($appointment['patient_bonus_id']);
    $compensation_bonus_created = !empty($appointment['compensation_bonus_created']);
    $payment_text = appointment_cancel_payment_label($appointment);
    $paid_warning = $payment_status === 'paid'
        ? '<p><b>Atención:</b> esta cita constaba como pagada. Revisa si corresponde hacer devolución o contactar con el paciente.</p>'
        : '';
    if ($is_bonus_payment) {
        $paid_warning = '<p>Esta cita fue reservada con bono. El paciente volver&aacute; a tener una cita disponible en su bono.</p>';
    } elseif ($compensation_bonus_created) {
        $paid_warning = '<p>Se ha creado un vale de 1 sesi&oacute;n para el paciente. Podr&aacute; usarlo para reservar otra cita desde la web.</p>';
    }
    $patient_payment_note = $is_bonus_payment
        ? '<p><b>Estado del pago:</b> pagada con bono</p>'
        : '';
    if ($compensation_bonus_created) {
        $patient_payment_note = '<p><b>Compensaci&oacute;n:</b> hemos generado un vale de 1 sesi&oacute;n para que puedas reservar otra cita desde la web.</p>';
    }

    notify_admin(
        $mysqli,
        'Cita cancelada',
        '<p>Se ha cancelado una cita.</p>' .
        '<p><b>Paciente:</b> ' . htmlspecialchars($patient_name) . '<br>' .
        '<b>Fecha:</b> ' . htmlspecialchars($appointment_text) . '<br>' .
        '<b>Servicio:</b> ' . htmlspecialchars($service_text) . '<br>' .
        '<b>Modalidad:</b> ' . htmlspecialchars($consultation_text) . '<br>' .
        '<b>Estado del pago:</b> ' . htmlspecialchars($payment_text) . '</p>' .
        $paid_warning,
        $patient_email ?: null
    );

    if ($patient_email) {
        send_app_email(
            $patient_email,
            'Cita cancelada',
            '<p>Hola ' . htmlspecialchars($patient_name) . ',</p>' .
            '<p>Tu cita ' . htmlspecialchars(strtolower($service_text)) . ' ' . htmlspecialchars(strtolower($consultation_text)) . ' para el ' . htmlspecialchars($appointment_text) . ' ha sido cancelada correctamente.</p>' .
            '<p><b>Servicio:</b> ' . htmlspecialchars($service_text) . '</p>' .
            '<p><b>Modalidad:</b> ' . htmlspecialchars($consultation_text) . '</p>' .
            $patient_payment_note,
            null,
            $mysqli
        );
    }
}
