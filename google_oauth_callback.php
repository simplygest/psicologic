<?php
session_start();
require_once 'db.php';
require_once 'google_helpers.php';
require_once 'settings_helpers.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'], true)) {
    header('Location: login.php');
    exit;
}

$ok = false;
$message = '';
$branding = get_public_branding_settings($mysqli);

try {
    if (empty($_GET['state']) || empty($_SESSION['google_oauth_state']) || $_GET['state'] !== $_SESSION['google_oauth_state']) {
        throw new \Exception('Estado OAuth inválido');
    }

    if (!empty($_GET['error'])) {
        throw new \Exception('Google devolvió error: ' . $_GET['error']);
    }

    if (empty($_GET['code'])) {
        throw new \Exception('Google no devolvió código de autorización');
    }

    google_exchange_code($mysqli, $_GET['code']);
    unset($_SESSION['google_oauth_state']);
    $ok = true;
    $message = 'Google se ha conectado correctamente.';
} catch (\Exception $e) {
    $message = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Conexión Google - Psicología Minimal</title>
    <?= favicon_link_tags($branding) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>:root { --primary-color: <?= htmlspecialchars($branding['primary_color']) ?>; }</style>
</head>

<body class="d-flex align-items-center justify-content-center" style="min-height: 100vh;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card p-4 text-center">
                    <h2 class="<?= $ok ? 'text-success' : 'text-danger' ?> mb-3">
                        <?= $ok ? 'Conexión completada' : 'No se pudo conectar' ?>
                    </h2>
                    <p><?= htmlspecialchars($message) ?></p>
                    <div class="mt-4">
                        <a href="dashboard.php" class="btn btn-primary">Volver al panel</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
