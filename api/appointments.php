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
               a.payment_method, a.paid_at, a.consultation_type, u.name, u.email, u.phone 
        FROM appointments a
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
            'is_own' => ($app['user_id'] == $user_id)
        ];
    }

    $payment_settings = [
        'online_payment_enabled' => 0,
        'appointment_price' => '70.00',
        'min_booking_notice_days' => 2,
        'max_booking_notice_days' => MAX_BOOKING_DAYS,
        'appointment_start_time' => '10:00:00',
        'appointment_end_time' => '19:00:00',
        'break_start_time' => '15:00:00',
        'break_end_time' => '16:00:00',
        'available_weekdays' => '1,2,3,4,5',
        'appointment_delivery_mode' => 'both'
    ];
    $settings_res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if ($settings_res->num_rows > 0) {
        $price_column = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'appointment_price'");
        if ($price_column->num_rows === 0) {
            $mysqli->query("ALTER TABLE payment_settings ADD appointment_price DECIMAL(10,2) NOT NULL DEFAULT 70.00 AFTER terminal");
        }
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
        $settings_res = $mysqli->query("
            SELECT online_payment_enabled, appointment_price, min_booking_notice_days, max_booking_notice_days,
                   appointment_start_time, appointment_end_time, break_start_time, break_end_time,
                   available_weekdays, appointment_delivery_mode
            FROM payment_settings
            WHERE id = 1
        ");
        if ($settings_row = $settings_res->fetch_assoc()) {
            $payment_settings = $settings_row;
        }
    }

    echo json_encode(['success' => true, 'appointments' => $apps_map, 'closed_days' => $closed_days, 'payment_settings' => $payment_settings]);

} elseif ($action === 'book') {
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';
    $consultation_type = $_POST['consultation_type'] ?? '';
    $target_user_id = $is_admin ? ($_POST['user_id'] ?? '') : $user_id;

    if (!$date || !$time || !$target_user_id) {
        echo json_encode(['success' => false, 'error' => 'Faltan datos']);
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
        $settings_res = $mysqli->query("
            SELECT min_booking_notice_days, max_booking_notice_days,
                   appointment_start_time, appointment_end_time, break_start_time, break_end_time,
                   available_weekdays, appointment_delivery_mode
            FROM payment_settings
            WHERE id = 1
        ");
        if ($settings = $settings_res->fetch_assoc()) {
            $min_booking_notice_days = (int) $settings['min_booking_notice_days'];
            $max_booking_notice_days = (int) $settings['max_booking_notice_days'];
            $appointment_delivery_mode = $settings['appointment_delivery_mode'] ?? 'both';
        }
    }

    if ($appointment_delivery_mode === 'online') {
        $consultation_type = 'online';
    } elseif ($appointment_delivery_mode === 'presencial') {
        $consultation_type = 'presencial';
    } elseif (!in_array($consultation_type, ['presencial', 'online'], true)) {
        echo json_encode(['success' => false, 'error' => 'Selecciona si la cita será presencial u online.']);
        exit;
    }

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

    try {
        $stmt = $mysqli->prepare("INSERT INTO appointments (user_id, appointment_date, appointment_time, consultation_type, status) VALUES (?, ?, ?, ?, 'booked')");
        $stmt->bind_param("isss", $target_user_id, $date, $time, $consultation_type);
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
            '<b>Modalidad:</b> ' . htmlspecialchars($consultation_text) . '<br>' .
            '<b>Email:</b> ' . htmlspecialchars($patient['email'] ?? 'Sin email') . '<br>' .
            '<b>Teléfono:</b> ' . htmlspecialchars($patient['phone'] ?? 'Sin teléfono') . '</p>',
            $patient['email'] ?? null
        );

        if (!empty($patient['email'])) {
            $payment_note = '<p>Recuerda que puedes pagar directamente en la consulta.</p>';
            $settings_res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
            if ($settings_res->num_rows > 0) {
                $settings_res = $mysqli->query("SELECT online_payment_enabled FROM payment_settings WHERE id = 1");
                $settings = $settings_res->fetch_assoc();
                if ($settings && (int) $settings['online_payment_enabled'] === 1) {
                    $payment_note = '<p><b>Importante:</b> este email confirma la reserva de la cita, pero no confirma el pago. Recibirás otro email cuando el pago se complete correctamente.</p>';
                }
            }

            send_app_email(
                $patient['email'],
                'Cita reservada',
                '<p>Hola ' . htmlspecialchars($patient['name']) . ',</p>' .
                '<p>Tu cita ' . htmlspecialchars(strtolower($consultation_text)) . ' para el ' . htmlspecialchars($appointment_text) . ' ha quedado reservada correctamente.</p>' .
                '<p><b>Modalidad:</b> ' . htmlspecialchars($consultation_text) . '</p>' .
                $payment_note .
                '<p>Por favor, si no puedes asistir te rogamos gestionar tu cita directamente en la web.</p>' .
                '<p><a href="' . htmlspecialchars(app_public_base_url() . 'cancelar_cita.php?t=' . $cancel_token) . '">Gestionar reserva</a></p>',
                null,
                $mysqli
            );
        }

        echo json_encode(['success' => true, 'appointment_id' => $appointment_id]);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => 'El horario ya está ocupado']);
    }

} elseif ($action === 'cancel') {
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';

    $lookup_sql = "
        SELECT a.id, a.appointment_date, a.appointment_time, a.consultation_type,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               u.name, u.email
        FROM appointments a
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
