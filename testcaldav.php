<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php?admin=1');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings_helpers.php';
require_once __DIR__ . '/caldav_helpers.php';

$branding = get_public_branding_settings($mysqli);
$app_name = $branding['app_name'] ?? 'SimplyGest Praxis';
$result = null;
$error = '';
$deleted = false;

$defaults = [
    'url' => caldav_default_url(),
    'title' => 'Prueba CalDAV SimplyGest Praxis',
    'description' => 'Evento de prueba creado desde SimplyGest Praxis.',
    'date' => date('Y-m-d', strtotime('+1 day')),
    'time' => '10:00',
    'duration' => '60'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = trim($_POST['url'] ?? caldav_default_url());
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $title = trim($_POST['title'] ?? $defaults['title']);
    $description = trim($_POST['description'] ?? $defaults['description']);
    $date = trim($_POST['date'] ?? $defaults['date']);
    $time = trim($_POST['time'] ?? $defaults['time']);
    $duration = (int) ($_POST['duration'] ?? 60);
    $delete_after_create = isset($_POST['delete_after_create']) && $_POST['delete_after_create'] === '1';

    if ($url === '') {
        $url = caldav_default_url();
    }
    if ($title === '') {
        $title = $defaults['title'];
    }
    if ($duration <= 0) {
        $duration = 60;
    }

    try {
        if ($username === '' || $password === '') {
            throw new \Exception('Indica usuario y contrasena de iCloud.');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \Exception('La URL CalDAV no es valida.');
        }

        $start = new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('Atlantic/Canary'));
        $end = $start->modify('+' . $duration . ' minutes');

        $result = caldav_create_event_in_default_calendar(
            $url,
            $username,
            $password,
            $title,
            $description,
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
            'Atlantic/Canary'
        );
        if ($delete_after_create && !empty($result['event_url'])) {
            $delete_response = caldav_request('DELETE', $result['event_url'], $username, $password);
            if (!in_array($delete_response['status'], [200, 202, 204, 404], true)) {
                throw new \Exception('Evento creado, pero no se pudo borrar. HTTP ' . $delete_response['status'] . '. ' . substr(trim($delete_response['body']), 0, 500));
            }
            $deleted = true;
            $result['delete_status'] = $delete_response['status'];
        }
    } catch (\Exception $e) {
        $error = $e->getMessage();
    }
}

function testcaldav_value($key, $defaults)
{
    return htmlspecialchars($_POST[$key] ?? $defaults[$key] ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Test CalDAV - <?= htmlspecialchars($app_name, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
    <style>
        :root { --primary-color: <?= htmlspecialchars($branding['primary_color'] ?? '#4285f4', ENT_QUOTES, 'UTF-8') ?>; }
        body { min-height: 100vh; background: #f6f7fb; }
        .test-shell { max-width: 860px; margin: 0 auto; padding: 48px 16px; }
    </style>
</head>

<body>
    <main class="test-shell">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1">Test CalDAV iCloud</h1>
                <p class="text-muted mb-0">Crea un evento de prueba en el calendario por defecto. La contraseña no se guarda.</p>
            </div>
            <a class="btn btn-outline-secondary" href="dashboard.php">Volver</a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($result): ?>
            <div class="alert alert-success">
                <strong>Evento creado correctamente.</strong>
                <div>Calendario: <?= htmlspecialchars($result['calendar_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <div>HTTP: <?= htmlspecialchars((string) $result['status'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php if ($deleted): ?>
                    <div>Borrado: correcto (HTTP <?= htmlspecialchars((string) $result['delete_status'], ENT_QUOTES, 'UTF-8') ?>)</div>
                <?php endif; ?>
                <div class="small text-break">Evento: <?= htmlspecialchars($result['event_url'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        <?php endif; ?>

        <div class="card p-4">
            <form method="post" autocomplete="off">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="username">Usuario / Apple ID</label>
                        <input class="form-control" type="email" id="username" name="username" value="<?= testcaldav_value('username', $defaults) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="password">Contraseña específica de app</label>
                        <input class="form-control" type="password" id="password" name="password" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="url">URL CalDAV</label>
                        <input class="form-control" type="url" id="url" name="url" value="<?= testcaldav_value('url', $defaults) ?>" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="title">Título del evento</label>
                        <input class="form-control" type="text" id="title" name="title" value="<?= testcaldav_value('title', $defaults) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="duration">Duración</label>
                        <select class="form-select" id="duration" name="duration">
                            <?php foreach ([30, 60, 90, 120] as $minutes): ?>
                                <option value="<?= $minutes ?>" <?= (string) ($_POST['duration'] ?? $defaults['duration']) === (string) $minutes ? 'selected' : '' ?>><?= $minutes ?> minutos</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="date">Fecha</label>
                        <input class="form-control" type="date" id="date" name="date" value="<?= testcaldav_value('date', $defaults) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="time">Hora</label>
                        <input class="form-control" type="time" id="time" name="time" value="<?= testcaldav_value('time', $defaults) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="description">Descripción</label>
                        <textarea class="form-control" id="description" name="description" rows="3"><?= testcaldav_value('description', $defaults) ?></textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="delete_after_create" name="delete_after_create" value="1" <?= isset($_POST['delete_after_create']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="delete_after_create">Borrar el evento de prueba justo despu&eacute;s de crearlo</label>
                        </div>
                    </div>
                </div>
                <div class="text-end mt-4">
                    <button class="btn btn-primary" type="submit">Crear evento de prueba</button>
                </div>
            </form>
        </div>
    </main>
</body>

</html>
