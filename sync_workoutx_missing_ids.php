<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/workoutx_helpers.php';

$is_cli = PHP_SAPI === 'cli';
if (!$is_cli) {
    header('Content-Type: text/html; charset=utf-8');
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'superadmin', 'reception', 'administration', 'technical'], true)) {
        http_response_code(403);
        echo 'No autorizado';
        exit;
    }
}

set_time_limit(0);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', '0');

function missing_out($message)
{
    echo htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') . ($GLOBALS['is_cli'] ? PHP_EOL : "<br>\n");
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    @flush();
}

function missing_request_int(string $key, int $default): int
{
    if ($GLOBALS['is_cli']) {
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if (strpos($arg, $key . '=') === 0) {
                return (int) substr($arg, strlen($key) + 1);
            }
        }
        return $default;
    }
    return isset($_GET[$key]) ? (int) $_GET[$key] : $default;
}

function missing_table_exists(mysqli $mysqli, string $table): bool
{
    $table = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

function missing_column_exists(mysqli $mysqli, string $table, string $column): bool
{
    $table = $mysqli->real_escape_string($table);
    $column = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function missing_index_exists(mysqli $mysqli, string $table, string $index): bool
{
    $table = $mysqli->real_escape_string($table);
    $index = $mysqli->real_escape_string($index);
    $res = $mysqli->query("SHOW INDEX FROM `$table` WHERE Key_name = '$index'");
    return $res && $res->num_rows > 0;
}

function missing_ensure_schema(mysqli $mysqli): void
{
    if (!missing_table_exists($mysqli, 'fitness_exercises')) {
        throw new RuntimeException('No existe fitness_exercises.');
    }

    $columns = [
        'image_url' => "ALTER TABLE fitness_exercises ADD image_url VARCHAR(500) DEFAULT NULL AFTER source_ids",
        'aliases' => "ALTER TABLE fitness_exercises ADD aliases TEXT DEFAULT NULL AFTER image_url",
        'external_source' => "ALTER TABLE fitness_exercises ADD external_source VARCHAR(80) DEFAULT NULL AFTER aliases",
        'external_id' => "ALTER TABLE fitness_exercises ADD external_id VARCHAR(120) DEFAULT NULL AFTER external_source",
        'workoutx_body_part' => "ALTER TABLE fitness_exercises ADD workoutx_body_part VARCHAR(120) DEFAULT NULL AFTER external_id",
        'workoutx_target' => "ALTER TABLE fitness_exercises ADD workoutx_target VARCHAR(120) DEFAULT NULL AFTER workoutx_body_part",
        'workoutx_equipment' => "ALTER TABLE fitness_exercises ADD workoutx_equipment VARCHAR(120) DEFAULT NULL AFTER workoutx_target",
        'description_en' => "ALTER TABLE fitness_exercises ADD description_en TEXT DEFAULT NULL AFTER description_es",
        'instructions_en' => "ALTER TABLE fitness_exercises ADD instructions_en LONGTEXT DEFAULT NULL AFTER cues_es",
        'instructions_es' => "ALTER TABLE fitness_exercises ADD instructions_es LONGTEXT DEFAULT NULL AFTER instructions_en",
        'secondary_muscles_en' => "ALTER TABLE fitness_exercises ADD secondary_muscles_en TEXT DEFAULT NULL AFTER instructions_es",
        'calories_per_min' => "ALTER TABLE fitness_exercises ADD calories_per_min DECIMAL(6,2) DEFAULT NULL AFTER secondary_muscles_en",
        'workoutx_raw_json' => "ALTER TABLE fitness_exercises ADD workoutx_raw_json LONGTEXT DEFAULT NULL AFTER workoutx_equipment",
        'workoutx_details_synced_at' => "ALTER TABLE fitness_exercises ADD workoutx_details_synced_at DATETIME DEFAULT NULL AFTER workoutx_raw_json"
    ];
    foreach ($columns as $column => $sql) {
        if (!missing_column_exists($mysqli, 'fitness_exercises', $column)) {
            $mysqli->query($sql);
        }
    }
    if (!missing_index_exists($mysqli, 'fitness_exercises', 'idx_fitness_exercises_external')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD INDEX idx_fitness_exercises_external (external_source, external_id)");
    }
}

function missing_remote_value(array $remote, array $keys): string
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

function missing_json($value): string
{
    if (!is_array($value)) {
        return '';
    }
    return json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function missing_local_exercise_id(array $remote): string
{
    $external_id = missing_remote_value($remote, ['id', 'exerciseId', 'exercise_id']);
    $seed = $external_id !== '' ? $external_id : missing_remote_value($remote, ['name']);
    $seed = preg_replace('/[^a-z0-9]+/i', '_', $seed);
    $seed = trim((string) $seed, '_');
    if ($seed === '') {
        $seed = substr(sha1(json_encode($remote)), 0, 16);
    }
    return 'WX_' . substr($seed, 0, 27);
}

try {
    if (!workoutx_available()) {
        throw new RuntimeException('El servicio de ejercicios no esta configurado.');
    }
    missing_ensure_schema($mysqli);

    $start = max(1, missing_request_int('start', 1));
    $end = max($start, missing_request_int('end', 1800));
    $width = max(1, min(8, missing_request_int('width', 4)));
    $sleep_ms = max(0, min(2000, missing_request_int('sleep_ms', 220)));
    $max_requests = max(1, min(10000, missing_request_int('max_requests', 10000)));

    $existing = [];
    $res = $mysqli->query("SELECT external_id FROM fitness_exercises WHERE external_source = 'workoutx' AND external_id IS NOT NULL AND external_id <> ''");
    while ($row = $res->fetch_assoc()) {
        $existing[(string) $row['external_id']] = true;
    }

    $insert = $mysqli->prepare("
        INSERT INTO fitness_exercises
            (exercise_id, name_en, name_es, category, difficulty, description_es, description_en, cues_es,
             instructions_en, instructions_es, secondary_muscles_en, calories_per_min, image_url, aliases,
             external_source, external_id, workoutx_body_part, workoutx_target, workoutx_equipment,
             workoutx_raw_json, workoutx_details_synced_at, active)
        VALUES
            (?, ?, ?, ?, ?, '', NULLIF(?, ''), '', NULLIF(?, ''), '', NULLIF(?, ''), NULLIF(?, ''),
             ?, ?, 'workoutx', ?, ?, ?, ?, ?, NOW(), 1)
        ON DUPLICATE KEY UPDATE
            name_en = COALESCE(NULLIF(VALUES(name_en), ''), name_en),
            name_es = COALESCE(NULLIF(VALUES(name_es), ''), name_es),
            description_en = COALESCE(NULLIF(VALUES(description_en), ''), description_en),
            instructions_en = COALESCE(NULLIF(VALUES(instructions_en), ''), instructions_en),
            secondary_muscles_en = COALESCE(NULLIF(VALUES(secondary_muscles_en), ''), secondary_muscles_en),
            calories_per_min = COALESCE(VALUES(calories_per_min), calories_per_min),
            image_url = COALESCE(NULLIF(VALUES(image_url), ''), image_url),
            aliases = COALESCE(NULLIF(VALUES(aliases), ''), aliases),
            external_source = 'workoutx',
            external_id = COALESCE(NULLIF(VALUES(external_id), ''), external_id),
            workoutx_body_part = COALESCE(NULLIF(VALUES(workoutx_body_part), ''), workoutx_body_part),
            workoutx_target = COALESCE(NULLIF(VALUES(workoutx_target), ''), workoutx_target),
            workoutx_equipment = COALESCE(NULLIF(VALUES(workoutx_equipment), ''), workoutx_equipment),
            workoutx_raw_json = COALESCE(NULLIF(VALUES(workoutx_raw_json), ''), workoutx_raw_json),
            workoutx_details_synced_at = NOW(),
            updated_at = CURRENT_TIMESTAMP
    ");

    missing_out('Buscando huecos WorkoutX por ID directo...');
    missing_out('Rango: ' . str_pad((string) $start, $width, '0', STR_PAD_LEFT) . ' - ' . str_pad((string) $end, $width, '0', STR_PAD_LEFT));

    $checked = 0;
    $skipped_existing = 0;
    $imported = 0;
    $not_found = 0;
    $errors = 0;
    $quota_exhausted = false;

    for ($i = $start; $i <= $end; $i++) {
        $external_id = str_pad((string) $i, $width, '0', STR_PAD_LEFT);
        if (isset($existing[$external_id])) {
            $skipped_existing++;
            continue;
        }
        if ($checked >= $max_requests) {
            missing_out('Max requests alcanzado. Pausando.');
            break;
        }

        $checked++;
        $response = workoutx_exercise_by_id($external_id);
        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];

        if (empty($response['success']) || !is_array($response['data'] ?? null)) {
            $status = (int) ($response['status'] ?? 0);
            $error = (string) ($response['error'] ?? '');
            if ($status === 404 || stripos($error, 'not found') !== false || stripos($error, 'no encontrado') !== false) {
                $not_found++;
            } else {
                $errors++;
                missing_out('ERROR ' . $external_id . ': ' . ($error !== '' ? $error : 'No se pudo consultar.'));
                if (stripos($error, 'quota') !== false || (isset($headers['quota_remaining']) && (int) $headers['quota_remaining'] <= 0)) {
                    $quota_exhausted = true;
                    break;
                }
            }
        } else {
            $remote = $response['data'];
            $name = missing_remote_value($remote, ['name']);
            if ($name === '') {
                $not_found++;
            } else {
                $exercise_id = missing_local_exercise_id($remote);
                $body_part = missing_remote_value($remote, ['bodyPart', 'body_part']);
                $target = missing_remote_value($remote, ['target', 'targetMuscle', 'target_muscle']);
                $equipment = missing_remote_value($remote, ['equipment', 'equipmentName', 'equipment_name']);
                $difficulty = missing_remote_value($remote, ['difficulty', 'level']);
                $description = missing_remote_value($remote, ['description']);
                $instructions = missing_json($remote['instructions'] ?? []);
                $secondary = missing_json($remote['secondaryMuscles'] ?? []);
                $calories = missing_remote_value($remote, ['caloriesPerMinute', 'caloriesBurnPerMin']);
                $gif_url = missing_remote_value($remote, ['gifUrl', 'gif_url', 'imageUrl', 'image_url']);
                $aliases = $name;
                $raw_json = json_encode($remote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $insert->bind_param(
                    'ssssssssssssssss',
                    $exercise_id,
                    $name,
                    $name,
                    $body_part,
                    $difficulty,
                    $description,
                    $instructions,
                    $secondary,
                    $calories,
                    $gif_url,
                    $aliases,
                    $external_id,
                    $body_part,
                    $target,
                    $equipment,
                    $raw_json
                );
                $insert->execute();
                $existing[$external_id] = true;
                $imported++;
                missing_out('IMPORTADO ' . $external_id . ' | ' . $name);
            }
        }

        if ($checked % 25 === 0) {
            $quota = $headers['quota_remaining'] ?? '';
            $rate = $headers['rate_remaining'] ?? '';
            missing_out('Progreso checked=' . $checked . ' importados=' . $imported . ' no_existe=' . $not_found . ' errores=' . $errors . ($quota !== '' ? ' cuota=' . $quota : '') . ($rate !== '' ? ' rate=' . $rate : ''));
        }
        if ($sleep_ms > 0) {
            usleep($sleep_ms * 1000);
        }
    }

    missing_out('Sincronizacion por huecos completada.');
    missing_out(json_encode([
        'range_start' => $start,
        'range_end' => $end,
        'checked_missing_ids' => $checked,
        'skipped_existing' => $skipped_existing,
        'imported' => $imported,
        'not_found' => $not_found,
        'errors' => $errors,
        'quota_exhausted' => $quota_exhausted
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    http_response_code(500);
    missing_out('ERROR: ' . $e->getMessage());
}
