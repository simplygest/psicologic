<?php
session_start();
require_once __DIR__ . '/db.php';

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

function workoutx_json_out($message)
{
    echo htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') . ($GLOBALS['is_cli'] ? PHP_EOL : "<br>\n");
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    @flush();
}

function workoutx_json_arg(string $key, string $default = ''): string
{
    if ($GLOBALS['is_cli']) {
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if (strpos($arg, $key . '=') === 0) {
                return substr($arg, strlen($key) + 1);
            }
        }
        return $default;
    }
    return isset($_GET[$key]) ? (string) $_GET[$key] : $default;
}

function workoutx_json_bool_arg(string $key, bool $default = false): bool
{
    $value = strtolower(trim(workoutx_json_arg($key, $default ? '1' : '0')));
    return in_array($value, ['1', 'true', 'yes', 'si', 'sí'], true);
}

function workoutx_json_table_exists(mysqli $mysqli, string $table): bool
{
    $table = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

function workoutx_json_column_exists(mysqli $mysqli, string $table, string $column): bool
{
    $table = $mysqli->real_escape_string($table);
    $column = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function workoutx_json_index_exists(mysqli $mysqli, string $table, string $index): bool
{
    $table = $mysqli->real_escape_string($table);
    $index = $mysqli->real_escape_string($index);
    $res = $mysqli->query("SHOW INDEX FROM `$table` WHERE Key_name = '$index'");
    return $res && $res->num_rows > 0;
}

function workoutx_json_ensure_schema(mysqli $mysqli): void
{
    if (!workoutx_json_table_exists($mysqli, 'fitness_exercises')) {
        throw new RuntimeException('No existe fitness_exercises. Importa primero el conocimiento Fitness.');
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
        if (!workoutx_json_column_exists($mysqli, 'fitness_exercises', $column)) {
            $mysqli->query($sql);
        }
    }
    if (!workoutx_json_index_exists($mysqli, 'fitness_exercises', 'idx_fitness_exercises_external')) {
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

function workoutx_json_value(array $remote, array $keys): string
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

function workoutx_json_array($value): string
{
    if (!is_array($value)) {
        return '';
    }
    return json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function workoutx_json_local_id(array $remote): string
{
    $external_id = workoutx_json_value($remote, ['id', 'exerciseId', 'exercise_id']);
    $seed = $external_id !== '' ? $external_id : workoutx_json_value($remote, ['name']);
    $seed = preg_replace('/[^a-z0-9]+/i', '_', $seed);
    $seed = trim((string) $seed, '_');
    if ($seed === '') {
        $seed = substr(sha1(json_encode($remote)), 0, 16);
    }
    return 'WX_' . substr($seed, 0, 27);
}

function workoutx_json_candidates(): array
{
    $requested = trim(workoutx_json_arg('file', ''));
    $paths = [];
    if ($requested !== '') {
        $paths[] = $requested;
    }
    $paths[] = __DIR__ . '/uploads/global/knowledgebase/WorkoutX/completo.json';
    $paths[] = __DIR__ . '/uploads/global/knowledgebase/workoutx/completo.json';
    $paths[] = __DIR__ . '/uploads/global/knowledgebase/Knowledge-Fitness/completo.json';
    return $paths;
}

function workoutx_json_resolve_file(): string
{
    foreach (workoutx_json_candidates() as $candidate) {
        if ($candidate === '') {
            continue;
        }
        if (!preg_match('/^[a-zA-Z]:[\\\\\/]/', $candidate) && strpos($candidate, DIRECTORY_SEPARATOR) !== 0) {
            $candidate = __DIR__ . '/' . ltrim($candidate, '/\\');
        }
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    throw new RuntimeException('No se encuentra completo.json. Sube el archivo a uploads/global/knowledgebase/WorkoutX/completo.json o usa ?file=ruta.');
}

try {
    $apply = workoutx_json_bool_arg('apply', false);
    if ($apply) {
        workoutx_json_ensure_schema($mysqli);
    } elseif (!workoutx_json_table_exists($mysqli, 'fitness_exercises')) {
        throw new RuntimeException('No existe fitness_exercises. No se puede comparar.');
    }

    $file = workoutx_json_resolve_file();
    workoutx_json_out('Archivo: ' . $file);
    workoutx_json_out($apply ? 'Modo: IMPORTAR' : 'Modo: SIMULACION. Añade apply=1 para escribir cambios.');

    $raw = file_get_contents($file);
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new RuntimeException('El JSON no es valido.');
    }
    $items = $payload['data'] ?? $payload['exercises'] ?? $payload['results'] ?? null;
    if (!is_array($items)) {
        throw new RuntimeException('No se ha encontrado un array data/exercises/results en el JSON.');
    }

    $seen = [];
    $duplicates = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = workoutx_json_value($item, ['id', 'exerciseId', 'exercise_id']);
        if ($id === '') {
            continue;
        }
        if (isset($seen[$id])) {
            $duplicates++;
        }
        $seen[$id] = true;
    }
    workoutx_json_out('Ejercicios JSON: ' . count($items) . ' | IDs unicos: ' . count($seen) . ' | duplicados: ' . $duplicates);

    $existing = [];
    $res = $mysqli->query("SELECT exercise_id, external_id FROM fitness_exercises WHERE external_source = 'workoutx' AND external_id IS NOT NULL AND external_id <> ''");
    while ($row = $res->fetch_assoc()) {
        $existing[(string) $row['external_id']] = (string) $row['exercise_id'];
    }
    workoutx_json_out('Ejercicios WorkoutX actuales en BD: ' . count($existing));

    $insert = null;
    $region_insert = null;
    if ($apply) {
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
        $region_insert = $mysqli->prepare("
            INSERT IGNORE INTO fitness_exercise_regions (exercise_id, bodymuscles_id, role, intensity)
            VALUES (?, ?, 'primary', 80)
        ");
    }

    $map_lookup = null;
    if (workoutx_json_table_exists($mysqli, 'fitness_workoutx_muscle_map')) {
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
    }

    $inserted = 0;
    $updated = 0;
    $missing_name = 0;
    $mapped_regions = 0;
    $without_region_map = 0;
    foreach ($items as $remote) {
        if (!is_array($remote)) {
            continue;
        }
        $external_id = workoutx_json_value($remote, ['id', 'exerciseId', 'exercise_id']);
        $name = workoutx_json_value($remote, ['name']);
        if ($external_id === '' || $name === '') {
            $missing_name++;
            continue;
        }

        $exercise_id = $existing[$external_id] ?? workoutx_json_local_id($remote);
        $body_part = workoutx_json_value($remote, ['bodyPart', 'body_part']);
        $target = workoutx_json_value($remote, ['target', 'targetMuscle', 'target_muscle']);
        $equipment = workoutx_json_value($remote, ['equipment', 'equipmentName', 'equipment_name']);
        $difficulty = workoutx_json_value($remote, ['difficulty', 'level']);
        $description = workoutx_json_value($remote, ['description']);
        $instructions = workoutx_json_array($remote['instructions'] ?? []);
        $secondary = workoutx_json_array($remote['secondaryMuscles'] ?? []);
        $calories = workoutx_json_value($remote, ['caloriesPerMinute', 'caloriesBurnPerMin']);
        $gif_url = workoutx_json_value($remote, ['gifUrl', 'gif_url', 'imageUrl', 'image_url']);
        $aliases = $name;
        $raw_json = json_encode($remote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (isset($existing[$external_id])) {
            $updated++;
        } else {
            $inserted++;
        }

        if ($apply && $insert) {
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
        }

        $region_count = 0;
        if ($map_lookup && ($body_part !== '' || $target !== '')) {
            $map_lookup->bind_param('ssssssss', $body_part, $target, $body_part, $target, $target, $target, $body_part, $body_part);
            $map_lookup->execute();
            $res = $map_lookup->get_result();
            while ($mapped = $res->fetch_assoc()) {
                $bodymuscles_id = (string) ($mapped['bodymuscles_id'] ?? '');
                if ($bodymuscles_id === '') {
                    continue;
                }
                $region_count++;
                if ($apply && $region_insert) {
                    $region_insert->bind_param('ss', $exercise_id, $bodymuscles_id);
                    $region_insert->execute();
                }
            }
        }
        if ($region_count > 0) {
            $mapped_regions += $region_count;
        } else {
            $without_region_map++;
        }
    }

    workoutx_json_out(json_encode([
        'json_total' => count($items),
        'json_unique_ids' => count($seen),
        'db_existing_workoutx' => count($existing),
        'would_insert_or_inserted' => $inserted,
        'would_update_or_updated' => $updated,
        'missing_id_or_name' => $missing_name,
        'region_links_found' => $mapped_regions,
        'exercises_without_region_map' => $without_region_map,
        'applied' => $apply
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    http_response_code(500);
    workoutx_json_out('ERROR: ' . $e->getMessage());
}
