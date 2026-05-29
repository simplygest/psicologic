<?php

function ensure_branding_columns($mysqli)
{
    $res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if (!$res || $res->num_rows === 0) {
        return;
    }

    $columns = [
        'app_name' => "ALTER TABLE payment_settings ADD app_name VARCHAR(255) DEFAULT 'PsicoLogic' AFTER id",
        'profile_image_path' => "ALTER TABLE payment_settings ADD profile_image_path VARCHAR(255) DEFAULT NULL AFTER app_name",
        'landing_image_path' => "ALTER TABLE payment_settings ADD landing_image_path VARCHAR(255) DEFAULT NULL AFTER profile_image_path",
        'primary_color' => "ALTER TABLE payment_settings ADD primary_color VARCHAR(7) NOT NULL DEFAULT '#8f7fba' AFTER landing_image_path",
        'show_profile_image_public' => "ALTER TABLE payment_settings ADD show_profile_image_public TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_image_path",
        'show_prices_public' => "ALTER TABLE payment_settings ADD show_prices_public TINYINT(1) NOT NULL DEFAULT 0 AFTER show_profile_image_public"
    ];

    foreach ($columns as $column => $sql) {
        $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE '$column'");
        if ($column_res && $column_res->num_rows === 0) {
            $mysqli->query($sql);
        }
    }
}

function get_public_branding_settings($mysqli)
{
    $settings = [
        'app_name' => 'PsicoLogic',
        'profile_image_path' => '',
        'landing_image_path' => '',
        'primary_color' => '#8f7fba',
        'show_profile_image_public' => 0,
        'show_prices_public' => 0
    ];

    $res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if (!$res || $res->num_rows === 0) {
        return $settings;
    }

    ensure_branding_columns($mysqli);

    $res = $mysqli->query("
        SELECT app_name, profile_image_path, landing_image_path, primary_color, show_profile_image_public, show_prices_public
        FROM payment_settings
        WHERE id = 1
    ");

    if ($row = $res->fetch_assoc()) {
        $settings['app_name'] = trim($row['app_name'] ?? '') ?: 'PsicoLogic';
        $settings['profile_image_path'] = $row['profile_image_path'] ?? '';
        $settings['landing_image_path'] = $row['landing_image_path'] ?? '';
        $settings['primary_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $row['primary_color'] ?? '') ? strtolower($row['primary_color']) : '#8f7fba';
        $settings['show_profile_image_public'] = (int) ($row['show_profile_image_public'] ?? 0);
        $settings['show_prices_public'] = (int) ($row['show_prices_public'] ?? 0);
    }

    return $settings;
}
