<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helpers.php';
require_once __DIR__ . '/sector_text_helpers.php';

function signup_open_db()
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $mysqli = mysqli_init();
    if (!$mysqli) {
        throw new RuntimeException('No se pudo inicializar MySQL.');
    }
    $flags = 0;
    if (defined('DB_SSL') && DB_SSL) {
        $cert_name = defined('DB_SSL_CERT') ? DB_SSL_CERT : 'mysql.pem';
        $cert_path = preg_match('/^(?:[a-zA-Z]:[\/\\\\]|\/)/', $cert_name)
            ? $cert_name
            : __DIR__ . '/' . ltrim($cert_name, '/\\');
        if (is_file($cert_path)) {
            $mysqli->ssl_set(null, null, $cert_path, null, null);
        }
        $flags = MYSQLI_CLIENT_SSL;
    }
    $mysqli->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, null, $flags);
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function signup_slug($value)
{
    $value = trim((string) $value);
    if (class_exists('Transliterator')) {
        $transliterator = Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        if ($transliterator) {
            $value = $transliterator->transliterate($value);
        }
    } else {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
        $value = strtolower($value);
    }
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim((string) $value, '-');
    return substr($value !== '' ? $value : 'praxis', 0, 52);
}

function signup_unique_tenant_key($mysqli, $company_name)
{
    $base = signup_slug($company_name);
    $candidate = $base;
    $suffix = 1;
    $stmt = $mysqli->prepare('SELECT 1 FROM tenants WHERE tenant_key = ? LIMIT 1');
    while (true) {
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_row()) {
            $stmt->close();
            return $candidate;
        }
        $suffix++;
        $candidate = substr($base, 0, max(8, 52 - strlen((string) $suffix) - 1)) . '-' . $suffix;
    }
}

function signup_verify_recaptcha($token)
{
    if (!defined('RECAPTCHA_SECRET_KEY') || RECAPTCHA_SECRET_KEY === '') {
        return false;
    }
    $payload = http_build_query([
        'secret' => RECAPTCHA_SECRET_KEY,
        'response' => (string) $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    $curl = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($status !== 200 || !is_string($response)) {
        return false;
    }
    $decoded = json_decode($response, true);
    return !empty($decoded['success'])
        && (($decoded['action'] ?? 'signup') === 'signup')
        && (float) ($decoded['score'] ?? 0) >= 0.5;
}

if (empty($_SESSION['signup_csrf'])) {
    $_SESSION['signup_csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$available_sectors = [];
foreach (sector_texts_available() as $sector) {
    $key = (string) ($sector['key'] ?? '');
    if ($key !== '') {
        $available_sectors[$key] = $sector;
    }
}
$signup_sector_order = [
    'psicologia',
    'psicopedagogia',
    'sexologia',
    'logopedia',
    'fisioterapia',
    'fitness',
    'entrenamiento_personal',
    'nutricion',
    'terapia_ocupacional',
    'osteopatia',
    'quiropractica',
    'generico',
];
$sectors = [];
foreach ($signup_sector_order as $key) {
    if (isset($available_sectors[$key])) {
        $sectors[] = $available_sectors[$key];
    }
}
$sector_keys = array_column($sectors, 'key');
$recaptcha_ready = defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY !== ''
    && defined('RECAPTCHA_SECRET_KEY') && RECAPTCHA_SECRET_KEY !== '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $company_name = trim((string) ($_POST['company_name'] ?? ''));
    $sector_key = trim((string) ($_POST['sector_key'] ?? ''));
    $contact_name = trim((string) ($_POST['contact_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $password_confirm = (string) ($_POST['password_confirm'] ?? '');
    $timezone = trim((string) ($_POST['timezone'] ?? 'Europe/Madrid'));
    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        $timezone = 'Europe/Madrid';
    }

    if (!hash_equals($_SESSION['signup_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'La sesión del formulario ha caducado. Recarga la página e inténtalo de nuevo.';
    }
    if (!$recaptcha_ready || !signup_verify_recaptcha($_POST['recaptcha_token'] ?? '')) {
        $errors[] = 'No se pudo validar la protección anti-spam. Vuelve a intentarlo.';
    }
    if ($company_name === '' || mb_strlen($company_name) > 160) {
        $errors[] = 'Indica un nombre de empresa válido.';
    }
    if (!in_array($sector_key, $sector_keys, true)) {
        $errors[] = 'Selecciona un tipo de centro válido.';
    }
    if ($contact_name === '' || mb_strlen($contact_name) > 160) {
        $errors[] = 'Indica la persona de contacto.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Indica una dirección de email válida.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif (!hash_equals($password, $password_confirm)) {
        $errors[] = 'Las contraseñas no coinciden.';
    }

    if (!$errors) {
        $mysqli = null;
        try {
            $mysqli = signup_open_db();
            $stmt = $mysqli->prepare("
                SELECT 1
                FROM users
                WHERE LOWER(email) = ?
                  AND role <> 'patient'
                LIMIT 1
            ");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            if ($stmt->get_result()->fetch_row()) {
                throw new RuntimeException('Ya existe una cuenta profesional asociada a ese email.');
            }
            $stmt->close();

            $stmt = $mysqli->prepare('SELECT 1 FROM tenants WHERE LOWER(signup_email) = ? LIMIT 1');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            if ($stmt->get_result()->fetch_row()) {
                throw new RuntimeException('Ya existe una cuenta o instalación pendiente asociada a ese email.');
            }
            $stmt->close();

            $mysqli->begin_transaction();
            $tenant_key = signup_unique_tenant_key($mysqli, $company_name);
            $plan_key = 'summum';
            $db_name = DB_NAME;
            $stmt = $mysqli->prepare("
                INSERT INTO tenants
                    (tenant_key, tenant_name, signup_email, db_name, app_name, sector_texts_key, plan_key,
                     dashboard_config_mode, public_site_enabled, timezone, status,
                     registration_status, trial_days, installed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'advanced', 0, ?, 'active', 0, 15, NOW())
            ");
            $stmt->bind_param('ssssssss', $tenant_key, $company_name, $email, $db_name, $company_name, $sector_key, $plan_key, $timezone);
            $stmt->execute();
            $tenant_id = (int) $mysqli->insert_id;
            $stmt->close();

            define('CURRENT_TENANT_ID', $tenant_id);
            define('CURRENT_TENANT_KEY', $tenant_key);
            define('CURRENT_TENANT_CUSTOM_DOMAIN', false);
            $GLOBALS['current_tenant'] = [
                'id' => $tenant_id,
                'tenant_key' => $tenant_key,
                'tenant_name' => $company_name,
                'app_name' => $company_name,
                'sector_texts_key' => $sector_key,
                'plan_key' => $plan_key,
                'dashboard_config_mode' => 'advanced',
                'public_site_enabled' => 0,
                'timezone' => $timezone,
                'status' => 'active',
            ];

            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $role = 'superadmin';
            $stmt = $mysqli->prepare("
                INSERT INTO users (tenant_id, name, email, phone, password_hash, role)
                VALUES (?, ?, ?, NULL, ?, ?)
            ");
            $stmt->bind_param('issss', $tenant_id, $contact_name, $email, $password_hash, $role);
            $stmt->execute();
            $user_id = (int) $mysqli->insert_id;
            $stmt->close();

            require_once __DIR__ . '/settings_helpers.php';
            require_once __DIR__ . '/cabinet_helpers.php';
            require_once __DIR__ . '/payment_helpers.php';

            ensure_current_tenant_payment_settings($mysqli);
            $stmt = $mysqli->prepare("
                UPDATE payment_settings
                SET app_name = ?, admin_notification_email = ?, sector_texts_key = ?,
                    dashboard_config_mode = 'advanced', public_site_enabled = 0,
                    primary_color = '#4285f4'
                WHERE tenant_id = ?
            ");
            $stmt->bind_param('sssi', $company_name, $email, $sector_key, $tenant_id);
            $stmt->execute();
            $stmt->close();

            ensure_cabinet_schema($mysqli);
            seed_default_professional($mysqli);
            seed_default_appointment_services($mysqli);

            $mysqli->commit();

            require_once __DIR__ . '/system_mail_helpers.php';
            $tenant_url = rtrim(tenant_primary_platform_origin(), '/') . '/' . rawurlencode($tenant_key) . '/';
            $professional_access_url = rtrim(tenant_primary_platform_origin(), '/') . '/acceso.php';
            $welcome_logo_url = rtrim(tenant_primary_platform_origin(), '/')
                . '/uploads/global/sgpraxis-completo-1-transparente.png';
            $welcome_body = system_mail_minimal_layout(
                'Tu cuenta ya está preparada',
                '<p>Hola ' . htmlspecialchars($contact_name, ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p>Hemos creado el espacio de <strong>' . htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8') . '</strong> en SimplyGest Praxis.</p>'
                . '<p><a href="' . htmlspecialchars($professional_access_url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#4285f4;color:#fff;text-decoration:none;padding:11px 18px">Acceder a SimplyGest Praxis</a></p>'
                . '<p style="font-size:13px;color:#66737d">URL de tu centro: <a href="' . htmlspecialchars($tenant_url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($tenant_url, ENT_QUOTES, 'UTF-8') . '</a></p>',
                $welcome_logo_url
            );
            if (!send_system_email($email, 'Te damos la bienvenida a SimplyGest Praxis', $welcome_body)) {
                error_log('Alta SGPraxis: no se pudo enviar la bienvenida a ' . $email);
            }

            $system_settings = system_mail_settings();
            $notification_body = system_mail_minimal_layout(
                'Nueva cuenta registrada',
                '<p><strong>Centro:</strong> ' . htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p><strong>Contacto:</strong> ' . htmlspecialchars($contact_name, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p><strong>Email:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p><strong>Sector:</strong> ' . htmlspecialchars($sector_key, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p><strong>URL:</strong> <a href="' . htmlspecialchars($tenant_url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($tenant_url, ENT_QUOTES, 'UTF-8') . '</a></p>'
            );
            if (!send_system_email($system_settings['notification_email'], 'Nueva cuenta en SimplyGest Praxis', $notification_body)) {
                error_log('Alta SGPraxis: no se pudo enviar la notificación interna.');
            }

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user_id;
            $_SESSION['role'] = $role;
            $_SESSION['name'] = $contact_name;
            $_SESSION['tenant_id'] = $tenant_id;
            $_SESSION['tenant_key'] = $tenant_key;
            $_SESSION['auth_tenant_id'] = $tenant_id;
            $_SESSION['auth_tenant_key'] = $tenant_key;
            unset($_SESSION['signup_csrf']);
            header('Location: /' . rawurlencode($tenant_key) . '/dashboard.php');
            exit;
        } catch (Throwable $exception) {
            if ($mysqli instanceof mysqli) {
                try {
                    $mysqli->rollback();
                } catch (Throwable $ignored) {
                }
            }
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'No se pudo crear la cuenta en este momento. Vuelve a intentarlo más tarde.';
        } finally {
            if ($mysqli instanceof mysqli) {
                $mysqli->close();
            }
        }
    }
}

$signup_logo_path = 'uploads/global/sgpraxis-completo-1-transparente.png';
$signup_logo = app_upload_asset_url($signup_logo_path);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Prueba SimplyGest Praxis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="css/style.css?v=<?= (int) @filemtime(__DIR__ . '/css/style.css') ?>" rel="stylesheet">
    <style>
        body { background: #f5f7fa; min-height: 100vh; }
        .sg-nav { position: sticky; top: 0; z-index: 10; background: rgba(255, 255, 255, .86); backdrop-filter: blur(18px); border-bottom: 1px solid rgba(219, 230, 245, .9); }
        .sg-brand-logo { height: 42px; width: auto; display: block; }
        .sg-nav-link { color: #4c5b70; font-weight: 700; text-decoration: none; font-size: .95rem; }
        .sg-nav-link:hover { color: var(--primary-color); }
        .sg-mobile-plans-link { color: var(--primary-color); font-size: .9rem; white-space: nowrap; }
        .signup-shell { min-height: calc(100vh - 59px); display: grid; place-items: center; padding: 32px 16px; }
        .signup-layout { width: min(100%, 1040px); }
        .signup-brand { max-height: 54px; max-width: 260px; object-fit: contain; }
        .signup-copy { padding: 24px 20px 24px 0; }
        .signup-card { background: #fff; border: 1px solid var(--border-color); border-radius: 8px; padding: 28px; box-shadow: 0 18px 48px rgba(31, 42, 55, .08); }
        .signup-points { display: grid; gap: 12px; padding: 0; list-style: none; }
        .signup-points i { color: var(--primary-color); margin-right: 8px; }
        @media (max-width: 991.98px) {
            .signup-copy { padding: 0 calc(var(--bs-gutter-x) * .5) 18px; }
            .signup-copy .signup-brand { display: none; }
        }
    </style>
    <?php if ($recaptcha_ready): ?>
        <script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars(RECAPTCHA_SITE_KEY, ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php endif; ?>
</head>
<body>
<nav class="sg-nav">
    <div class="container d-flex align-items-center justify-content-between">
        <a href="./" class="d-inline-flex align-items-center text-decoration-none">
            <img src="<?= htmlspecialchars($signup_logo, ENT_QUOTES, 'UTF-8') ?>" alt="SimplyGest Praxis" class="sg-brand-logo">
        </a>
        <div class="d-none d-md-flex align-items-center gap-4">
            <a class="sg-nav-link" href="./">Inicio</a>
            <a class="sg-nav-link" href="./#sectores">Sectores</a>
            <a class="sg-nav-link" href="app-plans.php">Planes</a>
            <a class="btn btn-primary btn-sm" href="acceso.php">Iniciar sesión</a>
        </div>
        <div class="d-flex d-md-none align-items-center gap-3">
            <a class="sg-nav-link sg-mobile-plans-link" href="app-plans.php">Planes</a>
            <a class="btn btn-primary btn-sm" href="acceso.php">Iniciar sesión</a>
        </div>
    </div>
</nav>
<!-- signup-form-v2 -->
<main class="signup-shell">
    <div class="signup-layout">
        <div class="row g-4 align-items-center">
            <section class="col-lg-5 signup-copy">
                <img src="<?= htmlspecialchars($signup_logo, ENT_QUOTES, 'UTF-8') ?>" alt="SimplyGest Praxis" class="signup-brand mb-4">
                <h1 class="h2 mb-3">Prueba todas las funciones durante 15 días</h1>
                <p class="text-muted mb-4">Prueba el plan Summum durante 15 días</p>
                <ul class="signup-points">
                    <li><i class="bi bi-check-circle-fill"></i>Sin tarjeta y sin permanencia</li>
                    <li><i class="bi bi-check-circle-fill"></i>URL propia para tu centro</li>
                    <li><i class="bi bi-check-circle-fill"></i>Configuración guiada tras el alta</li>
                </ul>
            </section>
            <section class="col-lg-7">
                <div class="signup-card">
                    <h2 class="h4 mb-1">Prueba SimplyGest Praxis</h2>
                    <p class="text-muted mb-4">En unos segundos podrás empezar a gestionar tu espacio</p>
                    <?php if (!$recaptcha_ready): ?>
                        <div class="alert alert-warning small">El registro estará disponible cuando se configure reCAPTCHA para este dominio.</div>
                    <?php endif; ?>
                    <?php if ($errors): ?>
                        <div class="alert alert-danger">
                            <?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" id="signup-form">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['signup_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="recaptcha_token" id="recaptcha-token">
                        <input type="hidden" name="timezone" id="signup-timezone" value="Europe/Madrid">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label" for="company-name">Nombre de la empresa, centro o profesional</label>
                                <input class="form-control" id="company-name" name="company_name" maxlength="160" value="<?= htmlspecialchars($_POST['company_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="sector-key">Sector</label>
                                <select class="form-select" id="sector-key" name="sector_key" required>
                                    <option value="">Selecciona una opción</option>
                                    <?php foreach ($sectors as $sector): ?>
                                        <option value="<?= htmlspecialchars($sector['key'], ENT_QUOTES, 'UTF-8') ?>" <?= ($_POST['sector_key'] ?? '') === $sector['key'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($sector['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="contact-name">Persona de contacto</label>
                                <input class="form-control" id="contact-name" name="contact_name" maxlength="160" value="<?= htmlspecialchars($_POST['contact_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="signup-email">Email</label>
                                <input class="form-control" type="email" id="signup-email" name="email" maxlength="190" autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="signup-password">Contraseña</label>
                                <input class="form-control" type="password" id="signup-password" name="password" minlength="8" autocomplete="new-password" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="signup-password-confirm">Confirmar contraseña</label>
                                <input class="form-control" type="password" id="signup-password-confirm" name="password_confirm" minlength="8" autocomplete="new-password" required>
                            </div>
                            <div class="col-12 d-grid mt-4">
                                <button class="btn btn-primary" type="submit" <?= $recaptcha_ready ? '' : 'disabled' ?>>
                                    Crear mi cuenta de prueba <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>
</main>
<?php if ($recaptcha_ready): ?>
<script>
document.getElementById('signup-timezone').value = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Madrid';
document.getElementById('signup-form').addEventListener('submit', function (event) {
    if (this.dataset.recaptchaReady === '1') return;
    event.preventDefault();
    const form = this;
    grecaptcha.ready(function () {
        grecaptcha.execute(<?= json_encode(RECAPTCHA_SITE_KEY) ?>, { action: 'signup' }).then(function (token) {
            document.getElementById('recaptcha-token').value = token;
            form.dataset.recaptchaReady = '1';
            form.submit();
        });
    });
});
</script>
<?php endif; ?>
</body>
</html>
