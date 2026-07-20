<?php
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
if (!defined('CRON_WEBHOOK_TOKEN') || !hash_equals(CRON_WEBHOOK_TOKEN, (string) $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token no valido']);
    exit;
}

require_once 'db.php';
require_once 'payment_helpers.php';
require_once 'mail_helpers.php';
require_once 'sms_helpers.php';
require_once 'message_template_helpers.php';
require_once 'urlme_helpers.php';
require_once 'dashboard_config_helpers.php';
require_once 'app_log_helpers.php';

ensure_appointment_payment_columns($mysqli);
ensure_appointment_services_tables($mysqli);
$tenant_id = current_tenant_id();

$settings_table = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
if (!$settings_table || $settings_table->num_rows === 0) {
    echo json_encode(['success' => true, 'enabled' => false, 'sent' => 0, 'message' => 'No hay configuracion de recordatorios']);
    exit;
}

$column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'appointment_reminder_enabled'");
if ($column_res && $column_res->num_rows === 0) {
    $mysqli->query("ALTER TABLE payment_settings ADD appointment_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER admin_notification_email");
}
$column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'appointment_second_reminder_enabled'");
if ($column_res && $column_res->num_rows === 0) {
    $mysqli->query("ALTER TABLE payment_settings ADD appointment_second_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER appointment_reminder_enabled");
}
$column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'appointment_second_reminder_hours'");
if ($column_res && $column_res->num_rows === 0) {
    $mysqli->query("ALTER TABLE payment_settings ADD appointment_second_reminder_hours SMALLINT UNSIGNED NOT NULL DEFAULT 48 AFTER appointment_second_reminder_enabled");
}
$column_res = $mysqli->query("SHOW COLUMNS FROM appointments LIKE 'second_reminder_sent_at'");
if ($column_res && $column_res->num_rows === 0) {
    $mysqli->query("ALTER TABLE appointments ADD second_reminder_sent_at DATETIME DEFAULT NULL");
}
$column_res = $mysqli->query("SHOW COLUMNS FROM appointments LIKE 'sms_reminder_sent_at'");
if ($column_res && $column_res->num_rows === 0) {
    $mysqli->query("ALTER TABLE appointments ADD sms_reminder_sent_at DATETIME DEFAULT NULL AFTER second_reminder_sent_at");
}
$sms_columns = [
    'sms_provider' => "ALTER TABLE payment_settings ADD sms_provider VARCHAR(20) NOT NULL DEFAULT 'none' AFTER appointment_second_reminder_hours",
    'sms_sender' => "ALTER TABLE payment_settings ADD sms_sender VARCHAR(40) DEFAULT NULL AFTER sms_provider",
    'sms_username' => "ALTER TABLE payment_settings ADD sms_username VARCHAR(120) DEFAULT NULL AFTER sms_sender",
    'sms_password' => "ALTER TABLE payment_settings ADD sms_password VARCHAR(255) DEFAULT NULL AFTER sms_username",
    'sms_api_key' => "ALTER TABLE payment_settings ADD sms_api_key VARCHAR(255) DEFAULT NULL AFTER sms_password",
    'sms_reminder_enabled' => "ALTER TABLE payment_settings ADD sms_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER sms_api_key",
    'sms_reminder_hours' => "ALTER TABLE payment_settings ADD sms_reminder_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24 AFTER sms_reminder_enabled"
];
foreach ($sms_columns as $column => $sql) {
    $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE '$column'");
    if ($column_res && $column_res->num_rows === 0) {
        $mysqli->query($sql);
    }
}
ensure_payment_settings_price_columns($mysqli);

$settings_res = $mysqli->query("
    SELECT app_name, site_phone, appointment_reminder_enabled, appointment_second_reminder_enabled, appointment_second_reminder_hours,
           online_payment_enabled, appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price,
           sms_provider, sms_sender, sms_username, sms_password, sms_api_key, sms_reminder_enabled, sms_reminder_hours
    FROM payment_settings
    WHERE tenant_id = $tenant_id
");
$settings = $settings_res->fetch_assoc() ?: [];

$first_reminder_enabled = (int) ($settings['appointment_reminder_enabled'] ?? 0) === 1;
$second_reminder_enabled = (int) ($settings['appointment_second_reminder_enabled'] ?? 0) === 1;
$second_reminder_hours = max(1, min(168, (int) ($settings['appointment_second_reminder_hours'] ?? 48)));
if ($second_reminder_hours === 24) {
    $second_reminder_enabled = false;
}
$sms_reminder_enabled = (int) ($settings['sms_reminder_enabled'] ?? 0) === 1;
$sms_reminder_hours = max(1, min(168, (int) ($settings['sms_reminder_hours'] ?? 24)));

if (!$first_reminder_enabled && !$second_reminder_enabled && !$sms_reminder_enabled) {
    echo json_encode(['success' => true, 'enabled' => false, 'sent' => 0]);
    exit;
}

if (!app_feature_enabled_from_db($mysqli, 'reminders.patient24h', false)) {
    echo json_encode(['success' => true, 'enabled' => false, 'sent' => 0, 'message' => 'Recordatorios no disponibles en este plan']);
    exit;
}

function reminder_first_name($name)
{
    $first_name = message_template_first_name($name);
    if ($first_name === '') {
        return 'tu profesional';
    }
    return $first_name;
}

function reminder_manage_link($mysqli, $tenant_id, array $appointment)
{
    $cancel_token = $appointment['cancel_token'] ?? '';
    if (!$cancel_token) {
        $cancel_token = bin2hex(random_bytes(32));
        $update_token = $mysqli->prepare("UPDATE appointments SET cancel_token = ? WHERE tenant_id = ? AND id = ?");
        $update_token->bind_param("sii", $cancel_token, $tenant_id, $appointment['id']);
        $update_token->execute();
    }

    $base_url = app_public_base_url();
    return urlme_shorten_url($base_url . 'cancelar_cita.php?t=' . urlencode($cancel_token), 'Recordatorio cita SimplyGest Praxis');
}

function reminder_email_body_to_html($body)
{
    $body = (string) $body;
    if ($body !== strip_tags($body)) {
        return $body;
    }
    return nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
}

function send_appointment_reminders_for_window($mysqli, $tenant_id, $settings, $hours_before, $sent_column)
{
    $sent_column = $sent_column === 'second_reminder_sent_at' ? 'second_reminder_sent_at' : 'reminder_sent_at';
    $hours_before = max(1, min(168, (int) $hours_before));
    $window_start = max(0, $hours_before - 1);
    $window_end = $hours_before + 1;

    $template = message_template_get($mysqli, 'appointment_email_reminder');
    $stmt = $mysqli->prepare("
        SELECT a.id, a.appointment_date, a.appointment_time, a.consultation_type, a.service_type, a.cancel_token, a.online_session_url,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               so.price AS service_price, s.name AS service_name,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               u.name, u.email,
               COALESCE(p.display_name, '') AS professional_name
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        LEFT JOIN professionals p ON p.id = a.professional_id AND p.tenant_id = a.tenant_id
        JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
        WHERE a.tenant_id = ?
          AND a.status = 'booked'
          AND a.$sent_column IS NULL
          AND u.email IS NOT NULL
          AND u.email != ''
          AND TIMESTAMP(a.appointment_date, a.appointment_time) BETWEEN DATE_ADD(NOW(), INTERVAL $window_start HOUR) AND DATE_ADD(NOW(), INTERVAL $window_end HOUR)
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
    ");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $sent = 0;
    $failed = 0;
    $base_url = app_public_base_url();
    $payment_enabled = (int) ($settings['online_payment_enabled'] ?? 0) === 1;

    foreach ($appointments as $appointment) {
        $manage_link = reminder_manage_link($mysqli, $tenant_id, $appointment);
        $date = date('d/m/Y', strtotime($appointment['appointment_date']));
        $date_short = date('d/m', strtotime($appointment['appointment_date']));
        $time = date('H:i', strtotime($appointment['appointment_time']));
        $end_time = date('H:i', strtotime($appointment['appointment_time'] . ' +' . (int) ($appointment['duration_minutes'] ?? 60) . ' minutes'));
        $consultation_text = appointment_consultation_label($appointment['consultation_type'] ?? 'presencial');
        $service_text = appointment_service_option_label($appointment);
        $price = format_appointment_price(appointment_price_for_row($settings, $appointment));
        $payment_note = '';

        if ($payment_enabled && $appointment['payment_status'] !== 'paid') {
            $payment_note = '<p>Si no has hecho aun el pago, puedes realizar el pago con tarjeta o Bizum desde el mismo enlace.</p>';
        }
        $online_link_note = '';
        if (($appointment['consultation_type'] ?? '') === 'online' && !empty($appointment['online_session_url'])) {
            $online_link_note =
                '<p><b>Enlace de videollamada:</b><br>' .
                '<a href="' . htmlspecialchars($appointment['online_session_url']) . '">Acceder a la cita online</a></p>' .
                '<p>Si el boton no funciona, copia y pega este enlace en tu navegador:<br>' . htmlspecialchars($appointment['online_session_url']) . '</p>';
        }
        $vars = [
            'nombre' => message_template_first_name($appointment['name'] ?? '') ?: ($appointment['name'] ?? ''),
            'nombre_completo' => $appointment['name'] ?? '',
            'profesional' => $appointment['professional_name'] ?? '',
            'profesional_nombre' => reminder_first_name($appointment['professional_name'] ?? ''),
            'fecha' => $date,
            'fecha_corta' => $date_short,
            'hora' => $time,
            'hora_fin' => $end_time,
            'duracion' => (string) (int) ($appointment['duration_minutes'] ?? 60),
            'modalidad' => $consultation_text,
            'servicio' => $service_text,
            'lugar' => '',
            'link' => $appointment['online_session_url'] ?? '',
            'enlace_gestion' => $manage_link,
            'nombre_centro' => $settings['app_name'] ?? '',
            'telefono_centro' => $settings['site_phone'] ?? '',
            'importe' => $price . ' EUR'
        ];
        $body = reminder_email_body_to_html(message_template_render($template['body'] ?? message_template_default_body('appointment_email_reminder'), $vars));
        $body .=
            '<p><b>Servicio:</b> ' . htmlspecialchars($service_text) . '</p>' .
            '<p><b>Modalidad:</b> ' . htmlspecialchars($consultation_text) . '</p>' .
            $online_link_note .
            '<p><b>Importe:</b> ' . htmlspecialchars($price) . ' &euro;</p>' .
            '<p><a href="' . htmlspecialchars($manage_link) . '">Gestionar/cancelar reserva</a></p>' .
            $payment_note;
        $subject = message_template_render($template['subject'] ?: message_template_default_subject('appointment_email_reminder'), $vars);

        if (send_app_email($appointment['email'], $subject, $body, null, $mysqli)) {
            $update_sent = $mysqli->prepare("UPDATE appointments SET $sent_column = NOW() WHERE tenant_id = ? AND id = ?");
            $update_sent->bind_param("ii", $tenant_id, $appointment['id']);
            $update_sent->execute();
            app_log($mysqli, [
                'tenant_id' => $tenant_id,
                'user_id' => null,
                'action' => 'automatic_appointment_reminder',
                'channel' => 'email',
                'status' => 'ok',
                'target_type' => 'appointment',
                'target_id' => (int) $appointment['id'],
                'title' => 'Recordatorio automatico de cita',
                'message' => 'Recordatorio automatico enviado por email a ' . ($appointment['email'] ?? '') . '.',
                'metadata' => [
                    'sent_column' => $sent_column,
                    'patient_name' => $appointment['name'] ?? '',
                    'appointment_date' => $appointment['appointment_date'] ?? '',
                    'appointment_time' => $appointment['appointment_time'] ?? ''
                ]
            ]);
            $sent++;
        } else {
            $email_error = function_exists('get_app_email_last_error') ? get_app_email_last_error() : '';
            $error_detail = $email_error !== '' ? ' Motivo: ' . $email_error : '';
            app_log($mysqli, [
                'tenant_id' => $tenant_id,
                'user_id' => null,
                'action' => 'automatic_appointment_reminder',
                'channel' => 'email',
                'status' => 'error',
                'target_type' => 'appointment',
                'target_id' => (int) $appointment['id'],
                'title' => 'Recordatorio automatico de cita',
                'message' => 'No se pudo enviar el recordatorio automatico por email a ' . ($appointment['email'] ?? '') . '.' . $error_detail,
                'metadata' => [
                    'sent_column' => $sent_column,
                    'error' => $email_error,
                    'patient_name' => $appointment['name'] ?? '',
                    'appointment_date' => $appointment['appointment_date'] ?? '',
                    'appointment_time' => $appointment['appointment_time'] ?? ''
                ]
            ]);
            $failed++;
        }
    }

    return ['checked' => count($appointments), 'sent' => $sent, 'failed' => $failed];
}

function send_sms_appointment_reminders_for_window($mysqli, $tenant_id, $settings, $hours_before)
{
    $hours_before = max(1, min(168, (int) $hours_before));
    $window_start = max(0, $hours_before - 1);
    $window_end = $hours_before + 1;

    $template = message_template_get($mysqli, 'appointment_sms_reminder');
    $stmt = $mysqli->prepare("
        SELECT a.id, a.appointment_date, a.appointment_time, a.cancel_token,
               COALESCE(a.duration_minutes, 60) AS duration_minutes,
               u.name, u.phone, COALESCE(p.display_name, '') AS professional_name
        FROM appointments a
        JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
        LEFT JOIN professionals p ON p.id = a.professional_id AND p.tenant_id = a.tenant_id
        WHERE a.tenant_id = ?
          AND a.status = 'booked'
          AND a.sms_reminder_sent_at IS NULL
          AND u.phone IS NOT NULL
          AND u.phone != ''
          AND TIMESTAMP(a.appointment_date, a.appointment_time) BETWEEN DATE_ADD(NOW(), INTERVAL $window_start HOUR) AND DATE_ADD(NOW(), INTERVAL $window_end HOUR)
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
    ");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $sent = 0;
    $failed = 0;

    foreach ($appointments as $appointment) {
        $manage_link = reminder_manage_link($mysqli, $tenant_id, $appointment);
        $date = date('d/m', strtotime($appointment['appointment_date']));
        $date_full = date('d/m/Y', strtotime($appointment['appointment_date']));
        $time = date('H:i', strtotime($appointment['appointment_time']));
        $end_time = date('H:i', strtotime($appointment['appointment_time'] . ' +' . (int) ($appointment['duration_minutes'] ?? 60) . ' minutes'));
        $professional_name = reminder_first_name($appointment['professional_name'] ?? '');
        $patient_sms_name = message_template_first_name($appointment['name'] ?? '') ?: ($appointment['name'] ?? '');
        $message = message_template_render($template['body'] ?? message_template_default_body('appointment_sms_reminder'), [
            'nombre' => $patient_sms_name,
            'nombre_completo' => $patient_sms_name,
            'profesional' => $appointment['professional_name'] ?? '',
            'profesional_nombre' => $professional_name,
            'fecha' => $date_full,
            'fecha_corta' => $date,
            'hora' => $time,
            'hora_fin' => $end_time,
            'duracion' => (string) (int) ($appointment['duration_minutes'] ?? 60),
            'modalidad' => '',
            'servicio' => '',
            'lugar' => '',
            'link' => '',
            'enlace_gestion' => $manage_link,
            'nombre_centro' => $settings['app_name'] ?? '',
            'telefono_centro' => $settings['site_phone'] ?? '',
            'importe' => ''
        ]);
        if (strpos($message, $manage_link) === false) {
            $message .= ' Gestionar/cancelar: ' . $manage_link;
        }

        try {
            $sms_result = sms_send_with_settings($settings, $appointment['phone'], $message, [
                'custom' => 'reminder-' . $tenant_id . '-' . (int) $appointment['id']
            ]);
        } catch (Throwable $e) {
            $sms_result = ['success' => false, 'error' => $e->getMessage()];
        }

        if (!empty($sms_result['success'])) {
            $update_sent = $mysqli->prepare("UPDATE appointments SET sms_reminder_sent_at = NOW() WHERE tenant_id = ? AND id = ?");
            $update_sent->bind_param("ii", $tenant_id, $appointment['id']);
            $update_sent->execute();
            app_log($mysqli, [
                'tenant_id' => $tenant_id,
                'user_id' => null,
                'action' => 'automatic_appointment_reminder',
                'channel' => 'sms',
                'status' => 'ok',
                'target_type' => 'appointment',
                'target_id' => (int) $appointment['id'],
                'title' => 'Recordatorio automatico de cita',
                'message' => 'Recordatorio automatico enviado por SMS a ' . ($appointment['phone'] ?? '') . '.',
                'metadata' => [
                    'provider' => $sms_result['provider'] ?? '',
                    'sms_id' => $sms_result['id'] ?? '',
                    'patient_name' => $appointment['name'] ?? '',
                    'appointment_date' => $appointment['appointment_date'] ?? '',
                    'appointment_time' => $appointment['appointment_time'] ?? ''
                ]
            ]);
            $sent++;
        } else {
            app_log($mysqli, [
                'tenant_id' => $tenant_id,
                'user_id' => null,
                'action' => 'automatic_appointment_reminder',
                'channel' => 'sms',
                'status' => 'error',
                'target_type' => 'appointment',
                'target_id' => (int) $appointment['id'],
                'title' => 'Recordatorio automatico de cita',
                'message' => 'No se pudo enviar el recordatorio automatico por SMS: ' . ($sms_result['error'] ?? 'Error desconocido.'),
                'metadata' => [
                    'provider' => $sms_result['provider'] ?? '',
                    'patient_name' => $appointment['name'] ?? '',
                    'appointment_date' => $appointment['appointment_date'] ?? '',
                    'appointment_time' => $appointment['appointment_time'] ?? ''
                ]
            ]);
            $failed++;
        }
    }

    return ['checked' => count($appointments), 'sent' => $sent, 'failed' => $failed];
}

$totals = ['checked' => 0, 'sent' => 0, 'failed' => 0];
$sms_totals = ['checked' => 0, 'sent' => 0, 'failed' => 0];
if ($first_reminder_enabled) {
    $result = send_appointment_reminders_for_window($mysqli, $tenant_id, $settings, 24, 'reminder_sent_at');
    $totals['checked'] += $result['checked'];
    $totals['sent'] += $result['sent'];
    $totals['failed'] += $result['failed'];
}
if ($second_reminder_enabled) {
    $result = send_appointment_reminders_for_window($mysqli, $tenant_id, $settings, $second_reminder_hours, 'second_reminder_sent_at');
    $totals['checked'] += $result['checked'];
    $totals['sent'] += $result['sent'];
    $totals['failed'] += $result['failed'];
}
if ($sms_reminder_enabled) {
    $result = send_sms_appointment_reminders_for_window($mysqli, $tenant_id, $settings, $sms_reminder_hours);
    $sms_totals['checked'] += $result['checked'];
    $sms_totals['sent'] += $result['sent'];
    $sms_totals['failed'] += $result['failed'];
}

echo json_encode([
    'success' => true,
    'enabled' => true,
    'checked' => $totals['checked'],
    'sent' => $totals['sent'],
    'failed' => $totals['failed'],
    'sms_checked' => $sms_totals['checked'],
    'sms_sent' => $sms_totals['sent'],
    'sms_failed' => $sms_totals['failed'],
    'second_reminder_hours' => $second_reminder_enabled ? $second_reminder_hours : null
]);
