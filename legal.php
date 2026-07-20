<?php
session_start();
require_once 'db.php';
require_once 'settings_helpers.php';
require_once 'cabinet_helpers.php';

$branding = get_public_branding_settings($mysqli);
$app_name = $branding['app_name'];
$site_tagline = trim($branding['site_tagline'] ?? '');
$profile_image_path = $branding['show_profile_image_public'] ? app_upload_asset_url($branding['profile_image_path']) : '';
$show_prices_public = (int) ($branding['show_prices_public'] ?? 0) === 1;
$show_contact_public = (int) ($branding['show_contact_public'] ?? 0) === 1;
$show_team_public = cabinet_public_team_enabled($mysqli);
$is_logged_in = isset($_SESSION['user_id']);
$is_admin = in_array($_SESSION['role'] ?? '', ['admin', 'superadmin', 'reception', 'administration', 'technical'], true);
$online_booking_enabled = (int) ($branding['online_booking_enabled'] ?? 1) === 1;
$show_patient_area = $online_booking_enabled || $is_admin;

$legal_owner = trim($branding['legal_owner_name'] ?? '') ?: $app_name;
$legal_nif = trim($branding['legal_nif'] ?? '');
$legal_address = trim($branding['legal_address'] ?? '');
$legal_email = trim($branding['legal_email'] ?? '');
$legal_license = trim($branding['legal_license_number'] ?? '');
$legal_college = trim($branding['legal_professional_college'] ?? '');
$uses_non_technical_cookies = (int) ($branding['legal_uses_non_technical_cookies'] ?? 0) === 1;
$legal_terms_notes = trim($branding['legal_terms_notes'] ?? '');

$settings = [
    'online_payment_enabled' => 0,
    'bonuses_enabled' => 0,
    'appointment_delivery_mode' => 'both'
];
$settings_res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
if ($settings_res && $settings_res->num_rows > 0) {
    $tenant_id = current_tenant_id();
    $res = $mysqli->query("SELECT online_payment_enabled, bonuses_enabled, appointment_delivery_mode FROM payment_settings WHERE tenant_id = $tenant_id");
    if ($row = $res->fetch_assoc()) {
        $settings = array_merge($settings, $row);
    }
}

function legal_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function legal_value($value, $fallback = 'Pendiente de completar')
{
    $value = trim((string) $value);
    return $value !== '' ? legal_h($value) : '<span class="text-muted">' . legal_h($fallback) . '</span>';
}

function legal_safe_basic_html($value)
{
    $html = strip_tags((string) $value, '<p><br><strong><b><em><i><ul><ol><li>');
    return preg_replace('/<([a-z][a-z0-9]*)\b[^>]*>/i', '<$1>', $html);
}

function legal_delivery_text($mode)
{
    if ($mode === 'online') {
        return 'online';
    }
    if ($mode === 'presencial') {
        return 'presencial';
    }
    return 'presencial y online';
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Información legal - <?= legal_h($app_name) ?></title>
    <meta name="description" content="Aviso legal, política de privacidad, política de cookies y condiciones de servicio de <?= legal_h($app_name) ?>.">
    <?= favicon_link_tags($branding) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
    <style>:root { --primary-color: <?= legal_h($branding['primary_color']) ?>; }</style>
</head>

<body class="public-site">
    <nav class="navbar navbar-expand-lg public-navbar">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <?php if ($profile_image_path): ?>
                    <img src="<?= legal_h($profile_image_path) ?>" alt="" class="brand-avatar">
                <?php endif; ?>
                <span><?= legal_h($app_name) ?></span>
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
        <section class="landing-section legal-page-section">
            <div class="container">
                <div class="legal-layout">
                    <aside class="legal-nav">
                        <a href="#privacidad">Política de privacidad</a>
                        <a href="#aviso-legal">Aviso legal</a>
                        <a href="#cookies">Política de cookies</a>
                        <a href="#condiciones">Condiciones del servicio</a>
                    </aside>

                    <div class="legal-content">
                        <div class="section-heading">
                            <span>Información legal</span>
                            <h1>Privacidad, aviso legal y condiciones</h1>
                        </div>
                        <p class="section-copy">Esta página reúne la información legal básica del sitio web, el tratamiento de datos personales, el uso de cookies y las condiciones aplicables a las reservas y servicios ofrecidos.</p>

                        <section class="legal-card" id="privacidad">
                            <h2>Política de privacidad</h2>
                            <div class="legal-data-grid">
                                <div><strong>Responsable</strong><span><?= legal_value($legal_owner) ?></span></div>
                                <div><strong>NIF/CIF</strong><span><?= legal_value($legal_nif) ?></span></div>
                                <div><strong>Domicilio profesional</strong><span><?= legal_value($legal_address) ?></span></div>
                                <div><strong>Email de contacto</strong><span><?= legal_value($legal_email, 'Configura un email legal') ?></span></div>
                            </div>
                            <h3>Datos que se recogen</h3>
                            <p>Podemos tratar los datos que facilites al utilizar la web: nombre, email, teléfono, datos necesarios para crear una cuenta, gestionar citas, enviar comunicaciones relacionadas con reservas, atender consultas, tramitar pagos y, cuando proceda, información asociada a bonos o historial de citas.</p>
                            <h3>Finalidades</h3>
                            <p>Los datos se utilizan para responder solicitudes de información, gestionar el alta y acceso al área de pacientes, reservar, modificar o cancelar citas, enviar recordatorios o comunicaciones operativas, gestionar pagos online y mantener la relación asistencial o administrativa con la consulta.</p>
                            <h3>Legitimación</h3>
                            <p>La base jurídica puede ser el consentimiento de la persona interesada, la ejecución de una relación precontractual o contractual, el cumplimiento de obligaciones legales y el interés legítimo en mantener la seguridad y operativa del sitio.</p>
                            <h3>Conservación</h3>
                            <p>Los datos se conservarán durante el tiempo necesario para atender la finalidad para la que fueron recogidos y, posteriormente, durante los plazos exigidos por normativa sanitaria, fiscal, contable o de responsabilidad profesional.</p>
                            <h3>Derechos</h3>
                            <p>Puedes solicitar el acceso, rectificación, supresión, oposición, limitación del tratamiento y portabilidad de tus datos escribiendo al email indicado en esta página. También puedes presentar una reclamación ante la Agencia Española de Protección de Datos si consideras que el tratamiento no se ajusta a la normativa.</p>
                        </section>

                        <section class="legal-card" id="aviso-legal">
                            <h2>Aviso legal</h2>
                            <p>En cumplimiento de la normativa aplicable a servicios de la sociedad de la información, se facilita la información identificativa del titular de este sitio web.</p>
                            <div class="legal-data-grid">
                                <div><strong>Titular</strong><span><?= legal_value($legal_owner) ?></span></div>
                                <div><strong>NIF/CIF</strong><span><?= legal_value($legal_nif) ?></span></div>
                                <div><strong>Domicilio</strong><span><?= legal_value($legal_address) ?></span></div>
                                <div><strong>Email</strong><span><?= legal_value($legal_email, 'Configura un email legal') ?></span></div>
                                <div><strong>Nº colegiado</strong><span><?= legal_value($legal_license) ?></span></div>
                                <div><strong>Colegio profesional</strong><span><?= legal_value($legal_college) ?></span></div>
                            </div>
                            <p>El acceso y uso del sitio web atribuye la condición de usuario e implica la aceptación de este aviso legal. Los contenidos de la web tienen carácter informativo y no sustituyen una valoración profesional individualizada.</p>
                            <p>El titular se reserva la posibilidad de actualizar, modificar o retirar contenidos del sitio web, así como limitar el acceso a determinadas áreas por motivos técnicos, legales o de seguridad.</p>
                        </section>

                        <section class="legal-card" id="cookies">
                            <h2>Política de cookies</h2>
                            <?php if ($uses_non_technical_cookies): ?>
                                <p>Este sitio puede utilizar cookies técnicas necesarias y cookies no técnicas, como cookies de medición, análisis o servicios de terceros. Las cookies no técnicas solo deberán instalarse cuando exista una base válida de consentimiento o configuración equivalente conforme a la normativa aplicable.</p>
                                <p>Si se incorporan herramientas de analítica, publicidad, píxeles o servicios externos, deberán identificarse sus finalidades, duración, titularidad y forma de gestionar el consentimiento.</p>
                            <?php else: ?>
                                <p>Este sitio está configurado para utilizar únicamente cookies técnicas o necesarias para el funcionamiento de la web, como las de sesión, seguridad, autenticación o gestión de formularios. Estas cookies no tienen finalidad publicitaria ni de seguimiento comercial.</p>
                                <p>Si en el futuro se incorporan cookies de analítica, publicidad o servicios no estrictamente necesarios, se actualizará esta política y, cuando proceda, se solicitará el consentimiento correspondiente.</p>
                            <?php endif; ?>
                            <p>Puedes configurar o eliminar cookies desde las opciones de privacidad de tu navegador.</p>
                        </section>

                        <section class="legal-card" id="condiciones">
                            <h2>Condiciones del servicio</h2>
                            <p>Los servicios ofrecidos en esta web están relacionados con la atención psicológica, la gestión de citas y la comunicación entre la consulta y los pacientes. La disponibilidad de citas, modalidades y profesionales puede variar según la agenda de cada profesional.</p>
                            <p>La modalidad de atención configurada actualmente es <?= legal_h(legal_delivery_text($settings['appointment_delivery_mode'] ?? 'both')) ?>. En el caso de terapia online, la persona usuaria debe disponer de conexión suficiente, un entorno privado y los medios técnicos necesarios para realizar la sesión.</p>
                            <?php if ((int) ($settings['online_payment_enabled'] ?? 0) === 1): ?>
                                <p>Cuando el pago online esté disponible, la reserva podrá requerir o permitir el pago mediante los métodos habilitados en la pasarela. La confirmación del pago quedará sujeta a la respuesta de la entidad bancaria o proveedor de pago.</p>
                            <?php else: ?>
                                <p>Si el pago online no está habilitado, las tarifas y condiciones de pago se comunicarán por los canales habituales de la consulta.</p>
                            <?php endif; ?>
                            <?php if ((int) ($settings['bonuses_enabled'] ?? 0) === 1): ?>
                                <p>Si se ofrecen bonos de sesiones, su compra, uso y saldo disponible se gestionará desde el área correspondiente. El uso de bonos podrá estar sujeto a las condiciones de cancelación, devolución o compensación indicadas por la consulta.</p>
                            <?php endif; ?>
                            <p>Las cancelaciones, cambios de cita, devoluciones o compensaciones se gestionarán conforme a las condiciones indicadas por la consulta y a la normativa aplicable. Cuando una cita ya pagada se cancele, la consulta podrá gestionar la devolución, compensación o creación de un vale según la configuración del sistema y el criterio profesional o administrativo aplicable.</p>
                            <?php if ($legal_terms_notes !== ''): ?>
                                <h3>Condiciones particulares</h3>
                                <div class="legal-custom-html"><?= legal_safe_basic_html($legal_terms_notes) ?></div>
                            <?php endif; ?>
                        </section>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="public-footer">
        <div class="container d-flex flex-column flex-md-row justify-content-between gap-2">
            <span>&copy; <?= date('Y') ?> <?= legal_h($app_name) ?></span>
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
