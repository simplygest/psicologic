<?php
// config.php
require_once __DIR__ . '/app_paths.php';

$local_config_file = __DIR__ . '/config.local.php';
$base_config = file_exists($local_config_file) ? require $local_config_file : [];
$local_config = is_array($base_config) ? $base_config : [];

function psicologic_config_value($key, $fallback = null)
{
    global $local_config;
    return array_key_exists($key, $local_config) ? $local_config[$key] : $fallback;
}

$tenant_config_file = app_tenant_config_file('config.php');
$legacy_tenant_config_file = app_tenant_config_file('tenant-config.php');
if (!file_exists($tenant_config_file) && file_exists($legacy_tenant_config_file)) {
    $tenant_config_file = $legacy_tenant_config_file;
}
$tenant_config = file_exists($tenant_config_file) ? require $tenant_config_file : [];
if (is_array($tenant_config)) {
    $local_config = array_replace($local_config, $tenant_config);
}

$is_installed = file_exists($tenant_config_file) || array_key_exists('db_name', $local_config);
$script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$is_install_path = strpos($script_name, '/install/') !== false || basename($script_name) === 'install';

if (!$is_installed && PHP_SAPI !== 'cli' && !$is_install_path) {
    $install_url = rtrim(dirname($script_name), '/\\');
    if (basename($install_url) === 'api') {
        $install_url = rtrim(dirname($install_url), '/\\');
    }
    $install_url = ($install_url ? $install_url : '') . '/install/';
    header('Location: ' . $install_url);
    exit;
}

define('DB_HOST', psicologic_config_value('db_host', 'simplygest-mysql-server.mysql.database.azure.com'));
define('DB_PORT', (int) psicologic_config_value('db_port', 3306));
define('DB_USER', psicologic_config_value('db_user', 'pqqxlczroe'));
define('DB_PASS', psicologic_config_value('db_password', '19SwoumJ19!'));
define('DB_NAME', psicologic_config_value('db_name', 'sgpraxis'));
define('DB_SSL', (bool) psicologic_config_value('db_ssl', true));
define('DB_SSL_CERT', psicologic_config_value('db_ssl_cert', 'mysql.pem'));
define('APP_BASE_PATH', trim((string) psicologic_config_value('app_base_path', 'sgpraxis'), '/'));

// Max days in advance to book
define('MAX_BOOKING_DAYS', (int) psicologic_config_value('max_booking_days', 40));

// Token required by cron_reminders.php webhook.
define('CRON_WEBHOOK_TOKEN', psicologic_config_value('cron_webhook_token', 'f6638870ecf94a479a68a88f3b2d03da533425975ea44cc3a62679a09f7756f9'));

// Fastcron API token used to create/delete reminder cronjobs.
define('FASTCRON_API_KEY', psicologic_config_value('fastcron_api_key', 'CHCQEM5VYRPWZUYYI7J1X9BR4083EO9F'));

// URLME API token used to shorten patient-facing links. Leave empty to keep full URLs.
define('URLME_API_KEY', psicologic_config_value('urlme_api_key', '367939f724112f6a4bc067301fd4a8d4cc4e3d618ca6390c857df610b81c2c8a'));

// WorkoutX Exercise API token used for external exercise media lookup.
define('WORKOUTX_API_KEY', psicologic_config_value('workoutx_api_key', 'wx_f0a9a3231d3fb410b9cb003cec9df23f948deb2f5454cda96c52b833'));

// Set timezone
date_default_timezone_set(psicologic_config_value('timezone', 'Atlantic/Canary'));
