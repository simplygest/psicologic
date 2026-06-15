<?php

function caldav_default_url()
{
    return 'https://caldav.icloud.com';
}

function caldav_build_ics_datetime($value, $timezone = 'Atlantic/Canary')
{
    $date = new DateTimeImmutable($value, new DateTimeZone($timezone));
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
}

function caldav_escape_text($value)
{
    $value = str_replace('\\', '\\\\', (string) $value);
    $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
    return str_replace([',', ';'], ['\,', '\;'], $value);
}

function caldav_build_ics($uid, $summary, $description, $start_value, $end_value, $timezone = 'Atlantic/Canary', $location = '')
{
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//PsicoLogic//CalDAV//ES',
        'CALSCALE:GREGORIAN',
        'BEGIN:VEVENT',
        'UID:' . $uid,
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . caldav_build_ics_datetime($start_value, $timezone),
        'DTEND:' . caldav_build_ics_datetime($end_value, $timezone),
        'SUMMARY:' . caldav_escape_text($summary),
        'DESCRIPTION:' . caldav_escape_text($description),
    ];
    if (trim((string) $location) !== '') {
        $lines[] = 'LOCATION:' . caldav_escape_text($location);
        $lines[] = 'URL:' . trim((string) $location);
    }
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    return implode("\r\n", $lines) . "\r\n";
}

function caldav_request($method, $url, $username, $password, $body = '', $headers = [], $depth = null)
{
    if (!function_exists('curl_init')) {
        throw new \Exception('La extension PHP cURL no esta disponible.');
    }

    $request_headers = $headers;
    if ($depth !== null) {
        $request_headers[] = 'Depth: ' . $depth;
    }
    if ($body !== '' && !array_filter($request_headers, fn($header) => stripos($header, 'Content-Type:') === 0)) {
        $request_headers[] = 'Content-Type: application/xml; charset=utf-8';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERPWD => $username . ':' . $password,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_UNRESTRICTED_AUTH => true,
        CURLOPT_HTTPHEADER => $request_headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    if ($body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response_body = curl_exec($ch);
    if ($response_body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new \Exception('Error CalDAV: ' . $error);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => $response_body,
        'url' => $effective_url ?: $url
    ];
}

function caldav_xml_xpath($xml)
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $dom->loadXML($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        throw new \Exception('La respuesta CalDAV no es XML valido.');
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('d', 'DAV:');
    $xpath->registerNamespace('cal', 'urn:ietf:params:xml:ns:caldav');
    return $xpath;
}

function caldav_resolve_url($base_url, $href)
{
    $href = trim($href);
    if ($href === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }

    $base = parse_url($base_url);
    if (!$base || empty($base['scheme']) || empty($base['host'])) {
        return $href;
    }

    $port = isset($base['port']) ? ':' . $base['port'] : '';
    $root = $base['scheme'] . '://' . $base['host'] . $port;
    if ($href[0] === '/') {
        return $root . $href;
    }

    $path = $base['path'] ?? '/';
    $path = preg_replace('#/[^/]*$#', '/', $path);
    return $root . $path . $href;
}

function caldav_first_href($xml, $query)
{
    $xpath = caldav_xml_xpath($xml);
    $nodes = $xpath->query($query);
    if (!$nodes || $nodes->length === 0) {
        return '';
    }
    return trim($nodes->item(0)->textContent);
}

function caldav_discover_default_calendar($base_url, $username, $password)
{
    $base_url = rtrim($base_url ?: caldav_default_url(), '/');

    $principal_body = '<?xml version="1.0" encoding="utf-8" ?><d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal /></d:prop></d:propfind>';
    $principal_urls = [
        $base_url . '/.well-known/caldav',
        $base_url . '/'
    ];
    $principal_response = null;
    foreach ($principal_urls as $principal_url) {
        $candidate = caldav_request('PROPFIND', $principal_url, $username, $password, $principal_body, [], 0);
        if (in_array($candidate['status'], [207, 200], true)) {
            $principal_response = $candidate;
            break;
        }
        $principal_response = $candidate;
    }

    if (!$principal_response || !in_array($principal_response['status'], [207, 200], true)) {
        throw new \Exception('No se pudo obtener el principal CalDAV. HTTP ' . ($principal_response['status'] ?? 'sin respuesta'));
    }

    $principal_href = caldav_first_href($principal_response['body'], '//d:current-user-principal/d:href');
    if ($principal_href === '') {
        throw new \Exception('No se encontro current-user-principal en CalDAV.');
    }
    $principal_url = caldav_resolve_url($principal_response['url'], $principal_href);

    $home_body = '<?xml version="1.0" encoding="utf-8" ?><d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"><d:prop><cal:calendar-home-set /></d:prop></d:propfind>';
    $home_response = caldav_request('PROPFIND', $principal_url, $username, $password, $home_body, [], 0);
    if (!in_array($home_response['status'], [207, 200], true)) {
        throw new \Exception('No se pudo obtener calendar-home-set. HTTP ' . $home_response['status']);
    }

    $home_href = caldav_first_href($home_response['body'], '//cal:calendar-home-set/d:href');
    if ($home_href === '') {
        throw new \Exception('No se encontro calendar-home-set en CalDAV.');
    }
    $home_url = caldav_resolve_url($home_response['url'], $home_href);

    $calendar_body = '<?xml version="1.0" encoding="utf-8" ?><d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"><d:prop><d:displayname /><d:resourcetype /><cal:supported-calendar-component-set /></d:prop></d:propfind>';
    $calendar_response = caldav_request('PROPFIND', $home_url, $username, $password, $calendar_body, [], 1);
    if (!in_array($calendar_response['status'], [207, 200], true)) {
        throw new \Exception('No se pudieron listar calendarios. HTTP ' . $calendar_response['status']);
    }

    $xpath = caldav_xml_xpath($calendar_response['body']);
    $responses = $xpath->query('//d:response');
    foreach ($responses as $response) {
        $href_node = $xpath->query('d:href', $response)->item(0);
        if (!$href_node) {
            continue;
        }

        $is_calendar = $xpath->query('.//d:resourcetype/cal:calendar', $response)->length > 0;
        if (!$is_calendar) {
            continue;
        }

        $supports_vevent = $xpath->query('.//cal:supported-calendar-component-set/cal:comp[@name="VEVENT"]', $response)->length > 0;
        $has_component_set = $xpath->query('.//cal:supported-calendar-component-set', $response)->length > 0;
        if ($has_component_set && !$supports_vevent) {
            continue;
        }

        $display_node = $xpath->query('.//d:displayname', $response)->item(0);
        return [
            'url' => caldav_resolve_url($calendar_response['url'], $href_node->textContent),
            'name' => $display_node ? trim($display_node->textContent) : 'Calendario'
        ];
    }

    throw new \Exception('No se encontro ningun calendario compatible con eventos.');
}

function caldav_create_event_in_default_calendar($base_url, $username, $password, $summary, $description, $start_value, $end_value, $timezone = 'Atlantic/Canary')
{
    $calendar = caldav_discover_default_calendar($base_url, $username, $password);
    $uid = bin2hex(random_bytes(16)) . '@psicologic';
    $event_url = rtrim($calendar['url'], '/') . '/' . rawurlencode($uid) . '.ics';
    $ics = caldav_build_ics($uid, $summary, $description, $start_value, $end_value, $timezone);

    $response = caldav_request('PUT', $event_url, $username, $password, $ics, [
        'Content-Type: text/calendar; charset=utf-8'
    ]);

    if (!in_array($response['status'], [200, 201, 204], true)) {
        throw new \Exception('No se pudo crear el evento CalDAV. HTTP ' . $response['status'] . '. ' . substr(trim($response['body']), 0, 500));
    }

    return [
        'uid' => $uid,
        'event_url' => $event_url,
        'calendar_url' => $calendar['url'],
        'calendar_name' => $calendar['name'],
        'status' => $response['status']
    ];
}

function caldav_get_settings($mysqli)
{
    $res = $mysqli->query("
        SELECT calendar_provider, icloud_calendar_email, icloud_calendar_app_password, icloud_calendar_url
        FROM payment_settings
        WHERE id = 1
    ");

    return $res->fetch_assoc() ?: [];
}

function caldav_ensure_appointment_event_column($mysqli)
{
    $res = $mysqli->query("SHOW COLUMNS FROM appointments LIKE 'icloud_calendar_event_url'");
    if ($res->num_rows === 0) {
        $mysqli->query("ALTER TABLE appointments ADD icloud_calendar_event_url VARCHAR(512) DEFAULT NULL");
    }
}

function icloud_create_calendar_event($mysqli, $appointment_id)
{
    caldav_ensure_appointment_event_column($mysqli);

    $settings = caldav_get_settings($mysqli);
    if (($settings['calendar_provider'] ?? 'none') !== 'icloud') {
        return null;
    }

    $username = trim($settings['icloud_calendar_email'] ?? '');
    $password = trim($settings['icloud_calendar_app_password'] ?? '');
    $base_url = trim($settings['icloud_calendar_url'] ?? '') ?: caldav_default_url();
    if ($username === '' || $password === '') {
        throw new \Exception('Faltan credenciales iCloud CalDAV');
    }

    $stmt = $mysqli->prepare("
        SELECT a.appointment_date, a.appointment_time, a.consultation_type, a.service_type,
               COALESCE(a.duration_minutes, so.duration_minutes, 60) AS duration_minutes,
               s.name AS service_name,
               u.name, u.email, u.phone
        FROM appointments a
        LEFT JOIN appointment_service_options so ON so.id = a.service_option_id
        LEFT JOIN appointment_services s ON s.id = so.service_id
        JOIN users u ON u.id = a.user_id
        WHERE a.id = ?
    ");
    $stmt->bind_param("i", $appointment_id);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
    if (!$appointment) {
        return null;
    }

    $start = new DateTimeImmutable($appointment['appointment_date'] . ' ' . $appointment['appointment_time'], new DateTimeZone(date_default_timezone_get()));
    $end = $start->modify('+' . (int) ($appointment['duration_minutes'] ?? 60) . ' minutes');
    $consultation_text = appointment_consultation_label($appointment['consultation_type'] ?? 'presencial');
    $service_text = appointment_service_option_label($appointment);

    $description = 'Paciente: ' . ($appointment['name'] ?? '') . "\nServicio: " . $service_text . "\nModalidad: " . $consultation_text;
    if (!empty($appointment['phone'])) {
        $description .= "\nTelefono: " . $appointment['phone'];
    }
    if (!empty($appointment['email'])) {
        $description .= "\nEmail: " . $appointment['email'];
    }

    $result = caldav_create_event_in_default_calendar(
        $base_url,
        $username,
        $password,
        'Cita ' . $service_text . ' ' . $consultation_text . ' - ' . ($appointment['name'] ?? ''),
        $description,
        $start->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s'),
        date_default_timezone_get()
    );

    $stmt = $mysqli->prepare("UPDATE appointments SET icloud_calendar_event_url = ? WHERE id = ?");
    $stmt->bind_param("si", $result['event_url'], $appointment_id);
    $stmt->execute();

    return $result['event_url'];
}

function icloud_delete_calendar_event($mysqli, $appointment_id)
{
    caldav_ensure_appointment_event_column($mysqli);

    $stmt = $mysqli->prepare("SELECT icloud_calendar_event_url FROM appointments WHERE id = ?");
    $stmt->bind_param("i", $appointment_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $event_url = trim($row['icloud_calendar_event_url'] ?? '');
    if ($event_url === '') {
        return false;
    }

    $settings = caldav_get_settings($mysqli);
    $username = trim($settings['icloud_calendar_email'] ?? '');
    $password = trim($settings['icloud_calendar_app_password'] ?? '');
    if ($username === '' || $password === '') {
        throw new \Exception('Faltan credenciales iCloud CalDAV');
    }

    $response = caldav_request('DELETE', $event_url, $username, $password);
    if (!in_array($response['status'], [200, 202, 204, 404], true)) {
        throw new \Exception('No se pudo eliminar el evento CalDAV. HTTP ' . $response['status'] . '. ' . substr(trim($response['body']), 0, 500));
    }

    return true;
}
