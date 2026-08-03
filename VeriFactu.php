<?php

require_once __DIR__ . '/verifactu_helpers.php';

$invoiceId = isset($verifactu_invoice_id) ? (int) $verifactu_invoice_id : 0;
if ($invoiceId <= 0) {
    throw new RuntimeException('No se indicó la factura que se iba a registrar en VeriFactu.');
}

verifactu_enqueue_invoice($mysqli, $invoiceId);
