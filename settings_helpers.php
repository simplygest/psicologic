<?php
require_once __DIR__ . '/sector_text_helpers.php';

function ensure_branding_columns($mysqli)
{
    $res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if (!$res || $res->num_rows === 0) {
        return;
    }

    $columns = [
        'app_name' => "ALTER TABLE payment_settings ADD app_name VARCHAR(255) DEFAULT 'SimplyGest Praxis' AFTER id",
        'site_tagline' => "ALTER TABLE payment_settings ADD site_tagline VARCHAR(255) DEFAULT NULL AFTER app_name",
        'site_phone' => "ALTER TABLE payment_settings ADD site_phone VARCHAR(40) DEFAULT NULL AFTER site_tagline",
        'profile_image_path' => "ALTER TABLE payment_settings ADD profile_image_path VARCHAR(255) DEFAULT NULL AFTER app_name",
        'landing_image_path' => "ALTER TABLE payment_settings ADD landing_image_path VARCHAR(255) DEFAULT NULL AFTER profile_image_path",
        'favicon_path' => "ALTER TABLE payment_settings ADD favicon_path VARCHAR(255) DEFAULT NULL AFTER profile_image_path",
        'primary_color' => "ALTER TABLE payment_settings ADD primary_color VARCHAR(7) NOT NULL DEFAULT '#4285f4' AFTER landing_image_path",
        'show_profile_image_public' => "ALTER TABLE payment_settings ADD show_profile_image_public TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_image_path",
        'public_site_enabled' => "ALTER TABLE payment_settings ADD public_site_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER show_profile_image_public",
        'show_prices_public' => "ALTER TABLE payment_settings ADD show_prices_public TINYINT(1) NOT NULL DEFAULT 0 AFTER show_profile_image_public",
        'show_contact_public' => "ALTER TABLE payment_settings ADD show_contact_public TINYINT(1) NOT NULL DEFAULT 0 AFTER show_prices_public",
        'dashboard_config_mode' => "ALTER TABLE payment_settings ADD dashboard_config_mode VARCHAR(16) NOT NULL DEFAULT 'simple'",
        'sector_texts_key' => "ALTER TABLE payment_settings ADD sector_texts_key VARCHAR(32) NOT NULL DEFAULT 'psicologia' AFTER dashboard_config_mode",
        'online_booking_enabled' => "ALTER TABLE payment_settings ADD online_booking_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER show_contact_public",
        'patient_registration_mode' => "ALTER TABLE payment_settings ADD patient_registration_mode VARCHAR(16) NOT NULL DEFAULT 'invite' AFTER online_booking_enabled",
        'initial_calendar_view' => "ALTER TABLE payment_settings ADD initial_calendar_view VARCHAR(12) NOT NULL DEFAULT 'month' AFTER online_booking_enabled",
        'legal_owner_name' => "ALTER TABLE payment_settings ADD legal_owner_name VARCHAR(255) DEFAULT NULL",
        'legal_nif' => "ALTER TABLE payment_settings ADD legal_nif VARCHAR(50) DEFAULT NULL",
        'legal_address' => "ALTER TABLE payment_settings ADD legal_address VARCHAR(500) DEFAULT NULL",
        'legal_email' => "ALTER TABLE payment_settings ADD legal_email VARCHAR(255) DEFAULT NULL",
        'legal_license_number' => "ALTER TABLE payment_settings ADD legal_license_number VARCHAR(100) DEFAULT NULL",
        'legal_professional_college' => "ALTER TABLE payment_settings ADD legal_professional_college VARCHAR(255) DEFAULT NULL",
        'legal_uses_non_technical_cookies' => "ALTER TABLE payment_settings ADD legal_uses_non_technical_cookies TINYINT NOT NULL DEFAULT 0",
        'legal_terms_notes' => "ALTER TABLE payment_settings ADD legal_terms_notes TEXT DEFAULT NULL"
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
        'app_name' => 'SimplyGest Praxis',
        'site_tagline' => 'Psicología sanitaria y neuropsicología en Santa Cruz de Tenerife',
        'site_phone' => '',
        'profile_image_path' => '',
        'landing_image_path' => '',
        'favicon_path' => '',
        'primary_color' => '#4285f4',
        'show_profile_image_public' => 0,
        'public_site_enabled' => 0,
        'show_prices_public' => 0,
        'show_contact_public' => 0,
        'online_booking_enabled' => 1,
        'patient_registration_mode' => 'invite',
        'initial_calendar_view' => 'month',
        'sector_texts_key' => 'psicologia',
        'legal_owner_name' => '',
        'legal_nif' => '',
        'legal_address' => '',
        'legal_email' => '',
        'legal_license_number' => '',
        'legal_professional_college' => '',
        'legal_uses_non_technical_cookies' => 0,
        'legal_terms_notes' => ''
    ];

    $res = $mysqli->query("SHOW TABLES LIKE 'payment_settings'");
    if (!$res || $res->num_rows === 0) {
        return $settings;
    }

    ensure_branding_columns($mysqli);

    $res = $mysqli->query("
        SELECT app_name, site_tagline, site_phone, profile_image_path, landing_image_path, favicon_path, primary_color, show_profile_image_public, public_site_enabled, show_prices_public, show_contact_public, online_booking_enabled, patient_registration_mode, initial_calendar_view, sector_texts_key,
               legal_owner_name, legal_nif, legal_address, legal_email, legal_license_number, legal_professional_college, legal_uses_non_technical_cookies, legal_terms_notes
        FROM payment_settings
        WHERE id = 1
    ");

    if ($row = $res->fetch_assoc()) {
        $settings['app_name'] = trim($row['app_name'] ?? '') ?: 'SimplyGest Praxis';
        $settings['site_tagline'] = trim($row['site_tagline'] ?? '') ?: 'Psicología sanitaria y neuropsicología en Santa Cruz de Tenerife';
        $settings['site_phone'] = trim($row['site_phone'] ?? '');
        $settings['profile_image_path'] = $row['profile_image_path'] ?? '';
        $settings['landing_image_path'] = $row['landing_image_path'] ?? '';
        $settings['favicon_path'] = $row['favicon_path'] ?? '';
        $settings['primary_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $row['primary_color'] ?? '') ? strtolower($row['primary_color']) : '#4285f4';
        $settings['show_profile_image_public'] = (int) ($row['show_profile_image_public'] ?? 0);
        $settings['public_site_enabled'] = (int) ($row['public_site_enabled'] ?? 0);
        $settings['show_prices_public'] = (int) ($row['show_prices_public'] ?? 0);
        $settings['show_contact_public'] = (int) ($row['show_contact_public'] ?? 0);
        $settings['online_booking_enabled'] = (int) ($row['online_booking_enabled'] ?? 1);
        $settings['patient_registration_mode'] = in_array(($row['patient_registration_mode'] ?? ''), ['invite', 'open'], true) ? $row['patient_registration_mode'] : 'invite';
        $settings['initial_calendar_view'] = in_array(($row['initial_calendar_view'] ?? ''), ['week', 'month'], true) ? $row['initial_calendar_view'] : 'month';
        $settings['sector_texts_key'] = sector_texts_validate_key($row['sector_texts_key'] ?? '') ? $row['sector_texts_key'] : sector_texts_default_key();
        $settings['legal_owner_name'] = trim($row['legal_owner_name'] ?? '');
        $settings['legal_nif'] = trim($row['legal_nif'] ?? '');
        $settings['legal_address'] = trim($row['legal_address'] ?? '');
        $settings['legal_email'] = trim($row['legal_email'] ?? '');
        $settings['legal_license_number'] = trim($row['legal_license_number'] ?? '');
        $settings['legal_professional_college'] = trim($row['legal_professional_college'] ?? '');
        $settings['legal_uses_non_technical_cookies'] = (int) ($row['legal_uses_non_technical_cookies'] ?? 0);
        $settings['legal_terms_notes'] = trim($row['legal_terms_notes'] ?? '');
    }

    ensure_branding_favicon($mysqli, $settings);

    return $settings;
}

function online_booking_enabled($mysqli)
{
    $settings = get_public_branding_settings($mysqli);
    return (int) ($settings['online_booking_enabled'] ?? 1) === 1;
}

function patient_registration_is_open($mysqli)
{
    $settings = get_public_branding_settings($mysqli);
    return ($settings['patient_registration_mode'] ?? 'invite') === 'open';
}

function public_asset_local_path($path)
{
    $path = trim((string) $path);
    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return '';
    }

    $path = ltrim(str_replace('\\', '/', $path), '/');
    if ($path === '' || strpos($path, '..') !== false) {
        return '';
    }

    return __DIR__ . '/' . $path;
}

function public_asset_exists($path)
{
    $local_path = public_asset_local_path($path);
    return $local_path !== '' && is_file($local_path);
}

function create_image_resource_from_file($path, $mime)
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }

    if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        return @imagecreatefromjpeg($path);
    }
    if ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
        return @imagecreatefrompng($path);
    }
    if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        return @imagecreatefromwebp($path);
    }
    if ($mime === 'image/gif' && function_exists('imagecreatefromgif')) {
        return @imagecreatefromgif($path);
    }

    return null;
}

function generate_favicon_from_public_image($source_public_path, $force = false)
{
    $source_path = public_asset_local_path($source_public_path);
    if ($source_path === '' || !is_file($source_path) || !function_exists('imagepng')) {
        return '';
    }

    $favicon_public_path = 'uploads/settings/favicon.ico';
    $favicon_path = public_asset_local_path($favicon_public_path);
    if (!$force && is_file($favicon_path) && filemtime($favicon_path) >= filemtime($source_path)) {
        return $favicon_public_path;
    }

    $image_info = @getimagesize($source_path);
    if (!$image_info || empty($image_info['mime'])) {
        return '';
    }

    $source = create_image_resource_from_file($source_path, $image_info['mime']);
    if (!$source) {
        return '';
    }

    $size = 64;
    $width = (int) ($image_info[0] ?? 0);
    $height = (int) ($image_info[1] ?? 0);
    if ($width <= 0 || $height <= 0) {
        imagedestroy($source);
        return '';
    }

    $crop_size = min($width, $height);
    $src_x = (int) floor(($width - $crop_size) / 2);
    $src_y = (int) floor(($height - $crop_size) / 2);
    $icon = imagecreatetruecolor($size, $size);
    imagealphablending($icon, false);
    imagesavealpha($icon, true);
    $transparent = imagecolorallocatealpha($icon, 0, 0, 0, 127);
    imagefilledrectangle($icon, 0, 0, $size, $size, $transparent);
    imagecopyresampled($icon, $source, 0, 0, $src_x, $src_y, $size, $size, $crop_size, $crop_size);

    ob_start();
    imagepng($icon);
    $png_data = ob_get_clean();
    imagedestroy($icon);
    imagedestroy($source);

    if ($png_data === false || $png_data === '') {
        return '';
    }

    $upload_dir = dirname($favicon_path);
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        return '';
    }

    $ico_data = pack('vvv', 0, 1, 1)
        . pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($png_data), 22)
        . $png_data;

    return file_put_contents($favicon_path, $ico_data, LOCK_EX) !== false ? $favicon_public_path : '';
}

function ensure_branding_favicon($mysqli, &$settings)
{
    $profile_image_path = trim((string) ($settings['profile_image_path'] ?? ''));
    if ($profile_image_path === '') {
        return;
    }

    $favicon_path = trim((string) ($settings['favicon_path'] ?? ''));
    $favicon_exists = $favicon_path !== '' && public_asset_exists($favicon_path);
    $source_path = public_asset_local_path($profile_image_path);
    $needs_regenerate = !$favicon_exists;
    if ($favicon_exists && $source_path !== '' && is_file($source_path)) {
        $favicon_local_path = public_asset_local_path($favicon_path);
        $needs_regenerate = $favicon_local_path !== '' && filemtime($favicon_local_path) < filemtime($source_path);
    }

    if (!$needs_regenerate) {
        return;
    }

    $generated_path = generate_favicon_from_public_image($profile_image_path, true);
    if ($generated_path === '') {
        return;
    }

    $settings['favicon_path'] = $generated_path;
    $stmt = $mysqli->prepare("UPDATE payment_settings SET favicon_path = ? WHERE id = 1");
    if ($stmt) {
        $stmt->bind_param("s", $generated_path);
        $stmt->execute();
    }
}

function favicon_href_for_branding($settings)
{
    $favicon_path = trim((string) ($settings['favicon_path'] ?? ''));
    if ($favicon_path !== '' && public_asset_exists($favicon_path)) {
        return $favicon_path;
    }

    return trim((string) ($settings['profile_image_path'] ?? ''));
}

function favicon_link_tags($settings)
{
    $href = favicon_href_for_branding($settings);
    if ($href === '') {
        return '';
    }

    $local_path = public_asset_local_path($href);
    $version = $local_path !== '' && is_file($local_path) ? '?v=' . filemtime($local_path) : '';
    $extension = strtolower(pathinfo(parse_url($href, PHP_URL_PATH) ?: $href, PATHINFO_EXTENSION));
    $types = [
        'ico' => 'image/x-icon',
        'svg' => 'image/svg+xml',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif'
    ];
    $type = $types[$extension] ?? 'image/png';
    $safe_href = htmlspecialchars($href . $version, ENT_QUOTES, 'UTF-8');
    $safe_type = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');

    return '<link rel="icon" href="' . $safe_href . '" type="' . $safe_type . '">' . "\n"
        . '    <link rel="shortcut icon" href="' . $safe_href . '" type="' . $safe_type . '">';
}
