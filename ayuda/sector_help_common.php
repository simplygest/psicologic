<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$rootDir = dirname(__DIR__);
$configPath = $rootDir . '/config.local.php';
$isInstalled = file_exists($configPath);

$branding = [
    'app_name' => 'SimplyGest Praxis',
    'primary_color' => '#4285f4',
    'profile_image_path' => '',
];

if ($isInstalled) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../login.php');
        exit;
    }

    if (!in_array(($_SESSION['role'] ?? ''), ['admin', 'superadmin'], true)) {
        header('Location: ../dashboard.php');
        exit;
    }

    require_once '../db.php';
    require_once '../settings_helpers.php';

    $branding = get_public_branding_settings($mysqli);
}

$app_name = $branding['app_name'] ?: 'SimplyGest Praxis';
$help = $help ?? [];
$help_title = $help['title'] ?? 'Ayuda';
$help_intro = $help['intro'] ?? '';
$help_kicker = $help['kicker'] ?? 'Ayuda sectorial';
$help_sidebar = $help['sidebar'] ?? [];
$help_sections = $help['sections'] ?? [];
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?= htmlspecialchars($help_title) ?> - <?= htmlspecialchars($app_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=<?= filemtime(__DIR__ . '/../css/style.css') ?>">
    <style>:root { --primary-color: <?= htmlspecialchars($branding['primary_color']) ?>; }</style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>

<body class="help-page">
    <nav class="navbar navbar-expand-lg py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="<?= $isInstalled ? '../dashboard.php' : '../install/' ?>">
                <span><?= htmlspecialchars($app_name) ?></span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <?php if ($isInstalled): ?>
                    <a href="../dashboard.php" class="btn btn-light btn-sm">
                        <i class="bi bi-calendar3"></i> Volver al dashboard
                    </a>
                    <a href="../logout.php" class="btn btn-outline-secondary btn-sm">Salir</a>
                <?php else: ?>
                    <a href="../install/" class="btn btn-primary btn-sm">
                        <i class="bi bi-tools"></i> Instalar app
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <main class="container help-layout">
        <aside class="help-sidebar">
            <div class="help-sidebar-inner">
                <div class="help-sidebar-title">Ayuda</div>
                <?php foreach ($help_sidebar as $item): ?>
                    <a href="<?= htmlspecialchars($item['href'] ?? '#') ?>"><?= htmlspecialchars($item['label'] ?? '') ?></a>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="help-content">
            <div class="help-hero" id="inicio">
                <div class="help-kicker"><?= htmlspecialchars($help_kicker) ?></div>
                <h1><?= htmlspecialchars($help_title) ?></h1>
                <?php if ($help_intro !== ''): ?>
                    <p><?= $help_intro ?></p>
                <?php endif; ?>
            </div>

            <?php foreach ($help_sections as $section): ?>
                <article class="help-section" id="<?= htmlspecialchars($section['id'] ?? '') ?>">
                    <h2><?= htmlspecialchars($section['title'] ?? '') ?></h2>
                    <?= $section['body'] ?? '' ?>
                </article>
            <?php endforeach; ?>
        </section>
    </main>
</body>

</html>
