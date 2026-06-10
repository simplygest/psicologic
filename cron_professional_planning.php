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
require_once 'cabinet_helpers.php';
require_once 'fastcron_helpers.php';

ensure_appointment_payment_columns($mysqli);
ensure_appointment_services_tables($mysqli);
ensure_cabinet_schema($mysqli);

$daily_professionals = fastcron_count_daily_planning_professionals($mysqli);
if ($daily_professionals === 0) {
    $sync = fastcron_sync_professional_planning_cron($mysqli, '', false);
    echo json_encode([
        'success' => true,
        'enabled' => false,
        'sent' => 0,
        'message' => 'No hay profesionales con resumen diario activo.',
        'cron_sync' => $sync['action'] ?? 'unchanged'
    ]);
    exit;
}

$mode = $_GET['mode'] ?? ($_POST['mode'] ?? '');
if ($mode === '') {
    $hour = (int) date('G');
    if ($hour >= 5 && $hour < 12) {
        $mode = 'today_morning';
    } elseif ($hour >= 16 && $hour <= 23) {
        $mode = 'tomorrow_evening';
    } else {
        echo json_encode([
            'success' => true,
            'enabled' => true,
            'sent' => 0,
            'message' => 'Fuera de la ventana de envio de planning diario.',
            'current_hour' => $hour
        ]);
        exit;
    }
}

if (!in_array($mode, ['today_morning', 'tomorrow_evening'], true)) {
    echo json_encode(['success' => false, 'error' => 'Modo de envio no valido']);
    exit;
}

$system_email = get_admin_notification_email($mysqli);
if ($system_email === '') {
    echo json_encode(['success' => true, 'enabled' => false, 'sent' => 0, 'message' => 'No hay cuenta de email del sistema configurada']);
    exit;
}

$target = new DateTime('today');
$period_label = 'hoy';
$period_label_html = 'hoy';
$subject = 'Planning de citas de hoy';
if ($mode === 'tomorrow_evening') {
    $target->modify('+1 day');
    $period_label = 'manana';
    $period_label_html = 'ma&ntilde;ana';
    $subject = 'Planning de citas de manana';
}
$target_date = $target->format('Y-m-d');

$stmt = $mysqli->prepare("
    SELECT p.id, p.display_name, p.public_email, u.email AS user_email
    FROM professionals p
    LEFT JOIN users u ON u.id = p.user_id
    WHERE p.is_active = 1
      AND p.appointment_summary_email_mode = ?
    ORDER BY p.sort_order ASC, p.display_name ASC
");
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'No se pudo preparar la consulta de profesionales']);
    exit;
}
$stmt->bind_param('s', $mode);
$stmt->execute();
$professionals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$sent = 0;
$failed = 0;
$skipped = 0;

foreach ($professionals as $professional) {
    $email = trim($professional['public_email'] ?: ($professional['user_email'] ?? ''));
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $skipped++;
        continue;
    }

    $summary = professional_appointments_summary_table($mysqli, (int) $professional['id'], $target_date, 1);
    $body =
        '<p>Hola ' . htmlspecialchars($professional['display_name'] ?? '') . ',</p>' .
        '<p>Te enviamos el resumen de tus citas para ' . $period_label_html . '.</p>' .
        $summary;

    if (send_app_email($email, $subject, $body, null, $mysqli)) {
        $sent++;
    } else {
        $failed++;
    }
}

echo json_encode([
    'success' => true,
    'mode' => $mode,
    'period' => $period_label,
    'target_date' => $target_date,
    'checked' => count($professionals),
    'sent' => $sent,
    'failed' => $failed,
    'skipped' => $skipped
]);
