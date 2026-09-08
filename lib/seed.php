<?php
/**
 * Datos iniciales: categorías y reglas de mapeo derivadas de los patrones
 * reales encontrados en los extractos (Tesoro, BDV, Banesco y la cuenta
 * de cabecera simple). Se ejecuta una sola vez, en la instalación.
 */

function categorias_semilla(): array
{
    return [
        // nombre                              grupo           color
        ['Comisiones bancarias',               'Banco',        '#d4a857'],
        ['Mantenimiento de plataforma POS',    'Banco',        '#b88a3a'],
        ['Traspaso entre cuentas propias',     'Interno',      '#6b82c4'],
        ['Compra de divisas',                  'Tesorería',    '#38d9ff'],
        ['Nómina y personal',                  'Personal',     '#7fd18b'],
        ['Préstamos y adelantos',              'Personal',     '#4fae63'],
        ['Impuestos y tributos',               'Obligaciones', '#ff8f6b'],
        ['Parafiscales (IVSS / BANAVIH)',      'Obligaciones', '#e0714f'],
        ['Servicio eléctrico',                 'Servicios',    '#ffd166'],
        ['Agua',                               'Servicios',    '#5bc8f5'],
        ['Internet y telecomunicaciones',      'Servicios',    '#c83bff'],
        ['Proveedores',                        'Operativo',    '#8fa6e0'],
        ['Compras con tarjeta / POS',          'Operativo',    '#a0b4ea'],
        ['Combustible y transporte',           'Operativo',    '#f0a500'],
        ['Aduana y logística',                 'Operativo',    '#9e7bd6'],
        ['Alquileres',                         'Operativo',    '#cfa6ff'],
        ['Devoluciones a clientes',            'Comercial',    '#ff7ab8'],
        ['Retiros y efectivo',                 'Tesorería',    '#9aa6c4'],
        ['Otros gastos',                       'General',      '#8892b0'],
    ];
}

/** [nombre, campo, tipo, patrón, categoría, beneficiario, prioridad] */
function reglas_semilla(): array
{
    return [
        // ---- Comisiones bancarias (prioridad alta: ganan sobre patrones generales)
        ['Comisión liquidación TDD/TDC/Electrón', 'concepto', 'contiene', 'COM/LIQ/',                 'Comisiones bancarias', 'Banco', 10],
        ['Comisión pago móvil APP (PAT)',         'concepto', 'contiene', 'COM.PAT',                  'Comisiones bancarias', 'Banco', 10],
        ['Comisión P2P APP',                      'concepto', 'contiene', 'COM.P2P',                  'Comisiones bancarias', 'Banco', 10],
        ['Comisión pago inmediato',               'concepto', 'contiene', 'COMIS.PAGO INMEDIATO',     'Comisiones bancarias', 'Banco', 10],
        ['Comisión rechazo pago inmediato',       'concepto', 'contiene', 'COMIS.RECHAZO',            'Comisiones bancarias', 'Banco', 10],
        ['Comisión pago a proveedores',           'concepto', 'regex',    'COMISION PAGO A PROVEE|COM PAGO A PROVEED', 'Comisiones bancarias', 'Banco', 10],
        ['Comisión pago de nómina',               'concepto', 'contiene', 'COMISION PAGOS DE NOMINA', 'Comisiones bancarias', 'Banco', 10],
        ['Comisión uso canal IB',                 'concepto', 'contiene', 'COMISION USO CANAL',       'Comisiones bancarias', 'Banco', 10],
        ['Comisión intervención digital',         'concepto', 'contiene', 'COMISION INTERVENCION',    'Comisiones bancarias', 'Banco', 10],
        ['Comisión crédito inmediato',            'concepto', 'regex',    'COMISI.{0,3}N CR.{0,3}DITO INMEDIATO', 'Comisiones bancarias', 'Banco', 10],
        ['Comisión transferencia CR inmediato',   'concepto', 'regex',    '^COM\.? ?TRF\.? ?CR',      'Comisiones bancarias', 'Banco', 10],
        ['Comisión pago móvil BDV',               'concepto', 'regex',    'COMISION PAGOMOVIL|COMISION PAG MOVIL', 'Comisiones bancarias', 'Banco', 10],
        ['Comisión pago móvil CCE / Banesco',     'concepto', 'regex',    '^COM\.? (BANESCO )?PAGO MOVIL', 'Comisiones bancarias', 'Banco', 10],
        ['Comisión operación biometría',          'concepto', 'contiene', 'COMISION OPERACION BIOMETRIA', 'Comisiones bancarias', 'Banco', 10],
        ['Comisión cobro centralizado',           'concepto', 'contiene', 'COMISION COBRO CENTRALIZADO',  'Comisiones bancarias', 'Banco', 10],
        ['Comisión transferencia otros bancos',   'concepto', 'contiene', 'COM TRANSF LINEA',         'Comisiones bancarias', 'Banco', 10],
        ['Mantenimiento plataforma POS',          'concepto', 'contiene', 'MANT PLAT POS',            'Mantenimiento de plataforma POS', 'Banco', 15],

        // ---- Movimientos internos
        ['Traspaso crédito inmediato mismo cliente', 'concepto', 'contiene', 'CREDITO INMEDIATO MISMO CLIENTE', 'Traspaso entre cuentas propias', '', 20],
        ['Traspaso a cuenta propia ARMORMARKET',     'concepto', 'regex',    'TRF ?(OB|CR INM).*ARMORMARKET',   'Traspaso entre cuentas propias', '', 20],
        ['Traspaso entre tarjetas (TRFBTJ)',         'concepto', 'contiene', 'TRFBTJ',                          'Traspaso entre cuentas propias', '', 20],

        // ---- Tesorería
        ['Compra de divisas (intervención digital)', 'concepto', 'contiene', 'COMPRA USD INTERVENCION', 'Compra de divisas', 'BCV', 20],

        // ---- Servicios
        ['Pago CORPOELEC',        'concepto', 'contiene', 'CORPOELEC',        'Servicio eléctrico',            'CORPOELEC',    30],
        ['Pago HIDROCAPITAL',     'concepto', 'contiene', 'HIDROCAPITAL',     'Agua',                          'HIDROCAPITAL', 30],
        ['Cuota SIM CARD',        'concepto', 'contiene', 'CUOTA SERV SIM CARD', 'Internet y telecomunicaciones', '',         30],
        ['Domiciliación Pay Tech','concepto', 'contiene', 'INVERSIONES PAY TECH', 'Internet y telecomunicaciones', 'Inversiones Pay Tech', 30],

        // ---- Obligaciones
        ['Recaudación SENIAT',    'concepto', 'contiene', 'SENIAT',           'Impuestos y tributos',          'SENIAT', 30],
        ['Recaudación SAREN',     'concepto', 'contiene', 'SAREN',            'Impuestos y tributos',          'SAREN',  30],
        ['Pago de impuestos',     'concepto', 'contiene', 'PAGO IMPUESTOS',   'Impuestos y tributos',          '',       30],
        ['Pago IVSS',             'concepto', 'contiene', 'IVSS',             'Parafiscales (IVSS / BANAVIH)', 'IVSS',    30],
        ['Pago BANAVIH',          'concepto', 'contiene', 'BANAVIH',          'Parafiscales (IVSS / BANAVIH)', 'BANAVIH', 30],

        // ---- Operativo
        ['Pago a proveedores',        'concepto', 'contiene', 'PAGO A PROVEEDORES',   'Proveedores', '', 40],
        ['Cuota Distribuidora Global','concepto', 'contiene', 'DISTRIBUIDORA GLOBAL', 'Proveedores', 'Distribuidora Global D', 40],
        ['Compra en punto de venta',  'concepto', 'regex',    '^COMPRA POS CTA ?CTE', 'Compras con tarjeta / POS', '', 45],
    ];
}

function sembrar(): int
{
    $pdo = db();
    $n = 0;

    $insCat = $pdo->prepare('INSERT IGNORE INTO categorias (nombre, grupo, color, fija) VALUES (?, ?, ?, 1)');
    foreach (categorias_semilla() as [$nombre, $grupo, $color]) {
        $insCat->execute([$nombre, $grupo, $color]);
        $n += $insCat->rowCount();
    }

    $ids = [];
    foreach ($pdo->query('SELECT id, nombre FROM categorias') as $c) {
        $ids[$c['nombre']] = (int) $c['id'];
    }

    $existe = $pdo->prepare('SELECT COUNT(*) FROM reglas WHERE nombre = ?');
    $insReg = $pdo->prepare('INSERT INTO reglas (nombre, campo, tipo, patron, categoria_id, beneficiario, prioridad)
                             VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach (reglas_semilla() as [$nombre, $campo, $tipo, $patron, $cat, $benef, $prio]) {
        if (!isset($ids[$cat])) {
            continue;
        }
        $existe->execute([$nombre]);
        if ((int) $existe->fetchColumn() > 0) {
            continue;
        }
        // el patrón se guarda normalizado igual que el texto contra el que se compara
        $p = $tipo === 'regex' ? $patron : norm($patron);
        $insReg->execute([$nombre, $campo, $tipo, $p, $ids[$cat], $benef, $prio]);
        $n++;
    }
    return $n;
}

/* ====================================================================== */
/* Desglose de las comisiones bancarias                                    */
/* ====================================================================== */

/**
 * Las subcategorías de comisión que pidió contabilidad, y las reglas que
 * llevan a cada una el concepto que imprime cada banco.
 *
 * Contabilidad pasó su catálogo el 08/09/2026: 69 conceptos de once bancos con
 * el nombre que le dan a cada uno. Doce nombres distintos, de los cuales cinco
 * eran «punto de venta» dicho de cinco maneras; el usuario decidió juntarlos en
 * uno con su desglose, para que el reporte enseñe el total del POS de un
 * vistazo y se pueda abrir.
 *
 * Se cuelgan de la categoría que ya existía, «Comisiones bancarias», que sigue
 * siendo una categoría normal: lo que ya estaba clasificado ahí no se mueve, y
 * los conceptos que contabilidad dejó en el nombre genérico siguen cayendo ahí.
 *
 * Corre una sola vez. Si alguien renombra o borra una de estas categorías
 * después, no se vuelven a crear: a partir de aquí el catálogo es suyo.
 */
function sembrar_desglose_comisiones(PDO $pdo): void
{
    if (ajuste('desglose_comisiones') === '1') {
        return;
    }
    $padre = (int) $pdo->query("SELECT id FROM categorias WHERE nombre = 'Comisiones bancarias' LIMIT 1")
                       ->fetchColumn();
    if ($padre === 0) {
        return;                      // todavía no se sembraron las categorías
    }

    // El punto de venta es el único que lleva un nivel más: contabilidad
    // distingue electrónico, débito y crédito, y el banco los cobra aparte.
    $pos = cat_hija($pdo, 'Punto de venta', $padre, 'Banco', '#b88a3a');

    $hijas = [
        'Cobro por servicios'        => [$padre, '#e0c48a'],
        'Pago móvil'                 => [$padre, '#f0d9a8'],
        'Comisión por traspaso'      => [$padre, '#c9a25e'],
        'Transferencia de fondos'    => [$padre, '#b8935a'],
        'Intervención cambiaria'     => [$padre, '#a67c3d'],
        'Punto de venta · electrónico' => [$pos, '#d9b877'],
        'Punto de venta · débito'    => [$pos, '#caa763'],
        'Punto de venta · crédito'   => [$pos, '#bb964f'],
    ];
    $cat = ['Comisiones bancarias' => $padre, 'Punto de venta' => $pos];
    foreach ($hijas as $nombre => [$dePadre, $color]) {
        $cat[$nombre] = cat_hija($pdo, $nombre, $dePadre, 'Banco', $color);
    }

    // La categoría de mantenimiento del POS ya existía suelta; pasa a colgar
    // del punto de venta en vez de duplicarla, así lo ya clasificado ahí suma
    // en el total del POS sin tocar un solo movimiento.
    $pdo->prepare("UPDATE categorias SET padre_id = ? WHERE nombre = 'Mantenimiento de plataforma POS'")
        ->execute([$pos]);
    $cat['Mantenimiento de plataforma POS'] =
        (int) $pdo->query("SELECT id FROM categorias WHERE nombre = 'Mantenimiento de plataforma POS'")
                  ->fetchColumn();
    $cat['Traspaso entre cuentas propias'] =
        (int) $pdo->query("SELECT id FROM categorias WHERE nombre = 'Traspaso entre cuentas propias'")
                  ->fetchColumn();

    // Tres reglas que ya existían tienen ahora un sitio más preciso.
    foreach ([
        'COM LIQ'               => 'Punto de venta',
        'COMISION USO CANAL'    => 'Cobro por servicios',
        'COMISION INTERVENCION' => 'Intervención cambiaria',
    ] as $patron => $destino) {
        if (!empty($cat[$destino])) {
            $pdo->prepare('UPDATE reglas SET categoria_id = ? WHERE patron = ?')
                ->execute([$cat[$destino], $patron]);
        }
    }

    $existe = $pdo->prepare('SELECT COUNT(*) FROM reglas WHERE nombre = ?');
    $ins = $pdo->prepare('INSERT INTO reglas (nombre, campo, tipo, patron, categoria_id, beneficiario, prioridad, activa)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach (reglas_desglose_comisiones() as [$nombre, $tipo, $patron, $destino, $prio, $activa]) {
        if (empty($cat[$destino])) {
            continue;
        }
        $existe->execute([$nombre]);
        if ((int) $existe->fetchColumn() > 0) {
            continue;
        }
        $ins->execute([$nombre, 'concepto', $tipo, $tipo === 'regex' ? $patron : norm($patron),
                       $cat[$destino], 'Banco', $prio, $activa]);
    }

    guardar_ajuste('desglose_comisiones', '1');
}

/** Crea la subcategoría si falta y devuelve su id. */
function cat_hija(PDO $pdo, string $nombre, int $padre, string $grupo, string $color): int
{
    $pdo->prepare('INSERT IGNORE INTO categorias (nombre, grupo, color, fija, padre_id) VALUES (?, ?, ?, 1, ?)')
        ->execute([$nombre, $grupo, $color, $padre]);
    $s = $pdo->prepare('SELECT id FROM categorias WHERE nombre = ?');
    $s->execute([$nombre]);
    return (int) $s->fetchColumn();
}

/**
 * Una fila por patrón: nombre, tipo, patrón, subcategoría, prioridad, activa.
 *
 * La prioridad manda: gana la primera que coincide, de menor a mayor. Las de
 * **8** tienen que adelantarse a reglas que ya existían en 10; las de **12**
 * solo tienen que llegar antes que la regla comodín de comisiones, que va en
 * 70 y se lo lleva todo lo que diga «COM».
 *
 * Los patrones se guardan normalizados: mayúsculas, sin acentos y con un
 * espacio donde había cualquier cosa que no fuera letra o número. Por eso
 * «COM/LIQ/ELE 8398…» se busca como «COM LIQ ELE».
 */
function reglas_desglose_comisiones(): array
{
    return [
        // --- Punto de venta, por tipo de tarjeta. Van en 8 porque la regla
        // «COM LIQ» que ya existía las alcanzaría antes.
        ['Comisión · liquidación punto de venta electrónico', 'contiene', 'COM LIQ ELE',
            'Punto de venta · electrónico', 8, 1],
        ['Comisión · liquidación punto de venta débito', 'contiene', 'COM LIQ TDD',
            'Punto de venta · débito', 8, 1],
        ['Comisión · liquidación punto de venta crédito', 'contiene', 'COM LIQ TDC',
            'Punto de venta · crédito', 8, 1],

        // --- Dos que contabilidad manda al nombre genérico aunque el texto
        // parezca de otra familia. Van en 8 para adelantarse a las de abajo.
        ['Comisión · servicio SMS de Banesco', 'contiene', 'COMISION SERVICIO SMS',
            'Comisiones bancarias', 8, 1],
        ['Comisión · servicio especializado POS del Exterior', 'contiene', 'SERVICIO ESPECIALIZADO POS',
            'Cobro por servicios', 8, 1],

        // --- Cobros por servicio: lo que el banco cobra por atender, no por mover plata.
        ['Comisión · emisión de estado de cuenta', 'regex',
            'ESTADO DE CUENTA|EDO DE CUENTA|EDO DE CTA|EDO CTA|ESTADO CUENTA|EMISION EDO|EMISION DE EDO',
            'Cobro por servicios', 12, 1],
        ['Comisión · mantenimiento de cuenta', 'regex',
            'MANTENIMIENTO|MANT Y SERV|MENSUAL MANT',
            'Cobro por servicios', 12, 1],
        ['Comisión · atención telefónica', 'regex',
            'ATENCION TELEFONICA|CONSULTA TELEFONICA|SERVICIO DE ATENCION|TELEFONICA IVR',
            'Cobro por servicios', 12, 1],
        ['Comisión · mensajes de texto', 'regex', '(^| )SMS( |$)|CRECERTEXTO',
            'Cobro por servicios', 12, 1],
        ['Comisión · cargo por servicio', 'empieza', 'CARGO POR SERVICIO',
            'Cobro por servicios', 12, 1],
        // El Tesoro lo escribe entero en una cuenta y abreviado en otra
        // («COMISION USO CANAL IB» y «COMIS USO CANAL INT BANK»), así que se
        // busca por lo único que comparten.
        ['Comisión · uso del canal de internet', 'contiene', 'USO CANAL',
            'Cobro por servicios', 12, 1],

        // --- Punto de venta: la tarifa del terminal y lo que cobra el aliado.
        ['Comisión · tarifa y mantenimiento del punto de venta', 'regex',
            'SERV MTTO POS|MANT PLAT POS|SERV ALIADO|DOMICILIACION ALIAD|COSTOS POS|RECAUDACION A TERCEROS|COM DIA A[0-9]',
            'Mantenimiento de plataforma POS', 12, 1],
        ['Comisión · punto de venta (abono)', 'contiene', 'COM POS ABO',
            'Punto de venta', 12, 1],
        ['Comisión · terminales de punto de venta', 'contiene', 'COMISION TERMINALES PUNTO',
            'Punto de venta', 12, 1],

        // --- Las demás familias del catálogo.
        // «P2C» a secas no sirve: en el extracto del Bicentenario hay 3.555
        // textos con esas tres letras y casi todos son «PAG P2C …», que es el
        // cobro que entra, no su comisión. Tiene que llevar «COM» delante.
        ['Comisión · pago móvil P2C', 'regex', 'COM( \\w+)? P2C', 'Pago móvil', 12, 1],
        ['Comisión · crédito inmediato entre cuentas', 'contiene', 'COM CR INM',
            'Traspaso entre cuentas', 12, 1],
        ['Comisión · transferencia de fondos', 'contiene', 'COMISION POR TRANSFERENCIA DE FONDOS',
            'Transferencia de fondos', 12, 1],
        ['Comisión · intervención cambiaria', 'contiene', 'COMISION INTERV',
            'Intervención cambiaria', 12, 1],
        ['Comisión · 0,50 % por operación en dólares', 'contiene', 'OPERACION EN USD',
            'Intervención cambiaria', 12, 1],
        // Bancrecer reembolsa el costo de la operación y no dice «comisión» en
        // ninguna parte, así que la regla comodín no lo alcanza. El patrón va
        // pegado a «COSTO OP» para no llevarse el «Reembolso de costos POS» de
        // Banplus, que contabilidad manda al punto de venta.
        ['Comisión · reembolso de costo de operación', 'empieza', 'REEMB COSTO OP',
            'Comisiones bancarias', 12, 1],

        // --- El BNC llama «traspaso» a lo que es un traspaso: no es comisión,
        // y CONCIL ya sabe enlazar los dos lados de la pareja.
        ['Traspaso entre cuentas propias · BNC', 'regex', '^TELF .*TRASPASO$',
            'Traspaso entre cuentas propias', 12, 1],

        // --- En espera de que contabilidad confirme. Se crean apagadas para
        // que se vean en la pantalla de Reglas y se enciendan de un clic: tal
        // como están en la hoja, convertirían pagos reales en comisiones.
        ['⏸ Mercantil · OP.CRED.DIRT. (parece el pago entero, no su comisión)', 'contiene',
            'OP CRED DIRT', 'Comisiones bancarias', 12, 0],
        ['⏸ Mercantil · PAGO DOMICILIADO (alcanzaría cualquier domiciliación)', 'contiene',
            'PAGO DOMICILIADO', 'Mantenimiento de plataforma POS', 12, 0],
    ];
}
