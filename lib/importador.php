<?php
/**
 * Detección de formato e importación de extractos.
 *
 * Los bancos entregan el mismo dato con encabezados distintos, así que las
 * columnas se localizan por nombre y no por posición:
 *   Bancamiga  → Fecha·Referencia·Concepto·DESCRIP·Débito·Crédito·Saldo (sin título)
 *   Tesoro     → título 'TESORO'  + Fecha·Referencia·Concepto·DESCRIPCION·Débito·Crédito
 *   Venezuela  → título de cuenta + Fecha·Referencia·Descripción·CONCEPTO·Débito·Crédito
 *   Banesco    → título 'BANESCO' + Fecha·Referencia·Descripción·(nota)·Monto con signo
 */

const CAB_FECHA  = ['FECHA', 'FECHA OPERACION', 'FECHA VALOR', 'FECHA MOVIMIENTO'];
const CAB_REF    = ['REFERENCIA', 'REF', 'NRO REFERENCIA', 'DOCUMENTO', 'NUMERO', 'COMPROBANTE'];
const CAB_TEXTO  = ['CONCEPTO', 'DESCRIPCION', 'DESCRIP', 'DETALLE', 'MOVIMIENTO', 'TRANSACCION', 'OBSERVACION'];
const CAB_DEBITO = ['DEBITO', 'DEBE', 'CARGO', 'CARGOS', 'RETIRO', 'DEBITOS'];
const CAB_CREDITO= ['CREDITO', 'HABER', 'ABONO', 'ABONOS', 'DEPOSITO', 'CREDITOS'];
const CAB_MONTO  = ['MONTO', 'IMPORTE', 'VALOR'];
const CAB_SALDO  = ['SALDO', 'BALANCE', 'SALDO ACTUAL'];

/** Lee las filas del archivo según su extensión. */
function leer_filas(string $ruta, string $ext): Generator
{
    return $ext === 'csv' ? csv_filas($ruta) : (new XlsxLector($ruta))->filas();
}

/**
 * Analiza el archivo: localiza el encabezado, mapea columnas y propone la cuenta.
 * Devuelve null en 'mapa' si no logra reconocer el formato.
 */
function analizar(string $ruta, string $ext): array
{
    $previas = [];      // filas antes del encabezado (títulos)
    $cabecera = null;
    $mapa = null;
    $muestra = [];
    $filaCab = -1;
    $i = 0;

    foreach (leer_filas($ruta, $ext) as $fila) {
        if ($cabecera === null) {
            if ($i > 15) {
                break;                       // no hay encabezado reconocible
            }
            if (es_cabecera($fila)) {
                $cabecera = $fila;
                $filaCab = $i;
                $mapa = mapear_columnas($fila);
            } else {
                $t = array_values(array_filter(array_map('limpiar', $fila), fn($v) => $v !== ''));
                if ($t !== []) {
                    $previas[] = implode(' ', $t);
                }
            }
            $i++;
            continue;
        }
        if (count($muestra) < 6) {
            $muestra[] = $fila;
        } else {
            break;
        }
        $i++;
    }

    $titulo = $previas[0] ?? '';
    $banco = detectar_banco($titulo, $mapa, $cabecera);

    return [
        'cabecera'  => $cabecera,
        'fila_cab'  => $filaCab,
        'mapa'      => $mapa,
        'titulo'    => $titulo,
        'banco'     => $banco,
        'cuenta'    => $titulo !== '' ? limpiar($titulo) : $banco,
        'muestra'   => $muestra,
    ];
}

function es_cabecera(array $fila): bool
{
    $hayFecha = false;
    $hayMonto = false;
    foreach ($fila as $v) {
        $n = norm((string) $v);
        if ($n === '') {
            continue;
        }
        if (in_array($n, CAB_FECHA, true)) {
            $hayFecha = true;
        }
        if (in_array($n, CAB_DEBITO, true) || in_array($n, CAB_CREDITO, true) || in_array($n, CAB_MONTO, true)) {
            $hayMonto = true;
        }
    }
    return $hayFecha && $hayMonto;
}

/** Asocia cada rol de columna con su índice en el archivo. */
function mapear_columnas(array $cab): ?array
{
    $m = ['fecha' => null, 'referencia' => null, 'concepto' => null, 'nota' => null,
          'debito' => null, 'credito' => null, 'monto' => null, 'saldo' => null];
    $textos = [];

    foreach ($cab as $idx => $v) {
        $n = norm((string) $v);
        if ($n === '') {
            continue;
        }
        if ($m['fecha'] === null && in_array($n, CAB_FECHA, true))          { $m['fecha'] = $idx; continue; }
        if ($m['referencia'] === null && in_array($n, CAB_REF, true))       { $m['referencia'] = $idx; continue; }
        if ($m['debito'] === null && in_array($n, CAB_DEBITO, true))        { $m['debito'] = $idx; continue; }
        if ($m['credito'] === null && in_array($n, CAB_CREDITO, true))      { $m['credito'] = $idx; continue; }
        if ($m['saldo'] === null && in_array($n, CAB_SALDO, true))          { $m['saldo'] = $idx; continue; }
        if ($m['monto'] === null && in_array($n, CAB_MONTO, true))          { $m['monto'] = $idx; continue; }
        if (in_array($n, CAB_TEXTO, true))                                  { $textos[] = $idx; }
    }

    if ($m['fecha'] === null) {
        return null;
    }
    if ($m['debito'] === null && $m['credito'] === null && $m['monto'] === null) {
        return null;
    }

    // El primer campo de texto es la descripción del banco; el segundo, la nota manual.
    $m['concepto'] = $textos[0] ?? null;
    $m['nota']     = $textos[1] ?? null;

    // Banesco deja sin encabezado la columna de notas: se toma la primera
    // columna libre situada entre la descripción y el monto.
    if ($m['nota'] === null && $m['concepto'] !== null) {
        $ocupadas = array_filter($m, fn($v) => $v !== null);
        $limite = $m['monto'] ?? $m['debito'] ?? $m['credito'];
        for ($c = $m['concepto'] + 1; $c < $limite; $c++) {
            if (!in_array($c, $ocupadas, true)) {
                $m['nota'] = $c;
                break;
            }
        }
    }
    return $m;
}

/** Identifica el banco por el título del archivo o por la huella del encabezado. */
function detectar_banco(string $titulo, ?array $mapa, ?array $cab): string
{
    $t = norm($titulo);
    $conocidos = [
        'BANESCO'    => 'Banesco',
        'VENEZUELA'  => 'Banco de Venezuela',
        'BDV'        => 'Banco de Venezuela',
        'TESORO'     => 'Banco del Tesoro',
        'BANCAMIGA'  => 'Bancamiga',
        'MERCANTIL'  => 'Mercantil',
        'PROVINCIAL' => 'Provincial',
        'BNC'        => 'BNC',
        'PLAZA'      => 'Bancaribe',
        'EXTERIOR'   => 'Exterior',
        'BICENTENARIO' => 'Bicentenario',
    ];
    foreach ($conocidos as $clave => $nombre) {
        if ($t !== '' && str_contains($t, $clave)) {
            return $nombre;
        }
    }
    // Sin título: el extracto de Bancamiga es el único que trae DESCRIP + Saldo.
    if ($titulo === '' && $cab !== null) {
        $ns = array_map(fn($v) => norm((string) $v), $cab);
        if (in_array('DESCRIP', $ns, true) && in_array('SALDO', $ns, true)) {
            return 'Bancamiga';
        }
    }
    return '';
}

/** Toma el valor de una columna de la fila, si el mapa la definió. */
function celda(array $fila, ?int $idx): string
{
    return $idx === null ? '' : trim((string) ($fila[$idx] ?? ''));
}

/**
 * Importa el archivo a la cuenta indicada.
 * Deduplica por firma+ocurrencia, así una carga repetida no genera copias
 * y un extracto acumulativo solo agrega las filas nuevas.
 */
function importar(string $ruta, string $ext, int $cuentaId, string $archivoNombre): array
{
    $info = analizar($ruta, $ext);
    if ($info['mapa'] === null) {
        throw new RuntimeException('No se reconoció el formato: falta la columna de fecha o la de montos.');
    }
    $m = $info['mapa'];
    $pdo = db();

    $pdo->prepare('INSERT INTO importaciones (archivo, cuenta_id, formato) VALUES (?, ?, ?)')
        ->execute([$archivoNombre, $cuentaId, $info['banco'] ?: 'genérico']);
    $impId = (int) $pdo->lastInsertId();

    $reglas = cargar_reglas($cuentaId);

    // Las filas se insertan por lotes: una sola sentencia cada $lote registros
    // en lugar de un round-trip por fila.
    $lote = 400;
    $cols = 18;
    $sqlBase = 'INSERT IGNORE INTO movimientos
            (cuenta_id, importacion_id, fecha, referencia, concepto, nota_banco,
             debito, credito, saldo, tipo, categoria_id, beneficiario, justificacion,
             estado, origen, regla_id, firma, ocurrencia) VALUES ';
    $stmts = [];
    $buffer = [];
    $descargar = function () use (&$buffer, &$insertados, $pdo, $sqlBase, $cols, &$stmts): void {
        if ($buffer === []) {
            return;
        }
        $n = count($buffer) / $cols;
        if (!isset($stmts[$n])) {
            $fila = '(' . implode(',', array_fill(0, $cols, '?')) . ')';
            $stmts[$n] = $pdo->prepare($sqlBase . implode(',', array_fill(0, (int) $n, $fila)));
        }
        $stmts[$n]->execute($buffer);
        $insertados += $stmts[$n]->rowCount();
        $buffer = [];
    };

    $filas = 0;
    $insertados = 0;
    $automaticos = 0;
    $ignoradas = 0;
    $vistas = [];       // firma → nº de veces vista en este archivo
    $encabezadoPasado = false;
    $fila_i = -1;

    $pdo->beginTransaction();
    foreach (leer_filas($ruta, $ext) as $fila) {
        $fila_i++;
        if (!$encabezadoPasado) {
            if ($fila_i === $info['fila_cab']) {
                $encabezadoPasado = true;
            }
            continue;
        }

        $fecha = a_fecha(celda($fila, $m['fecha']));
        if ($fecha === null) {
            $ignoradas++;
            continue;                       // totales, subtítulos y filas sueltas
        }

        if ($m['monto'] !== null && $m['debito'] === null && $m['credito'] === null) {
            $monto  = a_monto(celda($fila, $m['monto']));
            $debito = $monto < 0 ? abs($monto) : 0.0;
            $credito = $monto > 0 ? $monto : 0.0;
        } else {
            $debito  = abs(a_monto(celda($fila, $m['debito'])));
            $credito = abs(a_monto(celda($fila, $m['credito'])));
        }
        if ($debito == 0.0 && $credito == 0.0) {
            $ignoradas++;
            continue;
        }

        $referencia = limpiar(celda($fila, $m['referencia']));
        if (preg_match('/^\d+(\.\d+)?[eE]\+?\d+$/', $referencia)) {
            $referencia = number_format((float) $referencia, 0, '', '');  // 1.436003383E9 → 1436003383
        }
        $referencia = (string) preg_replace('/\.0+$/', '', $referencia);   // 75934.0 → 75934

        $concepto = mb_substr(limpiar(celda($fila, $m['concepto'])), 0, 400);
        $nota     = mb_substr(limpiar(celda($fila, $m['nota'])), 0, 255);
        $saldoTxt = celda($fila, $m['saldo']);
        $saldo    = $saldoTxt === '' ? null : a_monto($saldoTxt);
        $tipo     = $debito > 0 ? 'D' : 'C';

        $firma = sha1(implode('|', [
            $cuentaId, $fecha, norm($referencia), norm($concepto),
            number_format($debito, 2, '.', ''), number_format($credito, 2, '.', ''),
        ]));
        $vistas[$firma] = ($vistas[$firma] ?? 0) + 1;
        $ocurrencia = $vistas[$firma];

        // Solo los débitos entran al circuito de clasificación.
        $catId = null;
        $benef = '';
        $reglaId = null;
        $origen = '';
        $estado = 'pendiente';
        if ($tipo === 'D') {
            $r = casar_regla($reglas, [
                'concepto'   => norm($concepto),
                'nota'       => norm($nota),
                'referencia' => norm($referencia),
            ]);
            if ($r !== null) {
                $catId   = (int) $r['categoria_id'];
                $benef   = (string) $r['beneficiario'];
                $reglaId = (int) $r['id'];
                $origen  = 'regla';
                $estado  = 'conciliado';
            }
        } else {
            $estado = 'credito';
        }

        array_push(
            $buffer,
            $cuentaId, $impId, $fecha, $referencia, $concepto, $nota,
            $debito, $credito, $saldo, $tipo, $catId, $benef, $nota,
            $estado, $origen, $reglaId, $firma, $ocurrencia
        );
        $filas++;
        if ($origen === 'regla') {
            $automaticos++;
        }
        if (count($buffer) >= $lote * $cols) {
            $descargar();
        }
    }
    $descargar();
    $pdo->commit();

    $duplicados = $filas - $insertados;
    $automaticos = min($automaticos, $insertados);
    $pdo->prepare('UPDATE importaciones SET filas = ?, insertados = ?, duplicados = ?, auto_map = ? WHERE id = ?')
        ->execute([$filas, $insertados, $duplicados, $automaticos, $impId]);

    return [
        'importacion' => $impId,
        'banco'       => $info['banco'],
        'filas'       => $filas,
        'insertados'  => $insertados,
        'duplicados'  => $duplicados,
        'auto'        => $automaticos,
        'ignoradas'   => $ignoradas,
    ];
}

/** Busca la cuenta por nombre o la crea. */
function cuenta_id(string $nombre, string $banco = ''): int
{
    $nombre = mb_substr(limpiar($nombre), 0, 120);
    if ($nombre === '') {
        $nombre = $banco !== '' ? $banco : 'Sin nombre';
    }
    $pdo = db();
    $s = $pdo->prepare('SELECT id FROM cuentas WHERE nombre = ?');
    $s->execute([$nombre]);
    $id = $s->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $pdo->prepare('INSERT INTO cuentas (nombre, banco) VALUES (?, ?)')->execute([$nombre, $banco]);
    return (int) $pdo->lastInsertId();
}
