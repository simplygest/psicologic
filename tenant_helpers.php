<?php

function tenant_default_key()
{
    return 'stephanie';
}

function tenant_normalize_key($key)
{
    $key = strtolower(trim((string) $key));
    $key = preg_replace('/[^a-z0-9_-]+/', '-', $key);
    $key = trim($key, '-_');
    return $key;
}

function tenant_reserved_path_segments()
{
    return [
        'api',
        'admin',
        'ayuda',
        'css',
        'install',
        'js',
        'phpmailer',
        'redsys',
        'uploads'
    ];
}

function tenant_app_base_path()
{
    $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : 'sgpraxis';
    return tenant_normalize_key($base);
}

function tenant_path_segments()
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH);
    $path = str_replace('\\', '/', (string) $path);
    return array_values(array_filter(explode('/', trim($path, '/'))));
}

function tenant_key_from_path()
{
    $parts = tenant_path_segments();
    if (!$parts) {
        return '';
    }

    $base = tenant_app_base_path();
    if ($base !== '' && tenant_normalize_key($parts[0] ?? '') === $base) {
        array_shift($parts);
    }

    $candidate = tenant_normalize_key($parts[0] ?? '');
    if ($candidate === '' || in_array($candidate, tenant_reserved_path_segments(), true)) {
        return '';
    }

    return $candidate;
}

function tenant_request_is_reserved_path()
{
    $parts = tenant_path_segments();
    $base = tenant_app_base_path();
    if ($base !== '' && tenant_normalize_key($parts[0] ?? '') === $base) {
        array_shift($parts);
    }
    $first = tenant_normalize_key($parts[0] ?? '');
    return $first !== '' && in_array($first, tenant_reserved_path_segments(), true);
}

function tenant_key_from_request()
{
    if (function_exists('psicologic_config_value')) {
        $configured = psicologic_config_value('tenant_key', '');
        if ($configured !== '') {
            return tenant_normalize_key($configured);
        }
    }

    if (!empty($_SERVER['APP_TENANT_KEY'])) {
        return tenant_normalize_key($_SERVER['APP_TENANT_KEY']);
    }

    if (!empty($_GET['tenant'])) {
        return tenant_normalize_key($_GET['tenant']);
    }

    if (PHP_SAPI === 'cli') {
        return tenant_default_key();
    }

    $path_tenant_key = tenant_key_from_path();
    if ($path_tenant_key !== '') {
        return $path_tenant_key;
    }

    if (tenant_request_is_reserved_path() && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['tenant_key'])) {
        return tenant_normalize_key($_SESSION['tenant_key']);
    }

    return '';
}

function tenant_request_expects_json()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return strpos($script, '/api/') !== false || stripos($accept, 'application/json') !== false;
}

function tenant_request_is_install_path()
{
    $parts = tenant_path_segments();
    $base = tenant_app_base_path();
    if ($base !== '' && tenant_normalize_key($parts[0] ?? '') === $base) {
        array_shift($parts);
    }
    $first = tenant_normalize_key($parts[0] ?? '');
    if ($first !== '' && !in_array($first, tenant_reserved_path_segments(), true)) {
        array_shift($parts);
    }
    return tenant_normalize_key($parts[0] ?? '') === 'install';
}

function tenant_abort_request($message, $status = 404)
{
    if (!headers_sent()) {
        http_response_code($status);
        if (tenant_request_expects_json()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Content-Type: text/html; charset=UTF-8');
    }

    $safe_message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>URL inválida</title><style>body{font-family:Inter,Arial,sans-serif;background:#f6f7fb;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;color:#263238}.box{max-width:560px;background:#fff;border:1px solid #e1e5ec;border-radius:10px;padding:28px;box-shadow:0 18px 45px rgba(30,35,50,.08)}h1{margin:0 0 10px;font-size:1.5rem}p{margin:0;color:#66737d;line-height:1.5}</style></head><body><main class="box"><h1>URL inválida</h1><p>' . $safe_message . '</p></main></body></html>';
    exit;
}

function tenant_ensure_table($mysqli)
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS tenants (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_key VARCHAR(80) NOT NULL,
            tenant_name VARCHAR(160) NOT NULL,
            db_name VARCHAR(80) NOT NULL DEFAULT 'sgpraxis',
            app_name VARCHAR(160) NOT NULL DEFAULT 'SimplyGest Praxis',
            sector_texts_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            plan_key VARCHAR(32) NOT NULL DEFAULT 'novus',
            dashboard_config_mode VARCHAR(16) NOT NULL DEFAULT 'advanced',
            public_site_enabled TINYINT(1) NOT NULL DEFAULT 0,
            timezone VARCHAR(64) NOT NULL DEFAULT 'Atlantic/Canary',
            max_booking_days SMALLINT UNSIGNED NOT NULL DEFAULT 40,
            status ENUM('pending', 'installing', 'active', 'suspended', 'disabled') NOT NULL DEFAULT 'active',
            installed_at DATETIME DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_tenants_tenant_key (tenant_key),
            KEY idx_tenants_db_name (db_name),
            KEY idx_tenants_status (status),
            KEY idx_tenants_plan_key (plan_key),
            KEY idx_tenants_sector_texts_key (sector_texts_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function tenant_fetch_by_key($mysqli, $tenant_key)
{
    tenant_ensure_table($mysqli);
    $stmt = $mysqli->prepare("SELECT * FROM tenants WHERE tenant_key = ? LIMIT 1");
    $stmt->bind_param("s", $tenant_key);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function tenant_fetch_by_id($mysqli, $tenant_id)
{
    tenant_ensure_table($mysqli);
    $stmt = $mysqli->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function tenant_bootstrap_current($mysqli)
{
    if (defined('CURRENT_TENANT_ID')) {
        return;
    }

    $tenant_key = tenant_key_from_request();
    if ($tenant_key === '') {
        tenant_abort_request('La URL no contiene un tenant válido. Usa una dirección del tipo /' . tenant_app_base_path() . '/{tenant_key}/.', 404);
    }

    $tenant = tenant_fetch_by_key($mysqli, $tenant_key);

    if (!$tenant) {
        tenant_abort_request('No existe ningún tenant configurado para "' . $tenant_key . '".', 404);
    }

    define('CURRENT_TENANT_ID', (int) ($tenant['id'] ?? 1));
    define('CURRENT_TENANT_KEY', tenant_normalize_key($tenant['tenant_key'] ?? $tenant_key));
    $GLOBALS['current_tenant'] = $tenant;

    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['tenant_id'] = CURRENT_TENANT_ID;
        $_SESSION['tenant_key'] = CURRENT_TENANT_KEY;
    }

    $status = strtolower((string) ($tenant['status'] ?? 'active'));
    if (PHP_SAPI !== 'cli' && in_array($status, ['pending', 'installing'], true) && !tenant_request_is_install_path()) {
        if (tenant_request_expects_json()) {
            if (!headers_sent()) {
                http_response_code(409);
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode([
                'success' => false,
                'install_required' => true,
                'error' => 'Este tenant todavia no esta instalado.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!headers_sent()) {
            header('Location: install/index.php', true, 302);
        }
        exit;
    }
}

function current_tenant_id()
{
    return defined('CURRENT_TENANT_ID') ? (int) CURRENT_TENANT_ID : 1;
}

function current_tenant_key()
{
    return defined('CURRENT_TENANT_KEY') ? CURRENT_TENANT_KEY : tenant_default_key();
}

function current_tenant()
{
    return $GLOBALS['current_tenant'] ?? null;
}

function tenant_payment_settings_where()
{
    return 'tenant_id = ' . current_tenant_id();
}
