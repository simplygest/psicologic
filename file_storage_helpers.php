<?php

declare(strict_types=1);

require_once __DIR__ . '/azure_blob_helpers.php';

function praxis_storage_primary(): string
{
    $primary = strtolower(trim((string) psicologic_config_value('file_storage_primary', 'local')));
    return in_array($primary, ['local', 'azure_blob'], true) ? $primary : 'local';
}

function praxis_storage_blob_available(): bool
{
    return praxis_blob_account() !== ''
        && praxis_blob_container() !== ''
        && trim((string) getenv('IDENTITY_ENDPOINT')) !== ''
        && trim((string) getenv('IDENTITY_HEADER')) !== '';
}

function praxis_storage_normalize_relative(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
    if ($relativePath === '' || str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
        throw new InvalidArgumentException('Ruta de almacenamiento no v&aacute;lida.');
    }
    return $relativePath;
}

function praxis_storage_log_secondary_failure(string $operation, string $relativePath, Throwable $exception): void
{
    error_log(sprintf(
        'Praxis dual storage secondary failure [%s] %s: %s',
        $operation,
        $relativePath,
        $exception->getMessage()
    ));
}

function praxis_storage_upload_local_copy(string $relativePath, string $localPath, string $contentType = ''): bool
{
    $relativePath = praxis_storage_normalize_relative($relativePath);
    if (!is_file($localPath) || !is_readable($localPath)) {
        throw new RuntimeException('No existe la copia local que debe enviarse a Azure.');
    }
    if (!praxis_storage_blob_available()) {
        if (praxis_storage_primary() === 'azure_blob') {
            throw new RuntimeException('Azure Blob Storage es el almacenamiento principal, pero no est&aacute; disponible.');
        }
        return false;
    }

    if ($contentType === '') {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $contentType = $finfo->file($localPath) ?: 'application/octet-stream';
    }
    // Recordings can be hundreds of MB: use staged blocks instead of loading the file into PHP memory.
    if (filesize($localPath) > 8 * 1024 * 1024) {
        praxis_blob_put_file($relativePath, $localPath, $contentType);
    } else {
        $contents = file_get_contents($localPath);
        if (!is_string($contents)) throw new RuntimeException('No se pudo leer la copia local para replicarla.');
        praxis_blob_put($relativePath, $contents, $contentType);
    }
    return true;
}

function praxis_storage_after_local_write(string $relativePath, string $localPath, string $contentType = ''): void
{
    try {
        praxis_storage_upload_local_copy($relativePath, $localPath, $contentType);
    } catch (Throwable $exception) {
        if (praxis_storage_primary() === 'azure_blob') {
            @unlink($localPath);
            throw $exception;
        }
        praxis_storage_log_secondary_failure('write', $relativePath, $exception);
    }
}

function praxis_storage_move_uploaded_file(string $temporaryPath, string $relativePath, string $localPath, string $contentType = ''): bool
{
    if (!move_uploaded_file($temporaryPath, $localPath)) {
        return false;
    }
    praxis_storage_after_local_write($relativePath, $localPath, $contentType);
    return true;
}

function praxis_storage_write_contents(string $relativePath, string $localPath, string $contents, string $contentType = ''): int|false
{
    $written = file_put_contents($localPath, $contents, LOCK_EX);
    if ($written === false) {
        return false;
    }
    praxis_storage_after_local_write($relativePath, $localPath, $contentType);
    return $written;
}

function praxis_storage_copy_local(string $sourcePath, string $relativePath, string $localPath, string $contentType = ''): bool
{
    if (!copy($sourcePath, $localPath)) {
        return false;
    }
    praxis_storage_after_local_write($relativePath, $localPath, $contentType);
    return true;
}

function praxis_storage_register_generated_file(string $relativePath, string $localPath, string $contentType = ''): void
{
    praxis_storage_after_local_write($relativePath, $localPath, $contentType);
}

function praxis_storage_resolve_local_path(string $relativePath): string
{
    $relativePath = praxis_storage_normalize_relative($relativePath);
    $localPath = app_protected_path_from_relative($relativePath);
    if ($localPath !== '' && is_file($localPath)) {
        return $localPath;
    }
    if (!praxis_storage_blob_available()) {
        return $localPath;
    }

    try {
        $response = praxis_blob_get($relativePath);
        if ($localPath === '') {
            return '';
        }
        $directory = dirname($localPath);
        if (!app_ensure_dir($directory)) {
            throw new RuntimeException('No se pudo preparar la copia local de respaldo.');
        }
        if (file_put_contents($localPath, (string) $response['body'], LOCK_EX) === false) {
            throw new RuntimeException('No se pudo materializar el archivo recuperado desde Azure.');
        }
        return $localPath;
    } catch (Throwable $exception) {
        // Una lectura de existencia sobre un objeto que todavía no se ha creado es normal.
        // No debe llenar el registro de errores cada vez que se consulta su estado.
        if (!str_contains($exception->getMessage(), 'HTTP 404')) {
            praxis_storage_log_secondary_failure('read', $relativePath, $exception);
        }
        return $localPath;
    }
}

function praxis_storage_delete(string $relativePath): bool
{
    $relativePath = praxis_storage_normalize_relative($relativePath);
    $localPath = app_protected_path_from_relative($relativePath);
    $localDeleted = $localPath === '' || !is_file($localPath) || @unlink($localPath);

    if (praxis_storage_blob_available()) {
        try {
            praxis_blob_delete($relativePath);
        } catch (Throwable $exception) {
            // Borrar un objeto inexistente es equivalente al resultado buscado.
            if (!str_contains($exception->getMessage(), 'HTTP 404')) {
                if (praxis_storage_primary() === 'azure_blob') {
                    throw $exception;
                }
                praxis_storage_log_secondary_failure('delete', $relativePath, $exception);
            }
        }
    } elseif (praxis_storage_primary() === 'azure_blob') {
        throw new RuntimeException('Azure Blob Storage es el almacenamiento principal, pero no est&aacute; disponible.');
    }
    return $localDeleted;
}

/**
 * Delete an application file while keeping both persistent stores in sync.
 * Temporary files and files outside uploads are removed only from local disk.
 */
function praxis_storage_delete_path(string $path): bool
{
    $path = trim($path);
    if ($path === '') {
        return true;
    }

    $normalized = str_replace('\\', '/', $path);
    $root = rtrim(str_replace('\\', '/', __DIR__), '/');
    $relative = '';
    if (str_starts_with($normalized, $root . '/')) {
        $relative = substr($normalized, strlen($root) + 1);
    } elseif (!preg_match('#^(?:[A-Za-z]:/|/)#', $normalized)) {
        $relative = ltrim($normalized, '/');
    }

    if (
        $relative !== ''
        && (str_starts_with($relative, 'uploads/') || str_starts_with($relative, '_protected/uploads/'))
    ) {
        return praxis_storage_delete($relative);
    }

    return !is_file($path) || @unlink($path);
}
