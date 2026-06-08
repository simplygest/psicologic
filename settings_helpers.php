<?php

function ensure_branding_columns($mysqli)
{
    $res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if (!$res || $res->num_rows === 0) {
        return;
    }

    $columns = [
        'app_name' => "ALTER TABLE payment_settings ADD app_name VARCHAR(255) DEFAULT 'PsicoLogic' AFTER id",
        'site_tagline' => "ALTER TABLE payment_settings ADD site_tagline VARCHAR(255) DEFAULT NULL AFTER app_name",
        'site_phone' => "ALTER TABLE payment_settings ADD site_phone VARCHAR(40) DEFAULT NULL AFTER site_tagline",
        'profile_image_path' => "ALTER TABLE payment_settings ADD profile_image_path VARCHAR(255) DEFAULT NULL AFTER app_name",
        'landing_image_path' => "ALTER TABLE payment_settings ADD landing_image_path VARCHAR(255) DEFAULT NULL AFTER profile_image_path",
        'primary_color' => "ALTER TABLE payment_settings ADD primary_color VARCHAR(7) NOT NULL DEFAULT '#8f7fba' AFTER landing_image_path",
        'show_profile_image_public' => "ALTER TABLE payment_settings ADD show_profile_image_public TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_image_path",
        'show_prices_public' => "ALTER TABLE payment_settings ADD show_prices_public TINYINT(1) NOT NULL DEFAULT 0 AFTER show_profile_image_public",
        'show_contact_public' => "ALTER TABLE payment_settings ADD show_contact_public TINYINT(1) NOT NULL DEFAULT 0 AFTER show_prices_public",
        'online_booking_enabled' => "ALTER TABLE payment_settings ADD online_booking_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER show_contact_public",
        'initial_calendar_view' => "ALTER TABLE payment_settings ADD initial_calendar_view VARCHAR(12) NOT NULL DEFAULT 'month' AFTER online_booking_enabled"
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
        'site_tagline' => 'Psicología sanitaria y neuropsicología en Santa Cruz de Tenerife',
        'site_phone' => '',
        'profile_image_path' => '',
        'landing_image_path' => '',
        'primary_color' => '#8f7fba',
        'show_profile_image_public' => 0,
        'show_prices_public' => 0,
        'show_contact_public' => 0,
        'online_booking_enabled' => 1,
        'initial_calendar_view' => 'month'
    ];

    $res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if (!$res || $res->num_rows === 0) {
        return $settings;
    }

    ensure_branding_columns($mysqli);

    $res = $mysqli->query("
        SELECT app_name, site_tagline, site_phone, profile_image_path, landing_image_path, primary_color, show_profile_image_public, show_prices_public, show_contact_public, online_booking_enabled, initial_calendar_view
        FROM payment_settings
        WHERE id = 1
    ");

    if ($row = $res->fetch_assoc()) {
        $settings['app_name'] = trim($row['app_name'] ?? '') ?: 'PsicoLogic';
        $settings['site_tagline'] = trim($row['site_tagline'] ?? '') ?: 'Psicología sanitaria y neuropsicología en Santa Cruz de Tenerife';
        $settings['site_phone'] = trim($row['site_phone'] ?? '');
        $settings['profile_image_path'] = $row['profile_image_path'] ?? '';
        $settings['landing_image_path'] = $row['landing_image_path'] ?? '';
        $settings['primary_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $row['primary_color'] ?? '') ? strtolower($row['primary_color']) : '#8f7fba';
        $settings['show_profile_image_public'] = (int) ($row['show_profile_image_public'] ?? 0);
        $settings['show_prices_public'] = (int) ($row['show_prices_public'] ?? 0);
        $settings['show_contact_public'] = (int) ($row['show_contact_public'] ?? 0);
        $settings['online_booking_enabled'] = (int) ($row['online_booking_enabled'] ?? 1);
        $settings['initial_calendar_view'] = in_array(($row['initial_calendar_view'] ?? ''), ['week', 'month'], true) ? $row['initial_calendar_view'] : 'month';
    }

    return $settings;
}

function online_booking_enabled($mysqli)
{
    $settings = get_public_branding_settings($mysqli);
    return (int) ($settings['online_booking_enabled'] ?? 1) === 1;
}
