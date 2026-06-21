<?php
require_once __DIR__ . '/sector_text_helpers.php';
require_once __DIR__ . '/dashboard_config_helpers.php';

function payment_column_exists($mysqli, $table, $column)
{
    $table = $mysqli->real_escape_string($table);
    $column = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function payment_add_column_if_missing($mysqli, $table, $column, $definition)
{
    if (!payment_column_exists($mysqli, $table, $column)) {
        $mysqli->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function payment_index_exists($mysqli, $table, $index)
{
    $table = $mysqli->real_escape_string($table);
    $index = $mysqli->real_escape_string($index);
    $res = $mysqli->query("SHOW INDEX FROM `$table` WHERE Key_name = '$index'");
    return $res && $res->num_rows > 0;
}

function payment_drop_single_column_unique_indexes($mysqli, $table, $column)
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
            $mysqli->query("ALTER TABLE `$table` DROP INDEX `$key_sql`");
        }
    }
}

function ensure_appointment_payment_columns($mysqli)
{
    payment_add_column_if_missing($mysqli, 'appointments', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER id");
    $columns = [
        'payment_status' => "ALTER TABLE appointments ADD payment_status VARCHAR(32) NOT NULL DEFAULT 'pending'",
        'payment_method' => "ALTER TABLE appointments ADD payment_method VARCHAR(16) DEFAULT NULL",
        'paid_at' => "ALTER TABLE appointments ADD paid_at DATETIME DEFAULT NULL",
        'payment_updated_at' => "ALTER TABLE appointments ADD payment_updated_at DATETIME DEFAULT NULL",
        'payment_updated_by' => "ALTER TABLE appointments ADD payment_updated_by INT UNSIGNED DEFAULT NULL",
        'payment_attempt_id' => "ALTER TABLE appointments ADD payment_attempt_id INT UNSIGNED DEFAULT NULL",
        'google_calendar_event_id' => "ALTER TABLE appointments ADD google_calendar_event_id VARCHAR(255) DEFAULT NULL",
        'icloud_calendar_event_url' => "ALTER TABLE appointments ADD icloud_calendar_event_url VARCHAR(512) DEFAULT NULL",
        'cancel_token' => "ALTER TABLE appointments ADD cancel_token VARCHAR(64) DEFAULT NULL",
        'cancelled_at' => "ALTER TABLE appointments ADD cancelled_at DATETIME DEFAULT NULL",
        'reminder_sent_at' => "ALTER TABLE appointments ADD reminder_sent_at DATETIME DEFAULT NULL",
        'online_session_url' => "ALTER TABLE appointments ADD online_session_url VARCHAR(500) DEFAULT NULL",
        'consultation_type' => "ALTER TABLE appointments ADD consultation_type VARCHAR(16) NOT NULL DEFAULT 'presencial'",
        'service_type' => "ALTER TABLE appointments ADD service_type VARCHAR(16) NOT NULL DEFAULT 'individual'",
        'service_option_id' => "ALTER TABLE appointments ADD service_option_id INT UNSIGNED DEFAULT NULL",
        'duration_minutes' => "ALTER TABLE appointments ADD duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60",
        'patient_bonus_id' => "ALTER TABLE appointments ADD patient_bonus_id INT UNSIGNED DEFAULT NULL"
    ];

    foreach ($columns as $column => $sql) {
        $res = $mysqli->query("SHOW COLUMNS FROM appointments LIKE '$column'");
        if ($res->num_rows === 0) {
            $mysqli->query($sql);
        }
    }
}

function ensure_appointment_services_tables($mysqli)
{
    $tenant_id = current_tenant_id();
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS appointment_services (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            service_key VARCHAR(32) NOT NULL,
            name VARCHAR(120) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    payment_add_column_if_missing($mysqli, 'appointment_services', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS appointment_service_options (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            service_id INT UNSIGNED NOT NULL,
            duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
            consultation_type ENUM('presencial', 'online') NOT NULL DEFAULT 'presencial',
            price DECIMAL(10,2) NOT NULL DEFAULT 70.00,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_service_option (service_id, duration_minutes, consultation_type),
            INDEX idx_service_options_service (service_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    payment_add_column_if_missing($mysqli, 'appointment_service_options', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
    payment_drop_single_column_unique_indexes($mysqli, 'appointment_services', 'service_key');
    if (!payment_index_exists($mysqli, 'appointment_services', 'uniq_appointment_services_tenant_key')) {
        $mysqli->query("ALTER TABLE appointment_services ADD UNIQUE uniq_appointment_services_tenant_key (tenant_id, service_key)");
    }
    if (!payment_index_exists($mysqli, 'appointment_service_options', 'uniq_service_option_tenant')) {
        $mysqli->query("ALTER TABLE appointment_service_options ADD UNIQUE uniq_service_option_tenant (tenant_id, service_id, duration_minutes, consultation_type)");
    }

    seed_default_appointment_services($mysqli);
}

function ensure_bonus_tables($mysqli)
{
    $tenant_id = current_tenant_id();
    $settings_table = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if ($settings_table && $settings_table->num_rows > 0) {
        $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'bonuses_enabled'");
        if ($column_res && $column_res->num_rows === 0) {
            $mysqli->query("ALTER TABLE payment_settings ADD bonuses_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER show_prices_public");
        }
        $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'create_compensation_bonus_on_paid_cancel'");
        if ($column_res && $column_res->num_rows === 0) {
            $mysqli->query("ALTER TABLE payment_settings ADD create_compensation_bonus_on_paid_cancel TINYINT(1) NOT NULL DEFAULT 1 AFTER bonuses_enabled");
        }
    }

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS appointment_bonuses (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            bonus_key VARCHAR(32) NOT NULL,
            name VARCHAR(120) NOT NULL,
            session_count SMALLINT UNSIGNED NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    payment_add_column_if_missing($mysqli, 'appointment_bonuses', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
    payment_drop_single_column_unique_indexes($mysqli, 'appointment_bonuses', 'bonus_key');
    if (!payment_index_exists($mysqli, 'appointment_bonuses', 'uniq_appointment_bonuses_tenant_key')) {
        $mysqli->query("ALTER TABLE appointment_bonuses ADD UNIQUE uniq_appointment_bonuses_tenant_key (tenant_id, bonus_key)");
    }

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS patient_bonuses (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            user_id INT UNSIGNED NOT NULL,
            bonus_id INT UNSIGNED NOT NULL,
            total_sessions SMALLINT UNSIGNED NOT NULL,
            remaining_sessions SMALLINT UNSIGNED NOT NULL,
            status ENUM('active', 'used', 'expired', 'cancelled') NOT NULL DEFAULT 'active',
            purchased_at DATETIME DEFAULT NULL,
            expires_at DATE DEFAULT NULL,
            payment_attempt_id INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_patient_bonuses_user (user_id),
            INDEX idx_patient_bonuses_bonus (bonus_id),
            INDEX idx_patient_bonuses_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    payment_add_column_if_missing($mysqli, 'patient_bonuses', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");

    seed_default_bonuses($mysqli);
}

function seed_default_bonuses($mysqli)
{
    $tenant_id = current_tenant_id();
    $bonuses = [
        ['4_sessions', 'Bono de 4 sesiones', 4, 250.00, 10],
        ['10_sessions', 'Bono de 10 sesiones', 10, 600.00, 20]
    ];

    foreach ($bonuses as $bonus) {
        [$key, $name, $sessions, $price, $sort] = $bonus;
        $stmt = $mysqli->prepare("
            INSERT INTO appointment_bonuses (tenant_id, bonus_key, name, session_count, price, is_active, sort_order)
            VALUES (?, ?, ?, ?, ?, 0, ?)
            ON DUPLICATE KEY UPDATE bonus_key = bonus_key
        ");
        $stmt->bind_param("issidi", $tenant_id, $key, $name, $sessions, $price, $sort);
        $stmt->execute();
    }
}

function fetch_appointment_bonuses($mysqli, $only_active = false, $include_internal = false)
{
    $tenant_id = current_tenant_id();
    ensure_bonus_tables($mysqli);
    $conditions = ["tenant_id = $tenant_id"];
    if ($only_active) {
        $conditions[] = "is_active = 1";
    }
    if (!$include_internal) {
        $conditions[] = "bonus_key NOT LIKE 'internal_%'";
    }
    $where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";
    $res = $mysqli->query("
        SELECT id, bonus_key, name, session_count, price, is_active, sort_order
        FROM appointment_bonuses
        $where
        ORDER BY sort_order ASC, id ASC
    ");

    $bonuses = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['session_count'] = (int) $row['session_count'];
        $row['price'] = number_format((float) $row['price'], 2, '.', '');
        $row['is_active'] = (int) $row['is_active'];
        $bonuses[] = $row;
    }

    return $bonuses;
}

function bonuses_are_enabled($mysqli)
{
    if (!app_feature_enabled_from_db($mysqli, 'bonuses.enabled', false)) {
        return false;
    }
    ensure_bonus_tables($mysqli);
    $tenant_id = current_tenant_id();
    $res = $mysqli->query("SELECT bonuses_enabled FROM payment_settings WHERE tenant_id = $tenant_id");
    if (!$res || !($row = $res->fetch_assoc())) {
        return false;
    }
    return (int) ($row['bonuses_enabled'] ?? 0) === 1;
}

function compensation_bonus_on_paid_cancel_enabled($mysqli)
{
    ensure_bonus_tables($mysqli);
    $tenant_id = current_tenant_id();
    $settings_table = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if (!$settings_table || $settings_table->num_rows === 0) {
        return true;
    }

    $res = $mysqli->query("SELECT create_compensation_bonus_on_paid_cancel FROM payment_settings WHERE tenant_id = $tenant_id");
    if (!$res || !($row = $res->fetch_assoc())) {
        return true;
    }

    return (int) ($row['create_compensation_bonus_on_paid_cancel'] ?? 1) === 1;
}

function fetch_patient_bonus_balance($mysqli, $user_id)
{
    $tenant_id = current_tenant_id();
    ensure_bonus_tables($mysqli);
    $stmt = $mysqli->prepare("
        SELECT pb.id, pb.bonus_id, pb.total_sessions, pb.remaining_sessions, pb.status,
               pb.purchased_at, pb.expires_at, b.name, b.session_count
        FROM patient_bonuses pb
        JOIN appointment_bonuses b ON b.id = pb.bonus_id AND b.tenant_id = pb.tenant_id
        WHERE pb.tenant_id = ?
          AND pb.user_id = ?
          AND pb.status = 'active'
          AND pb.remaining_sessions > 0
          AND b.is_active = 1
          AND (pb.expires_at IS NULL OR pb.expires_at >= CURDATE())
        ORDER BY pb.expires_at IS NULL ASC, pb.expires_at ASC, pb.purchased_at ASC, pb.id ASC
    ");
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $bonuses = [];
    $total_remaining = 0;

    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['bonus_id'] = (int) $row['bonus_id'];
        $row['total_sessions'] = (int) $row['total_sessions'];
        $row['remaining_sessions'] = (int) $row['remaining_sessions'];
        $row['session_count'] = (int) $row['session_count'];
        $total_remaining += $row['remaining_sessions'];
        $bonuses[] = $row;
    }

    return [
        'total_remaining' => $total_remaining,
        'bonuses' => $bonuses
    ];
}

function claim_patient_bonus_session($mysqli, $user_id)
{
    $tenant_id = current_tenant_id();
    $balance = fetch_patient_bonus_balance($mysqli, $user_id);
    if (empty($balance['bonuses'])) {
        return null;
    }

    $bonus = $balance['bonuses'][0];
    $patient_bonus_id = (int) $bonus['id'];
    $stmt = $mysqli->prepare("
        UPDATE patient_bonuses
        SET remaining_sessions = remaining_sessions - 1,
            status = CASE WHEN remaining_sessions - 1 <= 0 THEN 'used' ELSE 'active' END
        WHERE tenant_id = ?
          AND id = ?
          AND user_id = ?
          AND status = 'active'
          AND remaining_sessions > 0
    ");
    $stmt->bind_param("iii", $tenant_id, $patient_bonus_id, $user_id);
    $stmt->execute();

    if ($stmt->affected_rows <= 0) {
        return null;
    }

    return [
        'patient_bonus_id' => $patient_bonus_id,
        'bonus_name' => $bonus['name'],
        'remaining_before' => (int) $bonus['remaining_sessions'],
        'remaining_after' => max(0, ((int) $bonus['remaining_sessions']) - 1)
    ];
}

function compensation_bonus_id($mysqli)
{
    $tenant_id = current_tenant_id();
    ensure_bonus_tables($mysqli);
    $key = 'internal_compensation_1_session';
    $name = 'Vale por cancelacion';
    $sessions = 1;
    $price = 0.00;
    $sort = 999;

    $stmt = $mysqli->prepare("
        INSERT INTO appointment_bonuses (tenant_id, bonus_key, name, session_count, price, is_active, sort_order)
        VALUES (?, ?, ?, ?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE name = VALUES(name), session_count = VALUES(session_count), is_active = 1
    ");
    $stmt->bind_param("issidi", $tenant_id, $key, $name, $sessions, $price, $sort);
    $stmt->execute();

    $stmt = $mysqli->prepare("SELECT id FROM appointment_bonuses WHERE tenant_id = ? AND bonus_key = ? LIMIT 1");
    $stmt->bind_param("is", $tenant_id, $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row ? (int) $row['id'] : null;
}

function create_compensation_bonus_for_user($mysqli, $user_id, $payment_attempt_id = null)
{
    $tenant_id = current_tenant_id();
    $bonus_id = compensation_bonus_id($mysqli);
    if (!$bonus_id) {
        throw new \Exception('No se pudo preparar el bono de compensacion.');
    }

    $total_sessions = 1;
    $remaining_sessions = 1;
    $status = 'active';
    $stmt = $mysqli->prepare("
        INSERT INTO patient_bonuses (tenant_id, user_id, bonus_id, total_sessions, remaining_sessions, status, purchased_at, payment_attempt_id)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
    ");
    $stmt->bind_param("iiiiisi", $tenant_id, $user_id, $bonus_id, $total_sessions, $remaining_sessions, $status, $payment_attempt_id);
    $stmt->execute();

    return $mysqli->insert_id;
}

function restore_patient_bonus_session($mysqli, $patient_bonus_id)
{
    if (!$patient_bonus_id) {
        return false;
    }

    ensure_bonus_tables($mysqli);
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("
        UPDATE patient_bonuses
        SET remaining_sessions = remaining_sessions + 1,
            status = 'active'
        WHERE tenant_id = ? AND id = ?
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_bonus_id);
    $stmt->execute();

    return $stmt->affected_rows > 0;
}

function seed_default_appointment_services($mysqli)
{
    $tenant_id = current_tenant_id();
    ensure_payment_settings_price_columns($mysqli);
    $sector_texts = sector_texts_for_db($mysqli);
    $sector_services = sector_appointment_services_from_texts($sector_texts);
    $sector_durations = sector_appointment_durations_from_texts($sector_texts);
    $default_service_keys = sector_default_appointment_service_keys($sector_texts);
    $default_durations = sector_default_appointment_duration_minutes($sector_texts);

    $settings = [
        'appointment_price' => 70.00,
        'online_appointment_price' => 70.00,
        'couple_appointment_price' => 90.00,
        'online_couple_appointment_price' => 90.00,
        'available_session_types' => implode(',', $default_service_keys),
        'available_session_durations' => implode(',', $default_durations),
        'appointment_delivery_mode' => 'both'
    ];

    $settings_table = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if ($settings_table && $settings_table->num_rows > 0) {
        $columns = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'available_session_types'");
        if ($columns && $columns->num_rows === 0) {
            $mysqli->query("ALTER TABLE payment_settings ADD available_session_types VARCHAR(100) NOT NULL DEFAULT 'individual'");
        }
        $columns = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'available_session_durations'");
        if ($columns && $columns->num_rows === 0) {
            $mysqli->query("ALTER TABLE payment_settings ADD available_session_durations VARCHAR(50) NOT NULL DEFAULT '60'");
        }
        $columns = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'appointment_delivery_mode'");
        if ($columns && $columns->num_rows === 0) {
            $mysqli->query("ALTER TABLE payment_settings ADD appointment_delivery_mode ENUM('both', 'presencial', 'online') NOT NULL DEFAULT 'both'");
        }
        $res = $mysqli->query("
            SELECT appointment_price, online_appointment_price, couple_appointment_price, online_couple_appointment_price,
                   available_session_types, available_session_durations, appointment_delivery_mode
            FROM payment_settings
            WHERE tenant_id = $tenant_id
        ");
        if ($res && ($row = $res->fetch_assoc())) {
            $settings = array_merge($settings, $row);
        }
    }

    $allowed_service_keys = array_column($sector_services, 'key');
    $active_service_types = array_values(array_intersect(
        array_filter(array_map('trim', explode(',', (string) ($settings['available_session_types'] ?? '')))),
        $allowed_service_keys
    ));
    if (!$active_service_types) {
        $active_service_types = $default_service_keys;
    }
    $allowed_durations = array_map(fn($item) => (int) $item['minutes'], $sector_durations);
    $active_durations = array_values(array_intersect(
        array_map('intval', array_filter(array_map('trim', explode(',', (string) ($settings['available_session_durations'] ?? ''))))),
        $allowed_durations
    ));
    if (!$active_durations) {
        $active_durations = $default_durations;
    }
    if ($settings_table && $settings_table->num_rows > 0) {
        $normalized_types = implode(',', $active_service_types);
        $normalized_durations = implode(',', $active_durations);
        $stmt = $mysqli->prepare("UPDATE payment_settings SET available_session_types = ?, available_session_durations = ? WHERE tenant_id = $tenant_id");
        $stmt->bind_param("ss", $normalized_types, $normalized_durations);
        $stmt->execute();
        $settings['available_session_types'] = $normalized_types;
        $settings['available_session_durations'] = $normalized_durations;
    }

    $services = [];
    foreach ($sector_services as $service) {
        $services[$service['key']] = [
            'name' => $service['name'],
            'active' => in_array($service['key'], $active_service_types, true) ? 1 : 0,
            'sort' => (int) ($service['sort'] ?? 10)
        ];
    }

    foreach ($services as $key => $service) {
        $stmt = $mysqli->prepare("
            INSERT INTO appointment_services (tenant_id, service_key, name, is_active, sort_order)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE service_key = service_key
        ");
        $stmt->bind_param("issii", $tenant_id, $key, $service['name'], $service['active'], $service['sort']);
        $stmt->execute();
    }

    $service_ids = [];
    $escaped_keys = array_map(fn($key) => "'" . $mysqli->real_escape_string($key) . "'", array_keys($services));
    $mysqli->query("UPDATE appointment_services SET is_active = 0 WHERE tenant_id = $tenant_id AND service_key NOT IN (" . implode(',', $escaped_keys) . ")");
    $res = $mysqli->query("SELECT id, service_key FROM appointment_services WHERE tenant_id = $tenant_id AND service_key IN (" . implode(',', $escaped_keys) . ")");
    while ($row = $res->fetch_assoc()) {
        $service_ids[$row['service_key']] = (int) $row['id'];
    }

    $mode = $settings['appointment_delivery_mode'] ?? 'both';
    $modalities = ['presencial', 'online'];

    foreach ($service_ids as $key => $service_id) {
        foreach ($allowed_durations as $duration) {
            foreach ($modalities as $consultation_type) {
                $base_price = appointment_default_option_price($settings, $key, $consultation_type, $duration);
                $is_active = in_array($duration, $active_durations, true) && in_array($key, $active_service_types, true) ? 1 : 0;
                if ($mode === 'presencial' && $consultation_type === 'online') {
                    $is_active = 0;
                }
                if ($mode === 'online' && $consultation_type === 'presencial') {
                    $is_active = 0;
                }
                $sort = ($duration * 10) + ($consultation_type === 'online' ? 1 : 0);
                $stmt = $mysqli->prepare("
                    INSERT INTO appointment_service_options (tenant_id, service_id, duration_minutes, consultation_type, price, is_active, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE service_id = service_id
                ");
                $stmt->bind_param("iiisdii", $tenant_id, $service_id, $duration, $consultation_type, $base_price, $is_active, $sort);
                $stmt->execute();
            }
        }
    }
}

function appointment_default_option_price($settings, $service_key, $consultation_type, $duration)
{
    $is_multi_person = in_array($service_key, ['couple', 'family', 'group'], true);
    $base = appointment_price_for_type($settings, $consultation_type, $is_multi_person ? 'couple' : 'individual');
    if ((int) $duration === 90) {
        return $is_multi_person ? max($base, 120.00) : max($base, 90.00);
    }
    if ((int) $duration === 120) {
        return $is_multi_person ? max($base, 150.00) : max($base, 120.00);
    }
    return $base;
}

function fetch_appointment_services($mysqli, $only_active_options = false)
{
    ensure_appointment_services_tables($mysqli);
    $tenant_id = current_tenant_id();
    $services = [];

    $res = $mysqli->query("
        SELECT id, service_key, name, is_active, sort_order
        FROM appointment_services
        WHERE tenant_id = $tenant_id
        ORDER BY sort_order ASC, id ASC
    ");
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (int) $row['is_active'];
        $row['options'] = [];
        $services[$row['id']] = $row;
    }

    $where = $only_active_options ? "AND o.is_active = 1 AND s.is_active = 1" : "";
    $res = $mysqli->query("
        SELECT o.id, o.service_id, o.duration_minutes, o.consultation_type, o.price, o.is_active, o.sort_order
        FROM appointment_service_options o
        JOIN appointment_services s ON s.id = o.service_id AND s.tenant_id = o.tenant_id
        WHERE o.tenant_id = $tenant_id
        $where
        ORDER BY s.sort_order ASC, o.sort_order ASC, o.id ASC
    ");
    while ($row = $res->fetch_assoc()) {
        $service_id = (int) $row['service_id'];
        if (!isset($services[$service_id])) {
            continue;
        }
        $row['id'] = (int) $row['id'];
        $row['service_id'] = $service_id;
        $row['duration_minutes'] = (int) $row['duration_minutes'];
        $row['price'] = number_format((float) $row['price'], 2, '.', '');
        $row['is_active'] = (int) $row['is_active'];
        $services[$service_id]['options'][] = $row;
    }

    return array_values($services);
}

function fetch_service_option($mysqli, $option_id)
{
    ensure_appointment_services_tables($mysqli);
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("
        SELECT o.id, o.service_id, o.duration_minutes, o.consultation_type, o.price, o.is_active,
               s.service_key, s.name AS service_name, s.is_active AS service_active
        FROM appointment_service_options o
        JOIN appointment_services s ON s.id = o.service_id AND s.tenant_id = o.tenant_id
        WHERE o.tenant_id = ? AND o.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $option_id);
    $stmt->execute();
    $option = $stmt->get_result()->fetch_assoc();
    if (!$option) {
        return null;
    }
    $option['id'] = (int) $option['id'];
    $option['duration_minutes'] = (int) $option['duration_minutes'];
    $option['price'] = (float) $option['price'];
    $option['is_active'] = (int) $option['is_active'];
    $option['service_active'] = (int) $option['service_active'];
    return $option;
}

function appointment_service_option_label($appointment)
{
    $service = trim($appointment['service_name'] ?? '');
    if ($service === '') {
        $service = appointment_service_label($appointment['service_type'] ?? 'individual');
    }
    $duration = (int) ($appointment['duration_minutes'] ?? 60);
    return $service . ' (' . $duration . ' min)';
}

function appointment_price_for_row($settings, $appointment)
{
    if (isset($appointment['service_price']) && $appointment['service_price'] !== null) {
        return (float) $appointment['service_price'];
    }
    return appointment_price_for_type($settings, $appointment['consultation_type'] ?? 'presencial', $appointment['service_type'] ?? 'individual');
}

function app_public_base_url()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? null) == 443) ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $path = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if (basename($path) === 'api') {
        $path = rtrim(dirname($path), '/');
    }

    return $protocol . $host . ($path ? $path . '/' : '/');
}

function ensure_payment_attempts_table($mysqli)
{
    $tenant_id = current_tenant_id();
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS payment_attempts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            appointment_id INT UNSIGNED DEFAULT NULL,
            user_id INT UNSIGNED NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            redsys_order VARCHAR(12) NOT NULL,
            amount_cents INT UNSIGNED NOT NULL,
            payment_method ENUM('card', 'bizum') NOT NULL DEFAULT 'card',
            purchase_type ENUM('appointment', 'bonus') NOT NULL DEFAULT 'appointment',
            bonus_id INT UNSIGNED DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'Iniciado',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_payment_attempts_appointment (appointment_id),
            INDEX idx_payment_attempts_user (user_id),
            INDEX idx_payment_attempts_bonus (bonus_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    payment_add_column_if_missing($mysqli, 'payment_attempts', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");

    $columns = [
        'purchase_type' => "ALTER TABLE payment_attempts ADD purchase_type ENUM('appointment', 'bonus') NOT NULL DEFAULT 'appointment' AFTER payment_method",
        'bonus_id' => "ALTER TABLE payment_attempts ADD bonus_id INT UNSIGNED DEFAULT NULL AFTER purchase_type"
    ];

    foreach ($columns as $column => $sql) {
        $res = $mysqli->query("SHOW COLUMNS FROM payment_attempts LIKE '$column'");
        if ($res && $res->num_rows === 0) {
            $mysqli->query($sql);
        }
    }

    $mysqli->query("ALTER TABLE payment_attempts MODIFY appointment_id INT UNSIGNED DEFAULT NULL");
}

function ensure_payment_settings_price_columns($mysqli)
{
    $table = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if (!$table || $table->num_rows === 0) {
        return;
    }

    $columns = [
        'appointment_price' => "ALTER TABLE payment_settings ADD appointment_price DECIMAL(10,2) NOT NULL DEFAULT 70.00 AFTER terminal",
        'online_appointment_price' => "ALTER TABLE payment_settings ADD online_appointment_price DECIMAL(10,2) NOT NULL DEFAULT 70.00 AFTER appointment_price",
        'couple_appointment_price' => "ALTER TABLE payment_settings ADD couple_appointment_price DECIMAL(10,2) NOT NULL DEFAULT 90.00 AFTER online_appointment_price",
        'online_couple_appointment_price' => "ALTER TABLE payment_settings ADD online_couple_appointment_price DECIMAL(10,2) NOT NULL DEFAULT 90.00 AFTER couple_appointment_price",
        'available_session_types' => "ALTER TABLE payment_settings ADD available_session_types VARCHAR(100) NOT NULL DEFAULT 'individual'",
        'available_session_durations' => "ALTER TABLE payment_settings ADD available_session_durations VARCHAR(50) NOT NULL DEFAULT '60'"
    ];

    foreach ($columns as $column => $sql) {
        $res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE '$column'");
        if ($res && $res->num_rows === 0) {
            $mysqli->query($sql);
        }
    }

    $mysqli->query("ALTER TABLE payment_settings ALTER appointment_price SET DEFAULT 70.00");
    $mysqli->query("ALTER TABLE payment_settings ALTER online_appointment_price SET DEFAULT 70.00");
    $mysqli->query("ALTER TABLE payment_settings ALTER couple_appointment_price SET DEFAULT 90.00");
    $mysqli->query("ALTER TABLE payment_settings ALTER online_couple_appointment_price SET DEFAULT 90.00");
    $mysqli->query("ALTER TABLE payment_settings MODIFY available_session_types VARCHAR(100) NOT NULL DEFAULT 'individual'");
    $mysqli->query("ALTER TABLE payment_settings MODIFY available_session_durations VARCHAR(50) NOT NULL DEFAULT '60'");
}

function appointment_price_for_type($settings, $consultation_type, $service_type = 'individual')
{
    $default_price = isset($settings['appointment_price']) ? (float) $settings['appointment_price'] : 70.00;
    if (in_array($service_type, ['couple', 'family', 'group'], true)) {
        if ($consultation_type === 'online') {
            return isset($settings['online_couple_appointment_price']) ? (float) $settings['online_couple_appointment_price'] : (isset($settings['couple_appointment_price']) ? (float) $settings['couple_appointment_price'] : 90.00);
        }

        return isset($settings['couple_appointment_price']) ? (float) $settings['couple_appointment_price'] : 90.00;
    }

    if ($consultation_type === 'online') {
        return isset($settings['online_appointment_price']) ? (float) $settings['online_appointment_price'] : $default_price;
    }

    return $default_price;
}

function appointment_service_label($service_type)
{
    $labels = [
        'individual' => 'Individual',
        'couple' => 'Pareja',
        'family' => 'Familiar',
        'group' => 'Grupo'
    ];
    return $labels[$service_type] ?? ucfirst((string) $service_type);
}

function format_appointment_price($price)
{
    return number_format((float) $price, 2, ',', '.');
}

function format_payment_amount($amount_cents)
{
    return number_format(((int) $amount_cents) / 100, 2, ',', '.');
}
