<?php
session_start();

$is_cli = PHP_SAPI === 'cli';

require_once __DIR__ . '/config.php';

$provided_token = trim((string) ($_GET['token'] ?? ($_POST['token'] ?? '')));
$expected_token = function_exists('psicologic_config_value') ? (string) psicologic_config_value('migration_token', '') : '';
if ($expected_token === '' && defined('CRON_WEBHOOK_TOKEN')) {
    $expected_token = (string) CRON_WEBHOOK_TOKEN;
}
$token_allowed = $expected_token !== '' && $provided_token !== '' && hash_equals($expected_token, $provided_token);

if (!$is_cli && (($_SESSION['role'] ?? '') !== 'superadmin') && !$token_allowed) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "No autorizado.\n";
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_helpers.php';
require_once __DIR__ . '/cabinet_helpers.php';
require_once __DIR__ . '/livekit_recording_helpers.php';
require_once __DIR__ . '/message_template_helpers.php';
require_once __DIR__ . '/app_log_helpers.php';
require_once __DIR__ . '/time_tracking_helpers.php';
require_once __DIR__ . '/questionnaire_helpers.php';

if (!$is_cli) {
    header('Content-Type: text/html; charset=UTF-8');
}

$migration_results = [];

function migration_log($status, $message)
{
    global $migration_results;
    $migration_results[] = ['status' => $status, 'message' => $message];
}

function migration_table_exists($mysqli, $table)
{
    $table = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

function migration_column_exists($mysqli, $table, $column)
{
    if (!migration_table_exists($mysqli, $table)) {
        return false;
    }
    $table = $mysqli->real_escape_string($table);
    $column = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function migration_index_exists($mysqli, $table, $index)
{
    if (!migration_table_exists($mysqli, $table)) {
        return false;
    }
    $table = $mysqli->real_escape_string($table);
    $index = $mysqli->real_escape_string($index);
    $res = $mysqli->query("SHOW INDEX FROM `$table` WHERE Key_name = '$index'");
    return $res && $res->num_rows > 0;
}

function migration_run($mysqli, $label, $sql)
{
    try {
        $mysqli->query($sql);
        migration_log('ok', $label);
    } catch (Throwable $e) {
        migration_log('error', $label . ': ' . $e->getMessage());
    }
}

function migration_add_column_if_missing($mysqli, $table, $column, $definition)
{
    if (migration_column_exists($mysqli, $table, $column)) {
        migration_log('skip', "$table.$column ya existe");
        return;
    }
    migration_run($mysqli, "ADD COLUMN $table.$column", "ALTER TABLE `$table` ADD `$column` $definition");
}

function migration_add_index_if_missing($mysqli, $table, $index, $definition)
{
    if (migration_index_exists($mysqli, $table, $index)) {
        migration_log('skip', "$table.$index ya existe");
        return;
    }
    migration_run($mysqli, "ADD INDEX $table.$index", "ALTER TABLE `$table` ADD $definition");
}

$tenant_id = current_tenant_id();

migration_add_column_if_missing($mysqli, 'tenants', 'signup_email', 'VARCHAR(190) DEFAULT NULL AFTER tenant_name');
migration_add_index_if_missing($mysqli, 'tenants', 'uq_tenants_signup_email', 'UNIQUE KEY `uq_tenants_signup_email` (`signup_email`)');
migration_add_column_if_missing($mysqli, 'tenants', 'subscription_granted', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER registered_at');
migration_add_column_if_missing($mysqli, 'tenants', 'subscription_granted_until', 'DATETIME DEFAULT NULL AFTER subscription_granted');
migration_run($mysqli, 'MODIFY users.role team member roles', "ALTER TABLE users MODIFY role ENUM('superadmin','admin','reception','administration','technical','patient') NOT NULL DEFAULT 'patient'");
migration_add_column_if_missing($mysqli, 'professional_settings', 'member_permissions_json', 'TEXT DEFAULT NULL AFTER livekit_enabled');
migration_add_column_if_missing($mysqli, 'professional_settings', 'contract_hours', 'DECIMAL(6,2) DEFAULT NULL AFTER member_permissions_json');
migration_add_column_if_missing($mysqli, 'professional_settings', 'contract_hours_unit', "VARCHAR(12) NOT NULL DEFAULT 'daily' AFTER contract_hours");
migration_add_column_if_missing($mysqli, 'work_plan_task_template_items', 'attachment_file_path', "VARCHAR(500) DEFAULT NULL AFTER fitness_exercise_id");
migration_add_column_if_missing($mysqli, 'work_plan_task_template_items', 'attachment_original_name', "VARCHAR(255) DEFAULT NULL AFTER attachment_file_path");
migration_add_column_if_missing($mysqli, 'work_plan_task_template_items', 'attachment_file_size', "INT UNSIGNED DEFAULT NULL AFTER attachment_original_name");
migration_add_column_if_missing($mysqli, 'work_plan_task_template_items', 'attachment_mime_type', "VARCHAR(120) DEFAULT NULL AFTER attachment_file_size");
migration_add_column_if_missing($mysqli, 'patient_work_plan_tasks', 'document_id', "INT UNSIGNED DEFAULT NULL AFTER fitness_exercise_id");
migration_add_index_if_missing($mysqli, 'patient_work_plan_tasks', 'idx_work_plan_document', "INDEX idx_work_plan_document (document_id)");
migration_add_column_if_missing($mysqli, 'patient_work_plan_tasks', 'patient_diagnosis_id', "INT UNSIGNED DEFAULT NULL AFTER patient_id");
migration_add_index_if_missing($mysqli, 'patient_work_plan_tasks', 'idx_work_plan_diagnosis', "INDEX idx_work_plan_diagnosis (patient_diagnosis_id)");
migration_add_column_if_missing($mysqli, 'payment_settings', 'time_tracking_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
migration_add_column_if_missing($mysqli, 'payment_settings', 'time_tracking_notify_missing_clock_in', 'TINYINT(1) NOT NULL DEFAULT 0');
migration_add_column_if_missing($mysqli, 'payment_settings', 'time_tracking_require_clock_in', 'TINYINT(1) NOT NULL DEFAULT 0');
migration_add_column_if_missing($mysqli, 'payment_settings', 'time_tracking_logout_on_clock_out', 'TINYINT(1) NOT NULL DEFAULT 0');

try {
    ensure_time_tracking_schema($mysqli);
    migration_log('ok', 'CREATE TABLE time_tracking_entries');
} catch (Throwable $e) {
    migration_log('error', 'time_tracking_entries: ' . $e->getMessage());
}

try {
    ensure_message_templates_table($mysqli);
    migration_log('ok', 'CREATE TABLE message_templates');
} catch (Throwable $e) {
    migration_log('error', 'message_templates: ' . $e->getMessage());
}

try {
    ensure_app_logs_table($mysqli);
    migration_log('ok', 'CREATE TABLE app_logs');
} catch (Throwable $e) {
    migration_log('error', 'app_logs: ' . $e->getMessage());
}

try {
    ensure_questionnaire_schema($mysqli);
    ensure_patient_questionnaire_schema($mysqli);
    migration_log('ok', 'CREATE TABLE questionnaires/questionnaire_questions/questionnaire_options');
} catch (Throwable $e) {
    migration_log('error', 'questionnaires: ' . $e->getMessage());
}

migration_run($mysqli, 'CREATE TABLE tenant_data_export_jobs', "
    CREATE TABLE IF NOT EXISTS tenant_data_export_jobs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        requested_by INT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'queued',
        progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
        current_step VARCHAR(255) DEFAULT NULL,
        password_ciphertext BLOB DEFAULT NULL,
        file_path VARCHAR(1000) DEFAULT NULL,
        file_name VARCHAR(255) DEFAULT NULL,
        file_size BIGINT UNSIGNED DEFAULT NULL,
        file_sha256 CHAR(64) DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        download_count INT UNSIGNED NOT NULL DEFAULT 0,
        downloaded_at DATETIME DEFAULT NULL,
        started_at DATETIME DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_tenant_data_exports_tenant (tenant_id, created_at),
        INDEX idx_tenant_data_exports_queue (status, created_at),
        INDEX idx_tenant_data_exports_expiry (status, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
migration_run($mysqli, 'CREATE TABLE tenant_data_export_runtime', "
    CREATE TABLE IF NOT EXISTS tenant_data_export_runtime (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        fastcron_id VARCHAR(64) DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
migration_run($mysqli, 'INIT tenant_data_export_runtime', "INSERT IGNORE INTO tenant_data_export_runtime (id) VALUES (1)");

migration_run($mysqli, 'CREATE TABLE legal_documents', "
    CREATE TABLE IF NOT EXISTS legal_documents (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        title VARCHAR(180) NOT NULL,
        category VARCHAR(80) DEFAULT NULL,
        version_label VARCHAR(80) DEFAULT NULL,
        file_path VARCHAR(500) DEFAULT NULL,
        original_file_name VARCHAR(255) DEFAULT NULL,
        file_size INT UNSIGNED DEFAULT NULL,
        mime_type VARCHAR(120) DEFAULT NULL,
        template_type VARCHAR(24) NOT NULL DEFAULT 'uploaded_pdf',
        content_json LONGTEXT DEFAULT NULL,
        source_key VARCHAR(120) DEFAULT NULL,
        template_revision INT UNSIGNED NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        is_required TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_legal_documents_tenant_active (tenant_id, is_active, is_required, title)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
migration_add_column_if_missing($mysqli, 'legal_documents', 'is_required', "TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
migration_add_column_if_missing($mysqli, 'legal_documents', 'template_type', "VARCHAR(24) NOT NULL DEFAULT 'uploaded_pdf' AFTER mime_type");
migration_add_column_if_missing($mysqli, 'legal_documents', 'content_json', "LONGTEXT DEFAULT NULL AFTER template_type");
migration_add_column_if_missing($mysqli, 'legal_documents', 'source_key', "VARCHAR(120) DEFAULT NULL AFTER content_json");
migration_add_column_if_missing($mysqli, 'legal_documents', 'template_revision', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER source_key");
migration_add_index_if_missing($mysqli, 'legal_documents', 'idx_legal_documents_tenant_active', "INDEX idx_legal_documents_tenant_active (tenant_id, is_active, is_required, title)");

migration_run($mysqli, 'CREATE TABLE service_legal_documents', "
    CREATE TABLE IF NOT EXISTS service_legal_documents (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        service_id INT UNSIGNED NOT NULL,
        legal_document_id INT UNSIGNED NOT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_service_legal_document (tenant_id, service_id, legal_document_id),
        INDEX idx_service_legal_documents_service (tenant_id, service_id),
        INDEX idx_service_legal_documents_document (tenant_id, legal_document_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

migration_run($mysqli, 'CREATE TABLE patient_legal_documents', "
    CREATE TABLE IF NOT EXISTS patient_legal_documents (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        patient_id INT UNSIGNED NOT NULL,
        legal_document_id INT UNSIGNED NOT NULL,
        accepted TINYINT(1) NOT NULL DEFAULT 0,
        accepted_at DATETIME DEFAULT NULL,
        accepted_by INT UNSIGNED DEFAULT NULL,
        acceptance_note TEXT DEFAULT NULL,
        signed_file_path VARCHAR(500) DEFAULT NULL,
        original_file_name VARCHAR(255) DEFAULT NULL,
        file_size INT UNSIGNED DEFAULT NULL,
        mime_type VARCHAR(120) DEFAULT NULL,
        signed_uploaded_by INT UNSIGNED DEFAULT NULL,
        signed_uploaded_at DATETIME DEFAULT NULL,
        signature_method VARCHAR(30) DEFAULT NULL,
        signer_name VARCHAR(180) DEFAULT NULL,
        signer_nif VARCHAR(50) DEFAULT NULL,
        source_pdf_sha256 CHAR(64) DEFAULT NULL,
        signed_pdf_sha256 CHAR(64) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_patient_legal_document (tenant_id, patient_id, legal_document_id),
        INDEX idx_patient_legal_documents_patient (tenant_id, patient_id),
        INDEX idx_patient_legal_documents_document (tenant_id, legal_document_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

migration_add_column_if_missing($mysqli, 'patient_legal_documents', 'signature_method', "VARCHAR(30) DEFAULT NULL AFTER signed_uploaded_at");
migration_add_column_if_missing($mysqli, 'patient_legal_documents', 'signer_name', "VARCHAR(180) DEFAULT NULL AFTER signature_method");
migration_add_column_if_missing($mysqli, 'patient_legal_documents', 'signer_nif', "VARCHAR(50) DEFAULT NULL AFTER signer_name");
migration_add_column_if_missing($mysqli, 'patient_legal_documents', 'source_pdf_sha256', "CHAR(64) DEFAULT NULL AFTER signer_nif");
migration_add_column_if_missing($mysqli, 'patient_legal_documents', 'signed_pdf_sha256', "CHAR(64) DEFAULT NULL AFTER source_pdf_sha256");

migration_run($mysqli, 'CREATE TABLE legal_consent_audit', "
    CREATE TABLE IF NOT EXISTS legal_consent_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        patient_id INT UNSIGNED NOT NULL,
        legal_document_id INT UNSIGNED NOT NULL,
        patient_legal_document_id INT UNSIGNED DEFAULT NULL,
        action VARCHAR(60) NOT NULL,
        signature_method VARCHAR(30) DEFAULT NULL,
        signer_name VARCHAR(180) DEFAULT NULL,
        signer_nif VARCHAR(50) DEFAULT NULL,
        source_pdf_sha256 CHAR(64) DEFAULT NULL,
        signed_pdf_sha256 CHAR(64) DEFAULT NULL,
        source_ip VARCHAR(64) DEFAULT NULL,
        user_agent VARCHAR(500) DEFAULT NULL,
        performed_by INT UNSIGNED DEFAULT NULL,
        metadata_json LONGTEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_legal_consent_audit_patient (tenant_id, patient_id, created_at),
        INDEX idx_legal_consent_audit_document (tenant_id, legal_document_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

migration_run($mysqli, 'CREATE TABLE tenant_domains', "
    CREATE TABLE IF NOT EXISTS tenant_domains (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id INT UNSIGNED NOT NULL,
        domain VARCHAR(255) NOT NULL,
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_tenant_domains_domain (domain),
        KEY idx_tenant_domains_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

if (migration_table_exists($mysqli, 'tenants')) {
    $tenant_registration_status_existed = migration_column_exists($mysqli, 'tenants', 'registration_status');
    migration_add_column_if_missing($mysqli, 'tenants', 'timezone', "VARCHAR(64) NOT NULL DEFAULT 'Europe/Madrid' AFTER public_site_enabled");
    migration_add_column_if_missing($mysqli, 'tenants', 'onboarding_completed', "TINYINT(1) NOT NULL DEFAULT 0 AFTER timezone");
    migration_add_column_if_missing($mysqli, 'tenants', 'onboarding_completed_at', "DATETIME DEFAULT NULL AFTER onboarding_completed");
    migration_add_column_if_missing($mysqli, 'tenants', 'onboarding_version', "SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER onboarding_completed_at");
    migration_add_column_if_missing($mysqli, 'tenants', 'onboarding_current_step', "TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER onboarding_version");
    migration_add_column_if_missing($mysqli, 'tenants', 'registration_status', "TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status");
    migration_add_column_if_missing($mysqli, 'tenants', 'trial_days', "SMALLINT UNSIGNED NOT NULL DEFAULT 15 AFTER registration_status");
    $mysqli->query("ALTER TABLE tenants MODIFY plan_key VARCHAR(32) NOT NULL DEFAULT 'summum'");
    migration_add_column_if_missing($mysqli, 'tenants', 'registered_at', "DATETIME DEFAULT NULL AFTER trial_days");
    migration_add_index_if_missing($mysqli, 'tenants', 'idx_tenants_registration_status', "INDEX idx_tenants_registration_status (registration_status)");

    if (!$tenant_registration_status_existed) {
        migration_run($mysqli, 'MARK existing tenants as registered', "
            UPDATE tenants
            SET registration_status = 1,
                registered_at = COALESCE(installed_at, created_at, NOW())
            WHERE status IN ('active', 'suspended')
        ");
    }
} else {
    migration_log('skip', 'tenants no existe');
}

migration_run($mysqli, 'CREATE TABLE patient_profiles', "
    CREATE TABLE IF NOT EXISTS patient_profiles (
        user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL DEFAULT $tenant_id,
        patient_type VARCHAR(80) DEFAULT NULL,
        fiscal_name VARCHAR(180) DEFAULT NULL,
        fiscal_nif VARCHAR(50) DEFAULT NULL,
        invoice_use_alt_data TINYINT(1) NOT NULL DEFAULT 0,
        invoice_tax_exempt TINYINT(1) NOT NULL DEFAULT 0,
        invoice_name VARCHAR(180) DEFAULT NULL,
        invoice_nif VARCHAR(50) DEFAULT NULL,
        invoice_email VARCHAR(180) DEFAULT NULL,
        invoice_phone VARCHAR(40) DEFAULT NULL,
        invoice_address VARCHAR(255) DEFAULT NULL,
        patient_status VARCHAR(20) NOT NULL DEFAULT 'active',
        deletion_requested_at DATETIME DEFAULT NULL,
        deletion_reason VARCHAR(500) DEFAULT NULL,
        deletion_mode VARCHAR(24) DEFAULT NULL,
        waiting_list TINYINT(1) NOT NULL DEFAULT 0,
        birth_date DATE DEFAULT NULL,
        referral_source VARCHAR(80) DEFAULT NULL,
        knowledge_problem_id INT UNSIGNED DEFAULT NULL,
        manual_diagnosis VARCHAR(500) DEFAULT NULL,
        initial_consultation_reason TEXT DEFAULT NULL,
        background_notes TEXT DEFAULT NULL,
        support_network_notes TEXT DEFAULT NULL,
        habits TEXT DEFAULT NULL,
        smoker TINYINT(1) NOT NULL DEFAULT 0,
        alcohol_consumption VARCHAR(20) DEFAULT NULL,
        preferred_service_option_id INT UNSIGNED DEFAULT NULL,
        emergency_contact_name VARCHAR(150) DEFAULT NULL,
        emergency_contact_nif VARCHAR(50) DEFAULT NULL,
        emergency_contact_phone VARCHAR(40) DEFAULT NULL,
        emergency_contact_relation VARCHAR(80) DEFAULT NULL,
        address VARCHAR(255) DEFAULT NULL,
        admission_date DATE DEFAULT NULL,
        notes LONGTEXT DEFAULT NULL,
        physical_sex VARCHAR(12) DEFAULT NULL,
        weight_kg DECIMAL(6,2) DEFAULT NULL,
        height_cm DECIMAL(6,2) DEFAULT NULL,
        body_fat_percentage DECIMAL(5,2) DEFAULT NULL,
        waist_cm DECIMAL(6,2) DEFAULT NULL,
        hip_cm DECIMAL(6,2) DEFAULT NULL,
        chest_cm DECIMAL(6,2) DEFAULT NULL,
        thigh_cm DECIMAL(6,2) DEFAULT NULL,
        biceps_cm DECIMAL(6,2) DEFAULT NULL,
        calf_cm DECIMAL(6,2) DEFAULT NULL,
        skinfold_triceps_mm DECIMAL(6,2) DEFAULT NULL,
        skinfold_subscapular_mm DECIMAL(6,2) DEFAULT NULL,
        skinfold_suprailiac_mm DECIMAL(6,2) DEFAULT NULL,
        skinfold_abdominal_mm DECIMAL(6,2) DEFAULT NULL,
        skinfold_chest_mm DECIMAL(6,2) DEFAULT NULL,
        skinfold_thigh_mm DECIMAL(6,2) DEFAULT NULL,
        photo_path VARCHAR(255) DEFAULT NULL,
        document_path VARCHAR(255) DEFAULT NULL,
        document_name VARCHAR(255) DEFAULT NULL,
        created_by_admin TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_patient_profiles_type (patient_type),
        INDEX idx_patient_profiles_admission (admission_date),
        INDEX idx_patient_profiles_waiting_list (tenant_id, waiting_list)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

migration_add_column_if_missing($mysqli, 'patient_profiles', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER user_id");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'fiscal_name', "VARCHAR(180) DEFAULT NULL AFTER patient_type");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'fiscal_nif', "VARCHAR(50) DEFAULT NULL AFTER fiscal_name");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_use_alt_data', "TINYINT(1) NOT NULL DEFAULT 0 AFTER fiscal_nif");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_tax_exempt', "TINYINT(1) NOT NULL DEFAULT 0 AFTER invoice_use_alt_data");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_name', "VARCHAR(180) DEFAULT NULL AFTER invoice_use_alt_data");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_nif', "VARCHAR(50) DEFAULT NULL AFTER invoice_name");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_email', "VARCHAR(180) DEFAULT NULL AFTER invoice_nif");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_phone', "VARCHAR(40) DEFAULT NULL AFTER invoice_email");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'invoice_address', "VARCHAR(255) DEFAULT NULL AFTER invoice_phone");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'timezone', "VARCHAR(64) DEFAULT NULL AFTER invoice_address");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'waiting_list', "TINYINT(1) NOT NULL DEFAULT 0 AFTER patient_status");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'deletion_requested_at', "DATETIME DEFAULT NULL AFTER patient_status");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'deletion_reason', "VARCHAR(500) DEFAULT NULL AFTER deletion_requested_at");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'deletion_mode', "VARCHAR(24) DEFAULT NULL AFTER deletion_reason");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'emergency_contact_nif', "VARCHAR(50) DEFAULT NULL AFTER emergency_contact_name");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'habits', "TEXT DEFAULT NULL AFTER support_network_notes");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'smoker', "TINYINT(1) NOT NULL DEFAULT 0 AFTER habits");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'alcohol_consumption', "VARCHAR(20) DEFAULT NULL AFTER smoker");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'preferred_service_option_id', "INT UNSIGNED DEFAULT NULL AFTER alcohol_consumption");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'address', "VARCHAR(255) DEFAULT NULL AFTER emergency_contact_relation");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'knowledge_problem_id', "INT UNSIGNED DEFAULT NULL AFTER referral_source");
migration_add_column_if_missing($mysqli, 'patient_profiles', 'manual_diagnosis', "VARCHAR(500) DEFAULT NULL AFTER knowledge_problem_id");
if (!migration_column_exists($mysqli, 'patient_profiles', 'initial_consultation_reason')) {
    migration_add_column_if_missing($mysqli, 'patient_profiles', 'initial_consultation_reason', "TEXT DEFAULT NULL AFTER knowledge_problem_id");
}
if (migration_column_exists($mysqli, 'patient_profiles', 'initial_consultation_reason')) {
    migration_add_column_if_missing($mysqli, 'patient_profiles', 'background_notes', "TEXT DEFAULT NULL AFTER initial_consultation_reason");
} else {
    migration_add_column_if_missing($mysqli, 'patient_profiles', 'background_notes', "TEXT DEFAULT NULL AFTER knowledge_problem_id");
}
if (migration_column_exists($mysqli, 'patient_profiles', 'background_notes')) {
    migration_add_column_if_missing($mysqli, 'patient_profiles', 'support_network_notes', "TEXT DEFAULT NULL AFTER background_notes");
} else {
    migration_add_column_if_missing($mysqli, 'patient_profiles', 'support_network_notes', "TEXT DEFAULT NULL AFTER initial_consultation_reason");
}
migration_add_index_if_missing($mysqli, 'patient_profiles', 'idx_patient_profiles_knowledge_problem', "INDEX idx_patient_profiles_knowledge_problem (knowledge_problem_id)");
migration_add_index_if_missing($mysqli, 'patient_profiles', 'idx_patient_profiles_waiting_list', "INDEX idx_patient_profiles_waiting_list (tenant_id, waiting_list)");

migration_run($mysqli, 'CREATE TABLE patient_diagnoses', "
    CREATE TABLE IF NOT EXISTS patient_diagnoses (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        patient_id INT UNSIGNED NOT NULL,
        knowledge_problem_id INT UNSIGNED DEFAULT NULL,
        source_type VARCHAR(20) NOT NULL DEFAULT 'manual',
        manual_label VARCHAR(500) DEFAULT NULL,
        selected_objective VARCHAR(500) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        notes TEXT DEFAULT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_patient_diagnoses_patient (tenant_id, patient_id, status),
        INDEX idx_patient_diagnoses_problem (knowledge_problem_id),
        INDEX idx_patient_diagnoses_primary (tenant_id, patient_id, is_primary)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
migration_add_column_if_missing($mysqli, 'patient_diagnoses', 'sort_order', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_primary");
migration_run($mysqli, 'Initialize patient diagnosis priority', "UPDATE patient_diagnoses SET sort_order = id WHERE sort_order = 0");
migration_run($mysqli, 'Migrate legacy patient diagnoses', "
    INSERT INTO patient_diagnoses (
        tenant_id, patient_id, knowledge_problem_id, source_type, manual_label,
        status, is_primary, sort_order, created_by
    )
    SELECT pp.tenant_id, pp.user_id, pp.knowledge_problem_id,
           CASE WHEN pp.knowledge_problem_id IS NOT NULL AND pp.knowledge_problem_id > 0 THEN 'knowledge' ELSE 'manual' END,
           NULLIF(TRIM(pp.manual_diagnosis), ''), 'active', 1, pp.user_id, NULL
    FROM patient_profiles pp
    WHERE (
        (pp.knowledge_problem_id IS NOT NULL AND pp.knowledge_problem_id > 0)
        OR NULLIF(TRIM(pp.manual_diagnosis), '') IS NOT NULL
    )
      AND NOT EXISTS (
          SELECT 1 FROM patient_diagnoses pd
          WHERE pd.tenant_id = pp.tenant_id AND pd.patient_id = pp.user_id
      )
");

migration_run($mysqli, 'CREATE TABLE patient_contacts', "
    CREATE TABLE IF NOT EXISTS patient_contacts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id INT UNSIGNED NOT NULL,
        patient_id INT UNSIGNED NOT NULL,
        name VARCHAR(180) NOT NULL,
        relationship VARCHAR(80) DEFAULT NULL,
        nif VARCHAR(50) DEFAULT NULL,
        email VARCHAR(180) DEFAULT NULL,
        phone VARCHAR(40) DEFAULT NULL,
        address VARCHAR(255) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        is_legal_guardian TINYINT(1) NOT NULL DEFAULT 0,
        is_emergency_contact TINYINT(1) NOT NULL DEFAULT 0,
        receives_communications TINYINT(1) NOT NULL DEFAULT 0,
        portal_access_enabled TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        legacy_source VARCHAR(40) DEFAULT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_patient_contacts_legacy (tenant_id, patient_id, legacy_source),
        INDEX idx_patient_contacts_patient (tenant_id, patient_id, is_active),
        INDEX idx_patient_contacts_email (tenant_id, email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

migration_run($mysqli, 'IMPORT legacy patient contacts', "
    INSERT INTO patient_contacts (
        tenant_id, patient_id, name, relationship, nif, phone,
        is_legal_guardian, is_emergency_contact, legacy_source, created_by
    )
    SELECT
        pp.tenant_id, pp.user_id, pp.emergency_contact_name,
        pp.emergency_contact_relation, pp.emergency_contact_nif,
        pp.emergency_contact_phone, 1, 1, 'patient_profile', NULL
    FROM patient_profiles pp
    WHERE NULLIF(TRIM(pp.emergency_contact_name), '') IS NOT NULL
    ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        relationship = VALUES(relationship),
        nif = VALUES(nif),
        phone = VALUES(phone),
        is_active = 1
");

migration_add_column_if_missing($mysqli, 'patient_evolution_files', 'visible_to_patient', "TINYINT(1) NOT NULL DEFAULT 0 AFTER file_size");
migration_add_column_if_missing($mysqli, 'patient_documents', 'appointment_id', "INT UNSIGNED DEFAULT NULL AFTER patient_id");
migration_add_column_if_missing($mysqli, 'patient_documents', 'editable_file_path', "VARCHAR(500) DEFAULT NULL AFTER mime_type");
migration_add_column_if_missing($mysqli, 'patient_documents', 'editable_mime_type', "VARCHAR(120) DEFAULT NULL AFTER editable_file_path");
migration_add_index_if_missing($mysqli, 'patient_documents', 'idx_patient_documents_appointment', "INDEX idx_patient_documents_appointment (tenant_id, appointment_id)");

migration_add_column_if_missing($mysqli, 'appointments', 'online_session_url', "VARCHAR(500) DEFAULT NULL AFTER payment_updated_by");
migration_add_column_if_missing($mysqli, 'appointments', 'livekit_access_token', "CHAR(64) DEFAULT NULL AFTER online_session_url");
if (migration_column_exists($mysqli, 'appointments', 'livekit_access_token')) {
    migration_add_column_if_missing($mysqli, 'appointments', 'session_notes', "TEXT DEFAULT NULL AFTER livekit_access_token");
} else {
    migration_add_column_if_missing($mysqli, 'appointments', 'session_notes', "TEXT DEFAULT NULL AFTER online_session_url");
}
migration_add_column_if_missing($mysqli, 'appointments', 'reminder_sent_at', "DATETIME DEFAULT NULL");
migration_add_column_if_missing($mysqli, 'appointments', 'second_reminder_sent_at', "DATETIME DEFAULT NULL AFTER reminder_sent_at");
migration_add_column_if_missing($mysqli, 'appointments', 'sms_reminder_sent_at', "DATETIME DEFAULT NULL AFTER second_reminder_sent_at");
migration_add_column_if_missing($mysqli, 'appointments', 'consultation_type', "VARCHAR(20) NOT NULL DEFAULT 'presencial' AFTER appointment_time");
migration_add_column_if_missing($mysqli, 'appointments', 'location_id', "INT UNSIGNED DEFAULT NULL AFTER consultation_type");
migration_add_column_if_missing($mysqli, 'appointments', 'service_type', "VARCHAR(20) DEFAULT NULL AFTER consultation_type");
migration_add_column_if_missing($mysqli, 'appointments', 'service_option_id', "INT UNSIGNED DEFAULT NULL AFTER service_type");
migration_add_column_if_missing($mysqli, 'appointments', 'duration_minutes', "SMALLINT UNSIGNED DEFAULT NULL AFTER service_option_id");
migration_run($mysqli, 'MODIFY appointments.status', "ALTER TABLE appointments MODIFY status VARCHAR(32) NOT NULL DEFAULT 'booked'");

try {
    ensure_cabinet_schema($mysqli);
    migration_log('ok', 'Equipo: professionals/professional_settings');
} catch (Throwable $e) {
    migration_log('error', 'Equipo: ' . $e->getMessage());
}
migration_add_column_if_missing($mysqli, 'professional_settings', 'default_appointment_location', "VARCHAR(255) DEFAULT NULL AFTER available_weekdays");
migration_add_column_if_missing($mysqli, 'professional_settings', 'default_location_id', "INT UNSIGNED DEFAULT NULL AFTER default_appointment_location");
migration_add_column_if_missing($mysqli, 'professional_settings', 'livekit_enabled', "TINYINT(1) NOT NULL DEFAULT 1 AFTER default_appointment_location");
$video_provider_was_missing = !migration_column_exists($mysqli, 'professional_settings', 'video_provider');
migration_add_column_if_missing($mysqli, 'professional_settings', 'video_provider', "VARCHAR(20) NOT NULL DEFAULT 'livekit' AFTER livekit_enabled");
if ($video_provider_was_missing && migration_column_exists($mysqli, 'professional_settings', 'video_provider')) {
    migration_run($mysqli, 'Inicializar proveedor de videollamada', "UPDATE professional_settings SET video_provider = CASE WHEN livekit_enabled = 1 THEN 'livekit' ELSE 'manual' END");
}
migration_add_column_if_missing($mysqli, 'professional_settings', 'livekit_recording_enabled', "TINYINT(1) NOT NULL DEFAULT 0 AFTER livekit_enabled");
migration_add_column_if_missing($mysqli, 'professional_settings', 'livekit_recording_mode', "VARCHAR(20) NOT NULL DEFAULT 'audio' AFTER livekit_recording_enabled");

try {
    ensure_livekit_recording_schema($mysqli, true);
    migration_log('ok', 'LiveKit recording: professional_settings/appointment_recordings');
} catch (Throwable $e) {
    migration_log('error', 'LiveKit recording: ' . $e->getMessage());
}

migration_run($mysqli, 'CREATE TABLE appointment_locations', "
    CREATE TABLE IF NOT EXISTS appointment_locations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id INT UNSIGNED NOT NULL DEFAULT $tenant_id,
        location_key VARCHAR(80) DEFAULT NULL,
        name VARCHAR(120) NOT NULL,
        location_type VARCHAR(24) NOT NULL DEFAULT 'custom',
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_appointment_locations_key (tenant_id, location_key),
        INDEX idx_appointment_locations_enabled (tenant_id, is_enabled, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
migration_add_column_if_missing($mysqli, 'appointment_locations', 'tenant_id', "INT UNSIGNED NOT NULL DEFAULT $tenant_id AFTER id");
migration_add_column_if_missing($mysqli, 'appointment_locations', 'location_key', "VARCHAR(80) DEFAULT NULL AFTER tenant_id");
migration_add_column_if_missing($mysqli, 'appointment_locations', 'location_type', "VARCHAR(24) NOT NULL DEFAULT 'custom' AFTER name");
migration_add_column_if_missing($mysqli, 'appointment_locations', 'is_enabled', "TINYINT(1) NOT NULL DEFAULT 1 AFTER location_type");
migration_add_column_if_missing($mysqli, 'appointment_locations', 'is_system', "TINYINT(1) NOT NULL DEFAULT 0 AFTER is_enabled");
migration_add_column_if_missing($mysqli, 'appointment_locations', 'sort_order', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_system");
migration_run($mysqli, 'SEED appointment_locations.default', "
    INSERT INTO appointment_locations (tenant_id, location_key, name, location_type, is_enabled, is_system, sort_order)
    VALUES ($tenant_id, 'default', 'Predeterminada', 'default', 1, 1, 10)
    ON DUPLICATE KEY UPDATE name = VALUES(name), location_type = VALUES(location_type), is_enabled = 1, is_system = 1, sort_order = VALUES(sort_order)
");
migration_run($mysqli, 'SEED appointment_locations.home', "
    INSERT INTO appointment_locations (tenant_id, location_key, name, location_type, is_enabled, is_system, sort_order)
    VALUES ($tenant_id, 'home', 'A domicilio', 'home', 0, 1, 20)
    ON DUPLICATE KEY UPDATE name = VALUES(name), location_type = VALUES(location_type), is_system = 1, sort_order = VALUES(sort_order)
");

try {
    ensure_invoice_schema($mysqli);
    migration_log('ok', 'Facturacion: movim/payment_settings/patient fiscal fields');
} catch (Throwable $e) {
    migration_log('error', 'Facturacion: ' . $e->getMessage());
}

migration_add_column_if_missing($mysqli, 'payment_settings', 'billing_tax_system', "VARCHAR(16) NOT NULL DEFAULT 'iva' AFTER billing_report_concept");
migration_add_column_if_missing($mysqli, 'payment_settings', 'billing_default_tax_mode', "VARCHAR(16) NOT NULL DEFAULT 'exempt' AFTER billing_tax_system");
migration_add_column_if_missing($mysqli, 'payment_settings', 'billing_default_tax_rate', "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER billing_default_tax_mode");
migration_add_column_if_missing($mysqli, 'payment_settings', 'billing_exemption_reason', "VARCHAR(500) DEFAULT NULL AFTER billing_default_tax_rate");
migration_add_column_if_missing($mysqli, 'appointment_services', 'tax_mode', "VARCHAR(16) NOT NULL DEFAULT 'inherit' AFTER name");
migration_add_column_if_missing($mysqli, 'appointment_services', 'tax_rate', "DECIMAL(5,2) DEFAULT NULL AFTER tax_mode");
migration_add_column_if_missing($mysqli, 'appointment_services', 'tax_exemption_reason', "VARCHAR(500) DEFAULT NULL AFTER tax_rate");
migration_add_column_if_missing($mysqli, 'appointment_service_options', 'discount_percentage', "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER price");
migration_add_column_if_missing($mysqli, 'payment_settings', 'discount_period_enabled', "TINYINT(1) NOT NULL DEFAULT 0 AFTER show_prices_public");
migration_add_column_if_missing($mysqli, 'payment_settings', 'discount_period_start_date', "DATE DEFAULT NULL AFTER discount_period_enabled");
migration_add_column_if_missing($mysqli, 'payment_settings', 'discount_period_end_date', "DATE DEFAULT NULL AFTER discount_period_start_date");
migration_add_column_if_missing($mysqli, 'payment_settings', 'discount_show_public', "TINYINT(1) NOT NULL DEFAULT 0 AFTER discount_period_end_date");
migration_add_column_if_missing($mysqli, 'appointments', 'base_price_amount', "DECIMAL(10,2) DEFAULT NULL AFTER duration_minutes");
migration_add_column_if_missing($mysqli, 'appointments', 'discount_percentage', "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER base_price_amount");
migration_add_column_if_missing($mysqli, 'appointments', 'final_price_amount', "DECIMAL(10,2) DEFAULT NULL AFTER discount_percentage");
migration_add_column_if_missing($mysqli, 'movim', 'tax_system', "VARCHAR(16) NOT NULL DEFAULT 'iva' AFTER iva_importe");
migration_add_column_if_missing($mysqli, 'movim', 'tax_exemption_reason', "VARCHAR(500) DEFAULT NULL AFTER tax_system");
migration_add_column_if_missing($mysqli, 'movim', 'destinatario_direccion', "VARCHAR(255) DEFAULT NULL AFTER destinatario_email");
migration_add_column_if_missing($mysqli, 'payment_settings', 'verifactu_enabled', "TINYINT(1) NOT NULL DEFAULT 1 AFTER billing_exemption_reason");
migration_add_column_if_missing($mysqli, 'payment_settings', 'verifactu_environment', "TINYINT(1) NOT NULL DEFAULT 0 AFTER verifactu_enabled");
migration_add_column_if_missing($mysqli, 'payment_settings', 'verifactu_taxpayer_type', "VARCHAR(20) NOT NULL DEFAULT 'self_employed' AFTER verifactu_environment");
migration_add_column_if_missing($mysqli, 'payment_settings', 'verifactu_activation_mode', "VARCHAR(16) NOT NULL DEFAULT 'official' AFTER verifactu_taxpayer_type");
migration_add_column_if_missing($mysqli, 'payment_settings', 'verifactu_start_date', "DATE DEFAULT NULL AFTER verifactu_activation_mode");
migration_add_column_if_missing($mysqli, 'payment_settings', 'verifactu_activated_at', "DATETIME DEFAULT NULL AFTER verifactu_start_date");
migration_run($mysqli, 'NORMALIZE payment_settings.verifactu_environment', "
    UPDATE payment_settings
    SET verifactu_environment = CASE
        WHEN LOWER(CAST(verifactu_environment AS CHAR)) IN ('1', 'production', 'real') THEN 1
        ELSE 0
    END
");
migration_run($mysqli, 'MODIFY payment_settings.verifactu_environment', "
    ALTER TABLE payment_settings
    MODIFY verifactu_environment TINYINT(1) NOT NULL DEFAULT 0
");
migration_run($mysqli, 'INITIALIZE VeriFactu activation dates', "
    UPDATE payment_settings
    SET verifactu_taxpayer_type = CASE
            WHEN verifactu_taxpayer_type = 'company' THEN 'company'
            ELSE 'self_employed'
        END,
        verifactu_activation_mode = CASE
            WHEN verifactu_enabled = 1 AND verifactu_activation_mode = 'disabled' THEN 'official'
            ELSE verifactu_activation_mode
        END,
        verifactu_start_date = CASE
            WHEN verifactu_enabled = 1 AND verifactu_start_date IS NULL
                THEN CASE
                    WHEN verifactu_taxpayer_type = 'company' THEN '2027-01-01'
                    ELSE '2027-07-01'
                END
            ELSE verifactu_start_date
        END
");
migration_run($mysqli, 'DEFAULT VeriFactu official activation', "
    UPDATE payment_settings
    SET verifactu_enabled = 1,
        verifactu_activation_mode = 'official',
        verifactu_start_date = CASE
            WHEN verifactu_taxpayer_type = 'company' THEN '2027-01-01'
            ELSE '2027-07-01'
        END
    WHERE verifactu_activation_mode = 'disabled'
       OR verifactu_start_date IS NULL
");
migration_run($mysqli, 'MODIFY VeriFactu activation defaults', "
    ALTER TABLE payment_settings
        MODIFY verifactu_enabled TINYINT(1) NOT NULL DEFAULT 1,
        MODIFY verifactu_activation_mode VARCHAR(16) NOT NULL DEFAULT 'official'
");
migration_run($mysqli, 'INITIALIZE VeriFactu activation timestamp', "
    UPDATE payment_settings
    SET verifactu_activated_at = COALESCE(verifactu_activated_at, NOW())
    WHERE verifactu_environment = 1
      AND verifactu_enabled = 1
");

migration_run($mysqli, 'CREATE TABLE verifactu_chains', "
    CREATE TABLE IF NOT EXISTS verifactu_chains (
        tenant_id INT UNSIGNED NOT NULL,
        environment VARCHAR(16) NOT NULL DEFAULT 'test',
        last_record_id BIGINT UNSIGNED DEFAULT NULL,
        last_invoice_number VARCHAR(40) DEFAULT NULL,
        last_invoice_date DATE DEFAULT NULL,
        last_fingerprint CHAR(64) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (tenant_id, environment)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

migration_run($mysqli, 'CREATE TABLE verifactu_records', "
    CREATE TABLE IF NOT EXISTS verifactu_records (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id INT UNSIGNED NOT NULL,
        movim_id INT UNSIGNED NOT NULL,
        environment VARCHAR(16) NOT NULL DEFAULT 'test',
        operation_type VARCHAR(24) NOT NULL DEFAULT 'registration',
        status VARCHAR(32) NOT NULL DEFAULT 'generated',
        invoice_number VARCHAR(40) NOT NULL,
        invoice_date DATE NOT NULL,
        previous_record_id BIGINT UNSIGNED DEFAULT NULL,
        previous_invoice_number VARCHAR(40) DEFAULT NULL,
        previous_invoice_date DATE DEFAULT NULL,
        previous_fingerprint CHAR(64) DEFAULT NULL,
        fingerprint CHAR(64) NOT NULL,
        payload_json LONGTEXT NOT NULL,
        record_xml_path VARCHAR(500) DEFAULT NULL,
        qr_url VARCHAR(1000) DEFAULT NULL,
        qr_url VARCHAR(1000) DEFAULT NULL,
        request_path VARCHAR(500) DEFAULT NULL,
        response_path VARCHAR(500) DEFAULT NULL,
        response_code VARCHAR(80) DEFAULT NULL,
        response_message TEXT DEFAULT NULL,
        attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        last_attempt_at DATETIME DEFAULT NULL,
        next_attempt_at DATETIME DEFAULT NULL,
        lock_token CHAR(36) DEFAULT NULL,
        locked_at DATETIME DEFAULT NULL,
        generated_at VARCHAR(40) NOT NULL,
        sent_at DATETIME DEFAULT NULL,
        accepted_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_verifactu_record (tenant_id, movim_id, environment, operation_type),
        INDEX idx_verifactu_pending (status, next_attempt_at, locked_at),
        INDEX idx_verifactu_chain (tenant_id, environment, id),
        INDEX idx_verifactu_movim (tenant_id, movim_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
migration_add_column_if_missing($mysqli, 'verifactu_records', 'record_xml_path', "VARCHAR(500) DEFAULT NULL AFTER payload_json");
migration_add_column_if_missing($mysqli, 'verifactu_records', 'qr_url', "VARCHAR(1000) DEFAULT NULL AFTER record_xml_path");
migration_run($mysqli, 'MODIFY verifactu_records.status', "ALTER TABLE verifactu_records MODIFY status VARCHAR(32) NOT NULL DEFAULT 'generated'");

migration_run($mysqli, 'CREATE TABLE verifactu_batches', "
    CREATE TABLE IF NOT EXISTS verifactu_batches (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id INT UNSIGNED NOT NULL,
        environment VARCHAR(16) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'generated',
        incident TINYINT(1) NOT NULL DEFAULT 0,
        record_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        request_path VARCHAR(500) DEFAULT NULL,
        response_path VARCHAR(500) DEFAULT NULL,
        response_code VARCHAR(80) DEFAULT NULL,
        response_message TEXT DEFAULT NULL,
        generated_at DATETIME NOT NULL,
        sent_at DATETIME DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_verifactu_batches_pending (status, tenant_id, environment)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

migration_run($mysqli, 'CREATE TABLE verifactu_batch_records', "
    CREATE TABLE IF NOT EXISTS verifactu_batch_records (
        batch_id BIGINT UNSIGNED NOT NULL,
        record_id BIGINT UNSIGNED NOT NULL,
        sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (batch_id, record_id),
        UNIQUE KEY uq_verifactu_record_batch (record_id),
        INDEX idx_verifactu_batch_order (batch_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

migration_add_column_if_missing($mysqli, 'payment_settings', 'legal_province', "VARCHAR(120) DEFAULT NULL AFTER legal_address");
migration_add_column_if_missing($mysqli, 'payment_settings', 'legal_city', "VARCHAR(120) DEFAULT NULL AFTER legal_province");
migration_add_column_if_missing($mysqli, 'payment_settings', 'legal_postal_code', "VARCHAR(20) DEFAULT NULL AFTER legal_city");
migration_add_column_if_missing($mysqli, 'payment_settings', 'legal_health_registry_number', "VARCHAR(120) DEFAULT NULL AFTER legal_email");

migration_add_column_if_missing($mysqli, 'payment_settings', 'appointment_second_reminder_enabled', "TINYINT(1) NOT NULL DEFAULT 0 AFTER appointment_reminder_enabled");
migration_add_column_if_missing($mysqli, 'payment_settings', 'appointment_second_reminder_hours', "SMALLINT UNSIGNED NOT NULL DEFAULT 48 AFTER appointment_second_reminder_enabled");
migration_add_column_if_missing($mysqli, 'payment_settings', 'sms_provider', "VARCHAR(20) NOT NULL DEFAULT 'none' AFTER appointment_second_reminder_hours");
migration_add_column_if_missing($mysqli, 'payment_settings', 'sms_sender', "VARCHAR(40) DEFAULT NULL AFTER sms_provider");
migration_add_column_if_missing($mysqli, 'payment_settings', 'sms_username', "VARCHAR(120) DEFAULT NULL AFTER sms_sender");
migration_add_column_if_missing($mysqli, 'payment_settings', 'sms_password', "VARCHAR(255) DEFAULT NULL AFTER sms_username");
migration_add_column_if_missing($mysqli, 'payment_settings', 'sms_api_key', "VARCHAR(255) DEFAULT NULL AFTER sms_password");
migration_add_column_if_missing($mysqli, 'payment_settings', 'sms_reminder_enabled', "TINYINT(1) NOT NULL DEFAULT 0 AFTER sms_api_key");
migration_add_column_if_missing($mysqli, 'payment_settings', 'sms_reminder_hours', "SMALLINT UNSIGNED NOT NULL DEFAULT 24 AFTER sms_reminder_enabled");
migration_add_column_if_missing($mysqli, 'payment_settings', 'signature_auto_invoices', "TINYINT(1) NOT NULL DEFAULT 0 AFTER sms_reminder_hours");
migration_add_column_if_missing($mysqli, 'payment_settings', 'signature_auto_reports', "TINYINT(1) NOT NULL DEFAULT 0 AFTER signature_auto_invoices");
migration_add_column_if_missing($mysqli, 'payment_settings', 'signature_auto_documents', "TINYINT(1) NOT NULL DEFAULT 0 AFTER signature_auto_reports");
migration_add_column_if_missing($mysqli, 'appointments', 'patient_confirmed_at', "DATETIME DEFAULT NULL AFTER cancelled_at");
migration_add_column_if_missing($mysqli, 'payment_settings', 'microsoft_refresh_token', "TEXT DEFAULT NULL AFTER icloud_calendar_url");
migration_add_column_if_missing($mysqli, 'professional_settings', 'initial_calendar_view', "VARCHAR(12) DEFAULT NULL");
migration_add_column_if_missing($mysqli, 'professional_settings', 'timezone', "VARCHAR(64) DEFAULT NULL");
migration_add_column_if_missing($mysqli, 'professional_settings', 'notify_new_appointments', "TINYINT(1) DEFAULT NULL");
migration_add_column_if_missing($mysqli, 'professional_settings', 'notify_cancellations', "TINYINT(1) DEFAULT NULL");
migration_add_column_if_missing($mysqli, 'professional_settings', 'notify_payments', "TINYINT(1) DEFAULT NULL");
migration_add_column_if_missing($mysqli, 'professional_settings', 'notify_daily_summary', "TINYINT(1) DEFAULT NULL");
migration_add_column_if_missing($mysqli, 'professional_settings', 'notify_waiting_list', "TINYINT(1) DEFAULT NULL");
migration_add_column_if_missing($mysqli, 'professionals', 'professional_college', "VARCHAR(180) DEFAULT NULL AFTER license_number");
migration_add_column_if_missing($mysqli, 'payment_settings', 'microsoft_connected_email', "VARCHAR(255) DEFAULT NULL AFTER microsoft_refresh_token");
migration_add_column_if_missing($mysqli, 'payment_settings', 'microsoft_calendar_id', "VARCHAR(255) DEFAULT NULL AFTER microsoft_connected_email");
migration_add_column_if_missing($mysqli, 'appointments', 'microsoft_calendar_event_id', "VARCHAR(255) DEFAULT NULL AFTER icloud_calendar_event_url");
migration_run($mysqli, 'Crear tenant_subscriptions', "CREATE TABLE IF NOT EXISTS tenant_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    provider VARCHAR(24) NOT NULL DEFAULT 'braintree',
    environment VARCHAR(16) NOT NULL DEFAULT 'sandbox',
    provider_customer_id VARCHAR(100) DEFAULT NULL,
    provider_subscription_id VARCHAR(100) NOT NULL,
    provider_plan_id VARCHAR(100) NOT NULL,
    plan_key VARCHAR(32) NOT NULL,
    billing_interval VARCHAR(16) NOT NULL DEFAULT 'monthly',
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    amount DECIMAL(12,2) DEFAULT NULL,
    payment_method_type VARCHAR(32) DEFAULT NULL,
    payment_method_last4 VARCHAR(8) DEFAULT NULL,
    payment_method_expiry VARCHAR(7) DEFAULT NULL,
    trial_ends_at DATETIME DEFAULT NULL,
    current_period_starts_at DATETIME DEFAULT NULL,
    current_period_ends_at DATETIME DEFAULT NULL,
    next_billing_at DATETIME DEFAULT NULL,
    cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
    canceled_at DATETIME DEFAULT NULL,
    failure_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_webhook_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_subscriptions_provider_id (provider, environment, provider_subscription_id),
    KEY idx_tenant_subscriptions_tenant (tenant_id, status),
    KEY idx_tenant_subscriptions_customer (provider, environment, provider_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
migration_run($mysqli, 'Crear subscription_transactions', "CREATE TABLE IF NOT EXISTS subscription_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(24) NOT NULL DEFAULT 'braintree',
    environment VARCHAR(16) NOT NULL DEFAULT 'sandbox',
    provider_transaction_id VARCHAR(100) NOT NULL,
    status VARCHAR(32) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    processed_at DATETIME DEFAULT NULL,
    invoice_id BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subscription_transactions_provider_id (provider, environment, provider_transaction_id),
    KEY idx_subscription_transactions_subscription (subscription_id, processed_at),
    KEY idx_subscription_transactions_tenant (tenant_id, processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
migration_run($mysqli, 'Crear subscription_webhook_events', "CREATE TABLE IF NOT EXISTS subscription_webhook_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(24) NOT NULL DEFAULT 'braintree',
    environment VARCHAR(16) NOT NULL DEFAULT 'sandbox',
    event_key CHAR(64) NOT NULL,
    event_kind VARCHAR(80) NOT NULL,
    provider_subscription_id VARCHAR(100) DEFAULT NULL,
    occurred_at DATETIME DEFAULT NULL,
    payload_hash CHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'received',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    processed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subscription_webhook_event (provider, environment, event_key),
    KEY idx_subscription_webhook_status (status, created_at),
    KEY idx_subscription_webhook_subscription (provider_subscription_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
migration_run($mysqli, 'Crear subscription_notifications', "CREATE TABLE IF NOT EXISTS subscription_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED DEFAULT NULL,
    environment VARCHAR(16) NOT NULL DEFAULT 'sandbox',
    event_type VARCHAR(80) NOT NULL,
    notification_key CHAR(64) NOT NULL,
    tenant_email VARCHAR(190) DEFAULT NULL,
    tenant_status VARCHAR(16) NOT NULL DEFAULT 'pending',
    internal_email VARCHAR(190) DEFAULT NULL,
    internal_status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subscription_notification (environment, notification_key),
    KEY idx_subscription_notification_tenant (tenant_id, created_at),
    KEY idx_subscription_notification_status (tenant_status, internal_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
migration_run($mysqli, 'MODIFY payment_settings.available_session_types', "ALTER TABLE payment_settings MODIFY available_session_types VARCHAR(255) NOT NULL DEFAULT 'individual'");
migration_run($mysqli, 'MODIFY payment_settings.available_session_durations', "ALTER TABLE payment_settings MODIFY available_session_durations VARCHAR(100) NOT NULL DEFAULT '60'");
migration_run($mysqli, 'MODIFY professional_settings.available_session_types', "ALTER TABLE professional_settings MODIFY available_session_types VARCHAR(255) DEFAULT NULL");
migration_run($mysqli, 'MODIFY professional_settings.available_session_durations', "ALTER TABLE professional_settings MODIFY available_session_durations VARCHAR(100) DEFAULT NULL");

migration_run($mysqli, 'CREATE TABLE document_signatures', "
    CREATE TABLE IF NOT EXISTS document_signatures (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id INT UNSIGNED NOT NULL,
        source_type VARCHAR(64) NOT NULL,
        source_id BIGINT UNSIGNED NOT NULL,
        source_sha256 CHAR(64) NOT NULL,
        signed_sha256 CHAR(64) NOT NULL,
        certificate_owner ENUM('tenant','professional') NOT NULL,
        professional_id INT UNSIGNED DEFAULT NULL,
        certificate_fingerprint VARCHAR(128) DEFAULT NULL,
        signed_file_path VARCHAR(500) NOT NULL,
        original_file_name VARCHAR(255) NOT NULL,
        signed_by INT UNSIGNED DEFAULT NULL,
        signed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_document_signature_source (tenant_id, source_type, source_id, source_sha256),
        INDEX idx_document_signatures_source (tenant_id, source_type, source_id),
        INDEX idx_document_signatures_professional (tenant_id, professional_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$has_errors = count(array_filter($migration_results, fn($row) => $row['status'] === 'error')) > 0;
$summary_status = $has_errors ? 'Error' : 'OK';

if ($is_cli) {
    echo $summary_status . PHP_EOL;
    foreach ($migration_results as $row) {
        echo '[' . strtoupper($row['status']) . '] ' . $row['message'] . PHP_EOL;
    }
    exit($has_errors ? 1 : 0);
}

if ($has_errors && !headers_sent()) {
    http_response_code(500);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($summary_status, ENT_QUOTES, 'UTF-8') ?> - Migraciones SimplyGest Praxis</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f6f7fb; color: #263238; margin: 0; padding: 32px; }
        main { max-width: 900px; margin: 0 auto; background: #fff; border: 1px solid #dfe4ea; border-radius: 10px; padding: 24px; }
        h1 { margin-top: 0; }
        .summary { border-radius: 8px; font-size: 1.1rem; font-weight: 700; margin: 0 0 18px; padding: 12px 14px; }
        .summary.ok { background: #e8f5ee; color: #087f23; }
        .summary.error { background: #fdecec; color: #b00020; }
        .ok { color: #087f23; }
        .skip { color: #66737d; }
        .error { color: #b00020; }
        li { margin: 6px 0; }
    </style>
</head>
<body>
<main>
    <h1>Migraciones SimplyGest Praxis</h1>
    <p class="summary <?= $has_errors ? 'error' : 'ok' ?>"><?= htmlspecialchars($summary_status, ENT_QUOTES, 'UTF-8') ?></p>
    <p><?= $has_errors ? 'Se han producido errores. Revisa los pasos marcados como Error.' : 'Migraciones completadas. Los pasos Skip ya existian.' ?></p>
    <ul>
        <?php foreach ($migration_results as $row): ?>
            <li class="<?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?>">
                [<?= htmlspecialchars(strtoupper($row['status']), ENT_QUOTES, 'UTF-8') ?>]
                <?= htmlspecialchars($row['message'], ENT_QUOTES, 'UTF-8') ?>
            </li>
        <?php endforeach; ?>
    </ul>
</main>
</body>
</html>
