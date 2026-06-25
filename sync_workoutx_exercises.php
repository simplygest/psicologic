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

function sync_out($message)
{
    echo htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') . ($GLOBALS['is_cli'] ? PHP_EOL : "<br>\n");
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    @flush();
}

function sync_table_exists(mysqli $mysqli, string $table): bool
{
    $table = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

function sync_column_exists(mysqli $mysqli, string $table, string $column): bool
{
    $table = $mysqli->real_escape_string($table);
    $column = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function sync_index_exists(mysqli $mysqli, string $table, string $index): bool
{
    $table = $mysqli->real_escape_string($table);
    $index = $mysqli->real_escape_string($index);
    $res = $mysqli->query("SHOW INDEX FROM `$table` WHERE Key_name = '$index'");
    return $res && $res->num_rows > 0;
}

function sync_ensure_workoutx_schema(mysqli $mysqli): void
{
    if (!sync_table_exists($mysqli, 'fitness_exercises')) {
        throw new RuntimeException('No existe fitness_exercises. Importa primero el conocimiento Fitness.');
    }

    $columns = [
        'image_url' => "ALTER TABLE fitness_exercises ADD image_url VARCHAR(500) DEFAULT NULL AFTER source_ids",
        'aliases' => "ALTER TABLE fitness_exercises ADD aliases TEXT DEFAULT NULL AFTER image_url",
        'external_source' => "ALTER TABLE fitness_exercises ADD external_source VARCHAR(80) DEFAULT NULL AFTER aliases",
        'external_id' => "ALTER TABLE fitness_exercises ADD external_id VARCHAR(120) DEFAULT NULL AFTER external_source",
        'workoutx_body_part' => "ALTER TABLE fitness_exercises ADD workoutx_body_part VARCHAR(120) DEFAULT NULL AFTER external_id",
        'workoutx_target' => "ALTER TABLE fitness_exercises ADD workoutx_target VARCHAR(120) DEFAULT NULL AFTER workoutx_body_part",
        'workoutx_equipment' => "ALTER TABLE fitness_exercises ADD workoutx_equipment VARCHAR(120) DEFAULT NULL AFTER workoutx_target"
    ];
    foreach ($columns as $column => $sql) {
        if (!sync_column_exists($mysqli, 'fitness_exercises', $column)) {
            $mysqli->query($sql);
        }
    }
    if (!sync_index_exists($mysqli, 'fitness_exercises', 'idx_fitness_exercises_external')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD INDEX idx_fitness_exercises_external (external_source, external_id)");
    }

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS fitness_workoutx_muscle_map (
            bodymuscles_id VARCHAR(100) NOT NULL,
            workoutx_body_part VARCHAR(120) NOT NULL,
            workoutx_target VARCHAR(120) NOT NULL,
            match_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (bodymuscles_id, workoutx_body_part, workoutx_target),
            INDEX idx_workoutx_muscle_target (workoutx_target),
            INDEX idx_workoutx_muscle_body_part (workoutx_body_part)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function sync_local_name_candidates(array $exercise): array
{
    $names = [];
    foreach (['name_en', 'name_es'] as $field) {
        if (!empty($exercise[$field])) {
            $names[] = $exercise[$field];
        }
    }
    if (!empty($exercise['aliases'])) {
        foreach (preg_split('/[,;|]+/', (string) $exercise['aliases']) as $alias) {
            if (trim($alias) !== '') {
                $names[] = trim($alias);
            }
        }
    }
    return array_values(array_unique($names));
}

function sync_match_score(array $remote, array $local): int
{
    $remote_name = workoutx_normalize_text($remote['name'] ?? '');
    if ($remote_name === '') {
        return 0;
    }
    $best = 0;
    foreach (sync_local_name_candidates($local) as $name) {
        $local_name = workoutx_normalize_text($name);
        if ($local_name === '') {
            continue;
        }
        if ($remote_name === $local_name) {
            $best = max($best, 100);
        } elseif (strpos($remote_name, $local_name) !== false || strpos($local_name, $remote_name) !== false) {
            $best = max($best, 78);
        } else {
            similar_text($remote_name, $local_name, $percent);
            $best = max($best, (int) round($percent));
        }
    }
    return $best;
}

function sync_best_local_match(array $remote, array $local_exercises): ?array
{
    $best = null;
    $best_score = 0;
    foreach ($local_exercises as $local) {
        if (!empty($local['external_id']) && ($local['external_source'] ?? '') === 'workoutx' && $local['external_id'] !== ($remote['id'] ?? '')) {
            continue;
        }
        $score = sync_match_score($remote, $local);
        if ($score > $best_score) {
            $best = $local;
            $best_score = $score;
        }
    }
    if ($best && $best_score >= 72) {
        $best['_match_score'] = $best_score;
        return $best;
    }
    return null;
}

function sync_remote_value(array $remote, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($remote[$key]) && trim((string) $remote[$key]) !== '') {
            $value = $remote[$key];
            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map('strval', $value)));
            }
            return trim((string) $value);
        }
    }
    return '';
}

function sync_remote_exercise_id(array $remote): string
{
    $external_id = sync_remote_value($remote, ['id', 'exerciseId', 'exercise_id']);
    $seed = $external_id !== '' ? $external_id : sync_remote_value($remote, ['name']);
    $seed = preg_replace('/[^a-z0-9]+/i', '_', $seed);
    $seed = trim((string) $seed, '_');
    if ($seed === '') {
        $seed = substr(sha1(json_encode($remote)), 0, 16);
    }
    return 'WX_' . substr($seed, 0, 27);
}

function sync_header_int(array $headers, string $key): ?int
{
    if (!isset($headers[$key]) || trim((string) $headers[$key]) === '') {
        return null;
    }
    return (int) $headers[$key];
}

function sync_append_alias($aliases, $remote_name)
{
    $aliases = trim((string) $aliases);
    $remote_name = trim((string) $remote_name);
    if ($remote_name === '') {
        return $aliases;
    }
    $existing = array_map('workoutx_normalize_text', preg_split('/[,;|]+/', $aliases));
    if (in_array(workoutx_normalize_text($remote_name), $existing, true)) {
        return $aliases;
    }
    return $aliases !== '' ? $aliases . ', ' . $remote_name : $remote_name;
}

try {
    if (!workoutx_available()) {
        throw new RuntimeException('WorkoutX no esta configurado. Define workoutx_api_key en config.local.php o en la configuracion del tenant.');
    }
    sync_ensure_workoutx_schema($mysqli);

    sync_out('Cargando ejercicios locales...');
    $local_exercises = [];
    $res = $mysqli->query("SELECT * FROM fitness_exercises WHERE active = 1");
    while ($row = $res->fetch_assoc()) {
        $local_exercises[$row['exercise_id']] = $row;
    }
    sync_out('Ejercicios locales: ' . count($local_exercises));

    $update = $mysqli->prepare("
        UPDATE fitness_exercises
        SET external_source = 'workoutx',
            external_id = ?,
            image_url = COALESCE(NULLIF(?, ''), image_url),
            aliases = COALESCE(NULLIF(?, ''), aliases),
            workoutx_body_part = ?,
            workoutx_target = ?,
            workoutx_equipment = ?
        WHERE exercise_id = ?
        LIMIT 1
    ");
    $map_stmt = $mysqli->prepare("
        INSERT INTO fitness_workoutx_muscle_map (bodymuscles_id, workoutx_body_part, workoutx_target, match_count)
        VALUES (?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE match_count = match_count + 1, last_synced_at = CURRENT_TIMESTAMP
    ");
    $regions_stmt = $mysqli->prepare("SELECT bodymuscles_id FROM fitness_exercise_regions WHERE exercise_id = ?");
    $insert_remote = $mysqli->prepare("
        INSERT INTO fitness_exercises
            (exercise_id, name_en, name_es, category, difficulty, description_es, cues_es, image_url, aliases, external_source, external_id, workoutx_body_part, workoutx_target, workoutx_equipment, active)
        VALUES
            (?, ?, ?, ?, ?, ?, '', ?, ?, 'workoutx', ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            image_url = COALESCE(NULLIF(VALUES(image_url), ''), image_url),
            aliases = COALESCE(NULLIF(VALUES(aliases), ''), aliases),
            external_source = 'workoutx',
            external_id = COALESCE(NULLIF(VALUES(external_id), ''), external_id),
            workoutx_body_part = VALUES(workoutx_body_part),
            workoutx_target = VALUES(workoutx_target),
            workoutx_equipment = VALUES(workoutx_equipment),
            updated_at = CURRENT_TIMESTAMP
    ");
    $map_lookup = $mysqli->prepare("
        SELECT bodymuscles_id,
               MIN(CASE WHEN workoutx_body_part = ? AND workoutx_target = ? THEN 0 ELSE 1 END) AS match_priority
        FROM fitness_workoutx_muscle_map
        WHERE (workoutx_body_part = ? AND workoutx_target = ?)
           OR (workoutx_target = ? AND ? <> '')
           OR (workoutx_body_part = ? AND ? <> '')
        GROUP BY bodymuscles_id
        ORDER BY match_priority ASC
    ");
    $region_insert = $mysqli->prepare("
        INSERT IGNORE INTO fitness_exercise_regions (exercise_id, bodymuscles_id, role, intensity)
        VALUES (?, ?, 'primary', 80)
    ");

    $limit = 200;
    $offset = 0;
    $remote_count = 0;
    $matched = 0;
    $unmatched = 0;
    $imported = 0;
    $errors = 0;
    $rate_limit_waits = 0;
    $quota_exhausted = false;

    while (true) {
        $response = workoutx_list_exercises($limit, $offset);
        if (empty($response['success'])) {
            $error_message = (string) ($response['error'] ?? 'error desconocido');
            $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
            $quota_remaining = sync_header_int($headers, 'quota_remaining');
            if ($quota_remaining !== null && $quota_remaining <= 0) {
                $quota_exhausted = true;
                sync_out('Cuota WorkoutX agotada en offset ' . $offset . '. Sincronizacion pausada hasta que se renueve la cuota.');
                break;
            }
            if (stripos($error_message, 'quota') !== false) {
                $quota_exhausted = true;
                sync_out('Cuota WorkoutX agotada en offset ' . $offset . ': ' . $error_message);
                break;
            }
            if (stripos($error_message, 'rate limit') !== false && $rate_limit_waits < 12) {
                $rate_limit_waits++;
                sync_out('WorkoutX ha aplicado rate limit en offset ' . $offset . '. Esperando 65 segundos antes de continuar...');
                sleep(65);
                continue;
            }
            $errors++;
            sync_out('Error WorkoutX en offset ' . $offset . ': ' . $error_message);
            break;
        }
        $remote_exercises = workoutx_extract_exercises($response['data'] ?? []);
        if (!$remote_exercises) {
            break;
        }

        foreach ($remote_exercises as $remote) {
            if (!is_array($remote)) {
                continue;
            }
            $remote_count++;
            $local = sync_best_local_match($remote, $local_exercises);
            $external_id = sync_remote_value($remote, ['id', 'exerciseId', 'exercise_id']);
            $gif_url = sync_remote_value($remote, ['gifUrl', 'gif_url', 'imageUrl', 'image_url']);
            $body_part = sync_remote_value($remote, ['bodyPart', 'body_part']);
            $target = sync_remote_value($remote, ['target', 'targetMuscle', 'target_muscle']);
            $equipment = sync_remote_value($remote, ['equipment', 'equipmentName', 'equipment_name']);

            if (!$local) {
                $exercise_id = sync_remote_exercise_id($remote);
                $name = sync_remote_value($remote, ['name']);
                if ($name === '') {
                    $unmatched++;
                    continue;
                }
                $description = sync_remote_value($remote, ['description', 'instructions', 'instructionsText', 'instructions_text']);
                $aliases = sync_append_alias('', $name);
                $category = $body_part;
                $difficulty = sync_remote_value($remote, ['difficulty', 'level']);

                $insert_remote->bind_param('ssssssssssss', $exercise_id, $name, $name, $category, $difficulty, $description, $gif_url, $aliases, $external_id, $body_part, $target, $equipment);
                $insert_remote->execute();

                if ($body_part !== '' || $target !== '') {
                    $map_lookup->bind_param('ssssssss', $body_part, $target, $body_part, $target, $target, $target, $body_part, $body_part);
                    $map_lookup->execute();
                    $mapped_regions = $map_lookup->get_result();
                    while ($mapped_region = $mapped_regions->fetch_assoc()) {
                        $bodymuscles_id = (string) ($mapped_region['bodymuscles_id'] ?? '');
                        if ($bodymuscles_id !== '') {
                            $region_insert->bind_param('ss', $exercise_id, $bodymuscles_id);
                            $region_insert->execute();
                        }
                    }
                }

                $local_exercises[$exercise_id] = [
                    'exercise_id' => $exercise_id,
                    'name_en' => $name,
                    'name_es' => $name,
                    'aliases' => $aliases,
                    'external_source' => 'workoutx',
                    'external_id' => $external_id,
                    'image_url' => $gif_url
                ];
                $unmatched++;
                $imported++;
                continue;
            }

            $aliases = sync_append_alias($local['aliases'] ?? '', $remote['name'] ?? '');
            $exercise_id = (string) $local['exercise_id'];

            $update->bind_param('sssssss', $external_id, $gif_url, $aliases, $body_part, $target, $equipment, $exercise_id);
            $update->execute();
            $matched++;

            if ($body_part !== '' || $target !== '') {
                $regions_stmt->bind_param('s', $exercise_id);
                $regions_stmt->execute();
                $regions = $regions_stmt->get_result();
                while ($region = $regions->fetch_assoc()) {
                    $bodymuscles_id = (string) ($region['bodymuscles_id'] ?? '');
                    if ($bodymuscles_id !== '') {
                        $map_stmt->bind_param('sss', $bodymuscles_id, $body_part, $target);
                        $map_stmt->execute();
                    }
                }
            }

            $local_exercises[$exercise_id]['external_source'] = 'workoutx';
            $local_exercises[$exercise_id]['external_id'] = $external_id;
            $local_exercises[$exercise_id]['image_url'] = $gif_url ?: ($local_exercises[$exercise_id]['image_url'] ?? '');
            $local_exercises[$exercise_id]['aliases'] = $aliases;
        }

        $usage = is_array($response['headers'] ?? null) ? $response['headers'] : [];
        $quota_text = !empty($usage['quota_remaining']) ? ' | Cuota restante: ' . $usage['quota_remaining'] : '';
        sync_out('Procesados remotos: ' . $remote_count . ' | Casados: ' . $matched . ' | Importados nuevos: ' . $imported . ' | Sin match: ' . $unmatched . $quota_text);
        $quota_remaining = sync_header_int(is_array($usage) ? $usage : [], 'quota_remaining');
        if ($quota_remaining !== null && $quota_remaining <= 0) {
            $quota_exhausted = true;
            sync_out('Cuota WorkoutX agotada. Sincronizacion pausada para evitar llamadas rechazadas.');
            break;
        }
        if (count($remote_exercises) < $limit) {
            break;
        }
        $offset += $limit;
        usleep(250000);
    }

    sync_out('Sincronizacion completada.');
    sync_out(json_encode([
        'remote_processed' => $remote_count,
        'matched' => $matched,
        'unmatched' => $unmatched,
        'imported' => $imported,
        'rate_limit_waits' => $rate_limit_waits,
        'quota_exhausted' => $quota_exhausted,
        'errors' => $errors
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    http_response_code(500);
    sync_out('ERROR: ' . $e->getMessage());
}
