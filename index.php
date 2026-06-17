<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
require_once 'db.php';
require_once 'settings_helpers.php';
require_once 'cabinet_helpers.php';
$branding = get_public_branding_settings($mysqli);
$app_name = $branding['app_name'];
$site_tagline = trim($branding['site_tagline'] ?? '') ?: 'Psicología sanitaria y neuropsicología en Santa Cruz de Tenerife';
$site_phone = trim($branding['site_phone'] ?? '');
$profile_image_path = $branding['show_profile_image_public'] ? $branding['profile_image_path'] : '';
$landing_image_path = $branding['landing_image_path'] ?: '';
$show_prices_public = (int) ($branding['show_prices_public'] ?? 0) === 1;
$show_contact_public = (int) ($branding['show_contact_public'] ?? 0) === 1;
$show_team_public = cabinet_public_team_enabled($mysqli);
$is_logged_in = isset($_SESSION['user_id']);
$is_admin = in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'], true);
if ((int) ($branding['public_site_enabled'] ?? 0) !== 1) {
    header('Location: login.php');
    exit;
}
$online_booking_enabled = (int) ($branding['online_booking_enabled'] ?? 1) === 1;
$show_patient_area = $online_booking_enabled || $is_admin;
$appointment_delivery_mode = 'both';
$settings_table = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
if ($settings_table && $settings_table->num_rows > 0) {
    $delivery_column = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'appointment_delivery_mode'");
    if ($delivery_column && $delivery_column->num_rows > 0) {
        $delivery_res = $mysqli->query("SELECT appointment_delivery_mode FROM payment_settings WHERE id = 1");
        if ($delivery_row = $delivery_res->fetch_assoc()) {
            $appointment_delivery_mode = $delivery_row['appointment_delivery_mode'] ?: 'both';
        }
    }
}

function public_delivery_text($mode)
{
    if ($mode === 'online') {
        return 'sesiones online';
    }
    if ($mode === 'presencial') {
        return 'sesiones presenciales';
    }

    return 'sesiones presenciales y online';
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?= htmlspecialchars($app_name) ?> - Psicología sanitaria</title>
    <meta name="description" content="<?= htmlspecialchars($site_tagline) ?>">
    <?= favicon_link_tags($branding) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
                    <a class="nav-link" href="#servicios">Servicios</a>
                    <a class="nav-link" href="#formacion">Formación</a>
                    <a class="nav-link" href="#experiencia">Experiencia</a>
                    <?php if ($show_team_public): ?>
                        <a class="nav-link" href="equipo.php">Equipo</a>
                    <?php endif; ?>
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
        <section class="landing-hero">
            <div class="container">
                <div class="row align-items-center g-5">
                    <div class="col-lg-7">
                        <p class="landing-kicker"><?= htmlspecialchars($site_tagline) ?></p>
                        <h1><?= htmlspecialchars($app_name) ?></h1>
                        <p class="landing-lead">Acompañamiento psicológico basado en evidencia, con <?= htmlspecialchars(public_delivery_text($appointment_delivery_mode)) ?> para adultos, infancia y adolescencia.</p>
                        <div class="landing-actions">
                            <?php if ($show_patient_area): ?>
                                <a href="<?= $is_logged_in ? 'dashboard.php' : 'login.php' ?>" class="btn btn-primary btn-lg">
                                    <?= $is_admin ? 'Abrir panel admin' : ($is_logged_in ? 'Gestionar mis citas' : 'Pedir cita') ?>
                                </a>
                            <?php endif; ?>
                            <a href="#servicios" class="btn btn-outline-secondary btn-lg">Ver servicios</a>
                            <?php if ($show_contact_public): ?>
                                <a href="contacto.php" class="btn btn-outline-secondary btn-lg">Enviar consulta</a>
                            <?php endif; ?>
                        </div>
                        <?php if ($site_phone !== ''): ?>
                            <div class="landing-contact">
                                <span>Teléfono de contacto</span>
                                <a href="tel:<?= htmlspecialchars(preg_replace('/[^\d+]/', '', $site_phone)) ?>"><?= htmlspecialchars($site_phone) ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-lg-5">
                        <div class="landing-portrait">
                            <?php if ($landing_image_path): ?>
                                <img src="<?= htmlspecialchars($landing_image_path) ?>" alt="<?= htmlspecialchars($app_name) ?>">
                            <?php else: ?>
                                <div class="landing-portrait-placeholder">
                                    <span>SLB</span>
                                </div>
                            <?php endif; ?>
                            <div class="landing-portrait-caption">
                                <strong>N.º colegiada T-04491</strong>
                                <span>Consulta privada en Santa Cruz de Tenerife</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="landing-section" id="servicios">
            <div class="container">
                <div class="section-heading">
                    <span>Servicios</span>
                    <h2>Áreas de intervención</h2>
                </div>
                <div class="service-grid">
                    <article class="service-card">
                        <h3>Psicología general sanitaria</h3>
                        <p>Evaluación e intervención psicológica adaptada a las necesidades de cada persona, con un enfoque práctico y basado en la evidencia.</p>
                    </article>
                    <article class="service-card">
                        <h3>Neuropsicología</h3>
                        <p>Valoración, estimulación y rehabilitación cognitiva en dificultades de memoria, atención, funciones ejecutivas y daño cerebral adquirido.</p>
                    </article>
                    <article class="service-card">
                        <h3>Infancia y adolescencia</h3>
                        <p>Atención a dificultades emocionales, conductuales y del neurodesarrollo, incluyendo TDAH, autismo y apoyo a familias.</p>
                    </article>
                    <article class="service-card">
                        <h3>Bienestar emocional</h3>
                        <p>Acompañamiento en ansiedad, depresión, autoestima, duelo, estrés, adicciones y problemas relacionales.</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="landing-section muted-section" id="experiencia">
            <div class="container">
                <div class="row g-5 align-items-start">
                    <div class="col-lg-5">
                        <div class="section-heading">
                            <span>Experiencia</span>
                            <h2>Trayectoria clínica y comunitaria</h2>
                        </div>
                        <p class="section-copy">Stephanie Luis Báez es psicóloga sanitaria y neuropsicóloga. Su experiencia incluye trabajo en hospitales, clínicas, gabinetes de psicología, programas comunitarios y recursos especializados.</p>
                    </div>
                    <div class="col-lg-7">
                        <div class="timeline-list">
                            <div>
                                <strong>Neurocentro Tenerife</strong>
                                <span>Psicóloga y neuropsicóloga en la actualidad.</span>
                            </div>
                            <div>
                                <strong>Fundación Instituto Spiral</strong>
                                <span>Intervención neuropsicológica y apoyo en procesos de rehabilitación.</span>
                            </div>
                            <div>
                                <strong>Ayuntamiento de San Cristóbal de La Laguna</strong>
                                <span>Experiencia en programas de atención comunitaria.</span>
                            </div>
                            <div>
                                <strong>Hospital Universitario de Canarias y gabinetes privados</strong>
                                <span>Recorrido profesional en contextos sanitarios y asistenciales.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="landing-section" id="formacion">
            <div class="container">
                <div class="section-heading">
                    <span>Formación</span>
                    <h2>Especialización académica</h2>
                </div>
                <div class="education-grid">
                    <div>Grado en Psicología por la Universidad de La Laguna.</div>
                    <div>Máster en Psicología General Sanitaria por la Universidad Autónoma de Madrid.</div>
                    <div>Máster en Neuropsicología Clínica por la Universidad Complutense de Madrid.</div>
                    <div>Experta universitaria en Trastornos de la Personalidad por la UDIMA.</div>
                    <div>Experta universitaria en Psicopatología Clínica Infanto-Juvenil por la UDIMA.</div>
                    <div>Preparación PIR en Academia APIR.</div>
                </div>
            </div>
        </section>

        <section class="landing-section cta-section" id="consulta">
            <div class="container">
                <div class="row align-items-center g-4">
                    <div class="col-lg-7">
                        <div class="section-heading mb-0">
                            <span>Consulta</span>
                            <h2>Gestiona tu cita online</h2>
                        </div>
                        <?php if ($online_booking_enabled): ?>
                            <p class="section-copy mb-0">Si ya tienes cuenta, puedes acceder al &aacute;rea de pacientes para consultar disponibilidad, reservar o cancelar una cita <?= htmlspecialchars(public_delivery_text($appointment_delivery_mode)) ?>.</p>
                        <?php else: ?>
                            <p class="section-copy mb-0">La gesti&oacute;n de citas online no est&aacute; disponible en este momento. La consulta gestiona las reservas directamente.</p>
                        <?php endif; ?>
                    </div>
                    <div class="col-lg-5 text-lg-end">
                        <?php if ($show_patient_area): ?>
                            <a href="<?= $is_logged_in ? 'dashboard.php' : 'login.php' ?>" class="btn btn-primary btn-lg">
                                <?= $is_admin ? 'Abrir panel admin' : ($is_logged_in ? 'Abrir calendario' : 'Acceder al &aacute;rea de pacientes') ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($site_phone !== ''): ?>
                            <a href="tel:<?= htmlspecialchars(preg_replace('/[^\d+]/', '', $site_phone)) ?>" class="btn btn-outline-secondary btn-lg ms-lg-2 mt-2 mt-lg-0">
                                Llamar
                            </a>
                        <?php endif; ?>
                        <?php if ($show_contact_public): ?>
                            <a href="contacto.php" class="btn btn-outline-secondary btn-lg ms-lg-2 mt-2 mt-lg-0">
                                Enviar consulta
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
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
