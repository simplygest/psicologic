<?php
session_start();
require_once 'db.php';
require_once 'google_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

try {
    header('Location: ' . google_build_auth_url($mysqli));
    exit;
} catch (\Exception $e) {
    echo 'No se pudo iniciar la conexión con Google: ' . htmlspecialchars($e->getMessage());
}
