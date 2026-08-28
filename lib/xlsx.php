<?php
/**
 * Lector XLSX sin dependencias externas (ZipArchive + XMLReader).
 * Recorre la hoja en streaming, así un extracto de miles de filas no
 * consume memoria proporcional al archivo.
 */

class XlsxLector
{
    /** Tope del contenido descomprimido, como defensa ante un ZIP bomba. */
    private const MAX_DESCOMPRIMIDO = 400 * 1048576;

    private string $ruta;
    private array $shared = [];
    private string $hojaXml = '';

    public function __construct(string $ruta)
    {
        $this->ruta = $ruta;
    }

    /** @return Generator<int, array<int,string>> filas indexadas por columna (0-based) */
    public function filas(): Generator
    {
        $zip = new ZipArchive();
        if ($zip->open($this->ruta) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo XLSX.');
        }
        $this->verificarTamano($zip);
        $this->hojaXml = $this->rutaPrimeraHoja($zip);
        $this->cargarShared($zip);

        $xml = $zip->getFromName($this->hojaXml);
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException('El archivo no contiene una hoja de cálculo legible.');
        }

        $r = new XMLReader();
        $r->XML($xml, 'UTF-8', LIBXML_NONET);
        $fila = [];
        $col = -1;
        $tipo = '';
        $enV = false;
        $enT = false;
        $buffer = '';

        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT) {
                switch ($r->localName) {
                    case 'row':
                        $fila = [];
                        break;
                    case 'c':
                        $ref = $r->getAttribute('r');
                        $col = $ref !== null ? self::colIndice($ref) : $col + 1;
                        $tipo = (string) $r->getAttribute('t');
                        $buffer = '';
                        break;
                    case 'v':
                        $enV = true;
                        $buffer = '';
                        break;
                    case 't':
                        $enT = true;
                        $buffer = '';
                        break;
                }
                if ($r->isEmptyElement) {
                    $enV = $enT = false;
                }
            } elseif ($r->nodeType === XMLReader::TEXT || $r->nodeType === XMLReader::CDATA) {
                if ($enV || $enT) {
                    $buffer .= $r->value;
                }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT) {
                switch ($r->localName) {
                    case 'v':
                        $enV = false;
                        $valor = $tipo === 's'
                            ? ($this->shared[(int) $buffer] ?? '')
                            : $buffer;
                        if ($valor !== '' && $col >= 0) {
                            $fila[$col] = $valor;
                        }
                        break;
                    case 't':
                        $enT = false;
                        if ($tipo === 'inlineStr' && $buffer !== '' && $col >= 0) {
                            $fila[$col] = ($fila[$col] ?? '') . $buffer;
                        }
                        break;
                    case 'row':
                        if ($fila !== []) {
                            ksort($fila);
                            yield $fila;
                        }
                        $fila = [];
                        break;
                }
            }
        }
        $r->close();
    }

    /**
     * Un ZIP pequeño puede descomprimirse en gigabytes. Se rechaza antes de
     * leer nada si el contenido declarado supera el límite.
     */
    private function verificarTamano(ZipArchive $zip): void
    {
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $total += (int) ($st['size'] ?? 0);
            if ($total > self::MAX_DESCOMPRIMIDO) {
                $zip->close();
                throw new RuntimeException(
                    'El archivo descomprimido supera los ' . (self::MAX_DESCOMPRIMIDO / 1048576) . ' MB permitidos.'
                );
            }
        }
    }

    private function rutaPrimeraHoja(ZipArchive $zip): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $x = @simplexml_load_string($wb);
            $rx = @simplexml_load_string($rels);
            if ($x && $rx) {
                $ns = $x->getNamespaces(true);
                $hojas = $x->sheets->sheet ?? [];
                foreach ($hojas as $h) {
                    $rid = (string) ($h->attributes($ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id ?? '');
                    foreach ($rx->Relationship as $rel) {
                        if ((string) $rel['Id'] === $rid) {
                            $destino = ltrim((string) $rel['Target'], '/');
                            return str_starts_with($destino, 'xl/') ? $destino : 'xl/' . $destino;
                        }
                    }
                }
            }
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (str_starts_with((string) $n, 'xl/worksheets/') && str_ends_with((string) $n, '.xml')) {
                return (string) $n;
            }
        }
        throw new RuntimeException('El archivo no contiene hojas de cálculo.');
    }

    private function cargarShared(ZipArchive $zip): void
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return;
        }
        $r = new XMLReader();
        $r->XML($xml, 'UTF-8', LIBXML_NONET);
        $actual = null;
        $enT = false;
        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT) {
                if ($r->localName === 'si') {
                    $actual = '';
                } elseif ($r->localName === 't') {
                    $enT = true;
                }
            } elseif (($r->nodeType === XMLReader::TEXT || $r->nodeType === XMLReader::CDATA) && $enT && $actual !== null) {
                $actual .= $r->value;
            } elseif ($r->nodeType === XMLReader::END_ELEMENT) {
                if ($r->localName === 't') {
                    $enT = false;
                } elseif ($r->localName === 'si') {
                    $this->shared[] = (string) $actual;
                    $actual = null;
                }
            }
        }
        $r->close();
    }

    /** 'BC12' → 54 (índice 0-based de la columna) */
    public static function colIndice(string $ref): int
    {
        $n = 0;
        for ($i = 0, $len = strlen($ref); $i < $len; $i++) {
            $c = ord($ref[$i]);
            if ($c < 65 || $c > 90) {
                break;
            }
            $n = $n * 26 + ($c - 64);
        }
        return max(0, $n - 1);
    }
}

/** Lector CSV con detección de separador (, ; tab) y BOM. */
function csv_filas(string $ruta): Generator
{
    $fh = fopen($ruta, 'r');
    if (!$fh) {
        throw new RuntimeException('No se pudo abrir el archivo CSV.');
    }
    $primera = fgets($fh);
    if ($primera === false) {
        fclose($fh);
        return;
    }
    $primera = preg_replace('/^\xEF\xBB\xBF/', '', $primera);
    $sep = ',';
    $mejor = 0;
    foreach ([',', ';', "\t", '|'] as $cand) {
        $c = substr_count((string) $primera, $cand);
        if ($c > $mejor) {
            $mejor = $c;
            $sep = $cand;
        }
    }
    rewind($fh);
    $n = 0;
    while (($f = fgetcsv($fh, 0, $sep)) !== false) {
        if ($n === 0 && isset($f[0])) {
            $f[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $f[0]);
        }
        $n++;
        $fila = [];
        foreach ($f as $i => $v) {
            $v = trim((string) $v);
            if ($v !== '') {
                $fila[$i] = $v;
            }
        }
        if ($fila !== []) {
            yield $fila;
        }
    }
    fclose($fh);
}
