<?php

declare(strict_types=1);

require_once __DIR__ . '/stampbyme_helpers.php';

function verifactu_is_enabled(mysqli $mysqli, ?int $tenantId = null): bool
{
    $tenantId = $tenantId ?? current_tenant_id();
    verifactu_sync_environment($mysqli, $tenantId);
    $stmt = $mysqli->prepare("
        SELECT billing_enabled, billing_country, verifactu_enabled,
               verifactu_start_date
        FROM payment_settings
        WHERE tenant_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc();

    if (!$settings
        || (int) ($settings['billing_enabled'] ?? 0) !== 1
        || strtoupper(trim((string) ($settings['billing_country'] ?? ''))) !== 'ES'
        || (int) ($settings['verifactu_enabled'] ?? 0) !== 1) {
        return false;
    }

    $startDate = trim((string) ($settings['verifactu_start_date'] ?? ''));
    return $startDate !== '' && $startDate <= date('Y-m-d');
}

function verifactu_environment(mysqli $mysqli, ?int $tenantId = null): string
{
    $tenantId = $tenantId ?? current_tenant_id();
    verifactu_sync_environment($mysqli, $tenantId);
    $stmt = $mysqli->prepare("SELECT verifactu_environment FROM payment_settings WHERE tenant_id = ? LIMIT 1");
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int) ($row['verifactu_environment'] ?? 0) === 1 ? 'production' : 'test';
}

function verifactu_sync_environment(mysqli $mysqli, int $tenantId): void
{
    $stmt = $mysqli->prepare("
        UPDATE payment_settings
        SET verifactu_environment = 1,
            verifactu_activated_at = COALESCE(verifactu_activated_at, NOW())
        WHERE tenant_id = ?
          AND verifactu_enabled = 1
          AND verifactu_environment = 0
          AND verifactu_activated_at IS NULL
          AND verifactu_start_date IS NOT NULL
          AND verifactu_start_date <= CURRENT_DATE
    ");
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
}

function verifactu_official_start_date(string $taxpayerType): string
{
    return $taxpayerType === 'company' ? '2027-01-01' : '2027-07-01';
}

function verifactu_can_send_record(array $record): bool
{
    return true;
}

function verifactu_normalize_tax_id(string $value): string
{
    return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', trim($value)));
}

function verifactu_valid_spanish_tax_id(string $value): bool
{
    $value = verifactu_normalize_tax_id($value);
    if (preg_match('/^[0-9]{8}[A-Z]$/', $value)) {
        return 'TRWAGMYFPDXBNJZSQVHLCKE'[((int) substr($value, 0, 8)) % 23] === $value[8];
    }
    if (preg_match('/^[XYZ][0-9]{7}[A-Z]$/', $value)) {
        $number = strtr(substr($value, 0, 8), ['X' => '0', 'Y' => '1', 'Z' => '2']);
        return 'TRWAGMYFPDXBNJZSQVHLCKE'[((int) $number) % 23] === $value[8];
    }
    if (!preg_match('/^[ABCDEFGHJNPQRSUVW][0-9]{7}[0-9A-J]$/', $value)) {
        return false;
    }

    $digits = substr($value, 1, 7);
    $sumEven = (int) $digits[1] + (int) $digits[3] + (int) $digits[5];
    $sumOdd = 0;
    foreach ([0, 2, 4, 6] as $index) {
        $doubled = ((int) $digits[$index]) * 2;
        $sumOdd += intdiv($doubled, 10) + ($doubled % 10);
    }
    $control = (10 - (($sumEven + $sumOdd) % 10)) % 10;
    $expected = ctype_digit($value[8]) ? (string) $control : 'JABCDEFGHI'[$control];
    return $value[8] === $expected;
}

function verifactu_spanish_tax_id_type(string $value): string
{
    $value = verifactu_normalize_tax_id($value);
    if (preg_match('/^(?:[0-9]{8}|[XYZ][0-9]{7})[A-Z]$/', $value)) {
        return 'individual';
    }
    if (preg_match('/^[ABCDEFGHJNPQRSUVW][0-9]{7}[0-9A-J]$/', $value)) {
        return 'entity';
    }
    return '';
}

function verifactu_aeat_census_check(string $nif, string $name, array $certificatePaths): array
{
    $nif = verifactu_normalize_tax_id($nif);
    $name = trim($name);
    if ($nif === '' || $name === '') {
        return ['ok' => false, 'result' => '', 'error' => 'Faltan el NIF o el nombre para consultar el censo de la AEAT.'];
    }
    if (!is_file($certificatePaths['certificate'] ?? '') || !is_file($certificatePaths['private_key'] ?? '')) {
        return ['ok' => false, 'result' => '', 'error' => 'Falta el certificado digital para consultar el censo de la AEAT.'];
    }

    static $cache = [];
    $cacheKey = $nif . '|' . mb_strtoupper($name, 'UTF-8');
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $soapRequest = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" '
        . 'xmlns:vnif="http://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/burt/jdit/ws/VNifV2Ent.xsd">'
        . '<soapenv:Header/><soapenv:Body><vnif:VNifV2Ent><vnif:Contribuyente>'
        . '<vnif:Nif>' . verifactu_xml_escape($nif) . '</vnif:Nif>'
        . '<vnif:Nombre>' . verifactu_xml_escape($name) . '</vnif:Nombre>'
        . '</vnif:Contribuyente></vnif:VNifV2Ent></soapenv:Body></soapenv:Envelope>';

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://www1.agenciatributaria.gob.es/wlpl/BURT-JDIT/ws/VNifV2SOAP',
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $soapRequest,
        CURLOPT_SSLCERT => $certificatePaths['certificate'],
        CURLOPT_SSLKEY => $certificatePaths['private_key'],
        CURLOPT_SSLCERTTYPE => 'PEM',
        CURLOPT_SSLKEYTYPE => 'PEM',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: ""',
            'Content-Length: ' . strlen($soapRequest),
        ],
    ]);
    $response = curl_exec($curl);
    $curlError = $response === false ? curl_error($curl) : '';
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false || trim((string) $response) === '') {
        return $cache[$cacheKey] = [
            'ok' => false,
            'result' => '',
            'error' => 'No se pudo consultar el censo de la AEAT.'
                . ($curlError !== '' ? ' ' . $curlError : ''),
        ];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string((string) $response);
    $resultNodes = $xml !== false ? $xml->xpath('//*[local-name()="Contribuyente"]/*[local-name()="Resultado"]') : [];
    $faultNodes = $xml !== false ? $xml->xpath('//*[local-name()="Fault"]/*[local-name()="faultstring"]') : [];
    libxml_clear_errors();
    $result = trim((string) ($resultNodes[0] ?? ''));
    if ($result === 'IDENTIFICADO') {
        return $cache[$cacheKey] = ['ok' => true, 'result' => $result, 'error' => ''];
    }
    $fault = trim((string) ($faultNodes[0] ?? ''));
    $error = $fault !== ''
        ? $fault
        : ($result !== '' ? $result : 'La AEAT no devolvió un resultado censal reconocible (HTTP ' . $httpCode . ').');
    return $cache[$cacheKey] = ['ok' => false, 'result' => $result, 'error' => $error];
}

function verifactu_exemption_code(string $taxSystem): string
{
    return strtolower(trim($taxSystem)) === 'igic' ? 'E6' : 'E1';
}

function verifactu_exemption_reason(string $taxSystem): string
{
    return strtolower(trim($taxSystem)) === 'igic'
        ? 'Operación exenta de IGIC conforme al artículo 50.Uno.3.º de la Ley 4/2012.'
        : 'Operación exenta de IVA conforme al artículo 20.Uno.3.º de la Ley 37/1992.';
}

function verifactu_emitter(mysqli $mysqli, int $tenantId): array
{
    $stmt = $mysqli->prepare("
        SELECT COALESCE(NULLIF(TRIM(ps.legal_owner_name), ''), NULLIF(TRIM(t.app_name), ''),
                        NULLIF(TRIM(t.tenant_name), '')) AS name,
               ps.legal_nif AS nif, ps.legal_address AS address, ps.legal_postal_code AS postal_code,
               ps.legal_city AS city, ps.legal_province AS province,
               ps.verifactu_environment, ps.verifactu_taxpayer_type
        FROM tenants t
        LEFT JOIN payment_settings ps ON ps.tenant_id = t.id
        WHERE t.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $emitter = $stmt->get_result()->fetch_assoc() ?: [];

    $emitter['taxpayer_type'] = (string) ($emitter['verifactu_taxpayer_type'] ?? 'self_employed');
    unset($emitter['verifactu_environment'], $emitter['verifactu_taxpayer_type']);
    return $emitter;
}

function verifactu_precheck(mysqli $mysqli, array $invoice): array
{
    $tenantId = (int) ($invoice['tenant_id'] ?? current_tenant_id());
    $forceEnabled = !empty($invoice['_force_verifactu']);
    if (!$forceEnabled && !verifactu_is_enabled($mysqli, $tenantId)) {
        return ['ok' => true, 'enabled' => false, 'errors' => []];
    }

    $errors = [];
    $emitter = verifactu_emitter($mysqli, $tenantId);
    if (trim((string) ($emitter['name'] ?? '')) === '') {
        $errors[] = 'Falta el nombre o razón social del emisor.';
    }
    if (!verifactu_valid_spanish_tax_id((string) ($emitter['nif'] ?? ''))) {
        $errors[] = 'El NIF del emisor no es válido.';
    } else {
        $emitterTaxIdType = verifactu_spanish_tax_id_type((string) ($emitter['nif'] ?? ''));
        $configuredTaxpayerType = (string) ($emitter['taxpayer_type'] ?? 'self_employed');
        if ($configuredTaxpayerType === 'company' && $emitterTaxIdType === 'individual') {
            $errors[] = 'El tipo configurado es Persona Jurídica, pero el NIF del emisor corresponde a una persona física.';
        } elseif ($configuredTaxpayerType === 'self_employed' && $emitterTaxIdType === 'entity') {
            $errors[] = 'El tipo configurado es Persona Física, pero el NIF del emisor corresponde a una persona jurídica o entidad.';
        }
    }
    if (trim((string) ($emitter['address'] ?? '')) === '') {
        $errors[] = 'Falta el domicilio fiscal del emisor.';
    }
    if (!verifactu_valid_spanish_tax_id((string) ($invoice['recipient_nif'] ?? ''))) {
        $errors[] = 'El NIF del destinatario no es válido.';
    }
    if (trim((string) ($invoice['recipient_name'] ?? '')) === '') {
        $errors[] = 'Falta el nombre o razón social del destinatario.';
    }
    if (trim((string) ($invoice['recipient_address'] ?? '')) === '') {
        $errors[] = 'Falta el domicilio del destinatario.';
    }
    $total = round((float) ($invoice['total'] ?? 0), 2);
    if ($total < 0) {
        $errors[] = 'El total de la factura no puede ser negativo.';
    }
    $taxSystem = strtolower(trim((string) ($invoice['tax_system'] ?? 'iva')));
    $taxRate = round((float) ($invoice['tax_rate'] ?? 0), 2);
    $allowedRates = $taxSystem === 'igic' ? [0.0, 7.0] : [0.0, 21.0];
    if (!in_array($taxRate, $allowedRates, true)) {
        $errors[] = 'El tipo impositivo no coincide con la fiscalidad configurada.';
    }
    $certificate = stampbyme_certificate_status($tenantId);
    $certificateIdentityMatches = true;
    if (!$certificate['available']) {
        $errors[] = 'Falta el certificado digital general del centro necesario para enviar a VeriFactu.';
        $certificateIdentityMatches = false;
    } elseif (($certificate['valid_to'] ?? '') !== '' && strtotime((string) $certificate['valid_to']) <= time()) {
        $errors[] = 'El certificado digital general del centro está caducado.';
        $certificateIdentityMatches = false;
    } else {
        $emitterNif = verifactu_normalize_tax_id((string) ($emitter['nif'] ?? ''));
        $certificateTaxIds = array_values(array_unique(array_filter(array_map(
            'verifactu_normalize_tax_id',
            (array) ($certificate['tax_ids'] ?? [])
        ))));
        if ($emitterNif !== '' && $certificateTaxIds && !in_array($emitterNif, $certificateTaxIds, true)) {
            $errors[] = 'El NIF del certificado digital no coincide con el NIF del emisor de VeriFactu. '
                . 'Selecciona un certificado del obligado a emitir la factura.';
            $certificateIdentityMatches = false;
        }
    }
    if (!$errors && $certificateIdentityMatches) {
        $certificatePaths = stampbyme_certificate_paths($tenantId);
        $emitterCensus = verifactu_aeat_census_check(
            (string) ($emitter['nif'] ?? ''),
            (string) ($emitter['name'] ?? ''),
            $certificatePaths
        );
        if (!$emitterCensus['ok']) {
            $errors[] = 'La AEAT no ha podido identificar al emisor con el NIF y nombre configurados: '
                . $emitterCensus['error'];
        }
        $recipientCensus = verifactu_aeat_census_check(
            (string) ($invoice['recipient_nif'] ?? ''),
            (string) ($invoice['recipient_name'] ?? ''),
            $certificatePaths
        );
        if (!$recipientCensus['ok']) {
            $errors[] = 'La AEAT no ha podido identificar al destinatario con el NIF y nombre indicados: '
                . $recipientCensus['error'];
        }
    }

    return [
        'ok' => !$errors,
        'enabled' => true,
        'errors' => $errors,
        'emitter' => $emitter,
        'certificate' => $certificate,
    ];
}

function verifactu_precheck_or_fail(mysqli $mysqli, array $invoice): array
{
    $result = verifactu_precheck($mysqli, $invoice);
    if (!$result['ok']) {
        throw new RuntimeException('No se puede emitir la factura: ' . implode(' ', $result['errors']));
    }
    return $result;
}

function verifactu_invoice_snapshot(mysqli $mysqli, int $tenantId, int $invoiceId): array
{
    $stmt = $mysqli->prepare("
        SELECT id, tenant_id, serie, numero, numero_factura, fecha, destinatario_nombre,
               destinatario_nif, destinatario_direccion, concepto, base_imponible,
               iva_porcentaje, iva_importe, tax_system, tax_exemption_reason, total,
               moneda, forma_pago, lineas_json, created_at
        FROM movim
        WHERE tenant_id = ? AND id = ? AND tipo_movim = 'factura'
        LIMIT 1
    ");
    $stmt->bind_param('ii', $tenantId, $invoiceId);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    if (!$invoice) {
        throw new RuntimeException('No se encontró la factura que se iba a registrar en VeriFactu.');
    }
    if (
        round((float) ($invoice['iva_porcentaje'] ?? 0), 2) === 0.0
        && trim((string) ($invoice['tax_exemption_reason'] ?? '')) === ''
    ) {
        $invoice['tax_exemption_reason'] = verifactu_exemption_reason((string) ($invoice['tax_system'] ?? 'iva'));
    }
    return $invoice;
}

function verifactu_fingerprint(array $invoice, array $emitter, string $previousHash, string $generatedAt): string
{
    $source = implode('&', [
        'IDEmisorFactura=' . verifactu_normalize_tax_id((string) ($emitter['nif'] ?? '')),
        'NumSerieFactura=' . trim((string) ($invoice['numero_factura'] ?? '')),
        'FechaExpedicionFactura=' . date('d-m-Y', strtotime((string) ($invoice['fecha'] ?? 'now'))),
        'TipoFactura=F1',
        'CuotaTotal=' . number_format((float) ($invoice['iva_importe'] ?? 0), 2, '.', ''),
        'ImporteTotal=' . number_format((float) ($invoice['total'] ?? 0), 2, '.', ''),
        'Huella=' . $previousHash,
        'FechaHoraHusoGenRegistro=' . $generatedAt,
    ]);
    return strtoupper(hash('sha256', $source));
}

function verifactu_xml_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function verifactu_qr_url(array $invoice, array $emitter, string $environment): string
{
    $baseUrl = $environment === 'production'
        ? 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR'
        : 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR';
    $invoiceDate = date('d-m-Y', strtotime((string) ($invoice['fecha'] ?? 'now')));
    $params = [
        'nif' => verifactu_normalize_tax_id((string) ($emitter['nif'] ?? '')),
        'numserie' => trim((string) ($invoice['numero_factura'] ?? '')),
        'fecha' => $invoiceDate,
        'importe' => number_format((float) ($invoice['total'] ?? 0), 2, '.', ''),
    ];
    return $baseUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function verifactu_record_xml(array $invoice, array $emitter, array $chain, string $fingerprint, string $generatedAt): string
{
    $taxSystem = strtolower((string) ($invoice['tax_system'] ?? 'iva'));
    $taxCode = $taxSystem === 'igic' ? '02' : '01';
    $taxRate = round((float) ($invoice['iva_porcentaje'] ?? 0), 2);
    $base = number_format((float) ($invoice['base_imponible'] ?? 0), 2, '.', '');
    $taxAmount = number_format((float) ($invoice['iva_importe'] ?? 0), 2, '.', '');
    $total = number_format((float) ($invoice['total'] ?? 0), 2, '.', '');
    $invoiceDate = date('d-m-Y', strtotime((string) ($invoice['fecha'] ?? 'now')));
    $invoiceNumber = trim((string) ($invoice['numero_factura'] ?? ''));
    $previousFingerprint = trim((string) ($chain['previous_fingerprint'] ?? ''));
    $isFirst = $previousFingerprint === '';

    $xml = '<RegistroFactura>';
    $xml .= '<RegistroAlta xmlns="https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd">';
    $xml .= '<IDVersion>1.0</IDVersion>';
    $xml .= '<IDFactura>';
    $xml .= '<IDEmisorFactura>' . verifactu_xml_escape(verifactu_normalize_tax_id((string) ($emitter['nif'] ?? ''))) . '</IDEmisorFactura>';
    $xml .= '<NumSerieFactura>' . verifactu_xml_escape($invoiceNumber) . '</NumSerieFactura>';
    $xml .= '<FechaExpedicionFactura>' . verifactu_xml_escape($invoiceDate) . '</FechaExpedicionFactura>';
    $xml .= '</IDFactura>';
    $xml .= '<RefExterna>' . (int) ($invoice['id'] ?? 0) . '</RefExterna>';
    $xml .= '<NombreRazonEmisor>' . verifactu_xml_escape($emitter['name'] ?? '') . '</NombreRazonEmisor>';
    $xml .= '<TipoFactura>F1</TipoFactura>';
    $xml .= '<DescripcionOperacion>' . verifactu_xml_escape($invoice['concepto'] ?? 'Servicio profesional') . '</DescripcionOperacion>';
    $xml .= '<Destinatarios><IDDestinatario>';
    $xml .= '<NombreRazon>' . verifactu_xml_escape($invoice['destinatario_nombre'] ?? '') . '</NombreRazon>';
    $xml .= '<NIF>' . verifactu_xml_escape(verifactu_normalize_tax_id((string) ($invoice['destinatario_nif'] ?? ''))) . '</NIF>';
    $xml .= '</IDDestinatario></Destinatarios>';
    $xml .= '<Desglose><DetalleDesglose>';
    $xml .= '<Impuesto>' . $taxCode . '</Impuesto>';
    $xml .= '<ClaveRegimen>01</ClaveRegimen>';
    if ($taxRate > 0) {
        $xml .= '<CalificacionOperacion>S1</CalificacionOperacion>';
        $xml .= '<TipoImpositivo>' . number_format($taxRate, 2, '.', '') . '</TipoImpositivo>';
        $xml .= '<BaseImponibleOimporteNoSujeto>' . $base . '</BaseImponibleOimporteNoSujeto>';
        $xml .= '<CuotaRepercutida>' . $taxAmount . '</CuotaRepercutida>';
    } else {
        $xml .= '<OperacionExenta>'
            . verifactu_xml_escape(verifactu_exemption_code($taxSystem))
            . '</OperacionExenta>';
        $xml .= '<BaseImponibleOimporteNoSujeto>' . $base . '</BaseImponibleOimporteNoSujeto>';
    }
    $xml .= '</DetalleDesglose></Desglose>';
    $xml .= '<CuotaTotal>' . $taxAmount . '</CuotaTotal>';
    $xml .= '<ImporteTotal>' . $total . '</ImporteTotal>';
    $xml .= '<Encadenamiento>';
    if ($isFirst) {
        $xml .= '<PrimerRegistro>S</PrimerRegistro>';
    } else {
        $xml .= '<RegistroAnterior>';
        $xml .= '<IDEmisorFactura>' . verifactu_xml_escape(verifactu_normalize_tax_id((string) ($emitter['nif'] ?? ''))) . '</IDEmisorFactura>';
        $xml .= '<NumSerieFactura>' . verifactu_xml_escape($chain['previous_invoice_number'] ?? '') . '</NumSerieFactura>';
        $xml .= '<FechaExpedicionFactura>' . verifactu_xml_escape(date('d-m-Y', strtotime((string) ($chain['previous_invoice_date'] ?? 'now')))) . '</FechaExpedicionFactura>';
        $xml .= '<Huella>' . verifactu_xml_escape($previousFingerprint) . '</Huella>';
        $xml .= '</RegistroAnterior>';
    }
    $xml .= '</Encadenamiento>';
    $xml .= '<SistemaInformatico>';
    $xml .= '<NombreRazon>' . verifactu_xml_escape(defined('VERIFACTU_DEVELOPER_NAME') ? VERIFACTU_DEVELOPER_NAME : 'SimplyGest Software SLU') . '</NombreRazon>';
    $xml .= '<NIF>' . verifactu_xml_escape(defined('VERIFACTU_DEVELOPER_NIF') ? VERIFACTU_DEVELOPER_NIF : 'B76780022') . '</NIF>';
    $xml .= '<NombreSistemaInformatico>SimplyGest Praxis</NombreSistemaInformatico>';
    $xml .= '<IdSistemaInformatico>' . verifactu_xml_escape(defined('VERIFACTU_SYSTEM_ID') ? VERIFACTU_SYSTEM_ID : 'SP') . '</IdSistemaInformatico>';
    $xml .= '<Version>' . verifactu_xml_escape(defined('VERIFACTU_SYSTEM_VERSION') ? VERIFACTU_SYSTEM_VERSION : '1.0') . '</Version>';
    $installationId = defined('VERIFACTU_INSTALLATION_PREFIX')
        ? (string) VERIFACTU_INSTALLATION_PREFIX
        : 'SGPRAXIS';
    $installationId .= max(1, (int) ($invoice['tenant_id'] ?? 0));
    $xml .= '<NumeroInstalacion>' . verifactu_xml_escape($installationId) . '</NumeroInstalacion>';
    $xml .= '<TipoUsoPosibleSoloVerifactu>S</TipoUsoPosibleSoloVerifactu>';
    $xml .= '<TipoUsoPosibleMultiOT>S</TipoUsoPosibleMultiOT>';
    $xml .= '<IndicadorMultiplesOT>S</IndicadorMultiplesOT>';
    $xml .= '</SistemaInformatico>';
    $xml .= '<FechaHoraHusoGenRegistro>' . verifactu_xml_escape($generatedAt) . '</FechaHoraHusoGenRegistro>';
    $xml .= '<TipoHuella>01</TipoHuella>';
    $xml .= '<Huella>' . verifactu_xml_escape($fingerprint) . '</Huella>';
    $xml .= '</RegistroAlta></RegistroFactura>';
    return $xml;
}

function verifactu_store_record_xml(int $tenantId, string $environment, int $invoiceId, string $xml): string
{
    $directory = rtrim(app_protected_uploads_root(), '/\\')
        . '/verifactu/tenant-' . $tenantId . '/' . $environment . '/records';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo crear el almacenamiento protegido de VeriFactu.');
    }
    $path = $directory . '/invoice-' . $invoiceId . '.xml';
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, $xml, LOCK_EX) === false) {
        @unlink($temporary);
        throw new RuntimeException('No se pudo guardar el XML de VeriFactu.');
    }
    @chmod($temporary, 0600);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('No se pudo consolidar el XML de VeriFactu.');
    }
    return $path;
}

function verifactu_requires_incident(string $generatedAt, int $thresholdSeconds = 240): bool
{
    $timestamp = strtotime($generatedAt);
    return $timestamp === false || (time() - $timestamp) > $thresholdSeconds;
}

function verifactu_batch_xml(array $records, array $emitter): array
{
    $incident = false;
    $fragments = '';
    foreach ($records as $record) {
        if (verifactu_requires_incident((string) ($record['generated_at'] ?? ''))) {
            $incident = true;
        }
        $path = (string) ($record['record_xml_path'] ?? '');
        $fragment = $path !== '' && is_file($path) ? file_get_contents($path) : false;
        if ($fragment === false || trim($fragment) === '') {
            throw new RuntimeException('Falta un XML individual al formar el bloque de VeriFactu.');
        }
        $fragments .= $fragment;
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml .= '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
    $xml .= '<SOAP-ENV:Body>';
    $xml .= '<RegFactuSistemaFacturacion xmlns="https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd">';
    $xml .= '<Cabecera>';
    $xml .= '<ObligadoEmision xmlns="https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd">';
    $xml .= '<NombreRazon>' . verifactu_xml_escape($emitter['name'] ?? '') . '</NombreRazon>';
    $xml .= '<NIF>' . verifactu_xml_escape(verifactu_normalize_tax_id((string) ($emitter['nif'] ?? ''))) . '</NIF>';
    $xml .= '</ObligadoEmision>';
    $xml .= '<RemisionVoluntaria xmlns="https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd">';
    $xml .= '<Incidencia>' . ($incident ? 'S' : 'N') . '</Incidencia>';
    $xml .= '</RemisionVoluntaria></Cabecera>';
    $xml .= $fragments;
    $xml .= '</RegFactuSistemaFacturacion></SOAP-ENV:Body></SOAP-ENV:Envelope>';

    return ['xml' => $xml, 'incident' => $incident];
}

function verifactu_batch_emitter(array $records, array $fallbackEmitter): array
{
    $batchEmitter = [];
    $batchNif = '';
    foreach ($records as $record) {
        $payload = json_decode((string) ($record['payload_json'] ?? ''), true);
        $recordEmitter = is_array($payload) && is_array($payload['emitter'] ?? null)
            ? $payload['emitter']
            : [];
        $recordNif = verifactu_normalize_tax_id((string) ($recordEmitter['nif'] ?? ''));
        if ($recordNif === '') {
            continue;
        }
        if ($batchNif !== '' && $recordNif !== $batchNif) {
            throw new RuntimeException(
                'El lote contiene registros generados con distintos emisores. '
                . 'No se ha enviado nada a la AEAT.'
            );
        }
        $batchNif = $recordNif;
        $batchEmitter = $recordEmitter;
    }

    if ($batchNif === '') {
        return $fallbackEmitter;
    }
    if (trim((string) ($batchEmitter['name'] ?? '')) === '') {
        $batchEmitter['name'] = (string) ($fallbackEmitter['name'] ?? '');
    }
    return $batchEmitter;
}

function verifactu_store_exchange_xml(int $tenantId, string $environment, string $type, string $xml): string
{
    $type = $type === 'responses' ? 'responses' : 'requests';
    $directory = rtrim(app_protected_uploads_root(), '/\\')
        . '/verifactu/tenant-' . $tenantId . '/' . $environment . '/' . $type;
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo crear el almacenamiento protegido de intercambios VeriFactu.');
    }
    $path = $directory . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(5)) . '.xml';
    if (file_put_contents($path, $xml, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo guardar el XML de intercambio VeriFactu.');
    }
    @chmod($path, 0600);
    return $path;
}

function verifactu_mark_batch_error(
    mysqli $mysqli,
    int $tenantId,
    int $batchId,
    array $records,
    string $requestPath,
    string $responsePath,
    string $responseCode,
    string $message
): void {
    $updateRecord = $mysqli->prepare("
        UPDATE verifactu_records
        SET status = 'error', request_path = ?, response_path = ?, response_code = ?,
            response_message = ?, attempt_count = attempt_count + 1,
            last_attempt_at = NOW(), next_attempt_at = NULL
        WHERE id = ?
    ");
    $updateInvoice = $mysqli->prepare("
        UPDATE movim
        SET verifactu_estado = 'error', verifactu_error = ?
        WHERE tenant_id = ? AND id = ?
    ");
    foreach ($records as $record) {
        $recordId = (int) ($record['id'] ?? 0);
        $movimId = (int) ($record['movim_id'] ?? 0);
        $updateRecord->bind_param(
            'ssssi',
            $requestPath,
            $responsePath,
            $responseCode,
            $message,
            $recordId
        );
        $updateRecord->execute();
        $updateInvoice->bind_param('sii', $message, $tenantId, $movimId);
        $updateInvoice->execute();
    }

    $updateBatch = $mysqli->prepare("
        UPDATE verifactu_batches
        SET status = 'error', response_path = ?, response_code = ?, response_message = ?,
            sent_at = NOW(), completed_at = NOW()
        WHERE id = ?
    ");
    $updateBatch->bind_param('sssi', $responsePath, $responseCode, $message, $batchId);
    $updateBatch->execute();
}

function verifactu_send_generated_records(
    mysqli $mysqli,
    int $tenantId,
    array $recordIds,
    string $environment = 'test'
): array {
    $recordIds = array_values(array_unique(array_filter(array_map('intval', $recordIds), static fn ($id) => $id > 0)));
    if (!$recordIds) {
        throw new RuntimeException('No hay registros VeriFactu generados para enviar.');
    }
    $environment = $environment === 'production' ? 'production' : 'test';
    $idList = implode(',', $recordIds);
    $result = $mysqli->query("
        SELECT id, movim_id, invoice_number, generated_at, record_xml_path, payload_json
        FROM verifactu_records
        WHERE tenant_id = $tenantId
          AND environment = '" . $mysqli->real_escape_string($environment) . "'
          AND id IN ($idList)
          AND status IN ('generated', 'pending', 'retry', 'error')
        ORDER BY id ASC
        LIMIT 1000
    ");
    $records = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if (!$records) {
        throw new RuntimeException('No se encontraron registros VeriFactu enviables para el lote.');
    }

    $currentEmitter = verifactu_emitter($mysqli, $tenantId);
    $emitter = verifactu_batch_emitter($records, $currentEmitter);
    $batch = verifactu_batch_xml($records, $emitter);
    $requestPath = verifactu_store_exchange_xml($tenantId, $environment, 'requests', $batch['xml']);
    $certificatePaths = stampbyme_certificate_paths($tenantId);
    if (!is_file($certificatePaths['certificate']) || !is_file($certificatePaths['private_key'])) {
        throw new RuntimeException('Falta el certificado digital general del centro necesario para enviar a VeriFactu.');
    }

    $batchInsert = $mysqli->prepare("
        INSERT INTO verifactu_batches
            (tenant_id, environment, status, incident, record_count, request_path, generated_at)
        VALUES (?, ?, 'generated', ?, ?, ?, NOW())
    ");
    $incident = !empty($batch['incident']) ? 1 : 0;
    $recordCount = count($records);
    $batchInsert->bind_param('isiis', $tenantId, $environment, $incident, $recordCount, $requestPath);
    $batchInsert->execute();
    $batchId = (int) $mysqli->insert_id;
    $batchRecordInsert = $mysqli->prepare("
        INSERT IGNORE INTO verifactu_batch_records (batch_id, record_id, sort_order)
        VALUES (?, ?, ?)
    ");
    foreach ($records as $index => $record) {
        $recordId = (int) $record['id'];
        $sortOrder = $index + 1;
        $batchRecordInsert->bind_param('iii', $batchId, $recordId, $sortOrder);
        $batchRecordInsert->execute();
    }

    $url = $environment === 'production'
        ? 'https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'
        : 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_CONNECTTIMEOUT => 100,
        CURLOPT_TIMEOUT => 100,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'SimplyGest Praxis VeriFactu/1.0',
        CURLOPT_POST => true,
        CURLOPT_SSLCERT => $certificatePaths['certificate'],
        CURLOPT_SSLKEY => $certificatePaths['private_key'],
        CURLOPT_POSTFIELDS => $batch['xml'],
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/xml;charset=utf-8',
            'Accept: application/xml',
            'SOAPAction: AltaFactuSistemaFacturacion',
        ],
    ]);
    $response = curl_exec($curl);
    $curlError = $response === false ? curl_error($curl) : '';
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($response === false || trim((string) $response) === '') {
        $message = 'No ha habido respuesta de la AEAT o ha fallado el envío.'
            . ($curlError !== '' ? ' ' . $curlError : '');
        $httpCodeText = (string) $httpCode;
        verifactu_mark_batch_error(
            $mysqli,
            $tenantId,
            $batchId,
            $records,
            $requestPath,
            '',
            $httpCodeText,
            $message
        );
        throw new RuntimeException($message);
    }

    $response = (string) $response;
    $responsePath = verifactu_store_exchange_xml($tenantId, $environment, 'responses', $response);
    $normalized = preg_replace('/(<\s*)\w+:/', '$1', $response);
    $normalized = preg_replace('/(<\/\s*)\w+:/', '$1', (string) $normalized);
    $normalized = preg_replace('/\s+xmlns:[^>]+/', '', (string) $normalized);
    $parser = simplexml_load_string((string) $normalized);
    if ($parser === false) {
        $message = 'La AEAT respondió, pero no se pudo interpretar su XML.';
        verifactu_mark_batch_error(
            $mysqli,
            $tenantId,
            $batchId,
            $records,
            $requestPath,
            $responsePath,
            (string) $httpCode,
            $message
        );
        throw new RuntimeException($message);
    }

    $body = $parser->Body ?? null;
    $lineResults = [];
    $globalStatus = '';
    $globalMessage = '';
    if ($body && isset($body->Fault)) {
        $globalStatus = 'Error';
        $globalMessage = trim((string) $body->Fault->faultcode . ' ' . (string) $body->Fault->faultstring);
    } elseif ($body && isset($body->RespuestaRegFactuSistemaFacturacion)) {
        $answer = $body->RespuestaRegFactuSistemaFacturacion;
        $globalStatus = (string) $answer->EstadoEnvio;
        foreach ($answer->RespuestaLinea as $line) {
            $movimId = (int) ((string) ($line->RefExterna ?? '0'));
            $errorCode = trim((string) ($line->CodigoErrorRegistro ?? ''));
            $errorMessage = trim((string) ($line->DescripcionErrorRegistro ?? ''));
            $recordStatus = trim((string) ($line->EstadoRegistro ?? ''));
            $accepted = in_array($recordStatus, ['Correcto', 'AceptadoConErrores', 'Duplicada'], true)
                || in_array($errorCode, ['3000', '2004'], true);
            $lineResults[$movimId] = [
                'accepted' => $accepted,
                'status' => $recordStatus,
                'code' => $errorCode,
                'message' => $errorMessage,
            ];
        }
    } else {
        $globalStatus = 'Error';
        $globalMessage = 'La respuesta de la AEAT no contiene un resultado reconocible.';
    }

    $updateRecord = $mysqli->prepare("
        UPDATE verifactu_records
        SET status = ?, request_path = ?, response_path = ?, response_code = ?, response_message = ?,
            attempt_count = attempt_count + 1, last_attempt_at = NOW(), sent_at = NOW(),
            accepted_at = ?, next_attempt_at = NULL
        WHERE id = ?
    ");
    $updateInvoice = $mysqli->prepare("
        UPDATE movim
        SET verifactu_estado = ?, verifactu_error = ?
        WHERE tenant_id = ? AND id = ?
    ");
    $acceptedCount = 0;
    $errorCount = 0;
    $details = [];
    foreach ($records as $record) {
        $recordId = (int) $record['id'];
        $movimId = (int) $record['movim_id'];
        $line = $lineResults[$movimId] ?? null;
        $accepted = $line !== null && !empty($line['accepted']);
        $status = $accepted ? 'sent' : 'error';
        $code = $line['code'] ?? ($httpCode > 0 ? (string) $httpCode : '');
        $message = $line['message'] ?? ($globalMessage !== '' ? $globalMessage : 'La AEAT no devolvió resultado para esta factura.');
        $acceptedAt = $accepted ? date('Y-m-d H:i:s') : null;
        $updateRecord->bind_param(
            'ssssssi',
            $status,
            $requestPath,
            $responsePath,
            $code,
            $message,
            $acceptedAt,
            $recordId
        );
        $updateRecord->execute();
        $invoiceStatus = $accepted ? 'enviada' : 'error';
        $invoiceError = $accepted ? null : $message;
        $updateInvoice->bind_param('ssii', $invoiceStatus, $invoiceError, $tenantId, $movimId);
        $updateInvoice->execute();
        $accepted ? $acceptedCount++ : $errorCount++;
        $details[] = [
            'movim_id' => $movimId,
            'invoice_number' => (string) $record['invoice_number'],
            'accepted' => $accepted,
            'status' => $line['status'] ?? '',
            'code' => $code,
            'message' => $message,
        ];
    }

    $batchStatus = $errorCount === 0 ? 'sent' : ($acceptedCount > 0 ? 'partial' : 'error');
    $batchMessage = $globalMessage !== '' ? $globalMessage : $globalStatus;
    $updateBatch = $mysqli->prepare("
        UPDATE verifactu_batches
        SET status = ?, response_path = ?, response_code = ?, response_message = ?,
            sent_at = NOW(), completed_at = NOW()
        WHERE id = ?
    ");
    $httpCodeText = (string) $httpCode;
    $updateBatch->bind_param('ssssi', $batchStatus, $responsePath, $httpCodeText, $batchMessage, $batchId);
    $updateBatch->execute();

    return [
        'batch_id' => $batchId,
        'status' => $batchStatus,
        'http_code' => $httpCode,
        'accepted' => $acceptedCount,
        'errors' => $errorCount,
        'incident' => $incident,
        'request_path' => $requestPath,
        'response_path' => $responsePath,
        'request_xml' => $batch['xml'],
        'response_xml' => $response,
        'details' => $details,
    ];
}

function verifactu_enqueue_invoice(mysqli $mysqli, int $invoiceId, array $options = []): array
{
    $tenantId = current_tenant_id();
    $forceTest = !empty($options['force_test']);
    if (!$forceTest && !verifactu_is_enabled($mysqli, $tenantId)) {
        $stmt = $mysqli->prepare("UPDATE movim SET verifactu_estado = 'no_aplica', verifactu_error = NULL WHERE tenant_id = ? AND id = ?");
        $stmt->bind_param('ii', $tenantId, $invoiceId);
        $stmt->execute();
        return ['queued' => false, 'reason' => 'disabled'];
    }

    $environment = $forceTest ? 'test' : verifactu_environment($mysqli, $tenantId);
    $invoice = verifactu_invoice_snapshot($mysqli, $tenantId, $invoiceId);
    $precheck = verifactu_precheck_or_fail($mysqli, [
        'tenant_id' => $tenantId,
        'recipient_name' => $invoice['destinatario_nombre'],
        'recipient_nif' => $invoice['destinatario_nif'],
        'recipient_address' => $invoice['destinatario_direccion'],
        'total' => $invoice['total'],
        'tax_system' => $invoice['tax_system'],
        'tax_rate' => $invoice['iva_porcentaje'],
        'tax_exemption_reason' => $invoice['tax_exemption_reason'],
        '_force_verifactu' => $forceTest,
        '_environment' => $forceTest ? 'test' : null,
    ]);

    $existing = $mysqli->prepare("
        SELECT id, status, fingerprint
        FROM verifactu_records
        WHERE tenant_id = ? AND movim_id = ? AND environment = ? AND operation_type = 'registration'
        LIMIT 1
    ");
    $existing->bind_param('iis', $tenantId, $invoiceId, $environment);
    $existing->execute();
    if ($record = $existing->get_result()->fetch_assoc()) {
        $updateInvoice = $mysqli->prepare("
            UPDATE movim SET verifactu_estado = 'generada', verifactu_error = NULL
            WHERE tenant_id = ? AND id = ?
        ");
        $updateInvoice->bind_param('ii', $tenantId, $invoiceId);
        $updateInvoice->execute();
        return ['queued' => true, 'existing' => true] + $record;
    }

    $mysqli->query("
        INSERT IGNORE INTO verifactu_chains (tenant_id, environment)
        VALUES ($tenantId, '" . $mysqli->real_escape_string($environment) . "')
    ");
    $chainStmt = $mysqli->prepare("
        SELECT last_record_id, last_invoice_number, last_invoice_date, last_fingerprint
        FROM verifactu_chains
        WHERE tenant_id = ? AND environment = ?
        FOR UPDATE
    ");
    $chainStmt->bind_param('is', $tenantId, $environment);
    $chainStmt->execute();
    $chain = $chainStmt->get_result()->fetch_assoc() ?: [];

    $timezone = function_exists('tenant_timezone') ? tenant_timezone() : date_default_timezone_get();
    $generatedAt = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('c');
    $previousHash = trim((string) ($chain['last_fingerprint'] ?? ''));
    $fingerprint = verifactu_fingerprint($invoice, $precheck['emitter'], $previousHash, $generatedAt);
    $chainPayload = [
        'previous_record_id' => $chain['last_record_id'] ?? null,
        'previous_invoice_number' => $chain['last_invoice_number'] ?? null,
        'previous_invoice_date' => $chain['last_invoice_date'] ?? null,
        'previous_fingerprint' => $previousHash,
    ];
    $recordXml = verifactu_record_xml($invoice, $precheck['emitter'], $chainPayload, $fingerprint, $generatedAt);
    $recordXmlPath = verifactu_store_record_xml($tenantId, $environment, $invoiceId, $recordXml);
    $qrUrl = verifactu_qr_url($invoice, $precheck['emitter'], $environment);
    $payload = [
        'version' => 1,
        'environment' => $environment,
        'operation_type' => 'registration',
        'generated_at' => $generatedAt,
        'emitter' => $precheck['emitter'],
        'invoice' => $invoice,
        'chain' => $chainPayload,
        'fingerprint' => $fingerprint,
        'record_xml_path' => $recordXmlPath,
        'qr_url' => $qrUrl,
    ];
    $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $previousRecordId = !empty($chain['last_record_id']) ? (int) $chain['last_record_id'] : null;
    $previousInvoiceNumber = trim((string) ($chain['last_invoice_number'] ?? '')) ?: null;
    $previousInvoiceDate = trim((string) ($chain['last_invoice_date'] ?? '')) ?: null;
    $previousFingerprint = $previousHash !== '' ? $previousHash : null;
    $invoiceNumber = (string) $invoice['numero_factura'];
    $invoiceDate = (string) $invoice['fecha'];

    $insert = $mysqli->prepare("
        INSERT INTO verifactu_records
            (tenant_id, movim_id, environment, operation_type, status, invoice_number,
             invoice_date, previous_record_id, previous_invoice_number, previous_invoice_date,
             previous_fingerprint, fingerprint, payload_json, record_xml_path, qr_url, generated_at)
        VALUES (?, ?, ?, 'registration', 'generated', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->bind_param(
        'iisssissssssss',
        $tenantId,
        $invoiceId,
        $environment,
        $invoiceNumber,
        $invoiceDate,
        $previousRecordId,
        $previousInvoiceNumber,
        $previousInvoiceDate,
        $previousFingerprint,
        $fingerprint,
        $payloadJson,
        $recordXmlPath,
        $qrUrl,
        $generatedAt
    );
    $insert->execute();
    $recordId = (int) $mysqli->insert_id;

    $updateChain = $mysqli->prepare("
        UPDATE verifactu_chains
        SET last_record_id = ?, last_invoice_number = ?, last_invoice_date = ?,
            last_fingerprint = ?, updated_at = CURRENT_TIMESTAMP
        WHERE tenant_id = ? AND environment = ?
    ");
    $updateChain->bind_param('isssis', $recordId, $invoiceNumber, $invoiceDate, $fingerprint, $tenantId, $environment);
    $updateChain->execute();

    $updateInvoice = $mysqli->prepare("
        UPDATE movim SET verifactu_estado = 'generada', verifactu_error = NULL
        WHERE tenant_id = ? AND id = ?
    ");
    $updateInvoice->bind_param('ii', $tenantId, $invoiceId);
    $updateInvoice->execute();

    return ['queued' => true, 'record_id' => $recordId, 'fingerprint' => $fingerprint];
}

function verifactu_pending_count(mysqli $mysqli): int
{
    $result = $mysqli->query("
        SELECT COUNT(*) AS total
        FROM verifactu_records
        WHERE status IN ('generated', 'pending', 'retry')
          AND (next_attempt_at IS NULL OR next_attempt_at <= CURRENT_TIMESTAMP)
    ");
    $row = $result ? $result->fetch_assoc() : null;
    return (int) ($row['total'] ?? 0);
}
