<?php
session_start();
require_once 'db.php';
require_once 'google_helpers.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'superadmin', 'reception', 'administration', 'technical'], true)) {
    header('Location: login.php');
    exit;
}

try {
    $_SESSION['google_oauth_return_url'] = google_tenant_dashboard_url();
    header('Location: ' . google_build_auth_url($mysqli));
    exit;
} catch (\Exception $e) {
    echo 'No se pudo iniciar la conexión con Google: ' . htmlspecialchars($e->getMessage());
}
