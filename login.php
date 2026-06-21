<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
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
$official_brand_logo_url = app_official_brand_logo_url();
$tenant_brand_logo_url = trim((string) ($branding['profile_image_path'] ?? '')) !== ''
    ? app_upload_asset_url($branding['profile_image_path'])
    : $official_brand_logo_url;
$is_admin_login = isset($_GET['admin']);
$open_patient_registration = ($branding['patient_registration_mode'] ?? 'invite') === 'open';
$remembered_login_id = $_COOKIE['psicologic_login_id'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Iniciar Sesión - <?= htmlspecialchars($app_name) ?></title>
    <?= favicon_link_tags($branding) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
    <style>:root { --primary-color: <?= htmlspecialchars($branding['primary_color']) ?>; }</style>
</head>

<body class="auth-page d-flex align-items-center justify-content-center flex-column" style="min-height: 100vh;">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card p-4">
                    <div class="text-center mb-4">
                        <img src="<?= htmlspecialchars($tenant_brand_logo_url) ?>" alt="<?= htmlspecialchars($app_name) ?>" class="auth-brand-image mb-3">
                        <h2 style="color: var(--primary-color);"><?= htmlspecialchars($app_name) ?></h2>
                        <p class="text-muted"><?= $is_admin_login ? 'Acceso privado de administración.' : 'Inicia sesión para gestionar tus citas.' ?></p>
                    </div>

                    <div id="login-alert" class="alert d-none"></div>

                    <form id="login-form" autocomplete="on">
                        <div class="mb-3">
                            <label for="login-id" class="form-label">Email o teléfono</label>
                            <input type="text" class="form-control" id="login-id" name="login_id" value="<?= htmlspecialchars($remembered_login_id) ?>" autocomplete="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Contraseña</label>
                            <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                        </div>
                        <div class="form-check text-start">
                            <input class="form-check-input" type="checkbox" id="remember-login" checked>
                            <label class="form-check-label" for="remember-login">Recordar usuario en este navegador</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 mt-3">Entrar</button>
                    </form>

                    <div class="text-center mt-3">
                        <a href="forgot_password.php" class="text-decoration-none" style="color: var(--primary-color);">He olvidado mi contrase&ntilde;a</a>
                    </div>

                    <?php if (!$is_admin_login && $open_patient_registration): ?>
                        <div class="text-center mt-2">
                            <a href="register.php" class="text-decoration-none" style="color: var(--primary-color);">Crea tu cuenta</a>
                        </div>
                    <?php endif; ?>

                    <div class="text-center mt-3">
                        <a href="index.php" class="text-decoration-none" style="color: var(--primary-color);">Volver a la página principal</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="auth-legal-footer">
        <span class="legal-brand-line">
            <img src="<?= htmlspecialchars($official_brand_logo_url) ?>" alt="" class="legal-brand-mark">
            <span><?= htmlspecialchars(app_legal_footer_text()) ?></span>
        </span>
    </footer>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function () {
            function setCookie(name, value, days) {
                const expires = new Date(Date.now() + days * 86400000).toUTCString();
                const secure = window.location.protocol === 'https:' ? '; Secure' : '';
                document.cookie = `${name}=${encodeURIComponent(value)}; expires=${expires}; path=/; SameSite=Lax${secure}`;
            }

            function deleteCookie(name) {
                const secure = window.location.protocol === 'https:' ? '; Secure' : '';
                document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Lax${secure}`;
            }

            $('#login-form').on('submit', function (e) {
                e.preventDefault();
                $('#login-alert').addClass('d-none');
                const $button = $(this).find('button[type="submit"]');
                if (!$button.data('original-html')) {
                    $button.data('original-html', $button.html());
                }
                $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Entrando...');

                function resetLoginButton() {
                    $button.prop('disabled', false).html($button.data('original-html') || 'Entrar');
                }

                $.ajax({
                    url: 'api/auth.php?action=login',
                    method: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function (res) {
                        if (res.success) {
                            if ($('#remember-login').is(':checked')) {
                                setCookie('psicologic_login_id', $('#login-id').val().trim(), 180);
                            } else {
                                deleteCookie('psicologic_login_id');
                            }
                            window.location.href = 'dashboard.php';
                        } else {
                            $('#login-alert').removeClass('d-none alert-success').addClass('alert-danger').text(res.error);
                            resetLoginButton();
                        }
                    },
                    error: function () {
                        $('#login-alert').removeClass('d-none alert-success').addClass('alert-danger').text("Error de conexión");
                        resetLoginButton();
                    }
                });
            });
        });
    </script>
</body>

</html>
