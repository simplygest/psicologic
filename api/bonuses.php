<?php
session_start();
require_once '../db.php';
require_once '../settings_helpers.php';
require_once '../payment_helpers.php';
require_once '../cabinet_helpers.php';
require_once '../dashboard_config_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$action = $_GET['action'] ?? '';
$user_id = (int) $_SESSION['user_id'];
$is_admin = in_array(($_SESSION['role'] ?? ''), ['admin', 'superadmin'], true);
$is_superadmin = ($_SESSION['role'] ?? '') === 'superadmin';

if (!$is_admin && !online_booking_enabled($mysqli)) {
    echo json_encode(['success' => false, 'error' => 'El área de pacientes no está disponible en este momento.']);
    exit;
}

if (!app_feature_enabled_from_db($mysqli, 'bonuses.enabled', false)) {
    echo json_encode(['success' => false, 'error' => 'Los bonos no estan disponibles en este plan.']);
    exit;
}

ensure_bonus_tables($mysqli);
ensure_payment_attempts_table($mysqli);
ensure_cabinet_schema($mysqli);

function bonus_current_professional_id_for_user($mysqli, $user_id)
{
    $tenant_id = current_tenant_id();
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return 0;
    }
    $stmt = $mysqli->prepare("SELECT id FROM professionals WHERE tenant_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['id'] : 0;
}

function bonus_requested_professional_filter($mysqli)
{
    global $is_superadmin, $user_id;
    $tenant_id = current_tenant_id();
    $current_professional_id = bonus_current_professional_id_for_user($mysqli, $user_id);
    if (!$is_superadmin) {
        return $current_professional_id > 0 ? $current_professional_id : -1;
    }

    $raw = $_GET['professional_id'] ?? '';
    if ($raw === 'all') {
        return 0;
    }
    if ($raw === '' || $raw === null) {
        return $current_professional_id;
    }
    $requested_id = max(0, (int) $raw);
    if ($requested_id <= 0) {
        return 0;
    }
    $stmt = $mysqli->prepare("SELECT id FROM professionals WHERE tenant_id = ? AND id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $requested_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ? $requested_id : -1;
}

function bonus_active_professionals_payload($mysqli)
{
    $tenant_id = current_tenant_id();
    $branding = get_public_branding_settings($mysqli);
    $dashboard_photo = $branding['profile_image_path'] ?? '';
    $rows = [];
    $res = $mysqli->query("
        SELECT p.id, p.display_name, p.public_photo_path, u.role AS user_role
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
        WHERE p.tenant_id = $tenant_id
          AND p.is_active = 1
        ORDER BY CASE WHEN u.role = 'superadmin' THEN 0 ELSE 1 END ASC,
                 p.sort_order ASC,
                 p.display_name ASC
    ");
    while ($row = $res->fetch_assoc()) {
        $photo_path = $row['public_photo_path'] ?: (($row['user_role'] ?? '') === 'superadmin' ? $dashboard_photo : '');
        $rows[] = [
            'id' => (int) $row['id'],
            'display_name' => $row['display_name'],
            'public_photo_path' => $row['public_photo_path'] ?? '',
            'display_photo_path' => $photo_path
        ];
    }
    return $rows;
}

function format_bonus_row($row)
{
    $row['id'] = (int) $row['id'];
    $row['user_id'] = (int) $row['user_id'];
    $row['bonus_id'] = (int) $row['bonus_id'];
    $row['total_sessions'] = (int) $row['total_sessions'];
    $row['remaining_sessions'] = (int) $row['remaining_sessions'];
    $row['amount_paid'] = isset($row['amount_paid']) ? number_format(((int) $row['amount_paid']) / 100, 2, '.', '') : null;
    if (isset($row['professional_id'])) {
        $row['professional_id'] = (int) $row['professional_id'];
    }
    if (isset($row['professional_photo_path'], $row['professional_user_role'])) {
        $branding = get_public_branding_settings($GLOBALS['mysqli']);
        $row['professional_photo_path'] = $row['professional_photo_path'] ?: ($row['professional_user_role'] === 'superadmin' ? ($branding['profile_image_path'] ?? '') : '');
    }
    return $row;
}

function standard_individual_session_price($mysqli)
{
    $tenant_id = current_tenant_id();
    ensure_appointment_services_tables($mysqli);
    $res = $mysqli->query("
        SELECT o.price
        FROM appointment_service_options o
        JOIN appointment_services s ON s.id = o.service_id AND s.tenant_id = o.tenant_id
        WHERE o.tenant_id = $tenant_id
          AND s.service_key = 'individual'
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

function bonus_admin_can_access_patient($mysqli, $patient_id)
{
    global $is_superadmin, $user_id;
    $patient_id = (int) $patient_id;
    if ($patient_id <= 0) {
        return false;
    }
    if ($is_superadmin) {
        return true;
    }
    $current_professional_id = bonus_current_professional_id_for_user($mysqli, $user_id);
    return $current_professional_id > 0 && cabinet_patient_primary_professional_id($mysqli, $patient_id) === $current_professional_id;
}

function bonus_patient_exists($mysqli, $patient_id)
{
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("SELECT id FROM users WHERE tenant_id = ? AND id = ? AND role = 'patient' LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
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

if ($action === 'patient_bonuses') {
    if (!$is_admin) {
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit;
    }

    $patient_id = (int) ($_GET['patient_id'] ?? 0);
    $tenant_id = current_tenant_id();
    if (!bonus_patient_exists($mysqli, $patient_id) || !bonus_admin_can_access_patient($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'Paciente no valido.']);
        exit;
    }

    $stmt = $mysqli->prepare("
        SELECT pb.id, pb.user_id, pb.bonus_id, pb.total_sessions, pb.remaining_sessions, pb.status,
               pb.purchased_at, pb.expires_at, b.name, pa.amount_cents AS amount_paid, pa.payment_method,
               p.id AS professional_id, p.display_name AS professional_name, p.public_photo_path AS professional_photo_path,
               pu.role AS professional_user_role
        FROM patient_bonuses pb
        JOIN appointment_bonuses b ON b.id = pb.bonus_id AND b.tenant_id = pb.tenant_id
        LEFT JOIN payment_attempts pa ON pa.id = pb.payment_attempt_id AND pa.tenant_id = pb.tenant_id
        LEFT JOIN professionals p ON p.id = pb.professional_id AND p.tenant_id = pb.tenant_id
        LEFT JOIN users pu ON pu.id = p.user_id AND pu.tenant_id = p.tenant_id
        WHERE pb.tenant_id = ?
          AND pb.user_id = ?
        ORDER BY pb.purchased_at DESC, pb.id DESC
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $bonuses = [];
    while ($row = $res->fetch_assoc()) {
        $bonuses[] = format_bonus_row($row);
    }

    echo json_encode([
        'success' => true,
        'bonuses' => $bonuses,
        'bonuses_enabled' => bonuses_are_enabled($mysqli) ? 1 : 0,
        'can_manage' => $is_superadmin && bonuses_are_enabled($mysqli) ? 1 : 0,
        'catalog' => $is_superadmin && bonuses_are_enabled($mysqli) ? fetch_appointment_bonuses($mysqli, true, true) : []
    ]);
    exit;
}

if ($action === 'create_patient_bonus') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit;
    }
    if (!bonuses_are_enabled($mysqli)) {
        echo json_encode(['success' => false, 'error' => 'Los bonos no estan habilitados.']);
        exit;
    }

    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $tenant_id = current_tenant_id();
    $bonus_id = (int) ($_POST['bonus_id'] ?? 0);
    $total_sessions = (int) ($_POST['total_sessions'] ?? 0);
    $remaining_sessions = max(0, (int) ($_POST['remaining_sessions'] ?? 0));
    if (!bonus_patient_exists($mysqli, $patient_id)) {
        echo json_encode(['success' => false, 'error' => 'Paciente no valido.']);
        exit;
    }
    if ($bonus_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Selecciona un bono valido.']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT id, session_count FROM appointment_bonuses WHERE tenant_id = ? AND id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $bonus_id);
    $stmt->execute();
    $bonus = $stmt->get_result()->fetch_assoc();
    if (!$bonus) {
        echo json_encode(['success' => false, 'error' => 'El bono seleccionado no esta disponible.']);
        exit;
    }

    if ($total_sessions <= 0) {
        $total_sessions = (int) $bonus['session_count'];
    }
    if ($remaining_sessions <= 0 && !isset($_POST['remaining_sessions'])) {
        $remaining_sessions = $total_sessions;
    }
    $total_sessions = max($total_sessions, $remaining_sessions);
    $status = $remaining_sessions > 0 ? 'active' : 'used';
    $professional_id = cabinet_patient_primary_professional_id($mysqli, $patient_id);
    if ($professional_id <= 0) {
        $professional_id = bonus_current_professional_id_for_user($mysqli, $user_id);
    }

    $stmt = $mysqli->prepare("
        INSERT INTO patient_bonuses (tenant_id, professional_id, user_id, bonus_id, total_sessions, remaining_sessions, status, purchased_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("iiiiiis", $tenant_id, $professional_id, $patient_id, $bonus_id, $total_sessions, $remaining_sessions, $status);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'No se pudo crear el bono.']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Bono creado correctamente.']);
    exit;
}

if ($action === 'my_bonuses') {
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("
        SELECT pb.id, pb.user_id, pb.bonus_id, pb.total_sessions, pb.remaining_sessions, pb.status,
               pb.purchased_at, pb.expires_at, b.name, pa.amount_cents AS amount_paid, pa.payment_method
        FROM patient_bonuses pb
        JOIN appointment_bonuses b ON b.id = pb.bonus_id AND b.tenant_id = pb.tenant_id
        LEFT JOIN payment_attempts pa ON pa.id = pb.payment_attempt_id AND pa.tenant_id = pb.tenant_id
        WHERE pb.tenant_id = ?
          AND pb.user_id = ?
        ORDER BY pb.purchased_at DESC, pb.id DESC
    ");
    $stmt->bind_param("ii", $tenant_id, $user_id);
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

    $tenant_id = current_tenant_id();
    $professional_id = bonus_requested_professional_filter($mysqli);
    $effective_professional_expr = "COALESCE(pb.professional_id, ppf.professional_id, pp.professional_id)";
    $professional_where = $professional_id > 0 ? " AND $effective_professional_expr = " . (int) $professional_id : ($professional_id < 0 ? " AND 1 = 0" : "");

    $res = $mysqli->query("
        SELECT pb.id, pb.user_id, pb.bonus_id, pb.total_sessions, pb.remaining_sessions, pb.status,
               pb.purchased_at, pb.expires_at, b.name, pa.amount_cents AS amount_paid, pa.payment_method,
               u.name AS patient_name, u.email AS patient_email, u.phone AS patient_phone,
               p.id AS professional_id, p.display_name AS professional_name, p.public_photo_path AS professional_photo_path,
               pu.role AS professional_user_role
        FROM patient_bonuses pb
        JOIN appointment_bonuses b ON b.id = pb.bonus_id AND b.tenant_id = pb.tenant_id
        JOIN users u ON u.id = pb.user_id AND u.tenant_id = pb.tenant_id
        LEFT JOIN payment_attempts pa ON pa.id = pb.payment_attempt_id AND pa.tenant_id = pb.tenant_id
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = pb.user_id AND ppf.tenant_id = pb.tenant_id AND ppf.is_primary = 1
        LEFT JOIN patient_profiles pp ON pp.user_id = pb.user_id AND pp.tenant_id = pb.tenant_id
        LEFT JOIN professionals p ON p.id = $effective_professional_expr AND p.tenant_id = pb.tenant_id
        LEFT JOIN users pu ON pu.id = p.user_id AND pu.tenant_id = p.tenant_id
        WHERE pb.tenant_id = $tenant_id
          $professional_where
        ORDER BY pb.purchased_at DESC, pb.id DESC
    ");
    $bonuses = [];
    while ($row = $res->fetch_assoc()) {
        $bonuses[] = format_bonus_row($row);
    }
    echo json_encode([
        'success' => true,
        'bonuses' => $bonuses,
        'professionals' => $is_superadmin ? bonus_active_professionals_payload($mysqli) : [],
        'current_professional_id' => bonus_current_professional_id_for_user($mysqli, $user_id)
    ]);
    exit;
}

if ($action === 'update_patient_bonus') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit;
    }
    if (!bonuses_are_enabled($mysqli)) {
        echo json_encode(['success' => false, 'error' => 'Los bonos no están habilitados.']);
        exit;
    }

    $patient_bonus_id = (int) ($_POST['patient_bonus_id'] ?? 0);
    $tenant_id = current_tenant_id();
    $remaining_sessions = max(0, (int) ($_POST['remaining_sessions'] ?? 0));
    if ($patient_bonus_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Bono no válido.']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT id, total_sessions FROM patient_bonuses WHERE tenant_id = ? AND id = ? LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $patient_bonus_id);
    $stmt->execute();
    $bonus = $stmt->get_result()->fetch_assoc();
    if (!$bonus) {
        echo json_encode(['success' => false, 'error' => 'No se encontró el bono.']);
        exit;
    }

    $total_sessions = max((int) $bonus['total_sessions'], $remaining_sessions);
    $status = $remaining_sessions > 0 ? 'active' : 'used';
    $stmt = $mysqli->prepare("
        UPDATE patient_bonuses
        SET total_sessions = ?, remaining_sessions = ?, status = ?
        WHERE tenant_id = ? AND id = ?
    ");
    $stmt->bind_param("iisii", $total_sessions, $remaining_sessions, $status, $tenant_id, $patient_bonus_id);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'No se pudo actualizar el bono.']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Bono actualizado correctamente.']);
    exit;
}

if ($action === 'delete_patient_bonus') {
    if (!$is_superadmin) {
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit;
    }
    if (!bonuses_are_enabled($mysqli)) {
        echo json_encode(['success' => false, 'error' => 'Los bonos no están habilitados.']);
        exit;
    }

    $patient_bonus_id = (int) ($_POST['patient_bonus_id'] ?? 0);
    $tenant_id = current_tenant_id();
    if ($patient_bonus_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Bono no válido.']);
        exit;
    }

    $stmt = $mysqli->prepare("DELETE FROM patient_bonuses WHERE tenant_id = ? AND id = ?");
    $stmt->bind_param("ii", $tenant_id, $patient_bonus_id);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'No se pudo eliminar el bono.']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Bono eliminado correctamente.']);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Accion invalida']);
