<?php
require_once __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');
set_time_limit(300);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', '0');

$sectors = [
    'fisioterapia' => [
        'dir' => __DIR__ . '/Knowledge-Fisioterapia',
        'prefix' => 'fisioterapia',
        'files' => [
            'sources' => 'fisioterapia_01_fuentes.csv',
            'areas' => 'fisioterapia_02_areas.csv',
            'problems' => 'fisioterapia_03_problemas_objetivos.csv',
            'techniques' => 'fisioterapia_04_tecnicas_metodos.csv',
            'tasks' => 'fisioterapia_05_tareas_pautas.csv',
            'problem_techniques' => 'fisioterapia_06_problema_tecnica.csv',
            'recommendations' => 'fisioterapia_07_recomendaciones_tareas.csv',
            'questionnaires' => 'fisioterapia_08_evaluaciones_indicadores.csv'
        ]
    ],
    'nutricion' => [
        'dir' => __DIR__ . '/Knowledge-Nutricion',
        'prefix' => 'nutricion',
        'files' => [
            'sources' => 'nutricion_01_fuentes.csv',
            'areas' => 'nutricion_02_areas.csv',
            'problems' => 'nutricion_03_problemas_objetivos.csv',
            'techniques' => 'nutricion_04_tecnicas_metodos.csv',
            'tasks' => 'nutricion_05_tareas_pautas.csv',
            'problem_techniques' => 'nutricion_06_problema_tecnica.csv',
            'recommendations' => 'nutricion_07_recomendaciones_tareas.csv',
            'questionnaires' => 'nutricion_08_evaluaciones_indicadores.csv'
        ]
    ],
    'osteopatia' => [
        'dir' => __DIR__ . '/Knowledge-Osteopatia',
        'prefix' => 'osteopatia',
        'files' => [
            'sources' => 'osteopatia_01_fuentes.csv',
            'areas' => 'osteopatia_02_areas.csv',
            'problems' => 'osteopatia_03_problemas_objetivos.csv',
            'techniques' => 'osteopatia_04_tecnicas_metodos.csv',
            'tasks' => 'osteopatia_05_tareas_pautas.csv',
            'problem_techniques' => 'osteopatia_06_problema_tecnica.csv',
            'recommendations' => 'osteopatia_07_recomendaciones_tareas.csv',
            'questionnaires' => 'osteopatia_08_evaluaciones_indicadores.csv'
        ]
    ],
    'logopedia' => [
        'dir' => __DIR__ . '/Knowledge-Logopedia',
        'prefix' => 'logopedia',
        'files' => [
            'sources' => 'logopedia_01_fuentes.csv',
            'areas' => 'logopedia_02_areas.csv',
            'problems' => 'logopedia_03_problemas_objetivos.csv',
            'techniques' => 'logopedia_04_tecnicas_metodos.csv',
            'tasks' => 'logopedia_05_tareas_pautas.csv',
            'problem_techniques' => 'logopedia_06_problema_tecnica.csv',
            'recommendations' => 'logopedia_07_recomendaciones_tareas.csv',
            'questionnaires' => 'logopedia_08_evaluaciones_indicadores.csv'
        ]
    ],
    'quiropractica' => [
        'dir' => __DIR__ . '/Knowledge-Quiropractica',
        'prefix' => 'quiropractica',
        'files' => [
            'sources' => 'quiropractica_01_fuentes.csv',
            'areas' => 'quiropractica_02_areas.csv',
            'problems' => 'quiropractica_03_problemas_objetivos.csv',
            'techniques' => 'quiropractica_04_tecnicas_metodos.csv',
            'tasks' => 'quiropractica_05_tareas_pautas.csv',
            'recommendations' => 'quiropractica_07_recomendaciones_tareas.csv',
            'questionnaires' => 'quiropractica_08_evaluaciones.csv'
        ]
    ]
];

function table_exists(mysqli $mysqli, string $table): bool
{
    $table = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

function column_exists(mysqli $mysqli, string $table, string $column): bool
{
    $table = $mysqli->real_escape_string($table);
    $column = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function index_exists(mysqli $mysqli, string $table, string $index): bool
{
    $table = $mysqli->real_escape_string($table);
    $index = $mysqli->real_escape_string($index);
    $res = $mysqli->query("SHOW INDEX FROM `$table` WHERE Key_name = '$index'");
    return $res && $res->num_rows > 0;
}

function add_column_if_missing(mysqli $mysqli, string $table, string $column, string $definition): void
{
    if (!column_exists($mysqli, $table, $column)) {
        $mysqli->query("ALTER TABLE `$table` ADD `$column` $definition");
    }
}

function add_index_if_missing(mysqli $mysqli, string $table, string $index, string $definition): void
{
    if (!index_exists($mysqli, $table, $index)) {
        $mysqli->query("ALTER TABLE `$table` ADD $definition");
    }
}

function ensure_sector_code_unique(mysqli $mysqli, string $table, string $code_column): void
{
    if (!table_exists($mysqli, $table) || !column_exists($mysqli, $table, $code_column)) {
        return;
    }
    $indexes_res = $mysqli->query("SHOW INDEX FROM `$table`");
    if ($indexes_res) {
        $indexes = [];
        while ($row = $indexes_res->fetch_assoc()) {
            $key = $row['Key_name'] ?? '';
            if ($key === '' || $key === 'PRIMARY' || (int) ($row['Non_unique'] ?? 1) !== 0) {
                continue;
            }
            $indexes[$key][] = [
                'column' => $row['Column_name'] ?? '',
                'seq' => (int) ($row['Seq_in_index'] ?? 0)
            ];
        }
        foreach ($indexes as $key => $columns) {
            usort($columns, fn($a, $b) => $a['seq'] <=> $b['seq']);
            $column_names = array_map(fn($item) => $item['column'], $columns);
            if ($column_names === [$code_column]) {
                $key_sql = str_replace('`', '``', $key);
                $mysqli->query("ALTER TABLE `$table` DROP INDEX `$key_sql`");
            }
        }
    }
    $index = 'uniq_' . $table . '_sector_code';
    add_index_if_missing($mysqli, $table, $index, "UNIQUE `$index` (sector_key, `$code_column`)");
}

function ensure_knowledge_schema(mysqli $mysqli): void
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_areas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            area_code VARCHAR(64) NOT NULL,
            name VARCHAR(180) NOT NULL,
            description TEXT DEFAULT NULL,
            UNIQUE uniq_knowledge_areas_sector_code (sector_key, area_code),
            INDEX idx_knowledge_areas_sector (sector_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_sources (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            source_code VARCHAR(64) NOT NULL,
            name VARCHAR(180) DEFAULT NULL,
            organization VARCHAR(180) DEFAULT NULL,
            title VARCHAR(255) DEFAULT NULL,
            url VARCHAR(500) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            UNIQUE uniq_knowledge_sources_sector_code (sector_key, source_code),
            INDEX idx_knowledge_sources_sector (sector_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_techniques (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            technique_code VARCHAR(64) NOT NULL,
            name VARCHAR(180) NOT NULL,
            description TEXT DEFAULT NULL,
            risk_level VARCHAR(32) DEFAULT NULL,
            UNIQUE uniq_knowledge_techniques_sector_code (sector_key, technique_code),
            INDEX idx_knowledge_techniques_sector (sector_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_problems (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            problem_code VARCHAR(64) NOT NULL,
            area_id INT UNSIGNED NOT NULL,
            name VARCHAR(180) NOT NULL,
            alias VARCHAR(180) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            population VARCHAR(120) DEFAULT NULL,
            risk_level VARCHAR(32) DEFAULT NULL,
            UNIQUE uniq_knowledge_problems_sector_code (sector_key, problem_code),
            INDEX idx_knowledge_problems_sector (sector_key),
            INDEX idx_knowledge_problems_area (area_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_tasks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            task_code VARCHAR(64) NOT NULL,
            technique_id INT UNSIGNED DEFAULT NULL,
            title VARCHAR(220) NOT NULL,
            description TEXT DEFAULT NULL,
            objective TEXT DEFAULT NULL,
            risk_level VARCHAR(32) DEFAULT NULL,
            estimated_duration VARCHAR(120) DEFAULT NULL,
            UNIQUE uniq_knowledge_tasks_sector_code (sector_key, task_code),
            INDEX idx_knowledge_tasks_sector (sector_key),
            INDEX idx_knowledge_tasks_technique (technique_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_problem_techniques (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            problem_id INT UNSIGNED NOT NULL,
            technique_id INT UNSIGNED NOT NULL,
            UNIQUE uniq_knowledge_problem_techniques (sector_key, problem_id, technique_id),
            INDEX idx_knowledge_problem_techniques_sector (sector_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_recommendations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            recommendation_code VARCHAR(64) DEFAULT NULL,
            problem_id INT UNSIGNED NOT NULL,
            technique_id INT UNSIGNED NOT NULL,
            task_id INT UNSIGNED NOT NULL,
            priority VARCHAR(32) DEFAULT NULL,
            clinical_note TEXT DEFAULT NULL,
            UNIQUE uniq_knowledge_recommendations_sector_code (sector_key, recommendation_code),
            INDEX idx_knowledge_recommendations_sector (sector_key),
            INDEX idx_knowledge_recommendations_problem (problem_id),
            INDEX idx_knowledge_recommendations_technique (technique_id),
            INDEX idx_knowledge_recommendations_task (task_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_questionnaires (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            questionnaire_code VARCHAR(64) NOT NULL,
            problem_id INT UNSIGNED NOT NULL,
            name VARCHAR(220) NOT NULL,
            use_area VARCHAR(180) DEFAULT NULL,
            questionnaire_type VARCHAR(120) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            UNIQUE uniq_knowledge_questionnaires_sector_code (sector_key, questionnaire_code),
            INDEX idx_knowledge_questionnaires_sector (sector_key),
            INDEX idx_knowledge_questionnaires_problem (problem_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS knowledge_problem_sources (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sector_key VARCHAR(32) NOT NULL DEFAULT 'psicologia',
            problem_id INT UNSIGNED NOT NULL,
            source_id INT UNSIGNED NOT NULL,
            UNIQUE uniq_knowledge_problem_sources (sector_key, problem_id, source_id),
            INDEX idx_knowledge_problem_sources_sector (sector_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach ([
        'knowledge_areas' => ['area_code'],
        'knowledge_sources' => ['source_code'],
        'knowledge_techniques' => ['technique_code'],
        'knowledge_problems' => ['problem_code'],
        'knowledge_tasks' => ['task_code'],
        'knowledge_recommendations' => ['recommendation_code'],
        'knowledge_questionnaires' => ['questionnaire_code']
    ] as $table => $columns) {
        add_column_if_missing($mysqli, $table, 'sector_key', "VARCHAR(32) NOT NULL DEFAULT 'psicologia' AFTER id");
        $mysqli->query("UPDATE `$table` SET sector_key = 'psicologia' WHERE sector_key IS NULL OR sector_key = ''");
        add_index_if_missing($mysqli, $table, 'idx_' . $table . '_sector', "INDEX `idx_{$table}_sector` (sector_key)");
        foreach ($columns as $column) {
            add_column_if_missing($mysqli, $table, $column, "VARCHAR(64) DEFAULT NULL");
        }
        ensure_sector_code_unique($mysqli, $table, $columns[0]);
    }
    add_column_if_missing($mysqli, 'knowledge_recommendations', 'clinical_note', 'TEXT DEFAULT NULL');
    add_column_if_missing($mysqli, 'knowledge_problem_sources', 'sector_key', "VARCHAR(32) NOT NULL DEFAULT 'psicologia' FIRST");
    add_index_if_missing($mysqli, 'knowledge_problem_sources', 'idx_knowledge_problem_sources_sector', "INDEX idx_knowledge_problem_sources_sector (sector_key)");
    add_column_if_missing($mysqli, 'knowledge_problem_techniques', 'sector_key', "VARCHAR(32) NOT NULL DEFAULT 'psicologia' FIRST");
    add_index_if_missing($mysqli, 'knowledge_problem_techniques', 'idx_knowledge_problem_techniques_sector', "INDEX idx_knowledge_problem_techniques_sector (sector_key)");
}

function keep_alive(string $message): void
{
    echo '<div>[' . date('H:i:s') . '] ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>' . PHP_EOL;
    @ob_flush();
    @flush();
}

function normalize_csv_header(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    $value = trim($value);
    return $value;
}

function csv_rows(string $path): array
{
    if (!is_file($path)) {
        keep_alive("Aviso: no existe el CSV $path");
        return [];
    }
    $fh = fopen($path, 'rb');
    if (!$fh) {
        throw new RuntimeException("No se pudo abrir $path");
    }
    $header = fgetcsv($fh, 0, ';');
    if (!$header) {
        fclose($fh);
        return [];
    }
    $header = array_map(fn($value) => normalize_csv_header((string) $value), $header);
    $rows = [];
    while (($row = fgetcsv($fh, 0, ';')) !== false) {
        if (count($row) === 1 && trim((string) $row[0]) === '') {
            continue;
        }
        $assoc = [];
        foreach ($header as $index => $name) {
            $assoc[$name] = isset($row[$index]) ? trim((string) $row[$index]) : '';
        }
        $rows[] = $assoc;
    }
    fclose($fh);
    return $rows;
}

function pick(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return trim((string) $row[$key]);
        }
    }
    return $default;
}

function split_ids(string $value): array
{
    $value = trim($value, " \t\n\r\0\x0B\"'");
    if ($value === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', preg_split('/[;,|]+/', $value))));
}

function fetch_id(mysqli $mysqli, string $table, string $code_column, string $code, string $sector_key): int
{
    $stmt = $mysqli->prepare("SELECT id FROM `$table` WHERE sector_key = ? AND `$code_column` = ? LIMIT 1");
    $stmt->bind_param('ss', $sector_key, $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['id'] : 0;
}

function upsert_source(mysqli $mysqli, string $sector_key, array $row): int
{
    $code = pick($row, ['fuente_id', 'source_code']);
    if ($code === '') return 0;
    $organization = pick($row, ['organismo', 'organization']);
    $title = pick($row, ['titulo', 'title']);
    $url = pick($row, ['url']);
    $notes = pick($row, ['notas', 'notes']);
    $name = $organization ?: $code;
    $stmt = $mysqli->prepare("
        INSERT INTO knowledge_sources (sector_key, source_code, name, organization, title, url, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name), organization = VALUES(organization), title = VALUES(title), url = VALUES(url), notes = VALUES(notes)
    ");
    $stmt->bind_param('sssssss', $sector_key, $code, $name, $organization, $title, $url, $notes);
    $stmt->execute();
    return fetch_id($mysqli, 'knowledge_sources', 'source_code', $code, $sector_key);
}

function upsert_area(mysqli $mysqli, string $sector_key, array $row): int
{
    $code = pick($row, ['area_id', 'area_code']);
    if ($code === '') return 0;
    $name = pick($row, ['nombre', 'name']);
    $description = pick($row, ['descripcion', 'description']);
    $stmt = $mysqli->prepare("
        INSERT INTO knowledge_areas (sector_key, area_code, name, description)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)
    ");
    $stmt->bind_param('ssss', $sector_key, $code, $name, $description);
    $stmt->execute();
    return fetch_id($mysqli, 'knowledge_areas', 'area_code', $code, $sector_key);
}

function upsert_problem(mysqli $mysqli, string $sector_key, array $row, array $area_map): int
{
    $code = pick($row, ['problema_id', 'problem_code']);
    if ($code === '') return 0;
    $area_code = pick($row, ['area_id']);
    $area_id = $area_map[$area_code] ?? 0;
    if (!$area_id) return 0;
    $name = pick($row, ['nombre', 'name']);
    $alias = pick($row, ['alias']);
    $description = pick($row, ['descripcion', 'description']);
    $population = pick($row, ['poblacion', 'population']);
    $risk = pick($row, ['nivel_riesgo', 'riesgo', 'risk_level']);
    $stmt = $mysqli->prepare("
        INSERT INTO knowledge_problems (sector_key, problem_code, area_id, name, alias, description, population, risk_level)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE area_id = VALUES(area_id), name = VALUES(name), alias = VALUES(alias), description = VALUES(description), population = VALUES(population), risk_level = VALUES(risk_level)
    ");
    $stmt->bind_param('ssisssss', $sector_key, $code, $area_id, $name, $alias, $description, $population, $risk);
    $stmt->execute();
    return fetch_id($mysqli, 'knowledge_problems', 'problem_code', $code, $sector_key);
}

function upsert_technique(mysqli $mysqli, string $sector_key, array $row): int
{
    $code = pick($row, ['tecnica_id', 'technique_code']);
    if ($code === '') return 0;
    $name = pick($row, ['nombre', 'name']);
    $description = pick($row, ['descripcion', 'description']);
    $risk = pick($row, ['nivel_riesgo', 'riesgo', 'risk_level']);
    $stmt = $mysqli->prepare("
        INSERT INTO knowledge_techniques (sector_key, technique_code, name, description, risk_level)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), risk_level = VALUES(risk_level)
    ");
    $stmt->bind_param('sssss', $sector_key, $code, $name, $description, $risk);
    $stmt->execute();
    return fetch_id($mysqli, 'knowledge_techniques', 'technique_code', $code, $sector_key);
}

function upsert_task(mysqli $mysqli, string $sector_key, array $row, array $technique_map): int
{
    $code = pick($row, ['tarea_id', 'task_code']);
    if ($code === '') return 0;
    $technique_code = pick($row, ['tecnica_id']);
    $technique_id = $technique_map[$technique_code] ?? null;
    $title = pick($row, ['titulo', 'title']);
    $description = pick($row, ['descripcion', 'description']);
    $objective = pick($row, ['objetivo', 'objective']);
    $risk = pick($row, ['nivel_riesgo', 'riesgo', 'risk_level']);
    $duration = pick($row, ['duracion_estimada', 'duracion', 'estimated_duration']);
    $stmt = $mysqli->prepare("
        INSERT INTO knowledge_tasks (sector_key, task_code, technique_id, title, description, objective, risk_level, estimated_duration)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE technique_id = VALUES(technique_id), title = VALUES(title), description = VALUES(description), objective = VALUES(objective), risk_level = VALUES(risk_level), estimated_duration = VALUES(estimated_duration)
    ");
    $stmt->bind_param('ssisssss', $sector_key, $code, $technique_id, $title, $description, $objective, $risk, $duration);
    $stmt->execute();
    return fetch_id($mysqli, 'knowledge_tasks', 'task_code', $code, $sector_key);
}

function import_problem_sources(mysqli $mysqli, string $sector_key, array $problem_rows, array $problem_map, array $source_map): int
{
    $delete = $mysqli->prepare("DELETE FROM knowledge_problem_sources WHERE sector_key = ?");
    $delete->bind_param('s', $sector_key);
    $delete->execute();
    $stmt = $mysqli->prepare("
        INSERT IGNORE INTO knowledge_problem_sources (sector_key, problem_id, source_id)
        VALUES (?, ?, ?)
    ");
    $count = 0;
    foreach ($problem_rows as $row) {
        $problem_code = pick($row, ['problema_id', 'problem_code']);
        $problem_id = $problem_map[$problem_code] ?? 0;
        foreach (split_ids(pick($row, ['fuente_ids', 'source_ids'])) as $source_code) {
            $source_id = $source_map[$source_code] ?? 0;
            if ($problem_id && $source_id) {
                $stmt->bind_param('sii', $sector_key, $problem_id, $source_id);
                $stmt->execute();
                $count += $stmt->affected_rows > 0 ? 1 : 0;
            }
        }
    }
    return $count;
}

function import_problem_techniques(mysqli $mysqli, string $sector_key, array $rows, array $problem_map, array $technique_map): int
{
    $delete = $mysqli->prepare("DELETE FROM knowledge_problem_techniques WHERE sector_key = ?");
    $delete->bind_param('s', $sector_key);
    $delete->execute();
    $stmt = $mysqli->prepare("
        INSERT IGNORE INTO knowledge_problem_techniques (sector_key, problem_id, technique_id)
        VALUES (?, ?, ?)
    ");
    $count = 0;
    foreach ($rows as $row) {
        $problem_id = $problem_map[pick($row, ['problema_id'])] ?? 0;
        $technique_id = $technique_map[pick($row, ['tecnica_id'])] ?? 0;
        if ($problem_id && $technique_id) {
            $stmt->bind_param('sii', $sector_key, $problem_id, $technique_id);
            $stmt->execute();
            $count += $stmt->affected_rows > 0 ? 1 : 0;
        }
    }
    return $count;
}

function import_recommendations(mysqli $mysqli, string $sector_key, array $rows, array $problem_map, array $technique_map, array $task_map): int
{
    $delete = $mysqli->prepare("DELETE FROM knowledge_recommendations WHERE sector_key = ?");
    $delete->bind_param('s', $sector_key);
    $delete->execute();
    $stmt = $mysqli->prepare("
        INSERT INTO knowledge_recommendations (sector_key, recommendation_code, problem_id, technique_id, task_id, priority, clinical_note)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE problem_id = VALUES(problem_id), technique_id = VALUES(technique_id), task_id = VALUES(task_id), priority = VALUES(priority), clinical_note = VALUES(clinical_note)
    ");
    $count = 0;
    $processed = 0;
    foreach ($rows as $row) {
        $processed++;
        $code = pick($row, ['recomendacion_id', 'recommendation_code']);
        $problem_id = $problem_map[pick($row, ['problema_id'])] ?? 0;
        $technique_id = $technique_map[pick($row, ['tecnica_id'])] ?? 0;
        $task_id = $task_map[pick($row, ['tarea_id'])] ?? 0;
        $priority = pick($row, ['prioridad', 'priority'], 'media');
        $note = pick($row, ['nota_profesional', 'nota', 'clinical_note']);
        if ($code && $problem_id && $technique_id && $task_id) {
            $stmt->bind_param('ssiiiss', $sector_key, $code, $problem_id, $technique_id, $task_id, $priority, $note);
            $stmt->execute();
            $count++;
        }
        if ($processed % 100 === 0) {
            keep_alive("$sector_key: $processed recomendaciones procesadas");
        }
    }
    return $count;
}

function import_questionnaires(mysqli $mysqli, string $sector_key, array $rows, array $problem_map): int
{
    $delete = $mysqli->prepare("DELETE FROM knowledge_questionnaires WHERE sector_key = ?");
    $delete->bind_param('s', $sector_key);
    $delete->execute();
    $stmt = $mysqli->prepare("
        INSERT INTO knowledge_questionnaires (sector_key, questionnaire_code, problem_id, name, use_area, questionnaire_type, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE problem_id = VALUES(problem_id), name = VALUES(name), use_area = VALUES(use_area), questionnaire_type = VALUES(questionnaire_type), notes = VALUES(notes)
    ");
    $count = 0;
    $processed = 0;
    foreach ($rows as $row) {
        $processed++;
        $code = pick($row, ['evaluacion_id', 'questionnaire_code']);
        $problem_id = $problem_map[pick($row, ['problema_id'])] ?? 0;
        $name = pick($row, ['nombre', 'name']);
        $use_area = pick($row, ['area_uso', 'uso', 'use_area']);
        $type = pick($row, ['tipo', 'questionnaire_type']);
        $notes = pick($row, ['notas', 'notes']);
        if ($code && $problem_id && $name) {
            $stmt->bind_param('ssissss', $sector_key, $code, $problem_id, $name, $use_area, $type, $notes);
            $stmt->execute();
            $count++;
        }
        if ($processed % 100 === 0) {
            keep_alive("$sector_key: $processed evaluaciones procesadas");
        }
    }
    return $count;
}

function import_sector(mysqli $mysqli, string $sector_key, array $config): array
{
    $dir = $config['dir'];
    $files = $config['files'];
    $stats = [];

    keep_alive("Iniciando sector $sector_key");

    $source_map = [];
    keep_alive("$sector_key: importando fuentes");
    foreach (csv_rows($dir . '/' . $files['sources']) as $row) {
        $code = pick($row, ['fuente_id', 'source_code']);
        $id = upsert_source($mysqli, $sector_key, $row);
        if ($code && $id) $source_map[$code] = $id;
    }
    $stats['fuentes'] = count($source_map);

    $area_map = [];
    keep_alive("$sector_key: importando areas");
    foreach (csv_rows($dir . '/' . $files['areas']) as $row) {
        $code = pick($row, ['area_id', 'area_code']);
        $id = upsert_area($mysqli, $sector_key, $row);
        if ($code && $id) $area_map[$code] = $id;
    }
    $stats['areas'] = count($area_map);

    $problem_rows = csv_rows($dir . '/' . $files['problems']);
    $problem_map = [];
    keep_alive("$sector_key: importando problemas/diagnosticos");
    foreach ($problem_rows as $row) {
        $code = pick($row, ['problema_id', 'problem_code']);
        $id = upsert_problem($mysqli, $sector_key, $row, $area_map);
        if ($code && $id) $problem_map[$code] = $id;
    }
    $stats['problemas'] = count($problem_map);
    $stats['fuentes_problema'] = import_problem_sources($mysqli, $sector_key, $problem_rows, $problem_map, $source_map);

    $technique_map = [];
    keep_alive("$sector_key: importando tecnicas/metodos");
    foreach (csv_rows($dir . '/' . $files['techniques']) as $row) {
        $code = pick($row, ['tecnica_id', 'technique_code']);
        $id = upsert_technique($mysqli, $sector_key, $row);
        if ($code && $id) $technique_map[$code] = $id;
    }
    $stats['tecnicas'] = count($technique_map);

    $task_map = [];
    keep_alive("$sector_key: importando tareas/pautas");
    foreach (csv_rows($dir . '/' . $files['tasks']) as $row) {
        $code = pick($row, ['tarea_id', 'task_code']);
        $id = upsert_task($mysqli, $sector_key, $row, $technique_map);
        if ($code && $id) $task_map[$code] = $id;
    }
    $stats['tareas'] = count($task_map);

    $problem_techniques_file = $files['problem_techniques'] ?? '';
    keep_alive("$sector_key: importando relaciones problema-tecnica");
    $stats['problema_tecnica'] = $problem_techniques_file
        ? import_problem_techniques($mysqli, $sector_key, csv_rows($dir . '/' . $problem_techniques_file), $problem_map, $technique_map)
        : 0;

    $recommendation_rows = csv_rows($dir . '/' . $files['recommendations']);
    keep_alive("$sector_key: importando recomendaciones");
    $stats['recomendaciones'] = import_recommendations($mysqli, $sector_key, $recommendation_rows, $problem_map, $technique_map, $task_map);
    if (!$stats['problema_tecnica']) {
        $derived_pairs = [];
        foreach ($recommendation_rows as $row) {
            $p = pick($row, ['problema_id']);
            $t = pick($row, ['tecnica_id']);
            if ($p && $t) {
                $derived_pairs[$p . '|' . $t] = ['problema_id' => $p, 'tecnica_id' => $t];
            }
        }
        $stats['problema_tecnica'] = import_problem_techniques($mysqli, $sector_key, array_values($derived_pairs), $problem_map, $technique_map);
    }

    $questionnaire_file = $files['questionnaires'] ?? '';
    keep_alive("$sector_key: importando evaluaciones/cuestionarios");
    $stats['evaluaciones'] = $questionnaire_file
        ? import_questionnaires($mysqli, $sector_key, csv_rows($dir . '/' . $questionnaire_file), $problem_map)
        : 0;

    keep_alive("Sector $sector_key completado");

    return $stats;
}

if (($_GET['run'] ?? '') !== '1') {
    echo '<h1>Importador Knowledge por sector</h1>';
    echo '<p>Este importador cargara Fisioterapia, Nutricion, Osteopatia, Logopedia y Quiropractica usando sector_key.</p>';
    echo '<p><a href="?run=1">Ejecutar importacion</a></p>';
    exit;
}

try {
    echo '<h1>Importando Knowledge por sector</h1>';
    keep_alive('Preparando esquema de base de datos');
    ensure_knowledge_schema($mysqli);
    $mysqli->begin_transaction();
    $all_stats = [];
    foreach ($sectors as $sector_key => $config) {
        $all_stats[$sector_key] = import_sector($mysqli, $sector_key, $config);
    }
    $mysqli->commit();

    echo '<h1>Importacion completada</h1>';
    echo '<pre>' . htmlspecialchars(json_encode($all_stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
    echo '<p>Cuando compruebes que todo esta correcto, borra este archivo del servidor: <code>import_knowledge_sectors.php</code>.</p>';
} catch (Throwable $e) {
    if ($mysqli->errno === 0) {
        @$mysqli->rollback();
    }
    http_response_code(500);
    echo '<h1>Error importando knowledge</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}
