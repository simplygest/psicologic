<?php
session_start();
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}
require_once 'db.php';
require_once 'settings_helpers.php';
require_once 'cabinet_helpers.php';
$is_superadmin = ($_SESSION['role'] === 'superadmin');
$is_admin = in_array($_SESSION['role'], ['admin', 'superadmin'], true);
$branding = get_public_branding_settings($mysqli);
if (!$is_admin && (int) ($branding['online_booking_enabled'] ?? 1) !== 1) {
  header('Location: index.php');
  exit;
}
$app_name = $branding['app_name'];
$profile_image_path = $branding['profile_image_path'];
$navbar_brand_text = 'Panel de Control';
$navbar_image_path = $profile_image_path;
$has_team_members = false;
if ($is_admin) {
  ensure_cabinet_schema($mysqli);
  $team_count_res = $mysqli->query("SELECT COUNT(*) AS total FROM professionals WHERE is_active = 1");
  $team_count = $team_count_res ? (int) ($team_count_res->fetch_assoc()['total'] ?? 0) : 0;
  $has_team_members = $team_count > 1;
  if ($has_team_members && !$is_superadmin) {
    $navbar_brand_text = 'Gesti&oacute;n de Reservas';
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
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
  <style>:root { --primary-color: <?= htmlspecialchars($branding['primary_color']) ?>; }</style>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>

<body>

  <nav class="navbar navbar-expand-lg py-3">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center gap-2" id="app-brand-link" href="#">
        <?php if ($navbar_image_path): ?>
          <img src="<?= htmlspecialchars($navbar_image_path) ?>" alt="" class="brand-avatar" id="app-brand-image">
        <?php else: ?>
          <img src="" alt="" class="brand-avatar d-none" id="app-brand-image">
        <?php endif; ?>
        <span id="app-brand"><?= $navbar_brand_text ?></span>
      </a>
      <div class="d-flex align-items-center gap-2">
        <span class="dashboard-user-greeting" style="color: var(--text-color);">Hola,
          <?= htmlspecialchars($_SESSION['name']) ?></span>
        <div class="dropdown">
          <button class="btn btn-light btn-sm dropdown-toggle" type="button" id="dashboard-options-menu" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-three-dots-vertical"></i> Opciones
          </button>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="dashboard-options-menu">
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
      <div class="mb-4 d-flex gap-2 flex-wrap align-items-center">
        <button class="btn btn-primary" id="btn-generate-invite"><i class="bi bi-link-45deg"></i> Generar
          Invitación</button>
        <button class="btn btn-primary" id="btn-upcoming-appointments" type="button"><i class="bi bi-list-check"></i> Pr&oacute;ximas citas</button>
        <button class="btn btn-primary" id="btn-admin-stats" type="button"><i class="bi bi-bar-chart"></i> Estad&iacute;sticas</button>
        <button class="btn btn-primary" id="btn-admin-bonuses" type="button"><i class="bi bi-card-list"></i> Consultar bonos</button>
        <button class="btn btn-primary" id="btn-admin-patients" type="button"><i class="bi bi-people"></i> <?= $is_superadmin ? 'Pacientes' : 'Mis pacientes' ?></button>
        <span id="admin-actions-msg" class="align-self-center ms-2 text-success" style="display: none;"></span>
        <button class="btn btn-primary ms-auto" id="btn-calendar-view-toggle" type="button"><i class="bi bi-calendar3"></i> Ver mes</button>
      </div>
    <?php else: ?>
      <div class="mb-4 d-flex gap-2 flex-wrap align-items-center" id="patient-bonus-actions" style="display: none !important;">
        <button class="btn btn-primary" id="btn-buy-bonus" type="button"><i class="bi bi-bag-check"></i> Comprar bono</button>
        <button class="btn btn-primary" id="btn-my-bonuses" type="button"><i class="bi bi-card-list"></i> Consultar bonos</button>
        <button class="btn btn-primary ms-auto" id="btn-calendar-view-toggle" type="button"><i class="bi bi-calendar3"></i> Ver mes</button>
      </div>
    <?php endif; ?>

    <div id="calendar-container">
      <div class="text-center text-muted py-5">
        <div class="spinner-border text-secondary" role="status"></div><br>Cargando calendario...
      </div>
    </div>
  </div>

  <div class="modal fade" id="appointmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content" style="border-radius: 12px;">
        <div class="modal-header border-0">
          <h5 class="modal-title" id="modalTitle">Gestión de Cita</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body py-4 text-center">
          <p id="modalDesc" class="mb-4">¿Qué deseas hacer?</p>
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
              <label class="form-label" for="booking-professional">Profesional</label>
              <select id="booking-professional" class="form-select">
                <option value="">Selecciona un profesional...</option>
              </select>
              <div class="form-text" id="booking-patient-professional-note"></div>
            </div>
          <?php endif; ?>

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
    <div class="modal fade" id="inviteModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md modal-dialog-centered">
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
      <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
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
                    <th class="text-end">Acciones</th>
                  </tr>
                </thead>
                <tbody id="admin-patients-body">
                  <tr><td colspan="<?= $is_superadmin ? 8 : 7 ?>" class="text-center text-muted py-4">Cargando...</td></tr>
                </tbody>
              </table>
            </div>
            <div class="text-end text-muted small mt-2" id="admin-patients-count"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="patientEditorModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-centered">
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
                <button class="nav-link" id="patient-history-tab" data-bs-toggle="tab" data-bs-target="#patient-history-panel" type="button" role="tab">Historial de citas</button>
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
                      <label class="form-label" for="patient-editor-admission-date">Fecha de alta</label>
                      <input type="date" class="form-control" id="patient-editor-admission-date" name="admission_date">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label" for="patient-editor-document">Archivo PDF/Excel</label>
                      <input type="file" class="form-control" id="patient-editor-document" name="patient_document" accept=".pdf,.xls,.xlsx">
                      <div class="form-text" id="patient-editor-document-status"></div>
                    </div>
                    <div class="col-12">
                      <label class="form-label" for="patient-editor-notes">Notas internas</label>
                      <textarea class="form-control" id="patient-editor-notes" name="notes" rows="6"></textarea>
                    </div>
                  </div>
                  <div class="text-end mt-4">
                    <button class="btn btn-primary" type="submit" id="btn-save-patient">Guardar paciente</button>
                  </div>
                </form>
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
                        <th>Estado</th>
                      </tr>
                    </thead>
                    <tbody id="patient-history-body">
                      <tr><td colspan="6" class="text-center text-muted py-4">Selecciona un paciente guardado para ver su historial.</td></tr>
                    </tbody>
                  </table>
                </div>
                <div class="text-end text-muted small mt-2" id="patient-history-count"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="upcomingAppointmentsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Pr&oacute;ximas citas</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="upcoming-appointments-alert" class="alert d-none"></div>
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
                  </tr>
                </thead>
                <tbody id="upcoming-appointments-body">
                  <tr>
                    <td colspan="6" class="text-center text-muted py-4">Cargando...</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="text-end text-muted small mt-2" id="upcoming-appointments-count"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="adminStatsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Estad&iacute;sticas</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="admin-stats-alert" class="alert d-none"></div>
            <div id="admin-stats-content">
              <div class="text-center text-muted py-4">Cargando...</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="modal fade" id="bonusesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
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
                <button class="nav-link" id="sms-settings-tab" data-bs-toggle="tab" data-bs-target="#sms-settings-panel"
                  type="button" role="tab">SMS</button>
              </li>
              <li class="nav-item <?= $is_superadmin ? '' : 'd-none' ?>" role="presentation">
                <button class="nav-link" id="interface-settings-tab" data-bs-toggle="tab" data-bs-target="#interface-settings-panel"
                  type="button" role="tab">Interfaz</button>
              </li>
              <?php if ($is_superadmin): ?>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" id="cabinet-settings-tab" data-bs-toggle="tab" data-bs-target="#cabinet-settings-panel"
                    type="button" role="tab">Equipo</button>
                </li>
              <?php endif; ?>
            </ul>

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
                      <div class="col-sm-6">
                        <div class="form-check">
                          <input class="form-check-input available-session-type" type="checkbox" id="available-session-individual" value="individual" checked disabled>
                          <label class="form-check-label" for="available-session-individual">Sesión individual</label>
                        </div>
                      </div>
                      <div class="col-sm-6">
                        <div class="form-check">
                          <input class="form-check-input available-session-type" type="checkbox" id="available-session-couple" value="couple">
                          <label class="form-check-label" for="available-session-couple">Sesión de pareja</label>
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
                      <div class="col-sm-4">
                        <div class="form-check">
                          <input class="form-check-input available-session-duration" type="checkbox" id="available-duration-60" value="60" checked>
                          <label class="form-check-label" for="available-duration-60">60 minutos</label>
                        </div>
                      </div>
                      <div class="col-sm-4">
                        <div class="form-check">
                          <input class="form-check-input available-session-duration" type="checkbox" id="available-duration-90" value="90">
                          <label class="form-check-label" for="available-duration-90">90 minutos</label>
                        </div>
                      </div>
                      <div class="col-sm-4">
                        <div class="form-check">
                          <input class="form-check-input available-session-duration" type="checkbox" id="available-duration-120" value="120">
                          <label class="form-check-label" for="available-duration-120">120 minutos</label>
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
                <div class="text-end mt-4">
                  <button type="button" class="btn btn-primary" id="btn-save-general-settings">Guardar configuración</button>
                </div>
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
                <div class="text-end mt-4">
                  <button type="button" class="btn btn-primary" id="btn-save-services-settings">Guardar precios</button>
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
                <div class="text-end mt-4">
                  <button type="button" class="btn btn-primary" id="btn-save-bonuses-settings">Guardar bonos</button>
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
                <div class="text-end mt-4">
                  <button type="button" class="btn btn-primary" id="btn-save-interface-settings">Guardar configuración</button>
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
                <div class="text-end">
                  <button type="button" class="btn btn-primary" id="btn-save-booking-settings">Guardar configuración</button>
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

                  <div class="text-end">
                    <button type="submit" class="btn btn-primary">Guardar configuración</button>
                  </div>
                </form>
              </div>

              <div class="tab-pane fade" id="email-settings-panel" role="tabpanel" aria-labelledby="email-settings-tab">
                <div id="email-settings-alert" class="alert d-none"></div>

                <div class="row g-3 align-items-center mb-3">
                  <label class="col-lg-2 col-form-label" for="admin-notification-email">Email avisos</label>
                  <div class="col-lg-10">
                    <input type="email" class="form-control" id="admin-notification-email" autocomplete="email">
                  </div>
                </div>
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

                <div class="text-end settings-actions">
                  <button type="button" class="btn btn-primary" id="btn-save-email-settings">Guardar configuración</button>
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
                <div class="text-end">
                  <button type="button" class="btn btn-primary" id="btn-save-calendar-settings">Guardar configuración</button>
                </div>
              </div>

              <div class="tab-pane fade" id="sms-settings-panel" role="tabpanel" aria-labelledby="sms-settings-tab">
                <div class="text-muted">
                  La configuración de SMS se añadirá aquí cuando elijas la plataforma de envío.
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
                  <div class="text-end mt-4">
                    <button type="button" class="btn btn-primary" id="btn-save-cabinet-settings">Guardar equipo</button>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($is_admin): ?>
    <div class="modal fade" id="closedDayModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md modal-dialog-centered">
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
      <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="professional-editor-title">Profesional</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form id="professional-editor-form">
            <div class="modal-body">
              <input type="hidden" id="professional-editor-index" value="-1">
              <input type="hidden" id="professional-editor-id" value="0">
              <input type="hidden" id="professional-editor-user-id" value="0">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label" for="professional-editor-name">Nombre</label>
                  <input type="text" class="form-control" id="professional-editor-name" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="professional-editor-email">Email de acceso</label>
                  <input type="email" class="form-control" id="professional-editor-email" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="professional-editor-title-field">Cargo</label>
                  <input type="text" class="form-control" id="professional-editor-title-field" placeholder="Psicóloga sanitaria, Psicólogo clínico...">
                </div>
                <div class="col-md-3">
                  <label class="form-label" for="professional-editor-license">Nº de colegiado</label>
                  <input type="text" class="form-control" id="professional-editor-license" placeholder="Ej. T-00000">
                </div>
                <div class="col-md-3 professional-editor-permission-wrap">
                  <label class="form-label" for="professional-editor-role">Permiso</label>
                  <select class="form-select" id="professional-editor-role">
                    <option value="admin">Admin</option>
                    <option value="superadmin">Superadmin</option>
                  </select>
                </div>
                <div class="col-12">
                  <label class="form-label" for="professional-editor-specialty">Especialidad</label>
                  <textarea class="form-control" id="professional-editor-specialty" rows="3" placeholder="Ansiedad, terapia infantil, adultos, pareja..."></textarea>
                </div>
                <div class="col-12">
                  <label class="form-label" for="professional-editor-photo">Foto del profesional</label>
                  <input type="file" class="form-control" id="professional-editor-photo" accept="image/jpeg,image/png,image/webp,image/gif">
                  <div class="d-flex align-items-center gap-3 mt-2">
                    <img src="" alt="" id="professional-editor-photo-preview" class="d-none" style="width: 56px; height: 56px; border-radius: 50%; object-fit: cover;">
                    <div class="form-text" id="professional-editor-photo-status">Formatos permitidos: JPG, PNG, WEBP o GIF. Máximo 2 MB.</div>
                  </div>
                </div>
                <div class="col-12 professional-editor-status-wrap">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="professional-editor-active" checked>
                    <label class="form-check-label" for="professional-editor-active">Profesional activo</label>
                  </div>
                  <div class="form-text">Si está desactivado, no aparecerá como profesional disponible del gabinete.</div>
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
      <div class="modal-dialog modal-md modal-dialog-centered">
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

  <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
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
  </script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script src="js/app.js?v=<?= filemtime(__DIR__ . '/js/app.js') ?>"></script>
</body>

</html>
