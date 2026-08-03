<?php

function invoice_template_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function invoice_template_money($value)
{
    return number_format((float) $value, 2, ',', '.') . ' EUR';
}

function invoice_template_date($value)
{
    $timestamp = strtotime((string) $value);
    return $timestamp ? date('d/m/Y', $timestamp) : (string) $value;
}

function invoice_template_address(array $party)
{
    $lines = [];
    if (!empty($party['address'])) {
        foreach (preg_split('/\R/u', trim((string) $party['address'])) ?: [] as $address_line) {
            $address_line = trim($address_line);
            if ($address_line !== '') {
                $lines[] = $address_line;
            }
        }
    }
    $location = trim(implode(' ', array_filter([
        trim((string) ($party['postal_code'] ?? '')),
        trim((string) ($party['city'] ?? '')),
    ])));
    if ($location !== '') {
        $lines[] = $location;
    }
    if (!empty($party['province'])) {
        $lines[] = trim((string) $party['province']);
    }
    return $lines;
}

function invoice_template_minimal_html(array $invoice, array $issuer, array $recipient, array $options = [])
{
    $primary = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($options['primary_color'] ?? ''))
        ? strtolower((string) $options['primary_color'])
        : '#6f5aa8';
    $tax_system = strtolower((string) ($invoice['tax_system'] ?? 'iva')) === 'igic' ? 'IGIC' : 'IVA';
    $tax_rate = (float) ($invoice['iva_porcentaje'] ?? 0);
    $logo = trim((string) ($options['logo_path'] ?? ''));
    $issuer_address = invoice_template_address($issuer);
    $recipient_address = invoice_template_address($recipient);
    $exemption = trim((string) ($invoice['tax_exemption_reason'] ?? ''));
    $verification_status = trim((string) ($invoice['verifactu_estado'] ?? ''));
    $verification_qr_url = trim((string) ($options['verifactu_qr_url'] ?? ''));

    ob_start();
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { margin: 0; color: #202933; font-family: dejavusans, sans-serif; font-size: 10pt; }
        .top-line { height: 4px; background: <?= invoice_template_h($primary) ?>; margin-bottom: 18px; }
        .header-table, .party-table, .summary-table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: top; }
        .issuer-name { color: <?= invoice_template_h($primary) ?>; font-size: 16pt; font-weight: bold; margin-bottom: 4px; }
        .muted { color: #687582; }
        .logo-cell { width: 28%; text-align: right; }
        .logo { max-width: 150px; max-height: 58px; }
        .document-title { margin: 26px 0 5px; font-size: 22pt; font-weight: bold; color: #202933; }
        .document-number { color: <?= invoice_template_h($primary) ?>; font-size: 11pt; font-weight: bold; }
        .party-table { margin-top: 22px; }
        .party-table td { vertical-align: top; }
        .party-data-cell { width: 35%; border: 1px solid #dfe4e8; padding: 12px; }
        .party-qr-cell { width: 30%; border: 0; padding: 0 8px; text-align: center; vertical-align: middle !important; }
        .party-qr-label { margin-top: 2px; color: #202933; font-size: 8pt; font-weight: bold; text-align: center; }
        .party-title { margin-bottom: 7px; color: <?= invoice_template_h($primary) ?>; font-size: 8pt; font-weight: bold; text-transform: uppercase; }
        .party-name { margin-bottom: 4px; font-size: 11pt; font-weight: bold; }
        .meta-table { width: 100%; margin-top: 22px; border-collapse: collapse; }
        .meta-table td { padding: 7px 9px; border-bottom: 1px solid #e3e7ea; }
        .meta-label { width: 18%; color: #687582; }
        .line-table { width: 100%; margin-top: 25px; border-collapse: collapse; }
        .line-table th { padding: 9px; color: #fff; background: <?= invoice_template_h($primary) ?>; font-size: 8pt; text-align: left; }
        .line-table td { padding: 11px 9px; border-bottom: 1px solid #dfe4e8; vertical-align: top; }
        .right { text-align: right !important; }
        .summary-wrap { margin-top: 22px; margin-left: 51%; width: 49%; }
        .summary-table td { padding: 6px 8px; }
        .summary-table .total td { padding-top: 9px; border-top: 2px solid <?= invoice_template_h($primary) ?>; font-size: 13pt; font-weight: bold; }
        .notice { margin-top: 24px; padding: 10px 12px; border-left: 3px solid <?= invoice_template_h($primary) ?>; background: #f5f3f9; color: #4f5963; font-size: 8.5pt; }
        .footer { margin-top: 30px; padding-top: 9px; border-top: 1px solid #dfe4e8; color: #7a858f; font-size: 7.5pt; }
    </style>
</head>
<body>
    <div class="top-line"></div>
    <table class="header-table">
        <tr>
            <td>
                <div class="issuer-name"><?= invoice_template_h($issuer['name'] ?? '') ?></div>
                <?php if (!empty($issuer['nif'])): ?><div>NIF: <?= invoice_template_h($issuer['nif']) ?></div><?php endif; ?>
                <?php foreach ($issuer_address as $line): ?><div class="muted"><?= invoice_template_h($line) ?></div><?php endforeach; ?>
                <?php if (!empty($issuer['email'])): ?><div class="muted"><?= invoice_template_h($issuer['email']) ?></div><?php endif; ?>
            </td>
            <?php if ($logo !== '' && is_file($logo)): ?>
                <td class="logo-cell"><img class="logo" src="<?= invoice_template_h($logo) ?>" alt=""></td>
            <?php endif; ?>
        </tr>
    </table>

    <div class="document-title">FACTURA</div>
    <div class="document-number"><?= invoice_template_h($invoice['numero_factura'] ?? '') ?></div>

    <table class="party-table">
        <tr>
            <td class="party-data-cell">
                <div class="party-title">Emisor</div>
                <div class="party-name"><?= invoice_template_h($issuer['name'] ?? '') ?></div>
                <div><?= invoice_template_h($issuer['nif'] ?? '') ?></div>
                <?php foreach ($issuer_address as $line): ?><div class="muted"><?= invoice_template_h($line) ?></div><?php endforeach; ?>
            </td>
            <td class="party-qr-cell">
                <?php if ($verification_qr_url !== ''): ?>
                    <barcode code="<?= invoice_template_h($verification_qr_url) ?>" type="QR" size="1.15" error="M" disableborder="1" />
                    <div class="party-qr-label">VERI*FACTU</div>
                <?php endif; ?>
            </td>
            <td class="party-data-cell">
                <div class="party-title">Destinatario</div>
                <div class="party-name"><?= invoice_template_h($recipient['name'] ?? '') ?></div>
                <div><?= invoice_template_h($recipient['nif'] ?? '') ?></div>
                <?php foreach ($recipient_address as $line): ?><div class="muted"><?= invoice_template_h($line) ?></div><?php endforeach; ?>
                <?php if (!empty($recipient['email'])): ?><div class="muted"><?= invoice_template_h($recipient['email']) ?></div><?php endif; ?>
            </td>
        </tr>
    </table>

    <table class="meta-table">
        <tr>
            <td class="meta-label">Fecha</td>
            <td><?= invoice_template_h(invoice_template_date($invoice['fecha'] ?? '')) ?></td>
            <td class="meta-label">Forma de pago</td>
            <td><?= invoice_template_h(($invoice['forma_pago'] ?? '') ?: 'No especificada') ?></td>
        </tr>
    </table>

    <table class="line-table">
        <thead>
            <tr>
                <th>Concepto</th>
                <th class="right" style="width: 14%;">Cantidad</th>
                <th class="right" style="width: 20%;">Base</th>
                <th class="right" style="width: 15%;"><?= invoice_template_h($tax_system) ?></th>
                <th class="right" style="width: 20%;">Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?= invoice_template_h($invoice['concepto'] ?? '') ?></td>
                <td class="right">1</td>
                <td class="right"><?= invoice_template_h(invoice_template_money($invoice['base_imponible'] ?? 0)) ?></td>
                <td class="right"><?= invoice_template_h(number_format($tax_rate, 2, ',', '.')) ?> %</td>
                <td class="right"><?= invoice_template_h(invoice_template_money($invoice['total'] ?? 0)) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="summary-wrap">
        <table class="summary-table">
            <tr><td>Base imponible</td><td class="right"><?= invoice_template_h(invoice_template_money($invoice['base_imponible'] ?? 0)) ?></td></tr>
            <tr><td><?= invoice_template_h($tax_system) ?> <?= invoice_template_h(number_format($tax_rate, 2, ',', '.')) ?> %</td><td class="right"><?= invoice_template_h(invoice_template_money($invoice['iva_importe'] ?? 0)) ?></td></tr>
            <tr class="total"><td>Total</td><td class="right"><?= invoice_template_h(invoice_template_money($invoice['total'] ?? 0)) ?></td></tr>
        </table>
    </div>

    <?php if ($tax_rate <= 0 && $exemption !== ''): ?>
        <div class="notice"><strong>Operación exenta de <?= invoice_template_h($tax_system) ?>.</strong> <?= invoice_template_h($exemption) ?></div>
    <?php endif; ?>

    <div class="footer">
        Factura emitida el <?= invoice_template_h(invoice_template_date($invoice['fecha'] ?? '')) ?>.
        <?php if ($verification_status !== ''): ?> Estado fiscal: <?= invoice_template_h($verification_status) ?>.<?php endif; ?>
    </div>
</body>
</html>
    <?php
    return (string) ob_get_clean();
}
