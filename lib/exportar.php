<?php
/** Exportación a CSV y XLSX, sin dependencias externas. */

/** Envía un CSV (UTF-8 con BOM, separador ';' para que Excel en español lo abra bien). */
function exportar_csv(string $nombre, array $encabezados, iterable $filas): void
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $encabezados, ';');
    foreach ($filas as $f) {
        fputcsv($out, $f, ';');
    }
    fclose($out);
    exit;
}

/**
 * Escribe un XLSX real. Los montos van como número (para poder sumar en Excel)
 * y el resto como texto en línea.
 */
function exportar_xlsx(string $nombre, array $encabezados, iterable $filas, array $numericas = []): void
{
    $tmp = tempnam(sys_get_temp_dir(), 'xls');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Movimientos" sheetId="1" r:id="rId1"/></sheets></workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');

    // Estilo 1: encabezado en negrita. Estilo 2: número con 2 decimales y miles.
    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '</cellXfs></styleSheet>');

    $hoja = fopen('php://temp/maxmemory:8388608', 'w+');
    fwrite($hoja, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');

    $escribirFila = function (array $valores, int $nfila, bool $cabecera) use ($hoja, $numericas): void {
        fwrite($hoja, '<row r="' . $nfila . '">');
        foreach ($valores as $c => $v) {
            $ref = col_letra($c) . $nfila;
            $esNum = !$cabecera && in_array($c, $numericas, true) && is_numeric($v);
            if ($esNum) {
                fwrite($hoja, '<c r="' . $ref . '" s="2"><v>' . (0 + $v) . '</v></c>');
            } else {
                $txt = htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $txt = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $txt);
                fwrite($hoja, '<c r="' . $ref . '" t="inlineStr"' . ($cabecera ? ' s="1"' : '')
                    . '><is><t xml:space="preserve">' . $txt . '</t></is></c>');
            }
        }
        fwrite($hoja, '</row>');
    };

    $escribirFila($encabezados, 1, true);
    $n = 2;
    foreach ($filas as $f) {
        $escribirFila(array_values($f), $n++, false);
    }
    fwrite($hoja, '</sheetData></worksheet>');
    rewind($hoja);
    $zip->addFromString('xl/worksheets/sheet1.xml', stream_get_contents($hoja));
    fclose($hoja);
    $zip->close();

    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombre . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    unlink($tmp);
    exit;
}

/** 0 → A, 26 → AA */
function col_letra(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $r = ($i - 1) % 26;
        $s = chr(65 + $r) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}
