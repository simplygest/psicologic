<?php

function ensure_app_logs_table($mysqli)
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS app_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED DEFAULT NULL,
            target_type VARCHAR(40) DEFAULT NULL,
            target_id INT UNSIGNED DEFAULT NULL,
            action VARCHAR(80) NOT NULL,
            channel VARCHAR(20) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'ok',
            title VARCHAR(255) DEFAULT NULL,
            message TEXT DEFAULT NULL,
            metadata_json JSON DEFAULT NULL,
            ip_address VARCHAR(64) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_app_logs_tenant_created (tenant_id, created_at),
            KEY idx_app_logs_action (tenant_id, action),
            KEY idx_app_logs_target (tenant_id, target_type, target_id),
            KEY idx_app_logs_user (tenant_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function app_log($mysqli, array $data)
{
    try {
        ensure_app_logs_table($mysqli);

        $tenant_id = (int) ($data['tenant_id'] ?? (function_exists('current_tenant_id') ? current_tenant_id() : 0));
        if ($tenant_id <= 0) {
            return false;
        }

        $user_id = isset($data['user_id'])
            ? (int) $data['user_id']
            : (int) ($_SESSION['user_id'] ?? 0);
        $user_id = $user_id > 0 ? $user_id : null;

        $target_type = trim((string) ($data['target_type'] ?? ''));
        $target_type = $target_type !== '' ? substr($target_type, 0, 40) : null;
        $target_id = isset($data['target_id']) && (int) $data['target_id'] > 0 ? (int) $data['target_id'] : null;
        $action = substr(trim((string) ($data['action'] ?? '')), 0, 80);
        if ($action === '') {
            return false;
        }
        $channel = trim((string) ($data['channel'] ?? ''));
        $channel = $channel !== '' ? substr($channel, 0, 20) : null;
        $status = substr(trim((string) ($data['status'] ?? 'ok')), 0, 20) ?: 'ok';
        $title = trim((string) ($data['title'] ?? ''));
        $title = $title !== '' ? substr($title, 0, 255) : null;
        $message = trim((string) ($data['message'] ?? ''));
        $message = $message !== '' ? $message : null;
        $metadata = $data['metadata'] ?? null;
        $metadata_json = $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64) ?: null;
        $user_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null;

        $stmt = $mysqli->prepare("
            INSERT INTO app_logs
                (tenant_id, user_id, target_type, target_id, action, channel, status, title, message, metadata_json, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "iisissssssss",
            $tenant_id,
            $user_id,
            $target_type,
            $target_id,
            $action,
            $channel,
            $status,
            $title,
            $message,
            $metadata_json,
            $ip,
            $user_agent
        );
        return $stmt->execute();
    } catch (Throwable $e) {
        return false;
    }
}

function app_log_sensitive_access($mysqli, array $data)
{
    $patient_id = (int) ($data['patient_id'] ?? 0);
    if ($patient_id <= 0) {
        return false;
    }

    $resource_type = substr(trim((string) ($data['resource_type'] ?? 'clinical_record')), 0, 40);
    $resource_id = (int) ($data['resource_id'] ?? 0);
    $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
    $metadata['resource_type'] = $resource_type;
    if ($resource_id > 0) {
        $metadata['resource_id'] = $resource_id;
    }

    return app_log($mysqli, [
        'action' => substr(trim((string) ($data['action'] ?? 'sensitive_resource_accessed')), 0, 80),
        'status' => 'ok',
        'target_type' => 'patient',
        'target_id' => $patient_id,
        'title' => trim((string) ($data['title'] ?? 'Acceso a información sensible')),
        'message' => trim((string) ($data['message'] ?? '')),
        'metadata' => $metadata
    ]);
}

function app_log_normalize_comparable_value($value)
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_float($value) || is_int($value)) {
        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
    }
    return trim((string) $value);
}

function app_log_changed_field_labels(array $previous, array $current, array $labels)
{
    $changed = [];
    foreach ($labels as $field => $label) {
        if (app_log_normalize_comparable_value($previous[$field] ?? null) !== app_log_normalize_comparable_value($current[$field] ?? null)) {
            $changed[] = (string) $label;
        }
    }
    return array_values(array_unique($changed));
}
