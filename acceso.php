<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helpers.php';

function professional_access_open_db()
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

$error = '';
$login_id = trim((string) ($_POST['login_id'] ?? ($_COOKIE['psicologic_professional_login_id'] ?? '')));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    if (!filter_var($login_id, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Indica tu email profesional y contraseña.';
    } else {
        try {
            $mysqli = professional_access_open_db();
            $stmt = $mysqli->prepare("
                SELECT u.id, u.tenant_id, u.name, u.role, u.password_hash,
                       t.tenant_key, t.status
                FROM users u
                INNER JOIN tenants t ON t.id = u.tenant_id
                WHERE LOWER(u.email) = LOWER(?)
                  AND u.role IN ('superadmin', 'admin', 'reception', 'administration', 'technical')
                ORDER BY u.id
                LIMIT 2
            ");
            $stmt->bind_param('s', $login_id);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $mysqli->close();

            if (count($rows) > 1) {
                $error = 'Este email está asociado a más de un centro. Accede desde la URL específica de tu organización.';
            } elseif (!$rows || empty($rows[0]['password_hash']) || !password_verify($password, $rows[0]['password_hash'])) {
                $error = 'Credenciales incorrectas.';
            } elseif (strtolower((string) ($rows[0]['status'] ?? '')) !== 'active') {
                $error = 'La cuenta no está activa en este momento.';
            } else {
                $user = $rows[0];
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['tenant_id'] = (int) $user['tenant_id'];
                $_SESSION['tenant_key'] = tenant_normalize_key($user['tenant_key']);
                $_SESSION['auth_tenant_id'] = (int) $user['tenant_id'];
                $_SESSION['auth_tenant_key'] = tenant_normalize_key($user['tenant_key']);
                setcookie('psicologic_professional_login_id', $login_id, [
                    'expires' => time() + 180 * 86400,
                    'path' => '/',
                    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);
                header('Location: /' . rawurlencode($_SESSION['tenant_key']) . '/dashboard.php');
                exit;
            }
        } catch (Throwable $exception) {
            $error = 'No se pudo iniciar sesión en este momento.';
        }
    }
}

$official_logo = app_upload_asset_url('uploads/global/sgpraxis-completo-1-transparente.png');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Acceso profesional - SimplyGest Praxis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css?v=<?= (int) @filemtime(__DIR__ . '/css/style.css') ?>" rel="stylesheet">
    <style>
        body { background: #f5f7fa; min-height: 100vh; }
        .sg-nav { position: sticky; top: 0; z-index: 10; background: rgba(255, 255, 255, .86); backdrop-filter: blur(18px); border-bottom: 1px solid rgba(219, 230, 245, .9); }
        .sg-brand-logo { height: 42px; width: auto; display: block; }
        .sg-nav-link { color: #4c5b70; font-weight: 700; text-decoration: none; font-size: .95rem; }
        .sg-nav-link:hover { color: var(--primary-color); }
        .sg-mobile-plans-link { color: var(--primary-color); font-size: .9rem; white-space: nowrap; }
        .professional-access-shell { min-height: calc(100vh - 59px); display: grid; place-items: center; padding: 24px 16px; }
        .professional-access-card { width: min(100%, 460px); background: #fff; border: 1px solid var(--border-color); border-radius: 8px; padding: 30px; box-shadow: 0 18px 48px rgba(31, 42, 55, .08); }
        .professional-access-logo { max-height: 54px; max-width: 250px; object-fit: contain; }
    </style>
</head>
<body>
<nav class="sg-nav">
    <div class="container d-flex align-items-center justify-content-between">
        <a href="./" class="d-inline-flex align-items-center text-decoration-none">
            <img src="<?= htmlspecialchars($official_logo, ENT_QUOTES, 'UTF-8') ?>" alt="SimplyGest Praxis" class="sg-brand-logo">
        </a>
        <div class="d-none d-md-flex align-items-center gap-4">
            <a class="sg-nav-link" href="./">Inicio</a>
            <a class="sg-nav-link" href="./#sectores">Sectores</a>
            <a class="sg-nav-link" href="app-plans.php">Planes</a>
            <a class="btn btn-primary btn-sm" href="signup.php">Probar 15 días</a>
        </div>
        <div class="d-flex d-md-none align-items-center gap-3">
            <a class="sg-nav-link sg-mobile-plans-link" href="app-plans.php">Planes</a>
            <a class="btn btn-primary btn-sm" href="signup.php">Probar</a>
        </div>
    </div>
</nav>
<main class="professional-access-shell">
    <section class="professional-access-card">
        <div class="text-center mb-4">
            <img src="<?= htmlspecialchars($official_logo, ENT_QUOTES, 'UTF-8') ?>" alt="SimplyGest Praxis" class="professional-access-logo mb-3">
            <h1 class="h4 mb-1">Acceso profesional</h1>
            <p class="text-muted mb-0">Entra con la cuenta de tu centro.</p>
        </div>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post">
            <div class="mb-3">
                <label class="form-label" for="professional-email">Email profesional</label>
                <input class="form-control" type="email" id="professional-email" name="login_id" value="<?= htmlspecialchars($login_id, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="professional-password">Contraseña</label>
                <input class="form-control" type="password" id="professional-password" name="password" autocomplete="current-password" required>
            </div>
            <button class="btn btn-primary w-100" type="submit">Entrar</button>
        </form>
        <div class="text-center small mt-4">
            <a href="signup.php">Crear una cuenta de prueba</a>
            <span class="text-muted mx-2">·</span>
            <a href="./">Volver</a>
        </div>
        <div class="alert alert-light border small mt-4 mb-0">
            Los pacientes y clientes deben acceder desde la web o URL de su centro.
        </div>
    </section>
</main>
</body>
</html>
