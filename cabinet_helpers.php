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
    if (function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }

    $tenant_id = current_tenant_id();
    $mysqli->query("ALTER TABLE users MODIFY role ENUM('superadmin','admin','reception','administration','technical','patient') NOT NULL DEFAULT 'patient'");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS professional_availability_blocks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            professional_id INT UNSIGNED NOT NULL,
            block_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            reason VARCHAR(180) NOT NULL DEFAULT '',
            created_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_availability_blocks_calendar (tenant_id, professional_id, block_date, start_time),
            INDEX idx_availability_blocks_creator (tenant_id, created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
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
            professional_college VARCHAR(180) DEFAULT NULL,
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
    cabinet_add_column_if_missing($mysqli, 'professionals', 'professional_college', "VARCHAR(180) DEFAULT NULL AFTER license_number");
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
            available_session_types VARCHAR(255) DEFAULT NULL,
            available_session_durations VARCHAR(100) DEFAULT NULL,
            appointment_start_time TIME DEFAULT NULL,
            appointment_end_time TIME DEFAULT NULL,
            break_start_time TIME DEFAULT NULL,
            break_end_time TIME DEFAULT NULL,
            available_weekdays VARCHAR(32) DEFAULT NULL,
            default_appointment_location VARCHAR(255) DEFAULT NULL,
            default_location_id INT UNSIGNED DEFAULT NULL,
            livekit_enabled TINYINT(1) NOT NULL DEFAULT 1,
            video_provider VARCHAR(20) NOT NULL DEFAULT 'daily',
            livekit_recording_enabled TINYINT(1) NOT NULL DEFAULT 0,
            livekit_recording_mode VARCHAR(20) NOT NULL DEFAULT 'audio',
            member_permissions_json TEXT DEFAULT NULL,
            min_booking_notice_days INT UNSIGNED DEFAULT NULL,
            max_booking_notice_days INT UNSIGNED DEFAULT NULL,
            knowledge_sector_mode VARCHAR(16) NOT NULL DEFAULT 'own',
            knowledge_sector_keys_json TEXT DEFAULT NULL,
            bonuses_enabled TINYINT(1) DEFAULT NULL,
            create_compensation_bonus_on_paid_cancel TINYINT(1) DEFAULT NULL,
            initial_calendar_view VARCHAR(12) DEFAULT NULL,
            last_calendar_view VARCHAR(12) DEFAULT NULL,
            timezone VARCHAR(64) DEFAULT NULL,
            notify_new_appointments TINYINT(1) DEFAULT NULL,
            notify_cancellations TINYINT(1) DEFAULT NULL,
            notify_payments TINYINT(1) DEFAULT NULL,
            notify_daily_summary TINYINT(1) DEFAULT NULL,
            notify_waiting_list TINYINT(1) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_professional_settings_professional (professional_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    cabinet_add_column_if_missing($mysqli, 'patient_professionals', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
    $mysqli->query("ALTER TABLE professional_settings MODIFY available_session_types VARCHAR(255) DEFAULT NULL");
    $mysqli->query("ALTER TABLE professional_settings MODIFY available_session_durations VARCHAR(100) DEFAULT NULL");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'default_appointment_location', "VARCHAR(255) DEFAULT NULL AFTER available_weekdays");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'default_location_id', "INT UNSIGNED DEFAULT NULL AFTER default_appointment_location");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'livekit_enabled', "TINYINT(1) NOT NULL DEFAULT 1 AFTER default_appointment_location");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'video_provider', "VARCHAR(20) NOT NULL DEFAULT 'daily' AFTER livekit_enabled");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'livekit_recording_enabled', "TINYINT(1) NOT NULL DEFAULT 0 AFTER livekit_enabled");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'livekit_recording_mode', "VARCHAR(20) NOT NULL DEFAULT 'audio' AFTER livekit_recording_enabled");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'member_permissions_json', "TEXT DEFAULT NULL AFTER livekit_enabled");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'min_booking_notice_days', "INT UNSIGNED DEFAULT NULL AFTER available_weekdays");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'max_booking_notice_days', "INT UNSIGNED DEFAULT NULL AFTER min_booking_notice_days");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'bonuses_enabled', "TINYINT(1) DEFAULT NULL AFTER max_booking_notice_days");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'create_compensation_bonus_on_paid_cancel', "TINYINT(1) DEFAULT NULL AFTER bonuses_enabled");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'initial_calendar_view', "VARCHAR(12) DEFAULT NULL AFTER create_compensation_bonus_on_paid_cancel");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'last_calendar_view', "VARCHAR(12) DEFAULT NULL AFTER initial_calendar_view");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'timezone', "VARCHAR(64) DEFAULT NULL AFTER initial_calendar_view");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'notify_new_appointments', "TINYINT(1) DEFAULT NULL AFTER timezone");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'notify_cancellations', "TINYINT(1) DEFAULT NULL AFTER notify_new_appointments");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'notify_payments', "TINYINT(1) DEFAULT NULL AFTER notify_cancellations");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'notify_daily_summary', "TINYINT(1) DEFAULT NULL AFTER notify_payments");
    cabinet_add_column_if_missing($mysqli, 'professional_settings', 'notify_waiting_list', "TINYINT(1) DEFAULT NULL AFTER notify_daily_summary");

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
    cabinet_add_index_if_missing($mysqli, 'appointments', 'idx_appointments_calendar_professional', 'tenant_id, professional_id, status, appointment_date, appointment_time');
    cabinet_add_index_if_missing($mysqli, 'appointments', 'idx_appointments_calendar_patient', 'tenant_id, user_id, status, appointment_date, appointment_time');
    cabinet_add_index_if_missing($mysqli, 'closed_days', 'idx_closed_days_professional_date', 'professional_id, closed_date');
    cabinet_add_index_if_missing($mysqli, 'closed_days', 'idx_closed_days_calendar', 'tenant_id, closed_date, is_global, professional_id');
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
        'default_appointment_location' => '',
        'default_location_id' => null,
        'livekit_enabled' => 1,
        'video_provider' => 'daily',
        'livekit_recording_enabled' => 0,
        'livekit_recording_mode' => 'audio',
        'member_permissions_json' => null,
        'min_booking_notice_days' => 2,
        'max_booking_notice_days' => 40
    ];
}

function cabinet_member_permission_keys()
{
    return [
        'bookable',
        'settings',
        'agenda',
        'patients',
        'appointments',
        'statistics',
        'view_patient_phone',
        'billing',
        'billing_own_patients',
        'reports',
        'private_patient_data',
        'ai_tools',
        'team_notes',
        'team_files',
        'create_appointments',
        'cancel_appointments',
        'issue_attendance_certificates',
        'create_patients'
    ];
}

function cabinet_default_member_permissions_for_role($role)
{
    $role = (string) $role;
    $all = array_fill_keys(cabinet_member_permission_keys(), true);
    if ($role === 'superadmin') {
        return $all;
    }
    if ($role === 'admin') {
        return $all;
    }
    if ($role === 'reception') {
        return [
            'bookable' => false,
            'settings' => false,
            'agenda' => true,
            'patients' => true,
            'appointments' => true,
            'statistics' => false,
            'view_patient_phone' => true,
            'billing' => false,
            'billing_own_patients' => false,
            'reports' => false,
            'private_patient_data' => false,
            'ai_tools' => false,
            'team_notes' => false,
            'team_files' => false,
            'create_appointments' => true,
            'cancel_appointments' => true,
            'issue_attendance_certificates' => true,
            'create_patients' => true
        ];
    }
    if ($role === 'administration') {
        return [
            'bookable' => false,
            'settings' => false,
            'agenda' => true,
            'patients' => true,
            'appointments' => true,
            'statistics' => true,
            'view_patient_phone' => true,
            'billing' => true,
            'billing_own_patients' => false,
            'reports' => false,
            'private_patient_data' => false,
            'ai_tools' => false,
            'team_notes' => false,
            'team_files' => false,
            'create_appointments' => true,
            'cancel_appointments' => true,
            'issue_attendance_certificates' => true,
            'create_patients' => true
        ];
    }
    if ($role === 'technical') {
        return [
            'bookable' => false,
            'settings' => true,
            'agenda' => false,
            'patients' => false,
            'appointments' => false,
            'statistics' => false,
            'view_patient_phone' => false,
            'billing' => false,
            'billing_own_patients' => false,
            'reports' => false,
            'private_patient_data' => false,
            'ai_tools' => false,
            'team_notes' => false,
            'team_files' => false,
            'create_appointments' => false,
            'cancel_appointments' => false,
            'issue_attendance_certificates' => false,
            'create_patients' => false
        ];
    }

    return array_fill_keys(cabinet_member_permission_keys(), false);
}

function cabinet_normalize_member_permissions($permissions, $role)
{
    if (is_string($permissions) && trim($permissions) !== '') {
        $decoded = json_decode($permissions, true);
        $permissions = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($permissions)) {
        $permissions = [];
    }

    $normalized = cabinet_default_member_permissions_for_role($role);
    foreach (cabinet_member_permission_keys() as $key) {
        if (array_key_exists($key, $permissions)) {
            $normalized[$key] = !empty($permissions[$key]);
        }
    }
    if ($role === 'superadmin') {
        $normalized = array_fill_keys(cabinet_member_permission_keys(), true);
    }
    return $normalized;
}

function cabinet_member_permissions_json($permissions, $role)
{
    return json_encode(cabinet_normalize_member_permissions($permissions, $role), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function cabinet_member_has_permission($permissions_json, $role, $permission)
{
    $permissions = cabinet_normalize_member_permissions($permissions_json, $role);
    return !empty($permissions[$permission]);
}

function cabinet_member_permissions_for_user($mysqli, $user_id, $role = null)
{
    ensure_cabinet_schema($mysqli);
    $tenant_id = current_tenant_id();
    $user_id = (int) $user_id;
    $role = $role !== null ? (string) $role : '';
    if ($user_id <= 0) {
        return cabinet_default_member_permissions_for_role($role);
    }

    $stmt = $mysqli->prepare("
        SELECT u.role, ps.member_permissions_json
        FROM users u
        LEFT JOIN professionals p ON p.user_id = u.id AND p.tenant_id = u.tenant_id
        LEFT JOIN professional_settings ps ON ps.professional_id = p.id AND ps.tenant_id = p.tenant_id
        WHERE u.tenant_id = ? AND u.id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return cabinet_default_member_permissions_for_role($role);
    }
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return cabinet_default_member_permissions_for_role($role);
    }
    $effective_role = $row['role'] ?: $role;
    return cabinet_normalize_member_permissions($row['member_permissions_json'] ?? null, $effective_role);
}

function cabinet_global_settings_as_professional_defaults($mysqli)
{
    $defaults = cabinet_default_professional_settings();
    $tenant_id = current_tenant_id();
    $columns = array_map(fn($column) => "`$column`", cabinet_professional_settings_columns());
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
    $stmt = $mysqli->prepare("
        SELECT p.id, u.role AS user_role, ps.member_permissions_json
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
        LEFT JOIN professional_settings ps ON ps.professional_id = p.id AND ps.tenant_id = p.tenant_id
        WHERE p.tenant_id = ?
          AND p.id = ?
          AND p.is_active = 1
          AND u.role IN ('superadmin', 'admin')
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ii", $tenant_id, $professional_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row && cabinet_member_has_permission($row['member_permissions_json'] ?? null, $row['user_role'] ?? 'admin', 'bookable');
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

    $stmt = $mysqli->prepare("SELECT professional_id FROM patient_profiles WHERE tenant_id = ? AND user_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("ii", $tenant_id, $patient_user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && !empty($row['professional_id'])) {
            return (int) $row['professional_id'];
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

    $stmt = $mysqli->prepare("
        SELECT p.id, u.role AS user_role, ps.member_permissions_json
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
        LEFT JOIN professional_settings ps ON ps.professional_id = p.id AND ps.tenant_id = p.tenant_id
        WHERE p.tenant_id = ?
          AND p.is_active = 1
          AND u.role IN ('superadmin', 'admin')
        ORDER BY p.sort_order ASC, p.id ASC
        LIMIT 1
    ");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if (cabinet_member_has_permission($row['member_permissions_json'] ?? null, $row['user_role'] ?? 'admin', 'bookable')) {
            return (int) $row['id'];
        }
    }
    return 0;
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
        SELECT p.id, p.display_name, p.public_photo_path, u.role AS user_role, ps.member_permissions_json
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
        LEFT JOIN professional_settings ps ON ps.professional_id = p.id AND ps.tenant_id = p.tenant_id
        WHERE p.tenant_id = ?
          AND p.is_active = 1
          AND u.role IN ('superadmin', 'admin')
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
        if (!cabinet_member_has_permission($row['member_permissions_json'] ?? null, $row['user_role'] ?? 'admin', 'bookable')) {
            continue;
        }
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
               available_weekdays, default_appointment_location, default_location_id, livekit_enabled, video_provider,
               livekit_recording_enabled, livekit_recording_mode, min_booking_notice_days, max_booking_notice_days
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

function cabinet_get_professional_preferences($mysqli, $professional_id)
{
    ensure_cabinet_schema($mysqli);
    $tenant_id = current_tenant_id();
    $professional_id = (int) $professional_id;
    $global_res = $mysqli->query("SELECT initial_calendar_view FROM payment_settings WHERE tenant_id = $tenant_id LIMIT 1");
    $global = $global_res ? ($global_res->fetch_assoc() ?: []) : [];
    $global_view_mode = in_array($global['initial_calendar_view'] ?? '', ['dashboard', 'week', 'month', 'agenda', 'list', 'patients', 'upcoming', 'remember'], true)
        ? $global['initial_calendar_view'] : 'month';
    $defaults = [
        'initial_calendar_view' => $global_view_mode === 'remember' ? 'month' : $global_view_mode,
        'initial_calendar_view_mode' => $global_view_mode,
        'initial_calendar_view_override' => '',
        'last_calendar_view' => 'month',
        'timezone' => function_exists('tenant_timezone') ? tenant_timezone() : date_default_timezone_get(),
        'timezone_override' => '',
        'notify_new_appointments' => 1,
        'notify_cancellations' => 1,
        'notify_payments' => 1,
        'notify_daily_summary' => 1,
        'notify_waiting_list' => 1,
    ];
    if ($professional_id <= 0) return $defaults;
    $preference_columns = ['initial_calendar_view', 'last_calendar_view', 'timezone', 'notify_new_appointments', 'notify_cancellations', 'notify_payments', 'notify_daily_summary', 'notify_waiting_list'];
    foreach ($preference_columns as $preference_column) {
        if (!cabinet_column_exists($mysqli, 'professional_settings', $preference_column)) return $defaults;
    }
    $stmt = $mysqli->prepare("
        SELECT initial_calendar_view, last_calendar_view, timezone, notify_new_appointments, notify_cancellations,
               notify_payments, notify_daily_summary, notify_waiting_list
        FROM professional_settings
        WHERE tenant_id = ? AND professional_id = ? LIMIT 1
    ");
    $stmt->bind_param('ii', $tenant_id, $professional_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $allowed_views = ['dashboard', 'week', 'month', 'agenda', 'list', 'patients', 'upcoming', 'remember'];
    $view_override = in_array($row['initial_calendar_view'] ?? '', $allowed_views, true) ? $row['initial_calendar_view'] : '';
    $last_view = in_array($row['last_calendar_view'] ?? '', ['month', 'week', 'agenda', 'list'], true) ? $row['last_calendar_view'] : 'month';
    $view_mode = $view_override ?: $global_view_mode;
    $timezone_override = function_exists('app_valid_timezone') && app_valid_timezone($row['timezone'] ?? '') ? $row['timezone'] : '';
    $defaults['initial_calendar_view_override'] = $view_override;
    $defaults['initial_calendar_view_mode'] = $view_mode;
    $defaults['last_calendar_view'] = $last_view;
    $defaults['initial_calendar_view'] = $view_mode === 'remember' ? $last_view : $view_mode;
    $defaults['timezone_override'] = $timezone_override;
    $defaults['timezone'] = $timezone_override ?: $defaults['timezone'];
    foreach (['notify_new_appointments', 'notify_cancellations', 'notify_payments', 'notify_daily_summary', 'notify_waiting_list'] as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null) {
            $defaults[$key] = (int) $row[$key] === 1 ? 1 : 0;
        }
    }
    return $defaults;
}

function cabinet_professional_notification_enabled($mysqli, $professional_id, $notification)
{
    $allowed = ['new_appointments', 'cancellations', 'payments', 'daily_summary', 'waiting_list'];
    $notification = (string) $notification;
    if (!in_array($notification, $allowed, true)) return true;
    if (function_exists('plan_feature_enabled_from_db')
        && !plan_feature_enabled_from_db($mysqli, 'reminders.patient24h', false)) {
        return false;
    }
    $preferences = cabinet_get_professional_preferences($mysqli, (int) $professional_id);
    return !empty($preferences['notify_' . $notification]);
}

function cabinet_save_last_calendar_view($mysqli, $professional_id, $view)
{
    ensure_cabinet_schema($mysqli);
    $tenant_id = current_tenant_id();
    $professional_id = (int) $professional_id;
    $view = (string) $view;
    if ($professional_id <= 0 || !in_array($view, ['month', 'week', 'agenda', 'list'], true)) return false;
    $stmt = $mysqli->prepare("
        INSERT INTO professional_settings (tenant_id, professional_id, last_calendar_view)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE last_calendar_view = VALUES(last_calendar_view)
    ");
    $stmt->bind_param('iis', $tenant_id, $professional_id, $view);
    return $stmt->execute();
}

function cabinet_save_professional_preferences($mysqli, $professional_id, $preferences)
{
    ensure_cabinet_schema($mysqli);
    $tenant_id = current_tenant_id();
    $professional_id = (int) $professional_id;
    if ($professional_id <= 0) return false;
    foreach (['initial_calendar_view', 'timezone', 'notify_new_appointments', 'notify_cancellations', 'notify_payments', 'notify_daily_summary', 'notify_waiting_list'] as $preference_column) {
        if (!cabinet_column_exists($mysqli, 'professional_settings', $preference_column)) return false;
    }
    $allowed_views = ['dashboard', 'week', 'month', 'agenda', 'list', 'patients', 'upcoming', 'remember'];
    $view = in_array($preferences['initial_calendar_view_override'] ?? '', $allowed_views, true) ? $preferences['initial_calendar_view_override'] : null;
    $timezone = trim((string) ($preferences['timezone_override'] ?? ''));
    if ($timezone === '' || !function_exists('app_valid_timezone') || !app_valid_timezone($timezone)) $timezone = null;
    $new = !empty($preferences['notify_new_appointments']) ? 1 : 0;
    $cancel = !empty($preferences['notify_cancellations']) ? 1 : 0;
    $payments = !empty($preferences['notify_payments']) ? 1 : 0;
    $daily = !empty($preferences['notify_daily_summary']) ? 1 : 0;
    $waiting = !empty($preferences['notify_waiting_list']) ? 1 : 0;
    if (function_exists('plan_feature_enabled_from_db')
        && !plan_feature_enabled_from_db($mysqli, 'reminders.patient24h', false)) {
        $new = $cancel = $payments = $daily = $waiting = 0;
    }
    $stmt = $mysqli->prepare("
        INSERT INTO professional_settings
            (tenant_id, professional_id, initial_calendar_view, timezone, notify_new_appointments,
             notify_cancellations, notify_payments, notify_daily_summary, notify_waiting_list)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE initial_calendar_view = VALUES(initial_calendar_view), timezone = VALUES(timezone),
            notify_new_appointments = VALUES(notify_new_appointments), notify_cancellations = VALUES(notify_cancellations),
            notify_payments = VALUES(notify_payments), notify_daily_summary = VALUES(notify_daily_summary),
            notify_waiting_list = VALUES(notify_waiting_list)
    ");
    $stmt->bind_param('iissiiiii', $tenant_id, $professional_id, $view, $timezone, $new, $cancel, $payments, $daily, $waiting);
    return $stmt->execute();
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
    $default_appointment_location = trim((string) ($settings['default_appointment_location'] ?? ''));
    $default_location_id = !empty($settings['default_location_id']) ? (int) $settings['default_location_id'] : null;
    $livekit_enabled = !empty($settings['livekit_enabled']) ? 1 : 0;
    $video_provider = strtolower(trim((string) ($settings['video_provider'] ?? ($livekit_enabled ? 'daily' : 'manual'))));
    if ($video_provider === 'livekit') $video_provider = 'daily';
    if (!in_array($video_provider, ['daily', 'manual'], true)) $video_provider = $livekit_enabled ? 'daily' : 'manual';
    $livekit_enabled = $video_provider === 'manual' ? 0 : 1;
    $livekit_recording_enabled = !empty($settings['livekit_recording_enabled']) ? 1 : 0;
    $livekit_recording_mode = (string) ($settings['livekit_recording_mode'] ?? 'audio');
    if (!in_array($livekit_recording_mode, ['audio', 'audio_video'], true)) {
        $livekit_recording_mode = 'audio';
    }
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
            default_appointment_location,
            default_location_id,
            livekit_enabled,
            video_provider,
            livekit_recording_enabled,
            livekit_recording_mode,
            min_booking_notice_days,
            max_booking_notice_days
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            appointment_delivery_mode = VALUES(appointment_delivery_mode),
            available_session_types = VALUES(available_session_types),
            available_session_durations = VALUES(available_session_durations),
            appointment_start_time = VALUES(appointment_start_time),
            appointment_end_time = VALUES(appointment_end_time),
            break_start_time = VALUES(break_start_time),
            break_end_time = VALUES(break_end_time),
            available_weekdays = VALUES(available_weekdays),
            default_appointment_location = VALUES(default_appointment_location),
            default_location_id = VALUES(default_location_id),
            livekit_enabled = VALUES(livekit_enabled),
            video_provider = VALUES(video_provider),
            livekit_recording_enabled = VALUES(livekit_recording_enabled),
            livekit_recording_mode = VALUES(livekit_recording_mode),
            min_booking_notice_days = VALUES(min_booking_notice_days),
            max_booking_notice_days = VALUES(max_booking_notice_days)
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "iisssssssssiisisii",
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
        $default_appointment_location,
        $default_location_id,
        $livekit_enabled,
        $video_provider,
        $livekit_recording_enabled,
        $livekit_recording_mode,
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
            default_appointment_location,
            default_location_id,
            livekit_enabled,
            video_provider,
            livekit_recording_enabled,
            livekit_recording_mode,
            min_booking_notice_days,
            max_booking_notice_days
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
    $default_appointment_location = trim((string) ($settings['default_appointment_location'] ?? ''));
    $default_location_id = !empty($settings['default_location_id']) ? (int) $settings['default_location_id'] : null;
    $livekit_enabled = !empty($settings['livekit_enabled']) ? 1 : 0;
    $video_provider = strtolower(trim((string) ($settings['video_provider'] ?? ($livekit_enabled ? 'daily' : 'manual'))));
    if ($video_provider === 'livekit') $video_provider = 'daily';
    if (!in_array($video_provider, ['daily', 'manual'], true)) $video_provider = $livekit_enabled ? 'daily' : 'manual';
    $livekit_recording_enabled = !empty($settings['livekit_recording_enabled']) ? 1 : 0;
    $livekit_recording_mode = (string) ($settings['livekit_recording_mode'] ?? 'audio');
    if (!in_array($livekit_recording_mode, ['audio', 'audio_video'], true)) {
        $livekit_recording_mode = 'audio';
    }
    $min_booking_notice_days = (int) ($settings['min_booking_notice_days'] ?? 2);
    $max_booking_notice_days = (int) ($settings['max_booking_notice_days'] ?? 40);

    $stmt->bind_param(
        "iisssssssssiisisii",
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
        $default_appointment_location,
        $default_location_id,
        $livekit_enabled,
        $video_provider,
        $livekit_recording_enabled,
        $livekit_recording_mode,
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

    $stmt = $mysqli->prepare("
        SELECT p.id, p.user_id, p.display_name, p.professional_title, p.license_number, p.professional_college,
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

    $photo = $professional['public_photo_path'] ?? '';
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
    $members = [];
    $stmt = $mysqli->prepare("
        SELECT p.id, p.user_id, p.display_name, p.professional_title, p.license_number, p.professional_college, p.professional_specialty,
               p.public_bio, p.public_photo_path, p.public_email, p.public_phone,
               p.instagram_url, p.facebook_url, p.tiktok_url,
               u.role AS user_role, ps.member_permissions_json
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
        LEFT JOIN professional_settings ps ON ps.professional_id = p.id AND ps.tenant_id = p.tenant_id
        WHERE p.tenant_id = ?
          AND p.is_active = 1
          AND u.role IN ('superadmin', 'admin')
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
        if (!cabinet_member_has_permission($row['member_permissions_json'] ?? null, $row['user_role'] ?? 'admin', 'bookable')) {
            continue;
        }
        $row['display_photo_path'] = $row['public_photo_path'] ?: '';
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
    return $settings && (int) ($settings['show_team_public'] ?? 0) === 1;
}

function cabinet_resolve_professional_id($mysqli, $session_user_id, $patient_user_id, $is_admin)
{
    ensure_cabinet_schema($mysqli);
    $tenant_id = current_tenant_id();
    $session_user_id = (int) $session_user_id;
    $patient_user_id = (int) $patient_user_id;

    if ($is_admin && $session_user_id > 0) {
        $stmt = $mysqli->prepare("
            SELECT p.id, u.role AS user_role, ps.member_permissions_json
            FROM professionals p
            LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
            LEFT JOIN professional_settings ps ON ps.professional_id = p.id AND ps.tenant_id = p.tenant_id
            WHERE p.tenant_id = ?
              AND p.user_id = ?
              AND p.is_active = 1
              AND u.role IN ('superadmin', 'admin')
            LIMIT 1
        ");
        $stmt->bind_param("ii", $tenant_id, $session_user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && cabinet_member_has_permission($row['member_permissions_json'] ?? null, $row['user_role'] ?? 'admin', 'bookable')) {
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

    $stmt = $mysqli->prepare("
        SELECT p.id, u.role AS user_role, ps.member_permissions_json
        FROM professionals p
        LEFT JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
        LEFT JOIN professional_settings ps ON ps.professional_id = p.id AND ps.tenant_id = p.tenant_id
        WHERE p.tenant_id = ?
          AND p.is_active = 1
          AND u.role IN ('superadmin', 'admin')
        ORDER BY p.sort_order ASC, p.id ASC
        LIMIT 1
    ");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if (cabinet_member_has_permission($row['member_permissions_json'] ?? null, $row['user_role'] ?? 'admin', 'bookable')) {
            return (int) $row['id'];
        }
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

    $stmt = $mysqli->prepare("UPDATE patient_profiles SET professional_id = ? WHERE tenant_id = ? AND user_id = ? AND (professional_id IS NULL OR professional_id = 0)");
    if ($stmt) {
        $stmt->bind_param("iii", $professional_id, $tenant_id, $patient_user_id);
        $stmt->execute();
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
