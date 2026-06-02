<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
require_once 'db.php';
require_once 'settings_helpers.php';
$branding = get_public_branding_settings($mysqli);
$app_name = $branding['app_name'];
$profile_image_path = $branding['show_profile_image_public'] ? $branding['profile_image_path'] : '';
$token = $_GET['t'] ?? '';
$valid_format = preg_match('/^[a-f0-9]{64}$/', $token);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Nueva contrase&ntilde;a - <?= htmlspecialchars($app_name) ?></title>
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
                        <p class="text-muted">Crea una nueva contrase&ntilde;a para tu cuenta.</p>
                    </div>

                    <div id="reset-alert" class="alert <?= $valid_format ? 'd-none' : 'alert-danger' ?>">
                        <?= $valid_format ? '' : 'El enlace no es v&aacute;lido.' ?>
                    </div>

                    <?php if ($valid_format): ?>
                        <form id="reset-form">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                            <div class="mb-3">
                                <label for="password" class="form-label">Nueva contrase&ntilde;a</label>
                                <input type="password" class="form-control" id="password" name="password" required minlength="6">
                            </div>
                            <div class="mb-3">
                                <label for="password-confirm" class="form-label">Repetir contrase&ntilde;a</label>
                                <input type="password" class="form-control" id="password-confirm" required minlength="6">
                            </div>
                            <button type="submit" class="btn btn-primary w-100 mt-3">Guardar contrase&ntilde;a</button>
                        </form>
                    <?php endif; ?>

                    <div class="text-center mt-3">
                        <a href="login.php" class="text-decoration-none" style="color: var(--primary-color);">Volver al login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function () {
            $('#reset-form').on('submit', function (e) {
                e.preventDefault();
                $('#reset-alert').addClass('d-none');

                const password = $('#password').val();
                const confirm = $('#password-confirm').val();
                if (password !== confirm) {
                    $('#reset-alert').removeClass('d-none alert-success').addClass('alert-danger').text('Las contraseñas no coinciden.');
                    return;
                }

                $.ajax({
                    url: 'api/auth.php?action=reset_password',
                    method: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function (res) {
                        if (res.success) {
                            $('#reset-form').hide();
                            $('#reset-alert').removeClass('d-none alert-danger').addClass('alert-success').text(res.message);
                        } else {
                            $('#reset-alert').removeClass('d-none alert-success').addClass('alert-danger').text(res.error);
                        }
                    },
                    error: function () {
                        $('#reset-alert').removeClass('d-none alert-success').addClass('alert-danger').text('Error de conexión');
                    }
                });
            });
        });
    </script>
</body>

</html>
