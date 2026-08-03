<?php

function message_template_keys()
{
    return ['appointment_email_reminder', 'appointment_sms_reminder', 'appointment_whatsapp_reminder'];
}

function message_template_default_subject($template_key)
{
    return $template_key === 'appointment_email_reminder' ? 'Recordatorio de cita' : '';
}

function message_template_default_body($template_key)
{
    if ($template_key === 'appointment_sms_reminder' || $template_key === 'appointment_whatsapp_reminder') {
        return 'Recordatorio de cita con {profesional_nombre} el {fecha_corta} a las {hora}. Gestionar/cancelar: {enlace_gestion}';
    }

    return "<p>Hola {nombre},</p>\n" .
        "<p>Te recordamos tu cita con {profesional_nombre} el {fecha} a las {hora} ({zona_horaria}).</p>\n" .
        "<p>Un saludo,<br>{nombre_centro}</p>";
}

function message_template_available_variables()
{
    return [
        'nombre' => 'Nombre',
        'nombre_completo' => 'Nombre completo',
        'profesional' => 'Profesional',
        'profesional_nombre' => 'Primer nombre del profesional',
        'fecha' => 'Fecha',
        'fecha_corta' => 'Fecha corta',
        'hora' => 'Hora',
        'hora_fin' => 'Hora fin',
        'zona_horaria' => 'Zona horaria',
        'duracion' => 'Duracion',
        'modalidad' => 'Modalidad',
        'servicio' => 'Servicio',
        'lugar' => 'Lugar',
        'link' => 'Link online',
        'enlace_gestion' => 'Enlace gestion/cancelacion',
        'nombre_centro' => 'Nombre del centro',
        'telefono_centro' => 'Telefono del centro',
        'importe' => 'Importe'
    ];
}

function ensure_message_templates_table($mysqli)
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS message_templates (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED NOT NULL,
            template_key VARCHAR(64) NOT NULL,
            subject VARCHAR(255) DEFAULT NULL,
            body TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_message_templates_tenant_key (tenant_id, template_key),
            KEY idx_message_templates_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function message_template_default_payload($template_key)
{
    return [
        'template_key' => $template_key,
        'subject' => message_template_default_subject($template_key),
        'body' => message_template_default_body($template_key),
        'is_default' => 1
    ];
}

function message_template_get($mysqli, $template_key)
{
    $template_key = trim((string) $template_key);
    if (!in_array($template_key, message_template_keys(), true)) {
        return null;
    }

    ensure_message_templates_table($mysqli);
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("
        SELECT template_key, subject, body
        FROM message_templates
        WHERE tenant_id = ? AND template_key = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return message_template_default_payload($template_key);
    }
    $stmt->bind_param("is", $tenant_id, $template_key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return message_template_default_payload($template_key);
    }

    return [
        'template_key' => $template_key,
        'subject' => $row['subject'] ?? '',
        'body' => $row['body'] ?? '',
        'is_default' => 0
    ];
}

function message_templates_get_all($mysqli)
{
    $rows = [];
    foreach (message_template_keys() as $key) {
        $rows[$key] = message_template_get($mysqli, $key);
    }
    return $rows;
}

function message_template_save($mysqli, $template_key, $subject, $body)
{
    $template_key = trim((string) $template_key);
    if (!in_array($template_key, message_template_keys(), true)) {
        throw new Exception('Plantilla no valida.');
    }

    $subject = trim((string) $subject);
    $body = trim((string) $body);
    if ($body === '') {
        throw new Exception('El texto de la plantilla no puede estar vacio.');
    }
    if ($template_key === 'appointment_sms_reminder' && strlen($body) > 500) {
        throw new Exception('El SMS es demasiado largo.');
    }

    ensure_message_templates_table($mysqli);
    $tenant_id = current_tenant_id();
    $stmt = $mysqli->prepare("
        INSERT INTO message_templates (tenant_id, template_key, subject, body)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body)
    ");
    $stmt->bind_param("isss", $tenant_id, $template_key, $subject, $body);
    $stmt->execute();
}

function message_template_first_name($name)
{
    $name = trim(preg_replace('/\s+/', ' ', (string) $name));
    if ($name === '') {
        return '';
    }
    $parts = explode(' ', $name);
    return $parts[0] ?: $name;
}

function message_template_render($template, array $vars)
{
    $template = (string) $template;
    $replacements = [];
    foreach ($vars as $key => $value) {
        $replacements['{' . $key . '}'] = (string) $value;
    }
    return strtr($template, $replacements);
}
