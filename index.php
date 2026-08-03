<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
chdir(__DIR__);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helpers.php';

function praxis_public_app_page_from_request()
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH);
    $path = str_replace('\\', '/', (string) $path);
    $parts = array_values(array_filter(explode('/', trim($path, '/'))));
    $base = tenant_app_base_path();
    if ($base !== '' && tenant_normalize_key($parts[0] ?? '') === $base) {
        array_shift($parts);
    }

    $first = strtolower((string) ($parts[0] ?? ''));
    $first = trim($first, '/');
    if (in_array($first, ['app-plans.php', 'app-plans'], true)) {
        return 'app-plans.php';
    }

    return '';
}

function praxis_direct_script_from_request()
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH);
    $path = str_replace('\\', '/', (string) $path);
    $parts = array_values(array_filter(explode('/', trim($path, '/'))));
    $base = tenant_app_base_path();
    if ($base !== '' && tenant_normalize_key($parts[0] ?? '') === $base) {
        array_shift($parts);
    }

    $allowed_scripts = [
        'google_oauth_start.php',
        'google_oauth_callback.php',
        'testlivekit.php',
        'testdaily.php',
        'excalidraw_library.php',
        'livekit_call.php',
        'video_call.php',
        'migration.php'
    ];

    $last = strtolower((string) end($parts));
    return in_array($last, $allowed_scripts, true) ? $last : '';
}

$direct_script = praxis_direct_script_from_request();
if ($direct_script !== '') {
    require __DIR__ . '/' . $direct_script;
    exit;
}

$public_app_page = praxis_public_app_page_from_request();
if ($public_app_page !== '') {
    require __DIR__ . '/' . $public_app_page;
    exit;
}

$requested_tenant_key = tenant_key_from_request();
if ($requested_tenant_key === '') {
    if (tenant_request_host_is_platform()) {
        require __DIR__ . '/landing_templates/app.php';
        exit;
    }
    require_once __DIR__ . '/db.php';
} else {
    require_once __DIR__ . '/db.php';
}

$template_key = preg_replace('/[^a-z0-9_-]+/', '', current_tenant_key());
$template_key = $template_key !== '' ? $template_key : 'default';
$template_path = __DIR__ . '/landing_templates/' . $template_key . '.php';
if (!is_file($template_path)) {
    $template_path = __DIR__ . '/landing_templates/default.php';
}

require $template_path;
exit;

