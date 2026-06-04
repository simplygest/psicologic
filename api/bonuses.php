<?php
session_start();
require_once '../db.php';
require_once '../settings_helpers.php';
require_once '../payment_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$action = $_GET['action'] ?? '';
$user_id = (int) $_SESSION['user_id'];
$is_admin = in_array(($_SESSION['role'] ?? ''), ['admin', 'superadmin'], true);

if (!$is_admin && !online_booking_enabled($mysqli)) {
    echo json_encode(['success' => false, 'error' => 'El área de pacientes no está disponible en este momento.']);
    exit;
}

ensure_bonus_tables($mysqli);
ensure_payment_attempts_table($mysqli);

function format_bonus_row($row)
{
    $row['id'] = (int) $row['id'];
    $row['user_id'] = (int) $row['user_id'];
    $row['bonus_id'] = (int) $row['bonus_id'];
    $row['total_sessions'] = (int) $row['total_sessions'];
    $row['remaining_sessions'] = (int) $row['remaining_sessions'];
    $row['amount_paid'] = isset($row['amount_paid']) ? number_format(((int) $row['amount_paid']) / 100, 2, '.', '') : null;
    return $row;
}

function standard_individual_session_price($mysqli)
{
    ensure_appointment_services_tables($mysqli);
    $res = $mysqli->query("
        SELECT o.price
        FROM appointment_service_options o
        JOIN appointment_services s ON s.id = o.service_id
        WHERE s.service_key = 'individual'
          AND s.is_active = 1
          AND o.is_active = 1
          AND o.duration_minutes = 60
        ORDER BY CASE WHEN o.consultation_type = 'presencial' THEN 0 ELSE 1 END, o.sort_order ASC, o.id ASC
        LIMIT 1
    ");
    if ($res && ($row = $res->fetch_assoc())) {
        return (float) $row['price'];
    }
    return 70.00;
}

if ($action === 'catalog') {
    $enabled = bonuses_are_enabled($mysqli);
    $bonuses = $enabled ? fetch_appointment_bonuses($mysqli, true) : [];
    $standard_price = standard_individual_session_price($mysqli);
    foreach ($bonuses as &$bonus) {
        $regular_total = $standard_price * (int) $bonus['session_count'];
        $bonus['regular_total'] = number_format($regular_total, 2, '.', '');
        $bonus['savings'] = number_format(max(0, $regular_total - (float) $bonus['price']), 2, '.', '');
    }
    unset($bonus);

    echo json_encode([
        'success' => true,
        'bonuses_enabled' => $enabled ? 1 : 0,
        'bonuses' => $bonuses
    ]);
    exit;
}

if ($action === 'my_bonuses') {
    $stmt = $mysqli->prepare("
        SELECT pb.id, pb.user_id, pb.bonus_id, pb.total_sessions, pb.remaining_sessions, pb.status,
               pb.purchased_at, pb.expires_at, b.name, pa.amount_cents AS amount_paid, pa.payment_method
        FROM patient_bonuses pb
        JOIN appointment_bonuses b ON b.id = pb.bonus_id
        LEFT JOIN payment_attempts pa ON pa.id = pb.payment_attempt_id
        WHERE pb.user_id = ?
        ORDER BY pb.purchased_at DESC, pb.id DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $bonuses = [];
    while ($row = $res->fetch_assoc()) {
        $bonuses[] = format_bonus_row($row);
    }
    echo json_encode(['success' => true, 'bonuses' => $bonuses]);
    exit;
}

if ($action === 'admin_list') {
    if (!$is_admin) {
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit;
    }

    $res = $mysqli->query("
        SELECT pb.id, pb.user_id, pb.bonus_id, pb.total_sessions, pb.remaining_sessions, pb.status,
               pb.purchased_at, pb.expires_at, b.name, pa.amount_cents AS amount_paid, pa.payment_method,
               u.name AS patient_name, u.email AS patient_email, u.phone AS patient_phone
        FROM patient_bonuses pb
        JOIN appointment_bonuses b ON b.id = pb.bonus_id
        JOIN users u ON u.id = pb.user_id
        LEFT JOIN payment_attempts pa ON pa.id = pb.payment_attempt_id
        ORDER BY pb.purchased_at DESC, pb.id DESC
    ");
    $bonuses = [];
    while ($row = $res->fetch_assoc()) {
        $bonuses[] = format_bonus_row($row);
    }
    echo json_encode(['success' => true, 'bonuses' => $bonuses]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Accion invalida']);
