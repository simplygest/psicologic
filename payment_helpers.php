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
        'reminder_sent_at' => "ALTER TABLE appointments ADD reminder_sent_at DATETIME DEFAULT NULL"
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

function format_payment_amount($amount_cents)
{
    return number_format(((int) $amount_cents) / 100, 2, ',', '.');
}
