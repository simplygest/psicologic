<?php
session_start();

$root = dirname(__DIR__);
require_once $root . '/config.php';
$config_path = $root . '/config.local.php';
$already_installed = file_exists($config_path);
$message = '';
$message_type = 'info';
$defaults = [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_user' => '',
    'db_password' => '',
    'db_name' => 'psicologic',
    'db_ssl' => '0',
    'timezone' => 'Atlantic/Canary',
    'max_booking_days' => '40',
    'app_name' => 'PsicoLogic',
    'admin_name' => 'Administrador',
    'admin_email' => '',
    'admin_password' => ''
];

function install_value($key, $defaults)
{
    return htmlspecialchars($_POST[$key] ?? $defaults[$key] ?? '', ENT_QUOTES, 'UTF-8');
}

function install_base_tables($mysqli)
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin', 'patient') NOT NULL DEFAULT 'patient',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_users_email (email),
            UNIQUE KEY uniq_users_phone (phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS invitations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(64) NOT NULL UNIQUE,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS appointments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            appointment_date DATE NOT NULL,
            appointment_time TIME NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'booked',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_appointments_date_time (appointment_date, appointment_time),
            INDEX idx_appointments_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS closed_days (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            closed_date DATE NOT NULL UNIQUE,
            reason VARCHAR(255) NOT NULL DEFAULT 'Descanso',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function install_ensure_payment_settings_columns($mysqli)
{
    $columns = [
        'min_booking_notice_days' => "ALTER TABLE payment_settings ADD min_booking_notice_days INT UNSIGNED NOT NULL DEFAULT 2",
        'max_booking_notice_days' => "ALTER TABLE payment_settings ADD max_booking_notice_days INT UNSIGNED NOT NULL DEFAULT 40",
        'appointment_start_time' => "ALTER TABLE payment_settings ADD appointment_start_time TIME NOT NULL DEFAULT '10:00:00'",
        'appointment_end_time' => "ALTER TABLE payment_settings ADD appointment_end_time TIME NOT NULL DEFAULT '19:00:00'",
        'break_start_time' => "ALTER TABLE payment_settings ADD break_start_time TIME DEFAULT '15:00:00'",
        'break_end_time' => "ALTER TABLE payment_settings ADD break_end_time TIME DEFAULT '16:00:00'",
        'available_weekdays' => "ALTER TABLE payment_settings ADD available_weekdays VARCHAR(32) NOT NULL DEFAULT '1,2,3,4,5'",
        'appointment_delivery_mode' => "ALTER TABLE payment_settings ADD appointment_delivery_mode ENUM('both', 'presencial', 'online') NOT NULL DEFAULT 'both'",
        'available_session_types' => "ALTER TABLE payment_settings ADD available_session_types VARCHAR(32) NOT NULL DEFAULT 'individual'",
        'available_session_durations' => "ALTER TABLE payment_settings ADD available_session_durations VARCHAR(16) NOT NULL DEFAULT '60'"
    ];

    foreach ($columns as $column => $sql) {
        $res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE '$column'");
        if ($res && $res->num_rows === 0) {
            $mysqli->query($sql);
        }
    }
}

function install_write_config($path, $settings)
{
    $content = "<?php\nreturn " . var_export($settings, true) . ";\n";
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new \Exception('No se pudo escribir config.local.php. Revisa permisos de escritura.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = [
        'db_host' => trim($_POST['db_host'] ?? ''),
        'db_port' => (int) ($_POST['db_port'] ?? 3306),
        'db_user' => trim($_POST['db_user'] ?? ''),
        'db_password' => (string) ($_POST['db_password'] ?? ''),
        'db_name' => trim($_POST['db_name'] ?? ''),
        'db_ssl' => isset($_POST['db_ssl']),
        'db_ssl_cert' => 'mysql.pem',
        'timezone' => trim($_POST['timezone'] ?? 'Atlantic/Canary'),
        'max_booking_days' => max(1, (int) ($_POST['max_booking_days'] ?? 40)),
        'cron_webhook_token' => defined('CRON_WEBHOOK_TOKEN') && CRON_WEBHOOK_TOKEN !== '' ? CRON_WEBHOOK_TOKEN : bin2hex(random_bytes(32)),
        'fastcron_api_key' => defined('FASTCRON_API_KEY') ? FASTCRON_API_KEY : ''
    ];
    $app_name = trim($_POST['app_name'] ?? 'PsicoLogic') ?: 'PsicoLogic';
    $admin_name = trim($_POST['admin_name'] ?? 'Administrador') ?: 'Administrador';
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_password = (string) ($_POST['admin_password'] ?? '');

    try {
        if (!$settings['db_host'] || !$settings['db_user'] || !$settings['db_name']) {
            throw new \Exception('Indica host, usuario y nombre de base de datos.');
        }
        if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
            throw new \Exception('Indica un email de administrador valido.');
        }
        if (strlen($admin_password) < 6) {
            throw new \Exception('La contraseña del admin debe tener al menos 6 caracteres.');
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $test = mysqli_init();
        if (!$test) {
            throw new \Exception('No se pudo inicializar mysqli.');
        }
        $flags = 0;
        if ($settings['db_ssl']) {
            $cert_path = ($_SERVER['DOCUMENT_ROOT'] ?? $root) . '/mysql.pem';
            if (file_exists($cert_path)) {
                $test->ssl_set(null, null, $cert_path, null, null);
            }
            $flags = MYSQLI_CLIENT_SSL;
        }
        $test->real_connect($settings['db_host'], $settings['db_user'], $settings['db_password'], $settings['db_name'], $settings['db_port'], null, $flags);
        $test->set_charset('utf8mb4');

        require_once $root . '/mail_helpers.php';
        require_once $root . '/settings_helpers.php';
        require_once $root . '/payment_helpers.php';

        install_base_tables($test);
        ensure_admin_notification_email_column($test);
        ensure_branding_columns($test);
        ensure_appointment_payment_columns($test);
        ensure_appointment_services_tables($test);
        ensure_bonus_tables($test);
        ensure_payment_attempts_table($test);
        ensure_payment_settings_price_columns($test);
        install_ensure_payment_settings_columns($test);

        $stmt = $test->prepare("
            UPDATE payment_settings
            SET app_name = ?, admin_notification_email = ?, max_booking_notice_days = ?
            WHERE id = 1
        ");
        $stmt->bind_param('ssi', $app_name, $admin_email, $settings['max_booking_days']);
        $stmt->execute();

        $hash = password_hash($admin_password, PASSWORD_DEFAULT);
        $role = 'admin';
        $stmt = $test->prepare("
            INSERT INTO users (name, email, phone, password_hash, role)
            VALUES (?, ?, NULL, ?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), role = 'admin'
        ");
        $stmt->bind_param('ssss', $admin_name, $admin_email, $hash, $role);
        $stmt->execute();

        install_write_config($config_path, $settings);
        $already_installed = true;
        $message_type = 'success';
        $message = 'Instalacion completada. Ya puedes iniciar sesion con el usuario admin.';
    } catch (\Exception $e) {
        $message_type = 'danger';
        $message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Instalacion - PsicoLogic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>

<body class="d-flex align-items-center justify-content-center" style="min-height: 100vh;">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card p-4">
                    <div class="mb-4">
                        <h2 style="color: var(--primary-color);">Instalacion inicial</h2>
                        <p class="text-muted mb-0">Configura la conexion a la base de datos y el primer usuario administrador.</p>
                    </div>

                    <?php if ($message): ?>
                        <div class="alert alert-<?= htmlspecialchars($message_type) ?>"><?= htmlspecialchars($message) ?></div>
                    <?php endif; ?>

                    <?php if ($already_installed && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
                        <div class="alert alert-info">La app ya tiene un archivo config.local.php. Para reinstalar, elimina ese archivo manualmente.</div>
                        <a class="btn btn-primary" href="../login.php">Ir al login</a>
                    <?php elseif ($message_type === 'success'): ?>
                        <a class="btn btn-primary" href="../login.php">Ir al login</a>
                    <?php else: ?>
                        <form method="post">
                            <h5 class="mb-3">Base de datos</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-8">
                                    <label class="form-label" for="db_host">Host</label>
                                    <input class="form-control" id="db_host" name="db_host" value="<?= install_value('db_host', $defaults) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="db_port">Puerto</label>
                                    <input class="form-control" id="db_port" name="db_port" type="number" value="<?= install_value('db_port', $defaults) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="db_name">Nombre BD</label>
                                    <input class="form-control" id="db_name" name="db_name" value="<?= install_value('db_name', $defaults) ?>" required>
                                    <div class="form-text">Cada psic&oacute;logo debe usar su propia base de datos, por ejemplo psicologic_stephanie.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="db_user">Usuario BD</label>
                                    <input class="form-control" id="db_user" name="db_user" value="<?= install_value('db_user', $defaults) ?>" required>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label" for="db_password">Contraseña BD</label>
                                    <input class="form-control" id="db_password" name="db_password" type="password" value="<?= install_value('db_password', $defaults) ?>">
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" id="db_ssl" name="db_ssl" <?= isset($_POST['db_ssl']) || $defaults['db_ssl'] === '1' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="db_ssl">Usar SSL</label>
                                    </div>
                                </div>
                            </div>

                            <h5 class="mb-3">Consulta y admin</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label" for="app_name">Titulo de la app</label>
                                    <input class="form-control" id="app_name" name="app_name" value="<?= install_value('app_name', $defaults) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="timezone">Zona horaria</label>
                                    <input class="form-control" id="timezone" name="timezone" value="<?= install_value('timezone', $defaults) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="admin_name">Nombre admin</label>
                                    <input class="form-control" id="admin_name" name="admin_name" value="<?= install_value('admin_name', $defaults) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="admin_email">Email admin</label>
                                    <input class="form-control" id="admin_email" name="admin_email" type="email" value="<?= install_value('admin_email', $defaults) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="admin_password">Contraseña admin</label>
                                    <input class="form-control" id="admin_password" name="admin_password" type="password" minlength="6" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="max_booking_days">Maximo dias reserva</label>
                                    <input class="form-control" id="max_booking_days" name="max_booking_days" type="number" min="1" value="<?= install_value('max_booking_days', $defaults) ?>" required>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button class="btn btn-primary" type="submit">Probar conexion e instalar</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
