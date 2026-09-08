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

/** «1 vez», «14 veces». Escribir «1 veces» delata que lo hizo una máquina. */
function veces(int $n): string
{
    return $n === 1 ? '1 vez' : number_format($n, 0, ',', '.') . ' veces';
}

/* ====================================================================== */
/* Corrección de lo que la gente teclea                                    */
/* ====================================================================== */

/**
 * Palabras que en esta casa se escriben mal una y otra vez.
 *
 * Sale de mirar lo que hay escrito de verdad: el libro de auditoría, las
 * categorías que crearon a mano y los nombres de las cuentas. Casi todo son
 * acentos que se caen al escribir en mayúsculas —el teclado no los pone— y
 * cuatro erratas que se repiten.
 *
 * La clave va en MAYÚSCULAS y sin acentos, que es como se compara.
 */
function correcciones_nombre(): array
{
    return [
        // erratas vistas en el sistema
        'NACONAL' => 'Nacional',   'GREDITOS' => 'Créditos',
        'EXPACION' => 'Expansión', 'EXPANCION' => 'Expansión',
        'TARIFAPOR' => 'Tarifa por',
        // Nombres propios de la casa, que se escriben pegados y en mayúsculas
        'ARMORMARKET' => 'Armor Market', 'ARMORPETS' => 'Armor Pets',
        'AMKPETS' => 'AMK Pets',
        // acentos que se pierden al teclear en mayúsculas
        'CREDITO' => 'Crédito',       'CREDITOS' => 'Créditos',
        'COMISION' => 'Comisión',     'COMISIONES' => 'Comisiones',
        'NOMINA' => 'Nómina',         'MOVIL' => 'Móvil',
        'INTERVENCION' => 'Intervención', 'LOGISTICA' => 'Logística',
        'ELECTRICO' => 'Eléctrico',   'TELEFONICA' => 'Telefónica',
        'PERMISOLOGIA' => 'Permisología', 'ALMACEN' => 'Almacén',
        'DEVOLUCION' => 'Devolución', 'DEVOLUCIONES' => 'Devoluciones',
        'LIQUIDACION' => 'Liquidación', 'DOMICILIACION' => 'Domiciliación',
        'RECAUDACION' => 'Recaudación', 'EMISION' => 'Emisión',
        'GESTION' => 'Gestión',       'ADMINISTRACION' => 'Administración',
        'OPERACION' => 'Operación',   'TRANSACCION' => 'Transacción',
        'MANTENIMIENTO' => 'Mantenimiento',
        // abreviaturas que no dicen nada en una lista
        'CTA' => '', 'CTA.' => '', 'BCO' => 'Banco',
    ];
}

/** Siglas que se quedan en mayúsculas: no son palabras. */
function siglas_conocidas(): array
{
    return ['AMK','AMKB','AMKCH','AMKLG','BNC','BDV','POS','PDV','CASHEA','IVSS',
            'BANAVIH','SENIAT','RIF','CA','SA','SRL','USD','IVA','ISLR','UVCC','P2C'];
}

/**
 * Arregla un nombre escrito a mano y dice qué le cambió.
 *
 * Devuelve `['texto' => …, 'cambios' => ['NACONAL → Nacional', …]]`. Lo que
 * importa tanto como corregir es **decir qué se corrigió**: quien lo escribió
 * tiene que poder ver que el sistema le tocó lo que puso, y volver atrás si no
 * era eso. Corregir en silencio es como no preguntar.
 */
function normalizar_nombre(string $texto): array
{
    $original = $texto;
    $cambios = [];
    $t = trim(preg_replace('/\s+/u', ' ', $texto));
    if ($t !== trim($texto)) {
        $cambios[] = 'se quitaron espacios de más';
    }
    if ($t === '') {
        return ['texto' => '', 'cambios' => []];
    }

    // Las llaves en «{$p}» no son estilo: en PHP, los bytes del guillemet
    // cuentan como parte del nombre de la variable, así que "«$p»" busca una
    // variable que no existe y el aviso sale vacío.
    $dicc = correcciones_nombre();
    $siglas = siglas_conocidas();
    $menores = ['de','del','y','la','el','en','por','a','con','para','los','las'];
    // Solo se recapitaliza si venía TODO en mayúsculas: si la persona ya se
    // tomó el trabajo de escribirlo bien, no se le toca.
    $todoMayus = $t === mb_strtoupper($t, 'UTF-8') && preg_match('/\p{L}{3,}/u', $t);

    $salida = [];
    foreach (explode(' ', $t) as $i => $p) {
        $limpia = mb_strtoupper(norm($p), 'UTF-8');
        if (isset($dicc[$limpia])) {
            $nueva = $dicc[$limpia];
            if ($nueva === '') {
                $cambios[] = "se quitó «{$p}»";
                continue;
            }
            if (mb_strtolower($nueva, 'UTF-8') !== mb_strtolower($p, 'UTF-8')) {
                $cambios[] = "«{$p}» → «{$nueva}»";
            }
            $salida[] = $nueva;
            continue;
        }
        if (in_array($limpia, $siglas, true)) {
            $salida[] = mb_strtoupper($p, 'UTF-8');
            continue;
        }
        if ($todoMayus) {
            $salida[] = $i > 0 && in_array(mb_strtolower($p, 'UTF-8'), $menores, true)
                ? mb_strtolower($p, 'UTF-8')
                : mb_convert_case($p, MB_CASE_TITLE, 'UTF-8');
            continue;
        }
        $salida[] = $p;
    }
    $final = trim(preg_replace('/\s+/u', ' ', implode(' ', $salida)));
    if ($todoMayus && $final !== $t) {
        $cambios[] = 'se pasó de mayúsculas sostenidas a texto normal';
    }
    return ['texto' => $final !== '' ? $final : $original, 'cambios' => $cambios];
}

/** El aviso que se le enseña a quien escribió, si hubo algo que corregir. */
function aviso_correccion(array $r, string $antes): string
{
    if ($r['cambios'] === [] || $r['texto'] === $antes) {
        return '';
    }
    return ' Se guardó como «' . $r['texto'] . '»: ' . implode(', ', $r['cambios']) . '.';
}

/**
 * El número de la pastilla del menú.
 *
 * Estaba topado en «999+», y con un semestre cargado de golpe eso deja a la
 * gente sin saber si le faltan mil o veinte mil: justo el número que viene a
 * mirar. Se enseña entero mientras quepa, y en miles cuando ya no.
 */
function cuenta_pastilla(int $n): string
{
    if ($n < 10000) {
        return number_format($n, 0, ',', '.');
    }
    if ($n < 1000000) {
        return rtrim(rtrim(number_format($n / 1000, 1, ',', '.'), '0'), ',') . ' mil';
    }
    return rtrim(rtrim(number_format($n / 1000000, 1, ',', '.'), '0'), ',') . ' mill.';
}
