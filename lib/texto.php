<?php
/** Normalización de texto, fechas y montos provenientes de los bancos. */

/** Repara texto que llega en Latin-1/CP1252 dentro de un archivo declarado UTF-8. */
function fix_utf8(string $s): string
{
    if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
        return $s;
    }
    return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

/**
 * Clave de comparación: mayúsculas, sin acentos, sin puntuación.
 * Los caracteres corruptos (U+FFFD) quedan como espacio, por eso las reglas
 * sobre palabras acentuadas se escriben como expresión regular con comodín.
 */
function norm(string $s): string
{
    $s = mb_strtoupper(trim(fix_utf8($s)), 'UTF-8');
    $s = strtr($s, [
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ñ' => 'N', 'Ç' => 'C',
    ]);
    $s = preg_replace('/[^A-Z0-9]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', (string) $s));
}

/** Limpia el texto para mostrarlo: colapsa espacios, recorta. */
function limpiar(string $s): string
{
    $s = fix_utf8($s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim((string) $s);
}

/** Serial de Excel (base 1899-12-30) o texto de fecha → 'YYYY-MM-DD'. */
function a_fecha(string $v): ?string
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (preg_match('/^\d+(\.\d+)?$/', $v)) {
        $serial = (int) floor((float) $v);
        if ($serial < 20000 || $serial > 80000) {   // fuera de 1954–2119
            return null;
        }
        $ts = ($serial - 25569) * 86400;
        return gmdate('Y-m-d', $ts);
    }
    if (preg_match('~^(\d{4})[-/](\d{1,2})[-/](\d{1,2})~', $v, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    if (preg_match('~^(\d{1,2})[-/](\d{1,2})[-/](\d{2,4})~', $v, $m)) {
        $y = (int) $m[3];
        if ($y < 100) {
            $y += 2000;
        }
        return sprintf('%04d-%02d-%02d', $y, (int) $m[2], (int) $m[1]); // dd/mm/yyyy
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** Texto de monto (1.234,56 · 1,234.56 · 1234.56 · notación científica) → float. */
function a_monto(string $v): float
{
    $v = trim(fix_utf8($v));
    if ($v === '') {
        return 0.0;
    }
    $neg = str_contains($v, '(') || str_starts_with($v, '-');
    $v = preg_replace('/[^0-9,.eE+\-]/', '', $v);
    if (preg_match('/^-?\d+(\.\d+)?[eE][+\-]?\d+$/', (string) $v)) {
        $n = (float) $v;
        return $neg && $n > 0 ? -$n : $n;
    }
    $v = str_replace(['e', 'E', '+'], '', (string) $v);
    $coma = strrpos($v, ',');
    $punto = strrpos($v, '.');
    if ($coma !== false && $punto !== false) {
        // el separador decimal es el que aparece más a la derecha
        $v = $coma > $punto
            ? str_replace(['.', ','], ['', '.'], $v)
            : str_replace(',', '', $v);
    } elseif ($coma !== false) {
        // una sola coma: decimal si deja 1-2 dígitos a la derecha
        $v = (strlen($v) - $coma - 1) <= 2 ? str_replace(',', '.', $v) : str_replace(',', '', $v);
    }
    $n = (float) preg_replace('/[^0-9.\-]/', '', $v);
    if ($neg && $n > 0) {
        $n = -$n;
    }
    return round($n, 2);
}

/** Formato de moneda para la interfaz. */
function bs(float $n, int $dec = 2): string
{
    return number_format($n, $dec, ',', '.');
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** '2026-08' → 'agosto 2026' (sin depender del locale del servidor). */
function strftime_es(string $ym): string
{
    static $meses = ['01' => 'enero', '02' => 'febrero', '03' => 'marzo', '04' => 'abril',
                     '05' => 'mayo', '06' => 'junio', '07' => 'julio', '08' => 'agosto',
                     '09' => 'septiembre', '10' => 'octubre', '11' => 'noviembre', '12' => 'diciembre'];
    [$a, $m] = array_pad(explode('-', $ym), 2, '01');
    return ($meses[$m] ?? $m) . ' ' . $a;
}
