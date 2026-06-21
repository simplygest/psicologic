<?php
session_start();
require_once '../db.php';
require_once '../settings_helpers.php';
require_once '../payment_helpers.php';
require_once '../mail_helpers.php';
require_once '../google_helpers.php';
require_once '../caldav_helpers.php';
require_once '../urlme_helpers.php';
require_once '../cabinet_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$action = $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];
$tenant_id = current_tenant_id();
$is_admin = in_array(($_SESSION['role'] ?? ''), ['admin', 'superadmin'], true);

if (!$is_admin && !online_booking_enabled($mysqli)) {
    echo json_encode(['success' => false, 'error' => 'El área de pacientes no está disponible en este momento.']);
    exit;
}

ensure_appointment_payment_columns($mysqli);
ensure_appointment_services_tables($mysqli);
ensure_bonus_tables($mysqli);
ensure_cabinet_schema($mysqli);

function ensure_patient_portal_work_plan_schema($mysqli)
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS patient_work_plan_tasks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            patient_id INT UNSIGNED NOT NULL,
            appointment_id INT UNSIGNED DEFAULT NULL,
            professional_id INT UNSIGNED DEFAULT NULL,
            title VARCHAR(180) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            priority TINYINT UNSIGNED NOT NULL DEFAULT 2,
            visible_to_patient TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT UNSIGNED DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            completed_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_work_plan_appointment (appointment_id),
            INDEX idx_work_plan_patient_status (patient_id, status),
            INDEX idx_work_plan_professional (professional_id),
            INDEX idx_work_plan_priority (priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $column_res = $mysqli->query("SHOW COLUMNS FROM patient_work_plan_tasks LIKE 'tenant_id'");
    if ($column_res && $column_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE patient_work_plan_tasks ADD tenant_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id");
    }
    $column_res = $mysqli->query("SHOW COLUMNS FROM patient_work_plan_tasks LIKE 'visible_to_patient'");
    if ($column_res && $column_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE patient_work_plan_tasks ADD visible_to_patient TINYINT(1) NOT NULL DEFAULT 0 AFTER priority");
    }
}

function ensure_patient_portal_documents_schema($mysqli)
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS patient_documents (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            patient_id INT UNSIGNED NOT NULL,
            professional_id INT UNSIGNED DEFAULT NULL,
            document_type VARCHAR(30) NOT NULL DEFAULT 'file',
            title VARCHAR(180) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            document_date DATE DEFAULT NULL,
            score VARCHAR(80) DEFAULT NULL,
            result_label VARCHAR(120) DEFAULT NULL,
            observations LONGTEXT DEFAULT NULL,
            file_path VARCHAR(500) DEFAULT NULL,
            original_file_name VARCHAR(255) DEFAULT NULL,
            file_size INT UNSIGNED DEFAULT NULL,
            mime_type VARCHAR(120) DEFAULT NULL,
            visible_to_patient TINYINT(1) NOT NULL DEFAULT 0,
            result_visible_to_patient TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'completed',
            created_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_patient_documents_patient (tenant_id, patient_id, document_type),
            INDEX idx_patient_documents_portal (tenant_id, patient_id, visible_to_patient)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $column_res = $mysqli->query("SHOW COLUMNS FROM patient_documents LIKE 'result_visible_to_patient'");
    if ($column_res && $column_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE patient_documents ADD result_visible_to_patient TINYINT(1) NOT NULL DEFAULT 0 AFTER visible_to_patient");
    }
}

function patient_portal_download_upload($relative_path, $mime_type, $file_name)
{
    $relative_path = ltrim((string) $relative_path, '/\\');
    $full_path = app_protected_path_from_relative($relative_path);
    if (!$full_path || !is_file($full_path)) {
        http_response_code(404);
        echo 'Archivo no encontrado';
        exit;
    }
    header_remove('Content-Type');
    header('Content-Type: ' . ($mime_type ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes($file_name ?: basename($full_path)) . '"');
    header('Content-Length: ' . filesize($full_path));
    readfile($full_path);
    exit;
}

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
        $mysqli->query("ALTER TABLE payment_settings ADD available_session_types VARCHAR(100) NOT NULL DEFAULT 'individual' AFTER appointment_delivery_mode");
    }

    $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'available_session_durations'");
    if ($column_res && $column_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE payment_settings ADD available_session_durations VARCHAR(50) NOT NULL DEFAULT '60' AFTER available_session_types");
    }

    $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'display_effective_duration_enabled'");
    if ($column_res && $column_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE payment_settings ADD display_effective_duration_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER available_session_durations");
    }

    $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'display_duration_offset_minutes'");
    if ($column_res && $column_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE payment_settings ADD display_duration_offset_minutes TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER display_effective_duration_enabled");
    }
}

function active_session_durations($settings)
{
    $durations = [];
    foreach (explode(',', $settings['available_session_durations'] ?? '60') as $duration) {
        $duration = (int) trim($duration);
        if ($duration > 0 && $duration <= 480 && !in_array($duration, $durations, true)) {
            $durations[] = $duration;
        }
    }
    sort($durations);
    return $durations ?: [60];
}

function schedule_slot_step_minutes($settings)
{
    $durations = active_session_durations($settings);
    return max(5, min($durations));
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

    $step = schedule_slot_step_minutes($settings);
    for ($minutes = $start; $minutes <= $end; $minutes += $step) {
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

function appointment_context_professional_id($mysqli, $session_user_id, $is_admin, $requested_professional_id = 0)
{
    if (!$is_admin) {
        $assigned_professional_id = cabinet_patient_primary_professional_id($mysqli, (int) $session_user_id);
        if ($assigned_professional_id > 0) {
            return $assigned_professional_id;
        }
        if (cabinet_new_patient_booking_mode($mysqli) === 'professional_first'
            && (int) $requested_professional_id > 0
            && cabinet_active_professional_exists($mysqli, (int) $requested_professional_id)) {
            return (int) $requested_professional_id;
        }
    }
    return cabinet_resolve_professional_id($mysqli, (int) $session_user_id, (int) $session_user_id, $is_admin);
}

function patient_uses_day_first_without_professional($mysqli, $session_user_id, $is_admin, $patient_has_assigned_professional = null)
{
    if ($is_admin) {
        return false;
    }
    if ($patient_has_assigned_professional === null) {
        $patient_has_assigned_professional = cabinet_patient_primary_professional_id($mysqli, (int) $session_user_id) > 0;
    }
    return !$patient_has_assigned_professional && cabinet_new_patient_booking_mode($mysqli) === 'day_first';
}

function apply_effective_professional_settings($mysqli, $payment_settings, $session_user_id, $is_admin, $context_professional_id = 0)
{
    $professional_id = (int) $context_professional_id;
    if ($professional_id <= 0) {
        $professional_id = appointment_context_professional_id($mysqli, $session_user_id, $is_admin);
    }
    if ($professional_id > 0) {
        $payment_settings = array_merge($payment_settings, cabinet_get_effective_professional_settings($mysqli, $professional_id));
        $payment_settings['current_professional_id'] = $professional_id;
    }
    return $payment_settings;
}

function default_booking_payment_settings()
{
    return [
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
        'available_session_durations' => '60',
        'display_effective_duration_enabled' => 0,
        'display_duration_offset_minutes' => 5,
        'bonuses_enabled' => 0,
        'create_compensation_bonus_on_paid_cancel' => 1
    ];
}

function apply_plan_limits_to_booking_settings($mysqli, $payment_settings)
{
    if (!app_feature_enabled_from_db($mysqli, 'onlinePayments.enabled', false) || !app_feature_enabled_from_db($mysqli, 'payments.online', false)) {
        $payment_settings['online_payment_enabled'] = 0;
    }
    if (!app_feature_enabled_from_db($mysqli, 'bonuses.enabled', false)) {
        $payment_settings['bonuses_enabled'] = 0;
        $payment_settings['create_compensation_bonus_on_paid_cancel'] = 0;
    }
    if (!app_feature_enabled_from_db($mysqli, 'appointments.effectiveDuration', false)) {
        $payment_settings['display_effective_duration_enabled'] = 0;
    }
    return $payment_settings;
}

function load_booking_payment_settings($mysqli)
{
    $tenant_id = current_tenant_id();
    $payment_settings = default_booking_payment_settings();
    $settings_res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if ($settings_res && $settings_res->num_rows > 0) {
        ensure_payment_settings_price_columns($mysqli);
        $limit_columns = [
            'min_booking_notice_days' => "ALTER TABLE payment_settings ADD min_booking_notice_days INT UNSIGNED NOT NULL DEFAULT 2",
            'max_booking_notice_days' => "ALTER TABLE payment_settings ADD max_booking_notice_days INT UNSIGNED NOT NULL DEFAULT 40"
        ];
        foreach ($limit_columns as $column => $sql) {
            $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE '$column'");
            if ($column_res && $column_res->num_rows === 0) {
                $mysqli->query($sql);
            }
        }
        ensure_schedule_setting_columns($mysqli);
        ensure_delivery_setting_column($mysqli);
        ensure_session_setting_column($mysqli);
        ensure_bonus_tables($mysqli);
        $settings_res = $mysqli->query("
            SELECT online_payment_enabled, appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price, available_session_types, available_session_durations, display_effective_duration_enabled, display_duration_offset_minutes,
                   min_booking_notice_days, max_booking_notice_days,
                   appointment_start_time, appointment_end_time, break_start_time, break_end_time,
                   available_weekdays, appointment_delivery_mode, bonuses_enabled, create_compensation_bonus_on_paid_cancel
            FROM payment_settings
            WHERE tenant_id = $tenant_id
        ");
        if ($settings_res && ($settings_row = $settings_res->fetch_assoc())) {
            $payment_settings = array_merge($payment_settings, $settings_row);
        }
    }
    return apply_plan_limits_to_booking_settings($mysqli, $payment_settings);
}

function service_options_for_settings($mysqli, $payment_settings)
{
    $service_options = [];
    $active_durations = active_session_durations($payment_settings);
    $active_delivery_mode = $payment_settings['appointment_delivery_mode'] ?? 'both';
    $active_service_types = explode(',', $payment_settings['available_session_types'] ?? 'individual');
    foreach (fetch_appointment_services($mysqli, true) as $service) {
        if (!in_array($service['service_key'], $active_service_types, true)) {
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
    return $service_options;
}

function active_professional_exists($mysqli, $professional_id)
{
    $tenant_id = current_tenant_id();
    if ((int) $professional_id <= 0) {
        return false;
    }
    $stmt = $mysqli->prepare("SELECT id FROM professionals WHERE tenant_id = ? AND id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $professional_id);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

function resolve_booking_professional_id($mysqli, $session_user_id, $target_user_id, $is_admin, $requested_professional_id)
{
    $is_superadmin = ($_SESSION['role'] ?? '') === 'superadmin';
    if ($is_superadmin && (int) $requested_professional_id > 0 && active_professional_exists($mysqli, (int) $requested_professional_id)) {
        return (int) $requested_professional_id;
    }
    if (!$is_admin) {
        $assigned_professional_id = cabinet_patient_primary_professional_id($mysqli, (int) $target_user_id);
        if ($assigned_professional_id > 0) {
            return $assigned_professional_id;
        }
        $new_patient_booking_mode = cabinet_new_patient_booking_mode($mysqli);
        if (in_array($new_patient_booking_mode, ['professional_first', 'day_first'], true)
            && (int) $requested_professional_id > 0
            && active_professional_exists($mysqli, (int) $requested_professional_id)) {
            return (int) $requested_professional_id;
        }
    }
    return cabinet_resolve_professional_id($mysqli, (int) $session_user_id, (int) $target_user_id, $is_admin);
}

function admin_can_book_patient_for_professional($mysqli, $patient_user_id, $professional_id)
{
    $tenant_id = current_tenant_id();
    if (($_SESSION['role'] ?? '') === 'superadmin') {
        return true;
    }
    $patient_user_id = (int) $patient_user_id;
    $professional_id = (int) $professional_id;
    if ($patient_user_id <= 0 || $professional_id <= 0) {
        return false;
    }
    $stmt = $mysqli->prepare("
        SELECT u.id
        FROM users u
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id AND pp.tenant_id = u.tenant_id
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.tenant_id = u.tenant_id AND ppf.is_primary = 1
        WHERE u.tenant_id = ?
          AND u.id = ?
          AND u.role = 'patient'
          AND COALESCE(ppf.professional_id, pp.professional_id) = ?
        LIMIT 1
    ");
    $stmt->bind_param("iii", $tenant_id, $patient_user_id, $professional_id);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

function patient_booking_context_payload($mysqli, $user_id, $is_admin, $context_professional_id, $patient_has_assigned_professional)
{
    $professional_context = cabinet_professional_display_payload($mysqli, $context_professional_id);
    if ($professional_context) {
        $professional_context['is_patient_assigned'] = $patient_has_assigned_professional ? 1 : 0;
    }

    $mode = $is_admin ? '' : cabinet_new_patient_booking_mode($mysqli);
    return [
        'professional_context' => $professional_context,
        'patient_has_assigned_professional' => $patient_has_assigned_professional ? 1 : 0,
        'new_patient_booking_mode' => $mode,
        'professionals' => (!$is_admin && !$patient_has_assigned_professional && $mode === 'professional_first')
            ? cabinet_active_professionals_for_booking($mysqli)
            : []
    ];
}

function professional_can_take_slot($mysqli, $professional_id, $date, $time, $duration_minutes, $settings)
{
    $tenant_id = current_tenant_id();
    $professional_id = (int) $professional_id;
    if ($professional_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}/', $time)) {
        return false;
    }

    $booking_date = new DateTime($date);
    if (!in_array((int) $booking_date->format('N'), active_weekdays($settings), true)) {
        return false;
    }

    if (!in_array(substr($time, 0, 5), schedule_slot_list($settings), true)) {
        return false;
    }

    $new_start = minutes_from_time($time);
    $new_end = $new_start + (int) $duration_minutes;
    $day_end = minutes_from_time($settings['appointment_end_time'] ?? '19:00:00') + 60;
    if ($new_end > $day_end) {
        return false;
    }

    if (!empty($settings['break_start_time']) && !empty($settings['break_end_time'])) {
        $break_start = minutes_from_time($settings['break_start_time']);
        $break_end = minutes_from_time($settings['break_end_time']);
        if ($new_start < $break_end && $new_end > $break_start) {
            return false;
        }
    }

    $stmt = $mysqli->prepare("SELECT id FROM closed_days WHERE tenant_id = ? AND closed_date = ? AND (is_global = 1 OR professional_id = ?) LIMIT 1");
    $stmt->bind_param("isi", $tenant_id, $date, $professional_id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        return false;
    }

    $stmt = $mysqli->prepare("
        SELECT appointment_time, COALESCE(duration_minutes, 60) AS duration_minutes
        FROM appointments
        WHERE tenant_id = ? AND appointment_date = ? AND status = 'booked'
          AND professional_id = ?
    ");
    $stmt->bind_param("isi", $tenant_id, $date, $professional_id);
    $stmt->execute();
    $existing_res = $stmt->get_result();
    while ($existing = $existing_res->fetch_assoc()) {
        $existing_start = minutes_from_time($existing['appointment_time']);
        $existing_end = $existing_start + (int) ($existing['duration_minutes'] ?? 60);
        if ($new_start < $existing_end && $new_end > $existing_start) {
            return false;
        }
    }

    return true;
}

if ($action === 'patient_portal_download_document') {
    if ($is_admin) {
        http_response_code(403);
        echo 'Disponible solo para pacientes.';
        exit;
    }
    ensure_patient_portal_documents_schema($mysqli);
    $document_id = (int) ($_GET['id'] ?? 0);
    $stmt = $mysqli->prepare("
        SELECT id, file_path, original_file_name, mime_type
        FROM patient_documents
        WHERE tenant_id = ?
          AND id = ?
          AND patient_id = ?
          AND visible_to_patient = 1
        LIMIT 1
    ");
    $stmt->bind_param("iii", $tenant_id, $document_id, $user_id);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc();
    if (!$document || empty($document['file_path'])) {
        http_response_code(404);
        echo 'Archivo no encontrado';
        exit;
    }
    patient_portal_download_upload($document['file_path'], $document['mime_type'] ?? '', $document['original_file_name'] ?? '');
} elseif ($action === 'patient_portal_summary') {
    if ($is_admin) {
        echo json_encode(['success' => false, 'error' => 'Disponible solo para pacientes.']);
        exit;
    }
    ensure_patient_portal_work_plan_schema($mysqli);
    ensure_patient_portal_documents_schema($mysqli);

    $stmt = $mysqli->prepare("
        SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.cancelled_at,
               a.consultation_type, a.online_session_url,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.patient_bonus_id,
               b.name AS bonus_name,
               s.name AS service_name,
               p.display_name AS professional_name
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        LEFT JOIN professionals p ON p.id = a.professional_id AND p.tenant_id = a.tenant_id
        LEFT JOIN patient_bonuses pb ON pb.id = a.patient_bonus_id AND pb.tenant_id = a.tenant_id
        LEFT JOIN appointment_bonuses b ON b.id = pb.bonus_id AND b.tenant_id = a.tenant_id
        WHERE a.tenant_id = ?
          AND a.user_id = ?
        ORDER BY a.appointment_date DESC, a.appointment_time DESC, a.id DESC
        LIMIT 80
    ");
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $appointments = [];
    while ($row = $res->fetch_assoc()) {
        $appointments[] = [
            'id' => (int) $row['id'],
            'appointment_date' => $row['appointment_date'],
            'appointment_time' => substr((string) $row['appointment_time'], 0, 5),
            'status' => $row['status'] ?? '',
            'cancelled_at' => $row['cancelled_at'] ?? '',
            'consultation_type' => $row['consultation_type'] ?? 'presencial',
            'online_session_url' => $row['online_session_url'] ?? '',
            'duration_minutes' => (int) ($row['duration_minutes'] ?? 60),
            'payment_status' => $row['payment_status'] ?? 'pending',
            'payment_method' => $row['payment_method'] ?? '',
            'patient_bonus_id' => (int) ($row['patient_bonus_id'] ?? 0),
            'bonus_name' => $row['bonus_name'] ?? '',
            'service_label' => appointment_service_option_label($row),
            'professional_name' => $row['professional_name'] ?? ''
        ];
    }

    $stmt = $mysqli->prepare("
        SELECT t.id, t.title, t.description, t.status, t.priority, t.completed_at, t.created_at,
               p.display_name AS professional_name
        FROM patient_work_plan_tasks t
        LEFT JOIN professionals p ON p.id = t.professional_id AND p.tenant_id = t.tenant_id
        WHERE t.tenant_id = ?
          AND t.patient_id = ?
          AND t.visible_to_patient = 1
        ORDER BY CASE WHEN t.status = 'pending' THEN 0 ELSE 1 END,
                 t.priority ASC,
                 t.updated_at DESC,
                 t.id DESC
        LIMIT 80
    ");
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $tasks = [];
    while ($row = $res->fetch_assoc()) {
        $tasks[] = [
            'id' => (int) $row['id'],
            'title' => $row['title'] ?? '',
            'description' => $row['description'] ?? '',
            'status' => $row['status'] ?? 'pending',
            'priority' => (int) ($row['priority'] ?? 2),
            'completed_at' => $row['completed_at'] ?? '',
            'created_at' => $row['created_at'] ?? '',
            'professional_name' => $row['professional_name'] ?? ''
        ];
    }

    $stmt = $mysqli->prepare("
        SELECT id, document_type, title, description, document_date, score, result_label, observations, result_visible_to_patient,
               original_file_name, file_size, status, updated_at
        FROM patient_documents
        WHERE tenant_id = ?
          AND patient_id = ?
          AND visible_to_patient = 1
        ORDER BY COALESCE(document_date, DATE(updated_at), DATE(created_at)) DESC,
                 updated_at DESC,
                 id DESC
        LIMIT 80
    ");
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $documents = [];
    while ($row = $res->fetch_assoc()) {
        $document_type = ($row['document_type'] ?? '') === 'questionnaire' ? 'questionnaire' : 'file';
        $show_result = $document_type === 'questionnaire' && (int) ($row['result_visible_to_patient'] ?? 0) === 1;
        $documents[] = [
            'id' => (int) $row['id'],
            'type' => $document_type,
            'name' => $row['title'] ?: ($row['original_file_name'] ?: ($document_type === 'questionnaire' ? 'Cuestionario' : 'Archivo')),
            'file_name' => $row['original_file_name'] ?? '',
            'description' => $row['description'] ?? '',
            'date' => $row['document_date'] ?: ($row['updated_at'] ?? ''),
            'score' => $show_result ? ($row['score'] ?? '') : '',
            'result_label' => $show_result ? ($row['result_label'] ?? '') : '',
            'observations' => $show_result ? ($row['observations'] ?? '') : '',
            'result_visible_to_patient' => $show_result ? 1 : 0,
            'size' => (int) ($row['file_size'] ?? 0),
            'status' => $row['status'] ?? 'completed',
            'url' => 'api/appointments.php?action=patient_portal_download_document&id=' . (int) $row['id']
        ];
    }

    $payment_settings = load_booking_payment_settings($mysqli);
    echo json_encode([
        'success' => true,
        'appointments' => $appointments,
        'tasks' => $tasks,
        'documents' => $documents,
        'payment_settings' => [
            'online_payment_enabled' => (int) ($payment_settings['online_payment_enabled'] ?? 0),
            'bonuses_enabled' => (int) ($payment_settings['bonuses_enabled'] ?? 0),
            'display_effective_duration_enabled' => (int) ($payment_settings['display_effective_duration_enabled'] ?? 0),
            'display_duration_offset_minutes' => (int) ($payment_settings['display_duration_offset_minutes'] ?? 5)
        ]
    ]);
} elseif ($action === 'booking_context') {
    if (!$is_admin) {
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit;
    }
    $requested_professional_id = (int) ($_GET['professional_id'] ?? 0);
    $context_professional_id = resolve_booking_professional_id($mysqli, (int) $user_id, (int) $user_id, $is_admin, $requested_professional_id);
    $payment_settings = load_booking_payment_settings($mysqli);
    if ($context_professional_id > 0) {
        $payment_settings = array_merge($payment_settings, cabinet_get_effective_professional_settings($mysqli, $context_professional_id));
        $payment_settings['current_professional_id'] = $context_professional_id;
    }
    echo json_encode([
        'success' => true,
        'professional_id' => $context_professional_id,
        'professional_context' => cabinet_professional_display_payload($mysqli, $context_professional_id),
        'payment_settings' => $payment_settings,
        'service_options' => service_options_for_settings($mysqli, $payment_settings)
    ]);
} elseif ($action === 'available_professionals_for_slot') {
    if ($is_admin) {
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit;
    }

    $date = $_GET['date'] ?? '';
    $time = $_GET['time'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        echo json_encode(['success' => false, 'error' => 'Fecha u hora no valida.']);
        exit;
    }

    if (cabinet_patient_primary_professional_id($mysqli, (int) $user_id) > 0 || cabinet_new_patient_booking_mode($mysqli) !== 'day_first') {
        echo json_encode(['success' => true, 'professionals' => []]);
        exit;
    }

    $base_settings = load_booking_payment_settings($mysqli);
    $available_professionals = [];
    foreach (cabinet_active_professionals_for_booking($mysqli) as $professional) {
        $professional_id = (int) ($professional['id'] ?? 0);
        $settings = array_merge($base_settings, cabinet_get_effective_professional_settings($mysqli, $professional_id));
        $settings['current_professional_id'] = $professional_id;
        $service_options = [];
        foreach (service_options_for_settings($mysqli, $settings) as $option) {
            if (professional_can_take_slot($mysqli, $professional_id, $date, $time, (int) $option['duration_minutes'], $settings)) {
                $service_options[] = $option;
            }
        }
        if ($service_options) {
            $professional['service_options'] = $service_options;
            $available_professionals[] = $professional;
        }
    }

    echo json_encode(['success' => true, 'professionals' => $available_professionals]);
} elseif ($action === 'get_month') {
    $month = $_GET['month'] ?? date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-01$/', $month)) {
        $month = date('Y-m-01');
    }
    $start_date = date('Y-m-01', strtotime($month));
    $end_date = date('Y-m-t', strtotime($month));
    $requested_professional_id = (int) ($_GET['professional_id'] ?? 0);
    $patient_has_assigned_professional = !$is_admin && cabinet_patient_primary_professional_id($mysqli, (int) $user_id) > 0;
    $is_day_first_unassigned = patient_uses_day_first_without_professional($mysqli, $user_id, $is_admin, $patient_has_assigned_professional);
    $context_professional_id = $is_day_first_unassigned ? 0 : appointment_context_professional_id($mysqli, $user_id, $is_admin, $requested_professional_id);

    $appointments = [];
    if (!$is_day_first_unassigned) {
        $stmt = $mysqli->prepare("
            SELECT a.id, a.appointment_date, a.appointment_time, a.user_id,
                   COALESCE(a.payment_status, 'pending') AS payment_status,
                   a.payment_method, a.paid_at, a.patient_bonus_id, a.consultation_type, a.service_type,
                   COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
                   so.price AS service_price, s.name AS service_name, s.service_key,
                   u.name, u.email, u.phone
            FROM appointments a
            LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
            LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
            JOIN users u ON a.user_id = u.id AND u.tenant_id = a.tenant_id
            WHERE a.tenant_id = ? AND a.appointment_date BETWEEN ? AND ? AND a.status = 'booked'
              AND a.professional_id = ?
        ");
        $stmt->bind_param("issi", $tenant_id, $start_date, $end_date, $context_professional_id);
        $stmt->execute();
        $appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    if ($is_day_first_unassigned) {
        $stmt2 = $mysqli->prepare("SELECT closed_date, reason FROM closed_days WHERE tenant_id = ? AND closed_date BETWEEN ? AND ? AND is_global = 1");
        $stmt2->bind_param("iss", $tenant_id, $start_date, $end_date);
    } else {
        $stmt2 = $mysqli->prepare("SELECT closed_date, reason FROM closed_days WHERE tenant_id = ? AND closed_date BETWEEN ? AND ? AND (is_global = 1 OR professional_id = ?)");
        $stmt2->bind_param("issi", $tenant_id, $start_date, $end_date, $context_professional_id);
    }
    $stmt2->execute();
    $closed_days_fetch = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

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
            'patient_bonus_id' => $app['patient_bonus_id'] ?? null,
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
        'available_session_durations' => '60',
        'display_effective_duration_enabled' => 0,
        'display_duration_offset_minutes' => 5,
        'bonuses_enabled' => 0,
        'create_compensation_bonus_on_paid_cancel' => 1
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
        ensure_bonus_tables($mysqli);
        $settings_res = $mysqli->query("
            SELECT online_payment_enabled, appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price, available_session_types, available_session_durations, display_effective_duration_enabled, display_duration_offset_minutes,
                   min_booking_notice_days, max_booking_notice_days,
                   appointment_start_time, appointment_end_time, break_start_time, break_end_time,
                   available_weekdays, appointment_delivery_mode, bonuses_enabled, create_compensation_bonus_on_paid_cancel
            FROM payment_settings
            WHERE tenant_id = $tenant_id
        ");
        if ($settings_row = $settings_res->fetch_assoc()) {
            $payment_settings = $settings_row;
        }
    }
    if (!$is_day_first_unassigned) {
        $payment_settings = apply_effective_professional_settings($mysqli, $payment_settings, $user_id, $is_admin, $context_professional_id);
    }
    $payment_settings = apply_plan_limits_to_booking_settings($mysqli, $payment_settings);

    $service_options = [];
    $active_durations = active_session_durations($payment_settings);
    $active_delivery_mode = $payment_settings['appointment_delivery_mode'] ?? 'both';
    $active_service_types = explode(',', $payment_settings['available_session_types'] ?? 'individual');
    foreach (fetch_appointment_services($mysqli, true) as $service) {
        if (!in_array($service['service_key'], $active_service_types, true)) {
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

    $patient_booking_context = patient_booking_context_payload($mysqli, (int) $user_id, $is_admin, $context_professional_id, $patient_has_assigned_professional);

    echo json_encode([
        'success' => true,
        'month' => $start_date,
        'appointments' => $apps_map,
        'closed_days' => $closed_days,
        'payment_settings' => $payment_settings,
        'service_options' => $service_options,
        'professional_context' => $patient_booking_context['professional_context'],
        'patient_has_assigned_professional' => $patient_booking_context['patient_has_assigned_professional'],
        'new_patient_booking_mode' => $patient_booking_context['new_patient_booking_mode'],
        'professionals' => $patient_booking_context['professionals']
    ]);

} elseif ($action === 'get_week') {
    // start_date expected to be a Monday (YYYY-MM-DD)
    $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('monday this week'));
    $end_date = date('Y-m-d', strtotime($start_date . ' +5 days')); // Saturday when enabled
    $requested_professional_id = (int) ($_GET['professional_id'] ?? 0);
    $patient_has_assigned_professional = !$is_admin && cabinet_patient_primary_professional_id($mysqli, (int) $user_id) > 0;
    $is_day_first_unassigned = patient_uses_day_first_without_professional($mysqli, $user_id, $is_admin, $patient_has_assigned_professional);
    $context_professional_id = $is_day_first_unassigned ? 0 : appointment_context_professional_id($mysqli, $user_id, $is_admin, $requested_professional_id);

    // Get appointments in range
    $appointments = [];
    if (!$is_day_first_unassigned) {
        $stmt = $mysqli->prepare("
            SELECT a.id, a.appointment_date, a.appointment_time, a.user_id,
                   COALESCE(a.payment_status, 'pending') AS payment_status,
                   a.payment_method, a.paid_at, a.patient_bonus_id, a.consultation_type, a.service_type,
                   COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
                   so.price AS service_price, s.name AS service_name, s.service_key,
                   u.name, u.email, u.phone
            FROM appointments a
            LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
            LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
            JOIN users u ON a.user_id = u.id AND u.tenant_id = a.tenant_id
            WHERE a.tenant_id = ? AND a.appointment_date BETWEEN ? AND ? AND a.status = 'booked'
              AND a.professional_id = ?
        ");
        $stmt->bind_param("issi", $tenant_id, $start_date, $end_date, $context_professional_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $appointments = $res->fetch_all(MYSQLI_ASSOC);
    }

    // Get closed days
    if ($is_day_first_unassigned) {
        $stmt2 = $mysqli->prepare("SELECT closed_date, reason FROM closed_days WHERE tenant_id = ? AND closed_date BETWEEN ? AND ? AND is_global = 1");
        $stmt2->bind_param("iss", $tenant_id, $start_date, $end_date);
    } else {
        $stmt2 = $mysqli->prepare("SELECT closed_date, reason FROM closed_days WHERE tenant_id = ? AND closed_date BETWEEN ? AND ? AND (is_global = 1 OR professional_id = ?)");
        $stmt2->bind_param("issi", $tenant_id, $start_date, $end_date, $context_professional_id);
    }
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
            'patient_bonus_id' => $app['patient_bonus_id'] ?? null,
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
        'available_session_durations' => '60',
        'display_effective_duration_enabled' => 0,
        'display_duration_offset_minutes' => 5,
        'bonuses_enabled' => 0,
        'create_compensation_bonus_on_paid_cancel' => 1
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
        ensure_bonus_tables($mysqli);
        $settings_res = $mysqli->query("
            SELECT online_payment_enabled, appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price, available_session_types, available_session_durations, display_effective_duration_enabled, display_duration_offset_minutes,
                   min_booking_notice_days, max_booking_notice_days,
                   appointment_start_time, appointment_end_time, break_start_time, break_end_time,
                   available_weekdays, appointment_delivery_mode, bonuses_enabled, create_compensation_bonus_on_paid_cancel
            FROM payment_settings
            WHERE tenant_id = $tenant_id
        ");
        if ($settings_row = $settings_res->fetch_assoc()) {
            $payment_settings = $settings_row;
        }
    }
    if (!$is_day_first_unassigned) {
        $payment_settings = apply_effective_professional_settings($mysqli, $payment_settings, $user_id, $is_admin, $context_professional_id);
    }
    $payment_settings = apply_plan_limits_to_booking_settings($mysqli, $payment_settings);

    $service_options = [];
    $active_durations = active_session_durations($payment_settings);
    $active_delivery_mode = $payment_settings['appointment_delivery_mode'] ?? 'both';
    $active_service_types = explode(',', $payment_settings['available_session_types'] ?? 'individual');
    foreach (fetch_appointment_services($mysqli, true) as $service) {
        if (!in_array($service['service_key'], $active_service_types, true)) {
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

    $patient_booking_context = patient_booking_context_payload($mysqli, (int) $user_id, $is_admin, $context_professional_id, $patient_has_assigned_professional);

    echo json_encode([
        'success' => true,
        'appointments' => $apps_map,
        'closed_days' => $closed_days,
        'payment_settings' => $payment_settings,
        'service_options' => $service_options,
        'professional_context' => $patient_booking_context['professional_context'],
        'patient_has_assigned_professional' => $patient_booking_context['patient_has_assigned_professional'],
        'new_patient_booking_mode' => $patient_booking_context['new_patient_booking_mode'],
        'professionals' => $patient_booking_context['professionals']
    ]);

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

    $target_user_id = (int) $target_user_id;
    $requested_professional_id = (int) ($_POST['professional_id'] ?? 0);
    $booking_professional_id = resolve_booking_professional_id($mysqli, (int) $user_id, $target_user_id, $is_admin, $requested_professional_id);
    if ($booking_professional_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'No se pudo asignar un profesional a la cita.']);
        exit;
    }
    if ($is_admin && !admin_can_book_patient_for_professional($mysqli, $target_user_id, $booking_professional_id)) {
        echo json_encode(['success' => false, 'error' => 'No tienes permiso para reservar citas de este paciente.']);
        exit;
    }

    $settings = load_booking_payment_settings($mysqli);
    $settings = array_merge($settings, cabinet_get_effective_professional_settings($mysqli, $booking_professional_id));
    $settings['current_professional_id'] = $booking_professional_id;
    $min_booking_notice_days = (int) ($settings['min_booking_notice_days'] ?? 2);
    $max_booking_notice_days = (int) ($settings['max_booking_notice_days'] ?? MAX_BOOKING_DAYS);
    $appointment_delivery_mode = $settings['appointment_delivery_mode'] ?? 'both';

    $service_option = $service_option_id > 0 ? fetch_service_option($mysqli, $service_option_id) : null;
    if (!$service_option || (int) $service_option['is_active'] !== 1 || (int) $service_option['service_active'] !== 1) {
        echo json_encode(['success' => false, 'error' => 'El servicio seleccionado no está disponible.']);
        exit;
    }
    $consultation_type = $service_option['consultation_type'];
    $service_type = $service_option['service_key'];
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

    $available_session_types = explode(',', $settings['available_session_types'] ?? 'individual');
    if (!in_array($service_type, $available_session_types, true)) {
        echo json_encode(['success' => false, 'error' => 'El servicio seleccionado no está disponible.']);
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
    $stmt = $mysqli->prepare("SELECT id FROM closed_days WHERE tenant_id = ? AND closed_date = ? AND (is_global = 1 OR professional_id = ?)");
    $stmt->bind_param("isi", $tenant_id, $date, $booking_professional_id);
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
    $day_end = minutes_from_time($settings['appointment_end_time'] ?? '19:00:00') + 60;
    if ($new_end > $day_end) {
        echo json_encode(['success' => false, 'error' => 'La duración seleccionada no cabe en el horario disponible.']);
        exit;
    }
    if (!empty($settings['break_start_time']) && !empty($settings['break_end_time'])) {
        $break_start = minutes_from_time($settings['break_start_time']);
        $break_end = minutes_from_time($settings['break_end_time']);
        if ($new_start < $break_end && $new_end > $break_start) {
            echo json_encode(['success' => false, 'error' => 'La duración seleccionada se solapa con el descanso.']);
            exit;
        }
    }
    $stmt = $mysqli->prepare("
        SELECT appointment_time, COALESCE(duration_minutes, 60) AS duration_minutes
        FROM appointments
        WHERE tenant_id = ? AND appointment_date = ? AND status = 'booked'
          AND professional_id = ?
    ");
    $stmt->bind_param("isi", $tenant_id, $date, $booking_professional_id);
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
        $professional_id = $booking_professional_id;
        $professional = cabinet_fetch_professional($mysqli, $professional_id);
        $stmt = $mysqli->prepare("INSERT INTO appointments (tenant_id, user_id, professional_id, appointment_date, appointment_time, consultation_type, service_type, service_option_id, duration_minutes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'booked')");
        $nullable_service_option_id = $service_option ? $service_option_id : null;
        $stmt->bind_param("iiissssii", $tenant_id, $target_user_id, $professional_id, $date, $time, $consultation_type, $service_type, $nullable_service_option_id, $duration_minutes);
        $stmt->execute();
        $appointment_id = $mysqli->insert_id;
        if (!$is_admin) {
            cabinet_assign_patient_to_professional_if_missing($mysqli, (int) $target_user_id, (int) $professional_id);
        }
        $cancel_token = bin2hex(random_bytes(32));
        $stmt = $mysqli->prepare("UPDATE appointments SET cancel_token = ? WHERE tenant_id = ? AND id = ?");
        $stmt->bind_param("sii", $cancel_token, $tenant_id, $appointment_id);
        $stmt->execute();
        $bonus_claim = null;
        if ($service_type === 'individual') {
            $bonus_claim = claim_patient_bonus_session($mysqli, (int) $target_user_id);
            if ($bonus_claim) {
                $payment_method = 'bonus';
                $payment_status = 'paid';
                $stmt = $mysqli->prepare("UPDATE appointments SET patient_bonus_id = ?, payment_status = ?, payment_method = ?, paid_at = NOW() WHERE tenant_id = ? AND id = ?");
                $stmt->bind_param("issii", $bonus_claim['patient_bonus_id'], $payment_status, $payment_method, $tenant_id, $appointment_id);
                $stmt->execute();
            }
        }

        $stmt = $mysqli->prepare("SELECT name, email, phone FROM users WHERE tenant_id = ? AND id = ?");
        $stmt->bind_param("ii", $tenant_id, $target_user_id);
        $stmt->execute();
        $patient = $stmt->get_result()->fetch_assoc();
        $appointment_text = appointment_label($date, $time);
        $consultation_text = appointment_consultation_label($consultation_type);
        $service_text = $service_option ? $service_option['service_name'] . ' (' . $duration_minutes . ' min)' : appointment_service_label($service_type);
        $price_settings = null;
        $appointment_price_text = null;
        $send_patient_calendar_link = 1;
        $settings_res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
        if ($settings_res->num_rows > 0) {
            ensure_payment_settings_price_columns($mysqli);
            $calendar_link_column = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'send_patient_calendar_link'");
            if ($calendar_link_column && $calendar_link_column->num_rows === 0) {
                $mysqli->query("ALTER TABLE payment_settings ADD send_patient_calendar_link TINYINT(1) NOT NULL DEFAULT 1");
            }
            ensure_session_setting_column($mysqli);
            $settings_res = $mysqli->query("SELECT online_payment_enabled, appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price, send_patient_calendar_link, display_effective_duration_enabled, display_duration_offset_minutes FROM payment_settings WHERE tenant_id = $tenant_id");
            $price_settings = $settings_res->fetch_assoc();
            if ($price_settings) {
                $price_settings = apply_plan_limits_to_booking_settings($mysqli, $price_settings);
            }
            $send_patient_calendar_link = (int) ($price_settings['send_patient_calendar_link'] ?? 1);
            if (!app_feature_enabled_from_db($mysqli, 'calendarSync.enabled', false)) {
                $send_patient_calendar_link = 0;
            }
            if ($bonus_claim) {
                $appointment_price_text = null;
            } elseif ($price_settings) {
                $appointment_price_text = format_appointment_price($service_option ? $service_option['price'] : appointment_price_for_type($price_settings, $consultation_type, $service_type));
            }
        }
        $display_duration_note = ($price_settings && (int) ($price_settings['display_effective_duration_enabled'] ?? 0) === 1)
            ? '<p><b>Duraci&oacute;n:</b> ' . (int) appointment_display_duration_minutes($duration_minutes, $price_settings) . ' minutos</p>'
            : '';
        $display_service_text = appointment_display_service_label($service_text, $duration_minutes, $price_settings ?: []);

        if (app_feature_enabled_from_db($mysqli, 'calendarSync.enabled', false)) {
            try {
                google_create_calendar_event($mysqli, $appointment_id);
            } catch (\Exception $e) {
                error_log('No se pudo crear evento en Google Calendar: ' . $e->getMessage());
            }

            try {
                icloud_create_calendar_event($mysqli, $appointment_id);
            } catch (\Exception $e) {
                error_log('No se pudo crear evento en iCloud Calendar: ' . $e->getMessage());
            }
        }

        $appointment_for_notification = [
            'professional_id' => $professional_id
        ];
        $professional_line = $professional
            ? '<b>Profesional:</b> ' . htmlspecialchars($professional['display_name']) . '<br>'
            : '';

        notify_appointment_professional(
            $mysqli,
            $appointment_for_notification,
            'Nueva cita reservada',
            '<p>Se ha reservado una nueva cita.</p>' .
            '<p><b>Paciente:</b> ' . htmlspecialchars($patient['name'] ?? '') . '<br>' .
            $professional_line .
            '<b>Fecha:</b> ' . htmlspecialchars($appointment_text) . '<br>' .
            '<b>Servicio:</b> ' . htmlspecialchars($display_service_text) . '<br>' .
            ($display_duration_note ? '<b>Duraci&oacute;n visible:</b> ' . (int) appointment_display_duration_minutes($duration_minutes, $price_settings) . ' minutos<br>' : '') .
            '<b>Modalidad:</b> ' . htmlspecialchars($consultation_text) . '<br>' .
            ($bonus_claim ? '<b>Bono:</b> Incluida con bono (' . (int) $bonus_claim['remaining_after'] . ' sesiones restantes)<br>' : '') .
            ($appointment_price_text !== null ? '<b>Importe:</b> ' . htmlspecialchars($appointment_price_text) . ' &euro;<br>' : '') .
            '<b>Email:</b> ' . htmlspecialchars($patient['email'] ?? 'Sin email') . '<br>' .
            '<b>Teléfono:</b> ' . htmlspecialchars($patient['phone'] ?? 'Sin teléfono') . '</p>',
            $patient['email'] ?? null,
            true
        );

        if (!empty($patient['email'])) {
            $manage_link = urlme_shorten_url(app_public_base_url() . 'cancelar_cita.php?t=' . $cancel_token, 'Gestionar reserva SimplyGest Praxis');
            $calendar_link = urlme_shorten_url(app_public_base_url() . 'appointment_ics.php?t=' . $cancel_token, 'Anadir cita al calendario SimplyGest Praxis');
            $payment_note = '<p>Recuerda que puedes pagar directamente en la consulta.</p>';
            if ($bonus_claim) {
                $payment_note = '<p><b>Bono:</b> esta cita queda incluida en tu bono. Te quedan ' . (int) $bonus_claim['remaining_after'] . ' sesiones.</p>';
            } elseif ($price_settings && (int) $price_settings['online_payment_enabled'] === 1) {
                $payment_note = '<p><b>Importante:</b> este email confirma la reserva de la cita, pero no confirma el pago. Recibirás otro email cuando el pago se complete correctamente.</p>';
            }

            send_app_email(
                $patient['email'],
                'Cita reservada',
                '<p>Hola ' . htmlspecialchars($patient['name']) . ',</p>' .
                ($professional ? '<p><b>Tu cita con ' . htmlspecialchars($professional['display_name']) . '</b></p>' : '') .
                '<p>Tu cita ' . htmlspecialchars(strtolower($display_service_text)) . ' ' . htmlspecialchars(strtolower($consultation_text)) . ' para el ' . htmlspecialchars($appointment_text) . ' ha quedado reservada correctamente.</p>' .
                '<p><b>Servicio:</b> ' . htmlspecialchars($display_service_text) . '</p>' .
                '<p><b>Modalidad:</b> ' . htmlspecialchars($consultation_text) . '</p>' .
                $display_duration_note .
                ($appointment_price_text !== null ? '<p><b>Importe:</b> ' . htmlspecialchars($appointment_price_text) . ' &euro;</p>' : '') .
                $payment_note .
                '<p>Por favor, si no puedes asistir te rogamos gestionar tu cita directamente en la web.</p>' .
                '<p><a href="' . htmlspecialchars($manage_link) . '">Gestionar reserva</a></p>' .
                ($send_patient_calendar_link ? '<p>A&ntilde;ade esta cita a tu calendario <a href="' . htmlspecialchars($calendar_link) . '">aqu&iacute;</a>.</p>' : ''),
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
            'price' => $service_option ? number_format((float) $service_option['price'], 2, '.', '') : null,
            'bonus_applied' => $bonus_claim ? 1 : 0,
            'bonus_remaining' => $bonus_claim ? (int) $bonus_claim['remaining_after'] : null
        ]);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => 'El horario ya está ocupado']);
    }

} elseif ($action === 'get_bonus_balance') {
    $target_user_id = $is_admin ? (int) ($_GET['user_id'] ?? 0) : (int) $user_id;
    if (!$target_user_id) {
        echo json_encode(['success' => false, 'error' => 'Selecciona un paciente.']);
        exit;
    }

    $enabled = bonuses_are_enabled($mysqli);
    $balance = fetch_patient_bonus_balance($mysqli, $target_user_id);
    echo json_encode([
        'success' => true,
        'bonuses_enabled' => $enabled ? 1 : 0,
        'total_remaining' => (int) $balance['total_remaining'],
        'bonuses' => $balance['bonuses']
    ]);

} elseif ($action === 'cancel') {
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';
    $is_superadmin = ($_SESSION['role'] ?? '') === 'superadmin';
    $create_compensation_bonus = true;
    if ($is_admin && isset($_POST['create_compensation_bonus'])) {
        $create_compensation_bonus = $_POST['create_compensation_bonus'] === '1';
    }
    $context_professional_id = appointment_context_professional_id($mysqli, $user_id, $is_admin);

    $lookup_sql = "
        SELECT a.id, a.appointment_date, a.appointment_time, a.consultation_type, a.service_type,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               s.name AS service_name,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.payment_attempt_id, a.patient_bonus_id,
               a.user_id, a.professional_id,
               u.name, u.email
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
        JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
        WHERE a.tenant_id = ? AND a.status = 'booked'
    ";
    $bind_types = 'i';
    $bind_values = [$tenant_id];
    if ($appointment_id > 0) {
        $lookup_sql .= " AND a.id = ?";
        $bind_types .= 'i';
        $bind_values[] = $appointment_id;
    } else {
        $lookup_sql .= " AND a.appointment_date = ? AND a.appointment_time = ?";
        $bind_types .= 'ss';
        $bind_values[] = $date;
        $bind_values[] = $time;
    }
    if (!$is_admin) {
        $lookup_sql .= " AND a.user_id = ?";
        $bind_types .= 'i';
        $bind_values[] = (int) $user_id;
    } elseif (!$is_superadmin) {
        $lookup_sql .= " AND a.professional_id = ?";
        $bind_types .= 'i';
        $bind_values[] = (int) $context_professional_id;
    }
    $lookup_stmt = $mysqli->prepare($lookup_sql);
    $bind_refs = [];
    foreach ($bind_values as $key => &$value) {
        $bind_refs[$key] = &$value;
    }
    call_user_func_array([$lookup_stmt, 'bind_param'], array_merge([$bind_types], $bind_refs));
    $lookup_stmt->execute();
    $appointment_to_cancel = $lookup_stmt->get_result()->fetch_assoc();

    if (!$appointment_to_cancel) {
        echo json_encode(['success' => false, 'error' => 'No se pudo cancelar o no tienes permiso']);
        exit;
    }

    if ($appointment_to_cancel) {
        try {
            google_delete_calendar_event($mysqli, (int) $appointment_to_cancel['id']);
        } catch (\Exception $e) {
            error_log('No se pudo eliminar evento en Google Calendar: ' . $e->getMessage());
        }
        try {
            icloud_delete_calendar_event($mysqli, (int) $appointment_to_cancel['id']);
        } catch (\Exception $e) {
            error_log('No se pudo eliminar evento en iCloud Calendar: ' . $e->getMessage());
        }
    }

    $stmt = $mysqli->prepare("UPDATE appointments SET status = 'cancelled', cancelled_at = NOW() WHERE tenant_id = ? AND id = ? AND status = 'booked'");
    $cancel_id = (int) $appointment_to_cancel['id'];
    $stmt->bind_param("ii", $tenant_id, $cancel_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        if (!empty($appointment_to_cancel['patient_bonus_id']) && ($appointment_to_cancel['payment_method'] ?? '') === 'bonus') {
            $appointment_to_cancel['bonus_session_restored'] = restore_patient_bonus_session($mysqli, (int) $appointment_to_cancel['patient_bonus_id']) ? 1 : 0;
        }
        if (compensation_bonus_on_paid_cancel_enabled($mysqli)
            && $create_compensation_bonus
            && ($appointment_to_cancel['payment_status'] ?? '') === 'paid'
            && in_array(($appointment_to_cancel['payment_method'] ?? ''), ['card', 'bizum'], true)
        ) {
            try {
                create_compensation_bonus_for_user(
                    $mysqli,
                    (int) $appointment_to_cancel['user_id'],
                    !empty($appointment_to_cancel['payment_attempt_id']) ? (int) $appointment_to_cancel['payment_attempt_id'] : null
                );
                $appointment_to_cancel['compensation_bonus_created'] = 1;
            } catch (\Exception $e) {
                error_log('No se pudo crear vale por cancelacion: ' . $e->getMessage());
            }
        }
        notify_appointment_cancelled($mysqli, $appointment_to_cancel);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No se pudo cancelar o no tienes permiso']);
    }
}
