<?php

function ensure_appointment_payment_columns($mysqli)
{
    $columns = [
        'payment_status' => "ALTER TABLE appointments ADD payment_status VARCHAR(32) NOT NULL DEFAULT 'pending'",
        'payment_method' => "ALTER TABLE appointments ADD payment_method VARCHAR(16) DEFAULT NULL",
        'paid_at' => "ALTER TABLE appointments ADD paid_at DATETIME DEFAULT NULL",
        'payment_attempt_id' => "ALTER TABLE appointments ADD payment_attempt_id INT UNSIGNED DEFAULT NULL",
        'google_calendar_event_id' => "ALTER TABLE appointments ADD google_calendar_event_id VARCHAR(255) DEFAULT NULL",
        'cancel_token' => "ALTER TABLE appointments ADD cancel_token VARCHAR(64) DEFAULT NULL",
        'reminder_sent_at' => "ALTER TABLE appointments ADD reminder_sent_at DATETIME DEFAULT NULL",
        'consultation_type' => "ALTER TABLE appointments ADD consultation_type VARCHAR(16) NOT NULL DEFAULT 'presencial'",
        'service_type' => "ALTER TABLE appointments ADD service_type VARCHAR(16) NOT NULL DEFAULT 'individual'"
    ];

    foreach ($columns as $column => $sql) {
        $res = $mysqli->query("SHOW COLUMNS FROM appointments LIKE '$column'");
        if ($res->num_rows === 0) {
            $mysqli->query($sql);
        }
    }
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
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS payment_attempts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            appointment_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            redsys_order VARCHAR(12) NOT NULL,
            amount_cents INT UNSIGNED NOT NULL,
            payment_method ENUM('card', 'bizum') NOT NULL DEFAULT 'card',
            status VARCHAR(32) NOT NULL DEFAULT 'Iniciado',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_payment_attempts_appointment (appointment_id),
            INDEX idx_payment_attempts_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
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
        'online_couple_appointment_price' => "ALTER TABLE payment_settings ADD online_couple_appointment_price DECIMAL(10,2) NOT NULL DEFAULT 90.00 AFTER couple_appointment_price"
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
}

function appointment_price_for_type($settings, $consultation_type, $service_type = 'individual')
{
    $default_price = isset($settings['appointment_price']) ? (float) $settings['appointment_price'] : 70.00;
    if ($service_type === 'couple') {
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
    return $service_type === 'couple' ? 'Pareja' : 'Individual';
}

function format_appointment_price($price)
{
    return number_format((float) $price, 2, ',', '.');
}

function format_payment_amount($amount_cents)
{
    return number_format(((int) $amount_cents) / 100, 2, ',', '.');
}
