<?php
require_once __DIR__ . '/dashboard_config_helpers.php';

function plan_usage_current_config($mysqli)
{
    return plan_config_for_key(dashboard_config_plan_key_from_db($mysqli));
}

function plan_usage_limit($mysqli, $limit, $default = null)
{
    return plan_config_limit_value(plan_usage_current_config($mysqli), $limit, $default);
}

function plan_usage_patient_count($mysqli)
{
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM users WHERE tenant_id = ? AND role = 'patient'");
    $stmt->bind_param('i', $tenant_id);
    $stmt->execute();
    return (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
}

function plan_usage_assert_patient_capacity($mysqli)
{
    $limit = (int) plan_usage_limit($mysqli, 'maxPatients', 0);
    if ($limit <= 0) {
        return;
    }
    $current = plan_usage_patient_count($mysqli);
    if ($current >= $limit) {
        throw new RuntimeException(
            'Has alcanzado el límite de ' . $limit . ' pacientes/clientes de tu plan. '
            . 'Puedes consultar los existentes o cambiar a un plan superior para añadir más.'
        );
    }
}

function plan_usage_week_bounds($appointment_date)
{
    $timezone = function_exists('tenant_timezone') ? tenant_timezone() : date_default_timezone_get();
    try {
        $date = new DateTimeImmutable((string) $appointment_date, new DateTimeZone($timezone));
    } catch (Throwable $exception) {
        throw new RuntimeException('La fecha de la cita no es válida.');
    }
    $start = $date->modify('monday this week')->format('Y-m-d');
    $end = $date->modify('sunday this week')->format('Y-m-d');
    return [$start, $end];
}

function plan_usage_appointment_count_for_week($mysqli, $appointment_date)
{
    [$start, $end] = plan_usage_week_bounds($appointment_date);
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("
        SELECT COUNT(*) AS total
        FROM appointments
        WHERE tenant_id = ?
          AND appointment_date BETWEEN ? AND ?
          AND status <> 'cancelled'
    ");
    $stmt->bind_param('iss', $tenant_id, $start, $end);
    $stmt->execute();
    return (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
}

function plan_usage_assert_appointment_capacity($mysqli, $appointment_date)
{
    $limit = (int) plan_usage_limit($mysqli, 'maxAppointmentsPerWeek', 0);
    if ($limit <= 0) {
        return;
    }
    $current = plan_usage_appointment_count_for_week($mysqli, $appointment_date);
    if ($current >= $limit) {
        [$start, $end] = plan_usage_week_bounds($appointment_date);
        throw new RuntimeException(
            'Has alcanzado el límite de ' . $limit . ' citas para la semana del '
            . date('d/m/Y', strtotime($start)) . ' al ' . date('d/m/Y', strtotime($end))
            . '. Cambia a un plan superior para programar más citas.'
        );
    }
}

function plan_usage_uploads_enabled($mysqli)
{
    return plan_config_feature_enabled(plan_usage_current_config($mysqli), 'documents.uploads', true);
}

function plan_usage_assert_uploads_enabled($mysqli)
{
    if (!plan_usage_uploads_enabled($mysqli)) {
        throw new RuntimeException(
            'La subida de archivos y adjuntos no está disponible en tu plan. '
            . 'Puedes utilizar esta función al cambiar a un plan superior.'
        );
    }
}

