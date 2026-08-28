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
