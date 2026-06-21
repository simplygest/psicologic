<?php

function app_root_dir()
{
    return __DIR__;
}

function app_public_uploads_base_relative()
{
    return 'uploads';
}

function app_global_uploads_relative_base()
{
    return app_public_uploads_base_relative() . '/global';
}

function app_global_uploads_dir()
{
    return app_root_dir() . '/' . app_global_uploads_relative_base();
}

function app_global_upload_dir($category)
{
    $category = trim(str_replace('\\', '/', (string) $category), '/');
    $category = preg_replace('/[^a-zA-Z0-9_\/-]+/', '-', $category);
    return app_global_uploads_dir() . ($category !== '' ? '/' . $category : '');
}

function app_prefer_global_upload_dir($category, $fallback_dir)
{
    $global_dir = app_global_upload_dir($category);
    return is_dir($global_dir) ? $global_dir : $fallback_dir;
}

function app_current_tenant_key()
{
    $configured = '';
    if (defined('CURRENT_TENANT_KEY')) {
        $configured = CURRENT_TENANT_KEY;
    }

    if (function_exists('psicologic_config_value')) {
        $configured = $configured !== '' ? $configured : psicologic_config_value('tenant_key', '');
    }

    if ($configured === '') {
        $configured = $_SERVER['APP_TENANT_KEY'] ?? '';
    }

    if ($configured === '' && PHP_SAPI !== 'cli') {
        $script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $parts = array_values(array_filter(explode('/', trim($script_name, '/'))));
        $configured = $parts[0] ?? basename(app_root_dir());
    }

    if ($configured === '') {
        $configured = basename(app_root_dir());
    }

    $configured = strtolower(trim((string) $configured));
    $configured = preg_replace('/[^a-z0-9_-]+/', '-', $configured);
    $configured = trim($configured, '-_');

    return $configured !== '' ? $configured : 'default';
}

function app_current_tenant_storage_key()
{
    if (defined('CURRENT_TENANT_ID')) {
        return (string) max(1, (int) CURRENT_TENANT_ID);
    }

    return app_current_tenant_key();
}

function app_tenant_public_uploads_relative_base()
{
    return app_public_uploads_base_relative() . '/' . app_current_tenant_storage_key();
}

function app_tenant_public_uploads_dir()
{
    return app_root_dir() . '/' . app_tenant_public_uploads_relative_base();
}

function app_tenant_public_upload_dir($category)
{
    $category = trim(str_replace('\\', '/', (string) $category), '/');
    $category = preg_replace('/[^a-zA-Z0-9_\/-]+/', '-', $category);
    return app_tenant_public_uploads_dir() . ($category !== '' ? '/' . $category : '');
}

function app_tenant_public_upload_relative_path($category, $filename)
{
    $category = trim(str_replace('\\', '/', (string) $category), '/');
    $filename = basename((string) $filename);
    return app_tenant_public_uploads_relative_base() . ($category !== '' ? '/' . $category : '') . '/' . $filename;
}

function app_upload_asset_url($relative_path)
{
    $relative_path = trim((string) $relative_path);
    if ($relative_path === '' || preg_match('#^(?:https?:)?//#i', $relative_path) || stripos($relative_path, 'data:') === 0) {
        return $relative_path;
    }

    $clean_path = ltrim(str_replace('\\', '/', $relative_path), '/');
    if ($clean_path === '' || strpos($clean_path, '..') !== false || strpos($clean_path, app_public_uploads_base_relative() . '/') !== 0) {
        return $relative_path;
    }

    $version = '';
    $local_path = app_public_path($clean_path);
    if ($local_path !== '' && is_file($local_path)) {
        $version = '&v=' . filemtime($local_path);
    }

    return 'asset.php?p=' . rawurlencode($clean_path) . $version;
}

function app_official_brand_logo_relative_path()
{
    return app_global_uploads_relative_base() . '/sgpraxis-logo transparente.png';
}

function app_official_brand_logo_url($prefix = '')
{
    $relative_path = app_official_brand_logo_relative_path();
    $version = '';
    $local_path = app_public_path($relative_path);
    if ($local_path !== '' && is_file($local_path)) {
        $version = '&v=' . filemtime($local_path);
    }
    return $prefix . 'asset.php?p=' . rawurlencode($relative_path) . $version;
}

function app_legal_footer_text()
{
    return 'SimplyGest Praxis - ' . date('Y');
}

function app_protected_uploads_root()
{
    $configured = function_exists('psicologic_config_value') ? psicologic_config_value('protected_uploads_root', '') : '';
    if (is_string($configured) && trim($configured) !== '') {
        return rtrim(str_replace('\\', '/', trim($configured)), '/');
    }

    return dirname(app_root_dir()) . '/_protected/uploads';
}

function app_tenant_protected_uploads_dir()
{
    return app_tenant_public_uploads_dir();
}

function app_tenant_protected_upload_dir($category)
{
    return app_tenant_public_upload_dir($category);
}

function app_tenant_protected_upload_relative_path($category, $filename)
{
    return app_tenant_public_upload_relative_path($category, $filename);
}

function app_tenant_config_dir()
{
    return app_tenant_public_upload_dir('config');
}

function app_tenant_config_file($filename)
{
    return app_tenant_config_dir() . '/' . basename((string) $filename);
}

function app_ensure_dir($dir)
{
    return is_dir($dir) || mkdir($dir, 0755, true);
}

function app_public_path($relative_path)
{
    $relative_path = ltrim(str_replace('\\', '/', (string) $relative_path), '/');
    if ($relative_path === '' || strpos($relative_path, '..') !== false) {
        return '';
    }
    return app_root_dir() . '/' . $relative_path;
}

function app_protected_path_from_relative($relative_path)
{
    $relative_path = ltrim(str_replace('\\', '/', (string) $relative_path), '/');
    if ($relative_path === '' || strpos($relative_path, '..') !== false) {
        return '';
    }

    $prefix = '_protected/uploads/';
    if (strpos($relative_path, $prefix) === 0) {
        $suffix = substr($relative_path, strlen($prefix));
        return app_protected_uploads_root() . '/' . $suffix;
    }

    $legacy_prefix = '_protected/uploads/tenants/';
    if (strpos($relative_path, $legacy_prefix) === 0) {
        $suffix = substr($relative_path, strlen($legacy_prefix));
        return app_protected_uploads_root() . '/' . $suffix;
    }

    if (strpos($relative_path, '_protected/') === 0) {
        return dirname(app_root_dir()) . '/' . $relative_path;
    }

    return app_public_path($relative_path);
}
