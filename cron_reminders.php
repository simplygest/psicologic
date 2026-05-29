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

ensure_appointment_payment_columns($mysqli);

$settings_table = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
if (!$settings_table || $settings_table->num_rows === 0) {
    echo json_encode(['success' => true, 'enabled' => false, 'sent' => 0, 'message' => 'No hay configuracion de recordatorios']);
    exit;
}

$column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'appointment_reminder_enabled'");
if ($column_res && $column_res->num_rows === 0) {
    $mysqli->query("ALTER TABLE payment_settings ADD appointment_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER admin_notification_email");
}
ensure_payment_settings_price_columns($mysqli);

$settings_res = $mysqli->query("
    SELECT appointment_reminder_enabled, online_payment_enabled, appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price
    FROM payment_settings
    WHERE id = 1
");
$settings = $settings_res->fetch_assoc() ?: [];

if ((int) ($settings['appointment_reminder_enabled'] ?? 0) !== 1) {
    echo json_encode(['success' => true, 'enabled' => false, 'sent' => 0]);
    exit;
}

$stmt = $mysqli->prepare("
    SELECT a.id, a.appointment_date, a.appointment_time, a.consultation_type, a.service_type, a.cancel_token,
           COALESCE(a.payment_status, 'pending') AS payment_status,
           u.name, u.email
    FROM appointments a
    JOIN users u ON u.id = a.user_id
    WHERE a.status = 'booked'
      AND a.reminder_sent_at IS NULL
      AND u.email IS NOT NULL
      AND u.email != ''
      AND TIMESTAMP(a.appointment_date, a.appointment_time) BETWEEN DATE_ADD(NOW(), INTERVAL 23 HOUR) AND DATE_ADD(NOW(), INTERVAL 25 HOUR)
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
");
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$sent = 0;
$failed = 0;
$base_url = app_public_base_url();
$payment_enabled = (int) ($settings['online_payment_enabled'] ?? 0) === 1;

foreach ($appointments as $appointment) {
    $cancel_token = $appointment['cancel_token'];
    if (!$cancel_token) {
        $cancel_token = bin2hex(random_bytes(32));
        $update_token = $mysqli->prepare("UPDATE appointments SET cancel_token = ? WHERE id = ?");
        $update_token->bind_param("si", $cancel_token, $appointment['id']);
        $update_token->execute();
    }

    $manage_link = $base_url . 'cancelar_cita.php?t=' . urlencode($cancel_token);
    $date = date('d/m/Y', strtotime($appointment['appointment_date']));
    $time = date('H:i', strtotime($appointment['appointment_time']));
    $consultation_text = appointment_consultation_label($appointment['consultation_type'] ?? 'presencial');
    $service_text = appointment_service_label($appointment['service_type'] ?? 'individual');
    $price = format_appointment_price(appointment_price_for_type($settings, $appointment['consultation_type'] ?? 'presencial', $appointment['service_type'] ?? 'individual'));
    $payment_note = '';

    if ($payment_enabled && $appointment['payment_status'] !== 'paid') {
        $payment_note = '<p>Si no has hecho aun el pago, puedes realizar el pago con tarjeta o Bizum desde el mismo enlace.</p>';
    }

    $body =
        '<p>Hola ' . htmlspecialchars($appointment['name']) . ',</p>' .
        '<p>Recuerda que tienes cita ' . htmlspecialchars(strtolower($service_text)) . ' ' . htmlspecialchars(strtolower($consultation_text)) . ' para el dia ' . htmlspecialchars($date) . ' a las ' . htmlspecialchars($time) . '.</p>' .
        '<p><b>Servicio:</b> ' . htmlspecialchars($service_text) . '</p>' .
        '<p><b>Modalidad:</b> ' . htmlspecialchars($consultation_text) . '</p>' .
        '<p><b>Importe:</b> ' . htmlspecialchars($price) . ' &euro;</p>' .
        '<p>Por favor, si no puedes acudir, puedes cancelar la cita en el siguiente enlace:</p>' .
        '<p><a href="' . htmlspecialchars($manage_link) . '">Gestionar reserva</a></p>' .
        $payment_note;

    if (send_app_email($appointment['email'], 'Recordatorio de cita', $body, null, $mysqli)) {
        $update_sent = $mysqli->prepare("UPDATE appointments SET reminder_sent_at = NOW() WHERE id = ?");
        $update_sent->bind_param("i", $appointment['id']);
        $update_sent->execute();
        $sent++;
    } else {
        $failed++;
    }
}

echo json_encode([
    'success' => true,
    'enabled' => true,
    'checked' => count($appointments),
    'sent' => $sent,
    'failed' => $failed
]);
