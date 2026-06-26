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
$is_superadmin = ($_SESSION['role'] === 'superadmin');
$is_admin = in_array($_SESSION['role'], ['admin', 'superadmin'], true);
if ($is_admin) {
  ensure_cabinet_schema($mysqli);
}
$branding = get_public_branding_settings($mysqli);
if (!$is_admin && !online_booking_enabled($mysqli)) {
  header('Location: index.php');
  exit;
}
$dashboard_config_mode = $is_admin ? dashboard_config_effective_mode_from_db($mysqli, $branding['plan_key'] ?? 'default') : 'simple';
$dashboard_config = dashboard_config_for_mode($dashboard_config_mode);
$plan_config = plan_config_for_key($branding['plan_key'] ?? 'default');
$sector_key = $branding['sector_texts_key'] ?? sector_texts_default_key();
$knowledge_base_enabled = $is_admin
  && app_feature_enabled($dashboard_config, $plan_config, 'knowledgeBase.enabled', false)
  && knowledge_base_sector_has_data($mysqli, $sector_key);
$body_map_sector_keys = ['fitness', 'fisioterapia', 'quiropractica', 'osteopatia'];
$physical_metrics_sector_keys = array_merge($body_map_sector_keys, ['nutricion']);
$physical_metrics_available = in_array($sector_key, $physical_metrics_sector_keys, true);
$physical_metrics_enabled = $is_admin && $physical_metrics_available;
$body_map_enabled = $knowledge_base_enabled && in_array($sector_key, $body_map_sector_keys, true);
$knowledge_disclaimer = 'Las recomendaciones mostradas son material de apoyo documental. No constituyen diagnóstico, prescripción clínica automática ni sustituyen el criterio profesional.';
if ($sector_key === 'fitness') {
  $knowledge_disclaimer = 'Las recomendaciones mostradas son material de apoyo para la planificación del entrenamiento. No sustituyen la valoración del profesional ni deben interpretarse como una rutina automática.';
} elseif (in_array($sector_key, ['fisioterapia', 'osteopatia', 'quiropractica'], true)) {
  $knowledge_disclaimer = 'Las recomendaciones mostradas son material de apoyo documental. No constituyen valoración clínica, tratamiento automático ni sustituyen el criterio profesional.';
}
$custom_dashboard_logo_enabled = app_feature_enabled($dashboard_config, $plan_config, 'branding.customLogo', false);
$sector_texts = sector_texts_for_key($sector_key, $dashboard_config, $plan_config);
$sector_texts_options = sector_texts_available();
$patient_label_singular = $sector_texts['labels']['patient']['singular'] ?? 'paciente';
$patient_label_plural = $sector_texts['labels']['patient']['plural'] ?? 'pacientes';
$patient_label_title_singular = $sector_texts['labels']['patient']['titleSingular'] ?? 'Paciente';
$patient_label_title_plural = $sector_texts['labels']['patient']['titlePlural'] ?? 'Pacientes';
$work_plan_title_singular = $sector_texts['labels']['workPlan']['titleSingular'] ?? 'Plan de trabajo';
$is_psychology_sector = $sector_key === 'psicologia';
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
$help_base_url = '/' . trim(function_exists('tenant_app_base_path') ? tenant_app_base_path() : 'sgpraxis', '/') . '/' . rawurlencode($tenant_url_key) . '/ayuda/';
$sector_help_url = ($sector_help_file !== '' && file_exists(__DIR__ . '/ayuda/' . $sector_help_file))
  ? $help_base_url . '?sector=' . rawurlencode($sector_key)
  : $help_base_url;
$official_brand_logo_url = app_official_brand_logo_url();
$profile_image_path = $custom_dashboard_logo_enabled ? $branding['profile_image_path'] : '';
$navbar_image_path = $profile_image_path;
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
  <style>:root { --primary-color: <?= htmlspecialchars($primary_color) ?>; --dashboard-navbar-text: <?= htmlspecialchars($navbar_text_color) ?>; --dashboard-navbar-control-bg: <?= htmlspecialchars($navbar_control_bg) ?>; --dashboard-navbar-control-hover-bg: <?= htmlspecialchars($navbar_control_hover_bg) ?>; }</style>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>

<body class="<?= $is_admin ? 'is-admin' : 'is-patient' ?>">

  <nav class="navbar navbar-expand-lg py-3">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center gap-2" id="app-brand-link" href="#">
        <img src="<?= htmlspecialchars($official_brand_logo_url) ?>" alt="SimplyGest Praxis" class="brand-avatar" id="app-brand-image">
        <span id="app-brand" class="dashboard-user-greeting">Hola, <?= htmlspecialchars($_SESSION['name']) ?></span>
      </a>
      <div class="d-flex align-items-center gap-2">
        <?php if ($is_admin): ?>
          <button class="btn btn-light btn-sm" type="button" id="btn-global-search" title="Buscar">
            <i class="bi bi-search"></i>
          </button>
        <?php endif; ?>
        <?php if ($navbar_image_url): ?>
          <img src="<?= htmlspecialchars($navbar_image_url) ?>" alt="" class="navbar-user-avatar" id="navbar-user-image">
        <?php else: ?>
          <img src="" alt="" class="navbar-user-avatar d-none" id="navbar-user-image">
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
            <?php if ($is_admin): ?>
              <li>
                <button class="dropdown-item" id="btn-open-settings" type="button">
                  <i class="bi bi-gear me-2"></i>Configuraci&oacute;n
                </button>
              </li>
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
      </div>
    </div>
  </nav>

  <div class="<?= $is_admin ? 'container-fluid dashboard-shell-container mt-4' : 'container mt-4' ?>">
    <?php if ($is_admin): ?>
      <div class="dashboard-shell">
        <aside class="dashboard-side-nav" aria-label="Navegaci&oacute;n del dashboard">
          <div class="dashboard-side-nav-section">
            <button class="dashboard-side-nav-item btn-dashboard-main-view" type="button" data-dashboard-main-view="agenda">
              <i class="bi bi-calendar3"></i><span>Agenda</span>
            </button>
            <button class="dashboard-side-nav-item btn-dashboard-main-view" type="button" data-dashboard-main-view="patients">
              <i class="bi bi-people"></i><span><?= htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') ?></span>
            </button>
            <button class="dashboard-side-nav-item btn-dashboard-main-view" type="button" data-dashboard-main-view="upcoming">
              <i class="bi bi-list-check"></i><span>Citas</span>
            </button>
          </div>
          <div class="dashboard-side-nav-section">
            <div class="dashboard-side-nav-label">Herramientas</div>
            <button class="dashboard-side-nav-item" id="btn-sidebar-generate-invite" type="button" data-dashboard-action="invite">
              <i class="bi bi-link-45deg"></i><span>Invitaci&oacute;n</span>
            </button>
            <button class="dashboard-side-nav-item" id="btn-sidebar-admin-stats" type="button" data-dashboard-action="stats">
              <i class="bi bi-bar-chart"></i><span>Estad&iacute;sticas</span>
            </button>
            <button class="dashboard-side-nav-item" id="btn-sidebar-admin-bonuses" type="button" data-dashboard-action="bonuses">
              <i class="bi bi-card-list"></i><span>Bonos</span>
            </button>
          </div>
        </aside>
        <main class="dashboard-shell-main">
    <?php endif; ?>

    <?php if ($is_admin): ?>
      <div class="mb-4 d-flex gap-2 flex-wrap align-items-center dashboard-actions-bar dashboard-actions-admin">
        <div class="dashboard-action-buttons d-flex gap-2 flex-wrap align-items-center">
        <button class="btn btn-primary" id="btn-generate-invite"><i class="bi bi-link-45deg"></i> Generar
          Invitación</button>
        <button class="btn btn-primary" id="btn-upcoming-appointments" type="button"><i class="bi bi-list-check"></i> Pr&oacute;ximas citas</button>
        <button class="btn btn-primary" id="btn-admin-stats" type="button"><i class="bi bi-bar-chart"></i> Estad&iacute;sticas</button>
          <button class="btn btn-primary" id="btn-admin-bonuses" type="button"><i class="bi bi-card-list"></i> Bonos</button>
        <button class="btn btn-primary" id="btn-admin-patients" type="button"><i class="bi bi-people"></i> <?= $is_superadmin ? htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') : 'Mis ' . htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?></button>
        </div>
        <div class="dropdown dashboard-mobile-menu">
          <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-list"></i> Men&uacute;
          </button>
          <ul class="dropdown-menu">
            <li><button class="dropdown-item" type="button" id="btn-mobile-generate-invite"><i class="bi bi-link-45deg me-2"></i>Generar invitaci&oacute;n</button></li>
            <li><button class="dropdown-item" type="button" id="btn-mobile-upcoming-appointments"><i class="bi bi-list-check me-2"></i>Pr&oacute;ximas citas</button></li>
            <li><button class="dropdown-item" type="button" id="btn-mobile-admin-stats"><i class="bi bi-bar-chart me-2"></i>Estad&iacute;sticas</button></li>
            <li><button class="dropdown-item" type="button" id="btn-mobile-admin-bonuses"><i class="bi bi-card-list me-2"></i>Bonos</button></li>
            <li><button class="dropdown-item" type="button" id="btn-mobile-admin-patients"><i class="bi bi-people me-2"></i><?= $is_superadmin ? htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') : 'Mis ' . htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?></button></li>
          </ul>
        </div>
        <span id="admin-actions-msg" class="align-self-center ms-2 text-success" style="display: none;"></span>
        <div class="btn-group ms-auto dashboard-view-switcher" id="admin-dashboard-view-switcher" role="group" aria-label="Vista del dashboard">
          <button class="btn btn-outline-primary btn-dashboard-view" type="button" data-dashboard-view="month"><i class="bi bi-calendar3"></i> Mes</button>
          <button class="btn btn-outline-primary btn-dashboard-view" type="button" data-dashboard-view="week"><i class="bi bi-calendar-week"></i> Semana</button>
        </div>
      </div>
    <?php else: ?>
      <div class="mb-4 d-flex gap-2 flex-wrap align-items-center dashboard-actions-bar" id="patient-bonus-actions">
        <div class="dashboard-action-buttons d-flex gap-2 flex-wrap align-items-center">
          <button class="btn btn-primary" id="btn-patient-portal-appointments" type="button"><i class="bi bi-calendar-check"></i> Mis citas</button>
          <button class="btn btn-primary" id="btn-patient-portal-tasks" type="button"><i class="bi bi-list-check"></i> Mis tareas</button>
          <button class="btn btn-primary" id="btn-patient-portal-documents" type="button"><i class="bi bi-folder2-open"></i> Mis documentos</button>
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
      <div id="patient-quick-appointment-summary" class="quick-appointments-summary d-none"></div>
    <?php endif; ?>
    <div id="patient-professional-choice" class="patient-professional-choice d-none"></div>
    <div id="patient-professional-context" class="booking-professional-context d-none"></div>
    <?php if ($is_admin): ?>
      <div id="quick-appointments-summary" class="quick-appointments-summary d-none"></div>
    <?php endif; ?>

    <div id="calendar-container">
      <div class="text-center text-muted py-5">
        <div class="spinner-border text-secondary" role="status"></div><br>Cargando calendario...
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
                <button class="nav-link" id="appointment-files-tab" data-bs-toggle="tab" data-bs-target="#appointment-files-panel" type="button" role="tab">Archivos</button>
              </li>
            </ul>
            <div class="tab-content">
              <div class="tab-pane fade show active" id="appointment-detail-panel" role="tabpanel" aria-labelledby="appointment-detail-tab">
                <div id="appointment-payment-summary" class="appointment-payment-summary mb-4">
                  <div class="text-center text-muted py-4">Cargando cita...</div>
                </div>
                <div id="appointment-payment-editor" class="appointment-payment-editor">
                  <div class="row g-3">
                    <div class="col-md-6">
                      <button type="button" class="payment-state-card" data-payment-status="pending">
                        <span class="payment-state-icon payment-state-pending"><i class="bi bi-hourglass-split"></i></span>
                        <strong>Pendiente</strong>
                        <small>La cita queda marcada como no pagada.</small>
                      </button>
                    </div>
                    <div class="col-md-6">
                      <button type="button" class="payment-state-card" data-payment-status="paid">
                        <span class="payment-state-icon payment-state-paid"><i class="bi bi-check2-circle"></i></span>
                        <strong>Pagada</strong>
                        <small>Registra un cobro manual u offline.</small>
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
              <div class="tab-pane fade" id="appointment-files-panel" role="tabpanel" aria-labelledby="appointment-files-tab">
                <div id="appointment-files-alert" class="alert d-none"></div>
                <div id="appointment-files-content">
                  <div class="text-center text-muted py-4">Cargando archivos...</div>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-danger me-auto" id="btn-cancel-appointment-from-detail">Cancelar cita</button>
            <button type="button" class="btn btn-outline-secondary" id="btn-open-patient-from-appointment-detail">
              <i class="bi bi-person-lines-fill"></i> Ver ficha del <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>
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
                  <input type="file" class="form-control" id="appointment-session-note-files" name="evolution_files[]" accept=".pdf,.xls,.xlsx,image/jpeg,image/png,image/webp,image/gif" multiple>
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

    <div class="modal fade" id="adminPatientsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><?= $is_superadmin ? htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') : 'Mis ' . htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="admin-patients-alert" class="alert d-none"></div>
            <div class="d-flex justify-content-end align-items-center gap-2 mb-3 flex-wrap">
              <button class="btn btn-primary btn-sm" type="button" id="btn-new-patient"><i class="bi bi-person-plus"></i> Nuevo <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?></button>
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
                    <th>Portal</th>
                    <th>Documento</th>
                    <th class="text-end no-export">Acciones</th>
                  </tr>
                </thead>
                <tbody id="admin-patients-body">
                  <tr><td colspan="<?= $is_superadmin ? 8 : 7 ?>" class="text-center text-muted py-4">Cargando...</td></tr>
                </tbody>
              </table>
            </div>
            <div class="text-end text-muted small mt-2" id="admin-patients-count"></div>
          </div>
          <div class="modal-footer justify-content-end flex-wrap gap-2">
            <div class="d-flex gap-2 flex-wrap">
              <button class="btn btn-outline-primary btn-sm btn-export-modal-table" type="button" data-table-target="#adminPatientsModal" data-export-type="print">
                <i class="bi bi-printer"></i> Imprimir
              </button>
              <button class="btn btn-outline-primary btn-sm btn-export-modal-table" type="button" data-table-target="#adminPatientsModal" data-export-type="csv">
                <i class="bi bi-download"></i> CSV
              </button>
              <button class="btn btn-outline-primary btn-sm btn-export-modal-table" type="button" data-table-target="#adminPatientsModal" data-export-type="xls">
                <i class="bi bi-file-earmark-spreadsheet"></i> Excel
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="patientEditorModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="patient-editor-title"><?= htmlspecialchars($patient_label_title_singular, ENT_QUOTES, 'UTF-8') ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="patient-editor-alert" class="alert d-none"></div>
            <ul class="nav nav-tabs mb-4" id="patient-editor-tabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="patient-data-tab" data-bs-toggle="tab" data-bs-target="#patient-data-panel" type="button" role="tab">Datos del <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?></button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="patient-more-data-tab" data-bs-toggle="tab" data-bs-target="#patient-more-data-panel" type="button" role="tab">M&aacute;s datos</button>
              </li>
              <?php if ($physical_metrics_enabled): ?>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" id="patient-physical-tab" data-bs-toggle="tab" data-bs-target="#patient-physical-panel" type="button" role="tab">Composici&oacute;n</button>
                </li>
              <?php endif; ?>
              <?php if ($knowledge_base_enabled): ?>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" id="patient-diagnosis-tab" data-bs-toggle="tab" data-bs-target="#patient-diagnosis-panel" type="button" role="tab"><?= htmlspecialchars(ucfirst($sector_texts['clinicalTerms']['diagnosis'] ?? 'Diagnóstico')) ?></button>
                </li>
              <?php endif; ?>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="patient-history-tab" data-bs-toggle="tab" data-bs-target="#patient-history-panel" type="button" role="tab">Historial de citas</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="patient-work-plan-tab" data-bs-toggle="tab" data-bs-target="#patient-work-plan-panel" type="button" role="tab">Plan de trabajo</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="patient-evolution-tab" data-bs-toggle="tab" data-bs-target="#patient-evolution-panel" type="button" role="tab">Evoluci&oacute;n</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="patient-files-tab" data-bs-toggle="tab" data-bs-target="#patient-files-panel" type="button" role="tab">Documentaci&oacute;n</button>
              </li>
              <li class="nav-item" role="presentation">
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
                      <label class="form-label" for="patient-editor-email">Email</label>
                      <input type="email" class="form-control" id="patient-editor-email" name="email">
                    </div>
                    <div class="col-md-6">
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
                    <?php if ($is_superadmin): ?>
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
                    <div class="col-md-6">
                      <label class="form-label" for="patient-editor-document">Archivo PDF/Excel</label>
                      <input type="file" class="form-control" id="patient-editor-document" name="patient_document" accept=".pdf,.xls,.xlsx">
                      <div class="form-text" id="patient-editor-document-status"></div>
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
                  <div class="col-md-6">
                    <label class="form-label" for="patient-editor-emergency-name">Contacto de emergencia / tutor</label>
                    <input type="text" class="form-control" id="patient-editor-emergency-name" name="emergency_contact_name" form="patient-editor-form">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="patient-editor-emergency-phone">Tel&eacute;fono de emergencia</label>
                    <input type="text" class="form-control" id="patient-editor-emergency-phone" name="emergency_contact_phone" form="patient-editor-form">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="patient-editor-emergency-relation">Relaci&oacute;n</label>
                    <input type="text" class="form-control" id="patient-editor-emergency-relation" name="emergency_contact_relation" form="patient-editor-form" placeholder="Madre, padre, pareja, familiar...">
                  </div>
                  <div class="col-12">
                    <label class="form-label" for="patient-editor-initial-reason">Motivo inicial de consulta</label>
                    <textarea class="form-control" id="patient-editor-initial-reason" name="initial_consultation_reason" rows="3" form="patient-editor-form"></textarea>
                  </div>
                  <div class="col-12">
                    <label class="form-label" for="patient-editor-notes">Notas internas</label>
                    <textarea class="form-control" id="patient-editor-notes" name="notes" rows="3" form="patient-editor-form"></textarea>
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
              <?php if ($knowledge_base_enabled): ?>
                <div class="tab-pane fade" id="patient-diagnosis-panel" role="tabpanel" aria-labelledby="patient-diagnosis-tab">
                  <div class="alert alert-info small mb-3">
                    <?= htmlspecialchars($knowledge_disclaimer, ENT_QUOTES, 'UTF-8') ?>
                  </div>
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
                      <div class="patient-objective-panel">
                      <label class="form-label" for="patient-editor-knowledge-problem"><?= htmlspecialchars($sector_texts['labels']['problem']['titleSingular'] ?? 'Problema o diagnóstico') ?></label>
                      <div class="form-text mb-2">Selecciona <?= htmlspecialchars($sector_texts['labels']['problem']['singular'] ?? 'un problema o diagnóstico') ?> para consultar <?= htmlspecialchars($sector_texts['labels']['technique']['plural'] ?? 'técnicas') ?>, <?= htmlspecialchars($sector_texts['labels']['task']['plural'] ?? 'tareas') ?>, <?= htmlspecialchars($sector_texts['labels']['evaluation']['plural'] ?? 'cuestionarios') ?> y fuentes.</div>
                      <select class="form-select" id="patient-editor-knowledge-problem" name="knowledge_problem_id" form="patient-editor-form">
                        <option value="">Sin <?= htmlspecialchars($sector_texts['clinicalTerms']['diagnosis'] ?? 'diagnóstico') ?> asociado</option>
                      </select>
                      <div id="patient-knowledge-content" class="patient-knowledge-content mt-3">
                        <div class="text-center text-muted py-4">No hay <?= htmlspecialchars($sector_texts['clinicalTerms']['diagnosis'] ?? 'diagnóstico') ?> seleccionado.</div>
                      </div>
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
              <?php endif; ?>
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
                  <button class="btn btn-primary btn-sm" type="button" id="btn-show-patient-work-plan-form">
                    <i class="bi bi-plus-lg"></i> Crear o importar tareas
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
                    <button type="button" class="btn btn-outline-primary patient-files-filter" data-files-filter="questionnaire">Cuestionarios</button>
                  </div>
                  <button type="button" class="btn btn-primary btn-sm" id="btn-show-patient-document-form">
                    <i class="bi bi-plus-lg"></i> Nuevo documento
                  </button>
                </div>
                <div class="table-responsive">
                  <table class="table align-middle">
                    <thead>
                      <tr>
                        <th>Documento</th>
                        <th>Tipo / origen</th>
                        <th>Fecha</th>
                        <th>Portal</th>
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
              <div class="tab-pane fade" id="patient-reports-panel" role="tabpanel" aria-labelledby="patient-reports-tab">
                <div id="patient-reports-alert" class="alert d-none"></div>
                <div class="alert alert-info small mb-3">
                  Los informes generados aqu&iacute; son borradores estructurados con los datos disponibles en la aplicaci&oacute;n. El profesional debe revisarlos, completarlos y firmarlos cuando corresponda antes de considerarlos versi&oacute;n final oficial.
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
            <button class="btn btn-primary" type="submit" id="btn-save-patient" form="patient-editor-form">Guardar cambios</button>
          </div>
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
                <div class="col-12">
                  <label class="form-label" for="patient-evolution-files">Archivos</label>
                  <input type="file" class="form-control" id="patient-evolution-files" name="evolution_files[]" accept=".pdf,.xls,.xlsx,image/jpeg,image/png,image/webp,image/gif" multiple>
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
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
          <div class="modal-footer justify-content-between flex-wrap gap-2">
            <div class="text-muted small">Exporta la pesta&ntilde;a visible con el filtro actual.</div>
            <div class="d-flex gap-2 flex-wrap">
              <button class="btn btn-outline-primary btn-sm btn-export-modal-table" type="button" data-table-target="#upcomingAppointmentsModal" data-export-type="print">
                <i class="bi bi-printer"></i> Imprimir
              </button>
              <button class="btn btn-outline-primary btn-sm btn-export-modal-table" type="button" data-table-target="#upcomingAppointmentsModal" data-export-type="csv">
                <i class="bi bi-download"></i> CSV
              </button>
              <button class="btn btn-outline-primary btn-sm btn-export-modal-table" type="button" data-table-target="#upcomingAppointmentsModal" data-export-type="xls">
                <i class="bi bi-file-earmark-spreadsheet"></i> Excel
              </button>
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
                <label class="form-label" for="patient-work-plan-template">Crea o importa tareas</label>
                <div class="input-group">
                  <select class="form-select" id="patient-work-plan-template">
                    <option value="manual">Crear manualmente</option>
                  </select>
                  <button type="button" class="btn btn-outline-primary" id="btn-import-work-plan-template">
                    <i class="bi bi-box-arrow-in-down"></i> Importar
                  </button>
                </div>
                <div class="form-text">Crea una tarea nueva o importa tareas desde tus plantillas o desde la base de conocimiento.</div>
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

    <div class="modal fade" id="settingsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl">
        <div class="modal-content settings-modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Configuración</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
                  type="button" role="tab">Reservas</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="task-templates-settings-tab" data-bs-toggle="tab" data-bs-target="#task-templates-settings-panel"
                  type="button" role="tab">Plantillas</button>
              </li>
              <li class="nav-item <?= $is_superadmin ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link" id="payment-settings-tab" data-bs-toggle="tab" data-bs-target="#payment-settings-panel"
                  type="button" role="tab">Pago online</button>
              </li>
              <li class="nav-item <?= $is_superadmin ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link" id="email-settings-tab" data-bs-toggle="tab" data-bs-target="#email-settings-panel"
                  type="button" role="tab">Envío de emails</button>
              </li>
              <li class="nav-item <?= $is_superadmin ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link" id="calendar-settings-tab" data-bs-toggle="tab" data-bs-target="#calendar-settings-panel"
                  type="button" role="tab">Calendario online</button>
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
            </ul>

            <div id="settings-save-alert" class="alert d-none"></div>

            <div class="tab-content">
              <div class="tab-pane fade show active" id="closed-days-panel" role="tabpanel" aria-labelledby="closed-days-tab">
                <div id="general-settings-alert" class="alert d-none"></div>
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

                <div class="row g-3 align-items-start mb-4">
                  <div class="col-lg-12">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" id="patient-tasks-visible-default">
                      <label class="form-check-label" for="patient-tasks-visible-default">Publicar las tareas del <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> en su Portal</label>
                    </div>
                    <div class="form-text">Indica si quieres que, por defecto, las tareas que asignes a tus <?= htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?> est&eacute;n visibles en su portal.</div>
                  </div>
                </div>
                <div class="row g-3 align-items-start mb-4">
                  <div class="col-lg-12">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" id="work-plan-task-status-enabled" checked>
                      <label class="form-check-label" for="work-plan-task-status-enabled">Permitir marcar tareas como completadas</label>
                    </div>
                    <div class="form-text">Si se desactiva, el plan de trabajo mostrar&aacute; las tareas, rutinas o pautas sin estados pendiente/completada ni botones de completar.</div>
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
                  </div>
                </div>

                <hr class="my-4">
                <div class="row g-3 align-items-start mb-4">
                  <label class="col-lg-2 col-form-label">Duraciones</label>
                  <div class="col-lg-10">
                    <div class="row g-2" id="available-session-durations-list"></div>
                    <div class="mt-3">
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

                <hr class="my-4">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
                  <h6 class="mb-0">Vacaciones y cierres</h6>
                  <button type="button" class="btn btn-primary btn-sm" id="btn-open-closed-modal">
                    <i class="bi bi-plus-lg"></i> Añadir
                  </button>
                </div>
                <ul class="list-group" id="closed-days-list"></ul>
              </div>

              <div class="tab-pane fade" id="services-settings-panel" role="tabpanel" aria-labelledby="services-settings-tab">
                <div id="services-settings-alert" class="alert d-none"></div>
                <div class="table-responsive services-table-wrap">
                  <table class="table align-middle services-table">
                    <thead>
                      <tr>
                        <th>Servicio</th>
                        <th>Duración</th>
                        <th>Modalidad</th>
                        <th>Precio</th>
                      </tr>
                    </thead>
                    <tbody id="services-settings-body">
                      <tr>
                        <td colspan="4" class="text-muted text-center py-4">Cargando precios...</td>
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
                  <div>Desde aqu&iacute; puedes crear plantillas de tareas predefinidas que suelas reutilizar habitualmente con tus <?= htmlspecialchars($patient_label_plural, ENT_QUOTES, 'UTF-8') ?>.</div>
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

              <div class="tab-pane fade" id="interface-settings-panel" role="tabpanel" aria-labelledby="interface-settings-tab">
                <div id="interface-settings-alert" class="alert d-none"></div>
                <div class="interface-settings-compact">
                  <div class="row g-3 align-items-center mb-3">
                    <label class="col-lg-2 col-form-label" for="app-name">Título</label>
                    <div class="col-lg-10">
                      <input type="text" class="form-control" id="app-name" placeholder="SimplyGest Praxis">
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-3">
                    <label class="col-lg-2 col-form-label" for="site-tagline">Eslogan</label>
                    <div class="col-lg-10">
                      <input type="text" class="form-control" id="site-tagline" placeholder="">
                      <div class="form-text">Se mostrará en la página principal/comercial.</div>
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-3">
                    <label class="col-lg-2 col-form-label" for="site-phone">Teléfono</label>
                    <div class="col-lg-10">
                      <input type="text" class="form-control" id="site-phone" placeholder="Ej. 600 000 000">
                      <div class="form-text">Si lo rellenas, aparecerá en la página principal junto a la opción de pedir cita.</div>
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-4">
                    <label class="col-lg-2 col-form-label" for="primary-color">Color principal</label>
                    <div class="col-lg-10">
                      <div class="d-flex gap-2 align-items-center">
                        <input type="color" class="form-control form-control-color" id="primary-color" value="#4285f4" title="Elige el color principal">
                        <input type="text" class="form-control" id="primary-color-text" value="#4285f4" maxlength="7" style="max-width: 120px;">
                      </div>
                      <div class="form-text">Se aplicará a botones, enlaces destacados y elementos principales de la interfaz.</div>
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-3">
                    <label class="col-lg-2 col-form-label" for="profile-image">Logotipo</label>
                    <div class="col-lg-10">
                      <input type="file" class="form-control" id="profile-image" accept="image/jpeg,image/png,image/webp,image/gif">
                      <div class="form-text">Formatos permitidos: JPG, PNG, WEBP o GIF. Máximo 2 MB.</div>
                      <div class="d-flex align-items-center gap-3 mt-3" id="profile-image-preview-row" style="display: none !important;">
                        <img src="" alt="" class="settings-image-preview" id="profile-image-preview">
                        <div class="small text-muted" id="profile-image-status"></div>
                      </div>
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-4">
                    <div class="col-lg-10 offset-lg-2">
                      <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="show-profile-image-public">
                        <label class="form-check-label" for="show-profile-image-public">Mostrar también esta imagen en login y registro</label>
                      </div>
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
                        <option value="month">Mensual</option>
                        <option value="week">Semanal</option>
                        <option value="patients"><?= htmlspecialchars($patient_label_title_plural, ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="upcoming">Citas</option>
                      </select>
                      <div class="form-text">Define qu&eacute; ver&aacute;n primero los profesionales al entrar al dashboard. El portal de pacientes mantiene su vista de reservas.</div>
                    </div>
                  </div>
                </div>
                <hr class="my-4">
                <div class="row g-3 align-items-start mb-4 d-none" id="dashboard-config-row">
                  <label class="col-lg-2 col-form-label" for="dashboard-config-mode">Dashboard</label>
                  <div class="col-lg-10">
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
                </div>
                <hr class="my-4">
                <div class="row g-3 align-items-start">
                  <label class="col-lg-2 col-form-label" for="landing-image">Imagen principal</label>
                  <div class="col-lg-10">
                    <input type="file" class="form-control" id="landing-image" accept="image/jpeg,image/png,image/webp,image/gif">
                    <div class="form-text">Se usa como imagen principal en la web comercial. Si no se sube, se mostrará un placeholder.</div>
                    <div class="d-flex align-items-center gap-3 mt-3" id="landing-image-preview-row" style="display: none !important;">
                      <img src="" alt="" class="settings-image-preview" id="landing-image-preview">
                      <div class="small text-muted" id="landing-image-status"></div>
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

                <div class="row g-3 align-items-center mb-3">
                  <label class="col-lg-2 col-form-label" for="smtp-from-name">Remitente</label>
                  <div class="col-lg-10">
                    <input type="text" class="form-control" id="smtp-from-name" placeholder="SimplyGest Praxis">
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
                  <div class="row g-3 align-items-center mb-3">
                    <label class="col-lg-2 col-form-label" for="google-client-id">Client ID</label>
                    <div class="col-lg-10">
                      <input type="text" class="form-control" id="google-client-id">
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-3">
                    <label class="col-lg-2 col-form-label" for="google-client-secret">Client Secret</label>
                    <div class="col-lg-10">
                      <input type="password" class="form-control" id="google-client-secret" autocomplete="new-password"
                        placeholder="Déjalo en blanco para conservar el actual">
                      <div class="form-text" id="google-client-secret-status"></div>
                    </div>
                  </div>
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
                <div class="row g-3 align-items-start mb-3">
                  <div class="col-lg-10 offset-lg-2">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" id="appointment-reminder-enabled" name="appointment_reminder_enabled">
                      <label class="form-check-label" for="appointment-reminder-enabled">Enviar email de recordatorio al <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?> 24 horas antes de la cita</label>
                    </div>
                  </div>
                </div>

              </div>

              <div class="tab-pane fade" id="calendar-settings-panel" role="tabpanel" aria-labelledby="calendar-settings-tab">
                <div id="calendar-settings-alert" class="alert d-none"></div>
                <div class="row g-3 align-items-center mb-3">
                  <label class="col-lg-2 col-form-label" for="calendar-provider">Sincronización</label>
                  <div class="col-lg-10">
                    <select class="form-select" id="calendar-provider">
                      <option value="none">No sincronizar</option>
                      <option value="google">Google Calendar</option>
                      <option value="icloud">iCloud Calendar</option>
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
                        Usa las mismas credenciales Google configuradas en la pestaña Envío de emails.
                      </div>
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
                  <label class="col-lg-2 col-form-label" for="legal-address">Domicilio</label>
                  <div class="col-lg-10">
                    <input type="text" class="form-control" id="legal-address" placeholder="Domicilio profesional">
                  </div>
                </div>
                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="legal-email">Email legal</label>
                  <div class="col-lg-4">
                    <input type="email" class="form-control" id="legal-email" placeholder="privacidad@dominio.com">
                    <div class="form-text">Se usará para privacidad, derechos RGPD y comunicaciones legales.</div>
                  </div>
                  <label class="col-lg-2 col-form-label" for="legal-license-number">Nº colegiado</label>
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="legal-license-number" placeholder="Ej. T-00000">
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
                      <div class="text-muted small">Alta y permisos b&aacute;sicos de los miembros del equipo.</div>
                    </div>
                    <button class="btn btn-primary btn-sm" type="button" id="btn-new-professional">
                      <i class="bi bi-person-plus"></i> Nuevo miembro
                    </button>
                  </div>
                  <div class="table-responsive admin-patients-table-wrap">
                    <table class="table align-middle">
                      <thead>
                        <tr>
                          <th>Profesional</th>
                          <th>Email</th>
                          <th>Permiso</th>
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
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-primary" id="btn-save-all-settings">Guardar cambios</button>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($is_superadmin): ?>
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
          <form id="task-template-item-form">
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

  <?php if ($is_superadmin): ?>
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
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="professional-editor-title-field" placeholder="<?= $professional_title_placeholder ?>">
                  </div>
                  <label class="col-lg-2 col-form-label" for="professional-editor-license">N&ordm; de colegiado</label>
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="professional-editor-license" placeholder="Ej. T-00000">
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label professional-editor-permission-wrap" for="professional-editor-role">Permiso</label>
                  <div class="col-lg-4 professional-editor-permission-wrap">
                    <select class="form-select" id="professional-editor-role">
                      <option value="admin">Admin</option>
                      <option value="superadmin">Superadmin</option>
                    </select>
                  </div>
                  <label class="col-lg-2 col-form-label" for="professional-editor-phone">Tel&eacute;fono</label>
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="professional-editor-phone" placeholder="Ej. 600 000 000">
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="professional-editor-specialty">Especialidad</label>
                  <div class="col-lg-10">
                    <textarea class="form-control" id="professional-editor-specialty" rows="2" placeholder="<?= $professional_specialty_placeholder ?>"></textarea>
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="professional-editor-bio">Informaci&oacute;n sobre m&iacute;</label>
                  <div class="col-lg-10">
                    <textarea class="form-control" id="professional-editor-bio" rows="3" placeholder="Presentaci&oacute;n breve del profesional, enfoque de trabajo, experiencia o forma de acompa&ntilde;ar al <?= htmlspecialchars($patient_label_singular, ENT_QUOTES, 'UTF-8') ?>..."></textarea>
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="professional-editor-instagram">Instagram</label>
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="professional-editor-instagram" placeholder="https://instagram.com/...">
                  </div>
                  <label class="col-lg-2 col-form-label" for="professional-editor-facebook">Facebook</label>
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="professional-editor-facebook" placeholder="https://facebook.com/...">
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="professional-editor-tiktok">TikTok</label>
                  <div class="col-lg-4">
                    <input type="text" class="form-control" id="professional-editor-tiktok" placeholder="https://tiktok.com/@...">
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="professional-editor-summary-mode">Resumen de citas</label>
                  <div class="col-lg-10">
                    <select class="form-select" id="professional-editor-summary-mode">
                      <option value="disabled">Desactivado</option>
                      <option value="tomorrow_evening">Recibir email con planning del d&iacute;a siguiente a &uacute;ltima hora de la tarde</option>
                      <option value="today_morning">Recibir email con planning del d&iacute;a a primera hora de la ma&ntilde;ana</option>
                      <option value="on_booking">Recibir un planning de las pr&oacute;ximas citas cada vez que se reciba un aviso de reserva</option>
                    </select>
                    <div class="form-text" id="professional-editor-summary-status"></div>
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3" id="professional-editor-knowledge-row">
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
                  <label class="col-lg-2 col-form-label" for="professional-editor-photo">Foto del profesional</label>
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
                      <label class="form-check-label" for="professional-editor-active">Profesional activo</label>
                    </div>
                    <div class="form-text">Si est&aacute; desactivado, no aparecer&aacute; como profesional disponible del equipo.</div>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn btn-primary" type="submit" id="btn-save-professional-editor">Guardar profesional</button>
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
            <h5 class="modal-title" id="professional-delete-title">Borrar profesional</h5>
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
  <?php endif; ?>

  <?php if (!$is_admin): ?>
    <div class="modal fade" id="patientSelfDataModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Mis datos</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form id="patient-self-data-form">
            <div class="modal-body">
              <div id="patient-self-data-alert" class="alert d-none"></div>
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
    const INITIAL_CALENDAR_VIEW = <?= json_encode(in_array(($branding['initial_calendar_view'] ?? 'month'), ['week', 'month', 'patients', 'upcoming'], true) ? $branding['initial_calendar_view'] : 'month') ?>;
    const DASHBOARD_CONFIG_MODE = <?= json_encode($dashboard_config_mode) ?>;
    const DASHBOARD_CONFIG = <?= json_encode($dashboard_config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const PLAN_CONFIG = <?= json_encode($plan_config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const KNOWLEDGE_BASE_ENABLED = <?= $knowledge_base_enabled ? 'true' : 'false' ?>;
    const CURRENT_SECTOR_KEY = <?= json_encode($sector_key) ?>;
    const BODY_MAP_ENABLED = <?= $body_map_enabled ? 'true' : 'false' ?>;
    const SECTOR_TEXTS = <?= json_encode($sector_texts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const SECTOR_TEXT_OPTIONS = <?= json_encode($sector_texts_options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const INITIAL_NAVBAR_IMAGE_URL = <?= json_encode($navbar_image_url) ?>;
  </script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <?php if ($physical_metrics_available): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <?php endif; ?>
  <?php if ($body_map_enabled): ?>
    <script src="https://unpkg.com/body-muscles/dist/umd/body-muscles.umd.min.js"></script>
  <?php endif; ?>
  <script src="js/app.js?v=<?= filemtime(__DIR__ . '/js/app.js') ?>"></script>
</body>

</html>
