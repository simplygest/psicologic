<?php

function render_public_nav($mysqli, array $branding, array $options = [])
{
    $app_name = trim((string) ($branding['app_name'] ?? '')) ?: 'SimplyGest Praxis';
    $profile_image_path = '';
    if ((int) ($branding['show_profile_image_public'] ?? 0) === 1 && trim((string) ($branding['profile_image_path'] ?? '')) !== '') {
        $profile_image_path = app_upload_asset_url($branding['profile_image_path']);
    }

    $show_team_public = function_exists('cabinet_public_team_enabled') ? cabinet_public_team_enabled($mysqli) : false;
    $show_prices_public = (int) ($branding['show_prices_public'] ?? 0) === 1;
    $show_contact_public = (int) ($branding['show_contact_public'] ?? 0) === 1;
    $is_logged_in = isset($_SESSION['user_id']);
    $is_admin = in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'], true);
    $show_patient_area = (bool) ($options['show_patient_area'] ?? true);
    $online_booking_enabled = function_exists('online_booking_enabled')
        ? online_booking_enabled($mysqli)
        : ((int) ($branding['online_booking_enabled'] ?? 1) === 1);

    ?>
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
                    <?php if ($show_team_public): ?>
                        <a class="nav-link" href="equipo.php">Equipo</a>
                    <?php endif; ?>
                    <?php if ($show_contact_public): ?>
                        <a class="nav-link" href="contacto.php">Contacto</a>
                    <?php endif; ?>
                    <?php if ($show_prices_public): ?>
                        <a class="nav-link" href="precios.php">Precios</a>
                    <?php endif; ?>
                    <?php if ($show_patient_area && ($online_booking_enabled || $is_admin)): ?>
                        <a class="btn btn-primary btn-sm ms-lg-2" href="<?= $is_logged_in ? 'dashboard.php' : 'login.php' ?>">
                            <?= $is_admin ? 'Panel admin' : ($is_logged_in ? 'Ir a mis citas' : '&Aacute;rea pacientes') ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
    <?php
}
