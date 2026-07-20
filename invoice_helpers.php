<?php
require_once __DIR__ . '/payment_helpers.php';
require_once __DIR__ . '/dashboard_config_helpers.php';

function invoice_table_exists($mysqli, $table)
{
    $table = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

function invoice_column_exists($mysqli, $table, $column)
{
    $table = $mysqli->real_escape_string($table);
    $column = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function invoice_add_column_if_missing($mysqli, $table, $column, $definition)
{
    if (!invoice_column_exists($mysqli, $table, $column)) {
        $mysqli->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function ensure_invoice_schema($mysqli)
{
    if (function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }
    static $invoice_schema_ensured = false;
    if ($invoice_schema_ensured) {
        return;
    }

    $tenant_id = current_tenant_id();
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS movim (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            tipo_movim VARCHAR(20) NOT NULL DEFAULT 'factura',
            serie VARCHAR(20) NOT NULL DEFAULT 'A',
            numero INT UNSIGNED NOT NULL,
            numero_factura VARCHAR(40) NOT NULL,
            fecha DATE NOT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'emitida',
            user_id INT UNSIGNED DEFAULT NULL,
            appointment_id INT UNSIGNED DEFAULT NULL,
            patient_bonus_id INT UNSIGNED DEFAULT NULL,
            payment_attempt_id INT UNSIGNED DEFAULT NULL,
            patient_report_id INT UNSIGNED DEFAULT NULL,
            origen_tipo VARCHAR(30) NOT NULL,
            origen_id INT UNSIGNED NOT NULL,
            destinatario_nombre VARCHAR(180) NOT NULL,
            destinatario_nif VARCHAR(50) NOT NULL,
            destinatario_email VARCHAR(180) DEFAULT NULL,
            concepto VARCHAR(255) NOT NULL,
            base_imponible DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            iva_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            iva_importe DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            moneda CHAR(3) NOT NULL DEFAULT 'EUR',
            forma_pago VARCHAR(30) DEFAULT NULL,
            lineas_json LONGTEXT DEFAULT NULL,
            verifactu_estado VARCHAR(30) NOT NULL DEFAULT 'pendiente',
            verifactu_error TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_movim_tenant_numero (tenant_id, serie, numero),
            UNIQUE KEY uniq_movim_tenant_origen (tenant_id, origen_tipo, origen_id),
            INDEX idx_movim_user (tenant_id, user_id),
            INDEX idx_movim_fecha (tenant_id, fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    invoice_add_column_if_missing($mysqli, 'movim', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
    invoice_add_column_if_missing($mysqli, 'movim', 'tipo_movim', "VARCHAR(20) NOT NULL DEFAULT 'factura' AFTER tenant_id");
    invoice_add_column_if_missing($mysqli, 'movim', 'patient_report_id', "INT UNSIGNED DEFAULT NULL AFTER payment_attempt_id");
    invoice_add_column_if_missing($mysqli, 'movim', 'verifactu_estado', "VARCHAR(30) NOT NULL DEFAULT 'pendiente' AFTER lineas_json");
    invoice_add_column_if_missing($mysqli, 'movim', 'verifactu_error', "TEXT DEFAULT NULL AFTER verifactu_estado");

    invoice_add_column_if_missing($mysqli, 'users', 'fiscal_name', "VARCHAR(180) DEFAULT NULL AFTER name");
    invoice_add_column_if_missing($mysqli, 'users', 'fiscal_nif', "VARCHAR(50) DEFAULT NULL AFTER fiscal_name");
    if (invoice_table_exists($mysqli, 'patient_profiles')) {
        invoice_add_column_if_missing($mysqli, 'patient_profiles', 'fiscal_name', "VARCHAR(180) DEFAULT NULL AFTER patient_type");
        invoice_add_column_if_missing($mysqli, 'patient_profiles', 'fiscal_nif', "VARCHAR(50) DEFAULT NULL AFTER fiscal_name");
        invoice_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_use_alt_data', "TINYINT(1) NOT NULL DEFAULT 0 AFTER fiscal_nif");
        invoice_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_name', "VARCHAR(180) DEFAULT NULL AFTER invoice_use_alt_data");
        invoice_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_nif', "VARCHAR(50) DEFAULT NULL AFTER invoice_name");
        invoice_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_email', "VARCHAR(180) DEFAULT NULL AFTER invoice_nif");
        invoice_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_phone', "VARCHAR(40) DEFAULT NULL AFTER invoice_email");
        invoice_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_address', "VARCHAR(255) DEFAULT NULL AFTER invoice_phone");
    }

    if (invoice_table_exists($mysqli, 'payment_settings')) {
        invoice_add_column_if_missing($mysqli, 'payment_settings', 'billing_enabled', "TINYINT(1) NOT NULL DEFAULT 0");
        invoice_add_column_if_missing($mysqli, 'payment_settings', 'billing_country', "VARCHAR(2) NOT NULL DEFAULT 'ES'");
        invoice_add_column_if_missing($mysqli, 'payment_settings', 'billing_province', "VARCHAR(80) DEFAULT NULL");
        invoice_add_column_if_missing($mysqli, 'payment_settings', 'billing_session_concept', "VARCHAR(255) NOT NULL DEFAULT 'Sesion del dia {fecha} de duracion {duracion} minutos'");
        invoice_add_column_if_missing($mysqli, 'payment_settings', 'billing_report_concept', "VARCHAR(255) NOT NULL DEFAULT 'Informe {titulo}'");
    }
    $invoice_schema_ensured = true;
}

function invoice_billing_enabled($mysqli)
{
    ensure_invoice_schema($mysqli);
    $tenant = function_exists('current_tenant') ? current_tenant() : null;
    $plan_key = plan_config_normalize_key(is_array($tenant) ? ($tenant['plan_key'] ?? '') : '', 'novus');
    if (!plan_config_feature_enabled(plan_config_for_key($plan_key), 'billing.enabled', false)) {
        return false;
    }
    $tenant_id = current_tenant_id();
    $res = $mysqli->query("SELECT billing_enabled FROM payment_settings WHERE tenant_id = $tenant_id LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
        return (int) ($row['billing_enabled'] ?? 0) === 1;
    }
    return false;
}

function invoice_billing_settings($mysqli)
{
    ensure_invoice_schema($mysqli);
    $tenant_id = current_tenant_id();
    $res = $mysqli->query("
        SELECT billing_enabled, billing_country, billing_province, billing_session_concept, billing_report_concept
        FROM payment_settings
        WHERE tenant_id = $tenant_id
        LIMIT 1
    ");
    return $res && ($row = $res->fetch_assoc()) ? $row : [
        'billing_enabled' => 0,
        'billing_country' => 'ES',
        'billing_province' => '',
        'billing_session_concept' => 'Sesion del dia {fecha} de duracion {duracion} minutos',
        'billing_report_concept' => 'Informe {titulo}'
    ];
}

function invoice_apply_template($template, array $values, $fallback)
{
    $template = trim((string) $template);
    if ($template === '') {
        $template = $fallback;
    }
    foreach ($values as $key => $value) {
        $template = str_replace('{' . $key . '}', (string) $value, $template);
    }
    return trim($template) ?: $fallback;
}

function invoice_recipient_for_user($mysqli, $user_id)
{
    $tenant_id = current_tenant_id();
    $user_id = (int) $user_id;
    $stmt = $mysqli->prepare("
        SELECT pp.user_id AS id, u.name, u.email, pp.fiscal_name, pp.fiscal_nif,
               pp.invoice_use_alt_data, pp.invoice_name, pp.invoice_nif, pp.invoice_email, pp.invoice_phone, pp.invoice_address
        FROM patient_profiles pp
        JOIN users u ON u.id = pp.user_id AND u.tenant_id = pp.tenant_id
        WHERE pp.tenant_id = ? AND pp.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) {
        return [false, 'No se encontro el destinatario de la factura.', null];
    }

    $use_alt_data = (int) ($user['invoice_use_alt_data'] ?? 0) === 1;
    if ($use_alt_data) {
        $name = trim((string) ($user['invoice_name'] ?? ''));
        $nif = strtoupper(trim((string) ($user['invoice_nif'] ?? '')));
        $email = trim((string) ($user['invoice_email'] ?? ''));
    } else {
        $name = trim((string) ($user['fiscal_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($user['name'] ?? ''));
        }
        $nif = strtoupper(trim((string) ($user['fiscal_nif'] ?? '')));
        $email = trim((string) ($user['email'] ?? ''));
    }
    if ($name === '' || $nif === '') {
        return [false, 'Para emitir factura faltan nombre fiscal o NIF del destinatario.', null];
    }

    return [true, '', [
        'user_id' => (int) $user['id'],
        'name' => $name,
        'nif' => $nif,
        'email' => $email !== '' ? $email : null
    ]];
}

function invoice_next_number($mysqli, $tenant_id, $serie)
{
    $stmt = $mysqli->prepare("
        SELECT COALESCE(MAX(numero), 0) + 1 AS next_number
        FROM movim
        WHERE tenant_id = ? AND serie = ?
        FOR UPDATE
    ");
    $stmt->bind_param("is", $tenant_id, $serie);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return max(1, (int) ($row['next_number'] ?? 1));
}

function invoice_existing_for_origin($mysqli, $origin_type, $origin_id)
{
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("
        SELECT id, numero_factura
        FROM movim
        WHERE tenant_id = ? AND origen_tipo = ? AND origen_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("isi", $tenant_id, $origin_type, $origin_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function invoice_manual_confirmation_received()
{
    $value = $_POST['confirm_invoice'] ?? $_GET['confirm_invoice'] ?? '';
    return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
}

function invoice_emit($mysqli, array $data)
{
    ensure_invoice_schema($mysqli);
    if (!invoice_billing_enabled($mysqli)) {
        return ['success' => true, 'skipped' => true, 'reason' => 'billing_disabled'];
    }
    $tenant_id = current_tenant_id();
    $origin_type = (string) ($data['origin_type'] ?? '');
    $origin_id = (int) ($data['origin_id'] ?? 0);
    if ($origin_type === '' || $origin_id <= 0) {
        return ['success' => false, 'error' => 'Origen de factura no valido.'];
    }

    $existing = invoice_existing_for_origin($mysqli, $origin_type, $origin_id);
    if ($existing) {
        return ['success' => true, 'already_invoiced' => true, 'invoice_id' => (int) $existing['id'], 'invoice_number' => $existing['numero_factura']];
    }

    [$recipient_ok, $recipient_error, $recipient] = invoice_recipient_for_user($mysqli, (int) ($data['user_id'] ?? 0));
    if (!$recipient_ok) {
        return ['success' => false, 'error' => $recipient_error];
    }

    $serie = trim((string) ($data['serie'] ?? 'A')) ?: 'A';
    $numero = invoice_next_number($mysqli, $tenant_id, $serie);
    $numero_factura = $serie . '-' . str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
    $concepto = trim((string) ($data['concept'] ?? 'Servicio profesional'));
    $base = round((float) ($data['base'] ?? $data['total'] ?? 0), 2);
    $iva_porcentaje = round((float) ($data['vat_rate'] ?? 0), 2);
    $iva_importe = round($base * $iva_porcentaje / 100, 2);
    $total = round($base + $iva_importe, 2);
    $payment_method = trim((string) ($data['payment_method'] ?? '')) ?: null;
    $lineas = json_encode([[
        'concepto' => $concepto,
        'cantidad' => 1,
        'base_imponible' => $base,
        'iva_porcentaje' => $iva_porcentaje,
        'iva_importe' => $iva_importe,
        'total' => $total
    ]], JSON_UNESCAPED_UNICODE);

    $appointment_id = isset($data['appointment_id']) ? (int) $data['appointment_id'] : null;
    $patient_bonus_id = isset($data['patient_bonus_id']) ? (int) $data['patient_bonus_id'] : null;
    $payment_attempt_id = isset($data['payment_attempt_id']) ? (int) $data['payment_attempt_id'] : null;
    $patient_report_id = isset($data['patient_report_id']) ? (int) $data['patient_report_id'] : null;
    $fecha = date('Y-m-d');
    $recipient_user_id = (int) $recipient['user_id'];
    $recipient_name = $recipient['name'];
    $recipient_nif = $recipient['nif'];
    $recipient_email = $recipient['email'];

    $stmt = $mysqli->prepare("
        INSERT INTO movim
            (tenant_id, tipo_movim, serie, numero, numero_factura, fecha, estado, user_id,
             appointment_id, patient_bonus_id, payment_attempt_id, patient_report_id,
             origen_tipo, origen_id, destinatario_nombre, destinatario_nif, destinatario_email,
             concepto, base_imponible, iva_porcentaje, iva_importe, total, forma_pago, lineas_json)
        VALUES
            (?, 'factura', ?, ?, ?, ?, 'emitida', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "isissiiiiisissssddddss",
        $tenant_id,
        $serie,
        $numero,
        $numero_factura,
        $fecha,
        $recipient_user_id,
        $appointment_id,
        $patient_bonus_id,
        $payment_attempt_id,
        $patient_report_id,
        $origin_type,
        $origin_id,
        $recipient_name,
        $recipient_nif,
        $recipient_email,
        $concepto,
        $base,
        $iva_porcentaje,
        $iva_importe,
        $total,
        $payment_method,
        $lineas
    );
    $stmt->execute();
    $invoice_id = (int) $mysqli->insert_id;

    $verifactu_file = __DIR__ . '/VeriFactu.php';
    if (is_file($verifactu_file)) {
        $verifactu_invoice_id = $invoice_id;
        $verifactu_invoice_number = $numero_factura;
        include $verifactu_file;
    }

    return ['success' => true, 'invoice_id' => $invoice_id, 'invoice_number' => $numero_factura];
}

function invoice_session_concept($mysqli, $appointment)
{
    $settings = invoice_billing_settings($mysqli);
    $date = !empty($appointment['appointment_date']) ? date('d/m/Y', strtotime($appointment['appointment_date'])) : date('d/m/Y');
    $duration = (int) ($appointment['duration_minutes'] ?? 60);
    return invoice_apply_template($settings['billing_session_concept'] ?? '', [
        'fecha' => $date,
        'duracion' => $duration,
        'hora' => !empty($appointment['appointment_time']) ? date('H:i', strtotime($appointment['appointment_time'])) : ''
    ], "Sesion del dia $date de duracion $duration minutos");
}

function invoice_emit_for_appointment($mysqli, $appointment_id, $payment_method = null)
{
    ensure_invoice_schema($mysqli);
    ensure_appointment_services_tables($mysqli);
    $tenant_id = current_tenant_id();
    $appointment_id = (int) $appointment_id;
    $stmt = $mysqli->prepare("
        SELECT a.id, a.user_id, a.payment_attempt_id, a.payment_method, a.appointment_date, a.appointment_time,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               so.price AS service_price
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
        WHERE a.tenant_id = ? AND a.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $appointment_id);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
    if (!$appointment) {
        return ['success' => false, 'error' => 'No se encontro la cita a facturar.'];
    }

    $settings = [];
    $settings_res = $mysqli->query("SELECT appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price FROM payment_settings WHERE tenant_id = $tenant_id LIMIT 1");
    if ($settings_res && ($row = $settings_res->fetch_assoc())) {
        $settings = $row;
    }
    $amount = appointment_price_for_row($settings, $appointment);
    return invoice_emit($mysqli, [
        'origin_type' => 'appointment',
        'origin_id' => $appointment_id,
        'user_id' => (int) $appointment['user_id'],
        'appointment_id' => $appointment_id,
        'payment_attempt_id' => $appointment['payment_attempt_id'] ? (int) $appointment['payment_attempt_id'] : null,
        'concept' => invoice_session_concept($mysqli, $appointment),
        'base' => $amount,
        'vat_rate' => 0,
        'payment_method' => $payment_method ?: ($appointment['payment_method'] ?? null)
    ]);
}

function invoice_emit_for_payment_attempt($mysqli, $payment_attempt_id)
{
    ensure_invoice_schema($mysqli);
    ensure_bonus_tables($mysqli);
    $tenant_id = current_tenant_id();
    $payment_attempt_id = (int) $payment_attempt_id;
    $stmt = $mysqli->prepare("
        SELECT pa.id, pa.appointment_id, pa.user_id, pa.amount_cents, pa.payment_method,
               COALESCE(pa.purchase_type, 'appointment') AS purchase_type, pa.bonus_id,
               pb.id AS patient_bonus_id, b.name AS bonus_name, b.session_count
        FROM payment_attempts pa
        LEFT JOIN patient_bonuses pb ON pb.payment_attempt_id = pa.id AND pb.tenant_id = pa.tenant_id
        LEFT JOIN appointment_bonuses b ON b.id = pa.bonus_id AND b.tenant_id = pa.tenant_id
        WHERE pa.tenant_id = ? AND pa.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $payment_attempt_id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    if (!$payment) {
        return ['success' => false, 'error' => 'No se encontro el pago a facturar.'];
    }

    if (($payment['purchase_type'] ?? 'appointment') === 'appointment') {
        return invoice_emit_for_appointment($mysqli, (int) $payment['appointment_id'], $payment['payment_method'] ?? null);
    }

    $bonus_name = trim((string) ($payment['bonus_name'] ?? 'Bono de sesiones')) ?: 'Bono de sesiones';
    $sessions = (int) ($payment['session_count'] ?? 0);
    $concept = $sessions > 0 ? "$bonus_name ($sessions sesiones)" : $bonus_name;
    return invoice_emit($mysqli, [
        'origin_type' => 'payment_attempt',
        'origin_id' => $payment_attempt_id,
        'user_id' => (int) $payment['user_id'],
        'patient_bonus_id' => $payment['patient_bonus_id'] ? (int) $payment['patient_bonus_id'] : null,
        'payment_attempt_id' => $payment_attempt_id,
        'concept' => $concept,
        'base' => ((int) $payment['amount_cents']) / 100,
        'vat_rate' => 0,
        'payment_method' => $payment['payment_method'] ?? null
    ]);
}

function invoice_emit_for_patient_report($mysqli, $report_id)
{
    ensure_invoice_schema($mysqli);
    $tenant_id = current_tenant_id();
    $report_id = (int) $report_id;
    $stmt = $mysqli->prepare("
        SELECT id, patient_id, title, price
        FROM patient_reports
        WHERE tenant_id = ? AND id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $report_id);
    $stmt->execute();
    $report = $stmt->get_result()->fetch_assoc();
    if (!$report) {
        return ['success' => false, 'error' => 'No se encontro el informe a facturar.'];
    }

    $title = trim((string) ($report['title'] ?? 'Informe'));
    $settings = invoice_billing_settings($mysqli);
    return invoice_emit($mysqli, [
        'origin_type' => 'patient_report',
        'origin_id' => $report_id,
        'user_id' => (int) $report['patient_id'],
        'patient_report_id' => $report_id,
        'concept' => invoice_apply_template($settings['billing_report_concept'] ?? '', ['titulo' => $title], 'Informe ' . $title),
        'base' => (float) ($report['price'] ?? 0),
        'vat_rate' => 0,
        'payment_method' => 'manual'
    ]);
}

function invoice_emit_for_patient_bonus($mysqli, $patient_bonus_id, $payment_method = 'manual')
{
    ensure_invoice_schema($mysqli);
    ensure_bonus_tables($mysqli);
    $tenant_id = current_tenant_id();
    $patient_bonus_id = (int) $patient_bonus_id;
    $stmt = $mysqli->prepare("
        SELECT pb.id, pb.user_id, pb.bonus_id, b.name, b.session_count, b.price
        FROM patient_bonuses pb
        JOIN appointment_bonuses b ON b.id = pb.bonus_id AND b.tenant_id = pb.tenant_id
        WHERE pb.tenant_id = ? AND pb.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_bonus_id);
    $stmt->execute();
    $bonus = $stmt->get_result()->fetch_assoc();
    if (!$bonus) {
        return ['success' => false, 'error' => 'No se encontro el bono a facturar.'];
    }

    $bonus_name = trim((string) ($bonus['name'] ?? 'Bono de sesiones')) ?: 'Bono de sesiones';
    $sessions = (int) ($bonus['session_count'] ?? 0);
    $concept = $sessions > 0 ? "$bonus_name ($sessions sesiones)" : $bonus_name;
    return invoice_emit($mysqli, [
        'origin_type' => 'patient_bonus',
        'origin_id' => $patient_bonus_id,
        'user_id' => (int) $bonus['user_id'],
        'patient_bonus_id' => $patient_bonus_id,
        'concept' => $concept,
        'base' => (float) ($bonus['price'] ?? 0),
        'vat_rate' => 0,
        'payment_method' => $payment_method
    ]);
}
