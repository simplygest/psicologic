<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../settings_helpers.php';
require_once __DIR__ . '/../cabinet_helpers.php';

$branding = get_public_branding_settings($mysqli);
$app_name = trim((string) ($branding['app_name'] ?? '')) ?: 'SimplyGest Praxis';
$site_tagline = trim((string) ($branding['site_tagline'] ?? ''));
$site_phone = trim((string) ($branding['site_phone'] ?? ''));
$official_brand_logo_url = app_official_brand_logo_url();
$tenant_brand_logo_url = trim((string) ($branding['profile_image_path'] ?? '')) !== ''
    ? app_upload_asset_url($branding['profile_image_path'])
    : $official_brand_logo_url;
$landing_image_path = trim((string) ($branding['landing_image_path'] ?? '')) !== ''
    ? app_upload_asset_url($branding['landing_image_path'])
    : '';
$show_contact_public = (int) ($branding['show_contact_public'] ?? 0) === 1;
$show_prices_public = (int) ($branding['show_prices_public'] ?? 0) === 1;
$show_team_public = cabinet_public_team_enabled($mysqli);
$is_logged_in = isset($_SESSION['user_id']);
$is_admin = in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'], true);

if ((int) ($branding['public_site_enabled'] ?? 0) !== 1) {
    header('Location: login.php');
    exit;
}

$online_booking_enabled = online_booking_enabled($mysqli);
$registration_open = $online_booking_enabled && patient_registration_is_open($mysqli);
$show_private_access = $online_booking_enabled || $is_admin;
$phone_href = preg_replace('/[^\d+]/', '', $site_phone);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?= htmlspecialchars($app_name, ENT_QUOTES, 'UTF-8') ?></title>
    <?php if ($site_tagline !== ''): ?>
        <meta name="description" content="<?= htmlspecialchars($site_tagline, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <?= favicon_link_tags($branding) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/../css/style.css') ?>">
    <style>:root { --primary-color: <?= htmlspecialchars($branding['primary_color'], ENT_QUOTES, 'UTF-8') ?>; }</style>
</head>

<body class="public-site">
    <nav class="navbar navbar-expand-lg public-navbar py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <img src="<?= htmlspecialchars($tenant_brand_logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($app_name, ENT_QUOTES, 'UTF-8') ?>" class="brand-avatar">
                <span><?= htmlspecialchars($app_name, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <?php if ($show_team_public): ?>
                    <a class="nav-link" href="equipo.php">Equipo</a>
                <?php endif; ?>
                <?php if ($show_contact_public): ?>
                    <a class="nav-link" href="contacto.php">Contacto</a>
                <?php endif; ?>
                <?php if ($show_prices_public): ?>
                    <a class="nav-link" href="precios.php">Precios</a>
                <?php endif; ?>
                <?php if ($show_private_access): ?>
                <?php if ($registration_open && !$is_logged_in): ?>
                    <a class="btn btn-outline-secondary btn-sm ms-lg-2" href="register.php">Registro</a>
                <?php endif; ?>
                <a class="btn btn-primary btn-sm ms-lg-2" href="<?= $is_logged_in ? 'dashboard.php' : 'login.php' ?>">
                    <?= $is_admin ? 'Panel admin' : ($is_logged_in ? 'Mi área' : 'Acceso') ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <main>
        <section class="landing-hero">
            <div class="container">
                <div class="row align-items-center g-5">
                    <div class="<?= $landing_image_path ? 'col-lg-7' : 'col-lg-9' ?>">
                        <?php if ($site_tagline !== ''): ?>
                            <p class="landing-kicker"><?= htmlspecialchars($site_tagline, ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                        <h1><?= htmlspecialchars($app_name, ENT_QUOTES, 'UTF-8') ?></h1>
                        <p class="landing-lead">
                            <?= $show_private_access
                                ? 'Accede a tu &aacute;rea privada para consultar la informaci&oacute;n disponible, gestionar tus citas y revisar los datos publicados por el profesional.'
                                : 'Consulta la informaci&oacute;n publicada por el centro y utiliza los canales disponibles para contactar.' ?>
                        </p>
                        <div class="landing-actions">
                            <?php if ($show_private_access): ?>
                            <a href="<?= $is_logged_in ? 'dashboard.php' : 'login.php' ?>" class="btn btn-primary btn-lg">
                                <?= $is_logged_in ? 'Abrir mi área' : 'Acceder' ?>
                            </a>
                            <?php if ($registration_open && !$is_logged_in): ?>
                                <a href="register.php" class="btn btn-outline-secondary btn-lg">Crear cuenta</a>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($show_contact_public): ?>
                                <a href="contacto.php" class="btn btn-outline-secondary btn-lg">Contacto</a>
                            <?php endif; ?>
                        </div>
                        <?php if ($site_phone !== ''): ?>
                            <div class="landing-contact">
                                <span>Teléfono de contacto</span>
                                <a href="tel:<?= htmlspecialchars($phone_href, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($site_phone, ENT_QUOTES, 'UTF-8') ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($landing_image_path): ?>
                        <div class="col-lg-5">
                            <div class="landing-portrait">
                                <img src="<?= htmlspecialchars($landing_image_path, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($app_name, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

    <footer class="public-footer">
        <div class="container d-flex flex-column flex-md-row justify-content-between gap-2">
            <span class="legal-brand-line">
                <img src="<?= htmlspecialchars($official_brand_logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="" class="legal-brand-mark">
                <span><?= htmlspecialchars(app_legal_footer_text(), ENT_QUOTES, 'UTF-8') ?></span>
            </span>
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
