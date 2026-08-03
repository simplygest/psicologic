<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}
require_once 'db.php';
require_once 'settings_helpers.php';
require_once 'cabinet_helpers.php';
require_once 'dashboard_config_helpers.php';
require_once 'plan_usage_helpers.php';
require_once 'time_tracking_helpers.php';
require_once 'braintree_helpers.php';
require_once 'mail_helpers.php';
require_once 'sms_helpers.php';
$is_superadmin = ($_SESSION['role'] === 'superadmin');
$braintree_environment = praxis_braintree_environment();
if ($is_superadmin && empty($_SESSION['subscription_csrf_token'])) {
  $_SESSION['subscription_csrf_token'] = bin2hex(random_bytes(32));
}
$is_admin = in_array($_SESSION['role'], ['admin', 'superadmin', 'reception', 'administration', 'technical'], true);
if ($is_admin) {
  ensure_cabinet_schema($mysqli);
}
$member_permissions = $is_admin ? cabinet_member_permissions_for_user($mysqli, (int) $_SESSION['user_id'], $_SESSION['role'] ?? '') : [];
$current_user_professional_id = 0;
if ($is_admin) {
  $stmt_current_professional = $mysqli->prepare("SELECT id FROM professionals WHERE tenant_id = ? AND user_id = ? LIMIT 1");
  $current_tenant_id_for_professional = current_tenant_id();
  $current_user_id_for_professional = (int) $_SESSION['user_id'];
  $stmt_current_professional->bind_param("ii", $current_tenant_id_for_professional, $current_user_id_for_professional);
  $stmt_current_professional->execute();
  $current_professional_row = $stmt_current_professional->get_result()->fetch_assoc();
  $current_user_professional_id = (int) ($current_professional_row['id'] ?? 0);
}
$can_access_settings = $is_superadmin || !empty($member_permissions['settings']);
$can_access_agenda = $is_superadmin || !empty($member_permissions['agenda']);
$can_access_patients = $is_superadmin || !empty($member_permissions['patients']);
$can_access_appointments = $is_superadmin || !empty($member_permissions['appointments']);
$can_access_statistics = $is_superadmin || !empty($member_permissions['statistics']);
$can_view_patient_phone = $is_superadmin || !empty($member_permissions['view_patient_phone']);
$can_access_billing = $is_superadmin || !empty($member_permissions['billing']);
$can_access_reports = $is_superadmin || !empty($member_permissions['reports']);
$can_access_private_patient_data = $is_superadmin || !empty($member_permissions['private_patient_data']);
$can_create_patients = $is_superadmin || !empty($member_permissions['create_patients']);
$can_create_appointments = $is_superadmin || !empty($member_permissions['create_appointments']);
$can_cancel_appointments = $is_superadmin || !empty($member_permissions['cancel_appointments']);
$branding = get_public_branding_settings($mysqli);
$public_site_enabled = !in_array(plan_config_normalize_key($branding['plan_key'] ?? 'novus', 'novus'), ['initium', 'novus'], true)
  && !empty($branding['public_site_enabled']);
$current_professional_preferences = $current_user_professional_id > 0
  ? cabinet_get_professional_preferences($mysqli, $current_user_professional_id)
  : ['initial_calendar_view' => ($branding['initial_calendar_view'] ?? 'month'), 'timezone' => tenant_timezone()];
$plan_config = plan_config_for_key($branding['plan_key'] ?? 'default');
$billing_plan_enabled = plan_config_feature_enabled($plan_config, 'billing.enabled', false);
$online_payments_plan_enabled = plan_config_feature_enabled($plan_config, 'onlinePayments.enabled', false)
  && plan_config_feature_enabled($plan_config, 'payments.online', false);
$email_reminders_plan_enabled = plan_config_feature_enabled($plan_config, 'reminders.patient24h', false);
$sms_reminders_plan_enabled = plan_config_feature_enabled($plan_config, 'reminders.sms', false);
$email_reminders_configured = $email_reminders_plan_enabled && app_email_is_configured($mysqli);
$sms_reminders_configured = $sms_reminders_plan_enabled && sms_is_configured($mysqli);
$calendar_sync_plan_enabled = plan_config_feature_enabled($plan_config, 'calendarSync.enabled', false);
$ui_customization_plan_enabled = plan_config_feature_enabled($plan_config, 'ui.customization', false);
$custom_locations_plan_enabled = plan_config_feature_enabled($plan_config, 'catalog.customLocations', false);
$discounts_plan_enabled = plan_config_feature_enabled($plan_config, 'catalog.discounts', false);
$online_document_editor_enabled = plan_config_feature_enabled($plan_config, 'documents.onlineEditor', false);
$drawing_board_enabled = plan_config_feature_enabled($plan_config, 'documents.drawingBoard', false);
$document_uploads_enabled = plan_config_feature_enabled($plan_config, 'documents.uploads', true);
$tenant_signature_plan_enabled = plan_config_feature_enabled($plan_config, 'digitalSignature.tenant', false);
$professional_signature_plan_enabled = plan_config_feature_enabled($plan_config, 'digitalSignature.professional', false);
$time_tracking_plan_enabled = plan_config_feature_enabled($plan_config, 'timeTracking.enabled', false);
$questionnaires_plan_enabled = plan_config_feature_enabled($plan_config, 'questionnaires.enabled', false);
$time_tracking_enabled = $is_admin && $time_tracking_plan_enabled && time_tracking_enabled($mysqli);
$patient_portal_enabled = plan_config_feature_enabled($plan_config, 'patientPortal.enabled', false);
$legal_consent_templates_enabled = plan_config_feature_enabled($plan_config, 'legalConsents.templates', false);
$legal_consent_service_mapping_enabled = plan_config_feature_enabled($plan_config, 'legalConsents.serviceMapping', false);
$legal_consent_handwritten_enabled = plan_config_feature_enabled($plan_config, 'legalConsents.handwrittenSignature', false);
$legal_consent_portal_signature_enabled = plan_config_feature_enabled($plan_config, 'legalConsents.portalSignature', false);
$autofirma_patient_signing_enabled = !$is_admin
  && $legal_consent_portal_signature_enabled
  && plan_config_feature_enabled($plan_config, 'legalConsents.autofirma', false)
  && defined('AUTOFIRMA_PATIENT_SIGNING_ENABLED')
  && AUTOFIRMA_PATIENT_SIGNING_ENABLED;
$initial_billing_settings = [
  'billing_enabled' => 0
];
if ($billing_plan_enabled) {
  $billing_column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'billing_enabled'");
  if ($billing_column_res && $billing_column_res->num_rows > 0) {
    $billing_res = $mysqli->query("SELECT billing_enabled FROM payment_settings WHERE tenant_id = " . current_tenant_id() . " LIMIT 1");
    if ($billing_res && ($billing_row = $billing_res->fetch_assoc())) {
      $initial_billing_settings['billing_enabled'] = (int) ($billing_row['billing_enabled'] ?? 0);
    }
  }
}
if (!$is_admin && !online_booking_enabled($mysqli)) {
  header('Location: index.php');
  exit;
}
$dashboard_config_mode = $is_admin ? dashboard_config_effective_mode_from_db($mysqli, $branding['plan_key'] ?? 'default') : 'simple';
$dashboard_config = dashboard_config_for_mode($dashboard_config_mode);
$invitations_feature_enabled = app_feature_enabled($dashboard_config, $plan_config, 'patientPortal.enabled', false)
  && app_feature_enabled($dashboard_config, $plan_config, 'patientPortal.invitations', false);
$statistics_feature_enabled = app_feature_enabled($dashboard_config, $plan_config, 'reports.globalReports', false);
$bonuses_feature_enabled = app_feature_enabled($dashboard_config, $plan_config, 'bonuses.enabled', false);
$sector_key = $branding['sector_texts_key'] ?? sector_texts_default_key();
$knowledge_base_enabled = $is_admin
  && app_feature_enabled($dashboard_config, $plan_config, 'knowledgeBase.enabled', false)
  && knowledge_base_sector_has_data($mysqli, $sector_key);
$body_map_sector_keys = ['fitness', 'fisioterapia', 'quiropractica', 'osteopatia'];
$physical_metrics_sector_keys = array_merge($body_map_sector_keys, ['nutricion']);
$physical_metrics_available = in_array($sector_key, $physical_metrics_sector_keys, true);
$physical_metrics_enabled = $is_admin && $physical_metrics_available;
$body_map_enabled = $knowledge_base_enabled && in_array($sector_key, $body_map_sector_keys, true);
$google_oauth_flash = $_SESSION['google_oauth_flash'] ?? null;
unset($_SESSION['google_oauth_flash']);
$microsoft_oauth_flash = $_SESSION['microsoft_oauth_flash'] ?? null;
unset($_SESSION['microsoft_oauth_flash']);
$knowledge_disclaimer = 'Las recomendaciones mostradas son material de apoyo documental. No constituyen diagnóstico, prescripción clínica automática ni sustituyen el criterio profesional.';
if ($sector_key === 'fitness') {
  $knowledge_disclaimer = 'Las recomendaciones mostradas son material de apoyo para la planificación del entrenamiento. No sustituyen la valoración del profesional ni deben interpretarse como una rutina automática.';
} elseif (in_array($sector_key, ['fisioterapia', 'osteopatia', 'quiropractica'], true)) {
  $knowledge_disclaimer = 'Las recomendaciones mostradas son material de apoyo documental. No constituyen valoración clínica, tratamiento automático ni sustituyen el criterio profesional.';
}
$sector_texts = sector_texts_for_key($sector_key, $dashboard_config, $plan_config);
$sector_texts_options = sector_texts_available();
$patient_label_singular = $sector_texts['labels']['patient']['singular'] ?? 'paciente';
$patient_label_plural = $sector_texts['labels']['patient']['plural'] ?? 'pacientes';
$patient_label_title_singular = $sector_texts['labels']['patient']['titleSingular'] ?? 'Paciente';
$patient_label_title_plural = $sector_texts['labels']['patient']['titlePlural'] ?? 'Pacientes';
$appointment_label_plural = $sector_texts['labels']['appointment']['plural'] ?? 'citas';
$work_plan_title_singular = $sector_texts['labels']['workPlan']['titleSingular'] ?? 'Plan de trabajo';
$is_psychology_sector = $sector_key === 'psicologia';
$therapeutic_context_sectors = ['psicologia', 'sexologia', 'psicopedagogia'];
$support_network_label = in_array($sector_key, $therapeutic_context_sectors, true)
  ? 'Red de apoyo y contexto vital'
  : 'Situaci&oacute;n familiar, laboral, etc.';
$patient_type_placeholder = $is_psychology_sector ? 'Adulto, pareja, derivado...' : '';
$patient_referral_placeholder = $is_psychology_sector ? 'Web, Doctoralia, recomendaci&oacute;n, m&eacute;dico...' : '';
$professional_title_placeholder = $is_psychology_sector ? 'Psic&oacute;loga sanitaria, Psic&oacute;logo cl&iacute;nico...' : '';
$professional_specialty_placeholder = $is_psychology_sector ? 'Ansiedad, terapia infantil, adultos, pareja...' : '';
$task_template_category_placeholder = $is_psychology_sector ? 'Ej. Ansiedad, adolescentes, seguimiento' : '';
$app_name = $branding['app_name'];
$primary_color = preg_match('/^#[0-9a-fA-F]{6}$/', $branding['primary_color'] ?? '') ? strtolower($branding['primary_color']) : '#4285f4';
$primary_rgb = [
  hexdec(substr($primary_color, 1, 2)),
  hexdec(substr($primary_color, 3, 2)),
  hexdec(substr($primary_color, 5, 2)),
];
$primary_luminance = (($primary_rgb[0] * 299) + ($primary_rgb[1] * 587) + ($primary_rgb[2] * 114)) / 1000;
$navbar_text_color = $primary_luminance > 185 ? '#222222' : '#ffffff';
$navbar_control_bg = $primary_luminance > 185 ? 'rgba(0,0,0,.06)' : 'rgba(255,255,255,.18)';
$navbar_control_hover_bg = $primary_luminance > 185 ? 'rgba(0,0,0,.1)' : 'rgba(255,255,255,.28)';
$sector_help_files = [
  'asesoria' => 'asesoria.php',
  'coaching' => 'coaching.php',
  'entrenamiento_personal' => 'fitness.php',
  'fisioterapia' => 'fisioterapia.php',
  'fitness' => 'fitness.php',
  'logopedia' => 'logopedia.php',
  'nutricion' => 'nutricion.php',
  'osteopatia' => 'osteopatia.php',
  'oposiciones' => 'preparacion_oposiciones.php',
  'preparacion_oposiciones' => 'preparacion_oposiciones.php',
  'psicopedagogia' => 'psicopedagogia.php',
  'quiropractica' => 'quiropractica.php',
  'sexologia' => 'sexologia.php',
  'terapia_ocupacional' => 'terapia_ocupacional.php',
];
$sector_help_file = $sector_help_files[$sector_key] ?? '';
$tenant_url_key = function_exists('current_tenant_key') ? current_tenant_key() : '';
$tenant_base_path = trim(function_exists('tenant_app_base_path') ? tenant_app_base_path() : '', '/');
$help_base_url = function_exists('tenant_public_base_url')
  ? tenant_public_base_url() . 'ayuda/'
  : '/' . ($tenant_base_path !== '' ? $tenant_base_path . '/' : '') . rawurlencode($tenant_url_key) . '/ayuda/';
$sector_help_url = ($sector_help_file !== '' && file_exists(__DIR__ . '/ayuda/' . $sector_help_file))
  ? $help_base_url . '?sector=' . rawurlencode($sector_key)
  : $help_base_url;
$official_brand_logo_url = app_official_brand_logo_url();
$tenant_logo_path = trim((string) ($branding['profile_image_path'] ?? ''));
$tenant_logo_url = $tenant_logo_path !== '' ? app_upload_asset_url($tenant_logo_path) : '';
$plan_key = plan_config_normalize_key($branding['plan_key'] ?? 'novus', 'novus');
$brand_logo_url = '';
if ($is_admin) {
  $brand_logo_url = (in_array($plan_key, ['initium', 'novus'], true) || $tenant_logo_url === '')
    ? $official_brand_logo_url
    : $tenant_logo_url;
} else {
  $brand_logo_url = $tenant_logo_url;
}
$navbar_image_path = '';
$has_team_members = false;
if (!$is_admin) {
  $stmt = $mysqli->prepare("
    SELECT photo_path
    FROM patient_profiles
    WHERE tenant_id = ? AND user_id = ?
    LIMIT 1
  ");
  $tenant_id = current_tenant_id();
  $stmt->bind_param("ii", $tenant_id, $_SESSION['user_id']);
  $stmt->execute();
  $patient_navbar = $stmt->get_result()->fetch_assoc();
  if (!empty($patient_navbar['photo_path'])) {
    $navbar_image_path = $patient_navbar['photo_path'];
  }
}
if ($is_admin) {
  $tenant_id = current_tenant_id();
  $team_count_res = $mysqli->query("SELECT COUNT(*) AS total FROM professionals WHERE tenant_id = $tenant_id AND is_active = 1");
  $team_count = $team_count_res ? (int) ($team_count_res->fetch_assoc()['total'] ?? 0) : 0;
  $has_team_members = $team_count > 1;
  if ($has_team_members && !$is_superadmin) {
    $stmt = $mysqli->prepare("
      SELECT public_photo_path
      FROM professionals
      WHERE tenant_id = ? AND user_id = ? AND is_active = 1
      LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $_SESSION['user_id']);
    $stmt->execute();
    $professional_navbar = $stmt->get_result()->fetch_assoc();
    if (!empty($professional_navbar['public_photo_path'])) {
      $navbar_image_path = $professional_navbar['public_photo_path'];
    }
  }
}
$navbar_image_url = $navbar_image_path !== '' ? app_upload_asset_url($navbar_image_path) : '';
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <title>Dashboard - <?= htmlspecialchars($app_name) ?></title>
  <?= favicon_link_tags($branding) ?>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
  <?php if ($questionnaires_plan_enabled): ?><link rel="stylesheet" href="css/questionnaires.css?v=<?= filemtime(__DIR__ . '/css/questionnaires.css') ?>"><?php endif; ?>
  <style>:root { --primary-color: <?= htmlspecialchars($primary_color) ?>; --dashboard-navbar-text: <?= htmlspecialchars($navbar_text_color) ?>; --dashboard-navbar-control-bg: <?= htmlspecialchars($navbar_control_bg) ?>; --dashboard-navbar-control-hover-bg: <?= htmlspecialchars($navbar_control_hover_bg) ?>; }</style>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>

<body class="<?= $is_admin ? 'is-admin' : 'is-patient' ?>">

  <nav class="navbar navbar-expand-lg">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center gap-2" id="app-brand-link" href="#">
        <?php if ($brand_logo_url !== ''): ?>
          <img src="<?= htmlspecialchars($brand_logo_url) ?>" alt="<?= htmlspecialchars($is_admin ? 'SimplyGest Praxis' : $app_name) ?>" class="brand-avatar app-brand-image" id="app-brand-image" data-official-src="<?= htmlspecialchars($official_brand_logo_url) ?>" data-tenant-src="<?= htmlspecialchars($tenant_logo_url) ?>">
        <?php endif; ?>
        <span id="app-brand" class="dashboard-user-greeting">Hola, <?= htmlspecialchars($_SESSION['name']) ?></span>
      </a>
      <div class="d-flex align-items-center gap-2">
        <?php if ($is_admin): ?>
          <button class="btn btn-light btn-sm" type="button" id="btn-global-search" title="Buscar">
            <i class="bi bi-search"></i>
          </button>
          <button class="btn btn-light btn-sm <?= $time_tracking_enabled ? '' : 'd-none' ?>" type="button" id="btn-navbar-time-tracking"
            data-bs-toggle="modal" data-bs-target="#timeTrackingModal" title="Control horario" aria-label="Control horario">
            <i class="bi bi-stopwatch"></i>
          </button>
        <?php endif; ?>
        <div class="dropdown">
          <button class="btn btn-light btn-sm dropdown-toggle" type="button" id="dashboard-options-menu" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-three-dots-vertical"></i> Opciones
          </button>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="dashboard-options-menu">
            <?php if (!$is_admin): ?>
              <li>
                <button class="dropdown-item" id="btn-my-profile" type="button">
                  <i class="bi bi-person-circle me-2"></i>Mis datos
                </button>
              </li>
            <?php endif; ?>
            <li>
              <button class="dropdown-item" id="btn-change-password" type="button">
                <i class="bi bi-key me-2"></i>Cambiar contrase&ntilde;a
              </button>
            </li>
            <?php if ($is_admin && $can_access_settings): ?>
              <li>
                <button class="dropdown-item" id="btn-open-settings" type="button">
                  <i class="bi bi-gear me-2"></i>Configuraci&oacute;n
                </button>
              </li>
            <?php endif; ?>
            <?php if ($is_superadmin): ?>
              <li>
                <button class="dropdown-item" id="btn-open-data-export" type="button">
                  <i class="bi bi-file-earmark-zip me-2"></i>Exportar todos mis datos
                </button>
              </li>
              <li>
                <button class="dropdown-item" id="btn-open-initial-onboarding" type="button">
                  <i class="bi bi-stars me-2"></i>Asistente inicial
                </button>
              </li>
            <?php endif; ?>
            <?php if ($current_user_professional_id > 0): ?>
              <li>
                <button class="dropdown-item" id="btn-my-professional-profile" type="button">
                  <i class="bi bi-person-badge me-2"></i>Mi ficha profesional
                </button>
              </li>
            <?php endif; ?>
            <?php if ($is_admin): ?>
              <li>
                <a class="dropdown-item" href="<?= htmlspecialchars($sector_help_url) ?>">
                  <i class="bi bi-question-circle me-2"></i>Ayuda
                </a>
              </li>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a href="logout.php" class="dropdown-item text-danger">
                <i class="bi bi-box-arrow-right me-2"></i>Cerrar sesi&oacute;n
              </a>
            </li>
          </ul>
        </div>
        <img src="<?= htmlspecialchars($navbar_image_url) ?>" alt="" class="navbar-user-avatar<?= $navbar_image_url ? '' : ' d-none' ?>" id="navbar-user-image">
      </div>
    </div>
  </nav>

  <div class="<?= $is_admin ? 'container-fluid dashboard-shell-container mt-4' : 'container mt-4' ?>">
    <?php if ($is_admin): ?>
      <div class="dashboard-shell">
        <aside class="dashboard-side-nav" aria-label="Navegaci&oacute;n del dashboard">
          <div class="dashboard-side-nav-section">
            <button class="dashboard-side-nav-item btn-dashboard-main-view" type="button" data-dashboard-main-view="dashboard">
              <i class="bi bi-grid-1x2"></i><span>Dashboard</span>
            </button>
            <button class="dashboard-side-nav-item btn-dashboard-main-view <?= $can_access_agenda ? '' : 'd-none' ?>" type="button" data-dashboard-main-view="agenda">
              <i class="bi bi-calendar3"></i><span>Agenda</span>
            </button>
            <button class="dashboard-side-nav-item btn-dashboard-main-view <?= $can_access_patients ? '' : 'd-none' ?>" type="button" data-dashboard-main-view="patients">
              <i class="bi bi-people"></i><span><?= htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') ?></span>
            </button>
            <button class="dashboard-side-nav-item btn-dashboard-main-view <?= $can_access_appointments ? '' : 'd-none' ?>" type="button" data-dashboard-main-view="upcoming">
              <i class="bi bi-list-check"></i><span>Citas</span>
            </button>
          </div>
          <div class="dashboard-side-nav-section">
            <div class="dashboard-side-nav-label">Herramientas</div>
            <?php if ($knowledge_base_enabled && !$body_map_enabled && $can_access_patients): ?>
              <button class="dashboard-side-nav-item btn-dashboard-main-view" type="button" data-dashboard-main-view="knowledge"><i class="bi bi-journal-medical"></i><span>Base Conocimiento</span></button>
            <?php endif; ?>
            <?php if ($questionnaires_plan_enabled && $can_access_settings): ?>
              <button class="dashboard-side-nav-item btn-dashboard-main-view" type="button" data-dashboard-main-view="questionnaires"><i class="bi bi-ui-checks"></i><span>Cuestionarios</span></button>
            <?php endif; ?>
            <?php if ($can_access_settings): ?>
              <button class="dashboard-side-nav-item btn-dashboard-main-view" type="button" data-dashboard-main-view="configuration"><i class="bi bi-gear"></i><span>Configuraci&oacute;n</span></button>
            <?php endif; ?>
            <button class="dashboard-side-nav-item <?= $can_create_patients ? ($invitations_feature_enabled ? '' : 'plan-locked') : 'd-none' ?>" id="btn-sidebar-generate-invite" type="button"
              <?= $invitations_feature_enabled ? 'data-dashboard-action="invite"' : 'disabled aria-disabled="true" title="Disponible en un plan superior"' ?>>
              <i class="bi bi-link-45deg"></i><span>Invitaci&oacute;n</span><?= $invitations_feature_enabled ? '' : '<i class="bi bi-lock-fill plan-lock-icon"></i>' ?>
            </button>
            <button class="dashboard-side-nav-item <?= $can_access_statistics ? ($statistics_feature_enabled ? '' : 'plan-locked') : 'd-none' ?>" id="btn-sidebar-admin-stats" type="button"
              <?= $statistics_feature_enabled ? 'data-dashboard-action="stats"' : 'disabled aria-disabled="true" title="Disponible en un plan superior"' ?>>
              <i class="bi bi-bar-chart"></i><span>Estad&iacute;sticas</span><?= $statistics_feature_enabled ? '' : '<i class="bi bi-lock-fill plan-lock-icon"></i>' ?>
            </button>
            <button class="dashboard-side-nav-item <?= $bonuses_feature_enabled ? '' : 'plan-locked' ?>" id="btn-sidebar-admin-bonuses" type="button"
              <?= $bonuses_feature_enabled ? 'data-dashboard-action="bonuses"' : 'disabled aria-disabled="true" title="Disponible en un plan superior"' ?>>
              <i class="bi bi-card-list"></i><span>Bonos</span><?= $bonuses_feature_enabled ? '' : '<i class="bi bi-lock-fill plan-lock-icon"></i>' ?>
            </button>
            <button class="dashboard-side-nav-item <?= $can_access_billing ? ($billing_plan_enabled ? '' : 'plan-locked') : 'd-none' ?>" id="btn-sidebar-admin-invoices" type="button"
              <?= $billing_plan_enabled ? 'data-dashboard-action="invoices" data-bs-toggle="modal" data-bs-target="#invoicesModal"' : 'disabled title="Disponible en un plan superior"' ?>>
              <i class="bi bi-receipt"></i><span>Facturas</span><?= $billing_plan_enabled ? '' : '<i class="bi bi-lock-fill plan-lock-icon"></i>' ?>
            </button>
            <button class="dashboard-side-nav-item <?= $time_tracking_plan_enabled ? ($time_tracking_enabled ? '' : 'd-none') : 'plan-locked' ?>" id="btn-sidebar-time-tracking" type="button"
              <?= $time_tracking_plan_enabled ? 'data-bs-toggle="modal" data-bs-target="#timeTrackingHistoryModal"' : 'disabled title="Disponible en el plan Summum"' ?>>
              <i class="bi bi-stopwatch"></i><span>Control horario</span><?= $time_tracking_plan_enabled ? '' : '<i class="bi bi-lock-fill plan-lock-icon"></i>' ?>
            </button>
            <?php if ($is_superadmin): ?>
              <button class="dashboard-side-nav-item" id="btn-sidebar-admin-log" type="button" data-dashboard-action="app-log">
                <i class="bi bi-activity"></i><span>Log</span>
              </button>
            <?php endif; ?>
          </div>
        </aside>
        <main class="dashboard-shell-main">
    <?php endif; ?>

    <?php if ($is_admin && is_array($google_oauth_flash)): ?>
      <?php
        $google_oauth_status = ($google_oauth_flash['status'] ?? '') === 'success' ? 'success' : 'danger';
        $google_oauth_message = trim((string) ($google_oauth_flash['message'] ?? ''));
      ?>
      <div class="alert alert-<?= htmlspecialchars($google_oauth_status, ENT_QUOTES, 'UTF-8') ?> mb-3 google-oauth-flash-alert">
        <?= htmlspecialchars($google_oauth_message !== '' ? $google_oauth_message : 'Google ha devuelto una respuesta sin detalle.', ENT_QUOTES, 'UTF-8') ?>
      </div>
      <script>
        setTimeout(function () {
          document.querySelectorAll('.google-oauth-flash-alert').forEach(function (alert) {
            alert.style.transition = 'opacity .2s ease';
            alert.style.opacity = '0';
            setTimeout(function () { alert.remove(); }, 220);
          });
        }, 3000);
      </script>
    <?php endif; ?>
    <?php if ($is_admin && is_array($microsoft_oauth_flash)): ?>
      <div class="alert alert-<?= ($microsoft_oauth_flash['status'] ?? '') === 'success' ? 'success' : 'danger' ?> mb-3 google-oauth-flash-alert">
        <?= htmlspecialchars($microsoft_oauth_flash['message'] ?? 'Microsoft ha devuelto una respuesta sin detalle.', ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <?php if ($is_admin): ?>
      <div class="mb-4 d-flex gap-2 flex-wrap align-items-center dashboard-actions-bar dashboard-actions-admin">
        <div class="dashboard-action-buttons d-flex gap-2 flex-wrap align-items-center">
        <button class="btn btn-primary <?= $can_create_patients ? '' : 'd-none' ?>" id="btn-generate-invite"><i class="bi bi-link-45deg"></i> Generar
          Invitación</button>
        <button class="btn btn-primary <?= $can_access_appointments ? '' : 'd-none' ?>" id="btn-upcoming-appointments" type="button"><i class="bi bi-list-check"></i> Pr&oacute;ximas citas</button>
          <button class="btn btn-primary <?= $can_access_statistics ? '' : 'd-none' ?>" id="btn-admin-stats" type="button"><i class="bi bi-bar-chart"></i> Estad&iacute;sticas</button>
          <button class="btn btn-primary" id="btn-admin-bonuses" type="button"><i class="bi bi-card-list"></i> Bonos</button>
          <button class="btn btn-primary <?= $billing_plan_enabled ? ($can_access_billing ? '' : 'd-none') : 'plan-locked' ?>" id="btn-admin-invoices" type="button"
            <?= $billing_plan_enabled ? 'data-bs-toggle="modal" data-bs-target="#invoicesModal"' : 'disabled title="Disponible en un plan superior"' ?>><i class="bi bi-receipt"></i> Facturas<?= $billing_plan_enabled ? '' : ' <i class="bi bi-lock-fill"></i>' ?></button>
          <button class="btn btn-primary <?= $time_tracking_enabled ? '' : 'd-none' ?>" id="btn-time-tracking" type="button" data-bs-toggle="modal" data-bs-target="#timeTrackingModal"><i class="bi bi-stopwatch"></i> Fichar</button>
        <button class="btn btn-primary <?= $can_access_patients ? '' : 'd-none' ?>" id="btn-admin-patients" type="button"><i class="bi bi-people"></i> <?= $is_superadmin ? htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') : 'Mis ' . htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?></button>
        </div>
        <div class="dropdown dashboard-mobile-menu">
          <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-list"></i> Men&uacute;
          </button>
          <ul class="dropdown-menu">
            <li><button class="dropdown-item btn-dashboard-main-view" type="button" data-dashboard-main-view="dashboard"><i class="bi bi-grid-1x2 me-2"></i>Dashboard</button></li>
            <li class="<?= $can_create_patients ? '' : 'd-none' ?>"><button class="dropdown-item" type="button" id="btn-mobile-generate-invite"><i class="bi bi-link-45deg me-2"></i>Generar invitaci&oacute;n</button></li>
            <li class="<?= $can_access_appointments ? '' : 'd-none' ?>"><button class="dropdown-item" type="button" id="btn-mobile-upcoming-appointments"><i class="bi bi-list-check me-2"></i>Pr&oacute;ximas citas</button></li>
            <li class="<?= $can_access_statistics ? '' : 'd-none' ?>"><button class="dropdown-item" type="button" id="btn-mobile-admin-stats"><i class="bi bi-bar-chart me-2"></i>Estad&iacute;sticas</button></li>
            <li><button class="dropdown-item" type="button" id="btn-mobile-admin-bonuses"><i class="bi bi-card-list me-2"></i>Bonos</button></li>
            <li class="<?= $billing_plan_enabled ? ($can_access_billing ? '' : 'd-none') : '' ?>"><button class="dropdown-item <?= $billing_plan_enabled ? '' : 'plan-locked' ?>" type="button" id="btn-mobile-admin-invoices"
              <?= $billing_plan_enabled ? 'data-bs-toggle="modal" data-bs-target="#invoicesModal"' : 'disabled title="Disponible en un plan superior"' ?>><i class="bi bi-receipt me-2"></i>Facturas<?= $billing_plan_enabled ? '' : '<i class="bi bi-lock-fill float-end"></i>' ?></button></li>
            <li class="<?= $time_tracking_enabled ? '' : 'd-none' ?>"><button class="dropdown-item" type="button" id="btn-mobile-time-tracking" data-bs-toggle="modal" data-bs-target="#timeTrackingHistoryModal"><i class="bi bi-stopwatch me-2"></i>Control horario</button></li>
            <li class="<?= $can_access_patients ? '' : 'd-none' ?>"><button class="dropdown-item" type="button" id="btn-mobile-admin-patients"><i class="bi bi-people me-2"></i><?= $is_superadmin ? htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') : 'Mis ' . htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?></button></li>
            <?php if ($knowledge_base_enabled && !$body_map_enabled && $can_access_patients): ?><li><button class="dropdown-item btn-dashboard-main-view" type="button" data-dashboard-main-view="knowledge"><i class="bi bi-journal-medical me-2"></i>Base de Conocimiento</button></li><?php endif; ?>
            <?php if ($questionnaires_plan_enabled && $can_access_settings): ?><li><button class="dropdown-item btn-dashboard-main-view" type="button" data-dashboard-main-view="questionnaires"><i class="bi bi-ui-checks me-2"></i>Cuestionarios</button></li><?php endif; ?>
            <?php if ($can_access_settings): ?><li><button class="dropdown-item btn-dashboard-main-view" type="button" data-dashboard-main-view="configuration"><i class="bi bi-gear me-2"></i>Configuraci&oacute;n</button></li><?php endif; ?>
          </ul>
        </div>
        <span id="admin-actions-msg" class="align-self-center ms-2 text-success" style="display: none;"></span>
        <div class="btn-group ms-auto dashboard-view-switcher" id="admin-dashboard-view-switcher" role="group" aria-label="Vista del dashboard">
          <button class="btn btn-outline-primary btn-dashboard-view <?= $can_access_agenda ? '' : 'd-none' ?>" type="button" data-dashboard-view="month"><i class="bi bi-calendar3"></i> Mes</button>
          <button class="btn btn-outline-primary btn-dashboard-view <?= $can_access_agenda ? '' : 'd-none' ?>" type="button" data-dashboard-view="week"><i class="bi bi-calendar-week"></i> Semana</button>
          <button class="btn btn-outline-primary btn-dashboard-view d-none <?= $can_access_agenda ? 'd-lg-inline-flex' : '' ?>" type="button" data-dashboard-view="agenda"><i class="bi bi-layout-three-columns"></i> Agenda</button>
        </div>
      </div>
    <?php else: ?>
      <?php if ($questionnaires_plan_enabled): ?><div id="portal-questionnaire-dashboard-alert" class="alert alert-warning small mb-3 d-none"></div><?php endif; ?>
      <div class="mb-4 d-flex gap-2 flex-wrap align-items-center dashboard-actions-bar" id="patient-bonus-actions">
        <div class="dashboard-action-buttons d-flex gap-2 flex-wrap align-items-center">
          <button class="btn btn-primary" id="btn-patient-portal-appointments" type="button"><i class="bi bi-calendar-check"></i> Mis citas</button>
          <button class="btn btn-primary" id="btn-patient-portal-tasks" type="button"><i class="bi bi-list-check"></i> Mis tareas</button>
          <button class="btn btn-primary" id="btn-patient-portal-documents" type="button"><i class="bi bi-folder2-open"></i> Mis documentos</button>
          <?php if ($questionnaires_plan_enabled): ?><button class="btn btn-primary" id="btn-patient-portal-questionnaires" type="button"><i class="bi bi-ui-checks"></i> Mis cuestionarios</button><?php endif; ?>
          <button class="btn btn-primary" id="btn-patient-portal-reports" type="button"><i class="bi bi-file-earmark-text"></i> Mis informes</button>
          <?php if ($physical_metrics_available): ?>
            <button class="btn btn-primary" id="btn-patient-portal-composition" type="button"><i class="bi bi-activity"></i> Mi progreso</button>
          <?php endif; ?>
          <button class="btn btn-primary" id="btn-buy-bonus" type="button" style="display: none;"><i class="bi bi-bag-check"></i> Comprar bono</button>
          <button class="btn btn-primary" id="btn-my-bonuses" type="button" style="display: none;"><i class="bi bi-card-list"></i> Mis bonos</button>
        </div>
        <div class="dropdown dashboard-mobile-menu">
          <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-list"></i> Men&uacute;
          </button>
          <ul class="dropdown-menu">
            <li><button class="dropdown-item" type="button" id="btn-mobile-patient-portal-appointments"><i class="bi bi-calendar-check me-2"></i>Mis citas</button></li>
            <li><button class="dropdown-item" type="button" id="btn-mobile-patient-portal-tasks"><i class="bi bi-list-check me-2"></i>Mis tareas</button></li>
            <li><button class="dropdown-item" type="button" id="btn-mobile-patient-portal-documents"><i class="bi bi-folder2-open me-2"></i>Mis documentos</button></li>
            <li><button class="dropdown-item" type="button" id="btn-mobile-patient-portal-reports"><i class="bi bi-file-earmark-text me-2"></i>Mis informes</button></li>
            <?php if ($physical_metrics_available): ?>
              <li><button class="dropdown-item" type="button" id="btn-mobile-patient-portal-composition"><i class="bi bi-activity me-2"></i>Mi progreso</button></li>
            <?php endif; ?>
            <li style="display: none;"><button class="dropdown-item" type="button" id="btn-mobile-buy-bonus"><i class="bi bi-bag-check me-2"></i>Comprar bono</button></li>
            <li style="display: none;"><button class="dropdown-item" type="button" id="btn-mobile-my-bonuses"><i class="bi bi-card-list me-2"></i>Mis bonos</button></li>
          </ul>
        </div>
        <button class="btn btn-primary ms-auto" id="btn-calendar-view-toggle" type="button"><i class="bi bi-calendar3"></i> Ver mes</button>
      </div>
    <?php endif; ?>
    <?php if (!$is_admin): ?>
      <div id="patient-pending-consents-alert" class="alert alert-warning d-none align-items-center justify-content-between gap-3 mb-3" role="alert">
        <span><i class="bi bi-pen me-2"></i>Tienes documentos pendientes de firma/aceptaci&oacute;n.</span>
        <button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0" id="btn-open-patient-pending-consents"
                data-bs-toggle="modal" data-bs-target="#handwrittenConsentModal">Revisar y firmar</button>
      </div>
      <div id="patient-quick-appointment-summary" class="quick-appointments-summary d-none"></div>
    <?php endif; ?>
    <div id="patient-professional-choice" class="patient-professional-choice d-none"></div>
    <div id="patient-professional-context" class="booking-professional-context d-none"></div>
    <?php if ($is_admin): ?>
      <div id="quick-appointments-summary" class="quick-appointments-summary d-none"></div>
      <div id="quick-booking-slot-alert" class="alert alert-info small d-none align-items-center justify-content-between gap-3 mb-3" role="status">
        <span id="quick-booking-slot-message"></span>
        <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" id="btn-cancel-quick-booking">Cancelar</button>
      </div>
    <?php endif; ?>

    <div id="calendar-container">
      <div class="text-center text-muted py-5">
        <div class="spinner-border text-secondary" role="status"></div><br>Cargando. Espera...
      </div>
    </div>
    <?php if ($is_admin): ?>
        </main>
      </div>
    <?php endif; ?>
  </div>

  <div class="modal fade" id="appointmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content" style="border-radius: 12px;">
        <div class="modal-header border-0">
          <h5 class="modal-title" id="modalTitle">Gestión de Cita</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body py-4 text-center">
          <p id="modalDesc" class="mb-4">¿Qué deseas hacer?</p>
          <div id="modal-professional-context" class="booking-professional-context booking-professional-context-modal d-none"></div>
          <input type="hidden" id="modalDate">
          <input type="hidden" id="modalTime">
          <input type="hidden" id="modalStatus">

          <?php if ($is_admin): ?>
            <div id="adminPatientSelect" class="mb-3 d-none">
              <select id="patientSelect" class="form-select">
                <option value="">Selecciona un <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>...</option>
              </select>
            </div>
          <?php endif; ?>

          <?php if ($is_superadmin): ?>
            <div id="adminProfessionalSelect" class="mb-3 d-none text-start">
              <label class="form-label">Profesional</label>
              <select id="booking-professional" class="form-select d-none">
                <option value="">Selecciona un profesional...</option>
              </select>
              <div id="booking-professional-cards" class="booking-professional-card-grid"></div>
              <div class="form-text" id="booking-patient-professional-note"></div>
            </div>
          <?php endif; ?>

          <div id="patientSlotProfessionalSelect" class="mb-3 d-none text-start"></div>

          <div id="consultationTypeSelect" class="mb-3 d-none text-start">
            <label class="form-label" for="consultation-type">Modalidad de la cita</label>
            <select id="consultation-type" class="form-select">
              <option value="presencial">Presencial</option>
              <option value="online">Online</option>
            </select>
          </div>

          <div id="serviceTypeSelect" class="mb-3 d-none text-start">
            <label class="form-label" for="service-type">Tipo de sesión</label>
            <select id="service-type" class="form-select">
              <option value="individual">Individual</option>
              <option value="couple">Pareja</option>
            </select>
          </div>

          <div id="bookingConsultationSelect" class="mb-3 d-none text-start">
            <label class="form-label">Modalidad</label>
            <div id="booking-consultation-cards" class="booking-consultation-card-grid"></div>
          </div>

          <div id="serviceOptionSelect" class="mb-3 d-none text-start">
            <label class="form-label" for="service-option">Servicio</label>
            <select id="service-option" class="form-select"></select>
            <div id="booking-discount-price" class="small mt-2 d-none"></div>
          </div>

          <div id="booking-bonus-notice" class="alert alert-success py-2 d-none text-start small"></div>

          <button class="btn btn-primary px-4" id="btn-confirm-action">Confirmar</button>
          <?php if (!$is_admin): ?>
            <div id="payment-options" class="mt-3 d-none">
              <div class="small text-muted mb-2" id="payment-options-text"></div>
              <div class="d-flex gap-2 justify-content-center flex-wrap">
                <button class="btn btn-success btn-sm" id="btn-pay-card" type="button">
                  <i class="bi bi-credit-card"></i> Pagar con tarjeta
                </button>
                <button class="btn btn-success btn-sm" id="btn-pay-bizum" type="button">
                  <i class="bi bi-phone"></i> Pagar con Bizum
                </button>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if ($is_admin): ?>
    <div class="modal fade" id="appointmentPaymentModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="appointment-payment-modal-title">Detalle de la cita</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="appointment-payment-alert" class="alert d-none"></div>
            <input type="hidden" id="appointment-payment-id">
            <ul class="nav nav-tabs mb-3" id="appointment-detail-tabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="appointment-detail-tab" data-bs-toggle="tab" data-bs-target="#appointment-detail-panel" type="button" role="tab">Detalle</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="appointment-session-tab" data-bs-toggle="tab" data-bs-target="#appointment-session-panel" type="button" role="tab">Tareas</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="appointment-private-notes-tab" data-bs-toggle="tab" data-bs-target="#appointment-private-notes-panel" type="button" role="tab">Notas de la sesi&oacute;n</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link <?= $document_uploads_enabled ? '' : 'plan-locked' ?>" id="appointment-files-tab" data-bs-toggle="tab" data-bs-target="#appointment-files-panel" type="button" role="tab"
                  <?= $document_uploads_enabled ? '' : 'disabled aria-disabled="true" title="Disponible en un plan superior"' ?>>Archivos<?= $document_uploads_enabled ? '' : ' <i class="bi bi-lock-fill ms-1"></i>' ?></button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link <?= $questionnaires_plan_enabled ? '' : 'plan-locked' ?>" id="appointment-questionnaires-tab" type="button" role="tab"
                  <?= $questionnaires_plan_enabled ? 'data-bs-toggle="tab" data-bs-target="#appointment-questionnaires-panel"' : 'disabled title="Disponible en el plan Summum"' ?>>Cuestionarios<?= $questionnaires_plan_enabled ? '' : ' <i class="bi bi-lock-fill ms-1"></i>' ?></button>
              </li>
            </ul>
            <div class="tab-content">
              <div class="tab-pane fade show active" id="appointment-detail-panel" role="tabpanel" aria-labelledby="appointment-detail-tab">
                <div id="appointment-payment-summary" class="appointment-payment-summary mb-4">
                  <div class="text-center text-muted py-4">Cargando cita...</div>
                </div>
                <div id="appointment-status-editor" class="appointment-status-editor mb-4 d-none">
                  <label class="form-label">Estado de asistencia</label>
                  <div class="btn-group appointment-status-group" role="group" aria-label="Estado de asistencia">
                    <button type="button" class="btn btn-outline-primary appointment-status-btn" data-appointment-status="booked">
                      <i class="bi bi-calendar-check"></i> Reservada
                    </button>
                    <button type="button" class="btn btn-outline-primary appointment-status-btn" data-appointment-status="completed">
                      <i class="bi bi-check2-circle"></i> Realizada
                    </button>
                    <button type="button" class="btn btn-outline-warning appointment-status-btn" data-appointment-status="no_show">
                      <i class="bi bi-person-x"></i> No asisti&oacute;
                    </button>
                  </div>
                </div>
                <div id="appointment-payment-editor" class="appointment-payment-editor">
                  <div class="row g-3">
                    <div class="col-md-6">
                      <button type="button" class="payment-state-card" data-payment-status="pending">
                        <span class="payment-state-icon payment-state-pending"><i class="bi bi-hourglass-split"></i></span>
                        <strong>Pendiente</strong>
                      </button>
                    </div>
                    <div class="col-md-6">
                      <button type="button" class="payment-state-card" data-payment-status="paid">
                        <span class="payment-state-icon payment-state-paid"><i class="bi bi-check2-circle"></i></span>
                        <strong>Pagada</strong>
                      </button>
                    </div>
                  </div>
                  <div class="mt-3" id="appointment-payment-method-wrap">
                    <label class="form-label" for="appointment-payment-method">Forma de pago</label>
                    <select class="form-select" id="appointment-payment-method">
                      <option value="cash">Efectivo</option>
                      <option value="bank_transfer">Transferencia</option>
                      <option value="card">Tarjeta online</option>
                      <option value="bizum">Bizum online</option>
                      <option value="other">Otro m&eacute;todo</option>
                      <option value="manual">Manual</option>
                    </select>
                  </div>
                </div>
              </div>
              <div class="tab-pane fade" id="appointment-session-panel" role="tabpanel" aria-labelledby="appointment-session-tab">
                <div id="appointment-session-alert" class="alert d-none"></div>
                <div id="appointment-session-content">
                  <div class="text-center text-muted py-4">Cargando sesi&oacute;n...</div>
                </div>
              </div>
              <div class="tab-pane fade" id="appointment-private-notes-panel" role="tabpanel" aria-labelledby="appointment-private-notes-tab">
                <div id="appointment-private-notes-alert" class="alert d-none"></div>
                <div class="alert alert-info small mb-3">
                  Nota privada, no visible por el paciente. Disponible solo en los informes internos.
                </div>
                <label class="form-label" for="appointment-private-session-notes">Notas de la sesi&oacute;n</label>
                <textarea class="form-control" id="appointment-private-session-notes" rows="10" placeholder="Notas, apuntes o informaci&oacute;n interna de esta cita..."></textarea>
              </div>
              <div class="tab-pane fade" id="appointment-files-panel" role="tabpanel" aria-labelledby="appointment-files-tab">
                <div id="appointment-files-alert" class="alert d-none"></div>
                <div id="appointment-files-content">
                  <div class="text-center text-muted py-4">Cargando archivos...</div>
                </div>
              </div>
              <div class="tab-pane fade" id="appointment-questionnaires-panel" role="tabpanel" aria-labelledby="appointment-questionnaires-tab">
                <div class="patient-questionnaires-host" data-questionnaire-context="appointment"><div class="text-center text-muted py-4">Abre esta pesta&ntilde;a para cargar los cuestionarios.</div></div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-danger me-auto" id="btn-cancel-appointment-from-detail">Cancelar cita</button>
            <div class="dropdown" id="appointment-reminder-dropdown">
              <button class="btn <?= ($email_reminders_plan_enabled || $sms_reminders_plan_enabled) ? 'btn-outline-primary dropdown-toggle' : 'btn-secondary plan-locked' ?>" type="button" id="btn-appointment-reminder-menu"
                <?= ($email_reminders_plan_enabled || $sms_reminders_plan_enabled) ? 'data-bs-toggle="dropdown" aria-expanded="false"' : 'disabled aria-disabled="true" title="Disponible en los planes Magister y Summum"' ?>>
                <i class="bi <?= ($email_reminders_plan_enabled || $sms_reminders_plan_enabled) ? 'bi-send' : 'bi-lock-fill' ?>"></i> Enviar recordatorio
              </button>
              <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="btn-appointment-reminder-menu">
                <li class="<?= $email_reminders_plan_enabled ? '' : 'd-none' ?>"><button class="dropdown-item btn-send-manual-appointment-reminder" type="button" data-channel="email" data-configured="<?= $email_reminders_configured ? '1' : '0' ?>" <?= $email_reminders_configured ? '' : 'disabled aria-disabled="true" title="Configura primero el envío de emails"' ?>><i class="bi bi-envelope me-2"></i>Email</button></li>
                <li class="<?= $sms_reminders_plan_enabled ? '' : 'd-none' ?>"><button class="dropdown-item btn-send-manual-appointment-reminder" type="button" data-channel="sms" data-configured="<?= $sms_reminders_configured ? '1' : '0' ?>" <?= $sms_reminders_configured ? '' : 'disabled aria-disabled="true" title="Configura primero el envío de SMS"' ?>><i class="bi bi-chat-left-text me-2"></i>SMS</button></li>
              </ul>
            </div>
            <button type="button" class="btn btn-outline-secondary" id="btn-open-patient-from-appointment-detail">
              <i class="bi bi-person-lines-fill"></i> Ver ficha del <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="btn btn-outline-primary" id="btn-book-another-appointment">
              <i class="bi bi-calendar-plus"></i> Reservar otra cita
            </button>
            <button type="button" class="btn btn-primary" id="btn-save-appointment-payment">Guardar cambios</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="appointmentSessionNoteModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <form id="appointment-session-note-form" enctype="multipart/form-data">
            <div class="modal-header">
              <h5 class="modal-title">Nueva nota / archivo</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" name="note_id" value="0">
              <input type="hidden" name="appointment_id" id="appointment-session-note-appointment-id" value="0">
              <input type="hidden" name="patient_id" id="appointment-session-note-patient-id" value="0">
              <input type="hidden" name="note_date" id="appointment-session-note-date" value="">
              <div class="row g-3">
                <div class="col-md-8">
                  <label class="form-label" for="appointment-session-note-title">T&iacute;tulo</label>
                  <input type="text" class="form-control" id="appointment-session-note-title" name="title" maxlength="180" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="appointment-session-note-files">Archivo</label>
                  <input type="file" class="form-control" id="appointment-session-note-files" name="evolution_files[]" accept=".pdf,.xls,.xlsx,image/jpeg,image/png,image/webp,image/gif" multiple
                    <?= $document_uploads_enabled ? '' : 'disabled title="La subida de adjuntos está disponible en un plan superior"' ?>>
                </div>
                <div class="col-12">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="appointment-session-note-visible-to-patient" name="visible_to_patient" value="1">
                    <label class="form-check-label" for="appointment-session-note-visible-to-patient">
                      Disponible en el portal del <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>
                    </label>
                  </div>
                </div>
                <div class="col-12">
                  <label class="form-label" for="appointment-session-note-description">Descripci&oacute;n</label>
                  <textarea class="form-control" id="appointment-session-note-description" name="description" rows="3"></textarea>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button class="btn btn-primary" type="submit" id="btn-save-appointment-session-note">
                <i class="bi bi-journal-plus"></i> Guardar nota
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade" id="manualInvoiceConfirmModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Emitir factura</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-warning small mb-3">
              Al confirmar este cobro se emitir&aacute; la factura correspondiente. Una vez emitida, no se podr&aacute;n modificar los datos econ&oacute;micos de este registro.
            </div>
            <p class="mb-0" id="manual-invoice-confirm-text">
              Si cancelas, no se marcar&aacute; como pagado y quedar&aacute; pendiente.
            </p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btn-confirm-manual-invoice">Emitir factura y marcar como pagado</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="globalSearchModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Buscar</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="input-group mb-3">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input type="search" class="form-control" id="global-search-input" placeholder="Buscar <?= htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?>, citas, archivos, tareas..." autocomplete="off">
            </div>
            <div id="global-search-results" class="global-search-results">
              <div class="text-center text-muted py-4">Escribe al menos 2 caracteres para buscar.</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="inviteModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Invitaci&oacute;n de registro</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="invite-modal-alert" class="alert d-none"></div>
            <div class="alert alert-info small">
              Env&iacute;a este enlace a tu <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> o p&iacute;dele que escanee el C&oacute;digo QR. Con &eacute;l acceder&aacute; al formulario de registro, donde podr&aacute; darse de alta para acceder al Portal de <?= htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') ?>.
            </div>
            <label class="form-label" for="invite-link">Enlace generado</label>
            <div class="input-group mb-3">
              <input type="text" class="form-control" id="invite-link" readonly>
              <button class="btn btn-outline-primary" type="button" id="btn-copy-invite-link">
                <i class="bi bi-clipboard"></i>
              </button>
            </div>
            <div class="invite-qr-wrap mb-4">
              <div id="invite-qr"></div>
            </div>
            <label class="form-label" for="invite-email">Enviar por email a...</label>
            <div class="input-group">
              <input type="email" class="form-control" id="invite-email" placeholder="email@ejemplo.com" autocomplete="off">
              <button class="btn btn-primary" type="button" id="btn-send-invite-email">Enviar</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="postCreatePatientInviteModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Invitar al Portal de <?= htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="post-create-patient-invite-alert" class="alert d-none"></div>
            <div class="alert alert-info small mb-3">
              El nuevo <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> todavía no tiene acceso al Portal. Puedes enviarle ahora una invitación para que cree su contraseña.
            </div>
            <p class="mb-1 fw-semibold" id="post-create-patient-invite-name"></p>
            <p class="mb-0 text-muted" id="post-create-patient-invite-email"></p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Ahora no</button>
            <button type="button" class="btn btn-primary" id="btn-send-post-create-patient-invite">
              <i class="bi bi-envelope"></i> Enviar invitación
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="adminPatientsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><?= $is_superadmin ? htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') : 'Mis ' . htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?></h5>
            <div class="d-flex align-items-center gap-2 ms-auto">
              <button class="btn btn-sm btn-outline-secondary btn-export-modal-table" type="button" data-table-target="#adminPatientsModal" data-export-type="print" title="Imprimir"><i class="bi bi-printer"></i></button>
              <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" title="Exportar pacientes"><i class="bi bi-download"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li><button class="dropdown-item btn-server-export" type="button" data-export-entity="patients" data-export-format="json"><i class="bi bi-braces me-2"></i>JSON</button></li>
                  <li><button class="dropdown-item btn-server-export" type="button" data-export-entity="patients" data-export-format="xlsx"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Excel</button></li>
                </ul>
              </div>
              <button type="button" class="btn-close m-0" data-bs-dismiss="modal"></button>
            </div>
          </div>
          <div class="modal-body">
            <div id="admin-patients-alert" class="alert d-none"></div>
            <div class="d-flex justify-content-end align-items-center gap-2 mb-3 flex-wrap">
              <button class="btn btn-primary btn-sm <?= $can_create_patients ? '' : 'd-none' ?>" type="button" id="btn-new-patient"><i class="bi bi-person-plus"></i> Nuevo <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?></button>
            </div>
            <div class="row g-2 mb-3">
              <div class="<?= $is_superadmin ? 'col-md-5' : 'col-md-8' ?>">
                <input type="search" class="form-control" id="admin-patients-search" placeholder="<?= $is_superadmin ? 'Buscar por nombre, email, telefono, tipo o profesional' : 'Buscar por nombre, email, telefono o tipo' ?>">
              </div>
              <?php if ($is_superadmin): ?>
                <div class="col-md-3">
                  <select class="form-select" id="admin-patients-professional">
                    <option value="">Cargando profesionales...</option>
                  </select>
                </div>
              <?php endif; ?>
              <div class="col-md-4">
                <select class="form-select" id="admin-patients-sort">
                  <option value="name_asc">Ordenar por nombre A-Z</option>
                  <option value="name_desc">Ordenar por nombre Z-A</option>
                  <option value="admission_desc">Alta mas reciente</option>
                  <option value="admission_asc">Alta mas antigua</option>
                </select>
              </div>
            </div>
            <div class="table-responsive admin-patients-table-wrap">
              <table class="table align-middle">
                <thead>
                  <tr>
                    <th><?= htmlspecialchars($patient_label_title_singular, ENT_QUOTES, 'UTF-8') ?></th>
                    <?php if ($is_superadmin): ?>
                      <th>Profesional</th>
                    <?php endif; ?>
                    <th>Contacto</th>
                    <th>Tipo</th>
                    <th>Alta</th>
                    <th class="text-center">Portal</th>
                    <th>Documento</th>
                    <th class="text-center">Obs.</th>
                    <th class="text-end no-export">Acciones</th>
                  </tr>
                </thead>
                <tbody id="admin-patients-body">
                  <tr><td colspan="<?= $is_superadmin ? 9 : 8 ?>" class="text-center text-muted py-4">Cargando...</td></tr>
                </tbody>
              </table>
            </div>
            <div class="text-end text-muted small mt-2" id="admin-patients-count"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="patientEditorModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="patient-editor-title"><?= htmlspecialchars($patient_label_title_singular, ENT_QUOTES, 'UTF-8') ?></h5>
            <div class="d-flex align-items-center gap-2 ms-auto">
              <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="btn-export-patient-json" title="Exportar ficha en JSON"><i class="bi bi-download"></i></button>
              <button type="button" class="btn-close m-0" data-bs-dismiss="modal"></button>
            </div>
          </div>
          <div class="modal-body">
            <div id="patient-editor-alert" class="alert d-none"></div>
            <ul class="nav nav-tabs mb-4" id="patient-editor-tabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="patient-data-tab" data-bs-toggle="tab" data-bs-target="#patient-data-panel" type="button" role="tab">Datos del <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?></button>
              </li>
              <li class="nav-item <?= $can_access_private_patient_data ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link" id="patient-more-data-tab" data-bs-toggle="tab" data-bs-target="#patient-more-data-panel" type="button" role="tab">M&aacute;s datos</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="patient-contacts-tab" data-bs-toggle="tab" data-bs-target="#patient-contacts-panel" type="button" role="tab">Contactos asociados</button>
              </li>
              <li class="nav-item <?= !empty($initial_billing_settings['billing_enabled']) && $can_access_billing ? '' : 'd-none' ?>" role="presentation" id="patient-billing-tab-item">
                <button class="nav-link" id="patient-billing-data-tab" data-bs-toggle="tab" data-bs-target="#patient-billing-data-panel" type="button" role="tab">Datos Facturaci&oacute;n</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link <?= $plan_key === 'initium' ? 'plan-locked' : '' ?>" id="patient-legal-documents-tab" data-bs-toggle="tab" data-bs-target="#patient-legal-documents-panel" type="button" role="tab"
                  <?= $plan_key === 'initium' ? 'disabled aria-disabled="true" title="Disponible en un plan superior"' : '' ?>>Consentimientos<?= $plan_key === 'initium' ? ' <i class="bi bi-lock-fill ms-1"></i>' : '' ?></button>
              </li>
              <?php if ($physical_metrics_enabled): ?>
                <li class="nav-item <?= $can_access_private_patient_data ? '' : 'd-none' ?>" role="presentation">
                  <button class="nav-link" id="patient-physical-tab" data-bs-toggle="tab" data-bs-target="#patient-physical-panel" type="button" role="tab">Composici&oacute;n</button>
                </li>
              <?php endif; ?>
              <li class="nav-item <?= $can_access_private_patient_data ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link" id="patient-diagnosis-tab" data-bs-toggle="tab" data-bs-target="#patient-diagnosis-panel" type="button" role="tab"><?= htmlspecialchars(ucfirst($sector_texts['clinicalTerms']['diagnosis'] ?? 'Diagnóstico')) ?></button>
              </li>
              <li class="nav-item <?= $can_access_private_patient_data ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link" id="patient-history-tab" data-bs-toggle="tab" data-bs-target="#patient-history-panel" type="button" role="tab">Historial de citas</button>
              </li>
              <li class="nav-item <?= $can_access_private_patient_data ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link" id="patient-work-plan-tab" data-bs-toggle="tab" data-bs-target="#patient-work-plan-panel" type="button" role="tab">Plan de trabajo</button>
              </li>
              <li class="nav-item <?= $can_access_private_patient_data ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link" id="patient-evolution-tab" data-bs-toggle="tab" data-bs-target="#patient-evolution-panel" type="button" role="tab">Evoluci&oacute;n</button>
              </li>
              <li class="nav-item <?= $can_access_private_patient_data ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link <?= $document_uploads_enabled ? '' : 'plan-locked' ?>" id="patient-files-tab" data-bs-toggle="tab" data-bs-target="#patient-files-panel" type="button" role="tab"
                  <?= $document_uploads_enabled ? '' : 'disabled aria-disabled="true" title="Disponible en un plan superior"' ?>>Documentaci&oacute;n<?= $document_uploads_enabled ? '' : ' <i class="bi bi-lock-fill ms-1"></i>' ?></button>
              </li>
              <li class="nav-item <?= $can_access_private_patient_data ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link <?= $questionnaires_plan_enabled ? '' : 'plan-locked' ?>" id="patient-questionnaires-tab" type="button" role="tab"
                  <?= $questionnaires_plan_enabled ? 'data-bs-toggle="tab" data-bs-target="#patient-questionnaires-panel"' : 'disabled title="Disponible en el plan Summum"' ?>>Cuestionarios<?= $questionnaires_plan_enabled ? '' : ' <i class="bi bi-lock-fill ms-1"></i>' ?></button>
              </li>
              <li class="nav-item <?= $can_access_reports ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link" id="patient-reports-tab" data-bs-toggle="tab" data-bs-target="#patient-reports-panel" type="button" role="tab">Informes</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="patient-bonuses-tab" data-bs-toggle="tab" data-bs-target="#patient-bonuses-panel" type="button" role="tab">Bonos</button>
              </li>
            </ul>
            <div class="tab-content">
              <div class="tab-pane fade show active" id="patient-data-panel" role="tabpanel" aria-labelledby="patient-data-tab">
                <form id="patient-editor-form">
                  <input type="hidden" id="patient-editor-id" name="patient_id">
                  <div class="row g-3">
                    <div class="col-md-6">
                      <label class="form-label" for="patient-editor-name">Nombre</label>
                      <input type="text" class="form-control" id="patient-editor-name" name="name" required>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label" for="patient-editor-type">Tipo</label>
                      <input type="text" class="form-control" id="patient-editor-type" name="patient_type" placeholder="<?= $patient_type_placeholder ?>">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label" for="patient-editor-fiscal-name">Nombre fiscal</label>
                      <input type="text" class="form-control" id="patient-editor-fiscal-name" name="fiscal_name" maxlength="180" placeholder="Nombre para facturas">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label" for="patient-editor-fiscal-nif">NIF / CIF</label>
                      <input type="text" class="form-control" id="patient-editor-fiscal-nif" name="fiscal_nif" maxlength="50" placeholder="NIF del destinatario">
                    </div>
                    <div class="col-12">
                      <label class="form-label" for="patient-editor-address">Domicilio</label>
                      <textarea class="form-control" id="patient-editor-address" name="address" form="patient-editor-form" rows="2" maxlength="255"></textarea>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label" for="patient-editor-email">Email</label>
                      <input type="email" class="form-control" id="patient-editor-email" name="email">
                    </div>
                    <div class="col-md-6 <?= $can_view_patient_phone ? '' : 'd-none' ?>">
                      <label class="form-label" for="patient-editor-phone">Tel&eacute;fono</label>
                      <input type="text" class="form-control" id="patient-editor-phone" name="phone">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label" for="patient-editor-photo">Foto</label>
                      <input type="file" class="form-control" id="patient-editor-photo" name="patient_photo" accept="image/jpeg,image/png,image/webp,image/gif">
                      <div class="d-flex align-items-center gap-3 mt-2">
                        <img src="" alt="" id="patient-editor-photo-preview" class="d-none" style="width: 56px; height: 56px; border-radius: 50%; object-fit: cover;">
                        <div class="form-text" id="patient-editor-photo-status">Formatos permitidos: JPG, PNG, WEBP o GIF. M&aacute;ximo 2 MB.</div>
                      </div>
                    </div>
                    <?php if ($is_superadmin && !in_array($plan_key, ['initium', 'novus'], true)): ?>
                      <div class="col-md-6">
                        <div id="patient-editor-professional-new-wrap">
                          <label class="form-label" for="patient-editor-professional">Asignar profesional</label>
                          <select class="form-select" id="patient-editor-professional" name="professional_id">
                            <option value="">Permitir elegir profesional al <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?></option>
                          </select>
                        </div>
                        <div id="patient-editor-professional-current-wrap" class="d-none">
                          <label class="form-label">Profesional asignado</label>
                          <div class="patient-transfer-box">
                            <div>
                              <strong id="patient-editor-current-professional">Sin profesional asignado</strong>
                              <div class="form-text" id="patient-editor-transfer-status">El traspaso solo puede hacerlo el superadmin.</div>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="btn-show-patient-transfer">
                              <i class="bi bi-arrow-left-right"></i> Traspasar <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>
                            </button>
                          </div>
                          <div id="patient-transfer-panel" class="patient-transfer-panel d-none">
                            <select class="form-select form-select-sm" id="patient-transfer-professional">
                              <option value="">Selecciona profesional...</option>
                            </select>
                            <button type="button" class="btn btn-primary btn-sm" id="btn-confirm-patient-transfer">
                              Confirmar
                            </button>
                          </div>
                        </div>
                      </div>
                    <?php endif; ?>
                    <div class="col-md-6">
                      <label class="form-label" for="patient-editor-admission-date">Fecha de alta</label>
                      <input type="date" class="form-control" id="patient-editor-admission-date" name="admission_date">
                    </div>
                    <div class="col-md-6 <?= $document_uploads_enabled ? '' : 'opacity-50' ?>">
                      <label class="form-label" for="patient-editor-document">Archivo PDF/Excel</label>
                      <input type="file" class="form-control" id="patient-editor-document" name="patient_document" accept=".pdf,.xls,.xlsx"
                        <?= $document_uploads_enabled ? '' : 'disabled title="Disponible en un plan superior"' ?>>
                      <div class="form-text" id="patient-editor-document-status"></div>
                    </div>
                    <div class="col-12">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="patient-editor-waiting-list" name="waiting_list" value="1" form="patient-editor-form">
                        <label class="form-check-label" for="patient-editor-waiting-list"><?= htmlspecialchars($patient_label_title_singular, ENT_QUOTES, 'UTF-8') ?> en lista de espera</label>
                      </div>
                    </div>
                  </div>
                </form>
              </div>
              <div class="tab-pane fade" id="patient-more-data-panel" role="tabpanel" aria-labelledby="patient-more-data-tab">
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label" for="patient-editor-status">Estado</label>
                    <select class="form-select" id="patient-editor-status" name="patient_status" form="patient-editor-form">
                      <option value="active">Activo</option>
                      <option value="paused">En pausa</option>
                      <option value="discharged">Alta</option>
                      <option value="inactive">Baja</option>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label" for="patient-editor-birth-date">Fecha de nacimiento</label>
                    <input type="date" class="form-control" id="patient-editor-birth-date" name="birth_date" form="patient-editor-form">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Edad</label>
                    <div class="form-control bg-light" id="patient-editor-age">-</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="patient-editor-referral-source">Fuente / derivaci&oacute;n</label>
                    <input type="text" class="form-control" id="patient-editor-referral-source" name="referral_source" form="patient-editor-form" placeholder="<?= $patient_referral_placeholder ?>">
                  </div>
                  <input type="hidden" id="patient-editor-emergency-name" name="emergency_contact_name" form="patient-editor-form">
                  <input type="hidden" id="patient-editor-emergency-nif" name="emergency_contact_nif" form="patient-editor-form">
                  <input type="hidden" id="patient-editor-emergency-phone" name="emergency_contact_phone" form="patient-editor-form">
                  <input type="hidden" id="patient-editor-emergency-relation" name="emergency_contact_relation" form="patient-editor-form">
                  <div class="col-12"><hr class="my-1"></div>
                  <div class="col-12 col-lg-6">
                    <label class="form-label" for="patient-editor-initial-reason">Motivo inicial de consulta</label>
                    <textarea class="form-control" id="patient-editor-initial-reason" name="initial_consultation_reason" rows="3" form="patient-editor-form"></textarea>
                  </div>
                  <div class="col-12 col-lg-6">
                    <label class="form-label" for="patient-editor-background">Antecedentes</label>
                    <textarea class="form-control" id="patient-editor-background" name="background_notes" rows="3" form="patient-editor-form"></textarea>
                  </div>
                  <div class="col-12 col-lg-6">
                    <label class="form-label" for="patient-editor-support-network"><?= $support_network_label ?></label>
                    <textarea class="form-control" id="patient-editor-support-network" name="support_network_notes" rows="3" form="patient-editor-form"></textarea>
                  </div>
                  <div class="col-12 col-lg-6">
                    <label class="form-label" for="patient-editor-notes">Notas internas</label>
                    <textarea class="form-control" id="patient-editor-notes" name="notes" rows="3" form="patient-editor-form"></textarea>
                  </div>
                  <div class="col-12 col-lg-6">
                    <label class="form-label">H&aacute;bitos</label>
                    <div class="row g-3">
                      <div class="col-sm-4">
                        <div class="form-check form-switch pt-sm-2">
                          <input class="form-check-input" type="checkbox" id="patient-editor-smoker" name="smoker" value="1" form="patient-editor-form">
                          <label class="form-check-label" for="patient-editor-smoker">Fumador</label>
                        </div>
                      </div>
                      <div class="col-sm-8">
                        <label class="visually-hidden" for="patient-editor-alcohol-consumption">Consumo de alcohol</label>
                        <select class="form-select" id="patient-editor-alcohol-consumption" name="alcohol_consumption" form="patient-editor-form">
                          <option value="">Sin especificar</option>
                          <option value="none">No bebe alcohol</option>
                          <option value="occasional">Bebe ocasionalmente</option>
                          <option value="frequent">Bebe frecuentemente</option>
                        </select>
                      </div>
                    </div>
                  </div>
                  <div class="col-12 col-lg-6">
                    <label class="form-label" for="patient-editor-preferred-service-option">Servicio preferido</label>
                    <select class="form-select" id="patient-editor-preferred-service-option" name="preferred_service_option_id" form="patient-editor-form">
                      <option value="">Sin servicio preferido</option>
                    </select>
                  </div>
                </div>
              </div>
              <div class="tab-pane fade" id="patient-contacts-panel" role="tabpanel" aria-labelledby="patient-contacts-tab">
                <div class="alert alert-info small">
                  A&ntilde;ade parejas, progenitores, tutores, familiares o contactos de emergencia vinculados a este <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>.
                </div>
                <div id="patient-contacts-alert" class="alert d-none"></div>
                <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                  <h6 class="mb-0">Contactos asociados</h6>
                  <button type="button" class="btn btn-outline-primary btn-sm" id="btn-new-patient-contact">
                    <i class="bi bi-person-plus"></i> Nuevo contacto
                  </button>
                </div>
                <div id="patient-contacts-list">
                  <div class="text-center text-muted py-4">Guarda primero el <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>.</div>
                </div>
              </div>
              <div class="tab-pane fade <?= !empty($initial_billing_settings['billing_enabled']) ? '' : 'd-none' ?>" id="patient-billing-data-panel" role="tabpanel" aria-labelledby="patient-billing-data-tab">
                <div class="row g-3">
                  <div class="col-12">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" id="patient-editor-invoice-tax-exempt" name="invoice_tax_exempt" value="1" form="patient-editor-form">
                      <label class="form-check-label" for="patient-editor-invoice-tax-exempt">Exento de IVA/IGIC</label>
                    </div>
                    <div class="form-text">Fuerza la exenci&oacute;n fiscal para las facturas de este paciente/cliente, aunque el servicio o la configuraci&oacute;n general indiquen un impuesto.</div>
                  </div>
                  <div class="col-12">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" id="patient-editor-invoice-use-alt-data" name="invoice_use_alt_data" value="1" form="patient-editor-form">
                      <label class="form-check-label" for="patient-editor-invoice-use-alt-data">Usar datos fiscales diferentes para las facturas</label>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="patient-editor-invoice-name">Nombre</label>
                    <input type="text" class="form-control patient-invoice-alt-field" id="patient-editor-invoice-name" name="invoice_name" form="patient-editor-form" maxlength="180" disabled>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="patient-editor-invoice-nif">NIF / CIF</label>
                    <input type="text" class="form-control patient-invoice-alt-field" id="patient-editor-invoice-nif" name="invoice_nif" form="patient-editor-form" maxlength="50" disabled>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="patient-editor-invoice-email">Email</label>
                    <input type="email" class="form-control patient-invoice-alt-field" id="patient-editor-invoice-email" name="invoice_email" form="patient-editor-form" maxlength="180" disabled>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="patient-editor-invoice-phone">Tel&eacute;fono</label>
                    <input type="text" class="form-control patient-invoice-alt-field" id="patient-editor-invoice-phone" name="invoice_phone" form="patient-editor-form" maxlength="40" disabled>
                  </div>
                  <div class="col-12">
                    <label class="form-label" for="patient-editor-invoice-address">Direcci&oacute;n</label>
                    <input type="text" class="form-control patient-invoice-alt-field" id="patient-editor-invoice-address" name="invoice_address" form="patient-editor-form" maxlength="255" disabled>
                  </div>
                </div>
              </div>
              <?php if ($physical_metrics_enabled): ?>
                <div class="tab-pane fade" id="patient-physical-panel" role="tabpanel" aria-labelledby="patient-physical-tab">
                  <div class="alert alert-info small mb-3">
                    Estos datos se sincronizan autom&aacute;ticamente con la pesta&ntilde;a Evoluci&oacute;n. Si en el futuro quieres registrar nuevas medidas, puedes hacerlo directamente desde Evoluci&oacute;n.
                    <br>
                    Registra medidas f&iacute;sicas orientativas para seguimiento. El IMC se calcula autom&aacute;ticamente y el porcentaje de grasa puede introducirse manualmente o estimarse con los datos disponibles.
                  </div>
                  <div class="row g-3">
                    <div class="col-md-4">
                      <label class="form-label" for="patient-editor-weight">Peso</label>
                      <div class="input-group">
                        <input type="number" step="0.1" min="0" class="form-control" id="patient-editor-weight" name="weight_kg" form="patient-editor-form">
                        <span class="input-group-text">kg</span>
                      </div>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label" for="patient-editor-height">Altura</label>
                      <div class="input-group">
                        <input type="number" step="0.1" min="0" class="form-control" id="patient-editor-height" name="height_cm" form="patient-editor-form">
                        <span class="input-group-text">cm</span>
                      </div>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label" for="patient-editor-physical-sex">Sexo biol&oacute;gico</label>
                      <select class="form-select" id="patient-editor-physical-sex" name="physical_sex" form="patient-editor-form">
                        <option value="">No indicado</option>
                        <option value="male">Masculino</option>
                        <option value="female">Femenino</option>
                      </select>
                    </div>
                    <div class="col-12">
                      <div class="border rounded p-3">
                        <h6 class="mb-3">Medidas corporales</h6>
                        <div class="row g-3">
                          <div class="col-md-4">
                            <label class="form-label" for="patient-editor-waist">Cintura</label>
                            <div class="input-group">
                              <input type="number" step="0.1" min="0" class="form-control" id="patient-editor-waist" name="waist_cm" form="patient-editor-form">
                              <span class="input-group-text">cm</span>
                            </div>
                          </div>
                          <div class="col-md-4">
                            <label class="form-label" for="patient-editor-hip">Cadera</label>
                            <div class="input-group">
                              <input type="number" step="0.1" min="0" class="form-control" id="patient-editor-hip" name="hip_cm" form="patient-editor-form">
                              <span class="input-group-text">cm</span>
                            </div>
                          </div>
                          <div class="col-md-4">
                            <label class="form-label" for="patient-editor-chest">Pecho / t&oacute;rax</label>
                            <div class="input-group">
                              <input type="number" step="0.1" min="0" class="form-control" id="patient-editor-chest" name="chest_cm" form="patient-editor-form">
                              <span class="input-group-text">cm</span>
                            </div>
                          </div>
                          <div class="col-md-4">
                            <label class="form-label" for="patient-editor-thigh">Muslo</label>
                            <div class="input-group">
                              <input type="number" step="0.1" min="0" class="form-control" id="patient-editor-thigh" name="thigh_cm" form="patient-editor-form">
                              <span class="input-group-text">cm</span>
                            </div>
                          </div>
                          <div class="col-md-4">
                            <label class="form-label" for="patient-editor-biceps">B&iacute;ceps</label>
                            <div class="input-group">
                              <input type="number" step="0.1" min="0" class="form-control" id="patient-editor-biceps" name="biceps_cm" form="patient-editor-form">
                              <span class="input-group-text">cm</span>
                            </div>
                          </div>
                          <div class="col-md-4">
                            <label class="form-label" for="patient-editor-calf">Gemelo</label>
                            <div class="input-group">
                              <input type="number" step="0.1" min="0" class="form-control" id="patient-editor-calf" name="calf_cm" form="patient-editor-form">
                              <span class="input-group-text">cm</span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div class="col-12">
                      <div class="border rounded p-3">
                        <h6 class="mb-3">Pliegues cut&aacute;neos</h6>
                        <div class="row g-3">
                          <div class="col-md-4">
                            <label class="form-label" for="patient-editor-skinfold-triceps">Tr&iacute;ceps</label>
                            <div class="input-group">
                              <input type="number" step="0.1" min="0" class="form-control" id="patient-editor-skinfold-triceps" name="skinfold_triceps_mm" form="patient-editor-form">
                              <span class="input-group-text">mm</span>
                            </div>
                          </div>
                          <div class="col-md-4">
                            <label class="form-label" for="patient-editor-skinfold-subscapular">Subescapular</label>
                            <div class="input-group">
                              <input type="number" step="0.1" min="0" class="form-control" id="patient-editor-skinfold-subscapular" name="skinfold_subscapular_mm" form="patient-editor-form">
                              <span class="input-group-text">mm</span>
                            </div>
                          </div>
                          <div class="col-md-4">
                            <label class="form-label" for="patient-editor-skinfold-suprailiac">Suprail&iacute;aco</label>
                            <div class="input-group">
                              <input type="number" step="0.1" min="0" class="form-control" id="patient-editor-skinfold-suprailiac" name="skinfold_suprailiac_mm" form="patient-editor-form">
                              <span class="input-group-text">mm</span>
                            </div>
                          </div>
                          <div class="col-md-4">
                            <label class="form-label" for="patient-editor-skinfold-abdominal">Abdominal</label>
                            <div class="input-group">
                              <input type="number" step="0.1" min="0" class="form-control" id="patient-editor-skinfold-abdominal" name="skinfold_abdominal_mm" form="patient-editor-form">
                              <span class="input-group-text">mm</span>
                            </div>
                          </div>
                          <div class="col-md-4">
                            <label class="form-label" for="patient-editor-skinfold-chest">Pectoral</label>
                            <div class="input-group">
                              <input type="number" step="0.1" min="0" class="form-control" id="patient-editor-skinfold-chest" name="skinfold_chest_mm" form="patient-editor-form">
                              <span class="input-group-text">mm</span>
                            </div>
                          </div>
                          <div class="col-md-4">
                            <label class="form-label" for="patient-editor-skinfold-thigh">Muslo</label>
                            <div class="input-group">
                              <input type="number" step="0.1" min="0" class="form-control" id="patient-editor-skinfold-thigh" name="skinfold_thigh_mm" form="patient-editor-form">
                              <span class="input-group-text">mm</span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div class="col-12">
                      <div class="border rounded p-3">
                        <h6 class="mb-3">C&aacute;lculos orientativos</h6>
                        <div class="row g-3">
                          <div class="col-md-4">
                            <label class="form-label">IMC</label>
                            <div class="bmi-indicator" id="patient-editor-bmi">
                              <div class="d-flex justify-content-between align-items-center gap-2">
                                <strong class="bmi-indicator-value">-</strong>
                                <span class="badge text-bg-light bmi-indicator-label">Sin datos</span>
                              </div>
                              <div class="bmi-indicator-bar" aria-hidden="true">
                                <span class="bmi-indicator-fill"></span>
                              </div>
                            </div>
                          </div>
                          <div class="col-md-8">
                            <label class="form-label" for="patient-editor-body-fat">Grasa corporal</label>
                            <div class="input-group">
                              <input type="number" step="0.1" min="0" max="80" class="form-control" id="patient-editor-body-fat" name="body_fat_percentage" form="patient-editor-form">
                              <span class="input-group-text">%</span>
                              <button class="btn btn-outline-primary" type="button" id="btn-calculate-body-fat">Calcular</button>
                            </div>
                            <div class="form-text" id="patient-editor-body-fat-note">Puedes introducirlo manualmente si ya tienes una medici&oacute;n fiable.</div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endif; ?>
                <div class="tab-pane fade" id="patient-diagnosis-panel" role="tabpanel" aria-labelledby="patient-diagnosis-tab">
                  <?php if ($knowledge_base_enabled && !$body_map_enabled): ?>
                  <div class="alert alert-info small mb-3">
                    Desde aqu&iacute; puedes indicar el <?= htmlspecialchars($sector_texts['clinicalTerms']['diagnosis'] ?? 'diagnóstico', ENT_QUOTES, 'UTF-8') ?> del <?= htmlspecialchars($sector_texts['labels']['patient']['singular'] ?? 'paciente', ENT_QUOTES, 'UTF-8') ?> o usar la Base de Conocimiento para buscar entre cientos de <?= htmlspecialchars($sector_texts['labels']['problem']['plural'] ?? 'diagnósticos', ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($sector_texts['labels']['technique']['plural'] ?? 'pautas', ENT_QUOTES, 'UTF-8') ?> y otros recursos. Las <?= htmlspecialchars($sector_texts['labels']['task']['plural'] ?? 'tareas', ENT_QUOTES, 'UTF-8') ?> que elijas para el proceso de <?= htmlspecialchars($sector_texts['clinicalTerms']['treatment'] ?? 'tratamiento', ENT_QUOTES, 'UTF-8') ?> se a&ntilde;aden autom&aacute;ticamente en la pesta&ntilde;a &laquo;Plan de trabajo&raquo;.
                  </div>
                  <?php endif; ?>
                  <?php if ($body_map_enabled): ?>
                  <div class="alert alert-info small mb-3">
                    <?= htmlspecialchars($knowledge_disclaimer, ENT_QUOTES, 'UTF-8') ?>
                  </div>
                  <?php endif; ?>
                  <div id="patient-knowledge-alert" class="alert d-none"></div>
                  <div class="row g-3">
                    <?php if ($body_map_enabled): ?>
                      <div class="col-12">
                        <div class="patient-knowledge-mode">
                          <div class="btn-group btn-group-sm" role="group" aria-label="Tipo de recomendaci&oacute;n">
                            <button type="button" class="btn btn-primary patient-knowledge-mode-btn active" data-knowledge-mode="muscles">
                              Ejercicios por m&uacute;sculo
                            </button>
                            <button type="button" class="btn btn-outline-primary patient-knowledge-mode-btn" data-knowledge-mode="objective">
                              Rutinas por objetivo
                            </button>
                            <button type="button" class="btn btn-outline-primary patient-knowledge-mode-btn" data-knowledge-mode="custom-workout">
                              Rutina personalizada
                            </button>
                          </div>
                          <p class="mb-0 text-muted small">Elige si quieres consultar ejercicios seg&uacute;n la zona muscular, revisar rutinas por objetivo o generar una rutina personalizada.</p>
                        </div>
                      </div>
                      <div class="col-12">
                        <section class="patient-body-map-panel patient-knowledge-mode-panel" data-knowledge-mode-panel="muscles">
                          <div class="patient-body-map-header">
                            <div>
                              <h6>Ejercicios por m&uacute;sculo</h6>
                              <p>Selecciona una o varias zonas para consultar ejercicios, rutinas o pautas relacionadas.</p>
                            </div>
                            <div class="patient-body-map-actions">
                              <div class="btn-group btn-group-sm" role="group" aria-label="Vista del mapa muscular">
                                <button type="button" class="btn btn-outline-primary active" id="btn-body-map-front">Frontal</button>
                                <button type="button" class="btn btn-outline-primary" id="btn-body-map-back">Posterior</button>
                              </div>
                              <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-body-map-clear">Limpiar</button>
                            </div>
                          </div>
                          <div class="row g-3 align-items-start">
                            <div class="col-lg-5">
                              <div id="patient-body-map" class="patient-body-map" aria-label="Mapa interactivo de musculatura"></div>
                            </div>
                            <div class="col-lg-7">
                              <div id="patient-body-map-selected" class="patient-body-map-selected">
                                <span class="text-muted">No hay zonas seleccionadas.</span>
                              </div>
                              <div id="patient-body-map-results" class="patient-body-map-results">
                                <div class="text-muted py-3">Selecciona un m&uacute;sculo para ver recomendaciones relacionadas.</div>
                              </div>
                            </div>
                          </div>
                        </section>
                      </div>
                    <?php endif; ?>
                    <div class="col-12 patient-knowledge-mode-panel<?= $body_map_enabled ? ' d-none' : '' ?>" data-knowledge-mode-panel="objective">
                      <div class="patient-objective-panel" id="patient-objective-panel">
                      <?php if (!$body_map_enabled): ?>
                      <div id="patient-knowledge-plan-b" class="d-none">
                        <div class="row g-2 mb-4">
                          <div class="col-12 col-lg-6">
                            <input type="text" class="form-control form-control-sm h-100" id="patient-manual-diagnosis" maxlength="500"
                              placeholder="Escribir <?= htmlspecialchars($sector_texts['clinicalTerms']['diagnosis'] ?? 'diagnóstico', ENT_QUOTES, 'UTF-8') ?> manualmente">
                          </div>
                          <div class="col-12 col-lg-2 d-grid">
                            <button type="button" class="btn btn-outline-primary btn-sm text-nowrap" id="btn-add-manual-diagnosis">
                              <i class="bi bi-plus-lg me-1"></i> A&ntilde;adir
                            </button>
                          </div>
                          <div class="col-12 col-lg-4 d-grid">
                            <button type="button" class="btn <?= $knowledge_base_enabled ? 'btn-primary' : 'btn-secondary plan-locked' ?> btn-sm text-nowrap" id="btn-use-knowledge-base"
                              <?= $knowledge_base_enabled ? '' : 'disabled title="Disponible en los planes Magister y Summum"' ?>>
                              <i class="bi <?= $knowledge_base_enabled ? 'bi-search' : 'bi-lock-fill' ?> me-1"></i> Usar base de conocimiento
                            </button>
                          </div>
                        </div>
                        <section class="patient-knowledge-assigned">
                          <h6 class="patient-knowledge-assigned-title">
                            <?= htmlspecialchars($sector_texts['labels']['problem']['titlePlural'] ?? 'Diagnósticos', ENT_QUOTES, 'UTF-8') ?> del <?= htmlspecialchars($sector_texts['labels']['patient']['singular'] ?? 'paciente', ENT_QUOTES, 'UTF-8') ?>
                          </h6>
                          <p class="text-muted small mb-3">
                            Aqu&iacute; aparecer&aacute;n los <?= htmlspecialchars($sector_texts['labels']['problem']['plural'] ?? 'diagnósticos', ENT_QUOTES, 'UTF-8') ?> del <?= htmlspecialchars($sector_texts['labels']['patient']['singular'] ?? 'paciente', ENT_QUOTES, 'UTF-8') ?>. Las tareas asociadas a cada objetivo se trasladan autom&aacute;ticamente a la pesta&ntilde;a &laquo;Plan de trabajo&raquo;. Puedes cambiar la prioridad de los <?= htmlspecialchars($sector_texts['labels']['problem']['plural'] ?? 'diagnósticos', ENT_QUOTES, 'UTF-8') ?> en caso de haber varios.
                          </p>
                          <div id="patient-knowledge-current-selection"></div>
                        </section>
                      </div>
                      <?php endif; ?>
                      <?php if ($body_map_enabled): ?>
                      <div id="patient-knowledge-plan-a">
                      <label class="form-label" for="patient-editor-knowledge-problem"><?= htmlspecialchars($sector_texts['labels']['problem']['titleSingular'] ?? 'Problema o diagnóstico') ?></label>
                      <div class="form-text mb-2">Selecciona <?= htmlspecialchars($sector_texts['labels']['problem']['singular'] ?? 'un problema o diagnóstico') ?> para consultar <?= htmlspecialchars($sector_texts['labels']['technique']['plural'] ?? 'técnicas') ?>, <?= htmlspecialchars($sector_texts['labels']['task']['plural'] ?? 'tareas') ?>, <?= htmlspecialchars($sector_texts['labels']['evaluation']['plural'] ?? 'cuestionarios') ?> y fuentes.</div>
                      <select class="form-select" id="patient-editor-knowledge-problem" name="knowledge_problem_id" form="patient-editor-form">
                        <option value="">Sin <?= htmlspecialchars($sector_texts['clinicalTerms']['diagnosis'] ?? 'diagnóstico') ?> asociado</option>
                      </select>
                      <div id="patient-knowledge-content" class="patient-knowledge-content mt-3">
                        <div class="text-center text-muted py-4">No hay <?= htmlspecialchars($sector_texts['clinicalTerms']['diagnosis'] ?? 'diagnóstico') ?> seleccionado.</div>
                      </div>
                      </div>
                      <?php else: ?>
                      <input type="hidden" id="patient-editor-knowledge-problem" name="knowledge_problem_id" form="patient-editor-form">
                      <?php endif; ?>
                      </div>
                    </div>
                    <?php if ($body_map_enabled): ?>
                      <div class="col-12 patient-knowledge-mode-panel d-none" data-knowledge-mode-panel="custom-workout">
                        <div class="patient-custom-workout-panel">
                          <div class="border rounded p-3">
                            <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
                              <div>
                                <h6 class="mb-1">Genera una rutina personalizada</h6>
                              </div>
                              <button type="button" class="btn btn-primary btn-sm align-self-lg-start" id="btn-generate-workoutx-plan">
                                <i class="bi bi-stars"></i> Generar rutina
                              </button>
                            </div>
                            <div class="row g-3">
                              <div class="col-md-3">
                                <label class="form-label" for="workoutx-plan-goal">Objetivo</label>
                                <select class="form-select" id="workoutx-plan-goal">
                                  <option value="muscle_gain">Ganar m&uacute;sculo</option>
                                  <option value="strength">Fuerza</option>
                                  <option value="fat_loss">P&eacute;rdida de grasa</option>
                                  <option value="endurance">Resistencia</option>
                                  <option value="mobility">Movilidad</option>
                                </select>
                              </div>
                              <div class="col-md-3">
                                <label class="form-label" for="workoutx-plan-duration">Duraci&oacute;n</label>
                                <div class="input-group">
                                  <input type="number" class="form-control" id="workoutx-plan-duration" min="20" max="120" step="5" value="45">
                                  <span class="input-group-text">min</span>
                                </div>
                              </div>
                              <div class="col-md-3">
                                <label class="form-label" for="workoutx-plan-level">Nivel</label>
                                <select class="form-select" id="workoutx-plan-level">
                                  <option value="beginner">Principiante</option>
                                  <option value="intermediate" selected>Intermedio</option>
                                  <option value="advanced">Avanzado</option>
                                </select>
                              </div>
                              <div class="col-md-3">
                                <label class="form-label" for="workoutx-plan-split">Enfoque</label>
                                <select class="form-select" id="workoutx-plan-split">
                                  <option value="full_body">Cuerpo completo</option>
                                  <option value="upper">Tren superior</option>
                                  <option value="lower">Tren inferior</option>
                                  <option value="push">Empuje</option>
                                  <option value="pull">Tir&oacute;n</option>
                                  <option value="legs">Piernas</option>
                                  <option value="push_pull_legs">Empuje, tir&oacute;n y piernas</option>
                                  <option value="upper_lower">Superior e inferior</option>
                                  <option value="core">Core</option>
                                </select>
                              </div>
                              <div class="col-md-6">
                                <label class="form-label">Material disponible</label>
                                <div class="workoutx-option-grid" id="workoutx-plan-equipment">
                                  <label class="form-check"><input class="form-check-input" type="checkbox" name="workoutx_plan_equipment[]" value="body weight"><span class="form-check-label">Peso corporal</span></label>
                                  <label class="form-check"><input class="form-check-input" type="checkbox" name="workoutx_plan_equipment[]" value="dumbbell"><span class="form-check-label">Mancuernas</span></label>
                                  <label class="form-check"><input class="form-check-input" type="checkbox" name="workoutx_plan_equipment[]" value="barbell"><span class="form-check-label">Barra</span></label>
                                  <label class="form-check"><input class="form-check-input" type="checkbox" name="workoutx_plan_equipment[]" value="cable"><span class="form-check-label">Polea / cable</span></label>
                                  <label class="form-check"><input class="form-check-input" type="checkbox" name="workoutx_plan_equipment[]" value="machine"><span class="form-check-label">M&aacute;quina</span></label>
                                  <label class="form-check"><input class="form-check-input" type="checkbox" name="workoutx_plan_equipment[]" value="kettlebell"><span class="form-check-label">Kettlebell</span></label>
                                  <label class="form-check"><input class="form-check-input" type="checkbox" name="workoutx_plan_equipment[]" value="resistance band"><span class="form-check-label">Banda el&aacute;stica</span></label>
                                  <label class="form-check"><input class="form-check-input" type="checkbox" name="workoutx_plan_equipment[]" value="smith machine"><span class="form-check-label">M&aacute;quina Smith</span></label>
                                </div>
                              </div>
                              <div class="col-md-6">
                                <label class="form-label">Zonas</label>
                                <div class="workoutx-option-grid" id="workoutx-plan-body-focus">
                                  <label class="form-check"><input class="form-check-input" type="checkbox" name="workoutx_plan_body_focus[]" value="chest"><span class="form-check-label">Pecho</span></label>
                                  <label class="form-check"><input class="form-check-input" type="checkbox" name="workoutx_plan_body_focus[]" value="back"><span class="form-check-label">Espalda</span></label>
                                  <label class="form-check"><input class="form-check-input" type="checkbox" name="workoutx_plan_body_focus[]" value="shoulders"><span class="form-check-label">Hombros</span></label>
                                  <label class="form-check"><input class="form-check-input" type="checkbox" name="workoutx_plan_body_focus[]" value="upper arms"><span class="form-check-label">Brazos</span></label>
                                  <label class="form-check"><input class="form-check-input" type="checkbox" name="workoutx_plan_body_focus[]" value="lower arms"><span class="form-check-label">Antebrazos</span></label>
                                  <label class="form-check"><input class="form-check-input" type="checkbox" name="workoutx_plan_body_focus[]" value="waist"><span class="form-check-label">Core / abdomen</span></label>
                                  <label class="form-check"><input class="form-check-input" type="checkbox" name="workoutx_plan_body_focus[]" value="upper legs"><span class="form-check-label">Piernas superiores</span></label>
                                  <label class="form-check"><input class="form-check-input" type="checkbox" name="workoutx_plan_body_focus[]" value="lower legs"><span class="form-check-label">Piernas inferiores</span></label>
                                </div>
                              </div>
                            </div>
                          </div>
                          <div id="workoutx-generated-plan" class="workoutx-generated-plan mt-3">
                          </div>
                        </div>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              <div class="tab-pane fade" id="patient-history-panel" role="tabpanel" aria-labelledby="patient-history-tab">
                <div id="patient-history-alert" class="alert d-none"></div>
                <div class="table-responsive patient-history-table-wrap">
                  <table class="table align-middle">
                    <thead>
                      <tr>
                        <th>Fecha</th>
                        <th>Profesional</th>
                        <th>Servicio</th>
                        <th>Modalidad</th>
                        <th>Pago</th>
                        <th class="text-end">Acciones</th>
                        <th>Estado</th>
                      </tr>
                    </thead>
                    <tbody id="patient-history-body">
                      <tr><td colspan="7" class="text-center text-muted py-4">Selecciona un <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> guardado para ver su historial.</td></tr>
                    </tbody>
                  </table>
                </div>
                <div class="text-end text-muted small mt-2" id="patient-history-count"></div>
              </div>
              <div class="tab-pane fade" id="patient-work-plan-panel" role="tabpanel" aria-labelledby="patient-work-plan-tab">
                <div id="patient-work-plan-alert" class="alert d-none"></div>
                <div class="alert alert-info d-flex align-items-start gap-2 small">
                  <i class="bi bi-list-check fs-5"></i>
                  <div>
                    <strong><?= htmlspecialchars($work_plan_title_singular, ENT_QUOTES, 'UTF-8') ?></strong>
                    <div id="patient-work-plan-help-text">Desde aqu&iacute; puedes personalizar tareas, actividades, temas a tratar o pautas para este <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>, y marcarlas como completadas o pendientes en las sucesivas citas.</div>
                  </div>
                </div>
                <div class="d-flex justify-content-end gap-2 mb-3 flex-wrap">
                  <button class="btn btn-outline-primary btn-sm" type="button" id="btn-show-patient-work-plan-form">
                    <i class="bi bi-plus-lg"></i> Crear tarea manualmente
                  </button>
                  <button class="btn btn-outline-primary btn-sm" type="button" id="btn-import-patient-task-template">
                    <i class="bi bi-collection"></i> Usar Mis Tareas
                  </button>
                  <button class="btn btn-primary btn-sm" type="button" id="btn-use-work-plan-knowledge">
                    <i class="bi bi-lightbulb"></i> Usar base de conocimiento
                  </button>
                </div>
                <div class="row g-3">
                  <div class="col-lg-6" id="patient-work-plan-pending-column">
                    <div class="patient-work-plan-column">
                      <h6>Pendientes</h6>
                      <div id="patient-work-plan-pending" class="patient-work-plan-list">
                        <div class="text-center text-muted py-4">Selecciona un <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> guardado para ver su plan.</div>
                      </div>
                    </div>
                  </div>
                  <div class="col-lg-6" id="patient-work-plan-completed-column">
                    <div class="patient-work-plan-column">
                      <h6>Completadas</h6>
                      <div id="patient-work-plan-completed-list" class="patient-work-plan-list">
                        <div class="text-center text-muted py-4">Selecciona un <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> guardado para ver su plan.</div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="text-end text-muted small mt-2" id="patient-work-plan-count"></div>
              </div>
              <div class="tab-pane fade" id="patient-evolution-panel" role="tabpanel" aria-labelledby="patient-evolution-tab">
                <div id="patient-evolution-alert" class="alert d-none"></div>
                <?php if ($physical_metrics_enabled): ?>
                  <div class="d-flex justify-content-center mb-3">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Vista de evoluci&oacute;n">
                      <button type="button" class="btn btn-primary patient-evolution-view-toggle" data-evolution-view="records">Evoluci&oacute;n</button>
                      <button type="button" class="btn btn-outline-primary patient-evolution-view-toggle" data-evolution-view="charts">Gr&aacute;ficos</button>
                    </div>
                  </div>
                <?php endif; ?>
                <div id="patient-evolution-records-view">
                  <div class="d-flex justify-content-end mb-3">
                    <button class="btn btn-primary btn-sm" type="button" id="btn-show-patient-evolution-form">
                      <i class="bi bi-plus-lg"></i> Nuevo registro
                    </button>
                  </div>
                  <div id="patient-evolution-list" class="patient-evolution-list">
                    <div class="text-center text-muted py-4">Selecciona un <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> guardado para ver su evoluci&oacute;n.</div>
                  </div>
                  <div class="text-end text-muted small mt-2" id="patient-evolution-count"></div>
                </div>
                <?php if ($physical_metrics_enabled): ?>
                  <div id="patient-evolution-charts-view" class="d-none">
                    <div class="patient-evolution-chart-toolbar">
                      <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Selector de gr&aacute;fico">
                        <button type="button" class="btn btn-primary patient-evolution-chart-group" data-chart-group="body">Peso, IMC y grasa</button>
                        <button type="button" class="btn btn-outline-primary patient-evolution-chart-group" data-chart-group="metrics">M&eacute;tricas</button>
                        <button type="button" class="btn btn-outline-primary patient-evolution-chart-group" data-chart-group="skinfolds">Pliegues</button>
                      </div>
                    </div>
                    <div id="patient-evolution-chart-grid" class="patient-evolution-chart-grid">
                      <div class="text-center text-muted py-4">Selecciona un <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> guardado para ver sus gr&aacute;ficos.</div>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
              <div class="tab-pane fade" id="patient-files-panel" role="tabpanel" aria-labelledby="patient-files-tab">
                <div id="patient-files-alert" class="alert d-none"></div>
                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
                  <div class="btn-group btn-group-sm" role="group" aria-label="Filtrar documentaci&oacute;n">
                    <button type="button" class="btn btn-primary patient-files-filter" data-files-filter="all">Todo</button>
                    <button type="button" class="btn btn-outline-primary patient-files-filter" data-files-filter="file">Archivos</button>
                    <?php if ($drawing_board_enabled): ?>
                    <button type="button" class="btn btn-outline-primary patient-files-filter" data-files-filter="drawing">Dibujos</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-primary patient-files-filter" data-files-filter="questionnaire">Cuestionarios</button>
                  </div>
                  <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btn-create-patient-docx"
                      <?= $online_document_editor_enabled ? '' : 'disabled title="Disponible en un plan superior"' ?>>
                      <i class="bi bi-file-earmark-plus"></i> Crear documento
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btn-create-patient-drawing"
                      <?= $drawing_board_enabled ? '' : 'disabled title="Disponible en un plan superior"' ?>>
                      <i class="bi bi-brush"></i> Crear dibujo
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" id="btn-show-patient-document-form"
                      <?= $document_uploads_enabled ? '' : 'disabled title="La subida de adjuntos está disponible en un plan superior"' ?>>
                      <i class="bi bi-plus-lg"></i> Nuevo documento
                    </button>
                  </div>
                </div>
                <div class="table-responsive">
                  <table class="table align-middle">
                    <thead>
                      <tr>
                        <th>Documento</th>
                        <th>Tipo</th>
                        <th>Fecha</th>
                        <th class="text-center">Portal</th>
                        <th class="text-end">Acciones</th>
                      </tr>
                    </thead>
                    <tbody id="patient-files-body">
                      <tr><td colspan="5" class="text-center text-muted py-4">Selecciona un <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> guardado para ver su documentaci&oacute;n.</td></tr>
                    </tbody>
                  </table>
                </div>
                <div class="text-end text-muted small mt-2" id="patient-files-count"></div>
              </div>
              <div class="tab-pane fade" id="patient-questionnaires-panel" role="tabpanel" aria-labelledby="patient-questionnaires-tab">
                <div class="patient-questionnaires-host" data-questionnaire-context="patient"><div class="text-center text-muted py-4">Abre esta pesta&ntilde;a para cargar los cuestionarios.</div></div>
              </div>
              <div class="tab-pane fade" id="patient-legal-documents-panel" role="tabpanel" aria-labelledby="patient-legal-documents-tab">
                <div id="patient-legal-documents-alert" class="alert d-none"></div>
                <div class="alert alert-info small mb-3" id="patient-legal-documents-info-alert">
                  A&ntilde;ade aqu&iacute; los consentimientos y documentos legales firmados/aceptados, o indica si han sido aceptados de forma externa.
                </div>
                <div class="table-responsive">
                  <table class="table align-middle">
                    <thead>
                      <tr>
                        <th>Documento</th>
                        <th>Estado</th>
                        <th>Firmado</th>
                        <th class="text-end">Acciones</th>
                      </tr>
                    </thead>
                    <tbody id="patient-legal-documents-body">
                      <tr><td colspan="4" class="text-center text-muted py-4">Selecciona un <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> guardado para ver sus consentimientos.</td></tr>
                    </tbody>
                  </table>
                </div>
                <div class="text-end text-muted small mt-2" id="patient-legal-documents-count"></div>
              </div>
              <div class="tab-pane fade" id="patient-reports-panel" role="tabpanel" aria-labelledby="patient-reports-tab">
                <div id="patient-reports-alert" class="alert d-none"></div>
                <div class="alert alert-info small mb-3">
                  <div>Los informes generados aqu&iacute; son borradores estructurados con los datos disponibles en la aplicaci&oacute;n. El profesional debe revisarlos, completarlos y firmarlos cuando corresponda antes de considerarlos versi&oacute;n final oficial.</div>
                  <div class="mt-1">Los informes se generan para el diagn&oacute;stico principal, en caso de haber varios.</div>
                </div>
                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                  <h6 class="mb-0">Informes disponibles</h6>
                  <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-primary btn-sm" id="btn-show-custom-patient-report" disabled>
                      <i class="bi bi-upload"></i> A&ntilde;adir plantilla de informe
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btn-show-suggested-patient-reports" disabled>
                      <i class="bi bi-stars"></i> Sugerir informes
                    </button>
                  </div>
                </div>
                <div class="table-responsive">
                  <table class="table align-middle">
                    <thead>
                      <tr>
                        <th>Informe</th>
                        <th>Estado</th>
                        <th>Coste</th>
                        <th>Fecha</th>
                        <th class="text-end">Acciones</th>
                      </tr>
                    </thead>
                    <tbody id="patient-reports-body">
                      <tr><td colspan="5" class="text-center text-muted py-4">Selecciona un <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> guardado para ver sus informes.</td></tr>
                    </tbody>
                  </table>
                </div>
                <div class="text-end text-muted small mt-2" id="patient-reports-count"></div>
              </div>
              <div class="tab-pane fade" id="patient-bonuses-panel" role="tabpanel" aria-labelledby="patient-bonuses-tab">
                <div id="patient-bonuses-alert" class="alert d-none"></div>
                <?php if ($is_superadmin): ?>
                  <div class="d-flex justify-content-end mb-3">
                    <button class="btn btn-primary btn-sm" type="button" id="btn-show-create-patient-bonus">
                      <i class="bi bi-plus-lg"></i> Crear bono para este <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>
                    </button>
                  </div>
                  <form id="patient-bonus-create-form" class="border rounded p-3 mb-3 d-none">
                    <div class="row g-3 align-items-end">
                      <div class="col-md-5">
                        <label class="form-label" for="patient-bonus-create-bonus">Tipo de bono</label>
                        <select class="form-select" id="patient-bonus-create-bonus" name="bonus_id"></select>
                      </div>
                      <div class="col-md-3">
                        <label class="form-label" for="patient-bonus-create-total">Sesiones compradas</label>
                        <input type="number" class="form-control" id="patient-bonus-create-total" name="total_sessions" min="1" max="999" value="1">
                      </div>
                      <div class="col-md-3">
                        <label class="form-label" for="patient-bonus-create-remaining">Sesiones restantes</label>
                        <input type="number" class="form-control" id="patient-bonus-create-remaining" name="remaining_sessions" min="0" max="999" value="1">
                      </div>
                      <div class="col-md-1 text-end">
                        <button class="btn btn-primary" type="submit" id="btn-create-patient-bonus" title="Guardar bono">
                          <i class="bi bi-check2"></i>
                        </button>
                      </div>
                    </div>
                    <div class="form-text mt-2">Creacion manual para ajustes internos, regalos o regularizaciones.</div>
                  </form>
                <?php endif; ?>
                <div class="table-responsive">
                  <table class="table align-middle">
                    <thead>
                      <tr>
                        <th>Bono</th>
                        <th>Compradas</th>
                        <th>Restantes</th>
                        <th>Pagado</th>
                        <th>Comprado</th>
                        <th>Estado</th>
                        <?php if ($is_superadmin): ?>
                          <th class="text-end">Acciones</th>
                        <?php endif; ?>
                      </tr>
                    </thead>
                    <tbody id="patient-bonuses-body">
                      <tr><td colspan="<?= $is_superadmin ? 7 : 6 ?>" class="text-center text-muted py-4">Selecciona un <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> guardado para ver sus bonos.</td></tr>
                    </tbody>
                  </table>
                </div>
                <div class="text-end text-muted small mt-2" id="patient-bonuses-count"></div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <?php if ($is_superadmin): ?>
            <button class="btn btn-outline-danger d-none" type="button" id="btn-manage-patient-deletion">
              <i class="bi bi-person-x"></i> Eliminar o bloquear
            </button>
            <?php endif; ?>
            <button class="btn btn-outline-primary me-auto d-none" type="button" id="btn-book-patient-appointment">
              <i class="bi bi-calendar-plus"></i> Nueva cita
            </button>
            <button class="btn btn-primary" type="submit" id="btn-save-patient" form="patient-editor-form">Guardar cambios</button>
          </div>
        </div>
      </div>
    </div>

    <?php if ($is_superadmin): ?>
    <div class="modal fade" id="patientDeletionModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <h5 class="modal-title"><i class="bi bi-person-x text-danger me-2"></i>Eliminar o bloquear paciente</h5>
              <div class="small text-muted" id="patient-deletion-name"></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="patient-deletion-alert" class="alert d-none"></div>
            <div class="alert alert-warning small">
              <strong>Conservaci&oacute;n legal:</strong> el derecho de supresi&oacute;n no permite eliminar documentaci&oacute;n que deba conservarse por obligaci&oacute;n legal.
              Las historias cl&iacute;nicas deben conservarse, con car&aacute;cter general, al menos cinco a&ntilde;os desde el alta del proceso asistencial
              (art. 17 de la Ley 41/2002). Las facturas y justificantes contables est&aacute;n sujetos a sus propios plazos fiscales y mercantiles,
              que pueden alcanzar seis a&ntilde;os. La normativa auton&oacute;mica puede establecer plazos superiores.
            </div>
            <div class="vstack gap-3">
              <label class="border rounded p-3 d-flex gap-3 align-items-start patient-deletion-option">
                <input class="form-check-input mt-1" type="radio" name="patient_deletion_mode" value="legal" checked>
                <span>
                  <strong>Solicitud de supresi&oacute;n / bloqueo legal</strong>
                  <span class="d-block small text-muted mt-1">Retira al paciente de la operativa, revoca su acceso al Portal y cancela sus citas futuras. El expediente que deba conservarse queda bloqueado y solo visible para el superadmin.</span>
                </span>
              </label>
              <label class="border rounded p-3 d-flex gap-3 align-items-start patient-deletion-option">
                <input class="form-check-input mt-1" type="radio" name="patient_deletion_mode" value="test">
                <span>
                  <strong>Registro de prueba, duplicado o creado por error</strong>
                  <span class="d-block small text-muted mt-1">Elimina permanentemente todos sus datos, citas, documentos y archivos. No se permitir&aacute; si existe alguna factura emitida.</span>
                </span>
              </label>
            </div>
            <div class="mt-3">
              <label class="form-label" for="patient-deletion-reason">Motivo de la eliminaci&oacute;n <span class="text-muted">(opcional)</span></label>
              <input type="text" class="form-control" id="patient-deletion-reason" maxlength="500" placeholder="Ej.: Solicitud del paciente o registro duplicado">
            </div>
            <div class="mt-3">
              <label class="form-label" for="patient-deletion-confirmation">Escribe <code>ELIMINAR</code> para confirmar</label>
              <input type="text" class="form-control" id="patient-deletion-confirmation" autocomplete="off" placeholder="ELIMINAR">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-danger" id="btn-confirm-patient-deletion" disabled>
              <i class="bi bi-trash"></i> Confirmar
            </button>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="modal fade" id="patientContactModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="patient-contact-modal-title">Nuevo contacto asociado</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form id="patient-contact-form">
            <div class="modal-body">
              <div id="patient-contact-alert" class="alert d-none"></div>
              <input type="hidden" id="patient-contact-id" name="contact_id" value="0">
              <input type="hidden" id="patient-contact-patient-id" name="patient_id" value="0">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label" for="patient-contact-name">Nombre</label>
                  <input type="text" class="form-control" id="patient-contact-name" name="name" maxlength="180" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="patient-contact-relationship">Relaci&oacute;n</label>
                  <input type="text" class="form-control" id="patient-contact-relationship" name="relationship" maxlength="80" placeholder="Pareja, madre, padre, tutor...">
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="patient-contact-nif">NIF / NIE</label>
                  <input type="text" class="form-control" id="patient-contact-nif" name="nif" maxlength="50">
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="patient-contact-email">Email</label>
                  <input type="email" class="form-control" id="patient-contact-email" name="email" maxlength="180">
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="patient-contact-phone">Tel&eacute;fono</label>
                  <input type="text" class="form-control" id="patient-contact-phone" name="phone" maxlength="40">
                </div>
                <div class="col-12">
                  <label class="form-label" for="patient-contact-address">Domicilio</label>
                  <textarea class="form-control" id="patient-contact-address" name="address" rows="2" maxlength="255"></textarea>
                </div>
                <div class="col-12">
                  <label class="form-label" for="patient-contact-notes">Observaciones</label>
                  <textarea class="form-control" id="patient-contact-notes" name="notes" rows="2"></textarea>
                </div>
                <div class="col-md-6">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="patient-contact-legal-guardian" name="is_legal_guardian" value="1">
                    <label class="form-check-label" for="patient-contact-legal-guardian">Tutor o representante legal</label>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="patient-contact-emergency" name="is_emergency_contact" value="1">
                    <label class="form-check-label" for="patient-contact-emergency">Contacto de emergencia</label>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="patient-contact-communications" name="receives_communications" value="1">
                    <label class="form-check-label" for="patient-contact-communications">Recibir comunicaciones</label>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="patient-contact-portal" name="portal_access_enabled" value="1">
                    <label class="form-check-label" for="patient-contact-portal">Autorizar acceso al portal</label>
                  </div>
                  <div class="form-text">La autorizaci&oacute;n requerir&aacute; una invitaci&oacute;n y credenciales propias.</div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary" id="btn-save-patient-contact">Guardar contacto</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade" id="patientEvolutionModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="patient-evolution-modal-title">Nuevo registro de evoluci&oacute;n</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form id="patient-evolution-form" enctype="multipart/form-data">
            <div class="modal-body">
              <input type="hidden" id="patient-evolution-id" name="note_id" value="0">
              <input type="hidden" id="patient-evolution-patient-id" name="patient_id" value="0">
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label" for="patient-evolution-date">Fecha</label>
                  <input type="date" class="form-control" id="patient-evolution-date" name="note_date" required>
                </div>
                <div class="col-md-8">
                  <label class="form-label" for="patient-evolution-appointment">Cita vinculada</label>
                  <select class="form-select" id="patient-evolution-appointment" name="appointment_id">
                    <option value="">Nota general del <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?></option>
                  </select>
                </div>
                <div class="col-12">
                  <label class="form-label" for="patient-evolution-title">T&iacute;tulo</label>
                  <input type="text" class="form-control" id="patient-evolution-title" name="title" maxlength="180" required>
                </div>
                <div class="col-12">
                  <label class="form-label" for="patient-evolution-description">Descripci&oacute;n</label>
                  <textarea class="form-control" id="patient-evolution-description" name="description" rows="3"></textarea>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="patient-evolution-observations">Observaciones</label>
                  <textarea class="form-control" id="patient-evolution-observations" name="observations" rows="3"></textarea>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="patient-evolution-next-steps">Pendientes / pr&oacute;xima cita</label>
                  <textarea class="form-control" id="patient-evolution-next-steps" name="next_steps" rows="3"></textarea>
                </div>
                <?php if ($physical_metrics_enabled): ?>
                  <div class="col-12">
                    <div class="border rounded p-3">
                      <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                        <h6 class="mb-0">Composici&oacute;n corporal</h6>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-copy-current-physical-metrics">
                          Usar medidas actuales
                        </button>
                      </div>
                      <div class="row g-3">
                        <div class="col-md-3">
                          <label class="form-label" for="patient-evolution-weight">Peso</label>
                          <div class="input-group">
                            <input type="number" step="0.1" min="0" class="form-control" id="patient-evolution-weight" name="weight_kg">
                            <span class="input-group-text">kg</span>
                          </div>
                        </div>
                        <div class="col-md-3">
                          <label class="form-label" for="patient-evolution-height">Altura</label>
                          <div class="input-group">
                            <input type="number" step="0.1" min="0" class="form-control" id="patient-evolution-height" name="height_cm">
                            <span class="input-group-text">cm</span>
                          </div>
                        </div>
                        <div class="col-md-3">
                          <label class="form-label">IMC</label>
                          <div class="bmi-indicator" id="patient-evolution-bmi">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                              <strong class="bmi-indicator-value">-</strong>
                              <span class="badge text-bg-light bmi-indicator-label">Sin datos</span>
                            </div>
                            <div class="bmi-indicator-bar" aria-hidden="true">
                              <span class="bmi-indicator-fill"></span>
                            </div>
                          </div>
                        </div>
                        <div class="col-md-3">
                          <label class="form-label" for="patient-evolution-body-fat">Grasa corporal</label>
                          <div class="input-group">
                            <input type="number" step="0.1" min="0" max="80" class="form-control" id="patient-evolution-body-fat" name="body_fat_percentage">
                            <span class="input-group-text">%</span>
                          </div>
                        </div>
                        <div class="col-md-3">
                          <label class="form-label" for="patient-evolution-waist">Cintura</label>
                          <div class="input-group">
                            <input type="number" step="0.1" min="0" class="form-control" id="patient-evolution-waist" name="waist_cm">
                            <span class="input-group-text">cm</span>
                          </div>
                        </div>
                        <div class="col-md-3">
                          <label class="form-label" for="patient-evolution-hip">Cadera</label>
                          <div class="input-group">
                            <input type="number" step="0.1" min="0" class="form-control" id="patient-evolution-hip" name="hip_cm">
                            <span class="input-group-text">cm</span>
                          </div>
                        </div>
                        <div class="col-md-3">
                          <label class="form-label" for="patient-evolution-thigh">Muslo</label>
                          <div class="input-group">
                            <input type="number" step="0.1" min="0" class="form-control" id="patient-evolution-thigh" name="thigh_cm">
                            <span class="input-group-text">cm</span>
                          </div>
                        </div>
                        <div class="col-md-3">
                          <label class="form-label" for="patient-evolution-chest">Pecho / t&oacute;rax</label>
                          <div class="input-group">
                            <input type="number" step="0.1" min="0" class="form-control" id="patient-evolution-chest" name="chest_cm">
                            <span class="input-group-text">cm</span>
                          </div>
                        </div>
                        <div class="col-md-3">
                          <label class="form-label" for="patient-evolution-biceps">B&iacute;ceps</label>
                          <div class="input-group">
                            <input type="number" step="0.1" min="0" class="form-control" id="patient-evolution-biceps" name="biceps_cm">
                            <span class="input-group-text">cm</span>
                          </div>
                        </div>
                        <div class="col-md-3">
                          <label class="form-label" for="patient-evolution-calf">Gemelo</label>
                          <div class="input-group">
                            <input type="number" step="0.1" min="0" class="form-control" id="patient-evolution-calf" name="calf_cm">
                            <span class="input-group-text">cm</span>
                          </div>
                        </div>
                        <div class="col-12">
                          <h6 class="mb-0 mt-2">Pliegues cut&aacute;neos</h6>
                        </div>
                        <div class="col-md-4">
                          <label class="form-label" for="patient-evolution-skinfold-triceps">Tr&iacute;ceps</label>
                          <div class="input-group">
                            <input type="number" step="0.1" min="0" class="form-control" id="patient-evolution-skinfold-triceps" name="skinfold_triceps_mm">
                            <span class="input-group-text">mm</span>
                          </div>
                        </div>
                        <div class="col-md-4">
                          <label class="form-label" for="patient-evolution-skinfold-subscapular">Subescapular</label>
                          <div class="input-group">
                            <input type="number" step="0.1" min="0" class="form-control" id="patient-evolution-skinfold-subscapular" name="skinfold_subscapular_mm">
                            <span class="input-group-text">mm</span>
                          </div>
                        </div>
                        <div class="col-md-4">
                          <label class="form-label" for="patient-evolution-skinfold-suprailiac">Suprail&iacute;aco</label>
                          <div class="input-group">
                            <input type="number" step="0.1" min="0" class="form-control" id="patient-evolution-skinfold-suprailiac" name="skinfold_suprailiac_mm">
                            <span class="input-group-text">mm</span>
                          </div>
                        </div>
                        <div class="col-md-4">
                          <label class="form-label" for="patient-evolution-skinfold-abdominal">Abdominal</label>
                          <div class="input-group">
                            <input type="number" step="0.1" min="0" class="form-control" id="patient-evolution-skinfold-abdominal" name="skinfold_abdominal_mm">
                            <span class="input-group-text">mm</span>
                          </div>
                        </div>
                        <div class="col-md-4">
                          <label class="form-label" for="patient-evolution-skinfold-chest">Pectoral</label>
                          <div class="input-group">
                            <input type="number" step="0.1" min="0" class="form-control" id="patient-evolution-skinfold-chest" name="skinfold_chest_mm">
                            <span class="input-group-text">mm</span>
                          </div>
                        </div>
                        <div class="col-md-4">
                          <label class="form-label" for="patient-evolution-skinfold-thigh">Muslo</label>
                          <div class="input-group">
                            <input type="number" step="0.1" min="0" class="form-control" id="patient-evolution-skinfold-thigh" name="skinfold_thigh_mm">
                            <span class="input-group-text">mm</span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                <?php endif; ?>
                <div class="col-12 <?= $document_uploads_enabled ? '' : 'opacity-50' ?>">
                  <label class="form-label" for="patient-evolution-files">Archivos</label>
                  <input type="file" class="form-control" id="patient-evolution-files" name="evolution_files[]" accept=".pdf,.xls,.xlsx,image/jpeg,image/png,image/webp,image/gif" multiple
                    <?= $document_uploads_enabled ? '' : 'disabled title="Disponible en un plan superior"' ?>>
                  <div class="form-text">Puedes adjuntar PDF, Excel o im&aacute;genes. M&aacute;ximo 12 MB por archivo.</div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" id="btn-cancel-patient-evolution-form" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary" id="btn-save-patient-evolution">Guardar evoluci&oacute;n</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade" id="workoutxExerciseModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="workoutx-exercise-title">Ejercicio</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="workoutx-exercise-alert" class="alert d-none"></div>
            <div id="workoutx-exercise-content" class="workoutx-exercise-content">
              <div class="text-center text-muted py-4">
                <span class="spinner-border spinner-border-sm me-2"></span>Cargando ejercicio...
              </div>
            </div>
          </div>
          <div class="modal-footer d-none" id="workoutx-exercise-footer">
            <button type="button" class="btn btn-primary" id="btn-add-workoutx-exercise-footer">
              <i class="bi bi-plus-lg"></i> Agregar ejercicio
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="patientDocumentModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <form id="patient-document-form" enctype="multipart/form-data">
            <div class="modal-header">
              <h5 class="modal-title">Nuevo documento</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div id="patient-document-alert" class="alert d-none"></div>
              <input type="hidden" id="patient-document-id" name="document_id" value="0">
              <input type="hidden" id="patient-document-patient-id" name="patient_id" value="0">
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label" for="patient-document-type">Tipo</label>
                  <select class="form-select" id="patient-document-type" name="document_type">
                    <option value="file">Archivo</option>
                    <option value="questionnaire">Cuestionario</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="patient-document-date">Fecha</label>
                  <input type="date" class="form-control" id="patient-document-date" name="document_date">
                </div>
                <div class="col-md-4 patient-document-questionnaire-field">
                  <label class="form-label" for="patient-document-status">Estado</label>
                  <select class="form-select" id="patient-document-status" name="status">
                    <option value="completed">Completado</option>
                    <option value="pending">Pendiente</option>
                    <option value="reviewed">Revisado</option>
                  </select>
                </div>
                <div class="col-12">
                  <label class="form-label" for="patient-document-title">T&iacute;tulo</label>
                  <input type="text" class="form-control" id="patient-document-title" name="title" maxlength="180" required>
                </div>
                <div class="col-12">
                  <label class="form-label" for="patient-document-description">Descripci&oacute;n</label>
                  <textarea class="form-control" id="patient-document-description" name="description" rows="2"></textarea>
                </div>
                <div class="col-md-6 patient-document-questionnaire-field">
                  <label class="form-label" for="patient-document-score">Nota / resultado</label>
                  <input type="text" class="form-control" id="patient-document-score" name="score" maxlength="80" placeholder="Ej. 18/30, nivel medio...">
                </div>
                <div class="col-md-6 patient-document-questionnaire-field">
                  <label class="form-label" for="patient-document-result-label">Etiqueta del resultado</label>
                  <input type="text" class="form-control" id="patient-document-result-label" name="result_label" maxlength="120" placeholder="Ej. Resultado moderado">
                </div>
                <div class="col-12 patient-document-questionnaire-field">
                  <label class="form-label" for="patient-document-observations">Observaciones</label>
                  <textarea class="form-control" id="patient-document-observations" name="observations" rows="3"></textarea>
                </div>
                <div class="col-12 patient-document-questionnaire-field">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="patient-document-result-visible-to-patient" name="result_visible_to_patient" value="1">
                    <label class="form-check-label" for="patient-document-result-visible-to-patient">Publicar nota/resultado en el Portal de Clientes</label>
                  </div>
                </div>
                <div class="col-md-5 patient-document-questionnaire-field">
                  <label class="form-label" for="patient-document-version-type">Versi&oacute;n</label>
                  <select class="form-select" id="patient-document-version-type" name="version_type">
                    <option value="completed">Cuestionario completado</option>
                    <option value="template">Plantilla vac&iacute;a</option>
                    <option value="revision">Revisi&oacute;n / seguimiento</option>
                  </select>
                </div>
                <div class="col-md-7">
                  <label class="form-label" for="patient-document-file">Archivo</label>
                  <input type="file" class="form-control" id="patient-document-file" name="document_file" accept=".pdf,.doc,.docx,.xls,.xlsx,image/jpeg,image/png,image/webp,image/gif">
                  <div class="form-text">PDF, DOC, DOCX, Excel o imagen. M&aacute;ximo 12 MB.</div>
                </div>
                <div class="col-12">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="patient-document-visible-to-patient" name="visible_to_patient" value="1">
                    <label class="form-check-label" for="patient-document-visible-to-patient">Disponible en el portal del <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?></label>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary" id="btn-save-patient-document">
                <i class="bi bi-check2"></i> Guardar documento
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <?php if ($online_document_editor_enabled): ?>
    <div class="modal fade" id="docxEditorModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-fullscreen-lg-down modal-xl docx-editor-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <h5 class="modal-title" id="docx-editor-modal-title">Documento DOCX</h5>
              <div class="text-muted small" id="docx-editor-modal-status">Preparando editor...</div>
            </div>
            <div class="d-flex gap-2 ms-auto me-2">
              <button type="button" class="btn btn-outline-primary btn-sm" id="btn-docx-editor-new">
                <i class="bi bi-file-earmark-plus"></i> Doc. en blanco
              </button>
              <button type="button" class="btn btn-outline-primary btn-sm" id="btn-docx-editor-upload">
                <i class="bi bi-upload"></i> Subir .docx
              </button>
              <input type="file" class="d-none" id="docx-editor-upload-input" accept=".docx">
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-0">
            <iframe id="docx-editor-frame" title="Editor DOCX" class="docx-editor-frame" src="about:blank"></iframe>
          </div>
          <div class="modal-footer">
            <span class="text-muted small me-auto" id="docx-editor-footer-status">Los cambios se autoguardan mientras editas.</span>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
            <button type="button" class="btn btn-outline-primary" id="btn-docx-editor-save-close">
              <i class="bi bi-box-arrow-down"></i> Guardar y cerrar
            </button>
            <button type="button" class="btn btn-primary" id="btn-docx-editor-save">
              <i class="bi bi-check2"></i> Guardar cambios
            </button>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($drawing_board_enabled): ?>
    <div class="modal fade" id="drawingEditorModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-fullscreen-lg-down modal-xl drawing-editor-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <h5 class="modal-title" id="drawing-editor-modal-title">Dibujo</h5>
              <div class="text-muted small" id="drawing-editor-modal-status">Preparando editor...</div>
            </div>
            <div class="d-flex gap-2 ms-auto me-2">
              <button type="button" class="btn btn-outline-primary btn-sm" id="btn-drawing-editor-new">
                <i class="bi bi-brush"></i> Dibujo en blanco
              </button>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-0">
            <iframe id="drawing-editor-frame" title="Editor de dibujos" class="drawing-editor-frame" src="about:blank"></iframe>
          </div>
          <div class="modal-footer">
            <span class="text-muted small me-auto" id="drawing-editor-footer-status">Los cambios se autoguardan mientras dibujas.</span>
            <button type="button" class="btn btn-outline-primary" id="btn-drawing-editor-download">
              <i class="bi bi-image"></i> Descargar imagen
            </button>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
            <button type="button" class="btn btn-outline-primary" id="btn-drawing-editor-save-close">
              <i class="bi bi-box-arrow-down"></i> Guardar y cerrar
            </button>
            <button type="button" class="btn btn-primary" id="btn-drawing-editor-save">
              <i class="bi bi-check2"></i> Guardar cambios
            </button>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="modal fade" id="patientLegalDocumentModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md">
        <div class="modal-content">
          <form id="patient-legal-document-form" enctype="multipart/form-data">
            <div class="modal-header">
              <h5 class="modal-title">Consentimiento</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div id="patient-legal-document-alert" class="alert d-none"></div>
              <input type="hidden" id="patient-legal-document-patient-id" name="patient_id" value="0">
              <input type="hidden" id="patient-legal-document-legal-id" name="legal_document_id" value="0">
              <div class="mb-3">
                <label class="form-label">Documento</label>
                <div class="fw-semibold" id="patient-legal-document-title">Documento legal</div>
                <div class="form-text" id="patient-legal-document-meta"></div>
              </div>
              <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="patient-legal-document-accepted" name="accepted" value="1">
                <label class="form-check-label" for="patient-legal-document-accepted">Aceptado/Firmado</label>
              </div>
              <div class="mb-3">
                <label class="form-label" for="patient-legal-document-note">Nota</label>
                <textarea class="form-control" id="patient-legal-document-note" name="acceptance_note" rows="3" placeholder="Ej. Firmado en papel, firmado en otro sistema..."></textarea>
              </div>
              <div>
                <label class="form-label" for="patient-legal-document-file">PDF firmado</label>
                <input type="file" class="form-control" id="patient-legal-document-file" name="signed_document_file" accept="application/pdf,.pdf">
                <div class="form-text">Opcional. Si subes el PDF firmado, el consentimiento se marcar&aacute; como aceptado/firmado.</div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary" id="btn-save-patient-legal-document">Guardar consentimiento</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade" id="handwrittenConsentModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable handwritten-consent-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <h5 class="modal-title">Firma presencial</h5>
              <div class="text-muted small" id="handwritten-consent-subtitle">Lectura y firma del consentimiento</div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="handwritten-consent-alert" class="alert d-none"></div>
            <input type="hidden" id="handwritten-consent-patient-id" value="0">
            <input type="hidden" id="handwritten-consent-document-id" value="0">

            <div class="consent-wizard-steps mb-3" aria-label="Progreso de la firma">
              <div class="consent-wizard-step active" data-step="1"><span>1</span><small>Leer</small></div>
              <div class="consent-wizard-step" data-step="2"><span>2</span><small>Identificar</small></div>
              <div class="consent-wizard-step" data-step="3"><span>3</span><small>Firmar</small></div>
              <div class="consent-wizard-step" data-step="4"><span>4</span><small>Confirmar</small></div>
            </div>

            <section class="handwritten-consent-step" data-step="1">
              <?php if ($patient_portal_enabled): ?>
                <div class="alert alert-info small py-2">
                  Este documento tambi&eacute;n puede firmarse desde el Portal de <?= htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') ?> con el certificado digital del <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>.
                </div>
              <?php endif; ?>
              <div class="alert alert-info small py-2">
                Lee el documento completo antes de continuar. Puedes desplazarte dentro del visor y ampliarlo desde los controles del navegador.
              </div>
              <iframe id="handwritten-consent-document-frame" class="handwritten-consent-frame" title="Documento que se va a firmar" src="about:blank"></iframe>
              <div class="form-check mt-3">
                <input class="form-check-input" type="checkbox" id="handwritten-consent-read">
                <label class="form-check-label" for="handwritten-consent-read">He leído el documento y he podido resolver mis dudas.</label>
              </div>
            </section>

            <section class="handwritten-consent-step d-none" data-step="2">
              <div class="alert alert-info small">
                Indica quién firma. Puede ser el propio paciente o su representante legal.
              </div>
              <div class="row g-3">
                <div class="col-md-8">
                  <label class="form-label" for="handwritten-consent-signer-name">Nombre y apellidos del firmante</label>
                  <input type="text" class="form-control" id="handwritten-consent-signer-name" maxlength="180" autocomplete="name">
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="handwritten-consent-signer-nif">NIF / NIE <span class="text-muted">(opcional)</span></label>
                  <input type="text" class="form-control" id="handwritten-consent-signer-nif" maxlength="50">
                </div>
              </div>
            </section>

            <section class="handwritten-consent-step d-none" data-step="3">
              <div class="text-center mb-3">
                <h6>Firma de la persona interesada</h6>
                <p class="text-muted mb-0">Firma dentro del recuadro con el dedo, ratón o lápiz digital.</p>
              </div>
              <div class="handwritten-signature-wrap">
                <canvas id="handwritten-consent-canvas" aria-label="Recuadro para firma manuscrita"></canvas>
              </div>
              <div class="text-center mt-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-clear-handwritten-consent">
                  <i class="bi bi-eraser"></i> Borrar firma
                </button>
              </div>
            </section>

            <section class="handwritten-consent-step d-none" data-step="4">
              <div class="alert alert-warning small">
                Revisa los datos antes de terminar. Al confirmar se generará el PDF firmado y el consentimiento quedará marcado automáticamente como aceptado y firmado.
              </div>
              <div class="border p-3">
                <div class="row g-3">
                  <div class="col-md-6"><small class="text-muted d-block">Paciente</small><strong id="handwritten-summary-patient"></strong></div>
                  <div class="col-md-6"><small class="text-muted d-block">Documento</small><strong id="handwritten-summary-document"></strong></div>
                  <div class="col-md-6"><small class="text-muted d-block">Firmante</small><strong id="handwritten-summary-signer"></strong></div>
                  <div class="col-md-6"><small class="text-muted d-block">Método</small><strong>Firma manuscrita presencial</strong></div>
                </div>
                <div class="mt-3">
                  <small class="text-muted d-block mb-1">Firma</small>
                  <img id="handwritten-summary-signature" class="handwritten-summary-signature" alt="Vista previa de la firma">
                </div>
              </div>
            </section>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary me-auto d-none" id="btn-handwritten-consent-previous">
              <i class="bi bi-chevron-left"></i> Anterior
            </button>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btn-handwritten-consent-next">
              Continuar <i class="bi bi-chevron-right"></i>
            </button>
            <button type="button" class="btn btn-success d-none" id="btn-handwritten-consent-finish">
              <i class="bi bi-check2"></i> Firmar y guardar
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="patientReportConfigModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <form id="patient-report-config-form" enctype="multipart/form-data">
            <div class="modal-header">
              <h5 class="modal-title">Configurar informe</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div id="patient-report-config-alert" class="alert d-none"></div>
              <input type="hidden" id="patient-report-config-id" name="report_id" value="0">
              <input type="hidden" id="patient-report-config-patient-id" name="patient_id" value="0">
              <div class="alert alert-info small mb-3">
                Sube aqu&iacute; la versi&oacute;n final revisada si quieres que el <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> pueda descargarla desde su portal. Si el informe es de pago, m&aacute;rcalo como pagado cuando corresponda.
              </div>
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label" for="patient-report-title">T&iacute;tulo</label>
                  <input type="text" class="form-control" id="patient-report-title" name="title" maxlength="180" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="patient-report-payment-mode">Tipo de cobro</label>
                  <select class="form-select" id="patient-report-payment-mode" name="payment_mode">
                    <option value="free">Gratuito</option>
                    <option value="included">Incluido en consulta</option>
                    <option value="paid">De pago</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="patient-report-payment-status">Estado de pago</label>
                  <select class="form-select" id="patient-report-payment-status" name="payment_status">
                    <option value="not_required">No requiere pago</option>
                    <option value="pending">Pendiente</option>
                    <option value="paid">Pagado</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="patient-report-price">Importe</label>
                  <div class="input-group">
                    <input type="number" class="form-control" id="patient-report-price" name="price" min="0" step="0.01">
                    <span class="input-group-text">&euro;</span>
                  </div>
                </div>
                <div class="col-12">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="patient-report-portal-available" name="portal_available" value="1">
                    <label class="form-check-label" for="patient-report-portal-available">Disponible en el portal del <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?></label>
                  </div>
                </div>
                <div class="col-12 d-none" id="patient-report-source-document-wrap">
                  <label class="form-label" for="patient-report-source-document">Plantilla de informe</label>
                  <input type="file" class="form-control" id="patient-report-source-document" name="source_document" accept=".pdf,.doc,.docx,.xls,.xlsx">
                  <div class="form-text">PDF, DOC, DOCX, XLS o XLSX. M&aacute;ximo 12 MB.</div>
                  <div id="patient-report-current-source" class="small text-muted mt-2"></div>
                </div>
                <div class="col-12">
                  <label class="form-label" for="patient-report-final-document">Versi&oacute;n final/oficial</label>
                  <input type="file" class="form-control" id="patient-report-final-document" name="final_document" accept=".pdf,.doc,.docx,.xls,.xlsx,image/jpeg,image/png,image/webp,image/gif">
                  <div class="form-text">PDF, DOC, DOCX, Excel o imagen. M&aacute;ximo 12 MB.</div>
                  <div id="patient-report-current-final" class="small text-muted mt-2"></div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary" id="btn-save-patient-report-config">
                <i class="bi bi-check2"></i> Guardar informe
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade" id="patientReportSuggestionsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Informes sugeridos</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="patient-report-suggestions-alert" class="alert d-none"></div>
            <div class="alert alert-info small mb-3">
              Se muestran informes de <?= htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?> con el mismo diagn&oacute;stico/objetivo.
            </div>
            <div class="mb-3">
              <label class="form-label" for="patient-report-suggestions-problem">Diagn&oacute;stico / objetivo</label>
              <select class="form-select" id="patient-report-suggestions-problem">
                <option value="">Cargando diagn&oacute;sticos...</option>
              </select>
            </div>
            <div id="patient-report-suggestions-body">
              <div class="text-center text-muted py-4">Cargando sugerencias...</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="upcomingAppointmentsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Pr&oacute;ximas citas</h5>
            <div class="d-flex align-items-center gap-2 ms-auto">
              <button class="btn btn-sm btn-outline-secondary btn-export-modal-table" type="button" data-table-target="#upcomingAppointmentsModal" data-export-type="print" title="Imprimir"><i class="bi bi-printer"></i></button>
              <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" title="Exportar citas"><i class="bi bi-download"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li><button class="dropdown-item btn-server-export" type="button" data-export-entity="appointments" data-export-format="json"><i class="bi bi-braces me-2"></i>JSON</button></li>
                  <li><button class="dropdown-item btn-server-export" type="button" data-export-entity="appointments" data-export-format="xlsx"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Excel</button></li>
                </ul>
              </div>
              <button type="button" class="btn-close m-0" data-bs-dismiss="modal"></button>
            </div>
          </div>
          <div class="modal-body">
            <div id="upcoming-appointments-alert" class="alert d-none"></div>
            <ul class="nav nav-tabs mb-3" id="upcoming-appointments-tabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="upcoming-list-tab" data-bs-toggle="tab" data-bs-target="#upcoming-list-panel" type="button" role="tab">Listado</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="cancelled-list-tab" data-bs-toggle="tab" data-bs-target="#cancelled-list-panel" type="button" role="tab">Canceladas</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="upcoming-planning-tab" data-bs-toggle="tab" data-bs-target="#upcoming-planning-panel" type="button" role="tab">Planning</button>
              </li>
            </ul>
            <div class="tab-content">
              <div class="tab-pane fade show active" id="upcoming-list-panel" role="tabpanel" aria-labelledby="upcoming-list-tab">
                <div class="row g-2 mb-3">
                  <div class="<?= $is_superadmin ? 'col-md-5' : 'col-md-7' ?>">
                    <input type="search" class="form-control" id="upcoming-appointments-search" placeholder="Buscar por <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>, email, profesional, servicio o pago">
                  </div>
                  <?php if ($is_superadmin): ?>
                    <div class="col-md-3">
                      <select class="form-select" id="upcoming-appointments-professional">
                        <option value="">Todos los profesionales</option>
                      </select>
                    </div>
                  <?php endif; ?>
                  <div class="<?= $is_superadmin ? 'col-md-4' : 'col-md-5' ?>">
                    <select class="form-select" id="upcoming-appointments-scope">
                      <option value="limit10">Pr&oacute;ximas 10 citas</option>
                      <option value="3days">Pr&oacute;ximos 3 d&iacute;as</option>
                      <option value="7days">Pr&oacute;ximos 7 d&iacute;as</option>
                      <option value="14days">Pr&oacute;ximos 14 d&iacute;as</option>
                      <option value="all">Todas las citas futuras</option>
                    </select>
                  </div>
                </div>
                <div class="table-responsive upcoming-appointments-table-wrap">
                  <table class="table align-middle">
                    <thead>
                      <tr>
                        <th>Fecha</th>
                        <th>Profesional</th>
                        <th><?= htmlspecialchars($patient_label_title_singular) ?></th>
                        <th>Servicio</th>
                        <th>Modalidad</th>
                        <th>Pago</th>
                        <th class="text-end no-export">Acciones</th>
                      </tr>
                    </thead>
                    <tbody id="upcoming-appointments-body">
                      <tr>
                        <td colspan="7" class="text-center text-muted py-4">Cargando...</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div class="text-end text-muted small mt-2" id="upcoming-appointments-count"></div>
              </div>
              <div class="tab-pane fade" id="cancelled-list-panel" role="tabpanel" aria-labelledby="cancelled-list-tab">
                <div class="row g-2 mb-3">
                  <div class="col-md-12">
                    <input type="search" class="form-control" id="cancelled-appointments-search" placeholder="Buscar por <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>, email, profesional, servicio o pago">
                  </div>
                </div>
                <div class="table-responsive upcoming-appointments-table-wrap">
                  <table class="table align-middle">
                    <thead>
                      <tr>
                        <th>Fecha cita</th>
                        <th>Cancelada</th>
                        <th>Profesional</th>
                        <th><?= htmlspecialchars($patient_label_title_singular, ENT_QUOTES, 'UTF-8') ?></th>
                        <th>Servicio</th>
                        <th>Modalidad</th>
                        <th>Pago</th>
                      </tr>
                    </thead>
                    <tbody id="cancelled-appointments-body">
                      <tr>
                        <td colspan="7" class="text-center text-muted py-4">Cargando...</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div class="text-end text-muted small mt-2" id="cancelled-appointments-count"></div>
              </div>
              <div class="tab-pane fade" id="upcoming-planning-panel" role="tabpanel" aria-labelledby="upcoming-planning-tab">
                <div class="row g-2 mb-3 justify-content-end">
                  <div class="col-md-4 col-lg-3">
                    <select class="form-select" id="upcoming-planning-scope">
                      <option value="today">Hoy</option>
                      <option value="tomorrow">Ma&ntilde;ana</option>
                      <option value="3days">Pr&oacute;ximos 3 d&iacute;as</option>
                      <option value="7days" selected>Pr&oacute;ximos 7 d&iacute;as</option>
                    </select>
                  </div>
                </div>
                <div id="upcoming-planning-wrap" class="upcoming-planning-wrap">
                  <div class="text-center text-muted py-4">Cargando planning...</div>
                </div>
                <div class="upcoming-planning-legend mt-3">
                  <span><i class="legend-free"></i> Libre</span>
                  <span><i class="legend-booked"></i> Cita</span>
                  <span><i class="legend-break"></i> Descanso</span>
                  <span><i class="legend-closed"></i> Cierre</span>
                  <span><i class="legend-unavailable"></i> No disponible</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="adminStatsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Estad&iacute;sticas</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="admin-stats-alert" class="alert d-none"></div>
            <ul class="nav nav-tabs mb-3" id="admin-reports-tabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="admin-stats-tab" data-bs-toggle="tab" data-bs-target="#admin-stats-panel" type="button" role="tab">Estad&iacute;sticas</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="admin-reports-tab" data-bs-toggle="tab" data-bs-target="#admin-reports-panel" type="button" role="tab">Listados</button>
              </li>
            </ul>
            <div class="tab-content">
              <div class="tab-pane fade show active" id="admin-stats-panel" role="tabpanel" aria-labelledby="admin-stats-tab">
                <div id="admin-stats-content">
                  <div class="text-center text-muted py-4">Cargando...</div>
                </div>
              </div>
              <div class="tab-pane fade" id="admin-reports-panel" role="tabpanel" aria-labelledby="admin-reports-tab">
                <div id="admin-reports-content">
                  <div class="text-center text-muted py-4">Cargando informes...</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

    <div class="modal fade" id="bonusesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="bonusesModalTitle">Bonos</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="bonuses-modal-alert" class="alert d-none"></div>
          <div id="bonus-catalog-panel" class="d-none">
            <div class="row g-3" id="bonus-catalog-list"></div>
          </div>
          <div id="bonus-list-panel" class="d-none">
            <div class="row g-2 mb-3 d-none" id="bonus-list-tools">
              <div class="<?= $is_superadmin ? 'col-md-5' : 'col-md-8' ?>">
                <input type="search" class="form-control" id="bonus-list-search" placeholder="<?= $is_superadmin ? 'Buscar por ' . htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') . ', email, bono, estado o profesional' : 'Buscar por ' . htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') . ', email, bono o estado' ?>">
              </div>
              <?php if ($is_superadmin): ?>
                <div class="col-md-3">
                  <select class="form-select" id="bonus-list-professional">
                    <option value="">Cargando profesionales...</option>
                  </select>
                </div>
              <?php endif; ?>
              <div class="col-md-4">
                <select class="form-select" id="bonus-list-sort">
                  <option value="date_desc">Compra mas reciente</option>
                  <option value="date_asc">Compra mas antigua</option>
                  <option value="patient_asc"><?= htmlspecialchars($patient_label_title_singular, ENT_QUOTES, 'UTF-8') ?> A-Z</option>
                  <option value="remaining_desc">Mas sesiones restantes</option>
                  <option value="remaining_asc">Menos sesiones restantes</option>
                </select>
              </div>
            </div>
            <div class="table-responsive admin-bonuses-table-wrap">
              <table class="table align-middle">
                <thead id="bonus-list-head"></thead>
                <tbody id="bonus-list-body"></tbody>
              </table>
            </div>
          </div>
          <div id="bonus-payment-options" class="d-none mt-3">
            <div class="small text-muted mb-2" id="bonus-payment-text"></div>
            <div class="d-flex gap-2 justify-content-center flex-wrap">
              <button class="btn btn-success btn-sm" id="btn-buy-bonus-card" type="button">
                <i class="bi bi-credit-card"></i> Pagar con tarjeta
              </button>
              <button class="btn btn-success btn-sm" id="btn-buy-bonus-bizum" type="button">
                <i class="bi bi-phone"></i> Pagar con Bizum
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($is_admin): ?>
    <div class="modal fade" id="patientWorkPlanTaskModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <form id="patient-work-plan-form">
            <div class="modal-header">
              <h5 class="modal-title" id="patient-work-plan-modal-title">Crear o importar tareas</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" id="patient-work-plan-id" name="task_id" value="0">
              <input type="hidden" id="patient-work-plan-patient-id" name="patient_id" value="0">
              <div id="patient-work-plan-template-block" class="mb-4">
                <label class="form-label" for="patient-work-plan-template">Mis plantillas de tareas</label>
                <div class="input-group">
                  <select class="form-select" id="patient-work-plan-template">
                    <option value="manual">Selecciona una plantilla</option>
                  </select>
                  <button type="button" class="btn btn-outline-primary" id="btn-import-work-plan-template">
                    <i class="bi bi-box-arrow-in-down"></i> Importar
                  </button>
                </div>
                <div class="form-text">Selecciona una de las plantillas configuradas por tu equipo.</div>
                <div class="alert alert-info py-2 px-3 mt-3 mb-0 d-none small" id="patient-work-plan-loading">
                  <span class="spinner-border spinner-border-sm me-2"></span>Espera mientras se cargan las plantillas.
                </div>
              </div>
              <div class="row g-3" id="patient-work-plan-manual-block">
                <div class="col-md-8">
                  <label class="form-label" for="patient-work-plan-title">T&iacute;tulo</label>
                  <input type="text" class="form-control" id="patient-work-plan-title" name="title" maxlength="180" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="patient-work-plan-priority">Prioridad</label>
                  <select class="form-select" id="patient-work-plan-priority" name="priority">
                    <option value="1">Alta</option>
                    <option value="2" selected>Normal</option>
                    <option value="3">Baja</option>
                  </select>
                </div>
                <div class="col-12">
                  <label class="form-label" for="patient-work-plan-description">Descripci&oacute;n / actividad</label>
                  <textarea class="form-control" id="patient-work-plan-description" name="description" rows="4"></textarea>
                </div>
                <div class="col-12" id="patient-work-plan-status-field">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="patient-work-plan-completed">
                    <label class="form-check-label" for="patient-work-plan-completed">Marcar como completada</label>
                  </div>
                </div>
                <div class="col-12">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="patient-work-plan-visible">
                    <label class="form-check-label" for="patient-work-plan-visible">El <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> puede ver esta tarea desde el Portal</label>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" id="btn-cancel-patient-work-plan-form" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary" id="btn-save-patient-work-plan">Guardar tarea</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade" id="invoicesModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Facturas emitidas</h5>
            <div class="d-flex align-items-center gap-2 ms-auto">
              <button class="btn btn-sm btn-outline-secondary btn-export-modal-table" type="button" data-table-target="#invoicesModal" data-export-type="print" title="Imprimir"><i class="bi bi-printer"></i></button>
              <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" title="Exportar facturas"><i class="bi bi-download"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li><button class="dropdown-item btn-server-export" type="button" data-export-entity="invoices" data-export-format="json"><i class="bi bi-braces me-2"></i>JSON</button></li>
                  <li><button class="dropdown-item btn-server-export" type="button" data-export-entity="invoices" data-export-format="xlsx"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Excel</button></li>
                </ul>
              </div>
              <button type="button" class="btn-close m-0" data-bs-dismiss="modal"></button>
            </div>
          </div>
          <div class="modal-body">
            <div id="invoices-modal-alert" class="alert d-none"></div>
            <div class="row g-2 mb-3">
              <div class="col-md-6">
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-search"></i></span>
                  <input type="search" class="form-control" id="invoices-search" placeholder="Buscar por factura, destinatario, NIF o concepto">
                </div>
              </div>
              <div class="col-md-3">
                <input type="date" class="form-control" id="invoices-date-from" aria-label="Fecha desde">
              </div>
              <div class="col-md-3">
                <input type="date" class="form-control" id="invoices-date-to" aria-label="Fecha hasta">
              </div>
            </div>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead>
                  <tr>
                    <th>Factura</th>
                    <th>Fecha</th>
                    <th>Destinatario</th>
                    <th>Concepto</th>
                    <th class="text-end">Total</th>
                    <th>VeriFactu</th>
                    <th class="text-end">Acciones</th>
                  </tr>
                </thead>
                <tbody id="invoices-list-body">
                  <tr><td colspan="7" class="text-center text-muted py-4">Cargando facturas...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="sendInvoiceEmailModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <h5 class="modal-title">Enviar factura por email</h5>
              <div class="small text-muted" id="send-invoice-email-number"></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <form id="send-invoice-email-form">
            <div class="modal-body">
              <div id="send-invoice-email-alert" class="alert d-none"></div>
              <input type="hidden" id="send-invoice-email-id">
              <label class="form-label" for="send-invoice-email-address">Dirección de email</label>
              <input type="email" class="form-control" id="send-invoice-email-address" required autocomplete="email">
              <div class="form-text">La factura se enviará como archivo PDF adjunto.</div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary" id="btn-send-invoice-email"><i class="bi bi-envelope"></i> Enviar factura</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <?php if ($is_admin && $time_tracking_plan_enabled): ?>
      <div class="modal fade" id="timeTrackingModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Control horario</h5>
              <button type="button" class="btn-close" id="time-tracking-modal-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div id="time-tracking-alert" class="alert d-none"></div>
              <div class="time-tracking-clock-card">
                <div class="time-tracking-clock-summary">
                  <div id="time-tracking-current-time" class="time-tracking-current-time">--:--:--</div>
                  <div class="small">Hola, <?= htmlspecialchars(trim((string) ($_SESSION['name'] ?? '')), ENT_QUOTES, 'UTF-8') ?></div>
                  <div id="time-tracking-state" class="fw-semibold">Comprobando...</div>
                </div>
                <div id="time-tracking-last-entry" class="text-muted small my-3 text-center"></div>
                <div class="d-grid gap-2">
                  <button class="btn btn-success time-tracking-action d-none" type="button" data-event-type="clock_in"><i class="bi bi-box-arrow-in-right"></i> Registrar entrada</button>
                  <button class="btn btn-warning time-tracking-action d-none" type="button" data-event-type="break_start"><i class="bi bi-pause-circle"></i> Iniciar descanso</button>
                  <button class="btn btn-warning time-tracking-action d-none" type="button" data-event-type="break_end"><i class="bi bi-play-circle"></i> Finalizar descanso</button>
                  <button class="btn btn-danger time-tracking-action d-none" type="button" data-event-type="clock_out"><i class="bi bi-box-arrow-right"></i> Registrar salida</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal fade" id="timeTrackingHistoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Control horario</h5>
              <div class="d-flex align-items-center gap-2 ms-auto">
                <button class="btn btn-sm btn-outline-secondary btn-export-modal-table" type="button"
                  data-table-target="#timeTrackingHistoryModal" data-export-type="print" title="Imprimir">
                  <i class="bi bi-printer"></i>
                </button>
                <div class="dropdown">
                  <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" title="Exportar">
                    <i class="bi bi-download"></i>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li><button class="dropdown-item time-tracking-export" type="button" data-format="json"><i class="bi bi-braces me-2"></i>JSON</button></li>
                    <li><button class="dropdown-item time-tracking-export" type="button" data-format="xlsx"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Excel</button></li>
                    <li><button class="dropdown-item time-tracking-export" type="button" data-format="pdf"><i class="bi bi-file-earmark-pdf me-2"></i>PDF</button></li>
                  </ul>
                </div>
                <button type="button" class="btn-close m-0" data-bs-dismiss="modal"></button>
              </div>
            </div>
            <div class="modal-body">
              <div id="time-tracking-report-alert" class="alert d-none"></div>
              <div class="row g-2 mb-3 align-items-end">
                <?php if ($is_superadmin): ?>
                  <div class="col-md-3">
                    <label class="form-label small" for="time-tracking-member-filter">Miembro</label>
                    <select class="form-select" id="time-tracking-member-filter"><option value="0">Todos</option></select>
                  </div>
                <?php endif; ?>
                <div class="<?= $is_superadmin ? 'col-md-3' : 'col-md-4' ?>">
                  <label class="form-label small" for="time-tracking-report-type">Informe</label>
                  <select class="form-select" id="time-tracking-report-type">
                    <option value="summary">Resumen</option>
                    <option value="entries">Entradas y salidas</option>
                    <option value="daily">Horas trabajadas por día y empleado</option>
                    <option value="average">Media de horas trabajadas</option>
                    <option value="overtime_daily">Registro diario de horas extra</option>
                    <option value="overtime_weekly">Registro semanal de horas extra</option>
                  </select>
                </div>
                <div class="<?= $is_superadmin ? 'col-md-3' : 'col-md-4' ?>">
                  <label class="form-label small" for="time-tracking-date-from">Desde</label>
                  <input type="date" class="form-control" id="time-tracking-date-from">
                </div>
                <div class="<?= $is_superadmin ? 'col-md-3' : 'col-md-4' ?>">
                  <label class="form-label small" for="time-tracking-date-to">Hasta</label>
                  <input type="date" class="form-control" id="time-tracking-date-to">
                </div>
              </div>
              <h6 id="time-tracking-report-title" class="mb-3">Resumen</h6>
              <div class="table-responsive">
                <table class="table table-sm align-middle small" id="time-tracking-report-table">
                  <thead id="time-tracking-report-head"></thead>
                  <tbody id="time-tracking-report-body"><tr><td class="text-center text-muted py-4">Cargando...</td></tr></tbody>
                </table>
              </div>
              <div id="time-tracking-report-count" class="text-muted small text-end"></div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($is_superadmin): ?>
      <div class="modal fade" id="appLogModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Log de actividad</h5>
              <div class="d-flex align-items-center gap-2 ms-auto">
                <button class="btn btn-sm btn-outline-secondary btn-export-modal-table" type="button" data-table-target="#appLogModal" data-export-type="print" title="Imprimir"><i class="bi bi-printer"></i></button>
                <div class="dropdown">
                  <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" title="Exportar log"><i class="bi bi-download"></i></button>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li><button class="dropdown-item btn-server-export" type="button" data-export-entity="logs" data-export-format="json"><i class="bi bi-braces me-2"></i>JSON</button></li>
                    <li><button class="dropdown-item btn-server-export" type="button" data-export-entity="logs" data-export-format="xlsx"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Excel</button></li>
                  </ul>
                </div>
                <button type="button" class="btn-close m-0" data-bs-dismiss="modal"></button>
              </div>
            </div>
            <div class="modal-body">
              <div id="app-log-alert" class="alert d-none"></div>
              <div class="row g-2 mb-3">
                <div class="col-md-6">
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="search" class="form-control" id="app-log-search" placeholder="Buscar por acci&oacute;n, usuario, canal o detalle">
                  </div>
                </div>
                <div class="col-md-3">
                  <input type="date" class="form-control" id="app-log-date-from" aria-label="Fecha desde">
                </div>
                <div class="col-md-3">
                  <input type="date" class="form-control" id="app-log-date-to" aria-label="Fecha hasta">
                </div>
              </div>
              <div class="table-responsive">
                <table class="table table-sm align-middle small">
                  <thead>
                    <tr>
                      <th>Fecha</th>
                      <th>Usuario</th>
                      <th>Acci&oacute;n</th>
                      <th>Canal</th>
                      <th>Estado</th>
                      <th>Detalle</th>
                    </tr>
                  </thead>
                  <tbody id="app-log-list-body">
                    <tr><td colspan="6" class="text-center text-muted py-4">Cargando log...</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($is_superadmin): ?>
      <div class="modal fade" id="initialOnboardingModal" tabindex="-1" aria-labelledby="initial-onboarding-title" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
          <div class="modal-content onboarding-modal-content">
            <div class="modal-header onboarding-modal-header">
              <div>
                <div class="onboarding-kicker"><i class="bi bi-stars me-1"></i> Primeros pasos</div>
                <h5 class="modal-title" id="initial-onboarding-title">Vamos a preparar tu espacio</h5>
              </div>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="onboarding-progress-wrap">
              <div class="onboarding-progress" id="initial-onboarding-progress">
                <?php for ($onboarding_step = 1; $onboarding_step <= 5; $onboarding_step++): ?>
                  <div class="onboarding-progress-step" data-step="<?= $onboarding_step ?>">
                    <span><?= $onboarding_step ?></span>
                    <small><?= ['Tu espacio', 'Datos legales', 'Tu estilo', 'Portal', 'Todo listo'][$onboarding_step - 1] ?></small>
                  </div>
                <?php endfor; ?>
              </div>
            </div>
            <div class="modal-body onboarding-modal-body">
              <div id="initial-onboarding-alert" class="alert d-none" role="alert"></div>

              <section class="onboarding-step" data-step="1">
                <div class="onboarding-step-heading">
                  <div class="onboarding-step-icon"><i class="bi bi-stars"></i></div>
                  <div>
                    <h3>¡Hola<span id="onboarding-contact-greeting"></span>!</h3>
                    <p>Gracias por confiar en SimplyGest Praxis. Este asistente es opcional, pero será todo mucho más cómodo si lo completas ahora.</p>
                  </div>
                </div>
                <div class="onboarding-form-card">
                  <div class="row g-4">
                    <div class="col-lg-6">
                      <label class="form-label" for="onboarding-sector">Sector</label>
                      <select class="form-select" id="onboarding-sector">
                        <?php foreach ($sector_texts_options as $sector_option): ?>
                          <option value="<?= htmlspecialchars($sector_option['key'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sector_option['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-lg-6">
                      <label class="form-label" for="onboarding-timezone">Zona horaria</label>
                      <select class="form-select" id="onboarding-timezone">
                        <option value="Europe/Madrid">Pen&iacute;nsula y Baleares</option>
                        <option value="Atlantic/Canary">Islas Canarias</option>
                        <option value="UTC">UTC</option>
                      </select>
                    </div>
                    <div class="col-lg-6">
                      <label class="form-label" for="onboarding-country">Pa&iacute;s</label>
                      <select class="form-select" id="onboarding-country">
                        <option value="ES">Espa&ntilde;a</option>
                        <option value="OT">Otro</option>
                      </select>
                    </div>
                    <div class="col-lg-6" id="onboarding-spanish-province-wrap">
                      <label class="form-label" for="onboarding-spanish-province">Provincia</label>
                      <select class="form-select" id="onboarding-spanish-province"></select>
                    </div>
                    <div class="col-lg-6 d-none" id="onboarding-other-province-wrap">
                      <label class="form-label" for="onboarding-other-province">Provincia / regi&oacute;n</label>
                      <input type="text" class="form-control" id="onboarding-other-province">
                    </div>
                    <div class="col-12">
                      <label class="form-label" for="onboarding-address">Direcci&oacute;n del centro o despacho</label>
                      <input type="text" class="form-control" id="onboarding-address" placeholder="Calle, número, planta...">
                    </div>
                    <div class="col-lg-8">
                      <label class="form-label" for="onboarding-city">Localidad</label>
                      <input type="text" class="form-control" id="onboarding-city">
                    </div>
                    <div class="col-lg-4">
                      <label class="form-label" for="onboarding-postal-code">C&oacute;digo postal</label>
                      <input type="text" class="form-control" id="onboarding-postal-code" maxlength="12">
                    </div>
                  </div>
                </div>
              </section>

              <section class="onboarding-step d-none" data-step="2">
                <div class="onboarding-step-heading">
                  <div class="onboarding-step-icon"><i class="bi bi-building-check"></i></div>
                  <div><h3>Datos legales y fiscales</h3><p>Con esto dejamos preparada la base para documentos, facturas y VeriFactu.</p></div>
                </div>
                <div class="onboarding-form-card">
                  <div class="row g-4">
                    <div class="col-lg-8">
                      <label class="form-label" for="onboarding-legal-owner">Titular o raz&oacute;n social</label>
                      <input type="text" class="form-control" id="onboarding-legal-owner">
                    </div>
                    <div class="col-lg-4">
                      <label class="form-label" for="onboarding-legal-nif">NIF</label>
                      <input type="text" class="form-control" id="onboarding-legal-nif">
                    </div>
                    <div class="col-lg-4">
                      <label class="form-label" for="onboarding-taxpayer-type">Tipo</label>
                      <select class="form-select" id="onboarding-taxpayer-type">
                        <option value="self_employed">Persona f&iacute;sica</option>
                        <option value="company">Persona jur&iacute;dica</option>
                      </select>
                    </div>
                    <div class="col-lg-4">
                      <label class="form-label" for="onboarding-license-number">N.&ordm; colegiado</label>
                      <input type="text" class="form-control" id="onboarding-license-number">
                    </div>
                    <div class="col-lg-4">
                      <label class="form-label" for="onboarding-health-registry">Registro sanitario</label>
                      <input type="text" class="form-control" id="onboarding-health-registry">
                    </div>
                    <div class="col-12">
                      <label class="form-label" for="onboarding-professional-college">Colegio profesional</label>
                      <input type="text" class="form-control" id="onboarding-professional-college">
                    </div>
                  </div>
                </div>
              </section>

              <section class="onboarding-step d-none" data-step="3">
                <div class="onboarding-step-heading">
                  <div class="onboarding-step-icon"><i class="bi bi-palette"></i></div>
                  <div><h3>Dale tu estilo</h3><p>Estos datos serán la primera impresión de tu espacio y de tu web.</p></div>
                </div>
                <div class="onboarding-form-card">
                  <div class="row g-4">
                    <div class="col-lg-6">
                      <label class="form-label" for="onboarding-app-name">T&iacute;tulo de la web</label>
                      <input type="text" class="form-control" id="onboarding-app-name">
                    </div>
                    <div class="col-lg-6">
                      <label class="form-label" for="onboarding-site-phone">Tel&eacute;fono</label>
                      <input type="text" class="form-control" id="onboarding-site-phone">
                    </div>
                    <div class="col-12">
                      <label class="form-label" for="onboarding-tagline">Eslogan</label>
                      <input type="text" class="form-control" id="onboarding-tagline" placeholder="Una frase breve que explique lo que haces">
                    </div>
                    <div class="col-12">
                      <label class="form-label">Color principal</label>
                      <div class="onboarding-color-picker">
                        <input type="color" class="form-control form-control-color" id="onboarding-primary-color" value="#4285f4">
                        <input type="text" class="form-control" id="onboarding-primary-color-text" value="#4285f4" maxlength="7">
                        <span class="onboarding-color-preview" id="onboarding-color-preview">Así se verá</span>
                      </div>
                    </div>
                    <div class="col-12">
                      <label class="form-label" for="onboarding-initial-view">Elige qué quieres ver primero cuando inicies sesión</label>
                      <select class="form-select" id="onboarding-initial-view">
                        <option value="dashboard">Dashboard</option>
                        <option value="month">Agenda mensual</option>
                        <option value="week">Agenda semanal</option>
                        <option value="patients"><?= htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="upcoming">Citas</option>
                      </select>
                      <div class="form-text">Esta será la primera sección que se abrirá al entrar en la app. Podrás cambiarla cuando quieras.</div>
                    </div>
                  </div>
                </div>
              </section>

              <section class="onboarding-step d-none" data-step="4">
                <div class="onboarding-step-heading">
                  <div class="onboarding-step-icon"><i class="bi bi-window"></i></div>
                  <div><h3>Portal y reservas</h3><p>Tu portal permite que tus pacientes o clientes consulten y gestionen sus citas desde su propio espacio.</p></div>
                </div>
                <div class="onboarding-form-card">
                  <div class="onboarding-portal-card">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" role="switch" id="onboarding-portal-enabled">
                      <label class="form-check-label fw-semibold" for="onboarding-portal-enabled">Habilitar el Portal de <?= htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') ?></label>
                    </div>
                    <div class="input-group mt-3">
                      <input type="text" class="form-control" id="onboarding-portal-url" readonly>
                      <button class="btn btn-outline-primary" type="button" id="btn-copy-onboarding-portal-url" title="Copiar URL"><i class="bi bi-clipboard"></i></button>
                    </div>
                    <div class="small text-muted mt-2" id="onboarding-portal-plan-help"></div>
                    <div class="mt-4 pt-3 border-top">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="onboarding-registration-requires-invite">
                        <label class="form-check-label fw-semibold" for="onboarding-registration-requires-invite">Permitir el registro solo mediante invitación</label>
                      </div>
                      <div class="form-text">Si lo activas, cada <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> necesitará una invitación enviada desde su ficha para crear su contraseña y poder acceder al Portal. Si lo desactivas, podrán registrarse directamente desde la dirección web del Portal.</div>
                    </div>
                  </div>
                  <div class="mt-4">
                    <label class="form-label" for="onboarding-delivery-mode">Modalidades de las citas</label>
                    <select class="form-select" id="onboarding-delivery-mode">
                      <option value="both">Presencial y online</option>
                      <option value="presencial">Solo presencial</option>
                      <option value="online">Solo online</option>
                    </select>
                  </div>
                </div>
              </section>

              <section class="onboarding-step d-none" data-step="5">
                <div class="onboarding-finish">
                  <div class="onboarding-finish-icon"><i class="bi bi-check2"></i></div>
                  <h3>Ya está (casi) todo listo</h3>
                  <p>Puedes crear tu primer <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>, configurar los servicios que ofreces, precios... o simplemente navegar por la app y descubrirlo todo a tu ritmo.</p>
                  <div class="onboarding-next-actions">
                    <button type="button" class="btn btn-outline-primary onboarding-finish-action" data-action="patient"><i class="bi bi-person-plus"></i><span>Crear mi primer <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?></span></button>
                    <button type="button" class="btn btn-outline-primary onboarding-finish-action" data-action="services"><i class="bi bi-grid"></i><span>Configurar servicios y precios</span></button>
                    <button type="button" class="btn btn-outline-primary onboarding-finish-action" data-action="team"><i class="bi bi-people"></i><span>Añadir miembros del equipo</span></button>
                  </div>
                </div>
              </section>
            </div>
            <div class="modal-footer onboarding-modal-footer">
              <button type="button" class="btn btn-link text-muted me-auto" data-bs-dismiss="modal">Ahora no</button>
              <button type="button" class="btn btn-outline-secondary d-none" id="btn-onboarding-previous"><i class="bi bi-arrow-left me-1"></i>Anterior</button>
              <button type="button" class="btn btn-primary" id="btn-onboarding-next">Continuar <i class="bi bi-arrow-right ms-1"></i></button>
            </div>
          </div>
        </div>
      </div>
      <div class="modal fade" id="onboardingApplyingModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-sm modal-dialog-centered">
          <div class="modal-content onboarding-applying-content">
            <div class="spinner-border text-primary mx-auto mb-3" role="status" aria-hidden="true"></div>
            <h5>Aplicando configuraci&oacute;n</h5>
            <p class="text-muted mb-0">Estamos preparando el dashboard con tus preferencias...</p>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="modal fade" id="settingsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl">
        <div class="modal-content settings-modal-content">
          <div class="modal-header">
            <div>
              <h5 class="modal-title" id="settings-modal-title">Configuración</h5>
              <div class="small text-muted d-none" id="settings-modal-breadcrumb"></div>
            </div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <ul class="nav nav-tabs mb-4" id="settings-tabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="closed-days-tab" data-bs-toggle="tab" data-bs-target="#closed-days-panel"
                  type="button" role="tab">General</button>
              </li>
              <li class="nav-item <?= $is_superadmin ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link" id="services-settings-tab" data-bs-toggle="tab" data-bs-target="#services-settings-panel"
                  type="button" role="tab">Precios</button>
              </li>
              <li class="nav-item <?= $is_superadmin ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link" id="bonuses-settings-tab" data-bs-toggle="tab" data-bs-target="#bonuses-settings-panel"
                  type="button" role="tab">Bonos</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="booking-settings-tab" data-bs-toggle="tab" data-bs-target="#booking-settings-panel"
                  type="button" role="tab">Horarios</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="closures-settings-tab" data-bs-toggle="tab" data-bs-target="#closures-settings-panel"
                  type="button" role="tab">Vacaciones y cierres</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="task-templates-settings-tab" data-bs-toggle="tab" data-bs-target="#task-templates-settings-panel"
                  type="button" role="tab">Mis Tareas</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link <?= $questionnaires_plan_enabled ? '' : 'plan-locked' ?>" id="questionnaires-settings-tab"
                  <?= $questionnaires_plan_enabled ? 'data-bs-toggle="tab" data-bs-target="#questionnaires-settings-panel"' : 'disabled title="Disponible en el plan Summum"' ?>
                  type="button" role="tab">Mis Cuestionarios<?= $questionnaires_plan_enabled ? '' : ' <i class="bi bi-lock-fill"></i>' ?></button>
              </li>
              <li class="nav-item <?= $is_superadmin ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link <?= $online_payments_plan_enabled ? '' : 'plan-locked' ?>" id="payment-settings-tab"
                  <?= $online_payments_plan_enabled ? 'data-bs-toggle="tab" data-bs-target="#payment-settings-panel"' : 'disabled title="Disponible en el plan Summum"' ?>
                  type="button" role="tab">Pago online<?= $online_payments_plan_enabled ? '' : ' <i class="bi bi-lock-fill"></i>' ?></button>
              </li>
              <li class="nav-item <?= $is_superadmin ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link <?= $email_reminders_plan_enabled ? '' : 'plan-locked' ?>" id="email-settings-tab"
                  <?= $email_reminders_plan_enabled ? 'data-bs-toggle="tab" data-bs-target="#email-settings-panel"' : 'disabled title="Disponible en los planes Magister y Summum"' ?>
                  type="button" role="tab">Envío de emails<?= $email_reminders_plan_enabled ? '' : ' <i class="bi bi-lock-fill"></i>' ?></button>
              </li>
              <li class="nav-item <?= $is_superadmin ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link <?= $sms_reminders_plan_enabled ? '' : 'plan-locked' ?>" id="sms-settings-tab"
                  <?= $sms_reminders_plan_enabled ? 'data-bs-toggle="tab" data-bs-target="#sms-settings-panel"' : 'disabled title="Disponible en los planes Magister y Summum"' ?>
                  type="button" role="tab">SMS<?= $sms_reminders_plan_enabled ? '' : ' <i class="bi bi-lock-fill"></i>' ?></button>
              </li>
              <li class="nav-item <?= $is_superadmin ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link <?= $calendar_sync_plan_enabled ? '' : 'plan-locked' ?>" id="calendar-settings-tab"
                  <?= $calendar_sync_plan_enabled ? 'data-bs-toggle="tab" data-bs-target="#calendar-settings-panel"' : 'disabled title="Disponible en los planes Magister y Summum"' ?>
                  type="button" role="tab">Calendario online<?= $calendar_sync_plan_enabled ? '' : ' <i class="bi bi-lock-fill"></i>' ?></button>
              </li>
              <li class="nav-item <?= $is_superadmin ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link" id="interface-settings-tab" data-bs-toggle="tab" data-bs-target="#interface-settings-panel"
                  type="button" role="tab">Interfaz</button>
              </li>
              <li class="nav-item <?= $is_superadmin ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link" id="legal-settings-tab" data-bs-toggle="tab" data-bs-target="#legal-settings-panel"
                  type="button" role="tab">Legal</button>
              </li>
              <?php if ($is_superadmin): ?>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" id="cabinet-settings-tab" data-bs-toggle="tab" data-bs-target="#cabinet-settings-panel"
                    type="button" role="tab">Equipo</button>
                </li>
              <?php endif; ?>
              <li class="nav-item <?= $is_superadmin ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link <?= $billing_plan_enabled ? '' : 'plan-locked' ?>" id="billing-settings-tab"
                  <?= $billing_plan_enabled ? 'data-bs-toggle="tab" data-bs-target="#billing-settings-panel"' : 'disabled title="Disponible en un plan superior"' ?>
                  type="button" role="tab">Facturaci&oacute;n<?= $billing_plan_enabled ? '' : ' <i class="bi bi-lock-fill"></i>' ?></button>
              </li>
              <li class="nav-item <?= $is_superadmin ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link <?= $tenant_signature_plan_enabled ? '' : 'plan-locked' ?>" id="signature-settings-tab"
                  <?= $tenant_signature_plan_enabled ? 'data-bs-toggle="tab" data-bs-target="#signature-settings-panel"' : 'disabled title="Disponible en los planes Magister y Summum"' ?>
                  type="button" role="tab">Certificado digital<?= $tenant_signature_plan_enabled ? '' : ' <i class="bi bi-lock-fill"></i>' ?></button>
              </li>
              <li class="nav-item <?= $is_superadmin ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link <?= $time_tracking_plan_enabled ? '' : 'plan-locked' ?>" id="time-tracking-settings-tab"
                  <?= $time_tracking_plan_enabled ? 'data-bs-toggle="tab" data-bs-target="#time-tracking-settings-panel"' : 'disabled title="Disponible en el plan Summum"' ?>
                  type="button" role="tab">Control horario<?= $time_tracking_plan_enabled ? '' : ' <i class="bi bi-lock-fill"></i>' ?></button>
              </li>
              <li class="nav-item <?= $is_superadmin ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link" id="subscription-settings-tab" data-bs-toggle="tab" data-bs-target="#subscription-settings-panel"
                  type="button" role="tab">Suscripci&oacute;n</button>
              </li>
            </ul>

            <div id="settings-save-alert" class="alert d-none"></div>

            <div class="tab-content">
              <div class="tab-pane fade show active" id="closed-days-panel" role="tabpanel" aria-labelledby="closed-days-tab">
                <div id="general-settings-alert" class="alert d-none"></div>
                <div class="row g-3 align-items-center mb-4 <?= $is_superadmin ? '' : 'd-none' ?>">
                  <label class="col-lg-2 col-form-label" for="tenant-timezone">Zona horaria</label>
                  <div class="col-lg-10">
                    <select class="form-select" id="tenant-timezone">
                      <option value="Europe/Madrid">Pen&iacute;nsula y Baleares</option>
                      <option value="Atlantic/Canary">Islas Canarias</option>
                      <option value="UTC">UTC</option>
                    </select>
                  </div>
                </div>
                <div class="row g-3 align-items-start mb-4 <?= $is_superadmin ? '' : 'd-none' ?>">
                  <div class="col-lg-12">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" id="online-booking-enabled" checked>
                      <label class="form-check-label" for="online-booking-enabled">Habilitar Portal de <?= htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') ?></label>
                    </div>
                    <div class="form-text">Si se desactiva, los <?= htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?> no tendr&aacute;n acceso a la reserva de citas, y ser&aacute; de uso interno por los profesionales.</div>
                  </div>
                </div>
                <div class="row g-3 align-items-start mb-4 <?= $is_superadmin ? '' : 'd-none' ?>">
                  <div class="col-lg-12">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" id="patient-registration-requires-invite" checked>
                      <label class="form-check-label" for="patient-registration-requires-invite">Requerir invitación para registrarse en el Portal</label>
                    </div>
                  </div>
                </div>

                <hr class="my-4">
                <div class="row g-3 align-items-center mb-4">
                  <label class="col-lg-2 col-form-label" for="appointment-delivery-mode">Modalidades</label>
                  <div class="col-lg-10">
                    <select class="form-select" id="appointment-delivery-mode">
                      <option value="both">Presencial y online</option>
                      <option value="presencial">Solo presencial</option>
                      <option value="online">Solo online</option>
                    </select>
                  </div>
                </div>

                <hr class="my-4">
                <div class="row g-3 align-items-start mb-4">
                  <label class="col-lg-2 col-form-label">Servicios</label>
                  <div class="col-lg-10">
                    <div class="row g-2" id="available-session-types-list"></div>
                    <button type="button" class="btn btn-outline-primary btn-sm mt-3 <?= $is_superadmin ? '' : 'd-none' ?>" id="btn-add-session-service">
                      <i class="bi bi-plus-lg"></i> Nuevo servicio
                    </button>
                  </div>
                </div>

                <hr class="my-4">
                <div class="row g-3 align-items-start mb-4 <?= $is_superadmin ? '' : 'd-none' ?>">
                  <label class="col-lg-2 col-form-label">Salas / ubicaciones</label>
                  <div class="col-lg-10">
                    <div class="row g-2" id="appointment-locations-list"></div>
                    <button type="button" class="btn btn-outline-primary btn-sm mt-3 <?= $custom_locations_plan_enabled ? '' : 'plan-locked' ?>" id="btn-add-appointment-location"
                      <?= $custom_locations_plan_enabled ? '' : 'disabled title="Disponible en los planes Magister y Summum"' ?>>
                      <i class="bi <?= $custom_locations_plan_enabled ? 'bi-plus-lg' : 'bi-lock-fill' ?>"></i> Nueva ubicaci&oacute;n
                    </button>
                    <div class="form-text"><?= $custom_locations_plan_enabled
                      ? 'Crea diferentes salas/ubicaciones para las citas. La ubicación "Predeterminada" se refiere a la dirección de tu centro/despacho.'
                      : 'Este plan permite utilizar las ubicaciones Predeterminada y A domicilio. Las ubicaciones personalizadas están disponibles en Magister y Summum.' ?></div>
                  </div>
                </div>

                <hr class="my-4">
                <div class="row g-3 align-items-start mb-4">
                  <label class="col-lg-2 col-form-label">Duraciones</label>
                  <div class="col-lg-10">
                    <div class="row g-2" id="available-session-durations-list"></div>
                    <button type="button" class="btn btn-outline-primary btn-sm mt-3 <?= $is_superadmin ? '' : 'd-none' ?>" id="btn-add-session-duration">
                      <i class="bi bi-plus-lg"></i> Nueva duraci&oacute;n
                    </button>
                    <div class="mt-3 <?= $is_superadmin ? '' : 'd-none' ?>">
                      <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="display-effective-duration-enabled">
                        <label class="form-check-label fw-semibold" for="display-effective-duration-enabled">Mostrar duraci&oacute;n efectiva al <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?></label>
                      </div>
                      <div class="row g-2 align-items-center">
                        <div class="col-sm-4 col-md-3">
                          <div class="input-group input-group-sm">
                            <input type="number" class="form-control" id="display-duration-offset-minutes" min="0" max="30" step="1" value="5">
                            <span class="input-group-text">min</span>
                          </div>
                        </div>
                        <div class="col-sm-8 col-md-9">
                          <div class="form-text mb-0">Resta estos minutos al horario mostrado. Ejemplo: un hueco real de 10:00 a 11:00 se mostrar&aacute; como 10:00 a 10:55.</div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="d-none">
                <hr class="my-4">
                <div class="row">
                  <div class="col-md-6 mb-3">
                    <label class="form-label" for="appointment-price">Precio individual presencial</label>
                    <div class="input-group">
                      <input type="number" class="form-control" id="appointment-price" min="0" step="0.01">
                      <span class="input-group-text">€</span>
                    </div>
                  </div>
                  <div class="col-md-6 mb-3" id="online-appointment-price-row">
                    <label class="form-label" for="online-appointment-price">Precio individual online</label>
                    <div class="input-group">
                      <input type="number" class="form-control" id="online-appointment-price" min="0" step="0.01">
                      <span class="input-group-text">€</span>
                    </div>
                  </div>
                  <div class="col-md-6 mb-3 couple-price-row">
                    <label class="form-label" for="couple-appointment-price">Precio pareja presencial</label>
                    <div class="input-group">
                      <input type="number" class="form-control" id="couple-appointment-price" min="0" step="0.01">
                      <span class="input-group-text">€</span>
                    </div>
                  </div>
                  <div class="col-md-6 mb-3 couple-price-row" id="online-couple-appointment-price-row">
                    <label class="form-label" for="online-couple-appointment-price">Precio pareja online</label>
                    <div class="input-group">
                      <input type="number" class="form-control" id="online-couple-appointment-price" min="0" step="0.01">
                      <span class="input-group-text">€</span>
                    </div>
                  </div>
                </div>

                </div>

              </div>

              <div class="tab-pane fade" id="services-settings-panel" role="tabpanel" aria-labelledby="services-settings-tab">
                <div id="services-settings-alert" class="alert d-none"></div>
                <?php if ($discounts_plan_enabled): ?>
                <div class="row g-3 align-items-start mb-3">
                  <div class="col-lg-4">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" id="discount-period-enabled">
                      <label class="form-check-label" for="discount-period-enabled">Activar periodo de descuento</label>
                    </div>
                  </div>
                  <div class="col-lg-4 discount-period-field">
                    <label class="form-label" for="discount-period-start-date">Desde</label>
                    <input type="date" class="form-control" id="discount-period-start-date">
                  </div>
                  <div class="col-lg-4 discount-period-field">
                    <label class="form-label" for="discount-period-end-date">Hasta</label>
                    <input type="date" class="form-control" id="discount-period-end-date">
                  </div>
                </div>
                <div class="form-check form-switch mb-4 discount-period-field">
                  <input class="form-check-input" type="checkbox" id="discount-show-public">
                  <label class="form-check-label" for="discount-show-public">Mostrar el descuento y los precios rebajados tambi&eacute;n en la web</label>
                </div>
                <?php endif; ?>
                <div class="table-responsive services-table-wrap">
                  <table class="table align-middle services-table">
                    <thead>
                      <tr>
                        <th>Servicio</th>
                        <th>Duración</th>
                        <th>Modalidad</th>
                        <th>Precio</th>
                        <?php if ($discounts_plan_enabled): ?><th>% Descuento</th><?php endif; ?>
                        <?php if ($billing_plan_enabled): ?>
                          <th id="services-tax-column-title">IVA</th>
                        <?php endif; ?>
                      </tr>
                    </thead>
                    <tbody id="services-settings-body">
                      <tr>
                        <td colspan="<?= 4 + ($discounts_plan_enabled ? 1 : 0) + ($billing_plan_enabled ? 1 : 0) ?>" class="text-muted text-center py-4">Cargando precios...</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="tab-pane fade" id="bonuses-settings-panel" role="tabpanel" aria-labelledby="bonuses-settings-tab">
                <div id="bonuses-settings-alert" class="alert d-none"></div>
                <div class="form-check form-switch mb-4">
                  <input class="form-check-input" type="checkbox" id="bonuses-enabled">
                  <label class="form-check-label" for="bonuses-enabled">Habilitar compra de bonos</label>
                </div>
                <div class="form-check form-switch mb-4">
                  <input class="form-check-input" type="checkbox" id="create-compensation-bonus-on-paid-cancel" checked>
                  <label class="form-check-label" for="create-compensation-bonus-on-paid-cancel">Crear un bono/vale al cancelar una cita que haya sido pagada</label>
                  <div class="form-text">Si una cita pagada con tarjeta o Bizum se cancela, se generar&aacute; un vale interno de 1 sesi&oacute;n para el <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>.</div>
                </div>
                <div id="bonuses-config-block">
                  <div class="table-responsive services-table-wrap">
                    <table class="table align-middle services-table">
                      <thead>
                        <tr>
                          <th>Bono</th>
                          <th>Sesiones</th>
                          <th>Precio</th>
                          <th class="text-center">Activo</th>
                        </tr>
                      </thead>
                      <tbody id="bonuses-settings-body">
                        <tr>
                          <td colspan="4" class="text-muted text-center py-4">Cargando bonos...</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <div class="tab-pane fade" id="task-templates-settings-panel" role="tabpanel" aria-labelledby="task-templates-settings-tab">
                <div id="task-templates-alert" class="alert d-none"></div>
                <div class="alert alert-info d-flex align-items-start gap-2 small">
                  <i class="bi bi-collection fs-5"></i>
                  <div>Desde aqu&iacute; puedes crear plantillas o grupos de tareas predefinidas que suelas reutilizar habitualmente con tus <?= htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?>. Cada plantilla puede contener distintas tareas, archivos, etc.</div>
                </div>
                <div class="<?= $is_superadmin ? '' : 'd-none' ?>">
                  <div class="row g-3 align-items-start mb-4">
                    <div class="col-12">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="patient-tasks-visible-default">
                        <label class="form-check-label" for="patient-tasks-visible-default">Publicar las tareas del <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> en su Portal</label>
                      </div>
                      <div class="form-text">Indica si quieres que, por defecto, las tareas que asignes a tus <?= htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?> est&eacute;n visibles en su portal. Tambi&eacute;n puedes hacerlo de manera individual con cada tarea/<?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> sobre la marcha.</div>
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-4">
                    <div class="col-12">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="work-plan-task-status-enabled" checked>
                        <label class="form-check-label" for="work-plan-task-status-enabled">Permitir marcar tareas como completadas</label>
                      </div>
                      <div class="form-text">Si se desactiva, el plan de trabajo mostrar&aacute; las tareas, rutinas o pautas sin estados pendiente/completada ni botones de completar.</div>
                    </div>
                  </div>
                  <hr class="my-4">
                </div>
                <div class="task-template-list-wrap">
                  <div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
                    <h6 class="mb-0">Plantillas disponibles</h6>
                    <div class="d-flex gap-2 flex-wrap">
                      <button type="button" class="btn btn-primary btn-sm" id="btn-new-task-template">
                        <i class="bi bi-plus-lg"></i> Nueva plantilla
                      </button>
                    </div>
                  </div>
                  <div id="task-templates-list" class="task-template-list">
                    <div class="text-center text-muted py-4">Cargando plantillas...</div>
                  </div>
                </div>
              </div>

              <div class="tab-pane fade" id="questionnaires-settings-panel" role="tabpanel" aria-labelledby="questionnaires-settings-tab">
                <div id="questionnaires-alert" class="alert d-none small"></div>
                <div class="alert alert-info d-flex align-items-start gap-2 small">
                  <i class="bi bi-ui-checks fs-5"></i>
                  <div>Crea cuestionarios reutilizables para tus <?= htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?>. Puedes compartirlos con todo el equipo o mantenerlos privados para tu uso personal.</div>
                </div>
                <div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
                  <h6 class="mb-0">Cuestionarios disponibles</h6>
                  <button type="button" class="btn btn-primary btn-sm" id="btn-new-questionnaire"><i class="bi bi-plus-lg"></i> Nuevo cuestionario</button>
                </div>
                <div id="questionnaires-list"><div class="text-center text-muted py-4">Abre esta pesta&ntilde;a para cargar los cuestionarios.</div></div>
              </div>

              <div class="tab-pane fade" id="interface-settings-panel" role="tabpanel" aria-labelledby="interface-settings-tab">
                <div id="interface-settings-alert" class="alert d-none"></div>
                <div class="interface-settings-compact">
                  <div class="row g-3 align-items-center mb-3">
                    <label class="col-lg-2 col-form-label" for="app-name">Título</label>
                    <div class="col-lg-10">
                      <input type="text" class="form-control" id="app-name" placeholder="SimplyGest Praxis">
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-3 <?= $public_site_enabled ? '' : 'd-none' ?>" id="interface-site-tagline-row">
                    <label class="col-lg-2 col-form-label" for="site-tagline">Eslogan</label>
                    <div class="col-lg-10">
                      <input type="text" class="form-control" id="site-tagline" placeholder="">
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-3">
                    <label class="col-lg-2 col-form-label" for="site-phone">Teléfono</label>
                    <div class="col-lg-10">
                      <input type="text" class="form-control" id="site-phone" placeholder="Ej. 600 000 000">
                      <?php if ($public_site_enabled): ?><div class="form-text">Si lo rellenas, aparecerá en la página principal junto a la opción de pedir cita.</div><?php endif; ?>
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-4">
                    <label class="col-lg-2 col-form-label" for="primary-color">Color principal</label>
                    <div class="col-lg-10">
                      <div class="d-flex gap-2 align-items-center">
                        <input type="color" class="form-control form-control-color" id="primary-color" value="#4285f4" title="Elige el color principal">
                        <input type="text" class="form-control" id="primary-color-text" value="#4285f4" maxlength="7" style="max-width: 120px;">
                      </div>
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-3">
                    <label class="col-lg-2 col-form-label" for="profile-image">Logotipo</label>
                    <div class="col-lg-4">
                      <input type="file" class="form-control" id="profile-image" accept="image/jpeg,image/png,image/webp,image/gif">
                      <div class="d-flex align-items-center gap-3 mt-3" id="profile-image-preview-row" style="display: none !important;">
                        <img src="" alt="" class="settings-image-preview" id="profile-image-preview">
                        <div class="small text-muted" id="profile-image-status"></div>
                      </div>
                    </div>
                    <label class="col-lg-2 col-form-label" for="landing-image">Imagen principal</label>
                    <div class="col-lg-4">
                      <input type="file" class="form-control" id="landing-image" accept="image/jpeg,image/png,image/webp,image/gif">
                      <div class="d-flex align-items-center gap-3 mt-3" id="landing-image-preview-row" style="display: none !important;">
                        <img src="" alt="" class="settings-image-preview" id="landing-image-preview">
                        <div class="small text-muted" id="landing-image-status"></div>
                      </div>
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-4">
                    <div class="col-lg-10 offset-lg-2">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="show-profile-image-public">
                        <label class="form-check-label" for="show-profile-image-public">Mostrar también esta imagen en login y registro</label>
                      </div>
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-4 <?= $public_site_enabled ? '' : 'd-none' ?>" id="interface-public-pages-row">
                    <label class="col-lg-2 col-form-label">Páginas secundarias</label>
                    <div class="col-lg-10">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="show-prices-public">
                        <label class="form-check-label" for="show-prices-public">Mostrar precios en la página principal/comercial</label>
                      </div>
                      <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" id="show-contact-public">
                        <label class="form-check-label" for="show-contact-public">Mostrar página "Contactar" en la web</label>
                      </div>
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-4">
                    <label class="col-lg-2 col-form-label" for="initial-calendar-view">Vista inicial</label>
                    <div class="col-lg-10">
                      <select class="form-select" id="initial-calendar-view">
                        <option value="dashboard">Dashboard</option>
                        <option value="month">Mensual</option>
                        <option value="week">Semanal</option>
                        <option value="patients"><?= htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="upcoming">Citas</option>
                      </select>
                      <div class="form-text">Define qu&eacute; ver&aacute;s por defecto al iniciar sesi&oacute;n en la app.</div>
                    </div>
                  </div>
                </div>
                <hr class="my-4">
                <div class="row g-3 align-items-start mb-4 d-none" id="dashboard-config-row">
                  <label class="col-lg-2 col-form-label" for="dashboard-config-mode">Dashboard</label>
                  <div class="col-lg-4">
                    <div class="d-flex gap-2 flex-wrap align-items-start">
                      <select class="form-select d-none" id="dashboard-config-mode" style="max-width: 260px;">
                        <option value="simple">Simple</option>
                        <option value="advanced">Completo</option>
                        <option value="custom">Personalizado</option>
                      </select>
                      <button type="button" class="btn btn-outline-primary d-none" id="btn-open-dashboard-custom-config">
                        <i class="bi bi-sliders"></i> Personalizar
                      </button>
                    </div>
                  </div>
                  <label class="col-lg-2 col-form-label" for="btn-open-custom-domain-config">Dominio personalizado</label>
                  <div class="col-lg-4">
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                      <button type="button" class="btn btn-outline-primary text-truncate" id="btn-open-custom-domain-config" style="max-width: 100%;">
                        <i class="bi bi-globe2"></i> Configurar
                      </button>
                    </div>
                  </div>
                </div>
              </div>

              <div class="tab-pane fade" id="booking-settings-panel" role="tabpanel" aria-labelledby="booking-settings-tab">
                <div id="booking-settings-alert" class="alert d-none"></div>
                <div class="row">
                  <div class="col-md-6 mb-3">
                    <label class="form-label" for="min-booking-notice-days">Mínimo de días de antelación</label>
                    <input type="number" class="form-control" id="min-booking-notice-days" min="0" step="1">
                    <div class="form-text">Usa 0 para permitir reservas desde hoy.</div>
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label" for="max-booking-notice-days">Máximo de días de antelación</label>
                    <input type="number" class="form-control" id="max-booking-notice-days" min="0" step="1">
                    <div class="form-text">Usa 0 para no aplicar límite máximo.</div>
                  </div>
                </div>
                <hr class="my-4">
                <div class="row">
                  <div class="col-md-6 mb-3">
                    <label class="form-label" for="appointment-start-time">Primera cita disponible</label>
                    <input type="time" class="form-control" id="appointment-start-time" step="3600" value="10:00">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label" for="appointment-end-time">Última cita disponible</label>
                    <input type="time" class="form-control" id="appointment-end-time" step="3600" value="19:00">
                  </div>
                </div>
                <div class="row">
                  <div class="col-md-6 mb-3">
                    <label class="form-label" for="break-start-time">Inicio de descanso</label>
                    <input type="time" class="form-control" id="break-start-time" step="3600" value="15:00">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label" for="break-end-time">Fin de descanso</label>
                    <input type="time" class="form-control" id="break-end-time" step="3600" value="16:00">
                    <div class="form-text">Deja el descanso vacío si no quieres bloquear horas intermedias.</div>
                  </div>
                </div>
                <hr class="my-4">
                <div class="mb-4">
                  <label class="form-label d-block">Días disponibles para consulta</label>
                  <div class="row g-2">
                    <div class="col-sm-6 col-lg-4">
                      <div class="form-check">
                        <input class="form-check-input available-weekday" type="checkbox" id="available-weekday-1" value="1" checked>
                        <label class="form-check-label" for="available-weekday-1">Lunes</label>
                      </div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                      <div class="form-check">
                        <input class="form-check-input available-weekday" type="checkbox" id="available-weekday-2" value="2" checked>
                        <label class="form-check-label" for="available-weekday-2">Martes</label>
                      </div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                      <div class="form-check">
                        <input class="form-check-input available-weekday" type="checkbox" id="available-weekday-3" value="3" checked>
                        <label class="form-check-label" for="available-weekday-3">Miércoles</label>
                      </div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                      <div class="form-check">
                        <input class="form-check-input available-weekday" type="checkbox" id="available-weekday-4" value="4" checked>
                        <label class="form-check-label" for="available-weekday-4">Jueves</label>
                      </div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                      <div class="form-check">
                        <input class="form-check-input available-weekday" type="checkbox" id="available-weekday-5" value="5" checked>
                        <label class="form-check-label" for="available-weekday-5">Viernes</label>
                      </div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                      <div class="form-check">
                        <input class="form-check-input available-weekday" type="checkbox" id="available-weekday-6" value="6">
                        <label class="form-check-label" for="available-weekday-6">Sábado</label>
                      </div>
                    </div>
                  </div>
                  <div class="form-text">El calendario mostrará solo los días seleccionados.</div>
                </div>
              </div>

              <div class="tab-pane fade plan-closures-section" id="closures-settings-panel" role="tabpanel" aria-labelledby="closures-settings-tab">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
                  <div>
                    <h6 class="mb-1">Vacaciones y cierres</h6>
                    <div class="text-muted small">Bloquea días completos para vacaciones, festivos o cierres puntuales.</div>
                  </div>
                  <button type="button" class="btn btn-primary btn-sm" id="btn-open-closed-modal">
                    <i class="bi bi-plus-lg"></i> Añadir
                  </button>
                </div>
                <ul class="list-group" id="closed-days-list"></ul>
              </div>

              <div class="tab-pane fade" id="payment-settings-panel" role="tabpanel" aria-labelledby="payment-settings-tab">
                <form id="payment-settings-form">
                  <div id="payment-settings-alert" class="alert d-none"></div>

                  <div class="row g-3 align-items-start mb-3">
                    <div class="col-lg-10 offset-lg-2">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="online-payment-enabled">
                        <label class="form-check-label" for="online-payment-enabled">Activar pago online opcional</label>
                      </div>
                    </div>
                  </div>

                  <div id="payment-config-fields">
                    <div class="row g-3 align-items-center mb-3">
                      <label class="col-lg-2 col-form-label" for="payment-environment">Modo</label>
                      <div class="col-lg-10">
                        <select class="form-select" id="payment-environment" required>
                          <option value="sandbox">Sandbox / pruebas</option>
                          <option value="real">Real</option>
                        </select>
                      </div>
                    </div>

                    <div class="row g-3 align-items-center mb-3">
                      <label class="col-lg-2 col-form-label" for="merchant-code">Comercio</label>
                      <div class="col-lg-4">
                        <input type="text" class="form-control" id="merchant-code" autocomplete="off">
                      </div>
                      <label class="col-lg-2 col-form-label" for="merchant-terminal">Terminal</label>
                      <div class="col-lg-4">
                        <input type="text" class="form-control" id="merchant-terminal" autocomplete="off">
                      </div>
                    </div>

                    <div class="row g-3 align-items-start mb-3">
                      <label class="col-lg-2 col-form-label" for="merchant-key">Clave</label>
                      <div class="col-lg-10">
                        <input type="password" class="form-control" id="merchant-key" autocomplete="new-password"
                          placeholder="Déjala en blanco para conservar la actual">
                        <div class="form-text" id="merchant-key-status"></div>
                      </div>
                    </div>
                  </div>

                </form>
              </div>

              <div class="tab-pane fade" id="email-settings-panel" role="tabpanel" aria-labelledby="email-settings-tab">
                <div id="email-settings-alert" class="alert d-none"></div>
                <div class="alert alert-info small py-2 mb-4" role="alert">
                  <i class="bi bi-info-circle me-2"></i>
                  Habilita el envío automático de emails, recordatorios de <?= htmlspecialchars($appointment_label_plural, ENT_QUOTES, 'UTF-8') ?>, etc. al profesional/<?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>.
                </div>

                <div class="row g-3 align-items-center mb-3">
                  <label class="col-lg-2 col-form-label" for="smtp-from-name">Remitente</label>
                  <div class="col-lg-10">
                    <input type="text" class="form-control" id="smtp-from-name">
                  </div>
                </div>


                <div class="row g-3 align-items-center mb-3">
                  <label class="col-lg-2 col-form-label" for="email-provider">Sistema</label>
                  <div class="col-lg-10">
                    <select class="form-select" id="email-provider">
                      <option value="phpmailer">PHPMailer / SMTP</option>
                      <option value="google">Google Gmail API</option>
                    </select>
                  </div>
                </div>

                <div id="smtp-settings-block">
                  <div class="row g-3 align-items-center mb-3">
                    <label class="col-lg-2 col-form-label" for="smtp-host">SMTP</label>
                    <div class="col-lg-6">
                      <input type="text" class="form-control" id="smtp-host" placeholder="smtp.gmail.com">
                    </div>
                    <label class="col-lg-1 col-form-label" for="smtp-port">Puerto</label>
                    <div class="col-lg-3">
                      <input type="number" class="form-control" id="smtp-port" min="1" value="587">
                    </div>
                  </div>
                  <div class="row g-3 align-items-center mb-3">
                    <label class="col-lg-2 col-form-label" for="smtp-username">Usuario</label>
                    <div class="col-lg-6">
                      <input type="text" class="form-control" id="smtp-username" autocomplete="username">
                    </div>
                    <label class="col-lg-1 col-form-label" for="smtp-secure">Cifrado</label>
                    <div class="col-lg-3">
                      <select class="form-select" id="smtp-secure">
                        <option value="tls">TLS</option>
                        <option value="ssl">SSL</option>
                        <option value="none">Ninguno</option>
                      </select>
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-3">
                    <label class="col-lg-2 col-form-label" for="smtp-password">Contraseña</label>
                    <div class="col-lg-10">
                      <input type="password" class="form-control" id="smtp-password" autocomplete="new-password"
                        placeholder="Déjala en blanco para conservar la actual">
                      <div class="form-text" id="smtp-password-status"></div>
                    </div>
                  </div>
                  <div class="row g-3 align-items-center mb-3">
                    <label class="col-lg-2 col-form-label" for="smtp-from-email">Email remitente</label>
                    <div class="col-lg-10">
                      <input type="email" class="form-control" id="smtp-from-email">
                    </div>
                  </div>
                </div>

                <div id="google-email-settings-block">
                  <input type="hidden" id="google-connected-email">
                  <input type="hidden" id="google-redirect-uri">
                  <input type="hidden" id="google-refresh-token">
                  <div class="row g-3 align-items-start mb-3">
                    <div class="col-lg-10 offset-lg-2">
                      <div class="small text-muted mb-3" id="google-connected-status"></div>
                      <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-primary" id="btn-google-connect">
                          Conectar con Google
                        </button>
                      </div>
                    </div>
                  </div>
                </div>

                <hr class="my-4">
                <div class="row g-3 align-items-start mb-3 settings-check-row">
                  <label class="col-lg-2 col-form-label">Recordatorios</label>
                  <div class="col-lg-10">
                    <div class="mb-3">
                      <button type="button" class="btn btn-outline-primary btn-sm btn-message-template" data-template-key="appointment_email_reminder">
                        <i class="bi bi-pencil-square"></i> Personalizar recordatorio
                      </button>
                    </div>
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" id="appointment-reminder-enabled" name="appointment_reminder_enabled">
                      <label class="form-check-label" for="appointment-reminder-enabled">Enviar email de recordatorio al <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> 24 horas antes de la cita</label>
                    </div>
                  </div>
                </div>
                <div class="row g-3 align-items-start mb-3 settings-check-row" id="appointment-second-reminder-row">
                  <div class="col-lg-10 offset-lg-2">
                    <div class="form-check form-switch mb-2">
                      <input class="form-check-input" type="checkbox" id="appointment-second-reminder-enabled" name="appointment_second_reminder_enabled">
                      <label class="form-check-label" for="appointment-second-reminder-enabled">Enviar un segundo email de recordatorio</label>
                    </div>
                    <div class="input-group input-group-sm" style="max-width: 220px;">
                      <input type="number" class="form-control" id="appointment-second-reminder-hours" name="appointment_second_reminder_hours" min="1" max="168" step="1" value="48" disabled>
                      <span class="input-group-text">horas antes</span>
                    </div>
                    <div class="form-text">Usa un valor distinto de 24 horas para evitar duplicar el recordatorio fijo.</div>
                  </div>
                </div>

              </div>

              <div class="tab-pane fade" id="sms-settings-panel" role="tabpanel" aria-labelledby="sms-settings-tab">
                <div id="sms-settings-alert" class="alert d-none"></div>
                <div class="alert alert-info small py-2 mb-4" role="alert">
                  <i class="bi bi-info-circle me-2"></i>
                  Configura el proveedor SMS para enviar recordatorios de <?= htmlspecialchars($appointment_label_plural, ENT_QUOTES, 'UTF-8') ?> al <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>.
                </div>

                <div class="row g-3 align-items-center mb-3">
                  <label class="col-lg-2 col-form-label" for="sms-sender">Remitente</label>
                  <div class="col-lg-10">
                    <input type="text" class="form-control" id="sms-sender" maxlength="40">
                  </div>
                </div>

                <div class="row g-3 align-items-center mb-3">
                  <label class="col-lg-2 col-form-label" for="sms-provider">Proveedor</label>
                  <div class="col-lg-10">
                    <select class="form-select" id="sms-provider">
                      <option value="none">Sin proveedor</option>
                      <option value="mundosms">MundoSMS</option>
                      <option value="smsup">SMSUp</option>
                      <option value="smsapi">SMSAPI</option>
                    </select>
                  </div>
                </div>

                <div id="sms-mundosms-settings-block">
                  <div class="row g-3 align-items-center mb-3">
                    <label class="col-lg-2 col-form-label" for="sms-username">Usuario</label>
                    <div class="col-lg-10">
                      <input type="text" class="form-control" id="sms-username" autocomplete="username">
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-3">
                    <label class="col-lg-2 col-form-label" for="sms-password">Contrase&ntilde;a</label>
                    <div class="col-lg-10">
                      <input type="password" class="form-control" id="sms-password" autocomplete="new-password" placeholder="D&eacute;jala en blanco para conservar la actual">
                      <div class="form-text" id="sms-password-status"></div>
                    </div>
                  </div>
                </div>

                <div id="sms-api-key-settings-block">
                  <div class="row g-3 align-items-start mb-3">
                    <label class="col-lg-2 col-form-label" for="sms-api-key">API Key</label>
                    <div class="col-lg-10">
                      <input type="password" class="form-control" id="sms-api-key" autocomplete="new-password" placeholder="D&eacute;jala en blanco para conservar la actual">
                      <div class="form-text" id="sms-api-key-status"></div>
                    </div>
                  </div>
                </div>

                <hr class="my-4">
                <div class="row g-3 align-items-start mb-3 settings-check-row">
                  <label class="col-lg-2 col-form-label">Recordatorios</label>
                  <div class="col-lg-10">
                    <div class="mb-3">
                      <button type="button" class="btn btn-outline-primary btn-sm btn-message-template" data-template-key="appointment_sms_reminder">
                        <i class="bi bi-pencil-square"></i> Personalizar recordatorio
                      </button>
                    </div>
                    <div class="form-check form-switch mb-2">
                      <input class="form-check-input" type="checkbox" id="sms-reminder-enabled" name="sms_reminder_enabled">
                      <label class="form-check-label" for="sms-reminder-enabled">Enviar SMS de recordatorio al <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?></label>
                    </div>
                    <div class="input-group input-group-sm" style="max-width: 220px;">
                      <input type="number" class="form-control" id="sms-reminder-hours" name="sms_reminder_hours" min="1" max="168" step="1" value="24" disabled>
                      <span class="input-group-text">horas antes</span>
                    </div>
                    <div class="form-text">Te recomendamos tener en cuenta la hora de envío del SMS para que el <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> no lo reciba en horario nocturno.</div>
                  </div>
                </div>
              </div>

              <div class="tab-pane fade" id="calendar-settings-panel" role="tabpanel" aria-labelledby="calendar-settings-tab">
                <div id="calendar-settings-alert" class="alert d-none"></div>
                <div class="alert alert-info small py-2 mb-4" role="alert">
                  <i class="bi bi-info-circle me-2"></i>
                  Permite sincronizar las <?= htmlspecialchars($appointment_label_plural, ENT_QUOTES, 'UTF-8') ?> con tu calendario online de forma automática.
                </div>
                <div class="row g-3 align-items-center mb-3">
                  <label class="col-lg-2 col-form-label" for="calendar-provider">Sincronización</label>
                  <div class="col-lg-10">
                    <select class="form-select" id="calendar-provider">
                      <option value="none">No sincronizar</option>
                      <option value="google">Google Calendar</option>
                      <option value="icloud">iCloud Calendar</option>
                      <option value="microsoft">Microsoft Outlook Calendar</option>
                    </select>
                  </div>
                </div>
                <div class="row g-3 align-items-start mb-3" id="calendar-provider-none-fields">
                  <div class="col-lg-10 offset-lg-2 text-muted">
                    No se crearán eventos automáticos en calendarios externos.
                  </div>
                </div>
                <div id="google-calendar-config-fields">
                  <div class="row g-3 align-items-center mb-3">
                    <label class="col-lg-2 col-form-label" for="google-calendar-id">Calendar ID</label>
                    <div class="col-lg-10">
                      <input type="text" class="form-control" id="google-calendar-id" placeholder="primary">
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-3">
                    <div class="col-lg-10 offset-lg-2">
                      <div class="text-muted mb-3">
                        Usa la misma cuenta Google autorizada para Gmail.
                      </div>
                      <div class="small text-muted mb-3" id="google-calendar-connected-status"></div>
                      <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-primary" id="btn-google-connect-calendar">
                          Conectar/Reautorizar Google
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
                <div id="icloud-calendar-config-fields">
                  <div class="row g-3 align-items-center mb-3">
                    <label class="col-lg-2 col-form-label" for="icloud-calendar-email">Apple ID</label>
                    <div class="col-lg-10">
                      <input type="email" class="form-control" id="icloud-calendar-email" placeholder="usuario@icloud.com">
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-3">
                    <label class="col-lg-2 col-form-label" for="icloud-calendar-app-password">Contraseña app</label>
                    <div class="col-lg-10">
                      <input type="password" class="form-control" id="icloud-calendar-app-password" placeholder="Déjalo en blanco para conservar la actual">
                      <div class="form-text" id="icloud-calendar-app-password-status"></div>
                    </div>
                  </div>
                  <input type="hidden" id="icloud-calendar-url" value="https://caldav.icloud.com">
                  <div class="row g-3 align-items-start mb-3">
                    <div class="col-lg-10 offset-lg-2 text-muted">
                      Se usará el calendario por defecto de iCloud mediante CalDAV. iCloud requiere una contraseña específica de app.
                    </div>
                  </div>
                </div>
                <div id="microsoft-calendar-config-fields">
                  <div class="row g-3 align-items-center mb-3">
                    <label class="col-lg-2 col-form-label" for="microsoft-calendar-id">Calendar ID</label>
                    <div class="col-lg-10">
                      <input type="text" class="form-control" id="microsoft-calendar-id" placeholder="Déjalo en blanco para usar el calendario principal">
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-3">
                    <div class="col-lg-10 offset-lg-2">
                      <div class="small mb-3" id="microsoft-calendar-connected-status"></div>
                      <button type="button" class="btn btn-outline-primary" id="btn-microsoft-connect-calendar">
                        Conectar con Microsoft
                      </button>
                    </div>
                  </div>
                </div>
                <hr class="my-4">
                <div class="row g-3 align-items-start mb-3">
                  <div class="col-lg-10 offset-lg-2">
                    <div class="form-check form-switch mb-2">
                      <input class="form-check-input" type="checkbox" id="send-patient-calendar-link" checked>
                      <label class="form-check-label" for="send-patient-calendar-link">Enviar link para crear la cita en el calendario a los <?= htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?> cuando hagan una reserva</label>
                    </div>
                    <div class="form-text" id="send-patient-calendar-link-status"></div>
                  </div>
                </div>
              </div>

              <div class="tab-pane fade" id="legal-settings-panel" role="tabpanel" aria-labelledby="legal-settings-tab">
                <div id="legal-settings-alert" class="alert d-none"></div>
                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="legal-owner-name">Titular</label>
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="legal-owner-name" placeholder="Nombre y apellidos o razón social">
                  </div>
                  <label class="col-lg-2 col-form-label" for="legal-nif">NIF/CIF</label>
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="legal-nif" placeholder="NIF, NIE o CIF">
                  </div>
                </div>
                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="verifactu-taxpayer-type">Tipo de titular</label>
                  <div class="col-lg-10">
                    <select class="form-select" id="verifactu-taxpayer-type">
                      <option value="self_employed">Persona F&iacute;sica</option>
                      <option value="company">Persona Jur&iacute;dica</option>
                    </select>
                  </div>
                </div>
                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="legal-address">Domicilio</label>
                  <div class="col-lg-10">
                    <input type="text" class="form-control" id="legal-address" placeholder="Domicilio profesional">
                  </div>
                </div>
                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="legal-country">Pa&iacute;s</label>
                  <div class="col-lg-4">
                    <select class="form-select" id="legal-country">
                      <option value="ES">Espa&ntilde;a</option>
                      <option value="OT">Otro</option>
                    </select>
                  </div>
                  <label class="col-lg-2 col-form-label" for="legal-province">Provincia</label>
                  <div class="col-lg-4" id="legal-spanish-province-wrap">
                    <select class="form-select" id="legal-province"></select>
                  </div>
                  <div class="col-lg-4 d-none" id="legal-other-province-wrap">
                    <input type="text" class="form-control" id="legal-province-other" placeholder="Provincia / regi&oacute;n">
                  </div>
                </div>
                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="legal-city">Localidad</label>
                  <div class="col-lg-4"><input type="text" class="form-control" id="legal-city"></div>
                  <label class="col-lg-2 col-form-label" for="legal-postal-code">C&oacute;digo postal</label>
                  <div class="col-lg-4"><input type="text" class="form-control" id="legal-postal-code" maxlength="20"></div>
                </div>
                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="legal-email">Email legal</label>
                  <div class="col-lg-10">
                    <input type="email" class="form-control" id="legal-email" placeholder="privacidad@dominio.com">
                  </div>
                </div>
                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="legal-license-number">N&ordm; colegiado</label>
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="legal-license-number" placeholder="Ej. T-00000">
                  </div>
                  <label class="col-lg-2 col-form-label" for="legal-health-registry-number">N&ordm; de registro sanitario</label>
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="legal-health-registry-number">
                  </div>
                </div>
                <div class="row g-3 align-items-start mb-4">
                  <label class="col-lg-2 col-form-label" for="legal-professional-college">Colegio profesional</label>
                  <div class="col-lg-10">
                    <input type="text" class="form-control" id="legal-professional-college" placeholder="">
                  </div>
                </div>
                <hr class="my-4">
                <div class="row g-3 align-items-start mb-4">
                  <div class="col-lg-10 offset-lg-2">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" id="legal-uses-non-technical-cookies">
                      <label class="form-check-label" for="legal-uses-non-technical-cookies">La web usa cookies no técnicas</label>
                    </div>
                    <div class="form-text">Actívalo solo si añades analítica, publicidad, píxeles o servicios similares que no sean estrictamente necesarios.</div>
                  </div>
                </div>
                <div class="row g-3 align-items-start">
                  <label class="col-lg-2 col-form-label" for="legal-terms-notes">Condiciones particulares</label>
                  <div class="col-lg-10">
                    <textarea class="form-control" id="legal-terms-notes" rows="5" placeholder="Ej. condiciones de cancelación, bonos, devoluciones, terapia online o cualquier matiz propio de la consulta."></textarea>
                    <div class="form-text">Este texto aparecerá al final de las condiciones del servicio. Admite HTML básico: &lt;p&gt;, &lt;br&gt;, &lt;strong&gt;, &lt;em&gt;, &lt;ul&gt;, &lt;ol&gt; y &lt;li&gt;.</div>
                  </div>
                </div>
                <hr class="my-4">
                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
                  <div>
                    <h6 class="mb-1">Consentimientos y documentos legales</h6>
                  </div>
                  <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-primary btn-sm <?= $legal_consent_service_mapping_enabled ? '' : 'plan-locked' ?>" id="btn-map-service-legal-documents"
                      <?= $legal_consent_service_mapping_enabled ? '' : 'disabled title="Disponible en el plan Summum"' ?>>
                      <i class="bi bi-diagram-3"></i> Asignar a servicios
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm <?= $legal_consent_templates_enabled ? '' : 'plan-locked' ?>" id="btn-show-suggested-legal-documents"
                      <?= $legal_consent_templates_enabled ? '' : 'disabled title="Disponible en un plan superior"' ?>>
                      <i class="bi bi-stars"></i> A&ntilde;adir plantillas sugeridas
                    </button>
                    <button type="button" class="btn btn-primary btn-sm <?= $legal_consent_templates_enabled ? '' : 'plan-locked' ?>" id="btn-show-legal-document-form"
                      <?= $legal_consent_templates_enabled ? '' : 'disabled title="Disponible en un plan superior"' ?>>
                      <i class="bi bi-plus-lg"></i> Nuevo consentimiento
                    </button>
                  </div>
                </div>
                <div class="table-responsive">
                  <table class="table align-middle">
                    <thead>
                      <tr>
                        <th>Documento</th>
                        <th>Categoría</th>
                        <th class="text-center">Obligatorio</th>
                        <th class="text-center">Activo</th>
                        <th class="text-end">Acciones</th>
                      </tr>
                    </thead>
                    <tbody id="legal-documents-body">
                      <tr><td colspan="5" class="text-center text-muted py-4">Cargando documentos legales...</td></tr>
                    </tbody>
                  </table>
                </div>
              </div>
              <?php if ($is_superadmin): ?>
                <div class="tab-pane fade" id="cabinet-settings-panel" role="tabpanel" aria-labelledby="cabinet-settings-tab">
                  <div id="cabinet-settings-alert" class="alert d-none"></div>
                  <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="show-team-public">
                    <label class="form-check-label" for="show-team-public">Mostrar p&aacute;gina "Equipo" en la web</label>
                  </div>
                  <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" id="allow-patient-transfer">
                    <label class="form-check-label" for="allow-patient-transfer">Permitir traspaso de <?= htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?></label>
                    <div class="form-text">Solo el usuario Administrador puede realizar los traspasos.</div>
                  </div>
                  <div class="row g-3 align-items-start mb-4">
                    <label class="col-lg-3 col-form-label" for="new-patient-booking-mode">M&eacute;todo de reserva para nuevos <?= htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?></label>
                    <div class="col-lg-9">
                      <select class="form-select" id="new-patient-booking-mode">
                        <option value="day_first">Elegir primero d&iacute;a/hora deseado y despu&eacute;s al profesional</option>
                        <option value="professional_first">Elegir primero al profesional y despu&eacute;s la fecha/hora</option>
                        <option value="fixed_professional">Derivar siempre los nuevos <?= htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?> a un profesional concreto</option>
                      </select>
                      <div class="form-text">Solo se aplicar&aacute; a <?= htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?> nuevos que todav&iacute;a no tengan profesional asignado.</div>
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-4 d-none" id="new-patient-fixed-professional-row">
                    <label class="col-lg-3 col-form-label" for="new-patient-fixed-professional">Profesional de derivaci&oacute;n</label>
                    <div class="col-lg-9">
                      <select class="form-select" id="new-patient-fixed-professional">
                        <option value="">Selecciona un profesional</option>
                      </select>
                    </div>
                  </div>
                  <div class="text-muted small mb-3 d-none" id="cabinet-settings-loading">Cargando configuraci&oacute;n del equipo...</div>
                  <hr class="my-4">
                  <div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
                    <div>
                      <h6 class="mb-1">Equipo</h6>
                      <div class="text-muted small"><?= plan_config_feature_enabled($plan_config, 'team.permissions', false) ? 'Alta y permisos b&aacute;sicos de los miembros del equipo.' : 'Alta de profesionales del equipo.' ?></div>
                      <div class="text-muted small" id="team-member-limit-note"></div>
                    </div>
                    <button class="btn btn-primary btn-sm" type="button" id="btn-new-professional">
                      <i class="bi bi-person-plus"></i> Nuevo miembro
                    </button>
                  </div>
                  <div class="table-responsive admin-patients-table-wrap">
                    <table class="table align-middle">
                      <thead>
                        <tr>
                          <th>Miembro</th>
                          <th>Email</th>
                          <th>Tipo</th>
                          <th>Estado</th>
                          <th class="text-end">Acciones</th>
                        </tr>
                      </thead>
                      <tbody id="professionals-settings-body">
                        <tr><td colspan="5" class="text-center text-muted py-4">Cargando...</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              <?php endif; ?>
              <div class="tab-pane fade <?= $is_superadmin ? '' : 'd-none' ?>" id="subscription-settings-panel" role="tabpanel" aria-labelledby="subscription-settings-tab">
                <div id="subscription-settings-alert" class="alert d-none small py-2"></div>
                <div id="subscription-sandbox-alert" class="alert alert-danger small py-2 <?= $braintree_environment === 'sandbox' ? '' : 'd-none' ?>">
                  <strong>Modo de prueba.</strong> Los cobros y tarjetas son simulados. Ninguna operaci&oacute;n de esta sesi&oacute;n afecta a la suscripci&oacute;n real.
                  <a class="alert-link ms-1" href="?sandbox=0">Volver a producci&oacute;n</a>
                </div>
                <div id="subscription-settings-loading" class="text-center text-muted py-5">
                  <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Cargando suscripci&oacute;n...
                </div>
                <div id="subscription-settings-content" class="d-none">
                  <div class="subscription-summary-card mb-4">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                      <div>
                        <div class="text-muted small mb-1">Plan actual</div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                          <h5 class="mb-0" id="subscription-plan-name">Initium</h5>
                          <span class="badge text-bg-secondary" id="subscription-status-badge"><?= $plan_key === 'initium' ? 'Plan gratuito' : 'Sin suscripci&oacute;n' ?></span>
                          <span class="badge text-bg-danger d-none" id="subscription-environment-badge">Sandbox</span>
                        </div>
                        <div class="text-muted small mt-2" id="subscription-period-text"></div>
                        <div class="text-muted small" id="subscription-payment-method"></div>
                      </div>
                      <div class="d-flex flex-wrap align-items-start gap-2">
                        <button type="button" class="btn btn-primary btn-sm" id="btn-subscription-plan"><i class="bi bi-arrow-repeat"></i> Contratar o cambiar plan</button>
                        <button type="button" class="btn btn-outline-primary btn-sm d-none" id="btn-subscription-payment"><i class="bi bi-credit-card"></i> Cambiar forma de pago</button>
                        <button type="button" class="btn btn-outline-danger btn-sm d-none" id="btn-subscription-cancel"><i class="bi bi-x-circle"></i> Cancelar suscripci&oacute;n</button>
                      </div>
                    </div>
                  </div>

                  <h6 class="mb-1">Historial de pagos y facturas</h6>
                  <p class="text-muted small mb-3">Aqu&iacute; podr&aacute;s consultar los cobros de la suscripci&oacute;n y descargar sus facturas fiscales.</p>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle subscription-history-table">
                      <thead><tr><th>Fecha</th><th>Operaci&oacute;n</th><th>Estado</th><th class="text-end">Importe</th><th class="text-center">Factura</th></tr></thead>
                      <tbody id="subscription-history-body"></tbody>
                    </table>
                  </div>
                </div>
              </div>
              <div class="tab-pane fade <?= ($is_superadmin && $billing_plan_enabled) ? '' : 'd-none' ?>" id="billing-settings-panel" role="tabpanel" aria-labelledby="billing-settings-tab">
                <div id="billing-settings-alert" class="alert d-none"></div>
                <div class="alert alert-info py-2 small mb-3">
                  Al habilitar la facturaci&oacute;n, se emitir&aacute;n autom&aacute;ticamente las facturas cada vez que se reciba un pago online o se marque una reserva, bono o informe como &quot;pagado&quot;.
                </div>
                <div class="form-check form-switch mb-4">
                  <input class="form-check-input" type="checkbox" id="billing-enabled">
                  <label class="form-check-label" for="billing-enabled">Activar emisi&oacute;n de facturas</label>
                </div>
                <div class="border-top pt-3 mt-4" id="verifactu-settings-block">
                  <h6 class="mb-3">VeriFactu</h6>
                  <div class="alert alert-info py-2 small mb-2">
                    Al activar la facturaci&oacute;n con VeriFactu, se iniciar&aacute; el env&iacute;o de las facturas y no podr&aacute; volver a desactivarse.
                  </div>
                  <div class="alert alert-warning py-2 small mb-3" id="verifactu-voluntary-warning">
                    Si inicias el env&iacute;o a VeriFactu antes de la fecha oficial, no podr&aacute;s desactivarlo. Hacienda no permite el env&iacute;o de pruebas.
                  </div>
                  <div class="row g-3">
                    <div class="col-12">
                      <label class="form-label" for="verifactu-activation-mode">Inicio de los env&iacute;os</label>
                      <select class="form-select" id="verifactu-activation-mode">
                        <option value="official">En la fecha oficial obligatoria</option>
                        <option value="voluntary">Activar voluntariamente</option>
                      </select>
                    </div>
                    <div class="col-12">
                      <div class="form-text" id="verifactu-official-date-text"></div>
                    </div>
                  </div>
                </div>
                <div class="border-top pt-3 mt-4">
                  <h6 class="mb-3">Fiscalidad de las facturas</h6>
                  <div class="alert alert-warning py-2 small mb-3">
                    Indica en la pesta&ntilde;a Precios si cada servicio est&aacute; exento o sujeto a impuesto. La exenci&oacute;n sanitaria depende tanto del servicio prestado como de la cualificaci&oacute;n del profesional.
                  </div>
                  <div class="row g-3 mb-3">
                    <div class="col-md-6">
                      <label class="form-label" for="billing-tax-system">Impuesto</label>
                      <select class="form-select" id="billing-tax-system" disabled>
                        <option value="iva">IVA</option>
                        <option value="igic">IGIC</option>
                      </select>
                    </div>
                    <div class="d-none">
                      <label class="form-label" for="billing-default-tax-mode">Tratamiento predeterminado</label>
                      <select class="form-select" id="billing-default-tax-mode">
                        <option value="exempt">Exento</option>
                        <option value="taxed">Sujeto a impuesto</option>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label" for="billing-default-tax-rate">Tipo impositivo</label>
                      <select class="form-select" id="billing-default-tax-rate" disabled>
                        <option value="21">21%</option>
                        <option value="7">7%</option>
                      </select>
                    </div>
                    <div class="d-none" id="billing-exemption-reason-block">
                      <label class="form-label" for="billing-exemption-reason">Motivo legal de exenci&oacute;n</label>
                      <input type="text" class="form-control" id="billing-exemption-reason" maxlength="500">
                      <div class="form-text">Este texto se incluir&aacute; en las facturas exentas.</div>
                    </div>
                  </div>
                </div>
                <hr>
                <div class="row g-3 mb-3">
                  <div class="col-md-6">
                    <label class="form-label" for="billing-session-concept">Concepto para citas/sesiones</label>
                    <input type="text" class="form-control" id="billing-session-concept" maxlength="255" placeholder="Sesión {servicio} del día {fecha} ({duracion} minutos)">
                    <div class="form-text">Variables: {servicio}, {fecha}, {hora}, {duracion}</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="billing-report-concept">Concepto para informes de pago</label>
                    <input type="text" class="form-control" id="billing-report-concept" maxlength="255" placeholder="Informe {titulo}">
                    <div class="form-text">Variable: {titulo}</div>
                  </div>
                </div>
              </div>
              <div class="tab-pane fade <?= ($is_superadmin && $tenant_signature_plan_enabled) ? '' : 'd-none' ?>" id="signature-settings-panel" role="tabpanel" aria-labelledby="signature-settings-tab">
                <div class="alert alert-info small py-2">
                  Importa el certificado PFX/P12 del centro. La contrase&ntilde;a se usa solo durante la importaci&oacute;n y no se guarda.
                </div>
                <div id="signature-certificate-alert" class="alert d-none"></div>
                <div id="signature-certificate-status" class="border rounded p-3 mb-3 text-muted small">Comprobando certificado...</div>
                <form id="signature-certificate-form" enctype="multipart/form-data">
                  <input type="hidden" id="signature-certificate-owner" value="0">
                  <div class="row g-3 align-items-end mb-4">
                    <div class="col-lg-5">
                      <label class="form-label" for="signature-certificate-file">Certificado PFX/P12 del centro</label>
                      <input type="file" class="form-control" id="signature-certificate-file" name="signature_certificate" accept=".pfx,.p12,application/x-pkcs12" required>
                    </div>
                    <div class="col-lg-4">
                      <label class="form-label" for="signature-certificate-password">Contrase&ntilde;a</label>
                      <input type="password" class="form-control" id="signature-certificate-password" name="certificate_password" autocomplete="new-password" required>
                    </div>
                    <div class="col-lg-3 d-flex gap-2">
                      <button type="submit" class="btn btn-primary flex-grow-1" id="btn-import-signature-certificate"><i class="bi bi-shield-check"></i> Importar</button>
                      <button type="button" class="btn btn-outline-danger d-none" id="btn-remove-signature-certificate" title="Eliminar certificado"><i class="bi bi-trash"></i></button>
                    </div>
                  </div>
                </form>
                <hr class="my-4">
                <h6>Firma autom&aacute;tica</h6>
                <div class="form-check form-switch mb-3">
                  <input class="form-check-input signature-auto-setting" type="checkbox" id="signature-auto-invoices">
                  <label class="form-check-label" for="signature-auto-invoices">Firmar autom&aacute;ticamente las facturas PDF</label>
                </div>
                <div class="form-check form-switch mb-3">
                  <input class="form-check-input signature-auto-setting" type="checkbox" id="signature-auto-reports">
                  <label class="form-check-label" for="signature-auto-reports">Firmar autom&aacute;ticamente los informes PDF</label>
                </div>
                <div class="form-check form-switch">
                  <input class="form-check-input signature-auto-setting" type="checkbox" id="signature-auto-documents">
                  <label class="form-check-label" for="signature-auto-documents">Firmar autom&aacute;ticamente otros PDF emitidos por el centro</label>
                </div>
              </div>
              <div class="tab-pane fade <?= ($is_superadmin && $time_tracking_plan_enabled) ? '' : 'd-none' ?>" id="time-tracking-settings-panel" role="tabpanel" aria-labelledby="time-tracking-settings-tab">
                <div class="alert alert-info small py-2">
                  Permite que los miembros del equipo registren las entradas, salidas y descansos.
                </div>
                <div id="time-tracking-settings-alert" class="alert d-none"></div>
                <div class="form-check form-switch mb-3">
                  <input class="form-check-input" type="checkbox" id="time-tracking-enabled">
                  <label class="form-check-label" for="time-tracking-enabled">Activar el control horario</label>
                </div>
                <div class="form-check form-switch mb-1">
                  <input class="form-check-input" type="checkbox" id="time-tracking-notify-missing-clock-in">
                  <label class="form-check-label" for="time-tracking-notify-missing-clock-in">Notificar al usuario si no ha fichado la entrada</label>
                </div>
                <div class="form-text mb-3">El bot&oacute;n para fichar llamar&aacute; la atenci&oacute;n para recordar al miembro que debe registrar su entrada.</div>
                <div class="form-check form-switch mb-1">
                  <input class="form-check-input" type="checkbox" id="time-tracking-require-clock-in">
                  <label class="form-check-label" for="time-tracking-require-clock-in">Exigir fichar para poder trabajar</label>
                </div>
                <div class="form-text mb-3">No se podr&aacute; utilizar el dashboard hasta que el miembro registre su entrada.</div>
                <div class="form-check form-switch mb-1">
                  <input class="form-check-input" type="checkbox" id="time-tracking-logout-on-clock-out">
                  <label class="form-check-label" for="time-tracking-logout-on-clock-out">Cerrar la sesi&oacute;n al registrar la salida</label>
                </div>
                <div class="form-text mb-3">Al registrar una salida se cerrar&aacute; autom&aacute;ticamente la sesi&oacute;n en SimplyGest Praxis.</div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary me-auto d-none" id="btn-settings-back-to-hub">
              <i class="bi bi-layout-text-sidebar-reverse me-1"></i>Mostrar toda la configuración
            </button>
            <button type="button" class="btn btn-primary" id="btn-save-all-settings">Guardar cambios</button>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($is_superadmin): ?>
    <div class="modal fade" id="subscriptionActionModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content position-relative overflow-hidden">
          <div id="subscription-processing-overlay" class="subscription-processing-overlay d-none" role="status" aria-live="assertive" aria-busy="true">
            <div class="spinner-border text-primary mb-3" aria-hidden="true"></div>
            <div class="fw-semibold" id="subscription-processing-title">Procesando la solicitud...</div>
            <div class="small text-muted mt-1">Por favor, espera y no cierres esta ventana.</div>
          </div>
          <div class="modal-header">
            <h5 class="modal-title" id="subscription-action-title">Gestionar suscripci&oacute;n</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="subscription-action-alert" class="alert d-none small py-2"></div>
            <div class="alert alert-danger small py-2 d-none" id="subscription-action-sandbox">
              Est&aacute;s utilizando el modo de prueba. No se realizar&aacute; ning&uacute;n cobro real.
            </div>
            <div id="subscription-plan-selector" class="mb-3">
              <div class="row g-3" id="subscription-plan-cards"></div>
              <div class="text-center mt-3">
                <a href="app-plans.php" target="_blank" rel="noopener noreferrer" class="subscription-compare-plans-link">Comparar los tres planes <i class="bi bi-box-arrow-up-right ms-1"></i></a>
              </div>
              <div class="form-text mt-3 d-none" id="subscription-plan-help">Si cambias a un plan superior, se te cargar&aacute; el importe proporcional a los d&iacute;as restantes del plan actual.</div>
            </div>
            <div id="subscription-dropin-loading" class="text-center text-muted py-4 d-none">
              <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Preparando el formulario de pago seguro...
            </div>
            <div id="subscription-dropin-container"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btn-confirm-subscription-action">Continuar</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="dashboardCustomConfigModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Personalizar interfaz</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="dashboard-custom-config-alert" class="alert d-none"></div>
            <label class="form-label" for="dashboard-custom-config-json">JSON personalizado</label>
            <div class="form-text mb-2">Aquí podrás elegir qué características quieres usar y, opcionalmente, personalizar textos de nomenclatura en la sección <code>texts</code>. Hazlo con precaución para evitar problemas con la app.</div>
            <textarea class="form-control dashboard-config-json-editor" id="dashboard-custom-config-json" rows="18" spellcheck="false"></textarea>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btn-save-dashboard-custom-config">Guardar JSON</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="customDomainModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Dominio personalizado</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="custom-domain-alert" class="alert d-none"></div>
            <div class="alert alert-info small">
              Un dominio personalizado permite que tus pacientes/clientes accedan a tu web usando tu propio dominio, por ejemplo <strong>tudominio.es</strong>, manteniendo visible esa dirección en el navegador.
            </div>
            <div class="mb-3">
              <label class="form-label" for="custom-domain-input">Dominio</label>
              <input type="text" class="form-control" id="custom-domain-input" placeholder="tudominio.es" autocomplete="off">
              <div class="form-text">Introduce solo el dominio. Puedes pegar una URL con https://, pero se guardará únicamente el dominio seguro.</div>
            </div>
            <a class="btn btn-outline-secondary btn-sm" id="custom-domain-help-link" href="<?= htmlspecialchars($help_base_url, ENT_QUOTES, 'UTF-8') ?>#dominios-personalizados" target="_blank" rel="noopener">
              <i class="bi bi-question-circle"></i> Ayuda
            </a>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-danger me-auto" id="btn-delete-custom-domain">
              <i class="bi bi-trash"></i> Quitar dominio
            </button>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btn-save-custom-domain">Guardar</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="legalDocumentModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <form id="legal-document-form" enctype="multipart/form-data">
            <div class="modal-header">
              <h5 class="modal-title">Documento legal</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div id="legal-document-alert" class="alert d-none"></div>
              <div class="alert alert-info small">
                Escribe el contenido del consentimiento. SGPraxis generar&aacute; el PDF con los datos del centro y del <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> cuando se consulte o firme.
              </div>
              <input type="hidden" id="legal-document-id" name="document_id" value="0">
              <input type="hidden" id="legal-document-template-type" name="template_type" value="generated">
              <div class="mb-3">
                <label class="form-label" for="legal-document-title">Nombre</label>
                <input type="text" class="form-control" id="legal-document-title" name="title" maxlength="180" required>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label" for="legal-document-category">Categor&iacute;a</label>
                  <input type="text" class="form-control" id="legal-document-category" name="category" maxlength="80" placeholder="Protecci&oacute;n de datos">
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="legal-document-version">Versi&oacute;n</label>
                  <input type="text" class="form-control" id="legal-document-version" name="version_label" maxlength="80" placeholder="v1, 2026...">
                </div>
                <div class="col-12">
                  <label class="form-label" for="legal-document-summary">Introducci&oacute;n / informaci&oacute;n general</label>
                  <textarea class="form-control" id="legal-document-summary" name="summary" rows="4" required></textarea>
                </div>
                <div class="col-12">
                  <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                    <label class="form-label mb-0">Apartados del consentimiento</label>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btn-add-legal-document-section">
                      <i class="bi bi-plus-lg"></i> A&ntilde;adir apartado
                    </button>
                  </div>
                  <div id="legal-document-sections" class="d-grid gap-2"></div>
                </div>
                <div class="col-12">
                  <label class="form-label" for="legal-document-declaration">Declaraci&oacute;n final de aceptaci&oacute;n</label>
                  <textarea class="form-control" id="legal-document-declaration" name="declaration" rows="4" required>Declaro que he recibido informaci&oacute;n clara y comprensible, que he podido formular preguntas y que acepto libremente el contenido de este documento.</textarea>
                </div>
                <div class="col-md-6">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="legal-document-required" name="is_required" value="1">
                    <label class="form-check-label" for="legal-document-required">Obligatorio</label>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="legal-document-active" name="is_active" value="1" checked>
                    <label class="form-check-label" for="legal-document-active">Activo</label>
                  </div>
                </div>
              </div>
              <details class="mt-3" id="legal-document-legacy-upload">
                <summary class="small text-muted cursor-pointer">Usar excepcionalmente un PDF externo</summary>
                <div class="pt-3">
                  <input type="file" class="form-control" id="legal-document-file" name="legal_document_file" accept="application/pdf,.pdf">
                  <div class="form-text" id="legal-document-file-status">Al seleccionar un PDF externo no se podr&aacute; autorrellenar su contenido.</div>
                </div>
              </details>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary" id="btn-save-legal-document">Guardar documento</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade" id="serviceLegalDocumentsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Consentimientos por servicio</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="service-legal-documents-alert" class="alert d-none"></div>
            <div class="alert alert-info small py-2 mb-3">
              Asigna uno o varios consentimientos a cada servicio. Cuando un <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> tenga una cita de ese servicio, los documentos asignados aparecer&aacute;n como obligatorios.
            </div>
            <div id="service-legal-documents-list">
              <div class="text-center text-muted py-4">Cargando servicios y consentimientos...</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btn-save-service-legal-documents">
              Guardar asignaciones
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="suggestedLegalDocumentsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <form id="suggested-legal-documents-form">
            <div class="modal-header">
              <h5 class="modal-title">A&ntilde;adir plantillas sugeridas</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div id="suggested-legal-documents-alert" class="alert d-none"></div>
              <div class="alert alert-warning small mb-3">
                Estas plantillas son una base orientativa. Deben revisarse y adaptarse a la actividad, normativa aplicable y criterio profesional/legal del responsable.
              </div>
              <div id="suggested-legal-documents-list" class="row g-2">
                <div class="col-12 text-center text-muted py-4">Cargando plantillas sugeridas...</div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary" id="btn-create-suggested-legal-documents">
                <i class="bi bi-plus-lg"></i> A&ntilde;adir seleccionadas
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($is_admin): ?>
    <div class="modal fade" id="taskTemplateModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md">
        <div class="modal-content">
          <form id="task-template-form">
            <div class="modal-header">
              <h5 class="modal-title" id="task-template-modal-title">Nueva plantilla</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" id="task-template-id" value="0">
              <div class="mb-3">
                <label class="form-label" for="task-template-title">Nombre de la plantilla</label>
                <input type="text" class="form-control" id="task-template-title" maxlength="180" placeholder="Ej. Plantilla para ansiedad" required>
              </div>
              <div class="mb-3">
                <label class="form-label" for="task-template-category">Terapia / categor&iacute;a</label>
                <input type="text" class="form-control" id="task-template-category" maxlength="120" placeholder="<?= $task_template_category_placeholder ?>">
              </div>
              <div class="mb-3">
                <label class="form-label" for="task-template-description">Descripci&oacute;n interna</label>
                <textarea class="form-control" id="task-template-description" rows="4"></textarea>
              </div>
              <?php if ($is_superadmin): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="task-template-global" checked>
                  <label class="form-check-label" for="task-template-global">Disponible para todo el equipo</label>
                </div>
              <?php endif; ?>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary" id="btn-save-task-template">Guardar plantilla</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade" id="taskTemplateItemModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md">
        <div class="modal-content">
          <form id="task-template-item-form" enctype="multipart/form-data">
            <div class="modal-header">
              <h5 class="modal-title" id="task-template-item-modal-title">Nueva tarea de plantilla</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" id="task-template-item-id" value="0">
              <div class="mb-3">
                <label class="form-label" for="task-template-item-template-id">Plantilla</label>
                <select class="form-select" id="task-template-item-template-id" required>
                  <option value="">Selecciona plantilla...</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label" for="task-template-item-title">T&iacute;tulo de la tarea</label>
                <input type="text" class="form-control" id="task-template-item-title" maxlength="180" required>
              </div>
              <div class="mb-3">
                <label class="form-label" for="task-template-item-priority">Prioridad</label>
                <select class="form-select" id="task-template-item-priority">
                  <option value="1">Alta</option>
                  <option value="2" selected>Normal</option>
                  <option value="3">Baja</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label" for="task-template-item-description">Descripci&oacute;n / actividad</label>
                <textarea class="form-control" id="task-template-item-description" rows="4"></textarea>
              </div>
              <div class="mb-3 <?= $document_uploads_enabled ? '' : 'opacity-50' ?>">
                <label class="form-label" for="task-template-item-file">Archivo asociado (opcional)</label>
                <input type="file" class="form-control" id="task-template-item-file" name="attachment" accept=".pdf,.docx,.jpg,.jpeg,.png,.webp,.gif"
                  <?= $document_uploads_enabled ? '' : 'disabled title="Disponible en un plan superior"' ?>>
                <div class="form-text">PDF, DOCX o imagen. M&aacute;ximo 12 MB.</div>
                <div id="task-template-item-current-file" class="small mt-2 d-none"></div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary" id="btn-save-task-template-item">Guardar tarea</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($is_admin): ?>
    <div class="modal fade" id="closedDayModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Añadir cierre</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form id="add-closed-form">
            <div class="modal-body">
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label" for="closed-start-date">Desde</label>
                  <input type="date" class="form-control" id="closed-start-date" required>
                </div>
                <div class="col-sm-6">
                  <label class="form-label" for="closed-end-date">Hasta</label>
                  <input type="date" class="form-control" id="closed-end-date">
                </div>
                <div class="col-12">
                  <label class="form-label" for="closed-reason">Motivo</label>
                  <input type="text" class="form-control" id="closed-reason" placeholder="Motivo (ej. Vacaciones)" required>
                </div>
                <?php if ($is_superadmin): ?>
                  <div class="col-12">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="closed-is-global">
                      <label class="form-check-label" for="closed-is-global">Cierre global del equipo</label>
                      <div class="form-text">Bloquear estos días para todo el equipo.</div>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button class="btn btn-primary" type="submit">Añadir cierre</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($is_superadmin || $current_user_professional_id > 0): ?>
    <div class="modal fade" id="professionalEditorModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="professional-editor-title">Profesional</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form id="professional-editor-form">
            <div class="modal-body">
              <div id="professional-editor-alert" class="alert d-none"></div>
              <input type="hidden" id="professional-editor-index" value="-1">
              <input type="hidden" id="professional-editor-id" value="0">
              <input type="hidden" id="professional-editor-user-id" value="0">
              <ul class="nav nav-tabs mb-3" id="professional-editor-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                  <button class="nav-link active" id="professional-editor-data-tab" data-bs-toggle="tab" data-bs-target="#professional-editor-data-panel" type="button" role="tab">Datos</button>
                </li>
                <li class="nav-item professional-profile-tab" role="presentation">
                  <button class="nav-link" id="professional-editor-extra-tab" data-bs-toggle="tab" data-bs-target="#professional-editor-extra-panel" type="button" role="tab">M&aacute;s datos</button>
                </li>
                <li class="nav-item professional-profile-tab" role="presentation">
                  <button class="nav-link" id="professional-editor-preferences-tab" data-bs-toggle="tab" data-bs-target="#professional-editor-preferences-panel" type="button" role="tab">Preferencias</button>
                </li>
                <?php if ($is_superadmin): ?>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" id="professional-editor-permissions-tab" data-bs-toggle="tab" data-bs-target="#professional-editor-permissions-panel" type="button" role="tab">Permisos</button>
                </li>
                <?php endif; ?>
                <?php if ($professional_signature_plan_enabled): ?>
                <li class="nav-item d-none" id="professional-editor-certificate-tab-item" role="presentation">
                  <button class="nav-link" id="professional-editor-certificate-tab" data-bs-toggle="tab" data-bs-target="#professional-editor-certificate-panel" type="button" role="tab">Certificado digital</button>
                </li>
                <?php endif; ?>
              </ul>
              <div class="tab-content">
                <div class="tab-pane fade show active" id="professional-editor-data-panel" role="tabpanel" aria-labelledby="professional-editor-data-tab">
              <div class="professional-editor-compact">
                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="professional-editor-name">Nombre</label>
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="professional-editor-name" required>
                  </div>
                  <label class="col-lg-2 col-form-label" for="professional-editor-email">Email de acceso</label>
                  <div class="col-lg-4">
                    <input type="email" class="form-control" id="professional-editor-email" required>
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="professional-editor-title-field">Cargo</label>
                  <div class="col-lg-10">
                    <input type="text" class="form-control" id="professional-editor-title-field" placeholder="<?= $professional_title_placeholder ?>">
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label professional-editor-permission-wrap <?= $is_superadmin ? '' : 'd-none' ?>" for="professional-editor-role">Tipo de miembro</label>
                  <div class="col-lg-4 professional-editor-permission-wrap <?= $is_superadmin ? '' : 'd-none' ?>">
                    <select class="form-select" id="professional-editor-role">
                      <option value="admin">Profesional</option>
                      <option value="reception">Recepci&oacute;n</option>
                      <option value="administration">Administraci&oacute;n</option>
                      <option value="technical">T&eacute;cnico</option>
                      <option value="superadmin">Superadmin</option>
                    </select>
                  </div>
                  <label class="col-lg-2 col-form-label" for="professional-editor-phone">Tel&eacute;fono</label>
                  <div class="<?= $is_superadmin ? 'col-lg-4' : 'col-lg-10' ?>">
                    <input type="text" class="form-control" id="professional-editor-phone" placeholder="Ej. 600 000 000">
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3 professional-extra-field">
                  <label class="col-lg-2 col-form-label" for="professional-editor-license">N&ordm; de colegiado</label>
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="professional-editor-license" placeholder="Ej. T-00000">
                  </div>
                  <label class="col-lg-2 col-form-label" for="professional-editor-college">Colegio profesional</label>
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="professional-editor-college" placeholder="Ej. Colegio Oficial de Psicolog&iacute;a">
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3 professional-extra-field">
                  <label class="col-lg-2 col-form-label" for="professional-editor-specialty">Especialidad</label>
                  <div class="col-lg-10">
                    <textarea class="form-control" id="professional-editor-specialty" rows="2" placeholder="<?= $professional_specialty_placeholder ?>"></textarea>
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3 professional-extra-field">
                  <label class="col-lg-2 col-form-label" for="professional-editor-bio">Informaci&oacute;n sobre m&iacute;</label>
                  <div class="col-lg-10">
                    <textarea class="form-control" id="professional-editor-bio" rows="3" placeholder="Presentaci&oacute;n breve del profesional, enfoque de trabajo, experiencia o forma de acompa&ntilde;ar al <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>..."></textarea>
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3 professional-extra-field">
                  <label class="col-lg-2 col-form-label" for="professional-editor-instagram">Instagram</label>
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="professional-editor-instagram" placeholder="https://instagram.com/...">
                  </div>
                  <label class="col-lg-2 col-form-label" for="professional-editor-facebook">Facebook</label>
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="professional-editor-facebook" placeholder="https://facebook.com/...">
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3 professional-extra-field">
                  <label class="col-lg-2 col-form-label" for="professional-editor-tiktok">TikTok</label>
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="professional-editor-tiktok" placeholder="https://tiktok.com/@...">
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3 professional-extra-field <?= $email_reminders_plan_enabled ? '' : 'opacity-50' ?>">
                  <label class="col-lg-2 col-form-label" for="professional-editor-summary-mode">Resumen de citas</label>
                  <div class="col-lg-10">
                    <select class="form-select" id="professional-editor-summary-mode" <?= $email_reminders_plan_enabled ? '' : 'disabled title="Disponible en un plan superior"' ?>>
                      <option value="disabled">Desactivado</option>
                      <option value="tomorrow_evening">Recibir email con planning del d&iacute;a siguiente a &uacute;ltima hora de la tarde</option>
                      <option value="today_morning">Recibir email con planning del d&iacute;a a primera hora de la ma&ntilde;ana</option>
                      <option value="on_booking">Recibir un planning de las pr&oacute;ximas citas cada vez que se reciba un aviso de reserva</option>
                    </select>
                    <div class="form-text" id="professional-editor-summary-status"></div>
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3 professional-bookable-field">
                  <label class="col-lg-2 col-form-label">Servicios</label>
                  <div class="col-lg-10">
                    <div class="row g-2" id="professional-editor-session-types"></div>
                    <div class="form-text">Elige qu&eacute; servicios ofrece este profesional de entre los servicios globales.</div>
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3 professional-bookable-field">
                  <label class="col-lg-2 col-form-label">Duraciones</label>
                  <div class="col-lg-10">
                    <div class="row g-2" id="professional-editor-session-durations"></div>
                    <div class="form-text">Elige qu&eacute; duraciones ofrece este profesional de entre las duraciones globales.</div>
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3 professional-bookable-field">
                  <label class="col-lg-2 col-form-label" for="professional-editor-default-location-id">Ubicaci&oacute;n predeterminada</label>
                  <div class="col-lg-10">
                    <select class="form-select" id="professional-editor-default-location-id"></select>
                    <input type="hidden" id="professional-editor-default-location" value="">
                    <div class="form-text">Si no se especifica, se entender&aacute; que la cita es en el centro de trabajo.</div>
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3 professional-bookable-field">
                  <label class="col-lg-2 col-form-label" for="professional-editor-video-provider">Videollamadas</label>
                  <div class="col-lg-10">
                    <select class="form-select" id="professional-editor-video-provider">
                      <option value="livekit">LiveKit</option>
                      <option value="daily">Daily</option>
                      <option value="manual">Enlace manual (Zoom, Teams u otro proveedor)</option>
                    </select>
                    <div class="form-text" id="professional-editor-livekit-help">El proveedor se aplica a las citas online de este profesional.</div>
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3 professional-bookable-field">
                  <label class="col-lg-2 col-form-label">Grabación</label>
                  <div class="col-lg-10">
                    <div class="d-flex flex-column flex-md-row align-items-md-center gap-2">
                      <div class="form-check form-switch mb-0 flex-grow-1">
                        <input class="form-check-input" type="checkbox" id="professional-editor-livekit-recording-enabled">
                        <label class="form-check-label" for="professional-editor-livekit-recording-enabled">Permitir grabación de sesiones online</label>
                      </div>
                      <select class="form-select flex-shrink-0" id="professional-editor-livekit-recording-mode" style="max-width: 260px;">
                        <option value="audio">Solo audio</option>
                        <option value="audio_video">Audio y vídeo</option>
                      </select>
                    </div>
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3 professional-bookable-field" id="professional-editor-knowledge-row">
                  <label class="col-lg-2 col-form-label" for="professional-editor-knowledge-mode">Base de conocimiento</label>
                  <div class="col-lg-10">
                    <div class="d-flex flex-column flex-md-row gap-2">
                      <select class="form-select" id="professional-editor-knowledge-mode">
                        <option value="own">Solo sector principal</option>
                        <option value="related">Sector principal y relacionados</option>
                        <option value="custom">Personalizado</option>
                      </select>
                      <button type="button" class="btn btn-outline-primary flex-shrink-0 d-none" id="btn-professional-knowledge-sectors">
                        <i class="bi bi-sliders"></i> Elegir sectores
                      </button>
                    </div>
                    <input type="hidden" id="professional-editor-knowledge-sector-keys" value="[]">
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="professional-editor-photo">Foto</label>
                  <div class="col-lg-10">
                    <input type="file" class="form-control" id="professional-editor-photo" accept="image/jpeg,image/png,image/webp,image/gif">
                    <div class="d-flex align-items-center gap-3 mt-2">
                      <img src="" alt="" id="professional-editor-photo-preview" class="d-none" style="width: 56px; height: 56px; border-radius: 50%; object-fit: cover;">
                      <div class="form-text" id="professional-editor-photo-status">Formatos permitidos: JPG, PNG, WEBP o GIF. M&aacute;ximo 5 MB.</div>
                    </div>
                  </div>
                </div>

                <div class="row g-3 align-items-start">
                  <div class="offset-lg-2 col-lg-10 professional-editor-status-wrap">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" id="professional-editor-active" checked>
                      <label class="form-check-label" for="professional-editor-active">Miembro activo</label>
                    </div>
                    <div class="form-text">Si est&aacute; desactivado, no podr&aacute; usarse como miembro activo del equipo.</div>
                  </div>
                </div>
              </div>
                </div>
                <div class="tab-pane fade" id="professional-editor-extra-panel" role="tabpanel" aria-labelledby="professional-editor-extra-tab">
                  <div id="professional-editor-extra-fields"></div>
                  <?php if ($is_superadmin): ?>
                  <hr class="my-4">
                  <div class="row g-3 align-items-center mb-1">
                    <label class="col-lg-2 col-form-label" for="professional-editor-contract-hours">N&ordm; Horas contrato</label>
                    <div class="col-lg-4">
                      <input type="number" class="form-control" id="professional-editor-contract-hours" min="0.25" max="168" step="0.25" placeholder="8">
                    </div>
                    <div class="col-lg-4">
                      <select class="form-select" id="professional-editor-contract-hours-unit">
                        <option value="daily">Diarias</option>
                        <option value="weekly">Semanales</option>
                      </select>
                    </div>
                  </div>
                  <?php endif; ?>
                </div>
                <div class="tab-pane fade" id="professional-editor-preferences-panel" role="tabpanel" aria-labelledby="professional-editor-preferences-tab">
                  <div class="alert alert-info small py-2">Si no eliges una vista o zona horaria propias, se usar&aacute; la configuraci&oacute;n general del centro.</div>
                  <div class="row g-3 mb-3">
                    <div class="col-md-6">
                      <label class="form-label" for="professional-editor-initial-view">Vista inicial</label>
                      <select class="form-select form-select-sm" id="professional-editor-initial-view">
                        <option value="">Usar la del centro</option><option value="dashboard">Dashboard</option><option value="month">Mes</option><option value="week">Semana</option><option value="patients">Pacientes</option><option value="upcoming">Pr&oacute;ximas citas</option>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label" for="professional-editor-timezone">Zona horaria</label>
                      <select class="form-select form-select-sm" id="professional-editor-timezone">
                        <option value="">Usar la del centro</option>
                        <?php foreach (timezone_identifiers_list() as $timezone_option): ?>
                          <option value="<?= htmlspecialchars($timezone_option, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($timezone_option, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <fieldset <?= $email_reminders_plan_enabled ? '' : 'disabled' ?> class="<?= $email_reminders_plan_enabled ? '' : 'opacity-50' ?>">
                  <h6>Notificaciones<?= $email_reminders_plan_enabled ? '' : ' <i class="bi bi-lock-fill ms-1"></i>' ?></h6>
                  <div class="row g-2" <?= $email_reminders_plan_enabled ? '' : 'title="Disponibles en un plan superior"' ?>>
                    <?php foreach ([
                      'new-appointments' => 'Nuevas citas', 'cancellations' => 'Cancelaciones', 'payments' => 'Pagos',
                      'daily-summary' => 'Recordatorio diario', 'waiting-list' => 'Lista de espera'
                    ] as $preference_key => $preference_label): ?>
                    <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="professional-editor-notify-<?= $preference_key ?>"><label class="form-check-label" for="professional-editor-notify-<?= $preference_key ?>"><?= $preference_label ?></label></div></div>
                    <?php endforeach; ?>
                  </div>
                  </fieldset>
                </div>
                <?php if ($is_superadmin): ?>
                <div class="tab-pane fade" id="professional-editor-permissions-panel" role="tabpanel" aria-labelledby="professional-editor-permissions-tab">
                  <div class="alert alert-info small py-2">
                    El tipo de miembro aplica una plantilla inicial de permisos. Despu&eacute;s puedes ajustar cada permiso de forma individual.
                  </div>
                  <div class="row g-3">
                    <div class="col-md-6">
                      <div class="form-check form-switch">
                        <input class="form-check-input professional-permission-check" type="checkbox" id="professional-permission-bookable" data-permission="bookable">
                        <label class="form-check-label" for="professional-permission-bookable">Disponible como profesional</label>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-check form-switch">
                        <input class="form-check-input professional-permission-check" type="checkbox" id="professional-permission-settings" data-permission="settings">
                        <label class="form-check-label" for="professional-permission-settings">Acceso a Configuraci&oacute;n</label>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-check form-switch">
                        <input class="form-check-input professional-permission-check" type="checkbox" id="professional-permission-agenda" data-permission="agenda">
                        <label class="form-check-label" for="professional-permission-agenda">Acceso a la Agenda</label>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-check form-switch">
                        <input class="form-check-input professional-permission-check" type="checkbox" id="professional-permission-patients" data-permission="patients">
                        <label class="form-check-label" for="professional-permission-patients">Acceso a Pacientes</label>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-check form-switch">
                        <input class="form-check-input professional-permission-check" type="checkbox" id="professional-permission-appointments" data-permission="appointments">
                        <label class="form-check-label" for="professional-permission-appointments">Acceso a Citas</label>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-check form-switch">
                        <input class="form-check-input professional-permission-check" type="checkbox" id="professional-permission-statistics" data-permission="statistics">
                        <label class="form-check-label" for="professional-permission-statistics">Acceso a Estad&iacute;sticas</label>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-check form-switch">
                        <input class="form-check-input professional-permission-check" type="checkbox" id="professional-permission-view-patient-phone" data-permission="view_patient_phone">
                        <label class="form-check-label" for="professional-permission-view-patient-phone">Mostrar tel&eacute;fono del paciente</label>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-check form-switch">
                        <input class="form-check-input professional-permission-check" type="checkbox" id="professional-permission-billing" data-permission="billing">
                        <label class="form-check-label" for="professional-permission-billing">Acceso a Facturas</label>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-check form-switch">
                        <input class="form-check-input professional-permission-check" type="checkbox" id="professional-permission-billing-own-patients" data-permission="billing_own_patients">
                        <label class="form-check-label" for="professional-permission-billing-own-patients">Acceso solo a facturas de sus pacientes</label>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-check form-switch">
                        <input class="form-check-input professional-permission-check" type="checkbox" id="professional-permission-reports" data-permission="reports">
                        <label class="form-check-label" for="professional-permission-reports">Acceso, descarga y generaci&oacute;n de informes</label>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-check form-switch">
                        <input class="form-check-input professional-permission-check" type="checkbox" id="professional-permission-private-patient-data" data-permission="private_patient_data">
                        <label class="form-check-label" for="professional-permission-private-patient-data">Acceso a datos privados del paciente</label>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-check form-switch">
                        <input class="form-check-input professional-permission-check" type="checkbox" id="professional-permission-create-appointments" data-permission="create_appointments">
                        <label class="form-check-label" for="professional-permission-create-appointments">Crear citas</label>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-check form-switch">
                        <input class="form-check-input professional-permission-check" type="checkbox" id="professional-permission-cancel-appointments" data-permission="cancel_appointments">
                        <label class="form-check-label" for="professional-permission-cancel-appointments">Cancelar citas</label>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-check form-switch">
                        <input class="form-check-input professional-permission-check" type="checkbox" id="professional-permission-create-patients" data-permission="create_patients">
                        <label class="form-check-label" for="professional-permission-create-patients">Crear nuevos pacientes</label>
                      </div>
                    </div>
                  </div>
                </div>
                <?php endif; ?>
                <?php if ($professional_signature_plan_enabled): ?>
                <div class="tab-pane fade" id="professional-editor-certificate-panel" role="tabpanel" aria-labelledby="professional-editor-certificate-tab">
                  <div class="alert alert-info small py-2">La contrase&ntilde;a se usa solo para importar el PFX/P12 y no se guarda. Cada profesional solo puede gestionar y utilizar su propio certificado y el gen&eacute;rico del centro si existe.</div>
                  <div id="my-signature-certificate-alert" class="alert d-none"></div>
                  <div id="my-signature-certificate-status" class="border rounded p-3 mb-3 text-muted small">Comprobando certificado...</div>
                  <div id="my-signature-certificate-form">
                    <input type="hidden" name="professional_id" value="0">
                    <div class="row g-3 align-items-end">
                      <div class="col-md-6">
                        <label class="form-label" for="my-signature-certificate-file">Certificado PFX/P12</label>
                        <input type="file" class="form-control" id="my-signature-certificate-file" name="signature_certificate" accept=".pfx,.p12,application/x-pkcs12">
                      </div>
                      <div class="col-md-4">
                        <label class="form-label" for="my-signature-certificate-password">Contrase&ntilde;a</label>
                        <input type="password" class="form-control" id="my-signature-certificate-password" name="certificate_password" autocomplete="new-password">
                      </div>
                      <div class="col-md-2 d-flex gap-2">
                        <button type="button" class="btn btn-primary flex-grow-1" id="btn-import-my-signature-certificate" title="Importar certificado"><i class="bi bi-shield-check"></i></button>
                        <button type="button" class="btn btn-outline-danger d-none" id="btn-remove-my-signature-certificate" title="Eliminar certificado"><i class="bi bi-trash"></i></button>
                      </div>
                    </div>
                  </div>
                </div>
                <?php endif; ?>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn btn-primary" type="submit" id="btn-save-professional-editor">Guardar cambios</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade" id="professionalKnowledgeSectorsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Sectores de conocimiento</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-info small">
              Elige qu&eacute; base de conocimiento quieres usar para tus diagn&oacute;sticos y objetivos. A&ntilde;adir varios sectores puede hacer que aparezcan diagn&oacute;sticos, t&eacute;cnicas o tareas duplicadas en el buscador.
            </div>
            <div id="professional-knowledge-sectors-list" class="d-flex flex-column gap-2"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btn-save-professional-knowledge-sectors">Guardar selecci&oacute;n</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="professionalTransferModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="professional-delete-title">Borrar miembro</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="transfer-delete-professional-id" value="0">
            <p id="transfer-delete-summary" class="mb-3"></p>
            <div class="alert alert-warning small">
              <strong>Importante:</strong> al borrar un profesional, se eliminará también su cuenta de acceso al sistema.
            </div>
            <p class="text-muted small mb-3">Se recomienda comprobar primero si el profesional tiene citas o <?= htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?> pendientes.</p>
            <div id="transfer-delete-target-wrap" class="d-none">
              <label class="form-label" for="transfer-delete-target">Traspasar citas y <?= htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?> a</label>
              <select class="form-select" id="transfer-delete-target"></select>
              <div class="form-text">El profesional se borrará después de completar el traspaso.</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-danger" id="btn-confirm-transfer-delete">Borrar</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="serviceCatalogItemModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
      <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="service-catalog-item-title">Nuevo servicio</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-info small py-2" id="service-catalog-item-info">
              Crea un nuevo servicio. Los profesionales podr&aacute;n elegir qu&eacute; servicios ofrecen entre los que hayas habilitado.
            </div>
            <div id="service-catalog-item-alert" class="alert alert-danger d-none small py-2"></div>
            <input type="hidden" id="service-catalog-item-type" value="service">
            <div class="mb-3" id="service-catalog-name-wrap">
              <label class="form-label" for="service-catalog-name">Nombre</label>
              <input type="text" class="form-control" id="service-catalog-name" maxlength="120">
            </div>
            <div class="mb-3 d-none" id="service-catalog-duration-wrap">
              <label class="form-label" for="service-catalog-duration">Duraci&oacute;n</label>
              <div class="input-group">
                <input type="number" class="form-control" id="service-catalog-duration" min="5" max="480" step="5">
                <span class="input-group-text">min</span>
              </div>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="service-catalog-enabled" checked>
              <label class="form-check-label" for="service-catalog-enabled">Habilitado</label>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btn-save-service-catalog-item">Guardar</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="serviceCatalogDeleteModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
      <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Eliminar</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="service-catalog-delete-alert" class="alert alert-warning small py-2 mb-0"></div>
            <input type="hidden" id="service-catalog-delete-type" value="">
            <input type="hidden" id="service-catalog-delete-key" value="">
            <input type="hidden" id="service-catalog-delete-id" value="0">
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-danger" id="btn-confirm-service-catalog-delete">
              <i class="bi bi-trash"></i> Eliminar
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="appointmentLocationItemModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
      <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Nueva ubicaci&oacute;n</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-info small py-2">Crea una nueva sala o ubicaci&oacute;n. Los profesionales podr&aacute;n usarla como ubicaci&oacute;n predeterminada si est&aacute; habilitada. Por ejemplo: Sala 1, Box 3, etc.</div>
            <div id="appointment-location-item-alert" class="alert alert-danger d-none small py-2"></div>
            <div class="mb-3">
              <label class="form-label" for="appointment-location-name">Nombre</label>
              <input type="text" class="form-control" id="appointment-location-name" maxlength="120" placeholder="Sala 2, Consulta norte...">
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="appointment-location-enabled" checked>
              <label class="form-check-label" for="appointment-location-enabled">Habilitada</label>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btn-save-appointment-location">Guardar</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="appointmentLocationDeleteModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
      <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Eliminar ubicaci&oacute;n</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="appointment-location-delete-alert" class="alert alert-warning small py-2 mb-0"></div>
            <input type="hidden" id="appointment-location-delete-id" value="0">
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-danger" id="btn-confirm-appointment-location-delete">
              <i class="bi bi-trash"></i> Eliminar
            </button>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$is_admin): ?>
    <div class="modal fade" id="patientSelfDataModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Mis datos</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form id="patient-self-data-form">
            <div class="modal-body">
              <div id="patient-self-data-alert" class="alert d-none"></div>
              <ul class="nav nav-tabs mb-4" id="patient-self-data-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                  <button class="nav-link active" id="patient-self-contact-tab" data-bs-toggle="tab" data-bs-target="#patient-self-contact-panel" type="button" role="tab">Mis datos</button>
                </li>
                <?php if (!empty($initial_billing_settings['billing_enabled'])): ?>
                  <li class="nav-item" role="presentation">
                    <button class="nav-link" id="patient-self-billing-tab" data-bs-toggle="tab" data-bs-target="#patient-self-billing-panel" type="button" role="tab">Datos facturaci&oacute;n</button>
                  </li>
                <?php endif; ?>
              </ul>
              <div class="tab-content">
                <div class="tab-pane fade show active" id="patient-self-contact-panel" role="tabpanel" aria-labelledby="patient-self-contact-tab">
                  <div class="mb-3">
                    <label class="form-label" for="patient-self-email">Email</label>
                    <input type="email" class="form-control" id="patient-self-email" name="email" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label" for="patient-self-phone">Tel&eacute;fono</label>
                    <input type="text" class="form-control" id="patient-self-phone" name="phone">
                  </div>
                  <div>
                    <label class="form-label" for="patient-self-photo">Foto de perfil</label>
                    <input type="file" class="form-control" id="patient-self-photo" name="patient_photo" accept="image/jpeg,image/png,image/webp,image/gif">
                    <div class="d-flex align-items-center gap-3 mt-2">
                      <img src="" alt="" id="patient-self-photo-preview" class="d-none" style="width: 56px; height: 56px; border-radius: 50%; object-fit: cover;">
                      <div class="form-text" id="patient-self-photo-status">Formatos permitidos: JPG, PNG, WEBP o GIF. M&aacute;ximo 2 MB.</div>
                    </div>
                  </div>
                </div>
                <?php if (!empty($initial_billing_settings['billing_enabled'])): ?>
                  <div class="tab-pane fade" id="patient-self-billing-panel" role="tabpanel" aria-labelledby="patient-self-billing-tab">
                    <div id="patient-self-billing-locked-alert" class="alert alert-info small d-none">
                      Si necesitas modificar alg&uacute;n dato existente, contacta con nosotros.
                    </div>
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label class="form-label" for="patient-self-fiscal-name">Nombre fiscal</label>
                        <input type="text" class="form-control patient-self-lockable-billing-field" id="patient-self-fiscal-name" name="fiscal_name" maxlength="180">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label" for="patient-self-fiscal-nif">NIF / CIF</label>
                        <input type="text" class="form-control patient-self-lockable-billing-field" id="patient-self-fiscal-nif" name="fiscal_nif" maxlength="50">
                      </div>
                      <div class="col-12">
                        <label class="form-label" for="patient-self-address">Direcci&oacute;n</label>
                        <input type="text" class="form-control patient-self-lockable-billing-field" id="patient-self-address" name="address" maxlength="255">
                      </div>
                      <div class="col-12">
                        <div class="form-check form-switch">
                          <input class="form-check-input" type="checkbox" id="patient-self-invoice-use-alt-data" name="invoice_use_alt_data" value="1">
                          <label class="form-check-label" for="patient-self-invoice-use-alt-data">La factura ir&aacute; a nombre de otra persona</label>
                        </div>
                      </div>
                      <div class="col-md-6 patient-self-alt-billing-wrap d-none">
                        <label class="form-label" for="patient-self-invoice-name">Nombre</label>
                        <input type="text" class="form-control patient-self-alt-billing-field patient-self-lockable-billing-field" id="patient-self-invoice-name" name="invoice_name" maxlength="180">
                      </div>
                      <div class="col-md-6 patient-self-alt-billing-wrap d-none">
                        <label class="form-label" for="patient-self-invoice-nif">NIF / CIF</label>
                        <input type="text" class="form-control patient-self-alt-billing-field patient-self-lockable-billing-field" id="patient-self-invoice-nif" name="invoice_nif" maxlength="50">
                      </div>
                      <div class="col-md-6 patient-self-alt-billing-wrap d-none">
                        <label class="form-label" for="patient-self-invoice-email">Email</label>
                        <input type="email" class="form-control patient-self-alt-billing-field patient-self-lockable-billing-field" id="patient-self-invoice-email" name="invoice_email" maxlength="180">
                      </div>
                      <div class="col-md-6 patient-self-alt-billing-wrap d-none">
                        <label class="form-label" for="patient-self-invoice-phone">Tel&eacute;fono</label>
                        <input type="text" class="form-control patient-self-alt-billing-field patient-self-lockable-billing-field" id="patient-self-invoice-phone" name="invoice_phone" maxlength="40">
                      </div>
                      <div class="col-12 patient-self-alt-billing-wrap d-none">
                        <label class="form-label" for="patient-self-invoice-address">Direcci&oacute;n</label>
                        <input type="text" class="form-control patient-self-alt-billing-field patient-self-lockable-billing-field" id="patient-self-invoice-address" name="invoice_address" maxlength="255">
                      </div>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button class="btn btn-primary" type="submit" id="btn-save-patient-self-data">Guardar datos</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$is_admin): ?>
    <div class="modal fade" id="handwrittenConsentModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable handwritten-consent-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <h5 class="modal-title">Firma online</h5>
              <div class="text-muted small" id="handwritten-consent-subtitle">Lectura y firma del consentimiento</div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="handwritten-consent-alert" class="alert d-none"></div>
            <input type="hidden" id="handwritten-consent-patient-id" value="0">
            <input type="hidden" id="handwritten-consent-document-id" value="0">

            <div class="consent-wizard-steps consent-wizard-steps-five mb-3" aria-label="Progreso de la firma">
              <div class="consent-wizard-step active" data-step="1"><span>1</span><small>Leer</small></div>
              <div class="consent-wizard-step" data-step="2"><span>2</span><small>M&eacute;todo</small></div>
              <div class="consent-wizard-step" data-step="3"><span>3</span><small>Identificar</small></div>
              <div class="consent-wizard-step" data-step="4"><span>4</span><small>Firmar</small></div>
              <div class="consent-wizard-step" data-step="5"><span>5</span><small>Confirmar</small></div>
            </div>

            <section class="handwritten-consent-step" data-step="1">
              <div class="alert alert-info small py-2">
                Lee el documento completo antes de continuar. Puedes desplazarte dentro del visor y ampliarlo desde los controles del navegador.
              </div>
              <iframe id="handwritten-consent-document-frame" class="handwritten-consent-frame" title="Documento que se va a firmar" src="about:blank"></iframe>
              <div class="form-check mt-3">
                <input class="form-check-input" type="checkbox" id="handwritten-consent-read">
                <label class="form-check-label" for="handwritten-consent-read">He le&iacute;do el documento y he podido resolver mis dudas.</label>
              </div>
            </section>

            <section class="handwritten-consent-step d-none" data-step="2">
              <div class="alert alert-info small">
                Elige c&oacute;mo quieres firmar el documento. Puedes cambiar de m&eacute;todo antes de completar la firma.
              </div>
              <div class="consent-sign-methods">
                <label class="consent-sign-method active" for="consent-sign-method-handwritten">
                  <input class="form-check-input" type="radio" name="consent_sign_method" id="consent-sign-method-handwritten" value="handwritten" checked>
                  <span class="consent-sign-method-icon"><i class="bi bi-pen"></i></span>
                  <span>
                    <strong>Firma manuscrita</strong>
                    <small>Firma en pantalla con el dedo, rat&oacute;n o l&aacute;piz digital.</small>
                  </span>
                </label>
                <label class="consent-sign-method <?= $autofirma_patient_signing_enabled ? '' : 'disabled' ?>" for="consent-sign-method-autofirma">
                  <input class="form-check-input" type="radio" name="consent_sign_method" id="consent-sign-method-autofirma" value="autofirma" <?= $autofirma_patient_signing_enabled ? '' : 'disabled' ?>>
                  <span class="consent-sign-method-icon"><i class="bi bi-patch-check"></i></span>
                  <span>
                    <strong>Firma con certificado digital</strong>
                    <small><?= $autofirma_patient_signing_enabled ? 'Usa un certificado instalado en tu equipo mediante AutoFirma.' : 'Esta opci&oacute;n no est&aacute; disponible actualmente.' ?></small>
                  </span>
                </label>
              </div>
              <div id="autofirma-certificate-selection" class="alert alert-secondary small mt-3 d-none" role="status"></div>
            </section>

            <section class="handwritten-consent-step d-none" data-step="3">
              <div class="alert alert-info small">
                Indica qui&eacute;n firma. Puede ser el propio paciente o su representante legal.
              </div>
              <div class="row g-3">
                <div class="col-md-8">
                  <label class="form-label" for="handwritten-consent-signer-name">Nombre y apellidos del firmante</label>
                  <input type="text" class="form-control" id="handwritten-consent-signer-name" maxlength="180" autocomplete="name">
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="handwritten-consent-signer-nif">NIF / NIE <span class="text-muted">(opcional)</span></label>
                  <input type="text" class="form-control" id="handwritten-consent-signer-nif" maxlength="50">
                </div>
              </div>
            </section>

            <section class="handwritten-consent-step d-none" data-step="4">
              <div id="handwritten-consent-signature-panel">
                <div class="text-center mb-3">
                  <h6>Firma de la persona interesada</h6>
                  <p class="text-muted mb-0">Firma dentro del recuadro con el dedo, rat&oacute;n o l&aacute;piz digital.</p>
                </div>
                <div class="handwritten-signature-wrap">
                  <canvas id="handwritten-consent-canvas" aria-label="Recuadro para firma manuscrita"></canvas>
                </div>
                <div class="text-center mt-2">
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-clear-handwritten-consent">
                    <i class="bi bi-eraser"></i> Borrar firma
                  </button>
                </div>
              </div>
              <div id="autofirma-consent-signature-panel" class="d-none">
                <div class="text-center mb-4">
                  <span class="autofirma-sign-icon"><i class="bi bi-patch-check"></i></span>
                  <h6 class="mt-3">Firma con AutoFirma</h6>
                  <p class="text-muted mb-0">AutoFirma abrir&aacute; el certificado seleccionado para firmar este PDF.</p>
                </div>
                <div id="autofirma-sign-status" class="alert alert-secondary small" role="status">
                  Preparado para firmar el documento.
                </div>
                <div class="text-center">
                  <button type="button" class="btn btn-primary" id="btn-sign-consent-autofirma">
                    <i class="bi bi-patch-check me-1"></i> Firmar ahora con AutoFirma
                  </button>
                </div>
              </div>
            </section>

            <section class="handwritten-consent-step d-none" data-step="5">
              <div class="alert alert-warning small">
                Revisa los datos antes de terminar. Al confirmar se generar&aacute; el PDF firmado y el consentimiento quedar&aacute; marcado autom&aacute;ticamente como aceptado y firmado.
              </div>
              <div class="border p-3">
                <div class="row g-3">
                  <div class="col-md-6"><small class="text-muted d-block">Paciente</small><strong id="handwritten-summary-patient"></strong></div>
                  <div class="col-md-6"><small class="text-muted d-block">Documento</small><strong id="handwritten-summary-document"></strong></div>
                  <div class="col-md-6"><small class="text-muted d-block">Firmante</small><strong id="handwritten-summary-signer"></strong></div>
                  <div class="col-md-6"><small class="text-muted d-block">M&eacute;todo</small><strong id="handwritten-summary-method">Firma manuscrita online</strong></div>
                </div>
                <div class="mt-3" id="handwritten-summary-signature-wrap">
                  <small class="text-muted d-block mb-1">Firma</small>
                  <img id="handwritten-summary-signature" class="handwritten-summary-signature" alt="Vista previa de la firma">
                </div>
                <div class="mt-3 d-none" id="autofirma-summary-certificate-wrap">
                  <small class="text-muted d-block mb-2">Certificado utilizado</small>
                  <dl class="autofirma-certificate-summary small mb-0" id="autofirma-summary-certificate"></dl>
                </div>
              </div>
            </section>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary me-auto d-none" id="btn-handwritten-consent-previous">
              <i class="bi bi-chevron-left"></i> Anterior
            </button>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btn-handwritten-consent-next">
              Continuar <i class="bi bi-chevron-right"></i>
            </button>
            <button type="button" class="btn btn-success d-none" id="btn-handwritten-consent-finish">
              <i class="bi bi-check2"></i> Firmar y guardar
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="patientPortalAppointmentsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Mis citas</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="patient-portal-appointments" class="patient-portal-list patient-portal-modal-list">
              <div class="text-muted small">Cargando citas...</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="patientPortalTasksModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Mis tareas</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="patient-portal-tasks" class="patient-portal-list patient-portal-modal-list">
              <div class="text-muted small">Cargando tareas...</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="patientPortalDocumentsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Mis documentos</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="btn-group btn-group-sm mb-3" role="group" aria-label="Filtrar documentos">
              <button type="button" class="btn btn-primary patient-portal-documents-filter" data-documents-filter="all">Todo</button>
              <button type="button" class="btn btn-outline-primary patient-portal-documents-filter" data-documents-filter="file">Archivos</button>
              <button type="button" class="btn btn-outline-primary patient-portal-documents-filter" data-documents-filter="questionnaire">Cuestionarios</button>
            </div>
            <div id="patient-portal-documents" class="patient-portal-list patient-portal-modal-list">
              <div class="text-muted small">Cargando documentos...</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php if ($questionnaires_plan_enabled): ?>
    <div class="modal fade" id="patientPortalQuestionnairesModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Mis cuestionarios</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div id="patient-questionnaires-pending-alert" class="alert alert-warning small d-none"></div>
          <div class="patient-questionnaires-host" data-questionnaire-context="portal"><div class="text-center text-muted py-4">Cargando cuestionarios...</div></div>
        </div>
      </div></div>
    </div>
    <?php endif; ?>

    <div class="modal fade" id="patientPortalReportsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Mis informes</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="patient-portal-reports" class="patient-portal-list patient-portal-modal-list">
              <div class="text-muted small">Cargando informes...</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php if ($physical_metrics_available): ?>
      <div class="modal fade" id="patientPortalCompositionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Mi progreso</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <ul class="nav nav-tabs mb-3" id="patient-portal-composition-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                  <button class="nav-link active" id="patient-portal-composition-current-tab" data-bs-toggle="tab" data-bs-target="#patient-portal-composition-current-panel" type="button" role="tab">Valores actuales</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" id="patient-portal-composition-history-tab" data-bs-toggle="tab" data-bs-target="#patient-portal-composition-history-panel" type="button" role="tab">Evoluci&oacute;n</button>
                </li>
              </ul>
              <div class="tab-content">
                <div class="tab-pane fade show active" id="patient-portal-composition-current-panel" role="tabpanel" aria-labelledby="patient-portal-composition-current-tab">
                  <div id="patient-portal-composition-current" class="patient-portal-composition">
                    <div class="text-muted small">Cargando valores...</div>
                  </div>
                </div>
                <div class="tab-pane fade" id="patient-portal-composition-history-panel" role="tabpanel" aria-labelledby="patient-portal-composition-history-tab">
                  <div class="patient-evolution-chart-toolbar">
                    <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Selector de gr&aacute;fico">
                      <button type="button" class="btn btn-primary patient-portal-composition-chart-group" data-chart-group="body">Peso, IMC y grasa</button>
                      <button type="button" class="btn btn-outline-primary patient-portal-composition-chart-group" data-chart-group="metrics">M&eacute;tricas</button>
                      <button type="button" class="btn btn-outline-primary patient-portal-composition-chart-group" data-chart-group="skinfolds">Pliegues</button>
                    </div>
                  </div>
                  <div id="patient-portal-composition-chart-grid" class="patient-evolution-chart-grid mb-3">
                    <div class="text-muted small">Cargando gr&aacute;ficos...</div>
                  </div>
                  <div id="patient-portal-composition-history" class="patient-portal-composition"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Cambiar contraseña</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="change-password-form">
          <div class="modal-body">
            <div id="change-password-alert" class="alert d-none"></div>
            <div class="mb-3">
              <label class="form-label" for="current-password">Contraseña actual</label>
              <input type="password" class="form-control" id="current-password" name="current_password" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="new-password">Nueva contraseña</label>
              <input type="password" class="form-control" id="new-password" name="new_password" required minlength="6">
            </div>
            <div>
              <label class="form-label" for="new-password-confirm">Repetir nueva contraseña</label>
              <input type="password" class="form-control" id="new-password-confirm" required minlength="6">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button class="btn btn-primary" type="submit">Guardar contraseña</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if ($knowledge_base_enabled && !$body_map_enabled): ?>
  <div class="modal fade" id="knowledgeBaseWizardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h5 class="modal-title">Usar base de conocimiento</h5>
            <div class="small text-muted" id="knowledge-wizard-progress-label">Problema</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning small">
            <?= htmlspecialchars($knowledge_disclaimer, ENT_QUOTES, 'UTF-8') ?>
          </div>
          <div class="knowledge-wizard-steps mb-4" id="knowledge-wizard-steps"></div>
          <div id="knowledge-wizard-alert" class="alert d-none"></div>
          <div id="knowledge-wizard-content"></div>
        </div>
        <div class="modal-footer justify-content-between">
          <button type="button" class="btn btn-outline-secondary" id="btn-knowledge-wizard-cancel" data-bs-dismiss="modal">Cancelar</button>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-primary d-none" id="btn-knowledge-wizard-previous"><i class="bi bi-chevron-left"></i> Anterior</button>
            <button type="button" class="btn btn-primary" id="btn-knowledge-wizard-next">Siguiente <i class="bi bi-chevron-right"></i></button>
            <button type="button" class="btn btn-success d-none" id="btn-knowledge-wizard-apply"><i class="bi bi-check2-circle"></i> A&ntilde;adir al paciente</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="knowledgeDiagnosisInfoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h5 class="modal-title" id="knowledge-diagnosis-info-title">Informaci&oacute;n del diagn&oacute;stico</h5>
            <div class="small text-muted" id="knowledge-diagnosis-info-area"></div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="knowledge-diagnosis-info-content"></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="modal fade" id="signatureChoiceModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-sm modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Firmar PDF</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-3">Elige el certificado con el que quieres firmar el documento.</p>
          <div class="d-grid gap-2">
            <button type="button" class="btn btn-outline-primary signature-owner-choice" data-owner="professional"><i class="bi bi-person-check me-2"></i>Mi certificado personal</button>
            <button type="button" class="btn btn-outline-primary signature-owner-choice" data-owner="tenant"><i class="bi bi-building-check me-2"></i>Certificado del centro</button>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="messageTemplateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="message-template-title">Personalizar recordatorio</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="message-template-alert" class="alert d-none"></div>
          <input type="hidden" id="message-template-key">
          <div class="alert alert-info small py-2" role="alert">
            <i class="bi bi-info-circle me-2"></i>
            Puedes personalizar el texto principal del recordatorio. La app a&ntilde;adir&aacute; autom&aacute;ticamente informaci&oacute;n importante como enlace online, ubicaci&oacute;n, gesti&oacute;n/cancelaci&oacute;n o avisos de pago cuando corresponda.
          </div>
          <div class="mb-3" id="message-template-subject-wrap">
            <label class="form-label" for="message-template-subject">Asunto</label>
            <input type="text" class="form-control" id="message-template-subject" maxlength="255">
          </div>
          <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
            <label class="form-label mb-0" for="message-template-body">Texto</label>
            <div class="dropdown">
              <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                Variables
              </button>
              <div class="dropdown-menu dropdown-menu-end message-template-vars-menu" id="message-template-vars-menu"></div>
            </div>
          </div>
          <textarea class="form-control" id="message-template-body" rows="10"></textarea>
          <div class="d-flex justify-content-between gap-2 mt-2">
            <div class="form-text" id="message-template-help"></div>
            <div class="form-text text-nowrap" id="message-template-counter"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-outline-primary" id="btn-reset-message-template">Restaurar por defecto</button>
          <button type="button" class="btn btn-primary" id="btn-save-message-template">Guardar plantilla</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="oauthRedirectModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-body text-center px-4 py-5">
          <div class="spinner-border text-primary mb-3" role="status">
            <span class="visually-hidden">Cargando...</span>
          </div>
          <h5 class="mb-3" id="oauth-redirect-title">Iniciando integración</h5>
          <p class="mb-2" id="oauth-redirect-message"></p>
          <p class="small text-muted mb-0">
            Si sigues viendo esta ventana después de 10 segundos, vuelve a intentarlo.
          </p>
        </div>
      </div>
    </div>
  </div>

  <?php if ($is_superadmin): ?>
  <div class="modal fade" id="dataExportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-file-earmark-zip me-2"></i>Exportar todos mis datos</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning small">
            <strong>La exportaci&oacute;n contendr&aacute; informaci&oacute;n confidencial.</strong><br>
            Este proceso puede tardar hasta 30 minutos. Te avisaremos con un aviso en el Dashboard cuando est&eacute; disponible la descarga. El archivo caducar&aacute; y se eliminar&aacute; autom&aacute;ticamente 48 horas despu&eacute;s de generarse.
          </div>
          <div id="data-export-alert" class="alert d-none small"></div>
          <form id="data-export-form" autocomplete="off">
            <div class="row g-2 align-items-end">
              <div class="col-md-5">
                <label class="form-label" for="data-export-password">Contrase&ntilde;a para el ZIP</label>
                <input class="form-control form-control-sm" type="password" id="data-export-password" minlength="10" maxlength="120" required autocomplete="new-password">
              </div>
              <div class="col-md-5">
                <label class="form-label" for="data-export-password-confirm">Repetir contrase&ntilde;a</label>
                <input class="form-control form-control-sm" type="password" id="data-export-password-confirm" minlength="10" maxlength="120" required autocomplete="new-password">
              </div>
              <div class="col-md-2 d-grid">
                <button class="btn btn-primary btn-sm" type="submit" id="btn-create-data-export"><i class="bi bi-shield-lock me-1"></i>Generar</button>
              </div>
            </div>
            <div class="form-text">Guarda esta contrase&ntilde;a: por seguridad no podremos recuperarla ni volver a mostr&aacute;rtela.</div>
          </form>
          <hr>
          <h6>Exportaciones recientes</h6>
          <div id="data-export-jobs"><div class="text-center text-muted py-4">Cargando...</div></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($questionnaires_plan_enabled): ?>
  <?php if ($is_admin): ?>
  <div class="modal fade" id="assignQuestionnaireModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
      <div class="modal-header"><div><h5 class="modal-title">Mis cuestionarios</h5><div class="small text-muted">Elige un cuestionario para asignarlo.</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div id="assign-questionnaire-alert" class="alert d-none small"></div><div id="assign-questionnaire-list"></div></div>
      <div class="modal-footer justify-content-between"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="assign-questionnaire-portal" checked><label class="form-check-label small" for="assign-questionnaire-portal">El <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> podr&aacute; responder el cuestionario desde el Portal de <?= htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') ?></label></div><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button></div>
    </div></div>
  </div>
  <?php endif; ?>

  <div class="modal fade" id="patientQuestionnaireViewModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
      <div class="modal-header"><div><h5 class="modal-title" id="patient-questionnaire-view-title">Cuestionario</h5><div class="small text-muted" id="patient-questionnaire-view-status"></div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div id="patient-questionnaire-view-alert" class="alert d-none small"></div><div id="patient-questionnaire-view-content"></div></div>
      <div class="modal-footer justify-content-between"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button><div class="d-flex gap-2"><button type="button" class="btn btn-outline-primary btn-sm d-none" id="btn-save-questionnaire-progress">Guardar y continuar m&aacute;s tarde</button><button type="button" class="btn btn-primary btn-sm d-none" id="btn-submit-patient-questionnaire">Finalizar cuestionario</button><button type="button" class="btn btn-success btn-sm d-none" id="btn-review-patient-questionnaire">Guardar revisi&oacute;n</button></div></div>
    </div></div>
  </div>
  <?php endif; ?>

  <?php if ($questionnaires_plan_enabled && $is_admin): ?>
  <div class="modal fade" id="questionnaireEditorModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div><h5 class="modal-title" id="questionnaire-editor-title">Nuevo cuestionario</h5><div class="small text-muted">Configura el contenido y las preguntas del cuestionario.</div></div>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body questionnaire-editor-body">
          <div id="questionnaire-editor-alert" class="alert d-none small"></div>
          <input type="hidden" id="questionnaire-id" value="0">
          <div class="row g-3">
            <div class="col-lg-4">
              <div class="questionnaire-config-card p-3">
                <h6>Datos del cuestionario</h6>
                <div class="mb-3"><label class="form-label small" for="questionnaire-title">T&iacute;tulo</label><input class="form-control form-control-sm" id="questionnaire-title" maxlength="180"></div>
                <div class="mb-3"><label class="form-label small" for="questionnaire-description">Indicaciones para el <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?></label><textarea class="form-control form-control-sm" id="questionnaire-description" rows="3" maxlength="2000"></textarea></div>
                <div class="mb-3"><label class="form-label small" for="questionnaire-category">Categor&iacute;a</label><input class="form-control form-control-sm" id="questionnaire-category" maxlength="120" placeholder="Ej. Ansiedad"></div>
                <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="questionnaire-scored"><label class="form-check-label small" for="questionnaire-scored">Cuestionario evaluable</label></div>
                <div class="mb-3 d-none" id="questionnaire-pass-wrap"><label class="form-label small" for="questionnaire-pass">Aprobado desde</label><div class="input-group input-group-sm"><input class="form-control" id="questionnaire-pass" type="number" min="0" max="100" value="60"><span class="input-group-text">%</span></div></div>
                <hr>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="questionnaire-global" checked><label class="form-check-label small" for="questionnaire-global">Disponible para todo el equipo</label></div>
                <div class="form-text">Desact&iacute;valo para que sea privado y solo puedas utilizarlo t&uacute;. Solo el autor podr&aacute; editarlo.</div>
              </div>
            </div>
            <div class="col-lg-8">
              <div class="questionnaire-questions-card p-3">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-3"><div><h6 class="mb-0">Preguntas</h6><span class="small text-muted" id="questionnaire-question-count">0 preguntas</span></div><button class="btn btn-primary btn-sm" type="button" id="btn-add-questionnaire-question"><i class="bi bi-plus-lg"></i> A&ntilde;adir pregunta</button></div>
                <div id="questionnaire-question-list"></div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer justify-content-between">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <div class="d-flex gap-2"><button type="button" class="btn btn-outline-primary btn-sm" id="btn-preview-questionnaire"><i class="bi bi-eye"></i> Vista previa</button><button type="button" class="btn btn-primary btn-sm" id="btn-save-questionnaire">Guardar cuestionario</button></div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="questionnaireQuestionModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="questionnaire-question-title">A&ntilde;adir pregunta</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <label class="form-label small fw-semibold">Tipo de respuesta</label><div class="row g-2 mb-4" id="questionnaire-question-types"></div>
          <hr>
          <input type="hidden" id="questionnaire-question-id">
          <input type="hidden" id="questionnaire-question-type" value="short_text">
          <div class="row g-3">
            <div class="col-12"><label class="form-label small" for="questionnaire-question-label">Pregunta</label><input class="form-control form-control-sm" id="questionnaire-question-label" maxlength="500"></div>
            <div class="col-12"><label class="form-label small" for="questionnaire-question-help">Texto de ayuda <span class="text-muted">(opcional)</span></label><input class="form-control form-control-sm" id="questionnaire-question-help" maxlength="500"></div>
            <div class="col-md-4"><div class="form-check mt-md-4"><input class="form-check-input" type="checkbox" id="questionnaire-question-required"><label class="form-check-label small" for="questionnaire-question-required">Respuesta obligatoria</label></div></div>
            <div class="col-md-4 questionnaire-score-setting"><label class="form-label small" for="questionnaire-question-points">Puntos</label><input class="form-control form-control-sm" type="number" id="questionnaire-question-points" min="0" max="100" step="0.01" value="1"></div>
            <div class="col-md-4 d-none" id="questionnaire-scale-setting"><label class="form-label small" for="questionnaire-question-scale">M&aacute;ximo de la escala</label><select class="form-select form-select-sm" id="questionnaire-question-scale"><option value="5">1 a 5</option><option value="10">1 a 10</option></select></div>
          </div>
          <div id="questionnaire-options-editor" class="mt-4 d-none"><div class="d-flex justify-content-between align-items-center mb-2"><div><h6 class="mb-0">Opciones</h6><span class="small text-muted" id="questionnaire-correct-help"></span></div><button class="btn btn-outline-primary btn-sm" type="button" id="btn-add-questionnaire-option"><i class="bi bi-plus"></i> Opci&oacute;n</button></div><div id="questionnaire-option-list"></div></div>
          <div id="questionnaire-boolean-correct" class="mt-4 d-none"><label class="form-label small" for="questionnaire-question-boolean-correct">Respuesta correcta</label><select class="form-select form-select-sm" id="questionnaire-question-boolean-correct"><option value="">Sin respuesta correcta</option><option value="yes">S&iacute;</option><option value="no">No</option></select></div>
          <div id="questionnaire-text-correct" class="mt-4 d-none"><label class="form-label small" for="questionnaire-question-text-correct">Respuesta correcta <span class="text-muted">(opcional)</span></label><input class="form-control form-control-sm" id="questionnaire-question-text-correct"></div>
        </div>
        <div class="modal-footer justify-content-between"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary btn-sm" id="btn-save-questionnaire-question">Guardar pregunta</button></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <footer class="dashboard-legal-footer">
    <span class="legal-brand-line">
      <img src="<?= htmlspecialchars($official_brand_logo_url) ?>" alt="" class="legal-brand-mark">
      <span><?= htmlspecialchars(app_legal_footer_text()) ?></span>
    </span>
  </footer>

  <script>
    const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
    const IS_SUPERADMIN = <?= $is_superadmin ? 'true' : 'false' ?>;
    const CURRENT_USER_ID = <?= (int) $_SESSION['user_id'] ?>;
    const MEMBER_PERMISSIONS = <?= json_encode($member_permissions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const INITIAL_CALENDAR_VIEW = <?= json_encode(in_array(($current_professional_preferences['initial_calendar_view'] ?? 'month'), ['dashboard', 'week', 'month', 'patients', 'upcoming'], true) ? $current_professional_preferences['initial_calendar_view'] : 'month') ?>;
    const EFFECTIVE_PROFESSIONAL_TIMEZONE = <?= json_encode($current_professional_preferences['timezone'] ?? tenant_timezone()) ?>;
    const DASHBOARD_CONFIG_MODE = <?= json_encode($dashboard_config_mode) ?>;
    const DASHBOARD_CONFIG = <?= json_encode($dashboard_config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const PLAN_CONFIG = <?= json_encode($plan_config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const KNOWLEDGE_BASE_ENABLED = <?= $knowledge_base_enabled ? 'true' : 'false' ?>;
    const CURRENT_SECTOR_KEY = <?= json_encode($sector_key) ?>;
    const BODY_MAP_ENABLED = <?= $body_map_enabled ? 'true' : 'false' ?>;
    const SECTOR_TEXTS = <?= json_encode($sector_texts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const SECTOR_TEXT_OPTIONS = <?= json_encode($sector_texts_options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const INITIAL_NAVBAR_IMAGE_URL = <?= json_encode($navbar_image_url) ?>;
    const INITIAL_BRAND_LOGO_URL = <?= json_encode($brand_logo_url) ?>;
    const OFFICIAL_BRAND_LOGO_URL = <?= json_encode($official_brand_logo_url) ?>;
    const APP_BASE_PATH = <?= json_encode(trim(function_exists('tenant_app_base_path') ? tenant_app_base_path() : (defined('APP_BASE_PATH') ? APP_BASE_PATH : ''), '/')) ?>;
    const INITIAL_BILLING_SETTINGS = <?= json_encode($initial_billing_settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const AUTOFIRMA_PATIENT_SIGNING_ENABLED = <?= $autofirma_patient_signing_enabled ? 'true' : 'false' ?>;
    const TIME_TRACKING_PLAN_ENABLED = <?= $time_tracking_plan_enabled ? 'true' : 'false' ?>;
    const INITIAL_TIME_TRACKING_ENABLED = <?= $time_tracking_enabled ? 'true' : 'false' ?>;
    const SUBSCRIPTION_CSRF_TOKEN = <?= json_encode($is_superadmin ? ($_SESSION['subscription_csrf_token'] ?? '') : '') ?>;
  </script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <?php if ($is_superadmin): ?>
    <script src="https://js.braintreegateway.com/web/dropin/1.47.0/js/dropin.min.js"></script>
  <?php endif; ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <?php if ($physical_metrics_available): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <?php endif; ?>
  <?php if ($body_map_enabled): ?>
    <script src="https://unpkg.com/body-muscles/dist/umd/body-muscles.umd.min.js"></script>
  <?php endif; ?>
  <?php if ($autofirma_patient_signing_enabled): ?>
    <script src="js/autofirma/autoscript.js?v=<?= filemtime(__DIR__ . '/js/autofirma/autoscript.js') ?>" charset="UTF-8"></script>
  <?php endif; ?>
  <script src="js/app.js?v=<?= filemtime(__DIR__ . '/js/app.js') ?>" charset="UTF-8"></script>
  <?php if ($questionnaires_plan_enabled): ?>
    <script>window.SGPRAxisPatientQuestionnaires = {isAdmin: <?= $is_admin ? 'true' : 'false' ?>, patientLabel: <?= json_encode($patient_label_singular, JSON_UNESCAPED_UNICODE) ?>};</script>
    <script src="js/patient-questionnaires.js?v=<?= filemtime(__DIR__ . '/js/patient-questionnaires.js') ?>" charset="UTF-8"></script>
  <?php endif; ?>
  <?php if ($questionnaires_plan_enabled && $is_admin): ?>
    <script>window.SGPRAxisQuestionnaireAssets = {script: <?= json_encode('js/questionnaires.js?v=' . filemtime(__DIR__ . '/js/questionnaires.js')) ?>};</script>
  <?php endif; ?>
</body>

</html>
