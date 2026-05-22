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
        'show_profile_image_public' => "ALTER TABLE payment_settings ADD show_profile_image_public TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_image_path"
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
        'show_profile_image_public' => 0
    ];

    $res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if (!$res || $res->num_rows === 0) {
        return $settings;
    }

    ensure_branding_columns($mysqli);

    $res = $mysqli->query("
        SELECT app_name, profile_image_path, show_profile_image_public
        FROM payment_settings
        WHERE id = 1
    ");

    if ($row = $res->fetch_assoc()) {
        $settings['app_name'] = trim($row['app_name'] ?? '') ?: 'PsicoLogic';
        $settings['profile_image_path'] = $row['profile_image_path'] ?? '';
        $settings['show_profile_image_public'] = (int) ($row['show_profile_image_public'] ?? 0);
    }

    return $settings;
}
