<?php
/**
 * Proveedores y facturas.
 *
 * El proveedor va en el propio movimiento porque todo pago tiene destinatario,
 * haya factura o no. Las facturas van aparte y se enlazan al pago, de modo que
 * más adelante quepa lo que en la práctica ocurre: una factura pagada en varias
 * partes, o un solo pago que cubre varias facturas. Hoy la pantalla solo deja
 * anotar una por movimiento, pero el dato no hay que rehacerlo.
 *
 * En Venezuela una factura lleva dos números: el suyo y el «número de control»
 * pre-impreso que exige la Providencia 00071 del SENIAT, que nunca se reinicia.
 * Se guardan los dos, aunque al justificar solo se pida el primero: el resto se
 * completa después desde la ficha del proveedor, sin frenar el trabajo diario.
 *
 * Los proveedores son comunes a todas las unidades de negocio, igual que las
 * categorías: así se puede preguntar cuánto le pagó el grupo entero a alguien.
 */

/** Todos los proveedores, con lo que se les lleva pagado. */
function proveedores(): array
{
    // Los proveedores son comunes, pero las cifras son de la unidad activa:
    // todo lo demás en la aplicación se lee así.
    return db()->query("SELECT p.*, COUNT(m.id) movs, COALESCE(SUM(m.debito),0) total
                          FROM proveedores p
                     LEFT JOIN movimientos m ON m.proveedor_id = p.id AND m.tipo = 'D'
                                            AND " . filtro_sede() . "
                      GROUP BY p.id ORDER BY p.nombre")->fetchAll();
}

/** Nombres de proveedor, para la lista de sugerencias del formulario. */
function nombres_proveedor(): array
{
    return db()->query('SELECT nombre FROM proveedores ORDER BY nombre')->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Busca el proveedor por nombre o lo crea. Devuelve null si el nombre viene
 * vacío, que es lo normal: anotar el proveedor es opcional.
 */
function proveedor_id(string $nombre): ?int
{
    $nombre = mb_substr(limpiar($nombre), 0, 160);
    if ($nombre === '') {
        return null;
    }
    $pdo = db();
    // Se busca por el nombre normalizado para que «Corpoelec» y «CORPOELEC»
    // no acaben siendo dos proveedores distintos.
    $s = $pdo->prepare('SELECT id FROM proveedores WHERE clave = ?');
    $s->execute([norm($nombre)]);
    $id = $s->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $pdo->prepare('INSERT INTO proveedores (nombre, clave) VALUES (?, ?)')
        ->execute([$nombre, norm($nombre)]);
    return (int) $pdo->lastInsertId();
}

/** Busca la factura de ese proveedor o la crea. */
function factura_id(int $proveedorId, string $numero): ?int
{
    $numero = mb_substr(limpiar($numero), 0, 60);
    if ($numero === '') {
        return null;
    }
    $pdo = db();
    $s = $pdo->prepare('SELECT id FROM facturas WHERE proveedor_id = ? AND numero = ?');
    $s->execute([$proveedorId, $numero]);
    $id = $s->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $pdo->prepare('INSERT INTO facturas (proveedor_id, numero) VALUES (?, ?)')
        ->execute([$proveedorId, $numero]);
    return (int) $pdo->lastInsertId();
}

/**
 * Deja constancia de que ese movimiento pagó esa factura.
 * El monto aplicado es el del pago: mientras la pantalla solo permita una
 * factura por movimiento no hay nada que repartir.
 */
function vincular_pago(int $facturaId, int $movimientoId, float $monto): void
{
    db()->prepare('INSERT INTO pagos_factura (factura_id, movimiento_id, monto) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE monto = VALUES(monto)')
        ->execute([$facturaId, $movimientoId, $monto]);
}

/**
 * Anota proveedor y factura de un movimiento en un solo gesto, que es como lo
 * usa la pantalla de justificar. Devuelve el id del proveedor, o null.
 */
function anotar_proveedor(int $movimientoId, string $proveedor, string $factura, float $monto): ?int
{
    $provId = proveedor_id($proveedor);
    if ($provId === null) {
        return null;
    }
    // Con el filtro de sede: el id del movimiento llega del formulario y sin
    // esto se podría etiquetar un pago de otra unidad de negocio.
    db()->prepare('UPDATE movimientos m SET m.proveedor_id = ? WHERE m.id = ? AND ' . filtro_sede())
        ->execute([$provId, $movimientoId]);

    $facId = factura_id($provId, $factura);
    if ($facId !== null) {
        vincular_pago($facId, $movimientoId, $monto);
    }
    return $provId;
}

/** Facturas anotadas a un movimiento, con su proveedor. */
function facturas_de_movimiento(int $movimientoId): array
{
    $s = db()->prepare('SELECT f.*, p.nombre proveedor, pf.monto aplicado
                          FROM pagos_factura pf
                          JOIN facturas f ON f.id = pf.factura_id
                          JOIN proveedores p ON p.id = f.proveedor_id
                         WHERE pf.movimiento_id = ?
                      ORDER BY f.numero');
    $s->execute([$movimientoId]);
    return $s->fetchAll();
}

/** Quita la factura de un movimiento sin borrar la factura en sí. */
function desvincular_pago(int $facturaId, int $movimientoId): void
{
    db()->prepare('DELETE FROM pagos_factura WHERE factura_id = ? AND movimiento_id = ?')
        ->execute([$facturaId, $movimientoId]);
}
