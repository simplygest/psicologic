<?php

function livekit_recording_storage_configured()
{
    return function_exists('psicologic_config_value')
        && (string) psicologic_config_value('livekit_recording_storage', '') !== '';
}

function livekit_recording_plan_enabled($mysqli)
{
    if (!function_exists('dashboard_config_plan_key_from_db') || !function_exists('plan_config_for_key') || !function_exists('plan_config_feature_enabled')) {
        return false;
    }
    $plan_config = plan_config_for_key(dashboard_config_plan_key_from_db($mysqli));
    return plan_config_feature_enabled($plan_config, 'videoCalls.recordingAudio', plan_config_feature_enabled($plan_config, 'livekit.recording', false));
}

function video_recording_video_plan_enabled($mysqli)
{
    if (!function_exists('dashboard_config_plan_key_from_db') || !function_exists('plan_config_for_key') || !function_exists('plan_config_feature_enabled')) return false;
    $config = plan_config_for_key(dashboard_config_plan_key_from_db($mysqli));
    return plan_config_feature_enabled($config, 'videoCalls.recordingVideo', false);
}

function livekit_recording_mode_label($mode)
{
    return $mode === 'audio_video' ? 'Audio y vídeo' : 'Solo audio';
}

function ensure_livekit_recording_schema($mysqli, $force = false)
{
    if (!$force && function_exists('app_auto_schema_migrations_enabled') && !app_auto_schema_migrations_enabled()) {
        return;
    }

    $tenant_id = function_exists('current_tenant_id') ? max(1, (int) current_tenant_id()) : 1;
    if (function_exists('ensure_cabinet_schema')) {
        ensure_cabinet_schema($mysqli);
    }
    if (function_exists('cabinet_add_column_if_missing')) {
        cabinet_add_column_if_missing($mysqli, 'professional_settings', 'livekit_recording_enabled', "TINYINT(1) NOT NULL DEFAULT 0 AFTER livekit_enabled");
        cabinet_add_column_if_missing($mysqli, 'professional_settings', 'livekit_recording_mode', "VARCHAR(20) NOT NULL DEFAULT 'audio' AFTER livekit_recording_enabled");
    }

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS appointment_recordings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED NOT NULL DEFAULT {$tenant_id},
            appointment_id INT UNSIGNED NOT NULL,
            patient_id INT UNSIGNED NOT NULL,
            professional_id INT UNSIGNED DEFAULT NULL,
            provider VARCHAR(20) NOT NULL DEFAULT 'livekit',
            document_id INT UNSIGNED DEFAULT NULL,
            room_name VARCHAR(180) DEFAULT NULL,
            egress_id VARCHAR(160) DEFAULT NULL,
            recording_mode VARCHAR(20) NOT NULL DEFAULT 'audio',
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            visible_to_patient TINYINT(1) NOT NULL DEFAULT 0,
            started_at DATETIME DEFAULT NULL,
            stopped_at DATETIME DEFAULT NULL,
            created_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_appointment_recordings_provider_id (provider, egress_id),
            KEY idx_appointment_recordings_appointment (tenant_id, appointment_id),
            KEY idx_appointment_recordings_patient (tenant_id, patient_id),
            KEY idx_appointment_recordings_document (tenant_id, document_id),
            KEY idx_appointment_recordings_status (tenant_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (function_exists('cabinet_add_column_if_missing')) {
        cabinet_add_column_if_missing($mysqli, 'appointment_recordings', 'provider', "VARCHAR(20) NOT NULL DEFAULT 'livekit' AFTER professional_id");
    }
}

function livekit_recording_enabled_for_professional($mysqli, $professional_id)
{
    if (!livekit_recording_plan_enabled($mysqli) || !function_exists('cabinet_get_effective_professional_settings')) {
        return false;
    }
    $settings = cabinet_get_effective_professional_settings($mysqli, (int) $professional_id);
    return (int) ($settings['livekit_recording_enabled'] ?? 0) === 1;
}
