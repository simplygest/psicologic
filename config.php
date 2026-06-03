<?php
// config.php
$local_config_file = __DIR__ . '/config.local.php';
$local_config = file_exists($local_config_file) ? require $local_config_file : [];
$is_installed = file_exists($local_config_file);
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

function psicologic_config_value($key, $fallback = null)
{
    global $local_config;
    return array_key_exists($key, $local_config) ? $local_config[$key] : $fallback;
}

define('DB_HOST', psicologic_config_value('db_host', 'simplygest-mysql-server.mysql.database.azure.com'));
define('DB_PORT', (int) psicologic_config_value('db_port', 3306));
define('DB_USER', psicologic_config_value('db_user', 'pqqxlczroe'));
define('DB_PASS', psicologic_config_value('db_password', '19SwoumJ19!'));
define('DB_NAME', psicologic_config_value('db_name', 'psicologic_db'));
define('DB_SSL', (bool) psicologic_config_value('db_ssl', true));
define('DB_SSL_CERT', psicologic_config_value('db_ssl_cert', 'mysql.pem'));

// Max days in advance to book
define('MAX_BOOKING_DAYS', (int) psicologic_config_value('max_booking_days', 40));

// Token required by cron_reminders.php webhook.
define('CRON_WEBHOOK_TOKEN', psicologic_config_value('cron_webhook_token', 'f6638870ecf94a479a68a88f3b2d03da533425975ea44cc3a62679a09f7756f9'));

// Fastcron API token used to create/delete reminder cronjobs.
define('FASTCRON_API_KEY', psicologic_config_value('fastcron_api_key', 'CHCQEM5VYRPWZUYYI7J1X9BR4083EO9F'));

// Set timezone
date_default_timezone_set(psicologic_config_value('timezone', 'Atlantic/Canary'));
