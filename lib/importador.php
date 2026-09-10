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

    $titulo = titulo_util($previas);
    $cuentaArch = cuenta_declarada($filas, $filaCab);
    $ficha = titular_declarado($filas, $filaCab);
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
        // Nombre propuesto para la cuenta: el banco si se reconoció, y si no
        // el título impreso. Nunca un metadato: más vale dejarlo en blanco y
        // que lo escriba una persona.
        'cuenta'        => $banco !== '' ? $banco : limpiar($titulo),
        'muestra'       => array_slice($filas, $desdeCab, 6),
        'huella'        => $h,
        'conocido'      => $formato !== null,
        'numero'        => $cuentaArch['numero'],
        'codigo'        => $cuentaArch['codigo'],
        'rif'           => $ficha['rif'],
        'titular'       => $ficha['titular'],
        'banco_codigo'  => $bancoCodigo,
        'saldo_inicial' => saldo_arranque(array_slice($filas, 0, max(1, $filaCab))),
        'cadena'        => $mapa !== null ? cadena_saldo($filas, $desdeCab, $mapa) : ['aplica' => false, 'ok' => 0, 'total' => 0],
    ];
}

/**
 * Primera línea de la cabecera que sirva como nombre de cuenta.
 *
 * Los bancos imprimen arriba cosas como «Fecha / Hora: 46263.44», «Usuario:
 * Jose Elias» o «Movimientos del 01/07/2026». Tomar la primera sin mirar
 * bautizaba la cuenta con eso, y el nombre es lo que la distingue.
 */
function titulo_util(array $previas): string
{
    $descartar = ['FECHA', 'HORA', 'USUARIO', 'CLIENTE', 'GENERAD', 'IMPRES', 'PAGINA',
                  'MOVIMIENTOS', 'RESUMEN', 'SALDO INICIAL', 'NRO CUENTA', 'TITULAR', 'PERIODO',
                  'CUENTA', 'ESTADO DE CUENTA', 'DESDE', 'HASTA'];
    foreach ($previas as $t) {
        $n = norm($t);
        if ($n === '' || mb_strlen($n) < 3) {
            continue;
        }
        foreach ($descartar as $d) {
            if (str_starts_with($n, $d)) {
                continue 2;
            }
        }
        // Una línea casi toda de dígitos es una fecha o un número, no un nombre.
        if (preg_replace('/\D/', '', $n) !== '' && mb_strlen(preg_replace('/\D/', '', $n)) > mb_strlen($n) / 2) {
            continue;
        }
        return $t;
    }
    return '';
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

    // Lo que suma el archivo de verdad, fila por fila. Esta es la cifra buena.
    // El resumen que el banco imprime en el pie se compara, pero no manda: hay
    // extractos que llegan con su propio total mal calculado, y antes eso
    // tumbaba una importación correcta.
    $declarado = totales_declarados($pie, $m);
    $cuadre = comparar_totales($declarado, $sumaD, $sumaC, $nD, $nC);

    $pdo->commit();

    // El catálogo aprende: el mes que viene este formato ya se reconoce solo.
    recordar_formato($info['huella']['clave'], $info['banco'], $info['huella'], $m);
    anotar_arranque($cuentaId, $info['saldo_inicial'] ?? null, $fechaMin);
    anotar_numero($cuentaId, (string) ($info['numero'] ?? ''));
    // Los repetidos por fecha corrida se buscan con el archivo ya dentro: la
    // pareja está en una carga anterior y hace falta compararla en la base.
    $repetidos = marcar_repetidos($cuentaId, $impId);
    // Las comisiones que no se reconocen por su texto necesitan ver la pareja,
    // así que se resuelven cuando el archivo entero ya está guardado.
    $automaticos += aplicar_comisiones($cuentaId);
    // Los traspasos necesitan ver las dos cuentas, así que se atan cuando el
    // archivo ya está dentro: la pareja puede llevar meses esperando en la otra.
    enlazar_traspasos($cuentaId);

    $duplicados = $filas - $insertados;
    $automaticos = min($automaticos, $insertados);
    $pdo->prepare('UPDATE importaciones SET filas = ?, insertados = ?, duplicados = ?, auto_map = ?,
                          suma_debito = ?, suma_credito = ?, descuadre = ? WHERE id = ?')
        ->execute([$filas, $insertados, $duplicados, $automaticos,
                   $sumaD, $sumaC, mb_substr($cuadre['discrepa'], 0, 255), $impId]);

    return [
        'importacion' => $impId,
        'banco'       => $info['banco'],
        'filas'       => $filas,
        'insertados'  => $insertados,
        'duplicados'  => $duplicados,
        'auto'        => $automaticos,
        'ignoradas'   => $ignoradas,
        'repetidos'   => $repetidos,
        'cuadre'      => $cuadre,
    ];
}

/**
 * Totaliza el archivo por nuestra cuenta y, aparte, mira qué dice el resumen
 * que el banco imprime al pie.
 *
 * La cifra que vale es la nuestra, sumada fila por fila. El pie del banco es
 * una opinión: hay historial de extractos que llegan con su propio total mal
 * calculado, y mientras ese pie mandaba, un archivo bueno se rechazaba entero.
 * Ahora la carga entra siempre y la diferencia, si la hay, se avisa.
 */
function comparar_totales(array $dec, float $sumaD, float $sumaC, int $nD, int $nC): array
{
    $r = [
        'aplica'   => false,        // ¿el archivo traía resumen al pie?
        'discrepa' => '',           // en qué se diferencia del nuestro
        'detalle'  => [],           // nuestras cifras, para mostrarlas
        'propio'   => ['debito' => $sumaD, 'credito' => $sumaC, 'n_debito' => $nD, 'n_credito' => $nC],
    ];
    $tol = 0.5;     // céntimos de redondeo acumulados en miles de filas
    $dif = [];

    foreach ([['debito', 'n_debito', $sumaD, $nD, 'salidas'],
              ['credito', 'n_credito', $sumaC, $nC, 'entradas']] as [$k, $kn, $suma, $n, $etq]) {
        $r['detalle'][] = ucfirst($etq) . ': ' . bs($suma) . " en $n movimientos";
        if ($dec[$k] === null) {
            continue;
        }
        $r['aplica'] = true;
        if (abs($dec[$k] - $suma) > $tol) {
            $dif[] = "en $etq el banco dice " . bs($dec[$k]) . ' y el archivo suma ' . bs($suma);
        }
        if ($dec[$kn] !== null && $dec[$kn] !== $n) {
            $dif[] = "el banco dice {$dec[$kn]} $etq y en el archivo hay $n";
        }
    }
    if ($dif !== []) {
        $r['discrepa'] = 'El resumen del propio banco no coincide con lo que trae el archivo: '
                       . implode('; ', $dif) . '. Se guardó lo que dicen las filas, que es lo real.';
    }
    return $r;
}

/**
 * Impide guardar cuando el extracto dice, con todas sus letras, que es de otra
 * cuenta distinta a la elegida.
 *
 * Hace falta desde que se sabe que una misma empresa, con el mismo RIF, puede
 * tener **varias cuentas en el mismo banco**: comparar solo el código de banco
 * —los cuatro primeros dígitos— las da todas por buenas, y un extracto de una
 * cuenta del Banco de Venezuela entraba tan campante en otra del mismo banco.
 *
 * Solo bloquea lo concluyente: el número completo, sin enmascarar, que imprime
 * el propio banco dentro del archivo. Si viene tapado con asteriscos no se
 * decide nada aquí.
 */
function choque_de_cuenta(int $cuentaId, array $c, array $a): string
{
    $crudo = (string) ($a['numero'] ?? '');
    if ($crudo === '' || str_contains($crudo, '*')) {
        return '';                  // enmascarado o ausente: no identifica nada
    }
    $delArchivo = preg_replace('/\D/', '', $crudo);
    if (strlen($delArchivo) < 15) {
        return '';                  // demasiado corto para ser un número de cuenta
    }
    $suyo = preg_replace('/\D/', '', (string) $c['numero']);
    if ($suyo === '' || $suyo === $delArchivo) {
        return '';                  // sin número anotado no hay contradicción
    }

    // ¿Y de cuál de las cuentas de esta unidad es entonces? Decirlo por su
    // nombre ahorra que alguien tenga que ir a compararlos a mano.
    $duena = '';
    $s = db()->prepare('SELECT nombre, numero FROM cuentas WHERE sede_id = ? AND id <> ?');
    $s->execute([(int) sede_actual(), $cuentaId]);
    foreach ($s->fetchAll() as $otra) {
        if (preg_replace('/\D/', '', (string) $otra['numero']) === $delArchivo) {
            $duena = (string) $otra['nombre'];
            break;
        }
    }

    return 'este extracto es de la cuenta ' . $delArchivo
         . ($duena !== '' ? ', que en el sistema es «' . $duena . '»' : ', que todavía no está registrada')
         . ', y se estaba guardando en «' . $c['nombre'] . '», que es la cuenta ' . $c['numero']
         . '. Las dos son del mismo banco, así que solo el número las distingue. No se guardó nada.';
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
    $codigo = (string) ($a['codigo'] ?? '');
    $delArchivo = banco_por_codigo($codigo);
    if ($delArchivo === '') {
        return '';
    }
    $s = db()->prepare('SELECT nombre, banco, numero FROM cuentas WHERE id = ?');
    $s->execute([$cuentaId]);
    $c = $s->fetch();
    if ($c === false) {
        return '';
    }

    $choque = choque_de_cuenta($cuentaId, $c, $a);
    if ($choque !== '') {
        return $choque;
    }

    // Lo primero es comparar número contra número: los cuatro primeros dígitos
    // son el banco, y eso vale aunque la cuenta no tenga anotado su nombre.
    // Comparar solo por el nombre del banco dejaba pasar los archivos cuando la
    // cuenta se había creado sin él, que es el caso de los cinco bancos que no
    // dicen quiénes son.
    $suyo = preg_replace('/\D/', '', (string) $c['numero']);
    if (strlen($suyo) >= 4) {
        if (substr($suyo, 0, 4) === $codigo) {
            return '';
        }
        $esperado = banco_por_codigo(substr($suyo, 0, 4));
        return 'este archivo es de ' . $delArchivo . ' (la cuenta que trae dentro empieza por '
             . $codigo . ') y se estaba guardando en «' . $c['nombre'] . '», que es la cuenta '
             . $c['numero'] . ($esperado !== '' ? ' de ' . $esperado : '') . '. No se guardó nada.';
    }

    if ((string) $c['banco'] === '' || norm((string) $c['banco']) === norm($delArchivo)) {
        return '';
    }
    return 'este archivo es de ' . $delArchivo . ' (la cuenta que trae dentro empieza por '
         . $codigo . ') y se estaba guardando en «' . $c['nombre'] . '», que es de '
         . $c['banco'] . '. No se guardó nada.';
}

/**
 * Qué se llevaría por delante deshacer una carga. Se enseña antes de tocar
 * nada: hay cosas que no vuelven, como el reparto de un pago entre facturas.
 */
function resumen_importacion(int $impId): array
{
    $pdo = db();
    $s = $pdo->prepare('SELECT i.archivo, i.creado_en, i.insertados, c.nombre cuenta, c.sede_id
                          FROM importaciones i LEFT JOIN cuentas c ON c.id = i.cuenta_id
                         WHERE i.id = ?');
    $s->execute([$impId]);
    $i = $s->fetch();
    if ($i === false || (int) $i['sede_id'] !== (int) sede_actual()) {
        return [];      // de otra unidad de negocio, o ya no existe
    }
    $c = $pdo->prepare('SELECT COUNT(*) n,
                               SUM(categoria_id IS NOT NULL) clasificados,
                               SUM(traspaso_id IS NOT NULL) traspasos
                          FROM movimientos WHERE importacion_id = ?');
    $c->execute([$impId]);
    $r = $c->fetch() ?: [];
    $p = $pdo->prepare('SELECT COUNT(*) FROM pagos_factura p
                          JOIN movimientos m ON m.id = p.movimiento_id
                         WHERE m.importacion_id = ?');
    $p->execute([$impId]);
    return [
        'archivo'      => (string) $i['archivo'],
        'cuenta'       => (string) $i['cuenta'],
        'creado_en'    => (string) $i['creado_en'],
        'movimientos'  => (int) ($r['n'] ?? 0),
        'clasificados' => (int) ($r['clasificados'] ?? 0),
        'traspasos'    => (int) ($r['traspasos'] ?? 0),
        'pagos'        => (int) $p->fetchColumn(),
    ];
}

/**
 * Deshace una carga entera: se lleva sus movimientos y la propia fila del
 * historial, como si nunca se hubiera subido.
 *
 * Hace falta desde que el extracto se carga solo al subirlo: si alguien sube el
 * archivo que no era, tiene que poder devolverlo sin llamar a nadie. Solo toca
 * cargas de la unidad de negocio activa.
 */
function deshacer_importacion(int $impId): array
{
    $r = resumen_importacion($impId);
    if ($r === []) {
        throw new RuntimeException('Esa carga no es de esta unidad de negocio.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    // La pareja de un traspaso apunta a estas filas y su columna no tiene clave
    // foránea: si no se limpia aquí, queda señalando a un movimiento que ya no
    // existe y el detalle del otro lado revienta al abrirlo.
    $pdo->prepare('UPDATE movimientos p
                     JOIN movimientos m ON m.id = p.traspaso_id
                      SET p.traspaso_id = NULL
                    WHERE m.importacion_id = ?')->execute([$impId]);
    // Los pagos repartidos a facturas caen por clave foránea; va avisado en la
    // pantalla porque eso sí es trabajo de una persona que se pierde.
    $pdo->prepare('DELETE FROM movimientos WHERE importacion_id = ?')->execute([$impId]);
    $pdo->prepare('DELETE FROM importaciones WHERE id = ?')->execute([$impId]);
    $pdo->commit();
    return $r;
}

/**
 * Señala las operaciones que parecen ser una que ya estaba, con la fecha
 * corrida. Bicentenario y el Tesoro mueven al mes siguiente operaciones de los
 * últimos días del mes; como la fecha entra en la firma, el control de
 * duplicados no las ve y la misma operación queda dos veces.
 *
 * No se borra nada: el equipo pidió que entren todas y se marquen, para que
 * alguien las mire y decida. Se revisan en la pantalla de Repetidos.
 *
 * Dos caminos, porque no todos los bancos dan una referencia que sirva:
 *  - Con referencia de verdad basta con ella y el monto; sobre los 8.001
 *    movimientos reales no señala ni una fila de más ni a un mes de distancia,
 *    así que la ventana puede ser ancha. «De verdad» quiere decir que el banco
 *    no se la pone también a operaciones de otro monto: ver $refPropia.
 *  - Sin referencia (el Tesoro trae 2.373 filas con un «0») hay que comparar el
 *    concepto, que sí se repite de verdad, así que la ventana se cierra a tres
 *    días y se descarta lo que el banco cobra un día sí y otro también.
 *
 * Las filas con una referencia larga pero reusada no entran por ningún camino,
 * a propósito. Mandarlas al del concepto se midió el 10/09/2026 sobre los
 * 32.629 movimientos cargados: las marcas pasaban de 17 a 324, casi todas
 * comisiones iguales en días seguidos. Una referencia que no identifica nada
 * más el concepto siguen sin ser prueba de que la operación entró dos veces.
 */
function marcar_repetidos(int $cuentaId, int $impId): int
{
    $pdo = db();
    $n = 0;

    // El JOIN busca la pareja más vieja (v.id < n.id), que es la que se quedó
    // con la fecha buena. Marcar la vieja dejaría el histórico moviéndose.
    $comun = 'JOIN movimientos v
                ON v.cuenta_id = n.cuenta_id AND v.id < n.id
               AND v.debito = n.debito AND v.credito = n.credito
               AND v.fecha <> n.fecha
             SET n.posible_repetido = 1, n.repetido_de = v.id
           WHERE n.importacion_id = ? AND n.posible_repetido = 0';

    // Una referencia larga no basta: vale solo si el banco no se la pone también
    // a operaciones de otro monto. Bicentenario escribe el mismo código en 1.267
    // renglones de punto de venta y Banesco repite el del remitente en cada
    // transferencia que recibe de él, así que con esas la regla quedaba
    // comparando «mismo monto en 31 días» y señaló dos cobros buenos el
    // 10/09/2026, el primer día que contabilidad abrió la pantalla. Va por
    // idx_mov_ref (cuenta_id, referencia): 3 ms para un extracto de 263 filas.
    $refPropia = "n.referencia NOT IN ('', '0') AND CHAR_LENGTH(n.referencia) >= 4
                  AND NOT EXISTS (SELECT 1 FROM movimientos o
                                   WHERE o.cuenta_id = n.cuenta_id
                                     AND o.referencia = n.referencia
                                     AND (o.debito <> n.debito OR o.credito <> n.credito))";

    $fuerte = $pdo->prepare("UPDATE movimientos n $comun
               AND v.referencia = n.referencia
               AND ABS(DATEDIFF(v.fecha, n.fecha)) <= 31
               AND $refPropia");
    $fuerte->execute([$impId]);
    $n += $fuerte->rowCount();

    // Sin referencia solo queda el concepto, y hay que descartar lo que el banco
    // cobra todos los días: el Tesoro carga los mismos 14,00 de «COMIS.RECHAZO
    // PAGO INMEDIATO» en seis fechas distintas y cada extracto señalaba el del
    // día anterior. Si ese cobro, con ese monto, aparece en más de dos fechas,
    // es un cobro que se repite y no una operación cargada dos veces. Cuesta
    // 20 ms en un extracto del día y un segundo en el libro del semestre.
    $debil = $pdo->prepare("UPDATE movimientos n $comun
               AND v.concepto = n.concepto AND n.concepto <> ''
               AND ABS(DATEDIFF(v.fecha, n.fecha)) <= 3
               AND (n.referencia IN ('', '0') OR CHAR_LENGTH(n.referencia) < 4)
               AND (SELECT COUNT(DISTINCT o.fecha) FROM movimientos o
                     WHERE o.cuenta_id = n.cuenta_id AND o.concepto = n.concepto
                       AND o.debito = n.debito AND o.credito = n.credito) <= 2");
    $debil->execute([$impId]);
    return $n + $debil->rowCount();
}

/** Cuántas operaciones están esperando que alguien diga si se repiten. */
function contar_repetidos(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM movimientos m
                               WHERE m.posible_repetido = 1 AND ' . filtro_sede('m'))->fetchColumn();
}

/** Las parejas señaladas, la nueva junto a la que ya estaba. */
function repetidos_pendientes(int $limite = 300): array
{
    // De cada lado se trae de qué archivo vino, si ya está clasificado y cuántas
    // facturas tiene relacionadas: es lo que hace falta para decidir cuál de los
    // dos se quita, y lo segundo se pierde al borrar (pagos_factura cae por FK).
    $sql = 'SELECT n.id, n.fecha, n.referencia, n.concepto, n.debito, n.credito, n.tipo,
                   n.categoria_id, c.nombre cuenta,
                   v.id AS vid, v.fecha AS vfecha, v.concepto AS vconcepto,
                   v.categoria_id AS vcategoria_id, iv.archivo AS varchivo,
                   i.archivo, i.creado_en AS cargado,
                   (SELECT COUNT(*) FROM pagos_factura pf WHERE pf.movimiento_id = n.id) AS facturas,
                   (SELECT COUNT(*) FROM pagos_factura pf WHERE pf.movimiento_id = v.id) AS vfacturas
              FROM movimientos n
              JOIN cuentas c ON c.id = n.cuenta_id
         LEFT JOIN movimientos v ON v.id = n.repetido_de
         LEFT JOIN importaciones i ON i.id = n.importacion_id
         LEFT JOIN importaciones iv ON iv.id = v.importacion_id
             WHERE n.posible_repetido = 1 AND ' . filtro_sede('n') . '
          ORDER BY n.fecha DESC, n.id DESC
             LIMIT ' . (int) $limite;
    return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Resuelve una pareja señalada. «quitar» borra una de las dos —que es lo que
 * deja los totales como son de verdad— y «dejar» las da por buenas.
 *
 * $cual dice cuál se borra: 'nueva', la que llegó después, o 'vieja', la que ya
 * estaba. Hasta el 10/09/2026 siempre se borraba la nueva, dando por hecho que
 * la vieja traía la fecha buena. Dejó de ser cierto con el libro del semestre:
 * ahí «la que ya estaba» se tecleó a mano y la fecha mala puede ser la suya
 * —Bancrecer traía 16 con el día y el mes al revés—, mientras que la nueva es
 * la del extracto del banco. Quién tiene razón lo decide la persona mirando
 * de dónde vino cada una.
 */
function resolver_repetido(int $id, string $que, string $cual = 'nueva'): bool
{
    $pdo = db();
    $s = $pdo->prepare('SELECT repetido_de FROM movimientos m WHERE m.id = ? AND ' . filtro_sede('m'));
    $s->execute([$id]);
    $fila = $s->fetch(PDO::FETCH_ASSOC);
    if ($fila === false) {
        return false;               // de otra unidad de negocio
    }
    if ($que !== 'quitar') {
        $pdo->prepare('UPDATE movimientos SET posible_repetido = 0, repetido_de = NULL WHERE id = ?')
            ->execute([$id]);
        return true;
    }

    $borrar = $id;
    if ($cual === 'vieja') {
        $borrar = (int) $fila['repetido_de'];
        if ($borrar === 0) {
            return false;           // la vieja ya no está: no hay qué quitar
        }
        // La nueva se queda como la buena, así que deja de estar señalada.
        $pdo->prepare('UPDATE movimientos SET posible_repetido = 0, repetido_de = NULL WHERE id = ?')
            ->execute([$id]);
    }

    // Ni traspaso_id ni repetido_de tienen clave foránea: sin esto otras filas
    // quedarían señalando a un movimiento que ya no está, y en la pantalla de
    // Repetidos la pareja saldría como «ya no está» sin que nadie la quitara.
    $pdo->prepare('UPDATE movimientos SET traspaso_id = NULL WHERE traspaso_id = ?')->execute([$borrar]);
    $pdo->prepare('UPDATE movimientos SET posible_repetido = 0, repetido_de = NULL WHERE repetido_de = ?')
        ->execute([$borrar]);
    $pdo->prepare('DELETE FROM movimientos WHERE id = ?')->execute([$borrar]);
    return true;
}

/**
 * Guarda el saldo de arranque que trae el propio extracto, si la cuenta aún no
 * lo tenía. Bancamiga y Bicentenario lo imprimen en la cabecera, así que deja
 * de hacer falta escribirlo a mano.
 */
/**
 * Guarda en la ficha el número de cuenta que venía impreso en el extracto, si
 * la cuenta aún no lo tenía. Es lo que después permite reconocer la cuenta sin
 * depender del título, que cambia de un archivo a otro.
 */
function anotar_numero(int $cuentaId, string $numero): void
{
    if ($numero === '' || str_contains($numero, '*')) {
        return;                     // enmascarado: no identifica nada
    }
    db()->prepare("UPDATE cuentas SET numero = ? WHERE id = ? AND numero = ''")
        ->execute([$numero, $cuentaId]);
}

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
    if (($a['numero'] ?? '') !== '') {
        $r[] = ['bien', 'Número de cuenta del extracto: ' . $a['numero'] . '.'];
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

/**
 * Pasa todos los movimientos de una cuenta a otra y borra la vacía.
 *
 * Hay que recalcular la firma de cada movimiento porque lleva dentro el id de
 * la cuenta: si no, dos cargas del mismo extracto en la cuenta ya fusionada no
 * se reconocerían como repetidas. Y como al mudarlos pueden chocar con
 * movimientos que el destino ya tenía, los que resulten idénticos se descartan
 * en vez de duplicarse.
 *
 * Devuelve cuántos se mudaron y cuántos se descartaron por estar repetidos.
 */
function fusionar_cuentas(int $origen, int $destino): array
{
    if ($origen === $destino) {
        throw new RuntimeException('Son la misma cuenta.');
    }
    $pdo = db();
    $sede = (int) sede_actual();
    $s = $pdo->prepare('SELECT COUNT(*) FROM cuentas WHERE id IN (?, ?) AND sede_id = ?');
    $s->execute([$origen, $destino, $sede]);
    if ((int) $s->fetchColumn() !== 2) {
        throw new RuntimeException('Alguna de las dos cuentas no es de esta unidad de negocio.');
    }

    // Las firmas que el destino ya tiene, para no volver a meterlas.
    $ya = [];
    foreach ($pdo->query("SELECT firma, MAX(ocurrencia) o FROM movimientos
                           WHERE cuenta_id = $destino GROUP BY firma") as $r) {
        $ya[$r['firma']] = (int) $r['o'];
    }

    $filas = $pdo->query("SELECT id, fecha, referencia, concepto, debito, credito
                            FROM movimientos WHERE cuenta_id = $origen ORDER BY id")->fetchAll();

    $mover = $pdo->prepare('UPDATE movimientos SET cuenta_id = ?, firma = ?, ocurrencia = ? WHERE id = ?');
    $borrar = $pdo->prepare('DELETE FROM movimientos WHERE id = ?');
    $movidos = 0;
    $repetidos = 0;

    $pdo->beginTransaction();
    foreach ($filas as $f) {
        $firma = sha1(implode('|', [
            $destino, $f['fecha'], norm((string) $f['referencia']), norm((string) $f['concepto']),
            number_format((float) $f['debito'], 2, '.', ''),
            number_format((float) $f['credito'], 2, '.', ''),
        ]));
        // Si el destino ya tenía esta misma línea, es el mismo movimiento
        // cargado dos veces: se descarta en lugar de duplicarlo.
        if (isset($ya[$firma])) {
            $borrar->execute([$f['id']]);
            $repetidos++;
            continue;
        }
        $ya[$firma] = 1;
        $mover->execute([$destino, $firma, 1, $f['id']]);
        $movidos++;
    }
    $pdo->prepare('DELETE FROM cuentas WHERE id = ?')->execute([$origen]);
    $pdo->commit();

    return ['movidos' => $movidos, 'repetidos' => $repetidos];
}

/**
 * Qué le falta a la ficha de una cuenta para poder cargarle extractos.
 *
 * Sin número, titular y RIF, dos cuentas del mismo banco solo se distinguen por
 * un nombre que sale del título del archivo — y ese título cambia de un mes a
 * otro, que es como acabaron existiendo dos «Venezuela» distintas.
 */
/** Las cuentas registradas de ese banco. Vacío si el archivo no dice cuál es. */
function cuentas_del_banco(string $banco, array $cuentas): array
{
    if ($banco === '') {
        return [];
    }
    return array_values(array_filter($cuentas, fn($c) => norm((string) $c['banco']) === norm($banco)));
}

/**
 * A qué cuenta lleva este archivo, o null si no se puede saber sin preguntar.
 *
 * Nunca adivina: con dos cuentas del mismo banco y sin número que las separe,
 * devuelve null y quien carga elige. Es lo que evita que un extracto entre en
 * la cuenta hermana.
 */
function cuenta_sugerida(array $a, array $cuentas): ?int
{
    $crudo   = (string) ($a['numero'] ?? '');
    $numArch = preg_replace('/\D/', '', $crudo);
    $tapado  = str_contains($crudo, '*');

    if ($numArch !== '' && !$tapado) {
        foreach ($cuentas as $c) {
            if (preg_replace('/\D/', '', (string) $c['numero']) === $numArch) {
                return (int) $c['id'];
            }
        }
    }
    // El Exterior tapa el medio y deja ver la punta y la cola («0115****0907»).
    if ($tapado && strlen($numArch) >= 8) {
        $ini = substr($numArch, 0, 4);
        $fin = substr($numArch, -4);
        $cand = [];
        foreach ($cuentas as $c) {
            $n = preg_replace('/\D/', '', (string) $c['numero']);
            if ($n !== '' && str_starts_with($n, $ini) && str_ends_with($n, $fin)) {
                $cand[] = (int) $c['id'];
            }
        }
        if (count($cand) === 1) {
            return $cand[0];
        }
    }
    foreach ($cuentas as $c) {
        if (norm((string) $c['nombre']) === norm((string) $a['cuenta']) && (string) $a['cuenta'] !== '') {
            return (int) $c['id'];
        }
    }
    $mismas = cuentas_del_banco((string) $a['banco'], $cuentas);
    return count($mismas) === 1 ? (int) $mismas[0]['id'] : null;
}

/**
 * Qué hay que preguntarle a quien carga, porque el archivo no lo trae.
 *
 * Si devuelve vacío no hay nada que confirmar y el archivo entra solo: la idea
 * es que suban el extracto y se cargue, sin pasos intermedios que no aportan.
 * Lo que se pregunta se pregunta una vez —la ficha de la cuenta queda escrita
 * y el mes siguiente ya no hace falta—.
 */
function preguntas_de(array $a, array $cuentas, ?int $sug): array
{
    if (empty($a['ok'])) {
        return ['formato'];
    }
    $q = [];
    if ($sug === null) {
        $q[] = 'cuenta';
        if ((string) $a['banco'] === '') {
            // Cinco de los once extractos no dicen de qué banco son. Si además
            // hay que crear la cuenta, alguien tiene que escribirlo.
            $q[] = 'banco';
        }
        return $q;                  // sin cuenta no se puede mirar su ficha
    }
    $ficha = null;
    foreach ($cuentas as $c) {
        if ((int) $c['id'] === $sug) {
            $ficha = $c;
        }
    }
    if ($ficha === null) {
        return ['cuenta'];
    }
    // Solo falta lo que ni la cuenta tiene ni el archivo trae: si el extracto
    // imprime el número, no hay nada que preguntar.
    $mezcla = [
        'numero'  => trim((string) $ficha['numero'])  ?: (string) ($a['numero'] ?? ''),
        'titular' => trim((string) $ficha['titular']) ?: (string) ($a['titular'] ?? ''),
        'rif'     => trim((string) $ficha['rif'])     ?: (string) ($a['rif'] ?? ''),
    ];
    if (str_contains((string) $mezcla['numero'], '*')) {
        $mezcla['numero'] = '';     // enmascarado no sirve de número
    }
    if (ficha_incompleta($mezcla) !== []) {
        $q[] = 'ficha';
    }
    return $q;
}

function ficha_incompleta(array $cuenta): array
{
    $falta = [];
    if (trim((string) ($cuenta['numero'] ?? '')) === '')  { $falta[] = 'el número de cuenta'; }
    if (trim((string) ($cuenta['titular'] ?? '')) === '') { $falta[] = 'el titular'; }
    if (trim((string) ($cuenta['rif'] ?? '')) === '')     { $falta[] = 'el RIF'; }
    return $falta;
}

/** Completa los datos que falten en la ficha, sin pisar los que ya estén. */
function completar_ficha(int $cuentaId, string $numero, string $titular, string $rif): void
{
    $pdo = db();
    foreach (['numero' => $numero, 'titular' => $titular, 'rif' => $rif] as $col => $val) {
        $val = trim($val);
        if ($val === '') {
            continue;
        }
        $pdo->prepare("UPDATE cuentas SET `$col` = ? WHERE id = ? AND `$col` = ''")
            ->execute([mb_substr(limpiar($val), 0, 160), $cuentaId]);
    }
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
