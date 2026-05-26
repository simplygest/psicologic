<?php
require_once 'db.php';
require_once 'payment_helpers.php';
require_once 'mail_helpers.php';
require_once 'settings_helpers.php';

$subject = 'Error en el proceso de pago';
$message = 'Ha ocurrido un problema durante el proceso de pago, o bien no se completó satisfactoriamente.';
$detail = '';
$branding = get_public_branding_settings($mysqli);

$token = $_GET['t'] ?? '';
$token = preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';

if ($token) {
    ensure_payment_attempts_table($mysqli);
    ensure_appointment_payment_columns($mysqli);

    $stmt = $mysqli->prepare("
        SELECT pa.id, pa.appointment_id, pa.amount_cents, pa.payment_method,
               a.appointment_date, a.appointment_time, a.consultation_type, u.name, u.email, u.phone
        FROM payment_attempts pa
        JOIN appointments a ON a.id = pa.appointment_id
        JOIN users u ON u.id = pa.user_id
        WHERE pa.token = ?
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();

    if ($payment) {
        $mysqli->begin_transaction();

        try {
            $stmt = $mysqli->prepare("UPDATE payment_attempts SET status = 'Error' WHERE id = ?");
            $stmt->bind_param("i", $payment['id']);
            $stmt->execute();

            $stmt = $mysqli->prepare("
                UPDATE appointments
                SET payment_status = 'failed', payment_attempt_id = ?
                WHERE id = ? AND payment_status != 'paid'
            ");
            $stmt->bind_param("ii", $payment['id'], $payment['appointment_id']);
            $stmt->execute();

            $mysqli->commit();

            $amount = format_payment_amount($payment['amount_cents']);
            $date = date('d/m/Y', strtotime($payment['appointment_date']));
            $time = date('H:i', strtotime($payment['appointment_time']));
            $consultation_text = appointment_consultation_label($payment['consultation_type'] ?? 'presencial');
            $detail = "La cita " . strtolower($consultation_text) . " del $date a las $time sigue reservada, pero el pago de $amount € no se ha completado.";

            notify_admin(
                $mysqli,
                'Pago no completado',
                '<p>Un pago online no se ha completado correctamente.</p>' .
                '<p><b>Paciente:</b> ' . htmlspecialchars($payment['name']) . '<br>' .
                '<b>Cita:</b> ' . htmlspecialchars("$date a las $time") . '<br>' .
                '<b>Modalidad:</b> ' . htmlspecialchars($consultation_text) . '<br>' .
                '<b>Importe:</b> ' . htmlspecialchars($amount) . ' €<br>' .
                '<b>Método:</b> ' . htmlspecialchars($payment['payment_method'] === 'bizum' ? 'Bizum' : 'tarjeta') . '</p>',
                $payment['email'] ?? null
            );

            if (!empty($payment['email'])) {
                send_app_email(
                    $payment['email'],
                    'Pago de cita no completado',
                    '<p>Hola ' . htmlspecialchars($payment['name']) . ',</p>' .
                    '<p>El pago online de tu cita ' . htmlspecialchars(strtolower($consultation_text)) . ' del ' . htmlspecialchars("$date a las $time") . ' no se ha completado correctamente.</p>' .
                    '<p><b>Modalidad:</b> ' . htmlspecialchars($consultation_text) . '</p>' .
                    '<p>La cita sigue reservada. Puedes contactar con la consulta si necesitas ayuda.</p>',
                    null,
                    $mysqli
                );
            }
        } catch (\Exception $e) {
            $mysqli->rollback();
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
    <title><?= htmlspecialchars($subject) ?> - Psicología Minimal</title>
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
                    <h2 class="text-danger mb-3"><?= htmlspecialchars($subject) ?></h2>
                    <p class="mb-2"><?= htmlspecialchars($message) ?></p>
                    <?php if ($detail): ?>
                        <p class="text-muted"><?= htmlspecialchars($detail) ?></p>
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
