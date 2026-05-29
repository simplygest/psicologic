<?php
session_start();
require_once '../db.php';
require_once '../redsys/apiRedsys.php';
require_once '../payment_helpers.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'] ?? null;
$is_admin = (($_SESSION['role'] ?? '') === 'admin');

function app_base_url()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
    $domain = $_SERVER['HTTP_HOST'];
    $path = dirname(dirname($_SERVER['REQUEST_URI']));
    $path = rtrim($path, '/');
    return $protocol . $domain . $path . '/';
}

if ($action !== 'create_redsys_form') {
    echo json_encode(['success' => false, 'error' => 'Acción inválida']);
    exit;
}

$appointment_id = (int) ($_POST['appointment_id'] ?? 0);
$cancel_token = $_POST['token'] ?? '';
$cancel_token = preg_match('/^[a-f0-9]{64}$/', $cancel_token) ? $cancel_token : '';
$payment_method = $_POST['payment_method'] ?? 'card';

if ((!$appointment_id && !$cancel_token) || !in_array($payment_method, ['card', 'bizum'], true)) {
    echo json_encode(['success' => false, 'error' => 'Datos de pago inválidos']);
    exit;
}

if (!$user_id && !$cancel_token) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

ensure_payment_attempts_table($mysqli);
ensure_appointment_payment_columns($mysqli);
ensure_appointment_services_tables($mysqli);

$lookup_where = $cancel_token ? 'a.cancel_token = ?' : 'a.id = ?';
$stmt = $mysqli->prepare("
    SELECT a.id, a.user_id, a.appointment_date, a.appointment_time, a.consultation_type, a.service_type,
           COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
           so.price AS service_price, s.name AS service_name,
           COALESCE(a.payment_status, 'pending') AS payment_status,
           u.name
    FROM appointments a
    LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
    LEFT JOIN appointment_services s ON s.id = so.service_id
    JOIN users u ON a.user_id = u.id
    WHERE $lookup_where AND a.status = 'booked'
");
if ($cancel_token) {
    $stmt->bind_param("s", $cancel_token);
} else {
    $stmt->bind_param("i", $appointment_id);
}
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();

if (!$appointment || (!$cancel_token && !$is_admin && (int) $appointment['user_id'] !== (int) $user_id)) {
    echo json_encode(['success' => false, 'error' => 'Cita no disponible para pago']);
    exit;
}

if ($appointment['payment_status'] === 'paid') {
    echo json_encode(['success' => false, 'error' => 'Esta cita ya está pagada']);
    exit;
}

$appointment_id = (int) $appointment['id'];

$settings_table = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
if ($settings_table->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'El pago online no está activo']);
    exit;
}

ensure_payment_settings_price_columns($mysqli);

$settings_res = $mysqli->query("
    SELECT online_payment_enabled, environment, merchant_code, merchant_key, terminal, appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price
    FROM payment_settings
    WHERE id = 1
");
$settings = $settings_res->fetch_assoc();

if (!$settings || (int) $settings['online_payment_enabled'] !== 1) {
    echo json_encode(['success' => false, 'error' => 'El pago online no está activo']);
    exit;
}

$appointment_price = appointment_price_for_row($settings, $appointment);

if (!$settings['merchant_code'] || !$settings['merchant_key'] || !$settings['terminal'] || (float) $appointment_price <= 0) {
    echo json_encode(['success' => false, 'error' => 'La configuración de Redsys está incompleta']);
    exit;
}

$amount = number_format((float) $appointment_price, 2, '.', '');
$amount_cents = (int) str_replace('.', '', $amount);
$order = sprintf('%04d%06d', $appointment_id % 10000, random_int(0, 999999));
$token = hash('sha256', $appointment_id . '|' . $user_id . '|' . $order . '|' . $amount_cents);

$stmt = $mysqli->prepare("
    INSERT INTO payment_attempts (appointment_id, user_id, token, redsys_order, amount_cents, payment_method, status)
    VALUES (?, ?, ?, ?, ?, ?, 'Iniciado')
");
$stmt->bind_param("iissis", $appointment_id, $appointment['user_id'], $token, $order, $amount_cents, $payment_method);
$stmt->execute();
$payment_attempt_id = $mysqli->insert_id;

$stmt = $mysqli->prepare("
    UPDATE appointments
    SET payment_status = 'pending', payment_method = ?, payment_attempt_id = ?
    WHERE id = ?
");
$stmt->bind_param("sii", $payment_method, $payment_attempt_id, $appointment_id);
$stmt->execute();

$base_url = app_base_url();
$url_pago = $settings['environment'] === 'sandbox'
    ? 'https://sis-t.redsys.es:25443/sis/realizarPago'
    : 'https://sis.redsys.es/sis/realizarPago';

$url_ok = $base_url . 'respuestaredsysok.php?t=' . urlencode($token);
$url_ko = $base_url . 'respuestaredsysko.php?t=' . urlencode($token);
$description = 'Cita ' . appointment_service_option_label($appointment) . ' ' . (($appointment['consultation_type'] ?? 'presencial') === 'online' ? 'online' : 'presencial') . ' ' . date('d/m/Y', strtotime($appointment['appointment_date'])) . ' ' . date('H:i', strtotime($appointment['appointment_time']));

$redsys = new RedsysAPI();
$redsys->setParameter('DS_MERCHANT_AMOUNT', (string) $amount_cents);
$redsys->setParameter('DS_MERCHANT_ORDER', $order);
$redsys->setParameter('DS_MERCHANT_MERCHANTCODE', $settings['merchant_code']);
$redsys->setParameter('DS_MERCHANT_CURRENCY', '978');
$redsys->setParameter('DS_MERCHANT_TRANSACTIONTYPE', '0');
$redsys->setParameter('DS_MERCHANT_TERMINAL', $settings['terminal']);
$redsys->setParameter('DS_MERCHANT_URLOK', $url_ok);
$redsys->setParameter('DS_MERCHANT_URLKO', $url_ko);
$redsys->setParameter('Ds_Merchant_ProductDescription', $description);
$redsys->setParameter('Ds_Merchant_MerchantName', 'PsicoLogic');
$redsys->setParameter('Ds_Merchant_MerchantData', $token);

if ($payment_method === 'bizum') {
    $redsys->setParameter('DS_MERCHANT_PAYMETHODS', 'z');
}

$version = 'HMAC_SHA256_V1';
$params = $redsys->createMerchantParameters();
$signature = $redsys->createMerchantSignature($settings['merchant_key']);
$target = $payment_method === 'bizum' ? " target=\"_blank\"" : '';

$form = '<form name="redsys-payment-form" id="redsys-payment-form" action="' . htmlspecialchars($url_pago, ENT_QUOTES, 'UTF-8') . '" method="POST"' . $target . '>';
$form .= '<input type="hidden" name="Ds_SignatureVersion" value="' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8') . '">';
$form .= '<input type="hidden" name="Ds_MerchantParameters" value="' . htmlspecialchars($params, ENT_QUOTES, 'UTF-8') . '">';
$form .= '<input type="hidden" name="Ds_Signature" value="' . htmlspecialchars($signature, ENT_QUOTES, 'UTF-8') . '">';
$form .= '</form>';

echo json_encode(['success' => true, 'form_html' => $form]);
