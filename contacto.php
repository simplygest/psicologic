<?php
session_start();
require_once 'db.php';
require_once 'settings_helpers.php';
require_once 'mail_helpers.php';
require_once 'cabinet_helpers.php';

$branding = get_public_branding_settings($mysqli);
if ((int) ($branding['show_contact_public'] ?? 0) !== 1) {
    http_response_code(404);
    echo 'Página no disponible';
    exit;
}

$app_name = $branding['app_name'];
$site_tagline = trim($branding['site_tagline'] ?? '');
$site_phone = trim($branding['site_phone'] ?? '');
$profile_image_path = $branding['show_profile_image_public'] ? $branding['profile_image_path'] : '';
$show_prices_public = (int) ($branding['show_prices_public'] ?? 0) === 1;
$show_team_public = cabinet_public_team_enabled($mysqli);
$is_logged_in = isset($_SESSION['user_id']);
$is_admin = in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'], true);
$online_booking_enabled = (int) ($branding['online_booking_enabled'] ?? 1) === 1;
$show_patient_area = $online_booking_enabled || $is_admin;

if (empty($_SESSION['contact_form_token'])) {
    $_SESSION['contact_form_token'] = bin2hex(random_bytes(24));
}

$form = [
    'name' => '',
    'phone' => '',
    'email' => '',
    'message' => ''
];
$success_message = '';
$error_message = '';

function contact_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function contact_recipient_email($mysqli)
{
    $email = get_admin_notification_email($mysqli);
    if ($email !== '') {
        return $email;
    }

    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['name'] = trim($_POST['name'] ?? '');
    $form['phone'] = trim($_POST['phone'] ?? '');
    $form['email'] = trim($_POST['email'] ?? '');
    $form['message'] = trim($_POST['message'] ?? '');
    $accepted_privacy = isset($_POST['privacy_accept']) && $_POST['privacy_accept'] === '1';
    $token = $_POST['contact_form_token'] ?? '';

    if (!hash_equals($_SESSION['contact_form_token'] ?? '', $token)) {
        $error_message = 'No se pudo validar el formulario. Recarga la página e inténtalo de nuevo.';
    } elseif ($form['name'] === '' || $form['phone'] === '' || $form['email'] === '' || $form['message'] === '') {
        $error_message = 'Completa todos los campos para enviar la consulta.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Indica un email válido para poder responderte.';
    } elseif (!$accepted_privacy) {
        $error_message = 'Debes aceptar la política de privacidad para enviar la consulta.';
    } else {
        $recipient = contact_recipient_email($mysqli);
        if ($recipient === '') {
            $error_message = 'No se pudo enviar la consulta porque no hay un email de sistema configurado.';
        } else {
            $html_body = '
                <p>Se ha recibido una solicitud de información desde la web.</p>
                <p><strong>Nombre:</strong> ' . contact_h($form['name']) . '</p>
                <p><strong>Teléfono:</strong> ' . contact_h($form['phone']) . '</p>
                <p><strong>Email:</strong> ' . contact_h($form['email']) . '</p>
                <p><strong>Consulta:</strong></p>
                <p>' . nl2br(contact_h($form['message'])) . '</p>
            ';

            if (send_app_email($recipient, 'Solicitud de información', $html_body, $form['email'], $mysqli)) {
                $success_message = 'Consulta enviada correctamente. Te responderemos lo antes posible.';
                $form = ['name' => '', 'phone' => '', 'email' => '', 'message' => ''];
                $_SESSION['contact_form_token'] = bin2hex(random_bytes(24));
            } else {
                $error_message = 'No se pudo enviar la consulta. Inténtalo de nuevo dentro de unos minutos.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Contacto - <?= contact_h($app_name) ?></title>
    <meta name="description" content="Envía una consulta a <?= contact_h($app_name) ?>.">
    <?= favicon_link_tags($branding) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
    <style>:root { --primary-color: <?= contact_h($branding['primary_color']) ?>; }</style>
</head>

<body class="public-site">
    <nav class="navbar navbar-expand-lg public-navbar py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <?php if ($profile_image_path): ?>
                    <img src="<?= contact_h($profile_image_path) ?>" alt="" class="brand-avatar">
                <?php endif; ?>
                <span><?= contact_h($app_name) ?></span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNav" aria-controls="publicNav" aria-expanded="false" aria-label="Abrir menú">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="publicNav">
                <div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                    <a class="nav-link" href="index.php">Inicio</a>
                    <?php if ($show_team_public): ?>
                        <a class="nav-link" href="equipo.php">Equipo</a>
                    <?php endif; ?>
                    <?php if ($show_prices_public): ?>
                        <a class="nav-link" href="precios.php">Precios</a>
                    <?php endif; ?>
                    <?php if ($show_patient_area): ?>
                        <a class="btn btn-primary btn-sm ms-lg-2" href="<?= $is_logged_in ? 'dashboard.php' : 'login.php' ?>">
                            <?= $is_admin ? 'Panel admin' : ($is_logged_in ? 'Ir a mis citas' : '&Aacute;rea pacientes') ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <main>
        <section class="landing-section contact-page-section">
            <div class="container">
                <div class="contact-layout">
                    <div>
                        <div class="section-heading">
                            <span>Contacto</span>
                            <h1>Enviar consulta</h1>
                        </div>
                        <p class="section-copy">Puedes enviarnos una pregunta o solicitud de información. Te responderemos usando los datos de contacto indicados en el formulario.</p>
                        <?php if ($site_tagline !== ''): ?>
                            <p class="contact-note"><?= contact_h($site_tagline) ?></p>
                        <?php endif; ?>
                        <?php if ($site_phone !== ''): ?>
                            <p class="contact-phone">
                                <span>Teléfono</span>
                                <a href="tel:<?= contact_h(preg_replace('/[^\d+]/', '', $site_phone)) ?>"><?= contact_h($site_phone) ?></a>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="contact-card">
                        <?php if ($success_message): ?>
                            <div class="alert alert-success"><?= contact_h($success_message) ?></div>
                        <?php endif; ?>
                        <?php if ($error_message): ?>
                            <div class="alert alert-danger"><?= contact_h($error_message) ?></div>
                        <?php endif; ?>

                        <form method="post">
                            <input type="hidden" name="contact_form_token" value="<?= contact_h($_SESSION['contact_form_token']) ?>">
                            <div class="mb-3">
                                <label class="form-label" for="contact-name">Nombre</label>
                                <input type="text" class="form-control" id="contact-name" name="name" value="<?= contact_h($form['name']) ?>" required>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="contact-phone">Teléfono</label>
                                    <input type="tel" class="form-control" id="contact-phone" name="phone" value="<?= contact_h($form['phone']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="contact-email">Email</label>
                                    <input type="email" class="form-control" id="contact-email" name="email" value="<?= contact_h($form['email']) ?>" required>
                                </div>
                            </div>
                            <div class="mt-3">
                                <label class="form-label" for="contact-message">Tu consulta</label>
                                <textarea class="form-control" id="contact-message" name="message" rows="7" required><?= contact_h($form['message']) ?></textarea>
                            </div>
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" value="1" id="privacy-accept" name="privacy_accept" required>
                                <label class="form-check-label" for="privacy-accept">
                                    Acepto la <a href="legal.php#privacidad" target="_blank" rel="noopener">política de privacidad</a>
                                </label>
                            </div>
                            <div class="text-end mt-4">
                                <button type="submit" class="btn btn-primary btn-lg">Enviar consulta</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="public-footer">
        <div class="container d-flex flex-column flex-md-row justify-content-between gap-2">
            <span>&copy; <?= date('Y') ?> <?= contact_h($app_name) ?></span>
            <span class="public-footer-links">
                <a href="legal.php#privacidad">Política de privacidad</a>
                <a href="legal.php#aviso-legal">Aviso legal</a>
                <a href="legal.php#cookies">Política de cookies</a>
                <a href="legal.php#condiciones">Términos y condiciones</a>
            </span>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
