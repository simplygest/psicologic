<?php
session_start();
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
$branding = get_public_branding_settings($mysqli);
if (!$is_admin && (int) ($branding['online_booking_enabled'] ?? 1) !== 1) {
  header('Location: index.php');
  exit;
}
$dashboard_config_mode = $is_admin ? dashboard_config_mode_from_db($mysqli) : 'simple';
$dashboard_config = dashboard_config_for_mode($dashboard_config_mode);
$plan_config = plan_config_for_current();
$sector_key = $branding['sector_texts_key'] ?? sector_texts_default_key();
$knowledge_base_enabled = $is_admin
  && app_feature_enabled($dashboard_config, $plan_config, 'knowledgeBase.enabled', false)
  && knowledge_base_sector_has_data($mysqli, $sector_key);
$sector_texts = sector_texts_for_key($sector_key);
$sector_texts_options = sector_texts_available();
$app_name = $branding['app_name'];
$profile_image_path = $branding['profile_image_path'];
$navbar_image_path = $profile_image_path;
$has_team_members = false;
if (!$is_admin) {
  $photo_column = $mysqli->query("SHOW COLUMNS FROM patient_profiles LIKE 'photo_path'");
  if ($photo_column && $photo_column->num_rows === 0) {
    $mysqli->query("ALTER TABLE patient_profiles ADD photo_path VARCHAR(255) DEFAULT NULL AFTER notes");
  }
  $stmt = $mysqli->prepare("
    SELECT photo_path
    FROM patient_profiles
    WHERE user_id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $_SESSION['user_id']);
  $stmt->execute();
  $patient_navbar = $stmt->get_result()->fetch_assoc();
  if (!empty($patient_navbar['photo_path'])) {
    $navbar_image_path = $patient_navbar['photo_path'];
  }
}
if ($is_admin) {
  ensure_cabinet_schema($mysqli);
  $team_count_res = $mysqli->query("SELECT COUNT(*) AS total FROM professionals WHERE is_active = 1");
  $team_count = $team_count_res ? (int) ($team_count_res->fetch_assoc()['total'] ?? 0) : 0;
  $has_team_members = $team_count > 1;
  if ($has_team_members && !$is_superadmin) {
    $stmt = $mysqli->prepare("
      SELECT public_photo_path
      FROM professionals
      WHERE user_id = ? AND is_active = 1
      LIMIT 1
    ");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $professional_navbar = $stmt->get_result()->fetch_assoc();
    if (!empty($professional_navbar['public_photo_path'])) {
      $navbar_image_path = $professional_navbar['public_photo_path'];
    }
  }
}
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
  <style>:root { --primary-color: <?= htmlspecialchars($branding['primary_color']) ?>; }</style>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>

<body class="<?= $is_admin ? 'is-admin' : 'is-patient' ?>">

  <nav class="navbar navbar-expand-lg py-3">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center gap-2" id="app-brand-link" href="#">
        <?php if ($navbar_image_path): ?>
          <img src="<?= htmlspecialchars($navbar_image_path) ?>" alt="" class="brand-avatar" id="app-brand-image">
        <?php else: ?>
          <img src="" alt="" class="brand-avatar d-none" id="app-brand-image">
        <?php endif; ?>
        <span id="app-brand" class="dashboard-user-greeting">Hola, <?= htmlspecialchars($_SESSION['name']) ?></span>
      </a>
      <div class="d-flex align-items-center gap-2">
        <?php if ($is_admin): ?>
          <button class="btn btn-light btn-sm" type="button" id="btn-global-search" title="Buscar">
            <i class="bi bi-search"></i>
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
            <?php if ($is_admin): ?>
              <li>
                <button class="dropdown-item" id="btn-open-settings" type="button">
                  <i class="bi bi-gear me-2"></i>Configuraci&oacute;n
                </button>
              </li>
              <li>
                <a class="dropdown-item" href="ayuda/">
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

  <div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">Calendario de Citas</h4>
      <div class="d-flex gap-2 flex-wrap justify-content-end">
        <button class="btn btn-light" id="btn-prev-week"><i class="bi bi-chevron-left"></i> <span id="calendar-prev-label">Semana Anterior</span></button>
        <button class="btn btn-light" id="btn-next-week"><span id="calendar-next-label">Semana Siguiente</span> <i class="bi bi-chevron-right"></i></button>
      </div>
    </div>

    <?php if ($is_admin): ?>
      <div class="mb-4 d-flex gap-2 flex-wrap align-items-center dashboard-actions-bar dashboard-actions-admin">
        <div class="dashboard-action-buttons d-flex gap-2 flex-wrap align-items-center">
        <button class="btn btn-primary" id="btn-generate-invite"><i class="bi bi-link-45deg"></i> Generar
          Invitación</button>
        <button class="btn btn-primary" id="btn-upcoming-appointments" type="button"><i class="bi bi-list-check"></i> Pr&oacute;ximas citas</button>
        <button class="btn btn-primary" id="btn-admin-stats" type="button"><i class="bi bi-bar-chart"></i> Informes y estad&iacute;sticas</button>
          <button class="btn btn-primary" id="btn-admin-bonuses" type="button"><i class="bi bi-card-list"></i> Bonos</button>
        <button class="btn btn-primary" id="btn-admin-patients" type="button"><i class="bi bi-people"></i> <?= $is_superadmin ? 'Pacientes' : 'Mis pacientes' ?></button>
        </div>
        <div class="dropdown dashboard-mobile-menu">
          <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-list"></i> Men&uacute;
          </button>
          <ul class="dropdown-menu">
            <li><button class="dropdown-item" type="button" id="btn-mobile-generate-invite"><i class="bi bi-link-45deg me-2"></i>Generar invitaci&oacute;n</button></li>
            <li><button class="dropdown-item" type="button" id="btn-mobile-upcoming-appointments"><i class="bi bi-list-check me-2"></i>Pr&oacute;ximas citas</button></li>
            <li><button class="dropdown-item" type="button" id="btn-mobile-admin-stats"><i class="bi bi-bar-chart me-2"></i>Informes y estad&iacute;sticas</button></li>
            <li><button class="dropdown-item" type="button" id="btn-mobile-admin-bonuses"><i class="bi bi-card-list me-2"></i>Bonos</button></li>
            <li><button class="dropdown-item" type="button" id="btn-mobile-admin-patients"><i class="bi bi-people me-2"></i><?= $is_superadmin ? 'Pacientes' : 'Mis pacientes' ?></button></li>
          </ul>
        </div>
        <span id="admin-actions-msg" class="align-self-center ms-2 text-success" style="display: none;"></span>
        <button class="btn btn-primary ms-auto" id="btn-calendar-view-toggle" type="button"><i class="bi bi-calendar3"></i> Ver mes</button>
      </div>
    <?php else: ?>
      <div class="mb-4 d-flex gap-2 flex-wrap align-items-center dashboard-actions-bar" id="patient-bonus-actions">
        <div class="dashboard-action-buttons d-flex gap-2 flex-wrap align-items-center">
          <button class="btn btn-primary" id="btn-patient-portal-appointments" type="button"><i class="bi bi-calendar-check"></i> Mis citas</button>
          <button class="btn btn-primary" id="btn-patient-portal-tasks" type="button"><i class="bi bi-list-check"></i> Mis tareas</button>
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
  </div>

  <div class="modal fade" id="appointmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
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
                <option value="">Selecciona un paciente...</option>
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
            <h5 class="modal-title">Detalle de la cita</h5>
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
                <button class="nav-link" id="appointment-session-tab" data-bs-toggle="tab" data-bs-target="#appointment-session-panel" type="button" role="tab">Sesi&oacute;n</button>
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
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-danger me-auto" id="btn-cancel-appointment-from-detail">Cancelar cita</button>
            <button type="button" class="btn btn-outline-secondary" id="btn-open-patient-from-appointment-detail">
              <i class="bi bi-person-lines-fill"></i> Ver ficha del paciente
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
              <input type="search" class="form-control" id="global-search-input" placeholder="Buscar pacientes, citas, archivos, tareas..." autocomplete="off">
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
            <h5 class="modal-title"><?= $is_superadmin ? 'Pacientes' : 'Mis pacientes' ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="admin-patients-alert" class="alert d-none"></div>
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
              <div class="text-muted small">Pacientes registrados y pacientes sin acceso web.</div>
              <button class="btn btn-primary btn-sm" type="button" id="btn-new-patient"><i class="bi bi-person-plus"></i> Nuevo paciente</button>
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
                    <th>Paciente</th>
                    <?php if ($is_superadmin): ?>
                      <th>Profesional</th>
                    <?php endif; ?>
                    <th>Contacto</th>
                    <th>Tipo</th>
                    <th>Alta</th>
                    <th>Acceso</th>
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
          <div class="modal-footer justify-content-between flex-wrap gap-2">
            <div class="text-muted small">Exporta el listado con el filtro y orden actuales.</div>
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
            <h5 class="modal-title" id="patient-editor-title">Paciente</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="patient-editor-alert" class="alert d-none"></div>
            <ul class="nav nav-tabs mb-4" id="patient-editor-tabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="patient-data-tab" data-bs-toggle="tab" data-bs-target="#patient-data-panel" type="button" role="tab">Datos del paciente</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="patient-more-data-tab" data-bs-toggle="tab" data-bs-target="#patient-more-data-panel" type="button" role="tab">M&aacute;s datos</button>
              </li>
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
                <button class="nav-link" id="patient-files-tab" data-bs-toggle="tab" data-bs-target="#patient-files-panel" type="button" role="tab">Archivos</button>
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
                      <input type="text" class="form-control" id="patient-editor-type" name="patient_type" placeholder="Adulto, pareja, derivado...">
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
                            <option value="">Permitir elegir profesional al paciente</option>
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
                              <i class="bi bi-arrow-left-right"></i> Traspasar paciente
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
                    <input type="text" class="form-control" id="patient-editor-referral-source" name="referral_source" form="patient-editor-form" placeholder="Web, Doctoralia, recomendaci&oacute;n, m&eacute;dico...">
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
              <?php if ($knowledge_base_enabled): ?>
                <div class="tab-pane fade" id="patient-diagnosis-panel" role="tabpanel" aria-labelledby="patient-diagnosis-tab">
                  <div class="alert alert-info mb-3">
                    Las recomendaciones mostradas son material de apoyo documental. No constituyen diagn&oacute;stico, prescripci&oacute;n cl&iacute;nica autom&aacute;tica ni sustituyen el criterio profesional del terapeuta.
                  </div>
                  <div id="patient-knowledge-alert" class="alert d-none"></div>
                  <div class="row g-3">
                    <div class="col-12">
                      <label class="form-label" for="patient-editor-knowledge-problem"><?= htmlspecialchars($sector_texts['labels']['problem']['titleSingular'] ?? 'Problema o diagnóstico') ?></label>
                      <div class="form-text mb-2">Selecciona <?= htmlspecialchars($sector_texts['labels']['problem']['singular'] ?? 'un problema o diagnóstico') ?> para consultar <?= htmlspecialchars($sector_texts['labels']['technique']['plural'] ?? 'técnicas') ?>, <?= htmlspecialchars($sector_texts['labels']['task']['plural'] ?? 'tareas') ?>, <?= htmlspecialchars($sector_texts['labels']['evaluation']['plural'] ?? 'cuestionarios') ?> y fuentes.</div>
                      <select class="form-select" id="patient-editor-knowledge-problem" name="knowledge_problem_id" form="patient-editor-form">
                        <option value="">Sin <?= htmlspecialchars($sector_texts['clinicalTerms']['diagnosis'] ?? 'diagnóstico') ?> asociado</option>
                      </select>
                    </div>
                    <div class="col-12">
                      <div id="patient-knowledge-content" class="patient-knowledge-content">
                        <div class="text-center text-muted py-4">No hay <?= htmlspecialchars($sector_texts['clinicalTerms']['diagnosis'] ?? 'diagnóstico') ?> seleccionado.</div>
                      </div>
                    </div>
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
                      <tr><td colspan="7" class="text-center text-muted py-4">Selecciona un paciente guardado para ver su historial.</td></tr>
                    </tbody>
                  </table>
                </div>
                <div class="text-end text-muted small mt-2" id="patient-history-count"></div>
              </div>
              <div class="tab-pane fade" id="patient-work-plan-panel" role="tabpanel" aria-labelledby="patient-work-plan-tab">
                <div id="patient-work-plan-alert" class="alert d-none"></div>
                <div class="alert alert-info d-flex align-items-start gap-2">
                  <i class="bi bi-list-check fs-5"></i>
                  <div>
                    <strong>Plan de trabajo del paciente</strong>
                    <div>Desde aqu&iacute; puedes personalizar tareas, actividades, temas a tratar o pautas para este paciente, y marcarlas como completadas o pendientes en las sucesivas citas.</div>
                  </div>
                </div>
                <div class="d-flex justify-content-end gap-2 mb-3 flex-wrap">
                  <button class="btn btn-primary btn-sm" type="button" id="btn-show-patient-work-plan-form">
                    <i class="bi bi-plus-lg"></i> Crear o importar tareas
                  </button>
                </div>
                <div class="row g-3">
                  <div class="col-lg-6">
                    <div class="patient-work-plan-column">
                      <h6>Pendientes</h6>
                      <div id="patient-work-plan-pending" class="patient-work-plan-list">
                        <div class="text-center text-muted py-4">Selecciona un paciente guardado para ver su plan.</div>
                      </div>
                    </div>
                  </div>
                  <div class="col-lg-6">
                    <div class="patient-work-plan-column">
                      <h6>Completadas</h6>
                      <div id="patient-work-plan-completed-list" class="patient-work-plan-list">
                        <div class="text-center text-muted py-4">Selecciona un paciente guardado para ver su plan.</div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="text-end text-muted small mt-2" id="patient-work-plan-count"></div>
              </div>
              <div class="tab-pane fade" id="patient-evolution-panel" role="tabpanel" aria-labelledby="patient-evolution-tab">
                <div id="patient-evolution-alert" class="alert d-none"></div>
                <div class="d-flex justify-content-end mb-3">
                  <button class="btn btn-primary btn-sm" type="button" id="btn-show-patient-evolution-form">
                    <i class="bi bi-plus-lg"></i> Nuevo registro
                  </button>
                </div>
                <form id="patient-evolution-form" class="border rounded p-3 mb-3 d-none" enctype="multipart/form-data">
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
                        <option value="">Nota general del paciente</option>
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
                    <div class="col-12">
                      <label class="form-label" for="patient-evolution-files">Archivos</label>
                      <input type="file" class="form-control" id="patient-evolution-files" name="evolution_files[]" accept=".pdf,.xls,.xlsx,image/jpeg,image/png,image/webp,image/gif" multiple>
                      <div class="form-text">Puedes adjuntar PDF, Excel o im&aacute;genes. M&aacute;ximo 12 MB por archivo.</div>
                    </div>
                  </div>
                  <div class="text-end mt-3">
                    <button type="button" class="btn btn-outline-secondary" id="btn-cancel-patient-evolution-form">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btn-save-patient-evolution">Guardar evoluci&oacute;n</button>
                  </div>
                </form>
                <div id="patient-evolution-list" class="patient-evolution-list">
                  <div class="text-center text-muted py-4">Selecciona un paciente guardado para ver su evoluci&oacute;n.</div>
                </div>
                <div class="text-end text-muted small mt-2" id="patient-evolution-count"></div>
              </div>
              <div class="tab-pane fade" id="patient-files-panel" role="tabpanel" aria-labelledby="patient-files-tab">
                <div id="patient-files-alert" class="alert d-none"></div>
                <div class="table-responsive">
                  <table class="table align-middle">
                    <thead>
                      <tr>
                        <th>Archivo</th>
                        <th>Origen</th>
                        <th>Fecha</th>
                        <th class="text-end">Acciones</th>
                      </tr>
                    </thead>
                    <tbody id="patient-files-body">
                      <tr><td colspan="4" class="text-center text-muted py-4">Selecciona un paciente guardado para ver sus archivos.</td></tr>
                    </tbody>
                  </table>
                </div>
                <div class="text-end text-muted small mt-2" id="patient-files-count"></div>
              </div>
              <div class="tab-pane fade" id="patient-bonuses-panel" role="tabpanel" aria-labelledby="patient-bonuses-tab">
                <div id="patient-bonuses-alert" class="alert d-none"></div>
                <?php if ($is_superadmin): ?>
                  <div class="d-flex justify-content-end mb-3">
                    <button class="btn btn-primary btn-sm" type="button" id="btn-show-create-patient-bonus">
                      <i class="bi bi-plus-lg"></i> Crear bono para este paciente
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
                      <tr><td colspan="<?= $is_superadmin ? 7 : 6 ?>" class="text-center text-muted py-4">Selecciona un paciente guardado para ver sus bonos.</td></tr>
                    </tbody>
                  </table>
                </div>
                <div class="text-end text-muted small mt-2" id="patient-bonuses-count"></div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <div class="me-auto d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-outline-primary btn-patient-report" id="btn-patient-report-internal" data-report-type="internal" disabled>
                <i class="bi bi-file-earmark-medical"></i> Informe interno
              </button>
              <button type="button" class="btn btn-outline-primary btn-patient-report" id="btn-patient-report-public" data-report-type="patient" disabled>
                <i class="bi bi-file-earmark-person"></i> Informe paciente
              </button>
            </div>
            <button class="btn btn-primary" type="submit" id="btn-save-patient" form="patient-editor-form">Guardar cambios</button>
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
                    <input type="search" class="form-control" id="upcoming-appointments-search" placeholder="Buscar por paciente, email, profesional, servicio o pago">
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
                        <th>Paciente</th>
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
                    <input type="search" class="form-control" id="cancelled-appointments-search" placeholder="Buscar por paciente, email, profesional, servicio o pago">
                  </div>
                </div>
                <div class="table-responsive upcoming-appointments-table-wrap">
                  <table class="table align-middle">
                    <thead>
                      <tr>
                        <th>Fecha cita</th>
                        <th>Cancelada</th>
                        <th>Profesional</th>
                        <th>Paciente</th>
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
            <h5 class="modal-title">Informes y estad&iacute;sticas</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="admin-stats-alert" class="alert d-none"></div>
            <ul class="nav nav-tabs mb-3" id="admin-reports-tabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="admin-stats-tab" data-bs-toggle="tab" data-bs-target="#admin-stats-panel" type="button" role="tab">Estad&iacute;sticas</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="admin-reports-tab" data-bs-toggle="tab" data-bs-target="#admin-reports-panel" type="button" role="tab">Informes</button>
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
                <input type="search" class="form-control" id="bonus-list-search" placeholder="<?= $is_superadmin ? 'Buscar por paciente, email, bono, estado o profesional' : 'Buscar por paciente, email, bono o estado' ?>">
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
                  <option value="patient_asc">Paciente A-Z</option>
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
                <div class="alert alert-info py-2 px-3 mt-3 mb-0 d-none" id="patient-work-plan-loading">
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
                <div class="col-12">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="patient-work-plan-completed">
                    <label class="form-check-label" for="patient-work-plan-completed">Marcar como completada</label>
                  </div>
                </div>
                <div class="col-12">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="patient-work-plan-visible">
                    <label class="form-check-label" for="patient-work-plan-visible">El paciente puede ver esta tarea desde el Portal</label>
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
                  <div class="col-lg-10 offset-lg-2">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" id="online-booking-enabled" checked>
                      <label class="form-check-label" for="online-booking-enabled">Permitir reservas online (dashboard público)</label>
                    </div>
                    <div class="form-text">Si se desactiva, los pacientes no tendrán acceso a la reserva de citas, y será de uso interno por los profesionales.</div>
                  </div>
                </div>
                <div class="row g-3 align-items-center mb-4 <?= $is_superadmin ? '' : 'd-none' ?>">
                  <label class="col-lg-2 col-form-label" for="patient-registration-mode">Registro de nuevos pacientes</label>
                  <div class="col-lg-10">
                    <select class="form-select" id="patient-registration-mode">
                      <option value="invite">Los pacientes necesitan una invitación para registrarse y poder realizar reservas</option>
                      <option value="open">Los pacientes pueden darse de alta directamente en la web sin invitación previa</option>
                    </select>
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-4">
                  <div class="col-lg-10 offset-lg-2">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" id="patient-tasks-visible-default">
                      <label class="form-check-label" for="patient-tasks-visible-default">Tareas visibles para los pacientes</label>
                    </div>
                    <div class="form-text">Indica si quieres que, por defecto, las tareas que asignes a tus pacientes est&eacute;n visibles en el portal de pacientes.</div>
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
                    <div class="row g-2">
                      <div class="col-6 col-md-3">
                        <div class="form-check">
                          <input class="form-check-input available-session-type" type="checkbox" id="available-session-individual" value="individual" checked disabled>
                          <label class="form-check-label" for="available-session-individual">Individual</label>
                        </div>
                      </div>
                      <div class="col-6 col-md-3">
                        <div class="form-check">
                          <input class="form-check-input available-session-type" type="checkbox" id="available-session-couple" value="couple">
                          <label class="form-check-label" for="available-session-couple">Parejas</label>
                        </div>
                      </div>
                      <div class="col-6 col-md-3">
                        <div class="form-check">
                          <input class="form-check-input available-session-type" type="checkbox" id="available-session-family" value="family">
                          <label class="form-check-label" for="available-session-family">Familiar</label>
                        </div>
                      </div>
                      <div class="col-6 col-md-3">
                        <div class="form-check">
                          <input class="form-check-input available-session-type" type="checkbox" id="available-session-group" value="group">
                          <label class="form-check-label" for="available-session-group">Grupos</label>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <hr class="my-4">
                <div class="row g-3 align-items-start mb-4">
                  <label class="col-lg-2 col-form-label">Duraciones</label>
                  <div class="col-lg-10">
                    <div class="row g-2">
                      <div class="col-4 col-md-3">
                        <div class="form-check">
                          <input class="form-check-input available-session-duration" type="checkbox" id="available-duration-60" value="60" checked>
                          <label class="form-check-label" for="available-duration-60">60 minutos</label>
                        </div>
                      </div>
                      <div class="col-4 col-md-3">
                        <div class="form-check">
                          <input class="form-check-input available-session-duration" type="checkbox" id="available-duration-90" value="90">
                          <label class="form-check-label" for="available-duration-90">90 minutos</label>
                        </div>
                      </div>
                      <div class="col-4 col-md-3">
                        <div class="form-check">
                          <input class="form-check-input available-session-duration" type="checkbox" id="available-duration-120" value="120">
                          <label class="form-check-label" for="available-duration-120">120 minutos</label>
                        </div>
                      </div>
                    </div>
                    <div class="mt-3">
                      <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="display-effective-duration-enabled">
                        <label class="form-check-label fw-semibold" for="display-effective-duration-enabled">Mostrar duraci&oacute;n efectiva al paciente</label>
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
                  <div class="form-text">Si una cita pagada con tarjeta o Bizum se cancela, se generar&aacute; un vale interno de 1 sesi&oacute;n para el paciente.</div>
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
                <div class="alert alert-info d-flex align-items-start gap-2">
                  <i class="bi bi-collection fs-5"></i>
                  <div>Desde aqu&iacute; puedes crear plantillas de tareas predefinidas que suelas reutilizar habitualmente con tus pacientes.</div>
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
                      <input type="text" class="form-control" id="app-name" placeholder="PsicoLogic">
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-3">
                    <label class="col-lg-2 col-form-label" for="site-tagline">Eslogan</label>
                    <div class="col-lg-10">
                      <input type="text" class="form-control" id="site-tagline" placeholder="Psicología sanitaria y neuropsicología en Santa Cruz de Tenerife">
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
                        <input type="color" class="form-control form-control-color" id="primary-color" value="#8f7fba" title="Elige el color principal">
                        <input type="text" class="form-control" id="primary-color-text" value="#8f7fba" maxlength="7" style="max-width: 120px;">
                      </div>
                      <div class="form-text">Se aplicará a botones, enlaces destacados y elementos principales de la interfaz.</div>
                    </div>
                  </div>
                  <div class="row g-3 align-items-start mb-3">
                    <label class="col-lg-2 col-form-label" for="profile-image">Imagen dashboard</label>
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
                      </select>
                      <div class="form-text">Se podrá alternar entre semanal y mensual, pero por defecto aparecerá el de la opción configurada.</div>
                    </div>
                  </div>
                </div>
                <hr class="my-4">
                <div class="row g-3 align-items-start mb-4">
                  <label class="col-lg-2 col-form-label" for="dashboard-config-mode">Dashboard</label>
                  <div class="col-lg-10">
                    <div class="d-flex gap-2 flex-wrap align-items-start">
                      <select class="form-select" id="dashboard-config-mode" style="max-width: 260px;">
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
                    <input type="text" class="form-control" id="smtp-from-name" placeholder="PsicoLogic">
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
                      <label class="form-check-label" for="appointment-reminder-enabled">Enviar email de recordatorio al paciente 24 horas antes de la cita</label>
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
                      <label class="form-check-label" for="send-patient-calendar-link">Enviar link para crear la cita en el calendario a los pacientes cuando hagan una reserva</label>
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
                    <input type="text" class="form-control" id="legal-professional-college" placeholder="Colegio Oficial de Psicología...">
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
                    <label class="form-check-label" for="allow-patient-transfer">Permitir traspaso de pacientes</label>
                    <div class="form-text">Solo el usuario Administrador puede realizar los traspasos.</div>
                  </div>
                  <div class="row g-3 align-items-start mb-4">
                    <label class="col-lg-3 col-form-label" for="new-patient-booking-mode">M&eacute;todo de reserva para nuevos pacientes</label>
                    <div class="col-lg-9">
                      <select class="form-select" id="new-patient-booking-mode">
                        <option value="day_first">Elegir primero d&iacute;a/hora deseado y despu&eacute;s al profesional</option>
                        <option value="professional_first">Elegir primero al profesional y despu&eacute;s la fecha/hora</option>
                        <option value="fixed_professional">Derivar siempre los nuevos pacientes a un profesional concreto</option>
                      </select>
                      <div class="form-text">Solo se aplicar&aacute; a pacientes nuevos que todav&iacute;a no tengan profesional asignado.</div>
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
                      <div class="text-muted small">Alta y permisos b&aacute;sicos de los miembros del gabinete.</div>
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
            <h5 class="modal-title">Personalizar dashboard</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="dashboard-custom-config-alert" class="alert d-none"></div>
            <label class="form-label" for="dashboard-custom-config-json">JSON personalizado</label>
            <div class="form-text mb-2">Aqui podras elegir que caracteristicas quieres usar o no. Hazlo con precaucion para evitar problemas con la app.</div>
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
                <input type="text" class="form-control" id="task-template-category" maxlength="120" placeholder="Ej. Ansiedad, adolescentes, seguimiento">
              </div>
              <div class="mb-3">
                <label class="form-label" for="task-template-description">Descripci&oacute;n interna</label>
                <textarea class="form-control" id="task-template-description" rows="4"></textarea>
              </div>
              <?php if ($is_superadmin): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="task-template-global" checked>
                  <label class="form-check-label" for="task-template-global">Disponible para todo el gabinete</label>
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
                      <label class="form-check-label" for="closed-is-global">Cierre global del gabinete</label>
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
                    <input type="text" class="form-control" id="professional-editor-title-field" placeholder="Psic&oacute;loga sanitaria, Psic&oacute;logo cl&iacute;nico...">
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
                    <textarea class="form-control" id="professional-editor-specialty" rows="2" placeholder="Ansiedad, terapia infantil, adultos, pareja..."></textarea>
                  </div>
                </div>

                <div class="row g-3 align-items-start mb-3">
                  <label class="col-lg-2 col-form-label" for="professional-editor-bio">Informaci&oacute;n sobre m&iacute;</label>
                  <div class="col-lg-10">
                    <textarea class="form-control" id="professional-editor-bio" rows="3" placeholder="Presentaci&oacute;n breve del profesional, enfoque de trabajo, experiencia o forma de acompa&ntilde;ar al paciente..."></textarea>
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
                    <div class="form-text">Si est&aacute; desactivado, no aparecer&aacute; como profesional disponible del gabinete.</div>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button class="btn btn-primary" type="submit" id="btn-save-professional-editor">Guardar profesional</button>
            </div>
          </form>
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
            <div class="alert alert-warning">
              <strong>Importante:</strong> al borrar un profesional, se eliminará también su cuenta de acceso al sistema.
            </div>
            <p class="text-muted small mb-3">Se recomienda comprobar primero si el profesional tiene citas o pacientes pendientes.</p>
            <div id="transfer-delete-target-wrap" class="d-none">
              <label class="form-label" for="transfer-delete-target">Traspasar citas y pacientes a</label>
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

  <script>
    const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
    const IS_SUPERADMIN = <?= $is_superadmin ? 'true' : 'false' ?>;
    const CURRENT_USER_ID = <?= (int) $_SESSION['user_id'] ?>;
    const INITIAL_CALENDAR_VIEW = <?= json_encode(($branding['initial_calendar_view'] ?? 'month') === 'week' ? 'week' : 'month') ?>;
    const DASHBOARD_CONFIG_MODE = <?= json_encode($dashboard_config_mode) ?>;
    const DASHBOARD_CONFIG = <?= json_encode($dashboard_config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const PLAN_CONFIG = <?= json_encode($plan_config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const KNOWLEDGE_BASE_ENABLED = <?= $knowledge_base_enabled ? 'true' : 'false' ?>;
    const SECTOR_TEXTS = <?= json_encode($sector_texts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const SECTOR_TEXT_OPTIONS = <?= json_encode($sector_texts_options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script src="js/app.js?v=<?= filemtime(__DIR__ . '/js/app.js') ?>"></script>
</body>

</html>
