<?php

function tenant_default_key()
{
    return '';
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
        'uploads',
        'google_oauth_start-php',
        'google_oauth_callback-php',
        'testlivekit-php',
        'livekit_call-php'
    ];
}

function tenant_request_host()
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? ''))));
    $host = preg_replace('/:\d+$/', '', $host);
    return trim($host, '.');
}

function tenant_platform_domains()
{
    $configured = function_exists('psicologic_config_value') ? psicologic_config_value('platform_domains', []) : [];
    if (is_string($configured)) {
        $configured = array_filter(array_map('trim', explode(',', $configured)));
    }
    if (!is_array($configured) || !$configured) {
        $configured = [
            'praxis.simplygest.es',
            'praxis.simplygest.com',
            'praxis.simplygest.cloud',
            'sgpraxiswebapp-bgg2eqfsabgdaxde.spaincentral-01.azurewebsites.net',
            'localhost',
            '127.0.0.1'
        ];
    }

    return array_values(array_unique(array_map(fn($domain) => strtolower(trim((string) $domain)), $configured)));
}

function tenant_request_host_is_platform()
{
    $host = tenant_request_host();
    return $host === '' || in_array($host, tenant_platform_domains(), true);
}

function tenant_primary_platform_origin()
{
    $configured = function_exists('psicologic_config_value') ? trim((string) psicologic_config_value('canonical_base_url', '')) : '';
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $domains = tenant_platform_domains();
    $domain = $domains[0] ?? tenant_request_host();
    return $domain !== '' ? 'https://' . $domain : '';
}

function tenant_signed_state_secret()
{
    if (function_exists('psicologic_config_value')) {
        $secret = trim((string) psicologic_config_value('state_secret', ''));
        if ($secret !== '') {
            return $secret;
        }
    }
    return defined('CRON_WEBHOOK_TOKEN') ? (string) CRON_WEBHOOK_TOKEN : '';
}

function tenant_make_signed_state($tenant_key)
{
    $tenant_key = tenant_normalize_key($tenant_key);
    $secret = tenant_signed_state_secret();
    if ($tenant_key === '' || $secret === '') {
        return '';
    }

    $issued_at = (string) time();
    $nonce = bin2hex(random_bytes(12));
    $payload = $tenant_key . '|' . $issued_at . '|' . $nonce;
    $signature = hash_hmac('sha256', $payload, $secret);

    return $tenant_key . '.' . $issued_at . '.' . $nonce . '.' . $signature;
}

function tenant_key_from_signed_state($state, $max_age_seconds = 1800)
{
    $state = trim((string) $state);
    $parts = explode('.', $state);
    if (count($parts) !== 4) {
        return '';
    }

    [$tenant_key, $issued_at, $nonce, $signature] = $parts;
    $tenant_key = tenant_normalize_key($tenant_key);
    if ($tenant_key === '' || !ctype_digit($issued_at) || !preg_match('/^[a-f0-9]{24}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
        return '';
    }
    if ((int) $issued_at < time() - $max_age_seconds || (int) $issued_at > time() + 300) {
        return '';
    }

    $secret = tenant_signed_state_secret();
    if ($secret === '') {
        return '';
    }
    $expected = hash_hmac('sha256', $tenant_key . '|' . $issued_at . '|' . $nonce, $secret);
    return hash_equals($expected, $signature) ? $tenant_key : '';
}

function tenant_app_base_path()
{
    $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : '';
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

    $first = (string) ($parts[0] ?? '');
    if (preg_match('/\.php$/i', $first)) {
        return '';
    }

    $candidate = tenant_normalize_key($first);
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
    if (PHP_SAPI === 'cli') {
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
        return tenant_default_key();
    }

    $path_tenant_key = tenant_key_from_path();
    if ($path_tenant_key !== '') {
        return $path_tenant_key;
    }

    if (!empty($_SERVER['APP_TENANT_KEY'])) {
        return tenant_normalize_key($_SERVER['APP_TENANT_KEY']);
    }

    if (!empty($_GET['tenant'])) {
        return tenant_normalize_key($_GET['tenant']);
    }

    if (tenant_request_is_reserved_path() && !empty($_GET['state'])) {
        $state_tenant_key = tenant_key_from_signed_state($_GET['state']);
        if ($state_tenant_key !== '') {
            return $state_tenant_key;
        }
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
    if (!function_exists('app_auto_schema_migrations_enabled') || !app_auto_schema_migrations_enabled()) {
        return;
    }

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

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS tenant_domains (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED NOT NULL,
            domain VARCHAR(255) NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_tenant_domains_domain (domain),
            KEY idx_tenant_domains_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function tenant_domain_normalize($domain)
{
    $domain = strtolower(trim((string) $domain));
    $domain = preg_replace('#^https?://#', '', $domain);
    $domain = preg_replace('#/.*$#', '', $domain);
    $domain = preg_replace('/:\d+$/', '', $domain);
    return trim($domain, '.');
}

function tenant_domains_table_exists($mysqli)
{
    $res = $mysqli->query("SHOW TABLES LIKE 'tenant_domains'");
    return $res && $res->num_rows > 0;
}

function tenant_fetch_by_domain($mysqli, $domain)
{
    $domain = tenant_domain_normalize($domain);
    if ($domain === '' || tenant_request_host_is_platform() || !tenant_domains_table_exists($mysqli)) {
        return null;
    }

    $candidates = [$domain];
    if (strpos($domain, 'www.') === 0) {
        $candidates[] = substr($domain, 4);
    } else {
        $candidates[] = 'www.' . $domain;
    }
    $candidates = array_values(array_unique($candidates));
    $placeholders = implode(',', array_fill(0, count($candidates), '?'));
    $types = str_repeat('s', count($candidates));

    $stmt = $mysqli->prepare("
        SELECT t.*
        FROM tenant_domains d
        INNER JOIN tenants t ON t.id = d.tenant_id
        WHERE d.domain IN ($placeholders)
        ORDER BY d.is_primary DESC, d.id ASC
        LIMIT 1
    ");
    $bind_params = [$types];
    foreach ($candidates as $index => $candidate) {
        $bind_params[] = &$candidates[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_params);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc() ?: null;
    if (!$tenant) {
        return null;
    }

    $plan_key = tenant_normalize_key($tenant['plan_key'] ?? '');
    if ($plan_key !== 'summum' && !tenant_plan_feature_enabled($plan_key, 'branding.customDomain')) {
        tenant_abort_request('El dominio personalizado solo esta disponible en el plan Summum.', 403);
    }

    return $tenant;
}

function tenant_primary_custom_domain_for_current($mysqli = null)
{
    if (current_tenant_id() <= 0) {
        return '';
    }
    $tenant = current_tenant();
    $plan_key = tenant_normalize_key($tenant['plan_key'] ?? '');
    if ($plan_key !== 'summum' && !tenant_plan_feature_enabled($plan_key, 'branding.customDomain')) {
        return '';
    }

    $mysqli = $mysqli ?: ($GLOBALS['mysqli'] ?? null);
    if (!$mysqli || !tenant_domains_table_exists($mysqli)) {
        return '';
    }

    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("
        SELECT domain
        FROM tenant_domains
        WHERE tenant_id = ?
        ORDER BY is_primary DESC, id ASC
        LIMIT 1
    ");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $domain = tenant_domain_normalize($stmt->get_result()->fetch_assoc()['domain'] ?? '');
    if ($domain === '' || tenant_request_host_is_platform() && in_array($domain, tenant_platform_domains(), true)) {
        return '';
    }
    return $domain;
}

function tenant_plan_feature_enabled($plan_key, $feature, $default = false)
{
    $plan_key = tenant_normalize_key($plan_key);
    if ($plan_key === '') {
        $plan_key = 'default';
    }

    $allowed = ['default', 'novus', 'magister', 'summum'];
    if (!in_array($plan_key, $allowed, true)) {
        $plan_key = 'default';
    }

    if (!preg_match('/^[a-z0-9_.-]+$/', (string) $feature)) {
        return (bool) $default;
    }

    $path = function_exists('app_global_upload_dir')
        ? app_global_upload_dir('plan-config') . '/' . $plan_key . '.json'
        : __DIR__ . '/uploads/global/plan-config/' . $plan_key . '.json';
    if (!is_file($path)) {
        return (bool) $default;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    $features = is_array($decoded['plan']['features'] ?? null) ? $decoded['plan']['features'] : [];
    return array_key_exists($feature, $features) ? (bool) $features[$feature] : (bool) $default;
}

function tenant_fetch_by_key($mysqli, $tenant_key)
{
    if (function_exists('app_auto_schema_migrations_enabled') && app_auto_schema_migrations_enabled()) {
        tenant_ensure_table($mysqli);
    }
    $stmt = $mysqli->prepare("SELECT * FROM tenants WHERE tenant_key = ? LIMIT 1");
    $stmt->bind_param("s", $tenant_key);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function tenant_fetch_by_id($mysqli, $tenant_id)
{
    if (function_exists('app_auto_schema_migrations_enabled') && app_auto_schema_migrations_enabled()) {
        tenant_ensure_table($mysqli);
    }
    $stmt = $mysqli->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function tenant_clear_authenticated_session()
{
    foreach (['user_id', 'role', 'name', 'auth_tenant_id', 'auth_tenant_key'] as $key) {
        unset($_SESSION[$key]);
    }
}

function tenant_validate_authenticated_session($mysqli)
{
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id'])) {
        return;
    }

    $user_id = (int) ($_SESSION['user_id'] ?? 0);
    if ($user_id <= 0) {
        tenant_clear_authenticated_session();
        return;
    }

    $stmt = $mysqli->prepare("SELECT id, name, role FROM users WHERE tenant_id = ? AND id = ? LIMIT 1");
    if (!$stmt) {
        tenant_clear_authenticated_session();
        return;
    }
    $tenant_id = current_tenant_id();
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) {
        tenant_clear_authenticated_session();
        return;
    }

    $_SESSION['name'] = $user['name'] ?? ($_SESSION['name'] ?? '');
    $_SESSION['role'] = $user['role'] ?? ($_SESSION['role'] ?? '');
    $_SESSION['auth_tenant_id'] = $tenant_id;
    $_SESSION['auth_tenant_key'] = current_tenant_key();
}

function tenant_bootstrap_current($mysqli)
{
    if (defined('CURRENT_TENANT_ID')) {
        return;
    }

    $tenant_key = tenant_key_from_request();
    $tenant = null;
    $custom_domain = false;

    if ($tenant_key !== '') {
        $tenant = tenant_fetch_by_key($mysqli, $tenant_key);
    } else {
        $tenant = tenant_fetch_by_domain($mysqli, tenant_request_host());
        $custom_domain = is_array($tenant);
        $tenant_key = $tenant['tenant_key'] ?? '';
    }

    if ($tenant_key === '') {
        tenant_abort_request('La URL no contiene un tenant válido. Usa una dirección del tipo /' . tenant_app_base_path() . '/{tenant_key}/ o un dominio personalizado configurado.', 404);
    }

    if (!$tenant) {
        tenant_abort_request('No existe ningún tenant configurado para "' . $tenant_key . '".', 404);
    }

    define('CURRENT_TENANT_ID', (int) ($tenant['id'] ?? 1));
    define('CURRENT_TENANT_KEY', tenant_normalize_key($tenant['tenant_key'] ?? $tenant_key));
    define('CURRENT_TENANT_CUSTOM_DOMAIN', $custom_domain);
    $GLOBALS['current_tenant'] = $tenant;

    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        $session_auth_tenant_id = (int) ($_SESSION['auth_tenant_id'] ?? ($_SESSION['tenant_id'] ?? 0));
        $session_auth_tenant_key = tenant_normalize_key($_SESSION['auth_tenant_key'] ?? ($_SESSION['tenant_key'] ?? ''));
        if (!empty($_SESSION['user_id'])
            && (($session_auth_tenant_id > 0 && $session_auth_tenant_id !== CURRENT_TENANT_ID)
                || ($session_auth_tenant_key !== '' && $session_auth_tenant_key !== CURRENT_TENANT_KEY))) {
            tenant_clear_authenticated_session();
        }
        $_SESSION['tenant_id'] = CURRENT_TENANT_ID;
        $_SESSION['tenant_key'] = CURRENT_TENANT_KEY;
        tenant_validate_authenticated_session($mysqli);
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
    return defined('CURRENT_TENANT_ID') ? (int) CURRENT_TENANT_ID : 0;
}

function current_tenant_key()
{
    return defined('CURRENT_TENANT_KEY') ? CURRENT_TENANT_KEY : tenant_default_key();
}

function current_tenant()
{
    return $GLOBALS['current_tenant'] ?? null;
}

function current_tenant_uses_custom_domain()
{
    return defined('CURRENT_TENANT_CUSTOM_DOMAIN') && CURRENT_TENANT_CUSTOM_DOMAIN;
}

function tenant_current_origin()
{
    $forwarded_proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $protocol = ($forwarded_proto === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? null) == 443)
        ? 'https://'
        : 'http://';
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '');
    return $protocol . trim((string) $host);
}

function tenant_current_script_base_path()
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $path = rtrim(dirname($script), '/');
    if (basename($path) === 'api') {
        $path = rtrim(dirname($path), '/');
    }
    return $path === '' || $path === '.' ? '' : $path;
}

function tenant_public_base_url()
{
    $origin = tenant_current_origin();
    if ($origin === 'http://' || $origin === 'https://') {
        return '';
    }

    if (current_tenant_uses_custom_domain()) {
        $path = tenant_current_script_base_path();
        return $origin . ($path !== '' ? $path : '') . '/';
    }

    $custom_domain = tenant_primary_custom_domain_for_current();
    if ($custom_domain !== '') {
        return 'https://' . $custom_domain . '/';
    }

    $base_path = trim(tenant_app_base_path(), '/');
    $tenant_key = trim(current_tenant_key(), '/');
    $path = ($base_path !== '' ? '/' . $base_path : '') . ($tenant_key !== '' ? '/' . rawurlencode($tenant_key) : '');
    return $origin . ($path !== '' ? $path : '') . '/';
}

function tenant_canonical_base_url()
{
    $origin = tenant_primary_platform_origin();
    if ($origin === '') {
        return tenant_public_base_url();
    }

    $base_path = trim(tenant_app_base_path(), '/');
    $tenant_key = trim(current_tenant_key(), '/');
    $path = ($base_path !== '' ? '/' . $base_path : '') . ($tenant_key !== '' ? '/' . rawurlencode($tenant_key) : '');
    return $origin . ($path !== '' ? $path : '') . '/';
}

function tenant_payment_settings_where()
{
    return 'tenant_id = ' . current_tenant_id();
}
