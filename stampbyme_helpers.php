<?php

declare(strict_types=1);

function stampbyme_api_url(): string
{
    return rtrim(trim((string) psicologic_config_value('stampbyme_api_url', 'https://api.stampby.me')), '/');
}

function stampbyme_api_key(): string
{
    return trim((string) psicologic_config_value('stampbyme_api_key', ''));
}

function stampbyme_certificate_dir(int $tenantId, ?int $professionalId = null): string
{
    $base = rtrim(app_protected_uploads_root(), '/\\') . '/signing-certificates/tenant-' . max(1, $tenantId);
    return $professionalId !== null && $professionalId > 0
        ? $base . '/professional-' . $professionalId
        : $base . '/tenant';
}

function stampbyme_certificate_paths(int $tenantId, ?int $professionalId = null): array
{
    $dir = stampbyme_certificate_dir($tenantId, $professionalId);
    return [
        'dir' => $dir,
        'certificate' => $dir . '/certificate.pem',
        'private_key' => $dir . '/private-key.pem',
        'chain' => $dir . '/chain.pem',
        'metadata' => $dir . '/metadata.json',
    ];
}

function stampbyme_certificate_status(int $tenantId, ?int $professionalId = null): array
{
    $paths = stampbyme_certificate_paths($tenantId, $professionalId);
    $available = is_file($paths['certificate']) && is_file($paths['private_key']);
    $metadata = [];
    if ($available && is_file($paths['metadata'])) {
        $decoded = json_decode((string) file_get_contents($paths['metadata']), true);
        $metadata = is_array($decoded) ? $decoded : [];
    }
    if ($available && (
        empty($metadata['subject'])
        || empty($metadata['issuer'])
        || !array_key_exists('holder_nif', $metadata)
        || !array_key_exists('organization_nif', $metadata)
    )) {
        $certificatePem = file_get_contents($paths['certificate']);
        $certificateInfo = is_string($certificatePem) && $certificatePem !== ''
            ? openssl_x509_parse($certificatePem, false)
            : false;
        if (is_array($certificateInfo)) {
            foreach (stampbyme_x509_metadata($certificateInfo) as $key => $value) {
                if ($value !== '' || !array_key_exists($key, $metadata)) {
                    $metadata[$key] = $value;
                }
            }
        }
    }

    $taxIds = array_values(array_unique(array_filter([
        stampbyme_normalize_tax_id((string) ($metadata['holder_nif'] ?? '')),
        stampbyme_normalize_tax_id((string) ($metadata['organization_nif'] ?? '')),
    ])));
    return [
        'available' => $available,
        'api_configured' => stampbyme_api_key() !== '' && stampbyme_api_url() !== '',
        'subject' => (string) ($metadata['subject'] ?? ''),
        'issuer' => (string) ($metadata['issuer'] ?? ''),
        'holder_name' => (string) ($metadata['holder_name'] ?? ''),
        'holder_nif' => (string) ($metadata['holder_nif'] ?? ''),
        'organization' => (string) ($metadata['organization'] ?? ''),
        'organization_nif' => (string) ($metadata['organization_nif'] ?? ''),
        'tax_ids' => $taxIds,
        'serial_number' => (string) ($metadata['serial_number'] ?? ''),
        'fingerprint_sha256' => (string) ($metadata['fingerprint_sha256'] ?? ''),
        'valid_from' => (string) ($metadata['valid_from'] ?? ''),
        'valid_to' => (string) ($metadata['valid_to'] ?? ''),
        'imported_at' => (string) ($metadata['imported_at'] ?? ''),
        'owner_type' => $professionalId !== null && $professionalId > 0 ? 'professional' : 'tenant',
        'professional_id' => $professionalId !== null && $professionalId > 0 ? $professionalId : null,
    ];
}

function stampbyme_x509_name(array $name): string
{
    foreach (['CN', 'O', 'OU'] as $key) {
        $candidate = $name[$key] ?? '';
        $value = trim(is_array($candidate) ? implode(', ', $candidate) : (string) $candidate);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function stampbyme_x509_value(array $values, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $values)) {
            continue;
        }
        $candidate = $values[$key];
        $value = trim(is_array($candidate) ? implode(', ', $candidate) : (string) $candidate);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function stampbyme_normalize_tax_id(string $value): string
{
    return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', trim($value)));
}

function stampbyme_extract_spanish_tax_id(array $candidates): string
{
    foreach ($candidates as $candidate) {
        $candidate = strtoupper(trim((string) $candidate));
        if (preg_match('/(?:IDCES-|VATES-|NIF[:=\s-]*)?([XYZ]\d{7}[A-Z]|\d{8}[A-Z]|[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J])/i', $candidate, $matches)) {
            return strtoupper($matches[1]);
        }
    }
    return '';
}

function stampbyme_x509_metadata(array $certificateInfo): array
{
    $subject = is_array($certificateInfo['subject'] ?? null) ? $certificateInfo['subject'] : [];
    $issuer = is_array($certificateInfo['issuer'] ?? null) ? $certificateInfo['issuer'] : [];
    $commonName = stampbyme_x509_value($subject, ['CN']);
    $givenName = stampbyme_x509_value($subject, ['GN', 'givenName']);
    $surname = stampbyme_x509_value($subject, ['SN', 'surname']);
    $holderName = trim($givenName . ' ' . $surname);
    if ($holderName === '') {
        $holderName = $commonName;
    }
    $organization = stampbyme_x509_value($subject, ['O', 'organizationName']);
    $holderNif = stampbyme_extract_spanish_tax_id([
        stampbyme_x509_value($subject, ['serialNumber', '2.5.4.5']),
        stampbyme_x509_value($subject, ['UID', 'uniqueIdentifier']),
        $commonName,
    ]);
    $organizationNif = stampbyme_extract_spanish_tax_id([
        stampbyme_x509_value($subject, ['organizationIdentifier', '2.5.4.97']),
        $organization,
    ]);

    return [
        'subject' => stampbyme_x509_name($subject),
        'issuer' => stampbyme_x509_name($issuer),
        'holder_name' => $holderName,
        'holder_nif' => $holderNif,
        'organization' => $organization,
        'organization_nif' => $organizationNif,
    ];
}

function stampbyme_import_pkcs12(int $tenantId, array $upload, string $password, ?int $professionalId = null): array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($upload['tmp_name'])) {
        throw new RuntimeException('Selecciona un certificado PFX o P12 válido.');
    }
    if ((int) ($upload['size'] ?? 0) <= 0 || (int) ($upload['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('El certificado no puede superar 5 MB.');
    }
    $extension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['pfx', 'p12'], true)) {
        throw new RuntimeException('El certificado debe tener formato PFX o P12.');
    }
    $contents = file_get_contents((string) $upload['tmp_name']);
    if ($contents === false || $contents === '') {
        throw new RuntimeException('No se pudo leer el certificado.');
    }

    $bundle = [];
    if (!openssl_pkcs12_read($contents, $bundle, $password)) {
        throw new RuntimeException('El PFX/P12 o su contraseña no son válidos.');
    }
    $certificatePem = trim((string) ($bundle['cert'] ?? ''));
    $privateKeyPem = trim((string) ($bundle['pkey'] ?? ''));
    $chainPem = implode(PHP_EOL, array_values($bundle['extracerts'] ?? []));
    if ($certificatePem === '' || $privateKeyPem === '') {
        throw new RuntimeException('El certificado no contiene certificado público y clave privada.');
    }
    if (!openssl_x509_check_private_key($certificatePem, $privateKeyPem)) {
        throw new RuntimeException('La clave privada no corresponde al certificado.');
    }
    $privateKey = openssl_pkey_get_private($privateKeyPem);
    $keyDetails = $privateKey !== false ? openssl_pkey_get_details($privateKey) : false;
    if (!is_array($keyDetails) || ($keyDetails['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
        throw new RuntimeException('StampByMe requiere actualmente un certificado RSA.');
    }
    $certificateInfo = openssl_x509_parse($certificatePem, false);
    if (!is_array($certificateInfo)) {
        throw new RuntimeException('No se pudieron validar los datos del certificado.');
    }
    $now = time();
    $validFrom = (int) ($certificateInfo['validFrom_time_t'] ?? 0);
    $validTo = (int) ($certificateInfo['validTo_time_t'] ?? 0);
    if ($validFrom > $now) {
        throw new RuntimeException('El certificado todavía no es válido.');
    }
    if ($validTo <= $now) {
        throw new RuntimeException('El certificado está caducado.');
    }

    $paths = stampbyme_certificate_paths($tenantId, $professionalId);
    if (!is_dir($paths['dir']) && !mkdir($paths['dir'], 0700, true) && !is_dir($paths['dir'])) {
        throw new RuntimeException('No se pudo crear el almacenamiento protegido del certificado.');
    }

    $metadata = stampbyme_x509_metadata($certificateInfo) + [
        'serial_number' => (string) ($certificateInfo['serialNumberHex'] ?? $certificateInfo['serialNumber'] ?? ''),
        'fingerprint_sha256' => (string) openssl_x509_fingerprint($certificatePem, 'sha256'),
        'valid_from' => $validFrom > 0 ? gmdate(DATE_ATOM, $validFrom) : '',
        'valid_to' => $validTo > 0 ? gmdate(DATE_ATOM, $validTo) : '',
        'imported_at' => gmdate(DATE_ATOM),
    ];

    $files = [
        $paths['certificate'] => $certificatePem . PHP_EOL,
        $paths['private_key'] => $privateKeyPem . PHP_EOL,
        $paths['chain'] => trim($chainPem) !== '' ? trim($chainPem) . PHP_EOL : '',
        $paths['metadata'] => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    ];
    foreach ($files as $path => $data) {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $data, LOCK_EX) === false) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo guardar el certificado de forma segura.');
        }
        @chmod($temporary, 0600);
        if (is_file($path) && !@unlink($path)) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo sustituir el certificado anterior.');
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo activar el certificado importado.');
        }
    }

    unset($contents, $bundle, $password, $privateKeyPem, $privateKey);
    return stampbyme_certificate_status($tenantId, $professionalId);
}

function stampbyme_remove_certificate(int $tenantId, ?int $professionalId = null): void
{
    $paths = stampbyme_certificate_paths($tenantId, $professionalId);
    foreach (['certificate', 'private_key', 'chain', 'metadata'] as $key) {
        if (is_file($paths[$key])) {
            @unlink($paths[$key]);
        }
    }
}

function stampbyme_execute(string $path, array|string $body, array $headers): array
{
    $apiKey = stampbyme_api_key();
    if ($apiKey === '') {
        throw new RuntimeException('StampByMe no está configurado en el servidor.');
    }
    $curl = curl_init(stampbyme_api_url() . $path);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge($headers, ['Authorization: Bearer ' . $apiKey]),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    $requestId = '';
    $error = curl_error($curl);
    curl_close($curl);
    if (!is_string($response) || $status < 200 || $status >= 300) {
        $decoded = is_string($response) ? json_decode($response, true) : null;
        $message = is_array($decoded) ? trim((string) ($decoded['message'] ?? '')) : '';
        $requestId = is_array($decoded) ? trim((string) ($decoded['request_id'] ?? '')) : '';
        throw new RuntimeException('StampByMe HTTP ' . $status . ': ' . ($message !== '' ? $message : ($error ?: 'No se pudo completar la firma.')) . ($requestId !== '' ? ' [' . $requestId . ']' : ''));
    }
    return ['body' => $response, 'status' => $status, 'content_type' => $contentType];
}

function stampbyme_resolve_certificate_paths(int $tenantId, ?int $professionalId = null, string $owner = 'auto'): array
{
    $owner = strtolower(trim($owner));
    if ($owner === 'tenant') {
        return stampbyme_certificate_paths($tenantId);
    }
    if ($owner === 'professional' && ($professionalId === null || $professionalId <= 0)) {
        throw new RuntimeException('No se pudo identificar el certificado personal del profesional.');
    }
    if ($professionalId !== null && $professionalId > 0 && in_array($owner, ['auto', 'professional'], true)) {
        $professionalPaths = stampbyme_certificate_paths($tenantId, $professionalId);
        if ($owner === 'professional' || (is_file($professionalPaths['certificate']) && is_file($professionalPaths['private_key']))) {
            return $professionalPaths;
        }
    }
    return stampbyme_certificate_paths($tenantId);
}

function stampbyme_signature_available(int $tenantId, ?int $professionalId = null): bool
{
    $paths = stampbyme_resolve_certificate_paths($tenantId, $professionalId);
    return is_file($paths['certificate'] ?? '')
        && is_file($paths['private_key'] ?? '')
        && stampbyme_api_url() !== ''
        && stampbyme_api_key() !== '';
}

function stampbyme_certificate_choices(int $tenantId, ?int $professionalId = null): array
{
    $tenantPaths = stampbyme_certificate_paths($tenantId);
    $professionalPaths = $professionalId !== null && $professionalId > 0
        ? stampbyme_certificate_paths($tenantId, $professionalId)
        : [];
    $tenantAvailable = is_file($tenantPaths['certificate']) && is_file($tenantPaths['private_key']);
    $professionalAvailable = !empty($professionalPaths)
        && is_file($professionalPaths['certificate'])
        && is_file($professionalPaths['private_key']);
    return [
        'tenant' => $tenantAvailable,
        'professional' => $professionalAvailable,
        'default' => $professionalAvailable ? 'professional' : ($tenantAvailable ? 'tenant' : ''),
        'api_configured' => stampbyme_api_url() !== '' && stampbyme_api_key() !== '',
    ];
}

function stampbyme_selected_certificate_fingerprint(int $tenantId, ?int $professionalId = null, string $owner = 'auto'): string
{
    $paths = stampbyme_resolve_certificate_paths($tenantId, $professionalId, $owner);
    if (is_file($paths['metadata'] ?? '')) {
        $metadata = json_decode((string) file_get_contents($paths['metadata']), true);
        if (is_array($metadata) && !empty($metadata['fingerprint_sha256'])) {
            return (string) $metadata['fingerprint_sha256'];
        }
    }
    return is_file($paths['certificate'] ?? '')
        ? (string) openssl_x509_fingerprint((string) file_get_contents($paths['certificate']), 'sha256')
        : '';
}

function stampbyme_sign_pdf_file(int $tenantId, string $pdfPath, ?int $professionalId = null, string $owner = 'auto'): string
{
    $paths = stampbyme_resolve_certificate_paths($tenantId, $professionalId, $owner);
    if (!is_file($pdfPath) || !is_file($paths['certificate']) || !is_file($paths['private_key'])) {
        throw new RuntimeException('No hay un PDF válido o un certificado de firma configurado.');
    }
    $certificatePem = (string) file_get_contents($paths['certificate']);
    $privateKeyPem = (string) file_get_contents($paths['private_key']);
    $chainPem = is_file($paths['chain']) ? (string) file_get_contents($paths['chain']) : '';
    if (!openssl_x509_check_private_key($certificatePem, $privateKeyPem)) {
        throw new RuntimeException('El certificado configurado no coincide con su clave privada.');
    }

    $preparedResponse = stampbyme_execute('/v1/pdf/signatures', [
        'pdf' => new CURLFile($pdfPath, 'application/pdf', basename($pdfPath)),
        'certificate_pem' => $certificatePem,
        'chain_pem' => $chainPem,
    ], ['Accept: application/json']);
    $prepared = json_decode($preparedResponse['body'], true);
    if (!is_array($prepared)) {
        throw new RuntimeException('StampByMe devolvió una preparación no válida.');
    }
    $dataToSign = base64_decode((string) ($prepared['data_to_sign'] ?? ''), true);
    $signingId = trim((string) ($prepared['signing_id'] ?? ''));
    if ($dataToSign === false || $dataToSign === '' || $signingId === '') {
        throw new RuntimeException('StampByMe no devolvió los datos necesarios para firmar.');
    }
    $privateKey = openssl_pkey_get_private($privateKeyPem);
    if ($privateKey === false || !openssl_sign($dataToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('OpenSSL no pudo generar la firma local.');
    }
    $completed = stampbyme_execute(
        '/v1/pdf/signatures/' . rawurlencode($signingId) . '/complete',
        json_encode(['signature_value' => base64_encode($signature)], JSON_THROW_ON_ERROR),
        ['Content-Type: application/json', 'Accept: application/pdf']
    );
    if (!str_starts_with(strtolower($completed['content_type']), 'application/pdf')) {
        throw new RuntimeException('StampByMe no devolvió un PDF firmado.');
    }
    unset($privateKeyPem, $privateKey, $dataToSign, $signature);
    return $completed['body'];
}

function stampbyme_sign_pdf_contents(int $tenantId, string $pdfContents, ?int $professionalId = null, string $owner = 'auto'): string
{
    $temporary = tempnam(sys_get_temp_dir(), 'sgp-sign-');
    if ($temporary === false || file_put_contents($temporary, $pdfContents, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo preparar temporalmente el PDF para firmarlo.');
    }
    try {
        return stampbyme_sign_pdf_file($tenantId, $temporary, $professionalId, $owner);
    } finally {
        @unlink($temporary);
    }
}

function stampbyme_sign_pdf_to_file(int $tenantId, string $sourcePath, string $destinationPath, ?int $professionalId = null, string $owner = 'auto'): void
{
    $signedPdf = stampbyme_sign_pdf_file($tenantId, $sourcePath, $professionalId, $owner);
    if (file_put_contents($destinationPath, $signedPdf, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo guardar el PDF firmado.');
    }
}
