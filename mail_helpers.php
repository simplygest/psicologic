<?php

function ensure_admin_notification_email_column($mysqli)
{
    return true;
}

function get_admin_notification_email($mysqli)
{
    ensure_admin_notification_email_column($mysqli);
    $tenant_id = current_tenant_id();

    $res = $mysqli->query("
        SELECT email_provider, admin_notification_email, smtp_from_email, google_connected_email
        FROM payment_settings
        WHERE tenant_id = $tenant_id
    ");
    $row = $res->fetch_assoc();
    if (!$row) {
        return '';
    }

    $notification_email = trim($row['admin_notification_email'] ?? '');
    if ($notification_email !== '') {
        return $notification_email;
    }

    $provider = $row['email_provider'] ?? 'phpmailer';
    if ($provider === 'google') {
        return trim($row['google_connected_email'] ?? '');
    }

    return trim($row['smtp_from_email'] ?? '');
}

function get_email_settings($mysqli)
{
    ensure_admin_notification_email_column($mysqli);
    $tenant_id = current_tenant_id();

    $res = $mysqli->query("
        SELECT app_name, email_provider, smtp_host, smtp_port, smtp_username, smtp_password, smtp_secure,
               smtp_from_email, smtp_from_name, google_connected_email
        FROM payment_settings
        WHERE tenant_id = $tenant_id
    ");
    return $res->fetch_assoc() ?: [];
}

function get_app_name($mysqli)
{
    ensure_admin_notification_email_column($mysqli);
    $tenant_id = current_tenant_id();
    $res = $mysqli->query("SELECT app_name FROM payment_settings WHERE tenant_id = $tenant_id");
    $row = $res->fetch_assoc();
    return trim($row['app_name'] ?? '') ?: 'SimplyGest Praxis';
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
    global $APP_EMAIL_LAST_ERROR;
    $APP_EMAIL_LAST_ERROR = '';

    $to = trim((string) $to);
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $APP_EMAIL_LAST_ERROR = 'Email de destino no valido.';
        return false;
    }

    $settings = $mysqli ? get_email_settings($mysqli) : [];
    $provider = $settings['email_provider'] ?? 'phpmailer';

    if ($provider === 'google') {
        require_once __DIR__ . '/google_helpers.php';
        try {
            return google_send_email($mysqli, $to, $subject, $html_body, $reply_to);
        } catch (\Exception $e) {
            $APP_EMAIL_LAST_ERROR = 'Gmail API: ' . $e->getMessage();
            error_log('Error enviando email con Gmail API: ' . $e->getMessage());
            return false;
        }
    }

    if (!load_phpmailer()) {
        $APP_EMAIL_LAST_ERROR = 'PHPMailer no esta instalado.';
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
        $from_name = $settings['smtp_from_name'] ?: ($settings['app_name'] ?? 'SimplyGest Praxis');
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
        $APP_EMAIL_LAST_ERROR = $mail->ErrorInfo ?: $e->getMessage();
        error_log('Error enviando email: ' . $APP_EMAIL_LAST_ERROR);
        return false;
    }
}

function get_app_email_last_error()
{
    global $APP_EMAIL_LAST_ERROR;
    return trim((string) ($APP_EMAIL_LAST_ERROR ?? ''));
}

function notify_admin($mysqli, $subject, $html_body, $reply_to = null)
{
    $admin_email = get_admin_notification_email($mysqli);
    if (!$admin_email) {
        return false;
    }

    return send_app_email($admin_email, $subject, $html_body, $reply_to, $mysqli);
}

function get_appointment_professional($mysqli, $appointment)
{
    $professional_id = (int) ($appointment['professional_id'] ?? 0);
    if ($professional_id <= 0) {
        return null;
    }

    if (function_exists('cabinet_fetch_professional')) {
        return cabinet_fetch_professional($mysqli, $professional_id);
    }

    $stmt = $mysqli->prepare("
        SELECT p.id, p.user_id, p.display_name, p.public_email, u.email AS user_email
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
        WHERE p.tenant_id = ?
          AND p.id = ?
        LIMIT 1
    ");
    $tenant_id = current_tenant_id();
    $stmt->bind_param("ii", $tenant_id, $professional_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }
    $row['notification_email'] = trim($row['public_email'] ?: ($row['user_email'] ?? ''));
    return $row;
}

function notify_appointment_professional($mysqli, $appointment, $subject, $html_body, $reply_to = null, $append_booking_summary = false)
{
    $professional = get_appointment_professional($mysqli, $appointment);
    $professional_email = trim((string) ($professional['notification_email'] ?? ''));
    if ($professional_email && filter_var($professional_email, FILTER_VALIDATE_EMAIL)) {
        if ($append_booking_summary
            && ($professional['appointment_summary_email_mode'] ?? 'on_booking') === 'on_booking'
            && !empty($professional['id'])) {
            $html_body .= professional_appointments_summary_table($mysqli, (int) $professional['id'], null, 3);
        }
        return send_app_email($professional_email, $subject, $html_body, $reply_to, $mysqli);
    }

    return notify_admin($mysqli, $subject, $html_body, $reply_to);
}

function appointment_label($date, $time)
{
    return date('d/m/Y', strtotime($date)) . ' a las ' . date('H:i', strtotime($time));
}

function appointment_display_duration_minutes($duration_minutes, $settings = [])
{
    $duration = max(1, (int) ($duration_minutes ?: 60));
    if ((int) ($settings['display_effective_duration_enabled'] ?? 0) !== 1) {
        return $duration;
    }
    $offset = max(0, min(30, (int) ($settings['display_duration_offset_minutes'] ?? 5)));
    return max(1, $duration - $offset);
}

function appointment_display_time_range($time, $duration_minutes, $settings = [])
{
    $start = date('H:i', strtotime($time));
    $display_duration = appointment_display_duration_minutes($duration_minutes, $settings);
    $end = date('H:i', strtotime($time . ' +' . $display_duration . ' minutes'));
    return $start . ' - ' . $end;
}

function appointment_display_service_label($service_text, $duration_minutes, $settings = [])
{
    $service_text = (string) $service_text;
    if ((int) ($settings['display_effective_duration_enabled'] ?? 0) !== 1) {
        return $service_text;
    }
    $display_duration = appointment_display_duration_minutes($duration_minutes, $settings);
    return preg_replace('/\(\d+\s*min\)/i', '(' . $display_duration . ' min)', $service_text);
}

function appointment_display_settings($mysqli)
{
    if (!$mysqli) {
        return [];
    }
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("
        SELECT display_effective_duration_enabled, display_duration_offset_minutes
        FROM payment_settings
        WHERE tenant_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
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

function professional_appointments_summary_table($mysqli, $professional_id, $start_date = null, $days = 3)
{
    $professional_id = (int) $professional_id;
    $days = max(1, min(14, (int) $days));
    if ($professional_id <= 0) {
        return '';
    }

    $start = $start_date ? new DateTime($start_date) : new DateTime('today');
    $end = clone $start;
    $end->modify('+' . $days . ' days');
    $start_sql = $start->format('Y-m-d');
    $end_sql = $end->format('Y-m-d');

    $stmt = $mysqli->prepare("
        SELECT a.appointment_date, a.appointment_time, a.consultation_type, a.service_type, a.online_session_url,
               COALESCE(a.duration_minutes, 60) AS duration_minutes,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               u.name AS patient_name,
               s.name AS service_name
        FROM appointments a
        INNER JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        WHERE a.tenant_id = ?
          AND a.professional_id = ?
          AND a.status = 'booked'
          AND a.appointment_date >= ?
          AND a.appointment_date < ?
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
    ");
    if (!$stmt) {
        return '';
    }
    $tenant_id = current_tenant_id();
    $stmt->bind_param('iiss', $tenant_id, $professional_id, $start_sql, $end_sql);
    $stmt->execute();
    $res = $stmt->get_result();
    $display_settings = appointment_display_settings($mysqli);

    $rows = '';
    $row_index = 0;
    while ($appointment = $res->fetch_assoc()) {
        $row_index++;
        $service = function_exists('appointment_service_option_label')
            ? appointment_service_option_label($appointment)
            : (($appointment['service_name'] ?? '') ?: appointment_service_label($appointment['service_type'] ?? 'individual'));
        $service = appointment_display_service_label($service, $appointment['duration_minutes'] ?? 60, $display_settings);
        $consultation = appointment_consultation_label($appointment['consultation_type'] ?? 'presencial');
        $payment = appointment_payment_label($appointment['payment_status'] ?? 'pending');
        $date_text = date('d/m/Y', strtotime($appointment['appointment_date']));
        $time_text = appointment_display_time_range($appointment['appointment_time'], $appointment['duration_minutes'] ?? 60, $display_settings);
        $bg = $row_index % 2 === 0 ? '#fbfafc' : '#ffffff';
        $consultation_bg = ($appointment['consultation_type'] ?? '') === 'online' ? '#e8f1ff' : '#e8f7ef';
        $consultation_color = ($appointment['consultation_type'] ?? '') === 'online' ? '#1e5aa8' : '#166534';
        $payment_bg = ($appointment['payment_status'] ?? '') === 'paid' ? '#dcfce7' : '#fff7d6';
        $payment_color = ($appointment['payment_status'] ?? '') === 'paid' ? '#166534' : '#8a6d1d';
        $online_link = (($appointment['consultation_type'] ?? '') === 'online' && !empty($appointment['online_session_url']))
            ? '<br><a href="' . htmlspecialchars($appointment['online_session_url']) . '" style="color:#1e5aa8;font-size:12px;">Enlace videollamada</a>'
            : '';

        $rows .= '<tr style="background:' . $bg . ';">' .
            '<td style="padding:10px 12px;border-bottom:1px solid #ebe7f1;color:#4b5563;white-space:nowrap;">' . htmlspecialchars($date_text) . '</td>' .
            '<td style="padding:10px 12px;border-bottom:1px solid #ebe7f1;color:#2f2642;font-weight:700;white-space:nowrap;">' . htmlspecialchars($time_text) . '</td>' .
            '<td style="padding:10px 12px;border-bottom:1px solid #ebe7f1;color:#2f2642;font-weight:600;">' . htmlspecialchars($appointment['patient_name'] ?? 'Paciente') . '</td>' .
            '<td style="padding:10px 12px;border-bottom:1px solid #ebe7f1;color:#4b5563;">' . htmlspecialchars($service) . '</td>' .
            '<td style="padding:10px 12px;border-bottom:1px solid #ebe7f1;white-space:nowrap;"><span style="display:inline-block;padding:4px 8px;border-radius:999px;background:' . $consultation_bg . ';color:' . $consultation_color . ';font-size:12px;font-weight:700;">' . htmlspecialchars($consultation) . '</span>' . $online_link . '</td>' .
            '<td style="padding:10px 12px;border-bottom:1px solid #ebe7f1;white-space:nowrap;"><span style="display:inline-block;padding:4px 8px;border-radius:999px;background:' . $payment_bg . ';color:' . $payment_color . ';font-size:12px;font-weight:700;">' . htmlspecialchars($payment) . '</span></td>' .
            '</tr>';
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="6" style="padding:14px 12px;border-bottom:1px solid #ebe7f1;color:#6b7280;text-align:center;">No hay citas previstas en este periodo.</td></tr>';
    }

    $period_end = clone $end;
    $period_end->modify('-1 day');
    $period_text = 'del ' . $start->format('d/m/Y') . ' al ' . $period_end->format('d/m/Y');

    return '<div style="margin-top:24px;padding-top:18px;border-top:1px solid #e5e1ed;">' .
        '<h3 style="margin:0 0 6px 0;color:#2f2642;font-size:18px;">Planning de pr&oacute;ximas citas</h3>' .
        '<p style="margin:0 0 12px 0;color:#6b7280;font-size:14px;">Aqu&iacute; tienes el resumen de citas ' . htmlspecialchars($period_text) . '.</p>' .
        '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;border:1px solid #e5e1ed;border-radius:8px;overflow:hidden;background:#ffffff;">' .
        '<thead><tr style="background:#4285f4;color:#ffffff;">' .
        '<th align="left" style="padding:10px 12px;font-size:13px;">Fecha</th>' .
        '<th align="left" style="padding:10px 12px;font-size:13px;">Hora</th>' .
        '<th align="left" style="padding:10px 12px;font-size:13px;">Paciente</th>' .
        '<th align="left" style="padding:10px 12px;font-size:13px;">Servicio</th>' .
        '<th align="left" style="padding:10px 12px;font-size:13px;">Modalidad</th>' .
        '<th align="left" style="padding:10px 12px;font-size:13px;">Pago</th>' .
        '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
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
    $professional = get_appointment_professional($mysqli, $appointment);
    $professional_line = $professional
        ? '<b>Profesional:</b> ' . htmlspecialchars($professional['display_name']) . '<br>'
        : '';
    $payment_status = $appointment['payment_status'] ?? 'pending';
    $is_bonus_payment = ($appointment['payment_method'] ?? '') === 'bonus' && !empty($appointment['patient_bonus_id']);
    $bonus_session_restored = !empty($appointment['bonus_session_restored']);
    $compensation_bonus_created = !empty($appointment['compensation_bonus_created']);
    $payment_text = appointment_cancel_payment_label($appointment);
    $paid_warning = $payment_status === 'paid'
        ? '<p><b>Atención:</b> esta cita constaba como pagada. Revisa si corresponde hacer devolución o contactar con el paciente.</p>'
        : '';
    if ($is_bonus_payment && $bonus_session_restored) {
        $paid_warning = '<p>Esta cita fue reservada con bono. El paciente volver&aacute; a tener una cita disponible en su bono.</p>';
    } elseif ($is_bonus_payment) {
        $paid_warning = '<p>Esta cita fue reservada con bono.</p>';
    } elseif ($compensation_bonus_created) {
        $paid_warning = '<p>Se ha creado un vale de 1 sesi&oacute;n para el paciente. Podr&aacute; usarlo para reservar otra cita desde la web.</p>';
    }
    $patient_payment_note = $is_bonus_payment
        ? '<p><b>Estado del pago:</b> pagada con bono</p>'
        : '';
    if ($is_bonus_payment && $bonus_session_restored) {
        $patient_payment_note = '<p><b>Estado del pago:</b> pagada con bono. El bono vuelve a estar disponible para otra cita.</p>';
    }
    if ($compensation_bonus_created) {
        $patient_payment_note = '<p><b>Compensaci&oacute;n:</b> hemos generado un vale de 1 sesi&oacute;n para que puedas reservar otra cita desde la web.</p>';
    }

    notify_appointment_professional(
        $mysqli,
        $appointment,
        'Cita cancelada',
        '<p>Se ha cancelado una cita.</p>' .
        '<p><b>Paciente:</b> ' . htmlspecialchars($patient_name) . '<br>' .
        $professional_line .
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
            ($professional ? '<p><b>Tu cita con ' . htmlspecialchars($professional['display_name']) . '</b></p>' : '') .
            '<p>Tu cita ' . htmlspecialchars(strtolower($service_text)) . ' ' . htmlspecialchars(strtolower($consultation_text)) . ' para el ' . htmlspecialchars($appointment_text) . ' ha sido cancelada correctamente.</p>' .
            '<p><b>Servicio:</b> ' . htmlspecialchars($service_text) . '</p>' .
            '<p><b>Modalidad:</b> ' . htmlspecialchars($consultation_text) . '</p>' .
            $patient_payment_note,
            null,
            $mysqli
        );
    }
}
