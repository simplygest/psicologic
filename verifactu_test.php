<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$providedToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$expectedToken = trim((string) psicologic_config_value('migration_token', ''));
if ($expectedToken === '' && defined('CRON_WEBHOOK_TOKEN')) {
    $expectedToken = trim((string) CRON_WEBHOOK_TOKEN);
}
if ($providedToken === '' || $expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('No autorizado.');
}

$tenantId = max(0, (int) ($_GET['tenant'] ?? $_POST['tenant'] ?? 0));
if ($tenantId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Debes indicar un tenant válido mediante ?tenant=ID.');
}

define('CURRENT_TENANT_ID', $tenantId);
define('CURRENT_TENANT_KEY', '');
define('CURRENT_TENANT_CUSTOM_DOMAIN', false);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/verifactu_helpers.php';

$tenantStmt = $mysqli->prepare("
    SELECT id, COALESCE(NULLIF(TRIM(app_name), ''), NULLIF(TRIM(tenant_name), ''), CONCAT('Tenant ', id)) AS name
    FROM tenants
    WHERE id = ?
    LIMIT 1
");
$tenantStmt->bind_param('i', $tenantId);
$tenantStmt->execute();
$tenant = $tenantStmt->get_result()->fetch_assoc();
if (!$tenant) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('No existe el tenant indicado.');
}

$logs = [];
$batchResult = null;
$requestXml = '';
$responseXml = '';
$pendingCount = 0;

function verifactu_test_log(array &$logs, string $status, string $message): void
{
    $logs[] = ['status' => $status, 'message' => $message];
}

function verifactu_test_mark_error(mysqli $mysqli, int $tenantId, int $invoiceId, string $message): void
{
    $stmt = $mysqli->prepare("
        UPDATE movim
        SET verifactu_estado = 'error', verifactu_error = ?
        WHERE tenant_id = ? AND id = ?
    ");
    $stmt->bind_param('sii', $message, $tenantId, $invoiceId);
    $stmt->execute();
}

$countStmt = $mysqli->prepare("
    SELECT COUNT(*) AS total
    FROM movim
    WHERE tenant_id = ? AND tipo_movim = 'factura' AND verifactu_estado = 'pendiente'
");
$countStmt->bind_param('i', $tenantId);
$countStmt->execute();
$pendingCount = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pendingStmt = $mysqli->prepare("
        SELECT id, numero_factura
        FROM movim
        WHERE tenant_id = ? AND tipo_movim = 'factura' AND verifactu_estado = 'pendiente'
        ORDER BY id ASC
    ");
    $pendingStmt->bind_param('i', $tenantId);
    $pendingStmt->execute();
    $pendingInvoices = $pendingStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    verifactu_test_log($logs, 'ok', 'Tenant localizado: ' . (string) $tenant['name'] . ' (#' . $tenantId . ').');
    verifactu_test_log($logs, 'info', 'Entorno forzado: pruebas AEAT.');
    verifactu_test_log($logs, 'info', count($pendingInvoices) . ' factura(s) pendiente(s) localizada(s).');
    $recordIds = [];

    foreach ($pendingInvoices as $pendingInvoice) {
        $invoiceId = (int) $pendingInvoice['id'];
        $invoiceNumber = (string) $pendingInvoice['numero_factura'];
        try {
            $mysqli->begin_transaction();
            $result = verifactu_enqueue_invoice($mysqli, $invoiceId, ['force_test' => true]);
            $mysqli->commit();
            $recordId = (int) ($result['id'] ?? $result['record_id'] ?? 0);
            if ($recordId > 0) {
                $recordIds[] = $recordId;
            }
            verifactu_test_log(
                $logs,
                'ok',
                $invoiceNumber . ': precheck correcto, huella y XML generados'
                    . (!empty($result['existing']) ? ' (registro de pruebas ya existente).' : '.')
            );
        } catch (Throwable $e) {
            try {
                $mysqli->rollback();
            } catch (Throwable $ignored) {
            }
            $message = $e->getMessage();
            verifactu_test_mark_error($mysqli, $tenantId, $invoiceId, $message);
            verifactu_test_log($logs, 'error', $invoiceNumber . ': ' . $message);
        }
    }

    if ($recordIds) {
        try {
            verifactu_test_log($logs, 'info', 'Formando bloque SOAP con ' . count($recordIds) . ' registro(s).');
            $batchResult = verifactu_send_generated_records($mysqli, $tenantId, $recordIds, 'test');
            $requestXml = (string) ($batchResult['request_xml'] ?? '');
            $responseXml = (string) ($batchResult['response_xml'] ?? '');
            verifactu_test_log(
                $logs,
                ($batchResult['errors'] ?? 0) > 0 ? 'error' : 'ok',
                'Respuesta AEAT: ' . (int) ($batchResult['accepted'] ?? 0) . ' aceptada(s), '
                    . (int) ($batchResult['errors'] ?? 0) . ' con error. HTTP '
                    . (int) ($batchResult['http_code'] ?? 0) . '.'
            );
            foreach (($batchResult['details'] ?? []) as $detail) {
                $message = (string) ($detail['invoice_number'] ?? '')
                    . ': ' . ((bool) ($detail['accepted'] ?? false) ? 'enviada' : 'error');
                if (($detail['code'] ?? '') !== '') {
                    $message .= ' [' . (string) $detail['code'] . ']';
                }
                if (($detail['message'] ?? '') !== '') {
                    $message .= ' ' . (string) $detail['message'];
                }
                verifactu_test_log($logs, !empty($detail['accepted']) ? 'ok' : 'error', $message);
            }
        } catch (Throwable $e) {
            verifactu_test_log($logs, 'error', 'Error al enviar el bloque: ' . $e->getMessage());
        }
    } elseif (!$pendingInvoices) {
        verifactu_test_log($logs, 'info', 'No hay facturas pendientes que procesar.');
    } else {
        verifactu_test_log($logs, 'error', 'Ninguna factura superó el precheck; no se realizó el envío.');
    }

    if (($_POST['download'] ?? '') === 'request' && $requestXml !== '') {
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="verifactu-test-request-tenant-' . $tenantId . '.xml"');
        echo $requestXml;
        exit;
    }
}

$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Prueba VeriFactu · SimplyGest Praxis</title>
  <style>
    :root { --primary:#7563a8; --ok:#16794b; --error:#b42318; --info:#175cd3; }
    * { box-sizing:border-box; }
    body { margin:0; background:#f5f7fa; color:#20242c; font:15px/1.45 system-ui,-apple-system,Segoe UI,sans-serif; }
    main { width:min(1180px,calc(100% - 32px)); margin:32px auto; }
    .panel { background:#fff; border:1px solid #dfe3e8; border-radius:8px; padding:24px; margin-bottom:20px; }
    h1,h2 { margin:0 0 8px; } h1 { font-size:25px; } h2 { font-size:18px; }
    .muted { color:#667085; }
    .warning { padding:12px 14px; border-left:4px solid #f79009; background:#fffaeb; margin:18px 0; }
    .summary { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin:16px 0; }
    .summary div { padding:14px; border:1px solid #e4e7ec; border-radius:6px; background:#f8f9fb; }
    button { border:1px solid var(--primary); border-radius:6px; padding:10px 14px; cursor:pointer; background:var(--primary); color:#fff; font-weight:650; }
    .log { list-style:none; margin:0; padding:0; }
    .log li { padding:9px 11px; border-bottom:1px solid #edf0f3; }
    .log .ok { color:var(--ok); } .log .error { color:var(--error); } .log .info { color:var(--info); }
    details { margin-top:18px; }
    summary { cursor:pointer; font-weight:700; }
    pre { max-height:500px; overflow:auto; white-space:pre-wrap; word-break:break-word; background:#101828; color:#d0d5dd; padding:16px; border-radius:6px; font:12px/1.45 Consolas,monospace; }
    @media (max-width:700px) { main { width:min(100% - 20px,1180px); margin:10px auto; } .panel { padding:16px; } .summary { grid-template-columns:1fr; } }
  </style>
</head>
<body>
<main>
  <section class="panel">
    <h1>Prueba integral de VeriFactu</h1>
    <div class="muted"><?= $h($tenant['name']) ?> · Tenant #<?= $tenantId ?> · Entorno de pruebas forzado</div>
    <div class="warning">Procesará todas las facturas pendientes, guardará sus XML y huellas, actualizará sus estados y enviará un único bloque al entorno de pruebas de AEAT.</div>
    <div class="summary">
      <div><strong>Facturas pendientes</strong><br><?= $pendingCount ?></div>
      <div><strong>Emisor</strong><br>Según Persona Física/Jurídica configurada</div>
      <div><strong>Sistema</strong><br>SimplyGest Praxis · SP · SGPRAXIS<?= $tenantId ?></div>
    </div>
    <form method="post">
      <input type="hidden" name="tenant" value="<?= $tenantId ?>">
      <input type="hidden" name="token" value="<?= $h($providedToken) ?>">
      <button type="submit" <?= $pendingCount === 0 ? 'disabled' : '' ?>>Comprobar, generar y enviar pendientes</button>
    </form>
  </section>

  <?php if ($logs): ?>
    <section class="panel">
      <h2>Log del proceso</h2>
      <ul class="log">
        <?php foreach ($logs as $entry): ?>
          <li class="<?= $h($entry['status']) ?>">[<?= strtoupper($h($entry['status'])) ?>] <?= $h($entry['message']) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($requestXml !== ''): ?>
        <details>
          <summary>XML enviado</summary>
          <pre><?= $h($requestXml) ?></pre>
        </details>
      <?php endif; ?>
      <?php if ($responseXml !== ''): ?>
        <details>
          <summary>Respuesta XML de AEAT</summary>
          <pre><?= $h($responseXml) ?></pre>
        </details>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
