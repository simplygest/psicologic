<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/dashboard_config_helpers.php';

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['admin', 'superadmin', 'reception', 'administration', 'technical'], true)) {
    http_response_code(403);
    exit;
}
if (!plan_feature_enabled_from_db($mysqli, 'documents.drawingBoard', false)) {
    http_response_code(403);
    exit;
}

$name = basename((string) ($_GET['file'] ?? ''));
if (!preg_match('/^[a-zA-Z0-9._-]+\.excalidrawlib$/', $name)) {
    http_response_code(400);
    exit;
}
$base = realpath(__DIR__ . '/uploads/global/excalidraw');
$path = $base ? realpath($base . DIRECTORY_SEPARATOR . $name) : false;
if (!$base || !$path || dirname($path) !== $base || !is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
