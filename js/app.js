let currentStartDate = getMonday(new Date());
let currentMonthDate = new Date();
let currentCalendarView = 'week';
let currentMonthData = null;
let selectedMonthDay = null;
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
    online_booking_enabled: 1,
    bonuses_enabled: 0,
    create_compensation_bonus_on_paid_cancel: 1
};
let APPOINTMENT_SERVICES = [];
let ACTIVE_SERVICE_OPTIONS = [];
let APPOINTMENT_BONUSES = [];
let PATIENT_BONUS_BALANCE = { bonuses_enabled: 0, total_remaining: 0, bonuses: [] };
let isAppointmentRequestInProgress = false;
let inviteModal = null;
let upcomingAppointmentsModal = null;
let upcomingAppointmentsInitialLoad = true;
let adminStatsModal = null;
let bonusesModal = null;
let selectedBonusToBuy = null;
let currentInviteLink = '';
let currentInviteToken = '';

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

function renderWeekInfo() {
    updateCalendarNavigationLabels();
    if (currentCalendarView === 'month') {
        loadMonthCalendar(formatMonthStart(currentMonthDate));
        return;
    }
    loadCalendar(formatDate(currentStartDate));
}

function formatMonthStart(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-01`;
}

function updateCalendarNavigationLabels() {
    const monthMode = currentCalendarView === 'month';
    $('#btn-calendar-view-toggle').html(monthMode ? '<i class="bi bi-calendar-week"></i> Ver semana' : '<i class="bi bi-calendar3"></i> Ver mes');
    $('#calendar-prev-label').text(monthMode ? 'Mes anterior' : 'Semana Anterior');
    $('#calendar-next-label').text(monthMode ? 'Mes siguiente' : 'Semana Siguiente');
}

function loadCalendar(startDateStr) {
    $('#calendar-container').html('<div class="text-center text-muted py-5"><div class="spinner-border text-secondary" role="status"></div><br>Cargando...</div>');

    $.ajax({
        url: 'api/appointments.php?action=get_week',
        data: { start_date: startDateStr },
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                PAYMENT_SETTINGS = res.payment_settings || PAYMENT_SETTINGS;
                if (Array.isArray(res.service_options)) {
                    ACTIVE_SERVICE_OPTIONS = res.service_options;
                }
                togglePatientBonusActions();
                drawCalendar(startDateStr, res.appointments, res.closed_days);
            }
        }
    });
}

function loadMonthCalendar(monthStr) {
    $('#calendar-container').html('<div class="text-center text-muted py-5"><div class="spinner-border text-secondary" role="status"></div><br>Cargando mes...</div>');

    $.ajax({
        url: 'api/appointments.php?action=get_month',
        data: { month: monthStr },
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                PAYMENT_SETTINGS = res.payment_settings || PAYMENT_SETTINGS;
                if (Array.isArray(res.service_options)) {
                    ACTIVE_SERVICE_OPTIONS = res.service_options;
                }
                togglePatientBonusActions();
                currentMonthData = res;
                selectedMonthDay = selectedMonthDay || firstAvailableMonthDay(res.month, res.appointments, res.closed_days);
                drawMonthCalendar(res.month, res.appointments || {}, res.closed_days || {});
            }
        }
    });
}

function drawCalendar(startDateStr, appointmentsMap, closedDays) {
    const activeDays = getActiveWeekdays();
    let html = `<div class="calendar-grid" style="--calendar-days: ${activeDays.length};">`;
    let startD = new Date(startDateStr);

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

function drawMonthCalendar(monthStr, appointmentsMap, closedDays) {
    const monthDate = new Date(`${monthStr}T00:00:00`);
    const year = monthDate.getFullYear();
    const month = monthDate.getMonth();
    const monthName = monthDate.toLocaleDateString('es-ES', { month: 'long', year: 'numeric' });
    const first = new Date(year, month, 1);
    const firstGrid = new Date(first);
    firstGrid.setDate(first.getDate() - ((first.getDay() + 6) % 7));

    let html = `
        <div class="month-calendar-layout">
            <div class="month-calendar-panel">
                <div class="month-calendar-title">${escapeHtml(monthName)}</div>
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
        const subtitle = status.available ? '' : status.label;
        html += `
            <button type="button" class="month-day ${status.className}${selected}" ${clickable}>
                <span>${day.getDate()}</span>
                <small>${subtitle}</small>
            </button>
        `;
    }

    html += `
                </div>
            </div>
            <div class="month-day-panel" id="month-day-panel">
                ${renderSelectedMonthDayPanel(appointmentsMap, closedDays)}
            </div>
        </div>
    `;

    $('#calendar-container').html(html);
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
    let html = `<div class="month-day-panel-header"><h5>${formatDisplayDate(selectedMonthDay)}</h5><span>${headerText}</span></div>`;
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

function getScheduleItems() {
    const start = timeToMinutes(PAYMENT_SETTINGS.appointment_start_time || '10:00');
    const end = timeToMinutes(PAYMENT_SETTINGS.appointment_end_time || '19:00');
    const hasBreak = PAYMENT_SETTINGS.break_start_time && PAYMENT_SETTINGS.break_end_time;
    const breakStart = hasBreak ? timeToMinutes(PAYMENT_SETTINGS.break_start_time) : null;
    const breakEnd = hasBreak ? timeToMinutes(PAYMENT_SETTINGS.break_end_time) : null;
    const items = [];
    let breakAdded = false;

    for (let minutes = start; minutes <= end; minutes += 60) {
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
    const dayEnd = lastStart + 60;
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
        let cancelPayloadArg = encodeURIComponent(JSON.stringify({
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
            cls = 'booked';
            text = `
                <div class="slot-content">
                    <div class="slot-main-row">
                        <span class="slot-label">${timeStr} - ${app.name}</span>
                    </div>
                    <div class="slot-meta-row">
                        <span class="slot-badges">${serviceBadge}${consultationBadge}${paymentBadge}</span>
                        <span class="slot-actions"><button class="btn btn-danger slot-action-btn" onclick="event.stopPropagation(); openModal('${dateStr}', '${timeStr}', 'cancel_admin', '${cancelPayloadArg}');" title="Cancelar cita"><i class="bi bi-trash"></i></button></span>
                    </div>
                </div>
            `;
            onClick = ``;
        } else {
            if (app.is_own) {
                cls = 'booked-by-me';
                text = `
                    <div class="slot-content">
                        <div class="slot-main-row">
                            <span class="slot-label">${timeStr} - Tu reserva</span>
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
        return ' <small class="payment-badge paid">Pagada</small>';
    }

    if (!IS_ADMIN) {
        return '';
    }

    if (app.payment_status === 'failed') {
        return ' <small class="payment-badge failed">Pago fallido</small>';
    }

    return ' <small class="payment-badge pending">Pendiente</small>';
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
    let label = '';
    if (app.service_type === 'couple') {
        label = duration === 60 ? 'Pareja' : `Pareja ${duration} min`;
    } else if (duration !== 60) {
        label = `${duration} min`;
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
        && PAYMENT_SETTINGS.online_payment_enabled == 1
        && app.payment_status !== 'paid';
}

// Modal handling
let appointmentModal = new bootstrap.Modal(document.getElementById('appointmentModal'));
let settingsModal = document.getElementById('settingsModal') ? new bootstrap.Modal(document.getElementById('settingsModal')) : null;
let closedDayModal = document.getElementById('closedDayModal') ? new bootstrap.Modal(document.getElementById('closedDayModal')) : null;
let professionalEditorModal = document.getElementById('professionalEditorModal') ? new bootstrap.Modal(document.getElementById('professionalEditorModal')) : null;
let professionalTransferModal = document.getElementById('professionalTransferModal') ? new bootstrap.Modal(document.getElementById('professionalTransferModal')) : null;
let changePasswordModal = document.getElementById('changePasswordModal') ? new bootstrap.Modal(document.getElementById('changePasswordModal')) : null;
inviteModal = document.getElementById('inviteModal') ? new bootstrap.Modal(document.getElementById('inviteModal')) : null;
upcomingAppointmentsModal = document.getElementById('upcomingAppointmentsModal') ? new bootstrap.Modal(document.getElementById('upcomingAppointmentsModal')) : null;
adminStatsModal = document.getElementById('adminStatsModal') ? new bootstrap.Modal(document.getElementById('adminStatsModal')) : null;
bonusesModal = document.getElementById('bonusesModal') ? new bootstrap.Modal(document.getElementById('bonusesModal')) : null;
let adminPatientsModal = document.getElementById('adminPatientsModal') ? new bootstrap.Modal(document.getElementById('adminPatientsModal')) : null;
let patientEditorModal = document.getElementById('patientEditorModal') ? new bootstrap.Modal(document.getElementById('patientEditorModal')) : null;
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
let CURRENT_UPCOMING_APPOINTMENTS = [];

function openModal(date, time, status, extraName = '', extraEmail = '', extraPhone = '') {
    $('#modalDate').val(date);
    $('#modalTime').val(time);
    $('#modalStatus').val(status);
    $('#payment-options').addClass('d-none');
    $('#consultationTypeSelect').addClass('d-none');
    $('#serviceTypeSelect').addClass('d-none');
    $('#serviceOptionSelect').addClass('d-none');
    $('#adminProfessionalSelect').addClass('d-none');
    $('#booking-patient-professional-note').text('');
    $('#booking-bonus-notice').addClass('d-none').text('');
    currentPaymentAppointmentId = null;
    $('#btn-confirm-action').removeClass('d-none').prop('disabled', false);

    if (status === 'available') {
        $('#modalTitle').text(`Reservar cita: ${formatDisplayDate(date)} a las ${time}`);
        if (IS_ADMIN) {
            $('#modalDesc').text('Selecciona un paciente para reservar el horario.');
            $('#adminPatientSelect').removeClass('d-none');
            if (IS_SUPERADMIN) {
                $('#adminProfessionalSelect').removeClass('d-none');
                populateBookingProfessionalSelect(CURRENT_PROFESSIONAL_ID);
                updateBookingPatientProfessionalNote(bookingPatientById($('#patientSelect').val()));
            }
        } else {
            $('#modalDesc').text('¿Estás seguro de que deseas reservar este horario?');
        }
        if (IS_SUPERADMIN) {
            loadBookingContextForProfessional($('#booking-professional').val() || CURRENT_PROFESSIONAL_ID);
        } else {
            renderBookingServiceOptions();
            refreshBookingBonusNotice();
        }
        $('#serviceOptionSelect').removeClass('d-none');
        $('#btn-confirm-action').removeClass('btn-danger').addClass('btn-primary').text('Reservar');
    } else if (status === 'cancel_admin') {
        $('#modalTitle').text(`Cancelar cita: ${formatDisplayDate(date)} a las ${time}`);
        $('#modalDesc').html(`Paciente: <b>${extraName}</b><br><small>Email: ${extraEmail}<br>Tel: ${extraPhone}</small><br><br>¿Confirmar cancelación?`);
        if (IS_ADMIN) $('#adminPatientSelect').addClass('d-none');
        $('#adminProfessionalSelect').addClass('d-none');
        $('#btn-confirm-action').removeClass('btn-primary').addClass('btn-danger').text('Cancelar cita');
    } else if (status === 'cancel_own') {
        $('#modalTitle').text(`Cancelar tu cita: ${formatDisplayDate(date)} a las ${time}`);
        $('#modalDesc').text('¿Estás seguro de que deseas cancelar tu cita?');
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

function applyCancelPaymentNotice(status, payload) {
    if (!['cancel_admin', 'cancel_own'].includes(status)) {
        return;
    }
    const data = parseCancelPayload(payload);
    const notice = cancelBonusNotice(data);
    if (status === 'cancel_admin') {
        $('#modalDesc').html(`Paciente: <b>${escapeHtml(data.name || '')}</b><br><small>Email: ${escapeHtml(data.email || '')}<br>Tel: ${escapeHtml(data.phone || '')}</small>${notice}<br>&iquest;Confirmar cancelaci&oacute;n?`);
        return;
    }
    $('#modalDesc').html(`${notice}<br>&iquest;Est&aacute;s seguro de que deseas cancelar tu cita?`);
}

$(document).ready(function () {
    renderWeekInfo();

    if (IS_ADMIN) {
        loadBookingPatients();
    }

    $('#btn-prev-week').click(function () {
        if (currentCalendarView === 'month') {
            currentMonthDate.setMonth(currentMonthDate.getMonth() - 1);
            selectedMonthDay = null;
        } else {
            currentStartDate.setDate(currentStartDate.getDate() - 7);
        }
        renderWeekInfo();
    });

    $('#btn-next-week').click(function () {
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

    $('#btn-buy-bonus').click(function () {
        openBuyBonusModal();
    });

    $('#btn-my-bonuses').click(function () {
        openMyBonusesModal();
    });

    $('#btn-admin-bonuses').click(function () {
        openAdminBonusesModal();
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

    $('#upcoming-appointments-search').on('input', function () {
        renderUpcomingAppointments(CURRENT_UPCOMING_APPOINTMENTS);
    });

    $('#upcoming-appointments-scope').on('change', function () {
        loadUpcomingAppointments();
    });

    $('#upcoming-appointments-professional').on('change', function () {
        loadUpcomingAppointments();
    });

    $('#btn-admin-stats').click(function () {
        openAdminStatsModal();
    });

    $('#btn-admin-patients').click(function () {
        openAdminPatientsModal();
    });

    $('#btn-new-patient').click(function () {
        openPatientEditorModal();
    });

    $('#admin-patients-body').on('click', '.btn-edit-patient', function () {
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

    $('#patient-editor-form').submit(function (e) {
        e.preventDefault();
        savePatient(this);
    });

    $('#patient-history-tab').on('shown.bs.tab', function () {
        const patientId = parseInt($('#patient-editor-id').val() || '0', 10);
        loadPatientAppointmentHistory(patientId);
    });

    $('#bonus-list-search, #bonus-list-sort').on('input change', function () {
        renderBonusList(CURRENT_BONUS_LIST, CURRENT_BONUS_ADMIN_VIEW);
    });

    $('#bonus-list-professional').on('change', function () {
        if (CURRENT_BONUS_ADMIN_VIEW) {
            loadBonusList('api/bonuses.php?action=admin_list', true);
        }
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
        if (IS_SUPERADMIN) {
            syncBookingProfessionalFromPatient();
        } else {
            refreshBookingBonusNotice();
        }
    });

    $('#booking-professional').change(function () {
        if (IS_SUPERADMIN) {
            loadBookingContextForProfessional($(this).val());
            updateBookingPatientProfessionalNote(bookingPatientById($('#patientSelect').val()));
        }
    });

    $('#service-option').change(function () {
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

    $('#professionalEditorModal').on('hidden.bs.modal', function () {
        $('body').removeClass('settings-secondary-modal-open');
        if ($('#settingsModal').hasClass('show')) {
            document.body.classList.add('modal-open');
        }
    });

    $('#professionalTransferModal').on('hidden.bs.modal', function () {
        $('body').removeClass('settings-secondary-modal-open');
        if ($('#settingsModal').hasClass('show')) {
            document.body.classList.add('modal-open');
        }
    });

    $('#closedDayModal, #professionalEditorModal, #professionalTransferModal').on('show.bs.modal', function () {
        if ($('#settingsModal').hasClass('show')) {
            $('body').addClass('settings-secondary-modal-open');
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
        savePaymentSettings('#payment-settings-alert', null, $(this).find('button[type="submit"]'), 'payment');
    });

    $('#btn-save-email-settings').click(function () {
        savePaymentSettings('#email-settings-alert', null, this, 'email');
    });

    $('#btn-save-calendar-settings').click(function () {
        savePaymentSettings('#calendar-settings-alert', null, this, 'calendar');
    });

    $('#btn-save-booking-settings').click(function () {
        savePaymentSettings('#booking-settings-alert', null, this, 'booking');
    });

    $('#btn-save-general-settings').click(function () {
        savePaymentSettings('#general-settings-alert', null, this, 'general');
    });

    $('#btn-save-interface-settings').click(function () {
        savePaymentSettings('#interface-settings-alert', null, this, 'interface');
    });

    $('#btn-save-services-settings').click(function () {
        saveServicesSettings(this);
    });

    $('#btn-save-bonuses-settings').click(function () {
        saveBonusesSettings(this);
    });

    $('#btn-save-cabinet-settings').click(function () {
        saveCabinetSettings(this);
    });

    $('#btn-new-professional').click(function () {
        openProfessionalEditor(-1);
    });

    $('#professional-editor-form').submit(function (e) {
        e.preventDefault();
        saveProfessionalEditor(this.querySelector('button[type="submit"]'));
    });

    $('#professional-editor-photo').on('change', function () {
        PROFESSIONAL_PHOTO_FILE = this.files && this.files[0] ? this.files[0] : null;
        if (PROFESSIONAL_PHOTO_FILE) {
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

    $('.available-session-type').change(function () {
        $('#available-session-individual').prop('checked', true);
        togglePriceRows();
        renderServicesSettings();
    });

    $('.available-session-duration').change(function () {
        $('#available-duration-60').prop('checked', true);
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
    });

    $('#primary-color-text').on('input', function () {
        const value = $(this).val().trim();
        if (/^#[0-9a-fA-F]{6}$/.test(value)) {
            $('#primary-color').val(value);
            document.documentElement.style.setProperty('--primary-color', value);
        }
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
        if (!data.user_id) { alert('Selecciona un paciente'); return; }
    }
    if (IS_SUPERADMIN) {
        data.professional_id = $('#booking-professional').val();
        if (!data.professional_id) { alert('Selecciona un profesional'); return; }
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

            renderWeekInfo();
            setAppointmentActionLoading(false);

            if (res.bonus_applied == 1) {
                const remaining = parseInt(res.bonus_remaining || 0, 10);
                $('#modalTitle').text('Cita reservada');
                const ownerText = IS_ADMIN ? 'La cita ha quedado reservada correctamente e incluida con el bono del paciente.' : 'Tu cita ha quedado reservada correctamente e incluida con tu bono.';
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
        return option.service_key === 'couple' ? 'couple' : 'individual';
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
    return type === 'couple' ? 'Pareja' : 'Individual';
}

function isCoupleServiceEnabled() {
    return String(PAYMENT_SETTINGS.available_session_types || 'individual').split(',').includes('couple');
}

function appointmentPriceForType(consultationType, serviceType = 'individual') {
    let rawPrice = PAYMENT_SETTINGS.appointment_price || '70.00';
    if (serviceType === 'couple') {
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
        $select.append('<option value="">Este profesional no tiene disponible este horario</option>');
        return;
    }
    const mode = PAYMENT_SETTINGS.appointment_delivery_mode || 'both';
    const visibleOptions = ACTIVE_SERVICE_OPTIONS.filter(option => {
        return (mode === 'both' || option.consultation_type === mode)
            && selectedSlotCanFitDuration(option.duration_minutes);
    });
    visibleOptions.forEach(option => {
        const label = `${option.service_name} · ${option.duration_minutes} min · ${consultationTypeLabel(option.consultation_type)} · ${formatPrice(option.price)} €`;
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
    if (!option || option.service_key === 'couple') {
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

function setAvailableSessionTypes(value) {
    const activeTypes = String(value || 'individual')
        .split(',')
        .map(type => type.trim());
    $('#available-session-individual').prop('checked', true);
    $('#available-session-couple').prop('checked', activeTypes.includes('couple'));
}

function setAvailableSessionDurations(value) {
    const activeDurations = String(value || '60')
        .split(',')
        .map(duration => duration.trim());
    $('.available-session-duration').prop('checked', false);
    activeDurations.forEach(duration => {
        $(`.available-session-duration[value="${duration}"]`).prop('checked', true);
    });
    $('#available-duration-60').prop('checked', true);
}

function cancelAppointment() {
    setAppointmentActionLoading(true);
    $.ajax({
        url: 'api/appointments.php?action=cancel',
        method: 'POST',
        data: {
            date: $('#modalDate').val(),
            time: $('#modalTime').val()
        },
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                appointmentModal.hide();
                renderWeekInfo();
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

function startRedsysPayment(appointmentId, paymentMethod) {
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
                appointmentModal.hide();
                renderWeekInfo();
                alert(res.error || 'La cita se ha reservado, pero no se pudo iniciar el pago.');
                return;
            }

            $('#redsys-payment-form').remove();
            $('body').append(res.form_html);
            $('#redsys-payment-form').trigger('submit');
            setAppointmentActionLoading(false);
        },
        error: function () {
            appointmentModal.hide();
            renderWeekInfo();
            alert('La cita se ha reservado, pero no se pudo conectar con Redsys.');
            setAppointmentActionLoading(false);
        }
    });
}

function togglePatientBonusActions() {
    if (IS_ADMIN) {
        return;
    }
    const canBuyBonuses = PAYMENT_SETTINGS.bonuses_enabled == 1 && PAYMENT_SETTINGS.online_payment_enabled == 1;
    const canUseInternalVouchers = PAYMENT_SETTINGS.create_compensation_bonus_on_paid_cancel === undefined ? true : PAYMENT_SETTINGS.create_compensation_bonus_on_paid_cancel == 1;
    const showBonusArea = canBuyBonuses || canUseInternalVouchers;
    $('#patient-bonus-actions').attr('style', '');
    $('#btn-buy-bonus').toggle(canBuyBonuses);
    $('#btn-my-bonuses').toggle(showBonusArea);
}

function openBuyBonusModal() {
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
    openBonusListModal('Bonos de pacientes', 'api/bonuses.php?action=admin_list', true);
}

function openBonusListModal(title, url, adminView) {
    if (!bonusesModal) return;
    selectedBonusToBuy = null;
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
    $('#bonus-list-head').html(adminView
        ? `<tr><th>Paciente</th>${showProfessional ? '<th>Profesional</th>' : ''}<th>Bono</th><th>Compradas</th><th>Restantes</th><th>Pagado</th><th>Comprado</th><th>Estado</th></tr>`
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
    return $('#bonus-list-professional').length ? 8 : 7;
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
            <td><strong>${bonus.remaining_sessions}</strong></td>
            <td>${paid}</td>
            <td>${date}</td>
            <td>${status}</td>
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
        showInviteAlert('danger', 'Indica el email del paciente.');
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
                showAdminPatientsAlert('danger', res.error || 'No se pudieron cargar los pacientes.');
                return;
            }
            if (Array.isArray(res.professionals)) {
                populateAdminPatientsProfessionalsFilter(res.professionals, res.current_professional_id);
            }
            ADMIN_PATIENTS = Array.isArray(res.patients) ? res.patients : [];
            renderAdminPatients(ADMIN_PATIENTS);
            refreshPatientSelectOptions();
        },
        error: function () {
            showAdminPatientsAlert('danger', 'Error de conexion al cargar los pacientes.');
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

function renderAdminPatients(patients) {
    const filteredPatients = filterAndSortAdminPatients(patients);
    const colspan = adminPatientsColspan();
    const showProfessional = $('#admin-patients-professional').length;
    if (!patients.length) {
        $('#admin-patients-body').html(`<tr><td colspan="${colspan}" class="text-center text-muted py-4">Todavia no hay pacientes.</td></tr>`);
        $('#admin-patients-count').text('');
        return;
    }
    if (!filteredPatients.length) {
        $('#admin-patients-body').html(`<tr><td colspan="${colspan}" class="text-center text-muted py-4">No hay pacientes que coincidan con la busqueda.</td></tr>`);
        $('#admin-patients-count').text('');
        return;
    }

    const html = filteredPatients.map(patient => {
        const contact = [
            patient.email ? `<div>${escapeHtml(patient.email)}</div>` : '',
            patient.phone ? `<small class="text-muted">${escapeHtml(patient.phone)}</small>` : ''
        ].join('') || '<span class="text-muted">Sin contacto</span>';
        const accessBadge = parseInt(patient.has_portal_access || 0, 10) === 1
            ? '<span class="badge text-bg-success">Con acceso</span>'
            : '<span class="badge text-bg-secondary">Sin acceso</span>';
        const inviteButton = parseInt(patient.has_portal_access || 0, 10) === 1
            ? ''
            : `<button class="btn btn-outline-primary btn-sm btn-send-patient-invite" type="button" data-patient-id="${patient.id}" title="Enviar invitacion de registro"><i class="bi bi-envelope"></i></button>`;
        const documentLink = patient.document_path
            ? `<a href="${escapeHtml(patient.document_path)}" target="_blank" rel="noopener">${escapeHtml(patient.document_name || 'Documento')}</a>`
            : '<span class="text-muted">Sin archivo</span>';

        return `
            <tr>
                <td><strong>${escapeHtml(patient.name || '')}</strong></td>
                ${showProfessional ? `<td>${professionalCellHtml(patient, 'professional_name', 'professional_photo_path')}</td>` : ''}
                <td>${contact}</td>
                <td>${patient.patient_type ? escapeHtml(patient.patient_type) : '<span class="text-muted">-</span>'}</td>
                <td>${patient.admission_date ? formatDisplayDate(patient.admission_date) : '<span class="text-muted">-</span>'}</td>
                <td>${accessBadge}</td>
                <td>${documentLink}</td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        ${inviteButton}
                        <button class="btn btn-outline-secondary btn-sm btn-edit-patient" type="button" data-patient-id="${patient.id}" title="Datos del paciente">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');

    $('#admin-patients-body').html(html);
    $('#admin-patients-count').text(`${filteredPatients.length} ${filteredPatients.length === 1 ? 'paciente' : 'pacientes'}`);
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

function openPatientEditorModal(patient = null) {
    if (!patientEditorModal) return;
    $('#patient-editor-alert').addClass('d-none').text('');
    $('#patient-history-alert').addClass('d-none').text('');
    $('#patient-editor-form')[0].reset();
    $('#patient-editor-title').text(patient ? 'Editar paciente' : 'Nuevo paciente');
    $('#patient-editor-id').val(patient ? patient.id : '');
    $('#patient-editor-name').val(patient ? patient.name || '' : '');
    $('#patient-editor-type').val(patient ? patient.patient_type || '' : '');
    $('#patient-editor-email').val(patient ? patient.email || '' : '');
    $('#patient-editor-phone').val(patient ? patient.phone || '' : '');
    $('#patient-editor-admission-date').val(patient ? patient.admission_date || formatDate(new Date()) : formatDate(new Date()));
    $('#patient-editor-notes').val(patient ? patient.notes || '' : '');
    if (patient && patient.document_path) {
        $('#patient-editor-document-status').html(`Archivo actual: <a href="${escapeHtml(patient.document_path)}" target="_blank" rel="noopener">${escapeHtml(patient.document_name || 'Documento')}</a>`);
    } else {
        $('#patient-editor-document-status').text('Puedes adjuntar un PDF, XLS o XLSX de hasta 12 MB.');
    }
    bootstrap.Tab.getOrCreateInstance(document.getElementById('patient-data-tab')).show();
    resetPatientAppointmentHistory(patient ? patient.id : 0);
    if (patient && patient.id) {
        loadPatientAppointmentHistory(patient.id);
    }
    patientEditorModal.show();
}

function resetPatientAppointmentHistory(patientId = 0) {
    $('#patient-history-count').text('');
    if (!patientId) {
        $('#patient-history-body').html('<tr><td colspan="6" class="text-center text-muted py-4">Guarda el paciente para ver su historial de citas.</td></tr>');
        return;
    }
    $('#patient-history-body').html('<tr><td colspan="6" class="text-center text-muted py-4">Cargando historial...</td></tr>');
}

function loadPatientAppointmentHistory(patientId) {
    patientId = parseInt(patientId || 0, 10);
    if (!patientId) {
        resetPatientAppointmentHistory(0);
        return;
    }
    $('#patient-history-alert').addClass('d-none').text('');
    $('#patient-history-body').html('<tr><td colspan="6" class="text-center text-muted py-4">Cargando historial...</td></tr>');
    $('#patient-history-count').text('');

    $.ajax({
        url: 'api/admin.php?action=patient_appointments',
        dataType: 'json',
        data: { patient_id: patientId },
        success: function (res) {
            if (!res.success) {
                showPatientHistoryAlert('danger', res.error || 'No se pudo cargar el historial.');
                $('#patient-history-body').html('<tr><td colspan="6" class="text-center text-muted py-4">No se pudo cargar el historial.</td></tr>');
                return;
            }
            renderPatientAppointmentHistory(res.appointments || []);
        },
        error: function () {
            showPatientHistoryAlert('danger', 'Error de conexión al cargar el historial.');
            $('#patient-history-body').html('<tr><td colspan="6" class="text-center text-muted py-4">No se pudo cargar el historial.</td></tr>');
        }
    });
}

function renderPatientAppointmentHistory(appointments) {
    const rows = Array.isArray(appointments) ? appointments : [];
    if (!rows.length) {
        $('#patient-history-body').html('<tr><td colspan="6" class="text-center text-muted py-4">Este paciente todavía no tiene citas registradas.</td></tr>');
        $('#patient-history-count').text('');
        return;
    }

    const html = rows.map(app => `
        <tr>
            <td><strong>${formatDisplayDate(app.appointment_date || '')}</strong><br><small class="text-muted">${escapeHtml(app.appointment_time || '')}</small></td>
            <td>${professionalCellHtml(app, 'professional_name', 'professional_photo_path')}</td>
            <td>${escapeHtml(app.service_label || '')}<br><small class="text-muted">${parseInt(app.duration_minutes || 60, 10)} minutos</small></td>
            <td>${consultationTypeLabel(app.consultation_type)}</td>
            <td>${adminPaymentLabel(app)}</td>
            <td>${appointmentStatusLabel(app.status)}</td>
        </tr>
    `).join('');

    $('#patient-history-body').html(html);
    $('#patient-history-count').text(`${rows.length} ${rows.length === 1 ? 'cita' : 'citas'}`);
}

function savePatient(form) {
    const $button = $('#btn-save-patient');
    const original = $button.html();
    const formData = new FormData(form);
    const selectedProfessional = $('#admin-patients-professional').val() || '';
    if (selectedProfessional && selectedProfessional !== 'all') {
        formData.set('professional_id', selectedProfessional);
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
                showPatientEditorAlert('danger', res.error || 'No se pudo guardar el paciente.');
                return;
            }
            showAdminPatientsAlert('success', res.message || 'Paciente guardado correctamente.');
            if (res.patient_id) {
                $('#patient-editor-id').val(res.patient_id);
                loadPatientAppointmentHistory(res.patient_id);
            }
            patientEditorModal.hide();
            loadAdminPatients();
            loadBookingPatients();
        },
        error: function () {
            showPatientEditorAlert('danger', 'Error de conexión al guardar el paciente.');
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
                    : 'Enviar invitacion al paciente.');
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
    if (!$select.length || !ADMIN_BOOKING_PATIENTS.length) {
        return;
    }
    const selected = $select.val();
    $select.html('<option value="">Selecciona un paciente...</option>');
    ADMIN_BOOKING_PATIENTS.forEach(patient => {
        const professional = patient.professional_name ? ` · ${patient.professional_name}` : '';
        $select.append(`<option value="${patient.id}">${escapeHtml((patient.name || '') + professional)}</option>`);
    });
    if (selected) {
        $select.val(selected);
    }
}

function loadBookingPatients() {
    if (!IS_ADMIN) return;
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
            }
        }
    });
}

function bookingPatientById(patientId) {
    return ADMIN_BOOKING_PATIENTS.find(patient => String(patient.id) === String(patientId)) || null;
}

function bookingProfessionalById(professionalId) {
    return CABINET_PROFESSIONALS.find(professional => String(professional.id) === String(professionalId)) || null;
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
}

function updateBookingPatientProfessionalNote(patient = null) {
    const $note = $('#booking-patient-professional-note');
    if (!$note.length) return;
    if (!patient) {
        $note.text('');
        return;
    }
    const professionalName = patient.professional_name || '';
    if (professionalName) {
        $note.text(`Paciente de ${professionalName}. Puedes cambiar el profesional solo para esta cita.`);
    } else {
        const currentProfessional = bookingProfessionalById(CURRENT_PROFESSIONAL_ID);
        $note.text(`Paciente sin profesional asignado. Se asignará por defecto a ${currentProfessional ? currentProfessional.display_name : 'tu usuario'}.`);
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
    $select.html('<option value="">Cargando servicios...</option>');
    $('#btn-confirm-action').prop('disabled', true);
    $.ajax({
        url: 'api/appointments.php?action=booking_context',
        method: 'GET',
        dataType: 'json',
        data: { professional_id: professionalId },
        success: function (res) {
            if (res.success) {
                PAYMENT_SETTINGS = res.payment_settings || PAYMENT_SETTINGS;
                ACTIVE_SERVICE_OPTIONS = Array.isArray(res.service_options) ? res.service_options : [];
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
            $('#btn-confirm-action').prop('disabled', false);
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

function openUpcomingAppointmentsModal() {
    if (!upcomingAppointmentsModal) return;
    $('#upcoming-appointments-alert').addClass('d-none').text('');
    $('#upcoming-appointments-search').val('');
    $('#upcoming-appointments-scope').val('limit10');
    upcomingAppointmentsInitialLoad = true;
    populateUpcomingProfessionalsFilter();
    $('#upcoming-appointments-body').html('<tr><td colspan="6" class="text-center text-muted py-4">Cargando...</td></tr>');
    $('#upcoming-appointments-count').text('');
    upcomingAppointmentsModal.show();
    loadUpcomingAppointments();
}

function loadUpcomingAppointments() {
    $('#upcoming-appointments-alert').addClass('d-none').text('');
    $('#upcoming-appointments-body').html('<tr><td colspan="6" class="text-center text-muted py-4">Cargando...</td></tr>');
    $('#upcoming-appointments-count').text('');
    const hasProfessionalFilter = $('#upcoming-appointments-professional').length > 0;
    const professionalFilterValue = hasProfessionalFilter && upcomingAppointmentsInitialLoad
        ? 'current'
        : ($('#upcoming-appointments-professional').val() || 'all');
    $.ajax({
        url: 'api/admin.php?action=upcoming_appointments',
        data: {
            scope: $('#upcoming-appointments-scope').val() || 'limit10',
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
            renderUpcomingAppointments(CURRENT_UPCOMING_APPOINTMENTS);
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
        $('#upcoming-appointments-body').html('<tr><td colspan="6" class="text-center text-muted py-4">No hay citas pr&oacute;ximas.</td></tr>');
        $('#upcoming-appointments-count').text('');
        return;
    }
    if (!rows.length) {
        $('#upcoming-appointments-body').html('<tr><td colspan="6" class="text-center text-muted py-4">No hay citas que coincidan con la busqueda.</td></tr>');
        $('#upcoming-appointments-count').text('');
        return;
    }

    let html = '';
    rows.forEach(app => {
        const professional = upcomingProfessionalCell(app);
        html += `
            <tr>
                <td><strong>${formatDisplayDate(app.appointment_date)}</strong><br><small class="text-muted">${escapeHtml(app.appointment_time || '')}</small></td>
                <td>${professional}</td>
                <td>${escapeHtml(app.patient_name || '')}<br><small class="text-muted">${escapeHtml(app.patient_email || app.patient_phone || '')}</small></td>
                <td>${escapeHtml(app.service_label || '')}</td>
                <td>${consultationTypeLabel(app.consultation_type)}</td>
                <td>${adminPaymentLabel(app)}</td>
            </tr>
        `;
    });
    $('#upcoming-appointments-body').html(html);
    $('#upcoming-appointments-count').text(`${rows.length} ${rows.length === 1 ? 'cita' : 'citas'}`);
}

function upcomingProfessionalCell(app = {}) {
    return professionalCellHtml(app, 'professional_name', 'professional_photo_path');
}

function professionalCellHtml(source = {}, nameKey = 'professional_name', photoKey = 'professional_photo_path') {
    const photo = source[photoKey] || '';
    const avatar = photo
        ? `<img src="${escapeHtml(photo)}" alt="" class="upcoming-professional-avatar">`
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

function adminPaymentLabel(app) {
    if (app.payment_status === 'paid') {
        return app.payment_method === 'bonus'
            ? '<span class="badge text-bg-success">Bono</span>'
            : '<span class="badge text-bg-success">Pagada</span>';
    }
    if (app.payment_status === 'failed') {
        return '<span class="badge text-bg-danger">Fallido</span>';
    }
    return '<span class="badge text-bg-warning">Pendiente</span>';
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
        },
        error: function () {
            showAdminStatsAlert('danger', 'Error de conexion al cargar las estadisticas.');
        }
    });
}

function renderAdminStats(stats) {
    const topPatients = Array.isArray(stats.top_patients) ? stats.top_patients : [];
    const professionalSummary = Array.isArray(stats.professional_summary) ? stats.professional_summary : [];
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
                            <span><strong>${parseInt(item.patient_count || 0, 10)}</strong> pacientes</span>
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
            <div class="stats-summary-card"><span>Bonos activos</span><strong>${parseInt(stats.active_bonus_count || 0, 10)}</strong><small>${parseInt(stats.active_bonus_sessions || 0, 10)} sesiones</small></div>
        </div>
        ${teamSummary}
        <h6 class="mb-3">Pacientes con m&aacute;s sesiones</h6>
        <div class="stats-bars">${bars}</div>
    `);
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

function loadPaymentSettings() {
    $('#payment-settings-alert, #email-settings-alert, #calendar-settings-alert, #booking-settings-alert, #general-settings-alert, #interface-settings-alert, #services-settings-alert, #bonuses-settings-alert, #cabinet-settings-alert').addClass('d-none');
    $('#merchant-key').val('');
    $('#smtp-password').val('');
    $('#google-client-secret').val('');
    $('#google-refresh-token').val('');
    $('#merchant-key-status').text('');
    $('#smtp-password-status').text('');
    $('#google-client-secret-status').text('');

    $.ajax({
        url: 'api/admin.php?action=get_payment_settings',
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                showPaymentSettingsAlert('danger', res.error || 'No se pudo cargar la configuración.');
                return;
            }

            let settings = res.settings || {};
            PAYMENT_SETTINGS = Object.assign({}, PAYMENT_SETTINGS, settings);
            APPOINTMENT_SERVICES = Array.isArray(res.services) ? res.services : [];
            APPOINTMENT_BONUSES = Array.isArray(res.bonuses) ? res.bonuses : [];
            $('#app-name').val(settings.app_name || 'PsicoLogic');
            $('#site-tagline').val(settings.site_tagline || '');
            $('#site-phone').val(settings.site_phone || '');
            const primaryColor = settings.primary_color || '#8f7fba';
            $('#primary-color').val(primaryColor);
            $('#primary-color-text').val(primaryColor);
            document.documentElement.style.setProperty('--primary-color', primaryColor);
            $('#appointment-delivery-mode').val(settings.appointment_delivery_mode || 'both');
            $('#app-brand').text(settings.app_name || 'PsicoLogic');
            document.title = `Dashboard - ${settings.app_name || 'PsicoLogic'}`;
            $('#show-profile-image-public').prop('checked', settings.show_profile_image_public == 1);
            $('#show-prices-public').prop('checked', settings.show_prices_public == 1);
            $('#online-booking-enabled').prop('checked', settings.online_booking_enabled === undefined ? true : settings.online_booking_enabled == 1);
            $('#bonuses-enabled').prop('checked', settings.bonuses_enabled == 1);
            $('#create-compensation-bonus-on-paid-cancel').prop('checked', settings.create_compensation_bonus_on_paid_cancel === undefined ? true : settings.create_compensation_bonus_on_paid_cancel == 1);
            toggleBonusesSettings();
            renderBonusesSettings();
            setAvailableSessionDurations(settings.available_session_durations || '60');
            renderServicesSettings();
            if (settings.profile_image_path) {
                $('#profile-image-preview').attr('src', settings.profile_image_path);
                $('#profile-image-preview-row').attr('style', '');
                $('#profile-image-status').text('Imagen actual guardada.');
                $('#app-brand-image').attr('src', settings.profile_image_path).removeClass('d-none');
            } else {
                $('#profile-image-preview-row').attr('style', 'display: none !important;');
                $('#profile-image-preview').attr('src', '');
                $('#profile-image-status').text('');
                $('#app-brand-image').attr('src', '').addClass('d-none');
            }
            $('#profile-image').val('');
            if (settings.landing_image_path) {
                $('#landing-image-preview').attr('src', settings.landing_image_path);
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
            $('#admin-notification-email').val(settings.admin_notification_email || '');
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
            if (typeof IS_SUPERADMIN !== 'undefined' && IS_SUPERADMIN) {
                loadCabinetSettings();
            }
        },
        error: function () {
            showPaymentSettingsAlert('danger', 'Error de conexión al cargar la configuración.');
        }
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
        ? 'El paciente recibirá un enlace .ics compatible con Apple Calendar, iCloud, Google Calendar y Outlook.'
        : 'Conecta primero la cuenta de email para poder enviar este enlace a los pacientes.');
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
    const showCouple = $('#available-session-couple').is(':checked');
    let html = '';
    APPOINTMENT_SERVICES.forEach(service => {
        const serviceKey = service.service_key === 'couple' ? 'couple' : 'individual';
        if (serviceKey === 'couple' && !showCouple) {
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

function saveBonusesSettings(button = null) {
    $('#bonuses-settings-alert').addClass('d-none');
    setSettingsButtonLoading(button, true);

    $.ajax({
        url: 'api/admin.php?action=save_bonuses',
        method: 'POST',
        dataType: 'json',
        data: {
            bonuses_enabled: $('#bonuses-enabled').is(':checked') ? '1' : '0',
            create_compensation_bonus_on_paid_cancel: $('#create-compensation-bonus-on-paid-cancel').is(':checked') ? '1' : '0',
            bonuses_json: JSON.stringify(collectBonusesSettings())
        },
        success: function (res) {
            if (res.success) {
                APPOINTMENT_BONUSES = Array.isArray(res.bonuses) ? res.bonuses : APPOINTMENT_BONUSES;
                renderBonusesSettings();
                showSettingsAlert('#bonuses-settings-alert', 'success', res.message || 'Bonos guardados correctamente.');
            } else {
                showSettingsAlert('#bonuses-settings-alert', 'danger', res.error || 'No se pudieron guardar los bonos.');
            }
        },
        error: function () {
            showSettingsAlert('#bonuses-settings-alert', 'danger', 'Error de conexión al guardar los bonos.');
        },
        complete: function () {
            setSettingsButtonLoading(button, false);
        }
    });
}

function selectedSessionDurations() {
    const durations = [];
    $('.available-session-duration:checked').each(function () {
        const duration = parseInt(this.value, 10);
        if ([60, 90, 120].includes(duration)) {
            durations.push(duration);
        }
    });
    return durations.length ? [...new Set(durations)].sort((a, b) => a - b) : [60];
}

function collectServicesSettings() {
    const visibleDurations = selectedSessionDurations();
    const visibleMode = $('#appointment-delivery-mode').val() || PAYMENT_SETTINGS.appointment_delivery_mode || 'both';
    const showCouple = $('#available-session-couple').is(':checked');
    return APPOINTMENT_SERVICES.map(service => {
        const $serviceRow = $(`.service-group-row[data-service-id="${service.id}"]`);
        const serviceKey = service.service_key === 'couple' ? 'couple' : 'individual';
        const serviceVisible = serviceKey === 'individual' || showCouple;
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

function saveServicesSettings(button = null) {
    $('#services-settings-alert').addClass('d-none');
    setSettingsButtonLoading(button, true);

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
                renderWeekInfo();
                showSettingsAlert('#services-settings-alert', 'success', res.message || 'Precios guardados correctamente.');
            } else {
                showSettingsAlert('#services-settings-alert', 'danger', res.error || 'No se pudieron guardar los precios.');
            }
        },
        error: function () {
            showSettingsAlert('#services-settings-alert', 'danger', 'Error de conexión al guardar los precios.');
        },
        complete: function () {
            setSettingsButtonLoading(button, false);
        }
    });
}

function loadCabinetSettings() {
    const $body = $('#professionals-settings-body');
    if (!$body.length) return;
    $body.html('<tr><td colspan="6" class="text-center text-muted py-4">Cargando...</td></tr>');
    $('#cabinet-settings-loading').removeClass('d-none');
    $('#show-team-public, #allow-patient-transfer').prop('disabled', true);

    $.ajax({
        url: 'api/admin.php?action=get_cabinet_settings',
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                showSettingsAlert('#cabinet-settings-alert', 'danger', res.error || 'No se pudo cargar el equipo profesional.');
                return;
            }
            $('#show-team-public').prop('checked', res.settings && res.settings.show_team_public == 1);
            $('#allow-patient-transfer').prop('checked', res.settings && res.settings.allow_patient_transfer == 1);
            CABINET_PROFESSIONALS = (res.professionals || []).map(normalizeProfessional);
            renderProfessionalsSettings();
        },
        error: function () {
            showSettingsAlert('#cabinet-settings-alert', 'danger', 'Error de conexión al cargar el equipo profesional.');
        },
        complete: function () {
            $('#cabinet-settings-loading').addClass('d-none');
            $('#show-team-public, #allow-patient-transfer').prop('disabled', false);
        }
    });
}

function normalizeProfessional(professional = {}) {
    return {
        id: parseInt(professional.id || 0, 10),
        user_id: parseInt(professional.user_id || 0, 10),
        display_name: professional.display_name || '',
        professional_title: professional.professional_title || '',
        license_number: professional.license_number || '',
        professional_specialty: professional.professional_specialty || '',
        public_photo_path: professional.public_photo_path || '',
        display_photo_path: professional.display_photo_path || professional.public_photo_path || '',
        email: professional.email || '',
        role: professional.role === 'superadmin' ? 'superadmin' : 'admin',
        is_active: professional.is_active == 0 ? 0 : 1,
        is_current_user: professional.is_current_user == 1 ? 1 : 0
    };
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
        ? `<img src="${escapeHtml(photo)}" alt="" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">`
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
    $('#professional-editor-index').val(index);
    $('#professional-editor-id').val(professional.id || 0);
    $('#professional-editor-user-id').val(professional.user_id || 0);
    $('#professional-editor-name').val(professional.display_name || '');
    $('#professional-editor-email').val(professional.email || '');
    $('#professional-editor-title-field').val(professional.professional_title || '');
    $('#professional-editor-license').val(professional.license_number || '');
    $('#professional-editor-specialty').val(professional.professional_specialty || '');
    $('#professional-editor-photo').val('');
    PROFESSIONAL_PHOTO_FILE = null;
    const photo = professional.public_photo_path || professional.display_photo_path || '';
    if (photo) {
        $('#professional-editor-photo-preview').attr('src', photo).removeClass('d-none');
        $('#professional-editor-photo-status').text('Foto actual. Sube una nueva solo si quieres cambiarla.');
    } else {
        $('#professional-editor-photo-preview').attr('src', '').addClass('d-none');
        $('#professional-editor-photo-status').text('Formatos permitidos: JPG, PNG, WEBP o GIF. Máximo 2 MB.');
    }
    $('#professional-editor-role').val(professional.role || 'admin');
    $('#professional-editor-active').prop('checked', professional.is_active != 0);

    $('.professional-editor-permission-wrap, .professional-editor-status-wrap').toggleClass('d-none', isCurrentSuperadmin);
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
        public_photo_path: existing.public_photo_path || '',
        display_photo_path: existing.display_photo_path || existing.public_photo_path || '',
        role: isCurrentSuperadmin ? 'superadmin' : $('#professional-editor-role').val(),
        is_active: isCurrentSuperadmin ? 1 : ($('#professional-editor-active').is(':checked') ? 1 : 0),
        is_current_user: existing.is_current_user || 0
    });

    if (!professional.display_name || !professional.email) {
        showSettingsAlert('#cabinet-settings-alert', 'danger', 'Indica nombre y email del miembro.');
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
        photoFile: selectedPhotoFile,
        photoIndex: index >= 0 ? index : CABINET_PROFESSIONALS.length - 1
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
        ? `<strong>${professionalName}</strong> tiene <strong>${usage.pending_appointments || 0}</strong> citas próximas y <strong>${usage.assigned_patients || 0}</strong> pacientes asignados.${historyText}`
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
    $('#cabinet-settings-alert').addClass('d-none');
    setSettingsButtonLoading(button, true);
    const professionals = collectProfessionalsSettings();
    const formData = new FormData();
    formData.append('show_team_public', $('#show-team-public').is(':checked') ? '1' : '0');
    formData.append('allow_patient_transfer', $('#allow-patient-transfer').is(':checked') ? '1' : '0');
    formData.append('professionals_json', JSON.stringify(professionals));
    if (options.photoFile && options.photoIndex >= 0) {
        formData.append('professional_photo', options.photoFile);
        formData.append('professional_photo_index', String(options.photoIndex));
    }

    $.ajax({
        url: 'api/admin.php?action=save_cabinet_settings',
        method: 'POST',
        dataType: 'json',
        data: formData,
        processData: false,
        contentType: false,
        success: function (res) {
            if (res.success) {
                showSettingsAlert('#cabinet-settings-alert', 'success', res.message || 'Equipo guardado correctamente.');
                if (options.closeProfessionalModal && professionalEditorModal) {
                    professionalEditorModal.hide();
                }
                PROFESSIONAL_PHOTO_FILE = null;
                loadCabinetSettings();
            } else {
                showSettingsAlert('#cabinet-settings-alert', 'danger', res.error || 'No se pudo guardar el equipo profesional.');
            }
        },
        error: function () {
            showSettingsAlert('#cabinet-settings-alert', 'danger', 'Error de conexión al guardar el equipo profesional.');
        },
        complete: function () {
            setSettingsButtonLoading(button, false);
        }
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

function savePaymentSettings(alertSelector = '#payment-settings-alert', onSuccess = null, button = null, section = '') {
    $('#payment-settings-alert, #email-settings-alert, #calendar-settings-alert, #booking-settings-alert, #general-settings-alert, #interface-settings-alert, #services-settings-alert, #bonuses-settings-alert').addClass('d-none');
    setSettingsButtonLoading(button, true);

    const reminderInput = document.getElementById('appointment-reminder-enabled');
    const reminderEnabledValue = reminderInput && reminderInput.checked ? '1' : '0';

    const formData = new FormData();
    formData.append('settings_section', section);
    formData.append('online_payment_enabled', $('#online-payment-enabled').is(':checked') ? '1' : '0');
    formData.append('app_name', $('#app-name').val().trim());
    formData.append('site_tagline', $('#site-tagline').val().trim());
    formData.append('site_phone', $('#site-phone').val().trim());
    formData.append('primary_color', $('#primary-color-text').val().trim());
    formData.append('appointment_delivery_mode', $('#appointment-delivery-mode').val());
    formData.append('available_session_types[]', 'individual');
    if ($('#available-session-couple').is(':checked')) {
        formData.append('available_session_types[]', 'couple');
    }
    $('.available-session-duration:checked').each(function () {
        formData.append('available_session_durations[]', this.value);
    });
    formData.append('show_profile_image_public', $('#show-profile-image-public').is(':checked') ? '1' : '0');
    formData.append('show_prices_public', $('#show-prices-public').is(':checked') ? '1' : '0');
    formData.append('online_booking_enabled', $('#online-booking-enabled').is(':checked') ? '1' : '0');
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
    formData.append('admin_notification_email', $('#admin-notification-email').val().trim());
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
                loadPaymentSettings();
                renderWeekInfo();
                showSettingsAlert(alertSelector, 'success', res.message || 'Configuración guardada correctamente.');
                if (onSuccess) {
                    onSuccess();
                }
            } else {
                showSettingsAlert(alertSelector, 'danger', res.error || 'No se pudo guardar la configuración.');
            }
        },
        error: function () {
            showSettingsAlert(alertSelector, 'danger', 'Error de conexión al guardar la configuración.');
        },
        complete: function () {
            setSettingsButtonLoading(button, false);
        }
    });
}
