<?php

function ensure_time_tracking_schema($mysqli)
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS time_tracking_entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            event_type ENUM('clock_in','break_start','break_end','clock_out') NOT NULL,
            occurred_at_utc DATETIME NOT NULL,
            local_datetime DATETIME NOT NULL,
            timezone_name VARCHAR(64) NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'web',
            created_by INT UNSIGNED NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            voided_at DATETIME DEFAULT NULL,
            voided_by INT UNSIGNED DEFAULT NULL,
            void_reason VARCHAR(500) DEFAULT NULL,
            INDEX idx_time_tracking_tenant_user_time (tenant_id, user_id, occurred_at_utc),
            INDEX idx_time_tracking_tenant_time (tenant_id, occurred_at_utc),
            INDEX idx_time_tracking_active (tenant_id, user_id, voided_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function time_tracking_plan_enabled($mysqli)
{
    return function_exists('plan_feature_enabled_from_db')
        && plan_feature_enabled_from_db($mysqli, 'timeTracking.enabled', false);
}

function time_tracking_enabled($mysqli)
{
    return !empty(time_tracking_settings_values($mysqli)['enabled']);
}

function time_tracking_settings_values($mysqli)
{
    $defaults = [
        'enabled' => 0,
        'notify_missing_clock_in' => 0,
        'require_clock_in' => 0,
        'logout_on_clock_out' => 0
    ];
    if (!time_tracking_plan_enabled($mysqli)) {
        return $defaults;
    }
    $res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'time_tracking_enabled'");
    if (!$res || $res->num_rows === 0) {
        return $defaults;
    }
    $tenant_id = current_tenant_id();
    $columns = [
        'time_tracking_enabled',
        'time_tracking_notify_missing_clock_in',
        'time_tracking_require_clock_in',
        'time_tracking_logout_on_clock_out'
    ];
    foreach (array_slice($columns, 1) as $column) {
        $column_res = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE '" . $mysqli->real_escape_string($column) . "'");
        if (!$column_res || $column_res->num_rows === 0) {
            return $defaults;
        }
    }
    $stmt = $mysqli->prepare("
        SELECT time_tracking_enabled, time_tracking_notify_missing_clock_in,
               time_tracking_require_clock_in, time_tracking_logout_on_clock_out
        FROM payment_settings
        WHERE tenant_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $tenant_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    return [
        'enabled' => (int) ($row['time_tracking_enabled'] ?? 0),
        'notify_missing_clock_in' => (int) ($row['time_tracking_notify_missing_clock_in'] ?? 0),
        'require_clock_in' => (int) ($row['time_tracking_require_clock_in'] ?? 0),
        'logout_on_clock_out' => (int) ($row['time_tracking_logout_on_clock_out'] ?? 0)
    ];
}

function time_tracking_latest_entry($mysqli, $tenant_id, $user_id)
{
    $stmt = $mysqli->prepare("
        SELECT id, event_type, occurred_at_utc, local_datetime, timezone_name
        FROM time_tracking_entries
        WHERE tenant_id = ? AND user_id = ? AND voided_at IS NULL
        ORDER BY occurred_at_utc DESC, id DESC
        LIMIT 1
    ");
    $stmt->bind_param('ii', $tenant_id, $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function time_tracking_state_from_event($event_type)
{
    if ($event_type === 'clock_in' || $event_type === 'break_end') {
        return 'working';
    }
    if ($event_type === 'break_start') {
        return 'break';
    }
    return 'off';
}

function time_tracking_allowed_events($state)
{
    if ($state === 'working') {
        return ['break_start', 'clock_out'];
    }
    if ($state === 'break') {
        return ['break_end'];
    }
    return ['clock_in'];
}

function time_tracking_current_status($mysqli, $tenant_id, $user_id)
{
    $entry = time_tracking_latest_entry($mysqli, $tenant_id, $user_id);
    $state = time_tracking_state_from_event($entry['event_type'] ?? '');
    return [
        'state' => $state,
        'last_entry' => $entry,
        'allowed_events' => time_tracking_allowed_events($state)
    ];
}

function time_tracking_register_event($mysqli, $tenant_id, $user_id, $event_type)
{
    $allowed_types = ['clock_in', 'break_start', 'break_end', 'clock_out'];
    if (!in_array($event_type, $allowed_types, true)) {
        throw new InvalidArgumentException('Tipo de fichaje no válido.');
    }

    $status = time_tracking_current_status($mysqli, $tenant_id, $user_id);
    if (!in_array($event_type, $status['allowed_events'], true)) {
        throw new RuntimeException('Ese fichaje no corresponde con el estado actual.');
    }

    $timezone_name = function_exists('tenant_timezone') ? tenant_timezone() : 'UTC';
    try {
        $timezone = new DateTimeZone($timezone_name);
    } catch (Throwable $e) {
        $timezone_name = 'UTC';
        $timezone = new DateTimeZone('UTC');
    }
    $utc_now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $local_now = $utc_now->setTimezone($timezone);
    $occurred_at_utc = $utc_now->format('Y-m-d H:i:s');
    $local_datetime = $local_now->format('Y-m-d H:i:s');
    $created_by = (int) ($_SESSION['user_id'] ?? $user_id);
    $source = 'web';
    $ip_address = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $user_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $stmt = $mysqli->prepare("
        INSERT INTO time_tracking_entries
            (tenant_id, user_id, event_type, occurred_at_utc, local_datetime, timezone_name, source, created_by, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'iisssssiss',
        $tenant_id,
        $user_id,
        $event_type,
        $occurred_at_utc,
        $local_datetime,
        $timezone_name,
        $source,
        $created_by,
        $ip_address,
        $user_agent
    );
    $stmt->execute();

    return [
        'id' => (int) $mysqli->insert_id,
        'event_type' => $event_type,
        'occurred_at_utc' => $occurred_at_utc,
        'local_datetime' => $local_datetime,
        'timezone_name' => $timezone_name
    ];
}

function time_tracking_event_label($event_type)
{
    return [
        'clock_in' => 'Entrada',
        'break_start' => 'Inicio de descanso',
        'break_end' => 'Fin de descanso',
        'clock_out' => 'Salida'
    ][$event_type] ?? $event_type;
}

function time_tracking_format_seconds($seconds)
{
    $seconds = max(0, (int) round($seconds));
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remaining_seconds = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $remaining_seconds);
}

function time_tracking_add_interval_by_day(array &$days, $user_id, $user_name, $state, DateTimeImmutable $from, DateTimeImmutable $to)
{
    if (!in_array($state, ['working', 'break'], true) || $to <= $from) {
        return;
    }
    $cursor = $from;
    while ($cursor < $to) {
        $next_day = $cursor->setTime(0, 0, 0)->modify('+1 day');
        $segment_end = $to < $next_day ? $to : $next_day;
        $date = $cursor->format('Y-m-d');
        $key = $user_id . '|' . $date;
        if (!isset($days[$key])) {
            $days[$key] = [
                'user_id' => (int) $user_id,
                'user_name' => (string) $user_name,
                'date' => $date,
                'first_entry' => '',
                'working_seconds' => 0,
                'break_seconds' => 0
            ];
        }
        $seconds = max(0, $segment_end->getTimestamp() - $cursor->getTimestamp());
        if ($state === 'working') {
            $days[$key]['working_seconds'] += $seconds;
        } else {
            $days[$key]['break_seconds'] += $seconds;
        }
        $cursor = $segment_end;
    }
}

function time_tracking_report_members($mysqli, $tenant_id)
{
    $contract_select = function_exists('column_exists') && column_exists($mysqli, 'professional_settings', 'contract_hours')
        ? "ps.contract_hours, ps.contract_hours_unit"
        : "NULL AS contract_hours, 'daily' AS contract_hours_unit";
    $stmt = $mysqli->prepare("
        SELECT DISTINCT u.id AS user_id, COALESCE(NULLIF(p.display_name, ''), u.name, u.email) AS user_name,
               $contract_select
        FROM users u
        INNER JOIN professionals p ON p.tenant_id = u.tenant_id AND p.user_id = u.id
        LEFT JOIN professional_settings ps ON ps.tenant_id = p.tenant_id AND ps.professional_id = p.id
        WHERE u.tenant_id = ? AND u.role <> 'patient'
        ORDER BY user_name ASC
    ");
    $stmt->bind_param('i', $tenant_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function time_tracking_report_data($mysqli, $tenant_id, $requested_user_id, $date_from, $date_to)
{
    $members = time_tracking_report_members($mysqli, $tenant_id);
    $member_map = [];
    foreach ($members as $member) {
        $member_map[(int) $member['user_id']] = (string) $member['user_name'];
    }
    $selected_ids = $requested_user_id > 0
        ? (isset($member_map[$requested_user_id]) ? [$requested_user_id] : [])
        : array_keys($member_map);

    $timezone_name = function_exists('tenant_timezone') ? tenant_timezone() : 'UTC';
    try {
        $timezone = new DateTimeZone($timezone_name);
    } catch (Throwable $e) {
        $timezone = new DateTimeZone('UTC');
    }
    $period_start = new DateTimeImmutable($date_from . ' 00:00:00', $timezone);
    $period_end = new DateTimeImmutable($date_to . ' 23:59:59', $timezone);
    $now = new DateTimeImmutable('now', $timezone);
    $effective_end = $period_end < $now ? $period_end : $now;
    $days = [];
    $entries = [];

    foreach ($selected_ids as $user_id) {
        $user_id = (int) $user_id;
        $user_name = $member_map[$user_id] ?? '';
        $previous_stmt = $mysqli->prepare("
            SELECT event_type, local_datetime
            FROM time_tracking_entries
            WHERE tenant_id = ? AND user_id = ? AND voided_at IS NULL AND local_datetime < ?
            ORDER BY local_datetime DESC, id DESC
            LIMIT 1
        ");
        $period_start_sql = $period_start->format('Y-m-d H:i:s');
        $previous_stmt->bind_param('iis', $tenant_id, $user_id, $period_start_sql);
        $previous_stmt->execute();
        $previous = $previous_stmt->get_result()->fetch_assoc();

        $state = time_tracking_state_from_event($previous['event_type'] ?? '');
        $cursor = $period_start;
        $events_stmt = $mysqli->prepare("
            SELECT id, event_type, local_datetime, timezone_name
            FROM time_tracking_entries
            WHERE tenant_id = ? AND user_id = ? AND voided_at IS NULL
              AND local_datetime BETWEEN ? AND ?
            ORDER BY local_datetime ASC, id ASC
        ");
        $period_end_sql = $effective_end->format('Y-m-d H:i:s');
        $events_stmt->bind_param('iiss', $tenant_id, $user_id, $period_start_sql, $period_end_sql);
        $events_stmt->execute();
        $events = $events_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($events as $event) {
            $event_time = new DateTimeImmutable($event['local_datetime'], $timezone);
            time_tracking_add_interval_by_day($days, $user_id, $user_name, $state, $cursor, $event_time);
            if ($event['event_type'] === 'clock_in') {
                $day_key = $user_id . '|' . $event_time->format('Y-m-d');
                if (!isset($days[$day_key])) {
                    $days[$day_key] = [
                        'user_id' => $user_id,
                        'user_name' => $user_name,
                        'date' => $event_time->format('Y-m-d'),
                        'first_entry' => '',
                        'working_seconds' => 0,
                        'break_seconds' => 0
                    ];
                }
                if ($days[$day_key]['first_entry'] === '') {
                    $days[$day_key]['first_entry'] = $event['local_datetime'];
                }
            }
            $entries[] = [
                'id' => (int) $event['id'],
                'user_id' => $user_id,
                'user_name' => $user_name,
                'event_type' => $event['event_type'],
                'event_label' => time_tracking_event_label($event['event_type']),
                'local_datetime' => $event['local_datetime'],
                'timezone_name' => $event['timezone_name']
            ];
            $state = time_tracking_state_from_event($event['event_type']);
            $cursor = $event_time;
        }
        time_tracking_add_interval_by_day($days, $user_id, $user_name, $state, $cursor, $effective_end);
    }

    usort($entries, static function ($a, $b) {
        return strcmp($b['local_datetime'], $a['local_datetime']);
    });
    uasort($days, static function ($a, $b) {
        return [$a['date'], $a['user_name']] <=> [$b['date'], $b['user_name']];
    });

    return [
        'members' => $members,
        'entries' => array_values($entries),
        'days' => array_values($days),
        'date_from' => $date_from,
        'date_to' => $date_to
    ];
}

function time_tracking_build_report(array $data, $report_type)
{
    $report_type = in_array($report_type, ['summary', 'entries', 'daily', 'average', 'overtime_daily', 'overtime_weekly'], true)
        ? $report_type
        : 'entries';
    $titles = [
        'summary' => 'Resumen de horas',
        'entries' => 'Entradas y salidas',
        'daily' => 'Horas trabajadas por día y empleado',
        'average' => 'Media de horas trabajadas',
        'overtime_daily' => 'Registro diario de horas extra',
        'overtime_weekly' => 'Registro semanal de horas extra'
    ];
    $rows = [];
    $headers = [];
    $contract_references = [];
    foreach ($data['members'] as $member) {
        $hours = isset($member['contract_hours']) && $member['contract_hours'] !== null
            ? (float) $member['contract_hours']
            : 0.0;
        $unit = ($member['contract_hours_unit'] ?? 'daily') === 'weekly' ? 'weekly' : 'daily';
        if ($hours <= 0) {
            $hours = 8.0;
            $unit = 'daily';
        }
        $contract_references[(int) $member['user_id']] = [
            'daily_seconds' => (int) round(($unit === 'weekly' ? $hours / 5 : $hours) * 3600),
            'weekly_seconds' => (int) round(($unit === 'weekly' ? $hours : $hours * 5) * 3600)
        ];
    }

    if ($report_type === 'entries') {
        $headers = ['Fecha', 'Miembro', 'Acción', 'Zona horaria'];
        foreach ($data['entries'] as $entry) {
            $rows[] = [
                $entry['local_datetime'],
                $entry['user_name'],
                $entry['event_label'],
                $entry['timezone_name']
            ];
        }
    } elseif ($report_type === 'daily') {
        $headers = ['Día', 'Miembro', 'Primera entrada', 'Horas trabajadas', 'Descansos'];
        foreach ($data['days'] as $day) {
            $rows[] = [
                $day['date'],
                $day['user_name'],
                $day['first_entry'],
                time_tracking_format_seconds($day['working_seconds']),
                time_tracking_format_seconds($day['break_seconds'])
            ];
        }
    } else {
        $by_user = [];
        foreach ($data['days'] as $day) {
            $user_id = (int) $day['user_id'];
            if (!isset($by_user[$user_id])) {
                $by_user[$user_id] = [
                    'user_name' => $day['user_name'],
                    'working_seconds' => 0,
                    'break_seconds' => 0,
                    'worked_days' => 0
                ];
            }
            $by_user[$user_id]['working_seconds'] += $day['working_seconds'];
            $by_user[$user_id]['break_seconds'] += $day['break_seconds'];
            if ($day['working_seconds'] > 0) {
                $by_user[$user_id]['worked_days']++;
            }
        }

        if ($report_type === 'summary') {
            $headers = ['Miembro', 'Horas trabajadas', 'Descansos', 'Días con actividad'];
            foreach ($by_user as $user) {
                $rows[] = [
                    $user['user_name'],
                    time_tracking_format_seconds($user['working_seconds']),
                    time_tracking_format_seconds($user['break_seconds']),
                    $user['worked_days']
                ];
            }
        } elseif ($report_type === 'average') {
            $headers = ['Miembro', 'Días trabajados', 'Media diaria'];
            foreach ($by_user as $user) {
                $average = $user['worked_days'] > 0 ? $user['working_seconds'] / $user['worked_days'] : 0;
                $rows[] = [$user['user_name'], $user['worked_days'], time_tracking_format_seconds($average)];
            }
        } elseif ($report_type === 'overtime_daily') {
            $headers = ['Día', 'Miembro', 'Horas trabajadas', 'Referencia diaria', 'Horas extra'];
            foreach ($data['days'] as $day) {
                $reference = $contract_references[(int) $day['user_id']]['daily_seconds'] ?? 8 * 3600;
                $extra = max(0, $day['working_seconds'] - $reference);
                $rows[] = [
                    $day['date'],
                    $day['user_name'],
                    time_tracking_format_seconds($day['working_seconds']),
                    time_tracking_format_seconds($reference),
                    time_tracking_format_seconds($extra)
                ];
            }
        } else {
            $headers = ['Semana', 'Miembro', 'Horas trabajadas', 'Referencia semanal', 'Horas extra'];
            $weeks = [];
            foreach ($data['days'] as $day) {
                $date = new DateTimeImmutable($day['date']);
                $week = $date->format('o-\WW');
                $key = $day['user_id'] . '|' . $week;
                if (!isset($weeks[$key])) {
                    $weeks[$key] = ['week' => $week, 'user_id' => (int) $day['user_id'], 'user_name' => $day['user_name'], 'working_seconds' => 0];
                }
                $weeks[$key]['working_seconds'] += $day['working_seconds'];
            }
            foreach ($weeks as $week) {
                $reference = $contract_references[(int) $week['user_id']]['weekly_seconds'] ?? 40 * 3600;
                $extra = max(0, $week['working_seconds'] - $reference);
                $rows[] = [
                    $week['week'],
                    $week['user_name'],
                    time_tracking_format_seconds($week['working_seconds']),
                    time_tracking_format_seconds($reference),
                    time_tracking_format_seconds($extra)
                ];
            }
        }
    }

    return [
        'type' => $report_type,
        'title' => $titles[$report_type],
        'headers' => $headers,
        'rows' => $rows
    ];
}
