<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/db.php';

$template_key = preg_replace('/[^a-z0-9_-]+/', '', current_tenant_key());
$template_key = $template_key !== '' ? $template_key : 'default';
$template_path = __DIR__ . '/landing_templates/' . $template_key . '.php';
if (!is_file($template_path)) {
    $template_path = __DIR__ . '/landing_templates/default.php';
}

require $template_path;
exit;

