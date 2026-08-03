<?php
session_start();
require_once 'db.php';
require_once 'microsoft_helpers.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'], true)) {
    header('Location: login.php');
    exit;
}
try {
    $_SESSION['microsoft_oauth_return_url'] = google_tenant_dashboard_url();
    header('Location: ' . microsoft_build_auth_url());
    exit;
} catch (Throwable $e) {
    $_SESSION['microsoft_oauth_flash'] = ['status' => 'error', 'message' => $e->getMessage()];
    header('Location: ' . google_tenant_dashboard_url());
}
