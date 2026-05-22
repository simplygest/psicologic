<?php
require_once 'db.php';
require_once 'settings_helpers.php';
$token = $_GET['token'] ?? '';
$valid = false;
$branding = get_public_branding_settings($mysqli);
$app_name = $branding['app_name'];
$profile_image_path = $branding['show_profile_image_public'] ? $branding['profile_image_path'] : '';

if ($token) {
    $stmt = $mysqli->prepare("SELECT id FROM invitations WHERE token = ? AND used = 0");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->fetch_assoc()) {
        $valid = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro - <?= htmlspecialchars($app_name) ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
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

                    <?php if (!$valid): ?>
                        <div class="alert alert-danger text-center">
                            El enlace de invitación no es válido o ya ha sido utilizado.
                        </div>
                    <?php else: ?>
                        <div id="register-alert" class="alert d-none"></div>

                        <form id="register-form">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                            <div class="mb-3">
                                <label class="form-label">Nombre Completo</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email (Opcional si usas teléfono)</label>
                                <input type="email" class="form-control" name="email">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Teléfono (Opcional si usas email)</label>
                                <input type="text" class="form-control" name="phone">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Contraseña</label>
                                <input type="password" class="form-control" name="password" required minlength="6">
                            </div>
                            <button type="submit" class="btn btn-primary w-100 mt-3">Completar Registro</button>
                        </form>
                    <?php endif; ?>

                    <div class="text-center mt-3">
                        <a href="index.php" class="text-decoration-none" style="color: var(--primary-color);">Ya tengo una cuenta</a>
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
                let phone = $('input[name="phone"]').val().trim();

                if (!email && !phone) {
                    $('#register-alert').removeClass('d-none alert-success').addClass('alert-danger').text("Debes proporcionar un email o un teléfono.");
                    return;
                }

                $.ajax({
                    url: 'api/auth.php?action=register',
                    method: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function (res) {
                        if (res.success) {
                            $('#register-form').hide();
                            $('#register-alert').removeClass('d-none alert-danger').addClass('alert-success').text("Registro completado con éxito. Ya puedes iniciar sesión.");
                        } else {
                            $('#register-alert').removeClass('d-none alert-success').addClass('alert-danger').text(res.error);
                        }
                    },
                    error: function () {
                        $('#register-alert').removeClass('d-none alert-success').addClass('alert-danger').text("Error de conexión");
                    }
                });
            });
        });
    </script>
</body>

</html>
