<?php
require_once 'db.php';
require_once 'payment_helpers.php';
require_once 'invoice_helpers.php';
require_once 'mail_helpers.php';
require_once 'settings_helpers.php';

$subject = 'Pago aceptado';
$message = 'El pago se ha completado correctamente.';
$detail = '';
$valid = false;
$branding = get_public_branding_settings($mysqli);
$tenant_id = current_tenant_id();

$token = $_GET['t'] ?? '';
$token = preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';

if ($token) {
    ensure_payment_attempts_table($mysqli);
    ensure_appointment_payment_columns($mysqli);
    ensure_appointment_services_tables($mysqli);
    ensure_bonus_tables($mysqli);

    $stmt = $mysqli->prepare("
        SELECT pa.id, pa.appointment_id, pa.user_id, pa.amount_cents, pa.payment_method, pa.status,
               COALESCE(pa.purchase_type, 'appointment') AS purchase_type, pa.bonus_id,
               a.appointment_date, a.appointment_time, a.consultation_type, a.service_type,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               s.name AS service_name,
               b.name AS bonus_name, b.session_count AS bonus_sessions, b.price AS bonus_price,
               u.name, u.email, u.phone
        FROM payment_attempts pa
        LEFT JOIN appointments a ON a.id = pa.appointment_id AND a.tenant_id = pa.tenant_id
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = pa.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = pa.tenant_id
        LEFT JOIN appointment_bonuses b ON b.id = pa.bonus_id AND b.tenant_id = pa.tenant_id
        JOIN users u ON u.id = pa.user_id AND u.tenant_id = pa.tenant_id
        WHERE pa.tenant_id = ? AND pa.token = ?
    ");
    $stmt->bind_param("is", $tenant_id, $token);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();

    if ($payment) {
        $valid = true;
        $mysqli->begin_transaction();

        try {
            $stmt = $mysqli->prepare("UPDATE payment_attempts SET status = 'OK' WHERE tenant_id = ? AND id = ?");
            $stmt->bind_param("ii", $tenant_id, $payment['id']);
            $stmt->execute();

            if (($payment['purchase_type'] ?? 'appointment') === 'bonus') {
                $already = $mysqli->prepare("SELECT id FROM patient_bonuses WHERE tenant_id = ? AND payment_attempt_id = ? LIMIT 1");
                $already->bind_param("ii", $tenant_id, $payment['id']);
                $already->execute();
                $existing_bonus = $already->get_result()->fetch_assoc();

                if (!$existing_bonus) {
                    $status = 'active';
                    $total_sessions = (int) $payment['bonus_sessions'];
                    $remaining_sessions = $total_sessions;
                    $stmt = $mysqli->prepare("
                        INSERT INTO patient_bonuses (tenant_id, user_id, bonus_id, total_sessions, remaining_sessions, status, purchased_at, payment_attempt_id)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                    ");
                    $stmt->bind_param("iiiiisi", $tenant_id, $payment['user_id'], $payment['bonus_id'], $total_sessions, $remaining_sessions, $status, $payment['id']);
                    $stmt->execute();
                }
            } else {
                $stmt = $mysqli->prepare("
                    UPDATE appointments
                    SET payment_status = 'paid', payment_method = ?, paid_at = NOW(), payment_attempt_id = ?
                    WHERE tenant_id = ? AND id = ?
                ");
                $stmt->bind_param("siii", $payment['payment_method'], $payment['id'], $tenant_id, $payment['appointment_id']);
                $stmt->execute();
            }

            $invoice_result = invoice_emit_for_payment_attempt($mysqli, (int) $payment['id']);
            if (empty($invoice_result['success'])) {
                error_log('No se pudo emitir factura automatica para el pago ' . $payment['id'] . ': ' . ($invoice_result['error'] ?? 'error desconocido'));
            }

            $mysqli->commit();
        } catch (\Exception $e) {
            $mysqli->rollback();
            $valid = false;
            $subject = 'Pago aceptado';
            $message = 'El pago ha sido aceptado, pero no se pudo actualizar la informacion. Contacta con la consulta.';
        }

        if ($valid) {
            $method = $payment['payment_method'] === 'bizum' ? 'Bizum' : 'tarjeta';
            $amount = format_payment_amount($payment['amount_cents']);

            if (($payment['purchase_type'] ?? 'appointment') === 'bonus') {
                $bonus_name = $payment['bonus_name'] ?: 'Bono';
                $bonus_sessions = (int) $payment['bonus_sessions'];
                $detail = htmlspecialchars($bonus_name) . " de " . htmlspecialchars($payment['name']) . ". Sesiones: $bonus_sessions. Importe: $amount EUR. Metodo: $method.";

                $admin_sent = notify_admin(
                    $mysqli,
                    'Bono comprado',
                    '<p>Se ha comprado un bono online.</p>' .
                    '<p><b>Paciente:</b> ' . htmlspecialchars($payment['name']) . '<br>' .
                    '<b>Bono:</b> ' . htmlspecialchars($bonus_name) . '<br>' .
                    '<b>Sesiones:</b> ' . $bonus_sessions . '<br>' .
                    '<b>Importe:</b> ' . htmlspecialchars($amount) . ' &euro;<br>' .
                    '<b>Metodo:</b> ' . htmlspecialchars($method) . '</p>',
                    $payment['email'] ?? null
                );
                if (!$admin_sent) {
                    error_log('No se pudo enviar email al admin por compra de bono. Payment attempt: ' . $payment['id']);
                }

                if (!empty($payment['email'])) {
                    $patient_sent = send_app_email(
                        $payment['email'],
                        'Compra de bono confirmada',
                        '<p>Hola ' . htmlspecialchars($payment['name']) . ',</p>' .
                        '<p>Hemos recibido correctamente el pago de tu bono.</p>' .
                        '<p><b>Bono:</b> ' . htmlspecialchars($bonus_name) . '<br>' .
                        '<b>Sesiones incluidas:</b> ' . $bonus_sessions . '<br>' .
                        '<b>Importe:</b> ' . htmlspecialchars($amount) . ' &euro;<br>' .
                        '<b>Metodo:</b> ' . htmlspecialchars($method) . '</p>' .
                        '<p>Podras reservar tus citas usando el bono desde la web mientras tengas sesiones disponibles.</p>',
                        null,
                        $mysqli
                    );
                    if (!$patient_sent) {
                        error_log('No se pudo enviar email al paciente por compra de bono. Payment attempt: ' . $payment['id'] . ' Email: ' . $payment['email']);
                    }
                }
            } else {
                $date = date('d/m/Y', strtotime($payment['appointment_date']));
                $time = date('H:i', strtotime($payment['appointment_time']));
                $consultation_text = appointment_consultation_label($payment['consultation_type'] ?? 'presencial');
                $service_text = appointment_service_option_label($payment);
                $detail = "Cita " . htmlspecialchars(strtolower($service_text)) . " " . htmlspecialchars(strtolower($consultation_text)) . " de " . htmlspecialchars($payment['name']) . " el $date a las $time. Importe: $amount EUR. Metodo: $method.";

                notify_admin(
                    $mysqli,
                    'Pago recibido',
                    '<p>Se ha recibido un pago online.</p>' .
                    '<p><b>Paciente:</b> ' . htmlspecialchars($payment['name']) . '<br>' .
                    '<b>Cita:</b> ' . htmlspecialchars("$date a las $time") . '<br>' .
                    '<b>Servicio:</b> ' . htmlspecialchars($service_text) . '<br>' .
                    '<b>Modalidad:</b> ' . htmlspecialchars($consultation_text) . '<br>' .
                    '<b>Importe:</b> ' . htmlspecialchars($amount) . ' &euro;<br>' .
                    '<b>Metodo:</b> ' . htmlspecialchars($method) . '</p>',
                    $payment['email'] ?? null
                );

                if (!empty($payment['email'])) {
                    send_app_email(
                        $payment['email'],
                        'Pago de cita confirmado',
                        '<p>Hola ' . htmlspecialchars($payment['name']) . ',</p>' .
                        '<p>Hemos recibido correctamente el pago de tu cita ' . htmlspecialchars(strtolower($service_text)) . ' ' . htmlspecialchars(strtolower($consultation_text)) . ' del ' . htmlspecialchars("$date a las $time") . '.</p>' .
                        '<p><b>Importe:</b> ' . htmlspecialchars($amount) . ' &euro;<br>' .
                        '<b>Servicio:</b> ' . htmlspecialchars($service_text) . '<br>' .
                        '<b>Modalidad:</b> ' . htmlspecialchars($consultation_text) . '<br>' .
                        '<b>Metodo:</b> ' . htmlspecialchars($method) . '</p>',
                        null,
                        $mysqli
                    );
                }
            }
        }
    }
}

if (!$valid && !$detail) {
    $subject = 'Pago aceptado';
    $message = 'No hemos podido localizar la informacion asociada a este pago. Contacta con la consulta para confirmarlo.';
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($subject) ?> - <?= htmlspecialchars($branding['app_name']) ?></title>
    <?= favicon_link_tags($branding) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>:root { --primary-color: <?= htmlspecialchars($branding['primary_color']) ?>; }</style>
</head>

<body class="d-flex align-items-center justify-content-center" style="min-height: 100vh;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card p-4 text-center">
                    <h2 class="text-success mb-3"><?= htmlspecialchars($subject) ?></h2>
                    <p class="mb-2"><?= htmlspecialchars($message) ?></p>
                    <?php if ($detail): ?>
                        <p class="text-muted"><?= $detail ?></p>
                    <?php endif; ?>
                    <p class="text-muted mt-4 mb-0">Puedes cerrar esta ventana o volver al calendario.</p>
                    <div class="mt-4">
                        <a href="dashboard.php" class="btn btn-primary">Volver al calendario</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
