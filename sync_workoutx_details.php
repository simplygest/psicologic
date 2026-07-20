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

function sync_details_out($message)
{
    echo htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') . ($GLOBALS['is_cli'] ? PHP_EOL : "<br>\n");
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    @flush();
}

function sync_details_table_exists(mysqli $mysqli, string $table): bool
{
    $table = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

function sync_details_column_exists(mysqli $mysqli, string $table, string $column): bool
{
    $table = $mysqli->real_escape_string($table);
    $column = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function sync_details_index_exists(mysqli $mysqli, string $table, string $index): bool
{
    $table = $mysqli->real_escape_string($table);
    $index = $mysqli->real_escape_string($index);
    $res = $mysqli->query("SHOW INDEX FROM `$table` WHERE Key_name = '$index'");
    return $res && $res->num_rows > 0;
}

function sync_details_ensure_schema(mysqli $mysqli): void
{
    if (!sync_details_table_exists($mysqli, 'fitness_exercises')) {
        throw new RuntimeException('No existe fitness_exercises. Importa primero los ejercicios Fitness.');
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
        if (!sync_details_column_exists($mysqli, 'fitness_exercises', $column)) {
            $mysqli->query($sql);
        }
    }
    if (!sync_details_index_exists($mysqli, 'fitness_exercises', 'idx_fitness_exercises_external')) {
        $mysqli->query("ALTER TABLE fitness_exercises ADD INDEX idx_fitness_exercises_external (external_source, external_id)");
    }
}

function sync_details_value(array $remote, array $keys): string
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

function sync_details_json($value): string
{
    if (!is_array($value)) {
        return '';
    }
    return json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function sync_details_request_int(string $key, int $default): int
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

try {
    if (!workoutx_available()) {
        throw new RuntimeException('WorkoutX no esta configurado. Define WORKOUTX_API_KEY en config.local.php.');
    }
    sync_details_ensure_schema($mysqli);

    $limit = max(1, min(5000, sync_details_request_int('limit', 5000)));
    $force = sync_details_request_int('force', 0) === 1;
    $sleep_ms = max(0, min(1000, sync_details_request_int('sleep_ms', 220)));

    $where = "external_source = 'workoutx' AND external_id IS NOT NULL AND external_id <> '' AND active = 1";
    if (!$force) {
        $where .= " AND (workoutx_details_synced_at IS NULL OR instructions_en IS NULL OR instructions_en = '')";
    }
    $sql = "
        SELECT exercise_id, external_id, name_en, name_es
        FROM fitness_exercises
        WHERE $where
        ORDER BY workoutx_details_synced_at IS NULL DESC, exercise_id ASC
        LIMIT $limit
    ";
    $res = $mysqli->query($sql);
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    sync_details_out('Ejercicios pendientes: ' . count($rows));
    if (!$rows) {
        sync_details_out('No hay nada que sincronizar.');
        exit;
    }

    $update = $mysqli->prepare("
        UPDATE fitness_exercises
        SET description_en = NULLIF(?, ''),
            instructions_en = NULLIF(?, ''),
            secondary_muscles_en = NULLIF(?, ''),
            calories_per_min = NULLIF(?, ''),
            image_url = COALESCE(NULLIF(?, ''), image_url),
            workoutx_body_part = COALESCE(NULLIF(?, ''), workoutx_body_part),
            workoutx_target = COALESCE(NULLIF(?, ''), workoutx_target),
            workoutx_equipment = COALESCE(NULLIF(?, ''), workoutx_equipment),
            difficulty = COALESCE(NULLIF(?, ''), difficulty),
            workoutx_raw_json = ?,
            workoutx_details_synced_at = NOW()
        WHERE exercise_id = ?
        LIMIT 1
    ");

    $done = 0;
    $errors = 0;
    foreach ($rows as $row) {
        $exercise_id = (string) $row['exercise_id'];
        $external_id = (string) $row['external_id'];
        $response = workoutx_exercise_by_id($external_id);
        if (empty($response['success']) || !is_array($response['data'] ?? null)) {
            $errors++;
            sync_details_out('ERROR ' . $exercise_id . ' / ' . $external_id . ': ' . ($response['error'] ?? 'No se pudo obtener detalle.'));
            continue;
        }

        $remote = $response['data'];
        $description = sync_details_value($remote, ['description']);
        $instructions = sync_details_json($remote['instructions'] ?? []);
        $secondary = sync_details_json($remote['secondaryMuscles'] ?? []);
        $calories = sync_details_value($remote, ['caloriesPerMinute', 'caloriesBurnPerMin']);
        $gif_url = sync_details_value($remote, ['gifUrl', 'gif_url', 'imageUrl', 'image_url']);
        $body_part = sync_details_value($remote, ['bodyPart', 'body_part']);
        $target = sync_details_value($remote, ['target', 'targetMuscle', 'target_muscle']);
        $equipment = sync_details_value($remote, ['equipment', 'equipmentName', 'equipment_name']);
        $difficulty = sync_details_value($remote, ['difficulty', 'level']);
        $raw_json = json_encode($remote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $update->bind_param(
            'sssssssssss',
            $description,
            $instructions,
            $secondary,
            $calories,
            $gif_url,
            $body_part,
            $target,
            $equipment,
            $difficulty,
            $raw_json,
            $exercise_id
        );
        $update->execute();
        $done++;

        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
        $quota = $headers['quota_remaining'] ?? '';
        $rate = $headers['rate_remaining'] ?? '';
        sync_details_out('OK ' . $done . '/' . count($rows) . ' ' . $exercise_id . ' | WorkoutX ' . $external_id . ($quota !== '' ? ' | cuota ' . $quota : '') . ($rate !== '' ? ' | rate ' . $rate : ''));

        if ($sleep_ms > 0) {
            usleep($sleep_ms * 1000);
        }
    }

    sync_details_out('Sincronizacion completada. OK: ' . $done . ' | Errores: ' . $errors);
} catch (Throwable $e) {
    http_response_code(500);
    sync_details_out('ERROR: ' . $e->getMessage());
}
