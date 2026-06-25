<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/workoutx_helpers.php';

$is_cli = PHP_SAPI === 'cli';
if (!$is_cli) {
    header('Content-Type: text/html; charset=utf-8');
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'], true)) {
        http_response_code(403);
        echo 'No autorizado';
        exit;
    }
}

set_time_limit(0);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', '0');

function audit_out($message)
{
    echo htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') . ($GLOBALS['is_cli'] ? PHP_EOL : "<br>\n");
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    @flush();
}

function audit_request_int(string $key, int $default): int
{
    $source = PHP_SAPI === 'cli' ? getopt('', [$key . '::']) : $_GET;
    return isset($source[$key]) ? (int) $source[$key] : $default;
}

function audit_remote_value(array $remote, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($remote[$key]) && trim((string) $remote[$key]) !== '') {
            return trim((string) $remote[$key]);
        }
    }
    return '';
}

try {
    if (!workoutx_available()) {
        throw new RuntimeException('El servicio de ejercicios no esta configurado.');
    }

    $limit = max(1, min(500, audit_request_int('limit', 500)));
    $sleep_ms = max(0, min(2000, audit_request_int('sleep_ms', 250)));
    $max_pages = max(1, min(500, audit_request_int('max_pages', 200)));

    audit_out('Auditando ejercicios remotos...');
    audit_out('Limit: ' . $limit . ' | Max pages: ' . $max_pages);

    $remote = [];
    $offset = 0;
    $pages = 0;
    $errors = 0;
    $quota_exhausted = false;
    $last_headers = [];

    while ($pages < $max_pages) {
        $response = workoutx_list_exercises($limit, $offset);
        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
        $last_headers = $headers;

        if (empty($response['success'])) {
            $errors++;
            $error = (string) ($response['error'] ?? 'error desconocido');
            audit_out('ERROR offset ' . $offset . ': ' . $error);
            if (stripos($error, 'quota') !== false || (isset($headers['quota_remaining']) && (int) $headers['quota_remaining'] <= 0)) {
                $quota_exhausted = true;
            }
            break;
        }

        $items = workoutx_extract_exercises($response['data'] ?? []);
        $count = count($items);
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $external_id = audit_remote_value($item, ['id', 'exerciseId', 'exercise_id']);
            if ($external_id === '') {
                $external_id = sha1(json_encode($item));
            }
            $remote[$external_id] = [
                'id' => $external_id,
                'name' => audit_remote_value($item, ['name']),
                'bodyPart' => audit_remote_value($item, ['bodyPart', 'body_part']),
                'target' => audit_remote_value($item, ['target', 'targetMuscle', 'target_muscle']),
                'equipment' => audit_remote_value($item, ['equipment', 'equipmentName', 'equipment_name'])
            ];
        }

        $quota = $headers['quota_remaining'] ?? '';
        $rate = $headers['rate_remaining'] ?? '';
        audit_out('Offset ' . $offset . ' | Recibidos: ' . $count . ' | Unicos remotos: ' . count($remote) . ($quota !== '' ? ' | cuota ' . $quota : '') . ($rate !== '' ? ' | rate ' . $rate : ''));

        $pages++;
        if ($count < $limit) {
            break;
        }
        $offset += $limit;
        if ($sleep_ms > 0) {
            usleep($sleep_ms * 1000);
        }
    }

    $local = [];
    $res = $mysqli->query("SELECT exercise_id, external_id, name_en, name_es FROM fitness_exercises WHERE external_source = 'workoutx' AND external_id IS NOT NULL AND external_id <> '' AND active = 1");
    while ($row = $res->fetch_assoc()) {
        $local[(string) $row['external_id']] = $row;
    }

    $missing = [];
    foreach ($remote as $external_id => $item) {
        if (!isset($local[$external_id])) {
            $missing[] = $item;
        }
    }

    $extra = [];
    foreach ($local as $external_id => $item) {
        if (!isset($remote[$external_id])) {
            $extra[] = [
                'external_id' => $external_id,
                'exercise_id' => $item['exercise_id'] ?? '',
                'name_en' => $item['name_en'] ?? '',
                'name_es' => $item['name_es'] ?? ''
            ];
        }
    }

    audit_out('Auditoria completada.');
    audit_out(json_encode([
        'remote_unique' => count($remote),
        'local_workoutx' => count($local),
        'missing_in_local' => count($missing),
        'local_not_seen_remote' => count($extra),
        'quota_exhausted' => $quota_exhausted,
        'errors' => $errors,
        'last_headers' => $last_headers,
        'missing_sample' => array_slice($missing, 0, 25),
        'local_not_seen_sample' => array_slice($extra, 0, 25)
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    http_response_code(500);
    audit_out('ERROR: ' . $e->getMessage());
}
