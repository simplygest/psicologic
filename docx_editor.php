<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cabinet_helpers.php';
require_once __DIR__ . '/app_paths.php';
require_once __DIR__ . '/dashboard_config_helpers.php';

header('X-Robots-Tag: noindex, nofollow', true);

$role = $_SESSION['role'] ?? '';
$user_id = (int) ($_SESSION['user_id'] ?? 0);
if ($user_id <= 0 || !in_array($role, ['admin', 'superadmin', 'reception', 'administration', 'technical'], true)) {
    http_response_code(403);
    echo 'No autorizado';
    exit;
}

if (!plan_feature_enabled_from_db($mysqli, 'documents.onlineEditor', false)) {
    http_response_code(403);
    echo 'El editor online de documentos solo esta disponible en el plan Summum.';
    exit;
}

$tenant_id = current_tenant_id();
$is_superadmin = $role === 'superadmin';
$permissions = cabinet_member_permissions_for_user($mysqli, $user_id, $role);
if (!$is_superadmin && empty($permissions['patients'])) {
    http_response_code(403);
    echo 'No tienes permiso para acceder a documentos de pacientes.';
    exit;
}

function docx_editor_current_professional_id($mysqli, $user_id)
{
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("SELECT id FROM professionals WHERE tenant_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['id'] : 0;
}

function docx_editor_can_access_patient($mysqli, $patient_id)
{
    global $is_superadmin, $user_id;
    $tenant_id = current_tenant_id();
    $patient_id = (int) $patient_id;
    if ($patient_id <= 0) {
        return false;
    }
    if ($is_superadmin) {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE tenant_id = ? AND id = ? AND role = 'patient' LIMIT 1");
        $stmt->bind_param("ii", $tenant_id, $patient_id);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_assoc();
    }
    $professional_id = docx_editor_current_professional_id($mysqli, $user_id);
    if ($professional_id <= 0) {
        return false;
    }
    $stmt = $mysqli->prepare("
        SELECT u.id
        FROM users u
        LEFT JOIN patient_professionals ppf ON ppf.patient_id = u.id AND ppf.tenant_id = u.tenant_id AND ppf.is_primary = 1
        LEFT JOIN patient_profiles pp ON pp.user_id = u.id AND pp.tenant_id = u.tenant_id
        WHERE u.tenant_id = ?
          AND u.id = ?
          AND u.role = 'patient'
          AND COALESCE(ppf.professional_id, pp.professional_id) = ?
        LIMIT 1
    ");
    $stmt->bind_param("iii", $tenant_id, $patient_id, $professional_id);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

$document_id = (int) ($_GET['document_id'] ?? 0);
$patient_id = (int) ($_GET['patient_id'] ?? 0);
$appointment_id = (int) ($_GET['appointment_id'] ?? 0);
$title = trim((string) ($_GET['title'] ?? ''));
$file_name = '';
$load_url = '';

if ($document_id > 0) {
    $stmt = $mysqli->prepare("
        SELECT id, patient_id, appointment_id, title, original_file_name, mime_type
        FROM patient_documents
        WHERE tenant_id = ? AND id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $document_id);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc();
    if (!$document || !docx_editor_can_access_patient($mysqli, (int) $document['patient_id'])) {
        http_response_code(403);
        echo 'No autorizado';
        exit;
    }
    $mime = strtolower((string) ($document['mime_type'] ?? ''));
    $original = strtolower((string) ($document['original_file_name'] ?? ''));
    if ($mime !== 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' && !preg_match('/\.docx$/i', $original)) {
        http_response_code(400);
        echo 'Este documento no es editable.';
        exit;
    }
    $patient_id = (int) $document['patient_id'];
    $appointment_id = (int) ($document['appointment_id'] ?? $appointment_id);
    $title = $title !== '' ? $title : (string) ($document['title'] ?? 'Documento');
    $file_name = (string) ($document['original_file_name'] ?? '');
    $load_url = 'api/admin.php?action=load_docx_patient_document&document_id=' . $document_id;
}

if (!docx_editor_can_access_patient($mysqli, $patient_id)) {
    http_response_code(403);
    echo 'No autorizado';
    exit;
}

if ($title === '') {
    $title = 'Documento';
}

$config = [
    'mode' => $document_id > 0 ? 'edit' : 'create',
    'embedded' => true,
    'documentId' => $document_id,
    'patientId' => $patient_id,
    'appointmentId' => $appointment_id,
    'title' => $title,
    'fileName' => $file_name,
    'loadUrl' => $load_url,
    'saveUrl' => 'api/admin.php?action=save_docx_patient_document',
    'primaryColor' => '#8e79bf',
    'autosaveMs' => 30000
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Editor DOCX - SimplyGest Praxis</title>
    <script>
        window.SG_DOCX_EDITOR_CONFIG = <?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <link rel="stylesheet" href="/js/docx-editor-test/main.css?v=202607222">
</head>
<body>
    <div id="docx-editor-test-root"></div>
    <script type="module" src="/js/docx-editor-test/main.js?v=202607222"></script>
</body>
</html>
