<?php

function legal_template_decode_content($json)
{
    $content = json_decode((string) $json, true);
    if (!is_array($content)) {
        $content = [];
    }
    $sections = [];
    foreach (($content['sections'] ?? []) as $section) {
        if (!is_array($section)) {
            continue;
        }
        $title = trim((string) ($section['title'] ?? ''));
        $body = trim((string) ($section['content'] ?? ''));
        if ($title !== '' || $body !== '') {
            $sections[] = ['title' => $title, 'content' => $body];
        }
    }
    return [
        'summary' => trim((string) ($content['summary'] ?? '')),
        'sections' => $sections,
        'declaration' => trim((string) ($content['declaration'] ?? '')),
    ];
}

function legal_template_missing_value($value)
{
    $value = trim((string) $value);
    return $value !== '' ? $value : '________________';
}

function legal_consent_normalize_nif($value)
{
    return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', trim((string) $value)));
}

function legal_consent_expected_signer($mysqli, $tenant_id, $patient_id)
{
    $stmt = $mysqli->prepare("
        SELECT u.name, pp.fiscal_nif, pp.birth_date,
               pp.emergency_contact_name, pp.emergency_contact_nif
        FROM users u
        LEFT JOIN patient_profiles pp ON pp.tenant_id = u.tenant_id AND pp.user_id = u.id
        WHERE u.tenant_id = ? AND u.id = ? AND u.role = 'patient'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    if (!$patient) {
        throw new RuntimeException('No se encontró el paciente asociado a la firma.');
    }

    $is_minor = false;
    $birth_date = trim((string) ($patient['birth_date'] ?? ''));
    if ($birth_date !== '') {
        try {
            $birth = new DateTimeImmutable($birth_date);
            $today = new DateTimeImmutable('today');
            $is_minor = $birth <= $today && $birth->diff($today)->y < 18;
        } catch (Throwable $ignored) {
        }
    }

    $patient_name = trim((string) ($patient['name'] ?? ''));
    $patient_nif = legal_consent_normalize_nif($patient['fiscal_nif'] ?? '');
    $guardian_name = trim((string) ($patient['emergency_contact_name'] ?? ''));
    $guardian_nif = legal_consent_normalize_nif($patient['emergency_contact_nif'] ?? '');

    return [
        'is_minor' => $is_minor,
        'name' => $is_minor ? $guardian_name : $patient_name,
        'nif' => $is_minor ? $guardian_nif : $patient_nif,
        'patient_name' => $patient_name,
        'patient_nif' => $patient_nif,
        'guardian_name' => $guardian_name,
        'guardian_nif' => $guardian_nif,
    ];
}

function legal_consent_validate_signer_identity($expected, $signer_name, $signer_nif, $certificate_nif = null)
{
    $expected_name = trim((string) ($expected['name'] ?? ''));
    $expected_nif = legal_consent_normalize_nif($expected['nif'] ?? '');
    $actual_nif = legal_consent_normalize_nif($signer_nif);

    if (!empty($expected['is_minor']) && ($expected_name === '' || $expected_nif === '')) {
        throw new RuntimeException('El paciente es menor. Debes indicar el nombre y el NIF del tutor en su ficha antes de firmar.');
    }
    if ($certificate_nif === null && $expected_name !== '' && trim((string) $signer_name) !== $expected_name) {
        throw new RuntimeException('La persona firmante no coincide con la indicada en la ficha del paciente.');
    }
    if ($expected_nif !== '' && $actual_nif !== $expected_nif) {
        throw new RuntimeException('El NIF de la persona firmante no coincide con el indicado en la ficha del paciente.');
    }
    if ($certificate_nif !== null && $expected_nif !== '') {
        $certificate_nif = legal_consent_normalize_nif($certificate_nif);
        if ($certificate_nif === '') {
            throw new RuntimeException('El certificado no incluye un NIF que permita comprobar la identidad del firmante.');
        }
        if ($certificate_nif !== $expected_nif) {
            throw new RuntimeException('El NIF del certificado no coincide con el indicado en la ficha del paciente o de su tutor.');
        }
    }
}

function legal_template_patient_context($mysqli, $tenant_id, $patient_id)
{
    $stmt = $mysqli->prepare("
        SELECT u.name, u.email, u.phone,
               pp.fiscal_nif, pp.address, pp.birth_date,
               pp.emergency_contact_name, pp.emergency_contact_nif, pp.emergency_contact_phone, pp.emergency_contact_relation,
               p.display_name AS professional_name
        FROM users u
        LEFT JOIN patient_profiles pp ON pp.tenant_id = u.tenant_id AND pp.user_id = u.id
        LEFT JOIN professionals p ON p.tenant_id = pp.tenant_id AND p.id = pp.professional_id
        WHERE u.tenant_id = ? AND u.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $tenant_id, $patient_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
}

function legal_template_tenant_context($mysqli, $tenant_id)
{
    $stmt = $mysqli->prepare("
        SELECT app_name, site_phone, primary_color, legal_owner_name, legal_nif, legal_address,
               legal_province, legal_city, legal_postal_code, legal_email, legal_health_registry_number,
               legal_license_number, legal_professional_college
        FROM payment_settings
        WHERE tenant_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
}

function legal_template_replace_variables($text, array $values)
{
    $replace = [];
    foreach ($values as $key => $value) {
        $replace['{' . $key . '}'] = legal_template_missing_value($value);
    }
    return strtr((string) $text, $replace);
}

function legal_template_paragraphs_html($text)
{
    $paragraphs = preg_split('/\R{2,}/u', trim((string) $text)) ?: [];
    $html = '';
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            continue;
        }
        $html .= '<p>' . nl2br(report_h($paragraph)) . '</p>';
    }
    return $html;
}

function legal_template_document_html(array $document, array $tenant, array $patient = [], $preview = false)
{
    $content = legal_template_decode_content($document['content_json'] ?? '');
    $tenant_name = trim((string) (($tenant['legal_owner_name'] ?? '') ?: ($tenant['app_name'] ?? ''))) ?: 'SimplyGest Praxis';
    $color = trim((string) ($tenant['primary_color'] ?? '#6f5aa8'));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#6f5aa8';
    }
    $values = [
        'patient_name' => $patient['name'] ?? '',
        'patient_nif' => $patient['fiscal_nif'] ?? '',
        'patient_email' => $patient['email'] ?? '',
        'patient_phone' => $patient['phone'] ?? '',
        'patient_address' => $patient['address'] ?? '',
        'patient_birth_date' => !empty($patient['birth_date']) ? date('d/m/Y', strtotime($patient['birth_date'])) : '',
        'guardian_name' => $patient['emergency_contact_name'] ?? '',
        'guardian_nif' => $patient['emergency_contact_nif'] ?? '',
        'guardian_phone' => $patient['emergency_contact_phone'] ?? '',
        'guardian_relation' => $patient['emergency_contact_relation'] ?? '',
        'professional_name' => $patient['professional_name'] ?? '',
        'tenant_name' => $tenant_name,
        'tenant_nif' => $tenant['legal_nif'] ?? '',
        'tenant_address' => $tenant['legal_address'] ?? '',
        'tenant_province' => $tenant['legal_province'] ?? '',
        'tenant_city' => $tenant['legal_city'] ?? '',
        'tenant_postal_code' => $tenant['legal_postal_code'] ?? '',
        'tenant_email' => $tenant['legal_email'] ?? '',
        'tenant_health_registry_number' => $tenant['legal_health_registry_number'] ?? '',
        'tenant_phone' => $tenant['site_phone'] ?? '',
        'current_date' => date('d/m/Y'),
    ];
    $title = trim((string) ($document['title'] ?? 'Consentimiento informado'));
    $category = trim((string) ($document['category'] ?? 'Consentimiento'));
    $version = trim((string) ($document['version_label'] ?? ''));
    $formatted_address = implode(', ', array_filter([
        trim((string) ($tenant['legal_address'] ?? '')),
        trim((string) ($tenant['legal_postal_code'] ?? '')),
        trim((string) ($tenant['legal_city'] ?? '')),
        trim((string) ($tenant['legal_province'] ?? '')),
    ]));
    $header_meta = array_filter([
        trim((string) ($tenant['legal_nif'] ?? '')) !== '' ? 'NIF: ' . trim((string) $tenant['legal_nif']) : '',
        $formatted_address !== '' ? 'Domicilio: ' . $formatted_address : '',
        trim((string) ($tenant['legal_email'] ?? '')) !== '' ? 'Email: ' . trim((string) $tenant['legal_email']) : '',
        trim((string) ($tenant['site_phone'] ?? '')) !== '' ? 'Teléfono: ' . trim((string) $tenant['site_phone']) : '',
        trim((string) ($tenant['legal_health_registry_number'] ?? '')) !== '' ? 'N.º registro sanitario: ' . trim((string) $tenant['legal_health_registry_number']) : '',
        trim((string) ($tenant['legal_license_number'] ?? '')) !== '' ? 'N.º colegiado: ' . trim((string) $tenant['legal_license_number']) : '',
        trim((string) ($tenant['legal_professional_college'] ?? '')) !== '' ? trim((string) $tenant['legal_professional_college']) : '',
    ]);
    ob_start();
    ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    body { font-family: dejavusans, sans-serif; color: #27313a; font-size: 10.5pt; line-height: 1.48; }
    .header { border-bottom: 3px solid <?= report_h($color) ?>; padding-bottom: 10px; margin-bottom: 22px; }
    .header-table { width: 100%; border-collapse: collapse; }
    .header-table td { padding: 0; vertical-align: top; }
    .header-legal { width: 48%; text-align: right; }
    .brand { color: <?= report_h($color) ?>; font-size: 15pt; font-weight: bold; }
    .meta { color: #68757f; font-size: 8.2pt; line-height: 1.45; }
    h1 { font-size: 19pt; margin: 0 0 3px; color: #202a33; }
    .subtitle { color: <?= report_h($color) ?>; font-size: 9pt; font-weight: bold; text-transform: uppercase; }
    h2 { color: <?= report_h($color) ?>; font-size: 12pt; margin: 18px 0 7px; padding-bottom: 4px; border-bottom: 1px solid #dce2e7; }
    p { margin: 0 0 9px; }
    .identity { width: 100%; border-collapse: collapse; margin: 17px 0 19px; background: #f5f7f9; }
    .identity td { width: 50%; padding: 9px 11px; border-bottom: 1px solid #e0e5e9; vertical-align: top; }
    .identity td:first-child { border-right: 1px solid #e0e5e9; }
    .label { color: #68757f; font-size: 8pt; line-height: 1.2; }
    .value { font-weight: bold; font-size: 10.5pt; line-height: 1.35; padding-top: 3px; }
    .declaration { margin-top: 22px; padding: 13px 15px; border-left: 4px solid <?= report_h($color) ?>; background: #f4f2fa; }
    .declaration-title { color: <?= report_h($color) ?>; font-weight: bold; text-transform: uppercase; margin-bottom: 6px; }
    .signature { margin-top: 28px; page-break-inside: avoid; }
    .signature-line { width: 55%; border-top: 1px solid #7d8790; margin-top: 54px; padding-top: 5px; color: #68757f; font-size: 8.5pt; }
    .footer-note { margin-top: 24px; border-top: 1px solid #dce2e7; padding-top: 7px; color: #7a858e; font-size: 8pt; }
    .preview { padding: 8px 10px; margin-bottom: 15px; background: #fff4d6; border: 1px solid #efd28b; font-size: 9pt; }
</style>
</head>
<body>
    <div class="header">
        <table class="header-table">
            <tr>
                <td><div class="brand"><?= report_h($tenant_name) ?></div></td>
                <td class="header-legal">
                    <?php if ($header_meta): ?><div class="meta"><?= implode('<br>', array_map('report_h', $header_meta)) ?></div><?php endif; ?>
                </td>
            </tr>
        </table>
    </div>
    <?php if ($preview): ?><div class="preview">Vista previa de la plantilla. Los datos que falten se mostrarán mediante una línea.</div><?php endif; ?>
    <div class="subtitle"><?= report_h($category) ?><?= $version !== '' ? ' · ' . report_h($version) : '' ?></div>
    <h1><?= report_h($title) ?></h1>
    <table class="identity">
        <tr>
            <td><div class="label">Paciente / cliente</div><div class="value"><?= report_h(legal_template_missing_value($values['patient_name'])) ?></div></td>
            <td><div class="label">NIF / NIE</div><div class="value"><?= report_h(legal_template_missing_value($values['patient_nif'])) ?></div></td>
        </tr>
        <tr>
            <td><div class="label">Fecha de nacimiento</div><div class="value"><?= report_h(legal_template_missing_value($values['patient_birth_date'])) ?></div></td>
            <td><div class="label">Fecha del documento</div><div class="value"><?= report_h($values['current_date']) ?></div></td>
        </tr>
        <tr>
            <td><div class="label">Profesional</div><div class="value"><?= report_h(legal_template_missing_value($values['professional_name'])) ?></div></td>
            <td><div class="label">Representante / tutor, si procede</div><div class="value"><?= report_h(legal_template_missing_value(trim((string) $values['guardian_name']) . (trim((string) $values['guardian_nif']) !== '' ? ' · NIF/NIE: ' . trim((string) $values['guardian_nif']) : ''))) ?></div></td>
        </tr>
    </table>
    <?= legal_template_paragraphs_html(legal_template_replace_variables($content['summary'], $values)) ?>
    <?php foreach ($content['sections'] as $section): ?>
        <h2><?= report_h(legal_template_replace_variables($section['title'], $values)) ?></h2>
        <?= legal_template_paragraphs_html(legal_template_replace_variables($section['content'], $values)) ?>
    <?php endforeach; ?>
    <?php if ($content['declaration'] !== ''): ?>
        <div class="declaration">
            <div class="declaration-title">Declaración de la persona firmante</div>
            <?= legal_template_paragraphs_html(legal_template_replace_variables($content['declaration'], $values)) ?>
        </div>
    <?php endif; ?>
    <div class="signature">
        <p>En <?= report_h(legal_template_missing_value($tenant['legal_city'] ?? '')) ?>, a <?= report_h($values['current_date']) ?>.</p>
        <div class="signature-line">Firma de la persona interesada o representante legal</div>
    </div>
    <div class="footer-note">Documento generado por <?= report_h($tenant_name) ?>. La persona firmante declara haber leído y comprendido su contenido.</div>
</body>
</html>
    <?php
    return ob_get_clean();
}

function legal_template_mpdf_instance()
{
    $temp_dir = sys_get_temp_dir();
    return new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 17,
        'margin_right' => 17,
        'margin_top' => 15,
        'margin_bottom' => 17,
        'tempDir' => is_dir($temp_dir) && is_writable($temp_dir) ? $temp_dir : __DIR__,
    ]);
}

function legal_template_generate_pdf_file($mysqli, array $document, $tenant_id, $patient_id, $destination, $preview = false)
{
    if (!function_exists('pdf_mpdf_available') || !pdf_mpdf_available()) {
        throw new RuntimeException('mPDF no está instalado.');
    }
    $tenant = legal_template_tenant_context($mysqli, $tenant_id);
    $patient = $patient_id > 0 ? legal_template_patient_context($mysqli, $tenant_id, $patient_id) : [];
    $html = legal_template_document_html($document, $tenant, $patient, $preview);
    $mpdf = legal_template_mpdf_instance();
    $mpdf->SetTitle((string) ($document['title'] ?? 'Consentimiento'));
    $mpdf->WriteHTML(pdf_prepare_html($html));
    $mpdf->Output($destination, \Mpdf\Output\Destination::FILE);
}

function legal_template_materialize_source($mysqli, array $document, $tenant_id, $patient_id)
{
    if (($document['template_type'] ?? 'uploaded_pdf') !== 'generated') {
        $path = function_exists('stored_upload_full_path')
            ? stored_upload_full_path($document['file_path'] ?? '')
            : app_protected_path_from_relative($document['file_path'] ?? '');
        return ['path' => $path, 'temporary' => false];
    }
    $path = tempnam(sys_get_temp_dir(), 'sgp_legal_');
    if (!$path) {
        throw new RuntimeException('No se pudo preparar el documento temporal.');
    }
    legal_template_generate_pdf_file($mysqli, $document, $tenant_id, $patient_id, $path, false);
    return ['path' => $path, 'temporary' => true];
}
