<?php
header('Content-Type: text/html; charset=UTF-8');
require_once 'db.php';
require_once 'settings_helpers.php';
$token = $_GET['token'] ?? '';
$valid = false;
$invite_patient = null;
$branding = get_public_branding_settings($mysqli);
$app_name = $branding['app_name'];
$profile_image_path = $branding['show_profile_image_public'] ? $branding['profile_image_path'] : '';
$open_patient_registration = ($branding['patient_registration_mode'] ?? 'invite') === 'open';
$can_register = false;
$registration_closed_message = '';

if ($token) {
    $stmt = $mysqli->prepare("
        SELECT i.id, i.user_id, u.name, u.email, u.phone
        FROM invitations i
        LEFT JOIN users u ON u.id = i.user_id
        WHERE i.token = ? AND i.used = 0
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($invite = $res->fetch_assoc()) {
        $valid = true;
        $invite_patient = $invite;
    }
}

$can_register = $valid || (!$token && $open_patient_registration);
if (!$can_register) {
    $registration_closed_message = $token
        ? 'El enlace de invitación no es válido o ya ha sido utilizado.'
        : 'Contacta con nosotros para enviarte una invitación de registro online';
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Registro - <?= htmlspecialchars($app_name) ?></title>
    <?= favicon_link_tags($branding) ?>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
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
                        <p class="text-muted">Crea tu cuenta para poder pedir cita.</p>
                    </div>

                    <?php if (!$can_register): ?>
                        <div class="alert alert-danger text-center">
                            <?= htmlspecialchars($registration_closed_message) ?>
                        </div>
                    <?php else: ?>
                        <div id="register-alert" class="alert d-none"></div>
                        <div id="register-login-cta" class="d-none">
                            <a href="login.php" class="btn btn-primary w-100">Iniciar sesi&oacute;n</a>
                        </div>

                        <form id="register-form">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                            <div class="mb-3">
                                <label class="form-label">Nombre Completo</label>
                                <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($invite_patient['name'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($invite_patient['email'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Tel&eacute;fono (opcional)</label>
                                <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($invite_patient['phone'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Contraseña</label>
                                <input type="password" class="form-control" name="password" required minlength="6">
                            </div>
                            <button type="submit" class="btn btn-primary w-100 mt-3">Completar Registro</button>
                        </form>
                    <?php endif; ?>

                    <div class="text-center mt-3">
                        <a href="login.php" id="login-link" class="text-decoration-none" style="color: var(--primary-color);">Ya tengo una cuenta</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function () {
            $('#register-form').on('submit', function (e) {
                e.preventDefault();
                $('#register-alert').addClass('d-none');

                let email = $('input[name="email"]').val().trim();
                if (!email) {
                    $('#register-alert').removeClass('d-none alert-success').addClass('alert-danger').text("Debes proporcionar un email.");
                    return;
                }
                const $button = $(this).find('button[type="submit"]');
                if (!$button.data('original-html')) {
                    $button.data('original-html', $button.html());
                }
                $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Registrando...');

                function resetRegisterButton() {
                    $button.prop('disabled', false).html($button.data('original-html') || 'Completar Registro');
                }

                $.ajax({
                    url: 'api/auth.php?action=register',
                    method: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function (res) {
                        if (res.success) {
                            $('#register-form').hide();
                            $('#register-login-cta').removeClass('d-none');
                            $('#login-link').text('Iniciar sesión');
                            $('#register-alert').removeClass('d-none alert-danger').addClass('alert-success').text("Registro completado con éxito. Ya puedes iniciar sesión.");
                        } else {
                            $('#register-alert').removeClass('d-none alert-success').addClass('alert-danger').text(res.error);
                            resetRegisterButton();
                        }
                    },
                    error: function () {
                        $('#register-alert').removeClass('d-none alert-success').addClass('alert-danger').text("Error de conexión");
                        resetRegisterButton();
                    }
                });
            });
        });
    </script>
</body>

</html>
