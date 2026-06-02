<?php
session_start();
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}
require_once 'db.php';
require_once 'settings_helpers.php';
$is_admin = ($_SESSION['role'] === 'admin');
$branding = get_public_branding_settings($mysqli);
$app_name = $branding['app_name'];
$profile_image_path = $branding['profile_image_path'];
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
        <?php if ($profile_image_path): ?>
          <img src="<?= htmlspecialchars($profile_image_path) ?>" alt="" class="brand-avatar" id="app-brand-image">
        <?php else: ?>
          <img src="" alt="" class="brand-avatar d-none" id="app-brand-image">
        <?php endif; ?>
        <span id="app-brand"><?= htmlspecialchars($app_name) ?></span>
      </a>
      <div class="d-flex align-items-center">
        <span class="me-3 d-none d-md-inline" style="color: var(--text-color);">Hola,
          <?= htmlspecialchars($_SESSION['name']) ?></span>
        <?php if ($is_admin): ?>
          <button class="btn btn-light btn-sm me-2" id="btn-open-settings" type="button">
            <i class="bi bi-gear"></i> Configuración
          </button>
        <?php endif; ?>
        <a href="logout.php" class="btn btn-outline-secondary btn-sm">Salir</a>
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

    <div class="modal fade" id="upcomingAppointmentsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Pr&oacute;ximas citas</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="upcoming-appointments-alert" class="alert d-none"></div>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th>Paciente</th>
                    <th>Servicio</th>
                    <th>Modalidad</th>
                    <th>Pago</th>
                  </tr>
                </thead>
                <tbody id="upcoming-appointments-body">
                  <tr>
                    <td colspan="5" class="text-center text-muted py-4">Cargando...</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="adminStatsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
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
    <div class="modal-dialog modal-lg modal-dialog-centered">
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
            <div class="table-responsive">
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
      <div class="modal-dialog modal-lg">
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
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="services-settings-tab" data-bs-toggle="tab" data-bs-target="#services-settings-panel"
                  type="button" role="tab">Precios</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="bonuses-settings-tab" data-bs-toggle="tab" data-bs-target="#bonuses-settings-panel"
                  type="button" role="tab">Bonos</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="booking-settings-tab" data-bs-toggle="tab" data-bs-target="#booking-settings-panel"
                  type="button" role="tab">Reservas</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="payment-settings-tab" data-bs-toggle="tab" data-bs-target="#payment-settings-panel"
                  type="button" role="tab">Pago online</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="email-settings-tab" data-bs-toggle="tab" data-bs-target="#email-settings-panel"
                  type="button" role="tab">Envío de emails</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="calendar-settings-tab" data-bs-toggle="tab" data-bs-target="#calendar-settings-panel"
                  type="button" role="tab">Sincronizar con Calendario</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="sms-settings-tab" data-bs-toggle="tab" data-bs-target="#sms-settings-panel"
                  type="button" role="tab">SMS</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="interface-settings-tab" data-bs-toggle="tab" data-bs-target="#interface-settings-panel"
                  type="button" role="tab">Interfaz</button>
              </li>
            </ul>

            <div class="tab-content">
              <div class="tab-pane fade show active" id="closed-days-panel" role="tabpanel" aria-labelledby="closed-days-tab">
                <div id="general-settings-alert" class="alert d-none"></div>
                <div class="mb-4">
                  <label class="form-label" for="appointment-delivery-mode">Modalidades de cita disponibles</label>
                  <select class="form-select" id="appointment-delivery-mode">
                    <option value="both">Presencial y online</option>
                    <option value="presencial">Solo presencial</option>
                    <option value="online">Solo online</option>
                  </select>
                </div>

                <hr class="my-4">
                <div class="mb-4">
                  <label class="form-label d-block">Servicios ofrecidos</label>
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

                <hr class="my-4">
                <div class="mb-4">
                  <label class="form-label d-block">Duraciones disponibles</label>
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
                <form id="add-closed-form" class="mb-4">
                  <div class="row g-2">
                    <div class="col-md-3">
                      <label class="form-label" for="closed-start-date">Desde</label>
                      <input type="date" class="form-control" id="closed-start-date" required>
                    </div>
                    <div class="col-md-3">
                      <label class="form-label" for="closed-end-date">Hasta</label>
                      <input type="date" class="form-control" id="closed-end-date">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label" for="closed-reason">Motivo</label>
                      <input type="text" class="form-control" id="closed-reason" placeholder="Motivo (ej. Vacaciones)" required>
                    </div>
                    <div class="col-md-2 d-grid align-items-end">
                      <button class="btn btn-primary" type="submit">Añadir</button>
                    </div>
                  </div>
                </form>
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
                <div class="mb-4">
                  <label class="form-label" for="app-name">Título de la web</label>
                  <input type="text" class="form-control" id="app-name" placeholder="PsicoLogic">
                </div>
                <div class="mb-4">
                  <label class="form-label" for="primary-color">Color principal</label>
                  <div class="d-flex gap-2 align-items-center">
                    <input type="color" class="form-control form-control-color" id="primary-color" value="#8f7fba" title="Elige el color principal">
                    <input type="text" class="form-control" id="primary-color-text" value="#8f7fba" maxlength="7" style="max-width: 120px;">
                  </div>
                  <div class="form-text">Se aplicará a botones, enlaces destacados y elementos principales de la interfaz.</div>
                </div>
                <div class="mb-3">
                  <label class="form-label" for="profile-image">Foto o imagen del dashboard</label>
                  <input type="file" class="form-control" id="profile-image" accept="image/jpeg,image/png,image/webp,image/gif">
                  <div class="form-text">Formatos permitidos: JPG, PNG, WEBP o GIF. Máximo 2 MB.</div>
                </div>
                <div class="d-flex align-items-center gap-3 mb-3" id="profile-image-preview-row" style="display: none !important;">
                  <img src="" alt="" class="settings-image-preview" id="profile-image-preview">
                  <div class="small text-muted" id="profile-image-status"></div>
                </div>
                <div class="form-check form-switch mb-4">
                  <input class="form-check-input" type="checkbox" id="show-profile-image-public">
                  <label class="form-check-label" for="show-profile-image-public">Mostrar también esta imagen en login y registro</label>
                </div>
                <div class="form-check form-switch mb-4">
                  <input class="form-check-input" type="checkbox" id="show-prices-public">
                  <label class="form-check-label" for="show-prices-public">Mostrar precios en la página principal/comercial</label>
                </div>
                <hr class="my-4">
                <div class="mb-3">
                  <label class="form-label" for="landing-image">Foto de bienvenida de la página principal</label>
                  <input type="file" class="form-control" id="landing-image" accept="image/jpeg,image/png,image/webp,image/gif">
                  <div class="form-text">Se usa como imagen principal en la web comercial. Si no se sube, se mostrará un placeholder.</div>
                </div>
                <div class="d-flex align-items-center gap-3 mb-3" id="landing-image-preview-row" style="display: none !important;">
                  <img src="" alt="" class="settings-image-preview" id="landing-image-preview">
                  <div class="small text-muted" id="landing-image-status"></div>
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

                  <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="online-payment-enabled">
                    <label class="form-check-label" for="online-payment-enabled">Activar pago online opcional</label>
                  </div>

                  <div id="payment-config-fields">
                    <div class="mb-3">
                      <label class="form-label" for="payment-environment">Modo de la pasarela</label>
                      <select class="form-select" id="payment-environment" required>
                        <option value="sandbox">Sandbox / pruebas</option>
                        <option value="real">Real</option>
                      </select>
                    </div>

                    <div class="mb-3">
                      <label class="form-label" for="merchant-code">Código del Comercio</label>
                      <input type="text" class="form-control" id="merchant-code" autocomplete="off">
                    </div>

                    <div class="mb-3">
                      <label class="form-label" for="merchant-terminal">Nº de Terminal</label>
                      <input type="text" class="form-control" id="merchant-terminal" autocomplete="off">
                    </div>

                    <div class="mb-3">
                      <label class="form-label" for="merchant-key">Clave</label>
                      <input type="password" class="form-control" id="merchant-key" autocomplete="new-password"
                        placeholder="Déjala en blanco para conservar la actual">
                      <div class="form-text" id="merchant-key-status"></div>
                    </div>
                  </div>

                  <div class="text-end">
                    <button type="submit" class="btn btn-primary">Guardar configuración</button>
                  </div>
                </form>
              </div>

              <div class="tab-pane fade" id="email-settings-panel" role="tabpanel" aria-labelledby="email-settings-tab">
                <div id="email-settings-alert" class="alert d-none"></div>

                <div class="mb-3">
                  <label class="form-label" for="admin-notification-email">Email del administrador para notificaciones</label>
                  <input type="email" class="form-control" id="admin-notification-email" autocomplete="email">
                </div>
                <div class="mb-3">
                  <label class="form-label" for="smtp-from-name">Nombre remitente</label>
                  <input type="text" class="form-control" id="smtp-from-name" placeholder="PsicoLogic">
                </div>


                <div class="mb-3">
                  <label class="form-label" for="email-provider">Sistema de envío</label>
                  <select class="form-select" id="email-provider">
                    <option value="phpmailer">PHPMailer / SMTP</option>
                    <option value="google">Google Gmail API</option>
                  </select>
                </div>

                <div id="smtp-settings-block">
                  <div class="row">
                    <div class="col-md-8 mb-3">
                      <label class="form-label" for="smtp-host">Host SMTP</label>
                      <input type="text" class="form-control" id="smtp-host" placeholder="smtp.gmail.com">
                    </div>
                    <div class="col-md-4 mb-3">
                      <label class="form-label" for="smtp-port">Puerto</label>
                      <input type="number" class="form-control" id="smtp-port" min="1" value="587">
                    </div>
                  </div>
                  <div class="row">
                    <div class="col-md-8 mb-3">
                      <label class="form-label" for="smtp-username">Usuario SMTP</label>
                      <input type="text" class="form-control" id="smtp-username" autocomplete="username">
                    </div>
                    <div class="col-md-4 mb-3">
                      <label class="form-label" for="smtp-secure">Cifrado</label>
                      <select class="form-select" id="smtp-secure">
                        <option value="tls">TLS</option>
                        <option value="ssl">SSL</option>
                        <option value="none">Ninguno</option>
                      </select>
                    </div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label" for="smtp-password">Contraseña SMTP</label>
                    <input type="password" class="form-control" id="smtp-password" autocomplete="new-password"
                      placeholder="Déjala en blanco para conservar la actual">
                    <div class="form-text" id="smtp-password-status"></div>
                  </div>
                  <div class="row">
                    <div class="col-md-6 mb-3">
                      <label class="form-label" for="smtp-from-email">Email remitente</label>
                      <input type="email" class="form-control" id="smtp-from-email">
                    </div>
                  </div>
                </div>

                <div id="google-email-settings-block">
                  <input type="hidden" id="google-connected-email">
                  <input type="hidden" id="google-redirect-uri">
                  <input type="hidden" id="google-refresh-token">
                  <div class="mb-3">
                    <label class="form-label" for="google-client-id">Google Client ID</label>
                    <input type="text" class="form-control" id="google-client-id">
                  </div>
                  <div class="mb-3">
                    <label class="form-label" for="google-client-secret">Google Client Secret</label>
                    <input type="password" class="form-control" id="google-client-secret" autocomplete="new-password"
                      placeholder="Déjalo en blanco para conservar el actual">
                    <div class="form-text" id="google-client-secret-status"></div>
                  </div>
                  <div class="small text-muted mb-3" id="google-connected-status"></div>
                  <div class="d-flex gap-2 flex-wrap mb-3">
                    <button type="button" class="btn btn-outline-primary" id="btn-google-connect">
                      Conectar con Google
                    </button>
                  </div>
                </div>

                <hr class="my-4">
                <div class="form-check form-switch mb-3">
                  <input class="form-check-input" type="checkbox" id="appointment-reminder-enabled" name="appointment_reminder_enabled">
                  <label class="form-check-label" for="appointment-reminder-enabled">Enviar email de recordatorio al paciente 24 horas antes de la cita</label>
                </div>

                <div class="text-end settings-actions">
                  <button type="button" class="btn btn-primary" id="btn-save-email-settings">Guardar configuración</button>
                </div>
              </div>

              <div class="tab-pane fade" id="calendar-settings-panel" role="tabpanel" aria-labelledby="calendar-settings-tab">
                <div id="calendar-settings-alert" class="alert d-none"></div>
                <div class="form-check form-switch mb-3">
                  <input class="form-check-input" type="checkbox" id="google-calendar-enabled">
                  <label class="form-check-label" for="google-calendar-enabled">Sincronizar citas con Google Calendar</label>
                </div>
                <div id="calendar-config-fields">
                  <div class="mb-3">
                    <label class="form-label" for="google-calendar-id">Calendar ID</label>
                    <input type="text" class="form-control" id="google-calendar-id" placeholder="primary">
                  </div>
                  <div class="text-muted mb-3">
                    Usa las mismas credenciales Google configuradas en la pestaña Envío de emails.
                  </div>
                  <div class="d-flex gap-2 flex-wrap mb-3">
                    <button type="button" class="btn btn-outline-primary" id="btn-google-connect-calendar">
                      Conectar/Reautorizar Google
                    </button>
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
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <script>
    const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
  </script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script src="js/app.js?v=<?= filemtime(__DIR__ . '/js/app.js') ?>"></script>
</body>

</html>
