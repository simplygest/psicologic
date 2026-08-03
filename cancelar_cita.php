<?php
require_once 'db.php';
require_once 'payment_helpers.php';
require_once 'mail_helpers.php';
require_once 'google_helpers.php';
require_once 'microsoft_helpers.php';
require_once 'caldav_helpers.php';
require_once 'settings_helpers.php';
require_once 'app_log_helpers.php';

ensure_appointment_payment_columns($mysqli);
ensure_appointment_services_tables($mysqli);
$branding = get_public_branding_settings($mysqli);
$tenant_id = current_tenant_id();

$token = $_GET['t'] ?? ($_POST['token'] ?? '');
$token = preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
$message = '';
$message_type = 'danger';
$appointment = null;
$cancelled = false;
$confirmed = false;

if ($token) {
    $stmt = $mysqli->prepare("
        SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.consultation_type, a.service_type,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               so.price AS service_price, s.name AS service_name,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.payment_attempt_id, a.patient_bonus_id, a.patient_confirmed_at, a.user_id, u.name, u.email
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
        WHERE a.tenant_id = ?
          AND a.cancel_token = ?
    ");
    $stmt->bind_param("is", $tenant_id, $token);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$appointment || $appointment['status'] !== 'booked') {
        $message = 'La cita no existe o ya no está activa.';
    } elseif (($_POST['action'] ?? '') === 'confirm') {
        $stmt = $mysqli->prepare("UPDATE appointments SET patient_confirmed_at = COALESCE(patient_confirmed_at, NOW()) WHERE tenant_id = ? AND id = ? AND cancel_token = ? AND status = 'booked'");
        $stmt->bind_param("iis", $tenant_id, $appointment['id'], $token);
        $stmt->execute();
        $confirmed = $stmt->affected_rows > 0 || !empty($appointment['patient_confirmed_at']);
        $message_type = $confirmed ? 'success' : 'danger';
        $message = $confirmed ? 'Tu asistencia ha quedado confirmada correctamente.' : 'No se pudo confirmar la asistencia.';
        if ($confirmed) {
            $appointment['patient_confirmed_at'] = date('Y-m-d H:i:s');
            app_log($mysqli, [
                'user_id' => (int) ($appointment['user_id'] ?? 0),
                'action' => 'appointment_confirmed_by_patient',
                'status' => 'ok',
                'target_type' => 'appointment',
                'target_id' => (int) $appointment['id'],
                'title' => 'Asistencia confirmada',
                'message' => 'Asistencia confirmada por el paciente para la cita del ' . appointment_label($appointment['appointment_date'], $appointment['appointment_time']) . '.',
                'metadata' => [
                    'origin' => 'public_manage_link',
                    'patient_id' => (int) ($appointment['user_id'] ?? 0),
                    'patient_name' => $appointment['name'] ?? '',
                    'appointment_date' => $appointment['appointment_date'] ?? '',
                    'appointment_time' => $appointment['appointment_time'] ?? ''
                ]
            ]);
        }
    } else {
        try {
            google_delete_calendar_event($mysqli, (int) $appointment['id']);
        } catch (\Exception $e) {
            error_log('No se pudo eliminar evento en Google Calendar: ' . $e->getMessage());
        }
        try {
            icloud_delete_calendar_event($mysqli, (int) $appointment['id']);
        } catch (\Exception $e) {
            error_log('No se pudo eliminar evento en iCloud Calendar: ' . $e->getMessage());
        }
        try {
            microsoft_delete_calendar_event($mysqli, (int) $appointment['id']);
        } catch (\Exception $e) {
            error_log('No se pudo eliminar evento en Microsoft Outlook Calendar: ' . $e->getMessage());
        }

        $stmt = $mysqli->prepare("UPDATE appointments SET status = 'cancelled', cancelled_at = NOW() WHERE tenant_id = ? AND id = ? AND cancel_token = ? AND status = 'booked'");
        $stmt->bind_param("iis", $tenant_id, $appointment['id'], $token);
        $stmt->execute();

        $cancelled = $stmt->affected_rows > 0;
        $message_type = $cancelled ? 'success' : 'danger';
        $message = $cancelled ? 'Tu cita ha sido cancelada correctamente.' : 'No se pudo cancelar la cita.';
        if ($cancelled) {
            $appointment['status'] = 'cancelled';
            if (!empty($appointment['patient_bonus_id']) && ($appointment['payment_method'] ?? '') === 'bonus') {
                $appointment['bonus_session_restored'] = restore_patient_bonus_session($mysqli, (int) $appointment['patient_bonus_id']) ? 1 : 0;
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
            app_log($mysqli, [
                'user_id' => (int) ($appointment['user_id'] ?? 0),
                'action' => 'appointment_cancelled',
                'status' => 'ok',
                'target_type' => 'appointment',
                'target_id' => (int) $appointment['id'],
                'title' => 'Cita cancelada',
                'message' => 'Cita cancelada por enlace publico para ' . ($appointment['name'] ?? '') . ' el ' . appointment_label($appointment['appointment_date'], $appointment['appointment_time']) . '.',
                'metadata' => [
                    'origin' => 'public_manage_link',
                    'patient_id' => (int) ($appointment['user_id'] ?? 0),
                    'patient_name' => $appointment['name'] ?? '',
                    'appointment_date' => $appointment['appointment_date'] ?? '',
                    'appointment_time' => $appointment['appointment_time'] ?? '',
                    'payment_status' => $appointment['payment_status'] ?? '',
                    'payment_method' => $appointment['payment_method'] ?? '',
                    'bonus_session_restored' => (int) ($appointment['bonus_session_restored'] ?? 0),
                    'compensation_bonus_created' => (int) ($appointment['compensation_bonus_created'] ?? 0)
                ]
            ]);
        }
    }
}

$payment_settings = ['online_payment_enabled' => 0, 'appointment_price' => '70.00', 'online_appointment_price' => '70.00', 'couple_appointment_price' => '90.00', 'online_couple_appointment_price' => '90.00'];
$settings_res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
if ($settings_res->num_rows > 0) {
    ensure_payment_settings_price_columns($mysqli);
    $settings_res = $mysqli->query("SELECT online_payment_enabled, appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price FROM payment_settings WHERE tenant_id = $tenant_id");
    if ($settings = $settings_res->fetch_assoc()) {
        $payment_settings = $settings;
    }
}

$will_create_compensation_bonus = $appointment
    && $appointment['status'] === 'booked'
    && compensation_bonus_on_paid_cancel_enabled($mysqli)
    && ($appointment['payment_status'] ?? '') === 'paid'
    && in_array(($appointment['payment_method'] ?? ''), ['card', 'bizum'], true);

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
    <title>Gestionar reserva - SimplyGest Praxis</title>
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
                            <?php if (!empty($appointment['patient_confirmed_at'])): ?>
                                <div class="alert alert-success">
                                    <strong>Asistencia confirmada.</strong> Esta reserva sigue activa.
                                </div>
                            <?php else: ?>
                                <form method="post" class="mb-3">
                                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                                    <input type="hidden" name="action" value="confirm">
                                    <button type="submit" class="btn btn-primary w-100">Confirmar asistencia</button>
                                </form>
                            <?php endif; ?>
                            <?php if ((int) $payment_settings['online_payment_enabled'] === 1 && $appointment['payment_status'] !== 'paid'): ?>
                                <div class="mb-4">
                                    <p class="text-muted mb-2">Puedes pagar ahora tu cita de <?= htmlspecialchars(format_appointment_price(current_appointment_price($payment_settings, $appointment))) ?> €.</p>
                                    <div class="d-grid gap-2">
                                        <button class="btn btn-success" type="button" id="btn-pay-card">Pagar con tarjeta</button>
                                        <button class="btn btn-success" type="button" id="btn-pay-bizum">Pagar con Bizum</button>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($will_create_compensation_bonus): ?>
                                <div class="alert alert-info small">
                                    <strong>Se crear&aacute; un bono canjeable para una nueva sesi&oacute;n.</strong>
                                </div>
                            <?php endif; ?>

                            <form method="post" id="cancel-booking-form">
                                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                                <input type="hidden" name="action" value="cancel">
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
