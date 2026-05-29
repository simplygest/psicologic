<?php
session_start();
require_once 'db.php';
require_once 'settings_helpers.php';
$branding = get_public_branding_settings($mysqli);
$app_name = $branding['app_name'];
$profile_image_path = $branding['show_profile_image_public'] ? $branding['profile_image_path'] : '';
$landing_image_path = $branding['landing_image_path'] ?: '';
$show_prices_public = (int) ($branding['show_prices_public'] ?? 0) === 1;
$is_logged_in = isset($_SESSION['user_id']);
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
    <title>Stephanie Luis Báez - Psicóloga sanitaria y neuropsicóloga</title>
    <meta name="description" content="Consulta de psicología sanitaria y neuropsicología en Santa Cruz de Tenerife. Atención a adultos, infancia y adolescencia.">
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
                <span>Stephanie Luis Báez</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNav" aria-controls="publicNav" aria-expanded="false" aria-label="Abrir menú">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="publicNav">
                <div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                    <a class="nav-link" href="#servicios">Servicios</a>
                    <?php if ($show_prices_public): ?>
                        <a class="nav-link" href="precios.php">Precios</a>
                    <?php endif; ?>
                    <a class="nav-link" href="#experiencia">Experiencia</a>
                    <a class="nav-link" href="#formacion">Formación</a>
                    <a class="nav-link" href="#consulta">Consulta</a>
                    <a class="btn btn-primary btn-sm ms-lg-2" href="<?= $is_logged_in ? 'dashboard.php' : 'login.php' ?>">
                        <?= $is_logged_in ? 'Ir a mis citas' : 'Área pacientes' ?>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <main>
        <section class="landing-hero">
            <div class="container">
                <div class="row align-items-center g-5">
                    <div class="col-lg-7">
                        <p class="landing-kicker">Psicología sanitaria y neuropsicología en Santa Cruz de Tenerife</p>
                        <h1>Stephanie Luis Báez</h1>
                        <p class="landing-lead">Acompañamiento psicológico basado en evidencia, con <?= htmlspecialchars(public_delivery_text($appointment_delivery_mode)) ?> para adultos, infancia y adolescencia.</p>
                        <div class="landing-actions">
                            <a href="<?= $is_logged_in ? 'dashboard.php' : 'login.php' ?>" class="btn btn-primary btn-lg">
                                <?= $is_logged_in ? 'Gestionar mis citas' : 'Pedir cita' ?>
                            </a>
                            <a href="#servicios" class="btn btn-outline-secondary btn-lg">Ver servicios</a>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="landing-portrait">
                            <?php if ($landing_image_path): ?>
                                <img src="<?= htmlspecialchars($landing_image_path) ?>" alt="Stephanie Luis Báez">
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
                        <p class="section-copy mb-0">Si ya tienes cuenta, puedes acceder al área de pacientes para consultar disponibilidad, reservar o cancelar una cita <?= htmlspecialchars(public_delivery_text($appointment_delivery_mode)) ?>.</p>
                    </div>
                    <div class="col-lg-5 text-lg-end">
                        <a href="<?= $is_logged_in ? 'dashboard.php' : 'login.php' ?>" class="btn btn-primary btn-lg">
                            <?= $is_logged_in ? 'Abrir calendario' : 'Acceder al área de pacientes' ?>
                        </a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="public-footer">
        <div class="container d-flex flex-column flex-md-row justify-content-between gap-2">
            <span>&copy; <?= date('Y') ?> Stephanie Luis Báez</span>
            <span>Psicóloga sanitaria y neuropsicóloga. Santa Cruz de Tenerife.</span>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
