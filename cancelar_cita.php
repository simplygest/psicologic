<?php
require_once 'db.php';
require_once 'payment_helpers.php';
require_once 'mail_helpers.php';
require_once 'google_helpers.php';
require_once 'settings_helpers.php';

ensure_appointment_payment_columns($mysqli);
ensure_appointment_services_tables($mysqli);
$branding = get_public_branding_settings($mysqli);

$token = $_GET['t'] ?? ($_POST['token'] ?? '');
$token = preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
$message = '';
$message_type = 'danger';
$appointment = null;
$cancelled = false;

if ($token) {
    $stmt = $mysqli->prepare("
        SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.consultation_type, a.service_type,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               so.price AS service_price, s.name AS service_name,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.payment_attempt_id, a.patient_bonus_id, a.user_id, u.name, u.email
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
        LEFT JOIN appointment_services s ON s.id = so.service_id
        JOIN users u ON u.id = a.user_id
        WHERE a.cancel_token = ?
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$appointment || $appointment['status'] !== 'booked') {
        $message = 'La cita no existe o ya no está activa.';
    } else {
        try {
            google_delete_calendar_event($mysqli, (int) $appointment['id']);
        } catch (\Exception $e) {
            error_log('No se pudo eliminar evento en Google Calendar: ' . $e->getMessage());
        }

        $stmt = $mysqli->prepare("DELETE FROM appointments WHERE id = ? AND cancel_token = ?");
        $stmt->bind_param("is", $appointment['id'], $token);
        $stmt->execute();

        $cancelled = $stmt->affected_rows > 0;
        $message_type = $cancelled ? 'success' : 'danger';
        $message = $cancelled ? 'Tu cita ha sido cancelada correctamente.' : 'No se pudo cancelar la cita.';
        if ($cancelled) {
            if (!empty($appointment['patient_bonus_id']) && ($appointment['payment_method'] ?? '') === 'bonus') {
                restore_patient_bonus_session($mysqli, (int) $appointment['patient_bonus_id']);
            }
            if (compensation_bonus_on_paid_cancel_enabled($mysqli)
                && ($appointment['payment_status'] ?? '') === 'paid'
                && in_array(($appointment['payment_method'] ?? ''), ['card', 'bizum'], true)
            ) {
                try {
                    create_compensation_bonus_for_user(
                        $mysqli,
                        (int) $appointment['user_id'],
                        !empty($appointment['payment_attempt_id']) ? (int) $appointment['payment_attempt_id'] : null
                    );
                    $appointment['compensation_bonus_created'] = 1;
                } catch (\Exception $e) {
                    error_log('No se pudo crear vale por cancelacion: ' . $e->getMessage());
                }
            }
            notify_appointment_cancelled($mysqli, $appointment);
        }
    }
}

$payment_settings = ['online_payment_enabled' => 0, 'appointment_price' => '70.00', 'online_appointment_price' => '70.00', 'couple_appointment_price' => '90.00', 'online_couple_appointment_price' => '90.00'];
$settings_res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
if ($settings_res->num_rows > 0) {
    ensure_payment_settings_price_columns($mysqli);
    $settings_res = $mysqli->query("SELECT online_payment_enabled, appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price FROM payment_settings WHERE id = 1");
    if ($settings = $settings_res->fetch_assoc()) {
        $payment_settings = $settings;
    }
}

function payment_status_label($appointment)
{
    if (!$appointment) {
        return '';
    }

    if ($appointment['payment_status'] === 'paid') {
        return 'Pagada';
    }

    if ($appointment['payment_status'] === 'failed') {
        return 'Pago fallido';
    }

    return 'Pendiente de pago';
}

function consultation_type_label($appointment)
{
    return ($appointment['consultation_type'] ?? 'presencial') === 'online' ? 'Online' : 'Presencial';
}

function service_type_label($appointment)
{
    return appointment_service_option_label($appointment);
}

function current_appointment_price($settings, $appointment)
{
    return appointment_price_for_row($settings, $appointment);
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Gestionar reserva - PsicoLogic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>:root { --primary-color: <?= htmlspecialchars($branding['primary_color']) ?>; }</style>
</head>

<body class="d-flex align-items-center justify-content-center" style="min-height: 100vh;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card p-4">
                    <h2 class="mb-3" style="color: var(--primary-color);">Gestionar reserva</h2>

                    <?php if ($message): ?>
                        <div class="alert alert-<?= htmlspecialchars($message_type) ?>"><?= htmlspecialchars($message) ?></div>
                    <?php endif; ?>

                    <?php if (!$token || !$appointment): ?>
                        <p class="text-muted mb-0">El enlace de gestión no es válido.</p>
                    <?php elseif (!$cancelled): ?>
                        <p class="mb-3">Revisa los datos de tu reserva.</p>
                        <ul class="list-group mb-4">
                            <li class="list-group-item"><b>Paciente:</b> <?= htmlspecialchars($appointment['name']) ?></li>
                            <li class="list-group-item"><b>Día:</b> <?= htmlspecialchars(date('d/m/Y', strtotime($appointment['appointment_date']))) ?></li>
                            <li class="list-group-item"><b>Hora:</b> <?= htmlspecialchars(date('H:i', strtotime($appointment['appointment_time']))) ?></li>
                            <li class="list-group-item"><b>Servicio:</b> <?= htmlspecialchars(service_type_label($appointment)) ?></li>
                            <li class="list-group-item"><b>Modalidad:</b> <?= htmlspecialchars(consultation_type_label($appointment)) ?></li>
                            <li class="list-group-item"><b>Importe:</b> <?= htmlspecialchars(format_appointment_price(current_appointment_price($payment_settings, $appointment))) ?> €</li>
                            <?php if ((int) $payment_settings['online_payment_enabled'] === 1): ?>
                                <li class="list-group-item"><b>Pago:</b> <?= htmlspecialchars(payment_status_label($appointment)) ?></li>
                            <?php endif; ?>
                        </ul>

                        <?php if ($appointment['status'] === 'booked'): ?>
                            <?php if ((int) $payment_settings['online_payment_enabled'] === 1 && $appointment['payment_status'] !== 'paid'): ?>
                                <div class="mb-4">
                                    <p class="text-muted mb-2">Puedes pagar ahora tu cita de <?= htmlspecialchars(format_appointment_price(current_appointment_price($payment_settings, $appointment))) ?> €.</p>
                                    <div class="d-grid gap-2">
                                        <button class="btn btn-success" type="button" id="btn-pay-card">Pagar con tarjeta</button>
                                        <button class="btn btn-success" type="button" id="btn-pay-bizum">Pagar con Bizum</button>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <form method="post" id="cancel-booking-form">
                                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                                <button type="submit" class="btn btn-danger w-100">Cancelar esta reserva</button>
                            </form>
                        <?php else: ?>
                            <p class="text-muted mb-0">Esta cita ya no está activa.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        const BOOKING_TOKEN = <?= json_encode($token) ?>;

        function setButtonLoading(button, text) {
            const $button = $(button);
            if (!$button.data('original-html')) {
                $button.data('original-html', $button.html());
            }
            $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>' + text);
        }

        function restoreButton(button) {
            const $button = $(button);
            $button.prop('disabled', false).html($button.data('original-html') || 'Aceptar');
            $button.removeData('original-html');
        }

        function startPayment(method, button) {
            setButtonLoading(button, 'Conectando...');
            $('#btn-pay-card, #btn-pay-bizum').prop('disabled', true);
            $.ajax({
                url: 'api/payments.php?action=create_redsys_form',
                method: 'POST',
                dataType: 'json',
                data: {
                    token: BOOKING_TOKEN,
                    payment_method: method
                },
                success: function (res) {
                    if (!res.success) {
                        alert(res.error || 'No se pudo iniciar el pago.');
                        $('#btn-pay-card, #btn-pay-bizum').prop('disabled', false);
                        restoreButton(button);
                        return;
                    }

                    $('#redsys-payment-form').remove();
                    $('body').append(res.form_html);
                    $('#redsys-payment-form').trigger('submit');
                },
                error: function () {
                    alert('No se pudo conectar con Redsys.');
                    $('#btn-pay-card, #btn-pay-bizum').prop('disabled', false);
                    restoreButton(button);
                }
            });
        }

        $('#btn-pay-card').on('click', function () {
            startPayment('card', this);
        });

        $('#btn-pay-bizum').on('click', function () {
            startPayment('bizum', this);
        });

        $('#cancel-booking-form').on('submit', function () {
            setButtonLoading($(this).find('button[type="submit"]'), 'Cancelando...');
        });
    </script>
</body>

</html>
