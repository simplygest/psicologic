<?php
session_start();
require_once 'db.php';
require_once 'settings_helpers.php';
require_once 'cabinet_helpers.php';

$branding = get_public_branding_settings($mysqli);
if (!cabinet_public_team_enabled($mysqli)) {
    http_response_code(404);
    echo 'Pagina no disponible';
    exit;
}

$app_name = $branding['app_name'];
$profile_image_path = $branding['show_profile_image_public'] ? $branding['profile_image_path'] : '';
$is_logged_in = isset($_SESSION['user_id']);
$is_admin = in_array(($_SESSION['role'] ?? ''), ['admin', 'superadmin'], true);
$online_booking_enabled = (int) ($branding['online_booking_enabled'] ?? 1) === 1;
$show_patient_area = $online_booking_enabled || $is_admin;
$show_prices_public = (int) ($branding['show_prices_public'] ?? 0) === 1;
$show_contact_public = (int) ($branding['show_contact_public'] ?? 0) === 1;
$members = cabinet_fetch_public_team_members($mysqli);

function team_initials($name)
{
    $parts = preg_split('/\s+/', trim((string) $name));
    $initials = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $initials .= function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
        }
        $length = function_exists('mb_strlen') ? mb_strlen($initials, 'UTF-8') : strlen($initials);
        if ($length >= 2) {
            break;
        }
    }
    return function_exists('mb_strtoupper') ? mb_strtoupper($initials ?: 'PS', 'UTF-8') : strtoupper($initials ?: 'PS');
}

function team_specialty_pills($specialty)
{
    $items = preg_split('/,/', (string) $specialty);
    $items = array_values(array_filter(array_map('trim', $items), function ($item) {
        return $item !== '';
    }));
    if (!$items && trim((string) $specialty) !== '') {
        $items = [trim((string) $specialty)];
    }
    return $items;
}

function team_social_links($member)
{
    $links = [];
    if (!empty($member['instagram_url'])) {
        $links[] = ['url' => $member['instagram_url'], 'icon' => 'bi-instagram', 'label' => 'Instagram'];
    }
    if (!empty($member['facebook_url'])) {
        $links[] = ['url' => $member['facebook_url'], 'icon' => 'bi-facebook', 'label' => 'Facebook'];
    }
    if (!empty($member['tiktok_url'])) {
        $links[] = ['url' => $member['tiktok_url'], 'icon' => 'bi-tiktok', 'label' => 'TikTok'];
    }
    return $links;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Equipo - <?= htmlspecialchars($app_name) ?></title>
    <meta name="description" content="Equipo profesional de <?= htmlspecialchars($app_name) ?>.">
    <?= favicon_link_tags($branding) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
    <style>:root { --primary-color: <?= htmlspecialchars($branding['primary_color']) ?>; }</style>
</head>

<body class="public-site">
    <nav class="navbar navbar-expand-lg public-navbar py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <?php if ($profile_image_path): ?>
                    <img src="<?= htmlspecialchars($profile_image_path) ?>" alt="" class="brand-avatar">
                <?php endif; ?>
                <span><?= htmlspecialchars($app_name) ?></span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNav" aria-controls="publicNav" aria-expanded="false" aria-label="Abrir menú">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="publicNav">
                <div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                    <a class="nav-link" href="index.php">Inicio</a>
                    <?php if ($show_prices_public): ?>
                        <a class="nav-link" href="precios.php">Precios</a>
                    <?php endif; ?>
                    <?php if ($show_contact_public): ?>
                        <a class="nav-link" href="contacto.php">Contacto</a>
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
        <section class="landing-section team-page-section">
            <div class="container">
                <div class="team-page-header">
                    <div class="section-heading mb-0">
                        <span>Equipo profesional</span>
                        <h1>Especialistas que te acompañan</h1>
                    </div>
                    <p class="section-copy mb-0">Un equipo coordinado para ofrecer una atención cercana, rigurosa y adaptada a cada etapa del proceso terapéutico.</p>
                </div>

                <div class="team-grid">
                    <?php foreach ($members as $member): ?>
                        <article class="team-card">
                            <div class="team-card-photo">
                                <?php if (!empty($member['display_photo_path'])): ?>
                                    <img src="<?= htmlspecialchars($member['display_photo_path']) ?>" alt="<?= htmlspecialchars($member['display_name']) ?>">
                                <?php else: ?>
                                    <div class="team-card-placeholder"><?= htmlspecialchars(team_initials($member['display_name'])) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="team-card-body">
                                <h2><?= htmlspecialchars($member['display_name']) ?></h2>
                                <?php if (!empty($member['professional_title'])): ?>
                                    <p class="team-title"><?= htmlspecialchars($member['professional_title']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($member['license_number'])): ?>
                                    <p class="team-license">Nº de colegiado: <?= htmlspecialchars($member['license_number']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($member['public_phone'])): ?>
                                    <p class="team-phone"><i class="bi bi-telephone"></i> <?= htmlspecialchars($member['public_phone']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($member['professional_specialty'])): ?>
                                    <div class="team-specialty-pills">
                                        <?php foreach (team_specialty_pills($member['professional_specialty']) as $specialty): ?>
                                            <span><?= htmlspecialchars($specialty) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($member['public_bio'])): ?>
                                    <p class="team-bio"><?= nl2br(htmlspecialchars($member['public_bio'])) ?></p>
                                <?php endif; ?>
                                <?php $social_links = team_social_links($member); ?>
                                <?php if ($social_links): ?>
                                    <div class="team-social-links">
                                        <?php foreach ($social_links as $link): ?>
                                            <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= htmlspecialchars($link['label'] . ' de ' . $member['display_name']) ?>" title="<?= htmlspecialchars($link['label']) ?>">
                                                <i class="bi <?= htmlspecialchars($link['icon']) ?>"></i>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($online_booking_enabled): ?>
                    <div class="team-cta">
                        <div>
                            <h2>Reserva tu cita</h2>
                            <p>Accede al calendario para consultar disponibilidad y elegir el horario que mejor encaje contigo.</p>
                        </div>
                        <a href="<?= $is_logged_in ? 'dashboard.php' : 'login.php' ?>" class="btn btn-primary btn-lg">
                            <?= $is_admin ? 'Abrir panel admin' : ($is_logged_in ? 'Gestionar mis citas' : 'Reserva tu cita') ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <footer class="public-footer">
        <div class="container d-flex flex-column flex-md-row justify-content-between gap-2">
            <span>&copy; <?= date('Y') ?> <?= htmlspecialchars($app_name) ?></span>
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
