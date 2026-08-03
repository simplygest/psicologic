<?php
session_start();
require_once '../db.php';
require_once '../settings_helpers.php';
require_once '../plan_usage_helpers.php';
require_once '../payment_helpers.php';
require_once '../livekit_helpers.php';
require_once '../livekit_recording_helpers.php';
require_once '../mail_helpers.php';
require_once '../google_helpers.php';
require_once '../microsoft_helpers.php';
require_once '../caldav_helpers.php';
require_once '../urlme_helpers.php';
require_once '../cabinet_helpers.php';
require_once '../workoutx_helpers.php';
require_once '../app_log_helpers.php';
require_once '../pdf_helpers.php';
require_once '../legal_template_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$action = $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];
$tenant_id = current_tenant_id();
$is_admin = in_array(($_SESSION['role'] ?? ''), ['admin', 'superadmin', 'reception', 'administration', 'technical'], true);
$is_superadmin = ($_SESSION['role'] ?? '') === 'superadmin';
$member_permissions = $is_admin ? cabinet_member_permissions_for_user($mysqli, (int) $user_id, $_SESSION['role'] ?? '') : [];

function appointments_require_member_permission($permission, $error = 'No tienes permiso para realizar esta accion.')
{
    global $is_admin, $is_superadmin, $member_permissions;
    if (!$is_admin || $is_superadmin || !empty($member_permissions[$permission])) {
        return;
    }
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

function appointments_patient_phone($phone)
{
    global $is_admin, $is_superadmin, $member_permissions;
    if (!$is_admin) {
        return (string) ($phone ?? '');
    }
    return ($is_superadmin || !empty($member_permissions['view_patient_phone'])) ? (string) ($phone ?? '') : '';
}

if (!$is_admin && !online_booking_enabled($mysqli)) {
    echo json_encode(['success' => false, 'error' => 'El área de pacientes no está disponible en este momento.']);
    exit;
}

ensure_appointment_payment_columns($mysqli);
ensure_appointment_services_tables($mysqli);
ensure_appointment_locations_table($mysqli);
ensure_bonus_tables($mysqli);
ensure_cabinet_schema($mysqli);
ensure_livekit_recording_schema($mysqli);

if ($is_admin && in_array($action, ['booking_context', 'available_professionals_for_slot', 'get_month', 'get_week'], true)) {
    appointments_require_member_permission('agenda', 'No tienes permiso para acceder a la agenda.');
}
if ($is_admin && $action === 'book') {
    appointments_require_member_permission('create_appointments', 'No tienes permiso para crear citas.');
}
if ($is_admin && $action === 'cancel') {
    appointments_require_member_permission('cancel_appointments', 'No tienes permiso para cancelar citas.');
}

if (!$is_admin && in_array($action, [
    'patient_portal_prepare_autofirma', 'patient_portal_autofirma_source',
    'patient_portal_cancel_autofirma', 'patient_portal_autofirma_certificate',
    'patient_portal_sign_legal_document_autofirma', 'patient_portal_sign_legal_document'
], true) && !plan_feature_enabled_from_db($mysqli, 'legalConsents.portalSignature', false)) {
    echo json_encode(['success' => false, 'error' => 'La firma remota de consentimientos no está disponible en este plan.']);
    exit;
}
if (!$is_admin && in_array($action, [
    'patient_portal_prepare_autofirma', 'patient_portal_autofirma_source',
    'patient_portal_autofirma_certificate', 'patient_portal_sign_legal_document_autofirma'
], true) && !plan_feature_enabled_from_db($mysqli, 'legalConsents.autofirma', false)) {
    echo json_encode(['success' => false, 'error' => 'La firma con AutoFirma no está disponible en este plan.']);
    exit;
}

function ensure_patient_portal_work_plan_schema($mysqli)
{
    if (function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }

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
            fitness_exercise_id VARCHAR(40) DEFAULT NULL,
            document_id INT UNSIGNED DEFAULT NULL,
            created_by INT UNSIGNED DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            completed_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_work_plan_appointment (appointment_id),
            INDEX idx_work_plan_patient_status (patient_id, status),
            INDEX idx_work_plan_professional (professional_id),
            INDEX idx_work_plan_priority (priority),
            INDEX idx_work_plan_fitness_exercise (fitness_exercise_id)
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
    $column_res = $mysqli->query("SHOW COLUMNS FROM patient_work_plan_tasks LIKE 'fitness_exercise_id'");
    if ($column_res && $column_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE patient_work_plan_tasks ADD fitness_exercise_id VARCHAR(40) DEFAULT NULL AFTER visible_to_patient");
    }
    $column_res = $mysqli->query("SHOW COLUMNS FROM patient_work_plan_tasks LIKE 'document_id'");
    if ($column_res && $column_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE patient_work_plan_tasks ADD document_id INT UNSIGNED DEFAULT NULL AFTER fitness_exercise_id");
    }
}

function patient_portal_table_exists($mysqli, $table_name)
{
    $table_name = trim((string) $table_name);
    if ($table_name === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
        return false;
    }
    $stmt = $mysqli->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $table_name);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_row();
}

function patient_portal_column_exists($mysqli, $table_name, $column_name)
{
    $table_name = trim((string) $table_name);
    $column_name = trim((string) $column_name);
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name) || !preg_match('/^[a-zA-Z0-9_]+$/', $column_name)) {
        return false;
    }
    $stmt = $mysqli->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $table_name, $column_name);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_row();
}

function patient_portal_physical_metrics_enabled($mysqli)
{
    $branding = get_public_branding_settings($mysqli);
    $sector_key = strtolower((string) ($branding['sector_texts_key'] ?? ''));
    return in_array($sector_key, ['fitness', 'fisioterapia', 'quiropractica', 'osteopatia', 'nutricion'], true);
}

function patient_portal_decimal($value)
{
    if ($value === null || $value === '') {
        return '';
    }
    return (string) $value;
}

function patient_portal_signature_png(string $data_url): string
{
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\r\n]+)$#', trim($data_url), $matches)) {
        throw new RuntimeException('La firma manuscrita no tiene un formato válido.');
    }
    $png = base64_decode(preg_replace('/\s+/', '', $matches[1]), true);
    $info = $png !== false ? @getimagesizefromstring($png) : false;
    if ($png === false || strlen($png) < 100 || strlen($png) > 2 * 1024 * 1024 || !$info || ($info['mime'] ?? '') !== 'image/png') {
        throw new RuntimeException('La firma manuscrita está vacía o no es válida.');
    }
    return $png;
}

function patient_portal_create_signed_consent(string $source_path, string $title, string $patient_name, string $signer_name, string $signer_nif, string $signature_png): array
{
    if (!pdf_mpdf_available() || !is_file($source_path) || !is_readable($source_path)) {
        throw new RuntimeException('No se pudo abrir el PDF original del consentimiento.');
    }
    $upload_dir = app_tenant_protected_upload_dir('legal_signed');
    if (!app_ensure_dir($upload_dir)) {
        throw new RuntimeException('No se pudo preparar la carpeta de consentimientos firmados.');
    }
    $stored_name = 'patient_legal_handwritten_' . bin2hex(random_bytes(12)) . '.pdf';
    $destination = $upload_dir . '/' . $stored_name;
    $source_hash = hash_file('sha256', $source_path);
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => sys_get_temp_dir()]);
    $page_count = $mpdf->SetSourceFile($source_path);
    for ($page = 1; $page <= $page_count; $page++) {
        $template = $mpdf->ImportPage($page);
        $size = $mpdf->GetTemplateSize($template);
        $orientation = ($size['width'] ?? 0) > ($size['height'] ?? 0) ? 'L' : 'P';
        $mpdf->AddPage($orientation, '', '', '', '', 0, 0, 0, 0, 0, 0);
        $mpdf->UseTemplate($template, 0, 0, $size['width'], $size['height']);
    }
    $h = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $mpdf->AddPage('P');
    $mpdf->WriteHTML('<h1>Constancia de aceptación y firma</h1><hr>'
        . '<p><strong>Documento:</strong> ' . $h($title) . '</p>'
        . '<p><strong>Paciente:</strong> ' . $h($patient_name) . '</p>'
        . '<p><strong>Firmante:</strong> ' . $h($signer_name) . '</p>'
        . ($signer_nif !== '' ? '<p><strong>NIF/NIE:</strong> ' . $h($signer_nif) . '</p>' : '')
        . '<p><strong>Fecha y hora:</strong> ' . $h(date('d/m/Y H:i:s')) . '</p>'
        . '<p>La persona firmante declara haber leído y aceptado el documento anterior.</p>'
        . '<p><strong>Firma manuscrita</strong></p>'
        . '<img style="width:270px;height:110px;" src="data:image/png;base64,' . base64_encode($signature_png) . '">'
        . '<p style="font-size:9px;color:#66737d">Huella SHA-256 del documento original: ' . $h($source_hash) . '</p>');
    $mpdf->Output($destination, \Mpdf\Output\Destination::FILE);
    return [
        'path' => app_tenant_protected_upload_relative_path('legal_signed', $stored_name),
        'name' => pdf_safe_filename($title . '-firmado'),
        'size' => filesize($destination),
        'mime' => 'application/pdf',
        'source_sha256' => $source_hash,
        'signed_sha256' => hash_file('sha256', $destination)
    ];
}

function patient_portal_autofirma_decode(string $value, int $max_bytes, string $label): string
{
    $value = preg_replace('/\s+/', '', trim($value));
    $value = strtr($value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    if ($value === '' || strlen($value) > (int) ceil($max_bytes * 4 / 3) + 8) {
        throw new RuntimeException($label . ' no tiene un tamaño válido.');
    }
    $decoded = base64_decode($value, true);
    if ($decoded === false || $decoded === '' || strlen($decoded) > $max_bytes) {
        throw new RuntimeException($label . ' no contiene Base64 válido.');
    }
    return $decoded;
}

function patient_portal_autofirma_certificate_info(string $certificate_der): array
{
    if (!function_exists('openssl_x509_parse')) {
        throw new RuntimeException('El servidor no dispone del soporte OpenSSL necesario para leer el certificado.');
    }
    $certificate_pem = "-----BEGIN CERTIFICATE-----\n"
        . chunk_split(base64_encode($certificate_der), 64, "\n")
        . "-----END CERTIFICATE-----\n";
    $parsed = @openssl_x509_parse($certificate_pem);
    if (!is_array($parsed)) {
        throw new RuntimeException('AutoFirma no devolvió un certificado X.509 válido.');
    }

    $subject = is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
    $issuer = is_array($parsed['issuer'] ?? null) ? $parsed['issuer'] : [];
    $subject_value = static function (array $values, array $keys): string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $value = $values[$key];
            if (is_array($value)) {
                $value = reset($value);
            }
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    };

    $given_name = $subject_value($subject, ['GN', 'givenName']);
    $surname = $subject_value($subject, ['SN', 'surname']);
    $common_name = $subject_value($subject, ['CN']);
    $name = trim($given_name . ' ' . $surname);
    if ($name === '') {
        $name = $common_name;
    }

    $nif_candidates = [
        $subject_value($subject, ['serialNumber', '2.5.4.5']),
        $subject_value($subject, ['UID', 'uniqueIdentifier']),
        $common_name,
    ];
    $nif = '';
    foreach ($nif_candidates as $candidate) {
        $candidate = strtoupper(trim((string) $candidate));
        if (preg_match('/(?:IDCES-|NIF[:=\s-]*)?([XYZ]\d{7}[A-Z]|\d{8}[A-Z]|[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J])/i', $candidate, $matches)) {
            $nif = strtoupper($matches[1]);
            break;
        }
    }

    return [
        'name' => $name,
        'nif' => $nif,
        'common_name' => $common_name,
        'organization' => $subject_value($subject, ['O']),
        'issuer' => $subject_value($issuer, ['CN', 'O']),
        'serial' => (string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? ''),
        'fingerprint_sha256' => hash('sha256', $certificate_der),
        'valid_from' => isset($parsed['validFrom_time_t']) ? date('d/m/Y H:i', (int) $parsed['validFrom_time_t']) : '',
        'valid_to' => isset($parsed['validTo_time_t']) ? date('d/m/Y H:i', (int) $parsed['validTo_time_t']) : '',
        'valid_from_timestamp' => (int) ($parsed['validFrom_time_t'] ?? 0),
        'valid_to_timestamp' => (int) ($parsed['validTo_time_t'] ?? 0),
    ];
}

function patient_portal_autofirma_der_length(string $der): int
{
    $size = strlen($der);
    if ($size < 2 || ord($der[0]) !== 0x30) {
        return 0;
    }
    $first_length = ord($der[1]);
    if (($first_length & 0x80) === 0) {
        return min($size, 2 + $first_length);
    }
    $length_bytes = $first_length & 0x7f;
    if ($length_bytes <= 0 || $length_bytes > 4 || $size < 2 + $length_bytes) {
        return 0;
    }
    $content_length = 0;
    for ($index = 0; $index < $length_bytes; $index++) {
        $content_length = ($content_length << 8) | ord($der[2 + $index]);
    }
    $total = 2 + $length_bytes + $content_length;
    return $total > 0 && $total <= $size ? $total : 0;
}

function patient_portal_validate_autofirma_pdf(string $source_pdf, string $signed_pdf, string $certificate_der): array
{
    if (strncmp($source_pdf, '%PDF-', 5) !== 0 || strncmp($signed_pdf, '%PDF-', 5) !== 0) {
        throw new RuntimeException('El documento original o el resultado de AutoFirma no es un PDF válido.');
    }
    if (strlen($signed_pdf) <= strlen($source_pdf) || strncmp($signed_pdf, $source_pdf, strlen($source_pdf)) !== 0) {
        throw new RuntimeException('El PDF firmado no conserva exactamente el documento que se mostró para firmar.');
    }

    if (!preg_match_all('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $signed_pdf, $ranges, PREG_SET_ORDER) || !$ranges) {
        throw new RuntimeException('El resultado no contiene una firma PAdES reconocible.');
    }
    $range = end($ranges);
    $offset_a = (int) $range[1];
    $length_a = (int) $range[2];
    $offset_b = (int) $range[3];
    $length_b = (int) $range[4];
    if ($offset_a !== 0 || $length_a <= 0 || $offset_b <= $length_a || $length_b <= 0 || $offset_b + $length_b !== strlen($signed_pdf)) {
        throw new RuntimeException('La cobertura ByteRange de la firma PAdES no es coherente.');
    }

    $signature_gap = substr($signed_pdf, $length_a, $offset_b - $length_a);
    if (!preg_match('/\/Contents\s*<([0-9A-Fa-f\s]+)>/s', $signature_gap, $contents_match)) {
        throw new RuntimeException('No se encontró el contenedor criptográfico de la firma.');
    }
    $contents_hex = preg_replace('/\s+/', '', $contents_match[1]);
    $cms_padded = hex2bin($contents_hex);
    if ($cms_padded === false) {
        throw new RuntimeException('El contenedor criptográfico de la firma no es válido.');
    }
    $cms_length = patient_portal_autofirma_der_length($cms_padded);
    if ($cms_length <= 0) {
        throw new RuntimeException('No se pudo interpretar el contenedor CMS de la firma.');
    }
    $cms = substr($cms_padded, 0, $cms_length);
    if (strpos($cms, $certificate_der) === false) {
        throw new RuntimeException('El certificado devuelto no coincide con el incluido en el PDF firmado.');
    }

    return [
        'source_sha256' => hash('sha256', $source_pdf),
        'signed_sha256' => hash('sha256', $signed_pdf),
        'byte_range' => [$offset_a, $length_a, $offset_b, $length_b],
        'cms_sha256' => hash('sha256', $cms),
    ];
}

function patient_portal_store_autofirma_consent(string $source_path, string $title, string $signed_pdf, string $certificate_der): array
{
    if (!is_file($source_path) || !is_readable($source_path)) {
        throw new RuntimeException('No se pudo abrir el PDF original del consentimiento.');
    }
    $source_pdf = file_get_contents($source_path);
    if ($source_pdf === false) {
        throw new RuntimeException('No se pudo leer el PDF original del consentimiento.');
    }
    $validation = patient_portal_validate_autofirma_pdf($source_pdf, $signed_pdf, $certificate_der);
    $upload_dir = app_tenant_protected_upload_dir('legal_signed');
    if (!app_ensure_dir($upload_dir)) {
        throw new RuntimeException('No se pudo preparar la carpeta de consentimientos firmados.');
    }
    $stored_name = 'patient_legal_autofirma_' . bin2hex(random_bytes(12)) . '.pdf';
    $destination = $upload_dir . '/' . $stored_name;
    if (file_put_contents($destination, $signed_pdf, LOCK_EX) !== strlen($signed_pdf)) {
        @unlink($destination);
        throw new RuntimeException('No se pudo guardar el PDF firmado.');
    }
    return [
        'path' => app_tenant_protected_upload_relative_path('legal_signed', $stored_name),
        'absolute_path' => $destination,
        'name' => pdf_safe_filename($title . '-firmado'),
        'size' => filesize($destination),
        'mime' => 'application/pdf',
        'source_sha256' => $validation['source_sha256'],
        'signed_sha256' => $validation['signed_sha256'],
        'cms_sha256' => $validation['cms_sha256'],
        'byte_range' => $validation['byte_range'],
    ];
}

function patient_portal_physical_payload(array $row): array
{
    $weight = patient_portal_decimal($row['weight_kg'] ?? '');
    $height = patient_portal_decimal($row['height_cm'] ?? '');
    $bmi = '';
    if ((float) $weight > 0 && (float) $height > 0) {
        $height_m = ((float) $height) / 100;
        $bmi = number_format(((float) $weight) / ($height_m * $height_m), 1, '.', '');
    }
    return [
        'date' => $row['note_date'] ?? ($row['updated_at'] ?? ''),
        'physical_sex' => $row['physical_sex'] ?? '',
        'weight_kg' => $weight,
        'height_cm' => $height,
        'bmi' => $bmi,
        'body_fat_percentage' => patient_portal_decimal($row['body_fat_percentage'] ?? ''),
        'waist_cm' => patient_portal_decimal($row['waist_cm'] ?? ''),
        'hip_cm' => patient_portal_decimal($row['hip_cm'] ?? ''),
        'chest_cm' => patient_portal_decimal($row['chest_cm'] ?? ''),
        'thigh_cm' => patient_portal_decimal($row['thigh_cm'] ?? ''),
        'biceps_cm' => patient_portal_decimal($row['biceps_cm'] ?? ''),
        'calf_cm' => patient_portal_decimal($row['calf_cm'] ?? ''),
        'skinfold_triceps_mm' => patient_portal_decimal($row['skinfold_triceps_mm'] ?? ''),
        'skinfold_subscapular_mm' => patient_portal_decimal($row['skinfold_subscapular_mm'] ?? ''),
        'skinfold_suprailiac_mm' => patient_portal_decimal($row['skinfold_suprailiac_mm'] ?? ''),
        'skinfold_abdominal_mm' => patient_portal_decimal($row['skinfold_abdominal_mm'] ?? ''),
        'skinfold_chest_mm' => patient_portal_decimal($row['skinfold_chest_mm'] ?? ''),
        'skinfold_thigh_mm' => patient_portal_decimal($row['skinfold_thigh_mm'] ?? '')
    ];
}

function ensure_patient_portal_fitness_exercise_columns($mysqli)
{
    if (function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }

    if (!patient_portal_table_exists($mysqli, 'fitness_exercises')) {
        return;
    }
    $columns = [
        'calories_per_min' => "ALTER TABLE fitness_exercises ADD calories_per_min DECIMAL(6,2) DEFAULT NULL",
        'description_en' => "ALTER TABLE fitness_exercises ADD description_en TEXT DEFAULT NULL",
        'instructions_en' => "ALTER TABLE fitness_exercises ADD instructions_en LONGTEXT DEFAULT NULL",
        'instructions_es' => "ALTER TABLE fitness_exercises ADD instructions_es LONGTEXT DEFAULT NULL",
        'secondary_muscles_en' => "ALTER TABLE fitness_exercises ADD secondary_muscles_en TEXT DEFAULT NULL"
    ];
    foreach ($columns as $column => $sql) {
        $column_res = $mysqli->query("SHOW COLUMNS FROM fitness_exercises LIKE '$column'");
        if ($column_res && $column_res->num_rows === 0) {
            $mysqli->query($sql);
        }
    }
}

function patient_portal_work_plan_exercise($mysqli, $tenant_id, $patient_id, $task_id)
{
    ensure_patient_portal_work_plan_schema($mysqli);
    if (!patient_portal_table_exists($mysqli, 'fitness_exercises')) {
        return null;
    }
    ensure_patient_portal_fitness_exercise_columns($mysqli);
    $stmt = $mysqli->prepare("
        SELECT t.id AS task_id,
               pp.weight_kg AS patient_weight_kg,
               fe.exercise_id, fe.name_en, fe.name_es, fe.description_es, fe.description_en, fe.cues_es,
               fe.instructions_en, fe.instructions_es, fe.secondary_muscles_en,
               fe.image_url, fe.external_source, fe.external_id,
               fe.workoutx_body_part, fe.workoutx_target, fe.workoutx_equipment,
               fe.calories_per_min
        FROM patient_work_plan_tasks t
        INNER JOIN fitness_exercises fe ON fe.exercise_id = t.fitness_exercise_id AND fe.active = 1
        LEFT JOIN patient_profiles pp ON pp.tenant_id = t.tenant_id AND pp.user_id = t.patient_id
        WHERE t.tenant_id = ?
          AND t.patient_id = ?
          AND t.id = ?
          AND t.visible_to_patient = 1
        LIMIT 1
    ");
    $stmt->bind_param("iii", $tenant_id, $patient_id, $task_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function ensure_patient_portal_documents_schema($mysqli)
{
    if (function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }

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
    $column_res = $mysqli->query("SHOW COLUMNS FROM patient_evolution_files LIKE 'visible_to_patient'");
    if ($column_res && $column_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE patient_evolution_files ADD visible_to_patient TINYINT(1) NOT NULL DEFAULT 0 AFTER file_size");
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
    if (function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }

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
    if (function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }

    $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'appointment_delivery_mode'");
    if ($column_res && $column_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE payment_settings ADD appointment_delivery_mode ENUM('both', 'presencial', 'online') NOT NULL DEFAULT 'both' AFTER admin_notification_email");
    }
}

function ensure_session_setting_column($mysqli)
{
    if (function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }

    $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'available_session_types'");
    if ($column_res && $column_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE payment_settings ADD available_session_types VARCHAR(255) NOT NULL DEFAULT 'individual' AFTER appointment_delivery_mode");
    }

    $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'available_session_durations'");
    if ($column_res && $column_res->num_rows === 0) {
        $mysqli->query("ALTER TABLE payment_settings ADD available_session_durations VARCHAR(100) NOT NULL DEFAULT '60' AFTER available_session_types");
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
        'discount_period_enabled' => 0,
        'discount_period_start_date' => null,
        'discount_period_end_date' => null,
        'discount_show_public' => 0,
        'bonuses_enabled' => 0,
        'create_compensation_bonus_on_paid_cancel' => 1,
        'work_plan_task_status_enabled' => 1
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
    if (function_exists('app_auto_schema_migrations_enabled') && app_auto_schema_migrations_enabled()) {
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
    }
    $settings_res = $mysqli->query("
        SELECT online_payment_enabled, appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price, available_session_types, available_session_durations, display_effective_duration_enabled, display_duration_offset_minutes,
               discount_period_enabled, discount_period_start_date, discount_period_end_date, discount_show_public,
               min_booking_notice_days, max_booking_notice_days,
               appointment_start_time, appointment_end_time, break_start_time, break_end_time,
               available_weekdays, appointment_delivery_mode, bonuses_enabled, create_compensation_bonus_on_paid_cancel, work_plan_task_status_enabled
        FROM payment_settings
        WHERE tenant_id = $tenant_id
    ");
    if ($settings_res && ($settings_row = $settings_res->fetch_assoc())) {
        $payment_settings = array_merge($payment_settings, $settings_row);
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

if ($action === 'patient_portal_download_evolution_file') {
    if ($is_admin) {
        http_response_code(403);
        echo 'Disponible solo para pacientes.';
        exit;
    }
    ensure_patient_portal_documents_schema($mysqli);
    $file_id = (int) ($_GET['id'] ?? 0);
    $stmt = $mysqli->prepare("
        SELECT file_path, original_name, mime_type
        FROM patient_evolution_files
        WHERE tenant_id = ? AND id = ? AND patient_id = ? AND visible_to_patient = 1
        LIMIT 1
    ");
    $stmt->bind_param("iii", $tenant_id, $file_id, $user_id);
    $stmt->execute();
    $file = $stmt->get_result()->fetch_assoc();
    if (!$file) {
        http_response_code(404);
        echo 'Archivo no encontrado';
        exit;
    }
    patient_portal_download_upload($file['file_path'], $file['mime_type'] ?? '', $file['original_name'] ?? '');
} elseif ($action === 'patient_portal_download_document') {
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
} elseif ($action === 'patient_portal_legal_template') {
    if ($is_admin) {
        http_response_code(403);
        exit;
    }
    $document_id = (int) ($_GET['id'] ?? 0);
    $stmt = $mysqli->prepare("
        SELECT ld.id, ld.title, ld.category, ld.version_label, ld.file_path, ld.original_file_name,
               ld.template_type, ld.content_json
        FROM legal_documents ld
        WHERE ld.tenant_id = ? AND ld.id = ? AND ld.is_active = 1
          AND (
            ld.is_required = 1 OR EXISTS (
              SELECT 1 FROM service_legal_documents sld
              INNER JOIN appointment_service_options aso
                ON aso.tenant_id = sld.tenant_id AND aso.service_id = sld.service_id
              INNER JOIN appointments a
                ON a.tenant_id = aso.tenant_id AND a.service_option_id = aso.id
              WHERE sld.tenant_id = ld.tenant_id AND sld.legal_document_id = ld.id
                AND a.user_id = ? AND a.status <> 'cancelled'
            )
          )
        LIMIT 1
    ");
    $stmt->bind_param("iii", $tenant_id, $document_id, $user_id);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc();
    if ($document && ($document['template_type'] ?? 'uploaded_pdf') === 'generated') {
        try {
            $temporary = tempnam(sys_get_temp_dir(), 'sgp_patient_legal_');
            legal_template_generate_pdf_file($mysqli, $document, $tenant_id, $user_id, $temporary, false);
            while (ob_get_level() > 0) ob_end_clean();
            header_remove('Content-Type');
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . addslashes(pdf_safe_filename($document['title'] ?? 'consentimiento')) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . filesize($temporary));
            readfile($temporary);
            @unlink($temporary);
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'No se pudo generar el consentimiento.';
        }
        exit;
    }
    $full_path = $document ? app_protected_path_from_relative($document['file_path'] ?? '') : '';
    if (!$full_path || !is_file($full_path)) {
        http_response_code(404);
        exit;
    }
    while (ob_get_level() > 0) ob_end_clean();
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . addslashes($document['original_file_name'] ?: basename($full_path)) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($full_path));
    readfile($full_path);
    exit;
} elseif ($action === 'patient_portal_prepare_autofirma') {
    if ($is_admin || !defined('AUTOFIRMA_PATIENT_SIGNING_ENABLED') || !AUTOFIRMA_PATIENT_SIGNING_ENABLED) {
        echo json_encode(['success' => false, 'error' => 'La firma con AutoFirma no está disponible.']);
        exit;
    }
    $document_id = (int) ($_POST['legal_document_id'] ?? 0);
    $source = null;
    try {
        $stmt = $mysqli->prepare("
            SELECT ld.id, ld.title, ld.category, ld.version_label, ld.file_path, ld.original_file_name,
                   ld.template_type, ld.content_json
            FROM legal_documents ld
            LEFT JOIN patient_legal_documents pld
              ON pld.tenant_id = ld.tenant_id AND pld.patient_id = ? AND pld.legal_document_id = ld.id
            WHERE ld.tenant_id = ? AND ld.id = ? AND ld.is_active = 1 AND COALESCE(pld.accepted, 0) = 0
              AND (
                ld.is_required = 1 OR EXISTS (
                  SELECT 1 FROM service_legal_documents sld
                  INNER JOIN appointment_service_options aso
                    ON aso.tenant_id = sld.tenant_id AND aso.service_id = sld.service_id
                  INNER JOIN appointments a
                    ON a.tenant_id = aso.tenant_id AND a.service_option_id = aso.id
                  WHERE sld.tenant_id = ld.tenant_id AND sld.legal_document_id = ld.id
                    AND a.user_id = ? AND a.status <> 'cancelled'
                )
              )
            LIMIT 1
        ");
        $stmt->bind_param("iiii", $user_id, $tenant_id, $document_id, $user_id);
        $stmt->execute();
        $document = $stmt->get_result()->fetch_assoc();
        if (!$document) {
            throw new RuntimeException('El consentimiento no está pendiente o ya no está disponible.');
        }
        $source = legal_template_materialize_source($mysqli, $document, $tenant_id, $user_id);
        $pending_dir = app_tenant_protected_upload_dir('autofirma_pending');
        if (!app_ensure_dir($pending_dir)) {
            throw new RuntimeException('No se pudo preparar la operación temporal de firma.');
        }
        $token = bin2hex(random_bytes(24));
        $pending_path = $pending_dir . '/' . $token . '.pdf';
        if (!copy($source['path'], $pending_path)) {
            throw new RuntimeException('No se pudo preparar el PDF que se va a firmar.');
        }

        $previous = is_array($_SESSION['patient_autofirma_transactions'] ?? null)
            ? $_SESSION['patient_autofirma_transactions']
            : [];
        foreach ($previous as $previous_token => $transaction) {
            if ((int) ($transaction['expires_at'] ?? 0) < time()) {
                if (!empty($transaction['path']) && is_file($transaction['path'])) {
                    @unlink($transaction['path']);
                }
                unset($previous[$previous_token]);
            }
        }
        $previous[$token] = [
            'tenant_id' => $tenant_id,
            'user_id' => $user_id,
            'document_id' => $document_id,
            'path' => $pending_path,
            'source_sha256' => hash_file('sha256', $pending_path),
            'expires_at' => time() + 15 * 60,
        ];
        $_SESSION['patient_autofirma_transactions'] = $previous;
        echo json_encode([
            'success' => true,
            'token' => $token,
            'source_url' => 'api/appointments.php?action=patient_portal_autofirma_source&token=' . rawurlencode($token),
        ], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } finally {
        if (!empty($source['temporary']) && !empty($source['path']) && is_file($source['path'])) {
            @unlink($source['path']);
        }
    }
    exit;
} elseif ($action === 'patient_portal_autofirma_source') {
    if ($is_admin || !defined('AUTOFIRMA_PATIENT_SIGNING_ENABLED') || !AUTOFIRMA_PATIENT_SIGNING_ENABLED) {
        http_response_code(403);
        exit;
    }
    $token = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($_GET['token'] ?? '')));
    $transactions = is_array($_SESSION['patient_autofirma_transactions'] ?? null)
        ? $_SESSION['patient_autofirma_transactions']
        : [];
    $transaction = $transactions[$token] ?? null;
    if (
        !is_array($transaction)
        || (int) ($transaction['tenant_id'] ?? 0) !== $tenant_id
        || (int) ($transaction['user_id'] ?? 0) !== $user_id
        || (int) ($transaction['expires_at'] ?? 0) < time()
        || empty($transaction['path'])
        || !is_file($transaction['path'])
    ) {
        http_response_code(404);
        exit;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="consentimiento-autofirma.pdf"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($transaction['path']));
    readfile($transaction['path']);
    exit;
} elseif ($action === 'patient_portal_cancel_autofirma') {
    if ($is_admin) {
        echo json_encode(['success' => false, 'error' => 'Disponible solo para pacientes.']);
        exit;
    }
    $token = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($_POST['transaction_token'] ?? '')));
    $transactions = is_array($_SESSION['patient_autofirma_transactions'] ?? null)
        ? $_SESSION['patient_autofirma_transactions']
        : [];
    $transaction = $transactions[$token] ?? null;
    if (
        is_array($transaction)
        && (int) ($transaction['tenant_id'] ?? 0) === $tenant_id
        && (int) ($transaction['user_id'] ?? 0) === $user_id
    ) {
        if (!empty($transaction['path']) && is_file($transaction['path'])) {
            @unlink($transaction['path']);
        }
        unset($transactions[$token]);
        $_SESSION['patient_autofirma_transactions'] = $transactions;
    }
    echo json_encode(['success' => true]);
    exit;
} elseif ($action === 'patient_portal_autofirma_certificate') {
    if ($is_admin || !defined('AUTOFIRMA_PATIENT_SIGNING_ENABLED') || !AUTOFIRMA_PATIENT_SIGNING_ENABLED) {
        echo json_encode(['success' => false, 'error' => 'La firma con AutoFirma no está disponible.']);
        exit;
    }
    try {
        $certificate_der = patient_portal_autofirma_decode((string) ($_POST['certificate'] ?? ''), 256 * 1024, 'El certificado');
        $certificate = patient_portal_autofirma_certificate_info($certificate_der);
        echo json_encode(['success' => true, 'certificate' => $certificate], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
} elseif ($action === 'patient_portal_sign_legal_document_autofirma') {
    if ($is_admin || !defined('AUTOFIRMA_PATIENT_SIGNING_ENABLED') || !AUTOFIRMA_PATIENT_SIGNING_ENABLED) {
        echo json_encode(['success' => false, 'error' => 'La firma con AutoFirma no está disponible.']);
        exit;
    }
    $document_id = (int) ($_POST['legal_document_id'] ?? 0);
    $transaction_token = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($_POST['transaction_token'] ?? '')));
    $posted_signer_name = trim((string) ($_POST['signer_name'] ?? ''));
    $posted_signer_nif = strtoupper(trim((string) ($_POST['signer_nif'] ?? '')));
    $generated = null;
    $transactions = is_array($_SESSION['patient_autofirma_transactions'] ?? null)
        ? $_SESSION['patient_autofirma_transactions']
        : [];
    $transaction = $transactions[$transaction_token] ?? null;
    try {
        if (
            !is_array($transaction)
            || (int) ($transaction['tenant_id'] ?? 0) !== $tenant_id
            || (int) ($transaction['user_id'] ?? 0) !== $user_id
            || (int) ($transaction['document_id'] ?? 0) !== $document_id
            || (int) ($transaction['expires_at'] ?? 0) < time()
            || empty($transaction['path'])
            || !is_file($transaction['path'])
        ) {
            throw new RuntimeException('La operación de AutoFirma ha caducado. Vuelve a iniciar la firma.');
        }
        $signed_pdf = patient_portal_autofirma_decode((string) ($_POST['signed_pdf'] ?? ''), 16 * 1024 * 1024, 'El PDF firmado');
        $certificate_der = patient_portal_autofirma_decode((string) ($_POST['certificate'] ?? ''), 256 * 1024, 'El certificado');
        $certificate = patient_portal_autofirma_certificate_info($certificate_der);
        $now = time();
        if (!empty($certificate['valid_from_timestamp']) && $now < (int) $certificate['valid_from_timestamp']) {
            throw new RuntimeException('El certificado todavía no es válido.');
        }
        if (!empty($certificate['valid_to_timestamp']) && $now > (int) $certificate['valid_to_timestamp']) {
            throw new RuntimeException('El certificado utilizado está caducado.');
        }
        $signer_name = trim((string) ($certificate['name'] ?? '')) ?: $posted_signer_name;
        $signer_nif = trim((string) ($certificate['nif'] ?? '')) ?: $posted_signer_nif;
        if ($signer_name === '') {
            throw new RuntimeException('No se pudo identificar el nombre de la persona firmante.');
        }
        $expected_signer = legal_consent_expected_signer($mysqli, $tenant_id, $user_id);
        legal_consent_validate_signer_identity(
            $expected_signer,
            $signer_name,
            $signer_nif,
            (string) ($certificate['nif'] ?? '')
        );
        $signer_name = trim((string) ($expected_signer['name'] ?? '')) ?: $signer_name;
        $signer_nif = trim((string) ($expected_signer['nif'] ?? '')) ?: legal_consent_normalize_nif($signer_nif);

        $stmt = $mysqli->prepare("
            SELECT ld.id, ld.title, ld.category, ld.version_label, ld.file_path, ld.original_file_name,
                   ld.template_type, ld.content_json, u.name AS patient_name
            FROM legal_documents ld
            INNER JOIN users u ON u.tenant_id = ld.tenant_id AND u.id = ? AND u.role = 'patient'
            LEFT JOIN patient_legal_documents pld
              ON pld.tenant_id = ld.tenant_id AND pld.patient_id = u.id AND pld.legal_document_id = ld.id
            WHERE ld.tenant_id = ? AND ld.id = ? AND ld.is_active = 1 AND COALESCE(pld.accepted, 0) = 0
              AND (
                ld.is_required = 1 OR EXISTS (
                  SELECT 1 FROM service_legal_documents sld
                  INNER JOIN appointment_service_options aso
                    ON aso.tenant_id = sld.tenant_id AND aso.service_id = sld.service_id
                  INNER JOIN appointments a
                    ON a.tenant_id = aso.tenant_id AND a.service_option_id = aso.id
                  WHERE sld.tenant_id = ld.tenant_id AND sld.legal_document_id = ld.id
                    AND a.user_id = ? AND a.status <> 'cancelled'
                )
              )
            LIMIT 1
        ");
        $stmt->bind_param("iiii", $user_id, $tenant_id, $document_id, $user_id);
        $stmt->execute();
        $document = $stmt->get_result()->fetch_assoc();
        if (!$document) {
            throw new RuntimeException('El consentimiento no está pendiente o ya no está disponible.');
        }

        $generated = patient_portal_store_autofirma_consent(
            $transaction['path'],
            (string) $document['title'],
            $signed_pdf,
            $certificate_der
        );

        $mysqli->begin_transaction();
        $stmt = $mysqli->prepare("
            INSERT INTO patient_legal_documents
                (tenant_id, patient_id, legal_document_id, accepted, accepted_at, accepted_by, acceptance_note,
                 signed_file_path, original_file_name, file_size, mime_type, signed_uploaded_by, signed_uploaded_at,
                 signature_method, signer_name, signer_nif, source_pdf_sha256, signed_pdf_sha256)
            VALUES (?, ?, ?, 1, NOW(), ?, 'Aceptado y firmado con certificado digital mediante AutoFirma.',
                    ?, ?, ?, ?, ?, NOW(), 'autofirma_patient', ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                accepted = 1, accepted_at = NOW(), accepted_by = VALUES(accepted_by),
                acceptance_note = VALUES(acceptance_note), signed_file_path = VALUES(signed_file_path),
                original_file_name = VALUES(original_file_name), file_size = VALUES(file_size),
                mime_type = VALUES(mime_type), signed_uploaded_by = VALUES(signed_uploaded_by),
                signed_uploaded_at = NOW(), signature_method = VALUES(signature_method),
                signer_name = VALUES(signer_name), signer_nif = VALUES(signer_nif),
                source_pdf_sha256 = VALUES(source_pdf_sha256), signed_pdf_sha256 = VALUES(signed_pdf_sha256)
        ");
        $stmt->bind_param(
            "iiiissisissss",
            $tenant_id, $user_id, $document_id, $user_id,
            $generated['path'], $generated['name'], $generated['size'], $generated['mime'], $user_id,
            $signer_name, $signer_nif, $generated['source_sha256'], $generated['signed_sha256']
        );
        $stmt->execute();
        $patient_document_id = (int) $mysqli->insert_id;
        if ($patient_document_id <= 0) {
            $stmt = $mysqli->prepare("SELECT id FROM patient_legal_documents WHERE tenant_id = ? AND patient_id = ? AND legal_document_id = ? LIMIT 1");
            $stmt->bind_param("iii", $tenant_id, $user_id, $document_id);
            $stmt->execute();
            $patient_document_id = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0);
        }

        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $certificate_metadata = $certificate;
        unset($certificate_metadata['valid_from_timestamp'], $certificate_metadata['valid_to_timestamp']);
        $metadata = json_encode([
            'origin' => 'patient_portal',
            'document_title' => $document['title'],
            'certificate' => $certificate_metadata,
            'cms_sha256' => $generated['cms_sha256'],
            'byte_range' => $generated['byte_range'],
            'validation' => 'source_and_embedded_certificate',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $mysqli->prepare("
            INSERT INTO legal_consent_audit
                (tenant_id, patient_id, legal_document_id, patient_legal_document_id, action, signature_method,
                 signer_name, signer_nif, source_pdf_sha256, signed_pdf_sha256, source_ip, user_agent, performed_by, metadata_json)
            VALUES (?, ?, ?, ?, 'consent_signed', 'autofirma_patient', ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "iiiissssssis",
            $tenant_id, $user_id, $document_id, $patient_document_id,
            $signer_name, $signer_nif, $generated['source_sha256'], $generated['signed_sha256'],
            $ip, $agent, $user_id, $metadata
        );
        $stmt->execute();
        $mysqli->commit();

        app_log_write($mysqli, [
            'tenant_id' => $tenant_id,
            'user_id' => $user_id,
            'action' => 'patient_legal_document_signed_autofirma',
            'entity_type' => 'patient_legal_document',
            'entity_id' => $patient_document_id,
            'title' => 'Consentimiento firmado por el paciente con AutoFirma',
            'description' => (string) $document['title'] . ' - Certificado: ' . (string) ($certificate['common_name'] ?: $certificate['organization'])
        ]);
        echo json_encode([
            'success' => true,
            'message' => 'Consentimiento firmado con AutoFirma y guardado correctamente.',
            'certificate' => $certificate_metadata,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        try {
            $mysqli->rollback();
        } catch (Throwable $ignored) {
        }
        if (!empty($generated['absolute_path']) && is_file($generated['absolute_path'])) {
            @unlink($generated['absolute_path']);
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } finally {
        if (!empty($transaction['path']) && is_file($transaction['path'])) {
            @unlink($transaction['path']);
        }
        unset($transactions[$transaction_token]);
        $_SESSION['patient_autofirma_transactions'] = $transactions;
    }
    exit;
} elseif ($action === 'patient_portal_sign_legal_document') {
    if ($is_admin) {
        echo json_encode(['success' => false, 'error' => 'Disponible solo para pacientes.']);
        exit;
    }
    $document_id = (int) ($_POST['legal_document_id'] ?? 0);
    $signer_name = trim((string) ($_POST['signer_name'] ?? ''));
    $signer_nif = strtoupper(trim((string) ($_POST['signer_nif'] ?? '')));
    if ($signer_name === '') {
        echo json_encode(['success' => false, 'error' => 'Indica el nombre de la persona que firma.']);
        exit;
    }
    try {
        $expected_signer = legal_consent_expected_signer($mysqli, $tenant_id, $user_id);
        legal_consent_validate_signer_identity($expected_signer, $signer_name, $signer_nif);
        $signer_name = trim((string) ($expected_signer['name'] ?? '')) ?: $signer_name;
        $signer_nif = trim((string) ($expected_signer['nif'] ?? '')) ?: legal_consent_normalize_nif($signer_nif);
        $signature_png = patient_portal_signature_png((string) ($_POST['signature_data'] ?? ''));
        $stmt = $mysqli->prepare("
            SELECT ld.id, ld.title, ld.category, ld.version_label, ld.file_path, ld.original_file_name,
                   ld.template_type, ld.content_json, u.name AS patient_name
            FROM legal_documents ld
            INNER JOIN users u ON u.tenant_id = ld.tenant_id AND u.id = ? AND u.role = 'patient'
            LEFT JOIN patient_legal_documents pld
              ON pld.tenant_id = ld.tenant_id AND pld.patient_id = u.id AND pld.legal_document_id = ld.id
            WHERE ld.tenant_id = ? AND ld.id = ? AND ld.is_active = 1 AND COALESCE(pld.accepted, 0) = 0
              AND (
                ld.is_required = 1 OR EXISTS (
                  SELECT 1 FROM service_legal_documents sld
                  INNER JOIN appointment_service_options aso
                    ON aso.tenant_id = sld.tenant_id AND aso.service_id = sld.service_id
                  INNER JOIN appointments a
                    ON a.tenant_id = aso.tenant_id AND a.service_option_id = aso.id
                  WHERE sld.tenant_id = ld.tenant_id AND sld.legal_document_id = ld.id
                    AND a.user_id = ? AND a.status <> 'cancelled'
                )
              )
            LIMIT 1
        ");
        $stmt->bind_param("iiii", $user_id, $tenant_id, $document_id, $user_id);
        $stmt->execute();
        $document = $stmt->get_result()->fetch_assoc();
        if (!$document) {
            throw new RuntimeException('El consentimiento no está pendiente o ya no está disponible.');
        }
        $source = legal_template_materialize_source($mysqli, $document, $tenant_id, $user_id);
        $source_path = $source['path'];
        $generated = patient_portal_create_signed_consent(
            $source_path,
            (string) $document['title'],
            (string) $document['patient_name'],
            $signer_name,
            $signer_nif,
            $signature_png
        );
        $mysqli->begin_transaction();
        $stmt = $mysqli->prepare("
            INSERT INTO patient_legal_documents
                (tenant_id, patient_id, legal_document_id, accepted, accepted_at, accepted_by, acceptance_note,
                 signed_file_path, original_file_name, file_size, mime_type, signed_uploaded_by, signed_uploaded_at,
                 signature_method, signer_name, signer_nif, source_pdf_sha256, signed_pdf_sha256)
            VALUES (?, ?, ?, 1, NOW(), ?, 'Aceptado y firmado online por el paciente en SGPraxis.',
                    ?, ?, ?, ?, ?, NOW(), 'handwritten_online', ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                accepted = 1, accepted_at = NOW(), accepted_by = VALUES(accepted_by),
                acceptance_note = VALUES(acceptance_note), signed_file_path = VALUES(signed_file_path),
                original_file_name = VALUES(original_file_name), file_size = VALUES(file_size),
                mime_type = VALUES(mime_type), signed_uploaded_by = VALUES(signed_uploaded_by),
                signed_uploaded_at = NOW(), signature_method = VALUES(signature_method),
                signer_name = VALUES(signer_name), signer_nif = VALUES(signer_nif),
                source_pdf_sha256 = VALUES(source_pdf_sha256), signed_pdf_sha256 = VALUES(signed_pdf_sha256)
        ");
        $stmt->bind_param(
            "iiiissisissss",
            $tenant_id, $user_id, $document_id, $user_id,
            $generated['path'], $generated['name'], $generated['size'], $generated['mime'], $user_id,
            $signer_name, $signer_nif, $generated['source_sha256'], $generated['signed_sha256']
        );
        $stmt->execute();
        $patient_document_id = (int) $mysqli->insert_id;
        if ($patient_document_id <= 0) {
            $stmt = $mysqli->prepare("SELECT id FROM patient_legal_documents WHERE tenant_id = ? AND patient_id = ? AND legal_document_id = ? LIMIT 1");
            $stmt->bind_param("iii", $tenant_id, $user_id, $document_id);
            $stmt->execute();
            $patient_document_id = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0);
        }
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $metadata = json_encode(['origin' => 'patient_portal', 'document_title' => $document['title']], JSON_UNESCAPED_UNICODE);
        $stmt = $mysqli->prepare("
            INSERT INTO legal_consent_audit
                (tenant_id, patient_id, legal_document_id, patient_legal_document_id, action, signature_method,
                 signer_name, signer_nif, source_pdf_sha256, signed_pdf_sha256, source_ip, user_agent, performed_by, metadata_json)
            VALUES (?, ?, ?, ?, 'consent_signed', 'handwritten_online', ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iiiissssssis", $tenant_id, $user_id, $document_id, $patient_document_id, $signer_name, $signer_nif, $generated['source_sha256'], $generated['signed_sha256'], $ip, $agent, $user_id, $metadata);
        $stmt->execute();
        $mysqli->commit();
        app_log_write($mysqli, [
            'tenant_id' => $tenant_id, 'user_id' => $user_id, 'action' => 'patient_legal_document_signed_online',
            'entity_type' => 'patient_legal_document', 'entity_id' => $patient_document_id,
            'title' => 'Consentimiento firmado online por el paciente',
            'description' => (string) $document['title']
        ]);
        echo json_encode(['success' => true, 'message' => 'Consentimiento firmado y guardado correctamente.']);
    } catch (Throwable $e) {
        try { $mysqli->rollback(); } catch (Throwable $ignored) {}
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } finally {
        if (!empty($source['temporary']) && !empty($source['path']) && is_file($source['path'])) {
            @unlink($source['path']);
        }
    }
} elseif ($action === 'patient_portal_exercise_gif') {
    if ($is_admin) {
        http_response_code(403);
        exit;
    }
    $task_id = (int) ($_GET['task_id'] ?? 0);
    if ($task_id <= 0 || !workoutx_available()) {
        http_response_code(404);
        exit;
    }
    $exercise = patient_portal_work_plan_exercise($mysqli, $tenant_id, $user_id, $task_id);
    if (!$exercise || ($exercise['external_source'] ?? '') !== 'workoutx' || empty($exercise['external_id'])) {
        http_response_code(404);
        exit;
    }
    $gif = workoutx_fetch_gif($exercise['external_id']);
    if (empty($gif['success'])) {
        http_response_code((int) ($gif['status'] ?? 502) ?: 502);
        exit;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header_remove('Content-Type');
    header('Content-Type: ' . ($gif['content_type'] ?? 'image/gif'));
    header('Cache-Control: private, max-age=86400');
    echo $gif['body'];
    exit;
} elseif ($action === 'patient_portal_exercise_media') {
    if ($is_admin) {
        echo json_encode(['success' => false, 'error' => 'Disponible solo para pacientes.']);
        exit;
    }
    $task_id = (int) ($_GET['task_id'] ?? 0);
    $exercise = $task_id > 0 ? patient_portal_work_plan_exercise($mysqli, $tenant_id, $user_id, $task_id) : null;
    if (!$exercise) {
        echo json_encode(['success' => false, 'error' => 'No se encontro el ejercicio publicado.']);
        exit;
    }

    $local_instructions = [];
    $instructions_json = trim((string) ($exercise['instructions_es'] ?: $exercise['instructions_en'] ?: ''));
    if ($instructions_json !== '') {
        $decoded = json_decode($instructions_json, true);
        if (is_array($decoded)) {
            $local_instructions = array_values(array_filter(array_map('strval', $decoded)));
        } else {
            $local_instructions = array_values(array_filter(array_map('trim', preg_split('/\R+/', $instructions_json))));
        }
    }
    $local_secondary = [];
    $secondary_json = trim((string) ($exercise['secondary_muscles_en'] ?? ''));
    if ($secondary_json !== '') {
        $decoded_secondary = json_decode($secondary_json, true);
        if (is_array($decoded_secondary)) {
            $local_secondary = array_values(array_filter(array_map('strval', $decoded_secondary)));
        }
    }

    $remote = null;
    $headers = [];
    if (!$local_instructions && workoutx_available() && ($exercise['external_source'] ?? '') === 'workoutx' && !empty($exercise['external_id'])) {
        $remote_res = workoutx_exercise_by_id($exercise['external_id']);
        if (!empty($remote_res['success']) && is_array($remote_res['data'] ?? null)) {
            $remote = $remote_res['data'];
            $headers = $remote_res['headers'] ?? [];
        }
    }

    echo json_encode([
        'success' => true,
        'exercise' => [
            'localExerciseId' => $exercise['exercise_id'] ?? '',
            'id' => $exercise['external_id'] ?? '',
            'name' => $remote['name'] ?? ($exercise['name_en'] ?? ''),
            'localNameEs' => $exercise['name_es'] ?? '',
            'descriptionEs' => $exercise['description_es'] ?? '',
            'descriptionEn' => $exercise['description_en'] ?? '',
            'cuesEs' => $exercise['cues_es'] ?? '',
            'bodyPart' => $remote['bodyPart'] ?? ($exercise['workoutx_body_part'] ?? ''),
            'target' => $remote['target'] ?? ($exercise['workoutx_target'] ?? ''),
            'equipment' => $remote['equipment'] ?? ($exercise['workoutx_equipment'] ?? ''),
            'difficulty' => $remote['difficulty'] ?? '',
            'mechanic' => $remote['mechanic'] ?? '',
            'force' => $remote['force'] ?? '',
            'gifUrl' => !empty($exercise['external_id']) ? 'api/appointments.php?action=patient_portal_exercise_gif&task_id=' . $task_id : '',
            'remoteGifUrl' => $remote['gifUrl'] ?? ($exercise['image_url'] ?? ''),
            'instructions' => $local_instructions ?: ($remote['instructions'] ?? []),
            'secondaryMuscles' => $local_secondary ?: ($remote['secondaryMuscles'] ?? []),
            'caloriesPerMinute' => $remote['caloriesPerMinute'] ?? ($exercise['calories_per_min'] ?? null),
            'patientWeightKg' => $exercise['patient_weight_kg'] ?? null
        ],
        'headers' => $headers
    ]);
} elseif ($action === 'patient_portal_summary') {
    if ($is_admin) {
        echo json_encode(['success' => false, 'error' => 'Disponible solo para pacientes.']);
        exit;
    }
    ensure_patient_portal_work_plan_schema($mysqli);
    ensure_patient_portal_documents_schema($mysqli);

    $recording_select = patient_portal_table_exists($mysqli, 'appointment_recordings')
        ? "(SELECT COUNT(*)
             FROM appointment_recordings ar
             INNER JOIN patient_documents rd
               ON rd.id = ar.document_id AND rd.tenant_id = ar.tenant_id AND rd.patient_id = ar.patient_id
             WHERE ar.tenant_id = a.tenant_id AND ar.appointment_id = a.id
               AND ar.status = 'ready' AND rd.visible_to_patient = 1) AS recording_count,
           (SELECT ar.document_id
             FROM appointment_recordings ar
             INNER JOIN patient_documents rd
               ON rd.id = ar.document_id AND rd.tenant_id = ar.tenant_id AND rd.patient_id = ar.patient_id
             WHERE ar.tenant_id = a.tenant_id AND ar.appointment_id = a.id
               AND ar.status = 'ready' AND rd.visible_to_patient = 1
             ORDER BY ar.id DESC
             LIMIT 1) AS recording_document_id"
        : "0 AS recording_count, NULL AS recording_document_id";

    $stmt = $mysqli->prepare("
        SELECT a.id, a.professional_id, a.appointment_date, a.appointment_time, a.status, a.cancelled_at,
               a.consultation_type, a.online_session_url,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               COALESCE(a.payment_status, 'pending') AS payment_status,
               a.payment_method, a.patient_bonus_id,
               {$recording_select},
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
            'livekit_enabled' => livekit_enabled_for_professional($mysqli, (int) ($row['professional_id'] ?? 0)) ? 1 : 0,
            'video_provider' => video_provider_for_professional($mysqli, (int) ($row['professional_id'] ?? 0)),
            'duration_minutes' => (int) ($row['duration_minutes'] ?? 60),
            'payment_status' => $row['payment_status'] ?? 'pending',
            'payment_method' => $row['payment_method'] ?? '',
            'patient_bonus_id' => (int) ($row['patient_bonus_id'] ?? 0),
            'bonus_name' => $row['bonus_name'] ?? '',
            'service_label' => appointment_service_option_label($row),
            'professional_name' => $row['professional_name'] ?? '',
            'recording_count' => (int) ($row['recording_count'] ?? 0),
            'recording_document_id' => (int) ($row['recording_document_id'] ?? 0),
            'recording_url' => !empty($row['recording_document_id']) ? 'api/appointments.php?action=patient_portal_download_document&id=' . (int) $row['recording_document_id'] : ''
        ];
    }
    $stmt = $mysqli->prepare("
        SELECT id, original_name, file_size, uploaded_at
        FROM patient_evolution_files
        WHERE tenant_id = ? AND patient_id = ? AND visible_to_patient = 1
        ORDER BY uploaded_at DESC, id DESC
        LIMIT 80
    ");
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $documents[] = [
            'id' => 'evolution-' . (int) $row['id'],
            'type' => 'file',
            'name' => $row['original_name'] ?: 'Archivo',
            'file_name' => $row['original_name'] ?? '',
            'description' => '',
            'date' => $row['uploaded_at'] ?? '',
            'score' => '',
            'result_label' => '',
            'observations' => '',
            'result_visible_to_patient' => 0,
            'size' => (int) ($row['file_size'] ?? 0),
            'status' => 'completed',
            'url' => 'api/appointments.php?action=patient_portal_download_evolution_file&id=' . (int) $row['id']
        ];
    }
    usort($documents, fn($a, $b) => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));

    $has_fitness_exercises = patient_portal_table_exists($mysqli, 'fitness_exercises');
    $fitness_select = $has_fitness_exercises
        ? "t.fitness_exercise_id, fe.name_es AS fitness_name_es, fe.name_en AS fitness_name_en,
           fe.external_source AS fitness_external_source, fe.external_id AS fitness_external_id,
           fe.image_url AS fitness_image_url"
        : "NULL AS fitness_exercise_id, NULL AS fitness_name_es, NULL AS fitness_name_en,
           NULL AS fitness_external_source, NULL AS fitness_external_id, NULL AS fitness_image_url";
    $fitness_join = $has_fitness_exercises
        ? "LEFT JOIN fitness_exercises fe ON fe.exercise_id = t.fitness_exercise_id AND fe.active = 1"
        : "";

    $stmt = $mysqli->prepare("
        SELECT t.id, t.title, t.description, t.status, t.priority, t.completed_at, t.created_at,
               d.file_path AS attachment_file_path, d.original_file_name AS attachment_original_name,
               d.file_size AS attachment_file_size, d.mime_type AS attachment_mime_type,
               p.display_name AS professional_name,
               {$fitness_select}
        FROM patient_work_plan_tasks t
        LEFT JOIN professionals p ON p.id = t.professional_id AND p.tenant_id = t.tenant_id
        LEFT JOIN patient_documents d ON d.id = t.document_id AND d.tenant_id = t.tenant_id
            AND d.patient_id = t.patient_id AND d.visible_to_patient = 1
        {$fitness_join}
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
            'professional_name' => $row['professional_name'] ?? '',
            'attachment_file_path' => $row['attachment_file_path'] ?? '',
            'attachment_original_name' => $row['attachment_original_name'] ?? '',
            'attachment_file_size' => (int) ($row['attachment_file_size'] ?? 0),
            'attachment_mime_type' => $row['attachment_mime_type'] ?? '',
            'fitness_exercise_id' => $row['fitness_exercise_id'] ?? '',
            'fitness_exercise' => !empty($row['fitness_exercise_id']) ? [
                'exercise_id' => $row['fitness_exercise_id'] ?? '',
                'name_es' => $row['fitness_name_es'] ?? '',
                'name_en' => $row['fitness_name_en'] ?? '',
                'external_source' => $row['fitness_external_source'] ?? '',
                'external_id' => $row['fitness_external_id'] ?? '',
                'image_url' => $row['fitness_image_url'] ?? ''
            ] : null
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

    $reports = [];
    if (patient_portal_table_exists($mysqli, 'patient_reports')) {
        $stmt = $mysqli->prepare("
            SELECT r.id, r.report_key, r.title, r.status, r.payment_mode, r.payment_status, r.price,
                   r.generated_at, r.updated_at,
                   r.source_type,
                   COALESCE(r.official_document_id, r.final_document_id, r.source_document_id) AS document_id,
                   d.original_file_name AS final_document_name,
                   d.file_size AS final_document_size
            FROM patient_reports r
            LEFT JOIN patient_documents d ON d.id = COALESCE(r.official_document_id, r.final_document_id)
                AND d.tenant_id = r.tenant_id
                AND d.patient_id = r.patient_id
            WHERE r.tenant_id = ?
              AND r.patient_id = ?
              AND r.portal_available = 1
            ORDER BY COALESCE(r.generated_at, r.created_at) DESC, r.id DESC
            LIMIT 50
        ");
        $stmt->bind_param("ii", $tenant_id, $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $document_id = (int) ($row['document_id'] ?? 0);
            $reports[] = [
                'id' => (int) $row['id'],
                'report_key' => $row['report_key'] ?? '',
                'source_type' => $row['source_type'] ?? 'app_generated',
                'title' => $row['title'] ?? 'Informe',
                'status' => $row['status'] ?? '',
                'payment_mode' => $row['payment_mode'] ?? 'free',
                'payment_status' => $row['payment_status'] ?? 'not_required',
                'price' => $row['price'] !== null ? (float) $row['price'] : null,
                'generated_at' => $row['generated_at'] ?: ($row['updated_at'] ?? ''),
                'final_document_name' => $row['final_document_name'] ?? '',
                'final_document_size' => (int) ($row['final_document_size'] ?? 0),
                'url' => $document_id > 0 ? 'api/appointments.php?action=patient_portal_download_document&id=' . $document_id : ''
            ];
        }
    }

    $composition = ['enabled' => false, 'current' => null, 'history' => []];
    if (patient_portal_physical_metrics_enabled($mysqli) && patient_portal_table_exists($mysqli, 'patient_profiles')) {
        $composition['enabled'] = true;
        $stmt = $mysqli->prepare("
            SELECT updated_at, physical_sex, weight_kg, height_cm, body_fat_percentage,
                   waist_cm, hip_cm, chest_cm, thigh_cm, biceps_cm, calf_cm,
                   skinfold_triceps_mm, skinfold_subscapular_mm, skinfold_suprailiac_mm,
                   skinfold_abdominal_mm, skinfold_chest_mm, skinfold_thigh_mm
            FROM patient_profiles
            WHERE tenant_id = ? AND user_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $tenant_id, $user_id);
        $stmt->execute();
        $profile = $stmt->get_result()->fetch_assoc();
        if ($profile) {
            $composition['current'] = patient_portal_physical_payload($profile);
        }

        if (patient_portal_table_exists($mysqli, 'patient_evolution_notes')) {
            $stmt = $mysqli->prepare("
                SELECT note_date, weight_kg, height_cm, body_fat_percentage,
                       waist_cm, hip_cm, chest_cm, thigh_cm, biceps_cm, calf_cm,
                       skinfold_triceps_mm, skinfold_subscapular_mm, skinfold_suprailiac_mm,
                       skinfold_abdominal_mm, skinfold_chest_mm, skinfold_thigh_mm
                FROM patient_evolution_notes
                WHERE tenant_id = ?
                  AND patient_id = ?
                  AND (
                    weight_kg IS NOT NULL OR height_cm IS NOT NULL OR body_fat_percentage IS NOT NULL OR
                    waist_cm IS NOT NULL OR hip_cm IS NOT NULL OR chest_cm IS NOT NULL OR thigh_cm IS NOT NULL OR
                    biceps_cm IS NOT NULL OR calf_cm IS NOT NULL OR skinfold_triceps_mm IS NOT NULL OR
                    skinfold_subscapular_mm IS NOT NULL OR skinfold_suprailiac_mm IS NOT NULL OR
                    skinfold_abdominal_mm IS NOT NULL OR skinfold_chest_mm IS NOT NULL OR skinfold_thigh_mm IS NOT NULL
                  )
                ORDER BY note_date DESC, id DESC
                LIMIT 80
            ");
            $stmt->bind_param("ii", $tenant_id, $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $composition['history'][] = patient_portal_physical_payload($row);
            }
        }
    }

    $pending_consents = [];
    $stmt = $mysqli->prepare("
        SELECT ld.id, ld.title, ld.category, ld.version_label
        FROM legal_documents ld
        LEFT JOIN patient_legal_documents pld
          ON pld.tenant_id = ld.tenant_id AND pld.patient_id = ? AND pld.legal_document_id = ld.id
        WHERE ld.tenant_id = ? AND ld.is_active = 1
          AND (
            ld.is_required = 1 OR EXISTS (
              SELECT 1 FROM service_legal_documents sld
              INNER JOIN appointment_service_options aso
                ON aso.tenant_id = sld.tenant_id AND aso.service_id = sld.service_id
              INNER JOIN appointments a
                ON a.tenant_id = aso.tenant_id AND a.service_option_id = aso.id
              WHERE sld.tenant_id = ld.tenant_id AND sld.legal_document_id = ld.id
                AND a.user_id = ? AND a.status <> 'cancelled'
            )
          )
          AND COALESCE(pld.accepted, 0) = 0
        ORDER BY ld.title, ld.id
    ");
    $stmt->bind_param("iii", $user_id, $tenant_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $pending_consents[] = [
            'legal_document_id' => (int) $row['id'],
            'title' => $row['title'] ?? 'Consentimiento',
            'category' => $row['category'] ?? '',
            'version_label' => $row['version_label'] ?? '',
            'template_url' => 'api/appointments.php?action=patient_portal_legal_template&id=' . (int) $row['id']
        ];
    }
    $patient_portal_signer = legal_consent_expected_signer($mysqli, $tenant_id, $user_id);
    $patient_portal_name = (string) ($patient_portal_signer['patient_name'] ?? '');
    $patient_portal_nif = (string) ($patient_portal_signer['patient_nif'] ?? '');

    $payment_settings = load_booking_payment_settings($mysqli);
    echo json_encode([
        'success' => true,
        'appointments' => $appointments,
        'tasks' => $tasks,
        'documents' => $documents,
        'reports' => $reports,
        'pending_consents' => $pending_consents,
        'patient_name' => $patient_portal_name,
        'patient_nif' => $patient_portal_nif,
        'consent_signer_name' => (string) ($patient_portal_signer['name'] ?? ''),
        'consent_signer_nif' => (string) ($patient_portal_signer['nif'] ?? ''),
        'consent_signer_is_minor' => !empty($patient_portal_signer['is_minor']) ? 1 : 0,
        'composition' => $composition,
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
            SELECT a.id, a.appointment_date, a.appointment_time, a.user_id, a.status, a.patient_confirmed_at,
                   COALESCE(a.payment_status, 'pending') AS payment_status,
                   a.payment_method, a.paid_at, a.patient_bonus_id, a.consultation_type, a.service_type,
                   COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
                   a.base_price_amount, a.discount_percentage, a.final_price_amount,
                   so.price AS service_price, s.name AS service_name, s.service_key,
                   u.name, u.email, u.phone
            FROM appointments a
            LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
            LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
            JOIN users u ON a.user_id = u.id AND u.tenant_id = a.tenant_id
            WHERE a.tenant_id = ? AND a.appointment_date BETWEEN ? AND ? AND a.status IN ('booked', 'completed', 'no_show')
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
            'status' => $app['status'] ?? 'booked',
            'patient_confirmed_at' => $app['patient_confirmed_at'] ?? null,
            'name' => $app['name'],
            'email' => $app['email'] ?? 'Sin email',
            'phone' => appointments_patient_phone($app['phone'] ?? ''),
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
            'price' => number_format(appointment_price_for_row([], $app), 2, '.', ''),
            'base_price' => $app['base_price_amount'] !== null ? number_format((float) $app['base_price_amount'], 2, '.', '') : null,
            'discount_percentage' => number_format((float) ($app['discount_percentage'] ?? 0), 2, '.', ''),
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
        'create_compensation_bonus_on_paid_cancel' => 1,
        'work_plan_task_status_enabled' => 1
    ];
    if (function_exists('app_auto_schema_migrations_enabled') && app_auto_schema_migrations_enabled()) {
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
    }
    $settings_res = $mysqli->query("
        SELECT online_payment_enabled, appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price, available_session_types, available_session_durations, display_effective_duration_enabled, display_duration_offset_minutes,
               discount_period_enabled, discount_period_start_date, discount_period_end_date, discount_show_public,
               min_booking_notice_days, max_booking_notice_days,
               appointment_start_time, appointment_end_time, break_start_time, break_end_time,
               available_weekdays, appointment_delivery_mode, bonuses_enabled, create_compensation_bonus_on_paid_cancel, work_plan_task_status_enabled
        FROM payment_settings
        WHERE tenant_id = $tenant_id
    ");
    if ($settings_res && ($settings_row = $settings_res->fetch_assoc())) {
        $payment_settings = $settings_row;
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
            SELECT a.id, a.appointment_date, a.appointment_time, a.user_id, a.status, a.patient_confirmed_at,
                   COALESCE(a.payment_status, 'pending') AS payment_status,
                   a.payment_method, a.paid_at, a.patient_bonus_id, a.consultation_type, a.service_type,
                   COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
                   a.base_price_amount, a.discount_percentage, a.final_price_amount,
                   so.price AS service_price, s.name AS service_name, s.service_key,
                   u.name, u.email, u.phone
            FROM appointments a
            LEFT JOIN appointment_service_options so ON so.id = a.service_option_id AND so.tenant_id = a.tenant_id
            LEFT JOIN appointment_services s ON s.id = so.service_id AND s.tenant_id = a.tenant_id
            JOIN users u ON a.user_id = u.id AND u.tenant_id = a.tenant_id
            WHERE a.tenant_id = ? AND a.appointment_date BETWEEN ? AND ? AND a.status IN ('booked', 'completed', 'no_show')
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
            'status' => $app['status'] ?? 'booked',
            'patient_confirmed_at' => $app['patient_confirmed_at'] ?? null,
            'name' => $app['name'],
            'email' => $app['email'] ?? 'Sin email',
            'phone' => appointments_patient_phone($app['phone'] ?? ''),
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
            'price' => number_format(appointment_price_for_row([], $app), 2, '.', ''),
            'base_price' => $app['base_price_amount'] !== null ? number_format((float) $app['base_price_amount'], 2, '.', '') : null,
            'discount_percentage' => number_format((float) ($app['discount_percentage'] ?? 0), 2, '.', ''),
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
        'create_compensation_bonus_on_paid_cancel' => 1,
        'work_plan_task_status_enabled' => 1
    ];
    if (function_exists('app_auto_schema_migrations_enabled') && app_auto_schema_migrations_enabled()) {
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
    }
    $settings_res = $mysqli->query("
        SELECT online_payment_enabled, appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price, available_session_types, available_session_durations, display_effective_duration_enabled, display_duration_offset_minutes,
               discount_period_enabled, discount_period_start_date, discount_period_end_date, discount_show_public,
               min_booking_notice_days, max_booking_notice_days,
               appointment_start_time, appointment_end_time, break_start_time, break_end_time,
               available_weekdays, appointment_delivery_mode, bonuses_enabled, create_compensation_bonus_on_paid_cancel, work_plan_task_status_enabled
        FROM payment_settings
        WHERE tenant_id = $tenant_id
    ");
    if ($settings_res && ($settings_row = $settings_res->fetch_assoc())) {
        $payment_settings = $settings_row;
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
        plan_usage_assert_appointment_capacity($mysqli, $date);
    } catch (\RuntimeException $exception) {
        echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
        exit;
    }

    try {
        $professional_id = $booking_professional_id;
        $professional = cabinet_fetch_professional($mysqli, $professional_id);
        $location_id = null;
        $default_appointment_location = '';
        if ($consultation_type === 'presencial') {
            $location_id = !empty($settings['default_location_id']) ? (int) $settings['default_location_id'] : default_appointment_location_id($mysqli);
            $location = fetch_appointment_location($mysqli, $location_id);
            if (!$location || (int) ($location['is_enabled'] ?? 0) !== 1) {
                $location_id = default_appointment_location_id($mysqli);
                $location = fetch_appointment_location($mysqli, $location_id);
            }
            $default_appointment_location = appointment_location_display_name($location);
        }
        $price_snapshot = appointment_discount_price_snapshot($settings, $service_option, $date);
        $stmt = $mysqli->prepare("INSERT INTO appointments (tenant_id, user_id, professional_id, appointment_date, appointment_time, consultation_type, service_type, service_option_id, duration_minutes, base_price_amount, discount_percentage, final_price_amount, location_id, online_session_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'booked')");
        $nullable_service_option_id = $service_option ? $service_option_id : null;
        $stmt->bind_param("iiissssiidddis", $tenant_id, $target_user_id, $professional_id, $date, $time, $consultation_type, $service_type, $nullable_service_option_id, $duration_minutes, $price_snapshot['base'], $price_snapshot['discount_percentage'], $price_snapshot['final'], $location_id, $default_appointment_location);
        $stmt->execute();
        $appointment_id = $mysqli->insert_id;
        $livekit_access_token = '';
        if ($consultation_type === 'online' && livekit_enabled_for_professional($mysqli, $professional_id)) {
            $livekit_access_token = livekit_ensure_appointment_link_token($mysqli, $appointment_id);
        }
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
        if (function_exists('app_auto_schema_migrations_enabled') && app_auto_schema_migrations_enabled()) {
            ensure_payment_settings_price_columns($mysqli);
            $calendar_link_column = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'send_patient_calendar_link'");
            if ($calendar_link_column && $calendar_link_column->num_rows === 0) {
                $mysqli->query("ALTER TABLE payment_settings ADD send_patient_calendar_link TINYINT(1) NOT NULL DEFAULT 1");
            }
            ensure_session_setting_column($mysqli);
        }
        $settings_res = $mysqli->query("SELECT online_payment_enabled, appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price, send_patient_calendar_link, display_effective_duration_enabled, display_duration_offset_minutes FROM payment_settings WHERE tenant_id = $tenant_id");
        if ($settings_res) {
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
                $appointment_price_text = format_appointment_price($price_snapshot['final']);
            }
        }
        $display_duration_note = ($price_settings && (int) ($price_settings['display_effective_duration_enabled'] ?? 0) === 1)
            ? '<p><b>Duraci&oacute;n:</b> ' . (int) appointment_display_duration_minutes($duration_minutes, $price_settings) . ' minutos</p>'
            : '';
        $display_service_text = appointment_display_service_label($service_text, $duration_minutes, $price_settings ?: []);
        $branding_settings = get_public_branding_settings($mysqli);
        $center_address = trim((string) ($branding_settings['legal_address'] ?? ''));
        $patient_location_line = appointment_location_email_html([
            'consultation_type' => $consultation_type,
            'online_session_url' => $default_appointment_location
        ], $center_address, true);
        $livekit_patient_link = $consultation_type === 'online' && livekit_enabled_for_professional($mysqli, $professional_id)
            ? livekit_patient_join_url([
                'id' => $appointment_id,
                'user_id' => $target_user_id,
                'appointment_date' => $date,
                'appointment_time' => $time,
                'duration_minutes' => $duration_minutes,
                'livekit_access_token' => $livekit_access_token
            ])
            : '';
        $online_call_line = $livekit_patient_link !== ''
            ? '<p><a href="' . htmlspecialchars($livekit_patient_link) . '">Acceder a la videollamada</a></p>'
            : '';

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
            try {
                microsoft_create_calendar_event($mysqli, $appointment_id);
            } catch (\Exception $e) {
                error_log('No se pudo crear evento en Microsoft Outlook Calendar: ' . $e->getMessage());
            }
        }

        app_log($mysqli, [
            'action' => 'appointment_created',
            'status' => 'ok',
            'target_type' => 'appointment',
            'target_id' => (int) $appointment_id,
            'title' => 'Cita reservada',
            'message' => 'Cita reservada para ' . ($patient['name'] ?? '') . ' el ' . $appointment_text . '.',
            'metadata' => [
                'origin' => $is_admin ? 'staff' : 'patient_portal',
                'patient_id' => (int) $target_user_id,
                'patient_name' => $patient['name'] ?? '',
                'professional_id' => (int) $professional_id,
                'professional_name' => $professional['display_name'] ?? '',
                'appointment_date' => $date,
                'appointment_time' => $time,
                'consultation_type' => $consultation_type,
                'service' => $service_text,
                'duration_minutes' => (int) $duration_minutes,
                'location_id' => $location_id ? (int) $location_id : 0,
                'bonus_applied' => $bonus_claim ? 1 : 0,
                'livekit_prepared' => $livekit_access_token !== '' ? 1 : 0
            ]
        ]);

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
            true,
            'new_appointments'
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
                ($patient_location_line ? '<p>' . preg_replace('/^<br>/', '', $patient_location_line) . '</p>' : '') .
                $online_call_line .
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
            'price' => number_format((float) $price_snapshot['final'], 2, '.', ''),
            'base_price' => number_format((float) $price_snapshot['base'], 2, '.', ''),
            'discount_percentage' => number_format((float) $price_snapshot['discount_percentage'], 2, '.', ''),
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
        SELECT a.id, a.appointment_date, a.appointment_time, a.consultation_type, a.service_type, a.online_session_url,
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
        try {
            microsoft_delete_calendar_event($mysqli, (int) $appointment_to_cancel['id']);
        } catch (\Exception $e) {
            error_log('No se pudo eliminar evento en Microsoft Outlook Calendar: ' . $e->getMessage());
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
        app_log($mysqli, [
            'action' => 'appointment_cancelled',
            'status' => 'ok',
            'target_type' => 'appointment',
            'target_id' => (int) $appointment_to_cancel['id'],
            'title' => 'Cita cancelada',
            'message' => 'Cita cancelada para ' . ($appointment_to_cancel['name'] ?? '') . ' el ' . appointment_label($appointment_to_cancel['appointment_date'], $appointment_to_cancel['appointment_time']) . '.',
            'metadata' => [
                'origin' => $is_admin ? 'staff' : 'patient_portal',
                'patient_id' => (int) ($appointment_to_cancel['user_id'] ?? 0),
                'patient_name' => $appointment_to_cancel['name'] ?? '',
                'professional_id' => (int) ($appointment_to_cancel['professional_id'] ?? 0),
                'appointment_date' => $appointment_to_cancel['appointment_date'] ?? '',
                'appointment_time' => $appointment_to_cancel['appointment_time'] ?? '',
                'payment_status' => $appointment_to_cancel['payment_status'] ?? '',
                'payment_method' => $appointment_to_cancel['payment_method'] ?? '',
                'bonus_session_restored' => (int) ($appointment_to_cancel['bonus_session_restored'] ?? 0),
                'compensation_bonus_created' => (int) ($appointment_to_cancel['compensation_bonus_created'] ?? 0)
            ]
        ]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No se pudo cancelar o no tienes permiso']);
    }
}
