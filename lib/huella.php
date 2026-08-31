<?php
/**
 * Reconocimiento de formatos por la estructura del archivo.
 *
 * El nombre del archivo y el de la hoja no se miran: contabilidad los renombra
 * y no son prueba de nada. Todo lo que se usa aquí sale del contenido.
 *
 * La huella combina qué rótulos trae el encabezado y en qué columna cae cada
 * uno, en qué fila está ese encabezado, cuántas columnas de datos hay y de qué
 * forma es cada columna. Sobre las 16 muestras reales dio 14 huellas distintas,
 * y las dos repetidas eran de verdad el mismo formato.
 */

/**
 * Los primeros cuatro dígitos de una cuenta venezolana identifican al banco.
 * Cuando el archivo trae el número de cuenta, esto es prueba concluyente.
 */
const CODIGOS_BANCO = [
    '0102' => 'Banco de Venezuela', '0104' => 'Venezolano de Crédito',
    '0105' => 'Mercantil',          '0108' => 'Provincial',
    '0114' => 'Bancaribe',          '0115' => 'Exterior',
    '0128' => 'Banco Caroní',       '0134' => 'Banesco',
    '0137' => 'Sofitasa',           '0138' => 'Banco Plaza',
    '0146' => 'Bangente',           '0151' => 'BFC',
    '0156' => '100% Banco',         '0157' => 'Del Sur',
    '0163' => 'Banco del Tesoro',   '0166' => 'Banco Agrícola',
    '0168' => 'Bancrecer',          '0169' => 'Mi Banco',
    '0171' => 'Banco Activo',       '0172' => 'Bancamiga',
    '0174' => 'Banplus',            '0175' => 'Bicentenario',
    '0177' => 'Banfanb',            '0191' => 'BNC',
];

/** Rótulos que cuentan como encabezado al buscar la fila de cabecera. */
const CAB_EXTRA = ['NRO', 'NRO CUENTA', 'CODIGO', 'COD TRANSACC', 'TIPO TRANSACCION',
                   'TIPO OPERACION', 'MOTIVO', 'BALANCE', 'TIPOMOVIMIENTO', 'TITULAR',
                   'COD MOTIVO', 'CODIGO TRANSACCION'];

/** Todo el vocabulario de encabezados conocido. */
function vocabulario(): array
{
    static $v = null;
    return $v ??= array_merge(CAB_FECHA, CAB_REF, CAB_TEXTO, CAB_DEBITO,
                              CAB_CREDITO, CAB_MONTO, CAB_SALDO, CAB_EXTRA);
}

/**
 * Calcula la huella estructural a partir de las primeras filas.
 * Devuelve también la fila de encabezado, que es donde empiezan los datos.
 */
function huella(array $filas): array
{
    $vocab = vocabulario();

    // El encabezado es la fila con más rótulos reconocidos, dentro de las 16
    // primeras. Ninguno de los bancos vistos lo pone más abajo.
    $filaCab = -1;
    $mejor = 0;
    foreach ($filas as $i => $f) {
        if ($i > 15) {
            break;
        }
        $n = 0;
        foreach ($f as $v) {
            if (in_array(norm((string) $v), $vocab, true)) {
                $n++;
            }
        }
        if ($n > $mejor) {
            $mejor = $n;
            $filaCab = $i;
        }
    }
    // Con un solo rótulo suelto no hay encabezado: el archivo es posicional.
    if ($mejor < 2) {
        $filaCab = -1;
    }

    $rotulos = [];
    if ($filaCab >= 0) {
        foreach ($filas[$filaCab] as $idx => $v) {
            $n = norm((string) $v);
            if ($n !== '') {
                $rotulos[] = "$idx:$n";
            }
        }
    }

    $ancho = 0;
    for ($i = $filaCab + 1; $i < min(count($filas), $filaCab + 13); $i++) {
        if ($filas[$i]) {
            $ancho = max($ancho, max(array_keys($filas[$i])) + 1);
        }
    }

    $forma = forma_columnas($filas, $filaCab + 1, $ancho);

    return [
        'fila_cab' => $filaCab,
        'rotulos'  => $rotulos,
        'ancho'    => $ancho,
        'forma'    => $forma,
        'clave'    => md5(implode('|', $rotulos) . '#' . $forma),
    ];
}

/**
 * Resume de qué tipo es cada columna mirando los datos, no los rótulos:
 * F fecha · N número · S signo (+/-) · T texto · V vacía.
 * Es lo que separa formatos que comparten encabezado y lo que permite
 * reconocer los archivos que no traen encabezado.
 */
function forma_columnas(array $filas, int $desde, int $ancho): string
{
    $forma = '';
    for ($c = 0; $c < $ancho; $c++) {
        $vals = [];
        for ($i = $desde; $i < min(count($filas), $desde + 12); $i++) {
            $v = trim((string) ($filas[$i][$c] ?? ''));
            if ($v !== '') {
                $vals[] = $v;
            }
        }
        if ($vals === []) {
            $forma .= 'V';
            continue;
        }
        $fechas = 0;
        $numeros = 0;
        $signos = 0;
        foreach ($vals as $v) {
            if ($v === '+' || $v === '-') {
                $signos++;
            } elseif (preg_match('~^\d{1,2}[-/]\d{1,2}[-/]\d{2,4}~', $v)) {
                $fechas++;
            } elseif (preg_match('/^-?[\d.,]+$/', $v)) {
                $numeros++;
                // Un entero de 5 cifras en el rango 40000-60000 es una fecha
                // serial de Excel, que es como la mandan casi todos.
                if (!str_contains($v, ',') && strlen($v) <= 5
                    && (float) $v >= 40000 && (float) $v <= 60000) {
                    $fechas++;
                }
            }
        }
        $t = count($vals);
        $forma .= $signos === $t ? 'S'
                : ($fechas >= $t * 0.8 ? 'F'
                : ($numeros >= $t * 0.8 ? 'N' : 'T'));
    }
    return $forma;
}

/**
 * Busca el número de cuenta dentro del archivo y deduce el banco de sus
 * primeros cuatro dígitos. Es la evidencia más fuerte que hay, porque el dato
 * viene del banco y no de cómo alguien haya llamado al archivo.
 */
function cuenta_declarada(array $filas, int $filaCab): array
{
    // Primero, la cabecera que imprime el banco: ahí el número es un dato del
    // documento, no de una transacción.
    $cabeceraFin = $filaCab >= 0 ? $filaCab : min(count($filas), 3);
    for ($i = 0; $i < $cabeceraFin; $i++) {
        $r = numero_en_fila($filas[$i]);
        if ($r !== null) {
            return $r;
        }
    }

    // Si no, una columna que repita el mismo número en todas las filas: eso es
    // la cuenta del extracto (Venezuela la trae así). Una referencia cambia en
    // cada fila, así que nunca pasa este filtro.
    $desde = $filaCab + 1;
    $porColumna = [];
    $n = 0;
    for ($i = $desde; $i < min(count($filas), $desde + 12); $i++) {
        $n++;
        foreach ($filas[$i] as $c => $v) {
            $v = trim((string) $v);
            if (preg_match('/^\d{20}$/', $v)) {
                $porColumna[$c][$v] = ($porColumna[$c][$v] ?? 0) + 1;
            }
        }
    }
    foreach ($porColumna as $valores) {
        arsort($valores);
        $numero = (string) array_key_first($valores);
        // El mismo número en todas las filas de la muestra, sin una sola
        // excepción. Con un criterio más flojo se colaría la cuenta de la
        // contraparte: en el extracto del BNC hay cinco filas seguidas con la
        // cuenta del Tesoro, que es a quien se le transfirió.
        if ($n >= 5 && $valores[$numero] === $n) {
            return ['numero' => $numero, 'codigo' => substr($numero, 0, 4)];
        }
    }
    return ['numero' => '', 'codigo' => ''];
}

/** Busca en una fila un número de cuenta, entero o enmascarado. */
function numero_en_fila(array $fila): ?array
{
    $txt = implode(' ', array_map('strval', $fila));
    if (preg_match('/\b(\d{20})\b/', $txt, $m)) {
        return ['numero' => $m[1], 'codigo' => substr($m[1], 0, 4)];
    }
    if (preg_match('/(\d{4})\*{4,}(\d{3,4})/', $txt, $m)) {
        return ['numero' => $m[1] . str_repeat('*', 12) . $m[2], 'codigo' => $m[1]];
    }
    if (preg_match('/CUENTA\D{0,12}(\d{4})[\d\s-]{10,}/i', $txt, $m)) {
        return ['numero' => trim($m[0]), 'codigo' => $m[1]];
    }
    return null;
}

/** Nombre del banco a partir del código de cuenta, si se reconoce. */
function banco_por_codigo(string $codigo): string
{
    return CODIGOS_BANCO[$codigo] ?? '';
}

/**
 * Comprueba que el saldo de cada fila sea el de la anterior menos el débito
 * más el crédito. Si encadena, el mapeo de columnas es correcto con certeza.
 *
 * No sirve al revés: Banplus no entrega las filas en orden de saldo y
 * Provincial las entrega al revés, así que un resultado bajo no prueba nada.
 * Por eso el importador solo lo usa como confirmación, nunca para rechazar.
 */
function cadena_saldo(array $filas, int $desde, array $mapa): array
{
    if ($mapa['saldo'] === null) {
        return ['aplica' => false, 'ok' => 0, 'total' => 0];
    }
    $filas = array_slice($filas, $desde);
    // Provincial entrega el extracto del último día al primero, así que se
    // prueba en los dos sentidos y se toma el que encadene.
    $mejor = ['aplica' => false, 'ok' => 0, 'total' => 0];
    foreach ([$filas, array_reverse($filas)] as $orden) {
        $r = encadena($orden, $mapa);
        // Se queda con el mejor de los dos sentidos, pero conserva 'aplica'
        // aunque no encadene ninguna: que la comprobación falle es justamente
        // lo que hay que contar, no algo que deba pasar en silencio.
        if ($r['ok'] > $mejor['ok'] || (!$mejor['aplica'] && $r['aplica'])) {
            $mejor = $r;
        }
    }
    return $mejor;
}

/** Recorre las filas en el orden dado comprobando saldo anterior + movimiento. */
function encadena(array $filas, array $mapa): array
{
    $prev = null;
    $ok = 0;
    $total = 0;
    foreach ($filas as $f) {
        $s = celda($f, $mapa['saldo']);
        if ($s === '' || a_fecha(celda($f, $mapa['fecha'])) === null) {
            continue;
        }
        $saldo = a_monto($s);
        if ($mapa['signo'] !== null && $mapa['monto'] !== null) {
            // Importe siempre positivo con el signo en otra columna (Exterior).
            $mov = abs(a_monto(celda($f, $mapa['monto'])));
            if (celda($f, $mapa['signo']) === '-') {
                $mov = -$mov;
            }
        } elseif ($mapa['monto'] !== null && $mapa['debito'] === null) {
            $mov = a_monto(celda($f, $mapa['monto']));
        } else {
            $mov = abs(a_monto(celda($f, $mapa['credito']))) - abs(a_monto(celda($f, $mapa['debito'])));
        }
        if ($prev !== null) {
            $total++;
            if (abs(($prev + $mov) - $saldo) < 0.02) {
                $ok++;
            }
        }
        $prev = $saldo;
        if ($total >= 120) {
            break;
        }
    }
    return ['aplica' => $total > 0, 'ok' => $ok, 'total' => $total];
}

/**
 * Lee los totales que el propio archivo declara en su pie ("Total Débitos",
 * "Totales", "Debito Total"). Bancamiga, BNC y Bicentenario los traen, y
 * cuadran al céntimo, así que sirven para verificar la importación completa.
 */
function totales_declarados(array $pie, array $mapa): array
{
    $r = ['debito' => null, 'credito' => null, 'n_debito' => null, 'n_credito' => null];
    foreach ($pie as $f) {
        $txt = norm(implode(' ', array_map('strval', $f)));
        if (!str_contains($txt, 'TOTAL')) {
            continue;
        }
        // El rótulo puede venir en una celda y el número en otra, o los dos
        // juntos ("Debito Total: 29829071,84"). Se cubren ambos casos.
        foreach ($f as $idx => $v) {
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            $n = norm($v);
            // El rótulo tiene que hablar de totales. Sin esto, el pie de un
            // extracto que dijera «BANCO NACIONAL DE CREDITO» se leería como
            // un total de créditos y tumbaría una importación correcta.
            if (!str_contains($n, 'TOTAL')) {
                continue;
            }
            $esDeb = str_contains($n, 'DEBITO') || str_contains($n, 'DEBE');
            $esCre = str_contains($n, 'CREDITO') || str_contains($n, 'HABER');
            if ($esDeb || $esCre) {
                if (preg_match('/\((\d+)\)/', $v, $m)) {
                    $r[$esDeb ? 'n_debito' : 'n_credito'] = (int) $m[1];
                }
                // ¿el número va pegado al rótulo?
                if (preg_match('/[:\s]([\d.,]+)\s*$/', $v, $m) && a_monto($m[1]) > 0) {
                    $r[$esDeb ? 'debito' : 'credito'] = a_monto($m[1]);
                    continue;
                }
                // si no, en la columna del propio débito o crédito
                $col = $esDeb ? $mapa['debito'] : $mapa['credito'];
                $val = celda($f, $col);
                if ($val !== '') {
                    $r[$esDeb ? 'debito' : 'credito'] = abs(a_monto($val));
                }
            }
        }
        // Fila "Totales" a secas: los importes están en sus propias columnas.
        if ($r['debito'] === null && $r['credito'] === null && str_starts_with(ltrim($txt), 'TOTALES')) {
            $d = celda($f, $mapa['debito']);
            $c = celda($f, $mapa['credito']);
            if ($d !== '') {
                $r['debito'] = abs(a_monto($d));
            }
            if ($c !== '') {
                $r['credito'] = abs(a_monto($c));
            }
        }
    }
    return $r;
}

/**
 * Saldo con el que arranca el extracto, cuando el banco lo pone en la cabecera
 * (Bancamiga y Bicentenario lo hacen). Ahorra tenerlo que cargar a mano.
 */
function saldo_arranque(array $previas): ?float
{
    foreach ($previas as $f) {
        foreach ($f as $idx => $v) {
            $v = trim((string) $v);
            if ($v === '' || !str_contains(norm($v), 'SALDO INICIAL')) {
                continue;
            }
            if (preg_match('/[:\s]([\d.,]+)\s*$/', $v, $m)) {
                return a_monto($m[1]);
            }
            // el rótulo en una celda y el número en otra de la misma fila
            for ($c = $idx + 1; $c <= max(array_keys($f)); $c++) {
                $x = trim((string) ($f[$c] ?? ''));
                if ($x !== '') {
                    return a_monto($x);
                }
            }
        }
    }
    return null;
}

/* ------------------------------------------------------------------ */
/* Catálogo de formatos: vive en la base y aprende de lo que se carga. */
/* ------------------------------------------------------------------ */

/**
 * Devuelve el formato guardado para esa huella, o null si es desconocido.
 * El catálogo es una ayuda, no un requisito: si la consulta falla, el archivo
 * se sigue deduciendo por su estructura y la importación no se interrumpe.
 */
function formato_por_clave(string $clave): ?array
{
    try {
        $s = db()->prepare('SELECT * FROM formatos WHERE clave = ?');
        $s->execute([$clave]);
        $f = $s->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if ($f === false) {
        return null;
    }
    $f['mapa'] = json_decode((string) $f['mapa'], true) ?: null;
    return $f;
}

/**
 * Guarda o refresca un formato. Se llama al confirmar una importación, así que
 * el catálogo se llena solo: el segundo mes ese banco ya se reconoce.
 */
function recordar_formato(string $clave, string $banco, array $h, ?array $mapa): void
{
    try {
        db()->prepare('INSERT INTO formatos (clave, banco, fila_cab, mapa, rotulos, forma, ancho, veces, visto_en)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
                       ON DUPLICATE KEY UPDATE
                         veces = veces + 1, visto_en = NOW(),
                         banco = IF(banco = "", VALUES(banco), banco)')
           ->execute([
               $clave, $banco, $h['fila_cab'], json_encode($mapa),
               mb_substr(implode(' ', $h['rotulos']), 0, 500), $h['forma'], $h['ancho'],
           ]);
    } catch (Throwable $e) {
        // Aprender es opcional: si no se pudo guardar, la carga ya está hecha.
    }
}
