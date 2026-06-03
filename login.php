<?php
session_start();
require_once 'db.php';
require_once 'settings_helpers.php';
if (isset($_SESSION['user_id'])) {
    if (($_SESSION['role'] ?? '') !== 'admin' && !online_booking_enabled($mysqli)) {
        header('Location: index.php');
        exit;
    }
    header('Location: dashboard.php');
    exit;
}
$branding = get_public_branding_settings($mysqli);
$app_name = $branding['app_name'];
$profile_image_path = $branding['show_profile_image_public'] ? $branding['profile_image_path'] : '';
$is_admin_login = isset($_GET['admin']);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Iniciar Sesion - <?= htmlspecialchars($app_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
    <style>:root { --primary-color: <?= htmlspecialchars($branding['primary_color']) ?>; }</style>
</head>

<body class="d-flex align-items-center justify-content-center" style="min-height: 100vh;">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card p-4">
                    <div class="text-center mb-4">
                        <?php if ($profile_image_path): ?>
                            <img src="<?= htmlspecialchars($profile_image_path) ?>" alt="" class="auth-brand-image mb-3">
                        <?php endif; ?>
                        <h2 style="color: var(--primary-color);"><?= htmlspecialchars($app_name) ?></h2>
                        <p class="text-muted"><?= $is_admin_login ? 'Acceso privado de administración.' : 'Inicia sesion para gestionar tus citas.' ?></p>
                    </div>

                    <div id="login-alert" class="alert d-none"></div>

                    <form id="login-form">
                        <div class="mb-3">
                            <label for="login-id" class="form-label">Email o Telefono</label>
                            <input type="text" class="form-control" id="login-id" name="login_id" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Contrasena</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 mt-3">Entrar</button>
                    </form>

                    <div class="text-center mt-3">
                        <a href="forgot_password.php" class="text-decoration-none" style="color: var(--primary-color);">He olvidado mi contrase&ntilde;a</a>
                    </div>

                    <div class="text-center mt-3">
                        <a href="index.php" class="text-decoration-none" style="color: var(--primary-color);">Volver a la pagina principal</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function () {
            $('#login-form').on('submit', function (e) {
                e.preventDefault();
                $('#login-alert').addClass('d-none');

                $.ajax({
                    url: 'api/auth.php?action=login',
                    method: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function (res) {
                        if (res.success) {
                            window.location.href = 'dashboard.php';
                        } else {
                            $('#login-alert').removeClass('d-none alert-success').addClass('alert-danger').text(res.error);
                        }
                    },
                    error: function () {
                        $('#login-alert').removeClass('d-none alert-success').addClass('alert-danger').text("Error de conexion");
                    }
                });
            });
        });
    </script>
</body>

</html>
