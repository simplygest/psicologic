<?php
session_start();
require_once __DIR__ . '/app_paths.php';

$relative_path = ltrim(str_replace('\\', '/', (string) ($_GET['p'] ?? '')), '/');
if ($relative_path === '' || strpos($relative_path, '..') !== false) {
    http_response_code(400);
    exit('Archivo no valido');
}

$parts = explode('/', $relative_path);
$extension = strtolower(pathinfo($relative_path, PATHINFO_EXTENSION));
$mime_types = [
    'ico' => 'image/x-icon',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif'
];
if (!isset($mime_types[$extension])) {
    http_response_code(403);
    exit('Tipo de archivo no permitido');
}

function serve_asset_file($relative_path, $mime_type)
{
    $local_path = app_public_path($relative_path);
    if ($local_path === '' || !is_file($local_path)) {
        http_response_code(404);
        exit('Archivo no encontrado');
    }

    $etag = '"' . md5($relative_path . '|' . filemtime($local_path) . '|' . filesize($local_path)) . '"';
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . filesize($local_path));
    header('Cache-Control: private, max-age=86400');
    header('ETag: ' . $etag);
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }

    readfile($local_path);
    exit;
}

if (($parts[0] ?? '') === app_public_uploads_base_relative() && ($parts[1] ?? '') === 'global') {
    serve_asset_file($relative_path, $mime_types[$extension]);
}

require_once __DIR__ . '/db.php';

$tenant_storage_key = app_current_tenant_storage_key();
if (($parts[0] ?? '') !== app_public_uploads_base_relative() || ($parts[1] ?? '') !== $tenant_storage_key) {
    http_response_code(404);
    exit('Archivo no encontrado');
}

$category = $parts[2] ?? '';
$public_categories = ['settings', 'professionals'];
$session_role = $_SESSION['role'] ?? '';
$session_user_id = (int) ($_SESSION['user_id'] ?? 0);
$session_tenant_id = (int) ($_SESSION['tenant_id'] ?? current_tenant_id());

if (!in_array($category, $public_categories, true)) {
    if ($session_user_id <= 0 || $session_tenant_id !== current_tenant_id()) {
        http_response_code(403);
        exit('No autorizado');
    }

    if ($category === 'patients') {
if (!in_array($session_role, ['admin', 'superadmin', 'reception', 'administration', 'technical'], true)) {
            $stmt = $mysqli->prepare("SELECT photo_path FROM patient_profiles WHERE tenant_id = ? AND user_id = ? LIMIT 1");
            $tenant_id = current_tenant_id();
            $stmt->bind_param("ii", $tenant_id, $session_user_id);
            $stmt->execute();
            $profile = $stmt->get_result()->fetch_assoc();
            if (!$profile || ($profile['photo_path'] ?? '') !== $relative_path) {
                http_response_code(403);
                exit('No autorizado');
            }
        }
    } else {
        http_response_code(403);
        exit('No autorizado');
    }
}

serve_asset_file($relative_path, $mime_types[$extension]);
