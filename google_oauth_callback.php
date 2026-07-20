<?php
session_start();
require_once 'db.php';
require_once 'google_helpers.php';
require_once 'settings_helpers.php';

$oauth_state = (string) ($_GET['state'] ?? '');
$signed_state_tenant_key = function_exists('tenant_key_from_signed_state') ? tenant_key_from_signed_state($oauth_state) : '';
$has_authenticated_oauth_user = isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['admin', 'superadmin', 'reception', 'administration', 'technical'], true);
if (!$has_authenticated_oauth_user && $signed_state_tenant_key === '') {
    header('Location: login.php');
    exit;
}

$ok = false;
$message = '';
$branding = get_public_branding_settings($mysqli);
$return_url = $_SESSION['google_oauth_return_url'] ?? google_tenant_dashboard_url();

function google_oauth_return_to_dashboard($return_url, $status, $message = '')
{
    $_SESSION['google_oauth_flash'] = [
        'status' => $status,
        'message' => $message
    ];
    header('Location: ' . $return_url);
    exit;
}

try {
    $session_state = (string) ($_SESSION['google_oauth_state'] ?? '');
    $state_from_session = $session_state !== '' && hash_equals($session_state, $oauth_state);
    $state_from_signature = $signed_state_tenant_key !== '' && $signed_state_tenant_key === current_tenant_key();
    if ($oauth_state === '' || (!$state_from_session && !$state_from_signature)) {
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
    unset($_SESSION['google_oauth_return_url']);
    $ok = true;
    $message = 'Google se ha conectado correctamente.';
    google_oauth_return_to_dashboard($return_url, 'success', $message);
} catch (\Exception $e) {
    $message = $e->getMessage();
    google_oauth_return_to_dashboard($return_url, 'error', $message);
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
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
                        <a href="<?= htmlspecialchars($return_url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">Volver al panel</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
