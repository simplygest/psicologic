<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/app_paths.php';
require_once __DIR__ . '/import_knowledge_sectors.php';

header('Content-Type: text/html; charset=utf-8');
set_time_limit(300);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', '0');

function fitness_import_base_dir(): string
{
    return app_global_upload_dir('knowledgebase');
}

function ensure_bodymuscles_schema(mysqli $mysqli): void
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS praxis_bodymuscles_groups (
          group_id VARCHAR(10) PRIMARY KEY,
          group_en VARCHAR(100) NOT NULL,
          group_es VARCHAR(100) NOT NULL,
          region_count INT NOT NULL DEFAULT 0,
          bodymuscles_ids TEXT,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_bodymuscles_groups_es (group_es)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS praxis_bodymuscles_regions (
          region_id VARCHAR(10) PRIMARY KEY,
          bodymuscles_id VARCHAR(100) NOT NULL,
          name_en VARCHAR(150) NOT NULL,
          name_es VARCHAR(200) NOT NULL,
          group_en VARCHAR(100) NOT NULL,
          group_es VARCHAR(100) NOT NULL,
          view VARCHAR(20) NOT NULL,
          view_es VARCHAR(50) NOT NULL,
          side VARCHAR(20) NOT NULL,
          side_es VARCHAR(50) NOT NULL,
          pair_key VARCHAR(100) NOT NULL,
          region_type VARCHAR(30) NOT NULL DEFAULT 'muscle',
          active TINYINT(1) NOT NULL DEFAULT 1,
          source_file VARCHAR(100) DEFAULT NULL,
          path MEDIUMTEXT,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_bodymuscles_id (bodymuscles_id),
          INDEX idx_bodymuscles_group_es (group_es),
          INDEX idx_bodymuscles_view (view),
          INDEX idx_bodymuscles_side (side),
          INDEX idx_bodymuscles_pair_key (pair_key),
          INDEX idx_bodymuscles_active (active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS praxis_bodymuscles_group_regions (
          group_id VARCHAR(10) NOT NULL,
          region_id VARCHAR(10) NOT NULL,
          bodymuscles_id VARCHAR(100) NOT NULL,
          PRIMARY KEY (group_id, region_id),
          INDEX idx_bodymuscles_group_regions_region (region_id),
          INDEX idx_bodymuscles_group_regions_bodymuscles (bodymuscles_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function ensure_fitness_schema(mysqli $mysqli): void
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS fitness_sources (
          source_id VARCHAR(30) PRIMARY KEY,
          nombre VARCHAR(120) DEFAULT NULL,
          titulo VARCHAR(255) DEFAULT NULL,
          url VARCHAR(500) DEFAULT NULL,
          notas TEXT,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS fitness_equipment (
          equipment_id VARCHAR(30) PRIMARY KEY,
          nombre_es VARCHAR(120) NOT NULL,
          name_en VARCHAR(120) DEFAULT NULL,
          descripcion TEXT,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_fitness_equipment_nombre (nombre_es)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS fitness_goals (
          goal_id VARCHAR(30) PRIMARY KEY,
          nombre VARCHAR(120) NOT NULL,
          descripcion TEXT,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_fitness_goals_nombre (nombre)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS fitness_exercises (
          exercise_id VARCHAR(30) PRIMARY KEY,
          name_en VARCHAR(160) DEFAULT NULL,
          name_es VARCHAR(160) NOT NULL,
          equipment_id VARCHAR(30) DEFAULT NULL,
          category VARCHAR(80) DEFAULT NULL,
          difficulty VARCHAR(60) DEFAULT NULL,
          mechanics VARCHAR(60) DEFAULT NULL,
          movement_pattern VARCHAR(100) DEFAULT NULL,
          description_es TEXT,
          cues_es TEXT,
          source_ids VARCHAR(255) DEFAULT NULL,
          image_url VARCHAR(500) DEFAULT NULL,
          aliases TEXT DEFAULT NULL,
          external_source VARCHAR(80) DEFAULT NULL,
          external_id VARCHAR(120) DEFAULT NULL,
          workoutx_body_part VARCHAR(120) DEFAULT NULL,
          workoutx_target VARCHAR(120) DEFAULT NULL,
          workoutx_equipment VARCHAR(120) DEFAULT NULL,
          active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_fitness_exercises_equipment (equipment_id),
          INDEX idx_fitness_exercises_category (category),
          INDEX idx_fitness_exercises_difficulty (difficulty),
          INDEX idx_fitness_exercises_pattern (movement_pattern),
          INDEX idx_fitness_exercises_external (external_source, external_id),
          INDEX idx_fitness_exercises_active (active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    add_column_if_missing($mysqli, 'fitness_exercises', 'image_url', 'VARCHAR(500) DEFAULT NULL AFTER source_ids');
    add_column_if_missing($mysqli, 'fitness_exercises', 'aliases', 'TEXT DEFAULT NULL AFTER image_url');
    add_column_if_missing($mysqli, 'fitness_exercises', 'external_source', 'VARCHAR(80) DEFAULT NULL AFTER aliases');
    add_column_if_missing($mysqli, 'fitness_exercises', 'external_id', 'VARCHAR(120) DEFAULT NULL AFTER external_source');
    add_column_if_missing($mysqli, 'fitness_exercises', 'workoutx_body_part', 'VARCHAR(120) DEFAULT NULL AFTER external_id');
    add_column_if_missing($mysqli, 'fitness_exercises', 'workoutx_target', 'VARCHAR(120) DEFAULT NULL AFTER workoutx_body_part');
    add_column_if_missing($mysqli, 'fitness_exercises', 'workoutx_equipment', 'VARCHAR(120) DEFAULT NULL AFTER workoutx_target');
    add_index_if_missing($mysqli, 'fitness_exercises', 'idx_fitness_exercises_external', 'INDEX idx_fitness_exercises_external (external_source, external_id)');
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS fitness_exercise_sources (
          exercise_id VARCHAR(30) NOT NULL,
          source_id VARCHAR(30) NOT NULL,
          PRIMARY KEY (exercise_id, source_id),
          INDEX idx_fitness_exercise_sources_source (source_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS fitness_exercise_regions (
          exercise_id VARCHAR(30) NOT NULL,
          bodymuscles_id VARCHAR(100) NOT NULL,
          role VARCHAR(30) NOT NULL DEFAULT 'primary',
          intensity TINYINT UNSIGNED NOT NULL DEFAULT 100,
          PRIMARY KEY (exercise_id, bodymuscles_id, role),
          INDEX idx_fitness_exercise_regions_bodymuscles (bodymuscles_id),
          INDEX idx_fitness_exercise_regions_role (role),
          INDEX idx_fitness_exercise_regions_intensity (intensity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS fitness_routines (
          routine_id VARCHAR(30) PRIMARY KEY,
          name_es VARCHAR(180) NOT NULL,
          goal_id VARCHAR(30) DEFAULT NULL,
          level VARCHAR(80) DEFAULT NULL,
          days_per_week TINYINT UNSIGNED DEFAULT NULL,
          description_es TEXT,
          active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_fitness_routines_goal (goal_id),
          INDEX idx_fitness_routines_level (level),
          INDEX idx_fitness_routines_active (active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS fitness_routine_exercises (
          routine_id VARCHAR(30) NOT NULL,
          day_label VARCHAR(80) NOT NULL,
          order_num INT UNSIGNED NOT NULL,
          exercise_id VARCHAR(30) NOT NULL,
          sets VARCHAR(20) DEFAULT NULL,
          reps VARCHAR(50) DEFAULT NULL,
          rest_seconds INT UNSIGNED DEFAULT NULL,
          notes TEXT,
          PRIMARY KEY (routine_id, day_label, order_num),
          INDEX idx_fitness_routine_exercises_exercise (exercise_id),
          INDEX idx_fitness_routine_exercises_routine (routine_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function import_bodymuscles(mysqli $mysqli, string $dir): array
{
    keep_alive('Importando grupos musculares Body Muscles');
    $stats = ['groups' => 0, 'regions' => 0, 'group_regions' => 0];

    $stmt = $mysqli->prepare("
        INSERT INTO praxis_bodymuscles_groups (group_id, group_en, group_es, region_count, bodymuscles_ids)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE group_en = VALUES(group_en), group_es = VALUES(group_es), region_count = VALUES(region_count), bodymuscles_ids = VALUES(bodymuscles_ids)
    ");
    foreach (csv_rows($dir . '/praxis_bodymuscles_groups.csv') as $row) {
        $group_id = pick($row, ['group_id']);
        $group_en = pick($row, ['group_en']);
        $group_es = pick($row, ['group_es']);
        $region_count = (int) pick($row, ['region_count'], '0');
        $bodymuscles_ids = pick($row, ['bodymuscles_ids']);
        if ($group_id === '' || $group_es === '') {
            continue;
        }
        $stmt->bind_param('sssis', $group_id, $group_en, $group_es, $region_count, $bodymuscles_ids);
        $stmt->execute();
        $stats['groups']++;
    }

    keep_alive('Importando regiones musculares Body Muscles');
    $stmt = $mysqli->prepare("
        INSERT INTO praxis_bodymuscles_regions (region_id, bodymuscles_id, name_en, name_es, group_en, group_es, view, view_es, side, side_es, pair_key, region_type, active, source_file, path)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE bodymuscles_id = VALUES(bodymuscles_id), name_en = VALUES(name_en), name_es = VALUES(name_es), group_en = VALUES(group_en), group_es = VALUES(group_es), view = VALUES(view), view_es = VALUES(view_es), side = VALUES(side), side_es = VALUES(side_es), pair_key = VALUES(pair_key), region_type = VALUES(region_type), active = VALUES(active), source_file = VALUES(source_file), path = VALUES(path)
    ");
    foreach (csv_rows($dir . '/praxis_bodymuscles_regions.csv') as $row) {
        $region_id = pick($row, ['region_id']);
        $bodymuscles_id = pick($row, ['bodymuscles_id']);
        $name_en = pick($row, ['name_en']);
        $name_es = pick($row, ['name_es']);
        $group_en = pick($row, ['group_en']);
        $group_es = pick($row, ['group_es']);
        $view = pick($row, ['view']);
        $view_es = pick($row, ['view_es']);
        $side = pick($row, ['side']);
        $side_es = pick($row, ['side_es']);
        $pair_key = pick($row, ['pair_key']);
        $region_type = pick($row, ['region_type'], 'muscle');
        $active = (int) pick($row, ['active'], '1');
        $source_file = pick($row, ['source_file']);
        $path = pick($row, ['path']);
        if ($region_id === '' || $bodymuscles_id === '' || $name_es === '') {
            continue;
        }
        $stmt->bind_param('ssssssssssssiss', $region_id, $bodymuscles_id, $name_en, $name_es, $group_en, $group_es, $view, $view_es, $side, $side_es, $pair_key, $region_type, $active, $source_file, $path);
        $stmt->execute();
        $stats['regions']++;
    }

    keep_alive('Importando relaciones grupo-region');
    $mysqli->query('DELETE FROM praxis_bodymuscles_group_regions');
    $stmt = $mysqli->prepare("
        INSERT INTO praxis_bodymuscles_group_regions (group_id, region_id, bodymuscles_id)
        VALUES (?, ?, ?)
    ");
    foreach (csv_rows($dir . '/praxis_bodymuscles_group_regions.csv') as $row) {
        $group_id = pick($row, ['group_id']);
        $region_id = pick($row, ['region_id']);
        $bodymuscles_id = pick($row, ['bodymuscles_id']);
        if ($group_id === '' || $region_id === '' || $bodymuscles_id === '') {
            continue;
        }
        $stmt->bind_param('sss', $group_id, $region_id, $bodymuscles_id);
        $stmt->execute();
        $stats['group_regions']++;
    }

    return $stats;
}

function import_fitness_native(mysqli $mysqli, string $dir): array
{
    $stats = [
        'sources' => 0,
        'equipment' => 0,
        'goals' => 0,
        'exercises' => 0,
        'exercise_sources' => 0,
        'exercise_regions' => 0,
        'routines' => 0,
        'routine_exercises' => 0
    ];

    keep_alive('Importando fuentes Fitness');
    $stmt = $mysqli->prepare("
        INSERT INTO fitness_sources (source_id, nombre, titulo, url, notas)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), titulo = VALUES(titulo), url = VALUES(url), notas = VALUES(notas)
    ");
    foreach (csv_rows($dir . '/fitness_01_fuentes.csv') as $row) {
        $source_id = pick($row, ['source_id']);
        $nombre = pick($row, ['nombre']);
        $titulo = pick($row, ['titulo']);
        $url = pick($row, ['url']);
        $notas = pick($row, ['notas']);
        if ($source_id === '') {
            continue;
        }
        $stmt->bind_param('sssss', $source_id, $nombre, $titulo, $url, $notas);
        $stmt->execute();
        $stats['sources']++;
    }

    keep_alive('Importando equipamiento Fitness');
    $stmt = $mysqli->prepare("
        INSERT INTO fitness_equipment (equipment_id, nombre_es, name_en, descripcion)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE nombre_es = VALUES(nombre_es), name_en = VALUES(name_en), descripcion = VALUES(descripcion)
    ");
    foreach (csv_rows($dir . '/fitness_02_equipamiento.csv') as $row) {
        $equipment_id = pick($row, ['equipment_id']);
        $nombre_es = pick($row, ['nombre_es']);
        $name_en = pick($row, ['name_en']);
        $descripcion = pick($row, ['descripcion']);
        if ($equipment_id === '' || $nombre_es === '') {
            continue;
        }
        $stmt->bind_param('ssss', $equipment_id, $nombre_es, $name_en, $descripcion);
        $stmt->execute();
        $stats['equipment']++;
    }

    keep_alive('Importando objetivos Fitness');
    $stmt = $mysqli->prepare("
        INSERT INTO fitness_goals (goal_id, nombre, descripcion)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion)
    ");
    foreach (csv_rows($dir . '/fitness_03_objetivos.csv') as $row) {
        $goal_id = pick($row, ['goal_id']);
        $nombre = pick($row, ['nombre']);
        $descripcion = pick($row, ['descripcion']);
        if ($goal_id === '' || $nombre === '') {
            continue;
        }
        $stmt->bind_param('sss', $goal_id, $nombre, $descripcion);
        $stmt->execute();
        $stats['goals']++;
    }

    keep_alive('Importando ejercicios Fitness');
    $mysqli->query('DELETE FROM fitness_exercise_sources');
    $stmt = $mysqli->prepare("
        INSERT INTO fitness_exercises (exercise_id, name_en, name_es, equipment_id, category, difficulty, mechanics, movement_pattern, description_es, cues_es, source_ids, image_url, aliases, external_source, external_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name_en = VALUES(name_en), name_es = VALUES(name_es), equipment_id = VALUES(equipment_id), category = VALUES(category), difficulty = VALUES(difficulty), mechanics = VALUES(mechanics), movement_pattern = VALUES(movement_pattern), description_es = VALUES(description_es), cues_es = VALUES(cues_es), source_ids = VALUES(source_ids), image_url = VALUES(image_url), aliases = VALUES(aliases), external_source = VALUES(external_source), external_id = VALUES(external_id), active = 1
    ");
    $source_stmt = $mysqli->prepare("
        INSERT IGNORE INTO fitness_exercise_sources (exercise_id, source_id)
        VALUES (?, ?)
    ");
    $exercise_rows = csv_rows($dir . '/fitness_04_ejercicios.csv');
    $exercise_by_id = [];
    foreach ($exercise_rows as $row) {
        $exercise_id = pick($row, ['exercise_id']);
        $name_en = pick($row, ['name_en']);
        $name_es = pick($row, ['name_es']);
        $equipment_id = pick($row, ['equipment_id']);
        $category = pick($row, ['category']);
        $difficulty = pick($row, ['difficulty']);
        $mechanics = pick($row, ['mechanics']);
        $movement_pattern = pick($row, ['movement_pattern']);
        $description_es = pick($row, ['description_es']);
        $cues_es = pick($row, ['cues_es']);
        $source_ids = pick($row, ['source_ids']);
        $image_url = pick($row, ['image_url', 'imageurl', 'imageUrl', 'gif_url', 'gifUrl', 'gifurl']);
        $aliases = pick($row, ['aliases', 'alias', 'name_aliases', 'alternative_names', 'synonyms']);
        $external_source = pick($row, ['external_source', 'api_source', 'source_api']);
        $external_id = pick($row, ['external_id', 'api_id', 'exercise_db_id', 'exercisedb_id']);
        if ($external_source === '' && $external_id !== '') {
            $external_source = 'exercisedb';
        }
        if ($exercise_id === '' || $name_es === '') {
            continue;
        }
        $stmt->bind_param('sssssssssssssss', $exercise_id, $name_en, $name_es, $equipment_id, $category, $difficulty, $mechanics, $movement_pattern, $description_es, $cues_es, $source_ids, $image_url, $aliases, $external_source, $external_id);
        $stmt->execute();
        $stats['exercises']++;
        $exercise_by_id[$exercise_id] = $row;
        foreach (split_ids($source_ids) as $source_id) {
            $source_stmt->bind_param('ss', $exercise_id, $source_id);
            $source_stmt->execute();
            $stats['exercise_sources'] += $source_stmt->affected_rows > 0 ? 1 : 0;
        }
    }

    keep_alive('Importando relaciones ejercicio-musculo');
    $mysqli->query('DELETE FROM fitness_exercise_regions');
    $stmt = $mysqli->prepare("
        INSERT INTO fitness_exercise_regions (exercise_id, bodymuscles_id, role, intensity)
        VALUES (?, ?, ?, ?)
    ");
    foreach (csv_rows($dir . '/fitness_05_ejercicio_musculos_bodymuscles.csv') as $row) {
        $exercise_id = pick($row, ['exercise_id']);
        $bodymuscles_id = pick($row, ['bodymuscles_id']);
        $role = pick($row, ['role'], 'primary');
        $intensity = (int) pick($row, ['intensity'], '100');
        if ($exercise_id === '' || $bodymuscles_id === '') {
            continue;
        }
        $stmt->bind_param('sssi', $exercise_id, $bodymuscles_id, $role, $intensity);
        $stmt->execute();
        $stats['exercise_regions']++;
    }

    keep_alive('Importando rutinas Fitness');
    $stmt = $mysqli->prepare("
        INSERT INTO fitness_routines (routine_id, name_es, goal_id, level, days_per_week, description_es)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name_es = VALUES(name_es), goal_id = VALUES(goal_id), level = VALUES(level), days_per_week = VALUES(days_per_week), description_es = VALUES(description_es), active = 1
    ");
    foreach (csv_rows($dir . '/fitness_06_rutinas.csv') as $row) {
        $routine_id = pick($row, ['routine_id']);
        $name_es = pick($row, ['name_es']);
        $goal_id = pick($row, ['goal_id']);
        $level = pick($row, ['level']);
        $days_per_week = (int) pick($row, ['days_per_week'], '0');
        $description_es = pick($row, ['description_es']);
        if ($routine_id === '' || $name_es === '') {
            continue;
        }
        $stmt->bind_param('ssssis', $routine_id, $name_es, $goal_id, $level, $days_per_week, $description_es);
        $stmt->execute();
        $stats['routines']++;
    }

    keep_alive('Importando ejercicios de rutinas Fitness');
    $mysqli->query('DELETE FROM fitness_routine_exercises');
    $stmt = $mysqli->prepare("
        INSERT INTO fitness_routine_exercises (routine_id, day_label, order_num, exercise_id, sets, reps, rest_seconds, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach (csv_rows($dir . '/fitness_07_rutina_ejercicios.csv') as $row) {
        $routine_id = pick($row, ['routine_id']);
        $day_label = pick($row, ['day_label']);
        $order_num = (int) pick($row, ['order_num'], '0');
        $exercise_id = pick($row, ['exercise_id']);
        $sets = pick($row, ['sets']);
        $reps = pick($row, ['reps']);
        $rest_seconds = (int) pick($row, ['rest_seconds'], '0');
        $notes = pick($row, ['notes']);
        if ($routine_id === '' || $day_label === '' || !$order_num || $exercise_id === '') {
            continue;
        }
        $stmt->bind_param('ssisssis', $routine_id, $day_label, $order_num, $exercise_id, $sets, $reps, $rest_seconds, $notes);
        $stmt->execute();
        $stats['routine_exercises']++;
    }

    return $stats;
}

function import_fitness_to_knowledge(mysqli $mysqli, string $dir): array
{
    $sector_key = 'fitness';
    $stats = ['areas' => 0, 'problems' => 0, 'techniques' => 0, 'tasks' => 0, 'problem_techniques' => 0, 'recommendations' => 0];

    keep_alive('Preparando capa knowledge para Fitness');
    ensure_knowledge_schema($mysqli);
    foreach (['knowledge_recommendations', 'knowledge_problem_techniques', 'knowledge_tasks', 'knowledge_techniques', 'knowledge_problems', 'knowledge_areas'] as $table) {
        $stmt = $mysqli->prepare("DELETE FROM `$table` WHERE sector_key = ?");
        $stmt->bind_param('s', $sector_key);
        $stmt->execute();
    }

    $area_code = 'FIT_AREA_OBJECTIVES';
    $area_name = 'Objetivos fitness';
    $area_description = 'Objetivos habituales de entrenamiento personal y fitness.';
    $stmt = $mysqli->prepare("
        INSERT INTO knowledge_areas (sector_key, area_code, name, description)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)
    ");
    $stmt->bind_param('ssss', $sector_key, $area_code, $area_name, $area_description);
    $stmt->execute();
    $area_id = fetch_id($mysqli, 'knowledge_areas', 'area_code', $area_code, $sector_key);
    $stats['areas'] = $area_id ? 1 : 0;

    $problem_map = [];
    $stmt = $mysqli->prepare("
        INSERT INTO knowledge_problems (sector_key, problem_code, area_id, name, alias, description, population, risk_level)
        VALUES (?, ?, ?, ?, '', ?, 'Clientes de entrenamiento personal', '')
        ON DUPLICATE KEY UPDATE area_id = VALUES(area_id), name = VALUES(name), description = VALUES(description), population = VALUES(population), risk_level = VALUES(risk_level)
    ");
    foreach (csv_rows($dir . '/fitness_03_objetivos.csv') as $row) {
        $goal_id = pick($row, ['goal_id']);
        $name = pick($row, ['nombre']);
        $description = pick($row, ['descripcion']);
        if ($goal_id === '' || $name === '' || !$area_id) {
            continue;
        }
        $stmt->bind_param('ssiss', $sector_key, $goal_id, $area_id, $name, $description);
        $stmt->execute();
        $problem_id = fetch_id($mysqli, 'knowledge_problems', 'problem_code', $goal_id, $sector_key);
        if ($problem_id) {
            $problem_map[$goal_id] = $problem_id;
            $stats['problems']++;
        }
    }

    $routine_map = [];
    $stmt = $mysqli->prepare("
        INSERT INTO knowledge_techniques (sector_key, technique_code, name, description, risk_level)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), risk_level = VALUES(risk_level)
    ");
    foreach (csv_rows($dir . '/fitness_06_rutinas.csv') as $row) {
        $routine_id = pick($row, ['routine_id']);
        $name = pick($row, ['name_es']);
        $description = trim(pick($row, ['description_es']) . "\nNivel: " . pick($row, ['level']) . '. Días/semana: ' . pick($row, ['days_per_week']));
        $risk = pick($row, ['level']);
        if ($routine_id === '' || $name === '') {
            continue;
        }
        $stmt->bind_param('sssss', $sector_key, $routine_id, $name, $description, $risk);
        $stmt->execute();
        $technique_id = fetch_id($mysqli, 'knowledge_techniques', 'technique_code', $routine_id, $sector_key);
        if ($technique_id) {
            $routine_map[$routine_id] = ['id' => $technique_id, 'goal_id' => pick($row, ['goal_id'])];
            $stats['techniques']++;
        }
    }

    $exercise_rows = [];
    foreach (csv_rows($dir . '/fitness_04_ejercicios.csv') as $row) {
        $exercise_rows[pick($row, ['exercise_id'])] = $row;
    }

    $task_map = [];
    $stmt = $mysqli->prepare("
        INSERT INTO knowledge_tasks (sector_key, task_code, technique_id, title, description, objective, risk_level, estimated_duration)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE technique_id = VALUES(technique_id), title = VALUES(title), description = VALUES(description), objective = VALUES(objective), risk_level = VALUES(risk_level), estimated_duration = VALUES(estimated_duration)
    ");
    foreach (csv_rows($dir . '/fitness_07_rutina_ejercicios.csv') as $row) {
        $routine_id = pick($row, ['routine_id']);
        $exercise_id = pick($row, ['exercise_id']);
        $technique_id = (int) ($routine_map[$routine_id]['id'] ?? 0);
        $exercise = $exercise_rows[$exercise_id] ?? [];
        $task_code = $routine_id . '_' . preg_replace('/[^A-Z0-9]+/i', '_', pick($row, ['day_label'])) . '_' . pick($row, ['order_num']) . '_' . $exercise_id;
        $title = pick($exercise, ['name_es'], $exercise_id);
        $description_parts = array_filter([
            pick($exercise, ['description_es']),
            pick($exercise, ['cues_es']) !== '' ? 'Indicaciones: ' . pick($exercise, ['cues_es']) : '',
            pick($row, ['notes']) !== '' ? 'Notas: ' . pick($row, ['notes']) : ''
        ]);
        $description = implode("\n", $description_parts);
        $objective = trim(pick($row, ['day_label']) . ': ' . pick($row, ['sets']) . ' series x ' . pick($row, ['reps']) . ' reps. Descanso: ' . pick($row, ['rest_seconds']) . ' s.');
        $risk = pick($exercise, ['difficulty']);
        $duration = '';
        if ($task_code === '' || !$technique_id || $title === '') {
            continue;
        }
        $stmt->bind_param('ssisssss', $sector_key, $task_code, $technique_id, $title, $description, $objective, $risk, $duration);
        $stmt->execute();
        $task_id = fetch_id($mysqli, 'knowledge_tasks', 'task_code', $task_code, $sector_key);
        if ($task_id) {
            $task_map[$task_code] = ['id' => $task_id, 'routine_id' => $routine_id];
            $stats['tasks']++;
        }
    }

    keep_alive('Creando relaciones objetivo-rutina y recomendaciones');
    $problem_technique_stmt = $mysqli->prepare("
        INSERT IGNORE INTO knowledge_problem_techniques (sector_key, problem_id, technique_id)
        VALUES (?, ?, ?)
    ");
    foreach ($routine_map as $routine_id => $routine) {
        $problem_id = (int) ($problem_map[$routine['goal_id']] ?? 0);
        $technique_id = (int) $routine['id'];
        if (!$problem_id || !$technique_id) {
            continue;
        }
        $problem_technique_stmt->bind_param('sii', $sector_key, $problem_id, $technique_id);
        $problem_technique_stmt->execute();
        $stats['problem_techniques'] += $problem_technique_stmt->affected_rows > 0 ? 1 : 0;
    }

    $recommendation_stmt = $mysqli->prepare("
        INSERT INTO knowledge_recommendations (sector_key, recommendation_code, problem_id, technique_id, task_id, priority, clinical_note)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE problem_id = VALUES(problem_id), technique_id = VALUES(technique_id), task_id = VALUES(task_id), priority = VALUES(priority), clinical_note = VALUES(clinical_note)
    ");
    foreach ($task_map as $task_code => $task) {
        $routine = $routine_map[$task['routine_id']] ?? null;
        $problem_id = $routine ? (int) ($problem_map[$routine['goal_id']] ?? 0) : 0;
        $technique_id = $routine ? (int) $routine['id'] : 0;
        $task_id = (int) $task['id'];
        $priority = 'media';
        $note = 'Ejercicio incluido en rutina predefinida de Fitness.';
        if (!$problem_id || !$technique_id || !$task_id) {
            continue;
        }
        $recommendation_stmt->bind_param('ssiiiss', $sector_key, $task_code, $problem_id, $technique_id, $task_id, $priority, $note);
        $recommendation_stmt->execute();
        $stats['recommendations']++;
    }

    return $stats;
}

if (($_GET['run'] ?? '') !== '1') {
    echo '<h1>Importador Fitness / Body Muscles</h1>';
    echo '<p>Carga regiones musculares, ejercicios, rutinas y objetivos Fitness.</p>';
    echo '<p><a href="?run=1">Ejecutar importacion</a></p>';
    exit;
}

try {
    echo '<h1>Importando Fitness / Body Muscles</h1>';
    $base_dir = fitness_import_base_dir();
    $muscles_dir = $base_dir . '/Knowledge-Muscles';
    $fitness_dir = $base_dir . '/Knowledge-Fitness';
    if (!is_dir($muscles_dir) || !is_dir($fitness_dir)) {
        throw new RuntimeException('No se encuentran las carpetas Knowledge-Muscles y Knowledge-Fitness en ' . $base_dir);
    }

    ensure_bodymuscles_schema($mysqli);
    ensure_fitness_schema($mysqli);
    ensure_knowledge_schema($mysqli);

    $mysqli->begin_transaction();
    $stats = [
        'bodymuscles' => import_bodymuscles($mysqli, $muscles_dir),
        'fitness' => import_fitness_native($mysqli, $fitness_dir),
        'knowledge_fitness' => import_fitness_to_knowledge($mysqli, $fitness_dir)
    ];
    $mysqli->commit();

    echo '<h1>Importacion completada</h1>';
    echo '<pre>' . htmlspecialchars(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '</pre>';
    echo '<p>Cuando compruebes que todo esta correcto, puedes borrar este archivo del servidor: <code>import_fitness_knowledge.php</code>.</p>';
} catch (Throwable $e) {
    @$mysqli->rollback();
    http_response_code(500);
    echo '<h1>Error importando Fitness</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
}
