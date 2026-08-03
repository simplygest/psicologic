<?php

function pdf_autoload_path()
{
    return __DIR__ . '/vendor/autoload.php';
}

function pdf_mpdf_available()
{
    $autoload = pdf_autoload_path();
    if (!is_file($autoload)) {
        return false;
    }
    require_once $autoload;
    return class_exists('\\Mpdf\\Mpdf');
}

function pdf_safe_filename($name, $fallback = 'documento.pdf')
{
    $name = trim((string) $name);
    if ($name === '') {
        $name = $fallback;
    }
    $name = preg_replace('/[^\pL\pN._-]+/u', '-', $name);
    $name = trim($name, '-_.');
    if ($name === '') {
        $name = $fallback;
    }
    if (!preg_match('/\.pdf$/i', $name)) {
        $name .= '.pdf';
    }
    return $name;
}

function pdf_prepare_html($html)
{
    $html = (string) $html;
    $pdf_css = '<style>
        body { background: #fff !important; }
        main { max-width: none !important; padding: 0 !important; }
        .report-toolbar { display: none !important; }
        .report-sheet { border: 0 !important; border-radius: 0 !important; padding: 0 !important; }
        .grid { display: block !important; margin-top: 12px !important; }
        .grid .metric { display: inline-block !important; width: 23% !important; min-height: 48px !important; margin: 0 1.2% 10px 0 !important; padding: 10px !important; vertical-align: top !important; box-sizing: border-box !important; }
        .grid .metric span { display: block !important; margin: 0 0 5px 0 !important; line-height: 1.25 !important; }
        .grid .metric strong { display: block !important; margin: 0 !important; line-height: 1.15 !important; }
        .grid .field { display: inline-block !important; width: 47% !important; margin: 0 2% 10px 0 !important; vertical-align: top !important; box-sizing: border-box !important; }
        .grid .field span { display: block !important; margin-bottom: 3px !important; line-height: 1.25 !important; }
        .grid .field strong { display: block !important; line-height: 1.25 !important; }
        .note, .task, tr { page-break-inside: avoid; }
    </style>';

    if (stripos($html, '</head>') !== false) {
        return preg_replace('/<\/head>/i', $pdf_css . '</head>', $html, 1);
    }
    return $pdf_css . $html;
}

function pdf_output_html($html, $filename, array $options = [])
{
    $pdf = pdf_render_html($html, $options);

    $safe_filename = pdf_safe_filename($filename);
    header_remove('Content-Type');
    header_remove('Content-Disposition');
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header('Content-Disposition: ' . (!empty($options['download']) ? 'attachment' : 'inline') . '; filename="' . $safe_filename . '"');
    echo $pdf;
    exit;
}

function pdf_render_html($html, array $options = [])
{
    if (!pdf_mpdf_available()) {
        throw new RuntimeException('mPDF no esta instalado o no se encontro vendor/autoload.php.');
    }

    $temp_dir = sys_get_temp_dir();
    if (!is_dir($temp_dir) || !is_writable($temp_dir)) {
        $temp_dir = __DIR__;
    }

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => $options['format'] ?? 'A4',
        'margin_left' => $options['margin_left'] ?? 12,
        'margin_right' => $options['margin_right'] ?? 12,
        'margin_top' => $options['margin_top'] ?? 12,
        'margin_bottom' => $options['margin_bottom'] ?? 14,
        'tempDir' => $temp_dir,
    ]);

    if (!empty($options['title'])) {
        $mpdf->SetTitle((string) $options['title']);
    }
    if (!empty($options['author'])) {
        $mpdf->SetAuthor((string) $options['author']);
    }

    try {
        $mpdf->WriteHTML(pdf_prepare_html($html));
        $pdf = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    } catch (\Mpdf\MpdfException $e) {
        if (stripos($e->getMessage(), 'Mpdf\\QrCode package was not found') !== false) {
            throw new RuntimeException(
                'No se puede generar el código QR del PDF porque falta el paquete mpdf/qrcode. '
                . 'Ejecuta "composer require mpdf/qrcode" en la raíz de la aplicación.',
                0,
                $e
            );
        }
        throw $e;
    }
    if (!empty($options['signed'])) {
        if (isset($options['sign_callback']) && is_callable($options['sign_callback'])) {
            $pdf = (string) $options['sign_callback']($pdf);
        } else {
            require_once __DIR__ . '/stampbyme_helpers.php';
            $tenant_id = (int) ($options['tenant_id'] ?? (defined('CURRENT_TENANT_ID') ? CURRENT_TENANT_ID : 0));
            if ($tenant_id <= 0) {
                throw new RuntimeException('No se pudo identificar el tenant para firmar el PDF.');
            }
            $professional_id = (int) ($options['professional_id'] ?? 0);
            $certificate_owner = (string) ($options['certificate_owner'] ?? 'auto');
            $pdf = stampbyme_sign_pdf_contents(
                $tenant_id,
                $pdf,
                $professional_id > 0 ? $professional_id : null,
                $certificate_owner
            );
        }
        if (isset($options['on_signed']) && is_callable($options['on_signed'])) {
            $options['on_signed']($pdf);
        }
    }

    return $pdf;
}
