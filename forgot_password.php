<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';
require_once 'settings_helpers.php';
require_once 'cabinet_helpers.php';
require_once 'public_nav_helpers.php';
$branding = get_public_branding_settings($mysqli);
$app_name = $branding['app_name'];
$official_brand_logo_url = app_official_brand_logo_url();
$has_tenant_brand_logo = trim((string) ($branding['profile_image_path'] ?? '')) !== '';
$tenant_brand_logo_url = $has_tenant_brand_logo
    ? app_upload_asset_url($branding['profile_image_path'])
    : $official_brand_logo_url;
$show_public_nav = (int) ($branding['public_site_enabled'] ?? 0) === 1;
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Recordar contrase&ntilde;a - <?= htmlspecialchars($app_name) ?></title>
    <?= favicon_link_tags($branding) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
    <style>:root { --primary-color: <?= htmlspecialchars($branding['primary_color']) ?>; }</style>
</head>

<body class="auth-page <?= $show_public_nav ? 'auth-page-with-nav public-site' : 'd-flex align-items-center justify-content-center flex-column' ?>" style="min-height: 100vh;">
    <?php if ($show_public_nav): ?>
        <?php render_public_nav($mysqli, $branding, ['show_patient_area' => false]); ?>
    <?php endif; ?>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card p-4">
                    <div class="text-center mb-4">
                        <img src="<?= htmlspecialchars($tenant_brand_logo_url) ?>" alt="<?= htmlspecialchars($app_name) ?>" class="auth-brand-image<?= $has_tenant_brand_logo ? '' : ' auth-brand-image-official' ?> mb-3">
                        <h2 style="color: var(--primary-color);"><?= htmlspecialchars($app_name) ?></h2>
                        <p class="text-muted">Te enviaremos un enlace para crear una nueva contrase&ntilde;a.</p>
                    </div>

                    <div id="forgot-alert" class="alert d-none"></div>

                    <form id="forgot-form">
                        <div class="mb-3">
                            <label for="login-id" class="form-label">Email o tel&eacute;fono</label>
                            <input type="text" class="form-control" id="login-id" name="login_id" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 mt-3">Enviar enlace</button>
                    </form>

                    <div class="text-center mt-3">
                        <a href="login.php" class="text-decoration-none" style="color: var(--primary-color);">Volver al login</a>
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
    <?php if ($show_public_nav): ?>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php endif; ?>
    <script>
        $(document).ready(function () {
            $('#forgot-form').on('submit', function (e) {
                e.preventDefault();
                $('#forgot-alert').addClass('d-none');

                $.ajax({
                    url: 'api/auth.php?action=request_password_reset',
                    method: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function (res) {
                        if (res.success) {
                            $('#forgot-form').hide();
                            $('#forgot-alert').removeClass('d-none alert-danger').addClass('alert-success').text(res.message);
                        } else {
                            $('#forgot-alert').removeClass('d-none alert-success').addClass('alert-danger').text(res.error);
                        }
                    },
                    error: function () {
                        $('#forgot-alert').removeClass('d-none alert-success').addClass('alert-danger').text('Error de conexión');
                    }
                });
            });
        });
    </script>
</body>

</html>
