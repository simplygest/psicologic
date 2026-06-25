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

function workoutx_translations_out($message)
{
    echo htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') . ($GLOBALS['is_cli'] ? PHP_EOL : "<br>\n");
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    @flush();
}

function workoutx_translations_arg(string $key, string $default = ''): string
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

function workoutx_translations_bool_arg(string $key, bool $default = false): bool
{
    $value = strtolower(trim(workoutx_translations_arg($key, $default ? '1' : '0')));
    return in_array($value, ['1', 'true', 'yes', 'si', 'sí'], true);
}

function workoutx_translations_table_exists(mysqli $mysqli, string $table): bool
{
    $table = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

function workoutx_translations_candidates(): array
{
    $requested = trim(workoutx_translations_arg('file', ''));
    $paths = [];
    if ($requested !== '') {
        $paths[] = $requested;
    }
    $paths[] = __DIR__ . '/uploads/global/knowledgebase/WorkoutX/fitness_exercises_workoutx_es_revisado.json';
    $paths[] = __DIR__ . '/uploads/global/knowledgebase/WorkoutX/translations.json';
    return $paths;
}

function workoutx_translations_resolve_file(): string
{
    foreach (workoutx_translations_candidates() as $candidate) {
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
    throw new RuntimeException('No se encuentra el JSON de traducciones. Sube el archivo a uploads/global/knowledgebase/WorkoutX/fitness_exercises_workoutx_es_revisado.json o usa ?file=ruta.');
}

function workoutx_translations_text($value): string
{
    if ($value === null) {
        return '';
    }
    if (is_array($value)) {
        return json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return trim((string) $value);
}

function workoutx_translations_validate_utf8(string $value): bool
{
    return preg_match('//u', $value) === 1;
}

function workoutx_translations_validate_instructions(string $value): bool
{
    if ($value === '') {
        return false;
    }
    $decoded = json_decode($value, true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        return false;
    }
    foreach ($decoded as $step) {
        if (!is_string($step) || trim($step) === '') {
            return false;
        }
    }
    return count($decoded) > 0;
}

function workoutx_translations_has_encoding_artifacts(string $value): bool
{
    return strpos($value, "\xEF\xBF\xBD") !== false
        || strpos($value, 'Ã') !== false
        || strpos($value, 'Â') !== false;
}

try {
    if (!workoutx_translations_table_exists($mysqli, 'fitness_exercises')) {
        throw new RuntimeException('No existe fitness_exercises.');
    }

    $apply = workoutx_translations_bool_arg('apply', false);
    $file = workoutx_translations_resolve_file();
    workoutx_translations_out('Archivo: ' . $file);
    workoutx_translations_out($apply ? 'Modo: IMPORTAR TRADUCCIONES' : 'Modo: SIMULACION. Añade apply=1 para escribir cambios.');

    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('No se pudo leer el archivo o esta vacio.');
    }
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    }
    if (!workoutx_translations_validate_utf8($raw)) {
        throw new RuntimeException('El archivo no parece estar codificado como UTF-8 valido.');
    }

    $rows = json_decode($raw, true);
    if (!is_array($rows) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('JSON no valido: ' . json_last_error_msg());
    }
    if (isset($rows['data']) && is_array($rows['data'])) {
        $rows = $rows['data'];
    }

    $existing = [];
    $res = $mysqli->query("SELECT exercise_id FROM fitness_exercises WHERE external_source = 'workoutx' AND active = 1");
    while ($row = $res->fetch_assoc()) {
        $existing[(string) $row['exercise_id']] = true;
    }

    $update = null;
    if ($apply) {
        $update = $mysqli->prepare("
            UPDATE fitness_exercises
            SET name_es = COALESCE(NULLIF(?, ''), name_es),
                description_es = COALESCE(NULLIF(?, ''), description_es),
                instructions_es = COALESCE(NULLIF(?, ''), instructions_es),
                cues_es = COALESCE(NULLIF(?, ''), cues_es),
                aliases = COALESCE(NULLIF(?, ''), aliases),
                updated_at = CURRENT_TIMESTAMP
            WHERE exercise_id = ?
              AND external_source = 'workoutx'
            LIMIT 1
        ");
    }

    $seen = [];
    $valid = 0;
    $updated = 0;
    $not_found = 0;
    $duplicate = 0;
    $invalid = 0;
    $encoding_artifacts = 0;
    $bad_instructions = 0;
    $samples = [];

    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            $invalid++;
            $samples[] = 'Fila ' . ($index + 1) . ': no es objeto.';
            continue;
        }

        $exercise_id = workoutx_translations_text($row['exercise_id'] ?? '');
        if ($exercise_id === '') {
            $invalid++;
            $samples[] = 'Fila ' . ($index + 1) . ': falta exercise_id.';
            continue;
        }
        if (isset($seen[$exercise_id])) {
            $duplicate++;
            continue;
        }
        $seen[$exercise_id] = true;

        $name_es = workoutx_translations_text($row['name_es'] ?? '');
        $description_es = workoutx_translations_text($row['description_es'] ?? '');
        $instructions_es = workoutx_translations_text($row['instructions_es'] ?? '');
        $cues_es = workoutx_translations_text($row['cues_es'] ?? '');
        $aliases = workoutx_translations_text($row['aliases'] ?? '');

        $joined = $name_es . ' ' . $description_es . ' ' . $instructions_es . ' ' . $cues_es;
        if (!workoutx_translations_validate_utf8($joined) || workoutx_translations_has_encoding_artifacts($joined)) {
            $encoding_artifacts++;
            if (count($samples) < 10) {
                $samples[] = $exercise_id . ': posible problema de codificacion.';
            }
            continue;
        }
        if ($name_es === '' || $description_es === '') {
            $invalid++;
            if (count($samples) < 10) {
                $samples[] = $exercise_id . ': faltan name_es o description_es.';
            }
            continue;
        }
        if (!workoutx_translations_validate_instructions($instructions_es)) {
            $bad_instructions++;
            if (count($samples) < 10) {
                $samples[] = $exercise_id . ': instructions_es no es un array JSON valido.';
            }
            continue;
        }
        if (!isset($existing[$exercise_id])) {
            $not_found++;
            continue;
        }

        $valid++;
        if ($apply && $update) {
            $update->bind_param('ssssss', $name_es, $description_es, $instructions_es, $cues_es, $aliases, $exercise_id);
            $update->execute();
            if ($update->affected_rows >= 0) {
                $updated++;
            }
        }
    }

    workoutx_translations_out(json_encode([
        'json_rows' => count($rows),
        'unique_rows' => count($seen),
        'db_workoutx_rows' => count($existing),
        'valid_for_update' => $valid,
        'updated' => $updated,
        'not_found_in_db' => $not_found,
        'duplicate_rows' => $duplicate,
        'invalid_rows' => $invalid,
        'encoding_artifacts' => $encoding_artifacts,
        'bad_instructions_es' => $bad_instructions,
        'applied' => $apply,
        'samples' => $samples
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    http_response_code(500);
    workoutx_translations_out('ERROR: ' . $e->getMessage());
}
