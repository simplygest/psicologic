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

function cabinet_index_exists($mysqli, $table, $index)
{
    $table = $mysqli->real_escape_string($table);
    $index = $mysqli->real_escape_string($index);
    $res = $mysqli->query("SHOW INDEX FROM `$table` WHERE Key_name = '$index'");
    return $res && $res->num_rows > 0;
}

function cabinet_drop_single_column_unique_indexes($mysqli, $table, $column)
{
    $table_sql = $mysqli->real_escape_string($table);
    $column_sql = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW INDEX FROM `$table_sql`");
    if (!$res) {
        return;
    }
    $indexes = [];
    while ($row = $res->fetch_assoc()) {
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
        if ($column_names === [$column_sql]) {
            $key_sql = str_replace('`', '``', $key);
            $mysqli->query("ALTER TABLE `$table_sql` DROP INDEX `$key_sql`");
        }
    }
}

function cabinet_add_unique_index_if_missing($mysqli, $table, $index, $columns)
{
    if (!cabinet_index_exists($mysqli, $table, $index)) {
        $mysqli->query("ALTER TABLE `$table` ADD UNIQUE `$index` ($columns)");
    }
}

function cabinet_drop_legacy_closed_date_unique_if_needed($mysqli)
{
    $stmt = $mysqli->prepare("
        SELECT s.INDEX_NAME
        FROM INFORMATION_SCHEMA.STATISTICS s
        WHERE s.TABLE_SCHEMA = DATABASE()
          AND s.TABLE_NAME = 'closed_days'
          AND s.NON_UNIQUE = 0
          AND s.INDEX_NAME <> 'PRIMARY'
        GROUP BY s.INDEX_NAME
        HAVING SUM(CASE WHEN s.COLUMN_NAME = 'closed_date' THEN 1 ELSE 0 END) > 0
           AND COUNT(*) = 1
    ");
    if (!$stmt) {
        return;
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $index = $mysqli->real_escape_string($row['INDEX_NAME']);
        $mysqli->query("ALTER TABLE closed_days DROP INDEX `$index`");
    }
}

function ensure_cabinet_schema($mysqli)
{
    $tenant_id = current_tenant_id();
    $mysqli->query("ALTER TABLE users MODIFY role ENUM('superadmin','admin','patient') NOT NULL DEFAULT 'patient'");
    cabinet_add_column_if_missing($mysqli, 'users', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
    cabinet_drop_single_column_unique_indexes($mysqli, 'users', 'email');
    cabinet_drop_single_column_unique_indexes($mysqli, 'users', 'phone');
    cabinet_add_unique_index_if_missing($mysqli, 'users', 'uniq_users_tenant_email', 'tenant_id, email');
    cabinet_add_unique_index_if_missing($mysqli, 'users', 'uniq_users_tenant_phone', 'tenant_id, phone');
    cabinet_add_index_if_missing($mysqli, 'users', 'idx_users_tenant_role', 'tenant_id, role');

    $settings_table = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if ($settings_table && $settings_table->num_rows > 0) {
        cabinet_add_column_if_missing($mysqli, 'payment_settings', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
        cabinet_add_column_if_missing($mysqli, 'payment_settings', 'show_team_public', "TINYINT(1) NOT NULL DEFAULT 0");
        cabinet_add_column_if_missing($mysqli, 'payment_settings', 'allow_patient_transfer', "TINYINT(1) NOT NULL DEFAULT 0");
        cabinet_add_column_if_missing($mysqli, 'payment_settings', 'new_patient_booking_mode', "VARCHAR(32) NOT NULL DEFAULT 'day_first'");
        cabinet_add_column_if_missing($mysqli, 'payment_settings', 'new_patient_fixed_professional_id', "INT UNSIGNED DEFAULT NULL");
    }

    $closed_days_table = $mysqli->query("SHOW TABLES LIKE 'closed_days'");
    if ($closed_days_table && $closed_days_table->num_rows > 0) {
        cabinet_add_column_if_missing($mysqli, 'closed_days', 'is_global', "TINYINT(1) NOT NULL DEFAULT 0 AFTER reason");
        cabinet_drop_legacy_closed_date_unique_if_needed($mysqli);
        cabinet_add_index_if_missing($mysqli, 'closed_days', 'idx_closed_days_global_date', 'is_global, closed_date');
    }

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS professionals (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            user_id INT UNSIGNED DEFAULT NULL,
            display_name VARCHAR(150) NOT NULL,
            public_slug VARCHAR(160) DEFAULT NULL,
            professional_title VARCHAR(180) DEFAULT NULL,
            license_number VARCHAR(80) DEFAULT NULL,
            professional_specialty TEXT DEFAULT NULL,
            public_bio TEXT DEFAULT NULL,
            public_photo_path VARCHAR(255) DEFAULT NULL,
            public_email VARCHAR(255) DEFAULT NULL,
            public_phone VARCHAR(40) DEFAULT NULL,
            instagram_url VARCHAR(255) DEFAULT NULL,
            facebook_url VARCHAR(255) DEFAULT NULL,
            tiktok_url VARCHAR(255) DEFAULT NULL,
            appointment_summary_email_mode VARCHAR(32) NOT NULL DEFAULT 'on_booking',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_professionals_user (user_id),
            UNIQUE KEY uniq_professionals_tenant_slug (tenant_id, public_slug),
            INDEX idx_professionals_active (is_active),
            INDEX idx_professionals_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    cabinet_add_column_if_missing($mysqli, 'professionals', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
    cabinet_add_column_if_missing($mysqli, 'professionals', 'professional_specialty', "TEXT DEFAULT NULL AFTER professional_title");
    cabinet_add_column_if_missing($mysqli, 'professionals', 'license_number', "VARCHAR(80) DEFAULT NULL AFTER professional_title");
    cabinet_add_column_if_missing($mysqli, 'professionals', 'public_bio', "TEXT DEFAULT NULL AFTER professional_specialty");
    cabinet_add_column_if_missing($mysqli, 'professionals', 'public_photo_path', "VARCHAR(255) DEFAULT NULL AFTER public_bio");
    cabinet_add_column_if_missing($mysqli, 'professionals', 'public_phone', "VARCHAR(40) DEFAULT NULL AFTER public_email");
    cabinet_add_column_if_missing($mysqli, 'professionals', 'instagram_url', "VARCHAR(255) DEFAULT NULL AFTER public_phone");
    cabinet_add_column_if_missing($mysqli, 'professionals', 'facebook_url', "VARCHAR(255) DEFAULT NULL AFTER instagram_url");
    cabinet_add_column_if_missing($mysqli, 'professionals', 'tiktok_url', "VARCHAR(255) DEFAULT NULL AFTER facebook_url");
    cabinet_add_column_if_missing($mysqli, 'professionals', 'appointment_summary_email_mode', "VARCHAR(32) NOT NULL DEFAULT 'on_booking' AFTER tiktok_url");
    cabinet_drop_single_column_unique_indexes($mysqli, 'professionals', 'public_slug');
    cabinet_add_unique_index_if_missing($mysqli, 'professionals', 'uniq_professionals_tenant_slug', 'tenant_id, public_slug');

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS patient_professionals (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
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

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS professional_settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            professional_id INT UNSIGNED NOT NULL,
            appointment_delivery_mode ENUM('both', 'presencial', 'online') DEFAULT NULL,
            available_session_types VARCHAR(32) DEFAULT NULL,
            available_session_durations VARCHAR(16) DEFAULT NULL,
            appointment_start_time TIME DEFAULT NULL,
            appointment_end_time TIME DEFAULT NULL,
            break_start_time TIME DEFAULT NULL,
            break_end_time TIME DEFAULT NULL,
            available_weekdays VARCHAR(32) DEFAULT NULL,
            min_booking_notice_days INT UNSIGNED DEFAULT NULL,
            max_booking_notice_days INT UNSIGNED DEFAULT NULL,
            bonuses_enabled TINYINT(1) DEFAULT NULL,
            create_compensation_bonus_on_paid_cancel TINYINT(1) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_professional_settings_professional (professional_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    cabinet_add_column_if_missing($mysqli, 'patient_professionals', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'min_booking_notice_days', "INT UNSIGNED DEFAULT NULL AFTER available_weekdays");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'max_booking_notice_days', "INT UNSIGNED DEFAULT NULL AFTER min_booking_notice_days");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'bonuses_enabled', "TINYINT(1) DEFAULT NULL AFTER max_booking_notice_days");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'create_compensation_bonus_on_paid_cancel', "TINYINT(1) DEFAULT NULL AFTER bonuses_enabled");

    $tenant_column_targets = [
        'appointments' => "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id",
        'closed_days' => "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id",
        'invitations' => "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id",
        'patient_profiles' => "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER user_id",
        'patient_bonuses' => "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id",
        'payment_attempts' => "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id",
        'appointment_services' => "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id",
        'appointment_service_options' => "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id",
        'appointment_bonuses' => "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id"
    ];

    foreach ($tenant_column_targets as $table => $definition) {
        $exists = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($table) . "'");
        if ($exists && $exists->num_rows > 0) {
            cabinet_add_column_if_missing($mysqli, $table, 'tenant_id', $definition);
        }
    }

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

    $default_professional_id = seed_default_professional($mysqli);
    if ($default_professional_id) {
        cabinet_seed_professional_settings_from_superadmin($mysqli, $default_professional_id);
    }
    cabinet_seed_missing_professional_settings($mysqli);
}

function cabinet_professional_settings_columns()
{
    return [
        'appointment_delivery_mode',
        'available_session_types',
        'available_session_durations',
        'appointment_start_time',
        'appointment_end_time',
        'break_start_time',
        'break_end_time',
        'available_weekdays',
        'min_booking_notice_days',
        'max_booking_notice_days'
    ];
}

function cabinet_default_professional_settings()
{
    return [
        'appointment_delivery_mode' => 'both',
        'available_session_types' => 'individual',
        'available_session_durations' => '60',
        'appointment_start_time' => '10:00:00',
        'appointment_end_time' => '19:00:00',
        'break_start_time' => '15:00:00',
        'break_end_time' => '16:00:00',
        'available_weekdays' => '1,2,3,4,5',
        'min_booking_notice_days' => 2,
        'max_booking_notice_days' => 40
    ];
}

function cabinet_global_settings_as_professional_defaults($mysqli)
{
    $defaults = cabinet_default_professional_settings();
    $settings_table = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if (!$settings_table || $settings_table->num_rows === 0) {
        return $defaults;
    }

    $columns = [];
    foreach (cabinet_professional_settings_columns() as $column) {
        if (cabinet_column_exists($mysqli, 'payment_settings', $column)) {
            $columns[] = "`$column`";
        }
    }
    if (!$columns) {
        return $defaults;
    }

    $tenant_id = current_tenant_id();
    $res = $mysqli->query("SELECT " . implode(', ', $columns) . " FROM payment_settings WHERE tenant_id = $tenant_id LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) {
        return $defaults;
    }

    foreach ($defaults as $key => $default_value) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            $defaults[$key] = $row[$key];
        }
    }

    return $defaults;
}

function cabinet_superadmin_professional_id($mysqli)
{
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("
        SELECT p.id
        FROM professionals p
        INNER JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
        WHERE p.tenant_id = ? AND u.role = 'superadmin'
        ORDER BY p.id ASC
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            return (int) $row['id'];
        }
    }

    $stmt = $mysqli->prepare("
        SELECT p.id
        FROM professionals p
        INNER JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
        WHERE p.tenant_id = ? AND u.role IN ('admin', 'superadmin')
        ORDER BY FIELD(u.role, 'superadmin', 'admin'), p.id ASC
        LIMIT 1
    ");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['id'] : 0;
}

function cabinet_active_professional_exists($mysqli, $professional_id)
{
    $tenant_id = current_tenant_id();
    $professional_id = (int) $professional_id;
    if ($professional_id <= 0) {
        return false;
    }
    $stmt = $mysqli->prepare("SELECT id FROM professionals WHERE tenant_id = ? AND id = ? AND is_active = 1 LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ii", $tenant_id, $professional_id);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

function cabinet_patient_primary_professional_id($mysqli, $patient_user_id)
{
    ensure_cabinet_schema($mysqli);
    $tenant_id = current_tenant_id();
    $patient_user_id = (int) $patient_user_id;
    if ($patient_user_id <= 0) {
        return 0;
    }

    $stmt = $mysqli->prepare("
        SELECT professional_id
        FROM patient_professionals
        WHERE tenant_id = ? AND patient_id = ? AND is_primary = 1
        ORDER BY assigned_at DESC, id DESC
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("ii", $tenant_id, $patient_user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && !empty($row['professional_id'])) {
            return (int) $row['professional_id'];
        }
    }

    $profile_exists = $mysqli->query("SHOW TABLES LIKE 'patient_profiles'");
    if ($profile_exists && $profile_exists->num_rows > 0) {
        $stmt = $mysqli->prepare("SELECT professional_id FROM patient_profiles WHERE tenant_id = ? AND user_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("ii", $tenant_id, $patient_user_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && !empty($row['professional_id'])) {
                return (int) $row['professional_id'];
            }
        }
    }

    return 0;
}

function cabinet_new_patient_fixed_professional_id($mysqli)
{
    ensure_cabinet_schema($mysqli);
    $tenant_id = current_tenant_id();
    $settings_res = $mysqli->query("SELECT new_patient_booking_mode, new_patient_fixed_professional_id FROM payment_settings WHERE tenant_id = $tenant_id");
    $settings = $settings_res ? $settings_res->fetch_assoc() : null;
    if (!$settings || ($settings['new_patient_booking_mode'] ?? '') !== 'fixed_professional') {
        return 0;
    }

    $configured_id = (int) ($settings['new_patient_fixed_professional_id'] ?? 0);
    if ($configured_id > 0 && cabinet_active_professional_exists($mysqli, $configured_id)) {
        return $configured_id;
    }

    $superadmin_id = cabinet_superadmin_professional_id($mysqli);
    if ($superadmin_id > 0 && cabinet_active_professional_exists($mysqli, $superadmin_id)) {
        return $superadmin_id;
    }

    $stmt = $mysqli->prepare("SELECT id FROM professionals WHERE tenant_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['id'] : 0;
}

function cabinet_new_patient_booking_mode($mysqli)
{
    ensure_cabinet_schema($mysqli);
    $tenant_id = current_tenant_id();
    $settings_res = $mysqli->query("SELECT new_patient_booking_mode FROM payment_settings WHERE tenant_id = $tenant_id");
    $settings = $settings_res ? $settings_res->fetch_assoc() : null;
    $mode = $settings['new_patient_booking_mode'] ?? 'day_first';
    return in_array($mode, ['day_first', 'professional_first', 'fixed_professional'], true) ? $mode : 'day_first';
}

function cabinet_active_professionals_for_booking($mysqli)
{
    ensure_cabinet_schema($mysqli);
    $tenant_id = current_tenant_id();
    $professionals = [];
    $stmt = $mysqli->prepare("
        SELECT p.id, p.display_name, p.public_photo_path, u.role AS user_role
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
        WHERE p.tenant_id = ? AND p.is_active = 1
        ORDER BY CASE WHEN u.role = 'superadmin' THEN 0 ELSE 1 END ASC,
                 p.sort_order ASC,
                 p.display_name ASC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res) {
        return [];
    }
    while ($row = $res->fetch_assoc()) {
        $payload = cabinet_professional_display_payload($mysqli, (int) $row['id']);
        if ($payload) {
            $professionals[] = $payload;
        }
    }
    return $professionals;
}

function cabinet_fetch_professional_settings_row($mysqli, $professional_id)
{
    $tenant_id = current_tenant_id();
    $professional_id = (int) $professional_id;
    if ($professional_id <= 0) {
        return null;
    }

    $stmt = $mysqli->prepare("
        SELECT appointment_delivery_mode, available_session_types, available_session_durations,
               appointment_start_time, appointment_end_time, break_start_time, break_end_time,
               available_weekdays, min_booking_notice_days, max_booking_notice_days
        FROM professional_settings
        WHERE tenant_id = ? AND professional_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("ii", $tenant_id, $professional_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function cabinet_get_effective_professional_settings($mysqli, $professional_id)
{
    ensure_cabinet_schema($mysqli);
    $settings = cabinet_global_settings_as_professional_defaults($mysqli);
    $row = cabinet_fetch_professional_settings_row($mysqli, $professional_id);
    if (!$row) {
        return $settings;
    }

    foreach ($settings as $key => $default_value) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            $settings[$key] = $row[$key];
        }
    }

    return $settings;
}

function cabinet_upsert_professional_settings($mysqli, $professional_id, $settings)
{
    $tenant_id = current_tenant_id();
    $professional_id = (int) $professional_id;
    if ($professional_id <= 0) {
        return false;
    }

    $defaults = cabinet_default_professional_settings();
    $settings = array_merge($defaults, array_intersect_key((array) $settings, $defaults));

    $appointment_delivery_mode = $settings['appointment_delivery_mode'] ?? 'both';
    $available_session_types = $settings['available_session_types'] ?? 'individual';
    $available_session_durations = $settings['available_session_durations'] ?? '60';
    $appointment_start_time = $settings['appointment_start_time'] ?? '10:00:00';
    $appointment_end_time = $settings['appointment_end_time'] ?? '19:00:00';
    $break_start_time = $settings['break_start_time'] ?? null;
    $break_end_time = $settings['break_end_time'] ?? null;
    $available_weekdays = $settings['available_weekdays'] ?? '1,2,3,4,5';
    $min_booking_notice_days = (int) ($settings['min_booking_notice_days'] ?? 2);
    $max_booking_notice_days = (int) ($settings['max_booking_notice_days'] ?? 40);

    $stmt = $mysqli->prepare("
        INSERT INTO professional_settings (
            tenant_id,
            professional_id,
            appointment_delivery_mode,
            available_session_types,
            available_session_durations,
            appointment_start_time,
            appointment_end_time,
            break_start_time,
            break_end_time,
            available_weekdays,
            min_booking_notice_days,
            max_booking_notice_days
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            appointment_delivery_mode = VALUES(appointment_delivery_mode),
            available_session_types = VALUES(available_session_types),
            available_session_durations = VALUES(available_session_durations),
            appointment_start_time = VALUES(appointment_start_time),
            appointment_end_time = VALUES(appointment_end_time),
            break_start_time = VALUES(break_start_time),
            break_end_time = VALUES(break_end_time),
            available_weekdays = VALUES(available_weekdays),
            min_booking_notice_days = VALUES(min_booking_notice_days),
            max_booking_notice_days = VALUES(max_booking_notice_days)
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "iissssssssii",
        $tenant_id,
        $professional_id,
        $appointment_delivery_mode,
        $available_session_types,
        $available_session_durations,
        $appointment_start_time,
        $appointment_end_time,
        $break_start_time,
        $break_end_time,
        $available_weekdays,
        $min_booking_notice_days,
        $max_booking_notice_days
    );
    $stmt->execute();
    return true;
}

function cabinet_seed_professional_settings_from_superadmin($mysqli, $professional_id)
{
    $tenant_id = current_tenant_id();
    $professional_id = (int) $professional_id;
    if ($professional_id <= 0) {
        return false;
    }

    $stmt = $mysqli->prepare("SELECT id FROM professional_settings WHERE tenant_id = ? AND professional_id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ii", $tenant_id, $professional_id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        return false;
    }

    $settings = null;
    $superadmin_professional_id = cabinet_superadmin_professional_id($mysqli);
    if ($superadmin_professional_id > 0 && $superadmin_professional_id !== $professional_id) {
        $settings = cabinet_fetch_professional_settings_row($mysqli, $superadmin_professional_id);
    }
    if (!$settings) {
        $settings = cabinet_global_settings_as_professional_defaults($mysqli);
    }

    $stmt = $mysqli->prepare("
        INSERT INTO professional_settings (
            tenant_id,
            professional_id,
            appointment_delivery_mode,
            available_session_types,
            available_session_durations,
            appointment_start_time,
            appointment_end_time,
            break_start_time,
            break_end_time,
            available_weekdays,
            min_booking_notice_days,
            max_booking_notice_days
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return false;
    }

    $appointment_delivery_mode = $settings['appointment_delivery_mode'] ?? 'both';
    $available_session_types = $settings['available_session_types'] ?? 'individual';
    $available_session_durations = $settings['available_session_durations'] ?? '60';
    $appointment_start_time = $settings['appointment_start_time'] ?? '10:00:00';
    $appointment_end_time = $settings['appointment_end_time'] ?? '19:00:00';
    $break_start_time = $settings['break_start_time'] ?? null;
    $break_end_time = $settings['break_end_time'] ?? null;
    $available_weekdays = $settings['available_weekdays'] ?? '1,2,3,4,5';
    $min_booking_notice_days = (int) ($settings['min_booking_notice_days'] ?? 2);
    $max_booking_notice_days = (int) ($settings['max_booking_notice_days'] ?? 40);

    $stmt->bind_param(
        "iissssssssii",
        $tenant_id,
        $professional_id,
        $appointment_delivery_mode,
        $available_session_types,
        $available_session_durations,
        $appointment_start_time,
        $appointment_end_time,
        $break_start_time,
        $break_end_time,
        $available_weekdays,
        $min_booking_notice_days,
        $max_booking_notice_days
    );
    $stmt->execute();
    return true;
}

function cabinet_seed_missing_professional_settings($mysqli)
{
    $tenant_id = current_tenant_id();
    $res = $mysqli->query("SELECT id FROM professionals WHERE tenant_id = $tenant_id ORDER BY id ASC");
    if (!$res) {
        return 0;
    }

    $created = 0;
    while ($row = $res->fetch_assoc()) {
        if (cabinet_seed_professional_settings_from_superadmin($mysqli, (int) $row['id'])) {
            $created++;
        }
    }
    return $created;
}

function cabinet_fetch_professional($mysqli, $professional_id)
{
    $tenant_id = current_tenant_id();
    $professional_id = (int) $professional_id;
    if ($professional_id <= 0) {
        return null;
    }

    $exists = $mysqli->query("SHOW TABLES LIKE 'professionals'");
    if (!$exists || $exists->num_rows === 0) {
        return null;
    }

    $stmt = $mysqli->prepare("
        SELECT p.id, p.user_id, p.display_name, p.professional_title, p.license_number,
               p.professional_specialty, p.public_bio, p.public_photo_path, p.public_email, p.public_phone,
               p.instagram_url, p.facebook_url, p.tiktok_url, p.appointment_summary_email_mode,
               u.email AS user_email, u.role AS user_role
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
        WHERE p.tenant_id = ? AND p.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $professional_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }

    $row['notification_email'] = trim($row['public_email'] ?: ($row['user_email'] ?? ''));
    return $row;
}

function cabinet_professional_display_payload($mysqli, $professional_id)
{
    $professional = cabinet_fetch_professional($mysqli, $professional_id);
    if (!$professional) {
        return null;
    }

    $tenant_id = current_tenant_id();
    $dashboard_photo = '';
    $settings_res = $mysqli->query("SELECT profile_image_path FROM payment_settings WHERE tenant_id = $tenant_id");
    if ($settings_row = ($settings_res ? $settings_res->fetch_assoc() : null)) {
        $dashboard_photo = $settings_row['profile_image_path'] ?? '';
    }

    $photo = $professional['public_photo_path'] ?? '';
    if (!$photo && ($professional['user_role'] ?? '') === 'superadmin') {
        $photo = $dashboard_photo;
    }
    $effective_settings = cabinet_get_effective_professional_settings($mysqli, (int) $professional['id']);

    return [
        'id' => (int) $professional['id'],
        'display_name' => $professional['display_name'] ?? '',
        'public_photo_path' => $professional['public_photo_path'] ?? '',
        'display_photo_path' => $photo ?: '',
        'user_role' => $professional['user_role'] ?? '',
        'appointment_delivery_mode' => $effective_settings['appointment_delivery_mode'] ?? 'both'
    ];
}

function cabinet_fetch_public_team_members($mysqli)
{
    ensure_cabinet_schema($mysqli);
    $tenant_id = current_tenant_id();
    $dashboard_photo = '';
    $settings_res = $mysqli->query("SELECT profile_image_path FROM payment_settings WHERE tenant_id = $tenant_id");
    if ($settings_row = ($settings_res ? $settings_res->fetch_assoc() : null)) {
        $dashboard_photo = $settings_row['profile_image_path'] ?? '';
    }
    $members = [];
    $stmt = $mysqli->prepare("
        SELECT p.id, p.user_id, p.display_name, p.professional_title, p.license_number, p.professional_specialty,
               p.public_bio, p.public_photo_path, p.public_email, p.public_phone,
               p.instagram_url, p.facebook_url, p.tiktok_url,
               u.role AS user_role
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
        WHERE p.tenant_id = ? AND p.is_active = 1
        ORDER BY CASE WHEN u.role = 'superadmin' THEN 0 ELSE 1 END ASC,
                 p.sort_order ASC,
                 p.display_name ASC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res) {
        return [];
    }
    while ($row = $res->fetch_assoc()) {
        $row['display_photo_path'] = $row['public_photo_path'] ?: (($row['user_role'] ?? '') === 'superadmin' ? $dashboard_photo : '');
        $members[] = $row;
    }
    return $members;
}

function cabinet_public_team_enabled($mysqli)
{
    ensure_cabinet_schema($mysqli);
    $tenant_id = current_tenant_id();
    $res = $mysqli->query("SELECT show_team_public FROM payment_settings WHERE tenant_id = $tenant_id");
    $settings = $res ? $res->fetch_assoc() : null;
    if (!$settings || (int) ($settings['show_team_public'] ?? 0) !== 1) {
        return false;
    }

    $members = cabinet_fetch_public_team_members($mysqli);
    $has_member_besides_superadmin = false;
    foreach ($members as $member) {
        if (($member['user_role'] ?? '') !== 'superadmin') {
            $has_member_besides_superadmin = true;
            break;
        }
    }

    return count($members) > 1 && $has_member_besides_superadmin;
}

function cabinet_resolve_professional_id($mysqli, $session_user_id, $patient_user_id, $is_admin)
{
    ensure_cabinet_schema($mysqli);
    $tenant_id = current_tenant_id();
    $session_user_id = (int) $session_user_id;
    $patient_user_id = (int) $patient_user_id;

    if ($is_admin && $session_user_id > 0) {
        $stmt = $mysqli->prepare("SELECT id FROM professionals WHERE tenant_id = ? AND user_id = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param("ii", $tenant_id, $session_user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            return (int) $row['id'];
        }
    }

    if ($patient_user_id > 0) {
        $assigned_professional_id = cabinet_patient_primary_professional_id($mysqli, $patient_user_id);
        if ($assigned_professional_id > 0) {
            return $assigned_professional_id;
        }
    }

    if (!$is_admin) {
        $fixed_professional_id = cabinet_new_patient_fixed_professional_id($mysqli);
        if ($fixed_professional_id > 0) {
            return $fixed_professional_id;
        }
    }

    $stmt = $mysqli->prepare("SELECT id FROM professionals WHERE tenant_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        return (int) $row['id'];
    }

    return seed_default_professional($mysqli);
}

function cabinet_assign_patient_to_professional_if_missing($mysqli, $patient_user_id, $professional_id, $notes = 'Asignacion automatica por primera reserva')
{
    ensure_cabinet_schema($mysqli);
    $tenant_id = current_tenant_id();
    $patient_user_id = (int) $patient_user_id;
    $professional_id = (int) $professional_id;
    if ($patient_user_id <= 0 || $professional_id <= 0) {
        return false;
    }
    if (cabinet_patient_primary_professional_id($mysqli, $patient_user_id) > 0) {
        return false;
    }

    $stmt = $mysqli->prepare("
        INSERT INTO patient_professionals (tenant_id, patient_id, professional_id, is_primary, notes)
        VALUES (?, ?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE is_primary = 1, notes = VALUES(notes)
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("iiis", $tenant_id, $patient_user_id, $professional_id, $notes);
    $stmt->execute();

    $profile_exists = $mysqli->query("SHOW TABLES LIKE 'patient_profiles'");
    if ($profile_exists && $profile_exists->num_rows > 0) {
        $stmt = $mysqli->prepare("UPDATE patient_profiles SET professional_id = ? WHERE tenant_id = ? AND user_id = ? AND (professional_id IS NULL OR professional_id = 0)");
        if ($stmt) {
            $stmt->bind_param("iii", $professional_id, $tenant_id, $patient_user_id);
            $stmt->execute();
        }
    }

    return true;
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
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("
        SELECT id, name, email, phone
        FROM users
        WHERE tenant_id = ? AND role IN ('superadmin', 'admin')
        ORDER BY FIELD(role, 'superadmin', 'admin'), id ASC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $res = $stmt->get_result();
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
        INSERT INTO professionals (tenant_id, user_id, display_name, public_slug, public_email, public_phone, is_active, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, 1, 10)
        ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), public_email = VALUES(public_email), public_phone = VALUES(public_phone)
    ");
    $stmt->bind_param("iissss", $tenant_id, $user_id, $display_name, $slug, $email, $phone);
    $stmt->execute();

    $stmt = $mysqli->prepare("SELECT id FROM professionals WHERE tenant_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $tenant_id, $user_id);
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
