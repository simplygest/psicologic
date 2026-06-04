<?php

function cabinet_column_exists($mysqli, $table, $column)
{
    $stmt = $mysqli->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row && (int) $row['total'] > 0;
}

function cabinet_add_column_if_missing($mysqli, $table, $column, $definition)
{
    if (!cabinet_column_exists($mysqli, $table, $column)) {
        $mysqli->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function ensure_cabinet_schema($mysqli)
{
    $mysqli->query("ALTER TABLE users MODIFY role ENUM('superadmin','admin','patient') NOT NULL DEFAULT 'patient'");

    $settings_table = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if ($settings_table && $settings_table->num_rows > 0) {
        cabinet_add_column_if_missing($mysqli, 'payment_settings', 'show_team_public', "TINYINT(1) NOT NULL DEFAULT 0");
        cabinet_add_column_if_missing($mysqli, 'payment_settings', 'allow_patient_transfer', "TINYINT(1) NOT NULL DEFAULT 0");
    }

    $closed_days_table = $mysqli->query("SHOW TABLES LIKE 'closed_days'");
    if ($closed_days_table && $closed_days_table->num_rows > 0) {
        cabinet_add_column_if_missing($mysqli, 'closed_days', 'is_global', "TINYINT(1) NOT NULL DEFAULT 0 AFTER reason");
        cabinet_add_index_if_missing($mysqli, 'closed_days', 'idx_closed_days_global_date', 'is_global, closed_date');
    }

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS professionals (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED DEFAULT NULL,
            display_name VARCHAR(150) NOT NULL,
            public_slug VARCHAR(160) DEFAULT NULL,
            professional_title VARCHAR(180) DEFAULT NULL,
            professional_specialty TEXT DEFAULT NULL,
            public_bio TEXT DEFAULT NULL,
            public_photo_path VARCHAR(255) DEFAULT NULL,
            public_email VARCHAR(255) DEFAULT NULL,
            public_phone VARCHAR(40) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_professionals_user (user_id),
            UNIQUE KEY uniq_professionals_slug (public_slug),
            INDEX idx_professionals_active (is_active),
            INDEX idx_professionals_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    cabinet_add_column_if_missing($mysqli, 'professionals', 'professional_specialty', "TEXT DEFAULT NULL AFTER professional_title");
    cabinet_add_column_if_missing($mysqli, 'professionals', 'public_photo_path', "VARCHAR(255) DEFAULT NULL AFTER public_bio");

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS patient_professionals (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            patient_id INT UNSIGNED NOT NULL,
            professional_id INT UNSIGNED NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 1,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            transferred_at DATETIME DEFAULT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            UNIQUE KEY uniq_patient_professional (patient_id, professional_id),
            INDEX idx_patient_professionals_patient (patient_id),
            INDEX idx_patient_professionals_professional (professional_id),
            INDEX idx_patient_professionals_primary (patient_id, is_primary)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $column_targets = [
        'appointments' => "INT UNSIGNED DEFAULT NULL AFTER user_id",
        'closed_days' => "INT UNSIGNED DEFAULT NULL AFTER id",
        'invitations' => "INT UNSIGNED DEFAULT NULL AFTER user_id",
        'patient_profiles' => "INT UNSIGNED DEFAULT NULL AFTER user_id",
        'patient_bonuses' => "INT UNSIGNED DEFAULT NULL AFTER user_id",
        'payment_attempts' => "INT UNSIGNED DEFAULT NULL AFTER user_id",
        'appointment_services' => "INT UNSIGNED DEFAULT NULL AFTER id",
        'appointment_service_options' => "INT UNSIGNED DEFAULT NULL AFTER service_id",
        'appointment_bonuses' => "INT UNSIGNED DEFAULT NULL AFTER id"
    ];

    foreach ($column_targets as $table => $definition) {
        $exists = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($table) . "'");
        if ($exists && $exists->num_rows > 0) {
            cabinet_add_column_if_missing($mysqli, $table, 'professional_id', $definition);
        }
    }

    cabinet_add_index_if_missing($mysqli, 'appointments', 'idx_appointments_professional_date', 'professional_id, appointment_date, appointment_time');
    cabinet_add_index_if_missing($mysqli, 'closed_days', 'idx_closed_days_professional_date', 'professional_id, closed_date');
    cabinet_add_index_if_missing($mysqli, 'invitations', 'idx_invitations_professional', 'professional_id');
    cabinet_add_index_if_missing($mysqli, 'patient_profiles', 'idx_patient_profiles_professional', 'professional_id');
    cabinet_add_index_if_missing($mysqli, 'patient_bonuses', 'idx_patient_bonuses_professional', 'professional_id');
    cabinet_add_index_if_missing($mysqli, 'payment_attempts', 'idx_payment_attempts_professional', 'professional_id');
    cabinet_add_index_if_missing($mysqli, 'appointment_services', 'idx_appointment_services_professional', 'professional_id');
    cabinet_add_index_if_missing($mysqli, 'appointment_service_options', 'idx_service_options_professional', 'professional_id');
    cabinet_add_index_if_missing($mysqli, 'appointment_bonuses', 'idx_appointment_bonuses_professional', 'professional_id');

    seed_default_professional($mysqli);
}

function cabinet_add_index_if_missing($mysqli, $table, $index, $columns)
{
    $table_exists = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($table) . "'");
    if (!$table_exists || $table_exists->num_rows === 0) {
        return;
    }

    $res = $mysqli->query("SHOW INDEX FROM `$table` WHERE Key_name = '" . $mysqli->real_escape_string($index) . "'");
    if ($res && $res->num_rows === 0) {
        $mysqli->query("ALTER TABLE `$table` ADD INDEX `$index` ($columns)");
    }
}

function seed_default_professional($mysqli)
{
    $res = $mysqli->query("
        SELECT id, name, email, phone
        FROM users
        WHERE role IN ('superadmin', 'admin')
        ORDER BY FIELD(role, 'superadmin', 'admin'), id ASC
        LIMIT 1
    ");
    $admin = $res ? $res->fetch_assoc() : null;
    if (!$admin) {
        return null;
    }

    $display_name = $admin['name'] ?: 'Profesional';
    $slug = cabinet_slugify($display_name);
    $email = $admin['email'] ?? null;
    $phone = $admin['phone'] ?? null;
    $user_id = (int) $admin['id'];

    $stmt = $mysqli->prepare("
        INSERT INTO professionals (user_id, display_name, public_slug, public_email, public_phone, is_active, sort_order)
        VALUES (?, ?, ?, ?, ?, 1, 10)
        ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), public_email = VALUES(public_email), public_phone = VALUES(public_phone)
    ");
    $stmt->bind_param("issss", $user_id, $display_name, $slug, $email, $phone);
    $stmt->execute();

    $stmt = $mysqli->prepare("SELECT id FROM professionals WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['id'] : null;
}

function cabinet_slugify($value)
{
    $value = trim((string) $value);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim($value, '-');
    return $value ?: 'profesional';
}
