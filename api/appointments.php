<?php
session_start();
require_once '../db.php';
require_once '../payment_helpers.php';
require_once '../mail_helpers.php';
require_once '../google_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$action = $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

ensure_appointment_payment_columns($mysqli);
ensure_appointment_services_tables($mysqli);

function ensure_schedule_setting_columns($mysqli)
{
    $columns = [
        'appointment_start_time' => "ALTER TABLE payment_settings ADD appointment_start_time TIME NOT NULL DEFAULT '10:00:00'",
        'appointment_end_time' => "ALTER TABLE payment_settings ADD appointment_end_time TIME NOT NULL DEFAULT '19:00:00'",
        'break_start_time' => "ALTER TABLE payment_settings ADD break_start_time TIME DEFAULT '15:00:00'",
        'break_end_time' => "ALTER TABLE payment_settings ADD break_end_time TIME DEFAULT '16:00:00'",
        'available_weekdays' => "ALTER TABLE payment_settings ADD available_weekdays VARCHAR(32) NOT NULL DEFAULT '1,2,3,4,5'"
    ];

    foreach ($columns as $column => $sql) {
        $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE '$column'");
        if ($column_res && $column_res->num_rows === 0) {
            $mysqli->query($sql);
        }
    }
}

function ensure_delivery_setting_column($mysqli)
{
    $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'appointment_delivery_mode'");
    if ($column_res && $column_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE payment_settings ADD appointment_delivery_mode ENUM('both', 'presencial', 'online') NOT NULL DEFAULT 'both' AFTER admin_notification_email");
    }
}

function ensure_session_setting_column($mysqli)
{
    $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'available_session_types'");
    if ($column_res && $column_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE payment_settings ADD available_session_types VARCHAR(32) NOT NULL DEFAULT 'individual' AFTER appointment_delivery_mode");
    }

    $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'available_session_durations'");
    if ($column_res && $column_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE payment_settings ADD available_session_durations VARCHAR(16) NOT NULL DEFAULT '60' AFTER available_session_types");
    }
}

function active_session_durations($settings)
{
    $durations = [];
    foreach (explode(',', $settings['available_session_durations'] ?? '60') as $duration) {
        $duration = (int) trim($duration);
        if (in_array($duration, [60, 90, 120], true) && !in_array($duration, $durations, true)) {
            $durations[] = $duration;
        }
    }
    sort($durations);
    return $durations ?: [60];
}

function minutes_from_time($time)
{
    [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)));
    return ($hours * 60) + $minutes;
}

function schedule_slot_list($settings)
{
    $start = minutes_from_time($settings['appointment_start_time'] ?? '10:00:00');
    $end = minutes_from_time($settings['appointment_end_time'] ?? '19:00:00');
    $break_start = !empty($settings['break_start_time']) ? minutes_from_time($settings['break_start_time']) : null;
    $break_end = !empty($settings['break_end_time']) ? minutes_from_time($settings['break_end_time']) : null;
    $slots = [];

    for ($minutes = $start; $minutes <= $end; $minutes += 60) {
        if ($break_start !== null && $break_end !== null && $minutes >= $break_start && $minutes < $break_end) {
            continue;
        }
        $slots[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    return $slots;
}

function active_weekdays($settings)
{
    $raw = $settings['available_weekdays'] ?? '1,2,3,4,5';
    $days = [];
    foreach (explode(',', $raw) as $day) {
        $day = (int) trim($day);
        if ($day >= 1 && $day <= 6 && !in_array($day, $days, true)) {
            $days[] = $day;
        }
    }
    sort($days);
    return $days ?: [1, 2, 3, 4, 5];
}

if ($action === 'get_week') {
    // start_date expected to be a Monday (YYYY-MM-DD)
    $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('monday this week'));
    $end_date = date('Y-m-d', strtotime($start_date . ' +5 days')); // Saturday when enabled

    // Get appointments in range
    $stmt = $mysqli->prepare("
        SELECT a.id, a.appointment_date, a.appointment_time, a.user_id,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.paid_at, a.consultation_type, a.service_type,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               so.price AS service_price, s.name AS service_name, s.service_key,
               u.name, u.email, u.phone
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
        LEFT JOIN appointment_services s ON s.id = so.service_id
        JOIN users u ON a.user_id = u.id
        WHERE a.appointment_date BETWEEN ? AND ? AND a.status = 'booked'
    ");
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $res = $stmt->get_result();
    $appointments = $res->fetch_all(MYSQLI_ASSOC);

    // Get closed days
    $stmt2 = $mysqli->prepare("SELECT closed_date, reason FROM closed_days WHERE closed_date BETWEEN ? AND ?");
    $stmt2->bind_param("ss", $start_date, $end_date);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $closed_days_fetch = $res2->fetch_all(MYSQLI_ASSOC);

    $closed_days = [];
    foreach ($closed_days_fetch as $row) {
        $closed_days[$row['closed_date']] = $row['reason'];
    }

    $apps_map = [];
    foreach ($appointments as $app) {
        $date = $app['appointment_date'];
        $time = date('H:i', strtotime($app['appointment_time']));
        if (!isset($apps_map[$date])) {
            $apps_map[$date] = [];
        }
        $apps_map[$date][$time] = [
            'id' => $app['id'],
            'user_id' => $app['user_id'],
            'name' => $app['name'],
            'email' => $app['email'] ?? 'Sin email',
            'phone' => $app['phone'] ?? 'Sin tel',
            'payment_status' => $app['payment_status'] ?? 'pending',
            'payment_method' => $app['payment_method'] ?? null,
            'paid_at' => $app['paid_at'] ?? null,
            'consultation_type' => $app['consultation_type'] ?? 'presencial',
            'service_type' => $app['service_type'] ?? 'individual',
            'service_label' => appointment_service_option_label($app),
            'service_name' => $app['service_name'] ?? null,
            'duration_minutes' => (int) ($app['duration_minutes'] ?? 60),
            'time' => $time,
            'price' => isset($app['service_price']) ? number_format((float) $app['service_price'], 2, '.', '') : null,
            'is_own' => ($app['user_id'] == $user_id)
        ];
    }

    $payment_settings = [
        'online_payment_enabled' => 0,
        'appointment_price' => '70.00',
        'online_appointment_price' => '70.00',
        'couple_appointment_price' => '90.00',
        'online_couple_appointment_price' => '90.00',
        'available_session_types' => 'individual',
        'min_booking_notice_days' => 2,
        'max_booking_notice_days' => MAX_BOOKING_DAYS,
        'appointment_start_time' => '10:00:00',
        'appointment_end_time' => '19:00:00',
        'break_start_time' => '15:00:00',
        'break_end_time' => '16:00:00',
        'available_weekdays' => '1,2,3,4,5',
        'appointment_delivery_mode' => 'both',
        'available_session_durations' => '60'
    ];
    $settings_res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if ($settings_res->num_rows > 0) {
        ensure_payment_settings_price_columns($mysqli);
        $limit_columns = [
            'min_booking_notice_days' => "ALTER TABLE payment_settings ADD min_booking_notice_days INT UNSIGNED NOT NULL DEFAULT 2",
            'max_booking_notice_days' => "ALTER TABLE payment_settings ADD max_booking_notice_days INT UNSIGNED NOT NULL DEFAULT 40"
        ];
        foreach ($limit_columns as $column => $sql) {
            $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE '$column'");
            if ($column_res->num_rows === 0) {
                $mysqli->query($sql);
            }
        }
        ensure_schedule_setting_columns($mysqli);
        ensure_delivery_setting_column($mysqli);
        ensure_session_setting_column($mysqli);
        $settings_res = $mysqli->query("
            SELECT online_payment_enabled, appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price, available_session_types, available_session_durations,
                   min_booking_notice_days, max_booking_notice_days,
                   appointment_start_time, appointment_end_time, break_start_time, break_end_time,
                   available_weekdays, appointment_delivery_mode
            FROM payment_settings
            WHERE id = 1
        ");
        if ($settings_row = $settings_res->fetch_assoc()) {
            $payment_settings = $settings_row;
        }
    }

    $service_options = [];
    $active_durations = active_session_durations($payment_settings);
    $active_delivery_mode = $payment_settings['appointment_delivery_mode'] ?? 'both';
    $active_service_types = explode(',', $payment_settings['available_session_types'] ?? 'individual');
    foreach (fetch_appointment_services($mysqli, true) as $service) {
        if ($service['service_key'] === 'couple' && !in_array('couple', $active_service_types, true)) {
            continue;
        }
        foreach ($service['options'] as $option) {
            if (!in_array((int) $option['duration_minutes'], $active_durations, true)) {
                continue;
            }
            if ($active_delivery_mode !== 'both' && $option['consultation_type'] !== $active_delivery_mode) {
                continue;
            }
            $option['service_name'] = $service['name'];
            $option['service_key'] = $service['service_key'];
            $service_options[] = $option;
        }
    }

    echo json_encode(['success' => true, 'appointments' => $apps_map, 'closed_days' => $closed_days, 'payment_settings' => $payment_settings, 'service_options' => $service_options]);

} elseif ($action === 'book') {
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';
    $consultation_type = $_POST['consultation_type'] ?? '';
    $service_type = $_POST['service_type'] ?? 'individual';
    $service_option_id = (int) ($_POST['service_option_id'] ?? 0);
    $target_user_id = $is_admin ? ($_POST['user_id'] ?? '') : $user_id;

    if (!$date || !$time || !$target_user_id) {
        echo json_encode(['success' => false, 'error' => 'Faltan datos']);
        exit;
    }

    if ($service_option_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Selecciona un servicio disponible.']);
        exit;
    }

    $min_booking_notice_days = 2;
    $max_booking_notice_days = MAX_BOOKING_DAYS;
    $appointment_delivery_mode = 'both';
    $settings = [];
    $settings_res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if ($settings_res->num_rows > 0) {
        $limit_columns = [
            'min_booking_notice_days' => "ALTER TABLE payment_settings ADD min_booking_notice_days INT UNSIGNED NOT NULL DEFAULT 2",
            'max_booking_notice_days' => "ALTER TABLE payment_settings ADD max_booking_notice_days INT UNSIGNED NOT NULL DEFAULT 40"
        ];
        foreach ($limit_columns as $column => $sql) {
            $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE '$column'");
            if ($column_res->num_rows === 0) {
                $mysqli->query($sql);
            }
        }
        ensure_schedule_setting_columns($mysqli);
        ensure_delivery_setting_column($mysqli);
        ensure_session_setting_column($mysqli);
        $settings_res = $mysqli->query("
            SELECT min_booking_notice_days, max_booking_notice_days,
                   appointment_start_time, appointment_end_time, break_start_time, break_end_time,
                   available_weekdays, appointment_delivery_mode, available_session_types, available_session_durations
            FROM payment_settings
            WHERE id = 1
        ");
        if ($settings = $settings_res->fetch_assoc()) {
            $min_booking_notice_days = (int) $settings['min_booking_notice_days'];
            $max_booking_notice_days = (int) $settings['max_booking_notice_days'];
            $appointment_delivery_mode = $settings['appointment_delivery_mode'] ?? 'both';
        }
    }

    $service_option = $service_option_id > 0 ? fetch_service_option($mysqli, $service_option_id) : null;
    if (!$service_option || (int) $service_option['is_active'] !== 1 || (int) $service_option['service_active'] !== 1) {
        echo json_encode(['success' => false, 'error' => 'El servicio seleccionado no está disponible.']);
        exit;
    }
    $consultation_type = $service_option['consultation_type'];
    $service_type = $service_option['service_key'] === 'couple' ? 'couple' : 'individual';
    if ($appointment_delivery_mode !== 'both' && $consultation_type !== $appointment_delivery_mode) {
        echo json_encode(['success' => false, 'error' => 'La modalidad seleccionada no está disponible.']);
        exit;
    }
    if (!in_array((int) $service_option['duration_minutes'], active_session_durations($settings), true)) {
        echo json_encode(['success' => false, 'error' => 'La duración seleccionada no está disponible.']);
        exit;
    }

    if ($appointment_delivery_mode === 'online') {
        $consultation_type = 'online';
    } elseif ($appointment_delivery_mode === 'presencial') {
        $consultation_type = 'presencial';
    } elseif (!in_array($consultation_type, ['presencial', 'online'], true)) {
        echo json_encode(['success' => false, 'error' => 'Selecciona si la cita será presencial u online.']);
        exit;
    }

    $service_type = $service_type === 'couple' ? 'couple' : 'individual';
    $available_session_types = explode(',', $settings['available_session_types'] ?? 'individual');
    if ($service_type === 'couple' && !in_array('couple', $available_session_types, true)) {
        echo json_encode(['success' => false, 'error' => 'La sesión de pareja no está disponible.']);
        exit;
    }
    $duration_minutes = $service_option ? (int) $service_option['duration_minutes'] : 60;

    // Checking booking limits
    $booking_date = new DateTime($date);
    $today = new DateTime(date('Y-m-d'));
    $diff = $today->diff($booking_date)->days;
    $invert = $today->diff($booking_date)->invert;

    if ($invert) {
        echo json_encode(['success' => false, 'error' => 'No puedes reservar en el pasado.']);
        exit;
    }

    if (!empty($settings) && !in_array((int) $booking_date->format('N'), active_weekdays($settings), true)) {
        echo json_encode(['success' => false, 'error' => 'El día seleccionado no está disponible para consulta.']);
        exit;
    }
    if ($min_booking_notice_days > 0 && $diff < $min_booking_notice_days && !$is_admin) {
        echo json_encode(['success' => false, 'error' => 'Solo puedes reservar con al menos ' . $min_booking_notice_days . ' días de antelación.']);
        exit;
    }
    if ($max_booking_notice_days > 0 && $diff > $max_booking_notice_days && !$is_admin) {
        echo json_encode(['success' => false, 'error' => 'Solo puedes reservar hasta con ' . $max_booking_notice_days . ' días de antelación.']);
        exit;
    }
    if (false && $diff > MAX_BOOKING_DAYS && !$is_admin) {
        echo json_encode(['success' => false, 'error' => 'Solo puedes reservar hasta con ' . MAX_BOOKING_DAYS . ' días de antelación.']);
        exit;
    }

    // Check closed days
    $stmt = $mysqli->prepare("SELECT id FROM closed_days WHERE closed_date = ?");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->fetch_assoc()) {
        echo json_encode(['success' => false, 'error' => 'El día seleccionado no está disponible (Descanso/Festivo).']);
        exit;
    }

    if (!empty($settings) && !in_array(substr($time, 0, 5), schedule_slot_list($settings), true)) {
        echo json_encode(['success' => false, 'error' => 'El horario seleccionado no está disponible.']);
        exit;
    }

    $new_start = minutes_from_time($time);
    $new_end = $new_start + $duration_minutes;
    $stmt = $mysqli->prepare("
        SELECT appointment_time, COALESCE(duration_minutes, 60) AS duration_minutes
        FROM appointments
        WHERE appointment_date = ? AND status = 'booked'
    ");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $existing_res = $stmt->get_result();
    while ($existing = $existing_res->fetch_assoc()) {
        $existing_start = minutes_from_time($existing['appointment_time']);
        $existing_end = $existing_start + (int) ($existing['duration_minutes'] ?? 60);
        if ($new_start < $existing_end && $new_end > $existing_start) {
            echo json_encode(['success' => false, 'error' => 'El horario ya está ocupado']);
            exit;
        }
    }

    try {
        $stmt = $mysqli->prepare("INSERT INTO appointments (user_id, appointment_date, appointment_time, consultation_type, service_type, service_option_id, duration_minutes, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'booked')");
        $nullable_service_option_id = $service_option ? $service_option_id : null;
        $stmt->bind_param("issssii", $target_user_id, $date, $time, $consultation_type, $service_type, $nullable_service_option_id, $duration_minutes);
        $stmt->execute();
        $appointment_id = $mysqli->insert_id;
        $cancel_token = bin2hex(random_bytes(32));
        $stmt = $mysqli->prepare("UPDATE appointments SET cancel_token = ? WHERE id = ?");
        $stmt->bind_param("si", $cancel_token, $appointment_id);
        $stmt->execute();

        $stmt = $mysqli->prepare("SELECT name, email, phone FROM users WHERE id = ?");
        $stmt->bind_param("i", $target_user_id);
        $stmt->execute();
        $patient = $stmt->get_result()->fetch_assoc();
        $appointment_text = appointment_label($date, $time);
        $consultation_text = appointment_consultation_label($consultation_type);
        $service_text = $service_option ? $service_option['service_name'] . ' (' . $duration_minutes . ' min)' : appointment_service_label($service_type);
        $price_settings = null;
        $appointment_price_text = null;
        $settings_res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
        if ($settings_res->num_rows > 0) {
            ensure_payment_settings_price_columns($mysqli);
            $settings_res = $mysqli->query("SELECT online_payment_enabled, appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price FROM payment_settings WHERE id = 1");
            $price_settings = $settings_res->fetch_assoc();
            if ($price_settings) {
                $appointment_price_text = format_appointment_price($service_option ? $service_option['price'] : appointment_price_for_type($price_settings, $consultation_type, $service_type));
            }
        }

        try {
            google_create_calendar_event($mysqli, $appointment_id);
        } catch (\Exception $e) {
            error_log('No se pudo crear evento en Google Calendar: ' . $e->getMessage());
        }

        notify_admin(
            $mysqli,
            'Nueva cita reservada',
            '<p>Se ha reservado una nueva cita.</p>' .
            '<p><b>Paciente:</b> ' . htmlspecialchars($patient['name'] ?? '') . '<br>' .
            '<b>Fecha:</b> ' . htmlspecialchars($appointment_text) . '<br>' .
            '<b>Servicio:</b> ' . htmlspecialchars($service_text) . '<br>' .
            '<b>Modalidad:</b> ' . htmlspecialchars($consultation_text) . '<br>' .
            ($appointment_price_text !== null ? '<b>Importe:</b> ' . htmlspecialchars($appointment_price_text) . ' &euro;<br>' : '') .
            '<b>Email:</b> ' . htmlspecialchars($patient['email'] ?? 'Sin email') . '<br>' .
            '<b>Teléfono:</b> ' . htmlspecialchars($patient['phone'] ?? 'Sin teléfono') . '</p>',
            $patient['email'] ?? null
        );

        if (!empty($patient['email'])) {
            $payment_note = '<p>Recuerda que puedes pagar directamente en la consulta.</p>';
            if ($price_settings && (int) $price_settings['online_payment_enabled'] === 1) {
                $payment_note = '<p><b>Importante:</b> este email confirma la reserva de la cita, pero no confirma el pago. Recibirás otro email cuando el pago se complete correctamente.</p>';
            }

            send_app_email(
                $patient['email'],
                'Cita reservada',
                '<p>Hola ' . htmlspecialchars($patient['name']) . ',</p>' .
                '<p>Tu cita ' . htmlspecialchars(strtolower($service_text)) . ' ' . htmlspecialchars(strtolower($consultation_text)) . ' para el ' . htmlspecialchars($appointment_text) . ' ha quedado reservada correctamente.</p>' .
                '<p><b>Servicio:</b> ' . htmlspecialchars($service_text) . '</p>' .
                '<p><b>Modalidad:</b> ' . htmlspecialchars($consultation_text) . '</p>' .
                ($appointment_price_text !== null ? '<p><b>Importe:</b> ' . htmlspecialchars($appointment_price_text) . ' &euro;</p>' : '') .
                $payment_note .
                '<p>Por favor, si no puedes asistir te rogamos gestionar tu cita directamente en la web.</p>' .
                '<p><a href="' . htmlspecialchars(app_public_base_url() . 'cancelar_cita.php?t=' . $cancel_token) . '">Gestionar reserva</a></p>',
                null,
                $mysqli
            );
        }

        echo json_encode([
            'success' => true,
            'appointment_id' => $appointment_id,
            'service_label' => $service_text,
            'consultation_type' => $consultation_type,
            'service_type' => $service_type,
            'price' => $service_option ? number_format((float) $service_option['price'], 2, '.', '') : null
        ]);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => 'El horario ya está ocupado']);
    }

} elseif ($action === 'cancel') {
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';

    $lookup_sql = "
        SELECT a.id, a.appointment_date, a.appointment_time, a.consultation_type, a.service_type,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               s.name AS service_name,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               u.name, u.email
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
        LEFT JOIN appointment_services s ON s.id = so.service_id
        JOIN users u ON u.id = a.user_id
        WHERE a.appointment_date = ? AND a.appointment_time = ? AND a.status = 'booked'
    ";
    if (!$is_admin) {
        $lookup_sql .= " AND a.user_id = ?";
    }
    $lookup_stmt = $mysqli->prepare($lookup_sql);
    if ($is_admin) {
        $lookup_stmt->bind_param("ss", $date, $time);
    } else {
        $lookup_stmt->bind_param("ssi", $date, $time, $user_id);
    }
    $lookup_stmt->execute();
    $appointment_to_cancel = $lookup_stmt->get_result()->fetch_assoc();

    if ($appointment_to_cancel) {
        try {
            google_delete_calendar_event($mysqli, (int) $appointment_to_cancel['id']);
        } catch (\Exception $e) {
            error_log('No se pudo eliminar evento en Google Calendar: ' . $e->getMessage());
        }
    }

    if ($is_admin) {
        $stmt = $mysqli->prepare("DELETE FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND status = 'booked'");
        $stmt->bind_param("ss", $date, $time);
        $stmt->execute();
    } else {
        $stmt = $mysqli->prepare("DELETE FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND user_id = ? AND status = 'booked'");
        $stmt->bind_param("ssi", $date, $time, $user_id);
        $stmt->execute();
    }

    if ($stmt->affected_rows > 0) {
        notify_appointment_cancelled($mysqli, $appointment_to_cancel);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No se pudo cancelar o no tienes permiso']);
    }
}
