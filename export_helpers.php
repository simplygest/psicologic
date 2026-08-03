<?php

function export_safe_filename($name, $extension)
{
    $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', trim((string) $name));
    $name = trim((string) $name, '-');
    return ($name !== '' ? $name : 'exportacion') . '.' . ltrim((string) $extension, '.');
}

function export_output_json($filename, $payload)
{
    header_remove('Content-Type');
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . export_safe_filename($filename, 'json') . '"');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function export_xml_escape($value)
{
    $value = preg_replace('/[^\P{C}\t\r\n]/u', '', (string) ($value ?? ''));
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function export_xlsx_column_name($index)
{
    $name = '';
    $index = (int) $index + 1;
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function export_zip_archive(array $files)
{
    $local_data = '';
    $central_data = '';
    $offset = 0;
    $timestamp = getdate();
    $dos_time = (($timestamp['hours'] & 0x1f) << 11) | (($timestamp['minutes'] & 0x3f) << 5) | ((int) ($timestamp['seconds'] / 2) & 0x1f);
    $dos_date = (((max(1980, $timestamp['year']) - 1980) & 0x7f) << 9) | (($timestamp['mon'] & 0x0f) << 5) | ($timestamp['mday'] & 0x1f);

    foreach ($files as $name => $content) {
        $name = str_replace('\\', '/', (string) $name);
        $content = (string) $content;
        $crc = crc32($content);
        $size = strlen($content);
        $name_length = strlen($name);
        $local_header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dos_time, $dos_date, $crc, $size, $size, $name_length, 0);
        $local_data .= $local_header . $name . $content;
        $central_data .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dos_time, $dos_date, $crc, $size, $size, $name_length, 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($local_header) + $name_length + $size;
    }

    $count = count($files);
    return $local_data
        . $central_data
        . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central_data), strlen($local_data), 0);
}

function export_output_xlsx($filename, array $headers, array $rows, $sheet_name = 'Datos')
{
    $sheet_name = mb_substr(preg_replace('/[\\\\\\/?*\\[\\]:]/u', ' ', (string) $sheet_name), 0, 31);
    $sheet_name = $sheet_name !== '' ? $sheet_name : 'Datos';
    $all_rows = [array_values($headers)];
    foreach ($rows as $row) {
        $all_rows[] = array_values($row);
    }

    $sheet_rows = '';
    foreach ($all_rows as $row_index => $row) {
        $cells = '';
        foreach ($row as $column_index => $value) {
            $reference = export_xlsx_column_name($column_index) . ($row_index + 1);
            $style = $row_index === 0 ? ' s="1"' : '';
            $cells .= '<c r="' . $reference . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">'
                . export_xml_escape($value) . '</t></is></c>';
        }
        $sheet_rows .= '<row r="' . ($row_index + 1) . '">' . $cells . '</row>';
    }

    $last_column = export_xlsx_column_name(max(0, count($headers) - 1));
    $auto_filter = count($headers) > 0 ? '<autoFilter ref="A1:' . $last_column . '1"/>' : '';
    $sheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetData>' . $sheet_rows . '</sheetData>' . $auto_filter . '</worksheet>';

    $files = [];
    $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
    $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . export_xml_escape($sheet_name) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
    $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF6F5AA8"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
        . '</styleSheet>';
    $files['xl/worksheets/sheet1.xml'] = $sheet_xml;
    $xlsx = export_zip_archive($files);

    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . export_safe_filename($filename, 'xlsx') . '"');
    header('Content-Length: ' . strlen($xlsx));
    header('X-Content-Type-Options: nosniff');
    echo $xlsx;
    exit;
}
