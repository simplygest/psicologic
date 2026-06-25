let currentStartDate = getMonday(new Date());
let currentMonthDate = new Date();
function normalizeInitialDashboardView(value) {
    const view = ['week', 'month', 'patients', 'upcoming'].includes(value) ? value : 'month';
    if (IS_ADMIN && !supportsDashboardInlineViews() && (view === 'patients' || view === 'upcoming')) {
        return defaultAgendaCalendarView();
    }
    return IS_ADMIN ? view : 'month';
}

function defaultAgendaCalendarView() {
    return (typeof INITIAL_CALENDAR_VIEW !== 'undefined' && INITIAL_CALENDAR_VIEW === 'week') ? 'week' : 'month';
}

function supportsDashboardInlineViews() {
    return typeof window !== 'undefined' && window.matchMedia('(min-width: 1200px)').matches;
}

let currentCalendarView = normalizeInitialDashboardView(typeof INITIAL_CALENDAR_VIEW !== 'undefined' ? INITIAL_CALENDAR_VIEW : 'month');
let currentMonthData = null;
let selectedMonthDay = null;
let selectedMonthDayAnimationClass = '';
let calendarInitialAnimationPending = true;
let DASHBOARD_PATIENTS = [];
let DASHBOARD_UPCOMING_APPOINTMENTS = [];
let dashboardPatientsLoaded = false;
let dashboardUpcomingLoaded = false;
let PAYMENT_SETTINGS = {
    online_payment_enabled: 0,
    appointment_price: '70.00',
    online_appointment_price: '70.00',
    couple_appointment_price: '90.00',
    online_couple_appointment_price: '90.00',
    available_session_types: 'individual',
    min_booking_notice_days: 2,
    max_booking_notice_days: 40,
    appointment_start_time: '10:00:00',
    appointment_end_time: '19:00:00',
    break_start_time: '15:00:00',
    break_end_time: '16:00:00',
    available_weekdays: '1,2,3,4,5',
    appointment_delivery_mode: 'both',
    available_session_durations: '60',
    display_effective_duration_enabled: 0,
    display_duration_offset_minutes: 5,
    online_booking_enabled: 1,
    patient_tasks_visible_default: 0,
    work_plan_task_status_enabled: 1,
    email_provider: 'phpmailer',
    smtp_from_email: '',
    google_connected_email: '',
    has_google_refresh_token: 0,
    bonuses_enabled: 0,
    create_compensation_bonus_on_paid_cancel: 1,
    allow_patient_transfer: 0
};

function workPlanTaskStatusEnabled() {
    const value = PAYMENT_SETTINGS.work_plan_task_status_enabled;
    if (value === undefined || value === null || value === '') {
        return true;
    }
    return parseInt(value, 10) === 1;
}
let APP_SECTOR_TEXTS = (typeof SECTOR_TEXTS !== 'undefined' && SECTOR_TEXTS) ? SECTOR_TEXTS : {};
let APP_SECTOR_TEXT_OPTIONS = (typeof SECTOR_TEXT_OPTIONS !== 'undefined' && Array.isArray(SECTOR_TEXT_OPTIONS)) ? SECTOR_TEXT_OPTIONS : [];
let APP_DASHBOARD_CONFIG = (typeof DASHBOARD_CONFIG !== 'undefined' && DASHBOARD_CONFIG) ? DASHBOARD_CONFIG : { features: {} };
let APP_PLAN_CONFIG = (typeof PLAN_CONFIG !== 'undefined' && PLAN_CONFIG) ? PLAN_CONFIG : { plan: { features: {} } };
let APP_KNOWLEDGE_BASE_ENABLED = typeof KNOWLEDGE_BASE_ENABLED !== 'undefined' ? Boolean(KNOWLEDGE_BASE_ENABLED) : false;
let APP_KNOWLEDGE_BASE_HAS_SECTOR_DATA = APP_KNOWLEDGE_BASE_ENABLED;
let APP_CURRENT_SECTOR_KEY = (typeof CURRENT_SECTOR_KEY !== 'undefined' && CURRENT_SECTOR_KEY) ? String(CURRENT_SECTOR_KEY).toLowerCase() : '';
let KNOWLEDGE_SECTOR_OPTIONS = {
    loaded: false,
    enabled: false,
    multi_sector_enabled: false,
    main_sector: APP_CURRENT_SECTOR_KEY,
    related_sectors: [],
    sectors: []
};
let APP_BODY_MAP_ENABLED = typeof BODY_MAP_ENABLED !== 'undefined' ? Boolean(BODY_MAP_ENABLED) : false;
let LOADED_DASHBOARD_CONFIG_MODE = (typeof DASHBOARD_CONFIG_MODE !== 'undefined' && DASHBOARD_CONFIG_MODE) ? DASHBOARD_CONFIG_MODE : 'simple';
let APPOINTMENT_SERVICES = [];
let ACTIVE_SERVICE_OPTIONS = [];
let APPOINTMENT_BONUSES = [];
let SETTINGS_SNAPSHOTS = {
    services: '',
    bonuses: '',
    cabinet: ''
};
let PATIENT_BONUS_BALANCE = { bonuses_enabled: 0, total_remaining: 0, bonuses: [] };
let isAppointmentRequestInProgress = false;

function assetUrl(path) {
    const value = String(path || '').trim();
    if (!value || /^(https?:)?\/\//i.test(value) || value.startsWith('data:') || value.startsWith('asset.php')) {
        return value;
    }
    const clean = value.replace(/^\/+/, '').replace(/\\/g, '/');
    if (!clean.startsWith('uploads/')) {
        return value;
    }
    return `asset.php?p=${encodeURIComponent(clean)}`;
}

let inviteModal = null;
let upcomingAppointmentsModal = null;
let appointmentPaymentModal = null;
let appointmentSessionNoteModal = null;
let upcomingAppointmentsInitialLoad = true;
let CURRENT_UPCOMING_PLANNING = {
    appointments: [],
    settings: {},
    closedDays: []
};
let CURRENT_CANCELLED_APPOINTMENTS = [];
let adminStatsModal = null;
let bonusesModal = null;
let selectedBonusToBuy = null;
let patientWorkPlanTaskModal = null;
let currentInviteLink = '';
let currentInviteToken = '';
let currentCancelAppointmentId = null;
let appointmentSessionAlertTimer = null;
let appointmentPaymentAlertTimer = null;
let CURRENT_PATIENT_PROFESSIONAL_CONTEXT = null;
let CURRENT_BOOKING_PROFESSIONAL_CONTEXT = null;
let CURRENT_PATIENT_SELECTED_PROFESSIONAL_ID = 0;
let CURRENT_PATIENT_PROFESSIONALS = [];
let CURRENT_PATIENT_BOOKING_MODE = '';
let CURRENT_SLOT_PROFESSIONALS = [];
let CURRENT_SLOT_SELECTED_PROFESSIONAL_ID = 0;
let CURRENT_BOOKING_CONSULTATION_TYPE = '';
let CURRENT_PATIENT_EDITOR = null;
const QUICK_APPOINTMENTS_REFRESH_INTERVAL_MS = 60000;
let quickAppointmentsRefreshTimer = null;
let quickAppointmentsSummaryLoading = false;
let quickAppointmentsSummaryRequest = null;
let quickAppointmentsSummaryRendered = false;
let patientPortalSummaryLoading = false;
let bookingPatientsLoaded = false;
let bookingPatientsLoading = false;
let bookingPatientsRequest = null;
let bookingContextLoading = false;
const BODY_MAP_SECTORS = ['fitness', 'fisioterapia', 'quiropractica', 'osteopatia'];
let BODY_MUSCLE_CHART = null;
let BODY_MUSCLE_VIEW = 'FRONT';
let BODY_MUSCLE_STATE = {};
let BODY_MUSCLE_SELECTED = new Set();
let BODY_MUSCLE_SELECTED_NAMES = {};
let BODY_MUSCLE_RESULTS_REQUEST = null;

function appPrimaryColor() {
    return getComputedStyle(document.documentElement).getPropertyValue('--primary-color').trim() || '#4285f4';
}

function sectorText(path, fallback = '') {
    const parts = String(path || '').split('.').filter(Boolean);
    let current = APP_SECTOR_TEXTS;
    for (const part of parts) {
        if (!current || typeof current !== 'object' || !(part in current)) {
            return fallback;
        }
        current = current[part];
    }
    return typeof current === 'string' ? current : fallback;
}

function sectorLabel(entity, form = 'singular', fallback = '') {
    return sectorText(`labels.${entity}.${form}`, fallback);
}

function capitalizeFirst(value) {
    value = String(value || '');
    return value ? value.charAt(0).toUpperCase() + value.slice(1) : '';
}

function dashboardFeatureEnabled(feature, fallback = false) {
    return APP_DASHBOARD_CONFIG && APP_DASHBOARD_CONFIG.features && typeof APP_DASHBOARD_CONFIG.features[feature] === 'boolean'
        ? APP_DASHBOARD_CONFIG.features[feature]
        : fallback;
}

function planFeatureEnabled(feature, fallback = false) {
    return APP_PLAN_CONFIG && APP_PLAN_CONFIG.plan && APP_PLAN_CONFIG.plan.features && typeof APP_PLAN_CONFIG.plan.features[feature] === 'boolean'
        ? APP_PLAN_CONFIG.plan.features[feature]
        : fallback;
}

function appFeatureEnabled(feature, fallback = false) {
    return dashboardFeatureEnabled(feature, fallback) && planFeatureEnabled(feature, fallback);
}

function paymentPlanEnabled() {
    return appFeatureEnabled('onlinePayments.enabled', false) && appFeatureEnabled('payments.online', false);
}

function knowledgeBaseEnabled() {
    return APP_KNOWLEDGE_BASE_ENABLED && APP_KNOWLEDGE_BASE_HAS_SECTOR_DATA && appFeatureEnabled('knowledgeBase.enabled', false);
}

function knowledgeImportEnabled() {
    return knowledgeBaseEnabled() && appFeatureEnabled('knowledgeBase.importTasks', false);
}

function bodyMapEnabled() {
    return APP_BODY_MAP_ENABLED && knowledgeBaseEnabled() && BODY_MAP_SECTORS.includes(APP_CURRENT_SECTOR_KEY);
}

function setFeatureVisible(selector, visible) {
    const $elements = $(selector);
    if (!$elements.length) return;
    $elements.toggleClass('d-none', !visible);
    $elements.find('input, select, textarea, button').prop('disabled', !visible);
}

function ensureVisibleSettingsTab() {
    const activeTab = $('#settings-tabs .nav-link.active');
    if (activeTab.length && !activeTab.closest('.nav-item').hasClass('d-none')) {
        return;
    }
    const fallback = document.getElementById('closed-days-tab');
    if (fallback) {
        bootstrap.Tab.getOrCreateInstance(fallback).show();
    }
}

function showFallbackTabIfHidden(activeSelector, fallbackSelector) {
    const active = document.querySelector(activeSelector);
    if (!active || !active.classList.contains('active')) return;
    const item = active.closest('.nav-item');
    if (!item || !item.classList.contains('d-none')) return;
    const fallback = document.querySelector(fallbackSelector);
    if (fallback) {
        bootstrap.Tab.getOrCreateInstance(fallback).show();
    }
}

function applyPlanFeatureVisibility() {
    const patientPortal = appFeatureEnabled('patientPortal.enabled', false);
    const patientPortalPlan = planFeatureEnabled('patientPortal.enabled', false);
    const invitations = patientPortal && appFeatureEnabled('patientPortal.invitations', false);
    const bonuses = appFeatureEnabled('bonuses.enabled', false);
    const tasks = appFeatureEnabled('tasks.enabled', false);
    const tasksPlan = planFeatureEnabled('tasks.enabled', false);
    const templates = appFeatureEnabled('taskTemplates.enabled', false);
    const reports = appFeatureEnabled('reports.globalReports', false);
    const upcomingPlanning = appFeatureEnabled('upcomingAppointments.planning', false);
    const payments = paymentPlanEnabled();
    const reminders = appFeatureEnabled('reminders.patient24h', false);
    const calendarSync = appFeatureEnabled('calendarSync.enabled', false);
    const customLogo = appFeatureEnabled('branding.customLogo', false);
    const team = appFeatureEnabled('team.enabled', false);
    const uiCustomization = planFeatureEnabled('ui.customization', false);
    const effectiveDuration = appFeatureEnabled('appointments.effectiveDuration', false);

    setFeatureVisible('#btn-generate-invite, #btn-mobile-generate-invite, #btn-sidebar-generate-invite', invitations);
    setFeatureVisible('#btn-admin-bonuses, #btn-mobile-admin-bonuses, #btn-sidebar-admin-bonuses, #btn-buy-bonus, #btn-my-bonuses', bonuses);
    $('#btn-mobile-buy-bonus, #btn-mobile-my-bonuses').closest('li').toggleClass('d-none', !bonuses);
    $('#patient-bonuses-tab').closest('.nav-item').toggleClass('d-none', !bonuses);
    $('#patient-bonuses-panel').toggleClass('d-none', !bonuses);

    setFeatureVisible('#btn-admin-stats, #btn-mobile-admin-stats, #btn-sidebar-admin-stats', reports);
    $('#patient-reports-tab').closest('.nav-item').toggleClass('d-none', !reports);
    $('#patient-reports-panel').toggleClass('d-none', !reports);
    $('#upcoming-planning-tab').closest('.nav-item').toggleClass('d-none', !upcomingPlanning);
    $('#upcoming-planning-panel').toggleClass('d-none', !upcomingPlanning);
    setFeatureVisible('#btn-patient-portal-tasks, #btn-mobile-patient-portal-tasks', patientPortalPlan && tasksPlan);
    setFeatureVisible('#btn-patient-portal-documents, #btn-mobile-patient-portal-documents', patientPortalPlan);
    setFeatureVisible('#btn-patient-portal-reports, #btn-mobile-patient-portal-reports', patientPortalPlan && reports);
    $('#patient-work-plan-tab').closest('.nav-item').toggleClass('d-none', !tasks);
    $('#patient-work-plan-panel').toggleClass('d-none', !tasks);
    $('#appointment-session-tab').closest('.nav-item').toggleClass('d-none', !tasks);
    $('#appointment-session-panel').toggleClass('d-none', !tasks);

    $('#bonuses-settings-tab').closest('.nav-item').toggleClass('d-none', !bonuses);
    $('#bonuses-settings-panel').toggleClass('d-none', !bonuses);
    $('#task-templates-settings-tab').closest('.nav-item').toggleClass('d-none', !templates);
    $('#task-templates-settings-panel').toggleClass('d-none', !templates);
    $('#payment-settings-tab').closest('.nav-item').toggleClass('d-none', !payments);
    $('#payment-settings-panel').toggleClass('d-none', !payments);
    $('#calendar-settings-tab').closest('.nav-item').toggleClass('d-none', !calendarSync);
    $('#calendar-settings-panel').toggleClass('d-none', !calendarSync);
    $('#cabinet-settings-tab').closest('.nav-item').toggleClass('d-none', !team);
    $('#cabinet-settings-panel').toggleClass('d-none', !team);

    $('#online-booking-enabled').closest('.row').toggleClass('d-none', !patientPortal);
    $('#patient-registration-requires-invite').closest('.row').toggleClass('d-none', !patientPortal || !invitations);
    $('#patient-tasks-visible-default').closest('.row').toggleClass('d-none', !patientPortal || !tasks);
    $('#display-effective-duration-enabled').closest('.mt-3').toggleClass('d-none', !effectiveDuration);
    $('#appointment-reminder-enabled').closest('.row').toggleClass('d-none', !reminders);
    $('#profile-image').closest('.row').toggleClass('d-none', !customLogo);
    $('#show-profile-image-public').closest('.form-check').toggleClass('d-none', !customLogo);

    $('#dashboard-config-row').toggleClass('d-none', !uiCustomization);
    $('#dashboard-config-mode').val(uiCustomization ? (LOADED_DASHBOARD_CONFIG_MODE || 'advanced') : 'advanced');
    $('#btn-open-dashboard-custom-config').toggleClass('d-none', !uiCustomization);

    if (!payments) {
        $('#online-payment-enabled').prop('checked', false);
        togglePaymentSettings();
    }
    if (!calendarSync) {
        $('#calendar-provider').val('none');
        toggleCalendarSettings();
    }
    if (!reminders) {
        $('#appointment-reminder-enabled').prop('checked', false);
    }
    if (!bonuses) {
        $('#bonuses-enabled').prop('checked', false);
        toggleBonusesSettings();
    }

    ensureVisibleSettingsTab();
    showFallbackTabIfHidden('#patient-work-plan-tab', '#patient-data-tab');
    showFallbackTabIfHidden('#patient-bonuses-tab', '#patient-data-tab');
    showFallbackTabIfHidden('#patient-reports-tab', '#patient-data-tab');
    showFallbackTabIfHidden('#appointment-session-tab', '#appointment-detail-tab');
    showFallbackTabIfHidden('#upcoming-planning-tab', '#upcoming-list-tab');
    applyKnowledgeBaseVisibility();
}

function applyKnowledgeBaseVisibility() {
    const enabled = knowledgeBaseEnabled();
    const $tab = $('#patient-diagnosis-tab');
    const $panel = $('#patient-diagnosis-panel');
    if (!$tab.length || !$panel.length) return;
    $tab.closest('.nav-item').toggleClass('d-none', !enabled);
    $panel.toggleClass('d-none', !enabled);
    if (!enabled && $tab.hasClass('active')) {
        const fallback = document.getElementById('patient-data-tab');
        if (fallback) {
            bootstrap.Tab.getOrCreateInstance(fallback).show();
        }
    }
}

function getMonday(d) {
    d = new Date(d);
    var day = d.getDay(),
        diff = d.getDate() - day + (day == 0 ? -6 : 1); // adjust when day is sunday
    return new Date(d.setDate(diff));
}

function formatDate(date) {
    const d = new Date(date);
    let month = '' + (d.getMonth() + 1);
    let day = '' + d.getDate();
    const year = d.getFullYear();

    if (month.length < 2) month = '0' + month;
    if (day.length < 2) day = '0' + day;

    return [year, month, day].join('-');
}

function formatDisplayDate(dateStr) {
    const parts = dateStr.split('-');
    return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : dateStr;
}

function formatDisplayDateOnly(dateStr) {
    const raw = String(dateStr || '').trim();
    if (!raw) {
        return '';
    }
    return formatDisplayDate(raw.split(/[T\s]/)[0]);
}

function formatDisplayDateWithWeekday(dateStr) {
    const label = formatDisplayDate(dateStr);
    const date = new Date(`${dateStr}T00:00:00`);
    if (Number.isNaN(date.getTime())) {
        return label;
    }
    const weekdays = ['domingo', 'lunes', 'martes', 'mi&eacute;rcoles', 'jueves', 'viernes', 's&aacute;bado'];
    return `${label} (${weekdays[date.getDay()]})`;
}

function calculateAgeFromDate(dateStr) {
    if (!dateStr) return '';
    const birth = new Date(`${dateStr}T00:00:00`);
    if (Number.isNaN(birth.getTime())) return '';
    const today = new Date();
    let age = today.getFullYear() - birth.getFullYear();
    const monthDiff = today.getMonth() - birth.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
        age--;
    }
    return age >= 0 && age < 130 ? String(age) : '';
}

function updatePatientAgeDisplay() {
    const age = calculateAgeFromDate($('#patient-editor-birth-date').val());
    $('#patient-editor-age').text(age ? `${age} años` : '-');
}

function patientMetricNumber(selector) {
    const value = String($(selector).val() || '').replace(',', '.').trim();
    if (!value || Number.isNaN(Number(value))) return 0;
    return Number(value);
}

function calculatePatientBmi() {
    const weight = patientMetricNumber('#patient-editor-weight');
    const heightCm = patientMetricNumber('#patient-editor-height');
    if (weight <= 0 || heightCm <= 0) return 0;
    const heightM = heightCm / 100;
    return weight / (heightM * heightM);
}

function updatePatientBmiDisplay() {
    const bmi = calculatePatientBmi();
    updateBmiIndicator('#patient-editor-bmi', bmi);
}

function calculateBmiFromValues(weight, heightCm) {
    weight = Number(weight || 0);
    heightCm = Number(heightCm || 0);
    if (weight <= 0 || heightCm <= 0) return 0;
    const heightM = heightCm / 100;
    return weight / (heightM * heightM);
}

function updatePatientEvolutionBmiDisplay() {
    const bmi = calculateBmiFromValues(
        patientMetricNumber('#patient-evolution-weight'),
        patientMetricNumber('#patient-evolution-height')
    );
    updateBmiIndicator('#patient-evolution-bmi', bmi);
}

function updateBmiIndicator(selector, bmi) {
    const $indicator = $(selector);
    if (!$indicator.length) return;
    const $value = $indicator.find('.bmi-indicator-value');
    const $label = $indicator.find('.bmi-indicator-label');
    const $fill = $indicator.find('.bmi-indicator-fill');
    $indicator.removeClass('is-low is-healthy is-high');
    if (!bmi || bmi <= 0 || Number.isNaN(bmi)) {
        $value.text('-');
        $label.text('Sin datos').attr('class', 'badge text-bg-light bmi-indicator-label');
        $fill.css('width', '0%');
        return;
    }

    let state = 'healthy';
    let label = 'Saludable';
    let badgeClass = 'text-bg-success';
    if (bmi < 18.5) {
        state = 'low';
        label = 'Bajo';
        badgeClass = 'text-bg-warning';
    } else if (bmi >= 25) {
        state = 'high';
        label = 'Alto';
        badgeClass = 'text-bg-danger';
    }
    const percentage = Math.max(4, Math.min(100, ((bmi - 12) / 28) * 100));
    $indicator.addClass(`is-${state}`);
    $value.text(bmi.toFixed(1));
    $label.text(label).attr('class', `badge ${badgeClass} bmi-indicator-label`);
    $fill.css('width', `${percentage}%`);
}

function calculatePatientBodyFat() {
    const bmi = calculatePatientBmi();
    const birthDate = $('#patient-editor-birth-date').val() || '';
    const age = parseInt(calculateAgeFromDate(birthDate) || '0', 10);
    const sex = $('#patient-editor-physical-sex').val();
    const skinfoldBodyFat = calculatePatientBodyFatFromSkinfolds(age, sex);
    if (skinfoldBodyFat > 0) {
        const clampedSkinfold = Math.max(0, Math.min(80, skinfoldBodyFat));
        $('#patient-editor-body-fat').val(clampedSkinfold.toFixed(1));
        $('#patient-editor-body-fat-note').text(sex === 'male'
            ? 'Estimacion orientativa calculada con Jackson-Pollock 3 pliegues: pectoral, abdominal y muslo.'
            : 'Estimacion orientativa calculada con Jackson-Pollock 3 pliegues: triceps, suprailiaco y muslo.');
        return;
    }
    if (bmi <= 0 || age <= 0 || !['male', 'female'].includes(sex)) {
        const missing = [];
        if (bmi <= 0) missing.push('peso y altura');
        if (age <= 0) missing.push('fecha de nacimiento valida');
        if (!['male', 'female'].includes(sex)) missing.push('sexo biologico');
        $('#patient-editor-body-fat-note').text(`Para calcularlo faltan: ${missing.join(', ')}.`);
        return;
    }
    const sexFactor = sex === 'male' ? 1 : 0;
    const bodyFat = (1.2 * bmi) + (0.23 * age) - (10.8 * sexFactor) - 5.4;
    const clamped = Math.max(0, Math.min(80, bodyFat));
    $('#patient-editor-body-fat').val(clamped.toFixed(1));
    $('#patient-editor-body-fat-note').text('Estimacion orientativa calculada con formula Deurenberg usando IMC, edad y sexo biologico.');
}

function calculatePatientBodyFatFromSkinfolds(age, sex) {
    if (age <= 0 || !['male', 'female'].includes(sex)) return 0;
    const triceps = patientMetricNumber('#patient-editor-skinfold-triceps');
    const suprailiac = patientMetricNumber('#patient-editor-skinfold-suprailiac');
    const chest = patientMetricNumber('#patient-editor-skinfold-chest');
    const abdominal = patientMetricNumber('#patient-editor-skinfold-abdominal');
    const thigh = patientMetricNumber('#patient-editor-skinfold-thigh');
    let sum = 0;
    let density = 0;
    if (sex === 'male' && chest > 0 && abdominal > 0 && thigh > 0) {
        sum = chest + abdominal + thigh;
        density = 1.10938 - (0.0008267 * sum) + (0.0000016 * sum * sum) - (0.0002574 * age);
    } else if (sex === 'female' && triceps > 0 && suprailiac > 0 && thigh > 0) {
        sum = triceps + suprailiac + thigh;
        density = 1.0994921 - (0.0009929 * sum) + (0.0000023 * sum * sum) - (0.0001392 * age);
    }
    if (density <= 0) return 0;
    return (495 / density) - 450;
}

function renderWeekInfo() {
    updateCalendarNavigationLabels();
    if (IS_ADMIN && !isCalendarDashboardView()) {
        if (!quickAppointmentsSummaryRendered && !quickAppointmentsSummaryLoading) {
            loadQuickAppointmentsSummary(true);
        }
        if (currentCalendarView === 'patients') {
            return renderDashboardPatientsView();
        }
        if (currentCalendarView === 'upcoming') {
            return renderDashboardUpcomingView();
        }
    }
    const quickPromise = IS_ADMIN
        ? loadQuickAppointmentsSummary(!quickAppointmentsSummaryRendered)
        : $.Deferred().resolve().promise();

    if (currentCalendarView === 'month') {
        const monthStr = formatMonthStart(currentMonthDate);
        $('#calendar-container').html('<div class="text-center text-muted py-5"><div class="spinner-border text-secondary" role="status"></div><br>Cargando mes...</div>');
        const calendarPromise = fetchMonthCalendar(monthStr);
        return $.when(quickPromise, calendarPromise).done(function (_quickResult, calendarResult) {
            const res = Array.isArray(calendarResult) ? calendarResult[0] : calendarResult;
            applyMonthCalendarResponse(res);
        });
    }
    const startDateStr = formatDate(currentStartDate);
    $('#calendar-container').html('<div class="text-center text-muted py-5"><div class="spinner-border text-secondary" role="status"></div><br>Cargando...</div>');
    const calendarPromise = fetchWeekCalendar(startDateStr);
    return $.when(quickPromise, calendarPromise).done(function (_quickResult, calendarResult) {
        const res = Array.isArray(calendarResult) ? calendarResult[0] : calendarResult;
        applyWeekCalendarResponse(startDateStr, res);
    });
}

function isCalendarDashboardView() {
    return currentCalendarView === 'month' || currentCalendarView === 'week';
}

function invalidateDashboardUpcomingAppointments() {
    dashboardUpcomingLoaded = false;
    DASHBOARD_UPCOMING_APPOINTMENTS = [];
}

function formatMonthStart(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-01`;
}

function debounce(fn, wait = 250) {
    let timeout = null;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => fn.apply(this, args), wait);
    };
}

function updateCalendarNavigationLabels() {
    const monthMode = currentCalendarView === 'month';
    $('#btn-calendar-view-toggle').html(monthMode ? '<i class="bi bi-calendar-week"></i> Ver semana' : '<i class="bi bi-calendar3"></i> Ver mes');
    $('.btn-dashboard-view').removeClass('active btn-primary').addClass('btn-outline-primary');
    $(`.btn-dashboard-view[data-dashboard-view="${currentCalendarView}"]`).addClass('active btn-primary').removeClass('btn-outline-primary');
    $('.btn-dashboard-main-view').removeClass('active btn-primary');
    const mainView = isCalendarDashboardView() ? 'agenda' : currentCalendarView;
    $(`.btn-dashboard-main-view[data-dashboard-main-view="${mainView}"]`).addClass('active btn-primary');
    $('.btn-dashboard-calendar-mode').removeClass('active btn-primary').addClass('btn-outline-primary');
    $(`.btn-dashboard-calendar-mode[data-dashboard-calendar-view="${currentCalendarView}"]`).addClass('active btn-primary').removeClass('btn-outline-primary');
}

function setPatientProfessionalContext(context) {
    CURRENT_PATIENT_PROFESSIONAL_CONTEXT = context && context.display_name && context.is_patient_assigned == 1 ? context : null;
    CURRENT_BOOKING_PROFESSIONAL_CONTEXT = context && context.display_name ? context : null;
    renderPatientProfessionalContext();
}

function professionalContextHtml(context, titlePrefix, options = {}) {
    if (!context || !context.display_name) {
        return '';
    }
    const showSubtitle = options.showSubtitle !== false;
    const name = escapeHtml(context.display_name);
    const photo = context.display_photo_path
        ? `<img class="booking-professional-avatar" src="${escapeHtml(assetUrl(context.display_photo_path))}" alt="${name}">`
        : '<span class="booking-professional-avatar booking-professional-avatar-empty"><i class="bi bi-person"></i></span>';
    return `
        ${photo}
        <div>
            <div class="booking-professional-title">${escapeHtml(titlePrefix)} ${name}</div>
            ${showSubtitle ? '<div class="booking-professional-subtitle">Busca una fecha/hora disponible utilizando el calendario semanal o mensual.</div>' : ''}
        </div>
    `;
}

function renderPatientProfessionalContext() {
    const $context = $('#patient-professional-context');
    if (!$context.length || IS_ADMIN || !CURRENT_PATIENT_PROFESSIONAL_CONTEXT) {
        $context.addClass('d-none').empty();
        return;
    }
    $context.html(professionalContextHtml(CURRENT_PATIENT_PROFESSIONAL_CONTEXT, 'Reserva tu cita con')).removeClass('d-none');
}

function renderModalProfessionalContext() {
    const $context = $('#modal-professional-context');
    const context = CURRENT_PATIENT_PROFESSIONAL_CONTEXT || CURRENT_BOOKING_PROFESSIONAL_CONTEXT;
    const choosingProfessionalInsideModal = CURRENT_PATIENT_BOOKING_MODE === 'day_first'
        && !CURRENT_PATIENT_PROFESSIONAL_CONTEXT
        && CURRENT_SLOT_PROFESSIONALS.length > 0;
    if (!$context.length || IS_ADMIN || !context || choosingProfessionalInsideModal) {
        $context.addClass('d-none').empty();
        return;
    }
    $context.html(professionalContextHtml(context, 'Tu cita con', { showSubtitle: false })).removeClass('d-none');
}

function setSlotBookingProfessional(professionalId) {
    CURRENT_SLOT_SELECTED_PROFESSIONAL_ID = parseInt(professionalId || 0, 10);
    const professional = CURRENT_SLOT_PROFESSIONALS.find(item => parseInt(item.id, 10) === CURRENT_SLOT_SELECTED_PROFESSIONAL_ID) || null;
    if (!professional) {
        ACTIVE_SERVICE_OPTIONS = [];
        CURRENT_BOOKING_PROFESSIONAL_CONTEXT = null;
        $('#modal-professional-context').addClass('d-none').empty();
        renderBookingServiceOptions();
        refreshBookingBonusNotice();
        return;
    }
    CURRENT_BOOKING_PROFESSIONAL_CONTEXT = professional;
    ACTIVE_SERVICE_OPTIONS = Array.isArray(professional.service_options) ? professional.service_options : [];
    CURRENT_BOOKING_CONSULTATION_TYPE = '';
    renderSlotProfessionalCards();
    renderModalProfessionalContext();
    renderBookingServiceOptions();
    refreshBookingBonusNotice();
}

function professionalDeliveryPills(professional) {
    const mode = professional && professional.appointment_delivery_mode ? professional.appointment_delivery_mode : 'both';
    const pills = [];
    if (mode === 'both' || mode === 'presencial') {
        pills.push('<span class="professional-delivery-pill presencial">Presencial</span>');
    }
    if (mode === 'both' || mode === 'online') {
        pills.push('<span class="professional-delivery-pill online">Online</span>');
    }
    return pills.length ? `<span class="professional-delivery-pills">${pills.join('')}</span>` : '';
}

function bookingConsultationTypesAvailable() {
    const mode = PAYMENT_SETTINGS.appointment_delivery_mode || 'both';
    const types = new Set();
    ACTIVE_SERVICE_OPTIONS.forEach(option => {
        if ((mode === 'both' || option.consultation_type === mode)
            && selectedSlotCanFitDuration(option.duration_minutes)) {
            types.add(option.consultation_type);
        }
    });
    return types;
}

function ensureBookingConsultationType() {
    const available = bookingConsultationTypesAvailable();
    if (CURRENT_BOOKING_CONSULTATION_TYPE && available.has(CURRENT_BOOKING_CONSULTATION_TYPE)) {
        return CURRENT_BOOKING_CONSULTATION_TYPE;
    }
    CURRENT_BOOKING_CONSULTATION_TYPE = available.has('presencial')
        ? 'presencial'
        : (available.has('online') ? 'online' : '');
    return CURRENT_BOOKING_CONSULTATION_TYPE;
}

function renderBookingConsultationCards() {
    const $wrap = $('#bookingConsultationSelect');
    const $cards = $('#booking-consultation-cards');
    if (!$wrap.length || !$cards.length || $('#modalStatus').val() !== 'available') {
        $wrap.addClass('d-none');
        $cards.empty();
        return;
    }

    const available = bookingConsultationTypesAvailable();
    const selected = ensureBookingConsultationType();
    if (!available.size) {
        $wrap.addClass('d-none');
        $cards.empty();
        return;
    }

    const options = [
        { type: 'presencial', label: 'Presencial', icon: 'bi-person-check' },
        { type: 'online', label: 'Online', icon: 'bi-camera-video' }
    ];
    $cards.html(options.map(option => {
        const enabled = available.has(option.type);
        const active = selected === option.type;
        const disabledText = enabled ? '' : '<span class="booking-consultation-card-note">No disponible</span>';
        return `
            <button type="button" class="booking-consultation-card ${active ? 'is-selected' : ''} ${enabled ? '' : 'is-disabled'}" data-consultation-type="${option.type}" ${enabled ? '' : 'disabled'} aria-pressed="${active ? 'true' : 'false'}">
                <i class="bi ${option.icon}"></i>
                <span>${option.label}</span>
                ${disabledText}
            </button>
        `;
    }).join(''));
    $wrap.removeClass('d-none');
}

function renderSlotProfessionalCards() {
    const $container = $('#patientSlotProfessionalSelect');
    if (!$container.length) return;
    if (!CURRENT_SLOT_PROFESSIONALS.length) {
        $container.html('<div class="alert alert-secondary mb-0 text-center">No hay profesionales disponibles para este horario.</div>').removeClass('d-none');
        return;
    }

    const cards = CURRENT_SLOT_PROFESSIONALS.map(professional => {
        const id = parseInt(professional.id, 10);
        const selected = id === CURRENT_SLOT_SELECTED_PROFESSIONAL_ID;
        const name = escapeHtml(professional.display_name || 'Sin nombre');
        const photo = professional.display_photo_path
            ? `<img class="patient-professional-card-avatar" src="${escapeHtml(assetUrl(professional.display_photo_path))}" alt="${name}">`
            : '<span class="patient-professional-card-avatar patient-professional-card-avatar-empty"><i class="bi bi-person"></i></span>';
        return `
            <button type="button" class="patient-professional-card ${selected ? 'is-selected' : ''}" data-professional-id="${id}" aria-pressed="${selected ? 'true' : 'false'}">
                ${photo}
                <span class="patient-professional-card-body">
                    <span class="patient-professional-card-name">${name}</span>
                    ${professionalDeliveryPills(professional)}
                </span>
            </button>
        `;
    }).join('');

    $container.html(`
        <div class="patient-professional-choice-title mb-1">Profesionales disponibles</div>
        <div class="patient-professional-choice-help mb-2">Elige con qui&eacute;n quieres tu consulta para este horario.</div>
        <div class="slot-professional-card-grid">${cards}</div>
    `).removeClass('d-none');
}

function loadAvailableProfessionalsForSlot(date, time) {
    const $container = $('#patientSlotProfessionalSelect');
    $container.html('<div class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm me-2"></span>Buscando profesionales disponibles...</div>').removeClass('d-none');
    $('#btn-confirm-action').prop('disabled', true);
    ACTIVE_SERVICE_OPTIONS = [];
    CURRENT_SLOT_PROFESSIONALS = [];
    CURRENT_SLOT_SELECTED_PROFESSIONAL_ID = 0;
    CURRENT_BOOKING_PROFESSIONAL_CONTEXT = null;
    renderBookingServiceOptions();
    refreshBookingBonusNotice();

    $.ajax({
        url: 'api/appointments.php?action=available_professionals_for_slot',
        method: 'GET',
        dataType: 'json',
        data: { date, time },
        success: function (res) {
            if (!res.success) {
                $container.html(`<div class="alert alert-danger mb-0">${escapeHtml(res.error || 'No se pudo cargar la disponibilidad.')}</div>`);
                return;
            }
            CURRENT_SLOT_PROFESSIONALS = Array.isArray(res.professionals) ? res.professionals : [];
            if (CURRENT_SLOT_PROFESSIONALS.length) {
                setSlotBookingProfessional(CURRENT_SLOT_PROFESSIONALS[0].id);
            } else {
                renderSlotProfessionalCards();
            }
        },
        error: function () {
            $container.html('<div class="alert alert-danger mb-0">Error al cargar profesionales disponibles.</div>');
        },
        complete: function () {
            $('#btn-confirm-action').prop('disabled', CURRENT_SLOT_PROFESSIONALS.length === 0);
        }
    });
}

function selectedPatientProfessionalRequestData() {
    if (IS_ADMIN || !CURRENT_PATIENT_SELECTED_PROFESSIONAL_ID) {
        return {};
    }
    return { professional_id: CURRENT_PATIENT_SELECTED_PROFESSIONAL_ID };
}

function renderPatientProfessionalChoice(professionals, context, mode) {
    const $choice = $('#patient-professional-choice');
    CURRENT_PATIENT_BOOKING_MODE = mode || '';
    if (!$choice.length || IS_ADMIN || mode !== 'professional_first' || !Array.isArray(professionals) || !professionals.length) {
        $choice.addClass('d-none').empty();
        CURRENT_PATIENT_PROFESSIONALS = [];
        CURRENT_PATIENT_SELECTED_PROFESSIONAL_ID = 0;
        return;
    }

    CURRENT_PATIENT_PROFESSIONALS = professionals;
    const contextId = context && context.id ? parseInt(context.id, 10) : 0;
    if (!CURRENT_PATIENT_SELECTED_PROFESSIONAL_ID || !professionals.some(professional => parseInt(professional.id, 10) === CURRENT_PATIENT_SELECTED_PROFESSIONAL_ID)) {
        CURRENT_PATIENT_SELECTED_PROFESSIONAL_ID = contextId || parseInt(professionals[0].id, 10);
    }

    const cards = professionals.map(professional => {
        const id = parseInt(professional.id, 10);
        const selected = id === CURRENT_PATIENT_SELECTED_PROFESSIONAL_ID;
        const name = escapeHtml(professional.display_name || 'Sin nombre');
        const photo = professional.display_photo_path
            ? `<img class="patient-professional-card-avatar" src="${escapeHtml(assetUrl(professional.display_photo_path))}" alt="${name}">`
            : '<span class="patient-professional-card-avatar patient-professional-card-avatar-empty"><i class="bi bi-person"></i></span>';
        return `
            <button type="button" class="patient-professional-card ${selected ? 'is-selected' : ''}" data-professional-id="${id}" aria-pressed="${selected ? 'true' : 'false'}">
                ${photo}
                <span class="patient-professional-card-body">
                    <span class="patient-professional-card-name">${name}</span>
                    ${professionalDeliveryPills(professional)}
                </span>
            </button>
        `;
    }).join('');

    $choice.html(`
        <div class="patient-professional-choice-title">Elige con qui&eacute;n quieres tu cita</div>
        <div class="patient-professional-choice-help">Elige con qui&eacute;n quieres tu consulta. Despu&eacute;s podr&aacute;s buscar la fecha y hora.</div>
        <div class="patient-professional-card-grid">
            ${cards}
        </div>
    `).removeClass('d-none');
}

function shouldChooseProfessionalInSlot() {
    return !IS_ADMIN
        && CURRENT_PATIENT_BOOKING_MODE === 'day_first'
        && !CURRENT_PATIENT_PROFESSIONAL_CONTEXT;
}

function loadCalendar(startDateStr) {
    $('#calendar-container').html('<div class="text-center text-muted py-5"><div class="spinner-border text-secondary" role="status"></div><br>Cargando...</div>');

    return fetchWeekCalendar(startDateStr).done(function (res) {
        applyWeekCalendarResponse(startDateStr, res);
    });
}

function fetchWeekCalendar(startDateStr) {
    return $.ajax({
        url: 'api/appointments.php?action=get_week',
        data: { start_date: startDateStr, ...selectedPatientProfessionalRequestData() },
        method: 'GET',
        dataType: 'json'
    });
}

function applyWeekCalendarResponse(startDateStr, res) {
    if (res && res.success) {
        PAYMENT_SETTINGS = res.payment_settings || PAYMENT_SETTINGS;
        setPatientProfessionalContext(res.professional_context || null);
        renderPatientProfessionalChoice(res.professionals || [], res.professional_context || null, res.new_patient_booking_mode || '');
        if (Array.isArray(res.service_options)) {
            ACTIVE_SERVICE_OPTIONS = res.service_options;
        }
        togglePatientBonusActions();
        drawCalendar(startDateStr, res.appointments, res.closed_days);
    }
}

function loadMonthCalendar(monthStr) {
    $('#calendar-container').html('<div class="text-center text-muted py-5"><div class="spinner-border text-secondary" role="status"></div><br>Cargando mes...</div>');

    return fetchMonthCalendar(monthStr).done(function (res) {
        applyMonthCalendarResponse(res);
    });
}

function fetchMonthCalendar(monthStr) {
    return $.ajax({
        url: 'api/appointments.php?action=get_month',
        data: { month: monthStr, ...selectedPatientProfessionalRequestData() },
        method: 'GET',
        dataType: 'json'
    });
}

function applyMonthCalendarResponse(res) {
    if (res && res.success) {
        PAYMENT_SETTINGS = res.payment_settings || PAYMENT_SETTINGS;
        setPatientProfessionalContext(res.professional_context || null);
        renderPatientProfessionalChoice(res.professionals || [], res.professional_context || null, res.new_patient_booking_mode || '');
        if (Array.isArray(res.service_options)) {
            ACTIVE_SERVICE_OPTIONS = res.service_options;
        }
        togglePatientBonusActions();
        currentMonthData = res;
        selectedMonthDay = selectedMonthDay || firstAvailableMonthDay(res.month, res.appointments, res.closed_days);
        drawMonthCalendar(res.month, res.appointments || {}, res.closed_days || {});
    }
}

function consumeCalendarInitialAnimationClass() {
    if (!calendarInitialAnimationPending) {
        return '';
    }
    calendarInitialAnimationPending = false;
    return ' dashboard-enter';
}

function drawCalendar(startDateStr, appointmentsMap, closedDays) {
    const activeDays = getActiveWeekdays();
    let startD = new Date(startDateStr);
    const lastActiveDay = activeDays.length ? Math.max(...activeDays) : 5;
    const endD = new Date(startD);
    endD.setDate(startD.getDate() + (lastActiveDay - 1));
    const weekTitle = `${formatCompactDate(startD)} - ${formatCompactDate(endD)}`;
    let html = `
        ${dashboardCalendarModeSwitcherHtml()}
        <div class="week-calendar-nav${consumeCalendarInitialAnimationClass()}">
            <button class="btn btn-light calendar-nav-icon" id="btn-prev-week" type="button" aria-label="Semana anterior" title="Semana anterior">
                <i class="bi bi-chevron-left"></i>
            </button>
            <div class="week-calendar-title">${escapeHtml(weekTitle)}</div>
            <button class="btn btn-light calendar-nav-icon" id="btn-next-week" type="button" aria-label="Semana siguiente" title="Semana siguiente">
                <i class="bi bi-chevron-right"></i>
            </button>
        </div>
        <div class="calendar-grid" style="--calendar-days: ${activeDays.length};">
    `;

    const daysArr = {
        1: 'Lunes',
        2: 'Martes',
        3: 'Miércoles',
        4: 'Jueves',
        5: 'Viernes',
        6: 'Sábado'
    };

    activeDays.forEach(dayNumber => {
        let currentDay = new Date(startD);
        currentDay.setDate(currentDay.getDate() + (dayNumber - 1));
        let dateStr = formatDate(currentDay);
        let displayDate = ('0' + currentDay.getDate()).slice(-2) + '/' + ('0' + (currentDay.getMonth() + 1)).slice(-2);

        let isClosed = closedDays[dateStr] !== undefined;
        let closedReason = isClosed ? (IS_ADMIN ? closedDays[dateStr] : 'No disponible') : '';

        html += `<div class="calendar-day">
                    <div class="day-header">${daysArr[dayNumber]}<br><small class="text-muted">${displayDate}</small></div>`;

        if (isClosed) {
            html += `<div class="alert alert-danger text-center p-2 mb-0" style="font-size:0.9em">${closedReason}</div>`;
        } else {
            getScheduleItems().forEach(item => {
                if (item.type === 'break') {
                    html += `<div class="text-center text-muted" style="font-size:0.8em; margin: 5px 0;">Descanso</div>`;
                    return;
                }
                html += renderSlot(dateStr, item.time, appointmentsMap[dateStr]);
            });
        }

        html += `</div>`;
    });

    html += '</div>';
    $('#calendar-container').html(html);
}

function formatCompactDate(date) {
    return `${String(date.getDate()).padStart(2, '0')}/${String(date.getMonth() + 1).padStart(2, '0')}/${date.getFullYear()}`;
}

function dashboardCalendarModeSwitcherHtml() {
    if (!IS_ADMIN) {
        return '';
    }
    return `
        <div class="dashboard-calendar-mode-switcher mb-3">
            <div class="btn-group" role="group" aria-label="Modo de agenda">
                <button class="btn btn-outline-primary btn-dashboard-calendar-mode${currentCalendarView === 'month' ? ' active btn-primary' : ''}" type="button" data-dashboard-calendar-view="month">
                    <i class="bi bi-calendar3"></i> Mensual
                </button>
                <button class="btn btn-outline-primary btn-dashboard-calendar-mode${currentCalendarView === 'week' ? ' active btn-primary' : ''}" type="button" data-dashboard-calendar-view="week">
                    <i class="bi bi-calendar-week"></i> Semanal
                </button>
            </div>
        </div>
    `;
}

function drawMonthCalendar(monthStr, appointmentsMap, closedDays) {
    const monthDate = new Date(`${monthStr}T00:00:00`);
    const year = monthDate.getFullYear();
    const month = monthDate.getMonth();
    const monthName = monthDate.toLocaleDateString('es-ES', { month: 'long', year: 'numeric' });
    const first = new Date(year, month, 1);
    const firstGrid = new Date(first);
    firstGrid.setDate(first.getDate() - ((first.getDay() + 6) % 7));

    let html = `
        ${dashboardCalendarModeSwitcherHtml()}
        <div class="month-calendar-layout${consumeCalendarInitialAnimationClass()}">
            <div class="month-calendar-panel">
                <div class="month-calendar-header">
                    <button class="btn btn-light calendar-nav-icon" id="btn-prev-week" type="button" aria-label="Mes anterior" title="Mes anterior">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <div class="month-calendar-title">${escapeHtml(monthName)}</div>
                    <button class="btn btn-light calendar-nav-icon" id="btn-next-week" type="button" aria-label="Mes siguiente" title="Mes siguiente">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
                <div class="month-weekdays">
                    <span>Lun</span><span>Mar</span><span>Mi&eacute;</span><span>Jue</span><span>Vie</span><span>S&aacute;b</span><span>Dom</span>
                </div>
                <div class="month-grid">
    `;

    for (let i = 0; i < 42; i++) {
        const day = new Date(firstGrid);
        day.setDate(firstGrid.getDate() + i);
        const dateStr = formatDate(day);
        const inMonth = day.getMonth() === month;
        const status = monthDayStatus(dateStr, appointmentsMap, closedDays, inMonth);
        const selected = selectedMonthDay === dateStr ? ' selected' : '';
        const clickable = inMonth ? `onclick="selectMonthDay('${dateStr}')"` : '';
        const subtitle = status.available ? '' : compactMonthDayLabel(status.label);
        const appointmentCount = monthDayAppointmentCount(appointmentsMap[dateStr]);
        const appointmentBadge = IS_ADMIN && appointmentCount > 0
            ? `<b class="month-day-appointment-badge" title="${appointmentCount} ${appointmentCount === 1 ? 'cita' : 'citas'}">${appointmentCount}</b>`
            : '';
        html += `
            <button type="button" class="month-day ${status.className}${selected}" ${clickable}>
                <span>${day.getDate()}</span>
                <small>${subtitle}</small>
                ${appointmentBadge}
            </button>
        `;
    }

    html += `
                </div>
            </div>
            <div class="month-day-panel ${selectedMonthDayAnimationClass}" id="month-day-panel">
                ${renderSelectedMonthDayPanel(appointmentsMap, closedDays)}
            </div>
        </div>
    `;

    $('#calendar-container').html(html);
    selectedMonthDayAnimationClass = '';
}

function monthDayAppointmentCount(dayApps) {
    if (!dayApps) {
        return 0;
    }
    const ids = new Set();
    Object.values(dayApps).forEach(app => {
        if (app && app.id) {
            ids.add(String(app.id));
        }
    });
    return ids.size;
}

function compactMonthDayLabel(label) {
    return label === 'No disponible' ? 'No disp.' : label;
}

function monthDayStatus(dateStr, appointmentsMap, closedDays, inMonth = true) {
    if (!inMonth) {
        return { available: false, freeSlots: 0, label: '', className: 'outside-month' };
    }
    if (closedDays[dateStr]) {
        return { available: false, freeSlots: 0, label: IS_ADMIN ? closedDays[dateStr] : 'No disponible', className: 'closed-day' };
    }
    const day = new Date(`${dateStr}T00:00:00`);
    const jsDay = day.getDay();
    const dayNumber = jsDay === 0 ? 7 : jsDay;
    if (!getActiveWeekdays().includes(dayNumber)) {
        return { available: false, freeSlots: 0, label: 'No disponible', className: 'disabled-day' };
    }
    if (isOutsideAllowedBookingWindow(dateStr)) {
        return { available: false, freeSlots: 0, label: 'No disponible', className: 'disabled-day' };
    }

    let freeSlots = 0;
    getScheduleItems().forEach(item => {
        if (item.type === 'break') return;
        const app = appointmentsMap[dateStr] ? appointmentsMap[dateStr][item.time] : null;
        const overlap = !app ? findOverlappingAppointment(appointmentsMap[dateStr], item.time) : null;
        if (!app && !overlap && !isPastSlot(dateStr, item.time)) {
            freeSlots++;
        }
    });

    if (freeSlots <= 0) {
        return { available: false, freeSlots: 0, label: 'Completo', className: 'full-day' };
    }
    return { available: true, freeSlots, label: 'Disponible', className: 'available-day' };
}

function firstAvailableMonthDay(monthStr, appointmentsMap, closedDays) {
    const monthDate = new Date(`${monthStr}T00:00:00`);
    const year = monthDate.getFullYear();
    const month = monthDate.getMonth();
    const lastDay = new Date(year, month + 1, 0).getDate();
    for (let day = 1; day <= lastDay; day++) {
        const dateStr = formatDate(new Date(year, month, day));
        if (monthDayStatus(dateStr, appointmentsMap || {}, closedDays || {}, true).available) {
            return dateStr;
        }
    }
    return null;
}

function selectMonthDay(dateStr) {
    const previousDay = selectedMonthDay;
    if (previousDay === dateStr) {
        return;
    }
    if (previousDay && previousDay !== dateStr) {
        selectedMonthDayAnimationClass = dateStr > previousDay ? 'month-day-panel-enter-right' : 'month-day-panel-enter-left';
    } else {
        selectedMonthDayAnimationClass = '';
    }
    selectedMonthDay = dateStr;
    if (!currentMonthData) return;
    drawMonthCalendar(currentMonthData.month, currentMonthData.appointments || {}, currentMonthData.closed_days || {});
}

function renderSelectedMonthDayPanel(appointmentsMap, closedDays) {
    if (!selectedMonthDay) {
        return '<div class="text-muted text-center py-5">No hay d&iacute;as con huecos disponibles este mes.</div>';
    }
    const status = monthDayStatus(selectedMonthDay, appointmentsMap, closedDays, true);
    const headerText = status.available ? `${status.freeSlots || 0} huecos libres` : 'D&iacute;a no disponible';
    let html = `<div class="month-day-panel-header"><h5>${formatDisplayDateWithWeekday(selectedMonthDay)}</h5><span>${headerText}</span></div>`;
    if (!status.available) {
        const reason = closedDays[selectedMonthDay] && IS_ADMIN ? closedDays[selectedMonthDay] : (status.label || 'No disponible');
        return html + `<div class="alert alert-secondary text-center py-4 mb-0"><strong>D&iacute;a no disponible</strong><br><span>${escapeHtml(reason)}</span></div>`;
    }
    getScheduleItems().forEach(item => {
        if (item.type === 'break') {
            html += '<div class="text-center text-muted month-break">Descanso</div>';
            return;
        }
        html += renderSlot(selectedMonthDay, item.time, appointmentsMap[selectedMonthDay]);
    });
    return html;
}

function getActiveWeekdays() {
    const raw = String(PAYMENT_SETTINGS.available_weekdays || '1,2,3,4,5');
    const days = raw.split(',')
        .map(day => parseInt(day, 10))
        .filter(day => day >= 1 && day <= 6);
    const uniqueDays = [...new Set(days)].sort((a, b) => a - b);
    return uniqueDays.length ? uniqueDays : [1, 2, 3, 4, 5];
}

function timeToMinutes(time) {
    const parts = String(time || '').slice(0, 5).split(':');
    if (parts.length !== 2) return 0;
    return (parseInt(parts[0], 10) * 60) + parseInt(parts[1], 10);
}

function minutesToTime(minutes) {
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}

function displayDurationMinutes(durationMinutes) {
    const duration = parseInt(durationMinutes || 60, 10) || 60;
    if (PAYMENT_SETTINGS.display_effective_duration_enabled != 1) {
        return duration;
    }
    const offset = Math.max(0, Math.min(30, parseInt(PAYMENT_SETTINGS.display_duration_offset_minutes || 5, 10) || 0));
    return Math.max(1, duration - offset);
}

function displayAppointmentTimeRange(startTime, durationMinutes, endTime = '') {
    const start = String(startTime || '').slice(0, 5);
    if (!start) {
        return '';
    }
    const displayEnd = minutesToTime(timeToMinutes(start) + displayDurationMinutes(durationMinutes));
    return `${start} - ${displayEnd}`;
}

function displayAppointmentDurationLabel(durationMinutes) {
    return `${displayDurationMinutes(durationMinutes)} min`;
}

function displayAppointmentServiceLabel(app = {}) {
    const label = String(app.service_label || '');
    if (!label) {
        return '';
    }
    if (PAYMENT_SETTINGS.display_effective_duration_enabled != 1) {
        return label;
    }
    return label.replace(/\((\d+)\s*min\)/i, (_match, minutes) => `(${displayDurationMinutes(app.duration_minutes || minutes)} min)`);
}

function activeScheduleDurations(settings = PAYMENT_SETTINGS) {
    const durations = String(settings.available_session_durations || '60')
        .split(',')
        .map(duration => parseInt(duration, 10))
        .filter(duration => Number.isFinite(duration) && duration > 0 && duration <= 480);
    return durations.length ? [...new Set(durations)].sort((a, b) => a - b) : [60];
}

function activeScheduleSlotStep(settings = PAYMENT_SETTINGS) {
    return Math.max(5, Math.min(...activeScheduleDurations(settings)));
}

function getScheduleItems() {
    const start = timeToMinutes(PAYMENT_SETTINGS.appointment_start_time || '10:00');
    const end = timeToMinutes(PAYMENT_SETTINGS.appointment_end_time || '19:00');
    const hasBreak = PAYMENT_SETTINGS.break_start_time && PAYMENT_SETTINGS.break_end_time;
    const breakStart = hasBreak ? timeToMinutes(PAYMENT_SETTINGS.break_start_time) : null;
    const breakEnd = hasBreak ? timeToMinutes(PAYMENT_SETTINGS.break_end_time) : null;
    const items = [];
    let breakAdded = false;

    const slotStep = activeScheduleSlotStep();
    for (let minutes = start; minutes <= end; minutes += slotStep) {
        if (hasBreak && minutes >= breakStart && minutes < breakEnd) {
            if (!breakAdded) {
                items.push({ type: 'break' });
                breakAdded = true;
            }
            continue;
        }
        items.push({ type: 'slot', time: minutesToTime(minutes) });
    }

    return items;
}

function selectedSlotCanFitDuration(durationMinutes) {
    const slotTime = $('#modalTime').val();
    if (!slotTime) {
        return true;
    }
    const slotStart = timeToMinutes(slotTime);
    const slotEnd = slotStart + parseInt(durationMinutes || 60, 10);
    const lastStart = timeToMinutes(PAYMENT_SETTINGS.appointment_end_time || '19:00');
    const dayEnd = lastStart + Math.max(...activeScheduleDurations());
    if (slotEnd > dayEnd) {
        return false;
    }

    const hasBreak = PAYMENT_SETTINGS.break_start_time && PAYMENT_SETTINGS.break_end_time;
    if (hasBreak) {
        const breakStart = timeToMinutes(PAYMENT_SETTINGS.break_start_time);
        const breakEnd = timeToMinutes(PAYMENT_SETTINGS.break_end_time);
        if (slotStart < breakEnd && slotEnd > breakStart) {
            return false;
        }
    }

    return true;
}

function selectedSlotIsAllowedForCurrentSettings() {
    const slotDate = $('#modalDate').val();
    const slotTime = $('#modalTime').val();
    if (!slotDate || !slotTime) {
        return true;
    }
    const day = new Date(`${slotDate}T00:00:00`);
    const dayNumber = day.getDay() === 0 ? 7 : day.getDay();
    if (!getActiveWeekdays().includes(dayNumber)) {
        return false;
    }
    return getScheduleItems()
        .filter(item => item.type === 'slot')
        .some(item => item.time === String(slotTime).slice(0, 5));
}

function findOverlappingAppointment(dayApps, timeStr) {
    if (!dayApps) {
        return null;
    }
    const slotStart = timeToMinutes(timeStr);
    for (const app of Object.values(dayApps)) {
        const appStart = timeToMinutes(app.time || '');
        const duration = parseInt(app.duration_minutes || 60, 10);
        if (app.time !== timeStr && slotStart >= appStart && slotStart < appStart + duration) {
            return app;
        }
    }
    return null;
}

function renderSlot(dateStr, timeStr, dayApps) {
    let app = dayApps ? dayApps[timeStr] : null;
    let overlapApp = !app ? findOverlappingAppointment(dayApps, timeStr) : null;
    let isPast = isPastSlot(dateStr, timeStr);
    let isOutsideBookingWindow = isOutsideAllowedBookingWindow(dateStr);
    let cls = 'available';
    let text = timeStr;
    let isBooked = false;
    let onClick = `openModal('${dateStr}', '${timeStr}', 'available')`;

    // Convert current user ID if available in session? We rely on UI vs API limits mostly.
    if (overlapApp) {
        cls = 'booked';
        text = `${timeStr} - Ocupado`;
        onClick = `alert('Este horario no esta disponible')`;
    } else if (app) {
        let paymentBadge = getPaymentBadge(app);
        let consultationBadge = getConsultationBadge(app.consultation_type);
        let serviceBadge = getServiceBadge(app);
        let appDisplayRange = displayAppointmentTimeRange(timeStr, app.duration_minutes || 60);
        let cancelPayloadArg = encodeURIComponent(JSON.stringify({
            id: app.id || null,
            name: app.name || '',
            email: app.email || '',
            phone: app.phone || '',
            payment_status: app.payment_status || '',
            payment_method: app.payment_method || '',
            patient_bonus_id: app.patient_bonus_id || null
        }));
        let payPriceArg = escapeJsString(app.price || '');
        let payLabelArg = escapeJsString(app.service_label || '');
        let payButton = canPayAppointment(app)
            ? `<button class="btn btn-success slot-action-btn" onclick="event.stopPropagation(); openModal('${dateStr}', '${timeStr}', 'pay_own', '${app.id}', '${payPriceArg}', '${payLabelArg}');" title="Pagar cita"><i class="bi bi-credit-card"></i></button>`
            : '';
        if (IS_ADMIN) {
            const patientSingular = sectorLabel('patient', 'singular', 'paciente');
            const patientButton = app.user_id
                ? `<button class="btn btn-outline-secondary slot-action-btn" onclick="event.stopPropagation(); openPatientEditorById(${parseInt(app.user_id, 10)}, this);" title="Datos del ${escapeHtml(patientSingular)}"><i class="bi bi-person-lines-fill"></i></button>`
                : '';
            cls = 'booked';
            text = `
                    <div class="slot-content">
                        <div class="slot-main-row">
                        <span class="slot-label">${appDisplayRange} - ${app.name}</span>
                    </div>
                    <div class="slot-meta-row">
                        <span class="slot-badges">${serviceBadge}${consultationBadge}${paymentBadge}</span>
                        <span class="slot-actions">${patientButton}<button class="btn btn-danger slot-action-btn" onclick="event.stopPropagation(); openModal('${dateStr}', '${timeStr}', 'cancel_admin', '${cancelPayloadArg}');" title="Cancelar cita"><i class="bi bi-trash"></i></button></span>
                    </div>
                </div>
            `;
            onClick = app.id ? `openAppointmentPaymentModal(${parseInt(app.id, 10)})` : ``;
        } else {
            if (app.is_own) {
                cls = 'booked-by-me';
                text = `
                    <div class="slot-content">
                        <div class="slot-main-row">
                            <span class="slot-label">${appDisplayRange} - Tu reserva</span>
                        </div>
                        <div class="slot-meta-row">
                            <span class="slot-badges">${serviceBadge}${consultationBadge}${paymentBadge}</span>
                            <span class="slot-actions">${payButton}<button class="btn btn-danger slot-action-btn" onclick="event.stopPropagation(); openModal('${dateStr}', '${timeStr}', 'cancel_own', '${cancelPayloadArg}');" title="Cancelar cita"><i class="bi bi-trash"></i></button></span>
                        </div>
                    </div>
                `;
                onClick = ``; // do nothing on block click
            } else {
                cls = 'booked';
                text = `${timeStr} - No disponible`;
                onClick = `alert('Este horario no está disponible')`;
            }
        }
    }

    if ((isPast || isOutsideBookingWindow) && !(app && app.is_own && !IS_ADMIN)) {
        cls += isPast ? ' past' : ' unavailable-window';
        onClick = '';
    }

    return `<div class="slot ${cls}" ${onClick ? `onclick="${onClick}"` : ''}>${text}</div>`;
}

function loadQuickAppointmentsSummary(animate = !quickAppointmentsSummaryRendered) {
    const $wrap = $('#quick-appointments-summary');
    if (!$wrap.length || !IS_ADMIN) {
        return $.Deferred().resolve().promise();
    }
    if (quickAppointmentsSummaryLoading) {
        return quickAppointmentsSummaryRequest || $.Deferred().resolve().promise();
    }
    quickAppointmentsSummaryLoading = true;
    const deferred = $.Deferred();
    quickAppointmentsSummaryRequest = deferred.promise();

    $.ajax({
        url: 'api/admin.php?action=quick_appointments',
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                $wrap.addClass('d-none').empty();
                return;
            }
            renderQuickAppointmentsSummary(res.current || null, res.next || null, animate);
        },
        error: function () {
            $wrap.addClass('d-none').empty();
        },
        complete: function () {
            quickAppointmentsSummaryLoading = false;
            quickAppointmentsSummaryRequest = null;
            deferred.resolve();
        }
    });

    return deferred.promise();
}

function refreshQuickAppointmentSummaries() {
    if (IS_ADMIN) {
        loadQuickAppointmentsSummary(false);
        return;
    }
    if ($('#patient-quick-appointment-summary').length) {
        loadPatientPortalSummary();
    }
}

function startQuickAppointmentsAutoRefresh() {
    if (quickAppointmentsRefreshTimer) {
        clearInterval(quickAppointmentsRefreshTimer);
    }
    quickAppointmentsRefreshTimer = setInterval(refreshQuickAppointmentSummaries, QUICK_APPOINTMENTS_REFRESH_INTERVAL_MS);
}

function renderQuickAppointmentsSummary(current, next, animate = false) {
    const $wrap = $('#quick-appointments-summary');
    if (!$wrap.length) {
        return;
    }
    const normalized = normalizeQuickAppointmentsForNow(current, next);
    current = normalized.current;
    next = normalized.next;
    const cards = [];
    if (current) {
        cards.push(quickAppointmentCardHtml(current, 'Cita en curso', 'current'));
    }
    if (next) {
        cards.push(quickAppointmentCardHtml(next, current ? 'Siguiente cita' : 'Próxima cita', 'next'));
    }
    if (!cards.length) {
        $wrap.addClass('d-none').empty();
        return;
    }
    $wrap
        .removeClass('d-none')
        .html(`<div class="row g-3 mb-4${animate ? ' dashboard-enter' : ''}">${cards.join('')}</div>`);
    quickAppointmentsSummaryRendered = true;
}

function quickAppointmentCardHtml(app, title, type) {
    const start = app.appointment_time || '';
    const end = app.appointment_end_time || '';
    const timeText = displayAppointmentTimeRange(start, app.duration_minutes || 60, end);
    const dateTimeText = type === 'current'
        ? timeText
        : quickAppointmentTimingLabel(app);
    const contactText = quickAppointmentContactText(app);
    const contactActions = patientContactActionsHtml(app.patient_phone, '');
    const payment = adminPaymentLabel(app);
    const consultation = quickAppointmentConsultationBadge(app.consultation_type);
    const patientName = app.patient_name || sectorLabel('patient', 'titleSingular', 'Paciente');
    const mainTitle = `${dateTimeText} con ${patientName}`;
    const openButtonLabel = type === 'current' ? 'Abrir sesi&oacute;n' : 'Preparar sesi&oacute;n';
    return `
        <div class="col-12 col-lg-6">
            <article class="quick-appointment-card quick-appointment-${type}">
                <div class="quick-appointment-topline">
                    <span>${escapeHtml(title)}</span>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openAppointmentPaymentModal(${parseInt(app.id, 10)})">
                        <i class="bi bi-box-arrow-up-right"></i> ${openButtonLabel}
                    </button>
                </div>
                <div class="quick-appointment-body">
                    <div>
                        <h5>${escapeHtml(mainTitle)}</h5>
                        ${(contactText || contactActions) ? `
                            <div class="quick-appointment-contact-row">
                                ${contactText ? `<div class="quick-appointment-contact">${escapeHtml(contactText)}</div>` : '<div></div>'}
                                ${contactActions ? `<div class="quick-appointment-contact-actions">${contactActions}</div>` : ''}
                            </div>
                        ` : ''}
                    </div>
                </div>
                <div class="quick-appointment-meta">
                    <div class="quick-appointment-meta-main">
                        <span>${escapeHtml(displayAppointmentServiceLabel(app))}</span>
                        ${consultation}
                    </div>
                    <div class="quick-appointment-badges">
                        ${payment}
                    </div>
                </div>
            </article>
        </div>
    `;
}

function quickAppointmentStartDate(app = {}) {
    const dateStr = app.appointment_date || '';
    const timeStr = normalizeAppointmentClockTime(app.appointment_time || '');
    if (!dateStr || !timeStr) {
        return null;
    }
    const start = new Date(`${dateStr}T${timeStr}:00`);
    return Number.isNaN(start.getTime()) ? null : start;
}

function quickAppointmentEndDate(app = {}) {
    const start = quickAppointmentStartDate(app);
    if (!start) {
        return null;
    }
    const duration = parseInt(app.duration_minutes || 60, 10) || 60;
    return new Date(start.getTime() + duration * 60000);
}

function isQuickAppointmentInProgress(app = {}, now = new Date()) {
    const start = quickAppointmentStartDate(app);
    const end = quickAppointmentEndDate(app);
    return Boolean(start && end && start <= now && end > now);
}

function isQuickAppointmentFuture(app = {}, now = new Date()) {
    const start = quickAppointmentStartDate(app);
    return Boolean(start && start > now);
}

function normalizeQuickAppointmentsForNow(current, next) {
    const now = new Date();
    let normalizedCurrent = current || null;
    let normalizedNext = next || null;

    if (normalizedCurrent && !isQuickAppointmentInProgress(normalizedCurrent, now)) {
        if (isQuickAppointmentFuture(normalizedCurrent, now) && !normalizedNext) {
            normalizedNext = normalizedCurrent;
        }
        normalizedCurrent = null;
    }

    if (!normalizedCurrent && normalizedNext && isQuickAppointmentInProgress(normalizedNext, now)) {
        normalizedCurrent = normalizedNext;
        normalizedNext = null;
    }

    return {
        current: normalizedCurrent,
        next: normalizedNext
    };
}

function normalizeAppointmentClockTime(value) {
    const raw = String(value || '').trim();
    const match = raw.match(/^(\d{1,2}):(\d{2})/);
    if (!match) {
        return '';
    }
    return `${String(match[1]).padStart(2, '0')}:${match[2]}`;
}

function quickAppointmentContactText(app = {}) {
    const email = String(app.patient_email || '').trim();
    const phone = String(app.patient_phone || '').trim();
    if (email && phone) {
        return `${email} (${phone})`;
    }
    return email || phone;
}

function quickAppointmentContactHtml(app = {}) {
    const email = String(app.patient_email || '').trim();
    const phone = String(app.patient_phone || '').trim();
    const text = email && phone ? `${escapeHtml(email)} (${escapeHtml(phone)})` : escapeHtml(email || phone);
    if (!text) {
        return '';
    }
    return `${text}${patientContactActionsHtml(phone, 'ms-2')}`;
}

function patientContactSummaryHtml(email, phone) {
    const emailText = String(email || '').trim();
    const phoneText = String(phone || '').trim();
    const parts = [];
    if (emailText) {
        parts.push(escapeHtml(emailText));
    }
    if (phoneText) {
        parts.push(`${emailText ? '(' : ''}${escapeHtml(phoneText)}${emailText ? ')' : ''}${patientContactActionsHtml(phoneText, 'ms-1')}`);
    }
    return parts.join(' ');
}

function patientContactActionsHtml(phone, extraClass = '') {
    const rawPhone = String(phone || '').trim();
    if (!rawPhone) {
        return '';
    }
    const telHref = rawPhone.replace(/"/g, '');
    const whatsappDigits = rawPhone.replace(/[^\d+]/g, '').replace(/^\+/, '');
    const whatsappButton = whatsappDigits.length >= 6
        ? `<a class="btn btn-outline-success btn-sm patient-contact-action${extraClass ? ` ${extraClass}` : ''}" href="https://wa.me/${escapeHtml(whatsappDigits)}" target="_blank" rel="noopener" title="WhatsApp" onclick="event.stopPropagation();"><i class="bi bi-whatsapp"></i></a>`
        : '';
    return `
        <span class="patient-contact-actions">
            <a class="btn btn-outline-secondary btn-sm patient-contact-action${extraClass ? ` ${extraClass}` : ''}" href="tel:${escapeHtml(telHref)}" title="Llamar" onclick="event.stopPropagation();"><i class="bi bi-telephone"></i></a>
            ${whatsappButton}
        </span>
    `;
}

function quickAppointmentConsultationBadge(type) {
    const isOnline = type === 'online';
    return `<span class="badge ${isOnline ? 'text-bg-info' : 'text-bg-light'}">${consultationTypeLabel(type)}</span>`;
}

function quickAppointmentDateLabel(dateStr) {
    if (!dateStr) {
        return '';
    }
    const today = formatDate(new Date());
    const tomorrowDate = new Date();
    tomorrowDate.setDate(tomorrowDate.getDate() + 1);
    const tomorrow = formatDate(tomorrowDate);
    if (dateStr === today) {
        return 'Hoy';
    }
    if (dateStr === tomorrow) {
        return 'Mañana';
    }
    return formatDisplayDate(dateStr);
}

function quickAppointmentTimingLabel(app = {}) {
    const dateStr = app.appointment_date || '';
    const timeStr = normalizeAppointmentClockTime(app.appointment_time || '');
    if (!dateStr || !timeStr) {
        return quickAppointmentDateLabel(dateStr);
    }
    const start = new Date(`${dateStr}T${timeStr}:00`);
    if (Number.isNaN(start.getTime())) {
        return quickAppointmentDateLabel(dateStr);
    }
    const now = new Date();
    const diffMinutes = Math.round((start - now) / 60000);
    if (diffMinutes > 0 && diffMinutes < 60) {
        return `En ${diffMinutes} minuto${diffMinutes === 1 ? '' : 's'}`;
    }
    const today = formatDate(now);
    const tomorrowDate = new Date(now);
    tomorrowDate.setDate(tomorrowDate.getDate() + 1);
    const tomorrow = formatDate(tomorrowDate);
    if (dateStr === today) {
        return `Hoy a las ${timeStr}`;
    }
    if (dateStr === tomorrow) {
        return `Mañana a las ${timeStr}`;
    }
    return formatDisplayDate(dateStr);
}

function loadPatientPortalSummary() {
    if (IS_ADMIN || !$('#patient-quick-appointment-summary').length) {
        return;
    }
    if (patientPortalSummaryLoading) {
        return;
    }
    patientPortalSummaryLoading = true;
    $.ajax({
        url: 'api/appointments.php?action=patient_portal_summary',
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                $('#patient-quick-appointment-summary').addClass('d-none');
                return;
            }
            CURRENT_PATIENT_PORTAL = {
                appointments: Array.isArray(res.appointments) ? res.appointments : [],
                tasks: Array.isArray(res.tasks) ? res.tasks : [],
                documents: Array.isArray(res.documents) ? res.documents : [],
                reports: Array.isArray(res.reports) ? res.reports : [],
                composition: res.composition || { enabled: false, current: null, history: [] }
            };
            if (res.payment_settings) {
                PAYMENT_SETTINGS = { ...PAYMENT_SETTINGS, ...res.payment_settings };
            }
            renderPatientQuickAppointment(CURRENT_PATIENT_PORTAL.appointments);
            renderPatientPortalSummary(CURRENT_PATIENT_PORTAL);
        },
        error: function () {
            $('#patient-quick-appointment-summary').addClass('d-none');
        },
        complete: function () {
            patientPortalSummaryLoading = false;
        }
    });
}

function renderPatientQuickAppointment(appointments) {
    const $wrap = $('#patient-quick-appointment-summary');
    if (!$wrap.length) return;
    const now = new Date();
    const upcoming = (Array.isArray(appointments) ? appointments : [])
        .filter(app => app.status === 'booked')
        .filter(app => new Date(`${app.appointment_date}T${app.appointment_time || '00:00'}:00`).getTime() + ((parseInt(app.duration_minutes || 60, 10) || 60) * 60000) >= now.getTime())
        .sort((a, b) => new Date(`${a.appointment_date}T${a.appointment_time || '00:00'}:00`) - new Date(`${b.appointment_date}T${b.appointment_time || '00:00'}:00`));
    const app = upcoming[0] || null;
    if (!app) {
        $wrap.addClass('d-none').empty();
        return;
    }
    const start = new Date(`${app.appointment_date}T${app.appointment_time || '00:00'}:00`);
    const duration = parseInt(app.duration_minutes || 60, 10) || 60;
    const end = new Date(start.getTime() + duration * 60000);
    const isCurrent = start <= now && end >= now;
    app.appointment_end_time = `${String(end.getHours()).padStart(2, '0')}:${String(end.getMinutes()).padStart(2, '0')}`;
    const timeText = displayAppointmentTimeRange(app.appointment_time || '', app.duration_minutes || 60, app.appointment_end_time || '');
    const dateTimeText = `${formatDisplayDate(app.appointment_date || '')}${timeText ? ` · ${timeText}` : ''}`;
    const consultation = quickAppointmentConsultationBadge(app.consultation_type);
    const title = isCurrent ? 'Cita en curso' : 'Próxima cita';
    const onlineLink = app.consultation_type === 'online' && app.online_session_url
        ? `<a class="btn btn-outline-primary btn-sm" href="${escapeHtml(app.online_session_url)}" target="_blank" rel="noopener"><i class="bi bi-camera-video"></i> Entrar</a>`
        : '';
    $wrap.removeClass('d-none').html(`
        <div class="row g-3 mb-4 dashboard-enter">
            <div class="col-12 col-lg-6">
                <article class="quick-appointment-card quick-appointment-next">
                    <div class="quick-appointment-topline">
                        <span>${escapeHtml(title)}</span>
                        <div class="d-flex gap-2 flex-wrap justify-content-end">
                            ${onlineLink}
                            <button type="button" class="btn btn-outline-danger btn-sm btn-cancel-patient-quick-appointment" data-appointment-id="${parseInt(app.id, 10)}">
                                <i class="bi bi-x-circle"></i> Cancelar
                            </button>
                        </div>
                    </div>
                    <div class="quick-appointment-body">
                        <div>
                            <h5>${escapeHtml(dateTimeText)}</h5>
                            <div class="quick-appointment-contact">${escapeHtml(app.professional_name || '')}</div>
                        </div>
                    </div>
                    <div class="quick-appointment-meta">
                        <div class="quick-appointment-meta-main">
                            <span>${escapeHtml(displayAppointmentServiceLabel(app) || 'Cita')}</span>
                        </div>
                        <div class="quick-appointment-badges">${consultation}</div>
                    </div>
                </article>
            </div>
        </div>
    `);
}

function renderPatientPortalSummary(data) {
    const appointments = Array.isArray(data.appointments) ? data.appointments : [];
    const tasks = Array.isArray(data.tasks) ? data.tasks : [];
    const documents = Array.isArray(data.documents) ? data.documents : [];
    const reports = Array.isArray(data.reports) ? data.reports : [];
    const composition = data.composition || { enabled: false, current: null, history: [] };
    $('#patient-portal-appointments').html(renderPatientPortalAppointments(appointments));
    $('#patient-portal-tasks').html(renderPatientPortalTasks(tasks));
    $('#patient-portal-documents').html(renderPatientPortalDocuments(documents));
    $('#patient-portal-reports').html(renderPatientPortalReports(reports));
    renderPatientPortalComposition(composition);
}

function openPatientPortalAppointmentsModal() {
    renderPatientPortalSummary(CURRENT_PATIENT_PORTAL);
    if (patientPortalAppointmentsModal) {
        patientPortalAppointmentsModal.show();
    }
}

function openPatientPortalTasksModal() {
    renderPatientPortalSummary(CURRENT_PATIENT_PORTAL);
    if (patientPortalTasksModal) {
        patientPortalTasksModal.show();
    }
}

function openPatientPortalDocumentsModal() {
    renderPatientPortalSummary(CURRENT_PATIENT_PORTAL);
    if (patientPortalDocumentsModal) {
        patientPortalDocumentsModal.show();
    }
}

function openPatientPortalReportsModal() {
    renderPatientPortalSummary(CURRENT_PATIENT_PORTAL);
    if (patientPortalReportsModal) {
        patientPortalReportsModal.show();
    }
}

function openPatientPortalCompositionModal() {
    renderPatientPortalSummary(CURRENT_PATIENT_PORTAL);
    if (patientPortalCompositionModal) {
        patientPortalCompositionModal.show();
    }
}

function renderPatientPortalAppointments(appointments) {
    if (!appointments.length) {
        return '<div class="text-muted small">Todavia no tienes citas registradas.</div>';
    }
    return appointments.slice(0, 8).map(app => {
        const statusLabel = {
            booked: 'Reservada',
            cancelled: 'Cancelada',
            completed: 'Realizada',
            no_show: 'No asistida'
        }[app.status] || app.status || '';
        const paymentStatus = patientPortalAppointmentPaymentHtml(app);
        return `
            <div class="patient-portal-item">
                <div class="patient-portal-item-main">
                    <strong>${escapeHtml(formatDisplayDate(app.appointment_date || ''))} · ${escapeHtml(displayAppointmentTimeRange(app.appointment_time || '', app.duration_minutes || 60))}</strong>
                    <div class="text-muted small">${escapeHtml(displayAppointmentServiceLabel(app))}${app.professional_name ? ` · ${escapeHtml(app.professional_name)}` : ''}</div>
                    ${paymentStatus.meta}
                </div>
                <div class="patient-portal-item-actions">
                    ${app.status === 'booked' ? '' : `<span class="badge ${app.status === 'cancelled' ? 'text-bg-danger' : 'text-bg-light'}">${escapeHtml(statusLabel)}</span>`}
                    ${paymentStatus.action}
                </div>
            </div>
        `;
    }).join('');
}

function patientPortalAppointmentPaymentHtml(app = {}) {
    const hasBonus = parseInt(app.patient_bonus_id || 0, 10) > 0 || app.payment_method === 'bonus';
    const paid = app.payment_status === 'paid';
    const failed = app.payment_status === 'failed';
    const canPayNow = app.status === 'booked'
        && !paid
        && !hasBonus
        && PAYMENT_SETTINGS.online_payment_enabled == 1;
    let label = 'Pendiente de pago';
    let className = 'text-bg-warning';
    let icon = 'bi-hourglass-split';
    if (hasBonus) {
        label = app.bonus_name ? `Bono usado: ${app.bonus_name}` : 'Pagada con bono';
        className = 'text-bg-success';
        icon = 'bi-card-checklist';
    } else if (paid) {
        const method = paymentMethodLabel(app.payment_method);
        label = method ? `Pagada · ${method}` : 'Pagada';
        className = 'text-bg-success';
        icon = 'bi-check2-circle';
    } else if (failed) {
        label = 'Pago fallido';
        className = 'text-bg-danger';
        icon = 'bi-exclamation-triangle';
    }
    return {
        meta: `<div class="patient-portal-payment small"><span class="badge ${className}"><i class="bi ${icon}"></i> ${escapeHtml(label)}</span></div>`,
        action: canPayNow
            ? `<button type="button" class="btn btn-success btn-sm btn-patient-portal-pay" data-appointment-id="${parseInt(app.id || 0, 10)}"><i class="bi bi-credit-card"></i> Pagar ahora</button>`
            : ''
    };
}

function renderPatientPortalTasks(tasks) {
    if (!tasks.length) {
        return '<div class="text-muted small">No tienes tareas visibles en el portal.</div>';
    }
    const statusEnabled = workPlanTaskStatusEnabled();
    return tasks.slice(0, 8).map(task => {
        const completed = task.status === 'completed';
        const priority = workPlanPriorityLabel(task.priority);
        const isFitnessExercise = Boolean(task.fitness_exercise_id);
        const statusBadge = statusEnabled
            ? `<span class="badge ${completed ? 'text-bg-success' : 'text-bg-warning'}">${completed ? 'Completada' : 'Pendiente'}</span>`
            : '';
        return `
            <div class="patient-portal-item ${completed ? 'is-completed' : ''}">
                <div class="patient-portal-item-main">
                    <div class="d-flex gap-1 flex-wrap mb-1">
                        <span class="badge ${priority.className}">${priority.label}</span>
                        ${statusBadge}
                        ${isFitnessExercise ? '<span class="badge text-bg-info">Ejercicio</span>' : ''}
                    </div>
                    <strong>${escapeHtml(task.title || '')}</strong>
                    ${task.description ? `<div class="text-muted small">${escapeHtml(task.description)}</div>` : ''}
                    ${statusEnabled && completed && task.completed_at ? `<div class="text-muted small">Completada el ${escapeHtml(formatDateTimeLabel(task.completed_at))}</div>` : ''}
                </div>
                ${isFitnessExercise ? `
                    <div class="patient-portal-item-actions">
                        <button type="button" class="btn btn-outline-primary btn-sm btn-patient-portal-exercise-detail" data-task-id="${parseInt(task.id || 0, 10)}">
                            <i class="bi bi-info-circle"></i> Detalle
                        </button>
                    </div>
                ` : ''}
            </div>
        `;
    }).join('');
}

function renderPatientPortalDocuments(documents) {
    const filter = CURRENT_PATIENT_PORTAL_DOCUMENTS_FILTER || 'all';
    const filtered = (Array.isArray(documents) ? documents : []).filter(doc => {
        if (filter === 'all') return true;
        return doc.type === filter;
    });
    if (!filtered.length) {
        const emptyText = filter === 'questionnaire'
            ? 'No tienes cuestionarios disponibles en el portal.'
            : (filter === 'file' ? 'No tienes archivos disponibles en el portal.' : 'No tienes documentos disponibles en el portal.');
        return `<div class="text-muted small">${emptyText}</div>`;
    }
    return filtered.slice(0, 20).map(doc => {
        const isQuestionnaire = doc.type === 'questionnaire';
        const badge = isQuestionnaire
            ? '<span class="badge text-bg-info">Cuestionario</span>'
            : '<span class="badge text-bg-secondary">Archivo</span>';
        const status = isQuestionnaire && doc.status
            ? `<span class="badge ${doc.status === 'pending' ? 'text-bg-warning' : 'text-bg-success'}">${escapeHtml(formatPatientDocumentStatus(doc.status))}</span>`
            : '';
        const result = [doc.score, doc.result_label].filter(Boolean).join(' · ');
        const meta = [
            doc.date ? formatDateTimeLabel(doc.date) : '',
            doc.file_name || '',
            doc.size ? formatFileSize(doc.size) : ''
        ].filter(Boolean).join(' · ');
        return `
            <div class="patient-portal-item">
                <div class="patient-portal-item-main">
                    <div class="d-flex gap-1 flex-wrap mb-1">${badge}${status}</div>
                    <strong>${escapeHtml(doc.name || (isQuestionnaire ? 'Cuestionario' : 'Archivo'))}</strong>
                    ${meta ? `<div class="text-muted small">${escapeHtml(meta)}</div>` : ''}
                    ${doc.description ? `<div class="text-muted small">${escapeHtml(doc.description)}</div>` : ''}
                    ${result ? `<div class="small">${escapeHtml(result)}</div>` : ''}
                    ${doc.observations ? `<div class="text-muted small">${escapeHtml(doc.observations)}</div>` : ''}
                </div>
                <div class="patient-portal-item-actions">
                    ${doc.url ? `<a class="btn btn-outline-primary btn-sm" href="${escapeHtml(doc.url)}" target="_blank" rel="noopener"><i class="bi bi-download"></i> Descargar</a>` : ''}
                </div>
            </div>
        `;
    }).join('');
}

function renderPatientPortalReports(reports) {
    const rows = Array.isArray(reports) ? reports : [];
    if (!rows.length) {
        return '<div class="text-muted small">No tienes informes disponibles en el portal.</div>';
    }
    return rows.slice(0, 20).map(report => {
        const paymentBadge = report.payment_status === 'paid'
            ? '<span class="badge text-bg-success">Pagado</span>'
            : (report.payment_status === 'pending'
                ? '<span class="badge text-bg-warning">Pendiente de pago</span>'
                : '<span class="badge text-bg-light">Sin pago</span>');
        const price = parseFloat(report.price || 0);
        const priceText = report.payment_mode === 'paid' && price > 0 ? `${price.toFixed(2)} EUR` : patientReportPaymentLabel(report);
        const canDownload = Boolean(report.url) && (report.payment_mode !== 'paid' || report.payment_status === 'paid');
        const pendingPaid = report.payment_mode === 'paid' && report.payment_status !== 'paid';
        return `
            <div class="patient-portal-item">
                <div class="patient-portal-item-main">
                    <div class="d-flex gap-1 flex-wrap mb-1">${paymentBadge}</div>
                    <strong>${escapeHtml(report.title || 'Informe')}</strong>
                    <div class="text-muted small">${escapeHtml(patientReportTypeLabel(report.report_key))}${report.generated_at ? ` · ${escapeHtml(formatDateTimeLabel(report.generated_at))}` : ''}</div>
                    <div class="text-muted small">${escapeHtml(priceText)}</div>
                    ${pendingPaid ? '<div class="text-muted small">Este informe estará disponible cuando el pago figure como completado.</div>' : ''}
                </div>
                <div class="patient-portal-item-actions">
                    ${canDownload ? `<a class="btn btn-outline-primary btn-sm" href="${escapeHtml(report.url)}" target="_blank" rel="noopener"><i class="bi bi-download"></i> Descargar</a>` : ''}
                </div>
            </div>
        `;
    }).join('');
}

function patientPortalMetricGroups(data = {}) {
    return [
        {
            title: 'Datos base',
            items: [
                ['Peso', data.weight_kg, 'kg'],
                ['Altura', data.height_cm, 'cm'],
                ['Grasa corporal', data.body_fat_percentage, '%']
            ]
        },
        {
            title: 'Medidas corporales',
            items: [
                ['Cintura', data.waist_cm, 'cm'],
                ['Cadera', data.hip_cm, 'cm'],
                ['Pecho / torax', data.chest_cm, 'cm'],
                ['Muslo', data.thigh_cm, 'cm'],
                ['Biceps', data.biceps_cm, 'cm'],
                ['Gemelo', data.calf_cm, 'cm']
            ]
        },
        {
            title: 'Pliegues cutaneos',
            items: [
                ['Triceps', data.skinfold_triceps_mm, 'mm'],
                ['Subescapular', data.skinfold_subscapular_mm, 'mm'],
                ['Suprailiaco', data.skinfold_suprailiac_mm, 'mm'],
                ['Abdominal', data.skinfold_abdominal_mm, 'mm'],
                ['Pectoral', data.skinfold_chest_mm, 'mm'],
                ['Muslo', data.skinfold_thigh_mm, 'mm']
            ]
        }
    ];
}

function physicalEvolutionChartGroups() {
    return {
        body: [
            { key: 'weight_kg', label: 'Peso', unit: 'kg', color: appPrimaryColor() },
            { key: 'bmi', label: 'IMC', unit: '', color: '#198754' },
            { key: 'body_fat_percentage', label: 'Grasa corporal', unit: '%', color: '#dc3545' }
        ],
        metrics: [
            { key: 'waist_cm', label: 'Cintura', unit: 'cm' },
            { key: 'hip_cm', label: 'Cadera', unit: 'cm' },
            { key: 'chest_cm', label: 'Pecho / torax', unit: 'cm' },
            { key: 'thigh_cm', label: 'Muslo', unit: 'cm' },
            { key: 'biceps_cm', label: 'Biceps', unit: 'cm' },
            { key: 'calf_cm', label: 'Gemelo', unit: 'cm' }
        ],
        skinfolds: [
            { key: 'skinfold_triceps_mm', label: 'Pliegue triceps', unit: 'mm' },
            { key: 'skinfold_subscapular_mm', label: 'Pliegue subescapular', unit: 'mm' },
            { key: 'skinfold_suprailiac_mm', label: 'Pliegue suprailiaco', unit: 'mm' },
            { key: 'skinfold_abdominal_mm', label: 'Pliegue abdominal', unit: 'mm' },
            { key: 'skinfold_chest_mm', label: 'Pliegue pectoral', unit: 'mm' },
            { key: 'skinfold_thigh_mm', label: 'Pliegue muslo', unit: 'mm' }
        ]
    };
}

function destroyPhysicalEvolutionCharts(chartStore) {
    if (!Array.isArray(chartStore)) return;
    chartStore.forEach(chart => {
        if (chart && typeof chart.destroy === 'function') {
            chart.destroy();
        }
    });
    chartStore.length = 0;
}

function physicalEvolutionNumber(value) {
    if (value === null || value === undefined) return null;
    const raw = String(value).replace(',', '.').trim();
    if (!raw) return null;
    const number = Number(raw);
    return Number.isNaN(number) ? null : number;
}

function physicalEvolutionDate(row = {}) {
    return row.note_date || row.date || row.updated_at || '';
}

function physicalEvolutionMetricValue(row = {}, key = '') {
    if (key === 'bmi') {
        const explicit = physicalEvolutionNumber(row.bmi);
        if (explicit !== null && explicit > 0) return explicit;
        const calculated = calculateBmiFromValues(row.weight_kg, row.height_cm);
        return calculated > 0 ? Number(calculated.toFixed(1)) : null;
    }
    return physicalEvolutionNumber(row[key]);
}

function physicalEvolutionRows(rows) {
    return (Array.isArray(rows) ? rows : [])
        .filter(row => physicalEvolutionDate(row))
        .slice()
        .sort((a, b) => String(physicalEvolutionDate(a)).localeCompare(String(physicalEvolutionDate(b))));
}

function bmiChartColor(points) {
    const last = points.length ? points[points.length - 1].value : 0;
    if (!last || last <= 0) return '#adb5bd';
    if (last < 18.5) return '#f0ad00';
    if (last >= 25) return '#dc3545';
    return '#198754';
}

function renderPhysicalEvolutionCharts(options = {}) {
    const selector = options.selector || '';
    const rows = physicalEvolutionRows(options.rows || []);
    const groupKey = options.groupKey || 'body';
    const chartStore = options.chartStore || [];
    const emptyText = options.emptyText || 'No hay registros suficientes para mostrar graficos.';
    const metrics = (physicalEvolutionChartGroups()[groupKey] || physicalEvolutionChartGroups().body)
        .map((metric, index) => ({ ...metric, color: metric.color || chartPaletteColor(index) }));
    const $grid = $(selector);
    if (!$grid.length) return;
    destroyPhysicalEvolutionCharts(chartStore);

    if (typeof Chart === 'undefined') {
        $grid.html('<div class="text-center text-muted py-4">No se pudo cargar la libreria de graficos.</div>');
        return;
    }

    const charts = metrics.map(metric => {
        const points = rows
            .map(row => ({
                label: formatDisplayDate(physicalEvolutionDate(row)),
                value: physicalEvolutionMetricValue(row, metric.key)
            }))
            .filter(point => point.value !== null && point.value !== undefined);
        return { metric, points };
    }).filter(chart => chart.points.length > 0);

    if (!charts.length) {
        $grid.html(`<div class="text-center text-muted py-4">${escapeHtml(emptyText)}</div>`);
        return;
    }

    $grid.html(charts.map((chart, index) => `
        <article class="patient-evolution-chart-card">
            <div class="patient-evolution-chart-title">
                <h6>${escapeHtml(chart.metric.label)}</h6>
                ${chart.metric.unit ? `<span>${escapeHtml(chart.metric.unit)}</span>` : ''}
            </div>
            <div class="patient-evolution-chart-canvas">
                <canvas id="${escapeHtml((options.canvasPrefix || 'patient-evolution-chart') + '-' + groupKey + '-' + index)}"></canvas>
            </div>
        </article>
    `).join(''));

    charts.forEach((chart, index) => {
        const color = chart.metric.key === 'bmi' ? bmiChartColor(chart.points) : chart.metric.color;
        const canvas = document.getElementById(`${options.canvasPrefix || 'patient-evolution-chart'}-${groupKey}-${index}`);
        if (!canvas) return;
        chartStore.push(new Chart(canvas, {
            type: 'line',
            data: {
                labels: chart.points.map(point => point.label),
                datasets: [{
                    label: chart.metric.label,
                    data: chart.points.map(point => point.value),
                    borderColor: color,
                    backgroundColor: hexToRgba(color, 0.14),
                    borderWidth: 2,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    fill: true,
                    tension: 0.28
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const value = Number(context.parsed.y || 0);
                                return `${chart.metric.label}: ${value.toFixed(1).replace(/\.0$/, '')}${chart.metric.unit ? ` ${chart.metric.unit}` : ''}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 6 }
                    },
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: function (value) {
                                const number = Number(value || 0);
                                return `${number.toFixed(1).replace(/\.0$/, '')}${chart.metric.unit ? ` ${chart.metric.unit}` : ''}`;
                            }
                        }
                    }
                }
            }
        }));
    });
}

function chartPaletteColor(index) {
    const colors = [appPrimaryColor(), '#198754', '#fd7e14', '#0dcaf0', '#6f42c1', '#dc3545'];
    return colors[index % colors.length];
}

function hexToRgba(color, alpha) {
    const value = String(color || '').trim();
    if (!value.startsWith('#')) return value;
    const hex = value.replace('#', '');
    const normalized = hex.length === 3
        ? hex.split('').map(char => char + char).join('')
        : hex;
    if (normalized.length !== 6) return value;
    const r = parseInt(normalized.slice(0, 2), 16);
    const g = parseInt(normalized.slice(2, 4), 16);
    const b = parseInt(normalized.slice(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function renderPatientEvolutionCharts() {
    renderPhysicalEvolutionCharts({
        selector: '#patient-evolution-chart-grid',
        rows: CURRENT_PATIENT_EVOLUTION_ROWS,
        groupKey: CURRENT_PATIENT_EVOLUTION_CHART_GROUP,
        chartStore: PATIENT_EVOLUTION_CHARTS,
        canvasPrefix: 'patient-evolution-chart',
        emptyText: 'Todavia no hay registros con valores para este grupo.'
    });
}

function renderPatientPortalCompositionCharts(history) {
    renderPhysicalEvolutionCharts({
        selector: '#patient-portal-composition-chart-grid',
        rows: history,
        groupKey: CURRENT_PATIENT_PORTAL_COMPOSITION_CHART_GROUP,
        chartStore: PATIENT_PORTAL_COMPOSITION_CHARTS,
        canvasPrefix: 'patient-portal-composition-chart',
        emptyText: 'Todavia no tienes registros con valores para este grupo.'
    });
}

function renderPatientPortalComposition(composition = {}) {
    if (!$('#patient-portal-composition-current').length) return;
    if (!composition.enabled) {
        $('#patient-portal-composition-current').html('<div class="text-muted small">El progreso corporal no esta disponible en este sector.</div>');
        $('#patient-portal-composition-history').html('');
        $('#patient-portal-composition-chart-grid').html('');
        destroyPhysicalEvolutionCharts(PATIENT_PORTAL_COMPOSITION_CHARTS);
        return;
    }
    $('#patient-portal-composition-current').html(renderPatientPortalCurrentComposition(composition.current || null));
    $('#patient-portal-composition-history').html(renderPatientPortalCompositionHistory(Array.isArray(composition.history) ? composition.history : []));
    renderPatientPortalCompositionCharts(Array.isArray(composition.history) ? composition.history : []);
    const currentBmi = parseFloat(String((composition.current || {}).bmi || '').replace(',', '.'));
    updateBmiIndicator('#patient-portal-current-bmi', currentBmi);
    (Array.isArray(composition.history) ? composition.history : []).forEach((row, index) => {
        const bmi = parseFloat(String(row.bmi || '').replace(',', '.'));
        updateBmiIndicator(`#patient-portal-history-bmi-${index}`, bmi);
    });
}

function renderPatientPortalCurrentComposition(current) {
    if (!current || !patientPortalCompositionHasValues(current)) {
        return '<div class="text-muted small">Todavia no tienes medidas registradas.</div>';
    }
    const groups = patientPortalMetricGroups(current)
        .map(group => renderPatientPortalMetricGroup(group))
        .filter(Boolean)
        .join('');
    return `
        <div class="patient-portal-composition-current">
            <div class="patient-portal-composition-bmi mb-3">
                <label class="form-label mb-1">IMC</label>
                ${renderPortalBmiIndicator('patient-portal-current-bmi')}
            </div>
            ${groups}
        </div>
    `;
}

function renderPatientPortalMetricGroup(group) {
    const items = group.items
        .filter(([, value]) => value !== null && value !== undefined && String(value).trim() !== '')
        .map(([label, value, unit]) => `
            <div class="patient-portal-metric-card">
                <span>${escapeHtml(label)}</span>
                <strong>${escapeHtml(formatMetricValue(value))}${unit ? ` ${escapeHtml(unit)}` : ''}</strong>
            </div>
        `).join('');
    if (!items) return '';
    return `
        <section class="patient-portal-metric-section">
            <h6>${escapeHtml(group.title)}</h6>
            <div class="patient-portal-metric-grid">${items}</div>
        </section>
    `;
}

function renderPortalBmiIndicator(id) {
    return `
        <div class="bmi-indicator" id="${escapeHtml(id)}">
            <div class="d-flex justify-content-between align-items-center gap-2">
                <strong class="bmi-indicator-value">-</strong>
                <span class="badge text-bg-light bmi-indicator-label">Sin datos</span>
            </div>
            <div class="bmi-indicator-bar" aria-hidden="true">
                <span class="bmi-indicator-fill"></span>
            </div>
        </div>
    `;
}

function patientPortalCompositionHasValues(row = {}) {
    return patientPortalMetricGroups(row).some(group => group.items.some(([, value]) => value !== null && value !== undefined && String(value).trim() !== ''))
        || String(row.bmi || '').trim() !== '';
}

function renderPatientPortalCompositionHistory(history) {
    if (!history.length) {
        return '<div class="text-muted small">Todavia no tienes registros de evolucion de medidas.</div>';
    }
    return `
        <div class="patient-portal-composition-history-note small text-muted mb-3">Registros usados para calcular los graficos.</div>
        <div class="patient-portal-composition-history">
            ${history.map((row, index) => renderPatientPortalCompositionHistoryItem(row, index)).join('')}
        </div>
    `;
}

function renderPatientPortalCompositionHistoryItem(row, index) {
    const groups = patientPortalMetricGroups(row)
        .map(group => renderPatientPortalHistoryMetricGroup(group))
        .filter(Boolean)
        .join('');
    return `
        <article class="patient-portal-composition-history-item">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <strong>${escapeHtml(formatDisplayDate(row.date || '') || 'Sin fecha')}</strong>
            </div>
            <div class="patient-portal-composition-history-bmi mb-2">
                ${renderPortalBmiIndicator(`patient-portal-history-bmi-${index}`)}
            </div>
            ${groups || '<div class="text-muted small">Sin medidas registradas en este punto.</div>'}
        </article>
    `;
}

function renderPatientPortalHistoryMetricGroup(group) {
    const items = group.items
        .filter(([, value]) => value !== null && value !== undefined && String(value).trim() !== '')
        .map(([label, value, unit]) => `<span>${escapeHtml(label)} ${escapeHtml(formatMetricValue(value))}${unit ? ` ${escapeHtml(unit)}` : ''}</span>`)
        .join('');
    if (!items) return '';
    return `<div class="patient-portal-history-metrics small">${items}</div>`;
}

function runGlobalSearch(query) {
    const term = String(query || '').trim();
    const $results = $('#global-search-results');
    if (!$results.length) return;
    if (term.length < 2) {
        $results.html('<div class="text-center text-muted py-4">Escribe al menos 2 caracteres para buscar.</div>');
        return;
    }
    $results.html('<div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Buscando...</div>');
    $.ajax({
        url: 'api/admin.php?action=global_search',
        method: 'GET',
        dataType: 'json',
        data: { q: term },
        success: function (res) {
            if (!res.success) {
                $results.html(`<div class="alert alert-danger">${escapeHtml(res.error || 'No se pudo realizar la busqueda.')}</div>`);
                return;
            }
            renderGlobalSearchResults(res.results || {});
        },
        error: function () {
            $results.html('<div class="alert alert-danger">Error de conexion al buscar.</div>');
        }
    });
}

function renderGlobalSearchResults(groups) {
    const labels = {
        patients: 'Pacientes',
        professionals: 'Profesionales',
        appointments: 'Citas',
        files: 'Archivos',
        tasks: 'Tareas'
    };
    const sections = Object.keys(labels).map(key => {
        const rows = Array.isArray(groups[key]) ? groups[key] : [];
        if (!rows.length) return '';
        return `
            <section class="global-search-section">
                <h6>${labels[key]}</h6>
                <div class="global-search-list">
                    ${rows.map(renderGlobalSearchResult).join('')}
                </div>
            </section>
        `;
    }).filter(Boolean);
    $('#global-search-results').html(sections.length ? sections.join('') : '<div class="text-center text-muted py-4">No se encontraron resultados.</div>');
}

function renderGlobalSearchResult(item) {
    const action = item.action || {};
    const kind = action.kind || '';
    const id = parseInt(action.id || 0, 10);
    const patientId = parseInt(action.patient_id || 0, 10);
    const clickable = kind && id;
    return `
        <button type="button" class="global-search-result ${clickable ? '' : 'is-static'}" ${clickable ? `data-kind="${escapeHtml(kind)}" data-id="${id}" data-patient-id="${patientId || ''}"` : 'disabled'}>
            <span class="global-search-icon"><i class="bi ${escapeHtml(item.icon || 'bi-search')}"></i></span>
            <span class="global-search-content">
                <strong>${escapeHtml(item.title || '')}</strong>
                ${item.subtitle ? `<small>${escapeHtml(item.subtitle)}</small>` : ''}
                ${item.meta ? `<em>${escapeHtml(item.meta)}</em>` : ''}
            </span>
        </button>
    `;
}

function handleGlobalSearchAction(kind, id, patientId) {
    if (globalSearchModal) {
        globalSearchModal.hide();
    }
    id = parseInt(id || 0, 10);
    patientId = parseInt(patientId || 0, 10);
    if (kind === 'patient' && id) {
        openPatientEditorById(id);
    } else if (kind === 'appointment' && id) {
        openAppointmentPaymentModal(id);
    } else if (kind === 'file' && id) {
        window.open(`api/admin.php?action=download_evolution_file&id=${id}`, '_blank');
    } else if (kind === 'professional' && id && settingsModal) {
        loadClosedDays();
        loadPaymentSettings();
        settingsModal.show();
        const tabButton = document.getElementById('cabinet-settings-tab');
        if (tabButton && window.bootstrap) {
            bootstrap.Tab.getOrCreateInstance(tabButton).show();
        }
    } else if (patientId) {
        openPatientEditorById(patientId);
    }
}

function isPastSlot(dateStr, timeStr) {
    return new Date(`${dateStr}T${timeStr}:00`) < new Date();
}

function isOutsideAllowedBookingWindow(dateStr) {
    if (IS_ADMIN) {
        return false;
    }

    let today = new Date();
    today.setHours(0, 0, 0, 0);
    let target = new Date(`${dateStr}T00:00:00`);
    let diffDays = Math.round((target - today) / 86400000);
    let minDays = parseInt(PAYMENT_SETTINGS.min_booking_notice_days || 0, 10);
    let maxDays = parseInt(PAYMENT_SETTINGS.max_booking_notice_days || 0, 10);

    if (minDays > 0 && diffDays < minDays) {
        return true;
    }

    if (maxDays > 0 && diffDays > maxDays) {
        return true;
    }

    return false;
}

function getPaymentBadge(app) {
    if (!app || PAYMENT_SETTINGS.online_payment_enabled != 1) {
        return '';
    }

    if (app.payment_status === 'paid') {
        if (app.payment_method === 'bonus') {
            return ' <small class="payment-badge paid">Bono</small>';
        }
        return ' <small class="payment-badge paid">Pagado</small>';
    }

    if (!IS_ADMIN) {
        return '';
    }

    if (app.payment_status === 'failed') {
        return ' <small class="payment-badge failed">Pago fallido</small>';
    }

    return ' <small class="payment-badge pending">Pendiente de pago</small>';
}

function getConsultationBadge(consultationType) {
    const label = consultationType === 'online' ? 'Online' : 'Presencial';
    const cls = consultationType === 'online' ? 'online' : 'presencial';
    return ` <small class="consultation-badge ${cls}">${label}</small>`;
}

function getServiceBadge(app) {
    if (!app) {
        return '';
    }
    const duration = parseInt(app.duration_minutes || 60, 10);
    const displayDuration = displayDurationMinutes(duration);
    let label = '';
    if (app.service_type && app.service_type !== 'individual') {
        label = serviceTypeLabel(app.service_type);
        if (displayDuration !== 60) {
            label += ` ${displayDuration} min`;
        }
    } else if (displayDuration !== 60) {
        label = `${displayDuration} min`;
    } else if (app.service_name && !/individual/i.test(app.service_name)) {
        label = app.service_name;
    }
    if (!label) return '';
    return ` <small class="service-badge couple">${escapeHtml(label)}</small>`;
}

function canPayAppointment(app) {
    return !IS_ADMIN
        && app
        && app.is_own
        && paymentPlanEnabled()
        && PAYMENT_SETTINGS.online_payment_enabled == 1
        && app.payment_status !== 'paid';
}

// Modal handling
let appointmentModal = new bootstrap.Modal(document.getElementById('appointmentModal'));
let settingsModal = document.getElementById('settingsModal') ? new bootstrap.Modal(document.getElementById('settingsModal')) : null;
let closedDayModal = document.getElementById('closedDayModal') ? new bootstrap.Modal(document.getElementById('closedDayModal')) : null;
let professionalEditorModal = document.getElementById('professionalEditorModal') ? new bootstrap.Modal(document.getElementById('professionalEditorModal')) : null;
let professionalKnowledgeSectorsModal = document.getElementById('professionalKnowledgeSectorsModal') ? new bootstrap.Modal(document.getElementById('professionalKnowledgeSectorsModal')) : null;
let professionalTransferModal = document.getElementById('professionalTransferModal') ? new bootstrap.Modal(document.getElementById('professionalTransferModal')) : null;
let changePasswordModal = document.getElementById('changePasswordModal') ? new bootstrap.Modal(document.getElementById('changePasswordModal')) : null;
let patientSelfDataModal = document.getElementById('patientSelfDataModal') ? new bootstrap.Modal(document.getElementById('patientSelfDataModal')) : null;
let patientPortalAppointmentsModal = document.getElementById('patientPortalAppointmentsModal') ? new bootstrap.Modal(document.getElementById('patientPortalAppointmentsModal')) : null;
let patientPortalTasksModal = document.getElementById('patientPortalTasksModal') ? new bootstrap.Modal(document.getElementById('patientPortalTasksModal')) : null;
let patientPortalDocumentsModal = document.getElementById('patientPortalDocumentsModal') ? new bootstrap.Modal(document.getElementById('patientPortalDocumentsModal')) : null;
let patientPortalReportsModal = document.getElementById('patientPortalReportsModal') ? new bootstrap.Modal(document.getElementById('patientPortalReportsModal')) : null;
let patientPortalCompositionModal = document.getElementById('patientPortalCompositionModal') ? new bootstrap.Modal(document.getElementById('patientPortalCompositionModal')) : null;
let globalSearchModal = document.getElementById('globalSearchModal') ? new bootstrap.Modal(document.getElementById('globalSearchModal')) : null;
let dashboardCustomConfigModal = document.getElementById('dashboardCustomConfigModal') ? new bootstrap.Modal(document.getElementById('dashboardCustomConfigModal')) : null;
let taskTemplateModal = document.getElementById('taskTemplateModal') ? new bootstrap.Modal(document.getElementById('taskTemplateModal')) : null;
let taskTemplateItemModal = document.getElementById('taskTemplateItemModal') ? new bootstrap.Modal(document.getElementById('taskTemplateItemModal')) : null;
inviteModal = document.getElementById('inviteModal') ? new bootstrap.Modal(document.getElementById('inviteModal')) : null;
upcomingAppointmentsModal = document.getElementById('upcomingAppointmentsModal') ? new bootstrap.Modal(document.getElementById('upcomingAppointmentsModal')) : null;
appointmentPaymentModal = document.getElementById('appointmentPaymentModal') ? new bootstrap.Modal(document.getElementById('appointmentPaymentModal')) : null;
appointmentSessionNoteModal = document.getElementById('appointmentSessionNoteModal') ? new bootstrap.Modal(document.getElementById('appointmentSessionNoteModal')) : null;
adminStatsModal = document.getElementById('adminStatsModal') ? new bootstrap.Modal(document.getElementById('adminStatsModal')) : null;
bonusesModal = document.getElementById('bonusesModal') ? new bootstrap.Modal(document.getElementById('bonusesModal')) : null;
let adminPatientsModal = document.getElementById('adminPatientsModal') ? new bootstrap.Modal(document.getElementById('adminPatientsModal')) : null;
let patientEditorModal = document.getElementById('patientEditorModal') ? new bootstrap.Modal(document.getElementById('patientEditorModal')) : null;
let patientDocumentModal = document.getElementById('patientDocumentModal') ? new bootstrap.Modal(document.getElementById('patientDocumentModal')) : null;
let patientReportConfigModal = document.getElementById('patientReportConfigModal') ? new bootstrap.Modal(document.getElementById('patientReportConfigModal')) : null;
let patientReportSuggestionsModal = document.getElementById('patientReportSuggestionsModal') ? new bootstrap.Modal(document.getElementById('patientReportSuggestionsModal')) : null;
let patientEvolutionModal = document.getElementById('patientEvolutionModal') ? new bootstrap.Modal(document.getElementById('patientEvolutionModal')) : null;
let workoutxExerciseModal = document.getElementById('workoutxExerciseModal') ? new bootstrap.Modal(document.getElementById('workoutxExerciseModal')) : null;
patientWorkPlanTaskModal = document.getElementById('patientWorkPlanTaskModal') ? new bootstrap.Modal(document.getElementById('patientWorkPlanTaskModal')) : null;
let pendingLocalProfessionalDeleteIndex = null;
let currentPaymentAppointmentId = null;
let ADMIN_PATIENTS = [];
let ADMIN_BOOKING_PATIENTS = [];
let CURRENT_PROFESSIONAL_ID = 0;
let adminPatientsAlertTimer = null;
let inviteAlertTimer = null;
let CURRENT_BONUS_LIST = [];
let CABINET_PROFESSIONALS = [];
let PROFESSIONAL_PHOTO_FILE = null;
let CURRENT_BONUS_ADMIN_VIEW = false;
let CURRENT_BONUS_LIST_URL = '';
let CURRENT_BONUS_CAN_MANAGE = false;
let CURRENT_UPCOMING_APPOINTMENTS = [];
let CURRENT_PATIENT_HISTORY_ID = 0;
let CURRENT_PATIENT_BONUSES_ID = 0;
let CURRENT_PATIENT_EVOLUTION_ID = 0;
let CURRENT_PATIENT_FILES_ID = 0;
let CURRENT_PATIENT_FILES_FILTER = 'all';
let CURRENT_PATIENT_FILES_ROWS = [];
let CURRENT_PATIENT_REPORTS_ID = 0;
let CURRENT_PATIENT_REPORTS_ROWS = [];
let patientFilesAlertTimer = null;
let patientReportsAlertTimer = null;
let CURRENT_PATIENT_WORK_PLAN_ID = 0;
let CURRENT_PATIENT_WORK_PLAN_ROWS = [];
let CURRENT_WORK_PLAN_FORM_CONTEXT = { source: 'patient', patientId: 0, appointmentId: 0 };
let KNOWLEDGE_PROBLEMS = [];
let KNOWLEDGE_PROBLEMS_LOADED = false;
let CURRENT_KNOWLEDGE_PROBLEM_DETAIL = null;
let CURRENT_PATIENT_PORTAL = { appointments: [], tasks: [], documents: [], reports: [], composition: { enabled: false, current: null, history: [] } };
let CURRENT_PATIENT_PORTAL_DOCUMENTS_FILTER = 'all';
let CURRENT_PATIENT_EVOLUTION_VIEW = 'records';
let CURRENT_PATIENT_EVOLUTION_CHART_GROUP = 'body';
let CURRENT_PATIENT_PORTAL_COMPOSITION_CHART_GROUP = 'body';
let PATIENT_EVOLUTION_CHARTS = [];
let PATIENT_PORTAL_COMPOSITION_CHARTS = [];
let WORK_PLAN_TASK_TEMPLATES = [];
let WORK_PLAN_TASK_TEMPLATES_LOADED = false;
let WORK_PLAN_KNOWLEDGE_IMPORT_OPTIONS = [];
let WORK_PLAN_KNOWLEDGE_IMPORT_OPTIONS_LOADED = false;
let WORK_PLAN_IMPORT_LOADING = false;
let CURRENT_PATIENT_EVOLUTION_ROWS = [];
let CURRENT_PATIENT_EVOLUTION_APPOINTMENTS = [];
let CURRENT_PATIENT_BONUS_CATALOG = [];
let CURRENT_PATIENT_BONUS_CAN_MANAGE = false;
let CURRENT_APPOINTMENT_PAYMENT_DETAIL = null;
let CURRENT_APPOINTMENT_SESSION = {
    appointment_id: 0,
    patient_id: 0,
    tasks: [],
    notes: [],
    files: []
};

function openModal(date, time, status, extraName = '', extraEmail = '', extraPhone = '') {
    setBookingModalLoadingState(false);
    $('#modalDate').val(date);
    $('#modalTime').val(time);
    $('#modalStatus').val(status);
    $('#payment-options').addClass('d-none');
    $('#consultationTypeSelect').addClass('d-none');
    $('#serviceTypeSelect').addClass('d-none');
    $('#bookingConsultationSelect').addClass('d-none');
    $('#booking-consultation-cards').empty();
    $('#serviceOptionSelect').addClass('d-none');
    $('#adminProfessionalSelect').addClass('d-none');
    $('#patientSlotProfessionalSelect').addClass('d-none').empty();
    $('#modal-professional-context').addClass('d-none').empty();
    $('#booking-patient-professional-note').text('');
    $('#booking-bonus-notice').addClass('d-none').text('');
    currentPaymentAppointmentId = null;
    currentCancelAppointmentId = null;
    CURRENT_BOOKING_CONSULTATION_TYPE = '';
    $('#btn-confirm-action').removeClass('d-none').prop('disabled', false);

    if (status === 'available') {
        $('#modalTitle').text(`Reservar cita: ${formatDisplayDate(date)} a las ${time}`);
        if (IS_ADMIN) {
            $('#modalDesc').text(`Selecciona un ${sectorLabel('patient', 'singular', 'paciente')} para reservar el horario.`);
            $('#adminPatientSelect').removeClass('d-none');
            if (!bookingPatientsLoaded) {
                setBookingPatientsLoadingState(true);
            }
            if (IS_SUPERADMIN) {
                $('#adminProfessionalSelect').removeClass('d-none');
            }
        } else {
            $('#modalDesc').text('Confirma la fecha/hora de tu cita.');
        }
        if (!IS_ADMIN && !shouldChooseProfessionalInSlot()) {
            renderModalProfessionalContext();
        }
        const continueAvailableBooking = function () {
            if (IS_SUPERADMIN) {
                populateBookingProfessionalSelect(CURRENT_PROFESSIONAL_ID);
                updateBookingPatientProfessionalNote(bookingPatientById($('#patientSelect').val()));
                loadBookingContextForProfessional($('#booking-professional').val() || CURRENT_PROFESSIONAL_ID);
            } else if (!IS_ADMIN && shouldChooseProfessionalInSlot()) {
                loadAvailableProfessionalsForSlot(date, time);
            } else {
                renderBookingServiceOptions();
                refreshBookingBonusNotice();
            }
        };

        if (IS_ADMIN && !bookingPatientsLoaded) {
            loadBookingPatients().done(function () {
                continueAvailableBooking();
            }).fail(function () {
                const $select = $('#patientSelect');
                if ($select.length) {
                    $select.html('<option value="">No se pudieron cargar los datos</option>').prop('disabled', true);
                }
                $('#btn-confirm-action').prop('disabled', true);
            }).always(function () {
                if (bookingPatientsLoaded) {
                    setBookingPatientsLoadingState(false);
                }
            });
        } else if (!IS_ADMIN && shouldChooseProfessionalInSlot()) {
            loadAvailableProfessionalsForSlot(date, time);
        } else {
            continueAvailableBooking();
        }
        $('#serviceOptionSelect').removeClass('d-none');
        $('#btn-confirm-action').removeClass('btn-danger').addClass('btn-primary').text('Reservar');
    } else if (status === 'cancel_admin') {
        $('#modalTitle').text(`Cancelar cita: ${formatDisplayDate(date)} a las ${time}`);
        $('#modalDesc').html(`${capitalizeFirst(sectorLabel('patient', 'singular', 'paciente'))}: <b>${extraName}</b><br><small>Email: ${extraEmail}<br>Tel: ${extraPhone}</small><br><br>¿Confirmar cancelación?`);
        currentCancelAppointmentId = parseCancelPayload(extraName).id || null;
        if (IS_ADMIN) $('#adminPatientSelect').addClass('d-none');
        $('#adminProfessionalSelect').addClass('d-none');
        $('#btn-confirm-action').removeClass('btn-primary').addClass('btn-danger').text('Cancelar cita');
    } else if (status === 'cancel_own') {
        $('#modalTitle').text(`Cancelar tu cita: ${formatDisplayDate(date)} a las ${time}`);
        $('#modalDesc').text('¿Estás seguro de que deseas cancelar tu cita?');
        currentCancelAppointmentId = parseCancelPayload(extraName).id || null;
        $('#btn-confirm-action').removeClass('btn-primary').addClass('btn-danger').text('Cancelar cita');
    } else if (status === 'pay_own') {
        currentPaymentAppointmentId = parseInt(extraName, 10);
        $('#modalTitle').text(`Pagar cita: ${formatDisplayDate(date)} a las ${time}`);
        const paymentAmount = extraEmail ? ` (${formatPrice(extraEmail)} €)` : '';
        const serviceText = extraPhone ? `<br>${escapeHtml(extraPhone)}` : '';
        $('#modalDesc').html(`Tu cita está reservada correctamente.${serviceText}<br><br>Elige cómo quieres pagarla${paymentAmount}.`);
        $('#btn-confirm-action').addClass('d-none');
        $('#payment-options-text').text('');
        $('#payment-options').removeClass('d-none');
    }

    applyCancelPaymentNotice(status, extraName);
    appointmentModal.show();
}

function parseCancelPayload(raw) {
    if (!raw) {
        return {};
    }
    try {
        return JSON.parse(decodeURIComponent(raw));
    } catch (e) {
        return { name: raw };
    }
}

function cancelBonusNotice(data) {
    if (!data || data.payment_method !== 'bonus' || !data.patient_bonus_id) {
        return '';
    }
    return '<div class="alert alert-success py-2 my-3 text-start small"><strong>Cita reservada con bono.</strong><br><span>Tras la cancelaci&oacute;n, volver&aacute;s a tener el bono disponible para otra reserva.</span></div>';
}

function canCreateCompensationBonusOnCancel(data) {
    const settingEnabled = PAYMENT_SETTINGS.create_compensation_bonus_on_paid_cancel === undefined
        ? true
        : PAYMENT_SETTINGS.create_compensation_bonus_on_paid_cancel == 1;
    return settingEnabled
        && data
        && data.payment_status === 'paid'
        && ['card', 'bizum'].includes(data.payment_method || '');
}

function cancelCompensationNotice(data, status) {
    if (!canCreateCompensationBonusOnCancel(data)) {
        return '';
    }

    const checkbox = status === 'cancel_admin'
        ? '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="cancel-create-compensation-bonus" checked><label class="form-check-label" for="cancel-create-compensation-bonus">Crear bono para esta cancelaci&oacute;n</label></div>'
        : '';

    return '<div class="alert alert-info py-2 my-3 text-start small"><strong id="cancel-compensation-bonus-text">Se crear&aacute; un bono canjeable para una nueva sesi&oacute;n.</strong>' + checkbox + '</div>';
}

function applyCancelPaymentNotice(status, payload) {
    if (!['cancel_admin', 'cancel_own'].includes(status)) {
        return;
    }
    const data = parseCancelPayload(payload);
    currentCancelAppointmentId = data.id || null;
    const notice = cancelBonusNotice(data) + cancelCompensationNotice(data, status);
    if (status === 'cancel_admin') {
        $('#modalDesc').html(`${capitalizeFirst(sectorLabel('patient', 'singular', 'paciente'))}: <b>${escapeHtml(data.name || '')}</b><br><small>Email: ${escapeHtml(data.email || '')}<br>Tel: ${escapeHtml(data.phone || '')}</small>${notice}<br>&iquest;Confirmar cancelaci&oacute;n?`);
        return;
    }
    $('#modalDesc').html(`${notice}<br>&iquest;Est&aacute;s seguro de que deseas cancelar tu cita?`);
}

$(document).ready(function () {
    applyPlanFeatureVisibility();
    applyKnowledgeBaseVisibility();
    renderWeekInfo();
    startQuickAppointmentsAutoRefresh();

    if (!IS_ADMIN) {
        loadPatientPortalSummary();
    }

    $('#btn-global-search').on('click', function () {
        if (!globalSearchModal) return;
        $('#global-search-input').val('');
        $('#global-search-results').html('<div class="text-center text-muted py-4">Escribe al menos 2 caracteres para buscar.</div>');
        globalSearchModal.show();
    });

    $('#globalSearchModal').on('shown.bs.modal', function () {
        setTimeout(() => $('#global-search-input').trigger('focus').trigger('select'), 40);
    });

    $('#global-search-input').on('input', debounce(function () {
        runGlobalSearch($(this).val());
    }, 250));

    $('#global-search-results').on('click', '.global-search-result', function () {
        handleGlobalSearchAction($(this).data('kind'), $(this).data('id'), $(this).data('patient-id'));
    });

    $('#patient-quick-appointment-summary').on('click', '.btn-cancel-patient-quick-appointment', function () {
        const appId = parseInt($(this).data('appointment-id') || 0, 10);
        const app = CURRENT_PATIENT_PORTAL.appointments.find(item => parseInt(item.id, 10) === appId);
        if (!app) return;
        const payload = encodeURIComponent(JSON.stringify({
            id: app.id,
            payment_status: app.payment_status || 'pending',
            payment_method: app.payment_method || ''
        }));
        openModal(app.appointment_date, app.appointment_time, 'cancel_own', payload);
    });

    $('#patient-portal-appointments').on('click', '.btn-patient-portal-pay', function () {
        const appointmentId = parseInt($(this).data('appointment-id') || 0, 10);
        if (!appointmentId) return;
        startRedsysPayment(appointmentId, 'card', {
            button: this,
            hideAppointmentModalOnError: false,
            failureMessage: 'No se pudo iniciar el pago de esta cita.'
        });
    });

    $('#patient-portal-tasks').on('click', '.btn-patient-portal-exercise-detail', function () {
        openPatientPortalExerciseModal($(this).data('task-id'));
    });

    $(document).on('click', '#btn-prev-week', function () {
        if (currentCalendarView === 'month') {
            currentMonthDate.setMonth(currentMonthDate.getMonth() - 1);
            selectedMonthDay = null;
        } else {
            currentStartDate.setDate(currentStartDate.getDate() - 7);
        }
        renderWeekInfo();
    });

    $(document).on('click', '#btn-next-week', function () {
        if (currentCalendarView === 'month') {
            currentMonthDate.setMonth(currentMonthDate.getMonth() + 1);
            selectedMonthDay = null;
        } else {
            currentStartDate.setDate(currentStartDate.getDate() + 7);
        }
        renderWeekInfo();
    });

    $('#btn-calendar-view-toggle').click(function () {
        if (currentCalendarView === 'week') {
            currentCalendarView = 'month';
            currentMonthDate = new Date(currentStartDate);
            selectedMonthDay = null;
        } else {
            currentCalendarView = 'week';
            if (selectedMonthDay) {
                currentStartDate = getMonday(new Date(`${selectedMonthDay}T00:00:00`));
            }
        }
        renderWeekInfo();
    });

    $(document).on('click', '.btn-dashboard-view', function () {
        if (!IS_ADMIN) {
            return;
        }
        const view = $(this).data('dashboard-view') || 'month';
        if (view === currentCalendarView) {
            return;
        }
        if (view === 'month') {
            currentCalendarView = 'month';
            currentMonthDate = new Date(currentStartDate);
            selectedMonthDay = null;
        } else if (view === 'week') {
            currentCalendarView = 'week';
            if (selectedMonthDay) {
                currentStartDate = getMonday(new Date(`${selectedMonthDay}T00:00:00`));
            }
        } else if (view === 'patients' || view === 'upcoming') {
            currentCalendarView = view;
        }
        renderWeekInfo();
    });

    $(document).on('click', '.btn-dashboard-main-view', function () {
        if (!IS_ADMIN) {
            return;
        }
        const view = $(this).data('dashboard-main-view') || 'agenda';
        const nextView = view === 'agenda' ? defaultAgendaCalendarView() : view;
        if (nextView === currentCalendarView) {
            updateCalendarNavigationLabels();
            return;
        }
        if (nextView === 'month') {
            currentCalendarView = 'month';
            currentMonthDate = new Date(currentStartDate);
            selectedMonthDay = null;
        } else if (nextView === 'week') {
            currentCalendarView = 'week';
            if (selectedMonthDay) {
                currentStartDate = getMonday(new Date(`${selectedMonthDay}T00:00:00`));
            }
        } else if (nextView === 'patients' || nextView === 'upcoming') {
            currentCalendarView = nextView;
        }
        renderWeekInfo();
    });

    $(document).on('click', '.btn-dashboard-calendar-mode', function () {
        if (!IS_ADMIN) {
            return;
        }
        const view = $(this).data('dashboard-calendar-view') || 'month';
        if (view === currentCalendarView) {
            return;
        }
        if (view === 'month') {
            currentCalendarView = 'month';
            currentMonthDate = new Date(currentStartDate);
            selectedMonthDay = null;
        } else if (view === 'week') {
            currentCalendarView = 'week';
            if (selectedMonthDay) {
                currentStartDate = getMonday(new Date(`${selectedMonthDay}T00:00:00`));
            }
        }
        renderWeekInfo();
    });

    $('#patient-professional-choice').on('click', '.patient-professional-card', function () {
        CURRENT_PATIENT_SELECTED_PROFESSIONAL_ID = parseInt($(this).data('professional-id') || 0, 10);
        selectedMonthDay = null;
        renderWeekInfo();
    });

    $('#patientSlotProfessionalSelect').on('click', '.patient-professional-card', function () {
        setSlotBookingProfessional($(this).data('professional-id'));
    });

    $('#btn-buy-bonus').click(function () {
        openBuyBonusModal();
    });

    $('#btn-my-bonuses').click(function () {
        openMyBonusesModal();
    });

    $('#btn-patient-portal-appointments').click(function () {
        openPatientPortalAppointmentsModal();
    });

    $('#btn-patient-portal-tasks').click(function () {
        openPatientPortalTasksModal();
    });

    $('#btn-patient-portal-documents').click(function () {
        openPatientPortalDocumentsModal();
    });

    $('#btn-patient-portal-reports').click(function () {
        openPatientPortalReportsModal();
    });

    $('#btn-patient-portal-composition').click(function () {
        openPatientPortalCompositionModal();
    });

    $('.patient-portal-documents-filter').click(function () {
        CURRENT_PATIENT_PORTAL_DOCUMENTS_FILTER = $(this).data('documents-filter') || 'all';
        $('.patient-portal-documents-filter').removeClass('btn-primary').addClass('btn-outline-primary');
        $(this).removeClass('btn-outline-primary').addClass('btn-primary');
        $('#patient-portal-documents').html(renderPatientPortalDocuments(CURRENT_PATIENT_PORTAL.documents || []));
    });

    $('.patient-portal-composition-chart-group').click(function () {
        CURRENT_PATIENT_PORTAL_COMPOSITION_CHART_GROUP = $(this).data('chart-group') || 'body';
        $('.patient-portal-composition-chart-group').removeClass('btn-primary').addClass('btn-outline-primary');
        $(this).removeClass('btn-outline-primary').addClass('btn-primary');
        renderPatientPortalCompositionCharts(((CURRENT_PATIENT_PORTAL.composition || {}).history) || []);
    });

    $('#btn-mobile-buy-bonus').click(function () {
        $('#btn-buy-bonus').trigger('click');
    });

    $('#btn-mobile-my-bonuses').click(function () {
        $('#btn-my-bonuses').trigger('click');
    });

    $('#btn-mobile-patient-portal-appointments').click(function () {
        $('#btn-patient-portal-appointments').trigger('click');
    });

    $('#btn-mobile-patient-portal-tasks').click(function () {
        $('#btn-patient-portal-tasks').trigger('click');
    });

    $('#btn-mobile-patient-portal-documents').click(function () {
        $('#btn-patient-portal-documents').trigger('click');
    });

    $('#btn-mobile-patient-portal-reports').click(function () {
        $('#btn-patient-portal-reports').trigger('click');
    });

    $('#btn-mobile-patient-portal-composition').click(function () {
        $('#btn-patient-portal-composition').trigger('click');
    });

    $('#patientPortalCompositionModal').on('shown.bs.modal', function () {
        renderPatientPortalCompositionCharts(((CURRENT_PATIENT_PORTAL.composition || {}).history) || []);
    });

    $('#patient-portal-composition-history-tab').on('shown.bs.tab', function () {
        renderPatientPortalCompositionCharts(((CURRENT_PATIENT_PORTAL.composition || {}).history) || []);
    });

    $('#btn-admin-bonuses').click(function () {
        openAdminBonusesModal();
    });

    $('#btn-mobile-admin-bonuses').click(function () {
        $('#btn-admin-bonuses').trigger('click');
    });

    $(document).on('click', '[data-dashboard-action="bonuses"]', function () {
        openAdminBonusesModal();
    });

    $(document).on('click', '[data-dashboard-action="stats"]', function () {
        $('#btn-admin-stats').trigger('click');
    });

    $(document).on('click', '[data-dashboard-action="invite"]', function () {
        $('#btn-generate-invite').trigger('click');
    });

    $('#btn-generate-invite').off('click').click(function () {
        const $button = $(this);
        const original = $button.html();
        $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Generando');
        $.ajax({
            url: 'api/admin.php?action=generate_invite',
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    openInviteModal(res.link, res.token || '');
                    copyTextToClipboard(res.link, function () {
                        $('#admin-actions-msg').text('Enlace copiado al portapapeles').fadeIn().delay(3000).fadeOut();
                    });
                } else {
                    alert(res.error || 'No se pudo generar la invitacion');
                }
            },
            complete: function () {
                $button.prop('disabled', false).html(original);
            }
        });
    });

    $('#btn-mobile-generate-invite').click(function () {
        $('#btn-generate-invite').trigger('click');
    });

    $('#btn-copy-invite-link').click(function () {
        copyTextToClipboard(currentInviteLink, function () {
            showInviteAlert('success', 'Enlace copiado al portapapeles.');
        });
    });

    $('#btn-send-invite-email').click(function () {
        sendInviteEmail(this);
    });

    $('#btn-upcoming-appointments').click(function () {
        openUpcomingAppointmentsModal();
    });

    $('#btn-mobile-upcoming-appointments').click(function () {
        $('#btn-upcoming-appointments').trigger('click');
    });

    $(document).on('click', '.btn-dashboard-more-upcoming', function () {
        openUpcomingAppointmentsModal();
    });

    $('#upcoming-appointments-search').on('input', function () {
        renderUpcomingAppointments(CURRENT_UPCOMING_APPOINTMENTS);
    });

    $('#cancelled-appointments-search').on('input', function () {
        renderCancelledAppointments(CURRENT_CANCELLED_APPOINTMENTS);
    });

    $('#upcoming-appointments-scope').on('change', function () {
        loadUpcomingAppointments();
    });

    $('#upcoming-planning-scope').on('change', function () {
        loadUpcomingAppointments();
    });

    $('#upcoming-appointments-professional').on('change', function () {
        loadUpcomingAppointments();
    });

    $('#appointmentPaymentModal').on('click', '.payment-state-card', function () {
        setAppointmentPaymentSelection($(this).data('payment-status'), $('#appointment-payment-method').val());
    });

    $('#appointmentPaymentModal').on('change', '#appointment-online-consultation-type', function () {
        toggleAppointmentOnlineFields();
    });

    $('#appointmentPaymentModal').on('input', '#appointment-online-session-url', function () {
        toggleAppointmentOnlineFields();
    });

    $('#appointmentPaymentModal').on('click', '#btn-save-appointment-online-details', function () {
        saveAppointmentOnlineDetails(this);
    });

    $('#appointmentPaymentModal').on('click', '#btn-send-appointment-online-link', function () {
        sendAppointmentOnlineLink(this);
    });

    $('#appointmentPaymentModal').on('click', '.btn-switch-appointment-modality', function () {
        switchAppointmentModality(this);
    });

    $('#btn-save-appointment-payment').on('click', function () {
        saveAppointmentPayment();
    });

    $('#btn-open-patient-from-appointment-detail').on('click', function () {
        const app = CURRENT_APPOINTMENT_PAYMENT_DETAIL || {};
        if (app.patient_id) {
            if (appointmentPaymentModal) {
                appointmentPaymentModal.hide();
            }
            setTimeout(() => openPatientEditorById(app.patient_id), 180);
        }
    });

    $('#btn-cancel-appointment-from-detail').on('click', function () {
        cancelAppointmentFromPaymentDetail();
    });

    $('#appointmentSessionNoteModal').on('submit', '#appointment-session-note-form', function (e) {
        e.preventDefault();
        saveAppointmentSessionNote(this);
    });

    $('#appointmentPaymentModal').on('click', '.btn-toggle-session-task', function () {
        setAppointmentSessionTaskStatus(this);
    });

    $('#appointmentPaymentModal').on('click', '#btn-toggle-appointment-session-note', function () {
        openAppointmentSessionNoteModal();
    });

    $('#appointmentPaymentModal').on('click', '#btn-show-appointment-session-work-plan-form', function () {
        showAppointmentSessionWorkPlanForm();
    });

    $('#appointmentPaymentModal').on('click', '.btn-delete-session-note', function () {
        deleteAppointmentSessionNote(this);
    });

    $('#appointmentPaymentModal').on('hidden.bs.modal', function () {
        $('body').removeClass('appointment-payment-secondary-modal-open');
    });

    $('#appointmentSessionNoteModal').on('show.bs.modal', function () {
        if ($('#appointmentPaymentModal').hasClass('show')) {
            $('body').addClass('appointment-session-note-modal-open');
        }
    });

    $('#appointmentSessionNoteModal').on('hidden.bs.modal', function () {
        $('body').removeClass('appointment-session-note-modal-open');
        resetAppointmentSessionNoteForm();
        if ($('#appointmentPaymentModal').hasClass('show')) {
            document.body.classList.add('modal-open');
        }
    });

    $('#btn-admin-stats').click(function () {
        openAdminStatsModal();
    });

    $('#btn-mobile-admin-stats').click(function () {
        $('#btn-admin-stats').trigger('click');
    });

    $('#admin-reports-content').on('click', '.btn-print-report-section', function () {
        printReportSection($(this).closest('.report-section'));
    });

    $('#admin-reports-content').on('click', '.btn-export-report-section', function () {
        exportReportSectionCsv($(this).closest('.report-section'));
    });

    $('#admin-reports-content').on('click', '.btn-export-report-section-xls', function () {
        exportReportSectionXls($(this).closest('.report-section'));
    });

    $('.btn-export-modal-table').on('click', function () {
        exportModalVisibleTable($(this).data('table-target'), $(this).data('export-type'));
    });

    $('#btn-admin-patients').click(function () {
        openAdminPatientsModal();
    });

    $('#btn-mobile-admin-patients').click(function () {
        $('#btn-admin-patients').trigger('click');
    });

    $('#btn-new-patient').click(function () {
        openPatientEditorModal();
    });

    $('#btn-show-patient-transfer').on('click', function () {
        $('#patient-transfer-panel').toggleClass('d-none');
        populatePatientTransferProfessionalSelect(CURRENT_PATIENT_EDITOR);
    });

    $('#btn-confirm-patient-transfer').on('click', function () {
        transferCurrentPatientProfessional();
    });

    $('#patient-editor-birth-date').on('input change', function () {
        updatePatientAgeDisplay();
    });

    $('#patient-editor-weight, #patient-editor-height').on('input change', function () {
        updatePatientBmiDisplay();
    });

    $('#patient-editor-physical-sex').on('change', function () {
        $('#patient-editor-body-fat-note').text('Puedes introducirlo manualmente si ya tienes una medicion fiable.');
    });

    $('#btn-calculate-body-fat').on('click', function () {
        calculatePatientBodyFat();
    });

    $('#patient-evolution-weight, #patient-evolution-height').on('input change', function () {
        updatePatientEvolutionBmiDisplay();
    });

    $('#btn-copy-current-physical-metrics').on('click', function () {
        copyCurrentPhysicalMetricsToEvolution();
    });

    $('.patient-evolution-view-toggle').on('click', function () {
        CURRENT_PATIENT_EVOLUTION_VIEW = $(this).data('evolution-view') || 'records';
        $('.patient-evolution-view-toggle').removeClass('btn-primary').addClass('btn-outline-primary');
        $(this).removeClass('btn-outline-primary').addClass('btn-primary');
        $('#patient-evolution-records-view').toggleClass('d-none', CURRENT_PATIENT_EVOLUTION_VIEW !== 'records');
        $('#patient-evolution-charts-view').toggleClass('d-none', CURRENT_PATIENT_EVOLUTION_VIEW !== 'charts');
        if (CURRENT_PATIENT_EVOLUTION_VIEW === 'charts') {
            renderPatientEvolutionCharts();
        }
    });

    $('.patient-evolution-chart-group').on('click', function () {
        CURRENT_PATIENT_EVOLUTION_CHART_GROUP = $(this).data('chart-group') || 'body';
        $('.patient-evolution-chart-group').removeClass('btn-primary').addClass('btn-outline-primary');
        $(this).removeClass('btn-outline-primary').addClass('btn-primary');
        renderPatientEvolutionCharts();
    });

    $('#patient-diagnosis-tab').on('shown.bs.tab', function () {
        if (!knowledgeBaseEnabled()) return;
        initPatientBodyMap();
        loadKnowledgeProblems(function () {
            loadSelectedPatientKnowledgeProblem();
        });
    });

    $('.patient-knowledge-mode-btn').on('click', function () {
        switchPatientKnowledgeMode($(this).data('knowledge-mode') || 'muscles');
    });

    $('#patient-editor-knowledge-problem').on('change', function () {
        updatePatientReportSuggestionButton();
        if (!knowledgeBaseEnabled()) return;
        loadSelectedPatientKnowledgeProblem();
    });

    $('#patient-knowledge-content').on('click', '.btn-add-knowledge-recommendation', function () {
        if (!knowledgeBaseEnabled()) return;
        importKnowledgeRecommendationTask(this);
    });

    $('#patient-knowledge-content').on('click', '.btn-import-knowledge-technique-tasks', function () {
        if (!knowledgeBaseEnabled()) return;
        importKnowledgeTechniqueTasks(this);
    });

    $('#btn-import-knowledge-problem-tasks').on('click', function () {
        if (!knowledgeBaseEnabled()) return;
        importKnowledgeProblemTasks(this);
    });

    $('#btn-body-map-front').on('click', function () {
        setPatientBodyMapView('FRONT');
    });

    $('#btn-body-map-back').on('click', function () {
        setPatientBodyMapView('BACK');
    });

    $('#btn-body-map-clear').on('click', function () {
        clearPatientBodyMapSelection();
    });

    $('#patient-body-map-results').on('click', '.btn-workoutx-exercise', function () {
        openWorkoutxExerciseModal($(this).data('exercise-id'), $(this).data('exercise-name'), $(this).data('workoutx-id') || '');
    });

    $('#workoutx-generated-plan').on('click', '.btn-workoutx-exercise', function () {
        openWorkoutxExerciseModal($(this).data('exercise-id'), $(this).data('exercise-name'), $(this).data('workoutx-id') || '');
    });

    $('#patient-body-map-results').on('click', '.btn-add-fitness-exercise', function () {
        addFitnessExerciseToPatient($(this).data('exercise-id'), this);
    });

    $('#workoutx-exercise-content').on('click', '.btn-add-workoutx-exercise', function () {
        addFitnessExerciseToPatient($(this).data('exercise-id'), this, $(this).data('workoutx-id') || '');
    });

    $('#btn-add-workoutx-exercise-footer').on('click', function () {
        addFitnessExerciseToPatient($(this).data('exercise-id'), this, $(this).data('workoutx-id') || '');
    });

    $('#btn-generate-workoutx-plan').on('click', function () {
        generateWorkoutxPlan(this);
    });

    $('#workoutx-generated-plan').on('click', '.btn-add-generated-workoutx-exercise', function () {
        addFitnessExerciseToPatient($(this).data('exercise-id'), this, $(this).data('workoutx-id') || '');
    });

    $('#workoutx-generated-plan').on('click', '.btn-add-workoutx-generated-plan', function () {
        addWorkoutxGeneratedPlanToPatient(this);
    });

    $(window).on('resize', function () {
        if (bodyMapEnabled()) {
            syncPatientBodyMapResultsHeight();
        }
        if (IS_ADMIN && !supportsDashboardInlineViews() && (currentCalendarView === 'patients' || currentCalendarView === 'upcoming')) {
            currentCalendarView = defaultAgendaCalendarView();
            renderWeekInfo();
        }
    });

    $('#patient-reports-body').on('click', '.btn-patient-report', function () {
        generatePatientReport(this);
    });

    $('#admin-patients-body').on('click', '.btn-edit-patient', function () {
        const patient = ADMIN_PATIENTS.find(item => String(item.id) === String($(this).data('patient-id')));
        openPatientEditorModal(patient || null);
    });

    $('#admin-patients-body').on('click', 'tr.admin-patient-row', function (event) {
        if ($(event.target).closest('button, a, input, select, textarea').length) {
            return;
        }
        const patient = ADMIN_PATIENTS.find(item => String(item.id) === String($(this).data('patient-id')));
        openPatientEditorModal(patient || null);
    });

    $('#admin-patients-body').on('click', '.btn-send-patient-invite', function () {
        openPatientInviteModal($(this).data('patient-id'), this);
    });

    $('#admin-patients-search, #admin-patients-sort').on('input change', function () {
        renderAdminPatients(ADMIN_PATIENTS);
    });

    $('#admin-patients-professional').on('change', function () {
        loadAdminPatients();
    });

    $(document).on('input change', '#dashboard-patients-search, #dashboard-patients-sort, #dashboard-patients-professional', function () {
        renderDashboardPatients(DASHBOARD_PATIENTS);
    });

    $(document).on('click', '.btn-dashboard-new-patient', function () {
        $('#btn-new-patient').trigger('click');
    });

    $(document).on('click', '.btn-dashboard-edit-patient', function () {
        const patient = DASHBOARD_PATIENTS.find(item => String(item.id) === String($(this).data('patient-id')));
        openPatientEditorModal(patient || null);
    });

    $(document).on('click', 'tr.dashboard-patient-row', function (event) {
        if ($(event.target).closest('button, a, input, select, textarea').length) {
            return;
        }
        const patient = DASHBOARD_PATIENTS.find(item => String(item.id) === String($(this).data('patient-id')));
        openPatientEditorModal(patient || null);
    });

    $(document).on('click', 'tr.dashboard-upcoming-row, tr.upcoming-appointment-row', function (event) {
        if ($(event.target).closest('button, a, input, select, textarea').length) {
            return;
        }
        const appointmentId = parseInt($(this).data('appointment-id') || 0, 10);
        if (appointmentId) {
            openAppointmentPaymentModal(appointmentId);
        }
    });

    $(document).on('click', '.btn-dashboard-send-patient-invite', function () {
        openPatientInviteModal($(this).data('patient-id'), this);
    });

    $(document).on('input', '#dashboard-upcoming-search', function () {
        renderDashboardUpcomingAppointments(DASHBOARD_UPCOMING_APPOINTMENTS);
    });

    $(document).on('change', '#dashboard-upcoming-scope, #dashboard-upcoming-professional', function () {
        dashboardUpcomingLoaded = false;
        loadDashboardUpcomingAppointments(false);
    });

    $('#patient-editor-form').submit(function (e) {
        e.preventDefault();
        savePatient(this);
    });

    $('#patient-editor-photo').on('change', function () {
        const file = this.files && this.files[0] ? this.files[0] : null;
        if (file) {
            $('#patient-editor-photo-preview').attr('src', URL.createObjectURL(file)).removeClass('d-none');
            $('#patient-editor-photo-status').text(file.name);
        }
    });

    $('#patient-history-tab').on('shown.bs.tab', function () {
        const patientId = parseInt($('#patient-editor-id').val() || '0', 10);
        loadPatientAppointmentHistory(patientId);
    });

    $('#patient-work-plan-tab').on('shown.bs.tab', function () {
        const patientId = parseInt($('#patient-editor-id').val() || '0', 10);
        loadPatientWorkPlan(patientId);
    });

    $('#patient-evolution-tab').on('shown.bs.tab', function () {
        const patientId = parseInt($('#patient-editor-id').val() || '0', 10);
        loadPatientEvolution(patientId);
    });

    $('#patientEditorModal').on('shown.bs.modal', function () {
        if ($('#patient-evolution-panel').hasClass('active') && CURRENT_PATIENT_EVOLUTION_VIEW === 'charts') {
            renderPatientEvolutionCharts();
        }
    });

    $('#patient-files-tab').on('shown.bs.tab', function () {
        const patientId = parseInt($('#patient-editor-id').val() || '0', 10);
        loadPatientFiles(patientId);
    });

    $('#patient-reports-tab').on('shown.bs.tab', function () {
        const patientId = parseInt($('#patient-editor-id').val() || '0', 10);
        loadPatientReports(patientId);
    });

    $('#btn-refresh-patient-reports').on('click', function () {
        loadPatientReports(CURRENT_PATIENT_REPORTS_ID || parseInt($('#patient-editor-id').val() || '0', 10));
    });

    $('#btn-show-custom-patient-report').on('click', function () {
        showCustomPatientReportForm();
    });

    $('#btn-show-suggested-patient-reports').on('click', function () {
        showPatientReportSuggestions();
    });

    $('#patient-report-suggestions-problem').on('change', function () {
        loadPatientReportSuggestionsForProblem($(this).val());
    });

    $('#patient-report-suggestions-body').on('click', '.btn-add-suggested-patient-report', function () {
        addSuggestedPatientReport(this);
    });

    $('#patient-reports-body').on('click', '.btn-configure-patient-report', function () {
        showPatientReportConfigForm($(this).data('report-id'));
    });

    $('#patient-report-payment-mode').on('change', function () {
        updatePatientReportPaymentFields();
    });

    $('#patient-report-config-form').on('submit', function (e) {
        e.preventDefault();
        savePatientReportConfig(this);
    });

    $('.patient-files-filter').on('click', function () {
        CURRENT_PATIENT_FILES_FILTER = $(this).data('files-filter') || 'all';
        $('.patient-files-filter').removeClass('btn-primary').addClass('btn-outline-primary');
        $(this).removeClass('btn-outline-primary').addClass('btn-primary');
        loadPatientFiles(CURRENT_PATIENT_FILES_ID || parseInt($('#patient-editor-id').val() || '0', 10));
    });

    $('#btn-show-patient-document-form').on('click', function () {
        const defaultType = CURRENT_PATIENT_FILES_FILTER === 'questionnaire' ? 'questionnaire' : 'file';
        showPatientDocumentForm(null, defaultType);
    });

    $('#patient-document-type').on('change', function () {
        updatePatientDocumentTypeFields(false, true);
    });

    $('#patient-document-form').on('submit', function (e) {
        e.preventDefault();
        savePatientDocument(this);
    });

    $('#patient-files-body').on('click', '.btn-delete-patient-document', function () {
        deletePatientDocument($(this).data('document-id'), $(this).closest('tr'));
    });

    $('#patient-files-body').on('click', '.btn-edit-patient-document', function () {
        const documentId = parseInt($(this).data('document-id') || '0', 10);
        const document = CURRENT_PATIENT_FILES_ROWS.find(item => parseInt(item.id || 0, 10) === documentId && item.can_delete);
        if (document) {
            showPatientDocumentForm(document);
        }
    });

    $('#patient-bonuses-tab').on('shown.bs.tab', function () {
        const patientId = parseInt($('#patient-editor-id').val() || '0', 10);
        loadPatientBonuses(patientId);
    });

    $('#btn-show-patient-evolution-form').on('click', function () {
        showPatientEvolutionForm();
    });

    $('#btn-cancel-patient-evolution-form').on('click', function () {
        hidePatientEvolutionForm();
    });

    $('#patient-evolution-form').on('submit', function (e) {
        e.preventDefault();
        savePatientEvolution(this);
    });

    $('#patient-evolution-list').on('click', '.btn-edit-patient-evolution', function () {
        const noteId = parseInt($(this).data('note-id') || '0', 10);
        const note = CURRENT_PATIENT_EVOLUTION_ROWS.find(item => parseInt(item.id, 10) === noteId) || null;
        if (note) {
            showPatientEvolutionForm(note);
        }
    });

    $('#btn-show-patient-work-plan-form').on('click', function () {
        showPatientWorkPlanForm();
    });

    $('#btn-open-work-plan-templates').on('click', function () {
        openTaskTemplatesSettings();
    });

    $('#patient-work-plan-template').on('change', function () {
        togglePatientWorkPlanTaskMode();
    });

    $('#btn-import-work-plan-template').on('click', function () {
        importWorkPlanTemplateToPatient(this);
    });

    $('#btn-cancel-patient-work-plan-form').on('click', function () {
        hidePatientWorkPlanForm();
    });

    $('#patient-work-plan-form').on('submit', function (e) {
        e.preventDefault();
        savePatientWorkPlanTask(this);
    });

    $('#patient-work-plan-pending, #patient-work-plan-completed-list').on('click', '.btn-edit-work-plan-task', function () {
        const taskId = parseInt($(this).data('task-id') || '0', 10);
        const task = CURRENT_PATIENT_WORK_PLAN_ROWS.find(item => parseInt(item.id, 10) === taskId) || null;
        if (task) {
            showPatientWorkPlanForm(task);
        }
    });

    $('#patient-work-plan-pending, #patient-work-plan-completed-list').on('click', '.btn-toggle-work-plan-task', function () {
        setPatientWorkPlanTaskStatus(this);
    });

    $('#patient-work-plan-pending, #patient-work-plan-completed-list').on('click', '.btn-delete-work-plan-task', function () {
        deletePatientWorkPlanTask(this);
    });

    $('#task-template-form').on('submit', function (e) {
        e.preventDefault();
        saveWorkPlanTaskTemplate();
    });

    $('#task-template-item-form').on('submit', function (e) {
        e.preventDefault();
        saveWorkPlanTaskTemplateItem();
    });

    $('#btn-new-task-template').on('click', function () {
        openTaskTemplateModal();
    });

    $('#task-templates-list').on('click', '.btn-edit-task-template', function () {
        const templateId = parseInt($(this).data('template-id') || '0', 10);
        const template = WORK_PLAN_TASK_TEMPLATES.find(item => parseInt(item.id, 10) === templateId) || null;
        if (template) {
            openTaskTemplateModal(template);
        }
    });

    $('#task-templates-list').on('click', '.btn-delete-task-template', function () {
        deleteWorkPlanTaskTemplate(this);
    });

    $('#task-templates-list').on('click', '.btn-edit-task-template-item', function () {
        const templateId = parseInt($(this).data('template-id') || '0', 10);
        const itemId = parseInt($(this).data('item-id') || '0', 10);
        const template = WORK_PLAN_TASK_TEMPLATES.find(item => parseInt(item.id, 10) === templateId) || null;
        const task = template && Array.isArray(template.items)
            ? template.items.find(item => parseInt(item.id, 10) === itemId)
            : null;
        if (template && task) {
            openTaskTemplateItemModal(task, templateId);
        }
    });

    $('#task-templates-list').on('click', '.btn-delete-task-template-item', function () {
        deleteWorkPlanTaskTemplateItem(this);
    });

    $('#task-templates-list').on('click', '.btn-add-task-template-item', function () {
        const templateId = parseInt($(this).data('template-id') || '0', 10);
        openTaskTemplateItemModal(null, templateId);
    });

    $('#task-templates-settings-tab').on('shown.bs.tab', function () {
        loadWorkPlanTaskTemplates();
    });

    $('#btn-show-create-patient-bonus').on('click', function () {
        $('#patient-bonus-create-form').toggleClass('d-none');
        populatePatientBonusCreateSelect();
    });

    $('#patient-bonus-create-bonus').on('change', function () {
        syncPatientBonusCreateSessions();
    });

    $('#patient-bonus-create-form').on('submit', function (e) {
        e.preventDefault();
        createPatientBonusForCurrentPatient();
    });

    $('#patient-bonuses-body').on('click', '.btn-save-patient-bonus', function () {
        savePatientBonusAdjustment(this);
    });

    $('#patient-bonuses-body').on('click', '.btn-delete-patient-bonus', function () {
        deletePatientBonusFromList(this);
    });

    $('#bonus-list-search, #bonus-list-sort').on('input change', function () {
        renderBonusList(CURRENT_BONUS_LIST, CURRENT_BONUS_ADMIN_VIEW);
    });

    $('#bonus-list-professional').on('change', function () {
        if (CURRENT_BONUS_ADMIN_VIEW) {
            loadBonusList('api/bonuses.php?action=admin_list', true);
        }
    });

    $('#bonus-list-body').on('click', '.btn-save-patient-bonus', function () {
        savePatientBonusAdjustment(this);
    });

    $('#bonus-list-body').on('click', '.btn-delete-patient-bonus', function () {
        deletePatientBonusFromList(this);
    });

    $('#btn-buy-bonus-card').click(function () {
        startBonusPayment('card');
    });

    $('#btn-buy-bonus-bizum').click(function () {
        startBonusPayment('bizum');
    });

    $('#btn-confirm-action').click(function () {
        let status = $('#modalStatus').val();
        if (status === 'available') {
            bookAppointment();
        } else {
            cancelAppointment();
        }
    });

    $('#btn-pay-card').click(function () {
        if (currentPaymentAppointmentId) {
            startRedsysPayment(currentPaymentAppointmentId, 'card');
        }
    });

    $('#btn-pay-bizum').click(function () {
        if (currentPaymentAppointmentId) {
            startRedsysPayment(currentPaymentAppointmentId, 'bizum');
        }
    });

    $('#patientSelect').change(function () {
        if (bookingContextLoading) return;
        if (IS_SUPERADMIN) {
            syncBookingProfessionalFromPatient();
        } else {
            refreshBookingBonusNotice();
        }
    });

    $('#booking-professional').change(function () {
        if (bookingContextLoading) return;
        if (IS_SUPERADMIN) {
            renderBookingProfessionalCards();
            loadBookingContextForProfessional($(this).val());
            updateBookingPatientProfessionalNote(bookingPatientById($('#patientSelect').val()));
        }
    });

    $('#booking-professional-cards').on('click', '.patient-professional-card', function () {
        if (bookingContextLoading) return;
        const professionalId = $(this).data('professional-id');
        $('#booking-professional').val(String(professionalId)).trigger('change');
    });

    $('#booking-consultation-cards').on('click', '.booking-consultation-card:not(.is-disabled)', function () {
        if (bookingContextLoading) return;
        CURRENT_BOOKING_CONSULTATION_TYPE = $(this).data('consultation-type') || '';
        renderBookingServiceOptions();
        refreshBookingBonusNotice();
    });

    $(document).on('change', '#cancel-create-compensation-bonus', function () {
        $('#cancel-compensation-bonus-text').html(this.checked
            ? 'Se crear&aacute; un bono canjeable para una nueva sesi&oacute;n.'
            : 'No se crear&aacute; un bono canjeable.');
    });

    $('#service-option').change(function () {
        if (bookingContextLoading) return;
        refreshBookingBonusNotice();
    });

    $('#btn-open-settings').click(function () {
        loadClosedDays();
        loadPaymentSettings();
        settingsModal.show();
    });

    $('#btn-change-password').click(function () {
        $('#change-password-form')[0].reset();
        $('#change-password-alert').addClass('d-none').text('');
        if (changePasswordModal) {
            changePasswordModal.show();
        }
    });

    $('#btn-my-profile').click(function () {
        openPatientSelfDataModal();
    });

    $('#patient-self-photo').on('change', function () {
        const file = this.files && this.files[0] ? this.files[0] : null;
        if (file) {
            $('#patient-self-photo-preview').attr('src', URL.createObjectURL(file)).removeClass('d-none');
            $('#patient-self-photo-status').text(file.name);
        }
    });

    $('#patient-self-data-form').submit(function (e) {
        e.preventDefault();
        savePatientSelfData(this);
    });

    $('#change-password-form').submit(function (e) {
        e.preventDefault();
        changeOwnPassword(this.querySelector('button[type="submit"]'));
    });

    $('#btn-open-closed-modal').click(function () {
        $('#closed-start-date').val('');
        $('#closed-end-date').val('');
        $('#closed-reason').val('');
        $('#closed-is-global').prop('checked', false);
        if (closedDayModal) {
            closedDayModal.show();
        }
    });

    $('#closedDayModal').on('hidden.bs.modal', function () {
        $('body').removeClass('settings-secondary-modal-open');
        if ($('#settingsModal').hasClass('show')) {
            document.body.classList.add('modal-open');
        }
    });

    $('#professionalEditorModal, #professionalKnowledgeSectorsModal').on('hidden.bs.modal', function () {
        $('body').removeClass('settings-secondary-modal-open');
        if ($('#settingsModal').hasClass('show')) {
            document.body.classList.add('modal-open');
        }
    });

    $('#professionalKnowledgeSectorsModal').on('show.bs.modal', function () {
        if ($('#professionalEditorModal').hasClass('show')) {
            $('body').addClass('professional-editor-secondary-modal-open');
        }
    });

    $('#professionalKnowledgeSectorsModal').on('hidden.bs.modal', function () {
        $('body').removeClass('professional-editor-secondary-modal-open');
        if ($('#professionalEditorModal').hasClass('show')) {
            document.body.classList.add('modal-open');
        }
    });

    $('#professionalTransferModal').on('hidden.bs.modal', function () {
        $('body').removeClass('settings-secondary-modal-open');
        if ($('#settingsModal').hasClass('show')) {
            document.body.classList.add('modal-open');
        }
    });

    $('#closedDayModal, #professionalEditorModal, #professionalKnowledgeSectorsModal, #professionalTransferModal').on('show.bs.modal', function () {
        if ($('#settingsModal').hasClass('show')) {
            $('body').addClass('settings-secondary-modal-open');
        }
    });

    $('#dashboardCustomConfigModal').on('show.bs.modal', function () {
        if ($('#settingsModal').hasClass('show')) {
            $('body').addClass('settings-secondary-modal-open');
        }
    });

    $('#dashboardCustomConfigModal').on('hidden.bs.modal', function () {
        $('body').removeClass('settings-secondary-modal-open');
        if ($('#settingsModal').hasClass('show')) {
            document.body.classList.add('modal-open');
        }
    });

    $('#taskTemplateModal, #taskTemplateItemModal').on('show.bs.modal', function () {
        if ($('#settingsModal').hasClass('show')) {
            $('body').addClass('settings-secondary-modal-open');
        }
    });

    $('#taskTemplateModal, #taskTemplateItemModal').on('hidden.bs.modal', function () {
        $('body').removeClass('settings-secondary-modal-open');
        if ($('#settingsModal').hasClass('show')) {
            document.body.classList.add('modal-open');
        }
    });

    $('#patientEditorModal').on('show.bs.modal', function () {
        if ($('#adminPatientsModal').hasClass('show')) {
            $('body').addClass('patients-secondary-modal-open');
        }
    });

    $('#patientEditorModal').on('hidden.bs.modal', function () {
        $('body').removeClass('patients-secondary-modal-open');
        if ($('#adminPatientsModal').hasClass('show')) {
            document.body.classList.add('modal-open');
        }
    });

    $('#patientWorkPlanTaskModal, #patientDocumentModal, #patientReportConfigModal, #patientReportSuggestionsModal, #patientEvolutionModal, #workoutxExerciseModal').on('show.bs.modal', function () {
        if ($('#patientEditorModal').hasClass('show')) {
            $('body').addClass('patient-editor-secondary-modal-open');
        }
    });

    $('#patientWorkPlanTaskModal').on('show.bs.modal', function () {
        if ($('#appointmentPaymentModal').hasClass('show')) {
            $('body').addClass('appointment-work-plan-modal-open');
        }
    });

    $('#patientWorkPlanTaskModal, #patientDocumentModal, #patientReportConfigModal, #patientReportSuggestionsModal, #patientEvolutionModal, #workoutxExerciseModal').on('hidden.bs.modal', function () {
        $('body').removeClass('patient-editor-secondary-modal-open');
        if ($('#patientEditorModal').hasClass('show')) {
            document.body.classList.add('modal-open');
        }
    });

    $('#patientWorkPlanTaskModal').on('hidden.bs.modal', function () {
        $('body').removeClass('appointment-work-plan-modal-open');
        resetPatientWorkPlanFormFields();
        if ($('#appointmentPaymentModal').hasClass('show')) {
            document.body.classList.add('modal-open');
        }
    });

    $('#adminPatientsModal').on('hidden.bs.modal', function () {
        $('body').removeClass('patients-secondary-modal-open');
    });

    $('#add-closed-form').submit(function (e) {
        e.preventDefault();
        $.ajax({
            url: 'api/admin.php?action=add_closed_day',
            method: 'POST',
            data: {
                start_date: $('#closed-start-date').val(),
                end_date: $('#closed-end-date').val(),
                reason: $('#closed-reason').val(),
                is_global: $('#closed-is-global').is(':checked') ? '1' : '0'
            },
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    $('#closed-start-date').val('');
                    $('#closed-end-date').val('');
                    $('#closed-reason').val('');
                    $('#closed-is-global').prop('checked', false);
                    loadClosedDays();
                    renderWeekInfo(); // Refresh bg
                    if (closedDayModal) {
                        closedDayModal.hide();
                    }
                    let msg = `${res.inserted || 0} día(s) añadidos`;
                    if (res.skipped) {
                        msg += `, ${res.skipped} ya existían`;
                    }
                    $('#admin-actions-msg').text(msg).fadeIn().delay(3000).fadeOut();
                } else {
                    alert(res.error);
                }
            }
        });
    });

    $('#payment-settings-form').submit(function (e) {
        e.preventDefault();
        saveAllSettings($('#btn-save-all-settings')[0] || null);
    });

    $('#btn-save-all-settings').click(function () {
        saveAllSettings(this);
    });
    $('#new-patient-booking-mode').on('change', function () {
        updateNewPatientBookingModeUi();
    });

    $('#btn-new-professional').click(function () {
        openProfessionalEditor(-1);
    });

    $('#professional-editor-form').submit(function (e) {
        e.preventDefault();
        saveProfessionalEditor(this.querySelector('button[type="submit"]'));
    });
    $('#professional-editor-knowledge-mode').on('change', updateProfessionalKnowledgeSectorUi);
    $('#btn-professional-knowledge-sectors').on('click', openProfessionalKnowledgeSectorsModal);
    $('#btn-save-professional-knowledge-sectors').on('click', saveProfessionalKnowledgeSectorsSelection);
    $('#professional-editor-email').on('input change', function () {
        updateProfessionalSummaryEmailUi();
    });

    $('#professional-editor-photo').on('change', function () {
        PROFESSIONAL_PHOTO_FILE = this.files && this.files[0] ? this.files[0] : null;
        if (PROFESSIONAL_PHOTO_FILE) {
            if (PROFESSIONAL_PHOTO_FILE.size > 5 * 1024 * 1024) {
                showProfessionalEditorAlert('danger', 'La foto del profesional no puede superar 5 MB.');
                this.value = '';
                PROFESSIONAL_PHOTO_FILE = null;
                return;
            }
            $('#professional-editor-alert').addClass('d-none').text('');
            $('#professional-editor-photo-preview').attr('src', URL.createObjectURL(PROFESSIONAL_PHOTO_FILE)).removeClass('d-none');
            $('#professional-editor-photo-status').text(PROFESSIONAL_PHOTO_FILE.name);
        }
    });

    $(document).on('click', '.btn-edit-professional', function () {
        openProfessionalEditor(parseInt($(this).data('index'), 10));
    });

    $(document).on('click', '.btn-delete-professional', function () {
        deleteProfessional(parseInt($(this).data('index'), 10), this);
    });

    $('#btn-confirm-transfer-delete').click(function () {
        const professionalId = parseInt($('#transfer-delete-professional-id').val() || '0', 10);
        const targetId = parseInt($('#transfer-delete-target').val() || '0', 10);
        performProfessionalDelete(professionalId, targetId, this);
    });

    $('#email-provider').change(function () {
        toggleEmailProviderSettings();
    });

    $('#online-payment-enabled').change(function () {
        togglePaymentSettings();
    });

    $('#calendar-provider').change(function () {
        toggleCalendarSettings();
    });

    $('#appointment-delivery-mode').change(function () {
        togglePriceRows();
        renderServicesSettings();
    });

    $('#bonuses-enabled').change(function () {
        toggleBonusesSettings();
    });

    $(document).on('change', '.available-session-type', function () {
        if (!$('.available-session-type:checked').length) {
            defaultAppointmentServiceKeys().forEach(type => {
                $(`.available-session-type[value="${type}"]`).prop('checked', true);
            });
        }
        togglePriceRows();
        renderServicesSettings();
    });

    $(document).on('change', '.available-session-duration', function () {
        if (!$('.available-session-duration:checked').length) {
            defaultAppointmentDurations().forEach(duration => {
                $(`.available-session-duration[value="${duration}"]`).prop('checked', true);
            });
        }
        renderServicesSettings();
    });

    $('#profile-image').change(function () {
        const file = this.files && this.files[0] ? this.files[0] : null;
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function (e) {
            $('#profile-image-preview').attr('src', e.target.result);
            $('#profile-image-preview-row').attr('style', '');
            $('#profile-image-status').text('Imagen nueva lista para guardar.');
        };
        reader.readAsDataURL(file);
    });

    $('#landing-image').change(function () {
        const file = this.files && this.files[0] ? this.files[0] : null;
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function (e) {
            $('#landing-image-preview').attr('src', e.target.result);
            $('#landing-image-preview-row').attr('style', '');
            $('#landing-image-status').text('Imagen nueva lista para guardar.');
        };
        reader.readAsDataURL(file);
    });

    $('#primary-color').change(function () {
        $('#primary-color-text').val(this.value);
        document.documentElement.style.setProperty('--primary-color', this.value);
        refreshPatientBodyMapSelectedColors();
    });

    $('#primary-color-text').on('input', function () {
        const value = $(this).val().trim();
        if (/^#[0-9a-fA-F]{6}$/.test(value)) {
            $('#primary-color').val(value);
            document.documentElement.style.setProperty('--primary-color', value);
            refreshPatientBodyMapSelectedColors();
        }
    });

    $('#dashboard-config-mode').on('change', function () {
        toggleDashboardConfigModeControls();
    });

    $('#btn-open-dashboard-custom-config').on('click', function () {
        if (!planFeatureEnabled('ui.customization', false)) {
            showSettingsAlert('#interface-settings-alert', 'danger', 'La personalizacion de interfaz no esta disponible en este plan.');
            return;
        }
        openDashboardCustomConfigModal();
    });

    $('#btn-save-dashboard-custom-config').on('click', function () {
        saveDashboardCustomConfig(this);
    });

    $('#btn-google-connect').click(function () {
        savePaymentSettings('#email-settings-alert', function () {
            window.location.href = 'google_oauth_start.php';
        }, this, 'email');
    });

    $('#btn-google-connect-calendar').click(function () {
        savePaymentSettings('#calendar-settings-alert', function () {
            window.location.href = 'google_oauth_start.php';
        }, this, 'calendar');
    });
});

function bookAppointment() {
    if (isAppointmentRequestInProgress) {
        return;
    }

    let data = {
        date: $('#modalDate').val(),
        time: $('#modalTime').val(),
        consultation_type: selectedConsultationType(),
        service_type: selectedServiceType(),
        service_option_id: selectedServiceOption() ? selectedServiceOption().id : ''
    };

    if (IS_ADMIN) {
        data.user_id = $('#patientSelect').val();
        if (!data.user_id) { alert(`Selecciona un ${sectorLabel('patient', 'singular', 'paciente')}`); return; }
    }
    if (IS_SUPERADMIN) {
        data.professional_id = $('#booking-professional').val();
        if (!data.professional_id) { alert('Selecciona un profesional'); return; }
    } else if (!IS_ADMIN && shouldChooseProfessionalInSlot()) {
        data.professional_id = CURRENT_SLOT_SELECTED_PROFESSIONAL_ID;
        if (!data.professional_id) { alert('Selecciona un profesional disponible'); return; }
    } else if (!IS_ADMIN && CURRENT_PATIENT_SELECTED_PROFESSIONAL_ID) {
        data.professional_id = CURRENT_PATIENT_SELECTED_PROFESSIONAL_ID;
    }
    if (!data.service_option_id) {
        alert('Selecciona un servicio disponible');
        return;
    }

    setAppointmentActionLoading(true);

    $.ajax({
        url: 'api/appointments.php?action=book',
        method: 'POST',
        data: data,
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                alert(res.error);
                setAppointmentActionLoading(false);
                return;
            }

            invalidateDashboardUpcomingAppointments();
            renderWeekInfo();
            if (!IS_ADMIN) {
                loadPatientPortalSummary();
            }
            setAppointmentActionLoading(false);

            if (res.bonus_applied == 1) {
                const remaining = parseInt(res.bonus_remaining || 0, 10);
                $('#modalTitle').text('Cita reservada');
                const ownerText = IS_ADMIN ? `La cita ha quedado reservada correctamente e incluida con el bono del ${sectorLabel('patient', 'singular', 'paciente')}.` : 'Tu cita ha quedado reservada correctamente e incluida con tu bono.';
                $('#modalDesc').html(`${ownerText}<br><br>Quedan ${remaining} ${remaining === 1 ? 'sesi&oacute;n' : 'sesiones'} disponibles.`);
                $('#btn-confirm-action').addClass('d-none');
                $('#payment-options').addClass('d-none');
                setTimeout(function () {
                    appointmentModal.hide();
                }, 2500);
                return;
            }

            if (!IS_ADMIN && PAYMENT_SETTINGS.online_payment_enabled == 1 && res.appointment_id) {
                currentPaymentAppointmentId = res.appointment_id;
                $('#modalTitle').text('Cita reservada');
                const serviceLabel = res.service_label || serviceTypeLabel(data.service_type);
                const price = res.price || appointmentPriceForType(data.consultation_type, data.service_type);
                $('#modalDesc').html(`Tu cita ${serviceLabel.toLowerCase()} ${consultationTypeLabel(data.consultation_type).toLowerCase()} ha quedado reservada correctamente.<br><br>Si quieres, puedes pagarla ahora (${formatPrice(price)} €).`);
                $('#btn-confirm-action').addClass('d-none');
                $('#payment-options-text').text('');
                $('#payment-options').removeClass('d-none');
                return;
            }

            appointmentModal.hide();
        },
        error: function () {
            alert('Error de conexión al reservar la cita');
            setAppointmentActionLoading(false);
        }
    });
}

function selectedConsultationType() {
    const option = selectedServiceOption();
    if (option) {
        return option.consultation_type;
    }
    const mode = PAYMENT_SETTINGS.appointment_delivery_mode || 'both';
    if (mode === 'online') {
        return 'online';
    }
    if (mode === 'presencial') {
        return 'presencial';
    }
    return $('#consultation-type').val() === 'online' ? 'online' : 'presencial';
}

function selectedServiceType() {
    const option = selectedServiceOption();
    if (option) {
        return option.service_key || 'individual';
    }
    return isCoupleServiceEnabled() && $('#service-type').val() === 'couple' ? 'couple' : 'individual';
}

function selectedServiceOption() {
    const selectedId = parseInt($('#service-option').val(), 10);
    if (!selectedId) {
        return null;
    }
    return ACTIVE_SERVICE_OPTIONS.find(option => parseInt(option.id, 10) === selectedId) || null;
}

function consultationTypeLabel(type) {
    return type === 'online' ? 'Online' : 'Presencial';
}

function serviceTypeLabel(type) {
    const catalogItem = appointmentServiceCatalog().find(item => item.key === type);
    if (catalogItem) {
        return catalogItem.label;
    }
    const labels = {
        individual: 'Individual',
        couple: 'Pareja',
        family: 'Familiar',
        group: 'Grupo'
    };
    return labels[type] || type || 'Individual';
}

function isCoupleServiceEnabled() {
    return String(PAYMENT_SETTINGS.available_session_types || 'individual').split(',').includes('couple');
}

function appointmentPriceForType(consultationType, serviceType = 'individual') {
    let rawPrice = PAYMENT_SETTINGS.appointment_price || '70.00';
    if (['couple', 'family', 'group'].includes(serviceType)) {
        rawPrice = consultationType === 'online'
            ? (PAYMENT_SETTINGS.online_couple_appointment_price || PAYMENT_SETTINGS.couple_appointment_price || '90.00')
            : (PAYMENT_SETTINGS.couple_appointment_price || '90.00');
    } else if (consultationType === 'online') {
        rawPrice = PAYMENT_SETTINGS.online_appointment_price || PAYMENT_SETTINGS.appointment_price || '70.00';
    }
    const price = parseFloat(String(rawPrice).replace(',', '.'));
    return Number.isFinite(price)
        ? price.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : rawPrice;
}

function formatPrice(value) {
    const price = parseFloat(String(value).replace(',', '.'));
    return Number.isFinite(price)
        ? price.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : value;
}

function renderBookingServiceOptions() {
    const $select = $('#service-option');
    $select.empty();
    if (!selectedSlotIsAllowedForCurrentSettings()) {
        renderBookingConsultationCards();
        $select.append('<option value="">Este profesional no tiene disponible este horario</option>');
        return;
    }
    const selectedConsultation = ensureBookingConsultationType();
    renderBookingConsultationCards();
    const visibleOptions = ACTIVE_SERVICE_OPTIONS.filter(option => {
        return (!selectedConsultation || option.consultation_type === selectedConsultation)
            && selectedSlotCanFitDuration(option.duration_minutes);
    });
    visibleOptions.forEach(option => {
        const label = `${option.service_name} · ${option.duration_minutes} min · ${formatPrice(option.price)} €`;
        $select.append(`<option value="${option.id}">${label}</option>`);
    });
    if (!visibleOptions.length) {
        $select.append('<option value="">No hay servicios disponibles para esta hora</option>');
    }
}

function refreshBookingBonusNotice() {
    const $notice = $('#booking-bonus-notice');
    if (!$notice.length || $('#modalStatus').val() !== 'available') {
        return;
    }

    $notice.addClass('d-none').removeClass('alert-success alert-info').text('');
    const option = selectedServiceOption();
    if (!option || option.service_key !== 'individual') {
        return;
    }

    const patientId = IS_ADMIN ? $('#patientSelect').val() : '';
    if (IS_ADMIN && !patientId) {
        return;
    }

    $.ajax({
        url: 'api/appointments.php?action=get_bonus_balance',
        method: 'GET',
        dataType: 'json',
        data: IS_ADMIN ? { user_id: patientId } : {},
        success: function (res) {
            if (!res.success) {
                return;
            }

            PATIENT_BONUS_BALANCE = res;
            const remaining = parseInt(res.total_remaining || 0, 10);
            if (remaining <= 0) {
                return;
            }

            const firstBonus = Array.isArray(res.bonuses) && res.bonuses.length ? res.bonuses[0] : null;
            const bonusName = firstBonus && firstBonus.name ? ` (${escapeHtml(firstBonus.name)})` : '';
            $notice
                .removeClass('d-none')
                .addClass('alert-success')
                .html(`Incluida con bono${bonusName}: ${remaining} ${remaining === 1 ? 'sesi&oacute;n restante' : 'sesiones restantes'}.`);
        }
    });
}

function setAvailableWeekdays(value) {
    const activeDays = String(value || '1,2,3,4,5')
        .split(',')
        .map(day => day.trim());
    $('.available-weekday').prop('checked', false);
    activeDays.forEach(day => {
        $(`.available-weekday[value="${day}"]`).prop('checked', true);
    });
}

function appointmentServiceCatalog() {
    const services = Array.isArray(APP_SECTOR_TEXTS.appointmentServices) ? APP_SECTOR_TEXTS.appointmentServices : [];
    if (services.length) {
        return services
            .map((item, index) => ({
                key: String(item.key || '').trim(),
                label: String(item.label || item.name || item.key || '').trim(),
                name: String(item.name || item.label || item.key || '').trim(),
                enabledByDefault: item.enabledByDefault === true,
                sort: Number.isFinite(parseInt(item.sort, 10)) ? parseInt(item.sort, 10) : (index + 1) * 10
            }))
            .filter(item => /^[a-z0-9_-]{2,32}$/.test(item.key))
            .sort((a, b) => a.sort - b.sort || a.label.localeCompare(b.label));
    }

    const labels = APP_SECTOR_TEXTS.services && typeof APP_SECTOR_TEXTS.services === 'object' ? APP_SECTOR_TEXTS.services : {
        individual: 'Individual',
        couple: 'Parejas',
        family: 'Familiar',
        group: 'Grupos'
    };
    return Object.keys(labels).map((key, index) => ({
        key,
        label: labels[key] || key,
        name: labels[key] || key,
        enabledByDefault: index === 0,
        sort: (index + 1) * 10
    }));
}

function appointmentDurationCatalog() {
    const durations = Array.isArray(APP_SECTOR_TEXTS.appointmentDurations) ? APP_SECTOR_TEXTS.appointmentDurations : [];
    const items = durations.length ? durations : [
        { minutes: 60, label: '60 minutos', enabledByDefault: true },
        { minutes: 90, label: '90 minutos', enabledByDefault: false },
        { minutes: 120, label: '120 minutos', enabledByDefault: false }
    ];
    return items
        .map((item, index) => ({
            minutes: parseInt(item.minutes, 10),
            label: String(item.label || `${item.minutes} minutos`).trim(),
            enabledByDefault: item.enabledByDefault === true,
            sort: Number.isFinite(parseInt(item.sort, 10)) ? parseInt(item.sort, 10) : (index + 1) * 10
        }))
        .filter(item => Number.isFinite(item.minutes) && item.minutes > 0 && item.minutes <= 480)
        .sort((a, b) => a.sort - b.sort || a.minutes - b.minutes);
}

function defaultAppointmentServiceKeys() {
    const catalog = appointmentServiceCatalog();
    const defaults = catalog.filter(item => item.enabledByDefault).map(item => item.key);
    return defaults.length ? defaults : (catalog[0] ? [catalog[0].key] : ['individual']);
}

function defaultAppointmentDurations() {
    const catalog = appointmentDurationCatalog();
    const defaults = catalog.filter(item => item.enabledByDefault).map(item => item.minutes);
    return defaults.length ? defaults : (catalog[0] ? [catalog[0].minutes] : [60]);
}

function renderAvailableSessionControls() {
    const $types = $('#available-session-types-list');
    if ($types.length) {
        $types.html(appointmentServiceCatalog().map(item => {
            const id = `available-session-${item.key}`;
            return `
                <div class="col-6 col-md-3">
                    <div class="form-check">
                        <input class="form-check-input available-session-type" type="checkbox" id="${id}" value="${escapeHtml(item.key)}">
                        <label class="form-check-label" for="${id}">${escapeHtml(item.label)}</label>
                    </div>
                </div>
            `;
        }).join(''));
    }

    const $durations = $('#available-session-durations-list');
    if ($durations.length) {
        $durations.html(appointmentDurationCatalog().map(item => {
            const id = `available-duration-${item.minutes}`;
            return `
                <div class="col-4 col-md-3">
                    <div class="form-check">
                        <input class="form-check-input available-session-duration" type="checkbox" id="${id}" value="${item.minutes}">
                        <label class="form-check-label" for="${id}">${escapeHtml(item.label)}</label>
                    </div>
                </div>
            `;
        }).join(''));
    }
}

function setAvailableSessionTypes(value) {
    const defaults = defaultAppointmentServiceKeys();
    const allowed = appointmentServiceCatalog().map(item => item.key);
    const activeTypes = String(value || defaults.join(','))
        .split(',')
        .map(type => type.trim())
        .filter(type => allowed.includes(type));
    $('.available-session-type').prop('checked', false);
    (activeTypes.length ? activeTypes : defaults).forEach(type => {
        $(`.available-session-type[value="${type}"]`).prop('checked', true);
    });
}

function selectedSessionTypes() {
    const types = [];
    $('.available-session-type:checked').each(function () {
        const type = String(this.value || '').trim();
        if (type && !types.includes(type)) {
            types.push(type);
        }
    });
    return types.length ? types : defaultAppointmentServiceKeys();
}

function setAvailableSessionDurations(value) {
    const defaults = defaultAppointmentDurations().map(String);
    const allowed = appointmentDurationCatalog().map(item => String(item.minutes));
    const activeDurations = String(value || defaults.join(','))
        .split(',')
        .map(duration => duration.trim())
        .filter(duration => allowed.includes(duration));
    $('.available-session-duration').prop('checked', false);
    (activeDurations.length ? activeDurations : defaults).forEach(duration => {
        $(`.available-session-duration[value="${duration}"]`).prop('checked', true);
    });
}

function cancelAppointment() {
    setAppointmentActionLoading(true);
    const data = {
        appointment_id: currentCancelAppointmentId || '',
        date: $('#modalDate').val(),
        time: $('#modalTime').val()
    };
    const $compensationCheckbox = $('#cancel-create-compensation-bonus');
    if ($compensationCheckbox.length) {
        data.create_compensation_bonus = $compensationCheckbox.is(':checked') ? '1' : '0';
    }

    $.ajax({
        url: 'api/appointments.php?action=cancel',
        method: 'POST',
        data: data,
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                appointmentModal.hide();
                refreshAfterAppointmentPaymentUpdate();
                if (!IS_ADMIN) {
                    loadPatientPortalSummary();
                }
                setAppointmentActionLoading(false);
            } else {
                alert(res.error);
                setAppointmentActionLoading(false);
            }
        },
        error: function () {
            alert('Error de conexión al cancelar la cita');
            setAppointmentActionLoading(false);
        }
    });
}

function startRedsysPayment(appointmentId, paymentMethod, options = {}) {
    const $button = options.button ? $(options.button) : $();
    const originalButtonHtml = $button.length ? $button.html() : '';
    if ($button.length) {
        $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Conectando...');
    }
    const finishLoading = () => {
        setAppointmentActionLoading(false);
        if ($button.length) {
            $button.prop('disabled', false).html(originalButtonHtml);
        }
    };
    setAppointmentActionLoading(true, paymentMethod);
    $.ajax({
        url: 'api/payments.php?action=create_redsys_form',
        method: 'POST',
        data: {
            appointment_id: appointmentId,
            payment_method: paymentMethod
        },
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                if (options.hideAppointmentModalOnError !== false) {
                    appointmentModal.hide();
                }
                invalidateDashboardUpcomingAppointments();
                renderWeekInfo();
                alert(res.error || options.failureMessage || 'La cita se ha reservado, pero no se pudo iniciar el pago.');
                finishLoading();
                return;
            }

            $('#redsys-payment-form').remove();
            $('body').append(res.form_html);
            $('#redsys-payment-form').trigger('submit');
            finishLoading();
        },
        error: function () {
            if (options.hideAppointmentModalOnError !== false) {
                appointmentModal.hide();
            }
            invalidateDashboardUpcomingAppointments();
            renderWeekInfo();
            alert(options.failureMessage || 'La cita se ha reservado, pero no se pudo conectar con Redsys.');
            finishLoading();
        }
    });
}

function togglePatientBonusActions() {
    if (IS_ADMIN) {
        return;
    }
    const bonuses = appFeatureEnabled('bonuses.enabled', false);
    const canBuyBonuses = bonuses && paymentPlanEnabled() && PAYMENT_SETTINGS.bonuses_enabled == 1 && PAYMENT_SETTINGS.online_payment_enabled == 1;
    const canUseInternalVouchers = PAYMENT_SETTINGS.create_compensation_bonus_on_paid_cancel === undefined ? true : PAYMENT_SETTINGS.create_compensation_bonus_on_paid_cancel == 1;
    const showBonusArea = bonuses && (canBuyBonuses || canUseInternalVouchers);
    $('#patient-bonus-actions').attr('style', '');
    $('#btn-buy-bonus').toggle(canBuyBonuses);
    $('#btn-my-bonuses').toggle(showBonusArea);
    $('#btn-mobile-buy-bonus').closest('li').toggle(canBuyBonuses);
    $('#btn-mobile-my-bonuses').closest('li').toggle(showBonusArea);
    $('#patient-bonus-actions .dashboard-mobile-menu').removeClass('d-none');
}

function openBuyBonusModal() {
    if (!appFeatureEnabled('bonuses.enabled', false) || !paymentPlanEnabled()) return;
    if (!bonusesModal) return;
    selectedBonusToBuy = null;
    $('#bonusesModalTitle').text('Comprar bono');
    $('#bonuses-modal-alert').addClass('d-none').text('');
    $('#bonus-list-panel').addClass('d-none');
    $('#bonus-list-tools').addClass('d-none');
    $('#bonus-payment-options').addClass('d-none');
    $('#bonus-catalog-panel').removeClass('d-none');
    $('#bonus-catalog-list').html('<div class="col-12 text-center text-muted py-4">Cargando bonos...</div>');
    bonusesModal.show();

    $.ajax({
        url: 'api/bonuses.php?action=catalog',
        dataType: 'json',
        success: function (res) {
            if (!res.success || res.bonuses_enabled != 1) {
                showBonusesModalAlert('danger', res.error || 'La compra de bonos no esta activa.');
                $('#bonus-catalog-list').empty();
                return;
            }
            renderBonusCatalog(res.bonuses || []);
        },
        error: function () {
            showBonusesModalAlert('danger', 'Error de conexion al cargar los bonos.');
            $('#bonus-catalog-list').empty();
        }
    });
}

function renderBonusCatalog(bonuses) {
    if (!bonuses.length) {
        $('#bonus-catalog-list').html('<div class="col-12 text-center text-muted py-4">No hay bonos disponibles.</div>');
        return;
    }

    let html = '';
    bonuses.forEach(bonus => {
        const regularTotal = bonus.regular_total ? formatPrice(bonus.regular_total) : '';
        const savings = bonus.savings ? parseFloat(String(bonus.savings).replace(',', '.')) : 0;
        const savingsHtml = savings > 0 ? `<small class="text-success">Ahorras ${formatPrice(bonus.savings)} €</small>` : '';
        html += `
            <div class="col-md-6">
                <div class="bonus-card" data-buy-bonus-id="${bonus.id}">
                    <h6>${escapeHtml(bonus.name)}</h6>
                    <p class="text-muted mb-2">${bonus.session_count} sesiones individuales</p>
                    ${regularTotal ? `<div class="bonus-regular-price">${regularTotal} €</div>` : ''}
                    <div class="bonus-price">${formatPrice(bonus.price)} €</div>
                    ${savingsHtml}
                    <button class="btn btn-primary btn-sm mt-3" type="button" onclick="selectBonusToBuy(${bonus.id}, '${escapeJsString(bonus.name)}', '${escapeJsString(bonus.price)}')">
                        Comprar
                    </button>
                </div>
            </div>
        `;
    });
    $('#bonus-catalog-list').html(html);
}

function selectBonusToBuy(id, name, price) {
    selectedBonusToBuy = { id, name, price };
    $('.bonus-card').removeClass('selected');
    $(`.bonus-card[data-buy-bonus-id="${id}"]`).addClass('selected');
    $('#bonus-payment-text').html(`Comprar <b>${escapeHtml(name)}</b> por ${formatPrice(price)} €.`);
    $('#bonus-payment-options').removeClass('d-none');
}

function startBonusPayment(paymentMethod) {
    if (!selectedBonusToBuy) {
        showBonusesModalAlert('danger', 'Selecciona un bono.');
        return;
    }

    $('#btn-buy-bonus-card, #btn-buy-bonus-bizum').prop('disabled', true);
    $.ajax({
        url: 'api/payments.php?action=create_bonus_redsys_form',
        method: 'POST',
        dataType: 'json',
        data: {
            bonus_id: selectedBonusToBuy.id,
            payment_method: paymentMethod
        },
        success: function (res) {
            if (!res.success) {
                showBonusesModalAlert('danger', res.error || 'No se pudo iniciar el pago del bono.');
                $('#btn-buy-bonus-card, #btn-buy-bonus-bizum').prop('disabled', false);
                return;
            }
            $('#redsys-payment-form').remove();
            $('body').append(res.form_html);
            $('#redsys-payment-form').trigger('submit');
        },
        error: function () {
            showBonusesModalAlert('danger', 'Error de conexion al iniciar el pago del bono.');
            $('#btn-buy-bonus-card, #btn-buy-bonus-bizum').prop('disabled', false);
        }
    });
}

function openMyBonusesModal() {
    openBonusListModal('Mis bonos', 'api/bonuses.php?action=my_bonuses', false);
}

function openAdminBonusesModal() {
    openBonusListModal(`Bonos de ${sectorLabel('patient', 'plural', 'pacientes')}`, 'api/bonuses.php?action=admin_list', true);
}

function openBonusListModal(title, url, adminView) {
    if (!appFeatureEnabled('bonuses.enabled', false)) return;
    if (!bonusesModal) return;
    selectedBonusToBuy = null;
    CURRENT_BONUS_LIST_URL = url;
    CURRENT_BONUS_CAN_MANAGE = false;
    $('#bonusesModalTitle').text(title);
    $('#bonuses-modal-alert').addClass('d-none').text('');
    $('#bonus-catalog-panel').addClass('d-none');
    $('#bonus-payment-options').addClass('d-none');
    $('#bonus-list-panel').removeClass('d-none');
    $('#bonus-list-tools').toggleClass('d-none', !adminView);
    $('#bonus-list-search').val('');
    $('#bonus-list-sort').val('date_desc');
    $('#bonus-list-professional').val('');
    renderBonusListHead(adminView);
    $('#bonus-list-body').html(`<tr><td colspan="${bonusListColspan(adminView)}" class="text-center text-muted py-4">Cargando...</td></tr>`);
    bonusesModal.show();
    loadBonusList(url, adminView);
}

function loadBonusList(url, adminView) {
    CURRENT_BONUS_LIST_URL = url;
    $.ajax({
        url,
        data: adminView ? { professional_id: $('#bonus-list-professional').val() || '' } : {},
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                showBonusesModalAlert('danger', res.error || 'No se pudieron cargar los bonos.');
                $('#bonus-list-body').empty();
                return;
            }
            if (adminView && Array.isArray(res.professionals)) {
                populateBonusProfessionalsFilter(res.professionals, res.current_professional_id);
            }
            CURRENT_BONUS_LIST = Array.isArray(res.bonuses) ? res.bonuses : [];
            CURRENT_BONUS_ADMIN_VIEW = adminView;
            CURRENT_BONUS_CAN_MANAGE = Boolean(adminView && IS_SUPERADMIN && PAYMENT_SETTINGS.bonuses_enabled == 1);
            renderBonusListHead(adminView);
            renderBonusList(CURRENT_BONUS_LIST, CURRENT_BONUS_ADMIN_VIEW);
        },
        error: function () {
            showBonusesModalAlert('danger', 'Error de conexion al cargar los bonos.');
            $('#bonus-list-body').empty();
        }
    });
}

function renderBonusListHead(adminView) {
    const showProfessional = adminView && $('#bonus-list-professional').length;
    const actionsHead = CURRENT_BONUS_CAN_MANAGE ? '<th class="text-end">Acciones</th>' : '';
    const patientTitleSingular = sectorLabel('patient', 'titleSingular', 'Paciente');
    $('#bonus-list-head').html(adminView
        ? `<tr><th>${escapeHtml(patientTitleSingular)}</th>${showProfessional ? '<th>Profesional</th>' : ''}<th>Bono</th><th>Compradas</th><th>Restantes</th><th>Pagado</th><th>Comprado</th><th>Estado</th>${actionsHead}</tr>`
        : '<tr><th>Bono</th><th>Compradas</th><th>Restantes</th><th>Pagado</th><th>Comprado</th><th>Estado</th></tr>');
}

function populateBonusProfessionalsFilter(professionals = null, currentProfessionalId = null) {
    const $select = $('#bonus-list-professional');
    if (!$select.length) return;
    const selected = $select.val() || '';
    const source = Array.isArray(professionals) ? professionals : CABINET_PROFESSIONALS;
    $select.html('<option value="all">Todos los profesionales</option>');
    source
        .filter(professional => professional.is_active != 0)
        .forEach(professional => {
            $select.append(`<option value="${professional.id}">${escapeHtml(professional.display_name || 'Sin nombre')}</option>`);
        });
    const fallback = currentProfessionalId ? String(currentProfessionalId) : '';
    const target = selected || fallback;
    if (target && $select.find(`option[value="${target}"]`).length) {
        $select.val(target);
    }
}

function bonusListColspan(adminView) {
    if (!adminView) return 6;
    const base = $('#bonus-list-professional').length ? 8 : 7;
    return CURRENT_BONUS_CAN_MANAGE ? base + 1 : base;
}

function renderBonusList(bonuses, adminView) {
    const colspan = bonusListColspan(adminView);
    const showProfessional = adminView && $('#bonus-list-professional').length;
    const rows = filterAndSortBonusList(bonuses, adminView);
    if (!bonuses.length) {
        $('#bonus-list-body').html(`<tr><td colspan="${colspan}" class="text-center text-muted py-4">No hay bonos comprados.</td></tr>`);
        return;
    }
    if (!rows.length) {
        $('#bonus-list-body').html(`<tr><td colspan="${colspan}" class="text-center text-muted py-4">No hay bonos que coincidan con la busqueda.</td></tr>`);
        return;
    }

    let html = '';
    rows.forEach(bonus => {
        const paid = bonus.amount_paid !== null && bonus.amount_paid !== undefined ? `${formatPrice(bonus.amount_paid)} €` : '-';
        const date = bonus.purchased_at ? formatDateTimeLabel(bonus.purchased_at) : '-';
        const status = bonusStatusLabel(bonus.status);
        const remainingCell = CURRENT_BONUS_CAN_MANAGE
            ? `<div class="bonus-remaining-control">
                    <input type="number" class="form-control form-control-sm bonus-remaining-input" min="0" max="999" value="${parseInt(bonus.remaining_sessions || 0, 10)}" data-original-value="${parseInt(bonus.remaining_sessions || 0, 10)}" data-patient-bonus-id="${bonus.id}">
                    <button class="btn btn-outline-primary btn-sm btn-save-patient-bonus" type="button" data-patient-bonus-id="${bonus.id}" title="Guardar sesiones restantes">
                        <i class="bi bi-check2"></i>
                    </button>
                </div>`
            : `<strong>${bonus.remaining_sessions}</strong>`;
        const actionsCell = CURRENT_BONUS_CAN_MANAGE
            ? `<td class="text-end">
                    <div class="bonus-row-actions">
                        <button class="btn btn-outline-danger btn-sm btn-delete-patient-bonus" type="button" data-patient-bonus-id="${bonus.id}" data-bonus-name="${escapeHtml(bonus.name || '')}" data-patient-name="${escapeHtml(bonus.patient_name || '')}" title="Eliminar bono">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>`
            : '';
        html += '<tr>';
        if (adminView) {
            html += `<td>${escapeHtml(bonus.patient_name || '')}<br><small class="text-muted">${escapeHtml(bonus.patient_email || '')}</small></td>`;
            if (showProfessional) {
                html += `<td>${professionalCellHtml(bonus, 'professional_name', 'professional_photo_path')}</td>`;
            }
        }
        html += `
            <td>${escapeHtml(bonus.name || '')}</td>
            <td>${bonus.total_sessions}</td>
            <td>${remainingCell}</td>
            <td>${paid}</td>
            <td>${date}</td>
            <td>${status}</td>
            ${actionsCell}
        </tr>`;
    });
    $('#bonus-list-body').html(html);
}

function filterAndSortBonusList(bonuses, adminView) {
    const search = ($('#bonus-list-search').val() || '').trim().toLowerCase();
    const sort = $('#bonus-list-sort').val() || 'date_desc';
    let rows = Array.isArray(bonuses) ? [...bonuses] : [];

    if (adminView && search) {
        rows = rows.filter(bonus => {
            const haystack = [
                bonus.patient_name,
                bonus.patient_email,
                bonus.professional_name,
                bonus.name,
                bonus.status,
                bonus.purchased_at
            ].join(' ').toLowerCase();
            return haystack.includes(search);
        });
    }

    rows.sort((a, b) => {
        if (sort === 'patient_asc') {
            return String(a.patient_name || '').localeCompare(String(b.patient_name || ''), 'es');
        }
        if (sort === 'remaining_desc' || sort === 'remaining_asc') {
            const aRemaining = parseInt(a.remaining_sessions || 0, 10);
            const bRemaining = parseInt(b.remaining_sessions || 0, 10);
            return sort === 'remaining_desc' ? bRemaining - aRemaining : aRemaining - bRemaining;
        }
        const aDate = String(a.purchased_at || '');
        const bDate = String(b.purchased_at || '');
        return sort === 'date_asc' ? aDate.localeCompare(bDate) : bDate.localeCompare(aDate);
    });

    return rows;
}

function bonusStatusLabel(status) {
    const labels = {
        active: '<span class="badge text-bg-success">Activo</span>',
        used: '<span class="badge text-bg-secondary">Usado</span>',
        expired: '<span class="badge text-bg-warning">Caducado</span>',
        cancelled: '<span class="badge text-bg-danger">Cancelado</span>'
    };
    return labels[status] || escapeHtml(status || '');
}

function reloadCurrentBonusList() {
    if (!CURRENT_BONUS_LIST_URL) {
        return;
    }
    loadBonusList(CURRENT_BONUS_LIST_URL, CURRENT_BONUS_ADMIN_VIEW);
}

function savePatientBonusAdjustment(button) {
    const $button = $(button);
    const patientBonusId = parseInt($button.data('patient-bonus-id') || 0, 10);
    const inPatientEditor = $button.closest('#patient-bonuses-panel').length > 0;
    const $input = $button.closest('.bonus-remaining-control').find('.bonus-remaining-input');
    const remaining = parseInt($input.val() || 0, 10);
    if (!patientBonusId || !Number.isFinite(remaining) || remaining < 0) {
        showBonusActionAlert(inPatientEditor, 'danger', 'Indica un número de sesiones válido.');
        return;
    }
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({
        url: 'api/bonuses.php?action=update_patient_bonus',
        method: 'POST',
        dataType: 'json',
        data: {
            patient_bonus_id: patientBonusId,
            remaining_sessions: remaining
        },
        success: function (res) {
            if (!res.success) {
                showBonusActionAlert(inPatientEditor, 'danger', res.error || 'No se pudo actualizar el bono.');
                return;
            }
            showBonusActionAlert(inPatientEditor, 'success', res.message || 'Bono actualizado correctamente.', true);
            reloadCurrentBonusList();
            reloadCurrentPatientBonuses();
        },
        error: function () {
            showBonusActionAlert(inPatientEditor, 'danger', 'Error de conexión al actualizar el bono.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function deletePatientBonusFromList(button) {
    const $button = $(button);
    const patientBonusId = parseInt($button.data('patient-bonus-id') || 0, 10);
    const inPatientEditor = $button.closest('#patient-bonuses-panel').length > 0;
    const bonusName = $button.data('bonus-name') || 'este bono';
    const patientName = $button.data('patient-name') || `este ${sectorLabel('patient', 'singular', 'paciente')}`;
    if (!patientBonusId) {
        showBonusActionAlert(inPatientEditor, 'danger', 'No se ha podido identificar el bono.');
        return;
    }
    if (!window.confirm(`¿Eliminar ${bonusName} de ${patientName}? Esta acción no se puede deshacer.`)) {
        return;
    }
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({
        url: 'api/bonuses.php?action=delete_patient_bonus',
        method: 'POST',
        dataType: 'json',
        data: { patient_bonus_id: patientBonusId },
        success: function (res) {
            if (!res.success) {
                showBonusActionAlert(inPatientEditor, 'danger', res.error || 'No se pudo eliminar el bono.');
                return;
            }
            showBonusActionAlert(inPatientEditor, 'success', res.message || 'Bono eliminado correctamente.', true);
            reloadCurrentBonusList();
            reloadCurrentPatientBonuses();
        },
        error: function () {
            showBonusActionAlert(inPatientEditor, 'danger', 'Error de conexión al eliminar el bono.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function formatDateTimeLabel(value) {
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) {
        return value;
    }
    return date.toLocaleDateString('es-ES') + ' ' + date.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
}

function showBonusesModalAlert(type, message) {
    $('#bonuses-modal-alert')
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .text(message);
}

function showBonusActionAlert(inPatientEditor, type, message, autoHide = false) {
    if (inPatientEditor) {
        showPatientBonusesAlert(type, message, autoHide);
        return;
    }
    const $alert = $('#bonuses-modal-alert')
        .stop(true, true)
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .text(message)
        .show();
    if (autoHide) {
        $alert.delay(4000).fadeOut(200, function () {
            $(this).addClass('d-none').show();
        });
    }
}

function openInviteModal(link, token = '', prefillEmail = '') {
    if (!inviteModal) return;
    currentInviteLink = link || '';
    currentInviteToken = token || '';
    $('#invite-link').val(currentInviteLink);
    $('#invite-email').val(prefillEmail || '');
    $('#invite-modal-alert').addClass('d-none').text('');
    $('#invite-qr').empty();

    if (currentInviteLink && typeof QRCode !== 'undefined') {
        new QRCode(document.getElementById('invite-qr'), {
            text: currentInviteLink,
            width: 180,
            height: 180,
            correctLevel: QRCode.CorrectLevel.M
        });
    } else {
        $('#invite-qr').html('<div class="text-muted small">No se pudo generar el QR.</div>');
    }

    inviteModal.show();
}

function copyTextToClipboard(text, onSuccess) {
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
            if (typeof onSuccess === 'function') onSuccess();
        });
        return;
    }
    const $temp = $('<input>');
    $('body').append($temp);
    $temp.val(text).select();
    document.execCommand('copy');
    $temp.remove();
    if (typeof onSuccess === 'function') onSuccess();
}

function sendInviteEmail(button) {
    const email = $('#invite-email').val().trim();
    if (!email) {
        showInviteAlert('danger', `Indica el email del ${sectorLabel('patient', 'singular', 'paciente')}.`);
        return;
    }

    const $button = $(button);
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Enviando');
    $.ajax({
        url: 'api/admin.php?action=send_invite_email',
        method: 'POST',
        dataType: 'json',
        data: {
            email,
            link: currentInviteLink,
            token: currentInviteToken
        },
        success: function (res) {
            if (res.success) {
                $('#invite-email').val('');
                showInviteAlert('success', res.message || 'Invitacion enviada correctamente.');
            } else {
                showInviteAlert('danger', res.error || 'No se pudo enviar la invitacion.');
            }
        },
        error: function () {
            showInviteAlert('danger', 'Error de conexion al enviar la invitacion.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function showInviteAlert(type, message) {
    if (inviteAlertTimer) {
        clearTimeout(inviteAlertTimer);
        inviteAlertTimer = null;
    }
    $('#invite-modal-alert')
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .text(message);
    if (type === 'success') {
        inviteAlertTimer = setTimeout(function () {
            $('#invite-modal-alert').addClass('d-none').text('');
        }, 3000);
    }
}

function openAdminPatientsModal() {
    if (!adminPatientsModal) return;
    if (adminPatientsAlertTimer) {
        clearTimeout(adminPatientsAlertTimer);
        adminPatientsAlertTimer = null;
    }
    $('#admin-patients-alert').addClass('d-none').text('');
    $('#admin-patients-search').val('');
    $('#admin-patients-sort').val('name_asc');
    $('#admin-patients-professional').val('');
    $('#admin-patients-body').html(`<tr><td colspan="${adminPatientsColspan()}" class="text-center text-muted py-4">Cargando...</td></tr>`);
    $('#admin-patients-count').text('');
    adminPatientsModal.show();
    loadAdminPatients();
}

function loadAdminPatients() {
    $.ajax({
        url: 'api/admin.php?action=list_patients',
        data: { professional_id: $('#admin-patients-professional').val() || '' },
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                showAdminPatientsAlert('danger', res.error || `No se pudieron cargar los ${sectorLabel('patient', 'plural', 'pacientes')}.`);
                return;
            }
            if (Array.isArray(res.professionals)) {
                CABINET_PROFESSIONALS = res.professionals.map(professional => ({
                    ...professional,
                    is_active: typeof professional.is_active === 'undefined' || professional.is_active === null ? 1 : professional.is_active
                }));
                populateAdminPatientsProfessionalsFilter(res.professionals, res.current_professional_id);
            }
            ADMIN_PATIENTS = Array.isArray(res.patients) ? res.patients : [];
            renderAdminPatients(ADMIN_PATIENTS);
            refreshPatientSelectOptions();
        },
        error: function () {
            showAdminPatientsAlert('danger', `Error de conexion al cargar los ${sectorLabel('patient', 'plural', 'pacientes')}.`);
        }
    });
}

function adminPatientsColspan() {
    return $('#admin-patients-professional').length ? 8 : 7;
}

function populateAdminPatientsProfessionalsFilter(professionals = null, currentProfessionalId = null) {
    const $select = $('#admin-patients-professional');
    if (!$select.length) return;
    const selected = $select.val() || '';
    const source = Array.isArray(professionals) ? professionals : CABINET_PROFESSIONALS;
    $select.html('<option value="all">Todos los profesionales</option>');
    source
        .filter(professional => professional.is_active != 0)
        .forEach(professional => {
            $select.append(`<option value="${professional.id}">${escapeHtml(professional.display_name || 'Sin nombre')}</option>`);
        });
    const fallback = currentProfessionalId ? String(currentProfessionalId) : '';
    const target = selected || fallback;
    if (target && $select.find(`option[value="${target}"]`).length) {
        $select.val(target);
    }
}

function populatePatientEditorProfessionalSelect(patient = null) {
    const $select = $('#patient-editor-professional');
    if (!$select.length) return;
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const selected = patient
        ? String(patient.professional_id || '')
        : String(CURRENT_PROFESSIONAL_ID || '');
    $select.html(`<option value="">Permitir elegir profesional al ${escapeHtml(patientSingular)}</option>`);
    CABINET_PROFESSIONALS
        .filter(professional => professional.is_active != 0)
        .forEach(professional => {
            $select.append(`<option value="${professional.id}">${escapeHtml(professional.display_name || 'Sin nombre')}</option>`);
        });
    if (selected && $select.find(`option[value="${selected}"]`).length) {
        $select.val(selected);
    } else {
        $select.val('');
    }
}

function updatePatientTransferUi(patient = null) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const patientPlural = sectorLabel('patient', 'plural', 'pacientes');
    const hasPatient = Boolean(patient && patient.id);
    $('#patient-editor-professional-new-wrap').toggleClass('d-none', hasPatient);
    $('#patient-editor-professional-current-wrap').toggleClass('d-none', !hasPatient);
    $('#patient-transfer-panel').addClass('d-none');
    if (!hasPatient) {
        return;
    }
    const hasProfessional = parseInt(patient.professional_id || 0, 10) > 0;
    const canAssignOrTransfer = IS_SUPERADMIN && (!hasProfessional || PAYMENT_SETTINGS.allow_patient_transfer == 1);
    $('#patient-editor-current-professional').text(patient.professional_name || 'Sin profesional asignado');
    $('#btn-show-patient-transfer')
        .prop('disabled', !canAssignOrTransfer)
        .html(`<i class="bi bi-arrow-left-right"></i> ${hasProfessional ? `Traspasar ${patientSingular}` : 'Asignar profesional'}`);
    let statusText = 'Las citas pasadas conservan su profesional historico; solo se moveran las citas futuras reservadas.';
    if (!hasProfessional) {
        statusText = `Este ${patientSingular} todavia no tiene profesional asignado. Puedes asignarlo manualmente.`;
    } else if (!canAssignOrTransfer) {
        statusText = `Activa "Permitir traspaso de ${patientPlural}" en configuracion para cambiarlo desde aqui.`;
    }
    $('#patient-editor-transfer-status').text(statusText);
    populatePatientTransferProfessionalSelect(patient);
}

function populatePatientTransferProfessionalSelect(patient = CURRENT_PATIENT_EDITOR) {
    const $select = $('#patient-transfer-professional');
    if (!$select.length) return;
    const currentId = parseInt(patient && patient.professional_id || 0, 10);
    const options = CABINET_PROFESSIONALS
        .filter(professional => professional.is_active != 0 && parseInt(professional.id || 0, 10) !== currentId)
        .map(professional => `<option value="${professional.id}">${escapeHtml(professional.display_name || 'Sin nombre')}</option>`)
        .join('');
    $select.html(`<option value="">Selecciona profesional...</option>${options}`);
}

function transferCurrentPatientProfessional() {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const patientId = parseInt($('#patient-editor-id').val() || 0, 10);
    const professionalId = parseInt($('#patient-transfer-professional').val() || 0, 10);
    if (!patientId || !professionalId) {
        showPatientEditorAlert('danger', 'Selecciona un profesional.');
        return;
    }
    const target = bookingProfessionalById(professionalId);
    const patient = CURRENT_PATIENT_EDITOR || {};
    const actionText = patient.professional_id ? 'traspasar' : 'asignar';
    if (!confirm(`¿Quieres ${actionText} este ${patientSingular} a ${target ? target.display_name : 'este profesional'}? Las citas pasadas conservarán su profesional histórico y solo se moverán las futuras reservadas.`)) {
        return;
    }
    const $button = $('#btn-confirm-patient-transfer');
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({
        url: 'api/admin.php?action=transfer_patient_professional',
        method: 'POST',
        dataType: 'json',
        data: { patient_id: patientId, professional_id: professionalId },
        success: function (res) {
            if (!res.success) {
                showPatientEditorAlert('danger', res.error || 'No se pudo completar el traspaso.');
                return;
            }
            showPatientEditorAlert('success', res.message || 'Profesional actualizado.');
            CURRENT_PATIENT_EDITOR = {
                ...(CURRENT_PATIENT_EDITOR || {}),
                professional_id: res.professional_id,
                professional_name: res.professional_name
            };
            const patientIndex = ADMIN_PATIENTS.findIndex(item => parseInt(item.id || 0, 10) === patientId);
            if (patientIndex >= 0) {
                ADMIN_PATIENTS[patientIndex].professional_id = res.professional_id;
                ADMIN_PATIENTS[patientIndex].professional_name = res.professional_name;
            }
            updatePatientTransferUi(CURRENT_PATIENT_EDITOR);
            loadPatientAppointmentHistory(patientId);
            loadAdminPatients();
            dashboardPatientsLoaded = false;
            invalidateDashboardUpcomingAppointments();
            renderWeekInfo();
        },
        error: function () {
            showPatientEditorAlert('danger', 'Error de conexion al completar el traspaso.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function renderAdminPatients(patients) {
    const filteredPatients = filterAndSortAdminPatients(patients);
    const colspan = adminPatientsColspan();
    const showProfessional = $('#admin-patients-professional').length;
    if (!patients.length) {
        $('#admin-patients-body').html(`<tr><td colspan="${colspan}" class="text-center text-muted py-4">Todavia no hay ${sectorLabel('patient', 'plural', 'pacientes')}.</td></tr>`);
        $('#admin-patients-count').text('');
        return;
    }
    if (!filteredPatients.length) {
        $('#admin-patients-body').html(`<tr><td colspan="${colspan}" class="text-center text-muted py-4">No hay ${sectorLabel('patient', 'plural', 'pacientes')} que coincidan con la busqueda.</td></tr>`);
        $('#admin-patients-count').text('');
        return;
    }

    const html = filteredPatients.map(patient => {
        const photo = patient.photo_path
            ? `<img class="table-avatar" src="${escapeHtml(assetUrl(patient.photo_path))}" alt="${escapeHtml(patient.name || '')}">`
            : '<span class="table-avatar table-avatar-empty"><i class="bi bi-person"></i></span>';
        const contact = [
            patient.email ? `<div>${escapeHtml(patient.email)}</div>` : '',
            patient.phone ? `<small class="text-muted">${escapeHtml(patient.phone)}${patientContactActionsHtml(patient.phone, 'ms-1')}</small>` : ''
        ].join('') || '<span class="text-muted">Sin contacto</span>';
        const accessBadge = parseInt(patient.has_portal_access || 0, 10) === 1
            ? '<span class="badge text-bg-success">Con acceso</span>'
            : '<span class="badge text-bg-secondary">Sin acceso</span>';
        const inviteButton = parseInt(patient.has_portal_access || 0, 10) === 1
            ? ''
            : `<button class="btn btn-outline-primary btn-sm btn-send-patient-invite" type="button" data-patient-id="${patient.id}" title="Enviar invitacion de registro"><i class="bi bi-envelope"></i></button>`;
        const documentLink = patient.document_path
            ? `<a href="api/admin.php?action=download_patient_document&patient_id=${patient.id}" target="_blank" rel="noopener">${escapeHtml(patient.document_name || 'Documento')}</a>`
            : '<span class="text-muted">Sin archivo</span>';

        return `
            <tr class="admin-patient-row" data-patient-id="${patient.id}">
                <td>
                    <div class="d-flex align-items-center gap-2">
                        ${photo}
                        <strong>${escapeHtml(patient.name || '')}</strong>
                    </div>
                </td>
                ${showProfessional ? `<td>${professionalCellHtml(patient, 'professional_name', 'professional_photo_path')}</td>` : ''}
                <td>${contact}</td>
                <td>${patient.patient_type ? escapeHtml(patient.patient_type) : '<span class="text-muted">-</span>'}</td>
                <td>${patient.admission_date ? formatDisplayDate(patient.admission_date) : '<span class="text-muted">-</span>'}</td>
                <td>${accessBadge}</td>
                <td>${documentLink}</td>
                <td class="text-end no-export">
                    <div class="d-inline-flex gap-1">
                        ${inviteButton}
                        <button class="btn btn-outline-secondary btn-sm btn-edit-patient" type="button" data-patient-id="${patient.id}" title="Datos del ${escapeHtml(sectorLabel('patient', 'singular', 'paciente'))}">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');

    $('#admin-patients-body').html(html);
    $('#admin-patients-count').text(`${filteredPatients.length} ${filteredPatients.length === 1 ? sectorLabel('patient', 'singular', 'paciente') : sectorLabel('patient', 'plural', 'pacientes')}`);
}

function filterAndSortAdminPatients(patients) {
    const search = ($('#admin-patients-search').val() || '').trim().toLowerCase();
    const sort = $('#admin-patients-sort').val() || 'name_asc';
    let rows = Array.isArray(patients) ? [...patients] : [];

    if (search) {
        rows = rows.filter(patient => {
            const haystack = [
                patient.name,
                patient.email,
                patient.phone,
                patient.patient_type,
                patient.professional_name,
                patient.admission_date
            ].join(' ').toLowerCase();
            return haystack.includes(search);
        });
    }

    rows.sort((a, b) => {
        if (sort === 'admission_desc' || sort === 'admission_asc') {
            const aDate = a.admission_date || '';
            const bDate = b.admission_date || '';
            return sort === 'admission_desc'
                ? bDate.localeCompare(aDate)
                : aDate.localeCompare(bDate);
        }
        const aName = a.name || '';
        const bName = b.name || '';
        return sort === 'name_desc'
            ? bName.localeCompare(aName, 'es')
            : aName.localeCompare(bName, 'es');
    });

    return rows;
}

function renderDashboardPatientsView() {
    const patientPluralTitle = sectorLabel('patient', 'titlePlural', 'Pacientes');
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const showProfessional = IS_SUPERADMIN;
    const professionalFilter = showProfessional ? `
        <div class="col-md-3">
            <select class="form-select" id="dashboard-patients-professional">
                <option value="all">Todos los profesionales</option>
            </select>
        </div>
    ` : '';
    $('#calendar-container').html(`
        <section class="dashboard-inline-panel dashboard-patients-view">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                <div>
                    <h5 class="mb-1">${escapeHtml(patientPluralTitle)}</h5>
                </div>
                <button class="btn btn-primary btn-sm btn-dashboard-new-patient" type="button">
                    <i class="bi bi-person-plus"></i> Nuevo ${escapeHtml(patientSingular)}
                </button>
            </div>
            <div id="dashboard-patients-alert" class="alert d-none"></div>
            <div class="row g-2 mb-3">
                <div class="${showProfessional ? 'col-md-5' : 'col-md-8'}">
                    <input type="search" class="form-control" id="dashboard-patients-search" placeholder="${showProfessional ? 'Buscar por nombre, email, telefono, tipo o profesional' : 'Buscar por nombre, email, telefono o tipo'}">
                </div>
                ${professionalFilter}
                <div class="col-md-4">
                    <select class="form-select" id="dashboard-patients-sort">
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
                            <th>${escapeHtml(sectorLabel('patient', 'titleSingular', 'Paciente'))}</th>
                            ${showProfessional ? '<th>Profesional</th>' : ''}
                            <th>Contacto</th>
                            <th>Tipo</th>
                            <th>Alta</th>
                            <th>Acceso</th>
                            <th>Documento</th>
                            <th class="text-end no-export">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="dashboard-patients-body">
                        <tr><td colspan="${showProfessional ? 8 : 7}" class="text-center text-muted py-4">Cargando...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="text-end text-muted small mt-2" id="dashboard-patients-count"></div>
        </section>
    `);
    if (!dashboardPatientsLoaded) {
        loadDashboardPatients();
    } else {
        populateDashboardPatientsProfessionalsFilter();
        renderDashboardPatients(DASHBOARD_PATIENTS);
    }
}

function loadDashboardPatients() {
    $('#dashboard-patients-alert').addClass('d-none').text('');
    $('#dashboard-patients-body').html(`<tr><td colspan="${IS_SUPERADMIN ? 8 : 7}" class="text-center text-muted py-4">Cargando...</td></tr>`);
    $('#dashboard-patients-count').text('');
    $.ajax({
        url: 'api/admin.php?action=list_patients',
        data: IS_SUPERADMIN ? { professional_id: 'all' } : {},
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                $('#dashboard-patients-alert').removeClass('d-none alert-success').addClass('alert-danger').text(res.error || `No se pudieron cargar los ${sectorLabel('patient', 'plural', 'pacientes')}.`);
                return;
            }
            if (Array.isArray(res.professionals)) {
                CABINET_PROFESSIONALS = res.professionals.map(professional => ({
                    ...professional,
                    is_active: typeof professional.is_active === 'undefined' || professional.is_active === null ? 1 : professional.is_active
                }));
            }
            DASHBOARD_PATIENTS = Array.isArray(res.patients) ? res.patients : [];
            dashboardPatientsLoaded = true;
            populateDashboardPatientsProfessionalsFilter(res.professionals || []);
            renderDashboardPatients(DASHBOARD_PATIENTS);
            refreshPatientSelectOptions();
        },
        error: function () {
            $('#dashboard-patients-alert').removeClass('d-none alert-success').addClass('alert-danger').text(`Error de conexion al cargar los ${sectorLabel('patient', 'plural', 'pacientes')}.`);
        }
    });
}

function populateDashboardPatientsProfessionalsFilter(professionals = null) {
    const $select = $('#dashboard-patients-professional');
    if (!$select.length) return;
    const selected = $select.val() || 'all';
    const source = Array.isArray(professionals) && professionals.length ? professionals : CABINET_PROFESSIONALS;
    $select.html('<option value="all">Todos los profesionales</option>');
    source
        .filter(professional => professional.is_active != 0)
        .forEach(professional => {
            $select.append(`<option value="${professional.id}">${escapeHtml(professional.display_name || 'Sin nombre')}</option>`);
        });
    if (selected && $select.find(`option[value="${selected}"]`).length) {
        $select.val(selected);
    }
}

function dashboardPatientsColspan() {
    return IS_SUPERADMIN ? 8 : 7;
}

function filterAndSortDashboardPatients(patients) {
    const search = ($('#dashboard-patients-search').val() || '').trim().toLowerCase();
    const sort = $('#dashboard-patients-sort').val() || 'name_asc';
    const professionalFilter = $('#dashboard-patients-professional').val() || 'all';
    let rows = Array.isArray(patients) ? [...patients] : [];
    if (IS_SUPERADMIN && professionalFilter !== 'all') {
        rows = rows.filter(patient => String(patient.professional_id || '') === String(professionalFilter));
    }
    if (search) {
        rows = rows.filter(patient => {
            const haystack = [
                patient.name,
                patient.email,
                patient.phone,
                patient.patient_type,
                patient.professional_name,
                patient.admission_date
            ].join(' ').toLowerCase();
            return haystack.includes(search);
        });
    }
    rows.sort((a, b) => {
        if (sort === 'admission_desc' || sort === 'admission_asc') {
            const aDate = a.admission_date || '';
            const bDate = b.admission_date || '';
            return sort === 'admission_desc'
                ? bDate.localeCompare(aDate)
                : aDate.localeCompare(bDate);
        }
        const aName = a.name || '';
        const bName = b.name || '';
        return sort === 'name_desc'
            ? bName.localeCompare(aName, 'es')
            : aName.localeCompare(bName, 'es');
    });
    return rows;
}

function renderDashboardPatients(patients) {
    const rows = filterAndSortDashboardPatients(patients);
    const colspan = dashboardPatientsColspan();
    if (!patients.length) {
        $('#dashboard-patients-body').html(`<tr><td colspan="${colspan}" class="text-center text-muted py-4">Todavia no hay ${sectorLabel('patient', 'plural', 'pacientes')}.</td></tr>`);
        $('#dashboard-patients-count').text('');
        return;
    }
    if (!rows.length) {
        $('#dashboard-patients-body').html(`<tr><td colspan="${colspan}" class="text-center text-muted py-4">No hay ${sectorLabel('patient', 'plural', 'pacientes')} que coincidan con la busqueda.</td></tr>`);
        $('#dashboard-patients-count').text('');
        return;
    }
    const html = rows.map(patient => {
        const photo = patient.photo_path
            ? `<img class="table-avatar" src="${escapeHtml(assetUrl(patient.photo_path))}" alt="${escapeHtml(patient.name || '')}">`
            : '<span class="table-avatar table-avatar-empty"><i class="bi bi-person"></i></span>';
        const contact = [
            patient.email ? `<div>${escapeHtml(patient.email)}</div>` : '',
            patient.phone ? `<small class="text-muted">${escapeHtml(patient.phone)}${patientContactActionsHtml(patient.phone, 'ms-1')}</small>` : ''
        ].join('') || '<span class="text-muted">Sin contacto</span>';
        const accessBadge = parseInt(patient.has_portal_access || 0, 10) === 1
            ? '<span class="badge text-bg-success">Con acceso</span>'
            : '<span class="badge text-bg-secondary">Sin acceso</span>';
        const inviteButton = parseInt(patient.has_portal_access || 0, 10) === 1
            ? ''
            : `<button class="btn btn-outline-primary btn-sm btn-dashboard-send-patient-invite" type="button" data-patient-id="${patient.id}" title="Enviar invitacion de registro"><i class="bi bi-envelope"></i></button>`;
        const documentLink = patient.document_path
            ? `<a href="api/admin.php?action=download_patient_document&patient_id=${patient.id}" target="_blank" rel="noopener">${escapeHtml(patient.document_name || 'Documento')}</a>`
            : '<span class="text-muted">Sin archivo</span>';
        return `
            <tr class="dashboard-patient-row" data-patient-id="${patient.id}">
                <td>
                    <div class="d-flex align-items-center gap-2">
                        ${photo}
                        <strong>${escapeHtml(patient.name || '')}</strong>
                    </div>
                </td>
                ${IS_SUPERADMIN ? `<td>${professionalCellHtml(patient, 'professional_name', 'professional_photo_path')}</td>` : ''}
                <td>${contact}</td>
                <td>${patient.patient_type ? escapeHtml(patient.patient_type) : '<span class="text-muted">-</span>'}</td>
                <td>${patient.admission_date ? formatDisplayDate(patient.admission_date) : '<span class="text-muted">-</span>'}</td>
                <td>${accessBadge}</td>
                <td>${documentLink}</td>
                <td class="text-end no-export">
                    <div class="d-inline-flex gap-1">
                        ${inviteButton}
                        <button class="btn btn-outline-secondary btn-sm btn-dashboard-edit-patient" type="button" data-patient-id="${patient.id}" title="Datos del ${escapeHtml(sectorLabel('patient', 'singular', 'paciente'))}">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
    $('#dashboard-patients-body').html(html);
    $('#dashboard-patients-count').text(`${rows.length} ${rows.length === 1 ? sectorLabel('patient', 'singular', 'paciente') : sectorLabel('patient', 'plural', 'pacientes')}`);
}

function renderDashboardUpcomingView() {
    $('#calendar-container').html(`
        <section class="dashboard-inline-panel dashboard-upcoming-view">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                <div>
                    <h5 class="mb-1">Pr&oacute;ximas citas</h5>
                </div>
                <button class="btn btn-primary btn-sm btn-dashboard-more-upcoming" type="button">
                    <i class="bi bi-list-check"></i> M&aacute;s citas
                </button>
            </div>
            <div id="dashboard-upcoming-alert" class="alert d-none"></div>
            <div class="row g-2 mb-3">
                <div class="${IS_SUPERADMIN ? 'col-md-5' : 'col-md-8'}">
                    <input type="search" class="form-control" id="dashboard-upcoming-search" placeholder="Buscar por ${escapeHtml(sectorLabel('patient', 'singular', 'paciente'))}, email, profesional, servicio o pago">
                </div>
                ${IS_SUPERADMIN ? `
                <div class="col-md-3">
                    <select class="form-select" id="dashboard-upcoming-professional">
                        <option value="current">Mis citas</option>
                    </select>
                </div>` : ''}
                <div class="col-md-4">
                    <select class="form-select" id="dashboard-upcoming-scope">
                        <option value="limit10">10 pr&oacute;ximas citas</option>
                        <option value="3days">Pr&oacute;ximos 3 d&iacute;as</option>
                        <option value="7days">Pr&oacute;ximos 7 d&iacute;as</option>
                        <option value="14days">Pr&oacute;ximos 14 d&iacute;as</option>
                        <option value="all">Todas las pr&oacute;ximas</option>
                    </select>
                </div>
            </div>
            <div class="table-responsive upcoming-appointments-table-wrap">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Profesional</th>
                            <th>${escapeHtml(sectorLabel('patient', 'titleSingular', 'Paciente'))}</th>
                            <th>Servicio</th>
                            <th>Modalidad</th>
                            <th>Pago</th>
                            <th class="text-end no-export">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="dashboard-upcoming-body">
                        <tr><td colspan="7" class="text-center text-muted py-4">Cargando...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="text-end text-muted small mt-2" id="dashboard-upcoming-count"></div>
        </section>
    `);
    populateDashboardUpcomingProfessionalsFilter();
    if (!dashboardUpcomingLoaded) {
        loadDashboardUpcomingAppointments(true);
    } else {
        renderDashboardUpcomingAppointments(DASHBOARD_UPCOMING_APPOINTMENTS);
    }
}

function loadDashboardUpcomingAppointments(initialLoad = false) {
    $('#dashboard-upcoming-alert').addClass('d-none').text('');
    $('#dashboard-upcoming-body').html('<tr><td colspan="7" class="text-center text-muted py-4">Cargando...</td></tr>');
    $('#dashboard-upcoming-count').text('');
    const professionalValue = IS_SUPERADMIN
        ? ($('#dashboard-upcoming-professional').val() || (initialLoad ? 'current' : 'all'))
        : '';
    $.ajax({
        url: 'api/admin.php?action=upcoming_appointments',
        data: {
            scope: $('#dashboard-upcoming-scope').val() || 'limit10',
            planning_scope: '3days',
            professional_id: professionalValue
        },
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                $('#dashboard-upcoming-alert').removeClass('d-none alert-success').addClass('alert-danger').text(res.error || 'No se pudieron cargar las citas.');
                return;
            }
            if (Array.isArray(res.professionals)) {
                CABINET_PROFESSIONALS = res.professionals.map(professional => ({
                    ...professional,
                    is_active: typeof professional.is_active === 'undefined' || professional.is_active === null ? 1 : professional.is_active
                }));
                populateDashboardUpcomingProfessionalsFilter(res.professionals, initialLoad ? res.current_professional_id : null);
            }
            DASHBOARD_UPCOMING_APPOINTMENTS = Array.isArray(res.appointments) ? res.appointments : [];
            dashboardUpcomingLoaded = true;
            renderDashboardUpcomingAppointments(DASHBOARD_UPCOMING_APPOINTMENTS);
        },
        error: function () {
            $('#dashboard-upcoming-alert').removeClass('d-none alert-success').addClass('alert-danger').text('Error de conexion al cargar las citas.');
        }
    });
}

function populateDashboardUpcomingProfessionalsFilter(professionals = null, preferredProfessionalId = null) {
    const $select = $('#dashboard-upcoming-professional');
    if (!$select.length) return;
    const selected = $select.val() || 'current';
    const source = Array.isArray(professionals) && professionals.length ? professionals : CABINET_PROFESSIONALS;
    $select.html('<option value="current">Mis citas</option><option value="all">Todos los profesionales</option>');
    source
        .filter(professional => professional.is_active != 0)
        .forEach(professional => {
            $select.append(`<option value="${professional.id}">${escapeHtml(professional.display_name || 'Sin nombre')}</option>`);
        });
    const preferred = preferredProfessionalId ? String(preferredProfessionalId) : '';
    if (preferred && $select.find(`option[value="${preferred}"]`).length) {
        $select.val(preferred);
    } else if (selected && $select.find(`option[value="${selected}"]`).length) {
        $select.val(selected);
    }
}

function filterDashboardUpcomingAppointments(appointments) {
    const search = ($('#dashboard-upcoming-search').val() || '').trim().toLowerCase();
    let rows = Array.isArray(appointments) ? [...appointments] : [];
    if (search) {
        rows = rows.filter(app => {
            const haystack = [
                app.appointment_date,
                app.appointment_time,
                app.patient_name,
                app.patient_email,
                app.patient_phone,
                app.professional_name,
                app.service_label,
                consultationTypeLabel(app.consultation_type),
                app.payment_status,
                app.payment_method
            ].join(' ').toLowerCase();
            return haystack.includes(search);
        });
    }
    rows.sort((a, b) => {
        const aKey = `${a.appointment_date || ''} ${a.appointment_time || ''}`;
        const bKey = `${b.appointment_date || ''} ${b.appointment_time || ''}`;
        return aKey.localeCompare(bKey);
    });
    return rows;
}

function renderDashboardUpcomingAppointments(appointments) {
    const rows = filterDashboardUpcomingAppointments(appointments);
    if (!appointments.length) {
        $('#dashboard-upcoming-body').html('<tr><td colspan="7" class="text-center text-muted py-4">No hay citas pr&oacute;ximas.</td></tr>');
        $('#dashboard-upcoming-count').text('');
        return;
    }
    if (!rows.length) {
        $('#dashboard-upcoming-body').html('<tr><td colspan="7" class="text-center text-muted py-4">No hay citas que coincidan con la b&uacute;squeda.</td></tr>');
        $('#dashboard-upcoming-count').text('');
        return;
    }
    const html = rows.map(app => `
        <tr class="dashboard-upcoming-row" data-appointment-id="${app.id || ''}">
            <td><strong>${formatDisplayDate(app.appointment_date)}</strong><br><small class="text-muted">${escapeHtml(displayAppointmentTimeRange(app.appointment_time || '', app.duration_minutes || 60))}</small></td>
            <td>${upcomingProfessionalCell(app)}</td>
            <td>${escapeHtml(app.patient_name || '')}<br><small class="text-muted">${patientContactSummaryHtml(app.patient_email, app.patient_phone) || '-'}</small></td>
            <td>${escapeHtml(displayAppointmentServiceLabel(app))}</td>
            <td>${consultationTypeLabel(app.consultation_type)}</td>
            <td>${adminPaymentLabel(app)}</td>
            <td class="text-end no-export">${appointmentPaymentButton(app)}</td>
        </tr>
    `).join('');
    $('#dashboard-upcoming-body').html(html);
    $('#dashboard-upcoming-count').text(`${rows.length} ${rows.length === 1 ? 'cita' : 'citas'}`);
}

function loadKnowledgeProblems(callback) {
    const done = typeof callback === 'function' ? callback : function () {};
    if (!knowledgeBaseEnabled()) {
        KNOWLEDGE_PROBLEMS = [];
        KNOWLEDGE_PROBLEMS_LOADED = true;
        done();
        return;
    }
    if (KNOWLEDGE_PROBLEMS_LOADED) {
        populateKnowledgeProblemSelect();
        done();
        return;
    }
    $('#patient-editor-knowledge-problem').prop('disabled', true).html('<option value="">Cargando diagn&oacute;sticos...</option>');
    $.ajax({
        url: 'api/admin.php?action=knowledge_problems',
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                showPatientKnowledgeAlert('danger', res.error || 'No se pudo cargar la base de conocimiento.');
                $('#patient-editor-knowledge-problem').html('<option value="">No disponible</option>');
                return;
            }
            KNOWLEDGE_PROBLEMS = Array.isArray(res.problems) ? res.problems : [];
            KNOWLEDGE_PROBLEMS_LOADED = true;
            populateKnowledgeProblemSelect();
            done();
        },
        error: function () {
            showPatientKnowledgeAlert('danger', 'Error de conexion al cargar la base de conocimiento.');
            $('#patient-editor-knowledge-problem').html('<option value="">No disponible</option>');
        },
        complete: function () {
            $('#patient-editor-knowledge-problem').prop('disabled', false);
        }
    });
}

function populateKnowledgeProblemSelect() {
    const selected = String($('#patient-editor-knowledge-problem').val() || (CURRENT_PATIENT_EDITOR ? CURRENT_PATIENT_EDITOR.knowledge_problem_id || '' : ''));
    const diagnosisLabel = sectorText('clinicalTerms.diagnosis', 'diagnostico');
    const groups = {};
    const hasMultipleSectors = new Set(KNOWLEDGE_PROBLEMS.map(problem => problem.sector_key || APP_CURRENT_SECTOR_KEY).filter(Boolean)).size > 1;
    KNOWLEDGE_PROBLEMS.forEach(problem => {
        const sectorLabel = hasMultipleSectors ? (problem.sector_label || knowledgeSectorName(problem.sector_key || APP_CURRENT_SECTOR_KEY)) : '';
        const area = `${sectorLabel ? `${sectorLabel} · ` : ''}${problem.area_name || 'Sin area'}`;
        if (!groups[area]) groups[area] = [];
        groups[area].push(problem);
    });
    let html = `<option value="">Sin ${escapeHtml(diagnosisLabel)} asociado</option>`;
    Object.keys(groups).sort().forEach(area => {
        html += `<optgroup label="${escapeHtml(area)}">`;
        groups[area].forEach(problem => {
            const label = `${problem.name || ''}${problem.alias ? ` (${problem.alias})` : ''}`;
            html += `<option value="${parseInt(problem.id, 10)}">${escapeHtml(label)}</option>`;
        });
        html += '</optgroup>';
    });
    $('#patient-editor-knowledge-problem').html(html).val(selected);
}

function loadSelectedPatientKnowledgeProblem() {
    const problemId = parseInt($('#patient-editor-knowledge-problem').val() || 0, 10);
    const diagnosisLabel = sectorText('clinicalTerms.diagnosis', 'diagnostico');
    CURRENT_KNOWLEDGE_PROBLEM_DETAIL = null;
    $('#patient-knowledge-alert').addClass('d-none').text('');
    if (!problemId) {
        $('#patient-knowledge-content').html(`<div class="text-center text-muted py-4">No hay ${escapeHtml(diagnosisLabel)} seleccionado.</div>`);
        return;
    }
    $('#patient-knowledge-content').html('<div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Cargando base de conocimiento...</div>');
    $.ajax({
        url: 'api/admin.php?action=knowledge_problem_detail',
        method: 'GET',
        dataType: 'json',
        data: { problem_id: problemId },
        success: function (res) {
            if (!res.success) {
                showPatientKnowledgeAlert('danger', res.error || `No se pudo cargar el ${diagnosisLabel}.`);
                $('#patient-knowledge-content').html('<div class="text-center text-muted py-4">No se pudo cargar la base de conocimiento.</div>');
                return;
            }
            CURRENT_KNOWLEDGE_PROBLEM_DETAIL = res;
            $('#patient-knowledge-content').html(renderKnowledgeProblemDetail(res));
        },
        error: function () {
            showPatientKnowledgeAlert('danger', `Error de conexion al cargar el ${diagnosisLabel}.`);
            $('#patient-knowledge-content').html('<div class="text-center text-muted py-4">No se pudo cargar la base de conocimiento.</div>');
        }
    });
}

function knowledgeRiskBadge(risk, label = 'Riesgo') {
    const value = String(risk || '').toLowerCase();
    const cls = value === 'alto' ? 'text-bg-danger' : (value === 'medio' ? 'text-bg-warning' : 'text-bg-success');
    return risk ? `<span class="badge ${cls}">${escapeHtml(label)}: ${escapeHtml(risk)}</span>` : '';
}

function knowledgePriorityBadge(priority) {
    const value = String(priority || '').toLowerCase();
    const cls = value === 'alta' ? 'text-bg-danger' : (value === 'media' ? 'text-bg-warning' : 'text-bg-secondary');
    return priority ? `<span class="badge ${cls}">Prioridad: ${escapeHtml(priority)}</span>` : '';
}

function renderKnowledgeProblemDetail(data) {
    const problem = data.problem || {};
    const techniques = Array.isArray(data.techniques) ? data.techniques : [];
    const knowledgeDocuments = Array.isArray(data.questionnaires) ? data.questionnaires : [];
    const questionnaires = knowledgeDocuments.filter(item => (item.resource_kind || 'questionnaire') !== 'document');
    const suggestedDocuments = knowledgeDocuments.filter(item => item.resource_kind === 'document');
    const sources = Array.isArray(data.sources) ? data.sources : [];
    const canImportKnowledgeTasks = knowledgeImportEnabled();
    const taskSingular = sectorLabel('task', 'singular', 'tarea');
    const taskPlural = sectorLabel('task', 'plural', 'tareas');
    const techniqueSingular = sectorLabel('technique', 'singular', 'tecnica');
    const techniquePlural = sectorLabel('technique', 'plural', 'tecnicas');
    const techniqueTitlePlural = sectorLabel('technique', 'titlePlural', 'Tecnicas');
    const evaluationTitlePlural = sectorLabel('evaluation', 'titlePlural', 'Cuestionarios');
    const resourceTitlePlural = sectorLabel('resource', 'titlePlural', 'Documentos');
    const goalTitleSingular = sectorLabel('goal', 'titleSingular', 'Objetivo');
    const techniquesHtml = techniques.length ? techniques.map((technique, index) => {
        const collapseId = `knowledge-technique-${parseInt(technique.id, 10) || index}`;
        const recommendations = Array.isArray(technique.recommendations) ? technique.recommendations : [];
        return `
        <article class="knowledge-technique">
            <button class="knowledge-technique-toggle collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#${collapseId}" aria-expanded="false" aria-controls="${collapseId}">
                <span>
                    <strong>${escapeHtml(technique.name || '')}</strong>
                    ${technique.description ? `<p>${escapeHtml(technique.description)}</p>` : ''}
                </span>
                <span class="knowledge-technique-side">
                    <span class="badge text-bg-light">${recommendations.length} ${recommendations.length === 1 ? escapeHtml(taskSingular) : escapeHtml(taskPlural)}</span>
                    ${knowledgeRiskBadge(technique.risk_level, 'Riesgo')}
                    <i class="bi bi-chevron-down"></i>
                </span>
            </button>
            <div class="collapse" id="${collapseId}">
                <div class="knowledge-recommendation-list">
                    ${recommendations.map(rec => {
                        const task = rec.task || {};
                        return `
                            <div class="knowledge-recommendation-item">
                                <div>
                                    <div class="knowledge-recommendation-title">
                                        <strong>${escapeHtml(task.title || '')}</strong>
                                        ${knowledgePriorityBadge(rec.priority)}
                                        ${knowledgeRiskBadge(task.risk_level, 'Riesgo tarea')}
                                    </div>
                                    ${task.description ? `<p>${escapeHtml(task.description)}</p>` : ''}
                                    <div class="knowledge-recommendation-meta">
                                        ${task.objective ? `<span>${escapeHtml(goalTitleSingular)}: ${escapeHtml(task.objective)}</span>` : ''}
                                        ${task.estimated_duration ? `<span>Duraci&oacute;n: ${escapeHtml(task.estimated_duration)}</span>` : ''}
                                    </div>
                                </div>
                                ${canImportKnowledgeTasks ? `<button class="btn btn-outline-primary btn-sm btn-add-knowledge-recommendation" type="button" data-recommendation-id="${parseInt(rec.id, 10)}">
                                    <i class="bi bi-plus-circle"></i> A&ntilde;adir ${escapeHtml(taskSingular)}
                                </button>` : ''}
                            </div>
                        `;
                    }).join('')}
                    ${canImportKnowledgeTasks ? `<div class="knowledge-technique-actions">
                        <button class="btn btn-outline-primary btn-sm btn-import-knowledge-technique-tasks" type="button" data-technique-id="${parseInt(technique.id, 10)}">
                            <i class="bi bi-list-check"></i> Importar ${recommendations.length === 1 ? 'el' : 'las'} ${recommendations.length} ${recommendations.length === 1 ? escapeHtml(taskSingular) : escapeHtml(taskPlural)}
                        </button>
                    </div>` : ''}
                </div>
            </div>
        </article>
    `;
    }).join('') : `<div class="text-muted">No hay ${escapeHtml(techniquePlural)} recomendadas.</div>`;
    const questionnairesHtml = questionnaires.length ? questionnaires.map(item => `
        <div class="knowledge-compact-item">
            <strong>${escapeHtml(item.name || '')}</strong>
            <span>${escapeHtml([item.type, item.use_area].filter(Boolean).join(' · '))}</span>
            ${item.notes ? `<small>${escapeHtml(item.notes)}</small>` : ''}
        </div>
    `).join('') : '';
    const suggestedDocumentsHtml = suggestedDocuments.length ? suggestedDocuments.map(item => `
        <div class="knowledge-compact-item">
            <strong>${escapeHtml(item.name || '')}</strong>
            <span>${escapeHtml([item.type, item.use_area].filter(Boolean).join(' - '))}</span>
            ${item.notes ? `<small>${escapeHtml(item.notes)}</small>` : ''}
        </div>
    `).join('') : '';
    const sourcesHtml = sources.length ? sources.map(source => `
        <div class="knowledge-compact-item knowledge-source-item">
            <div>
                <strong>${escapeHtml(source.name || source.code || '')}</strong>
                <span>${escapeHtml([source.organization, source.title].filter(Boolean).join(' · '))}</span>
            </div>
            ${source.url ? `
                <a class="btn btn-outline-primary btn-sm knowledge-source-link" href="${escapeHtml(source.url)}" target="_blank" rel="noopener" title="Abrir fuente">
                    <i class="bi bi-link-45deg"></i>
                </a>
            ` : ''}
        </div>
    `).join('') : '';
    return `
        <div class="knowledge-problem-summary">
            <div>
                <div class="knowledge-eyebrow">${escapeHtml(problem.area_name || '')}</div>
                <h5>${escapeHtml(problem.name || '')}</h5>
                ${problem.alias ? `<div class="text-muted">${escapeHtml(problem.alias)}</div>` : ''}
                ${problem.description ? `<p class="knowledge-description">${escapeHtml(problem.description)}</p>` : ''}
            </div>
            <div class="knowledge-summary-side">
                <div class="knowledge-summary-badges">
                    ${problem.sector_label ? `<span class="badge text-bg-light">${escapeHtml(problem.sector_label)}</span>` : ''}
                    ${knowledgeRiskBadge(problem.risk_level, 'Riesgo')}
                    ${problem.population ? `<span class="badge text-bg-light">${escapeHtml(problem.population)}</span>` : ''}
                </div>
                ${canImportKnowledgeTasks ? `<button class="btn btn-outline-primary btn-sm" type="button" id="btn-import-knowledge-problem-tasks" title="Importa ${escapeHtml(taskPlural)} recomendadas de todas las ${escapeHtml(techniquePlural)}">
                    <i class="bi bi-list-check"></i> Importar ${escapeHtml(taskPlural)}
                </button>` : ''}
            </div>
        </div>
        <div class="knowledge-section">
            <h6>${escapeHtml(techniqueTitlePlural)} y ${escapeHtml(taskPlural)} recomendadas</h6>
            <p class="knowledge-section-help">Haz click en ${escapeHtml(techniqueSingular)} para desplegar las ${escapeHtml(taskPlural)} recomendadas.</p>
            ${techniquesHtml}
        </div>
        ${(questionnairesHtml || suggestedDocumentsHtml || sourcesHtml) ? `<div class="row g-3">
            ${questionnairesHtml ? `<div class="col-12">
                <div class="knowledge-section">
                    <h6>${escapeHtml(evaluationTitlePlural)} sugeridos</h6>
                    ${questionnairesHtml}
                </div>
            </div>` : ''}
            ${suggestedDocumentsHtml ? `<div class="col-12">
                <div class="knowledge-section">
                    <h6>${escapeHtml(resourceTitlePlural)} sugeridos</h6>
                    ${suggestedDocumentsHtml}
                </div>
            </div>` : ''}
            ${sourcesHtml ? `<div class="col-12">
                <div class="knowledge-section">
                    <h6>Fuentes</h6>
                    ${sourcesHtml}
                </div>
            </div>` : ''}
        </div>` : ''}
    `;
}

function initPatientBodyMap() {
    if (!bodyMapEnabled() || !$('#patient-body-map').length || BODY_MUSCLE_CHART) {
        return;
    }
    if (typeof BodyMuscles === 'undefined' || !BodyMuscles.BodyChart) {
        $('#patient-body-map').html('<div class="text-muted text-center py-4">No se pudo cargar el mapa muscular.</div>');
        return;
    }
    const container = document.getElementById('patient-body-map');
    BODY_MUSCLE_VIEW = BodyMuscles.ViewSide ? BodyMuscles.ViewSide.FRONT : 'FRONT';
    BODY_MUSCLE_CHART = new BodyMuscles.BodyChart(container, {
        view: BODY_MUSCLE_VIEW,
        bodyState: BODY_MUSCLE_STATE,
        ariaLabel: 'Mapa muscular interactivo',
        showViewLabel: false,
        onMuscleClick: function (id, name) {
            togglePatientBodyMapMuscle(id, name);
        }
    });
    syncPatientBodyMapResultsHeight();
}

function switchPatientKnowledgeMode(mode) {
    if (!bodyMapEnabled()) return;
    const selectedMode = mode === 'objective' || mode === 'custom-workout' ? mode : 'muscles';
    $('.patient-knowledge-mode-btn').each(function () {
        const isActive = $(this).data('knowledge-mode') === selectedMode;
        $(this)
            .toggleClass('active btn-primary', isActive)
            .toggleClass('btn-outline-primary', !isActive);
    });
    $('.patient-knowledge-mode-panel').each(function () {
        $(this).toggleClass('d-none', $(this).data('knowledge-mode-panel') !== selectedMode);
    });
    if (selectedMode === 'muscles') {
        initPatientBodyMap();
        syncPatientBodyMapResultsHeight();
    } else if (selectedMode === 'objective') {
        loadKnowledgeProblems(function () {
            loadSelectedPatientKnowledgeProblem();
        });
    }
}

function setPatientBodyMapView(view) {
    if (!bodyMapEnabled()) return;
    initPatientBodyMap();
    const normalized = view === 'BACK' ? 'BACK' : 'FRONT';
    BODY_MUSCLE_VIEW = (typeof BodyMuscles !== 'undefined' && BodyMuscles.ViewSide) ? BodyMuscles.ViewSide[normalized] : normalized;
    $('#btn-body-map-front').toggleClass('active', normalized === 'FRONT');
    $('#btn-body-map-back').toggleClass('active', normalized === 'BACK');
    if (BODY_MUSCLE_CHART) {
        BODY_MUSCLE_CHART.update({ view: BODY_MUSCLE_VIEW, bodyState: BODY_MUSCLE_STATE });
        applyPatientBodyMapSelectionColor();
        syncPatientBodyMapResultsHeight();
    }
}

function togglePatientBodyMapMuscle(id, name) {
    if (!id) return;
    if (BODY_MUSCLE_SELECTED.has(id)) {
        BODY_MUSCLE_SELECTED.delete(id);
        delete BODY_MUSCLE_SELECTED_NAMES[id];
        delete BODY_MUSCLE_STATE[id];
    } else {
        BODY_MUSCLE_SELECTED.add(id);
        BODY_MUSCLE_SELECTED_NAMES[id] = name || id;
        BODY_MUSCLE_STATE[id] = patientBodyMapSelectedState();
    }
    if (BODY_MUSCLE_CHART) {
        BODY_MUSCLE_CHART.update({ bodyState: BODY_MUSCLE_STATE });
        applyPatientBodyMapSelectionColor();
    }
    renderPatientBodyMapSelection();
    if (BODY_MUSCLE_SELECTED.size) {
        loadPatientBodyMapResults();
    } else {
        resetPatientBodyMapResults();
    }
}

function patientBodyMapSelectedState() {
    return {
        intensity: 8,
        selected: true
    };
}

function refreshPatientBodyMapSelectedColors() {
    BODY_MUSCLE_SELECTED.forEach(id => {
        BODY_MUSCLE_STATE[id] = patientBodyMapSelectedState();
    });
    if (BODY_MUSCLE_CHART) {
        BODY_MUSCLE_CHART.update({ bodyState: BODY_MUSCLE_STATE });
        applyPatientBodyMapSelectionColor();
    }
}

function applyPatientBodyMapSelectionColor() {
    const primaryColor = appPrimaryColor();
    requestAnimationFrame(() => {
        $('#patient-body-map .body-chart-muscle').each(function () {
            const selected = String(this.getAttribute('aria-label') || '').includes('(selected)');
            this.setAttribute('fill', selected ? '#dc2626' : primaryColor);
            this.setAttribute('stroke', selected ? '#ffffff' : '#1e293b');
        });
        $('#patient-body-map .body-chart-background path').each(function () {
            this.setAttribute('fill', primaryColor);
        });
    });
}

function clearPatientBodyMapSelection() {
    BODY_MUSCLE_SELECTED.clear();
    BODY_MUSCLE_SELECTED_NAMES = {};
    BODY_MUSCLE_STATE = {};
    if (BODY_MUSCLE_CHART) {
        BODY_MUSCLE_CHART.update({ bodyState: BODY_MUSCLE_STATE });
        applyPatientBodyMapSelectionColor();
    }
    renderPatientBodyMapSelection();
    resetPatientBodyMapResults();
}

function resetPatientBodyMapResults() {
    if (BODY_MUSCLE_RESULTS_REQUEST && BODY_MUSCLE_RESULTS_REQUEST.readyState !== 4) {
        BODY_MUSCLE_RESULTS_REQUEST.abort();
    }
    $('#patient-body-map-results').html('<div class="text-muted py-3">Selecciona un m&uacute;sculo para ver recomendaciones relacionadas.</div>');
    syncPatientBodyMapResultsHeight();
}

function syncPatientBodyMapResultsHeight() {
    requestAnimationFrame(() => {
        const $map = $('#patient-body-map');
        const $results = $('#patient-body-map-results');
        const $selected = $('#patient-body-map-selected');
        if (!$map.length || !$results.length) return;
        if (window.matchMedia('(max-width: 768px)').matches) {
            $results.css({ maxHeight: '', height: '' });
            return;
        }
        const mapHeight = Math.max(0, $map.outerHeight() || 0);
        const selectedHeight = Math.max(0, $selected.outerHeight(true) || 0);
        const availableHeight = Math.max(260, mapHeight - selectedHeight);
        $results.css({ maxHeight: `${availableHeight}px`, height: `${availableHeight}px` });
    });
}

function renderPatientBodyMapSelection() {
    const ids = Array.from(BODY_MUSCLE_SELECTED);
    if (!ids.length) {
        $('#patient-body-map-selected').html('<span class="text-muted">No hay zonas seleccionadas.</span>');
        return;
    }
    $('#patient-body-map-selected').html(ids.map(id => `
        <span class="badge rounded-pill text-bg-light patient-body-map-chip">
            ${escapeHtml(BODY_MUSCLE_SELECTED_NAMES[id] || id)}
        </span>
    `).join(''));
}

function loadPatientBodyMapResults() {
    const ids = Array.from(BODY_MUSCLE_SELECTED);
    if (!ids.length) return;
    if (BODY_MUSCLE_RESULTS_REQUEST && BODY_MUSCLE_RESULTS_REQUEST.readyState !== 4) {
        BODY_MUSCLE_RESULTS_REQUEST.abort();
    }
    $('#patient-body-map-results').html('<div class="text-muted py-3"><span class="spinner-border spinner-border-sm me-2"></span>Cargando recomendaciones musculares...</div>');
    BODY_MUSCLE_RESULTS_REQUEST = $.ajax({
        url: 'api/admin.php?action=body_map_recommendations',
        method: 'GET',
        dataType: 'json',
        data: { muscles: ids.join(',') },
        success: function (res) {
            if (!res.success) {
                $('#patient-body-map-results').html(`<div class="alert alert-warning mb-0">${escapeHtml(res.error || 'No se pudieron cargar recomendaciones musculares.')}</div>`);
                return;
            }
            if (Array.isArray(res.muscles)) {
                res.muscles.forEach(muscle => {
                    if (muscle && muscle.bodymuscles_id && muscle.name_es) {
                        BODY_MUSCLE_SELECTED_NAMES[muscle.bodymuscles_id] = muscle.name_es;
                    }
                });
                renderPatientBodyMapSelection();
            }
            $('#patient-body-map-results').html(renderPatientBodyMapResults(res));
            syncPatientBodyMapResultsHeight();
        },
        error: function (xhr) {
            if (xhr.statusText === 'abort') return;
            $('#patient-body-map-results').html('<div class="alert alert-warning mb-0">Error de conexion al cargar recomendaciones musculares.</div>');
            syncPatientBodyMapResultsHeight();
        }
    });
}

function renderPatientBodyMapResults(data) {
    const exercises = Array.isArray(data.exercises) ? data.exercises : [];
    if (!exercises.length) {
        return `<div class="text-muted py-3">${escapeHtml(data.message || 'No hay ejercicios o pautas vinculadas a las zonas seleccionadas.')}</div>`;
    }
    return `
        <div class="patient-body-map-results-header">
            <strong>${exercises.length} ${exercises.length === 1 ? 'resultado' : 'resultados'}</strong>
        </div>
        <div class="patient-body-exercise-list">
            ${exercises.map(exercise => {
                const regions = Array.isArray(exercise.regions) ? exercise.regions : [];
                const roleLabels = Array.from(new Set(regions.map(region => patientBodyMapRoleLabel(region.role)).filter(Boolean)));
                const exerciseName = workoutxExerciseDisplayName(exercise, exercise.exercise_id || '');
                return `
                    <article class="patient-body-exercise-item">
                        <div class="patient-body-exercise-main">
                            <strong>${escapeHtml(exerciseName)}</strong>
                            ${exercise.aliases ? `<div class="patient-body-exercise-aliases">${escapeHtml(exercise.aliases)}</div>` : ''}
                            <div class="patient-body-exercise-roles">
                                ${roleLabels.map(role => `<span class="badge text-bg-light">${escapeHtml(role)}</span>`).join('')}
                            </div>
                        </div>
                        <div class="patient-body-exercise-tags">
                            <button type="button" class="btn btn-primary btn-sm btn-add-fitness-exercise" data-exercise-id="${escapeHtml(exercise.exercise_id || '')}">
                                <i class="bi bi-plus-lg"></i> Agregar ejercicio
                            </button>
                            <button type="button" class="btn btn-outline-primary btn-sm btn-workoutx-exercise" data-exercise-id="${escapeHtml(exercise.exercise_id || '')}" data-exercise-name="${escapeHtml(exerciseName)}">
                                <i class="bi bi-info-circle"></i> Detalle
                            </button>
                        </div>
                        ${exercise.description_es ? `<p class="patient-body-exercise-description">${escapeHtml(exercise.description_es)}</p>` : ''}
                    </article>
                `;
            }).join('')}
        </div>
    `;
}

function generateWorkoutxPlan(button) {
    if (!knowledgeBaseEnabled()) return;
    const patientId = parseInt($('#patient-editor-id').val() || '0', 10);
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    if (!patientId) {
        showPatientKnowledgeAlert('danger', `Guarda primero el ${patientSingular}.`, true);
        return;
    }

    const $button = $(button);
    const original = $button.html();
    const params = {
        goal: $('#workoutx-plan-goal').val() || 'muscle_gain',
        duration: $('#workoutx-plan-duration').val() || 45,
        level: $('#workoutx-plan-level').val() || 'intermediate',
        split: $('#workoutx-plan-split').val() || 'full_body',
        equipment: getCheckedWorkoutxPlanValues('workoutx_plan_equipment[]').join(','),
        bodyFocus: getCheckedWorkoutxPlanValues('workoutx_plan_body_focus[]').join(',')
    };

    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Generando');
    $('#workoutx-generated-plan').html('<div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Generando rutina personalizada...</div>');

    $.ajax({
        url: 'api/admin.php?action=workoutx_generate_plan',
        dataType: 'json',
        data: params,
        success: function (res) {
            if (!res.success) {
                $('#workoutx-generated-plan').html(`<div class="alert alert-warning mb-0">${escapeHtml(res.error || 'No se pudo generar la rutina.')}</div>`);
                return;
            }
            $('#workoutx-generated-plan').html(renderWorkoutxGeneratedPlan(res.plan || {}, res.headers || {}));
        },
        error: function () {
            $('#workoutx-generated-plan').html('<div class="alert alert-warning mb-0">Error de conexion al generar la rutina.</div>');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function renderWorkoutxGeneratedPlan(plan, usageHeaders = {}) {
    const exercises = Array.isArray(plan.exercises) ? plan.exercises : [];
    const warmup = Array.isArray(plan.warmup) ? plan.warmup : [];
    const cooldown = Array.isArray(plan.cooldown) ? plan.cooldown : [];
    const bodyFocus = Array.isArray(plan.bodyFocus) ? plan.bodyFocus : [];
    const summary = [
        workoutxPlanLabel('goal', plan.goal),
        workoutxPlanLabel('level', plan.level),
        plan.estimatedDurationMinutes ? `${plan.estimatedDurationMinutes} min` : '',
        plan.totalExercises ? `${plan.totalExercises} ejercicios` : '',
        bodyFocus.length ? translateFitnessExerciseMeta(bodyFocus).join(', ') : ''
    ].filter(Boolean);

    if (!exercises.length) {
        return '<div class="text-muted py-3">No se han encontrado ejercicios para esos parametros.</div>';
    }

    return `
        <div class="workoutx-generated-summary">
            <div>
                <h6 class="mb-1">Rutina generada</h6>
                ${summary.length ? `<div class="small text-muted">${escapeHtml(summary.join(' · '))}</div>` : ''}
            </div>
            <div class="d-flex flex-wrap align-items-start justify-content-end gap-2">
                <button type="button" class="btn btn-primary btn-sm btn-add-workoutx-generated-plan">
                    <i class="bi bi-plus-lg"></i> Agregar rutina completa
                </button>
            </div>
        </div>
        ${warmup.length ? renderWorkoutxPlanActivityBlock('Calentamiento', warmup) : ''}
        <div class="workoutx-generated-exercise-list">
            ${exercises.map(item => renderWorkoutxGeneratedExerciseItem(item)).join('')}
        </div>
        ${cooldown.length ? renderWorkoutxPlanActivityBlock('Vuelta a la calma', cooldown) : ''}
        ${renderWorkoutxUsage(usageHeaders)}
    `;
}

function getCheckedWorkoutxPlanValues(name) {
    return $(`input[name="${name}"]:checked`).map(function () {
        return $(this).val();
    }).get().filter(Boolean);
}

function workoutxExerciseDisplayName(exercise = {}, fallback = 'Ejercicio') {
    return String(
        exercise.localNameEs ||
        exercise.name_es ||
        exercise.nameEs ||
        exercise.name_en ||
        exercise.name ||
        fallback ||
        ''
    ).trim() || fallback;
}

function renderWorkoutxGeneratedExerciseItem(item) {
    const exercise = item && typeof item === 'object' && item.exercise ? item.exercise : item;
    const name = workoutxExerciseDisplayName({
        localNameEs: item.localNameEs,
        name_es: item.localNameEs,
        name_en: item.localNameEn,
        name: exercise.name
    }, 'Ejercicio');
    const meta = translateFitnessExerciseMeta([exercise.bodyPart, exercise.target, exercise.equipment]).filter(Boolean);
    const localExerciseId = item.localExerciseId || '';
    const workoutxId = item.workoutxExternalId || exercise.id || '';
    const prescription = [
        item.sets ? `${item.sets} series` : '',
        item.reps ? `${item.reps} reps` : '',
        item.restSeconds ? `${item.restSeconds}s descanso` : '',
        item.note || ''
    ].filter(Boolean);

    return `
        <article class="workoutx-generated-exercise-item">
            <div class="workoutx-generated-exercise-main">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <strong>${escapeHtml(name)}</strong>
                        ${exercise.name && name !== exercise.name ? `<div class="text-muted small">${escapeHtml(exercise.name)}</div>` : ''}
                    </div>
                    ${item.order ? `<span class="badge text-bg-light">#${escapeHtml(item.order)}</span>` : ''}
                </div>
                ${meta.length ? `<div class="small text-muted">${escapeHtml(meta.join(' · '))}</div>` : ''}
                ${prescription.length ? `<div class="workoutx-generated-prescription small">${escapeHtml(prescription.join(' · '))}</div>` : ''}
            </div>
            <div class="workoutx-generated-exercise-actions">
                <button type="button" class="btn btn-primary btn-sm btn-add-generated-workoutx-exercise" data-exercise-id="${escapeHtml(localExerciseId)}" data-workoutx-id="${escapeHtml(workoutxId)}">
                    <i class="bi bi-plus-lg"></i> Agregar ejercicio
                </button>
                <button type="button" class="btn btn-outline-primary btn-sm btn-workoutx-exercise" data-exercise-id="${escapeHtml(localExerciseId)}" data-workoutx-id="${escapeHtml(workoutxId)}" data-exercise-name="${escapeHtml(name)}">
                    <i class="bi bi-info-circle"></i> Detalle
                </button>
            </div>
        </article>
    `;
}

async function addWorkoutxGeneratedPlanToPatient(button) {
    const patientId = parseInt($('#patient-editor-id').val() || '0', 10);
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    if (!patientId) {
        showPatientKnowledgeAlert('danger', `Guarda primero el ${patientSingular}.`, true);
        return;
    }
    const $buttons = $('#workoutx-generated-plan .btn-add-generated-workoutx-exercise').filter(function () {
        return !$(this).prop('disabled');
    });
    if (!$buttons.length) {
        showPatientKnowledgeAlert('warning', 'No hay ejercicios pendientes para agregar.', true);
        return;
    }

    const $button = $(button);
    const original = $button.html();
    let ok = 0;
    let failed = 0;
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Agregando');
    $buttons.prop('disabled', true);

    for (let index = 0; index < $buttons.length; index++) {
        const itemButton = $buttons[index];
        const $itemButton = $(itemButton);
        const itemOriginal = $itemButton.html();
        $itemButton.html('<span class="spinner-border spinner-border-sm"></span>');
        try {
            const res = await postFitnessExerciseToPatient($itemButton.data('exercise-id'), $itemButton.data('workoutx-id') || '');
            if (res && res.success) {
                ok++;
                $itemButton
                    .removeClass('btn-primary')
                    .addClass('btn-success')
                    .html('<i class="bi bi-check-lg"></i> Agregado');
            } else {
                failed++;
                $itemButton.prop('disabled', false).html(itemOriginal);
            }
        } catch (error) {
            failed++;
            $itemButton.prop('disabled', false).html(itemOriginal);
        }
    }

    $button.prop('disabled', false).html(original);
    if (ok > 0) {
        loadPatientWorkPlan(patientId);
        showPatientKnowledgeAlert('success', `${ok} ejercicios agregados al plan de trabajo${failed ? ` (${failed} con error)` : ''}.`, true);
    } else {
        showPatientKnowledgeAlert('danger', 'No se pudo agregar ningun ejercicio de la rutina.', true);
    }
}

function renderWorkoutxPlanActivityBlock(title, items) {
    const rows = items.map(item => {
        const activity = item.activity || item.name || '';
        const duration = item.durationMinutes ? `${item.durationMinutes} min` : '';
        const note = item.note || '';
        return `<li>${escapeHtml([activity, duration, note].filter(Boolean).join(' · '))}</li>`;
    }).join('');
    return `
        <div class="workoutx-generated-activity">
            <h6>${escapeHtml(title)}</h6>
            <ul>${rows}</ul>
        </div>
    `;
}

function renderWorkoutxUsage(headers) {
    return '';
}

function workoutxPlanLabel(type, value) {
    const labels = {
        goal: {
            muscle_gain: 'Ganar músculo',
            strength: 'Fuerza',
            fat_loss: 'Pérdida de grasa',
            endurance: 'Resistencia',
            mobility: 'Movilidad'
        },
        level: {
            beginner: 'Principiante',
            intermediate: 'Intermedio',
            advanced: 'Avanzado'
        }
    };
    return labels[type] && labels[type][value] ? labels[type][value] : (value || '');
}

function patientBodyMapRoleLabel(role) {
    const labels = {
        primary: 'Principal',
        secondary: 'Secundario',
        stabilizer: 'Estabilizador'
    };
    return labels[String(role || '').toLowerCase()] || role || '';
}

function translateFitnessExerciseDisplayValue(value) {
    const key = String(value || '').trim();
    if (!key) return '';

    const labels = {
        'Abs': 'Abdominales',
        'Adductors': 'Aductores',
        'Back': 'Espalda',
        'Band': 'Banda elástica',
        'Barbell': 'Barra',
        'Beginner': 'Principiante',
        'Biceps': 'Bíceps',
        'Body Weight': 'Peso corporal',
        'Cable': 'Polea/cable',
        'Calves': 'Gemelos',
        'Cardio': 'Cardio',
        'Chest': 'Pecho',
        'Delts': 'Deltoides',
        'Dumbbell': 'Mancuernas',
        'Flexibility': 'Movilidad/flexibilidad',
        'Forearms': 'Antebrazos',
        'Glutes': 'Glúteos',
        'Hamstrings': 'Isquiosurales',
        'Intermediate': 'Intermedio',
        'Kettlebell': 'Kettlebell',
        'Lats': 'Dorsales',
        'Leverage Machine': 'Máquina guiada',
        'Lower Arms': 'Antebrazos',
        'Lower Back': 'Zona lumbar',
        'Lower Legs': 'Piernas',
        'Machine': 'Máquina',
        'Neck': 'Cuello',
        'Pectorals': 'Pectorales',
        'Quads': 'Cuádriceps',
        'Shoulders': 'Hombros',
        'Smith Machine': 'Máquina Smith',
        'Strength': 'Fuerza',
        'Traps': 'Trapecios',
        'Triceps': 'Tríceps',
        'Upper Arms': 'Brazos',
        'Upper Back': 'Espalda alta',
        'Upper Legs': 'Piernas',
        'Waist': 'Zona media',
        'advanced': 'Avanzado',
        'beginner': 'Principiante',
        'cardio': 'Cardio',
        'flexibility': 'Movilidad/flexibilidad',
        'intermediate': 'Intermedio',
        'strength': 'Fuerza'
    };

    return labels[key] || key;
}

function translateFitnessExerciseMeta(items) {
    return (items || [])
        .map(translateFitnessExerciseDisplayValue)
        .filter(Boolean);
}

function openWorkoutxExerciseModal(exerciseId, exerciseName = '', workoutxExternalId = '') {
    exerciseId = String(exerciseId || '').trim();
    exerciseName = String(exerciseName || '').trim();
    workoutxExternalId = String(workoutxExternalId || '').trim();
    if ((!exerciseId && !workoutxExternalId) || !workoutxExerciseModal) {
        showPatientKnowledgeAlert('danger', 'No se pudo identificar el ejercicio.', true);
        return;
    }

    $('#workoutx-exercise-title').text(exerciseName || 'Ejercicio');
    $('#workoutx-exercise-alert').addClass('d-none').text('');
    $('#workoutx-exercise-content').html('<div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Cargando ejercicio...</div>');
    $('#workoutx-exercise-footer').addClass('d-none');
    $('#btn-add-workoutx-exercise-footer').removeData('exercise-id').removeData('workoutx-id');
    workoutxExerciseModal.show();

    $.ajax({
        url: 'api/admin.php?action=workoutx_exercise_media',
        dataType: 'json',
        data: { exercise_id: exerciseId, workoutx_external_id: workoutxExternalId },
        success: function (res) {
            if (!res.success) {
                $('#workoutx-exercise-content').html('');
                $('#workoutx-exercise-alert')
                    .removeClass('d-none alert-success')
                    .addClass('alert-danger')
                    .text(res.error || 'No se pudo cargar el detalle del ejercicio.');
                return;
            }
            renderWorkoutxExercise(res.exercise || {}, res.headers || {});
        },
        error: function () {
            $('#workoutx-exercise-content').html('');
            $('#workoutx-exercise-alert')
                .removeClass('d-none alert-success')
                .addClass('alert-danger')
                .text('Error de conexion al consultar el detalle del ejercicio.');
        }
    });
}

function addFitnessExerciseToPatient(exerciseId, button, workoutxExternalId = '') {
    exerciseId = String(exerciseId || '').trim();
    workoutxExternalId = String(workoutxExternalId || '').trim();
    const patientId = parseInt($('#patient-editor-id').val() || '0', 10);
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    if (!patientId) {
        showPatientKnowledgeAlert('danger', `Guarda primero el ${patientSingular}.`, true);
        return;
    }
    if (!exerciseId && !workoutxExternalId) {
        showPatientKnowledgeAlert('danger', 'No se pudo identificar el ejercicio.', true);
        return;
    }

    const $button = $(button);
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    postFitnessExerciseToPatient(exerciseId, workoutxExternalId)
        .done(function (res) {
            if (!res.success) {
                showPatientKnowledgeAlert('danger', res.error || 'No se pudo agregar el ejercicio.', true);
                return;
            }
            showPatientKnowledgeAlert('success', res.message || 'Ejercicio agregado al plan de trabajo.', true);
            loadPatientWorkPlan(patientId);
        })
        .fail(function () {
            showPatientKnowledgeAlert('danger', 'Error de conexion al agregar el ejercicio.', true);
        })
        .always(function () {
            $button.prop('disabled', false).html(original);
        });
}

function postFitnessExerciseToPatient(exerciseId, workoutxExternalId = '') {
    const patientId = parseInt($('#patient-editor-id').val() || '0', 10);
    return $.ajax({
        url: 'api/admin.php?action=add_fitness_exercise_to_work_plan',
        method: 'POST',
        dataType: 'json',
        data: {
            patient_id: patientId,
            exercise_id: String(exerciseId || '').trim(),
            workoutx_external_id: String(workoutxExternalId || '').trim(),
            visible_to_patient: PAYMENT_SETTINGS.patient_tasks_visible_default == 1 ? 1 : 0
        }
    });
}

function openPatientPortalExerciseModal(taskId) {
    taskId = parseInt(taskId || 0, 10);
    if (!taskId || !workoutxExerciseModal) return;
    $('#workoutx-exercise-title').text('Detalle del ejercicio');
    $('#workoutx-exercise-alert').addClass('d-none').text('');
    $('#workoutx-exercise-content').html('<div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Cargando ejercicio...</div>');
    $('#workoutx-exercise-footer').addClass('d-none');
    $('#btn-add-workoutx-exercise-footer').removeData('exercise-id').removeData('workoutx-id');
    workoutxExerciseModal.show();

    $.ajax({
        url: 'api/appointments.php?action=patient_portal_exercise_media',
        dataType: 'json',
        data: { task_id: taskId },
        success: function (res) {
            if (!res.success) {
                $('#workoutx-exercise-content').html('');
                $('#workoutx-exercise-alert')
                    .removeClass('d-none alert-success')
                    .addClass('alert-danger')
                    .text(res.error || 'No se pudo cargar el ejercicio.');
                return;
            }
            renderWorkoutxExercise(res.exercise || {}, res.headers || {});
            $('#workoutx-exercise-footer').addClass('d-none');
            $('#btn-add-workoutx-exercise-footer').removeData('exercise-id').removeData('workoutx-id');
        },
        error: function () {
            $('#workoutx-exercise-content').html('');
            $('#workoutx-exercise-alert')
                .removeClass('d-none alert-success')
                .addClass('alert-danger')
                .text('Error de conexion al consultar el ejercicio.');
        }
    });
}

function renderWorkoutxExercise(exercise, usageHeaders = {}) {
    const name = workoutxExerciseDisplayName(exercise, 'Ejercicio');
    const localExerciseId = exercise.localExerciseId || '';
    const gifUrl = exercise.gifUrl || '';
    const meta = translateFitnessExerciseMeta([exercise.bodyPart, exercise.target, exercise.equipment, exercise.difficulty]);
    const secondary = Array.isArray(exercise.secondaryMuscles)
        ? translateFitnessExerciseMeta(exercise.secondaryMuscles)
        : [];
    const instructions = Array.isArray(exercise.instructions)
        ? exercise.instructions.map(step => String(step || '').trim()).filter(Boolean)
        : [];
    const description = exercise.descriptionEs || '';
    const cues = exercise.cuesEs || '';
    const caloriesHtml = renderWorkoutxCalories(exercise.caloriesPerMinute, exercise.patientWeightKg);

    $('#workoutx-exercise-title').text(name);
    $('#workoutx-exercise-alert').addClass('d-none').text('');
    $('#workoutx-exercise-content').html(`
        <div class="workoutx-exercise-layout">
            <div class="workoutx-exercise-gif">
                ${gifUrl
                    ? `<div class="workoutx-exercise-gif-loader"><span class="spinner-border spinner-border-sm me-2"></span>Cargando imagen...</div><img class="workoutx-exercise-image is-loading" data-src="${escapeHtml(gifUrl)}" alt="${escapeHtml(name)}">`
                    : '<div class="text-muted py-5 text-center">No hay GIF disponible para este ejercicio.</div>'}
            </div>
            <div class="workoutx-exercise-detail">
                ${meta.length ? `<div class="workoutx-exercise-meta">${escapeHtml(meta.join(' - '))}</div>` : ''}
                ${secondary.length ? `<div class="small text-muted mb-2">Secundarios: ${escapeHtml(secondary.join(', '))}</div>` : ''}
                ${caloriesHtml}
                ${description ? `<p class="small mb-0">${escapeHtml(description)}</p>` : ''}
            </div>
            <div class="workoutx-exercise-instruction-block">
                <h6 class="workoutx-exercise-section-title">Instrucciones</h6>
                ${instructions.length ? `
                    <ol class="workoutx-exercise-instructions">
                        ${instructions.slice(0, 8).map(step => `<li>${escapeHtml(step)}</li>`).join('')}
                    </ol>
                ` : '<div class="text-muted small">Sin instrucciones paso a paso disponibles.</div>'}
                ${cues ? `
                    <h6 class="workoutx-exercise-section-title mt-3">Recomendaciones</h6>
                    <div class="small text-muted">${escapeHtml(cues)}</div>
                ` : ''}
            </div>
        </div>
    `);
    const $footer = $('#workoutx-exercise-footer');
    const $footerButton = $('#btn-add-workoutx-exercise-footer');
    if (localExerciseId || exercise.id) {
        $footer.removeClass('d-none');
        $footerButton
            .data('exercise-id', localExerciseId)
            .data('workoutx-id', exercise.id || '');
    } else {
        $footer.addClass('d-none');
        $footerButton.removeData('exercise-id').removeData('workoutx-id');
    }
    const $img = $('#workoutx-exercise-content .workoutx-exercise-gif img');
    if ($img.length) {
        const revealImage = function () {
            $(this).removeClass('is-loading');
            $(this).siblings('.workoutx-exercise-gif-loader').addClass('d-none');
        };
        $img.on('load', revealImage).on('error', function () {
            $(this).addClass('d-none').removeClass('is-loading');
            $(this).siblings('.workoutx-exercise-gif-loader').html('<span class="text-muted">No se pudo cargar la imagen.</span>');
        });
        const source = String($img.data('src') || '').trim();
        if (source) {
            $img.attr('src', source);
            if ($img[0].complete && $img[0].naturalWidth > 0) {
                $img.trigger('load');
            }
        }
    }
}

function renderWorkoutxCalories(baseCaloriesPerMinute, patientWeightKg = null) {
    const base = Number(String(baseCaloriesPerMinute || '').replace(',', '.'));
    if (!base || Number.isNaN(base)) return '';

    const weight = Number(patientWeightKg || patientMetricNumber('#patient-editor-weight') || 0);
    if (weight > 0) {
        const adjusted = base * weight / 70;
        return `
            <div class="small text-muted mb-2">
                Calorías/min aprox.: ${escapeHtml(adjusted.toFixed(1))}
                <span class="d-block">Estimación ajustada al peso registrado: ${escapeHtml(formatMetricValue(weight))} kg.</span>
            </div>
        `;
    }

    return `
        <div class="small text-muted mb-2">
            Calorías/min aprox.: ${escapeHtml(base.toFixed(1))}
            <span class="d-block">Nota: calorías estimadas para una persona de 70 kg de peso.</span>
        </div>
    `;
}

function translateWorkoutxExerciseName(value) {
    let text = String(value || '').trim();
    if (!text) return '';
    const exact = {
        'Barbell Wide Bench Press': 'Press de banca con agarre ancho',
        'Barbell Decline Bench Press': 'Press de banca declinado con barra',
        'Barbell Incline Bench Press': 'Press de banca inclinado con barra',
        'Barbell Bench Press': 'Press de banca con barra',
        'Dumbbell Bench Press': 'Press de banca con mancuernas',
        'Cable Low Fly': 'Aperturas en polea baja',
        'Cable Standing Fly': 'Aperturas de pie en polea',
        'Smith Bench Press': 'Press de banca en maquina Smith'
    };
    if (exact[text]) return exact[text];
    const replacements = [
        ['Assisted', 'asistido'],
        ['Weighted', 'lastrado'],
        ['Barbell', 'con barra'],
        ['Dumbbell', 'con mancuernas'],
        ['Cable', 'en polea'],
        ['Smith', 'en máquina Smith'],
        ['Lever', 'en máquina'],
        ['Kettlebell', 'con kettlebell'],
        ['Chest Dip', 'fondo de pecho'],
        ['Triceps Dip', 'fondo de tríceps'],
        ['Bench Press', 'press de banca'],
        ['Shoulder Press', 'press de hombro'],
        ['Biceps Curl', 'curl de bíceps'],
        ['Pull-up', 'dominada'],
        ['Chin-up', 'dominada supina'],
        ['Push-up', 'flexión'],
        ['Squat', 'sentadilla'],
        ['Row', 'remo'],
        ['Crunch', 'crunch abdominal'],
        ['Knee Raise', 'elevación de rodillas'],
        ['Lateral Raise', 'elevación lateral'],
        ['Extension', 'extensión'],
        ['Curl', 'curl'],
        ['Press', 'press'],
        ['Fly', 'aperturas'],
        ['kneeling', 'de rodillas'],
        ['standing', 'de pie'],
        ['seated', 'sentado'],
        ['lying', 'tumbado'],
        ['incline', 'inclinado'],
        ['decline', 'declinado']
    ];
    replacements.forEach(([from, to]) => {
        text = text.replace(new RegExp(`\\b${escapeRegExp(from)}\\b`, 'gi'), to);
    });
    text = text
        .replace(/\bwith\b/gi, 'con')
        .replace(/\bon\b/gi, 'en')
        .replace(/\band\b/gi, 'y')
        .replace(/\s+/g, ' ')
        .trim();
    return text ? text.charAt(0).toUpperCase() + text.slice(1) : '';
}

function polishWorkoutxExerciseSpanishName(value) {
    let text = String(value || '').trim();
    if (!text) return '';
    const exact = {
        'Con barra declinado press de banca': 'Press de banca declinado con barra',
        'Con barra inclinado press de banca': 'Press de banca inclinado con barra',
        'Con barra ancho press de banca': 'Press de banca con agarre ancho',
        'Con barra wide press de banca': 'Press de banca con agarre ancho',
        'Con barra press de banca': 'Press de banca con barra',
        'Con mancuernas press de banca': 'Press de banca con mancuernas',
        'En polea low aperturas': 'Aperturas en polea baja',
        'En polea standing aperturas': 'Aperturas de pie en polea'
    };
    if (exact[text]) return exact[text];
    text = text
        .replace(/^Con barra declinado press de banca$/i, 'Press de banca declinado con barra')
        .replace(/^Con barra inclinado press de banca$/i, 'Press de banca inclinado con barra')
        .replace(/^Con barra(?: wide| ancho) press de banca$/i, 'Press de banca con agarre ancho')
        .replace(/^Con barra press de banca$/i, 'Press de banca con barra')
        .replace(/^Con mancuernas press de banca$/i, 'Press de banca con mancuernas')
        .replace(/^En polea low aperturas$/i, 'Aperturas en polea baja')
        .replace(/^En polea standing aperturas$/i, 'Aperturas de pie en polea');
    return text ? text.charAt(0).toUpperCase() + text.slice(1) : '';
}

function translateWorkoutxInstruction(value) {
    let text = String(value || '').trim();
    if (!text) return '';

    const exact = {
        'Adjust the machine to your desired height and secure your knees on the pad.': 'Ajusta la máquina a la altura adecuada y asegura las rodillas en el apoyo.',
        'Grasp the handles with your palms facing down and your arms fully extended.': 'Agarra las asas con las palmas hacia abajo y los brazos completamente extendidos.',
        'Lower your body by bending your elbows until your upper arms are parallel to the floor.': 'Baja el cuerpo flexionando los codos hasta que la parte superior de los brazos quede paralela al suelo.',
        'Repeat for the desired number of repetitions.': 'Repite el movimiento el número de repeticiones indicado.',
        'Pause for a moment, then push yourself back up to the starting position.': 'Haz una breve pausa y vuelve de forma controlada a la posición inicial.'
    };
    if (exact[text]) return exact[text];

    const replacements = [
        ['Adjust the machine to your desired height', 'Ajusta la máquina a la altura adecuada'],
        ['adjust the machine', 'ajusta la máquina'],
        ['secure your knees on the pad', 'asegura las rodillas en el apoyo'],
        ['on the pad', 'en el apoyo'],
        ['desired height', 'altura adecuada'],
        ['desired number of repetitions', 'número de repeticiones indicado'],
        ['Grasp the handles', 'Agarra las asas'],
        ['Grab the handles', 'Agarra las asas'],
        ['with your palms facing down', 'con las palmas hacia abajo'],
        ['with your palms facing up', 'con las palmas hacia arriba'],
        ['with your arms fully extended', 'con los brazos completamente extendidos'],
        ['arms fully extended', 'brazos completamente extendidos'],
        ['Lower your body', 'Baja el cuerpo'],
        ['by bending your elbows', 'flexionando los codos'],
        ['until your upper arms are parallel to the floor', 'hasta que la parte superior de los brazos quede paralela al suelo'],
        ['Push yourself back up', 'Empuja para volver arriba'],
        ['starting position', 'posición inicial'],
        ['Stand with your feet shoulder-width apart', 'Colócate de pie con los pies a la anchura de los hombros'],
        ['Sit on the machine', 'Siéntate en la máquina'],
        ['Lie flat on your back', 'Túmbate boca arriba'],
        ['Lie face down', 'Túmbate boca abajo'],
        ['Keep your back straight', 'Mantén la espalda recta'],
        ['Keep your core engaged', 'Mantén la zona media activada'],
        ['Engage your core', 'Activa la zona media'],
        ['Squeeze your', 'Contrae'],
        ['Slowly return', 'Vuelve lentamente'],
        ['Return to the starting position', 'Vuelve a la posición inicial'],
        ['Hold for a moment', 'Mantén la posición un instante'],
        ['Pause for a moment', 'Haz una breve pausa'],
        ['Inhale', 'Inspira'],
        ['Exhale', 'Espira'],
        ['your chest', 'el pecho'],
        ['your shoulders', 'los hombros'],
        ['your elbows', 'los codos'],
        ['your knees', 'las rodillas'],
        ['your hips', 'la cadera'],
        ['your arms', 'los brazos'],
        ['your legs', 'las piernas'],
        ['your feet', 'los pies'],
        ['your hands', 'las manos'],
        ['the weight', 'la carga'],
        ['the barbell', 'la barra'],
        ['the dumbbells', 'las mancuernas'],
        ['the cable', 'la polea'],
        ['the handle', 'el agarre'],
        ['the handles', 'las asas'],
        ['the floor', 'el suelo']
    ];

    replacements.forEach(([from, to]) => {
        text = text.replace(new RegExp(escapeRegExp(from), 'gi'), to);
    });

    text = text
        .replace(/\byour\b/gi, 'tu')
        .replace(/\bthe\b/gi, 'el')
        .replace(/\band\b/gi, 'y')
        .replace(/\bon\b/gi, 'en')
        .replace(/\bwith\b/gi, 'con')
        .replace(/\buntil\b/gi, 'hasta')
        .replace(/\bthen\b/gi, 'después')
        .replace(/\bslowly\b/gi, 'lentamente')
        .replace(/\bdown\b/gi, 'abajo')
        .replace(/\bup\b/gi, 'arriba')
        .replace(/\brepetitions\b/gi, 'repeticiones')
        .replace(/\breps\b/gi, 'repeticiones')
        .replace(/\s+/g, ' ')
        .trim();

    return text ? text.charAt(0).toUpperCase() + text.slice(1) : '';
}

function escapeRegExp(value) {
    return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function showPatientKnowledgeAlert(type, message, autoHide = false) {
    const alertClass = type === 'success'
        ? 'alert-success'
        : (type === 'warning' ? 'alert-warning' : (type === 'info' ? 'alert-info' : 'alert-danger'));
    const $alert = $('#patient-knowledge-alert')
        .removeClass('d-none alert-success alert-danger alert-warning alert-info')
        .addClass(alertClass)
        .text(message);
    if (autoHide || type === 'success') {
        setTimeout(() => $alert.addClass('d-none').text(''), 3000);
    }
}

function importKnowledgeRecommendationTask(button) {
    if (!knowledgeImportEnabled()) {
        showPatientKnowledgeAlert('danger', 'La importacion de tareas recomendadas no esta disponible en este plan.');
        return;
    }
    const patientId = parseInt($('#patient-editor-id').val() || 0, 10);
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const recommendationId = parseInt($(button).data('recommendation-id') || 0, 10);
    const taskSingular = sectorLabel('task', 'singular', 'tarea');
    if (!patientId) {
        showPatientKnowledgeAlert('danger', `Guarda primero el ${patientSingular}.`);
        return;
    }
    const $button = $(button);
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({
        url: 'api/admin.php?action=import_knowledge_recommendation_task',
        method: 'POST',
        dataType: 'json',
        data: { patient_id: patientId, recommendation_id: recommendationId },
        success: function (res) {
            if (!res.success) {
                showPatientKnowledgeAlert('danger', res.error || `No se pudo anadir ${taskSingular}.`);
                return;
            }
            showPatientKnowledgeAlert('success', `${capitalizeFirst(taskSingular)} anadida al plan de trabajo.`);
            loadPatientWorkPlan(patientId);
        },
        error: function () {
            showPatientKnowledgeAlert('danger', `Error de conexion al anadir ${taskSingular}.`);
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function importKnowledgeProblemTasks(button) {
    if (!knowledgeImportEnabled()) {
        showPatientKnowledgeAlert('danger', 'La importacion de tareas recomendadas no esta disponible en este plan.');
        return;
    }
    const patientId = parseInt($('#patient-editor-id').val() || 0, 10);
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const problemId = parseInt($('#patient-editor-knowledge-problem').val() || 0, 10);
    const diagnosisLabel = sectorText('clinicalTerms.diagnosis', 'diagnostico');
    const taskPlural = sectorLabel('task', 'plural', 'tareas');
    if (!patientId) {
        showPatientKnowledgeAlert('danger', `Guarda primero el ${patientSingular}.`);
        return;
    }
    if (!problemId) {
        showPatientKnowledgeAlert('danger', `Selecciona ${diagnosisLabel}.`);
        return;
    }
    const $button = $(button);
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Importando');
    $.ajax({
        url: 'api/admin.php?action=import_knowledge_problem_tasks',
        method: 'POST',
        dataType: 'json',
        data: { patient_id: patientId, problem_id: problemId },
        success: function (res) {
            if (!res.success) {
                showPatientKnowledgeAlert('danger', res.error || 'No se pudieron importar las tareas.');
                return;
            }
            const count = parseInt(res.count || 0, 10);
            showPatientKnowledgeAlert('success', `${count || ''} ${taskPlural} importadas.`.trim());
            loadPatientWorkPlan(patientId);
        },
        error: function () {
            showPatientKnowledgeAlert('danger', 'Error de conexion al importar las tareas.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function importKnowledgeTechniqueTasks(button) {
    if (!knowledgeImportEnabled()) {
        showPatientKnowledgeAlert('danger', 'La importacion de tareas recomendadas no esta disponible en este plan.');
        return;
    }
    const patientId = parseInt($('#patient-editor-id').val() || 0, 10);
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const problemId = parseInt($('#patient-editor-knowledge-problem').val() || 0, 10);
    const techniqueId = parseInt($(button).data('technique-id') || 0, 10);
    const techniqueSingular = sectorLabel('technique', 'singular', 'tecnica');
    const taskPlural = sectorLabel('task', 'plural', 'tareas');
    if (!patientId) {
        showPatientKnowledgeAlert('danger', `Guarda primero el ${patientSingular}.`);
        return;
    }
    if (!problemId || !techniqueId) {
        showPatientKnowledgeAlert('danger', `No se pudo identificar ${techniqueSingular} seleccionada.`);
        return;
    }
    const $button = $(button);
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Importando');
    $.ajax({
        url: 'api/admin.php?action=import_knowledge_technique_tasks',
        method: 'POST',
        dataType: 'json',
        data: { patient_id: patientId, problem_id: problemId, technique_id: techniqueId },
        success: function (res) {
            if (!res.success) {
                showPatientKnowledgeAlert('danger', res.error || `No se pudieron importar ${taskPlural}.`);
                return;
            }
            const count = parseInt(res.count || 0, 10);
            showPatientKnowledgeAlert('success', `${count || ''} ${taskPlural} importadas.`.trim());
            loadPatientWorkPlan(patientId);
        },
        error: function () {
            showPatientKnowledgeAlert('danger', `Error de conexion al importar ${taskPlural}.`);
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function openPatientEditorModal(patient = null) {
    if (!patientEditorModal) return;
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    CURRENT_PATIENT_EDITOR = patient ? { ...patient } : null;
    $('#patient-editor-alert').addClass('d-none').text('');
    $('#patient-history-alert').addClass('d-none').text('');
    $('#patient-editor-form')[0].reset();
    $('#patient-editor-title').text(patient ? `Editar ${patientSingular}` : `Nuevo ${patientSingular}`);
    $('#patient-editor-id').val(patient ? patient.id : '');
    $('.btn-patient-report').prop('disabled', !(patient && patient.id));
    $('#patient-editor-name').val(patient ? patient.name || '' : '');
    $('#patient-editor-type').val(patient ? patient.patient_type || '' : '');
    $('#patient-editor-status').val(patient ? patient.patient_status || 'active' : 'active');
    $('#patient-editor-birth-date').val(patient ? patient.birth_date || '' : '');
    $('#patient-editor-referral-source').val(patient ? patient.referral_source || '' : '');
    $('#patient-editor-knowledge-problem').val(patient ? patient.knowledge_problem_id || '' : '');
    CURRENT_KNOWLEDGE_PROBLEM_DETAIL = null;
    $('#patient-knowledge-alert').addClass('d-none').text('');
    $('#patient-knowledge-content').html('<div class="text-center text-muted py-4">No hay diagn&oacute;stico seleccionado.</div>');
    clearPatientBodyMapSelection();
    loadKnowledgeProblems(function () {
        $('#patient-editor-knowledge-problem').val(patient ? patient.knowledge_problem_id || '' : '');
    });
    $('#patient-editor-emergency-name').val(patient ? patient.emergency_contact_name || '' : '');
    $('#patient-editor-emergency-phone').val(patient ? patient.emergency_contact_phone || '' : '');
    $('#patient-editor-emergency-relation').val(patient ? patient.emergency_contact_relation || '' : '');
    $('#patient-editor-initial-reason').val(patient ? patient.initial_consultation_reason || '' : '');
    updatePatientAgeDisplay();
    $('#patient-editor-email').val(patient ? patient.email || '' : '');
    $('#patient-editor-phone').val(patient ? patient.phone || '' : '');
    $('#patient-editor-photo').val('');
    if (patient && patient.photo_path) {
        $('#patient-editor-photo-preview').attr('src', assetUrl(patient.photo_path)).removeClass('d-none');
        $('#patient-editor-photo-status').text('Foto actual guardada.');
    } else {
        $('#patient-editor-photo-preview').attr('src', '').addClass('d-none');
        $('#patient-editor-photo-status').text('Formatos permitidos: JPG, PNG, WEBP o GIF. Maximo 2 MB.');
    }
    populatePatientEditorProfessionalSelect(patient);
    $('#patient-editor-admission-date').val(patient ? patient.admission_date || formatDate(new Date()) : formatDate(new Date()));
    $('#patient-editor-notes').val(patient ? patient.notes || '' : '');
    $('#patient-editor-physical-sex').val(patient ? patient.physical_sex || '' : '');
    $('#patient-editor-weight').val(patient ? patient.weight_kg || '' : '');
    $('#patient-editor-height').val(patient ? patient.height_cm || '' : '');
    $('#patient-editor-body-fat').val(patient ? patient.body_fat_percentage || '' : '');
    $('#patient-editor-waist').val(patient ? patient.waist_cm || '' : '');
    $('#patient-editor-hip').val(patient ? patient.hip_cm || '' : '');
    $('#patient-editor-chest').val(patient ? patient.chest_cm || '' : '');
    $('#patient-editor-thigh').val(patient ? patient.thigh_cm || '' : '');
    $('#patient-editor-biceps').val(patient ? patient.biceps_cm || '' : '');
    $('#patient-editor-calf').val(patient ? patient.calf_cm || '' : '');
    $('#patient-editor-skinfold-triceps').val(patient ? patient.skinfold_triceps_mm || '' : '');
    $('#patient-editor-skinfold-subscapular').val(patient ? patient.skinfold_subscapular_mm || '' : '');
    $('#patient-editor-skinfold-suprailiac').val(patient ? patient.skinfold_suprailiac_mm || '' : '');
    $('#patient-editor-skinfold-abdominal').val(patient ? patient.skinfold_abdominal_mm || '' : '');
    $('#patient-editor-skinfold-chest').val(patient ? patient.skinfold_chest_mm || '' : '');
    $('#patient-editor-skinfold-thigh').val(patient ? patient.skinfold_thigh_mm || '' : '');
    $('#patient-editor-body-fat-note').text('Puedes introducirlo manualmente si ya tienes una medicion fiable.');
    updatePatientBmiDisplay();
    updatePatientTransferUi(patient);
    if (patient && patient.document_path) {
        $('#patient-editor-document-status').html(`Archivo actual: <a href="api/admin.php?action=download_patient_document&patient_id=${patient.id}" target="_blank" rel="noopener">${escapeHtml(patient.document_name || 'Documento')}</a>`);
    } else {
        $('#patient-editor-document-status').text('Puedes adjuntar un PDF, XLS o XLSX de hasta 12 MB.');
    }
    bootstrap.Tab.getOrCreateInstance(document.getElementById('patient-data-tab')).show();
    resetPatientAppointmentHistory(patient ? patient.id : 0);
    resetPatientWorkPlan(patient ? patient.id : 0);
    resetPatientEvolution(patient ? patient.id : 0);
    resetPatientFiles(patient ? patient.id : 0);
    resetPatientReports(patient ? patient.id : 0);
    resetPatientBonuses(patient ? patient.id : 0);
    if (patient && patient.id) {
        loadPatientAppointmentHistory(patient.id);
        loadPatientWorkPlan(patient.id);
        loadPatientEvolution(patient.id);
        loadPatientFiles(patient.id);
        loadPatientBonuses(patient.id);
    }
    patientEditorModal.show();
}

function resetPatientAppointmentHistory(patientId = 0) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    CURRENT_PATIENT_HISTORY_ID = parseInt(patientId || 0, 10);
    $('#patient-history-count').text('');
    if (!patientId) {
        $('#patient-history-body').html(`<tr><td colspan="7" class="text-center text-muted py-4">Guarda el ${patientSingular} para ver su historial de citas.</td></tr>`);
        return;
    }
    $('#patient-history-body').html('<tr><td colspan="7" class="text-center text-muted py-4">Cargando historial...</td></tr>');
}

function loadPatientAppointmentHistory(patientId) {
    patientId = parseInt(patientId || 0, 10);
    if (!patientId) {
        resetPatientAppointmentHistory(0);
        return;
    }
    $('#patient-history-alert').addClass('d-none').text('');
    CURRENT_PATIENT_HISTORY_ID = patientId;
    $('#patient-history-body').html('<tr><td colspan="7" class="text-center text-muted py-4">Cargando historial...</td></tr>');
    $('#patient-history-count').text('');

    $.ajax({
        url: 'api/admin.php?action=patient_appointments',
        dataType: 'json',
        data: { patient_id: patientId },
        success: function (res) {
            if (!res.success) {
                showPatientHistoryAlert('danger', res.error || 'No se pudo cargar el historial.');
                $('#patient-history-body').html('<tr><td colspan="7" class="text-center text-muted py-4">No se pudo cargar el historial.</td></tr>');
                return;
            }
            renderPatientAppointmentHistory(res.appointments || []);
        },
        error: function () {
            showPatientHistoryAlert('danger', 'Error de conexión al cargar el historial.');
            $('#patient-history-body').html('<tr><td colspan="7" class="text-center text-muted py-4">No se pudo cargar el historial.</td></tr>');
        }
    });
}

function renderPatientAppointmentHistory(appointments) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const rows = Array.isArray(appointments) ? appointments : [];
    if (!rows.length) {
        $('#patient-history-body').html(`<tr><td colspan="7" class="text-center text-muted py-4">Este ${patientSingular} todavía no tiene citas registradas.</td></tr>`);
        $('#patient-history-count').text('');
        return;
    }

    const html = rows.map(app => `
        <tr>
            <td><strong>${formatDisplayDate(app.appointment_date || '')}</strong><br><small class="text-muted">${escapeHtml(displayAppointmentTimeRange(app.appointment_time || '', app.duration_minutes || 60))}</small></td>
            <td>${professionalCellHtml(app, 'professional_name', 'professional_photo_path')}</td>
            <td>${escapeHtml(displayAppointmentServiceLabel(app))}<br><small class="text-muted">${displayAppointmentDurationLabel(app.duration_minutes || 60)}</small></td>
            <td>${consultationTypeLabel(app.consultation_type)}</td>
            <td>${adminPaymentLabel(app)}</td>
            <td class="text-end">${appointmentPaymentButton(app)}</td>
            <td>${appointmentStatusLabel(app.status)}</td>
        </tr>
    `).join('');

    $('#patient-history-body').html(html);
    $('#patient-history-count').text(`${rows.length} ${rows.length === 1 ? 'cita' : 'citas'}`);
}

function resetPatientWorkPlan(patientId = 0) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    CURRENT_PATIENT_WORK_PLAN_ID = parseInt(patientId || 0, 10);
    CURRENT_PATIENT_WORK_PLAN_ROWS = [];
    syncPatientWorkPlanStatusUi();
    $('#patient-work-plan-alert').addClass('d-none').text('');
    $('#patient-work-plan-count').text('');
    hidePatientWorkPlanForm();
    const emptyText = patientId ? 'Cargando plan de trabajo...' : `Guarda el ${patientSingular} para ver su plan de trabajo.`;
    $('#patient-work-plan-pending').html(`<div class="text-center text-muted py-4">${emptyText}</div>`);
    $('#patient-work-plan-completed-list').html(`<div class="text-center text-muted py-4">${emptyText}</div>`);
}

function loadPatientWorkPlan(patientId) {
    patientId = parseInt(patientId || 0, 10);
    if (!patientId) {
        resetPatientWorkPlan(0);
        return;
    }
    CURRENT_PATIENT_WORK_PLAN_ID = patientId;
    syncPatientWorkPlanStatusUi();
    $('#patient-work-plan-alert').addClass('d-none').text('');
    $('#patient-work-plan-pending').html('<div class="text-center text-muted py-4">Cargando plan de trabajo...</div>');
    $('#patient-work-plan-completed-list').html('<div class="text-center text-muted py-4">Cargando plan de trabajo...</div>');
    $('#patient-work-plan-count').text('');
    $.ajax({
        url: 'api/admin.php?action=patient_work_plan',
        dataType: 'json',
        data: { patient_id: patientId },
        success: function (res) {
            if (!res.success) {
                showPatientWorkPlanAlert('danger', res.error || 'No se pudo cargar el plan de trabajo.');
                $('#patient-work-plan-pending').html('<div class="text-center text-muted py-4">No se pudo cargar el plan.</div>');
                $('#patient-work-plan-completed-list').html('<div class="text-center text-muted py-4">No se pudo cargar el plan.</div>');
                return;
            }
            CURRENT_PATIENT_WORK_PLAN_ROWS = Array.isArray(res.tasks) ? res.tasks : [];
            renderPatientWorkPlan(CURRENT_PATIENT_WORK_PLAN_ROWS);
        },
        error: function () {
            showPatientWorkPlanAlert('danger', 'Error de conexion al cargar el plan de trabajo.');
            $('#patient-work-plan-pending').html('<div class="text-center text-muted py-4">No se pudo cargar el plan.</div>');
            $('#patient-work-plan-completed-list').html('<div class="text-center text-muted py-4">No se pudo cargar el plan.</div>');
        }
    });
}

function renderPatientWorkPlan(tasks) {
    const rows = Array.isArray(tasks) ? tasks : [];
    const statusEnabled = workPlanTaskStatusEnabled();
    syncPatientWorkPlanStatusUi();
    if (!statusEnabled) {
        $('#patient-work-plan-pending').html(renderPatientWorkPlanList(rows, 'all'));
        $('#patient-work-plan-completed-list').html('');
        $('#patient-work-plan-count').text(rows.length ? `${rows.length} tarea${rows.length === 1 ? '' : 's'}` : '');
        return;
    }
    const pending = rows.filter(task => task.status !== 'completed');
    const completed = rows.filter(task => task.status === 'completed');
    $('#patient-work-plan-pending').html(renderPatientWorkPlanList(pending, 'pending'));
    $('#patient-work-plan-completed-list').html(renderPatientWorkPlanList(completed, 'completed'));
    const pendingText = `${pending.length} pendiente${pending.length === 1 ? '' : 's'}`;
    const completedText = `${completed.length} completada${completed.length === 1 ? '' : 's'}`;
    $('#patient-work-plan-count').text(rows.length ? `${pendingText} · ${completedText}` : '');
}

function renderPatientWorkPlanList(tasks, listType) {
    if (!tasks.length) {
        if (listType === 'all') {
            return '<div class="text-center text-muted py-4">No hay tareas en el plan de trabajo.</div>';
        }
        return `<div class="text-center text-muted py-4">${listType === 'pending' ? 'No hay tareas pendientes.' : 'No hay tareas completadas.'}</div>`;
    }
    return tasks.map(task => renderPatientWorkPlanTask(task)).join('');
}

function renderPatientWorkPlanTask(task) {
    const completed = task.status === 'completed';
    const statusEnabled = workPlanTaskStatusEnabled();
    const priority = workPlanPriorityLabel(task.priority);
    const toggleTitle = completed ? 'Marcar como pendiente' : 'Marcar como completada';
    const toggleIcon = completed ? 'bi-arrow-counterclockwise' : 'bi-check2';
    const toggleClass = completed ? 'btn-outline-secondary' : 'btn-outline-success';
    const completedText = statusEnabled && completed && task.completed_at
        ? `<div class="small text-muted mt-2">Completada el ${formatDateTimeLabel(task.completed_at)}</div>`
        : '';
    const statusBadge = statusEnabled
        ? (completed ? '<span class="badge text-bg-success">Completada</span>' : '<span class="badge text-bg-warning">Pendiente</span>')
        : '';
    const toggleButton = statusEnabled
        ? `<button class="btn ${toggleClass} btn-sm btn-toggle-work-plan-task" type="button" data-task-id="${task.id}" data-next-status="${completed ? 'pending' : 'completed'}" title="${toggleTitle}">
                        <i class="bi ${toggleIcon}"></i>
                    </button>`
        : '';
    return `
        <div class="patient-work-plan-task ${completed ? 'is-completed' : ''}" data-task-id="${task.id}">
            <div class="d-flex justify-content-between align-items-start">
                <div class="pe-2">
                    <div class="d-flex flex-wrap align-items-center patient-work-plan-badges">
                        <span class="badge ${priority.className}">${priority.label}</span>
                        ${statusBadge}
                        ${task.visible_to_patient == 1 ? '<span class="badge text-bg-info">Visible portal</span>' : ''}
                    </div>
                    <h6 class="mb-1 mt-2">${escapeHtml(task.title || '')}</h6>
                    ${task.description ? `<div class="text-muted small">${escapeHtml(task.description)}</div>` : ''}
                    ${completedText}
                </div>
                <div class="patient-work-plan-actions">
                    ${toggleButton}
                    <button class="btn btn-outline-secondary btn-sm btn-edit-work-plan-task" type="button" data-task-id="${task.id}" title="Editar tarea">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-outline-danger btn-sm btn-delete-work-plan-task" type="button" data-task-id="${task.id}" title="Eliminar tarea">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    `;
}

function syncPatientWorkPlanStatusUi() {
    const enabled = workPlanTaskStatusEnabled();
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const helpText = enabled
        ? `Desde aqu\u00ed puedes personalizar tareas, actividades, temas a tratar o pautas para este ${patientSingular}, y marcarlas como completadas o pendientes en las sucesivas citas.`
        : `Desde aqu\u00ed puedes personalizar tareas, rutinas, actividades o pautas para este ${patientSingular}.`;
    $('#patient-work-plan-help-text').text(helpText);
    $('#patient-work-plan-pending-column')
        .toggleClass('col-lg-6', enabled)
        .toggleClass('col-lg-12', !enabled);
    $('#patient-work-plan-pending-column h6').text(enabled ? 'Pendientes' : 'Tareas del plan');
    $('#patient-work-plan-completed-column').toggleClass('d-none', !enabled);
    $('#patient-work-plan-status-field').toggleClass('d-none', !enabled);
    if (!enabled) {
        $('#patient-work-plan-completed').prop('checked', false);
    }
}

function workPlanPriorityLabel(priority) {
    const value = parseInt(priority || 2, 10);
    if (value === 1) return { label: 'Alta', className: 'text-bg-danger' };
    if (value === 3) return { label: 'Baja', className: 'text-bg-light' };
    return { label: 'Normal', className: 'text-bg-primary' };
}

function showPatientWorkPlanForm(task = null) {
    const patientId = parseInt($('#patient-editor-id').val() || '0', 10);
    openWorkPlanTaskModal({
        source: 'patient',
        patientId,
        appointmentId: 0,
        task
    });
}

function showAppointmentSessionWorkPlanForm() {
    const patientId = parseInt(CURRENT_APPOINTMENT_SESSION.patient_id || (CURRENT_APPOINTMENT_PAYMENT_DETAIL && CURRENT_APPOINTMENT_PAYMENT_DETAIL.patient_id) || 0, 10);
    const appointmentId = parseInt(CURRENT_APPOINTMENT_SESSION.appointment_id || (CURRENT_APPOINTMENT_PAYMENT_DETAIL && CURRENT_APPOINTMENT_PAYMENT_DETAIL.id) || 0, 10);
    openWorkPlanTaskModal({
        source: 'appointment-session',
        patientId,
        appointmentId,
        task: null
    });
}

function openWorkPlanTaskModal(options = {}) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const task = options.task || null;
    const source = options.source || 'patient';
    const patientId = parseInt(options.patientId || 0, 10);
    const appointmentId = parseInt(options.appointmentId || 0, 10);
    if (!patientId) {
        if (source === 'appointment-session') {
            showAppointmentSessionAlert('danger', `No se pudo identificar el ${patientSingular} de la cita.`);
        } else {
            showPatientWorkPlanAlert('danger', `Guarda primero el ${patientSingular}.`);
        }
        return;
    }
    CURRENT_WORK_PLAN_FORM_CONTEXT = { source, patientId, appointmentId: source === 'appointment-session' ? appointmentId : 0 };
    $('#patient-work-plan-modal-title').text(task ? 'Editar tarea' : (source === 'appointment-session' ? 'Crear o importar tareas de sesión' : 'Crear o importar tareas'));
    $('#patient-work-plan-id').val(task ? task.id : 0);
    $('#patient-work-plan-patient-id').val(patientId);
    $('#patient-work-plan-template').val('manual');
    $('#patient-work-plan-template-block').toggleClass('d-none', Boolean(task));
    $('#patient-work-plan-title').val(task ? task.title || '' : '');
    $('#patient-work-plan-description').val(task ? task.description || '' : '');
    $('#patient-work-plan-priority').val(task ? String(task.priority || 2) : '2');
    $('#patient-work-plan-completed').prop('checked', workPlanTaskStatusEnabled() && Boolean(task && task.status === 'completed'));
    $('#patient-work-plan-visible').prop('checked', task ? task.visible_to_patient == 1 : PAYMENT_SETTINGS.patient_tasks_visible_default == 1);
    syncPatientWorkPlanStatusUi();
    togglePatientWorkPlanTaskMode();
    if (patientWorkPlanTaskModal) {
        patientWorkPlanTaskModal.show();
        if (task) {
            setTimeout(() => $('#patient-work-plan-title').trigger('focus'), 180);
        } else {
            prepareWorkPlanImportOptions();
        }
    }
}

function setWorkPlanImportLoading(loading) {
    WORK_PLAN_IMPORT_LOADING = Boolean(loading);
    const $select = $('#patient-work-plan-template');
    $('#patient-work-plan-loading').toggleClass('d-none', !WORK_PLAN_IMPORT_LOADING);
    $select.prop('disabled', WORK_PLAN_IMPORT_LOADING);
    $('#patient-work-plan-manual-block').find('input, select, textarea').prop('disabled', WORK_PLAN_IMPORT_LOADING);
    if (WORK_PLAN_IMPORT_LOADING) {
        $select.html('<option value="manual">Cargando plantillas...</option>').val('manual');
        $('#btn-import-work-plan-template, #btn-save-patient-work-plan').prop('disabled', true);
        return;
    }
    togglePatientWorkPlanTaskMode();
}

function prepareWorkPlanImportOptions() {
    setWorkPlanImportLoading(true);
    const waitFor = function (promise) {
        const deferred = $.Deferred();
        promise.always(function () {
            deferred.resolve();
        });
        return deferred.promise();
    };
    const knowledgePromise = knowledgeImportEnabled()
        ? loadWorkPlanKnowledgeImportOptions()
        : $.Deferred().resolve({ success: true }).promise();
    $.when(waitFor(loadWorkPlanTaskTemplates()), waitFor(knowledgePromise))
        .done(function () {
            populateWorkPlanTemplateSelect();
            setWorkPlanImportLoading(false);
        });
}

function resetPatientWorkPlanFormFields() {
    if ($('#patient-work-plan-form').length) {
        $('#patient-work-plan-form')[0].reset();
    }
    $('#patient-work-plan-id').val(0);
    $('#patient-work-plan-template').val('manual');
    $('#patient-work-plan-loading').addClass('d-none');
    WORK_PLAN_IMPORT_LOADING = false;
    $('#patient-work-plan-template-block').removeClass('d-none');
    $('#patient-work-plan-priority').val('2');
    $('#patient-work-plan-completed').prop('checked', false);
    $('#patient-work-plan-visible').prop('checked', false);
    CURRENT_WORK_PLAN_FORM_CONTEXT = { source: 'patient', patientId: 0, appointmentId: 0 };
    syncPatientWorkPlanStatusUi();
    togglePatientWorkPlanTaskMode();
}

function getSelectedWorkPlanTemplateId() {
    const selection = getSelectedWorkPlanImportSelection();
    return selection.type === 'template' ? selection.templateId : 0;
}

function getSelectedWorkPlanImportSelection() {
    const value = $('#patient-work-plan-template').val();
    if (!value || value === 'manual') {
        return { type: 'manual' };
    }
    if (String(value).startsWith('template:')) {
        return { type: 'template', templateId: parseInt(String(value).replace('template:', ''), 10) || 0 };
    }
    if (String(value).startsWith('knowledge:')) {
        const parts = String(value).split(':');
        return {
            type: 'knowledge',
            problemId: parseInt(parts[1] || '0', 10) || 0,
            techniqueId: parseInt(parts[2] || '0', 10) || 0
        };
    }
    return { type: 'template', templateId: parseInt(value, 10) || 0 };
}

function togglePatientWorkPlanTaskMode() {
    if (WORK_PLAN_IMPORT_LOADING) {
        $('#patient-work-plan-manual-block').removeClass('d-none').find('input, select, textarea').prop('disabled', true);
        $('#btn-save-patient-work-plan').removeClass('d-none').prop('disabled', true);
        $('#btn-import-work-plan-template').addClass('d-none').prop('disabled', true);
        $('#patient-work-plan-template-block')
            .addClass('mb-4')
            .removeClass('mb-0');
        return;
    }
    const isEditing = parseInt($('#patient-work-plan-id').val() || '0', 10) > 0;
    const selection = getSelectedWorkPlanImportSelection();
    const isImportable = selection.type === 'template'
        ? selection.templateId > 0
        : (selection.type === 'knowledge' && selection.problemId > 0 && selection.techniqueId > 0);
    const isManual = isEditing || selection.type === 'manual' || !isImportable;
    $('#patient-work-plan-manual-block')
        .toggleClass('d-none', !isManual)
        .find('input, select, textarea')
        .prop('disabled', false);
    $('#btn-save-patient-work-plan').toggleClass('d-none', !isManual).prop('disabled', !isManual);
    $('#btn-import-work-plan-template').toggleClass('d-none', isManual).prop('disabled', isManual || !isImportable);
    $('#patient-work-plan-template-block')
        .toggleClass('mb-4', isManual)
        .toggleClass('mb-0', !isManual);
}

function hidePatientWorkPlanForm() {
    if (patientWorkPlanTaskModal && $('#patientWorkPlanTaskModal').hasClass('show')) {
        patientWorkPlanTaskModal.hide();
    }
    resetPatientWorkPlanFormFields();
}

function savePatientWorkPlanTask(form) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const context = { ...CURRENT_WORK_PLAN_FORM_CONTEXT };
    const patientId = parseInt(context.patientId || $('#patient-editor-id').val() || '0', 10);
    if (!patientId) {
        if (context.source === 'appointment-session') {
            showAppointmentSessionAlert('danger', `No se pudo identificar el ${patientSingular} de la cita.`);
        } else {
            showPatientWorkPlanAlert('danger', `Guarda primero el ${patientSingular}.`);
        }
        return;
    }
    const $button = $('#btn-save-patient-work-plan');
    const original = $button.html();
    const payload = {
        task_id: $('#patient-work-plan-id').val() || 0,
        patient_id: patientId,
        title: $('#patient-work-plan-title').val() || '',
        description: $('#patient-work-plan-description').val() || '',
        priority: $('#patient-work-plan-priority').val() || 2,
        status: workPlanTaskStatusEnabled() && $('#patient-work-plan-completed').is(':checked') ? 'completed' : 'pending',
        visible_to_patient: $('#patient-work-plan-visible').is(':checked') ? 1 : 0
    };
    if (context.source === 'appointment-session' && context.appointmentId) {
        payload.appointment_id = context.appointmentId;
    }
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Guardando');
    $.ajax({
        url: 'api/admin.php?action=save_patient_work_plan_task',
        method: 'POST',
        dataType: 'json',
        data: payload,
        success: function (res) {
            if (!res.success) {
                if (context.source === 'appointment-session') {
                    showAppointmentSessionAlert('danger', res.error || 'No se pudo guardar la tarea.');
                } else {
                    showPatientWorkPlanAlert('danger', res.error || 'No se pudo guardar la tarea.');
                }
                return;
            }
            if (context.source === 'appointment-session') {
                showAppointmentSessionAlert('success', res.message || 'Tarea guardada.');
            } else {
                showPatientWorkPlanAlert('success', res.message || 'Plan de trabajo guardado correctamente.', true);
            }
            hidePatientWorkPlanForm();
            if (context.source === 'appointment-session') {
                loadAppointmentSession();
            } else {
                loadPatientWorkPlan(patientId);
            }
        },
        error: function () {
            if (context.source === 'appointment-session') {
                showAppointmentSessionAlert('danger', 'Error de conexion al guardar la tarea.');
            } else {
                showPatientWorkPlanAlert('danger', 'Error de conexion al guardar la tarea.');
            }
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function setPatientWorkPlanTaskStatus(button) {
    if (!workPlanTaskStatusEnabled()) {
        return;
    }
    const $button = $(button);
    const taskId = parseInt($button.data('task-id') || 0, 10);
    const status = $button.data('next-status') === 'completed' ? 'completed' : 'pending';
    if (!taskId) return;
    const original = $button.html();
    const $card = $button.closest('.patient-work-plan-task');
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({
        url: 'api/admin.php?action=set_patient_work_plan_task_status',
        method: 'POST',
        dataType: 'json',
        data: { task_id: taskId, status },
        success: function (res) {
            if (!res.success) {
                showPatientWorkPlanAlert('danger', res.error || 'No se pudo actualizar la tarea.');
                return;
            }
            showPatientWorkPlanAlert('success', res.message || 'Tarea actualizada.', true);
            $card.addClass('is-moving');
            setTimeout(() => {
                CURRENT_PATIENT_WORK_PLAN_ROWS = CURRENT_PATIENT_WORK_PLAN_ROWS.map(task => {
                    if (parseInt(task.id || 0, 10) !== taskId) return task;
                    return {
                        ...task,
                        status,
                        completed_at: status === 'completed'
                            ? (res.completed_at || new Date().toISOString().slice(0, 19).replace('T', ' '))
                            : null
                    };
                });
                renderPatientWorkPlan(CURRENT_PATIENT_WORK_PLAN_ROWS);
            }, 180);
        },
        error: function () {
            showPatientWorkPlanAlert('danger', 'Error de conexion al actualizar la tarea.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function deletePatientWorkPlanTask(button) {
    const $button = $(button);
    const taskId = parseInt($button.data('task-id') || 0, 10);
    if (!taskId || !confirm('¿Eliminar esta tarea del plan de trabajo?')) {
        return;
    }
    const original = $button.html();
    const $card = $button.closest('.patient-work-plan-task');
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({
        url: 'api/admin.php?action=delete_patient_work_plan_task',
        method: 'POST',
        dataType: 'json',
        data: { task_id: taskId },
        success: function (res) {
            if (!res.success) {
                showPatientWorkPlanAlert('danger', res.error || 'No se pudo eliminar la tarea.');
                return;
            }
            showPatientWorkPlanAlert('success', res.message || 'Tarea eliminada correctamente.', true);
            $card.addClass('is-removing');
            setTimeout(() => {
                CURRENT_PATIENT_WORK_PLAN_ROWS = CURRENT_PATIENT_WORK_PLAN_ROWS.filter(task => parseInt(task.id || 0, 10) !== taskId);
                renderPatientWorkPlan(CURRENT_PATIENT_WORK_PLAN_ROWS);
            }, 180);
        },
        error: function () {
            showPatientWorkPlanAlert('danger', 'Error de conexion al eliminar la tarea.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function loadWorkPlanTaskTemplates(force = false) {
    if (!appFeatureEnabled('taskTemplates.enabled', false)) {
        WORK_PLAN_TASK_TEMPLATES = [];
        WORK_PLAN_TASK_TEMPLATES_LOADED = true;
        populateWorkPlanTemplateSelect();
        $('#task-templates-list').html('<div class="text-center text-muted py-4">Las plantillas no estan disponibles en este plan.</div>');
        return $.Deferred().resolve({ success: true }).promise();
    }
    if (WORK_PLAN_TASK_TEMPLATES_LOADED && !force) {
        populateWorkPlanTemplateSelect();
        renderWorkPlanTaskTemplates();
        return $.Deferred().resolve({ success: true }).promise();
    }
    $('#task-templates-list').html('<div class="text-center text-muted py-4">Cargando plantillas...</div>');
    return $.ajax({
        url: 'api/admin.php?action=work_plan_task_templates',
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                showTaskTemplatesAlert('danger', res.error || 'No se pudieron cargar las plantillas.');
                $('#task-templates-list').html('<div class="text-center text-muted py-4">No se pudieron cargar las plantillas.</div>');
                return;
            }
            WORK_PLAN_TASK_TEMPLATES = Array.isArray(res.templates) ? res.templates : [];
            WORK_PLAN_TASK_TEMPLATES_LOADED = true;
            populateWorkPlanTemplateSelect();
            renderWorkPlanTaskTemplates();
        },
        error: function () {
            showTaskTemplatesAlert('danger', 'Error de conexion al cargar las plantillas.');
            $('#task-templates-list').html('<div class="text-center text-muted py-4">No se pudieron cargar las plantillas.</div>');
        }
    });
}

function loadWorkPlanKnowledgeImportOptions(force = false) {
    if (!knowledgeImportEnabled()) {
        WORK_PLAN_KNOWLEDGE_IMPORT_OPTIONS = [];
        WORK_PLAN_KNOWLEDGE_IMPORT_OPTIONS_LOADED = true;
        populateWorkPlanTemplateSelect();
        return $.Deferred().resolve({ success: true }).promise();
    }
    if (WORK_PLAN_KNOWLEDGE_IMPORT_OPTIONS_LOADED && !force) {
        populateWorkPlanTemplateSelect();
        return $.Deferred().resolve({ success: true }).promise();
    }
    return $.ajax({
        url: 'api/admin.php?action=knowledge_import_options',
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                WORK_PLAN_KNOWLEDGE_IMPORT_OPTIONS = [];
                return;
            }
            WORK_PLAN_KNOWLEDGE_IMPORT_OPTIONS = Array.isArray(res.options) ? res.options : [];
            WORK_PLAN_KNOWLEDGE_IMPORT_OPTIONS_LOADED = true;
            populateWorkPlanTemplateSelect();
        },
        error: function () {
            WORK_PLAN_KNOWLEDGE_IMPORT_OPTIONS = [];
        }
    });
}

function populateWorkPlanTemplateSelect() {
    const $select = $('#patient-work-plan-template');
    if (!$select.length) return;
    const previous = $select.val() || 'manual';
    $select.empty().append('<option value="manual">Crear manualmente</option>');
    const activeTemplates = WORK_PLAN_TASK_TEMPLATES.filter(template => parseInt(template.is_active || 1, 10) === 1);
    const grouped = {};
    activeTemplates.forEach(template => {
        const category = (template.category || 'Sin categoria').trim() || 'Sin categoria';
        if (!grouped[category]) grouped[category] = [];
        grouped[category].push(template);
    });
    Object.keys(grouped).sort((a, b) => a.localeCompare(b)).forEach(category => {
        const $group = $('<optgroup>').attr('label', category);
        grouped[category].forEach(template => {
            const count = parseInt(template.item_count || (Array.isArray(template.items) ? template.items.length : 0), 10);
            $group.append(`<option value="template:${template.id}">${escapeHtml(template.title || '')}${count ? ` (${count})` : ''}</option>`);
        });
        $select.append($group);
    });
    if (knowledgeImportEnabled()) {
        const knowledgeGrouped = {};
        const knowledgeBaseLabel = 'Base de conocimiento';
        const diagnosisFallback = capitalizeFirst(sectorText('clinicalTerms.diagnosis', 'Diagnostico'));
        const taskSingular = sectorLabel('task', 'singular', 'tarea');
        const taskPlural = sectorLabel('task', 'plural', 'tareas');
        WORK_PLAN_KNOWLEDGE_IMPORT_OPTIONS.forEach(option => {
            const sectorName = option.sector_label || knowledgeSectorName(option.sector_key || APP_CURRENT_SECTOR_KEY);
            const key = `${sectorName ? `${sectorName} · ` : ''}${option.area_name || knowledgeBaseLabel} · ${option.problem_name || diagnosisFallback}`;
            if (!knowledgeGrouped[key]) knowledgeGrouped[key] = [];
            knowledgeGrouped[key].push(option);
        });
        Object.keys(knowledgeGrouped).sort((a, b) => a.localeCompare(b)).forEach(groupName => {
            const $group = $('<optgroup>').attr('label', `${knowledgeBaseLabel}: ${groupName}`);
            knowledgeGrouped[groupName].forEach(option => {
                const count = parseInt(option.task_count || 0, 10);
                const value = `knowledge:${parseInt(option.problem_id || 0, 10)}:${parseInt(option.technique_id || 0, 10)}`;
                $group.append(`<option value="${value}">${escapeHtml(option.technique_name || '')}${count ? ` (${count} ${escapeHtml(count === 1 ? taskSingular : taskPlural)})` : ''}</option>`);
            });
            $select.append($group);
        });
    }
    const hasPrevious = previous && $select.find('option').filter(function () {
        return $(this).val() === previous;
    }).length > 0;
    if (hasPrevious) {
        $select.val(previous);
    } else {
        $select.val('manual');
    }
    togglePatientWorkPlanTaskMode();
}

function renderWorkPlanTaskTemplates() {
    const $list = $('#task-templates-list');
    if (!$list.length) return;
    if (!WORK_PLAN_TASK_TEMPLATES.length) {
        $list.html(`<div class="text-center text-muted py-4">Todavia no hay plantillas. Crea la primera para reutilizarla en los ${sectorLabel('patient', 'plural', 'pacientes')}.</div>`);
        return;
    }
    const html = WORK_PLAN_TASK_TEMPLATES.map(template => {
        const category = template.category || 'Sin categoria';
        const owner = parseInt(template.is_global || 0, 10) === 1
            ? 'Gabinete'
            : (template.professional_name || 'Profesional');
        const items = Array.isArray(template.items) ? template.items : [];
        const itemHtml = items.length
            ? items.map(item => {
                const priority = workPlanPriorityLabel(item.priority);
                return `
                    <div class="task-template-subitem">
                        <div>
                            <span class="badge ${priority.className}">${priority.label}</span>
                            <strong>${escapeHtml(item.title || '')}</strong>
                            ${item.description ? `<div class="small text-muted mt-1">${escapeHtml(item.description)}</div>` : ''}
                        </div>
                        <div class="task-template-actions">
                            <button type="button" class="btn btn-outline-secondary btn-sm btn-edit-task-template-item" data-template-id="${template.id}" data-item-id="${item.id}" title="Editar tarea">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm btn-delete-task-template-item" data-item-id="${item.id}" title="Eliminar tarea">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
            }).join('')
            : '<div class="small text-muted mt-2">Esta plantilla todavia no tiene tareas.</div>';
        return `
            <div class="task-template-item">
                <div class="task-template-main">
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                        <span class="badge text-bg-light">${escapeHtml(category)}</span>
                        <span class="small text-muted">${escapeHtml(owner)}</span>
                        <span class="small text-muted">${items.length} tarea${items.length === 1 ? '' : 's'}</span>
                    </div>
                    <strong>${escapeHtml(template.title || '')}</strong>
                    ${template.description ? `<div class="small text-muted mt-1">${escapeHtml(template.description)}</div>` : ''}
                    <div class="task-template-subitems">${itemHtml}</div>
                </div>
                <div class="task-template-actions">
                    <button type="button" class="btn btn-outline-primary btn-sm btn-add-task-template-item" data-template-id="${template.id}" title="Nueva tarea en esta plantilla">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm btn-edit-task-template" data-template-id="${template.id}" title="Editar plantilla">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm btn-delete-task-template" data-template-id="${template.id}" title="Eliminar plantilla">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        `;
    }).join('');
    $list.html(html);
}

function openTaskTemplatesSettings() {
    if (!settingsModal) return;
    if (patientEditorModal && $('#patientEditorModal').hasClass('show')) {
        patientEditorModal.hide();
    }
    settingsModal.show();
    const tabEl = document.getElementById('task-templates-settings-tab');
    if (tabEl) {
        bootstrap.Tab.getOrCreateInstance(tabEl).show();
    }
    loadWorkPlanTaskTemplates(true);
}

function openTaskTemplateModal(template = null) {
    resetWorkPlanTaskTemplateForm();
    if (template) {
        fillWorkPlanTaskTemplateForm(template);
    }
    $('#task-template-modal-title').text(template ? 'Editar plantilla' : 'Nueva plantilla');
    if (taskTemplateModal) {
        taskTemplateModal.show();
    }
}

function resetWorkPlanTaskTemplateForm() {
    const form = document.getElementById('task-template-form');
    if (form) form.reset();
    $('#task-template-id').val(0);
    $('#task-template-global').prop('checked', true);
}

function fillWorkPlanTaskTemplateForm(template) {
    $('#task-template-id').val(template.id || 0);
    $('#task-template-category').val(template.category || '');
    $('#task-template-title').val(template.title || '');
    $('#task-template-description').val(template.description || '');
    $('#task-template-global').prop('checked', parseInt(template.is_global || 0, 10) === 1);
    $('#task-template-title').trigger('focus');
}

function openTaskTemplateItemModal(task = null, templateId = 0) {
    resetWorkPlanTaskTemplateItemForm();
    populateTaskTemplateItemTemplateSelect(templateId);
    if (task) {
        fillWorkPlanTaskTemplateItemForm(task, templateId);
    }
    $('#task-template-item-modal-title').text(task ? 'Editar tarea de plantilla' : 'Nueva tarea de plantilla');
    if (taskTemplateItemModal) {
        taskTemplateItemModal.show();
    }
}

function populateTaskTemplateItemTemplateSelect(selectedTemplateId = 0) {
    const $select = $('#task-template-item-template-id');
    if (!$select.length) return;
    const options = WORK_PLAN_TASK_TEMPLATES
        .map(template => `<option value="${template.id}">${escapeHtml(template.title || '')}</option>`)
        .join('');
    $select.html(`<option value="">Selecciona plantilla...</option>${options}`);
    if (selectedTemplateId && $select.find(`option[value="${selectedTemplateId}"]`).length) {
        $select.val(String(selectedTemplateId));
    }
}

function resetWorkPlanTaskTemplateItemForm() {
    const form = document.getElementById('task-template-item-form');
    if (form) form.reset();
    $('#task-template-item-id').val(0);
    $('#task-template-item-template-id').val('');
    $('#task-template-item-priority').val('2');
}

function fillWorkPlanTaskTemplateItemForm(task, templateId = 0) {
    $('#task-template-item-id').val(task.id || 0);
    $('#task-template-item-template-id').val(String(templateId || task.template_id || ''));
    $('#task-template-item-title').val(task.title || '');
    $('#task-template-item-description').val(task.description || '');
    $('#task-template-item-priority').val(String(task.priority || 2));
    $('#task-template-item-title').trigger('focus');
}

function saveWorkPlanTaskTemplate() {
    const $button = $('#btn-save-task-template');
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Guardando');
    $.ajax({
        url: 'api/admin.php?action=save_work_plan_task_template',
        method: 'POST',
        dataType: 'json',
        data: {
            template_id: $('#task-template-id').val() || 0,
            category: $('#task-template-category').val() || '',
            title: $('#task-template-title').val() || '',
            description: $('#task-template-description').val() || '',
            is_global: $('#task-template-global').is(':checked') ? '1' : '0'
        },
        success: function (res) {
            if (!res.success) {
                showTaskTemplatesAlert('danger', res.error || 'No se pudo guardar la plantilla.');
                return;
            }
            showTaskTemplatesAlert('success', res.message || 'Plantilla guardada correctamente.', true);
            if (taskTemplateModal) {
                taskTemplateModal.hide();
            }
            resetWorkPlanTaskTemplateForm();
            WORK_PLAN_TASK_TEMPLATES_LOADED = false;
            loadWorkPlanTaskTemplates(true);
        },
        error: function () {
            showTaskTemplatesAlert('danger', 'Error de conexion al guardar la plantilla.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function saveWorkPlanTaskTemplateItem() {
    const templateId = parseInt($('#task-template-item-template-id').val() || '0', 10);
    if (!templateId) {
        showTaskTemplatesAlert('danger', 'Guarda primero la plantilla.');
        return;
    }
    const $button = $('#btn-save-task-template-item');
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Guardando');
    $.ajax({
        url: 'api/admin.php?action=save_work_plan_task_template_item',
        method: 'POST',
        dataType: 'json',
        data: {
            item_id: $('#task-template-item-id').val() || 0,
            template_id: templateId,
            title: $('#task-template-item-title').val() || '',
            description: $('#task-template-item-description').val() || '',
            priority: $('#task-template-item-priority').val() || 2
        },
        success: function (res) {
            if (!res.success) {
                showTaskTemplatesAlert('danger', res.error || 'No se pudo guardar la tarea de plantilla.');
                return;
            }
            showTaskTemplatesAlert('success', res.message || 'Tarea guardada correctamente.', true);
            if (taskTemplateItemModal) {
                taskTemplateItemModal.hide();
            }
            resetWorkPlanTaskTemplateItemForm();
            WORK_PLAN_TASK_TEMPLATES_LOADED = false;
            loadWorkPlanTaskTemplates(true);
        },
        error: function () {
            showTaskTemplatesAlert('danger', 'Error de conexion al guardar la tarea de plantilla.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function deleteWorkPlanTaskTemplate(button) {
    const $button = $(button);
    const templateId = parseInt($button.data('template-id') || 0, 10);
    if (!templateId || !confirm('Eliminar esta plantilla de tareas?')) {
        return;
    }
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({
        url: 'api/admin.php?action=delete_work_plan_task_template',
        method: 'POST',
        dataType: 'json',
        data: { template_id: templateId },
        success: function (res) {
            if (!res.success) {
                showTaskTemplatesAlert('danger', res.error || 'No se pudo eliminar la plantilla.');
                return;
            }
            showTaskTemplatesAlert('success', res.message || 'Plantilla eliminada correctamente.', true);
            resetWorkPlanTaskTemplateForm();
            WORK_PLAN_TASK_TEMPLATES_LOADED = false;
            loadWorkPlanTaskTemplates(true);
        },
        error: function () {
            showTaskTemplatesAlert('danger', 'Error de conexion al eliminar la plantilla.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function deleteWorkPlanTaskTemplateItem(button) {
    const $button = $(button);
    const itemId = parseInt($button.data('item-id') || 0, 10);
    if (!itemId || !confirm('Eliminar esta tarea de la plantilla?')) {
        return;
    }
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({
        url: 'api/admin.php?action=delete_work_plan_task_template_item',
        method: 'POST',
        dataType: 'json',
        data: { item_id: itemId },
        success: function (res) {
            if (!res.success) {
                showTaskTemplatesAlert('danger', res.error || 'No se pudo eliminar la tarea de plantilla.');
                return;
            }
            showTaskTemplatesAlert('success', res.message || 'Tarea eliminada correctamente.', true);
            resetWorkPlanTaskTemplateItemForm();
            WORK_PLAN_TASK_TEMPLATES_LOADED = false;
            loadWorkPlanTaskTemplates(true);
        },
        error: function () {
            showTaskTemplatesAlert('danger', 'Error de conexion al eliminar la tarea de plantilla.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function importWorkPlanTemplateToPatient(button) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const context = { ...CURRENT_WORK_PLAN_FORM_CONTEXT };
    const patientId = parseInt(context.patientId || $('#patient-editor-id').val() || '0', 10);
    const selection = getSelectedWorkPlanImportSelection();
    if (!patientId) {
        if (context.source === 'appointment-session') {
            showAppointmentSessionAlert('danger', `No se pudo identificar el ${patientSingular} de la cita.`);
        } else {
            showPatientWorkPlanAlert('danger', `Guarda primero el ${patientSingular}.`);
        }
        return;
    }
    const isTemplate = selection.type === 'template' && selection.templateId > 0;
    const isKnowledge = selection.type === 'knowledge' && selection.problemId > 0 && selection.techniqueId > 0;
    if (selection.type === 'knowledge' && !knowledgeImportEnabled()) {
        if (context.source === 'appointment-session') {
            showAppointmentSessionAlert('danger', 'La importacion de tareas recomendadas no esta disponible en este plan.');
        } else {
            showPatientWorkPlanAlert('danger', 'La importacion de tareas recomendadas no esta disponible en este plan.');
        }
        return;
    }
    if (!isTemplate && !isKnowledge) {
        if (context.source === 'appointment-session') {
            showAppointmentSessionAlert('danger', `Selecciona una plantilla o una ${sectorLabel('technique', 'singular', 'tecnica')} de la base de conocimiento.`);
        } else {
            showPatientWorkPlanAlert('danger', `Selecciona una plantilla o una ${sectorLabel('technique', 'singular', 'tecnica')} de la base de conocimiento.`);
        }
        return;
    }
    const $button = $(button);
    const original = $button.html();
    const payload = { patient_id: patientId };
    if (isTemplate) {
        payload.template_id = selection.templateId;
    } else {
        payload.problem_id = selection.problemId;
        payload.technique_id = selection.techniqueId;
    }
    if (context.source === 'appointment-session' && context.appointmentId) {
        payload.appointment_id = context.appointmentId;
    }
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Importando');
    $.ajax({
        url: isTemplate ? 'api/admin.php?action=import_work_plan_task_template' : 'api/admin.php?action=import_knowledge_technique_tasks',
        method: 'POST',
        dataType: 'json',
        data: payload,
        success: function (res) {
            if (!res.success) {
                if (context.source === 'appointment-session') {
                    showAppointmentSessionAlert('danger', res.error || 'No se pudieron importar las tareas.');
                } else {
                    showPatientWorkPlanAlert('danger', res.error || 'No se pudieron importar las tareas.');
                }
                return;
            }
            if (context.source === 'appointment-session') {
                showAppointmentSessionAlert('success', res.message || 'Tareas importadas correctamente.');
            } else {
                showPatientWorkPlanAlert('success', res.message || 'Tareas importadas correctamente.', true);
            }
            $('#patient-work-plan-template').val('manual');
            hidePatientWorkPlanForm();
            if (context.source === 'appointment-session') {
                loadAppointmentSession();
            } else {
                loadPatientWorkPlan(patientId);
            }
        },
        error: function () {
            if (context.source === 'appointment-session') {
                showAppointmentSessionAlert('danger', 'Error de conexion al importar las tareas.');
            } else {
                showPatientWorkPlanAlert('danger', 'Error de conexion al importar las tareas.');
            }
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
            togglePatientWorkPlanTaskMode();
        }
    });
}

function showTaskTemplatesAlert(type, message, autoHide = false) {
    const $alert = $('#task-templates-alert');
    if (!$alert.length) return;
    $alert.removeClass('d-none alert-success alert-danger alert-warning alert-info')
        .addClass(`alert-${type}`)
        .text(message || '');
    if (autoHide) {
        setTimeout(() => $alert.addClass('d-none').text(''), 3000);
    }
}

function resetPatientEvolution(patientId = 0) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    CURRENT_PATIENT_EVOLUTION_ID = parseInt(patientId || 0, 10);
    CURRENT_PATIENT_EVOLUTION_ROWS = [];
    CURRENT_PATIENT_EVOLUTION_APPOINTMENTS = [];
    $('#patient-evolution-alert').addClass('d-none').text('');
    $('#patient-evolution-count').text('');
    destroyPhysicalEvolutionCharts(PATIENT_EVOLUTION_CHARTS);
    hidePatientEvolutionForm();
    if (!patientId) {
        $('#patient-evolution-list').html(`<div class="text-center text-muted py-4">Guarda el ${patientSingular} para ver su evolución.</div>`);
        return;
    }
    $('#patient-evolution-list').html('<div class="text-center text-muted py-4">Cargando evolución...</div>');
}

function loadPatientEvolution(patientId) {
    patientId = parseInt(patientId || 0, 10);
    if (!patientId) {
        resetPatientEvolution(0);
        return;
    }
    CURRENT_PATIENT_EVOLUTION_ID = patientId;
    $('#patient-evolution-chart-grid').html('<div class="text-center text-muted py-4">Cargando graficos...</div>');
    $('#patient-evolution-alert').addClass('d-none').text('');
    $('#patient-evolution-list').html('<div class="text-center text-muted py-4">Cargando evolución...</div>');
    $('#patient-evolution-count').text('');
    $.ajax({
        url: 'api/admin.php?action=patient_evolution',
        dataType: 'json',
        data: { patient_id: patientId },
        success: function (res) {
            if (!res.success) {
                showPatientEvolutionAlert('danger', res.error || 'No se pudo cargar la evolución.');
                $('#patient-evolution-list').html('<div class="text-center text-muted py-4">No se pudo cargar la evolución.</div>');
                return;
            }
            CURRENT_PATIENT_EVOLUTION_ROWS = Array.isArray(res.notes) ? res.notes : [];
            CURRENT_PATIENT_EVOLUTION_APPOINTMENTS = Array.isArray(res.appointments) ? res.appointments : [];
            populatePatientEvolutionAppointmentSelect();
            renderPatientEvolution(CURRENT_PATIENT_EVOLUTION_ROWS);
            renderPatientEvolutionCharts();
        },
        error: function () {
            showPatientEvolutionAlert('danger', 'Error de conexión al cargar la evolución.');
            $('#patient-evolution-list').html('<div class="text-center text-muted py-4">No se pudo cargar la evolución.</div>');
        }
    });
}

function populatePatientEvolutionAppointmentSelect(selected = '') {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const $select = $('#patient-evolution-appointment');
    $select.html(`<option value="">Nota general del ${patientSingular}</option>`);
    CURRENT_PATIENT_EVOLUTION_APPOINTMENTS.forEach(app => {
        $select.append(`<option value="${app.id}">${escapeHtml(app.label || '')}</option>`);
    });
    if (selected && $select.find(`option[value="${selected}"]`).length) {
        $select.val(String(selected));
    }
}

function copyCurrentPhysicalMetricsToEvolution() {
    $('#patient-evolution-weight').val($('#patient-editor-weight').val() || '');
    $('#patient-evolution-height').val($('#patient-editor-height').val() || '');
    $('#patient-evolution-body-fat').val($('#patient-editor-body-fat').val() || '');
    $('#patient-evolution-waist').val($('#patient-editor-waist').val() || '');
    $('#patient-evolution-hip').val($('#patient-editor-hip').val() || '');
    $('#patient-evolution-chest').val($('#patient-editor-chest').val() || '');
    $('#patient-evolution-thigh').val($('#patient-editor-thigh').val() || '');
    $('#patient-evolution-biceps').val($('#patient-editor-biceps').val() || '');
    $('#patient-evolution-calf').val($('#patient-editor-calf').val() || '');
    $('#patient-evolution-skinfold-triceps').val($('#patient-editor-skinfold-triceps').val() || '');
    $('#patient-evolution-skinfold-subscapular').val($('#patient-editor-skinfold-subscapular').val() || '');
    $('#patient-evolution-skinfold-suprailiac').val($('#patient-editor-skinfold-suprailiac').val() || '');
    $('#patient-evolution-skinfold-abdominal').val($('#patient-editor-skinfold-abdominal').val() || '');
    $('#patient-evolution-skinfold-chest').val($('#patient-editor-skinfold-chest').val() || '');
    $('#patient-evolution-skinfold-thigh').val($('#patient-editor-skinfold-thigh').val() || '');
    updatePatientEvolutionBmiDisplay();
}

function patientEvolutionMetricFieldMap() {
    return [
        ['weight_kg', '#patient-evolution-weight'],
        ['height_cm', '#patient-evolution-height'],
        ['body_fat_percentage', '#patient-evolution-body-fat'],
        ['waist_cm', '#patient-evolution-waist'],
        ['hip_cm', '#patient-evolution-hip'],
        ['chest_cm', '#patient-evolution-chest'],
        ['thigh_cm', '#patient-evolution-thigh'],
        ['biceps_cm', '#patient-evolution-biceps'],
        ['calf_cm', '#patient-evolution-calf'],
        ['skinfold_triceps_mm', '#patient-evolution-skinfold-triceps'],
        ['skinfold_subscapular_mm', '#patient-evolution-skinfold-subscapular'],
        ['skinfold_suprailiac_mm', '#patient-evolution-skinfold-suprailiac'],
        ['skinfold_abdominal_mm', '#patient-evolution-skinfold-abdominal'],
        ['skinfold_chest_mm', '#patient-evolution-skinfold-chest'],
        ['skinfold_thigh_mm', '#patient-evolution-skinfold-thigh']
    ];
}

function evolutionRowHasPhysicalMetrics(row = {}) {
    return patientEvolutionMetricFieldMap().some(([key]) => row[key] !== null && row[key] !== undefined && String(row[key]).trim() !== '');
}

function copyLatestPhysicalMetricsToEvolution() {
    const latest = (Array.isArray(CURRENT_PATIENT_EVOLUTION_ROWS) ? CURRENT_PATIENT_EVOLUTION_ROWS : [])
        .filter(evolutionRowHasPhysicalMetrics)
        .slice()
        .sort((a, b) => {
            const dateCompare = String(b.note_date || '').localeCompare(String(a.note_date || ''));
            if (dateCompare !== 0) return dateCompare;
            return parseInt(b.id || 0, 10) - parseInt(a.id || 0, 10);
        })[0] || null;
    if (!latest) {
        copyCurrentPhysicalMetricsToEvolution();
        return;
    }
    patientEvolutionMetricFieldMap().forEach(([key, selector]) => {
        $(selector).val(latest[key] || '');
    });
    updatePatientEvolutionBmiDisplay();
}

function renderEvolutionMetrics(note) {
    const metrics = [];
    if (note.weight_kg) metrics.push(`Peso ${formatMetricValue(note.weight_kg)} kg`);
    if (note.height_cm) metrics.push(`Altura ${formatMetricValue(note.height_cm)} cm`);
    const bmi = calculateBmiFromValues(note.weight_kg, note.height_cm);
    if (bmi > 0) metrics.push(`IMC ${bmi.toFixed(1)}`);
    if (note.body_fat_percentage) metrics.push(`Grasa ${formatMetricValue(note.body_fat_percentage)}%`);
    if (note.waist_cm) metrics.push(`Cintura ${formatMetricValue(note.waist_cm)} cm`);
    if (note.hip_cm) metrics.push(`Cadera ${formatMetricValue(note.hip_cm)} cm`);
    if (note.chest_cm) metrics.push(`Pecho ${formatMetricValue(note.chest_cm)} cm`);
    if (note.thigh_cm) metrics.push(`Muslo ${formatMetricValue(note.thigh_cm)} cm`);
    if (note.biceps_cm) metrics.push(`Biceps ${formatMetricValue(note.biceps_cm)} cm`);
    if (note.calf_cm) metrics.push(`Gemelo ${formatMetricValue(note.calf_cm)} cm`);
    if (note.skinfold_triceps_mm) metrics.push(`Pliegue triceps ${formatMetricValue(note.skinfold_triceps_mm)} mm`);
    if (note.skinfold_subscapular_mm) metrics.push(`Pliegue subescapular ${formatMetricValue(note.skinfold_subscapular_mm)} mm`);
    if (note.skinfold_suprailiac_mm) metrics.push(`Pliegue suprailiaco ${formatMetricValue(note.skinfold_suprailiac_mm)} mm`);
    if (note.skinfold_abdominal_mm) metrics.push(`Pliegue abdominal ${formatMetricValue(note.skinfold_abdominal_mm)} mm`);
    if (note.skinfold_chest_mm) metrics.push(`Pliegue pectoral ${formatMetricValue(note.skinfold_chest_mm)} mm`);
    if (note.skinfold_thigh_mm) metrics.push(`Pliegue muslo ${formatMetricValue(note.skinfold_thigh_mm)} mm`);
    return metrics.length
        ? `<div class="patient-evolution-metrics small text-muted mt-2">${metrics.map(item => `<span>${escapeHtml(item)}</span>`).join('')}</div>`
        : '';
}

function formatMetricValue(value) {
    const number = Number(String(value || '').replace(',', '.'));
    if (Number.isNaN(number)) return value || '';
    return number.toFixed(1).replace(/\.0$/, '');
}

function renderPatientEvolution(notes) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const rows = Array.isArray(notes) ? notes : [];
    if (!rows.length) {
        $('#patient-evolution-list').html(`<div class="text-center text-muted py-4">Este ${patientSingular} todavía no tiene registros de evolución.</div>`);
        $('#patient-evolution-count').text('');
        return;
    }

    const html = rows.map(note => {
        const appointmentText = note.appointment_id
            ? `<span class="badge text-bg-light">Cita ${formatDisplayDate(note.appointment_date || note.note_date)} ${escapeHtml(note.appointment_time || '')}</span>`
            : '<span class="badge text-bg-light">Nota general</span>';
        const fileText = parseInt(note.file_count || 0, 10) > 0
            ? `<span class="badge text-bg-secondary">${parseInt(note.file_count || 0, 10)} archivo${parseInt(note.file_count || 0, 10) === 1 ? '' : 's'}</span>`
            : '';
        return `
            <div class="patient-evolution-item">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="patient-evolution-date">${formatDisplayDate(note.note_date || '')}</div>
                        <h6 class="mb-1">${escapeHtml(note.title || '')}</h6>
                        <div class="d-flex flex-wrap patient-evolution-badges">
                            ${appointmentText}
                            ${fileText}
                        </div>
                    </div>
                    <button class="btn btn-outline-secondary btn-sm btn-edit-patient-evolution" type="button" data-note-id="${note.id}" title="Editar evolución">
                        <i class="bi bi-pencil"></i>
                    </button>
                </div>
                ${note.description ? `<p class="mb-2 mt-2">${escapeHtml(note.description)}</p>` : ''}
                ${renderEvolutionMetrics(note)}
                ${note.observations ? `<div class="small text-muted"><strong>Observaciones:</strong> ${escapeHtml(note.observations)}</div>` : ''}
                ${note.next_steps ? `<div class="small text-muted"><strong>Pendientes:</strong> ${escapeHtml(note.next_steps)}</div>` : ''}
            </div>
        `;
    }).join('');

    $('#patient-evolution-list').html(html);
    $('#patient-evolution-count').text(`${rows.length} ${rows.length === 1 ? 'registro' : 'registros'}`);
}

function showPatientEvolutionForm(note = null) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const patientId = parseInt($('#patient-editor-id').val() || '0', 10);
    if (!patientId) {
        showPatientEvolutionAlert('danger', `Guarda primero el ${patientSingular}.`);
        return;
    }
    $('#patient-evolution-modal-title').text(note ? 'Editar registro de evolución' : 'Nuevo registro de evolución');
    $('#patient-evolution-id').val(note ? note.id : 0);
    $('#patient-evolution-patient-id').val(patientId);
    $('#patient-evolution-date').val(note ? note.note_date || formatDate(new Date()) : formatDate(new Date()));
    $('#patient-evolution-title').val(note ? note.title || '' : '');
    $('#patient-evolution-description').val(note ? note.description || '' : '');
    $('#patient-evolution-observations').val(note ? note.observations || '' : '');
    $('#patient-evolution-next-steps').val(note ? note.next_steps || '' : '');
    $('#patient-evolution-files').val('');
    $('#patient-evolution-weight').val(note ? note.weight_kg || '' : '');
    $('#patient-evolution-height').val(note ? note.height_cm || '' : '');
    $('#patient-evolution-body-fat').val(note ? note.body_fat_percentage || '' : '');
    $('#patient-evolution-waist').val(note ? note.waist_cm || '' : '');
    $('#patient-evolution-hip').val(note ? note.hip_cm || '' : '');
    $('#patient-evolution-chest').val(note ? note.chest_cm || '' : '');
    $('#patient-evolution-thigh').val(note ? note.thigh_cm || '' : '');
    $('#patient-evolution-biceps').val(note ? note.biceps_cm || '' : '');
    $('#patient-evolution-calf').val(note ? note.calf_cm || '' : '');
    $('#patient-evolution-skinfold-triceps').val(note ? note.skinfold_triceps_mm || '' : '');
    $('#patient-evolution-skinfold-subscapular').val(note ? note.skinfold_subscapular_mm || '' : '');
    $('#patient-evolution-skinfold-suprailiac').val(note ? note.skinfold_suprailiac_mm || '' : '');
    $('#patient-evolution-skinfold-abdominal').val(note ? note.skinfold_abdominal_mm || '' : '');
    $('#patient-evolution-skinfold-chest').val(note ? note.skinfold_chest_mm || '' : '');
    $('#patient-evolution-skinfold-thigh').val(note ? note.skinfold_thigh_mm || '' : '');
    if (!note) {
        copyLatestPhysicalMetricsToEvolution();
    } else {
        updatePatientEvolutionBmiDisplay();
    }
    populatePatientEvolutionAppointmentSelect(note ? note.appointment_id || '' : '');
    if (patientEvolutionModal) {
        patientEvolutionModal.show();
        setTimeout(() => $('#patient-evolution-title').trigger('focus'), 180);
    }
}

function hidePatientEvolutionForm() {
    if (patientEvolutionModal && $('#patientEvolutionModal').hasClass('show')) {
        patientEvolutionModal.hide();
    }
    if ($('#patient-evolution-form').length) {
        $('#patient-evolution-form')[0].reset();
    }
    $('#patient-evolution-id').val(0);
    $('#patient-evolution-bmi').text('-');
}

function savePatientEvolution(form) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const patientId = parseInt($('#patient-editor-id').val() || '0', 10);
    if (!patientId) {
        showPatientEvolutionAlert('danger', `Guarda primero el ${patientSingular}.`);
        return;
    }
    const formData = new FormData(form);
    formData.set('patient_id', patientId);
    const $button = $('#btn-save-patient-evolution');
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Guardando');
    $.ajax({
        url: 'api/admin.php?action=save_patient_evolution',
        method: 'POST',
        data: formData,
        dataType: 'json',
        processData: false,
        contentType: false,
        success: function (res) {
            if (!res.success) {
                showPatientEvolutionAlert('danger', res.error || 'No se pudo guardar la evolución.');
                return;
            }
            showPatientEvolutionAlert('success', res.message || 'Evolución guardada correctamente.');
            hidePatientEvolutionForm();
            loadPatientEvolution(patientId);
            loadPatientFiles(patientId);
        },
        error: function () {
            showPatientEvolutionAlert('danger', 'Error de conexión al guardar la evolución.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function resetPatientReports(patientId = 0) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    CURRENT_PATIENT_REPORTS_ID = parseInt(patientId || 0, 10);
    CURRENT_PATIENT_REPORTS_ROWS = [];
    $('#patient-reports-alert').addClass('d-none').text('');
    $('#patient-reports-count').text('');
    $('#btn-refresh-patient-reports').prop('disabled', !patientId);
    $('#btn-show-custom-patient-report').prop('disabled', !patientId);
    updatePatientReportSuggestionButton();
    if (!patientId) {
        $('#patient-reports-body').html(`<tr><td colspan="5" class="text-center text-muted py-4">Guarda el ${patientSingular} para ver sus informes.</td></tr>`);
        return;
    }
    $('#patient-reports-body').html('<tr><td colspan="5" class="text-center text-muted py-4">Cargando informes...</td></tr>');
}

function currentPatientKnowledgeProblemId() {
    const selected = parseInt($('#patient-editor-knowledge-problem').val() || 0, 10);
    if (selected > 0) {
        return selected;
    }
    return parseInt(CURRENT_PATIENT_EDITOR && CURRENT_PATIENT_EDITOR.knowledge_problem_id ? CURRENT_PATIENT_EDITOR.knowledge_problem_id : 0, 10);
}

function updatePatientReportSuggestionButton() {
    const patientId = CURRENT_PATIENT_REPORTS_ID || parseInt($('#patient-editor-id').val() || '0', 10);
    $('#btn-show-suggested-patient-reports')
        .prop('disabled', !patientId)
        .attr('title', !patientId ? `Guarda primero el ${sectorLabel('patient', 'singular', 'paciente')} para ver informes sugeridos.` : '');
}

function loadPatientReports(patientId) {
    patientId = parseInt(patientId || 0, 10);
    if (!patientId) {
        resetPatientReports(0);
        return;
    }
    CURRENT_PATIENT_REPORTS_ID = patientId;
    $('#patient-reports-alert').addClass('d-none').text('');
    $('#patient-reports-body').html('<tr><td colspan="5" class="text-center text-muted py-4">Cargando informes...</td></tr>');
    $('#patient-reports-count').text('');
    $('#btn-refresh-patient-reports').prop('disabled', false);
    $('#btn-show-custom-patient-report').prop('disabled', false);
    updatePatientReportSuggestionButton();
    $.ajax({
        url: 'api/admin.php?action=patient_reports',
        dataType: 'json',
        data: { patient_id: patientId },
        success: function (res) {
            if (!res.success) {
                showPatientReportsAlert('danger', res.error || 'No se pudieron cargar los informes.');
                $('#patient-reports-body').html('<tr><td colspan="5" class="text-center text-muted py-4">No se pudieron cargar los informes.</td></tr>');
                return;
            }
            renderPatientReports(res.reports || []);
        },
        error: function () {
            showPatientReportsAlert('danger', 'Error de conexion al cargar los informes.');
            $('#patient-reports-body').html('<tr><td colspan="5" class="text-center text-muted py-4">No se pudieron cargar los informes.</td></tr>');
        }
    });
}

function showPatientReportSuggestions() {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const patientId = CURRENT_PATIENT_REPORTS_ID || parseInt($('#patient-editor-id').val() || '0', 10);
    if (!patientId) {
        showPatientReportsAlert('danger', `Guarda primero el ${patientSingular} para sugerir informes.`);
        return;
    }
    $('#patient-report-suggestions-alert').addClass('d-none').text('');
    $('#patient-report-suggestions-body').html('<div class="text-center text-muted py-4">Selecciona un diagnóstico/objetivo para ver informes sugeridos.</div>');
    $('#patient-report-suggestions-problem').prop('disabled', true).html('<option value="">Cargando diagnósticos...</option>');
    if (patientReportSuggestionsModal) {
        patientReportSuggestionsModal.show();
    }
    loadKnowledgeProblems(function () {
        populatePatientReportSuggestionProblemSelect();
        const selectedProblemId = parseInt($('#patient-report-suggestions-problem').val() || 0, 10);
        if (selectedProblemId > 0) {
            loadPatientReportSuggestionsForProblem(selectedProblemId);
        }
    });
}

function populatePatientReportSuggestionProblemSelect() {
    const selected = String(currentPatientKnowledgeProblemId() || '');
    const diagnosisLabel = sectorText('clinicalTerms.diagnosis', 'diagnóstico');
    const $select = $('#patient-report-suggestions-problem');
    const rows = Array.isArray(KNOWLEDGE_PROBLEMS) ? KNOWLEDGE_PROBLEMS : [];
    if (!rows.length) {
        $select.prop('disabled', true).html(`<option value="">No hay ${escapeHtml(diagnosisLabel)} disponibles</option>`);
        $('#patient-report-suggestions-body').html(`<div class="text-center text-muted py-4">No hay ${escapeHtml(diagnosisLabel)} disponibles.</div>`);
        return;
    }
    const groups = {};
    const hasMultipleSectors = new Set(rows.map(problem => problem.sector_key || APP_CURRENT_SECTOR_KEY).filter(Boolean)).size > 1;
    rows.forEach(problem => {
        const sectorLabelText = hasMultipleSectors ? (problem.sector_label || knowledgeSectorName(problem.sector_key || APP_CURRENT_SECTOR_KEY)) : '';
        const area = `${sectorLabelText ? `${sectorLabelText} · ` : ''}${problem.area_name || 'Sin área'}`;
        if (!groups[area]) groups[area] = [];
        groups[area].push(problem);
    });
    let html = `<option value="">Selecciona ${escapeHtml(diagnosisLabel)}</option>`;
    Object.keys(groups).sort().forEach(area => {
        html += `<optgroup label="${escapeHtml(area)}">`;
        groups[area].forEach(problem => {
            const label = `${problem.name || ''}${problem.alias ? ` (${problem.alias})` : ''}`;
            html += `<option value="${parseInt(problem.id, 10)}">${escapeHtml(label)}</option>`;
        });
        html += '</optgroup>';
    });
    $select.html(html).val(selected).prop('disabled', false);
    if (selected && !$select.val()) {
        $select.val('');
    }
}

function loadPatientReportSuggestionsForProblem(problemId) {
    const patientId = CURRENT_PATIENT_REPORTS_ID || parseInt($('#patient-editor-id').val() || '0', 10);
    problemId = parseInt(problemId || 0, 10);
    if (!patientId || !problemId) {
        $('#patient-report-suggestions-body').html('<div class="text-center text-muted py-4">Selecciona un diagnóstico/objetivo para ver informes sugeridos.</div>');
        return;
    }
    $('#patient-report-suggestions-alert').addClass('d-none').text('');
    $('#patient-report-suggestions-body').html('<div class="text-center text-muted py-4">Cargando sugerencias...</div>');
    $.ajax({
        url: 'api/admin.php?action=patient_report_suggestions',
        dataType: 'json',
        data: { patient_id: patientId, knowledge_problem_id: problemId },
        success: function (res) {
            if (!res.success) {
                $('#patient-report-suggestions-alert').removeClass('d-none alert-success').addClass('alert-danger').text(res.error || 'No se pudieron cargar las sugerencias.');
                $('#patient-report-suggestions-body').html('<div class="text-center text-muted py-4">No hay sugerencias disponibles.</div>');
                return;
            }
            renderPatientReportSuggestions(res.suggestions || []);
        },
        error: function () {
            $('#patient-report-suggestions-alert').removeClass('d-none alert-success').addClass('alert-danger').text('Error de conexion al cargar las sugerencias.');
            $('#patient-report-suggestions-body').html('<div class="text-center text-muted py-4">No hay sugerencias disponibles.</div>');
        }
    });
}

function renderPatientReportSuggestions(suggestions) {
    const rows = Array.isArray(suggestions) ? suggestions : [];
    if (!rows.length) {
        $('#patient-report-suggestions-body').html('<div class="text-center text-muted py-4">No hay informes sugeridos para este diagnostico.</div>');
        return;
    }
    const html = rows.map(item => {
        const title = item.title || 'Informe';
        const typeLabel = patientReportTypeLabel(item.report_key);
        const usedCount = parseInt(item.used_count || 0, 10);
        const usedText = usedCount === 1 ? 'Usado en 1 paciente similar' : `Usado en ${usedCount} pacientes similares`;
        const lastUsed = item.last_used_at ? formatDateTimeLabel(item.last_used_at) : '';
        const sourceDocumentName = String(item.source_document_name || '').trim();
        const sourceName = sourceDocumentName && sourceDocumentName !== '0' && sourceDocumentName !== '-'
            ? `<br><small class="text-muted">Plantilla: ${escapeHtml(sourceDocumentName)}</small>`
            : '';
        return `
            <div class="patient-report-suggestion-item border rounded p-3 mb-2">
                <div class="d-flex justify-content-between gap-3 flex-wrap">
                    <div>
                        <strong>${escapeHtml(title)}</strong>
                        <div class="text-muted small">${escapeHtml(typeLabel)} · ${escapeHtml(usedText)}${lastUsed ? ` · Ultimo uso: ${escapeHtml(lastUsed)}` : ''}</div>
                        ${sourceName}
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-outline-primary btn-sm btn-add-suggested-patient-report" data-source-report-id="${parseInt(item.id || 0, 10)}">
                            <i class="bi bi-plus-lg"></i> Añadir
                        </button>
                    </div>
                </div>
            </div>
        `;
    }).join('');
    $('#patient-report-suggestions-body').html(html);
}

function addSuggestedPatientReport(button) {
    const patientId = CURRENT_PATIENT_REPORTS_ID || parseInt($('#patient-editor-id').val() || '0', 10);
    const sourceReportId = parseInt($(button).data('source-report-id') || 0, 10);
    const problemId = parseInt($('#patient-report-suggestions-problem').val() || 0, 10);
    if (!patientId || !sourceReportId) {
        $('#patient-report-suggestions-alert').removeClass('d-none alert-success').addClass('alert-danger').text('No se pudo identificar el informe sugerido.');
        return;
    }
    const $button = $(button);
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Añadiendo...');
    $.ajax({
        url: 'api/admin.php?action=add_suggested_patient_report',
        method: 'POST',
        dataType: 'json',
        data: { patient_id: patientId, source_report_id: sourceReportId, knowledge_problem_id: problemId },
        success: function (res) {
            if (!res.success) {
                $('#patient-report-suggestions-alert').removeClass('d-none alert-success').addClass('alert-danger').text(res.error || 'No se pudo anadir el informe sugerido.');
                return;
            }
            $('#patient-report-suggestions-alert').removeClass('d-none alert-danger').addClass('alert-success').text(res.message || 'Informe anadido correctamente.');
            $button.closest('.patient-report-suggestion-item').fadeOut(180, function () {
                $(this).remove();
                if (!$('#patient-report-suggestions-body .patient-report-suggestion-item').length) {
                    $('#patient-report-suggestions-body').html('<div class="text-center text-muted py-4">No hay mas sugerencias disponibles.</div>');
                }
            });
            loadPatientReports(patientId);
        },
        error: function () {
            $('#patient-report-suggestions-alert').removeClass('d-none alert-success').addClass('alert-danger').text('Error de conexion al anadir el informe sugerido.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function renderPatientReports(reports) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const rows = Array.isArray(reports) ? reports : [];
    CURRENT_PATIENT_REPORTS_ROWS = rows;
    const templateRows = patientReportTemplates().map(template => {
        const lastGenerated = rows.find(report => report.report_key === template.key);
        const generatedMeta = lastGenerated
            ? (lastGenerated.generated_at ? formatDisplayDateOnly(lastGenerated.generated_at) : (lastGenerated.created_at ? formatDisplayDateOnly(lastGenerated.created_at) : ''))
            : '';
        const configButton = lastGenerated && template.key !== 'internal_summary'
            ? `<button type="button" class="btn btn-outline-secondary btn-sm btn-configure-patient-report" data-report-id="${parseInt(lastGenerated.id || 0, 10)}" title="Configurar informe" aria-label="Configurar informe"><i class="bi bi-gear"></i></button>`
            : '';
        const finalButton = lastGenerated && lastGenerated.final_url
            ? `<a class="btn btn-outline-success btn-sm" href="${escapeHtml(lastGenerated.final_url)}" target="_blank" rel="noopener" title="Descargar informe final" aria-label="Descargar informe final"><i class="bi bi-download"></i></a>`
            : '';
        const paymentBadge = lastGenerated ? patientReportPaymentBadge(lastGenerated) : '<span class="badge bg-light text-dark">Configurable</span>';
        const paymentLabel = lastGenerated ? patientReportPaymentLabel(lastGenerated) : template.defaultPayment;
        const statusLabel = lastGenerated ? patientReportStatusLabel(lastGenerated.status) : 'Pendiente';
        return `
        <tr class="patient-report-template-row">
            <td>
                <strong>${escapeHtml(template.title)}</strong>
                <br><small class="text-muted">${escapeHtml(template.description)}</small>
            </td>
            <td><span class="badge bg-light text-dark">${escapeHtml(statusLabel)}</span><br><small class="text-muted">Predefinido</small></td>
            <td>${paymentBadge}<br><small class="text-muted">${escapeHtml(paymentLabel)}</small></td>
            <td>${escapeHtml(generatedMeta)}</td>
            <td class="text-end">
                <div class="d-flex justify-content-end gap-1 flex-wrap">
                    ${configButton}
                    ${finalButton}
                    <button type="button" class="btn btn-outline-primary btn-sm btn-patient-report" data-report-key="${escapeHtml(template.key)}" data-report-type="${escapeHtml(template.type)}" title="${lastGenerated ? 'Abrir/imprimir informe' : 'Generar informe'}" aria-label="${lastGenerated ? 'Abrir/imprimir informe' : 'Generar informe'}">
                        <i class="bi bi-printer"></i>
                    </button>
                </div>
            </td>
        </tr>
        `;
    });
    const customRows = rows.filter(report => report.report_key === 'custom_upload').map(report => {
        const statusLabel = patientReportStatusLabel(report.status);
        const paymentLabel = patientReportPaymentLabel(report);
        const statusBadge = `<span class="badge bg-light text-dark">${escapeHtml(statusLabel)}</span>`;
        const paymentBadge = patientReportPaymentBadge(report);
        const previewButton = report.url
            ? `<a class="btn btn-outline-primary btn-sm" href="${escapeHtml(report.url)}" target="_blank" rel="noopener" title="Abrir borrador" aria-label="Abrir borrador"><i class="bi bi-file-earmark-text"></i></a>`
            : '';
        const sourceButton = report.source_url
            ? `<a class="btn btn-outline-primary btn-sm" href="${escapeHtml(report.source_url)}" target="_blank" rel="noopener" title="Descargar archivo" aria-label="Descargar archivo"><i class="bi bi-download"></i></a>`
            : '';
        const finalButton = report.final_url
            ? `<a class="btn btn-outline-success btn-sm" href="${escapeHtml(report.final_url)}" target="_blank" rel="noopener" title="Descargar informe final" aria-label="Descargar informe final"><i class="bi bi-download"></i></a>`
            : '';
        const configButton = `<button type="button" class="btn btn-outline-secondary btn-sm btn-configure-patient-report" data-report-id="${parseInt(report.id || 0, 10)}" title="Configurar informe" aria-label="Configurar informe"><i class="bi bi-gear"></i></button>`;
        const portalBadge = parseInt(report.portal_available || 0, 10) === 1
            ? '<br><span class="badge bg-info text-dark mt-1">Portal</span>'
            : '';
        return `
        <tr>
            <td>
                <strong>${escapeHtml(report.title || 'Informe')}</strong>
                <br><small class="text-muted">${escapeHtml(patientReportTypeLabel(report.report_key))}</small>
            </td>
            <td>${statusBadge}</td>
            <td>${paymentBadge}<br><small class="text-muted">${escapeHtml(paymentLabel)}</small>${portalBadge}</td>
            <td>${report.generated_at ? formatDisplayDateOnly(report.generated_at) : (report.created_at ? formatDisplayDateOnly(report.created_at) : '')}</td>
            <td class="text-end">
                <div class="d-flex justify-content-end gap-1 flex-wrap">
                    ${configButton}
                    ${previewButton}
                    ${sourceButton}
                    ${finalButton}
                </div>
            </td>
        </tr>
        `;
    });
    const generatedTemplateCount = patientReportTemplates().filter(template => rows.some(report => report.report_key === template.key)).length;
    const generatedCount = generatedTemplateCount + customRows.length;
    const html = templateRows.concat(customRows).join('');
    $('#patient-reports-body').html(html);
    $('#patient-reports-count').text(`${generatedCount} ${generatedCount === 1 ? 'informe generado/subido' : 'informes generados/subidos'}`);
}

function patientReportTemplates() {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    return [
        {
            key: 'internal_summary',
            type: 'internal',
            title: 'Informe interno',
            description: 'Informe para uso del profesional.',
            defaultPayment: 'Gratuito',
            icon: 'bi bi-file-earmark-plus'
        },
        {
            key: 'patient_summary',
            type: 'patient',
            title: `Informe ${patientSingular}`,
            description: `Alta, citas y tareas para entregar al ${patientSingular}.`,
            defaultPayment: 'Gratuito',
            icon: 'bi bi-file-earmark-plus'
        },
        {
            key: 'clinical_summary',
            type: 'clinical',
            title: 'Informe clínico',
            description: 'Borrador con diagnóstico, antecedentes, interpretación, conclusiones, etc.',
            defaultPayment: 'De pago',
            icon: 'bi bi-file-earmark-plus'
        },
        {
            key: 'evolution_report',
            type: 'evolution',
            title: 'Informe de evolución',
            description: 'Evolución del paciente, resultados de pruebas, etc.',
            defaultPayment: 'Incluido en consulta',
            icon: 'bi bi-file-earmark-plus'
        }
    ];
}

function patientReportPaymentBadge(report) {
    if (!report || report.payment_mode !== 'paid') {
        return '<span class="badge bg-light text-dark">Sin pago</span>';
    }
    return report.payment_status === 'paid'
        ? '<span class="badge bg-success">Pagado</span>'
        : '<span class="badge bg-warning text-dark">Pendiente de pago</span>';
}

function patientReportTypeLabel(reportKey) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const labels = {
        internal_summary: 'Borrador interno',
        patient_summary: `Resumen para el ${patientSingular}`,
        clinical_summary: 'Informe clínico',
        evolution_report: 'Informe de evolución',
        custom_upload: 'Plantilla de informe'
    };
    return labels[reportKey] || reportKey || 'Informe';
}

function patientReportStatusLabel(status) {
    const labels = {
        draft: 'Borrador',
        generated: 'Generado',
        final_uploaded: 'Final subido',
        cancelled: 'Cancelado'
    };
    return labels[status] || status || 'Generado';
}

function patientReportPaymentLabel(report) {
    if (!report || report.payment_mode !== 'paid') {
        return report && report.payment_mode === 'included' ? 'Incluido en consulta' : 'Gratuito';
    }
    const price = parseFloat(report.price || 0);
    return price > 0 ? `${price.toFixed(2)} EUR` : 'De pago';
}

function showPatientReportConfigForm(reportId) {
    const report = (CURRENT_PATIENT_REPORTS_ROWS || []).find(item => String(item.id) === String(reportId));
    if (!report) {
        showPatientReportsAlert('danger', 'No se pudo localizar el informe seleccionado.');
        return;
    }
    const configForm = $('#patient-report-config-form')[0];
    if (configForm) {
        configForm.reset();
    }
    $('#patient-report-config-alert').addClass('d-none').text('');
    $('#patient-report-config-id').val(report.id || 0);
    $('#patient-report-config-patient-id').val(report.patient_id || CURRENT_PATIENT_REPORTS_ID || 0);
    $('#patientReportConfigModal .modal-title').text('Configurar informe');
    $('#patient-report-title').val(report.title || 'Informe');
    $('#patient-report-payment-mode').val(report.payment_mode || 'free');
    $('#patient-report-payment-status').val(report.payment_status || (report.payment_mode === 'paid' ? 'pending' : 'not_required'));
    $('#patient-report-price').val(report.price ? parseFloat(report.price).toFixed(2) : '');
    $('#patient-report-portal-available').prop('checked', parseInt(report.portal_available || 0, 10) === 1);
    $('#patient-report-final-document').val('');
    $('#patient-report-source-document-wrap').toggleClass('d-none', report.source_type !== 'custom_upload');
    $('#patient-report-source-document').prop('required', false).val('');
    const sourceName = report.source_document_name || '';
    const sourceUrl = report.source_url || '';
    $('#patient-report-current-source').html(sourceName
        ? `Archivo base actual: ${sourceUrl ? `<a href="${escapeHtml(sourceUrl)}" target="_blank" rel="noopener">${escapeHtml(sourceName)}</a>` : escapeHtml(sourceName)}${report.source_document_size ? ` (${formatFileSize(report.source_document_size)})` : ''}`
        : '');
    const finalName = report.final_document_name || '';
    const finalUrl = report.final_url || '';
    $('#patient-report-current-final').html(finalName
        ? `Documento final actual: ${finalUrl ? `<a href="${escapeHtml(finalUrl)}" target="_blank" rel="noopener">${escapeHtml(finalName)}</a>` : escapeHtml(finalName)}${report.final_document_size ? ` (${formatFileSize(report.final_document_size)})` : ''}`
        : 'Todavia no hay version final/oficial subida.');
    updatePatientReportPaymentFields();
    if (patientReportConfigModal) {
        patientReportConfigModal.show();
    }
}

function showCustomPatientReportForm() {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const patientId = CURRENT_PATIENT_REPORTS_ID || parseInt($('#patient-editor-id').val() || '0', 10);
    if (!patientId) {
        showPatientReportsAlert('danger', `Guarda primero el ${patientSingular} para subir un informe propio.`);
        return;
    }
    const configForm = $('#patient-report-config-form')[0];
    if (configForm) {
        configForm.reset();
    }
    $('#patient-report-config-alert').addClass('d-none').text('');
    $('#patientReportConfigModal .modal-title').text('Añadir plantilla de informe');
    $('#patient-report-config-id').val('0');
    $('#patient-report-config-patient-id').val(patientId);
    $('#patient-report-title').val('');
    $('#patient-report-payment-mode').val('free');
    $('#patient-report-payment-status').val('not_required');
    $('#patient-report-price').val('');
    $('#patient-report-portal-available').prop('checked', false);
    $('#patient-report-source-document-wrap').removeClass('d-none');
    $('#patient-report-source-document').prop('required', true).val('');
    $('#patient-report-current-source').text('Sube el archivo base de la plantilla de informe.');
    $('#patient-report-final-document').val('');
    $('#patient-report-current-final').text('La version final/oficial se puede subir ahora o mas adelante.');
    updatePatientReportPaymentFields();
    if (patientReportConfigModal) {
        patientReportConfigModal.show();
    }
}

function updatePatientReportPaymentFields() {
    const mode = $('#patient-report-payment-mode').val() || 'free';
    const isPaid = mode === 'paid';
    $('#patient-report-price').prop('disabled', !isPaid);
    $('#patient-report-payment-status option[value="pending"]').prop('disabled', !isPaid);
    if (!isPaid && $('#patient-report-payment-status').val() === 'pending') {
        $('#patient-report-payment-status').val('not_required');
    }
    if (isPaid && $('#patient-report-payment-status').val() === 'not_required') {
        $('#patient-report-payment-status').val('pending');
    }
}

function savePatientReportConfig(form) {
    const $button = $('#btn-save-patient-report-config');
    const original = $button.html();
    const formData = new FormData(form);
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Guardando...');
    $('#patient-report-config-alert').addClass('d-none').text('');
    const reportId = parseInt($('#patient-report-config-id').val() || '0', 10);
    $.ajax({
        url: reportId > 0 ? 'api/admin.php?action=update_patient_report' : 'api/admin.php?action=create_custom_patient_report',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                $('#patient-report-config-alert').removeClass('d-none alert-success').addClass('alert-danger').text(res.error || 'No se pudo guardar el informe.');
                return;
            }
            if (patientReportConfigModal) {
                patientReportConfigModal.hide();
            }
            showPatientReportsAlert('success', res.message || 'Informe actualizado correctamente.', true);
            loadPatientReports(CURRENT_PATIENT_REPORTS_ID || parseInt($('#patient-editor-id').val() || '0', 10));
        },
        error: function () {
            $('#patient-report-config-alert').removeClass('d-none alert-success').addClass('alert-danger').text('Error de conexion al guardar el informe.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function generatePatientReport(button) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const patientId = parseInt($('#patient-editor-id').val() || '0', 10);
    if (!patientId) {
        showPatientReportsAlert('danger', `Guarda primero el ${patientSingular} para generar el informe.`);
        return;
    }
    const $button = $(button);
    const reportKey = $button.data('report-key') || ($button.data('report-type') === 'patient' ? 'patient_summary' : 'internal_summary');
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({
        url: 'api/admin.php?action=create_patient_report',
        method: 'POST',
        dataType: 'json',
        data: { patient_id: patientId, report_key: reportKey },
        success: function (res) {
            if (!res.success) {
                showPatientReportsAlert('danger', res.error || 'No se pudo generar el informe.');
                return;
            }
            showPatientReportsAlert('success', res.message || 'Informe generado correctamente.', true);
            loadPatientReports(patientId);
            if (res.url) {
                window.open(res.url, '_blank', 'noopener');
            }
        },
        error: function () {
            showPatientReportsAlert('danger', 'Error de conexion al generar el informe.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function resetPatientFiles(patientId = 0) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    CURRENT_PATIENT_FILES_ID = parseInt(patientId || 0, 10);
    CURRENT_PATIENT_FILES_ROWS = [];
    $('#patient-files-alert').addClass('d-none').text('');
    $('#patient-files-count').text('');
    if (!patientId) {
        $('#patient-files-body').html(`<tr><td colspan="5" class="text-center text-muted py-4">Guarda el ${patientSingular} para ver su documentaci&oacute;n.</td></tr>`);
        return;
    }
    $('#patient-files-body').html('<tr><td colspan="5" class="text-center text-muted py-4">Cargando documentaci&oacute;n...</td></tr>');
}

function loadPatientFiles(patientId) {
    patientId = parseInt(patientId || 0, 10);
    if (!patientId) {
        resetPatientFiles(0);
        return;
    }
    CURRENT_PATIENT_FILES_ID = patientId;
    $('#patient-files-alert').addClass('d-none').text('');
    $('#patient-files-body').html('<tr><td colspan="5" class="text-center text-muted py-4">Cargando documentaci&oacute;n...</td></tr>');
    $('#patient-files-count').text('');
    $.ajax({
        url: 'api/admin.php?action=patient_files',
        dataType: 'json',
        data: { patient_id: patientId, type: CURRENT_PATIENT_FILES_FILTER || 'all' },
        success: function (res) {
            if (!res.success) {
                showPatientFilesAlert('danger', res.error || 'No se pudo cargar la documentacion.');
                $('#patient-files-body').html('<tr><td colspan="5" class="text-center text-muted py-4">No se pudo cargar la documentaci&oacute;n.</td></tr>');
                return;
            }
            renderPatientFiles(res.files || []);
        },
        error: function () {
            showPatientFilesAlert('danger', 'Error de conexion al cargar la documentacion.');
            $('#patient-files-body').html('<tr><td colspan="5" class="text-center text-muted py-4">No se pudo cargar la documentaci&oacute;n.</td></tr>');
        }
    });
}

function renderPatientFiles(files) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const rows = Array.isArray(files) ? files : [];
    CURRENT_PATIENT_FILES_ROWS = rows;
    if (!rows.length) {
        const emptyText = CURRENT_PATIENT_FILES_FILTER === 'questionnaire'
            ? `Este ${patientSingular} no tiene cuestionarios.`
            : (CURRENT_PATIENT_FILES_FILTER === 'file'
                ? `Este ${patientSingular} no tiene archivos subidos.`
                : `Este ${patientSingular} no tiene documentaci&oacute;n.`);
        $('#patient-files-body').html(`<tr><td colspan="5" class="text-center text-muted py-4">${emptyText}</td></tr>`);
        $('#patient-files-count').text('');
        return;
    }
    const html = rows.map(file => {
        const isQuestionnaire = file.type === 'questionnaire';
        const typeBadge = isQuestionnaire
            ? '<span class="badge bg-info text-dark">Cuestionario</span>'
            : '<span class="badge bg-secondary">Archivo</span>';
        const statusBadge = isQuestionnaire && file.status
            ? `<span class="badge bg-light text-dark ms-1">${formatPatientDocumentStatus(file.status)}</span>`
            : '';
        const portalBadge = parseInt(file.visible_to_patient || 0, 10) === 1
            ? '<span class="badge bg-success">Portal</span>'
            : '<span class="text-muted small">No</span>';
        const scoreLine = (file.score || file.result_label)
            ? `<br><small class="text-muted">${escapeHtml([file.score, file.result_label].filter(Boolean).join(' - '))}</small>`
            : '';
        const fileNameLine = file.file_name
            ? `<br><small class="text-muted">${escapeHtml(file.file_name)}${file.size ? ` - ${formatFileSize(file.size)}` : ''}</small>`
            : (file.size ? `<br><small class="text-muted">${formatFileSize(file.size)}</small>` : '');
        const descriptionLine = file.description
            ? `<br><small class="text-muted">${escapeHtml(truncateText(file.description, 90))}</small>`
            : '';
        const versionLine = parseInt(file.version_count || 0, 10) > 1
            ? `<br><small class="text-muted">${parseInt(file.version_count, 10)} versiones</small>`
            : '';
        const downloadButton = file.url
            ? `<a class="btn btn-outline-primary btn-sm" href="${escapeHtml(file.url)}" target="_blank" rel="noopener" title="Descargar archivo"><i class="bi bi-download"></i></a>`
            : '';
        const editButton = file.can_delete
            ? `<button type="button" class="btn btn-outline-primary btn-sm btn-edit-patient-document" data-document-id="${parseInt(file.id || 0, 10)}" title="Editar documento"><i class="bi bi-pencil"></i></button>`
            : '';
        const deleteButton = file.can_delete
            ? `<button type="button" class="btn btn-outline-danger btn-sm btn-delete-patient-document" data-document-id="${parseInt(file.id || 0, 10)}" title="Eliminar documento"><i class="bi bi-trash"></i></button>`
            : '';
        return `
        <tr>
            <td><strong>${escapeHtml(file.name || 'Documento')}</strong>${scoreLine}${fileNameLine}${descriptionLine}</td>
            <td>${typeBadge}${statusBadge}<br><small class="text-muted">${escapeHtml(file.source || '')}${versionLine}</small></td>
            <td>${file.date ? formatDateTimeLabel(file.date) : '-'}</td>
            <td>${portalBadge}</td>
            <td class="text-end">
                <div class="d-inline-flex gap-1">${downloadButton}${editButton}${deleteButton}</div>
            </td>
        </tr>
    `;
    }).join('');
    $('#patient-files-body').html(html);
    $('#patient-files-count').text(`${rows.length} ${rows.length === 1 ? 'documento' : 'documentos'}`);
}

function formatPatientDocumentStatus(status) {
    const labels = {
        pending: 'Pendiente',
        completed: 'Completado',
        reviewed: 'Revisado'
    };
    return labels[status] || status || '';
}

function updatePatientDocumentTypeFields(isEditing = false, syncDefaultStatus = false) {
    const isQuestionnaire = $('#patient-document-type').val() === 'questionnaire';
    $('.patient-document-questionnaire-field').toggleClass('d-none', !isQuestionnaire);
    if (!isEditing && syncDefaultStatus) {
        $('#patient-document-status').val(isQuestionnaire ? 'pending' : 'completed');
        $('#patient-document-result-visible-to-patient').prop('checked', false);
    }
    $('#btn-save-patient-document').html(`<i class="bi bi-check2"></i> ${isQuestionnaire ? 'Guardar cuestionario' : 'Guardar documento'}`);
}

function normalizePatientDocumentDate(value) {
    const raw = String(value || '').trim();
    if (/^\d{4}-\d{2}-\d{2}/.test(raw)) {
        return raw.slice(0, 10);
    }
    return '';
}

function showPatientDocumentForm(document = null, defaultType = 'file') {
    const patientId = parseInt($('#patient-editor-id').val() || CURRENT_PATIENT_FILES_ID || '0', 10);
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    if (!patientId) {
        showPatientFilesAlert('danger', `Guarda el ${patientSingular} antes de anadir documentacion.`);
        return;
    }
    defaultType = defaultType === 'questionnaire' ? 'questionnaire' : 'file';
    const patientDocumentForm = $('#patient-document-form')[0];
    if (patientDocumentForm) {
        patientDocumentForm.reset();
    }
    $('#patient-document-id').val(document ? parseInt(document.id || 0, 10) : '0');
    $('#patient-document-patient-id').val(patientId);
    $('#patient-document-type').val((document && document.type) || defaultType);
    $('#patient-document-date').val(document ? normalizePatientDocumentDate(document.date) : new Date().toISOString().slice(0, 10));
    $('#patient-document-status').val((document && document.status) || (defaultType === 'questionnaire' ? 'pending' : 'completed'));
    $('#patient-document-title').val((document && document.name) || '');
    $('#patient-document-description').val((document && document.description) || '');
    $('#patient-document-score').val((document && document.score) || '');
    $('#patient-document-result-label').val((document && document.result_label) || '');
    $('#patient-document-observations').val((document && document.observations) || '');
    $('#patient-document-visible-to-patient').prop('checked', parseInt((document && document.visible_to_patient) || 0, 10) === 1);
    $('#patient-document-result-visible-to-patient').prop('checked', parseInt((document && document.result_visible_to_patient) || 0, 10) === 1);
    $('#patient-document-alert').addClass('d-none').text('');
    $('#patientDocumentModal .modal-title').text(document ? 'Editar documento' : 'Nuevo documento');
    updatePatientDocumentTypeFields(!!document, !document);
    if (patientDocumentModal) {
        patientDocumentModal.show();
    }
}

function savePatientDocument(form) {
    const formData = new FormData(form);
    const $button = $('#btn-save-patient-document');
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Guardando...');
    $('#patient-document-alert').addClass('d-none').text('');
    $.ajax({
        url: 'api/admin.php?action=save_patient_document_file',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                $('#patient-document-alert').removeClass('d-none alert-success').addClass('alert-danger').text(res.error || 'No se pudo guardar el documento.');
                return;
            }
            if (patientDocumentModal) {
                patientDocumentModal.hide();
            }
            showPatientFilesAlert('success', res.message || 'Documento guardado correctamente.');
            loadPatientFiles(CURRENT_PATIENT_FILES_ID || parseInt($('#patient-editor-id').val() || '0', 10));
        },
        error: function () {
            $('#patient-document-alert').removeClass('d-none alert-success').addClass('alert-danger').text('Error de conexion al guardar el documento.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function deletePatientDocument(documentId, $row) {
    documentId = parseInt(documentId || 0, 10);
    if (!documentId || !confirm('¿Eliminar este documento?')) {
        return;
    }
    $.ajax({
        url: 'api/admin.php?action=delete_patient_document_file',
        method: 'POST',
        dataType: 'json',
        data: { document_id: documentId },
        success: function (res) {
            if (!res.success) {
                showPatientFilesAlert('danger', res.error || 'No se pudo eliminar el documento.');
                return;
            }
            if ($row && $row.length) {
                $row.fadeOut(180, function () {
                    $(this).remove();
                    if (!$('#patient-files-body tr').length) {
                        loadPatientFiles(CURRENT_PATIENT_FILES_ID);
                    }
                });
            } else {
                loadPatientFiles(CURRENT_PATIENT_FILES_ID);
            }
            showPatientFilesAlert('success', res.message || 'Documento eliminado correctamente.');
        },
        error: function () {
            showPatientFilesAlert('danger', 'Error de conexion al eliminar el documento.');
        }
    });
}

function formatFileSize(bytes) {
    const size = parseInt(bytes || 0, 10);
    if (!size) return '';
    if (size < 1024) return `${size} B`;
    if (size < 1024 * 1024) return `${(size / 1024).toFixed(1).replace('.', ',')} KB`;
    return `${(size / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}

function resetPatientBonuses(patientId = 0) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    CURRENT_PATIENT_BONUSES_ID = parseInt(patientId || 0, 10);
    CURRENT_PATIENT_BONUS_CATALOG = [];
    CURRENT_PATIENT_BONUS_CAN_MANAGE = false;
    $('#patient-bonuses-alert').addClass('d-none').text('');
    $('#patient-bonuses-count').text('');
    $('#patient-bonus-create-form').addClass('d-none');
    $('#patient-bonus-create-bonus').empty();
    $('#btn-show-create-patient-bonus').addClass('d-none');
    if (!patientId) {
        $('#patient-bonuses-body').html(`<tr><td colspan="${IS_SUPERADMIN ? 7 : 6}" class="text-center text-muted py-4">Guarda el ${patientSingular} para ver sus bonos.</td></tr>`);
        return;
    }
    $('#patient-bonuses-body').html(`<tr><td colspan="${IS_SUPERADMIN ? 7 : 6}" class="text-center text-muted py-4">Cargando bonos...</td></tr>`);
}

function loadPatientBonuses(patientId) {
    patientId = parseInt(patientId || 0, 10);
    if (!patientId) {
        resetPatientBonuses(0);
        return;
    }
    CURRENT_PATIENT_BONUSES_ID = patientId;
    $('#patient-bonuses-alert').addClass('d-none').text('');
    $('#patient-bonuses-body').html(`<tr><td colspan="${IS_SUPERADMIN ? 7 : 6}" class="text-center text-muted py-4">Cargando bonos...</td></tr>`);
    $('#patient-bonuses-count').text('');

    $.ajax({
        url: 'api/bonuses.php?action=patient_bonuses',
        dataType: 'json',
        data: { patient_id: patientId },
        success: function (res) {
            if (!res.success) {
                showPatientBonusesAlert('danger', res.error || 'No se pudieron cargar los bonos.');
                $('#patient-bonuses-body').html(`<tr><td colspan="${IS_SUPERADMIN ? 7 : 6}" class="text-center text-muted py-4">No se pudieron cargar los bonos.</td></tr>`);
                return;
            }
            CURRENT_PATIENT_BONUS_CATALOG = Array.isArray(res.catalog) ? res.catalog : [];
            CURRENT_PATIENT_BONUS_CAN_MANAGE = Boolean(res.can_manage == 1);
            $('#btn-show-create-patient-bonus').toggleClass('d-none', !CURRENT_PATIENT_BONUS_CAN_MANAGE);
            if (!CURRENT_PATIENT_BONUS_CAN_MANAGE) {
                $('#patient-bonus-create-form').addClass('d-none');
            }
            renderPatientBonuses(res.bonuses || []);
        },
        error: function () {
            showPatientBonusesAlert('danger', 'Error de conexion al cargar los bonos.');
            $('#patient-bonuses-body').html(`<tr><td colspan="${IS_SUPERADMIN ? 7 : 6}" class="text-center text-muted py-4">No se pudieron cargar los bonos.</td></tr>`);
        }
    });
}

function renderPatientBonuses(bonuses) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const rows = Array.isArray(bonuses) ? bonuses : [];
    const colspan = IS_SUPERADMIN ? 7 : 6;
    if (!rows.length) {
        $('#patient-bonuses-body').html(`<tr><td colspan="${colspan}" class="text-center text-muted py-4">Este ${patientSingular} no tiene bonos registrados.</td></tr>`);
        $('#patient-bonuses-count').text('');
        return;
    }

    const html = rows.map(bonus => {
        const paid = bonus.amount_paid !== null && bonus.amount_paid !== undefined ? `${formatPrice(bonus.amount_paid)} €` : '-';
        const date = bonus.purchased_at ? formatDateTimeLabel(bonus.purchased_at) : '-';
        const remainingCell = CURRENT_PATIENT_BONUS_CAN_MANAGE
            ? `<div class="bonus-remaining-control">
                    <input type="number" class="form-control form-control-sm bonus-remaining-input" min="0" max="999" value="${parseInt(bonus.remaining_sessions || 0, 10)}" data-original-value="${parseInt(bonus.remaining_sessions || 0, 10)}" data-patient-bonus-id="${bonus.id}">
                    <button class="btn btn-outline-primary btn-sm btn-save-patient-bonus" type="button" data-patient-bonus-id="${bonus.id}" title="Guardar sesiones restantes">
                        <i class="bi bi-check2"></i>
                    </button>
                </div>`
            : `<strong>${bonus.remaining_sessions}</strong>`;
        const actionsCell = CURRENT_PATIENT_BONUS_CAN_MANAGE
            ? `<td class="text-end">
                    <button class="btn btn-outline-danger btn-sm btn-delete-patient-bonus" type="button" data-patient-bonus-id="${bonus.id}" data-bonus-name="${escapeHtml(bonus.name || '')}" data-patient-name="${escapeHtml($('#patient-editor-name').val() || '')}" title="Eliminar bono">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>`
            : (IS_SUPERADMIN ? '<td></td>' : '');
        return `
            <tr>
                <td>${escapeHtml(bonus.name || '')}</td>
                <td>${bonus.total_sessions}</td>
                <td>${remainingCell}</td>
                <td>${paid}</td>
                <td>${date}</td>
                <td>${bonusStatusLabel(bonus.status)}</td>
                ${actionsCell}
            </tr>
        `;
    }).join('');

    $('#patient-bonuses-body').html(html);
    $('#patient-bonuses-count').text(`${rows.length} ${rows.length === 1 ? 'bono' : 'bonos'}`);
}

function populatePatientBonusCreateSelect() {
    const $select = $('#patient-bonus-create-bonus');
    if (!$select.length) return;
    if (!CURRENT_PATIENT_BONUS_CATALOG.length) {
        $select.html('<option value="">No hay bonos activos disponibles</option>');
        $('#patient-bonus-create-total, #patient-bonus-create-remaining').val(1);
        return;
    }
    const current = $select.val();
    $select.html(CURRENT_PATIENT_BONUS_CATALOG.map(bonus => (
        `<option value="${bonus.id}" data-sessions="${parseInt(bonus.session_count || 1, 10)}">${escapeHtml(bonus.name || '')}</option>`
    )).join(''));
    if (current && $select.find(`option[value="${current}"]`).length) {
        $select.val(current);
    }
    syncPatientBonusCreateSessions();
}

function syncPatientBonusCreateSessions() {
    const sessions = parseInt($('#patient-bonus-create-bonus option:selected').data('sessions') || 1, 10);
    $('#patient-bonus-create-total').val(sessions);
    $('#patient-bonus-create-remaining').val(sessions);
}

function createPatientBonusForCurrentPatient() {
    const patientId = parseInt($('#patient-editor-id').val() || 0, 10);
    const bonusId = parseInt($('#patient-bonus-create-bonus').val() || 0, 10);
    const totalSessions = parseInt($('#patient-bonus-create-total').val() || 0, 10);
    const remainingSessions = parseInt($('#patient-bonus-create-remaining').val() || 0, 10);
    if (!patientId || !bonusId || totalSessions <= 0 || remainingSessions < 0) {
        showPatientBonusesAlert('danger', 'Revisa los datos del bono.');
        return;
    }
    const $button = $('#btn-create-patient-bonus');
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({
        url: 'api/bonuses.php?action=create_patient_bonus',
        method: 'POST',
        dataType: 'json',
        data: {
            patient_id: patientId,
            bonus_id: bonusId,
            total_sessions: totalSessions,
            remaining_sessions: remainingSessions
        },
        success: function (res) {
            if (!res.success) {
                showPatientBonusesAlert('danger', res.error || 'No se pudo crear el bono.');
                return;
            }
            showPatientBonusesAlert('success', res.message || 'Bono creado correctamente.', true);
            $('#patient-bonus-create-form').addClass('d-none');
            loadPatientBonuses(patientId);
            reloadCurrentBonusList();
        },
        error: function () {
            showPatientBonusesAlert('danger', 'Error de conexion al crear el bono.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function reloadCurrentPatientBonuses() {
    if ($('#patientEditorModal').hasClass('show') && CURRENT_PATIENT_BONUSES_ID > 0) {
        loadPatientBonuses(CURRENT_PATIENT_BONUSES_ID);
    }
}

function showPatientBonusesAlert(type, message, autoHide = false) {
    const $alert = $('#patient-bonuses-alert')
        .stop(true, true)
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .text(message)
        .show();
    if (autoHide) {
        $alert.delay(4000).fadeOut(200, function () {
            $(this).addClass('d-none').show();
        });
    }
}

function patientEditorSnapshot(patientId) {
    const existing = ADMIN_PATIENTS.find(item => String(item.id) === String(patientId)) || CURRENT_PATIENT_EDITOR || {};
    return {
        ...existing,
        id: parseInt(patientId || existing.id || 0, 10),
        name: $('#patient-editor-name').val() || '',
        patient_type: $('#patient-editor-type').val() || '',
        patient_status: $('#patient-editor-status').val() || 'active',
        birth_date: $('#patient-editor-birth-date').val() || '',
        referral_source: $('#patient-editor-referral-source').val() || '',
        knowledge_problem_id: parseInt($('#patient-editor-knowledge-problem').val() || 0, 10),
        emergency_contact_name: $('#patient-editor-emergency-name').val() || '',
        emergency_contact_phone: $('#patient-editor-emergency-phone').val() || '',
        emergency_contact_relation: $('#patient-editor-emergency-relation').val() || '',
        initial_consultation_reason: $('#patient-editor-initial-reason').val() || '',
        email: $('#patient-editor-email').val() || '',
        phone: $('#patient-editor-phone').val() || '',
        admission_date: $('#patient-editor-admission-date').val() || '',
        notes: $('#patient-editor-notes').val() || '',
        physical_sex: $('#patient-editor-physical-sex').val() || '',
        weight_kg: $('#patient-editor-weight').val() || '',
        height_cm: $('#patient-editor-height').val() || '',
        body_fat_percentage: $('#patient-editor-body-fat').val() || '',
        waist_cm: $('#patient-editor-waist').val() || '',
        hip_cm: $('#patient-editor-hip').val() || '',
        chest_cm: $('#patient-editor-chest').val() || '',
        thigh_cm: $('#patient-editor-thigh').val() || '',
        biceps_cm: $('#patient-editor-biceps').val() || '',
        calf_cm: $('#patient-editor-calf').val() || '',
        skinfold_triceps_mm: $('#patient-editor-skinfold-triceps').val() || '',
        skinfold_subscapular_mm: $('#patient-editor-skinfold-subscapular').val() || '',
        skinfold_suprailiac_mm: $('#patient-editor-skinfold-suprailiac').val() || '',
        skinfold_abdominal_mm: $('#patient-editor-skinfold-abdominal').val() || '',
        skinfold_chest_mm: $('#patient-editor-skinfold-chest').val() || '',
        skinfold_thigh_mm: $('#patient-editor-skinfold-thigh').val() || ''
    };
}

function mergePatientEditorSnapshot(patientId) {
    patientId = parseInt(patientId || 0, 10);
    if (!patientId) return;
    const snapshot = patientEditorSnapshot(patientId);
    const index = ADMIN_PATIENTS.findIndex(item => parseInt(item.id || 0, 10) === patientId);
    if (index >= 0) {
        ADMIN_PATIENTS[index] = { ...ADMIN_PATIENTS[index], ...snapshot };
    } else {
        ADMIN_PATIENTS.push(snapshot);
    }
    const dashboardIndex = DASHBOARD_PATIENTS.findIndex(item => parseInt(item.id || 0, 10) === patientId);
    if (dashboardIndex >= 0) {
        DASHBOARD_PATIENTS[dashboardIndex] = { ...DASHBOARD_PATIENTS[dashboardIndex], ...snapshot };
    } else if (dashboardPatientsLoaded) {
        DASHBOARD_PATIENTS.push(snapshot);
    }
    CURRENT_PATIENT_EDITOR = { ...snapshot };
    renderAdminPatients(ADMIN_PATIENTS);
    if (currentCalendarView === 'patients') {
        renderDashboardPatients(DASHBOARD_PATIENTS);
    }
    refreshPatientSelectOptions();
}

function savePatient(form) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const patientTitleSingular = sectorLabel('patient', 'titleSingular', 'Paciente');
    const $button = $('#btn-save-patient');
    const original = $button.html();
    const formData = new FormData(form);
    const isExistingPatient = parseInt($('#patient-editor-id').val() || 0, 10) > 0;
    if (isExistingPatient) {
        formData.delete('professional_id');
    }
    if ($('#patient-editor-professional').length && !isExistingPatient) {
        formData.set('professional_id', $('#patient-editor-professional').val() || '');
    } else if (!isExistingPatient) {
        const selectedProfessional = $('#admin-patients-professional').val() || '';
        if (selectedProfessional && selectedProfessional !== 'all') {
            formData.set('professional_id', selectedProfessional);
        }
    }
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Guardando');
    $('#patient-editor-alert').addClass('d-none').text('');

    $.ajax({
        url: 'api/admin.php?action=save_patient',
        method: 'POST',
        data: formData,
        dataType: 'json',
        processData: false,
        contentType: false,
        success: function (res) {
            if (!res.success) {
                showPatientEditorAlert('danger', res.error || `No se pudo guardar el ${patientSingular}.`);
                return;
            }
            showAdminPatientsAlert('success', res.message || `${patientTitleSingular} guardado correctamente.`);
            if (res.patient_id) {
                $('#patient-editor-id').val(res.patient_id);
                mergePatientEditorSnapshot(res.patient_id);
                $('.btn-patient-report').prop('disabled', false);
                loadPatientAppointmentHistory(res.patient_id);
            }
            patientEditorModal.hide();
            loadAdminPatients();
            dashboardPatientsLoaded = false;
            if (currentCalendarView === 'patients') {
                loadDashboardPatients();
            }
            loadBookingPatients(true);
        },
        error: function () {
            showPatientEditorAlert('danger', `Error de conexión al guardar el ${patientSingular}.`);
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function openPatientInviteModal(patientId, button) {
    const patient = ADMIN_PATIENTS.find(item => String(item.id) === String(patientId));
    const $button = $(button);
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

    $.ajax({
        url: 'api/admin.php?action=generate_invite',
        method: 'GET',
        dataType: 'json',
        data: { user_id: patientId },
        success: function (res) {
            if (res.success) {
                if (adminPatientsModal) {
                    adminPatientsModal.hide();
                }
                openInviteModal(res.link, res.token || '', patient ? patient.email || '' : '');
                showInviteAlert('success', patient && patient.name
                    ? `Enviar invitacion a ${patient.name}.`
                    : `Enviar invitacion al ${sectorLabel('patient', 'singular', 'paciente')}.`);
            } else {
                showAdminPatientsAlert('danger', res.error || 'No se pudo generar la invitacion.');
            }
        },
        error: function () {
            showAdminPatientsAlert('danger', 'Error de conexion al generar la invitacion.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function refreshPatientSelectOptions() {
    const $select = $('#patientSelect');
    if (!$select.length) {
        return;
    }
    const selected = $select.val();
    $select.html(`<option value="">Selecciona un ${sectorLabel('patient', 'singular', 'paciente')}...</option>`);
    ADMIN_BOOKING_PATIENTS.forEach(patient => {
        const professional = patient.professional_name ? ` · ${patient.professional_name}` : '';
        $select.append(`<option value="${patient.id}">${escapeHtml((patient.name || '') + professional)}</option>`);
    });
    if (selected) {
        $select.val(selected);
    }
}

function setBookingPatientsLoadingState(loading) {
    const $select = $('#patientSelect');
    if ($select.length) {
        if (loading) {
            $select.prop('disabled', true).html('<option value="">Cargando...</option>');
        } else {
            $select.prop('disabled', bookingContextLoading);
            refreshPatientSelectOptions();
        }
    }
    $('#booking-professional').prop('disabled', !!loading || bookingContextLoading);
    $('#btn-confirm-action').prop('disabled', !!loading || bookingContextLoading);
}

function setBookingModalLoadingState(loading, message = 'Cargando...') {
    bookingContextLoading = !!loading;
    const $modal = $('#appointmentModal');
    $modal.toggleClass('booking-modal-loading', bookingContextLoading);
    $('#patientSelect, #booking-professional, #service-option').prop('disabled', bookingContextLoading);
    $('#booking-professional-cards .patient-professional-card')
        .prop('disabled', bookingContextLoading)
        .toggleClass('is-loading', bookingContextLoading);
    $('#booking-consultation-cards .booking-consultation-card')
        .each(function () {
            const $card = $(this);
            $card.prop('disabled', bookingContextLoading || $card.hasClass('is-disabled'));
        })
        .toggleClass('is-loading', bookingContextLoading);
    $('#btn-confirm-action').prop('disabled', bookingContextLoading);
    if (bookingContextLoading && message) {
        const $service = $('#service-option');
        if ($service.length && !$service.hasClass('d-none')) {
            $service.html(`<option value="">${escapeHtml(message)}</option>`);
        }
    }
}

function loadBookingPatients(force = false) {
    if (!IS_ADMIN) {
        return $.Deferred().resolve().promise();
    }
    if (force) {
        bookingPatientsLoaded = false;
    }
    if (bookingPatientsLoaded) {
        return $.Deferred().resolve().promise();
    }
    if (bookingPatientsLoading) {
        return bookingPatientsRequest || $.Deferred().resolve().promise();
    }

    bookingPatientsLoading = true;
    const deferred = $.Deferred();
    bookingPatientsRequest = deferred.promise();

    $.ajax({
        url: 'api/admin.php?action=get_patients',
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                ADMIN_BOOKING_PATIENTS = Array.isArray(res.patients) ? res.patients : [];
                if (Array.isArray(res.professionals) && res.professionals.length) {
                    CABINET_PROFESSIONALS = res.professionals.map(professional => ({
                        ...professional,
                        is_active: typeof professional.is_active === 'undefined' || professional.is_active === null ? 1 : professional.is_active
                    }));
                }
                CURRENT_PROFESSIONAL_ID = parseInt(res.current_professional_id || CURRENT_PROFESSIONAL_ID || 0, 10);
                refreshPatientSelectOptions();
                populateBookingProfessionalSelect(CURRENT_PROFESSIONAL_ID);
                bookingPatientsLoaded = true;
                deferred.resolve(res);
                return;
            }
            deferred.reject(res);
        },
        error: function (xhr) {
            deferred.reject(xhr);
        },
        complete: function () {
            bookingPatientsLoading = false;
            bookingPatientsRequest = null;
        }
    });

    return bookingPatientsRequest;
}

function bookingPatientById(patientId) {
    return ADMIN_BOOKING_PATIENTS.find(patient => String(patient.id) === String(patientId)) || null;
}

function openPatientEditorById(patientId, button = null) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    patientId = parseInt(patientId || 0, 10);
    if (!patientId) return;
    const $button = button ? $(button) : $();
    const originalButtonHtml = $button.length ? $button.html() : '';
    if ($button.length) {
        $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    }
    const resetButton = function () {
        if ($button.length) {
            $button.prop('disabled', false).html(originalButtonHtml);
        }
    };
    let patient = ADMIN_PATIENTS.find(item => parseInt(item.id, 10) === patientId) || null;
    if (patient) {
        openPatientEditorModal(patient);
        resetButton();
        return;
    }

    $.ajax({
        url: 'api/admin.php?action=list_patients',
        dataType: 'json',
        data: IS_SUPERADMIN ? { professional_id: 'all' } : {},
        success: function (res) {
            if (!res.success) {
                alert(res.error || `No se pudo cargar la ficha del ${patientSingular}.`);
                return;
            }
            if (Array.isArray(res.professionals) && res.professionals.length) {
                CABINET_PROFESSIONALS = res.professionals.map(professional => ({
                    ...professional,
                    is_active: typeof professional.is_active === 'undefined' || professional.is_active === null ? 1 : professional.is_active
                }));
            }
            ADMIN_PATIENTS = Array.isArray(res.patients) ? res.patients : [];
            patient = ADMIN_PATIENTS.find(item => parseInt(item.id, 10) === patientId) || null;
            if (!patient) {
                alert(`No se encontró la ficha del ${patientSingular}.`);
                return;
            }
            openPatientEditorModal(patient);
        },
        error: function () {
            alert(`Error de conexión al cargar la ficha del ${patientSingular}.`);
        },
        complete: function () {
            resetButton();
        }
    });
}

function bookingProfessionalById(professionalId) {
    return CABINET_PROFESSIONALS.find(professional => String(professional.id) === String(professionalId)) || null;
}

function renderBookingProfessionalCards() {
    const $container = $('#booking-professional-cards');
    if (!$container.length) return;
    const selected = String($('#booking-professional').val() || CURRENT_PROFESSIONAL_ID || '');
    const cards = CABINET_PROFESSIONALS
        .filter(professional => professional.is_active != 0)
        .map(professional => {
            const id = String(professional.id);
            const name = escapeHtml(professional.display_name || 'Sin nombre');
            const selectedClass = id === selected ? 'is-selected' : '';
            const photo = professional.display_photo_path
                ? `<img class="patient-professional-card-avatar" src="${escapeHtml(assetUrl(professional.display_photo_path))}" alt="${name}">`
                : '<span class="patient-professional-card-avatar patient-professional-card-avatar-empty"><i class="bi bi-person"></i></span>';
            return `
                <button type="button" class="patient-professional-card ${selectedClass}" data-professional-id="${id}" aria-pressed="${id === selected ? 'true' : 'false'}">
                    ${photo}
                    <span class="patient-professional-card-body">
                        <span class="patient-professional-card-name">${name}</span>
                        ${professionalDeliveryPills(professional)}
                    </span>
                </button>
            `;
        }).join('');
    $container.html(cards || '<div class="text-muted small">No hay profesionales activos.</div>');
}

function populateBookingProfessionalSelect(selectedProfessionalId = null) {
    const $select = $('#booking-professional');
    if (!$select.length) return;
    const selected = selectedProfessionalId || $select.val() || CURRENT_PROFESSIONAL_ID;
    $select.html('<option value="">Selecciona un profesional...</option>');
    CABINET_PROFESSIONALS
        .filter(professional => professional.is_active != 0)
        .forEach(professional => {
            $select.append(`<option value="${professional.id}">${escapeHtml(professional.display_name || 'Sin nombre')}</option>`);
        });
    if (selected && $select.find(`option[value="${selected}"]`).length) {
        $select.val(String(selected));
    }
    renderBookingProfessionalCards();
}

function updateBookingPatientProfessionalNote(patient = null) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const $note = $('#booking-patient-professional-note');
    if (!$note.length) return;
    if (!patient) {
        $note.text('');
        return;
    }
    const professionalName = patient.professional_name || '';
    if (professionalName) {
        $note.text(`${capitalizeFirst(patientSingular)} de ${professionalName}. Puedes cambiar el profesional solo para esta cita.`);
    } else {
        const currentProfessional = bookingProfessionalById(CURRENT_PROFESSIONAL_ID);
        $note.text(`${capitalizeFirst(patientSingular)} sin profesional asignado. Se asignará por defecto a ${currentProfessional ? currentProfessional.display_name : 'tu usuario'}.`);
    }
}

function syncBookingProfessionalFromPatient() {
    if (!IS_SUPERADMIN) return;
    const patient = bookingPatientById($('#patientSelect').val());
    const selectedProfessionalId = patient && patient.professional_id
        ? parseInt(patient.professional_id, 10)
        : CURRENT_PROFESSIONAL_ID;
    populateBookingProfessionalSelect(selectedProfessionalId);
    updateBookingPatientProfessionalNote(patient);
    loadBookingContextForProfessional(selectedProfessionalId);
}

function loadBookingContextForProfessional(professionalId) {
    if (!IS_SUPERADMIN || !professionalId) {
        renderBookingServiceOptions();
        refreshBookingBonusNotice();
        return;
    }
    const $select = $('#service-option');
    setBookingModalLoadingState(true, 'Cargando servicios...');
    $.ajax({
        url: 'api/appointments.php?action=booking_context',
        method: 'GET',
        dataType: 'json',
        data: { professional_id: professionalId },
        success: function (res) {
            if (res.success) {
                PAYMENT_SETTINGS = res.payment_settings || PAYMENT_SETTINGS;
                ACTIVE_SERVICE_OPTIONS = Array.isArray(res.service_options) ? res.service_options : [];
                CURRENT_BOOKING_CONSULTATION_TYPE = '';
                renderBookingServiceOptions();
                refreshBookingBonusNotice();
            } else {
                $select.html('<option value="">No se pudo cargar la agenda del profesional</option>');
            }
        },
        error: function () {
            $select.html('<option value="">Error al cargar la agenda del profesional</option>');
        },
        complete: function () {
            setBookingModalLoadingState(false);
        }
    });
}

function showAdminPatientsAlert(type, message) {
    if (adminPatientsAlertTimer) {
        clearTimeout(adminPatientsAlertTimer);
        adminPatientsAlertTimer = null;
    }
    $('#admin-patients-alert')
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .text(message);
    if (type === 'success') {
        adminPatientsAlertTimer = setTimeout(function () {
            $('#admin-patients-alert').addClass('d-none').text('');
        }, 4000);
    }
}

function showPatientEditorAlert(type, message) {
    $('#patient-editor-alert')
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .text(message);
}

function showPatientHistoryAlert(type, message) {
    $('#patient-history-alert')
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .text(message);
}

function showPatientWorkPlanAlert(type, message, autoHide = false) {
    const $alert = $('#patient-work-plan-alert')
        .stop(true, true)
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .text(message)
        .show();
    if (autoHide || type === 'success') {
        $alert.delay(4000).fadeOut(200, function () {
            $(this).addClass('d-none').show().text('');
        });
    }
}

function showPatientEvolutionAlert(type, message) {
    $('#patient-evolution-alert')
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .text(message);
    if (type === 'success') {
        setTimeout(function () {
            $('#patient-evolution-alert').addClass('d-none').text('');
        }, 4000);
    }
}

function showPatientFilesAlert(type, message) {
    if (patientFilesAlertTimer) {
        clearTimeout(patientFilesAlertTimer);
    }
    $('#patient-files-alert')
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .text(message);
    patientFilesAlertTimer = setTimeout(function () {
        $('#patient-files-alert').addClass('d-none').text('');
    }, 3000);
}

function showPatientReportsAlert(type, message, autoHide = false) {
    if (patientReportsAlertTimer) {
        clearTimeout(patientReportsAlertTimer);
        patientReportsAlertTimer = null;
    }
    $('#patient-reports-alert')
        .removeClass('d-none alert-success alert-danger alert-warning')
        .addClass(type === 'success' ? 'alert-success' : (type === 'warning' ? 'alert-warning' : 'alert-danger'))
        .text(message);
    if (autoHide || type === 'success') {
        patientReportsAlertTimer = setTimeout(function () {
            $('#patient-reports-alert').addClass('d-none').text('');
            patientReportsAlertTimer = null;
        }, 3000);
    }
}

function openUpcomingAppointmentsModal() {
    if (!upcomingAppointmentsModal) return;
    $('#upcoming-appointments-alert').addClass('d-none').text('');
    $('#upcoming-appointments-search').val('');
    $('#cancelled-appointments-search').val('');
    $('#upcoming-appointments-scope').val('limit10');
    $('#upcoming-planning-scope').val('3days');
    upcomingAppointmentsInitialLoad = true;
    populateUpcomingProfessionalsFilter();
    $('#upcoming-appointments-body').html('<tr><td colspan="7" class="text-center text-muted py-4">Cargando...</td></tr>');
    $('#upcoming-appointments-count').text('');
    $('#cancelled-appointments-body').html('<tr><td colspan="7" class="text-center text-muted py-4">Cargando...</td></tr>');
    $('#cancelled-appointments-count').text('');
    $('#upcoming-planning-wrap').html('<div class="text-center text-muted py-4">Cargando planning...</div>');
    upcomingAppointmentsModal.show();
    loadUpcomingAppointments();
}

function loadUpcomingAppointments() {
    $('#upcoming-appointments-alert').addClass('d-none').text('');
    $('#upcoming-appointments-body').html('<tr><td colspan="7" class="text-center text-muted py-4">Cargando...</td></tr>');
    $('#upcoming-appointments-count').text('');
    $('#cancelled-appointments-body').html('<tr><td colspan="7" class="text-center text-muted py-4">Cargando...</td></tr>');
    $('#cancelled-appointments-count').text('');
    $('#upcoming-planning-wrap').html('<div class="text-center text-muted py-4">Cargando planning...</div>');
    const hasProfessionalFilter = $('#upcoming-appointments-professional').length > 0;
    const professionalFilterValue = hasProfessionalFilter && upcomingAppointmentsInitialLoad
        ? 'current'
        : ($('#upcoming-appointments-professional').val() || 'all');
    $.ajax({
        url: 'api/admin.php?action=upcoming_appointments',
        data: {
            scope: $('#upcoming-appointments-scope').val() || 'limit10',
            planning_scope: $('#upcoming-planning-scope').val() || '3days',
            professional_id: professionalFilterValue
        },
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                showUpcomingAppointmentsAlert('danger', res.error || 'No se pudieron cargar las citas.');
                return;
            }
            if (Array.isArray(res.professionals)) {
                populateUpcomingProfessionalsFilter(res.professionals, upcomingAppointmentsInitialLoad ? res.current_professional_id : null);
            }
            upcomingAppointmentsInitialLoad = false;
            CURRENT_UPCOMING_APPOINTMENTS = Array.isArray(res.appointments) ? res.appointments : [];
            CURRENT_CANCELLED_APPOINTMENTS = Array.isArray(res.cancelled_appointments) ? res.cancelled_appointments : [];
            CURRENT_UPCOMING_PLANNING = {
                appointments: Array.isArray(res.planning_appointments) ? res.planning_appointments : [],
                settings: res.planning_settings || {},
                closedDays: Array.isArray(res.closed_days) ? res.closed_days : []
            };
            renderUpcomingAppointments(CURRENT_UPCOMING_APPOINTMENTS);
            renderCancelledAppointments(CURRENT_CANCELLED_APPOINTMENTS);
            renderUpcomingPlanning();
        },
        error: function () {
            showUpcomingAppointmentsAlert('danger', 'Error de conexion al cargar las citas.');
        }
    });
}

function populateUpcomingProfessionalsFilter(professionals = null, preferredProfessionalId = null) {
    const $select = $('#upcoming-appointments-professional');
    if (!$select.length) return;
    const selected = $select.val() || '';
    $select.html('<option value="all">Todos los profesionales</option>');
    const source = Array.isArray(professionals) ? professionals : CABINET_PROFESSIONALS;
    source
        .filter(professional => professional.is_active != 0)
        .forEach(professional => {
            $select.append(`<option value="${professional.id}">${escapeHtml(professional.display_name || 'Sin nombre')}</option>`);
        });
    const preferred = preferredProfessionalId ? String(preferredProfessionalId) : '';
    if (preferred && $select.find(`option[value="${preferred}"]`).length) {
        $select.val(preferred);
    } else if (selected && $select.find(`option[value="${selected}"]`).length) {
        $select.val(selected);
    }
}

function renderUpcomingAppointments(appointments) {
    const rows = filterUpcomingAppointments(appointments);
    if (!appointments.length) {
        $('#upcoming-appointments-body').html('<tr><td colspan="7" class="text-center text-muted py-4">No hay citas pr&oacute;ximas.</td></tr>');
        $('#upcoming-appointments-count').text('');
        return;
    }
    if (!rows.length) {
        $('#upcoming-appointments-body').html('<tr><td colspan="7" class="text-center text-muted py-4">No hay citas que coincidan con la busqueda.</td></tr>');
        $('#upcoming-appointments-count').text('');
        return;
    }

    let html = '';
    rows.forEach(app => {
        const professional = upcomingProfessionalCell(app);
        html += `
            <tr class="upcoming-appointment-row" data-appointment-id="${app.id || ''}">
                <td><strong>${formatDisplayDate(app.appointment_date)}</strong><br><small class="text-muted">${escapeHtml(displayAppointmentTimeRange(app.appointment_time || '', app.duration_minutes || 60))}</small></td>
                <td>${professional}</td>
                <td>${escapeHtml(app.patient_name || '')}<br><small class="text-muted">${patientContactSummaryHtml(app.patient_email, app.patient_phone) || '-'}</small></td>
                <td>${escapeHtml(displayAppointmentServiceLabel(app))}</td>
                <td>${consultationTypeLabel(app.consultation_type)}</td>
                <td>${adminPaymentLabel(app)}</td>
                <td class="text-end no-export">${appointmentPaymentButton(app)}</td>
            </tr>
        `;
    });
    $('#upcoming-appointments-body').html(html);
    $('#upcoming-appointments-count').text(`${rows.length} ${rows.length === 1 ? 'cita' : 'citas'}`);
}

function renderCancelledAppointments(appointments) {
    const rows = filterCancelledAppointments(appointments);
    if (!appointments.length) {
        $('#cancelled-appointments-body').html('<tr><td colspan="7" class="text-center text-muted py-4">No hay citas canceladas.</td></tr>');
        $('#cancelled-appointments-count').text('');
        return;
    }
    if (!rows.length) {
        $('#cancelled-appointments-body').html('<tr><td colspan="7" class="text-center text-muted py-4">No hay citas canceladas que coincidan con la busqueda.</td></tr>');
        $('#cancelled-appointments-count').text('');
        return;
    }

    const html = rows.map(app => {
        const professional = upcomingProfessionalCell(app);
        const cancelledAt = app.cancelled_at ? formatDateTimeLabel(app.cancelled_at) : '-';
        return `
            <tr>
                <td><strong>${formatDisplayDate(app.appointment_date)}</strong><br><small class="text-muted">${escapeHtml(displayAppointmentTimeRange(app.appointment_time || '', app.duration_minutes || 60))}</small></td>
                <td>${escapeHtml(cancelledAt)}</td>
                <td>${professional}</td>
                <td>${escapeHtml(app.patient_name || '')}<br><small class="text-muted">${patientContactSummaryHtml(app.patient_email, app.patient_phone) || '-'}</small></td>
                <td>${escapeHtml(displayAppointmentServiceLabel(app))}</td>
                <td>${consultationTypeLabel(app.consultation_type)}</td>
                <td>${adminPaymentLabel(app)}</td>
            </tr>
        `;
    }).join('');

    $('#cancelled-appointments-body').html(html);
    $('#cancelled-appointments-count').text(`${rows.length} ${rows.length === 1 ? 'cita cancelada' : 'citas canceladas'}`);
}

function upcomingProfessionalCell(app = {}) {
    return professionalCellHtml(app, 'professional_name', 'professional_photo_path');
}

function parseDateKey(dateStr) {
    const parts = String(dateStr || '').split('-').map(part => parseInt(part, 10));
    if (parts.length !== 3 || parts.some(part => !Number.isFinite(part))) {
        return new Date();
    }
    return new Date(parts[0], parts[1] - 1, parts[2]);
}

function addDays(date, days) {
    const copy = new Date(date);
    copy.setDate(copy.getDate() + days);
    return copy;
}

function upcomingPlanningDays(settings = {}) {
    const total = Math.max(1, parseInt(settings.days || 7, 10));
    const startOffset = Math.max(0, parseInt(settings.start_offset || 0, 10));
    const today = addDays(parseDateKey(formatDate(new Date())), startOffset);
    const days = [];
    for (let i = 0; i < total; i++) {
        const date = addDays(today, i);
        days.push({
            date,
            key: formatDate(date),
            weekday: ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'][date.getDay()],
            label: `${String(date.getDate()).padStart(2, '0')}/${String(date.getMonth() + 1).padStart(2, '0')}`
        });
    }
    return days;
}

function upcomingPlanningActiveWeekdays(settings = {}) {
    const raw = String(settings.available_weekdays || '1,2,3,4,5');
    const days = raw.split(',')
        .map(day => parseInt(day, 10))
        .filter(day => day >= 1 && day <= 6);
    return days.length ? [...new Set(days)] : [1, 2, 3, 4, 5];
}

function upcomingPlanningClosedMap(closedDays = []) {
    const map = {};
    closedDays.forEach(day => {
        if (!day || !day.date) return;
        if (!map[day.date]) {
            map[day.date] = [];
        }
        map[day.date].push(day);
    });
    return map;
}

function upcomingPlanningSlots(settings = {}, appointments = []) {
    let start = timeToMinutes(settings.appointment_start_time || '10:00');
    let end = timeToMinutes(settings.appointment_end_time || '19:00');
    appointments.forEach(app => {
        const appStart = timeToMinutes(app.appointment_time || '');
        if (appStart > 0) {
            start = Math.min(start, appStart);
            end = Math.max(end, appStart);
        }
    });
    const slotStep = activeScheduleSlotStep(settings);
    start = Math.floor(start / slotStep) * slotStep;
    end = Math.ceil(end / slotStep) * slotStep;
    const slots = [];
    for (let minutes = start; minutes <= end; minutes += slotStep) {
        slots.push(minutesToTime(minutes));
    }
    return slots;
}

function upcomingPlanningAppointmentAt(dayAppointments, time) {
    const slotStart = timeToMinutes(time);
    let continuation = null;
    for (const app of dayAppointments) {
        const appStart = timeToMinutes(app.appointment_time || '');
        const duration = Math.max(60, parseInt(app.duration_minutes || 60, 10));
        const appEnd = appStart + duration;
        if (appStart === slotStart) {
            return { app, continuation: false };
        }
        if (appStart < slotStart && appEnd > slotStart) {
            continuation = { app, continuation: true };
        }
    }
    return continuation;
}

function renderUpcomingPlanning() {
    const planning = CURRENT_UPCOMING_PLANNING || {};
    const appointments = Array.isArray(planning.appointments) ? planning.appointments : [];
    const settings = planning.settings || {};
    const days = upcomingPlanningDays(settings);
    const slots = upcomingPlanningSlots(settings, appointments);
    const activeWeekdays = upcomingPlanningActiveWeekdays(settings);
    const closedMap = upcomingPlanningClosedMap(planning.closedDays || []);
    const appointmentsByDay = {};
    appointments.forEach(app => {
        const date = app.appointment_date || '';
        if (!appointmentsByDay[date]) {
            appointmentsByDay[date] = [];
        }
        appointmentsByDay[date].push(app);
    });

    if (!settings.professional_id) {
        $('#upcoming-planning-wrap').html('<div class="text-center text-muted py-4">No hay profesional activo para generar el planning.</div>');
        return;
    }

    let html = '<div class="table-responsive upcoming-planning-table-wrap"><table class="table upcoming-planning-table"><thead><tr><th class="planning-hour-head">Hora</th>';
    days.forEach(day => {
        html += `<th><span>${day.weekday}</span><strong>${day.label}</strong></th>`;
    });
    html += '</tr></thead><tbody>';

    const hasBreak = settings.break_start_time && settings.break_end_time;
    const breakStart = hasBreak ? timeToMinutes(settings.break_start_time) : null;
    const breakEnd = hasBreak ? timeToMinutes(settings.break_end_time) : null;

    slots.forEach(time => {
        const slotMinutes = timeToMinutes(time);
        html += `<tr><th class="planning-hour">${time}</th>`;
        days.forEach(day => {
            const dayNumber = day.date.getDay() === 0 ? 7 : day.date.getDay();
            const isClosed = Boolean(closedMap[day.key] && closedMap[day.key].length);
            const isAvailableWeekday = activeWeekdays.includes(dayNumber);
            const isBreak = hasBreak && slotMinutes >= breakStart && slotMinutes < breakEnd;
            const appointmentHit = upcomingPlanningAppointmentAt(appointmentsByDay[day.key] || [], time);

            if (appointmentHit) {
                const app = appointmentHit.app;
                const continuationClass = appointmentHit.continuation ? ' planning-cell-booked-continuation' : '';
                const continuationText = appointmentHit.continuation ? '<small>continúa</small>' : '';
                html += `
                    <td class="planning-cell planning-cell-booked${continuationClass}">
                        <div class="planning-appointment">
                            <strong>${escapeHtml(app.patient_name || '')}</strong>
                            ${continuationText}
                            <span>${escapeHtml(displayAppointmentServiceLabel(app))} · ${displayAppointmentDurationLabel(app.duration_minutes || 60)}</span>
                            <span>${consultationTypeLabel(app.consultation_type)} · ${adminPaymentLabel(app)}</span>
                        </div>
                    </td>
                `;
            } else if (isClosed) {
                html += '<td class="planning-cell planning-cell-closed">Cierre</td>';
            } else if (!isAvailableWeekday) {
                html += '<td class="planning-cell planning-cell-unavailable">No disp.</td>';
            } else if (isBreak) {
                html += '<td class="planning-cell planning-cell-break">Descanso</td>';
            } else {
                html += '<td class="planning-cell planning-cell-free">Libre</td>';
            }
        });
        html += '</tr>';
    });

    html += '</tbody></table></div>';
    $('#upcoming-planning-wrap').html(html);
}

function professionalCellHtml(source = {}, nameKey = 'professional_name', photoKey = 'professional_photo_path') {
    const photo = source[photoKey] || '';
    const avatar = photo
        ? `<img src="${escapeHtml(assetUrl(photo))}" alt="" class="upcoming-professional-avatar">`
        : '<span class="upcoming-professional-avatar upcoming-professional-avatar-empty"><i class="bi bi-person"></i></span>';
    return `
        <div class="upcoming-professional-cell">
            ${avatar}
            <span>${escapeHtml(source[nameKey] || 'Sin asignar')}</span>
        </div>
    `;
}

function filterUpcomingAppointments(appointments) {
    const search = ($('#upcoming-appointments-search').val() || '').trim().toLowerCase();
    let rows = Array.isArray(appointments) ? [...appointments] : [];
    if (search) {
        rows = rows.filter(app => {
            const haystack = [
                app.appointment_date,
                app.appointment_time,
                app.patient_name,
                app.patient_email,
                app.patient_phone,
                app.professional_name,
                app.service_label,
                consultationTypeLabel(app.consultation_type),
                app.payment_status,
                app.payment_method
            ].join(' ').toLowerCase();
            return haystack.includes(search);
        });
    }
    rows.sort((a, b) => {
        const aKey = `${a.appointment_date || ''} ${a.appointment_time || ''}`;
        const bKey = `${b.appointment_date || ''} ${b.appointment_time || ''}`;
        return aKey.localeCompare(bKey);
    });
    return rows;
}

function filterCancelledAppointments(appointments) {
    const search = ($('#cancelled-appointments-search').val() || '').trim().toLowerCase();
    let rows = Array.isArray(appointments) ? [...appointments] : [];
    if (search) {
        rows = rows.filter(app => {
            const haystack = [
                app.appointment_date,
                app.appointment_time,
                app.cancelled_at,
                app.patient_name,
                app.patient_email,
                app.patient_phone,
                app.professional_name,
                app.service_label,
                consultationTypeLabel(app.consultation_type),
                app.payment_status,
                app.payment_method
            ].join(' ').toLowerCase();
            return haystack.includes(search);
        });
    }
    rows.sort((a, b) => {
        const aKey = `${a.cancelled_at || ''} ${a.appointment_date || ''} ${a.appointment_time || ''}`;
        const bKey = `${b.cancelled_at || ''} ${b.appointment_date || ''} ${b.appointment_time || ''}`;
        return bKey.localeCompare(aKey);
    });
    return rows;
}

function adminPaymentLabel(app) {
    if (app.payment_status === 'paid') {
        return app.payment_method === 'bonus'
            ? '<span class="badge text-bg-success">Bono</span>'
            : '<span class="badge text-bg-success">Pagado</span>';
    }
    if (app.payment_status === 'failed') {
        return '<span class="badge text-bg-danger">Fallido</span>';
    }
    return '<span class="badge text-bg-warning">Pendiente de pago</span>';
}

function paymentMethodLabel(method) {
    const labels = {
        card: 'Tarjeta online',
        bizum: 'Bizum online',
        bonus: 'Bono',
        cash: 'Efectivo',
        bank_transfer: 'Transferencia',
        other: 'Otro método',
        manual: 'Manual'
    };
    return labels[method] || '';
}

function appointmentPaymentButton(app = {}) {
    if (!IS_ADMIN || !app.id) {
        return '';
    }
    return `
        <button class="btn btn-outline-primary btn-sm" type="button" onclick="openAppointmentPaymentModal(${parseInt(app.id, 10)})" title="Ver detalles">
            <i class="bi bi-eye"></i>
        </button>
    `;
}

function showAppointmentPaymentAlert(type, message) {
    const $alert = $('#appointment-payment-alert')
        .removeClass('d-none alert-success alert-danger alert-warning')
        .addClass(type === 'success' ? 'alert-success' : (type === 'warning' ? 'alert-warning' : 'alert-danger'))
        .text(message);
    if (appointmentPaymentAlertTimer) {
        clearTimeout(appointmentPaymentAlertTimer);
        appointmentPaymentAlertTimer = null;
    }
    if (type === 'success') {
        appointmentPaymentAlertTimer = setTimeout(function () {
            $alert.addClass('d-none').text('');
            appointmentPaymentAlertTimer = null;
        }, 3000);
    }
}

function showAppointmentModalityAlert(type, message) {
    if ($('#appointment-detail-panel').hasClass('active')) {
        showAppointmentPaymentAlert(type, message);
    } else {
        showAppointmentSessionAlert(type, message);
    }
}

function setAppointmentPaymentLoading(isLoading) {
    const $button = $('#btn-save-appointment-payment');
    if (!$button.data('original-text')) {
        $button.data('original-text', $button.html());
    }
    $button.prop('disabled', isLoading);
    if (isLoading) {
        $button.html('<span class="spinner-border spinner-border-sm me-2"></span>Guardando');
    } else {
        $button.html($button.data('original-text'));
    }
}

function setAppointmentPaymentSelection(status, method = '') {
    const normalizedStatus = status === 'paid' ? 'paid' : 'pending';
    $('.payment-state-card').removeClass('active');
    $(`.payment-state-card[data-payment-status="${normalizedStatus}"]`).addClass('active');
    $('#appointment-payment-method-wrap').toggleClass('d-none', normalizedStatus !== 'paid');
    if (normalizedStatus === 'paid') {
        $('#appointment-payment-method').val(method && method !== 'bonus' ? method : 'cash');
    }
}

function renderAppointmentPaymentSummary(app = {}) {
    const patientTitleSingular = sectorLabel('patient', 'titleSingular', 'Paciente');
    const contact = patientContactSummaryHtml(app.patient_email, app.patient_phone);
    const paymentMethod = paymentMethodLabel(app.payment_method);
    const paidText = app.payment_status === 'paid'
        ? (app.payment_method === 'bonus' ? 'Pagada con bono' : `Pagada${paymentMethod ? ` · ${paymentMethod}` : ''}`)
        : 'Pendiente de pago';
    const statusClass = app.payment_status === 'paid' ? 'appointment-payment-status-paid' : 'appointment-payment-status-pending';
    return `
        <div class="appointment-payment-card">
            <div class="appointment-payment-main">
                <div>
                    <span class="text-muted small">${escapeHtml(patientTitleSingular)}</span>
                    <h6>${escapeHtml(app.patient_name || '')}</h6>
                    ${contact ? `<small class="text-muted">${contact}</small>` : ''}
                </div>
                <span class="appointment-payment-status ${statusClass}">${escapeHtml(paidText)}</span>
            </div>
            <div class="appointment-payment-grid mt-3">
                <div><span>Fecha</span><strong>${formatDisplayDate(app.appointment_date || '')} · ${escapeHtml(displayAppointmentTimeRange(app.appointment_time || '', app.duration_minutes || 60))}</strong></div>
                <div><span>Profesional</span><strong>${escapeHtml(app.professional_name || 'Sin asignar')}</strong></div>
                <div><span>Servicio</span><strong>${escapeHtml(displayAppointmentServiceLabel(app))}</strong></div>
            </div>
            ${renderAppointmentKnowledgeProblemCard(app)}
            <div class="mt-3">${renderAppointmentModalitySessionCard(app)}</div>
        </div>
    `;
}

function renderAppointmentKnowledgeProblemCard(app = {}) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const problem = app.knowledge_problem || {};
    if (!problem.enabled) {
        return '';
    }

    const diagnosisLabel = capitalizeFirst(sectorText('clinicalTerms.diagnosis', 'diagnostico'));
    const problemTitle = problem.status === 'assigned'
        ? (problem.name || problem.short_name || diagnosisLabel)
        : `${diagnosisLabel} pendiente`;
    const meta = [];
    if (problem.area) meta.push(problem.area);
    if (problem.category) meta.push(problem.category);
    if (problem.risk_level) meta.push(`Riesgo: ${problem.risk_level}`);
    if (problem.target_population) meta.push(problem.target_population);

    return `
        <div class="appointment-knowledge-card mt-3">
            <div>
                <span>${escapeHtml(diagnosisLabel)}</span>
                <strong>${escapeHtml(problemTitle)}</strong>
                ${problem.status === 'assigned' && meta.length ? `<small>${escapeHtml(meta.join(' · '))}</small>` : ''}
                ${problem.status !== 'assigned' ? `<small>Este ${escapeHtml(patientSingular)} todavia no tiene un problema u objetivo vinculado en su ficha.</small>` : ''}
            </div>
        </div>
    `;
}

function appointmentModalitySwitchTarget(app = {}) {
    const current = app.consultation_type === 'online' ? 'online' : 'presencial';
    const target = current === 'online' ? 'presencial' : 'online';
    if (target === 'online' && app.can_online_appointment != 1) {
        return '';
    }
    if (target === 'presencial' && app.can_presential_appointment == 0) {
        return '';
    }
    return target;
}

function renderAppointmentModalityInline(app = {}) {
    const current = app.consultation_type === 'online' ? 'online' : 'presencial';
    const target = appointmentModalitySwitchTarget(app);
    return `
        <span>Modalidad</span>
        <div class="appointment-modality-row">
            <strong>${consultationTypeLabel(current)}</strong>
            ${target ? `
                <button class="btn btn-outline-primary btn-sm btn-switch-appointment-modality" type="button" data-target-modality="${target}">
                    <i class="bi bi-arrow-left-right"></i> Cambiar a ${consultationTypeLabel(target).toLowerCase()}
                </button>
            ` : ''}
        </div>
    `;
}

function renderAppointmentModalitySessionCard(app = {}) {
    const current = app.consultation_type === 'online' ? 'online' : 'presencial';
    const onlineUrl = app.online_session_url || '';
    const target = appointmentModalitySwitchTarget(app);
    return `
        <section class="appointment-session-box appointment-online-session-box">
            <div class="appointment-modality-session-main">
                <div>
                    <span>Modalidad actual</span>
                    <strong>${consultationTypeLabel(current)}</strong>
                </div>
                ${target ? `
                    <button class="btn btn-outline-primary btn-sm btn-switch-appointment-modality" type="button" data-target-modality="${target}">
                        <i class="bi bi-arrow-left-right"></i> Cambiar a ${consultationTypeLabel(target).toLowerCase()}
                    </button>
                ` : ''}
            </div>
            <div class="appointment-online-link-wrap ${current === 'online' ? '' : 'd-none'} mt-3">
                <label class="form-label" for="appointment-online-session-url">Link de la sesi&oacute;n online</label>
                <div class="input-group">
                    <input type="url" class="form-control" id="appointment-online-session-url" value="${escapeHtml(onlineUrl)}" placeholder="https://...">
                    <button class="btn btn-outline-primary" type="button" id="btn-send-appointment-online-link" ${onlineUrl ? '' : 'disabled'}>
                        <i class="bi bi-send"></i> Enviar al ${escapeHtml(sectorLabel('patient', 'singular', 'paciente'))}
                    </button>
                </div>
            </div>
        </section>
    `;
}
function resetAppointmentSessionPanel(message = 'Cargando sesión...') {
    CURRENT_APPOINTMENT_SESSION = {
        appointment_id: 0,
        patient_id: 0,
        tasks: [],
        notes: [],
        files: []
    };
    $('#appointment-session-alert, #appointment-files-alert').addClass('d-none').text('');
    $('#appointment-session-content').html(`<div class="text-center text-muted py-4">${message}</div>`);
    $('#appointment-files-content').html('<div class="text-center text-muted py-4">Cargando archivos...</div>');
    $('#appointment-payment-modal-title').text('Detalle de la cita');
    $('#appointment-session-tab').text('Tareas');
}

function loadAppointmentSession(app = CURRENT_APPOINTMENT_PAYMENT_DETAIL) {
    const appointmentId = parseInt(app && app.id ? app.id : 0, 10);
    if (!appointmentId) {
        resetAppointmentSessionPanel('No se ha podido cargar la sesión.');
        return;
    }
    $('#appointment-session-alert, #appointment-files-alert').addClass('d-none').text('');
    $('#appointment-session-content').html('<div class="text-center text-muted py-4">Cargando sesión...</div>');
    $('#appointment-files-content').html('<div class="text-center text-muted py-4">Cargando archivos...</div>');
    $.ajax({
        url: 'api/admin.php?action=appointment_session',
        dataType: 'json',
        data: { appointment_id: appointmentId },
        success: function (res) {
            if (!res.success) {
                showAppointmentSessionAlert('danger', res.error || 'No se pudo cargar la sesión.');
                $('#appointment-session-content').html('<div class="text-center text-muted py-4">No se pudo cargar la sesión.</div>');
                return;
            }
            CURRENT_APPOINTMENT_SESSION = {
                appointment_id: parseInt(res.appointment_id || appointmentId, 10),
                patient_id: parseInt(res.patient_id || app.patient_id || 0, 10),
                tasks: Array.isArray(res.tasks) ? res.tasks : [],
                notes: Array.isArray(res.notes) ? res.notes : [],
                files: Array.isArray(res.files) ? res.files : []
            };
            renderAppointmentSession(app);
        },
        error: function () {
            showAppointmentSessionAlert('danger', 'Error de conexión al cargar la sesión.');
            $('#appointment-session-content').html('<div class="text-center text-muted py-4">No se pudo cargar la sesión.</div>');
        }
    });
}

function updateAppointmentSessionTabLabel(app = {}) {
    const $title = $('#appointment-payment-modal-title');
    $('#appointment-session-tab').text('Tareas');
    if (!$title.length) return;
    const start = parseAppointmentDateTime(app.appointment_date, app.appointment_time);
    const end = parseAppointmentDateTime(app.appointment_date, app.appointment_time);
    const duration = parseInt(app.duration_minutes || 60, 10);
    if (end) {
        end.setMinutes(end.getMinutes() + duration);
    }
    const now = new Date();
    const isCurrent = start && end && start <= now && end >= now;
    $title.text(isCurrent ? 'Sesión en curso' : 'Preparar esta sesión');
}

function parseAppointmentDateTime(dateStr, timeStr) {
    if (!dateStr || !timeStr) return null;
    const normalizedTime = String(timeStr).slice(0, 5);
    const value = new Date(`${dateStr}T${normalizedTime}:00`);
    return Number.isNaN(value.getTime()) ? null : value;
}

function renderAppointmentSession(app = CURRENT_APPOINTMENT_PAYMENT_DETAIL) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    $('#appointment-session-content').html(`
        <div class="row g-3">
            <div class="col-lg-5">
                <section class="appointment-session-box">
                    <h6>Nueva tarea de esta sesión</h6>
                    <form id="appointment-session-task-form">
                        <input type="hidden" name="appointment_id" value="${CURRENT_APPOINTMENT_SESSION.appointment_id}">
                        <input type="hidden" name="patient_id" value="${CURRENT_APPOINTMENT_SESSION.patient_id}">
                        <div class="mb-2">
                            <label class="form-label" for="appointment-session-task-title">Título</label>
                            <input type="text" class="form-control" id="appointment-session-task-title" name="title" maxlength="180" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="appointment-session-task-priority">Prioridad</label>
                            <select class="form-select" id="appointment-session-task-priority" name="priority">
                                <option value="1">Alta</option>
                                <option value="2" selected>Normal</option>
                                <option value="3">Baja</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="appointment-session-task-description">Descripción / actividad</label>
                            <textarea class="form-control" id="appointment-session-task-description" name="description" rows="3"></textarea>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="appointment-session-task-completed" name="completed">
                            <label class="form-check-label" for="appointment-session-task-completed">Marcar como completada</label>
                        </div>
                        <button class="btn btn-primary btn-sm" type="submit" id="btn-save-appointment-session-task">
                            <i class="bi bi-plus-lg"></i> Guardar tarea
                        </button>
                    </form>
                </section>
            </div>
            <div class="col-lg-7">
                <section class="appointment-session-box">
                    <h6>Tareas de esta sesión</h6>
                    <div id="appointment-session-tasks">${renderAppointmentSessionTasks(CURRENT_APPOINTMENT_SESSION.tasks)}</div>
                </section>
            </div>
            <div class="col-12">
                <section class="appointment-session-box">
                    <h6>Nueva nota / archivo de sesión</h6>
                    <form id="appointment-session-note-form" enctype="multipart/form-data">
                        <input type="hidden" name="note_id" value="0">
                        <input type="hidden" name="appointment_id" value="${CURRENT_APPOINTMENT_SESSION.appointment_id}">
                        <input type="hidden" name="patient_id" value="${CURRENT_APPOINTMENT_SESSION.patient_id}">
                        <input type="hidden" name="note_date" value="${escapeHtml(appointmentDate)}">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="appointment-session-note-title">Título</label>
                                <input type="text" class="form-control" id="appointment-session-note-title" name="title" maxlength="180" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="appointment-session-note-files">Archivos</label>
                                <input type="file" class="form-control" id="appointment-session-note-files" name="evolution_files[]" accept=".pdf,.xls,.xlsx,image/jpeg,image/png,image/webp,image/gif" multiple>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="appointment-session-note-description">Descripción</label>
                                <textarea class="form-control" id="appointment-session-note-description" name="description" rows="3"></textarea>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="appointment-session-note-observations">Observaciones</label>
                                <textarea class="form-control" id="appointment-session-note-observations" name="observations" rows="3"></textarea>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="appointment-session-note-next">Pendientes</label>
                                <textarea class="form-control" id="appointment-session-note-next" name="next_steps" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="text-end mt-3">
                            <button class="btn btn-primary btn-sm" type="submit" id="btn-save-appointment-session-note">
                                <i class="bi bi-journal-plus"></i> Guardar nota
                            </button>
                        </div>
                    </form>
                </section>
            </div>
            <div class="col-lg-7">
                <section class="appointment-session-box">
                    <h6>Notas de esta sesión</h6>
                    <div id="appointment-session-notes">${renderAppointmentSessionNotes(CURRENT_APPOINTMENT_SESSION.notes)}</div>
                </section>
            </div>
            <div class="col-lg-5">
                <section class="appointment-session-box">
                    <h6>Archivos de esta sesión</h6>
                    <div id="appointment-session-files">${renderAppointmentSessionFiles(CURRENT_APPOINTMENT_SESSION.files)}</div>
                </section>
            </div>
        </div>
    `);
}

function renderAppointmentSessionTasks(tasks) {
    const rows = Array.isArray(tasks) ? tasks : [];
    if (!rows.length) {
        return '<div class="text-center text-muted py-3">No hay tareas vinculadas a esta cita.</div>';
    }
    return rows.map(task => {
        const completed = task.status === 'completed';
        const priority = workPlanPriorityLabel(task.priority);
        return `
            <div class="appointment-session-item ${completed ? 'is-completed' : ''}">
                <div class="d-flex justify-content-between gap-2">
                    <div>
                        <div class="patient-work-plan-badges mb-1">
                            <span class="badge ${priority.className}">${priority.label}</span>
                            ${completed ? '<span class="badge text-bg-success">Completada</span>' : '<span class="badge text-bg-warning">Pendiente</span>'}
                        </div>
                        <strong>${escapeHtml(task.title || '')}</strong>
                        ${task.description ? `<div class="small text-muted mt-1">${escapeHtml(task.description)}</div>` : ''}
                    </div>
                    <div class="appointment-session-actions">
                        <button class="btn btn-outline-success btn-sm btn-toggle-session-task" type="button" data-task-id="${task.id}" data-next-status="${completed ? 'pending' : 'completed'}" title="${completed ? 'Marcar pendiente' : 'Completar'}">
                            <i class="bi ${completed ? 'bi-arrow-counterclockwise' : 'bi-check2'}"></i>
                        </button>
                        <button class="btn btn-outline-danger btn-sm btn-delete-session-task" type="button" data-task-id="${task.id}" title="Eliminar">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function renderAppointmentSessionNotes(notes) {
    const rows = Array.isArray(notes) ? notes : [];
    if (!rows.length) {
        return '<div class="text-center text-muted py-3">No hay notas vinculadas a esta cita.</div>';
    }
    return rows.map(note => `
        <div class="appointment-session-item">
            <div class="d-flex justify-content-between align-items-start">
                <strong>${escapeHtml(note.title || '')}</strong>
                <small class="text-muted">${formatDisplayDate(note.note_date || '')}</small>
            </div>
            ${note.description ? `<div class="mt-2">${escapeHtml(note.description)}</div>` : ''}
            ${note.observations ? `<div class="small text-muted mt-2"><strong>Observaciones:</strong> ${escapeHtml(note.observations)}</div>` : ''}
            ${note.next_steps ? `<div class="small text-muted"><strong>Pendientes:</strong> ${escapeHtml(note.next_steps)}</div>` : ''}
            ${parseInt(note.file_count || 0, 10) > 0 ? `<span class="badge text-bg-secondary mt-2">${parseInt(note.file_count || 0, 10)} archivo${parseInt(note.file_count || 0, 10) === 1 ? '' : 's'}</span>` : ''}
        </div>
    `).join('');
}

function renderAppointmentSessionFiles(files) {
    const rows = Array.isArray(files) ? files : [];
    if (!rows.length) {
        return '<div class="text-center text-muted py-3">No hay archivos vinculados a esta cita.</div>';
    }
    return rows.map(file => `
        <div class="appointment-session-file">
            <div>
                <strong>${escapeHtml(file.name || 'Archivo')}</strong>
                <div class="small text-muted">${file.size ? formatFileSize(file.size) : ''}${file.date ? ` · ${formatDateTimeLabel(file.date)}` : ''}</div>
            </div>
            <a class="btn btn-outline-primary btn-sm" href="${escapeHtml(file.url || '#')}" target="_blank" rel="noopener" title="Descargar">
                <i class="bi bi-download"></i>
            </a>
        </div>
    `).join('');
}

function renderAppointmentSession(app = CURRENT_APPOINTMENT_PAYMENT_DETAIL) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const appointmentDate = app && app.appointment_date ? app.appointment_date : formatDate(new Date());
    $('#appointment-session-content').html(`
        <div class="row g-3">
            <div class="col-12">
                <section class="appointment-session-box">
                    <h6>Tareas del ${escapeHtml(patientSingular)}</h6>
                    <div id="appointment-session-tasks">${renderAppointmentSessionTasks(CURRENT_APPOINTMENT_SESSION.tasks)}</div>
                </section>
            </div>
            <div class="col-12">
                <section class="appointment-session-box">
                    <h6>Nueva nota / archivo de sesi&oacute;n</h6>
                    <form id="appointment-session-note-form" enctype="multipart/form-data">
                        <input type="hidden" name="note_id" value="0">
                        <input type="hidden" name="appointment_id" value="${CURRENT_APPOINTMENT_SESSION.appointment_id}">
                        <input type="hidden" name="patient_id" value="${CURRENT_APPOINTMENT_SESSION.patient_id}">
                        <input type="hidden" name="note_date" value="${escapeHtml(appointmentDate)}">
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
                                <textarea class="form-control" id="appointment-session-note-description" name="description" rows="4"></textarea>
                            </div>
                        </div>
                        <div class="text-end mt-3">
                            <button class="btn btn-primary btn-sm" type="submit" id="btn-save-appointment-session-note">
                                <i class="bi bi-journal-plus"></i> Guardar nota
                            </button>
                        </div>
                    </form>
                </section>
            </div>
            <div class="col-lg-7">
                <section class="appointment-session-box">
                    <h6>Notas de esta sesi&oacute;n</h6>
                    <div id="appointment-session-notes">${renderAppointmentSessionNotes(CURRENT_APPOINTMENT_SESSION.notes)}</div>
                </section>
            </div>
            <div class="col-lg-5">
                <section class="appointment-session-box">
                    <h6>Archivos de esta sesi&oacute;n</h6>
                    <div id="appointment-session-files">${renderAppointmentSessionFiles(CURRENT_APPOINTMENT_SESSION.files)}</div>
                </section>
            </div>
        </div>
    `);
}

function renderAppointmentSessionTasks(tasks) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const rows = Array.isArray(tasks) ? tasks : [];
    if (!rows.length) {
        return `<div class="text-center text-muted py-3">No hay tareas pendientes o completadas en el plan del ${escapeHtml(patientSingular)}.</div>`;
    }
    return rows.map(task => {
        const completed = task.status === 'completed';
        const priority = workPlanPriorityLabel(task.priority);
        return `
            <div class="appointment-session-item ${completed ? 'is-completed' : ''}">
                <div class="d-flex justify-content-between gap-2">
                    <div>
                        <div class="patient-work-plan-badges mb-1">
                            <span class="badge ${priority.className}">${priority.label}</span>
                            ${completed ? '<span class="badge text-bg-success">Completada</span>' : '<span class="badge text-bg-warning">Pendiente</span>'}
                        </div>
                        <strong>${escapeHtml(task.title || '')}</strong>
                        ${task.description ? `<div class="small text-muted mt-1">${escapeHtml(task.description)}</div>` : ''}
                    </div>
                    <div class="appointment-session-actions">
                        <button class="btn btn-outline-success btn-sm btn-toggle-session-task" type="button" data-task-id="${task.id}" data-next-status="${completed ? 'pending' : 'completed'}" title="${completed ? 'Marcar pendiente' : 'Completar'}">
                            <i class="bi ${completed ? 'bi-arrow-counterclockwise' : 'bi-check2'}"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function renderAppointmentSessionNotes(notes) {
    const rows = Array.isArray(notes) ? notes : [];
    if (!rows.length) {
        return '<div class="text-center text-muted py-3">No hay notas vinculadas a esta cita.</div>';
    }
    return rows.map(note => `
        <div class="appointment-session-item">
            <div class="d-flex justify-content-between align-items-start">
                <strong>${escapeHtml(note.title || '')}</strong>
                <small class="text-muted">${formatDisplayDate(note.note_date || '')}</small>
            </div>
            ${note.description ? `<div class="mt-2">${escapeHtml(note.description)}</div>` : ''}
            ${parseInt(note.file_count || 0, 10) > 0 ? `<span class="badge text-bg-secondary mt-2">${parseInt(note.file_count || 0, 10)} archivo${parseInt(note.file_count || 0, 10) === 1 ? '' : 's'}</span>` : ''}
        </div>
    `).join('');
}

function showAppointmentSessionAlert(type, message) {
    const target = $('#appointment-files-panel').hasClass('active') ? '#appointment-files-alert' : '#appointment-session-alert';
    const $alert = $(target)
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .text(message);
    if (appointmentSessionAlertTimer) {
        clearTimeout(appointmentSessionAlertTimer);
        appointmentSessionAlertTimer = null;
    }
    if (type === 'success') {
        appointmentSessionAlertTimer = setTimeout(function () {
            $alert.addClass('d-none').text('');
            appointmentSessionAlertTimer = null;
        }, 3000);
    }
}

function saveAppointmentSessionTask(form) {
    const $button = $('#btn-save-appointment-session-task');
    const original = $button.html();
    const data = {
        task_id: 0,
        appointment_id: CURRENT_APPOINTMENT_SESSION.appointment_id,
        patient_id: CURRENT_APPOINTMENT_SESSION.patient_id,
        title: $('#appointment-session-task-title').val() || '',
        description: $('#appointment-session-task-description').val() || '',
        priority: $('#appointment-session-task-priority').val() || 2,
        status: $('#appointment-session-task-completed').is(':checked') ? 'completed' : 'pending'
    };
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Guardando');
    $.ajax({
        url: 'api/admin.php?action=save_patient_work_plan_task',
        method: 'POST',
        dataType: 'json',
        data,
        success: function (res) {
            if (!res.success) {
                showAppointmentSessionAlert('danger', res.error || 'No se pudo guardar la tarea.');
                return;
            }
            showAppointmentSessionAlert('success', res.message || 'Tarea guardada.');
            form.reset();
            loadAppointmentSession();
        },
        error: function () {
            showAppointmentSessionAlert('danger', 'Error de conexión al guardar la tarea.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function saveAppointmentSessionNote(form) {
    const $button = $('#btn-save-appointment-session-note');
    const original = $button.html();
    const formData = new FormData(form);
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Guardando');
    $.ajax({
        url: 'api/admin.php?action=save_patient_evolution',
        method: 'POST',
        data: formData,
        dataType: 'json',
        processData: false,
        contentType: false,
        success: function (res) {
            if (!res.success) {
                showAppointmentSessionAlert('danger', res.error || 'No se pudo guardar la nota.');
                return;
            }
            showAppointmentSessionAlert('success', res.message || 'Nota guardada.');
            if (appointmentSessionNoteModal) {
                appointmentSessionNoteModal.hide();
            } else {
                resetAppointmentSessionNoteForm();
            }
            loadAppointmentSession();
        },
        error: function () {
            showAppointmentSessionAlert('danger', 'Error de conexión al guardar la nota.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function resetAppointmentSessionNoteForm() {
    const form = document.getElementById('appointment-session-note-form');
    if (form) {
        form.reset();
    }
    $('#appointment-session-note-appointment-id').val('0');
    $('#appointment-session-note-patient-id').val('0');
    $('#appointment-session-note-date').val('');
    $('#btn-save-appointment-session-note').prop('disabled', false).html('<i class="bi bi-journal-plus"></i> Guardar nota');
}

function openAppointmentSessionNoteModal() {
    if (!appointmentSessionNoteModal) {
        return;
    }
    const app = CURRENT_APPOINTMENT_PAYMENT_DETAIL || {};
    const appointmentDate = app.appointment_date || formatDate(new Date());
    resetAppointmentSessionNoteForm();
    $('#appointment-session-note-appointment-id').val(CURRENT_APPOINTMENT_SESSION.appointment_id || app.id || 0);
    $('#appointment-session-note-patient-id').val(CURRENT_APPOINTMENT_SESSION.patient_id || app.patient_id || 0);
    $('#appointment-session-note-date').val(appointmentDate);
    appointmentSessionNoteModal.show();
    setTimeout(() => $('#appointment-session-note-title').trigger('focus'), 180);
}

function setAppointmentSessionTaskStatus(button) {
    if (!workPlanTaskStatusEnabled()) {
        return;
    }
    const $button = $(button);
    const taskId = parseInt($button.data('task-id') || 0, 10);
    const status = $button.data('next-status') === 'completed' ? 'completed' : 'pending';
    if (!taskId) return;
    const $item = $button.closest('.appointment-session-task');
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({
        url: 'api/admin.php?action=set_patient_work_plan_task_status',
        method: 'POST',
        dataType: 'json',
        data: { task_id: taskId, status },
        success: function (res) {
            if (!res.success) {
                showAppointmentSessionAlert('danger', res.error || 'No se pudo actualizar la tarea.');
                return;
            }
            showAppointmentSessionAlert('success', res.message || 'Tarea actualizada.');
            updateAppointmentSessionTaskItem($item, taskId, status);
        },
        error: function () {
            showAppointmentSessionAlert('danger', 'Error de conexión al actualizar la tarea.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function updateAppointmentSessionTaskItem($item, taskId, status) {
    const tasks = Array.isArray(CURRENT_APPOINTMENT_SESSION.tasks) ? CURRENT_APPOINTMENT_SESSION.tasks : [];
    const task = tasks.find(item => parseInt(item.id || 0, 10) === taskId);
    if (!task || !$item || !$item.length) {
        loadAppointmentSession();
        return;
    }
    task.status = status;
    const $newItem = $(renderAppointmentSessionTasks([task])).css('opacity', 0);
    $item.replaceWith($newItem);
    $newItem.animate({ opacity: 1 }, 180);
}

function deleteAppointmentSessionNote(button) {
    const $button = $(button);
    const noteId = parseInt($button.data('note-id') || 0, 10);
    if (!noteId || !confirm('¿Eliminar esta nota de la sesión?')) {
        return;
    }
    const $item = $button.closest('.appointment-session-activity-item');
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({
        url: 'api/admin.php?action=delete_patient_evolution_note',
        method: 'POST',
        dataType: 'json',
        data: { note_id: noteId },
        success: function (res) {
            if (!res.success) {
                showAppointmentSessionAlert('danger', res.error || 'No se pudo eliminar la nota.');
                return;
            }
            showAppointmentSessionAlert('success', res.message || 'Nota eliminada.');
            removeAppointmentSessionActivityItem($item, noteId);
        },
        error: function () {
            showAppointmentSessionAlert('danger', 'Error de conexión al eliminar la nota.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function removeAppointmentSessionActivityItem($item, noteId) {
    if (Array.isArray(CURRENT_APPOINTMENT_SESSION.notes)) {
        CURRENT_APPOINTMENT_SESSION.notes = CURRENT_APPOINTMENT_SESSION.notes.filter(note => parseInt(note.id || 0, 10) !== noteId);
    }
    if (!$item || !$item.length) {
        $('#appointment-session-activity').html(renderAppointmentSessionActivity(CURRENT_APPOINTMENT_SESSION.notes, CURRENT_APPOINTMENT_SESSION.files));
        return;
    }
    $item
        .stop(true, true)
        .animate({ opacity: 0, height: 0, marginTop: 0, marginBottom: 0, paddingTop: 0, paddingBottom: 0 }, 220, function () {
            const $list = $(this).closest('.appointment-session-activity-list');
            $(this).remove();
            if (!$list.find('.appointment-session-activity-item').length) {
                $('#appointment-session-activity').html('<div class="text-center text-muted py-3">No hay notas ni archivos vinculados a esta sesi&oacute;n.</div>');
            }
        });
}

function deleteAppointmentSessionTask(button) {
    const $button = $(button);
    const taskId = parseInt($button.data('task-id') || 0, 10);
    if (!taskId || !confirm('¿Eliminar esta tarea de la sesión?')) {
        return;
    }
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({
        url: 'api/admin.php?action=delete_patient_work_plan_task',
        method: 'POST',
        dataType: 'json',
        data: { task_id: taskId },
        success: function (res) {
            if (!res.success) {
                showAppointmentSessionAlert('danger', res.error || 'No se pudo eliminar la tarea.');
                return;
            }
            showAppointmentSessionAlert('success', res.message || 'Tarea eliminada.');
            loadAppointmentSession();
        },
        error: function () {
            showAppointmentSessionAlert('danger', 'Error de conexión al eliminar la tarea.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function renderAppointmentSession(app = CURRENT_APPOINTMENT_PAYMENT_DETAIL) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    $('#appointment-session-content').html(`
        <div class="row g-3">
            <div class="col-12">
                <section class="appointment-session-box appointment-session-box-compact">
                    <div class="appointment-session-section-header">
                        <div>
                            <h6>Tareas del ${escapeHtml(patientSingular)}</h6>
                            <p>Crea tareas o importa plantillas para trabajar esta sesi&oacute;n.</p>
                        </div>
                        <button class="btn btn-outline-primary btn-sm" type="button" id="btn-show-appointment-session-work-plan-form">
                            <i class="bi bi-list-check"></i> Crear o importar tareas
                        </button>
                    </div>
                    <div id="appointment-session-tasks">${renderAppointmentSessionTasks(CURRENT_APPOINTMENT_SESSION.tasks)}</div>
                </section>
            </div>
        </div>
    `);
    $('#appointment-files-content').html(`
        <section class="appointment-session-box">
            <div class="appointment-session-section-header">
                <div>
                    <h6>Notas y archivos de la sesi&oacute;n</h6>
                </div>
                <button class="btn btn-outline-primary btn-sm" type="button" id="btn-toggle-appointment-session-note">
                    <i class="bi bi-journal-plus"></i> Nueva nota / archivo
                </button>
            </div>
            <div id="appointment-session-activity">${renderAppointmentSessionActivity(CURRENT_APPOINTMENT_SESSION.notes, CURRENT_APPOINTMENT_SESSION.files)}</div>
        </section>
    `);
}

function renderAppointmentSessionTasks(tasks) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const rows = Array.isArray(tasks) ? tasks : [];
    const statusEnabled = workPlanTaskStatusEnabled();
    if (!rows.length) {
        return `<div class="text-center text-muted py-3">${statusEnabled ? `No hay tareas pendientes o completadas en el plan del ${escapeHtml(patientSingular)}.` : `No hay tareas en el plan del ${escapeHtml(patientSingular)}.`}</div>`;
    }
    return rows.map(task => {
        const completed = task.status === 'completed';
        const priority = workPlanPriorityLabel(task.priority);
        const statusBadge = statusEnabled
            ? (completed ? '<span class="badge text-bg-success">Completada</span>' : '<span class="badge text-bg-warning">Pendiente</span>')
            : '';
        const toggleButton = statusEnabled
            ? `<button class="btn btn-outline-success btn-sm btn-toggle-session-task" type="button" data-task-id="${task.id}" data-next-status="${completed ? 'pending' : 'completed'}" title="${completed ? 'Marcar pendiente' : 'Completar'}">
                        <i class="bi ${completed ? 'bi-arrow-counterclockwise' : 'bi-check2'}"></i>
                    </button>`
            : '';
        return `
            <div class="appointment-session-task ${completed ? 'is-completed' : ''}">
                <div class="appointment-session-task-main">
                    <strong>${escapeHtml(task.title || '')}</strong>
                    ${task.description ? `<span>${escapeHtml(task.description)}</span>` : ''}
                </div>
                <div class="appointment-session-task-side">
                    <span class="badge ${priority.className}">${priority.label}</span>
                    ${statusBadge}
                    ${toggleButton}
                </div>
            </div>
        `;
    }).join('');
}

function renderAppointmentSessionActivity(notes, files) {
    const rows = Array.isArray(notes) ? notes : [];
    const items = rows.map(note => {
        const noteFiles = Array.isArray(note.files) ? note.files : [];
        const hasFiles = noteFiles.length > 0;
        const title = note.title || (hasFiles ? 'Archivo' : 'Nota');
        const detail = hasFiles
            ? noteFiles.map(file => `<a href="${escapeHtml(file.url || '#')}" target="_blank" rel="noopener">${escapeHtml(file.name || 'Archivo')}</a>`).join(', ')
            : escapeHtml(truncateText(note.description || '', 120));
        return {
            date: note.created_at || note.note_date || '',
            html: `
                <div class="appointment-session-activity-item">
                    <i class="bi ${hasFiles ? 'bi-paperclip' : 'bi-journal-text'}"></i>
                    <small class="appointment-session-activity-date">${formatDisplayDate(note.note_date || '')}</small>
                    <div class="appointment-session-activity-body">
                        <strong>${escapeHtml(title)}</strong>
                        ${detail ? `<div class="small text-muted mt-1">${detail}</div>` : ''}
                    </div>
                    <div class="appointment-session-activity-actions">
                        ${hasFiles && noteFiles[0] ? `<a class="btn btn-outline-primary btn-sm" href="${escapeHtml(noteFiles[0].url || '#')}" target="_blank" rel="noopener" title="Descargar"><i class="bi bi-download"></i></a>` : ''}
                        <button class="btn btn-outline-danger btn-sm btn-delete-session-note" type="button" data-note-id="${note.id}" title="Eliminar nota">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            `
        };
    });
    if (!items.length) {
        return '<div class="text-center text-muted py-3">No hay notas ni archivos vinculados a esta sesi&oacute;n.</div>';
    }
    items.sort((a, b) => String(b.date || '').localeCompare(String(a.date || '')));
    return `<div class="appointment-session-activity-list">${items.map(item => item.html).join('')}</div>`;
}

function truncateText(value, maxLength = 120) {
    const text = String(value || '').replace(/\s+/g, ' ').trim();
    if (text.length <= maxLength) return text;
    return `${text.slice(0, Math.max(0, maxLength - 1)).trim()}…`;
}

function toggleAppointmentOnlineFields() {
    const app = CURRENT_APPOINTMENT_PAYMENT_DETAIL || {};
    const isOnline = app.consultation_type === 'online';
    $('.appointment-online-link-wrap').toggleClass('d-none', !isOnline);
    $('#btn-send-appointment-online-link').prop('disabled', !isOnline || !String($('#appointment-online-session-url').val() || '').trim());
}

function updateAppointmentOnlineDetails(consultationType, onlineUrl, button, successMessage = 'Modalidad actualizada.') {
    const app = CURRENT_APPOINTMENT_PAYMENT_DETAIL || {};
    const appointmentId = parseInt(app.id || $('#appointment-payment-id').val() || 0, 10);
    if (!appointmentId) {
        showAppointmentModalityAlert('danger', 'No se ha podido identificar la cita.');
        return $.Deferred().reject().promise();
    }
    const $button = $(button);
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Guardando');
    return $.ajax({
        url: 'api/admin.php?action=update_appointment_online_details',
        method: 'POST',
        dataType: 'json',
        data: {
            appointment_id: appointmentId,
            consultation_type: consultationType,
            online_session_url: onlineUrl
        },
        success: function (res) {
            if (!res.success) {
                showAppointmentModalityAlert('danger', res.error || 'No se pudo guardar la modalidad.');
                return;
            }
            CURRENT_APPOINTMENT_PAYMENT_DETAIL = {
                ...(CURRENT_APPOINTMENT_PAYMENT_DETAIL || {}),
                consultation_type: res.consultation_type || consultationType,
                online_session_url: res.online_session_url || '',
                can_online_appointment: app.can_online_appointment,
                can_presential_appointment: app.can_presential_appointment
            };
            $('#appointment-payment-summary').html(renderAppointmentPaymentSummary(CURRENT_APPOINTMENT_PAYMENT_DETAIL));
            if (successMessage !== '' && CURRENT_APPOINTMENT_SESSION && CURRENT_APPOINTMENT_SESSION.appointment_id) {
                renderAppointmentSession(CURRENT_APPOINTMENT_PAYMENT_DETAIL);
            } else {
                $('#appointment-online-session-url').val(res.online_session_url || '');
                $('#btn-send-appointment-online-link').prop('disabled', !(res.online_session_url || ''));
            }
            if (successMessage !== '') {
                showAppointmentModalityAlert('success', res.message || successMessage);
            }
            refreshAfterAppointmentPaymentUpdate();
        },
        error: function () {
            showAppointmentModalityAlert('danger', 'Error de conexion al guardar la modalidad.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function switchAppointmentModality(button) {
    const target = $(button).data('target-modality') === 'online' ? 'online' : 'presencial';
    const app = CURRENT_APPOINTMENT_PAYMENT_DETAIL || {};
    if (target === 'online' && app.can_online_appointment != 1) {
        showAppointmentModalityAlert('danger', 'Este profesional no admite citas online.');
        return;
    }
    if (target === 'presencial' && app.can_presential_appointment == 0) {
        showAppointmentModalityAlert('danger', 'Este profesional no admite citas presenciales.');
        return;
    }
    const onlineUrl = target === 'online' ? String(app.online_session_url || $('#appointment-online-session-url').val() || '').trim() : '';
    updateAppointmentOnlineDetails(target, onlineUrl, button, `Cita cambiada a ${consultationTypeLabel(target).toLowerCase()}.`);
}

function saveAppointmentOnlineDetails(button) {
    const app = CURRENT_APPOINTMENT_PAYMENT_DETAIL || {};
    const consultationType = app.consultation_type === 'online' ? 'online' : 'presencial';
    const onlineUrl = consultationType === 'online' ? String($('#appointment-online-session-url').val() || '').trim() : '';
    return updateAppointmentOnlineDetails(consultationType, onlineUrl, button);
}

function sendAppointmentOnlineLink(button) {
    const app = CURRENT_APPOINTMENT_PAYMENT_DETAIL || {};
    const appointmentId = parseInt(app.id || $('#appointment-payment-id').val() || 0, 10);
    const consultationType = app.consultation_type === 'online' ? 'online' : 'presencial';
    const onlineUrl = consultationType === 'online' ? String($('#appointment-online-session-url').val() || '').trim() : '';
    if (!appointmentId) {
        showAppointmentModalityAlert('danger', 'No se ha podido identificar la cita.');
        return;
    }
    if (consultationType !== 'online' || !onlineUrl) {
        showAppointmentModalityAlert('danger', 'Indica un enlace para una cita online antes de enviarlo.');
        return;
    }
    const $button = $(button);
    const original = $button.html();
    let sendingEmail = false;
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Enviando');
    updateAppointmentOnlineDetails(consultationType, onlineUrl, button, '')
        .done(function (res) {
            if (!res.success) {
                return;
            }
            sendingEmail = true;
            $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Enviando');
            $.ajax({
                url: 'api/admin.php?action=send_appointment_online_link',
                method: 'POST',
                dataType: 'json',
                data: { appointment_id: appointmentId },
                success: function (sendRes) {
                    showAppointmentModalityAlert(sendRes.success ? 'success' : 'danger', sendRes.message || sendRes.error || 'No se pudo enviar el enlace.');
                },
                error: function () {
                    showAppointmentModalityAlert('danger', 'Error de conexion al enviar el enlace.');
                },
                complete: function () {
                    $button.prop('disabled', false).html(original);
                }
            });
        })
        .fail(function () {
            showAppointmentModalityAlert('danger', 'Error de conexion al guardar el enlace.');
        })
        .always(function () {
            if (!sendingEmail) {
                $button.prop('disabled', false).html(original);
            }
        });
}

function openAppointmentPaymentModal(appointmentId) {
    appointmentId = parseInt(appointmentId || 0, 10);
    if (!appointmentPaymentModal || !appointmentId) {
        return;
    }
    CURRENT_APPOINTMENT_PAYMENT_DETAIL = null;
    resetAppointmentSessionPanel();
    $('#appointment-payment-id').val(appointmentId);
    if (appointmentPaymentAlertTimer) {
        clearTimeout(appointmentPaymentAlertTimer);
        appointmentPaymentAlertTimer = null;
    }
    $('#appointment-payment-alert').addClass('d-none').text('');
    $('#appointment-payment-summary').html('<div class="text-center text-muted py-4">Cargando cita...</div>');
    $('#appointment-payment-editor').removeClass('d-none');
    $('#btn-save-appointment-payment').removeClass('d-none').prop('disabled', true);
    $('#btn-cancel-appointment-from-detail').addClass('d-none').prop('disabled', true);
    $('#btn-open-patient-from-appointment-detail').addClass('d-none').prop('disabled', true);
    $('body').toggleClass('appointment-payment-secondary-modal-open', $('.modal.show').not('#appointmentPaymentModal').length > 0);
    appointmentPaymentModal.show();

    $.ajax({
        url: 'api/admin.php?action=appointment_payment_detail',
        data: { appointment_id: appointmentId },
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                showAppointmentPaymentAlert('danger', res.error || 'No se pudo cargar la cita.');
                $('#appointment-payment-editor').addClass('d-none');
                return;
            }
            const app = res.appointment || {};
            CURRENT_APPOINTMENT_PAYMENT_DETAIL = app;
            $('#appointment-payment-summary').html(renderAppointmentPaymentSummary(app));
            updateAppointmentSessionTabLabel(app);
            bootstrap.Tab.getOrCreateInstance(document.getElementById('appointment-detail-tab')).show();
            loadAppointmentSession(app);
            setAppointmentPaymentSelection(app.payment_status, app.payment_method);
            const locked = app.is_bonus_payment == 1 || app.status === 'cancelled';
            $('#appointment-payment-editor').toggleClass('d-none', locked);
            $('#btn-save-appointment-payment').toggleClass('d-none', locked).prop('disabled', locked);
            $('#btn-cancel-appointment-from-detail').toggleClass('d-none', app.status !== 'booked').prop('disabled', app.status !== 'booked');
            $('#btn-open-patient-from-appointment-detail').toggleClass('d-none', !app.patient_id).prop('disabled', !app.patient_id);
            if (locked) {
                showAppointmentPaymentAlert('warning', app.is_bonus_payment == 1
                    ? 'Esta cita fue pagada con bono y no es posible modificarlo desde aquí.'
                    : 'No se puede modificar el pago de una cita cancelada.');
            }
        },
        error: function () {
            showAppointmentPaymentAlert('danger', 'Error de conexión al cargar la cita.');
            $('#appointment-payment-editor').addClass('d-none');
            $('#btn-cancel-appointment-from-detail').addClass('d-none').prop('disabled', true);
            $('#btn-open-patient-from-appointment-detail').addClass('d-none').prop('disabled', true);
        }
    });
}

function cancelAppointmentFromPaymentDetail() {
    const app = CURRENT_APPOINTMENT_PAYMENT_DETAIL || {};
    if (!app.id || app.status !== 'booked') {
        showAppointmentPaymentAlert('danger', 'No se ha podido identificar la cita para cancelarla.');
        return;
    }
    const payload = encodeURIComponent(JSON.stringify({
        id: app.id,
        name: app.patient_name || '',
        email: app.patient_email || '',
        phone: app.patient_phone || '',
        payment_status: app.payment_status || '',
        payment_method: app.payment_method || '',
        patient_bonus_id: app.patient_bonus_id || null
    }));
    const date = app.appointment_date || '';
    const time = String(app.appointment_time || '').slice(0, 5);
    appointmentPaymentModal.hide();
    setTimeout(() => {
        openModal(date, time, 'cancel_admin', payload);
    }, 180);
}

function saveAppointmentPayment() {
    const appointmentId = parseInt($('#appointment-payment-id').val() || 0, 10);
    const status = $('.payment-state-card.active').data('payment-status') || 'pending';
    const method = status === 'paid' ? ($('#appointment-payment-method').val() || 'cash') : '';
    if (!appointmentId) {
        showAppointmentPaymentAlert('danger', 'No se ha podido identificar la cita.');
        return;
    }
    setAppointmentPaymentLoading(true);
    $('#appointment-payment-alert').addClass('d-none').text('');
    $.ajax({
        url: 'api/admin.php?action=update_appointment_payment',
        method: 'POST',
        dataType: 'json',
        data: {
            appointment_id: appointmentId,
            payment_status: status,
            payment_method: method
        },
        success: function (res) {
            if (!res.success) {
                showAppointmentPaymentAlert('danger', res.error || 'No se pudo guardar el pago.');
                return;
            }
            showAppointmentPaymentAlert('success', res.message || 'Pago actualizado correctamente.');
            refreshAfterAppointmentPaymentUpdate();
            setTimeout(() => appointmentPaymentModal.hide(), 500);
        },
        error: function () {
            showAppointmentPaymentAlert('danger', 'Error de conexión al guardar el pago.');
        },
        complete: function () {
            setAppointmentPaymentLoading(false);
        }
    });
}

function refreshAfterAppointmentPaymentUpdate() {
    invalidateDashboardUpcomingAppointments();
    renderWeekInfo();
    if ($('#upcomingAppointmentsModal').hasClass('show')) {
        loadUpcomingAppointments();
    }
    if ($('#patientEditorModal').hasClass('show') && CURRENT_PATIENT_HISTORY_ID > 0) {
        loadPatientAppointmentHistory(CURRENT_PATIENT_HISTORY_ID);
    }
}

function appointmentStatusLabel(status) {
    const labels = {
        booked: '<span class="badge text-bg-success">Reservada</span>',
        cancelled: '<span class="badge text-bg-secondary">Cancelada</span>',
        completed: '<span class="badge text-bg-primary">Realizada</span>',
        no_show: '<span class="badge text-bg-warning">No asistió</span>'
    };
    return labels[status] || `<span class="badge text-bg-light">${escapeHtml(status || 'Sin estado')}</span>`;
}

function showUpcomingAppointmentsAlert(type, message) {
    $('#upcoming-appointments-alert')
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .text(message);
}

function openAdminStatsModal() {
    if (!adminStatsModal) return;
    $('#admin-stats-alert').addClass('d-none').text('');
    $('#admin-stats-content').html('<div class="text-center text-muted py-4">Cargando...</div>');
    $('#admin-reports-content').html('<div class="text-center text-muted py-4">Cargando informes...</div>');
    adminStatsModal.show();

    $.ajax({
        url: 'api/admin.php?action=admin_stats',
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                showAdminStatsAlert('danger', res.error || 'No se pudieron cargar las estadisticas.');
                return;
            }
            renderAdminStats(res.stats || {});
            renderAdminReports((res.stats || {}).reports || {});
        },
        error: function () {
            showAdminStatsAlert('danger', 'Error de conexion al cargar las estadisticas.');
        }
    });
}

function renderAdminStats(stats) {
    const topPatients = Array.isArray(stats.top_patients) ? stats.top_patients : [];
    const professionalSummary = Array.isArray(stats.professional_summary) ? stats.professional_summary : [];
    const revenue = stats.payment_revenue_month || {};
    const revenueCards = [
        ['Efectivo', revenue.cash || 0],
        ['Transferencia', revenue.bank_transfer || 0],
        ['Tarjeta', revenue.card || 0],
        ['Bizum', revenue.bizum || 0],
        ['Otros', revenue.other || revenue.manual || 0]
    ].map(item => `
        <div class="stats-summary-card"><span>${item[0]}</span><strong>${formatPrice(item[1])} &euro;</strong><small>este mes</small></div>
    `).join('');
    const maxSessions = topPatients.reduce((max, item) => Math.max(max, parseInt(item.sessions || 0, 10)), 0) || 1;
    const bars = topPatients.length
        ? topPatients.map(item => {
            const sessions = parseInt(item.sessions || 0, 10);
            const width = Math.max(8, Math.round((sessions / maxSessions) * 100));
            return `
                <div class="stats-bar-row">
                    <div class="stats-bar-label">
                        <strong>${escapeHtml(item.name || '')}</strong>
                        <small>${escapeHtml(item.email || '')}</small>
                    </div>
                    <div class="stats-bar-track">
                        <div class="stats-bar-fill" style="width: ${width}%"></div>
                    </div>
                    <div class="stats-bar-value">${sessions}</div>
                </div>
            `;
        }).join('')
        : '<div class="text-muted">Aun no hay citas suficientes para mostrar ranking.</div>';
    const teamSummary = professionalSummary.length > 1
        ? `
            <h6 class="mb-3">Resumen por profesional</h6>
            <div class="stats-professional-grid mb-4">
                ${professionalSummary.map(item => `
                    <div class="stats-professional-card">
                        ${professionalCellHtml(item, 'display_name', 'public_photo_path')}
                        <div class="stats-professional-numbers">
                            <span><strong>${parseInt(item.patient_count || 0, 10)}</strong> ${escapeHtml(sectorLabel('patient', 'plural', 'pacientes'))}</span>
                            <span><strong>${parseInt(item.upcoming_count || 0, 10)}</strong> citas pr&oacute;ximas</span>
                        </div>
                    </div>
                `).join('')}
            </div>
        `
        : '';

    $('#admin-stats-content').html(`
        <div class="stats-summary-grid mb-4">
            <div class="stats-summary-card"><span>Hoy</span><strong>${parseInt(stats.today_count || 0, 10)}</strong><small>citas</small></div>
            <div class="stats-summary-card"><span>Pr&oacute;ximas</span><strong>${parseInt(stats.upcoming_count || 0, 10)}</strong><small>citas</small></div>
            <div class="stats-summary-card"><span>Este mes</span><strong>${parseInt(stats.month_count || 0, 10)}</strong><small>citas</small></div>
            <div class="stats-summary-card"><span>Pacientes</span><strong>${parseInt(stats.patient_count || 0, 10)}</strong><small>fichas</small></div>
            <div class="stats-summary-card"><span>Ingresos online</span><strong>${formatPrice(stats.online_revenue_month || 0)} &euro;</strong><small>este mes</small></div>
            <div class="stats-summary-card"><span>Pendientes pago</span><strong>${parseInt(stats.pending_payment_count || 0, 10)}</strong><small>citas</small></div>
            <div class="stats-summary-card"><span>Bonos activos</span><strong>${parseInt(stats.active_bonus_count || 0, 10)}</strong><small>${parseInt(stats.active_bonus_sessions || 0, 10)} sesiones</small></div>
            ${revenueCards}
        </div>
        ${teamSummary}
        <h6 class="mb-3">Pacientes con m&aacute;s sesiones</h6>
        <div class="stats-bars">${bars}</div>
    `);
}

function renderAdminReports(reports) {
    const patientSingular = sectorLabel('patient', 'singular', 'paciente');
    const patientPlural = sectorLabel('patient', 'plural', 'pacientes');
    const patientTitleSingular = sectorLabel('patient', 'titleSingular', 'Paciente');
    const patientTitlePlural = sectorLabel('patient', 'titlePlural', 'Pacientes');
    const patientsWithoutUpcoming = Array.isArray(reports.patients_without_upcoming) ? reports.patients_without_upcoming : [];
    const recentCancellations = Array.isArray(reports.recent_cancellations) ? reports.recent_cancellations : [];
    const pendingPayments = Array.isArray(reports.pending_payments) ? reports.pending_payments : [];

    $('#admin-reports-content').html(`
        <div class="stats-summary-grid mb-4">
            <div class="stats-summary-card"><span>Sin pr&oacute;xima cita</span><strong>${patientsWithoutUpcoming.length}</strong><small>primeros casos</small></div>
            <div class="stats-summary-card"><span>Canceladas</span><strong>${recentCancellations.length}</strong><small>&uacute;ltimos 30 d&iacute;as</small></div>
            <div class="stats-summary-card"><span>Pendientes pago</span><strong>${pendingPayments.length}</strong><small>primeras citas</small></div>
        </div>
        ${reportTableHtml(
            `${escapeHtml(patientTitlePlural)} sin pr&oacute;xima cita`,
            [escapeHtml(patientTitleSingular), 'Contacto', '&Uacute;ltima cita'],
            patientsWithoutUpcoming,
            patient => [
                `<strong>${escapeHtml(patient.name || '')}</strong>`,
                patientContactSummaryHtml(patient.email, patient.phone) || '-',
                patient.last_appointment_at ? escapeHtml(formatDateTimeLabel(patient.last_appointment_at)) : 'Sin citas previas'
            ],
            `No hay ${escapeHtml(patientPlural)} sin pr&oacute;xima cita.`
        )}
        ${reportTableHtml(
            'Cancelaciones recientes',
            ['Fecha cita', 'Cancelada', escapeHtml(patientTitleSingular), 'Profesional', 'Servicio'],
            recentCancellations,
            app => [
                `<strong>${formatDisplayDate(app.appointment_date || '')}</strong><br><small class="text-muted">${escapeHtml(displayAppointmentTimeRange(app.appointment_time || '', app.duration_minutes || 60))}</small>`,
                app.cancelled_at ? escapeHtml(formatDateTimeLabel(app.cancelled_at)) : '-',
                `${escapeHtml(app.patient_name || '')}<br><small class="text-muted">${patientContactSummaryHtml(app.patient_email, app.patient_phone) || '-'}</small>`,
                escapeHtml(app.professional_name || 'Sin asignar'),
                `${escapeHtml(displayAppointmentServiceLabel(app))}<br><small class="text-muted">${displayAppointmentDurationLabel(app.duration_minutes || 60)}</small>`
            ],
            'No hay cancelaciones recientes.'
        )}
        ${reportTableHtml(
            'Citas pendientes de registrar pago',
            ['Fecha', escapeHtml(patientTitleSingular), 'Profesional', 'Servicio', 'Modalidad'],
            pendingPayments,
            app => [
                `<strong>${formatDisplayDate(app.appointment_date || '')}</strong><br><small class="text-muted">${escapeHtml(displayAppointmentTimeRange(app.appointment_time || '', app.duration_minutes || 60))}</small>`,
                `${escapeHtml(app.patient_name || '')}<br><small class="text-muted">${patientContactSummaryHtml(app.patient_email, app.patient_phone) || '-'}</small>`,
                escapeHtml(app.professional_name || 'Sin asignar'),
                escapeHtml(displayAppointmentServiceLabel(app)),
                consultationTypeLabel(app.consultation_type)
            ],
            'No hay citas pendientes de registrar pago.'
        )}
    `);
}

function reportTableHtml(title, headers, rows, mapRow, emptyText) {
    const reportId = slugifyReportTitle(title);
    const body = rows.length
        ? rows.map(row => `<tr>${mapRow(row).map(cell => `<td>${cell}</td>`).join('')}</tr>`).join('')
        : `<tr><td colspan="${headers.length}" class="text-center text-muted py-4">${emptyText}</td></tr>`;
    return `
        <section class="report-section mb-4" data-report-title="${escapeHtml(title)}" data-report-id="${escapeHtml(reportId)}">
            <div class="report-section-header">
                <h6>${title}</h6>
                <div class="report-section-actions">
                    <button class="btn btn-outline-primary btn-sm btn-print-report-section" type="button">
                        <i class="bi bi-printer"></i> Imprimir
                    </button>
                    <button class="btn btn-outline-primary btn-sm btn-export-report-section" type="button">
                        <i class="bi bi-download"></i> CSV
                    </button>
                    <button class="btn btn-outline-primary btn-sm btn-export-report-section-xls" type="button">
                        <i class="bi bi-file-earmark-spreadsheet"></i> XLS
                    </button>
                </div>
            </div>
            <div class="table-responsive report-table-wrap">
                <table class="table align-middle">
                    <thead>
                        <tr>${headers.map(header => `<th>${header}</th>`).join('')}</tr>
                    </thead>
                    <tbody>${body}</tbody>
                </table>
            </div>
        </section>
    `;
}

function slugifyReportTitle(title) {
    return String(title || 'informe')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/&[^;]+;/g, '')
        .replace(/[^a-zA-Z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .toLowerCase() || 'informe';
}

function reportSectionTitle($section) {
    return $section.find('h6').first().text().trim() || $section.data('report-title') || 'Informe';
}

function exportReportSectionCsv($section) {
    if (!$section || !$section.length) return;
    const rows = tableRowsForExport($section.find('table').first());
    if (!rows.length) return;
    const title = reportSectionTitle($section);
    const filename = `${slugifyReportTitle(title)}.csv`;
    downloadTextFile(filename, `\uFEFF${rows.map(row => row.map(csvEscapeCell).join(';')).join('\r\n')}`, 'text/csv;charset=utf-8;');
}

function exportReportSectionXls($section) {
    if (!$section || !$section.length) return;
    const title = reportSectionTitle($section);
    const filename = `${slugifyReportTitle(title)}.xls`;
    const tableHtml = tableHtmlForExport($section.find('table').first());
    if (!tableHtml) return;
    const html = `
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                table { border-collapse: collapse; }
                th, td { border: 1px solid #dfe5e8; padding: 6px; vertical-align: top; }
                th { font-weight: bold; }
            </style>
        </head>
        <body>
            <h1>${escapeHtml(title)}</h1>
            ${tableHtml}
        </body>
        </html>
    `;
    downloadTextFile(filename, `\uFEFF${html}`, 'application/vnd.ms-excel;charset=utf-8;');
}

function exportModalVisibleTable(target, type) {
    const $modal = $(target);
    if (!$modal.length) return;
    const $activePanel = $modal.find('.tab-pane.active.show, .tab-pane.active').first();
    const $scope = $activePanel.length ? $activePanel : $modal;
    const $table = $scope.find('table:visible').first();
    if (!$table.length) {
        const $alert = $modal.find('.alert').first();
        if ($alert.length) {
            $alert.removeClass('d-none alert-success').addClass('alert-danger').text('No hay una tabla visible para exportar.');
        }
        return;
    }
    const title = modalVisibleTableTitle($modal, $scope);
    if (type === 'print') {
        printTableExport($table, title);
    } else if (type === 'xls') {
        exportTableXls($table, title);
    } else {
        exportTableCsv($table, title);
    }
}

function modalVisibleTableTitle($modal, $scope) {
    const modalTitle = $modal.find('.modal-title').first().text().trim() || 'Listado';
    const tabId = $scope.attr('aria-labelledby');
    const tabTitle = tabId ? $(`#${tabId}`).text().trim() : '';
    return tabTitle ? `${modalTitle} - ${tabTitle}` : modalTitle;
}

function tableRowsForExport($table) {
    const rows = [];
    $table.find('tr').each(function () {
        const cells = [];
        $(this).find('th, td').each(function () {
            if ($(this).hasClass('no-export')) return;
            const columnIndex = $(this).index();
            const $headerCell = $table.find('thead tr').first().children().eq(columnIndex);
            if ($headerCell.hasClass('no-export')) return;
            cells.push($(this).text().replace(/\s+/g, ' ').trim());
        });
        if (cells.length) {
            rows.push(cells);
        }
    });
    return rows;
}

function csvEscapeCell(value) {
    return `"${String(value || '').replace(/"/g, '""')}"`;
}

function tableHtmlForExport($table) {
    const rows = tableRowsForExport($table);
    if (!rows.length) return '';
    const [headers, ...bodyRows] = rows;
    return `
        <table>
            <thead><tr>${headers.map(cell => `<th>${escapeHtml(cell)}</th>`).join('')}</tr></thead>
            <tbody>${bodyRows.map(row => `<tr>${row.map(cell => `<td>${escapeHtml(cell)}</td>`).join('')}</tr>`).join('')}</tbody>
        </table>
    `;
}

function exportTableCsv($table, title) {
    const rows = tableRowsForExport($table);
    if (!rows.length) return;
    downloadTextFile(`${slugifyReportTitle(title)}.csv`, `\uFEFF${rows.map(row => row.map(csvEscapeCell).join(';')).join('\r\n')}`, 'text/csv;charset=utf-8;');
}

function exportTableXls($table, title) {
    const tableHtml = tableHtmlForExport($table);
    if (!tableHtml) return;
    const html = `
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                table { border-collapse: collapse; }
                th, td { border: 1px solid #dfe5e8; padding: 6px; vertical-align: top; }
                th { font-weight: bold; }
            </style>
        </head>
        <body>
            <h1>${escapeHtml(title)}</h1>
            ${tableHtml}
        </body>
        </html>
    `;
    downloadTextFile(`${slugifyReportTitle(title)}.xls`, `\uFEFF${html}`, 'application/vnd.ms-excel;charset=utf-8;');
}

function downloadTextFile(filename, content, type) {
    const blob = new Blob([content], { type });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

function printReportSection($section) {
    if (!$section || !$section.length) return;
    const title = escapeHtml(reportSectionTitle($section));
    const tableHtml = tableHtmlForExport($section.find('table').first());
    const primaryColor = getComputedStyle(document.documentElement).getPropertyValue('--primary-color').trim() || '#4285f4';
    const win = window.open('', '_blank', 'width=1024,height=720');
    if (!win) {
        showAdminStatsAlert('danger', 'No se pudo abrir la ventana de impresion.');
        return;
    }
    win.opener = null;
    win.document.write(`
        <!doctype html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>${title}</title>
            <style>
                body { color: #263238; font-family: Arial, sans-serif; margin: 24px; }
                h1 { color: ${primaryColor}; font-size: 20px; margin: 0 0 18px; }
                table { border-collapse: collapse; width: 100%; }
                th, td { border: 1px solid #dfe5e8; font-size: 12px; padding: 8px; text-align: left; vertical-align: top; }
                th { color: ${primaryColor}; background: #f8f9fa; }
                small { color: #66737d; }
            </style>
        </head>
        <body>
            <h1>${title}</h1>
            ${tableHtml}
        </body>
        </html>
    `);
    win.document.close();
    win.focus();
    setTimeout(() => win.print(), 250);
}

function printTableExport($table, title) {
    const tableHtml = tableHtmlForExport($table);
    if (!tableHtml) return;
    const escapedTitle = escapeHtml(title);
    const primaryColor = getComputedStyle(document.documentElement).getPropertyValue('--primary-color').trim() || '#4285f4';
    const win = window.open('', '_blank', 'width=1024,height=720');
    if (!win) return;
    win.opener = null;
    win.document.write(`
        <!doctype html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>${escapedTitle}</title>
            <style>
                body { color: #263238; font-family: Arial, sans-serif; margin: 24px; }
                h1 { color: ${primaryColor}; font-size: 20px; margin: 0 0 18px; }
                table { border-collapse: collapse; width: 100%; }
                th, td { border: 1px solid #dfe5e8; font-size: 12px; padding: 8px; text-align: left; vertical-align: top; }
                th { color: ${primaryColor}; background: #f8f9fa; }
            </style>
        </head>
        <body>
            <h1>${escapedTitle}</h1>
            ${tableHtml}
        </body>
        </html>
    `);
    win.document.close();
    win.focus();
    setTimeout(() => win.print(), 250);
}

function showAdminStatsAlert(type, message) {
    $('#admin-stats-alert')
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .text(message);
}

function setAppointmentActionLoading(isLoading, paymentMethod = null) {
    isAppointmentRequestInProgress = isLoading;
    $('#btn-confirm-action, #btn-pay-card, #btn-pay-bizum').prop('disabled', isLoading);

    if (isLoading) {
        const actionText = ($('#modalStatus').val() || '').startsWith('cancel') ? 'Cancelando...' : 'Reservando...';
        $('#btn-confirm-action').data('original-text', $('#btn-confirm-action').text()).html(`<span class="spinner-border spinner-border-sm me-2"></span>${actionText}`);
        if (paymentMethod === 'card') {
            $('#btn-pay-card').data('original-text', $('#btn-pay-card').html()).html('<span class="spinner-border spinner-border-sm me-2"></span>Conectando...');
        } else if (paymentMethod === 'bizum') {
            $('#btn-pay-bizum').data('original-text', $('#btn-pay-bizum').html()).html('<span class="spinner-border spinner-border-sm me-2"></span>Conectando...');
        }
        return;
    }

    $('#btn-confirm-action').html($('#btn-confirm-action').data('original-text') || 'Reservar');
    $('#btn-pay-card').html($('#btn-pay-card').data('original-text') || '<i class="bi bi-credit-card"></i> Pagar con tarjeta');
    $('#btn-pay-bizum').html($('#btn-pay-bizum').data('original-text') || '<i class="bi bi-phone"></i> Pagar con Bizum');
}

function loadClosedDays() {
    $('#closed-days-list').html('<li class="list-group-item text-center text-muted py-3">Cargando...</li>');
    $.ajax({
        url: 'api/admin.php?action=list_closed_days',
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                let html = '';
                const showProfessionals = res.show_professionals == 1;
                const ranges = groupClosedDays(res.days || []);
                ranges.forEach(d => {
                    const label = d.start_date === d.end_date
                        ? formatDisplayDate(d.start_date)
                        : `Del ${formatDisplayDate(d.start_date)} al ${formatDisplayDate(d.end_date)}`;
                    const globalBadge = d.is_global == 1 ? ' <span class="badge text-bg-primary">Global</span>' : '';
                    const professional = showProfessionals
                        ? `<span class="closed-day-professional">${professionalCellHtml(d, 'professional_name', 'professional_photo_path')}</span>`
                        : '';
                    html += `<li class="list-group-item d-flex justify-content-between align-items-center">
                                <span class="closed-day-row-main">
                                    <span>${label} - ${escapeHtml(d.reason)}${globalBadge}</span>
                                    ${professional}
                                </span>
                                <button class="btn btn-sm btn-danger" onclick="deleteClosedRange('${escapeJsString(d.start_date)}', '${escapeJsString(d.end_date)}', '${escapeJsString(d.reason)}', ${d.is_global == 1 ? 1 : 0}, ${parseInt(d.professional_id || 0, 10)})"><i class="bi bi-trash"></i></button>
                             </li>`;
                });
                $('#closed-days-list').html(html || '<li class="list-group-item text-center text-muted py-3">No hay vacaciones o cierres próximos.</li>');
            }
        }
    });
}

function groupClosedDays(days) {
    const grouped = {};
    days.forEach(day => {
        const currentReason = String(day.reason || '');
        const currentGlobal = day.is_global == 1 ? 1 : 0;
        const currentProfessionalId = parseInt(day.professional_id || 0, 10);
        const key = [
            currentGlobal,
            currentProfessionalId,
            currentReason
        ].join('|');
        if (!grouped[key]) {
            grouped[key] = [];
        }
        grouped[key].push(day);
    });

    const ranges = [];

    Object.values(grouped).forEach(groupDays => {
        const sorted = groupDays.sort((a, b) => String(a.closed_date).localeCompare(String(b.closed_date)));
        sorted.forEach(day => {
            const currentDate = String(day.closed_date || '');
            const currentReason = String(day.reason || '');
            const currentGlobal = day.is_global == 1 ? 1 : 0;
            const currentProfessionalId = parseInt(day.professional_id || 0, 10);
            const last = ranges[ranges.length - 1];
            if (last
                && last.reason === currentReason
                && last.is_global === currentGlobal
                && parseInt(last.professional_id || 0, 10) === currentProfessionalId
                && isNextDate(last.end_date, currentDate)) {
                last.end_date = currentDate;
                return;
            }
            ranges.push({
                start_date: currentDate,
                end_date: currentDate,
                reason: currentReason,
                is_global: currentGlobal,
                professional_id: currentProfessionalId,
                professional_name: day.professional_name || (currentGlobal ? 'Todo el equipo' : ''),
                professional_photo_path: day.professional_photo_path || ''
            });
        });
    });

    return ranges.sort((a, b) => {
        const dateCompare = String(a.start_date).localeCompare(String(b.start_date));
        if (dateCompare !== 0) {
            return dateCompare;
        }
        return String(a.professional_name || '').localeCompare(String(b.professional_name || ''));
    });
}

function isNextDate(previousDate, currentDate) {
    const previous = new Date(`${previousDate}T00:00:00`);
    const current = new Date(`${currentDate}T00:00:00`);
    if (Number.isNaN(previous.getTime()) || Number.isNaN(current.getTime())) {
        return false;
    }
    previous.setDate(previous.getDate() + 1);
    return formatDate(previous) === currentDate;
}

function deleteClosedDay(id) {
    if (!confirm('¿Eliminar este día de descanso?')) return;
    $.ajax({
        url: 'api/admin.php?action=delete_closed_day',
        method: 'POST',
        data: { id: id },
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                loadClosedDays();
                renderWeekInfo();
            }
        }
    });
}

function deleteClosedRange(startDate, endDate, reason, isGlobal = 0, professionalId = 0) {
    const label = startDate === endDate ? formatDisplayDate(startDate) : `del ${formatDisplayDate(startDate)} al ${formatDisplayDate(endDate)}`;
    if (!confirm(`Eliminar este periodo de descanso ${label}?`)) return;
    $.ajax({
        url: 'api/admin.php?action=delete_closed_range',
        method: 'POST',
        data: {
            start_date: startDate,
            end_date: endDate,
            reason,
            is_global: isGlobal ? '1' : '0',
            professional_id: professionalId || ''
        },
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                loadClosedDays();
                renderWeekInfo();
            } else {
                alert(res.error || 'No se pudo eliminar el periodo');
            }
        }
    });
}

function showPaymentSettingsAlert(type, message) {
    showSettingsAlert('#payment-settings-alert', type, message);
}

function showSettingsAlert(selector, type, message) {
    const $alert = $(selector);
    if ($alert.data('hide-timer')) {
        clearTimeout($alert.data('hide-timer'));
    }

    $alert
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .text(type === 'success' ? 'Cambios guardados' : message);

    if (type === 'success') {
        const timer = setTimeout(function () {
            $alert.addClass('d-none');
        }, 4000);
        $alert.data('hide-timer', timer);
    }
}

function toggleDashboardConfigModeControls() {
    const uiCustomization = planFeatureEnabled('ui.customization', false);
    $('#dashboard-config-row').toggleClass('d-none', !uiCustomization);
    $('#dashboard-config-mode').val(uiCustomization ? 'custom' : 'advanced');
    $('#btn-open-dashboard-custom-config').toggleClass('d-none', !uiCustomization);
}

function openDashboardCustomConfigModal() {
    if (!dashboardCustomConfigModal) return;
    $('#dashboard-custom-config-alert').addClass('d-none').text('');
    $('#dashboard-custom-config-json').val('Cargando...');
    dashboardCustomConfigModal.show();
    $.ajax({
        url: 'api/admin.php?action=get_dashboard_custom_config',
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                showDashboardCustomConfigAlert('danger', res.error || 'No se pudo cargar el JSON personalizado.');
                $('#dashboard-custom-config-json').val('');
                return;
            }
            $('#dashboard-custom-config-json').val(res.json || '');
        },
        error: function () {
            showDashboardCustomConfigAlert('danger', 'Error de conexion al cargar el JSON personalizado.');
            $('#dashboard-custom-config-json').val('');
        }
    });
}

function saveDashboardCustomConfig(button) {
    const json = $('#dashboard-custom-config-json').val() || '';
    try {
        const parsed = JSON.parse(json);
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
            throw new Error('El JSON debe contener un objeto.');
        }
        if (!parsed.features || typeof parsed.features !== 'object' || Array.isArray(parsed.features)) {
            throw new Error('El JSON debe incluir el objeto "features".');
        }
        Object.keys(parsed.features).forEach(key => {
            if (typeof parsed.features[key] !== 'boolean') {
                throw new Error(`La opcion "${key}" debe ser true o false.`);
            }
        });
        if (Object.prototype.hasOwnProperty.call(parsed, 'texts') && (!parsed.texts || typeof parsed.texts !== 'object' || Array.isArray(parsed.texts))) {
            throw new Error('La seccion opcional "texts" debe ser un objeto.');
        }
    } catch (err) {
        showDashboardCustomConfigAlert('danger', err.message || 'JSON invalido.');
        return;
    }

    const $button = $(button);
    const original = $button.html();
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Guardando');
    $.ajax({
        url: 'api/admin.php?action=save_dashboard_custom_config',
        method: 'POST',
        dataType: 'json',
        data: { json },
        success: function (res) {
            if (!res.success) {
                showDashboardCustomConfigAlert('danger', res.error || 'No se pudo guardar el JSON personalizado.');
                return;
            }
            showDashboardCustomConfigAlert('success', res.message || 'Configuracion personalizada guardada. Se refrescara la ventana para cargar la nueva configuracion.');
            setTimeout(function () {
                window.location.reload();
            }, 1500);
        },
        error: function () {
            showDashboardCustomConfigAlert('danger', 'Error de conexion al guardar el JSON personalizado.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function showDashboardCustomConfigAlert(type, message) {
    const $alert = $('#dashboard-custom-config-alert');
    if (!$alert.length) return;
    $alert.removeClass('d-none alert-success alert-danger alert-warning alert-info')
        .addClass(`alert-${type}`)
        .text(message || '');
}

function loadPaymentSettings() {
    $('#settings-save-alert, #payment-settings-alert, #email-settings-alert, #calendar-settings-alert, #booking-settings-alert, #general-settings-alert, #interface-settings-alert, #legal-settings-alert, #services-settings-alert, #bonuses-settings-alert, #cabinet-settings-alert').addClass('d-none');
    $('#merchant-key').val('');
    $('#smtp-password').val('');
    $('#google-client-secret').val('');
    $('#google-refresh-token').val('');
    $('#merchant-key-status').text('');
    $('#smtp-password-status').text('');
    $('#google-client-secret-status').text('');

    return new Promise((resolve, reject) => {
        $.ajax({
        url: 'api/admin.php?action=get_payment_settings',
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                showPaymentSettingsAlert('danger', res.error || 'No se pudo cargar la configuración.');
                reject(new Error(res.error || 'No se pudo cargar la configuración.'));
                return;
            }

            let settings = res.settings || {};
            PAYMENT_SETTINGS = Object.assign({}, PAYMENT_SETTINGS, settings);
            APP_DASHBOARD_CONFIG = settings.dashboard_config || APP_DASHBOARD_CONFIG || { features: {} };
            APP_PLAN_CONFIG = settings.plan_config || APP_PLAN_CONFIG || { plan: { features: {} } };
            APP_KNOWLEDGE_BASE_ENABLED = appFeatureEnabled('knowledgeBase.enabled', false);
            APP_KNOWLEDGE_BASE_HAS_SECTOR_DATA = settings.knowledge_base_has_sector_data == 1;
            APP_SECTOR_TEXTS = settings.sector_texts || APP_SECTOR_TEXTS || {};
            APP_SECTOR_TEXT_OPTIONS = Array.isArray(settings.sector_texts_options) ? settings.sector_texts_options : APP_SECTOR_TEXT_OPTIONS;
            APPOINTMENT_SERVICES = Array.isArray(res.services) ? res.services : [];
            APPOINTMENT_BONUSES = Array.isArray(res.bonuses) ? res.bonuses : [];
            renderAvailableSessionControls();
            $('#app-name').val(settings.app_name || 'SimplyGest Praxis');
            $('#site-tagline').val(settings.site_tagline || '');
            $('#site-phone').val(settings.site_phone || '');
            const primaryColor = settings.primary_color || '#4285f4';
            $('#primary-color').val(primaryColor);
            $('#primary-color-text').val(primaryColor);
            document.documentElement.style.setProperty('--primary-color', primaryColor);
            $('#appointment-delivery-mode').val(settings.appointment_delivery_mode || 'both');
            document.title = `Dashboard - ${settings.app_name || 'SimplyGest Praxis'}`;
            $('#show-profile-image-public').prop('checked', settings.show_profile_image_public == 1);
            $('#show-prices-public').prop('checked', settings.show_prices_public == 1);
            $('#show-contact-public').prop('checked', settings.show_contact_public == 1);
            $('#legal-owner-name').val(settings.legal_owner_name || '');
            $('#legal-nif').val(settings.legal_nif || '');
            $('#legal-address').val(settings.legal_address || '');
            $('#legal-email').val(settings.legal_email || '');
            $('#legal-license-number').val(settings.legal_license_number || '');
            $('#legal-professional-college').val(settings.legal_professional_college || '');
            $('#legal-uses-non-technical-cookies').prop('checked', settings.legal_uses_non_technical_cookies == 1);
            $('#legal-terms-notes').val(settings.legal_terms_notes || '');
            const initialView = ['week', 'month', 'patients', 'upcoming'].includes(settings.initial_calendar_view) ? settings.initial_calendar_view : 'month';
            $('#initial-calendar-view').val(initialView);
            LOADED_DASHBOARD_CONFIG_MODE = ['simple', 'advanced', 'custom'].includes(settings.dashboard_config_mode) ? settings.dashboard_config_mode : 'simple';
            $('#dashboard-config-mode').val(LOADED_DASHBOARD_CONFIG_MODE);
            toggleDashboardConfigModeControls();
            $('#online-booking-enabled').prop('checked', settings.online_booking_enabled === undefined ? true : settings.online_booking_enabled == 1);
            $('#patient-tasks-visible-default').prop('checked', settings.patient_tasks_visible_default == 1);
            $('#work-plan-task-status-enabled').prop('checked', settings.work_plan_task_status_enabled === undefined ? true : settings.work_plan_task_status_enabled == 1);
            syncPatientWorkPlanStatusUi();
            if (CURRENT_PATIENT_WORK_PLAN_ROWS.length) {
                renderPatientWorkPlan(CURRENT_PATIENT_WORK_PLAN_ROWS);
            }
            $('#patient-registration-requires-invite').prop('checked', settings.patient_registration_mode !== 'open');
            $('#bonuses-enabled').prop('checked', settings.bonuses_enabled == 1);
            $('#create-compensation-bonus-on-paid-cancel').prop('checked', settings.create_compensation_bonus_on_paid_cancel === undefined ? true : settings.create_compensation_bonus_on_paid_cancel == 1);
            toggleBonusesSettings();
            renderBonusesSettings();
            captureBonusesSettingsSnapshot();
            setAvailableSessionDurations(settings.available_session_durations || '60');
            $('#display-effective-duration-enabled').prop('checked', settings.display_effective_duration_enabled == 1);
            $('#display-duration-offset-minutes').val(settings.display_duration_offset_minutes !== undefined && settings.display_duration_offset_minutes !== null ? settings.display_duration_offset_minutes : '5');
            renderServicesSettings();
            captureServicesSettingsSnapshot();
            if (settings.profile_image_path) {
                $('#profile-image-preview').attr('src', assetUrl(settings.profile_image_path));
                $('#profile-image-preview-row').attr('style', '');
                $('#profile-image-status').text('Imagen actual guardada.');
                $('#navbar-user-image').attr('src', assetUrl(settings.profile_image_path)).removeClass('d-none');
            } else {
                $('#profile-image-preview-row').attr('style', 'display: none !important;');
                $('#profile-image-preview').attr('src', '');
                $('#profile-image-status').text('');
                if (typeof INITIAL_NAVBAR_IMAGE_URL !== 'undefined' && INITIAL_NAVBAR_IMAGE_URL) {
                    $('#navbar-user-image').attr('src', INITIAL_NAVBAR_IMAGE_URL).removeClass('d-none');
                } else {
                    $('#navbar-user-image').attr('src', '').addClass('d-none');
                }
            }
            $('#profile-image').val('');
            if (settings.landing_image_path) {
                $('#landing-image-preview').attr('src', assetUrl(settings.landing_image_path));
                $('#landing-image-preview-row').attr('style', '');
                $('#landing-image-status').text('Imagen actual guardada.');
            } else {
                $('#landing-image-preview-row').attr('style', 'display: none !important;');
                $('#landing-image-preview').attr('src', '');
                $('#landing-image-status').text('');
            }
            $('#landing-image').val('');
            $('#online-payment-enabled').prop('checked', settings.online_payment_enabled == 1);
            togglePaymentSettings();
            $('#payment-environment').val(settings.environment || 'sandbox');
            $('#merchant-code').val(settings.merchant_code || '');
            $('#merchant-terminal').val(settings.terminal || '');
            setAvailableSessionTypes(settings.available_session_types || 'individual');
            $('#appointment-price').val(settings.appointment_price || '70.00');
            $('#online-appointment-price').val(settings.online_appointment_price || settings.appointment_price || '70.00');
            $('#couple-appointment-price').val(settings.couple_appointment_price || '90.00');
            $('#online-couple-appointment-price').val(settings.online_couple_appointment_price || settings.couple_appointment_price || '90.00');
            $('#min-booking-notice-days').val(settings.min_booking_notice_days !== undefined && settings.min_booking_notice_days !== null ? settings.min_booking_notice_days : '2');
            $('#max-booking-notice-days').val(settings.max_booking_notice_days || '0');
            $('#appointment-start-time').val((settings.appointment_start_time || '10:00').slice(0, 5));
            $('#appointment-end-time').val((settings.appointment_end_time || '19:00').slice(0, 5));
            $('#break-start-time').val(settings.break_start_time ? settings.break_start_time.slice(0, 5) : '15:00');
            $('#break-end-time').val(settings.break_end_time ? settings.break_end_time.slice(0, 5) : '16:00');
            setAvailableWeekdays(settings.available_weekdays || '1,2,3,4,5');
            $('#appointment-reminder-enabled').prop('checked', settings.appointment_reminder_enabled == 1);
            $('#email-provider').val(settings.email_provider || 'phpmailer');
            $('#smtp-host').val(settings.smtp_host || '');
            $('#smtp-port').val(settings.smtp_port || '587');
            $('#smtp-username').val(settings.smtp_username || '');
            $('#smtp-secure').val(settings.smtp_secure || 'tls');
            $('#smtp-from-email').val(settings.smtp_from_email || '');
            $('#smtp-from-name').val(settings.smtp_from_name || '');
            $('#google-client-id').val(settings.google_client_id || '');
            $('#google-connected-email').val(settings.google_connected_email || '');
            $('#google-redirect-uri').val(settings.google_redirect_uri || currentGoogleRedirectUri());
            $('#calendar-provider').val(settings.calendar_provider || (settings.google_calendar_enabled == 1 ? 'google' : 'none'));
            $('#google-calendar-id').val(settings.google_calendar_id || 'primary');
            $('#icloud-calendar-email').val(settings.icloud_calendar_email || '');
            $('#icloud-calendar-url').val(settings.icloud_calendar_url || 'https://caldav.icloud.com');
            $('#icloud-calendar-app-password').val('');
            $('#icloud-calendar-app-password-status').text(settings.has_icloud_calendar_app_password == 1
                ? 'Ya hay una contraseña específica de app guardada. Escribe una nueva solo si quieres cambiarla.'
                : 'Todavía no hay contraseña específica de app guardada.');
            $('#send-patient-calendar-link').prop('checked', settings.send_patient_calendar_link === undefined ? true : settings.send_patient_calendar_link == 1);
            toggleCalendarSettings();
            toggleEmailProviderSettings();
            $('#google-connected-status').text(settings.google_connected_email
                ? `Gmail conectado: ${settings.google_connected_email}`
                : 'Todavía no hay ninguna cuenta Gmail conectada.');

            if (settings.has_merchant_key == 1) {
                $('#merchant-key-status').text('Ya hay una clave guardada. Escribe una nueva solo si quieres cambiarla.');
            } else {
                $('#merchant-key-status').text('Todavía no hay ninguna clave guardada.');
            }

            $('#smtp-password-status').text(settings.has_smtp_password == 1
                ? 'Ya hay una contraseña SMTP guardada. Escribe una nueva solo si quieres cambiarla.'
                : 'Todavía no hay contraseña SMTP guardada.');
            $('#google-client-secret-status').text('');
            togglePriceRows();
            applyPlanFeatureVisibility();
            if (typeof IS_SUPERADMIN !== 'undefined' && IS_SUPERADMIN) {
                loadCabinetSettings()
                    .then(resolve)
                    .catch(reject);
            } else {
                resolve(res);
            }
        },
        error: function () {
            const message = 'Error de conexión al cargar la configuración.';
            showPaymentSettingsAlert('danger', message);
            reject(new Error(message));
        }
        });
    });
}

function toggleEmailProviderSettings() {
    let provider = $('#email-provider').val();
    $('#smtp-settings-block').toggle(provider === 'phpmailer');
    $('#google-email-settings-block').toggle(provider === 'google');
    togglePatientCalendarLinkSettings();
}

function setFieldBlockEnabled(selector, enabled) {
    $(selector).toggleClass('settings-disabled', !enabled);
    $(selector).find('input, select, textarea, button').prop('disabled', !enabled);
}

function togglePaymentSettings() {
    const enabled = $('#online-payment-enabled').is(':checked');
    const gatewayFields = '#payment-environment, #merchant-code, #merchant-terminal, #merchant-key';
    $(gatewayFields).prop('disabled', !enabled).closest('.mb-3').toggleClass('settings-disabled', !enabled);
    togglePriceRows();
}

function togglePriceRows() {
    const deliveryMode = $('#appointment-delivery-mode').val() || 'both';
    const hasCouple = $('#available-session-couple').is(':checked');
    $('#online-appointment-price-row').toggle(deliveryMode !== 'presencial');
    $('.couple-price-row').toggle(hasCouple);
    $('#online-couple-appointment-price-row').toggle(hasCouple && deliveryMode !== 'presencial');
}

function toggleCalendarSettings() {
    const provider = $('#calendar-provider').val() || 'none';
    $('#calendar-provider-none-fields').toggle(provider === 'none');
    $('#google-calendar-config-fields').toggle(provider === 'google');
    $('#icloud-calendar-config-fields').toggle(provider === 'icloud');
    setFieldBlockEnabled('#google-calendar-config-fields', provider === 'google');
    setFieldBlockEnabled('#icloud-calendar-config-fields', provider === 'icloud');
    togglePatientCalendarLinkSettings();
}

function patientCalendarEmailSettingsReady() {
    const provider = $('#email-provider').val() || PAYMENT_SETTINGS.email_provider || 'phpmailer';
    if (provider === 'google') {
        return Boolean(($('#google-connected-email').val() || '').trim() && PAYMENT_SETTINGS.has_google_refresh_token == 1);
    }

    return true;
}

function togglePatientCalendarLinkSettings() {
    const ready = patientCalendarEmailSettingsReady();
    $('#send-patient-calendar-link').prop('disabled', !ready);
    $('#send-patient-calendar-link-status').text(ready
        ? `El ${sectorLabel('patient', 'singular', 'paciente')} recibirá un enlace .ics compatible con Apple Calendar, iCloud, Google Calendar y Outlook.`
        : `Conecta primero la cuenta de email para poder enviar este enlace a los ${sectorLabel('patient', 'plural', 'pacientes')}.`);
}

function toggleBonusesSettings() {
    setFieldBlockEnabled('#bonuses-config-block', $('#bonuses-enabled').is(':checked'));
}

function renderServicesSettings() {
    const $body = $('#services-settings-body');
    if (!$body.length) {
        return;
    }
    if (!APPOINTMENT_SERVICES.length) {
        $body.html('<tr><td colspan="4" class="text-muted text-center py-4">No hay precios configurados.</td></tr>');
        return;
    }

    const visibleDurations = selectedSessionDurations();
    const visibleMode = $('#appointment-delivery-mode').val() || PAYMENT_SETTINGS.appointment_delivery_mode || 'both';
    const activeTypes = selectedSessionTypes();
    let html = '';
    APPOINTMENT_SERVICES.forEach(service => {
        const serviceKey = service.service_key || 'individual';
        if (!activeTypes.includes(serviceKey)) {
            return;
        }
        const serviceOptions = (Array.isArray(service.options) ? service.options : [])
            .filter(option => visibleDurations.includes(parseInt(option.duration_minutes, 10)))
            .filter(option => visibleMode === 'both' || option.consultation_type === visibleMode);
        if (!serviceOptions.length) {
            return;
        }
        html += `
            <tr class="service-group-row" data-service-id="${service.id}">
                <td colspan="4">
                    <input type="text" class="form-control form-control-sm service-name-input" value="${escapeHtml(service.name || '')}">
                </td>
            </tr>
        `;
        serviceOptions.forEach(option => {
            html += `
                <tr class="service-option-row" data-service-id="${service.id}" data-option-id="${option.id}" data-duration="${option.duration_minutes}">
                    <td class="text-muted small">${escapeHtml(service.name || '')}</td>
                    <td>${option.duration_minutes} minutos</td>
                    <td>${consultationTypeLabel(option.consultation_type)}</td>
                    <td>
                        <div class="input-group input-group-sm">
                            <input type="number" class="form-control option-price-input" min="0" step="0.01" value="${option.price}">
                            <span class="input-group-text">€</span>
                        </div>
                    </td>
                </tr>
            `;
        });
    });
    $body.html(html || '<tr><td colspan="4" class="text-muted text-center py-4">No hay precios para la configuración seleccionada.</td></tr>');
}

function renderBonusesSettings() {
    const $body = $('#bonuses-settings-body');
    if (!$body.length) {
        return;
    }
    if (!APPOINTMENT_BONUSES.length) {
        $body.html('<tr><td colspan="4" class="text-muted text-center py-4">No hay bonos configurados.</td></tr>');
        return;
    }

    let html = '';
    APPOINTMENT_BONUSES.forEach(bonus => {
        html += `
            <tr class="bonus-row" data-bonus-id="${bonus.id}">
                <td>
                    <input type="text" class="form-control form-control-sm bonus-name-input" value="${escapeHtml(bonus.name || '')}">
                </td>
                <td>${bonus.session_count}</td>
                <td>
                    <div class="input-group input-group-sm">
                        <input type="number" class="form-control bonus-price-input" min="0" step="0.01" value="${bonus.price}">
                        <span class="input-group-text">€</span>
                    </div>
                </td>
                <td class="text-center">
                    <input class="form-check-input bonus-active-input" type="checkbox" ${bonus.is_active == 1 ? 'checked' : ''}>
                </td>
            </tr>
        `;
    });
    $body.html(html);
}

function stableSettingsString(value) {
    return JSON.stringify(value);
}

function collectBonusesSettings() {
    return APPOINTMENT_BONUSES.map(bonus => {
        const $row = $(`.bonus-row[data-bonus-id="${bonus.id}"]`);
        return {
            id: bonus.id,
            name: $row.find('.bonus-name-input').val().trim(),
            session_count: parseInt(bonus.session_count, 10),
            price: $row.find('.bonus-price-input').val(),
            is_active: $row.find('.bonus-active-input').is(':checked') ? 1 : 0
        };
    });
}

function collectBonusesSettingsPayload() {
    return {
        bonuses_enabled: $('#bonuses-enabled').is(':checked') ? 1 : 0,
        create_compensation_bonus_on_paid_cancel: $('#create-compensation-bonus-on-paid-cancel').is(':checked') ? 1 : 0,
        bonuses: collectBonusesSettings()
    };
}

function captureBonusesSettingsSnapshot() {
    if (!$('#bonuses-settings-body').length || !APPOINTMENT_BONUSES.length) {
        SETTINGS_SNAPSHOTS.bonuses = '';
        return;
    }
    SETTINGS_SNAPSHOTS.bonuses = stableSettingsString(collectBonusesSettingsPayload());
}

function bonusesSettingsChanged() {
    return $('#bonuses-settings-body').length
        && SETTINGS_SNAPSHOTS.bonuses !== ''
        && stableSettingsString(collectBonusesSettingsPayload()) !== SETTINGS_SNAPSHOTS.bonuses;
}

function saveBonusesSettings(button = null, options = {}) {
    $('#bonuses-settings-alert').addClass('d-none');
    if (!options.suppressLoading) {
        setSettingsButtonLoading(button, true);
    }
    const payload = collectBonusesSettingsPayload();

    return new Promise((resolve, reject) => {
        $.ajax({
            url: 'api/admin.php?action=save_bonuses',
            method: 'POST',
            dataType: 'json',
            data: {
                bonuses_enabled: String(payload.bonuses_enabled),
                create_compensation_bonus_on_paid_cancel: String(payload.create_compensation_bonus_on_paid_cancel),
                bonuses_json: JSON.stringify(payload.bonuses)
            },
            success: function (res) {
                if (res.success) {
                    APPOINTMENT_BONUSES = Array.isArray(res.bonuses) ? res.bonuses : APPOINTMENT_BONUSES;
                    renderBonusesSettings();
                    captureBonusesSettingsSnapshot();
                    if (!options.silentSuccess) {
                        showSettingsAlert('#bonuses-settings-alert', 'success', res.message || 'Bonos guardados correctamente.');
                    }
                    resolve(res);
                } else {
                    const message = res.error || 'No se pudieron guardar los bonos.';
                    if (!options.silentError) {
                        showSettingsAlert('#bonuses-settings-alert', 'danger', message);
                    }
                    reject(new Error(message));
                }
            },
            error: function () {
                const message = 'Error de conexión al guardar los bonos.';
                if (!options.silentError) {
                    showSettingsAlert('#bonuses-settings-alert', 'danger', message);
                }
                reject(new Error(message));
            },
            complete: function () {
                if (!options.suppressLoading) {
                    setSettingsButtonLoading(button, false);
                }
            }
        });
    });
}

function selectedSessionDurations() {
    const durations = [];
    const allowed = appointmentDurationCatalog().map(item => item.minutes);
    $('.available-session-duration:checked').each(function () {
        const duration = parseInt(this.value, 10);
        if (allowed.includes(duration)) {
            durations.push(duration);
        }
    });
    return durations.length ? [...new Set(durations)].sort((a, b) => a - b) : defaultAppointmentDurations();
}

function collectServicesSettings() {
    const visibleDurations = selectedSessionDurations();
    const visibleMode = $('#appointment-delivery-mode').val() || PAYMENT_SETTINGS.appointment_delivery_mode || 'both';
    const activeTypes = selectedSessionTypes();
    return APPOINTMENT_SERVICES.map(service => {
        const $serviceRow = $(`.service-group-row[data-service-id="${service.id}"]`);
        const serviceKey = service.service_key || 'individual';
        const serviceVisible = activeTypes.includes(serviceKey);
        const options = (service.options || []).map(option => {
            const $optionRow = $(`.service-option-row[data-option-id="${option.id}"]`);
            const isVisible = visibleDurations.includes(parseInt(option.duration_minutes, 10))
                && (visibleMode === 'both' || option.consultation_type === visibleMode)
                && serviceVisible;
            return {
                id: option.id,
                duration_minutes: parseInt(option.duration_minutes, 10),
                consultation_type: option.consultation_type,
                price: isVisible ? $optionRow.find('.option-price-input').val() : option.price,
                is_active: isVisible ? 1 : 0
            };
        });
        return {
            id: service.id,
            name: ($serviceRow.find('.service-name-input').val() || service.name || '').trim(),
            is_active: serviceVisible ? 1 : 0,
            options
        };
    });
}

function captureServicesSettingsSnapshot() {
    if (!$('#services-settings-body').length || !APPOINTMENT_SERVICES.length) {
        SETTINGS_SNAPSHOTS.services = '';
        return;
    }
    SETTINGS_SNAPSHOTS.services = stableSettingsString(collectServicesSettings());
}

function servicesSettingsChanged() {
    return $('#services-settings-body').length
        && SETTINGS_SNAPSHOTS.services !== ''
        && stableSettingsString(collectServicesSettings()) !== SETTINGS_SNAPSHOTS.services;
}

function saveServicesSettings(button = null, options = {}) {
    $('#services-settings-alert').addClass('d-none');
    if (!options.suppressLoading) {
        setSettingsButtonLoading(button, true);
    }

    return new Promise((resolve, reject) => {
        $.ajax({
            url: 'api/admin.php?action=save_services',
            method: 'POST',
            dataType: 'json',
            data: {
                services_json: JSON.stringify(collectServicesSettings()),
                appointment_delivery_mode: $('#appointment-delivery-mode').val(),
                available_session_durations: selectedSessionDurations()
            },
            success: function (res) {
                if (res.success) {
                    APPOINTMENT_SERVICES = Array.isArray(res.services) ? res.services : APPOINTMENT_SERVICES;
                    renderServicesSettings();
                    captureServicesSettingsSnapshot();
                    if (!options.skipCalendarRefresh) {
                        renderWeekInfo();
                    }
                    if (!options.silentSuccess) {
                        showSettingsAlert('#services-settings-alert', 'success', res.message || 'Precios guardados correctamente.');
                    }
                    resolve(res);
                } else {
                    const message = res.error || 'No se pudieron guardar los precios.';
                    if (!options.silentError) {
                        showSettingsAlert('#services-settings-alert', 'danger', message);
                    }
                    reject(new Error(message));
                }
            },
            error: function () {
                const message = 'Error de conexión al guardar los precios.';
                if (!options.silentError) {
                    showSettingsAlert('#services-settings-alert', 'danger', message);
                }
                reject(new Error(message));
            },
            complete: function () {
                if (!options.suppressLoading) {
                    setSettingsButtonLoading(button, false);
                }
            }
        });
    });
}

function loadCabinetSettings() {
    const $body = $('#professionals-settings-body');
    if (!$body.length) return Promise.resolve();
    $body.html('<tr><td colspan="6" class="text-center text-muted py-4">Cargando...</td></tr>');
    $('#cabinet-settings-loading').removeClass('d-none');
    $('#show-team-public, #allow-patient-transfer, #new-patient-booking-mode, #new-patient-fixed-professional').prop('disabled', true);

    return new Promise((resolve, reject) => {
        $.ajax({
        url: 'api/admin.php?action=get_cabinet_settings',
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                showSettingsAlert('#cabinet-settings-alert', 'danger', res.error || 'No se pudo cargar el equipo profesional.');
                reject(new Error(res.error || 'No se pudo cargar el equipo profesional.'));
                return;
            }
            $('#show-team-public').prop('checked', res.settings && res.settings.show_team_public == 1);
            $('#allow-patient-transfer').prop('checked', res.settings && res.settings.allow_patient_transfer == 1);
            CABINET_PROFESSIONALS = (res.professionals || []).map(normalizeProfessional);
            populateNewPatientFixedProfessionalSelect(res.settings && res.settings.new_patient_fixed_professional_id);
            $('#new-patient-booking-mode').val((res.settings && res.settings.new_patient_booking_mode) || 'day_first');
            updateNewPatientBookingModeUi();
            renderProfessionalsSettings();
            captureCabinetSettingsSnapshot();
            resolve(res);
        },
        error: function () {
            const message = 'Error de conexión al cargar el equipo profesional.';
            showSettingsAlert('#cabinet-settings-alert', 'danger', message);
            reject(new Error(message));
        },
        complete: function () {
            $('#cabinet-settings-loading').addClass('d-none');
            $('#show-team-public, #allow-patient-transfer, #new-patient-booking-mode, #new-patient-fixed-professional').prop('disabled', false);
            updateNewPatientBookingModeUi();
        }
        });
    });
}

function populateNewPatientFixedProfessionalSelect(selectedId = null) {
    const $select = $('#new-patient-fixed-professional');
    if (!$select.length) return;
    const activeProfessionals = CABINET_PROFESSIONALS.filter(professional => professional.is_active != 0);
    const superadmin = activeProfessionals.find(professional => professional.role === 'superadmin');
    const fallbackProfessional = superadmin || activeProfessionals[0] || null;
    const selected = parseInt(selectedId || (fallbackProfessional ? fallbackProfessional.id : 0) || 0, 10);
    let html = '';
    activeProfessionals.forEach(professional => {
        html += `<option value="${professional.id}" ${parseInt(professional.id || 0, 10) === selected ? 'selected' : ''}>${escapeHtml(professional.display_name || 'Sin nombre')}</option>`;
    });
    $select.html(html);
    if (selected > 0) {
        $select.val(String(selected));
    }
}

function updateNewPatientBookingModeUi() {
    const mode = $('#new-patient-booking-mode').val() || 'day_first';
    const showFixedProfessional = mode === 'fixed_professional';
    $('#new-patient-fixed-professional-row').toggleClass('d-none', !showFixedProfessional);
    $('#new-patient-fixed-professional').prop('disabled', !showFixedProfessional || $('#cabinet-settings-loading').is(':visible'));
}

function normalizeProfessional(professional = {}) {
    return {
        id: parseInt(professional.id || 0, 10),
        user_id: parseInt(professional.user_id || 0, 10),
        display_name: professional.display_name || '',
        professional_title: professional.professional_title || '',
        license_number: professional.license_number || '',
        professional_specialty: professional.professional_specialty || '',
        public_bio: professional.public_bio || '',
        public_photo_path: professional.public_photo_path || '',
        display_photo_path: professional.display_photo_path || professional.public_photo_path || '',
        email: professional.email || '',
        public_phone: professional.public_phone || '',
        instagram_url: professional.instagram_url || '',
        facebook_url: professional.facebook_url || '',
        tiktok_url: professional.tiktok_url || '',
        appointment_summary_email_mode: professional.appointment_summary_email_mode || 'on_booking',
        knowledge_sector_mode: ['own', 'related', 'custom'].includes(professional.knowledge_sector_mode) ? professional.knowledge_sector_mode : 'own',
        knowledge_sector_keys: normalizeKnowledgeSectorKeys(professional.knowledge_sector_keys || []),
        role: professional.role === 'superadmin' ? 'superadmin' : 'admin',
        is_active: professional.is_active == 0 ? 0 : 1,
        is_current_user: professional.is_current_user == 1 ? 1 : 0
    };
}

function professionalSummaryEmailReady() {
    const provider = $('#email-provider').val() || PAYMENT_SETTINGS.email_provider || 'phpmailer';
    const professionalEmail = ($('#professional-editor-email').val() || '').trim();
    let systemReady = false;
    if (provider === 'google') {
        systemReady = Boolean((PAYMENT_SETTINGS.google_connected_email || $('#google-connected-email').val() || '').trim()
            && PAYMENT_SETTINGS.has_google_refresh_token == 1);
    } else {
        systemReady = Boolean((PAYMENT_SETTINGS.smtp_from_email || $('#smtp-from-email').val() || '').trim());
    }
    return systemReady && Boolean(professionalEmail);
}

function updateProfessionalSummaryEmailUi() {
    const $select = $('#professional-editor-summary-mode');
    if (!$select.length) return;
    const ready = professionalSummaryEmailReady();
    $select.prop('disabled', !ready);
    $('#professional-editor-summary-status').html(ready
        ? 'Se enviar&aacute; al email de acceso del profesional usando la cuenta de email del sistema.'
        : 'Disponible cuando haya cuenta de email del sistema y email del profesional configurados.');
}

function showProfessionalEditorAlert(type, message) {
    $('#professional-editor-alert')
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .text(message || '');
}

function normalizeKnowledgeSectorKeys(keys) {
    if (typeof keys === 'string') {
        try {
            const parsed = JSON.parse(keys);
            keys = Array.isArray(parsed) ? parsed : keys.split(',');
        } catch (err) {
            keys = keys.split(',');
        }
    }
    if (!Array.isArray(keys)) return [];
    const normalized = [];
    keys.forEach(key => {
        key = String(key || '').trim().toLowerCase();
        if (key && !normalized.includes(key)) {
            normalized.push(key);
        }
    });
    return normalized;
}

function knowledgeSectorName(key) {
    key = String(key || '').toLowerCase();
    const sector = (KNOWLEDGE_SECTOR_OPTIONS.sectors || []).find(item => String(item.key || '').toLowerCase() === key);
    if (sector && sector.name) return sector.name;
    const textOption = (APP_SECTOR_TEXT_OPTIONS || []).find(item => String(item.key || '').toLowerCase() === key);
    return textOption && textOption.name ? textOption.name : capitalizeFirst(key.replace(/[_-]+/g, ' '));
}

function loadKnowledgeSectorOptions(force = false) {
    if (KNOWLEDGE_SECTOR_OPTIONS.loaded && !force) {
        return $.Deferred().resolve(KNOWLEDGE_SECTOR_OPTIONS).promise();
    }
    return $.ajax({
        url: 'api/admin.php?action=knowledge_sector_options',
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                KNOWLEDGE_SECTOR_OPTIONS = {
                    loaded: true,
                    enabled: false,
                    multi_sector_enabled: false,
                    main_sector: APP_CURRENT_SECTOR_KEY,
                    related_sectors: [],
                    sectors: []
                };
                return;
            }
            KNOWLEDGE_SECTOR_OPTIONS = {
                loaded: true,
                enabled: Boolean(res.enabled),
                multi_sector_enabled: Boolean(res.multi_sector_enabled),
                main_sector: String(res.main_sector || APP_CURRENT_SECTOR_KEY || '').toLowerCase(),
                related_sectors: normalizeKnowledgeSectorKeys(res.related_sectors || []),
                sectors: Array.isArray(res.sectors) ? res.sectors : []
            };
            updateProfessionalKnowledgeSectorUi();
        },
        error: function () {
            KNOWLEDGE_SECTOR_OPTIONS.loaded = true;
            KNOWLEDGE_SECTOR_OPTIONS.enabled = false;
            updateProfessionalKnowledgeSectorUi();
        }
    });
}

function professionalEditorSelectedKnowledgeKeys() {
    return normalizeKnowledgeSectorKeys($('#professional-editor-knowledge-sector-keys').val() || '[]');
}

function setProfessionalEditorSelectedKnowledgeKeys(keys) {
    const main = KNOWLEDGE_SECTOR_OPTIONS.main_sector || APP_CURRENT_SECTOR_KEY || '';
    keys = normalizeKnowledgeSectorKeys(keys);
    if (main && !keys.includes(main)) {
        keys.unshift(main);
    }
    $('#professional-editor-knowledge-sector-keys').val(JSON.stringify(keys));
}

function updateProfessionalKnowledgeSectorUi() {
    const $row = $('#professional-editor-knowledge-row');
    if (!$row.length) return;
    const enabled = Boolean(KNOWLEDGE_SECTOR_OPTIONS.enabled);
    const allowed = enabled && Boolean(KNOWLEDGE_SECTOR_OPTIONS.multi_sector_enabled);
    const mode = allowed ? ($('#professional-editor-knowledge-mode').val() || 'own') : 'own';
    $('#professional-editor-knowledge-mode').prop('disabled', !allowed).val(mode);
    $('#btn-professional-knowledge-sectors').toggleClass('d-none', !allowed || mode !== 'custom');
}

function renderProfessionalKnowledgeSectorsModal() {
    const $list = $('#professional-knowledge-sectors-list');
    if (!$list.length) return;
    const main = KNOWLEDGE_SECTOR_OPTIONS.main_sector || APP_CURRENT_SECTOR_KEY || '';
    const selected = professionalEditorSelectedKnowledgeKeys();
    const sectors = Array.isArray(KNOWLEDGE_SECTOR_OPTIONS.sectors) ? KNOWLEDGE_SECTOR_OPTIONS.sectors : [];
    if (!sectors.length) {
        $list.html('<div class="text-muted">No hay sectores con base de conocimiento disponible.</div>');
        return;
    }
    const related = normalizeKnowledgeSectorKeys(KNOWLEDGE_SECTOR_OPTIONS.related_sectors || []);
    const sectorRank = sector => {
        const key = String(sector.key || '').toLowerCase();
        if (key === main) return 0;
        if (related.includes(key)) return 1;
        return 2;
    };
    const sortedSectors = sectors.slice().sort((a, b) => {
        const rankDiff = sectorRank(a) - sectorRank(b);
        if (rankDiff !== 0) return rankDiff;
        const nameA = String(a.name || knowledgeSectorName(a.key || '') || '').toLowerCase();
        const nameB = String(b.name || knowledgeSectorName(b.key || '') || '').toLowerCase();
        return nameA.localeCompare(nameB, 'es');
    });
    $list.html(sortedSectors.map(sector => {
        const key = String(sector.key || '').toLowerCase();
        const checked = key === main || selected.includes(key);
        const disabled = key === main;
        const total = parseInt(sector.total || 0, 10);
        const isRelated = related.includes(key) && key !== main;
        return `
            <label class="form-check border rounded m-0 professional-knowledge-sector-option">
                <input class="form-check-input professional-knowledge-sector-check" type="checkbox" value="${escapeHtml(key)}" ${checked ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
                <span class="form-check-label">
                    <strong>${escapeHtml(sector.name || knowledgeSectorName(key))}</strong>
                    ${disabled ? '<span class="badge text-bg-primary ms-2">Principal</span>' : ''}
                    ${isRelated ? '<span class="badge text-bg-light ms-2">Relacionado</span>' : ''}
                    ${total ? `<span class="text-muted small ms-2">${total} entradas</span>` : ''}
                </span>
            </label>
        `;
    }).join(''));
}

function openProfessionalKnowledgeSectorsModal() {
    if (!professionalKnowledgeSectorsModal) return;
    loadKnowledgeSectorOptions().always(function () {
        renderProfessionalKnowledgeSectorsModal();
        professionalKnowledgeSectorsModal.show();
    });
}

function saveProfessionalKnowledgeSectorsSelection() {
    const keys = [];
    $('.professional-knowledge-sector-check:checked').each(function () {
        keys.push(this.value);
    });
    setProfessionalEditorSelectedKnowledgeKeys(keys);
    updateProfessionalKnowledgeSectorUi();
    if (professionalKnowledgeSectorsModal) {
        professionalKnowledgeSectorsModal.hide();
    }
}

function renderProfessionalsSettings() {
    const $body = $('#professionals-settings-body');
    if (!$body.length) return;
    if (!CABINET_PROFESSIONALS.length) {
        $body.html('<tr><td colspan="5" class="text-center text-muted py-4">No hay profesionales configurados.</td></tr>');
        return;
    }

    $body.html(CABINET_PROFESSIONALS.map(professionalSettingsRowHtml).join(''));
}

function professionalSettingsRowHtml(professional = {}, index = 0) {
    const role = professional.role === 'superadmin' ? 'superadmin' : 'admin';
    const isCurrentSuperadmin = role === 'superadmin'
        && (professional.is_current_user == 1 || (typeof CURRENT_USER_ID !== 'undefined' && parseInt(professional.user_id || 0, 10) === parseInt(CURRENT_USER_ID || 0, 10)));
    const roleCell = role === 'superadmin'
        ? '<span class="badge bg-primary">Superadmin</span>'
        : '<span class="badge bg-secondary">Admin</span>';
    const activeCell = isCurrentSuperadmin
        ? '<span class="badge bg-success">Activo</span>'
        : `<input type="checkbox" class="form-check-input professional-active" data-index="${index}" ${professional.is_active == 0 ? '' : 'checked'}>`;
    const actionsCell = `<div class="professional-actions">` + (isCurrentSuperadmin ? '' : `
            <button class="btn btn-outline-danger btn-sm btn-delete-professional" type="button" data-index="${index}" title="Borrar profesional">
                <i class="bi bi-trash"></i>
            </button>`) + `<button class="btn btn-outline-primary btn-sm btn-edit-professional" type="button" data-index="${index}" title="Editar profesional">
                <i class="bi bi-pencil"></i>
            </button></div>`;
    const photo = professional.display_photo_path || professional.public_photo_path || '';
    const avatar = photo
        ? `<img src="${escapeHtml(assetUrl(photo))}" alt="" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">`
        : '<span class="d-inline-flex align-items-center justify-content-center bg-light text-muted border" style="width: 38px; height: 38px; border-radius: 50%;"><i class="bi bi-person"></i></span>';
    return `
        <tr class="professional-settings-row" data-index="${index}">
            <td>
                <div class="d-flex align-items-center gap-2">
                    ${avatar}
                    <strong>${escapeHtml(professional.display_name || 'Sin nombre')}</strong>
                </div>
            </td>
            <td>
                ${escapeHtml(professional.email || 'Sin email')}
            </td>
            <td>
                ${roleCell}
            </td>
            <td class="text-center">
                ${activeCell}
            </td>
            <td class="text-end">
                ${actionsCell}
            </td>
        </tr>
    `;
}

function openProfessionalEditor(index = -1) {
    if (!professionalEditorModal) return;
    const professional = index >= 0 ? CABINET_PROFESSIONALS[index] : normalizeProfessional({ is_active: 1, role: 'admin' });
    if (!professional) return;
    const isCurrentSuperadmin = professional.role === 'superadmin'
        && (professional.is_current_user == 1 || (typeof CURRENT_USER_ID !== 'undefined' && parseInt(professional.user_id || 0, 10) === parseInt(CURRENT_USER_ID || 0, 10)));

    $('#professional-editor-title').text(index >= 0 ? 'Editar miembro' : 'Nuevo miembro');
    $('#professional-editor-alert').addClass('d-none').removeClass('alert-success alert-danger').text('');
    $('#professional-editor-index').val(index);
    $('#professional-editor-id').val(professional.id || 0);
    $('#professional-editor-user-id').val(professional.user_id || 0);
    $('#professional-editor-name').val(professional.display_name || '');
    $('#professional-editor-email').val(professional.email || '');
    $('#professional-editor-title-field').val(professional.professional_title || '');
    $('#professional-editor-license').val(professional.license_number || '');
    $('#professional-editor-specialty').val(professional.professional_specialty || '');
    $('#professional-editor-bio').val(professional.public_bio || '');
    $('#professional-editor-phone').val(professional.public_phone || '');
    $('#professional-editor-instagram').val(professional.instagram_url || '');
    $('#professional-editor-facebook').val(professional.facebook_url || '');
    $('#professional-editor-tiktok').val(professional.tiktok_url || '');
    $('#professional-editor-summary-mode').val(professional.appointment_summary_email_mode || 'on_booking');
    $('#professional-editor-knowledge-mode').val(professional.knowledge_sector_mode || 'own');
    setProfessionalEditorSelectedKnowledgeKeys(professional.knowledge_sector_keys || []);
    updateProfessionalKnowledgeSectorUi();
    loadKnowledgeSectorOptions();
    $('#professional-editor-photo').val('');
    PROFESSIONAL_PHOTO_FILE = null;
    const photo = professional.public_photo_path || professional.display_photo_path || '';
    if (photo) {
        $('#professional-editor-photo-preview').attr('src', assetUrl(photo)).removeClass('d-none');
        $('#professional-editor-photo-status').text('Foto actual. Sube una nueva solo si quieres cambiarla.');
    } else {
        $('#professional-editor-photo-preview').attr('src', '').addClass('d-none');
        $('#professional-editor-photo-status').text('Formatos permitidos: JPG, PNG, WEBP o GIF. Maximo 5 MB.');
    }
    $('#professional-editor-role').val(professional.role || 'admin');
    $('#professional-editor-active').prop('checked', professional.is_active != 0);

    $('.professional-editor-permission-wrap, .professional-editor-status-wrap').toggleClass('d-none', isCurrentSuperadmin);
    updateProfessionalSummaryEmailUi();
    professionalEditorModal.show();
}

function saveProfessionalEditor(button = null) {
    const index = parseInt($('#professional-editor-index').val() || '-1', 10);
    const existing = index >= 0 ? CABINET_PROFESSIONALS[index] : {};
    const isCurrentSuperadmin = existing && existing.role === 'superadmin'
        && (existing.is_current_user == 1 || (typeof CURRENT_USER_ID !== 'undefined' && parseInt(existing.user_id || 0, 10) === parseInt(CURRENT_USER_ID || 0, 10)));
    const professional = normalizeProfessional({
        id: $('#professional-editor-id').val(),
        user_id: $('#professional-editor-user-id').val(),
        display_name: $('#professional-editor-name').val().trim(),
        email: $('#professional-editor-email').val().trim(),
        professional_title: $('#professional-editor-title-field').val().trim(),
        license_number: $('#professional-editor-license').val().trim(),
        professional_specialty: $('#professional-editor-specialty').val().trim(),
        public_bio: $('#professional-editor-bio').val().trim(),
        public_phone: $('#professional-editor-phone').val().trim(),
        instagram_url: $('#professional-editor-instagram').val().trim(),
        facebook_url: $('#professional-editor-facebook').val().trim(),
        tiktok_url: $('#professional-editor-tiktok').val().trim(),
        appointment_summary_email_mode: $('#professional-editor-summary-mode').val() || 'on_booking',
        knowledge_sector_mode: $('#professional-editor-knowledge-mode').val() || 'own',
        knowledge_sector_keys: professionalEditorSelectedKnowledgeKeys(),
        public_photo_path: existing.public_photo_path || '',
        display_photo_path: existing.display_photo_path || existing.public_photo_path || '',
        role: isCurrentSuperadmin ? 'superadmin' : $('#professional-editor-role').val(),
        is_active: isCurrentSuperadmin ? 1 : ($('#professional-editor-active').is(':checked') ? 1 : 0),
        is_current_user: existing.is_current_user || 0
    });

    if (!professional.display_name || !professional.email) {
        showProfessionalEditorAlert('danger', 'Indica nombre y email del miembro.');
        return;
    }
    if (index >= 0) {
        CABINET_PROFESSIONALS[index] = professional;
    } else {
        CABINET_PROFESSIONALS.push(professional);
    }
    const fileInput = document.getElementById('professional-editor-photo');
    const selectedPhotoFile = fileInput && fileInput.files && fileInput.files[0]
        ? fileInput.files[0]
        : PROFESSIONAL_PHOTO_FILE;

    saveCabinetSettings(button, {
        closeProfessionalModal: true,
        errorAlertSelector: '#professional-editor-alert',
        photoFile: selectedPhotoFile,
        photoIndex: index >= 0 ? index : CABINET_PROFESSIONALS.length - 1
    });
}

function openPatientSelfDataModal() {
    if (!patientSelfDataModal) return;
    $('#patient-self-data-form')[0].reset();
    $('#patient-self-data-alert').addClass('d-none').removeClass('alert-success alert-danger').text('');
    $('#patient-self-photo-status').text('Formatos permitidos: JPG, PNG, WEBP o GIF. Maximo 2 MB.');

    $.ajax({
        url: 'api/auth.php?action=my_profile',
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                $('#patient-self-data-alert').removeClass('d-none alert-success').addClass('alert-danger').text(res.error || 'No se pudieron cargar tus datos.');
                return;
            }
            const profile = res.profile || {};
            $('#patient-self-email').val(profile.email || '');
            $('#patient-self-phone').val(profile.phone || '');
            const photo = profile.photo_path || '';
            if (photo) {
                $('#patient-self-photo-preview').attr('src', assetUrl(photo)).removeClass('d-none');
            } else {
                $('#patient-self-photo-preview').attr('src', '').addClass('d-none');
            }
        },
        error: function () {
            $('#patient-self-data-alert').removeClass('d-none alert-success').addClass('alert-danger').text('Error de conexion al cargar tus datos.');
        }
    });

    patientSelfDataModal.show();
}

function savePatientSelfData(form) {
    const $button = $('#btn-save-patient-self-data');
    const original = $button.html();
    const $alert = $('#patient-self-data-alert');
    const formData = new FormData(form);

    $alert.addClass('d-none').removeClass('alert-success alert-danger').text('');
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Guardando');

    $.ajax({
        url: 'api/auth.php?action=save_my_profile',
        method: 'POST',
        dataType: 'json',
        data: formData,
        processData: false,
        contentType: false,
        success: function (res) {
            if (!res.success) {
                $alert.removeClass('d-none alert-success').addClass('alert-danger').text(res.error || 'No se pudieron guardar tus datos.');
                return;
            }
            const profile = res.profile || {};
            if (profile.photo_path) {
                $('#navbar-user-image').attr('src', assetUrl(profile.photo_path)).removeClass('d-none');
                $('#patient-self-photo-preview').attr('src', assetUrl(profile.photo_path)).removeClass('d-none');
            }
            $alert.removeClass('d-none alert-danger').addClass('alert-success').text(res.message || 'Datos actualizados correctamente.');
            setTimeout(function () {
                if (patientSelfDataModal) {
                    patientSelfDataModal.hide();
                }
            }, 1200);
        },
        error: function () {
            $alert.removeClass('d-none alert-success').addClass('alert-danger').text('Error de conexion al guardar tus datos.');
        },
        complete: function () {
            $button.prop('disabled', false).html(original);
        }
    });
}

function changeOwnPassword(button = null) {
    const currentPassword = $('#current-password').val();
    const newPassword = $('#new-password').val();
    const confirmPassword = $('#new-password-confirm').val();
    const $alert = $('#change-password-alert');
    $alert.addClass('d-none').removeClass('alert-success alert-danger').text('');

    if (newPassword.length < 6) {
        $alert.removeClass('d-none').addClass('alert-danger').text('La nueva contraseña debe tener al menos 6 caracteres.');
        return;
    }
    if (newPassword !== confirmPassword) {
        $alert.removeClass('d-none').addClass('alert-danger').text('Las contraseñas no coinciden.');
        return;
    }

    setSettingsButtonLoading(button, true);
    $.ajax({
        url: 'api/auth.php?action=change_password',
        method: 'POST',
        dataType: 'json',
        data: {
            current_password: currentPassword,
            new_password: newPassword
        },
        success: function (res) {
            if (res.success) {
                $alert.removeClass('d-none alert-danger').addClass('alert-success').text(res.message || 'Contraseña actualizada correctamente.');
                $('#change-password-form')[0].reset();
                setTimeout(function () {
                    if (changePasswordModal) {
                        changePasswordModal.hide();
                    }
                }, 1200);
            } else {
                $alert.removeClass('d-none alert-success').addClass('alert-danger').text(res.error || 'No se pudo cambiar la contraseña.');
            }
        },
        error: function () {
            $alert.removeClass('d-none alert-success').addClass('alert-danger').text('Error de conexión al cambiar la contraseña.');
        },
        complete: function () {
            setSettingsButtonLoading(button, false);
        }
    });
}

function syncProfessionalStatusFromTable() {
    $('#professionals-settings-body .professional-active').each(function () {
        const index = parseInt($(this).data('index'), 10);
        if (!Number.isNaN(index) && CABINET_PROFESSIONALS[index]) {
            CABINET_PROFESSIONALS[index].is_active = $(this).is(':checked') ? 1 : 0;
        }
    });
}

function collectProfessionalsSettings() {
    syncProfessionalStatusFromTable();
    return CABINET_PROFESSIONALS;
}

function collectCabinetSettingsPayload() {
    return {
        show_team_public: $('#show-team-public').is(':checked') ? 1 : 0,
        allow_patient_transfer: $('#allow-patient-transfer').is(':checked') ? 1 : 0,
        new_patient_booking_mode: $('#new-patient-booking-mode').val() || 'day_first',
        new_patient_fixed_professional_id: $('#new-patient-fixed-professional').val() || '',
        professionals: collectProfessionalsSettings()
    };
}

function captureCabinetSettingsSnapshot() {
    if (!$('#professionals-settings-body').length) {
        SETTINGS_SNAPSHOTS.cabinet = '';
        return;
    }
    SETTINGS_SNAPSHOTS.cabinet = stableSettingsString(collectCabinetSettingsPayload());
}

function cabinetSettingsChanged() {
    return $('#professionals-settings-body').length
        && SETTINGS_SNAPSHOTS.cabinet !== ''
        && stableSettingsString(collectCabinetSettingsPayload()) !== SETTINGS_SNAPSHOTS.cabinet;
}

function deleteProfessional(index, button = null) {
    const professional = CABINET_PROFESSIONALS[index];
    if (!professional || professional.is_current_user == 1) return;
    const $button = button ? $(button) : null;
    const original = $button ? $button.html() : '';

    if (!professional.id) {
        openProfessionalTransferDelete({
            professional: {
                id: 0,
                display_name: professional.display_name || 'este profesional'
            },
            usage: {
                pending_appointments: 0,
                assigned_patients: 0,
                linked_records: 0,
                requires_transfer: 0
            },
            targets: [],
            local_index: index
        });
        return;
    }

    if ($button) {
        $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    }
    $.ajax({
        url: 'api/admin.php?action=check_professional_delete',
        method: 'POST',
        dataType: 'json',
        data: { professional_id: professional.id },
        success: function (res) {
            if (!res.success) {
                showSettingsAlert('#cabinet-settings-alert', 'danger', res.error || 'No se pudo comprobar el profesional.');
                return;
            }
            openProfessionalTransferDelete(res);
        },
        error: function () {
            showSettingsAlert('#cabinet-settings-alert', 'danger', 'Error de conexión al comprobar el profesional.');
        },
        complete: function () {
            if ($button) {
                $button.prop('disabled', false).html(original);
            }
        }
    });
}

function openProfessionalTransferDelete(data) {
    if (!professionalTransferModal) return;
    const professional = data.professional || {};
    const usage = data.usage || {};
    const targets = data.targets || [];
    const requiresTransfer = usage.requires_transfer == 1;
    pendingLocalProfessionalDeleteIndex = Number.isInteger(data.local_index) ? data.local_index : null;
    $('#transfer-delete-professional-id').val(professional.id || 0);
    const extraRecords = Math.max(0, parseInt(usage.linked_records || 0, 10) - parseInt(usage.pending_appointments || 0, 10) - parseInt(usage.assigned_patients || 0, 10));
    const historyText = extraRecords > 0 ? `<br><small class="text-muted">También hay ${extraRecords} registros históricos o de configuración vinculados.</small>` : '';
    const professionalName = escapeHtml(professional.display_name || 'Este profesional');
    $('#professional-delete-title').text(requiresTransfer ? 'Traspasar antes de borrar' : 'Borrar profesional');
    $('#transfer-delete-summary').html(requiresTransfer
        ? `<strong>${professionalName}</strong> tiene <strong>${usage.pending_appointments || 0}</strong> citas próximas y <strong>${usage.assigned_patients || 0}</strong> ${sectorLabel('patient', 'plural', 'pacientes')} asignados.${historyText}`
        : `Vas a borrar a <strong>${professionalName}</strong> del equipo profesional.`
    );

    if (requiresTransfer) {
        $('#transfer-delete-target-wrap').removeClass('d-none');
        $('#btn-confirm-transfer-delete').text('Traspasar y borrar');
        if (!targets.length) {
            $('#transfer-delete-target').html('<option value="">No hay otro profesional activo disponible</option>').prop('disabled', true);
            $('#btn-confirm-transfer-delete').prop('disabled', true);
        } else {
            $('#transfer-delete-target')
                .html(targets.map(target => `<option value="${target.id}">${escapeHtml(target.display_name || 'Profesional')}</option>`).join(''))
                .prop('disabled', false);
            $('#btn-confirm-transfer-delete').prop('disabled', false);
        }
    } else {
        $('#transfer-delete-target-wrap').addClass('d-none');
        $('#transfer-delete-target').html('').prop('disabled', true);
        $('#btn-confirm-transfer-delete').text('Borrar');
        $('#btn-confirm-transfer-delete').prop('disabled', false);
    }
    professionalTransferModal.show();
}

function performProfessionalDelete(professionalId, targetProfessionalId = 0, button = null) {
    if (!professionalId) {
        if (pendingLocalProfessionalDeleteIndex !== null) {
            CABINET_PROFESSIONALS.splice(pendingLocalProfessionalDeleteIndex, 1);
            pendingLocalProfessionalDeleteIndex = null;
            renderProfessionalsSettings();
        }
        if (professionalTransferModal) {
            professionalTransferModal.hide();
        }
        return;
    }
    setSettingsButtonLoading(button, true);
    $.ajax({
        url: 'api/admin.php?action=delete_professional',
        method: 'POST',
        dataType: 'json',
        data: {
            professional_id: professionalId,
            target_professional_id: targetProfessionalId || 0
        },
        success: function (res) {
            if (res.success) {
                showSettingsAlert('#cabinet-settings-alert', 'success', res.message || 'Miembro borrado correctamente.');
                pendingLocalProfessionalDeleteIndex = null;
                if (professionalTransferModal) {
                    professionalTransferModal.hide();
                }
                loadCabinetSettings();
            } else {
                showSettingsAlert('#cabinet-settings-alert', 'danger', res.error || 'No se pudo borrar el profesional.');
            }
        },
        error: function () {
            showSettingsAlert('#cabinet-settings-alert', 'danger', 'Error de conexión al borrar el profesional.');
        },
        complete: function () {
            setSettingsButtonLoading(button, false);
        }
    });
}

function saveCabinetSettings(button = null, options = {}) {
    const alertSelector = options.errorAlertSelector || '#cabinet-settings-alert';
    $('#cabinet-settings-alert, #professional-editor-alert').addClass('d-none').removeClass('alert-success alert-danger').text('');
    if (!options.suppressLoading) {
        setSettingsButtonLoading(button, true);
    }
    const payload = collectCabinetSettingsPayload();
    const professionals = payload.professionals;
    const formData = new FormData();
    formData.append('show_team_public', String(payload.show_team_public));
    formData.append('allow_patient_transfer', String(payload.allow_patient_transfer));
    formData.append('new_patient_booking_mode', payload.new_patient_booking_mode);
    formData.append('new_patient_fixed_professional_id', payload.new_patient_fixed_professional_id);
    formData.append('professionals_json', JSON.stringify(professionals));
    if (options.photoFile && options.photoIndex >= 0) {
        formData.append('professional_photo', options.photoFile);
        formData.append('professional_photo_index', String(options.photoIndex));
    }

    return new Promise((resolve, reject) => {
        $.ajax({
            url: 'api/admin.php?action=save_cabinet_settings',
            method: 'POST',
            dataType: 'json',
            data: formData,
            processData: false,
            contentType: false,
            success: function (res) {
                if (res.success) {
                    if (!options.silentSuccess) {
                        showSettingsAlert('#cabinet-settings-alert', 'success', res.message || 'Equipo guardado correctamente.');
                    }
                    if (res.settings && typeof res.settings.allow_patient_transfer !== 'undefined') {
                        PAYMENT_SETTINGS.allow_patient_transfer = res.settings.allow_patient_transfer;
                    }
                    if (options.closeProfessionalModal && professionalEditorModal) {
                        professionalEditorModal.hide();
                    }
                    PROFESSIONAL_PHOTO_FILE = null;
                    if (options.skipReload) {
                        captureCabinetSettingsSnapshot();
                    } else {
                        loadCabinetSettings();
                    }
                    resolve(res);
                } else {
                    const message = res.error || 'No se pudo guardar el equipo profesional.';
                    if (!options.silentError) {
                        if (alertSelector === '#professional-editor-alert') {
                            showProfessionalEditorAlert('danger', message);
                        } else {
                            showSettingsAlert(alertSelector, 'danger', message);
                        }
                    }
                    reject(new Error(message));
                }
            },
            error: function () {
                const message = 'Error de conexión al guardar el equipo profesional.';
                if (!options.silentError) {
                    if (alertSelector === '#professional-editor-alert') {
                        showProfessionalEditorAlert('danger', message);
                    } else {
                        showSettingsAlert(alertSelector, 'danger', message);
                    }
                }
                reject(new Error(message));
            },
            complete: function () {
                if (!options.suppressLoading) {
                    setSettingsButtonLoading(button, false);
                }
            }
        });
    });
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function escapeJsString(value) {
    return String(value)
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'")
        .replace(/\r/g, '')
        .replace(/\n/g, ' ');
}

function currentGoogleRedirectUri() {
    return window.location.origin + window.location.pathname.replace(/dashboard\.php$/, '') + 'google_oauth_callback.php';
}

function setSettingsButtonLoading(button, loading) {
    if (!button) return;

    const $button = $(button);
    if (!$button.length) return;

    if (loading) {
        if (!$button.data('original-html')) {
            $button.data('original-html', $button.html());
        }
        $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Guardando...');
        return;
    }

    $button.prop('disabled', false).html($button.data('original-html') || 'Guardar configuración');
    $button.removeData('original-html');
}

function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function saveAllSettings(button = null) {
    const alertSelector = '#settings-save-alert';
    $('#settings-save-alert, #payment-settings-alert, #email-settings-alert, #calendar-settings-alert, #booking-settings-alert, #general-settings-alert, #interface-settings-alert, #legal-settings-alert, #services-settings-alert, #bonuses-settings-alert, #cabinet-settings-alert').addClass('d-none');
    setSettingsButtonLoading(button, true);
    const previousDashboardConfigMode = LOADED_DASHBOARD_CONFIG_MODE || 'advanced';
    const selectedDashboardConfigMode = LOADED_DASHBOARD_CONFIG_MODE || 'advanced';

    try {
        const settingsSection = (typeof IS_SUPERADMIN !== 'undefined' && IS_SUPERADMIN) ? 'all' : 'general';
        await savePaymentSettings(alertSelector, null, null, settingsSection, {
            suppressLoading: true,
            silentSuccess: true,
            silentError: true,
            skipReload: true,
            skipCalendarRefresh: true
        });

        if (typeof IS_SUPERADMIN !== 'undefined' && IS_SUPERADMIN) {
            if (servicesSettingsChanged()) {
                await saveServicesSettings(null, {
                    suppressLoading: true,
                    silentSuccess: true,
                    silentError: true,
                    skipCalendarRefresh: true
                });
            }
            if (bonusesSettingsChanged()) {
                await saveBonusesSettings(null, {
                    suppressLoading: true,
                    silentSuccess: true,
                    silentError: true
                });
            }
            if (cabinetSettingsChanged()) {
                await saveCabinetSettings(null, {
                    suppressLoading: true,
                    silentSuccess: true,
                    silentError: true,
                    skipReload: true
                });
            }
        }

        await loadPaymentSettings();
        if (previousDashboardConfigMode !== selectedDashboardConfigMode) {
            showSettingsAlert(alertSelector, 'success', 'Cambios guardados. Recargando dashboard...');
            window.location.reload();
            return;
        }
        await renderWeekInfo();
        showSettingsAlert(alertSelector, 'success', 'Cambios guardados');
        await delay(300);
        if (settingsModal) {
            settingsModal.hide();
        }
    } catch (error) {
        showSettingsAlert(alertSelector, 'danger', error && error.message ? error.message : 'No se pudieron guardar los cambios.');
    } finally {
        setSettingsButtonLoading(button, false);
    }
}

function savePaymentSettings(alertSelector = '#payment-settings-alert', onSuccess = null, button = null, section = '', options = {}) {
    $('#settings-save-alert, #payment-settings-alert, #email-settings-alert, #calendar-settings-alert, #booking-settings-alert, #general-settings-alert, #interface-settings-alert, #legal-settings-alert, #services-settings-alert, #bonuses-settings-alert, #cabinet-settings-alert').addClass('d-none');
    if (!options.suppressLoading) {
        setSettingsButtonLoading(button, true);
    }

    const reminderInput = document.getElementById('appointment-reminder-enabled');
    const reminderEnabledValue = reminderInput && reminderInput.checked ? '1' : '0';

    const formData = new FormData();
    formData.append('settings_section', section);
    formData.append('online_payment_enabled', $('#online-payment-enabled').is(':checked') ? '1' : '0');
    formData.append('app_name', $('#app-name').val().trim());
    formData.append('site_tagline', $('#site-tagline').val().trim());
    formData.append('site_phone', $('#site-phone').val().trim());
    formData.append('legal_owner_name', $('#legal-owner-name').val().trim());
    formData.append('legal_nif', $('#legal-nif').val().trim());
    formData.append('legal_address', $('#legal-address').val().trim());
    formData.append('legal_email', $('#legal-email').val().trim());
    formData.append('legal_license_number', $('#legal-license-number').val().trim());
    formData.append('legal_professional_college', $('#legal-professional-college').val().trim());
    formData.append('legal_uses_non_technical_cookies', $('#legal-uses-non-technical-cookies').is(':checked') ? '1' : '0');
    formData.append('legal_terms_notes', $('#legal-terms-notes').val().trim());
    formData.append('primary_color', $('#primary-color-text').val().trim());
    formData.append('appointment_delivery_mode', $('#appointment-delivery-mode').val());
    selectedSessionTypes().forEach(type => {
        formData.append('available_session_types[]', type);
    });
    $('.available-session-duration:checked').each(function () {
        formData.append('available_session_durations[]', this.value);
    });
    formData.append('display_effective_duration_enabled', $('#display-effective-duration-enabled').is(':checked') ? '1' : '0');
    formData.append('display_duration_offset_minutes', $('#display-duration-offset-minutes').val().trim() || '5');
    formData.append('show_profile_image_public', $('#show-profile-image-public').is(':checked') ? '1' : '0');
    formData.append('show_prices_public', $('#show-prices-public').is(':checked') ? '1' : '0');
    formData.append('show_contact_public', $('#show-contact-public').is(':checked') ? '1' : '0');
    formData.append('initial_calendar_view', $('#initial-calendar-view').val() || 'month');
    formData.append('dashboard_config_mode', LOADED_DASHBOARD_CONFIG_MODE || 'advanced');
    formData.append('online_booking_enabled', $('#online-booking-enabled').is(':checked') ? '1' : '0');
    formData.append('patient_tasks_visible_default', $('#patient-tasks-visible-default').is(':checked') ? '1' : '0');
    formData.append('work_plan_task_status_enabled', $('#work-plan-task-status-enabled').is(':checked') ? '1' : '0');
    formData.append('patient_registration_mode', $('#patient-registration-requires-invite').is(':checked') ? 'invite' : 'open');
    if ($('#profile-image')[0] && $('#profile-image')[0].files[0]) {
        formData.append('profile_image', $('#profile-image')[0].files[0]);
    }
    if ($('#landing-image')[0] && $('#landing-image')[0].files[0]) {
        formData.append('landing_image', $('#landing-image')[0].files[0]);
    }
    formData.append('environment', $('#payment-environment').val());
    formData.append('merchant_code', $('#merchant-code').val().trim());
    formData.append('terminal', $('#merchant-terminal').val().trim());
    formData.append('appointment_price', $('#appointment-price').val().trim());
    formData.append('online_appointment_price', $('#online-appointment-price').val().trim());
    formData.append('couple_appointment_price', $('#couple-appointment-price').val().trim());
    formData.append('online_couple_appointment_price', $('#online-couple-appointment-price').val().trim());
    formData.append('min_booking_notice_days', $('#min-booking-notice-days').val().trim());
    formData.append('max_booking_notice_days', $('#max-booking-notice-days').val().trim());
    formData.append('appointment_start_time', $('#appointment-start-time').val().trim());
    formData.append('appointment_end_time', $('#appointment-end-time').val().trim());
    formData.append('break_start_time', $('#break-start-time').val().trim());
    formData.append('break_end_time', $('#break-end-time').val().trim());
    $('.available-weekday:checked').each(function () {
        formData.append('available_weekdays[]', this.value);
    });
    formData.append('appointment_reminder_enabled', reminderEnabledValue);
    formData.append('merchant_key', $('#merchant-key').val().trim());
    formData.append('email_provider', $('#email-provider').val());
    formData.append('smtp_host', $('#smtp-host').val().trim());
    formData.append('smtp_port', $('#smtp-port').val().trim());
    formData.append('smtp_username', $('#smtp-username').val().trim());
    formData.append('smtp_password', $('#smtp-password').val().trim());
    formData.append('smtp_secure', $('#smtp-secure').val());
    formData.append('smtp_from_email', $('#smtp-from-email').val().trim());
    formData.append('smtp_from_name', $('#smtp-from-name').val().trim());
    formData.append('google_client_id', $('#google-client-id').val().trim());
    formData.append('google_client_secret', $('#google-client-secret').val().trim());
    formData.append('google_refresh_token', $('#google-refresh-token').val().trim());
    formData.append('google_connected_email', $('#google-connected-email').val().trim());
    formData.append('google_redirect_uri', currentGoogleRedirectUri());
    formData.append('calendar_provider', $('#calendar-provider').val() || 'none');
    formData.append('google_calendar_id', $('#google-calendar-id').val().trim());
    formData.append('icloud_calendar_email', $('#icloud-calendar-email').val().trim());
    formData.append('icloud_calendar_app_password', $('#icloud-calendar-app-password').val().trim());
    formData.append('icloud_calendar_url', $('#icloud-calendar-url').val().trim());
    formData.append('send_patient_calendar_link', $('#send-patient-calendar-link').is(':checked') ? '1' : '0');

    return new Promise((resolve, reject) => {
        $.ajax({
            url: 'api/admin.php?action=save_payment_settings',
            method: 'POST',
            dataType: 'json',
            data: formData,
            processData: false,
            contentType: false,
            success: function (res) {
                if (res.success) {
                    $('#merchant-key').val('');
                    $('#smtp-password').val('');
                    $('#google-client-secret').val('');
                    $('#google-refresh-token').val('');
                    $('#icloud-calendar-app-password').val('');
                    if (!options.skipReload) {
                        loadPaymentSettings();
                    }
                    if (!options.skipCalendarRefresh) {
                        renderWeekInfo();
                    }
                    if (!options.silentSuccess) {
                        showSettingsAlert(alertSelector, 'success', res.message || 'Configuración guardada correctamente.');
                    }
                    if (onSuccess) {
                        onSuccess();
                    }
                    resolve(res);
                } else {
                    const message = res.error || 'No se pudo guardar la configuración.';
                    if (!options.silentError) {
                        showSettingsAlert(alertSelector, 'danger', message);
                    }
                    reject(new Error(message));
                }
            },
            error: function () {
                const message = 'Error de conexión al guardar la configuración.';
                if (!options.silentError) {
                    showSettingsAlert(alertSelector, 'danger', message);
                }
                reject(new Error(message));
            },
            complete: function () {
                if (!options.suppressLoading) {
                    setSettingsButtonLoading(button, false);
                }
            }
        });
    });
}
