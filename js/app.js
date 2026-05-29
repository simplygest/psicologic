let currentStartDate = getMonday(new Date());
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
    appointment_delivery_mode: 'both'
};
let isAppointmentRequestInProgress = false;

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
    loadCalendar(formatDate(currentStartDate));
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
                drawCalendar(startDateStr, res.appointments, res.closed_days);
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

function renderSlot(dateStr, timeStr, dayApps) {
    let app = dayApps ? dayApps[timeStr] : null;
    let isPast = isPastSlot(dateStr, timeStr);
    let isOutsideBookingWindow = isOutsideAllowedBookingWindow(dateStr);
    let cls = 'available';
    let text = timeStr;
    let isBooked = false;
    let onClick = `openModal('${dateStr}', '${timeStr}', 'available')`;

    // Convert current user ID if available in session? We rely on UI vs API limits mostly.
    if (app) {
        let paymentBadge = getPaymentBadge(app);
        let consultationBadge = getConsultationBadge(app.consultation_type);
        let serviceBadge = getServiceBadge(app.service_type);
        let payButton = canPayAppointment(app)
            ? `<button class="btn btn-success slot-action-btn" onclick="event.stopPropagation(); openModal('${dateStr}', '${timeStr}', 'pay_own', '${app.id}', '${app.service_type || 'individual'}', '${app.consultation_type || 'presencial'}');" title="Pagar cita"><i class="bi bi-credit-card"></i></button>`
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
                        <span class="slot-actions"><button class="btn btn-danger slot-action-btn" onclick="event.stopPropagation(); openModal('${dateStr}', '${timeStr}', 'cancel_admin', '${app.name}', '${app.email}', '${app.phone}');" title="Cancelar cita"><i class="bi bi-trash"></i></button></span>
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
                            <span class="slot-actions">${payButton}<button class="btn btn-danger slot-action-btn" onclick="event.stopPropagation(); openModal('${dateStr}', '${timeStr}', 'cancel_own');" title="Cancelar cita"><i class="bi bi-trash"></i></button></span>
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

function getServiceBadge(serviceType) {
    if (serviceType !== 'couple') {
        return '';
    }
    return ' <small class="service-badge couple">Pareja</small>';
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
let currentPaymentAppointmentId = null;

function openModal(date, time, status, extraName = '', extraEmail = '', extraPhone = '') {
    $('#modalDate').val(date);
    $('#modalTime').val(time);
    $('#modalStatus').val(status);
    $('#payment-options').addClass('d-none');
    $('#consultationTypeSelect').addClass('d-none');
    $('#serviceTypeSelect').addClass('d-none');
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
        if ((PAYMENT_SETTINGS.appointment_delivery_mode || 'both') === 'both') {
            $('#consultation-type').val('presencial');
            $('#consultationTypeSelect').removeClass('d-none');
        }
        if (isCoupleServiceEnabled()) {
            $('#service-type').val('individual');
            $('#serviceTypeSelect').removeClass('d-none');
        }
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
        $('#modalDesc').html(`Tu cita está reservada correctamente.<br><br>Elige cómo quieres pagarla (${appointmentPriceForType(extraPhone, extraEmail)} €).`);
        $('#btn-confirm-action').addClass('d-none');
        $('#payment-options-text').text('');
        $('#payment-options').removeClass('d-none');
    }

    appointmentModal.show();
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
        currentStartDate.setDate(currentStartDate.getDate() - 7);
        renderWeekInfo();
    });

    $('#btn-next-week').click(function () {
        currentStartDate.setDate(currentStartDate.getDate() + 7);
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

    $('#email-provider').change(function () {
        toggleEmailProviderSettings();
    });

    $('#online-payment-enabled').change(function () {
        togglePaymentSettings();
    });

    $('#google-calendar-enabled').change(function () {
        toggleCalendarSettings();
    });

    $('#appointment-delivery-mode').change(function () {
        togglePriceRows();
    });

    $('.available-session-type').change(function () {
        $('#available-session-individual').prop('checked', true);
        togglePriceRows();
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
        service_type: selectedServiceType()
    };

    if (IS_ADMIN) {
        data.user_id = $('#patientSelect').val();
        if (!data.user_id) { alert('Selecciona un paciente'); return; }
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

            if (!IS_ADMIN && PAYMENT_SETTINGS.online_payment_enabled == 1 && res.appointment_id) {
                currentPaymentAppointmentId = res.appointment_id;
                $('#modalTitle').text('Cita reservada');
                $('#modalDesc').html(`Tu cita ${serviceTypeLabel(data.service_type).toLowerCase()} ${consultationTypeLabel(data.consultation_type).toLowerCase()} ha quedado reservada correctamente.<br><br>Si quieres, puedes pagarla ahora (${appointmentPriceForType(data.consultation_type, data.service_type)} €).`);
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
    return isCoupleServiceEnabled() && $('#service-type').val() === 'couple' ? 'couple' : 'individual';
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
                res.days.forEach(d => {
                    html += `<li class="list-group-item d-flex justify-content-between align-items-center">
                                ${d.closed_date} - ${d.reason}
                                <button class="btn btn-sm btn-danger" onclick="deleteClosedDay(${d.id})"><i class="bi bi-trash"></i></button>
                             </li>`;
                });
                $('#closed-days-list').html(html);
            }
        }
    });
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
    $('#payment-settings-alert, #email-settings-alert, #calendar-settings-alert, #booking-settings-alert, #general-settings-alert, #interface-settings-alert').addClass('d-none');
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
            $('#app-name').val(settings.app_name || 'PsicoLogic');
            const primaryColor = settings.primary_color || '#8f7fba';
            $('#primary-color').val(primaryColor);
            $('#primary-color-text').val(primaryColor);
            document.documentElement.style.setProperty('--primary-color', primaryColor);
            $('#appointment-delivery-mode').val(settings.appointment_delivery_mode || 'both');
            $('#app-brand').text(settings.app_name || 'PsicoLogic');
            document.title = `Dashboard - ${settings.app_name || 'PsicoLogic'}`;
            $('#show-profile-image-public').prop('checked', settings.show_profile_image_public == 1);
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
            $('#google-calendar-enabled').prop('checked', settings.google_calendar_enabled == 1);
            toggleCalendarSettings();
            $('#google-calendar-id').val(settings.google_calendar_id || 'primary');
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
    setFieldBlockEnabled('#calendar-config-fields', $('#google-calendar-enabled').is(':checked'));
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
    $('#payment-settings-alert, #email-settings-alert, #calendar-settings-alert, #booking-settings-alert, #general-settings-alert, #interface-settings-alert').addClass('d-none');
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
    formData.append('show_profile_image_public', $('#show-profile-image-public').is(':checked') ? '1' : '0');
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
    formData.append('google_calendar_enabled', $('#google-calendar-enabled').is(':checked') ? '1' : '0');
    formData.append('google_calendar_id', $('#google-calendar-id').val().trim());

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
