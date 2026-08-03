<?php
session_start();
require_once 'db.php';
require_once 'microsoft_helpers.php';

$state = (string) ($_GET['state'] ?? '');
$signedTenant = function_exists('tenant_key_from_signed_state') ? tenant_key_from_signed_state($state) : '';
$returnUrl = $_SESSION['microsoft_oauth_return_url'] ?? google_tenant_dashboard_url();
try {
    $sessionState = (string) ($_SESSION['microsoft_oauth_state'] ?? '');
    $valid = ($sessionState !== '' && hash_equals($sessionState, $state))
        || ($signedTenant !== '' && $signedTenant === current_tenant_key());
    if (!$valid) throw new Exception('Estado OAuth de Microsoft inválido');
    if (!empty($_GET['error'])) throw new Exception($_GET['error_description'] ?? $_GET['error']);
    if (empty($_GET['code'])) throw new Exception('Microsoft no devolvió código de autorización');
    microsoft_exchange_code($mysqli, $_GET['code']);
    $_SESSION['microsoft_oauth_flash'] = ['status' => 'success', 'message' => 'Microsoft Outlook Calendar se ha conectado correctamente.'];
} catch (Throwable $e) {
    $_SESSION['microsoft_oauth_flash'] = ['status' => 'error', 'message' => $e->getMessage()];
}
unset($_SESSION['microsoft_oauth_state'], $_SESSION['microsoft_oauth_return_url']);
header('Location: ' . $returnUrl);
exit;
