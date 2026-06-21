<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payment_helpers.php';
require_once __DIR__ . '/mail_helpers.php';
require_once __DIR__ . '/caldav_helpers.php';

$token = trim($_GET['t'] ?? '');
if ($token === '') {
    http_response_code(404);
    exit('Cita no encontrada');
}

ensure_appointment_payment_columns($mysqli);
ensure_appointment_services_tables($mysqli);

$stmt = $mysqli->prepare("
    SELECT a.appointment_date, a.appointment_time, a.consultation_type, a.service_type, a.online_session_url,
           COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
           s.name AS service_name,
           u.name, u.email, u.phone
    FROM appointments a
    JOIN users u ON u.id = a.user_id
    LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
    LEFT JOIN appointment_services s ON s.id = so.service_id
    WHERE a.cancel_token = ? AND a.status = 'booked'
    LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();

if (!$appointment) {
    http_response_code(404);
    exit('Cita no encontrada');
}

$service_text = appointment_service_option_label($appointment);
$consultation_text = appointment_consultation_label($appointment['consultation_type'] ?? 'presencial');
$patient_name = trim($appointment['name'] ?? '');
$summary = 'Cita ' . $service_text . ' ' . $consultation_text;
$description = 'Cita ' . strtolower($service_text) . ' ' . strtolower($consultation_text);
if ($patient_name !== '') {
    $description .= ' con ' . $patient_name;
}
$online_session_url = trim($appointment['online_session_url'] ?? '');
if (($appointment['consultation_type'] ?? '') === 'online' && $online_session_url !== '') {
    $description .= "\nEnlace de videollamada: " . $online_session_url;
}

$start = $appointment['appointment_date'] . ' ' . $appointment['appointment_time'];
$end = (new DateTimeImmutable($start, new DateTimeZone('Atlantic/Canary')))
    ->modify('+' . (int) ($appointment['duration_minutes'] ?? 60) . ' minutes')
    ->format('Y-m-d H:i:s');

$uid = 'appointment-' . hash('sha256', app_current_tenant_key() . ':' . $token) . '@simplygest-praxis';
$ics = caldav_build_ics($uid, $summary, $description, $start, $end, 'Atlantic/Canary', $online_session_url);
$filename = 'cita-' . date('Ymd-His', strtotime($start)) . '.ics';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo $ics;
