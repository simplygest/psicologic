<?php
session_start();
require_once 'db.php';
require_once 'settings_helpers.php';
require_once 'payment_helpers.php';
require_once 'cabinet_helpers.php';

$branding = get_public_branding_settings($mysqli);
if ((int) ($branding['show_prices_public'] ?? 0) !== 1) {
    http_response_code(404);
    echo 'Pagina no disponible';
    exit;
}

$app_name = $branding['app_name'];
$profile_image_path = $branding['show_profile_image_public'] ? app_upload_asset_url($branding['profile_image_path']) : '';
$is_logged_in = isset($_SESSION['user_id']);
$is_admin = in_array(($_SESSION['role'] ?? ''), ['admin', 'superadmin', 'reception', 'administration', 'technical'], true);
$online_booking_enabled = online_booking_enabled($mysqli);
$show_patient_area = $online_booking_enabled || $is_admin;
$show_team_public = cabinet_public_team_enabled($mysqli);
$show_contact_public = (int) ($branding['show_contact_public'] ?? 0) === 1;
$public_discount_active = (int) ($branding['discount_show_public'] ?? 0) === 1
    && appointment_discount_is_active($branding, date('Y-m-d'));
$public_discount_end = trim((string) ($branding['discount_period_end_date'] ?? ''));
$services = fetch_appointment_services($mysqli, true);
$bonuses = [];
$public_delivery_mode = 'both';
$public_durations = [60];
$public_service_types = ['individual'];
$bonuses_enabled = 0;
$tenant_id = current_tenant_id();
$stmt = $mysqli->prepare("
    SELECT appointment_delivery_mode, available_session_types, available_session_durations, bonuses_enabled
    FROM payment_settings
    WHERE tenant_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $tenant_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $public_delivery_mode = $row['appointment_delivery_mode'] ?: 'both';
    $public_service_types = array_values(array_filter(array_map('trim', explode(',', $row['available_session_types'] ?? 'individual'))));
    $bonuses_enabled = (int) ($row['bonuses_enabled'] ?? 0);
    $public_durations = [];
    foreach (explode(',', $row['available_session_durations'] ?? '60') as $duration) {
        $duration = (int) trim($duration);
        if ($duration > 0) {
            $public_durations[] = $duration;
        }
    }
    $public_service_types = $public_service_types ?: ['individual'];
    $public_durations = $public_durations ?: [60];
}
if ($bonuses_enabled === 1) {
    $bonuses = fetch_appointment_bonuses($mysqli, true);
}

function public_consultation_label($type)
{
    return $type === 'online' ? 'Online' : 'Presencial';
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Precios - <?= htmlspecialchars($app_name) ?></title>
    <?= favicon_link_tags($branding) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
    <style>:root { --primary-color: <?= htmlspecialchars($branding['primary_color']) ?>; }</style>
</head>

<body class="public-site">
    <nav class="navbar navbar-expand-lg public-navbar">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <?php if ($profile_image_path): ?>
                    <img src="<?= htmlspecialchars($profile_image_path) ?>" alt="" class="brand-avatar">
                <?php endif; ?>
                <span><?= htmlspecialchars($app_name) ?></span>
            </a>
            <div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <a class="nav-link" href="index.php">Inicio</a>
                <?php if ($show_team_public): ?>
                    <a class="nav-link" href="equipo.php">Equipo</a>
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
    </nav>

    <main>
        <section class="landing-section prices-page-section">
            <div class="container">
                <div class="section-heading">
                    <span>Precios</span>
                    <h1>Servicios disponibles</h1>
                </div>
                <?php if ($public_discount_active && $public_discount_end !== ''): ?>
                    <div class="alert alert-info py-2 small">
                        Precios promocionales disponibles hasta el <?= htmlspecialchars(date('d/m/Y', strtotime($public_discount_end))) ?>.
                    </div>
                <?php endif; ?>

                <div class="table-responsive prices-public-table">
                    <?php $visible_prices = 0; ?>
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Servicio</th>
                                <th>Duración</th>
                                <th>Modalidad</th>
                                <th class="text-end">Precio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($services as $service): ?>
                                <?php if (!in_array($service['service_key'], $public_service_types, true)) continue; ?>
                                <?php foreach ($service['options'] as $option): ?>
                                    <?php if (!in_array((int) $option['duration_minutes'], $public_durations, true) || ($public_delivery_mode !== 'both' && $option['consultation_type'] !== $public_delivery_mode)) continue; ?>
                                    <?php $visible_prices++; ?>
                                    <tr>
                                        <td><?= htmlspecialchars($service['name']) ?></td>
                                        <td><?= (int) $option['duration_minutes'] ?> minutos</td>
                                        <td><?= htmlspecialchars(public_consultation_label($option['consultation_type'])) ?></td>
                                        <td class="text-end">
                                            <?php
                                            $discount = $public_discount_active ? (float) ($option['discount_percentage'] ?? 0) : 0;
                                            $base_price = (float) $option['price'];
                                            $discounted_price = round($base_price * (1 - min(100, max(0, $discount)) / 100), 2);
                                            ?>
                                            <?php if ($discount > 0): ?>
                                                <span class="text-muted text-decoration-line-through me-2"><?= htmlspecialchars(format_appointment_price($base_price)) ?> &euro;</span>
                                                <strong class="text-success"><?= htmlspecialchars(format_appointment_price($discounted_price)) ?> &euro;</strong>
                                                <span class="badge bg-success ms-2">-<?= htmlspecialchars(format_appointment_price($discount)) ?>%</span>
                                            <?php else: ?>
                                                <?= htmlspecialchars(format_appointment_price($base_price)) ?> &euro;
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            <?php if ($visible_prices === 0): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">No hay precios disponibles.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($show_patient_area): ?>
                    <div class="mt-4">
                        <a href="<?= $is_logged_in ? 'dashboard.php' : 'login.php' ?>" class="btn btn-primary">
                            <?= $is_admin ? 'Abrir panel admin' : ($is_logged_in ? 'Gestionar mis citas' : 'Pedir cita') ?>
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ($bonuses): ?>
                    <div class="section-heading mt-5">
                        <span>Bonos</span>
                        <h2>Bonos de sesiones individuales</h2>
                    </div>
                    <div class="table-responsive prices-public-table">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Bono</th>
                                    <th>Sesiones</th>
                                    <th class="text-end">Precio</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bonuses as $bonus): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($bonus['name']) ?></td>
                                        <td><?= (int) $bonus['session_count'] ?> sesiones</td>
                                        <td class="text-end"><?= htmlspecialchars(format_appointment_price($bonus['price'])) ?> €</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
</body>

</html>
