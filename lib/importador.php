<?php
/**
 * Detección de formato e importación de extractos.
 *
 * El formato se reconoce por la estructura del archivo (ver lib/huella.php),
 * nunca por el nombre del archivo ni el de la hoja: contabilidad los renombra
 * y no prueban nada. Las columnas se localizan por su rótulo, y cuando el
 * archivo no trae rótulos, por la forma de los datos de cada columna.
 */

const CAB_FECHA  = ['FECHA', 'FECHA OPERACION', 'FECHA VALOR', 'FECHA MOVIMIENTO'];
const CAB_REF    = ['REFERENCIA', 'REF', 'NRO REFERENCIA', 'DOCUMENTO', 'NUMERO', 'COMPROBANTE'];
const CAB_TEXTO  = ['CONCEPTO', 'DESCRIPCION', 'DESCRIP', 'DETALLE', 'MOVIMIENTO', 'TRANSACCION', 'OBSERVACION', 'MOTIVO'];
const CAB_DEBITO = ['DEBITO', 'DEBE', 'CARGO', 'CARGOS', 'RETIRO', 'DEBITOS'];
const CAB_CREDITO= ['CREDITO', 'HABER', 'ABONO', 'ABONOS', 'DEPOSITO', 'CREDITOS'];
const CAB_MONTO  = ['MONTO', 'IMPORTE', 'VALOR'];
const CAB_SALDO  = ['SALDO', 'BALANCE', 'SALDO ACTUAL'];

/** Cuántas filas del principio se leen para reconocer el formato. */
const FILAS_MUESTRA = 40;

/** Lee las filas del archivo. El tipo se decide por el contenido, no por la extensión. */
function leer_filas(string $ruta, string $ext): Generator
{
    return match (formato_archivo($ruta)) {
        'xlsx' => (new XlsxLector($ruta))->filas(),
        'html' => html_filas($ruta),
        default => csv_filas($ruta),
    };
}

/**
 * Analiza el archivo: reconoce el formato, mapea columnas, propone la cuenta y
 * reúne las evidencias que permiten avisar si alguien se equivocó de banco.
 * Devuelve null en 'mapa' si no logra reconocer el formato.
 */
function analizar(string $ruta, string $ext): array
{
    $filas = [];
    foreach (leer_filas($ruta, $ext) as $f) {
        $filas[] = $f;
        if (count($filas) >= FILAS_MUESTRA) {
            break;
        }
    }

    $h = huella($filas);
    $filaCab = $h['fila_cab'];
    $cabecera = $filaCab >= 0 ? $filas[$filaCab] : null;

    // Las filas anteriores al encabezado son los títulos que imprime el banco.
    $previas = [];
    for ($i = 0; $i < max(0, $filaCab); $i++) {
        $t = array_values(array_filter(array_map('limpiar', $filas[$i]), fn($v) => $v !== ''));
        if ($t !== []) {
            $previas[] = implode(' ', $t);
        }
    }

    // Si el catálogo ya vio esta estructura, se reutiliza su mapeo.
    $formato = formato_por_clave($h['clave']);
    $mapa = $formato['mapa'] ?? null;
    if ($mapa === null) {
        $mapa = $cabecera !== null ? mapear_columnas($cabecera, $h['forma']) : null;
    }
    // Si los rótulos no alcanzaron, se intenta por la forma de los datos: puede
    // que la fila elegida como encabezado no lo fuera.
    $mapa ??= mapear_por_forma($h['forma']);

    $titulo = $previas[0] ?? '';
    $cuentaArch = cuenta_declarada($filas, $filaCab);
    $bancoCodigo = banco_por_codigo($cuentaArch['codigo']);

    // Orden de confianza: el número de cuenta impreso en el archivo primero,
    // porque lo escribe el banco; luego lo que el catálogo aprendió; y por
    // último el título. La huella es solo estructura, así que dos bancos con
    // el mismo diseño de columnas comparten entrada de catálogo: dejar que esa
    // entrada mande sobre el número de cuenta etiquetaría mal el archivo.
    $banco = $bancoCodigo;
    if ($banco === '') {
        $banco = ($formato['banco'] ?? '') ?: detectar_banco($titulo, $cabecera);
    }

    $desdeCab = $filaCab + 1;
    return [
        'cabecera'      => $cabecera,
        'fila_cab'      => $filaCab,
        'mapa'          => $mapa,
        'titulo'        => $titulo,
        'banco'         => $banco,
        'cuenta'        => $titulo !== '' ? limpiar($titulo) : $banco,
        'muestra'       => array_slice($filas, $desdeCab, 6),
        'huella'        => $h,
        'conocido'      => $formato !== null,
        'numero'        => $cuentaArch['numero'],
        'codigo'        => $cuentaArch['codigo'],
        'banco_codigo'  => $bancoCodigo,
        'saldo_inicial' => saldo_arranque(array_slice($filas, 0, max(1, $filaCab))),
        'cadena'        => $mapa !== null ? cadena_saldo($filas, $desdeCab, $mapa) : ['aplica' => false, 'ok' => 0, 'total' => 0],
    ];
}

/**
 * Asocia cada rol de columna con su índice, por el rótulo del encabezado.
 * La forma de los datos completa lo que el rótulo no dice: Banesco deja la
 * columna de fecha sin rótulo y hay que deducirla de que trae fechas.
 */
function mapear_columnas(array $cab, string $forma = ''): ?array
{
    $m = ['fecha' => null, 'referencia' => null, 'concepto' => null, 'nota' => null,
          'debito' => null, 'credito' => null, 'monto' => null, 'saldo' => null, 'signo' => null];
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

    // Sin rótulo de fecha, se toma la primera columna cuyos datos son fechas.
    if ($m['fecha'] === null) {
        $p = strpos($forma, 'F');
        if ($p !== false && !in_array($p, $m, true)) {
            $m['fecha'] = $p;
        }
    }

    if ($m['fecha'] === null) {
        return null;
    }
    if ($m['debito'] === null && $m['credito'] === null && $m['monto'] === null) {
        return null;
    }

    // El primer campo de texto es la descripción del banco; el segundo, la nota.
    $m['concepto'] = $textos[0] ?? null;
    $m['nota']     = $textos[1] ?? null;

    // Banesco deja sin rótulo la columna de notas: se toma la primera columna
    // libre entre la descripción y el monto, siempre que traiga texto. Sin esa
    // condición, en BNC se elegía una columna vacía que solo estorba.
    if ($m['nota'] === null && $m['concepto'] !== null) {
        $ocupadas = array_filter($m, fn($v) => $v !== null);
        $limite = $m['monto'] ?? $m['debito'] ?? $m['credito'];
        for ($c = $m['concepto'] + 1; $c < $limite; $c++) {
            if (!in_array($c, $ocupadas, true) && ($forma[$c] ?? 'V') === 'T') {
                $m['nota'] = $c;
                break;
            }
        }
    }
    return $m;
}

/**
 * Mapeo para archivos sin encabezado, deducido de la forma de las columnas.
 * Es el caso del Exterior: concepto·fecha·referencia·monto·signo·saldo, sin
 * un solo rótulo. La columna de signo (+/-) es la que ancla todo lo demás.
 */
function mapear_por_forma(string $forma): ?array
{
    $s = strpos($forma, 'S');
    $f = strpos($forma, 'F');
    if ($s === false || $f === false || !str_contains($forma, 'T')) {
        return null;
    }

    $m = ['fecha' => $f, 'referencia' => null, 'concepto' => strpos($forma, 'T'),
          'nota' => null, 'debito' => null, 'credito' => null,
          'monto' => null, 'saldo' => null, 'signo' => $s];

    // El importe es el número pegado al signo; el saldo, el primero que sigue.
    for ($c = $s - 1; $c >= 0; $c--) {
        if ($forma[$c] === 'N') { $m['monto'] = $c; break; }
    }
    for ($c = $s + 1; $c < strlen($forma); $c++) {
        if ($forma[$c] === 'N') { $m['saldo'] = $c; break; }
    }
    if ($m['monto'] === null) {
        return null;
    }
    // Lo que quede de numérico y no sea importe ni saldo es la referencia.
    for ($c = 0; $c < strlen($forma); $c++) {
        if ($forma[$c] === 'N' && $c !== $m['monto'] && $c !== $m['saldo']) {
            $m['referencia'] = $c;
            break;
        }
    }
    return $m;
}

/**
 * Identifica el banco por el título que el propio banco imprime dentro del
 * archivo. Es contenido, no nombre de archivo, así que sigue siendo válido
 * aunque renombren el documento.
 */
function detectar_banco(string $titulo, ?array $cab): string
{
    $t = norm($titulo);
    $conocidos = [
        'BANESCO'      => 'Banesco',
        'VENEZUELA'    => 'Banco de Venezuela',
        'BDV'          => 'Banco de Venezuela',
        'TESORO'       => 'Banco del Tesoro',
        'BANCAMIGA'    => 'Bancamiga',
        'MERCANTIL'    => 'Mercantil',
        'PROVINCIAL'   => 'Provincial',
        'BNC'          => 'BNC',
        'NACIONAL DE CREDITO' => 'BNC',
        'BANCO PLAZA'  => 'Banco Plaza',
        'BANCARIBE'    => 'Bancaribe',
        'CARIBE'       => 'Bancaribe',
        'EXTERIOR'     => 'Exterior',
        'BICENTENARIO' => 'Bicentenario',
        'TRABAJADORES' => 'Bicentenario',
        'BANCRECER'    => 'Bancrecer',
        'BANPLUS'      => 'Banplus',
    ];
    foreach ($conocidos as $clave => $nombre) {
        if ($t !== '' && str_contains($t, $clave)) {
            return $nombre;
        }
    }
    // Sin título: el extracto viejo de Bancamiga es el único con DESCRIP y Saldo.
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
 *
 * Al terminar compara lo importado con los totales que el propio archivo
 * declara en su pie. Si no cuadran, deshace todo: significa que se leyó mal.
 */
function importar(string $ruta, string $ext, int $cuentaId, string $archivoNombre, ?array $info = null): array
{
    // Se importa con el mismo análisis que se mostró al confirmar. Volver a
    // analizar aquí podría dar otro mapeo: si en el mismo lote se acaba de
    // aprender este formato, la segunda vez saldría del catálogo y no de los
    // rótulos que el usuario vio en la muestra.
    $info ??= analizar($ruta, $ext);
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
    $encabezadoPasado = $info['fila_cab'] < 0;
    $fila_i = -1;
    $pie = [];          // filas de totales del final, para verificar al cerrar
    $sumaD = 0.0;
    $sumaC = 0.0;
    $nD = 0;
    $nC = 0;
    $fechaMin = null;

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
            if (str_contains(norm(implode(' ', array_map('strval', $fila))), 'TOTAL')) {
                $pie[] = $fila;         // resumen del banco: sirve para cuadrar
            }
            continue;                   // totales, subtítulos y filas sueltas
        }

        if ($m['signo'] !== null && $m['monto'] !== null) {
            // El Exterior manda el importe siempre positivo y el signo aparte.
            $monto = abs(a_monto(celda($fila, $m['monto'])));
            $negativo = celda($fila, $m['signo']) === '-';
            $debito  = $negativo ? $monto : 0.0;
            $credito = $negativo ? 0.0 : $monto;
        } elseif ($m['monto'] !== null && $m['debito'] === null && $m['credito'] === null) {
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
        if ($debito > 0) { $nD++; $sumaD += $debito; }
        if ($credito > 0) { $nC++; $sumaC += $credito; }
        if ($fechaMin === null || $fecha < $fechaMin) { $fechaMin = $fecha; }

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

    // Verificación final contra lo que el propio archivo declara.
    $declarado = totales_declarados($pie, $m);
    $cuadre = comparar_totales($declarado, $sumaD, $sumaC, $nD, $nC);
    if ($cuadre['falla'] !== '') {
        $pdo->rollBack();
        throw new RuntimeException('El archivo no cuadra con su propio resumen: ' . $cuadre['falla']
            . ' No se guardó nada. Vuelve a descargarlo del banco y súbelo de nuevo.');
    }

    $pdo->commit();

    // El catálogo aprende: el mes que viene este formato ya se reconoce solo.
    recordar_formato($info['huella']['clave'], $info['banco'], $info['huella'], $m);
    anotar_arranque($cuentaId, $info['saldo_inicial'] ?? null, $fechaMin);

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
        'cuadre'      => $cuadre,
    ];
}

/**
 * Contrasta lo contado con lo que el archivo declara en su pie.
 * Devuelve el detalle para mostrarlo, y en 'falla' el motivo si no cuadra.
 */
function comparar_totales(array $dec, float $sumaD, float $sumaC, int $nD, int $nC): array
{
    $r = ['aplica' => false, 'falla' => '', 'detalle' => []];
    $tol = 0.5;     // céntimos de redondeo acumulados en miles de filas

    foreach ([['debito', 'n_debito', $sumaD, $nD, 'salidas'],
              ['credito', 'n_credito', $sumaC, $nC, 'entradas']] as [$k, $kn, $suma, $n, $etq]) {
        if ($dec[$k] === null) {
            continue;
        }
        $r['aplica'] = true;
        if (abs($dec[$k] - $suma) > $tol) {
            $r['falla'] = "el resumen dice $etq por " . bs($dec[$k]) . ' y se leyeron ' . bs($suma) . '.';
            return $r;
        }
        if ($dec[$kn] !== null && $dec[$kn] !== $n) {
            $r['falla'] = "el resumen dice {$dec[$kn]} $etq y se leyeron $n.";
            return $r;
        }
        $r['detalle'][] = ucfirst($etq) . ': ' . bs($suma) . ($dec[$kn] !== null ? " en {$dec[$kn]} movimientos" : '');
    }
    return $r;
}

/**
 * Impide guardar cuando la evidencia contradice la cuenta elegida.
 *
 * Solo bloquea lo concluyente: que el número de cuenta impreso en el archivo
 * sea de otro banco. Eso lo escribe el banco, no depende de cómo se llame el
 * archivo, y no admite discusión. Lo demás se avisa pero deja continuar.
 */
function choque_de_banco(int $cuentaId, array $a): string
{
    $delArchivo = banco_por_codigo((string) ($a['codigo'] ?? ''));
    if ($delArchivo === '') {
        return '';
    }
    $s = db()->prepare('SELECT nombre, banco FROM cuentas WHERE id = ?');
    $s->execute([$cuentaId]);
    $c = $s->fetch();
    if ($c === false || (string) $c['banco'] === '') {
        return '';
    }
    if (norm((string) $c['banco']) === norm($delArchivo)) {
        return '';
    }
    return 'este archivo es de ' . $delArchivo . ' (la cuenta que trae dentro empieza por '
         . $a['codigo'] . ') y se estaba guardando en «' . $c['nombre'] . '», que es de '
         . $c['banco'] . '. No se guardó nada.';
}

/**
 * Guarda el saldo de arranque que trae el propio extracto, si la cuenta aún no
 * lo tenía. Bancamiga y Bicentenario lo imprimen en la cabecera, así que deja
 * de hacer falta escribirlo a mano.
 */
function anotar_arranque(int $cuentaId, ?float $saldo, ?string $desde): void
{
    if ($saldo === null || $desde === null) {
        return;
    }
    // Los dos campos van juntos o el saldo sale mal: saldo_cuenta() suma los
    // movimientos desde saldo_fecha, y sin ella sumaría el historial entero
    // sobre un arranque que corresponde solo a este extracto.
    db()->prepare('UPDATE cuentas SET saldo_inicial = ?, saldo_fecha = ?
                    WHERE id = ? AND saldo_inicial = 0 AND saldo_fecha IS NULL')
        ->execute([$saldo, $desde, $cuentaId]);
}

/**
 * Resume en lenguaje llano qué se pudo comprobar del archivo, para que quien
 * lo sube vea en qué se basa el sistema antes de confirmar.
 */
function comprobaciones(array $a): array
{
    $r = [];
    $delArchivo = banco_por_codigo((string) ($a['codigo'] ?? ''));
    if ($delArchivo !== '') {
        $r[] = ['bien', 'El archivo trae por dentro una cuenta de ' . $delArchivo
                      . ', así que el banco es seguro.'];
    }
    $c = $a['cadena'] ?? ['aplica' => false];
    if (!empty($c['aplica']) && $c['total'] > 0) {
        $pct = (int) round(100 * $c['ok'] / $c['total']);
        // Cuando no encadena puede ser que el banco no ordene las filas, o que
        // las columnas se hayan leído mal. Aquí no se sabe cuál de las dos es,
        // así que no se afirma ninguna: se dice que no se pudo confirmar y se
        // pide mirar la muestra, que es lo único honesto.
        $r[] = $pct >= 90
            ? ['bien', "Los saldos del archivo encadenan fila por fila ($c[ok] de $c[total]): las columnas se leyeron bien."]
            : ['aviso', "Con los saldos de este archivo no se pudo confirmar la lectura ($c[ok] de $c[total] encajan). "
                      . 'Algunos bancos no entregan las filas en orden. Revise abajo que la muestra se vea bien '
                      . 'antes de guardar.'];
    }
    if (!empty($a['conocido'])) {
        $r[] = ['bien', 'Este formato ya se había cargado antes y se reconoció solo.'];
    } elseif (!empty($a['ok'])) {
        $r[] = ['aviso', 'Formato nuevo. Al confirmar, queda aprendido y la próxima vez no habrá que elegir nada.'];
    }
    if (($a['arranque'] ?? null) !== null) {
        $r[] = ['bien', 'Trae el saldo con el que arranca el mes: ' . bs((float) $a['arranque']) . '.'];
    }
    return $r;
}

/** Busca la cuenta por nombre o la crea. */
function cuenta_id(string $nombre, string $banco = ''): int
{
    $nombre = mb_substr(limpiar($nombre), 0, 120);
    if ($nombre === '') {
        $nombre = $banco !== '' ? $banco : 'Sin nombre';
    }
    $sede = sede_actual();
    if ($sede === null) {
        // Sin unidad activa no se sabe de quién sería la cuenta, y sede_id = 0
        // dejaría la cuenta huérfana para que la migración la adopte al azar.
        throw new RuntimeException('No hay una unidad de negocio activa: elige una antes de cargar.');
    }
    $pdo = db();
    // El nombre solo tiene que ser único dentro de la sede: dos unidades de
    // negocio pueden tener cada una su cuenta "BANESCO".
    $s = $pdo->prepare('SELECT id FROM cuentas WHERE nombre = ? AND sede_id = ?');
    $s->execute([$nombre, $sede]);
    $id = $s->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $pdo->prepare('INSERT INTO cuentas (nombre, banco, sede_id) VALUES (?, ?, ?)')
        ->execute([$nombre, $banco, $sede]);
    return (int) $pdo->lastInsertId();
}
