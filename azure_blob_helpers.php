<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function praxis_blob_account(): string
{
    return trim((string) psicologic_config_value('azure_blob_account', 'sgpraxisstorage'));
}

function praxis_blob_container(): string
{
    return trim((string) psicologic_config_value('azure_blob_container', 'sgpraxiscontainer1'));
}

function praxis_blob_managed_identity_token(): string
{
    static $cachedToken = '';
    static $cachedUntil = 0;

    if ($cachedToken !== '' && $cachedUntil > time() + 60) {
        return $cachedToken;
    }

    $endpoint = trim((string) getenv('IDENTITY_ENDPOINT'));
    $identityHeader = trim((string) getenv('IDENTITY_HEADER'));
    if ($endpoint === '' || $identityHeader === '') {
        throw new RuntimeException('La identidad administrada no est&aacute; disponible en este entorno.');
    }

    $separator = str_contains($endpoint, '?') ? '&' : '?';
    $url = $endpoint . $separator . http_build_query([
        'resource' => 'https://storage.azure.com/',
        'api-version' => '2019-08-01',
    ], '', '&', PHP_QUERY_RFC3986);

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-IDENTITY-HEADER: ' . $identityHeader],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    $decoded = is_string($body) ? json_decode($body, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['access_token'])) {
        throw new RuntimeException('No se pudo obtener el token de Azure' . ($error !== '' ? ': ' . $error : '.'));
    }

    $cachedToken = (string) $decoded['access_token'];
    $expiresOn = $decoded['expires_on'] ?? 0;
    $cachedUntil = is_numeric($expiresOn) ? (int) $expiresOn : time() + 300;
    return $cachedToken;
}

function praxis_blob_url(string $blobName = '', array $query = []): string
{
    $account = praxis_blob_account();
    $container = praxis_blob_container();
    if ($account === '' || $container === '') {
        throw new RuntimeException('Falta configurar la cuenta o el contenedor de Azure Blob Storage.');
    }

    $url = 'https://' . rawurlencode($account) . '.blob.core.windows.net/' . rawurlencode($container);
    if ($blobName !== '') {
        $segments = array_map('rawurlencode', explode('/', ltrim($blobName, '/')));
        $url .= '/' . implode('/', $segments);
    }
    if ($query) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    return $url;
}

function praxis_blob_request(string $method, string $blobName = '', array $query = [], ?string $body = null, array $extraHeaders = []): array
{
    $headers = array_merge([
        'Authorization: Bearer ' . praxis_blob_managed_identity_token(),
        'x-ms-version: 2023-11-03',
        'x-ms-date: ' . gmdate('D, d M Y H:i:s') . ' GMT',
        'x-ms-client-request-id: ' . bin2hex(random_bytes(16)),
    ], $extraHeaders);

    $curl = curl_init(praxis_blob_url($blobName, $query));
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $error = curl_error($curl);
    curl_close($curl);

    if (!is_string($raw)) {
        throw new RuntimeException('No se pudo conectar con Azure Blob Storage: ' . $error);
    }

    return [
        'status' => $status,
        'headers' => substr($raw, 0, $headerSize),
        'body' => substr($raw, $headerSize),
    ];
}

function praxis_blob_assert_success(array $response, array $allowedStatuses): void
{
    if (!in_array((int) ($response['status'] ?? 0), $allowedStatuses, true)) {
        $message = 'Azure Blob Storage devolvi&oacute; HTTP ' . (int) ($response['status'] ?? 0) . '.';
        $xml = @simplexml_load_string((string) ($response['body'] ?? ''));
        if ($xml !== false && isset($xml->Message)) {
            $message .= ' ' . trim((string) $xml->Message);
        }
        throw new RuntimeException($message);
    }
}

function praxis_blob_put(string $blobName, string $contents, string $contentType = 'application/octet-stream'): void
{
    $response = praxis_blob_request('PUT', $blobName, [], $contents, [
        'x-ms-blob-type: BlockBlob',
        'Content-Type: ' . $contentType,
        'Content-Length: ' . strlen($contents),
    ]);
    praxis_blob_assert_success($response, [201]);
}

function praxis_blob_put_file(string $blobName, string $localPath, string $contentType = 'application/octet-stream'): void
{
    $handle = fopen($localPath, 'rb');
    if (!$handle) throw new RuntimeException('No se pudo leer el archivo que debe enviarse a Azure.');
    $blockIds = [];
    try {
        for ($index = 0; !feof($handle); $index++) {
            $chunk = fread($handle, 4 * 1024 * 1024);
            if ($chunk === false) throw new RuntimeException('No se pudo leer un bloque del archivo.');
            if ($chunk === '') break;
            $blockId = base64_encode(str_pad((string) $index, 8, '0', STR_PAD_LEFT));
            $response = praxis_blob_request('PUT', $blobName, ['comp' => 'block', 'blockid' => $blockId], $chunk, ['Content-Length: ' . strlen($chunk)]);
            praxis_blob_assert_success($response, [201]);
            $blockIds[] = $blockId;
        }
    } finally { fclose($handle); }
    if (!$blockIds) throw new RuntimeException('El archivo que debe enviarse a Azure está vacío.');
    $xml = '<?xml version="1.0" encoding="utf-8"?><BlockList>' . implode('', array_map(fn($id) => '<Latest>' . htmlspecialchars($id, ENT_XML1) . '</Latest>', $blockIds)) . '</BlockList>';
    $response = praxis_blob_request('PUT', $blobName, ['comp' => 'blocklist'], $xml, ['Content-Type: application/xml', 'Content-Length: ' . strlen($xml), 'x-ms-blob-content-type: ' . $contentType]);
    praxis_blob_assert_success($response, [201]);
}

function praxis_blob_get(string $blobName): array
{
    $response = praxis_blob_request('GET', $blobName);
    praxis_blob_assert_success($response, [200]);
    return $response;
}

function praxis_blob_delete(string $blobName): void
{
    $response = praxis_blob_request('DELETE', $blobName, [], null, ['x-ms-delete-snapshots: include']);
    praxis_blob_assert_success($response, [202]);
}

function praxis_blob_list(string $prefix): array
{
    $response = praxis_blob_request('GET', '', [
        'restype' => 'container',
        'comp' => 'list',
        'prefix' => $prefix,
        'maxresults' => 100,
    ]);
    praxis_blob_assert_success($response, [200]);

    $xml = @simplexml_load_string((string) $response['body']);
    if ($xml === false) {
        throw new RuntimeException('Azure devolvi&oacute; un listado no v&aacute;lido.');
    }

    $items = [];
    foreach ($xml->Blobs->Blob ?? [] as $blob) {
        $items[] = [
            'name' => (string) $blob->Name,
            'size' => (int) ($blob->Properties->{'Content-Length'} ?? 0),
            'content_type' => (string) ($blob->Properties->{'Content-Type'} ?? 'application/octet-stream'),
            'last_modified' => (string) ($blob->Properties->{'Last-Modified'} ?? ''),
        ];
    }
    return $items;
}
