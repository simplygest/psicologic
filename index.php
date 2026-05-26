<?php
session_start();
require_once 'db.php';
require_once 'settings_helpers.php';
$branding = get_public_branding_settings($mysqli);
$app_name = $branding['app_name'];
$profile_image_path = $branding['show_profile_image_public'] ? $branding['profile_image_path'] : '';
$is_logged_in = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Stephanie Luis Baez - Psicologa sanitaria y neuropsicologa</title>
    <meta name="description" content="Consulta de psicologia sanitaria y neuropsicologia en Santa Cruz de Tenerife. Atencion a adultos, infancia y adolescencia.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
</head>

<body class="public-site">
    <nav class="navbar navbar-expand-lg public-navbar py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <?php if ($profile_image_path): ?>
                    <img src="<?= htmlspecialchars($profile_image_path) ?>" alt="" class="brand-avatar">
                <?php endif; ?>
                <span>Stephanie Luis Baez</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNav" aria-controls="publicNav" aria-expanded="false" aria-label="Abrir menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="publicNav">
                <div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                    <a class="nav-link" href="#servicios">Servicios</a>
                    <a class="nav-link" href="#experiencia">Experiencia</a>
                    <a class="nav-link" href="#formacion">Formacion</a>
                    <a class="nav-link" href="#consulta">Consulta</a>
                    <a class="btn btn-primary btn-sm ms-lg-2" href="<?= $is_logged_in ? 'dashboard.php' : 'login.php' ?>">
                        <?= $is_logged_in ? 'Ir a mis citas' : 'Area pacientes' ?>
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
                        <p class="landing-kicker">Psicologia sanitaria y neuropsicologia en Santa Cruz de Tenerife</p>
                        <h1>Stephanie Luis Baez</h1>
                        <p class="landing-lead">Acompanamiento psicologico basado en evidencia, con atencion personalizada para adultos, infancia y adolescencia.</p>
                        <div class="landing-actions">
                            <a href="<?= $is_logged_in ? 'dashboard.php' : 'login.php' ?>" class="btn btn-primary btn-lg">
                                <?= $is_logged_in ? 'Gestionar mis citas' : 'Pedir cita' ?>
                            </a>
                            <a href="#servicios" class="btn btn-outline-secondary btn-lg">Ver servicios</a>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="landing-portrait">
                            <?php if ($profile_image_path): ?>
                                <img src="<?= htmlspecialchars($profile_image_path) ?>" alt="Stephanie Luis Baez">
                            <?php else: ?>
                                <div class="landing-portrait-placeholder">
                                    <span>SLB</span>
                                </div>
                            <?php endif; ?>
                            <div class="landing-portrait-caption">
                                <strong>N. colegiada T-04491</strong>
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
                    <h2>Areas de intervencion</h2>
                </div>
                <div class="service-grid">
                    <article class="service-card">
                        <h3>Psicologia general sanitaria</h3>
                        <p>Evaluacion e intervencion psicologica adaptada a las necesidades de cada persona, con un enfoque practico y basado en la evidencia.</p>
                    </article>
                    <article class="service-card">
                        <h3>Neuropsicologia</h3>
                        <p>Valoracion, estimulacion y rehabilitacion cognitiva en dificultades de memoria, atencion, funciones ejecutivas y dano cerebral adquirido.</p>
                    </article>
                    <article class="service-card">
                        <h3>Infancia y adolescencia</h3>
                        <p>Atencion a dificultades emocionales, conductuales y del neurodesarrollo, incluyendo TDAH, autismo y apoyo a familias.</p>
                    </article>
                    <article class="service-card">
                        <h3>Bienestar emocional</h3>
                        <p>Acompanamiento en ansiedad, depresion, autoestima, duelo, estres, adicciones y problemas relacionales.</p>
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
                            <h2>Trayectoria clinica y comunitaria</h2>
                        </div>
                        <p class="section-copy">Stephanie Luis Baez es psicologa sanitaria y neuropsicologa. Su experiencia incluye trabajo en hospitales, clinicas, gabinetes de psicologia, programas comunitarios y recursos especializados.</p>
                    </div>
                    <div class="col-lg-7">
                        <div class="timeline-list">
                            <div>
                                <strong>Neurocentro Tenerife</strong>
                                <span>Psicologa y neuropsicologa en la actualidad.</span>
                            </div>
                            <div>
                                <strong>Fundacion Instituto Spiral</strong>
                                <span>Intervencion neuropsicologica y apoyo en procesos de rehabilitacion.</span>
                            </div>
                            <div>
                                <strong>Ayuntamiento de San Cristobal de La Laguna</strong>
                                <span>Experiencia en programas de atencion comunitaria.</span>
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
                    <span>Formacion</span>
                    <h2>Especializacion academica</h2>
                </div>
                <div class="education-grid">
                    <div>Grado en Psicologia por la Universidad de La Laguna.</div>
                    <div>Master en Psicologia General Sanitaria por la Universidad Autonoma de Madrid.</div>
                    <div>Master en Neuropsicologia Clinica por la Universidad Complutense de Madrid.</div>
                    <div>Experta universitaria en Trastornos de la Personalidad por la UDIMA.</div>
                    <div>Experta universitaria en Psicopatologia Clinica Infanto-Juvenil por la UDIMA.</div>
                    <div>Preparacion PIR en Academia APIR.</div>
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
                        <p class="section-copy mb-0">Si ya tienes cuenta, puedes acceder al area de pacientes para consultar disponibilidad, reservar o cancelar una cita.</p>
                    </div>
                    <div class="col-lg-5 text-lg-end">
                        <a href="<?= $is_logged_in ? 'dashboard.php' : 'login.php' ?>" class="btn btn-primary btn-lg">
                            <?= $is_logged_in ? 'Abrir calendario' : 'Acceder al area de pacientes' ?>
                        </a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="public-footer">
        <div class="container d-flex flex-column flex-md-row justify-content-between gap-2">
            <span>&copy; <?= date('Y') ?> Stephanie Luis Baez</span>
            <span>Psicologa sanitaria y neuropsicologa. Santa Cruz de Tenerife.</span>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
