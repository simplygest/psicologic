<?php

declare(strict_types=1);

chdir(__DIR__);
require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    $token = (string) ($_GET['token'] ?? '');
    $expected = defined('CRON_WEBHOOK_TOKEN') ? (string) CRON_WEBHOOK_TOKEN : '';
    if ($expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit('Error: acceso no autorizado.');
    }
}

// This is a platform worker. It must connect without resolving a tenant from
// the URL; each future send batch will select its tenant explicitly.
if (!defined('CURRENT_TENANT_ID')) {
    define('CURRENT_TENANT_ID', 0);
}
if (!defined('CURRENT_TENANT_KEY')) {
    define('CURRENT_TENANT_KEY', '');
}
if (!defined('CURRENT_TENANT_CUSTOM_DOMAIN')) {
    define('CURRENT_TENANT_CUSTOM_DOMAIN', false);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/verifactu_helpers.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}
echo 'OK: ' . verifactu_pending_count($mysqli) . ' registro(s) VeriFactu pendiente(s).' . PHP_EOL;
