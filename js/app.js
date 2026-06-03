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
let adminStatsModal = null;
let bonusesModal = null;
let selectedBonusToBuy = null;
let currentInviteLink = '';

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
        let closedReason = isClosed ? closedDays[dateStr] : '';

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
        const clickable = status.available ? `onclick="selectMonthDay('${dateStr}')"` : '';
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
    const day = new Date(`${dateStr}T00:00:00`);
    const jsDay = day.getDay();
    const dayNumber = jsDay === 0 ? 7 : jsDay;
    if (!getActiveWeekdays().includes(dayNumber)) {
        return { available: false, freeSlots: 0, label: 'No disponible', className: 'disabled-day' };
    }
    if (closedDays[dateStr]) {
        return { available: false, freeSlots: 0, label: closedDays[dateStr], className: 'closed-day' };
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
    let html = `<div class="month-day-panel-header"><h5>${formatDisplayDate(selectedMonthDay)}</h5><span>${status.freeSlots || 0} huecos libres</span></div>`;
    if (closedDays[selectedMonthDay]) {
        return html + `<div class="alert alert-danger">${escapeHtml(closedDays[selectedMonthDay])}</div>`;
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
inviteModal = document.getElementById('inviteModal') ? new bootstrap.Modal(document.getElementById('inviteModal')) : null;
upcomingAppointmentsModal = document.getElementById('upcomingAppointmentsModal') ? new bootstrap.Modal(document.getElementById('upcomingAppointmentsModal')) : null;
adminStatsModal = document.getElementById('adminStatsModal') ? new bootstrap.Modal(document.getElementById('adminStatsModal')) : null;
bonusesModal = document.getElementById('bonusesModal') ? new bootstrap.Modal(document.getElementById('bonusesModal')) : null;
let currentPaymentAppointmentId = null;

function openModal(date, time, status, extraName = '', extraEmail = '', extraPhone = '') {
    $('#modalDate').val(date);
    $('#modalTime').val(time);
    $('#modalStatus').val(status);
    $('#payment-options').addClass('d-none');
    $('#consultationTypeSelect').addClass('d-none');
    $('#serviceTypeSelect').addClass('d-none');
    $('#serviceOptionSelect').addClass('d-none');
    $('#booking-bonus-notice').addClass('d-none').text('');
    currentPaymentAppointmentId = null;
    $('#btn-confirm-action').removeClass('d-none').prop('disabled', false);

    if (status === 'available') {
        $('#modalTitle').text(`Reservar cita: ${formatDisplayDate(date)} a las ${time}`);
        if (IS_ADMIN) {
            $('#modalDesc').text('Selecciona un paciente para reservar el horario.');
            $('#adminPatientSelect').removeClass('d-none');
        } else {
            $('#modalDesc').text('¿Estás seguro de que deseas reservar este horario?');
        }
        renderBookingServiceOptions();
        $('#serviceOptionSelect').removeClass('d-none');
        refreshBookingBonusNotice();
        $('#btn-confirm-action').removeClass('btn-danger').addClass('btn-primary').text('Reservar');
    } else if (status === 'cancel_admin') {
        $('#modalTitle').text(`Cancelar cita: ${formatDisplayDate(date)} a las ${time}`);
        $('#modalDesc').html(`Paciente: <b>${extraName}</b><br><small>Email: ${extraEmail}<br>Tel: ${extraPhone}</small><br><br>¿Confirmar cancelación?`);
        if (IS_ADMIN) $('#adminPatientSelect').addClass('d-none');
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
        $.ajax({
            url: 'api/admin.php?action=get_patients',
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    let sel = $('#patientSelect');
                    res.patients.forEach(p => {
                        sel.append(`<option value="${p.id}">${p.name}</option>`);
                    });
                }
            }
        });
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

    $('#btn-generate-invite').click(function () {
        $.ajax({
            url: 'api/admin.php?action=generate_invite',
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    // Copy to clipboard
                    navigator.clipboard.writeText(res.link).then(() => {
                        $('#admin-actions-msg').text('✔ Enlace copiado al portapapeles').fadeIn().delay(3000).fadeOut();
                    });
                }
            }
        });
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
        $.ajax({
            url: 'api/admin.php?action=generate_invite',
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    openInviteModal(res.link);
                    copyTextToClipboard(res.link, function () {
                        $('#admin-actions-msg').text('Enlace copiado al portapapeles').fadeIn().delay(3000).fadeOut();
                    });
                } else {
                    alert(res.error || 'No se pudo generar la invitacion');
                }
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

    $('#btn-admin-stats').click(function () {
        openAdminStatsModal();
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

    $('#patientSelect, #service-option').change(function () {
        refreshBookingBonusNotice();
    });

    $('#btn-open-settings').click(function () {
        loadClosedDays();
        loadPaymentSettings();
        settingsModal.show();
    });

    $('#add-closed-form').submit(function (e) {
        e.preventDefault();
        $.ajax({
            url: 'api/admin.php?action=add_closed_day',
            method: 'POST',
            data: {
                start_date: $('#closed-start-date').val(),
                end_date: $('#closed-end-date').val(),
                reason: $('#closed-reason').val()
            },
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    $('#closed-start-date').val('');
                    $('#closed-end-date').val('');
                    $('#closed-reason').val('');
                    loadClosedDays();
                    renderWeekInfo(); // Refresh bg
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
        savePaymentSettings('#payment-settings-alert', null, $(this).find('button[type="submit"]'));
    });

    $('#btn-save-email-settings').click(function () {
        savePaymentSettings('#email-settings-alert', null, this);
    });

    $('#btn-save-calendar-settings').click(function () {
        savePaymentSettings('#calendar-settings-alert', null, this);
    });

    $('#btn-save-booking-settings').click(function () {
        savePaymentSettings('#booking-settings-alert', null, this);
    });

    $('#btn-save-general-settings').click(function () {
        savePaymentSettings('#general-settings-alert', null, this);
    });

    $('#btn-save-interface-settings').click(function () {
        savePaymentSettings('#interface-settings-alert', null, this);
    });

    $('#btn-save-services-settings').click(function () {
        saveServicesSettings(this);
    });

    $('#btn-save-bonuses-settings').click(function () {
        saveBonusesSettings(this);
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
        }, this);
    });

    $('#btn-google-connect-calendar').click(function () {
        savePaymentSettings('#calendar-settings-alert', function () {
            window.location.href = 'google_oauth_start.php';
        }, this);
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
    const mode = PAYMENT_SETTINGS.appointment_delivery_mode || 'both';
    const visibleOptions = ACTIVE_SERVICE_OPTIONS.filter(option => mode === 'both' || option.consultation_type === mode);
    visibleOptions.forEach(option => {
        const label = `${option.service_name} · ${option.duration_minutes} min · ${consultationTypeLabel(option.consultation_type)} · ${formatPrice(option.price)} €`;
        $select.append(`<option value="${option.id}">${label}</option>`);
    });
    if (!visibleOptions.length) {
        $select.append('<option value="">No hay servicios activos</option>');
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
    $('#bonus-list-head').html(adminView
        ? '<tr><th>Paciente</th><th>Bono</th><th>Compradas</th><th>Restantes</th><th>Pagado</th><th>Comprado</th><th>Estado</th></tr>'
        : '<tr><th>Bono</th><th>Compradas</th><th>Restantes</th><th>Pagado</th><th>Comprado</th><th>Estado</th></tr>');
    $('#bonus-list-body').html('<tr><td colspan="7" class="text-center text-muted py-4">Cargando...</td></tr>');
    bonusesModal.show();

    $.ajax({
        url,
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                showBonusesModalAlert('danger', res.error || 'No se pudieron cargar los bonos.');
                $('#bonus-list-body').empty();
                return;
            }
            renderBonusList(res.bonuses || [], adminView);
        },
        error: function () {
            showBonusesModalAlert('danger', 'Error de conexion al cargar los bonos.');
            $('#bonus-list-body').empty();
        }
    });
}

function renderBonusList(bonuses, adminView) {
    const colspan = adminView ? 7 : 6;
    if (!bonuses.length) {
        $('#bonus-list-body').html(`<tr><td colspan="${colspan}" class="text-center text-muted py-4">No hay bonos comprados.</td></tr>`);
        return;
    }

    let html = '';
    bonuses.forEach(bonus => {
        const paid = bonus.amount_paid !== null && bonus.amount_paid !== undefined ? `${formatPrice(bonus.amount_paid)} €` : '-';
        const date = bonus.purchased_at ? formatDateTimeLabel(bonus.purchased_at) : '-';
        const status = bonusStatusLabel(bonus.status);
        html += '<tr>';
        if (adminView) {
            html += `<td>${escapeHtml(bonus.patient_name || '')}<br><small class="text-muted">${escapeHtml(bonus.patient_email || '')}</small></td>`;
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

function openInviteModal(link) {
    if (!inviteModal) return;
    currentInviteLink = link || '';
    $('#invite-link').val(currentInviteLink);
    $('#invite-email').val('');
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
            link: currentInviteLink
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
    $('#invite-modal-alert')
        .removeClass('d-none alert-success alert-danger')
        .addClass(type === 'success' ? 'alert-success' : 'alert-danger')
        .text(message);
}

function openUpcomingAppointmentsModal() {
    if (!upcomingAppointmentsModal) return;
    $('#upcoming-appointments-alert').addClass('d-none').text('');
    $('#upcoming-appointments-body').html('<tr><td colspan="5" class="text-center text-muted py-4">Cargando...</td></tr>');
    upcomingAppointmentsModal.show();

    $.ajax({
        url: 'api/admin.php?action=upcoming_appointments',
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                showUpcomingAppointmentsAlert('danger', res.error || 'No se pudieron cargar las citas.');
                return;
            }
            renderUpcomingAppointments(res.appointments || []);
        },
        error: function () {
            showUpcomingAppointmentsAlert('danger', 'Error de conexion al cargar las citas.');
        }
    });
}

function renderUpcomingAppointments(appointments) {
    if (!appointments.length) {
        $('#upcoming-appointments-body').html('<tr><td colspan="5" class="text-center text-muted py-4">No hay citas futuras.</td></tr>');
        return;
    }

    let html = '';
    appointments.forEach(app => {
        html += `
            <tr>
                <td><strong>${formatDisplayDate(app.appointment_date)}</strong><br><small class="text-muted">${escapeHtml(app.appointment_time || '')}</small></td>
                <td>${escapeHtml(app.patient_name || '')}<br><small class="text-muted">${escapeHtml(app.patient_email || app.patient_phone || '')}</small></td>
                <td>${escapeHtml(app.service_label || '')}</td>
                <td>${consultationTypeLabel(app.consultation_type)}</td>
                <td>${adminPaymentLabel(app)}</td>
            </tr>
        `;
    });
    $('#upcoming-appointments-body').html(html);
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

    $('#admin-stats-content').html(`
        <div class="stats-summary-grid mb-4">
            <div class="stats-summary-card"><span>Hoy</span><strong>${parseInt(stats.today_count || 0, 10)}</strong><small>citas</small></div>
            <div class="stats-summary-card"><span>Pr&oacute;ximas</span><strong>${parseInt(stats.upcoming_count || 0, 10)}</strong><small>citas</small></div>
            <div class="stats-summary-card"><span>Este mes</span><strong>${parseInt(stats.month_count || 0, 10)}</strong><small>citas</small></div>
            <div class="stats-summary-card"><span>Ingresos online</span><strong>${formatPrice(stats.online_revenue_month || 0)} &euro;</strong><small>este mes</small></div>
            <div class="stats-summary-card"><span>Bonos activos</span><strong>${parseInt(stats.active_bonus_count || 0, 10)}</strong><small>${parseInt(stats.active_bonus_sessions || 0, 10)} sesiones</small></div>
        </div>
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
    $.ajax({
        url: 'api/admin.php?action=list_closed_days',
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                let html = '';
                const ranges = groupClosedDays(res.days || []);
                ranges.forEach(d => {
                    const label = d.start_date === d.end_date
                        ? formatDisplayDate(d.start_date)
                        : `Del ${formatDisplayDate(d.start_date)} al ${formatDisplayDate(d.end_date)}`;
                    html += `<li class="list-group-item d-flex justify-content-between align-items-center">
                                ${label} - ${escapeHtml(d.reason)}
                                <button class="btn btn-sm btn-danger" onclick="deleteClosedRange('${escapeJsString(d.start_date)}', '${escapeJsString(d.end_date)}', '${escapeJsString(d.reason)}')"><i class="bi bi-trash"></i></button>
                             </li>`;
                });
                $('#closed-days-list').html(html);
            }
        }
    });
}

function groupClosedDays(days) {
    const sorted = [...days].sort((a, b) => String(a.closed_date).localeCompare(String(b.closed_date)));
    const ranges = [];

    sorted.forEach(day => {
        const currentDate = String(day.closed_date || '');
        const currentReason = String(day.reason || '');
        const last = ranges[ranges.length - 1];
        if (last && last.reason === currentReason && isNextDate(last.end_date, currentDate)) {
            last.end_date = currentDate;
            return;
        }
        ranges.push({
            start_date: currentDate,
            end_date: currentDate,
            reason: currentReason
        });
    });

    return ranges;
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

function deleteClosedRange(startDate, endDate, reason) {
    const label = startDate === endDate ? formatDisplayDate(startDate) : `del ${formatDisplayDate(startDate)} al ${formatDisplayDate(endDate)}`;
    if (!confirm(`Eliminar este periodo de descanso ${label}?`)) return;
    $.ajax({
        url: 'api/admin.php?action=delete_closed_range',
        method: 'POST',
        data: {
            start_date: startDate,
            end_date: endDate,
            reason
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
        .text(message);

    if (type === 'success') {
        const timer = setTimeout(function () {
            $alert.addClass('d-none');
        }, 4000);
        $alert.data('hide-timer', timer);
    }
}

function loadPaymentSettings() {
    $('#payment-settings-alert, #email-settings-alert, #calendar-settings-alert, #booking-settings-alert, #general-settings-alert, #interface-settings-alert, #services-settings-alert, #bonuses-settings-alert').addClass('d-none');
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
            $('#google-client-secret-status').text(settings.has_google_client_secret == 1
                ? 'Ya hay un Client Secret guardado. Escribe uno nuevo solo si quieres cambiarlo.'
                : 'Todavía no hay Client Secret guardado.');
            togglePriceRows();
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

function savePaymentSettings(alertSelector = '#payment-settings-alert', onSuccess = null, button = null) {
    $('#payment-settings-alert, #email-settings-alert, #calendar-settings-alert, #booking-settings-alert, #general-settings-alert, #interface-settings-alert, #services-settings-alert, #bonuses-settings-alert').addClass('d-none');
    setSettingsButtonLoading(button, true);

    const reminderInput = document.getElementById('appointment-reminder-enabled');
    const reminderEnabledValue = reminderInput && reminderInput.checked ? '1' : '0';

    const formData = new FormData();
    formData.append('online_payment_enabled', $('#online-payment-enabled').is(':checked') ? '1' : '0');
    formData.append('app_name', $('#app-name').val().trim());
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
