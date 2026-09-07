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

/**
 * Busca la factura de ese proveedor en la unidad activa, o la crea.
 * La factura la debe una empresa concreta, así que la sede va en la clave: sin
 * ella la factura quedaría fuera de toda unidad y no la vería nadie.
 */
function factura_id(int $proveedorId, string $numero): ?int
{
    $numero = mb_substr(limpiar($numero), 0, 60);
    $sede = (int) sede_actual();
    if ($numero === '' || $sede <= 0) {
        return null;
    }
    $pdo = db();
    $s = $pdo->prepare('SELECT id FROM facturas WHERE proveedor_id = ? AND numero = ? AND sede_id = ?');
    $s->execute([$proveedorId, $numero, $sede]);
    $id = $s->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $pdo->prepare('INSERT INTO facturas (proveedor_id, numero, sede_id) VALUES (?, ?, ?)')
        ->execute([$proveedorId, $numero, $sede]);
    return (int) $pdo->lastInsertId();
}

/**
 * Deja constancia de que ese movimiento pagó esa factura.
 * El monto aplicado es el del pago: mientras la pantalla solo permita una
 * factura por movimiento no hay nada que repartir.
 */
function vincular_pago(int $facturaId, int $movimientoId, float $monto): void
{
    db()->prepare('INSERT INTO pagos_factura (factura_id, movimiento_id, monto, monto_bs, usuario_id)
                   VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE monto = VALUES(monto), monto_bs = VALUES(monto_bs)')
        ->execute([$facturaId, $movimientoId, $monto, $monto, (int) ($_SESSION['uid'] ?? 0) ?: null]);
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

/**
 * Facturas que cubre un movimiento. Trae dos cifras distintas que es fácil
 * confundir: «aplicado_aqui» es lo que puso este pago, y «aplicado» lo que la
 * factura lleva cubierto entre todos los pagos.
 */
function facturas_de_movimiento(int $movimientoId): array
{
    $s = db()->prepare('SELECT f.*, p.nombre proveedor,
                               pf.monto aplicado_aqui, pf.monto_bs, pf.tasa,
                               (SELECT COALESCE(SUM(x.monto), 0) FROM pagos_factura x
                                 WHERE x.factura_id = f.id) aplicado
                          FROM pagos_factura pf
                          JOIN facturas f ON f.id = pf.factura_id
                          JOIN proveedores p ON p.id = f.proveedor_id
                         WHERE pf.movimiento_id = ?
                      ORDER BY f.numero');
    $s->execute([$movimientoId]);
    $filas = $s->fetchAll();
    foreach ($filas as $i => $f) {
        $filas[$i]['saldo'] = saldo_factura($f);
    }
    return $filas;
}

/** Quita la factura de un movimiento sin borrar la factura en sí. */
function desvincular_pago(int $facturaId, int $movimientoId): void
{
    db()->prepare('DELETE FROM pagos_factura WHERE factura_id = ? AND movimiento_id = ?')
        ->execute([$facturaId, $movimientoId]);
}

/* ---------------------------------------------------------------- La ficha */

/**
 * El RIF limpio, o cadena vacía si eso no es un RIF.
 *
 * Se usa como clave para no duplicar un proveedor, y por eso tiene que ser
 * severo: en el listado que exporta contabilidad hay celdas de RIF con un
 * teléfono, con el código del proveedor o con la palabra que se les ocurrió.
 * Dar por bueno cualquier texto haría que dos proveedores distintos con la
 * misma celda basura se fundieran en uno.
 */
function rif_normalizado(string $rif): string
{
    $t = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $rif));
    return preg_match('/^[JGVEP]\d{8,10}$/', $t) === 1 ? $t : '';
}

/** Un proveedor por su id, o null. */
function proveedor(int $id): ?array
{
    $s = db()->prepare('SELECT * FROM proveedores WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/**
 * ¿Con qué proveedor existente choca esta ficha?
 *
 * Primero por el RIF, que es el identificador de verdad, y solo después por el
 * nombre normalizado. Si el RIF apunta a uno y el nombre a otro, no se decide
 * aquí: se devuelve el choque para que lo resuelva una persona. Fundir dos
 * fichas en silencio es lo que acaba produciendo pagos repetidos.
 */
function proveedor_que_choca(string $nombre, string $rifClave, int $salvo = 0): array
{
    $pdo = db();
    $porRif = null;
    if ($rifClave !== '') {
        $s = $pdo->prepare('SELECT * FROM proveedores WHERE rif_clave = ? AND id <> ? LIMIT 1');
        $s->execute([$rifClave, $salvo]);
        $porRif = $s->fetch() ?: null;
    }
    $s = $pdo->prepare('SELECT * FROM proveedores WHERE clave = ? AND id <> ? LIMIT 1');
    $s->execute([norm($nombre), $salvo]);
    $porNombre = $s->fetch() ?: null;

    return ['rif' => $porRif, 'nombre' => $porNombre];
}

/**
 * Alta o edición de un proveedor. Devuelve su id.
 * Lo único imprescindible es el nombre, igual que en la ficha de la cuenta:
 * exigir el RIF dejaría fuera al que se anota con prisa.
 */
function guardar_proveedor(array $d, int $id = 0): int
{
    $nombre = mb_substr(limpiar((string) ($d['nombre'] ?? '')), 0, 160);
    if ($nombre === '') {
        throw new RuntimeException('El proveedor necesita un nombre.');
    }
    $rif = mb_substr(limpiar((string) ($d['rif'] ?? '')), 0, 20);
    $campos = [
        'nombre'    => $nombre,
        'clave'     => norm($nombre),
        'codigo'    => mb_substr(limpiar((string) ($d['codigo'] ?? '')), 0, 40),
        'rif'       => $rif,
        'rif_clave' => rif_normalizado($rif),
        'nit'       => mb_substr(limpiar((string) ($d['nit'] ?? '')), 0, 20),
        'telefono'  => mb_substr(limpiar((string) ($d['telefono'] ?? '')), 0, 60),
        'nota'      => mb_substr(limpiar((string) ($d['nota'] ?? '')), 0, 255),
        'activo'    => empty($d['activo']) ? 0 : 1,
    ];

    $choque = proveedor_que_choca($nombre, $campos['rif_clave'], $id);
    if ($choque['nombre'] !== null) {
        throw new RuntimeException('Ya hay un proveedor que se llama «' . $choque['nombre']['nombre'] . '».');
    }
    if ($choque['rif'] !== null) {
        throw new RuntimeException('Ese RIF ya es de «' . $choque['rif']['nombre']
            . '». Si son el mismo, únelos desde el listado en lugar de crear otra ficha.');
    }

    $pdo = db();
    if ($id > 0) {
        $sql = implode(', ', array_map(fn($c) => "$c = ?", array_keys($campos)));
        $pdo->prepare("UPDATE proveedores SET $sql WHERE id = ?")
            ->execute([...array_values($campos), $id]);
        return $id;
    }
    $cols = implode(', ', array_keys($campos));
    $marcas = implode(', ', array_fill(0, count($campos), '?'));
    $pdo->prepare("INSERT INTO proveedores ($cols) VALUES ($marcas)")->execute(array_values($campos));
    return (int) $pdo->lastInsertId();
}

/**
 * Listado con lo que se le lleva pagado.
 *
 * El proveedor lo comparte todo el grupo —para poder preguntar cuánto le pagó
 * el consorcio entero—, pero las cifras y las facturas son de la unidad activa,
 * que es como se lee todo lo demás en la aplicación.
 */
function buscar_proveedores(string $texto = '', bool $soloActivos = false): array
{
    $sede = (int) sede_actual();
    $donde = [];
    $args  = [];
    if ($soloActivos) {
        $donde[] = 'p.activo = 1';
    }
    $texto = trim($texto);
    if ($texto !== '') {
        // Se busca por las tres cosas por las que la gente busca: el nombre, el
        // RIF (escrito con guiones o sin ellos, da igual) y el código corto.
        $donde[] = '(p.clave LIKE ? OR p.rif_clave LIKE ? OR UPPER(p.codigo) LIKE ?)';
        $plano = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $texto));
        $args = ['%' . norm($texto) . '%', '%' . $plano . '%', '%' . mb_strtoupper($texto) . '%'];
    }
    $sql = 'SELECT p.*,
                   (SELECT COUNT(*) FROM movimientos m
                     WHERE m.proveedor_id = p.id AND m.tipo = \'D\' AND ' . filtro_sede() . ') movs,
                   (SELECT COALESCE(SUM(m.debito), 0) FROM movimientos m
                     WHERE m.proveedor_id = p.id AND m.tipo = \'D\' AND ' . filtro_sede() . ') total,
                   (SELECT COUNT(*) FROM facturas f
                     WHERE f.proveedor_id = p.id AND f.sede_id = ' . $sede . ') facturas
              FROM proveedores p'
         . ($donde !== [] ? ' WHERE ' . implode(' AND ', $donde) : '')
         . ' ORDER BY p.nombre';
    $s = db()->prepare($sql);
    $s->execute($args);
    return $s->fetchAll();
}

/**
 * Une dos fichas del mismo proveedor. Todo lo del origen pasa al destino y el
 * origen desaparece; los datos que al destino le falten se completan con los
 * del origen, para no perder el RIF o el teléfono por el camino.
 */
function fusionar_proveedores(int $origen, int $destino): array
{
    if ($origen === $destino) {
        throw new RuntimeException('Son el mismo proveedor.');
    }
    $a = proveedor($origen);
    $b = proveedor($destino);
    if ($a === null || $b === null) {
        throw new RuntimeException('Alguno de los dos proveedores ya no existe.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('UPDATE movimientos SET proveedor_id = ? WHERE proveedor_id = ?');
        $s->execute([$destino, $origen]);
        $movidos = $s->rowCount();

        // Las facturas se mueven una a una: si el destino ya tiene ese número
        // en esa misma unidad, es la misma factura anotada dos veces y la del
        // origen sobra, con sus pagos apuntados a la que se queda.
        $facturas = $pdo->prepare('SELECT id, sede_id, numero FROM facturas WHERE proveedor_id = ?');
        $facturas->execute([$origen]);
        $mover  = $pdo->prepare('UPDATE facturas SET proveedor_id = ? WHERE id = ?');
        $buscar = $pdo->prepare('SELECT id FROM facturas WHERE proveedor_id = ? AND sede_id = ? AND numero = ?');
        $repunta = $pdo->prepare('UPDATE IGNORE pagos_factura SET factura_id = ? WHERE factura_id = ?');
        $borrar  = $pdo->prepare('DELETE FROM facturas WHERE id = ?');
        $movidasFac = 0;
        $unidas = 0;
        foreach ($facturas as $f) {
            $buscar->execute([$destino, (int) $f['sede_id'], $f['numero']]);
            $ya = $buscar->fetchColumn();
            if ($ya !== false) {
                $repunta->execute([(int) $ya, (int) $f['id']]);
                $borrar->execute([(int) $f['id']]);
                $unidas++;
                continue;
            }
            $mover->execute([$destino, (int) $f['id']]);
            $movidasFac++;
        }

        // Lo que al destino le falte se completa con lo del origen.
        $completa = [];
        foreach (['codigo', 'rif', 'rif_clave', 'nit', 'telefono', 'nota'] as $c) {
            if (trim((string) $b[$c]) === '' && trim((string) $a[$c]) !== '') {
                $completa[$c] = $a[$c];
            }
        }
        if ($completa !== []) {
            $sql = implode(', ', array_map(fn($c) => "$c = ?", array_keys($completa)));
            $pdo->prepare("UPDATE proveedores SET $sql WHERE id = ?")
                ->execute([...array_values($completa), $destino]);
        }
        $pdo->prepare('DELETE FROM proveedores WHERE id = ?')->execute([$origen]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return ['movidos' => $movidos, 'facturas' => $movidasFac, 'unidas' => $unidas];
}

/* ------------------------------------------------- Cargar desde un archivo */

/**
 * Rótulos con los que cada columna puede venir titulada.
 *
 * El archivo se reconoce por su estructura, nunca por su nombre: el listado lo
 * exporta el sistema de contabilidad y cada quien lo guarda como quiere. Lo que
 * sí vale es el título que el propio archivo imprime dentro, porque es
 * contenido. Es el mismo criterio de huella.php con los extractos del banco.
 */
const ROTULOS_PROVEEDOR = [
    'nombre'   => ['PROVEEDOR', 'PROVEEDORES', 'NOMBRE', 'RAZON SOCIAL', 'BENEFICIARIO', 'DESCRIPCION'],
    'codigo'   => ['CODIGO', 'COD', 'CLAVE'],
    'rif'      => ['RIF', 'R I F', 'CEDULA', 'C I', 'DOCUMENTO'],
    'nit'      => ['NIT', 'N I T'],
    'telefono' => ['TELEFONO', 'TELEFONOS', 'TELF', 'TLF', 'TEL', 'CELULAR'],
];

/**
 * Localiza la fila del encabezado y qué columna es cada cosa.
 *
 * Exige que en la misma fila estén el nombre y el RIF. Sin eso, la línea
 * «R.I.F..: J-502969349» de la cabecera del archivo —que es el RIF de la propia
 * empresa, no un rótulo de columna— pasaría por encabezado.
 */
function mapa_proveedores(array $muestra): ?array
{
    foreach ($muestra as $i => $fila) {
        if ($i > 30) {
            break;
        }
        $mapa = [];
        foreach ($fila as $col => $celda) {
            $r = norm((string) $celda);
            if ($r === '') {
                continue;
            }
            foreach (ROTULOS_PROVEEDOR as $campo => $posibles) {
                if (!isset($mapa[$campo]) && in_array($r, $posibles, true)) {
                    $mapa[$campo] = (int) $col;
                }
            }
        }
        if (isset($mapa['nombre'], $mapa['rif'])) {
            return ['fila' => (int) $i, 'mapa' => $mapa];
        }
    }
    return null;
}

/**
 * Lee el archivo y dice qué haría con cada fila, sin tocar la base.
 * El segundo paso, importar_proveedores(), recibe justo lo que aquí se devuelve.
 */
function analizar_proveedores(string $ruta, string $ext, ?array $mapaManual = null): array
{
    $filas = [];
    foreach (leer_filas($ruta, $ext) as $f) {
        $filas[] = $f;
        if (count($filas) > 20000) {
            break;              // un listado de proveedores no es un extracto
        }
    }
    if ($filas === []) {
        throw new RuntimeException('El archivo no tiene ninguna fila.');
    }

    $cab = $mapaManual !== null
        ? ['fila' => (int) ($mapaManual['fila'] ?? 0), 'mapa' => $mapaManual['mapa'] ?? []]
        : mapa_proveedores($filas);
    if ($cab === null || !isset($cab['mapa']['nombre'])) {
        return [
            'fila_cab' => null,
            'mapa'     => [],
            'muestra'  => array_slice($filas, 0, 12),
            'filas'    => [],
            'resumen'  => ['nuevos' => 0, 'existen' => 0, 'conflictos' => 0, 'repetidas' => 0, 'revisar' => 0, 'descartadas' => 0],
        ];
    }

    $mapa = $cab['mapa'];
    $dame = function (array $fila, string $campo) use ($mapa): string {
        if (!isset($mapa[$campo])) {
            return '';
        }
        return limpiar((string) ($fila[$mapa[$campo]] ?? ''));
    };

    $vistosNombre = [];
    $vistosRif    = [];
    $salida  = [];
    $resumen = ['nuevos' => 0, 'existen' => 0, 'conflictos' => 0, 'repetidas' => 0, 'revisar' => 0, 'descartadas' => 0];

    foreach (array_slice($filas, $cab['fila'] + 1) as $fila) {
        $datos = [
            'nombre'   => mb_substr($dame($fila, 'nombre'), 0, 160),
            'codigo'   => mb_substr($dame($fila, 'codigo'), 0, 40),
            'rif'      => mb_substr($dame($fila, 'rif'), 0, 20),
            'nit'      => mb_substr($dame($fila, 'nit'), 0, 20),
            'telefono' => mb_substr($dame($fila, 'telefono'), 0, 60),
        ];
        $datos['rif_clave'] = rif_normalizado($datos['rif']);

        if ($datos['nombre'] === '') {
            $salida[] = ['estado' => 'descartada', 'motivo' => 'la fila no trae nombre', 'datos' => $datos];
            $resumen['descartadas']++;
            continue;
        }

        // Dos filas con el mismo nombre son la misma ficha escrita dos veces y
        // la segunda sobra. Pero dos nombres distintos con el mismo RIF no son
        // lo mismo: en el listado real hay dos empresas que comparten un RIF
        // por error de tecleo, y descartar la segunda perdería un proveedor de
        // verdad. Esa entra, con su RIF a la vista para que lo corrijan, pero
        // sin usarlo como clave: plantarlo repetido estropearía las cargas
        // siguientes.
        $clave = norm($datos['nombre']);
        if (isset($vistosNombre[$clave])) {
            $salida[] = ['estado' => 'repetida', 'motivo' => 'ya venía antes en este mismo archivo', 'datos' => $datos];
            $resumen['repetidas']++;
            continue;
        }
        $vistosNombre[$clave] = true;

        $revisar = '';
        if ($datos['rif_clave'] !== '' && isset($vistosRif[$datos['rif_clave']])) {
            $revisar = 'comparte el RIF ' . $datos['rif'] . ' con «' . $vistosRif[$datos['rif_clave']]
                     . '», que viene antes en el archivo. Uno de los dos está mal.';
            $datos['rif_clave'] = '';
        } elseif ($datos['rif_clave'] !== '') {
            $vistosRif[$datos['rif_clave']] = $datos['nombre'];
        }

        $choque = proveedor_que_choca($datos['nombre'], $datos['rif_clave']);
        $porRif = $choque['rif'];
        $porNom = $choque['nombre'];

        // El RIF apunta a una ficha y el nombre a otra: son dos proveedores
        // distintos o uno repetido, y eso no lo decide el programa.
        if ($porRif !== null && $porNom !== null && (int) $porRif['id'] !== (int) $porNom['id']) {
            $salida[] = ['estado' => 'conflicto', 'datos' => $datos,
                'motivo' => 'el RIF es de «' . $porRif['nombre'] . '» y el nombre de «' . $porNom['nombre'] . '»'];
            $resumen['conflictos']++;
            continue;
        }

        $ya = $porRif ?? $porNom;
        if ($ya !== null) {
            $falta = [];
            foreach (['codigo', 'rif', 'nit', 'telefono'] as $c) {
                if ($datos[$c] !== '' && trim((string) $ya[$c]) === '') {
                    $falta[] = $c;
                }
            }
            $salida[] = ['estado' => 'existe', 'datos' => $datos,
                'proveedor_id' => (int) $ya['id'], 'nombre_actual' => (string) $ya['nombre'], 'falta' => $falta];
            $resumen['existen']++;
            continue;
        }

        if ($revisar !== '') {
            $salida[] = ['estado' => 'revisar', 'datos' => $datos, 'motivo' => $revisar];
            $resumen['revisar']++;
            continue;
        }
        $salida[] = ['estado' => 'nuevo', 'datos' => $datos];
        $resumen['nuevos']++;
    }

    return [
        'fila_cab' => $cab['fila'],
        'mapa'     => $mapa,
        'muestra'  => array_slice($filas, 0, 12),
        'filas'    => $salida,
        'resumen'  => $resumen,
    ];
}

/**
 * Mete en la base lo que analizar_proveedores() dio por bueno.
 *
 * A los que ya estaban solo se les completa lo que les falta: el archivo nunca
 * pisa un dato que alguien escribió a mano. Los conflictos no se tocan.
 */
function importar_proveedores(array $filas): array
{
    $pdo = db();
    $creados = 0;
    $completados = 0;

    $insertar = $pdo->prepare('INSERT INTO proveedores (nombre, clave, codigo, rif, rif_clave, nit, telefono)
                               VALUES (?, ?, ?, ?, ?, ?, ?)');
    $pdo->beginTransaction();
    try {
        foreach ($filas as $f) {
            $d = $f['datos'];
            if (in_array($f['estado'] ?? '', ['nuevo', 'revisar'], true)) {
                $insertar->execute([$d['nombre'], norm($d['nombre']), $d['codigo'],
                                    $d['rif'], $d['rif_clave'], $d['nit'], $d['telefono']]);
                $creados++;
                continue;
            }
            if (($f['estado'] ?? '') === 'existe' && ($f['falta'] ?? []) !== []) {
                $set  = implode(', ', array_map(fn($c) => "$c = ?", $f['falta']));
                $args = array_map(fn($c) => $d[$c], $f['falta']);
                if (in_array('rif', $f['falta'], true)) {
                    $set .= ', rif_clave = ?';
                    $args[] = $d['rif_clave'];
                }
                $args[] = (int) $f['proveedor_id'];
                $pdo->prepare("UPDATE proveedores SET $set WHERE id = ?")->execute($args);
                $completados++;
            }
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return ['creados' => $creados, 'completados' => $completados];
}

/* --------------------------------------------------- Pagos que se repiten */

/**
 * Cuántos días seguidos se vigilan. Al mismo proveedor se le paga el mismo
 * monto muchas veces al año —un alquiler, una cuota—; lo que no es normal es
 * que se repita dentro del mismo mes.
 */
const DIAS_PAGO_REPETIDO = 30;

/**
 * Otros pagos al mismo proveedor, por el mismo monto y cerca en el tiempo.
 *
 * Es la red que atrapa el pago duplicado cuando **no hay factura anotada**, que
 * es la mayoría de los casos: dos personas pagando desde dos bancos distintos
 * no se cruzan por ningún lado, y el monto es lo único que comparten.
 *
 * Mira todas las cuentas de la unidad a propósito: el caso que nos contaron es
 * justamente el de la misma factura pagada desde dos bancos.
 */
function pagos_repetidos(int $movId, int $provId, float $monto, string $fecha, int $tope = 5): array
{
    if ($provId <= 0 || $monto <= 0) {
        return [];
    }
    $s = db()->prepare('SELECT m.id, m.fecha, m.debito, m.justificacion, c.nombre cuenta, u.nombre autor
                          FROM movimientos m
                          JOIN cuentas c ON c.id = m.cuenta_id
                     LEFT JOIN usuarios u ON u.id = m.usuario_id
                         WHERE m.tipo = \'D\' AND m.proveedor_id = ? AND m.id <> ?
                           AND ABS(m.debito - ?) < 0.01
                           AND m.fecha BETWEEN DATE_SUB(?, INTERVAL ' . DIAS_PAGO_REPETIDO . ' DAY)
                                           AND DATE_ADD(?, INTERVAL ' . DIAS_PAGO_REPETIDO . ' DAY)
                           AND ' . filtro_sede() . '
                      ORDER BY m.fecha DESC, m.id DESC
                         LIMIT ' . max(1, $tope));
    $s->execute([$provId, $movId, $monto, $fecha, $fecha]);
    return $s->fetchAll();
}

/**
 * Todos los montos que se le repiten a un proveedor dentro de la ventana.
 * Con $provId se mira uno solo; sin él, la unidad entera, que es lo que
 * necesita el panel para avisar sin que nadie vaya a buscarlo.
 */
function montos_repetidos(?int $provId = null, int $tope = 20): array
{
    $extra = $provId !== null ? ' AND m.proveedor_id = ' . (int) $provId : '';
    return db()->query('SELECT m.proveedor_id, p.nombre proveedor, m.debito,
                               COUNT(*) veces, MIN(m.fecha) f1, MAX(m.fecha) f2,
                               COUNT(DISTINCT m.cuenta_id) cuentas,
                               GROUP_CONCAT(DISTINCT c.nombre ORDER BY c.nombre SEPARATOR ", ") lista_cuentas
                          FROM movimientos m
                          JOIN proveedores p ON p.id = m.proveedor_id
                          JOIN cuentas c ON c.id = m.cuenta_id
                         WHERE m.tipo = \'D\' AND m.debito > 0 AND ' . filtro_sede() . $extra . '
                      GROUP BY m.proveedor_id, m.debito
                        HAVING COUNT(*) > 1
                           AND DATEDIFF(MAX(m.fecha), MIN(m.fecha)) <= ' . DIAS_PAGO_REPETIDO . '
                      ORDER BY m.debito DESC
                         LIMIT ' . max(1, $tope))->fetchAll();
}

/* ------------------------------------------------------------- Las facturas */

/**
 * Cuánto le queda por cubrir a una factura.
 *
 * Retener no es dejar de pagar: lo retenido de IVA o de ISLR se le entrega al
 * SENIAT en nombre del proveedor, así que la factura queda saldada con lo
 * pagado más lo retenido. Una nota de crédito hace lo mismo. Si no se contara
 * así, toda factura con retención se quedaría eternamente «a medias».
 *
 * El saldo se calcula, no se guarda: un estado almacenado se desincroniza en
 * cuanto alguien anula un pago, y una suma no.
 */
function saldo_factura(array $f): array
{
    $monto    = (float) ($f['monto'] ?? 0);
    $retenido = (float) ($f['retencion_iva'] ?? 0) + (float) ($f['retencion_islr'] ?? 0)
              + (float) ($f['nota_credito'] ?? 0);
    $aplicado = (float) ($f['aplicado'] ?? 0);
    $pagable  = round($monto - $retenido, 2);
    $queda    = round($pagable - $aplicado, 2);

    if ($monto <= 0) {
        $estado = 'sin monto';
    } elseif ($queda < -0.01) {
        $estado = 'excedida';
    } elseif ($queda <= 0.01) {
        $estado = 'cubierta';
    } elseif ($aplicado > 0.01) {
        $estado = 'parcial';
    } else {
        $estado = 'abierta';
    }

    return ['monto' => $monto, 'retenido' => $retenido, 'pagable' => $pagable,
            'aplicado' => $aplicado, 'queda' => max(0.0, $queda), 'exceso' => max(0.0, -$queda),
            'estado' => $estado, 'moneda' => (string) ($f['moneda'] ?? 'VES')];
}

/** Cómo se escribe un monto en la moneda de su factura. */
function monto_moneda(float $n, string $moneda, int $dec = 2): string
{
    return ($moneda === 'USD' ? '$ ' : 'Bs ') . bs($n, $dec);
}

/** Las facturas de un proveedor en la unidad activa, con lo que llevan cubierto. */
function facturas_de_proveedor(int $proveedorId, bool $soloAbiertas = false): array
{
    $s = db()->prepare('SELECT f.*, COALESCE(SUM(pf.monto), 0) aplicado, COUNT(pf.id) pagos
                          FROM facturas f
                     LEFT JOIN pagos_factura pf ON pf.factura_id = f.id
                         WHERE f.proveedor_id = ? AND f.sede_id = ?
                      GROUP BY f.id
                      ORDER BY f.fecha IS NULL, f.fecha DESC, f.numero');
    $s->execute([$proveedorId, (int) sede_actual()]);
    $filas = $s->fetchAll();

    $salida = [];
    foreach ($filas as $f) {
        $f['saldo'] = saldo_factura($f);
        if ($soloAbiertas && in_array($f['saldo']['estado'], ['cubierta', 'excedida'], true)) {
            continue;
        }
        $salida[] = $f;
    }
    return $salida;
}

/**
 * La forma comparable del número de factura.
 *
 * Dos personas escriben la misma factura de tres maneras: «0001», «1» y
 * «F-0001». Para la base son tres facturas distintas, y ahí es donde se cuela
 * el pago repetido. Se quita todo lo que no sea letra o número y se le comen
 * los ceros de la izquierda a cada tramo de dígitos —«1000» sigue siendo mil,
 * que ese es el error fácil de cometer al programarlo—.
 */
function clave_factura(string $numero): string
{
    $s = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $numero) ?? '');
    return (string) preg_replace_callback('/\d+/', fn($m) => ltrim($m[0], '0') ?: '0', $s);
}

/** La factura de ese proveedor cuyo número es el mismo, escrito como se escriba. */
function factura_por_clave(int $proveedorId, string $numero, int $excepto = 0): ?array
{
    $clave = clave_factura($numero);
    if ($clave === '') {
        return null;
    }
    $s = db()->prepare('SELECT f.*, COALESCE(SUM(pf.monto), 0) aplicado
                          FROM facturas f
                     LEFT JOIN pagos_factura pf ON pf.factura_id = f.id
                         WHERE f.proveedor_id = ? AND f.sede_id = ? AND f.numero_clave = ? AND f.id <> ?
                      GROUP BY f.id LIMIT 1');
    $s->execute([$proveedorId, (int) sede_actual(), $clave, $excepto]);
    $f = $s->fetch();
    if (!$f) {
        return null;
    }
    $f['saldo'] = saldo_factura($f);
    return $f;
}

/**
 * En una frase: quién pagó esa factura y desde dónde. Es lo que hay que poner
 * en el aviso, porque «ya está cubierta» no le dice a nadie a quién preguntar.
 */
function quien_pago_factura(int $facturaId): string
{
    $s = db()->prepare('SELECT m.fecha, c.nombre cuenta, u.nombre autor
                          FROM pagos_factura pf
                          JOIN movimientos m ON m.id = pf.movimiento_id
                          JOIN cuentas c ON c.id = m.cuenta_id
                     LEFT JOIN usuarios u ON u.id = pf.usuario_id
                         WHERE pf.factura_id = ?
                      ORDER BY m.fecha DESC LIMIT 1');
    $s->execute([$facturaId]);
    $p = $s->fetch();
    if (!$p) {
        return '';
    }
    return 'La pagaron el ' . date('d/m/Y', strtotime((string) $p['fecha']))
         . ' desde ' . $p['cuenta'] . ($p['autor'] ? ', lo anotó ' . $p['autor'] : '') . '.';
}

/** Una factura de la unidad activa, con su saldo. Null si es de otra unidad. */
function factura_de_sede(int $id): ?array
{
    $s = db()->prepare('SELECT f.*, COALESCE(SUM(pf.monto), 0) aplicado
                          FROM facturas f
                     LEFT JOIN pagos_factura pf ON pf.factura_id = f.id
                         WHERE f.id = ? AND f.sede_id = ?
                      GROUP BY f.id');
    $s->execute([$id, (int) sede_actual()]);
    $f = $s->fetch();
    if (!$f) {
        return null;
    }
    $f['saldo'] = saldo_factura($f);
    return $f;
}

/** Los pagos que cubren una factura, con la fecha y la cuenta de cada uno. */
function pagos_de_factura(int $facturaId): array
{
    $s = db()->prepare('SELECT pf.*, m.fecha, m.referencia, m.concepto, c.nombre cuenta
                          FROM pagos_factura pf
                          JOIN movimientos m ON m.id = pf.movimiento_id
                          JOIN cuentas c ON c.id = m.cuenta_id
                         WHERE pf.factura_id = ?
                      ORDER BY m.fecha, m.id');
    $s->execute([$facturaId]);
    return $s->fetchAll();
}

/**
 * Alta o corrección de una factura. Devuelve su id.
 *
 * La factura es de la unidad que la debe, no del grupo: el proveedor se
 * comparte, la deuda no. Por eso sede_id entra en la clave y dos empresas
 * pueden tener la misma factura número 1 del mismo proveedor.
 */
function guardar_factura(array $d, int $id = 0): int
{
    $sede = (int) sede_actual();
    if ($sede <= 0) {
        throw new RuntimeException('Elige primero una unidad de negocio.');
    }
    $proveedorId = (int) ($d['proveedor_id'] ?? 0);
    if ($proveedorId <= 0 || proveedor($proveedorId) === null) {
        throw new RuntimeException('Hay que decir de qué proveedor es la factura.');
    }
    $numero = mb_substr(limpiar((string) ($d['numero'] ?? '')), 0, 60);
    if ($numero === '') {
        throw new RuntimeException('La factura necesita su número.');
    }
    // El mismo número escrito de otra manera es la misma factura. Se avisa aquí
    // y no al chocar la clave única, porque la clave única solo salta cuando se
    // teclea idéntico, que es justo lo que no pasa cuando son dos personas.
    $gemela = factura_por_clave($proveedorId, $numero, $id);
    if ($gemela !== null) {
        $quien = $gemela['saldo']['aplicado'] > 0.01 ? ' ' . quien_pago_factura((int) $gemela['id']) : '';
        throw new RuntimeException('Ya está anotada la factura «' . $gemela['numero'] . '» de ese proveedor, '
            . 'que es la misma que «' . $numero . '».' . $quien
            . ' Búsquela en la lista en vez de anotarla otra vez.');
    }
    $monto = a_monto((string) ($d['monto'] ?? '0'));
    if ($monto <= 0) {
        throw new RuntimeException('La factura necesita su monto: sin él no se puede saber cuánto queda por cubrir.');
    }
    $moneda = ($d['moneda'] ?? 'VES') === 'USD' ? 'USD' : 'VES';
    $campos = [
        'proveedor_id'   => $proveedorId,
        'sede_id'        => $sede,
        'numero'         => $numero,
        'numero_clave'   => clave_factura($numero),
        'numero_control' => mb_substr(limpiar((string) ($d['numero_control'] ?? '')), 0, 40),
        'fecha'          => a_fecha((string) ($d['fecha'] ?? '')),
        'monto'          => $monto,
        'moneda'         => $moneda,
        'retencion_iva'  => a_monto((string) ($d['retencion_iva'] ?? '0')),
        'retencion_islr' => a_monto((string) ($d['retencion_islr'] ?? '0')),
        'nota_credito'   => a_monto((string) ($d['nota_credito'] ?? '0')),
        'nota'           => mb_substr(limpiar((string) ($d['nota'] ?? '')), 0, 255),
        'origen'         => ($d['origen'] ?? 'manual') === 'archivo' ? 'archivo' : 'manual',
        'usuario_id'     => (int) ($_SESSION['uid'] ?? 0) ?: null,
    ];
    if ($campos['retencion_iva'] + $campos['retencion_islr'] + $campos['nota_credito'] > $monto + 0.01) {
        throw new RuntimeException('Lo retenido no puede ser mayor que la propia factura.');
    }

    $pdo = db();
    try {
        if ($id > 0) {
            if (factura_de_sede($id) === null) {
                throw new RuntimeException('Esa factura no es de esta unidad de negocio.');
            }
            $sql = implode(', ', array_map(fn($c) => "$c = ?", array_keys($campos)));
            $pdo->prepare("UPDATE facturas SET $sql WHERE id = ? AND sede_id = ?")
                ->execute([...array_values($campos), $id, $sede]);
            return $id;
        }
        $cols   = implode(', ', array_keys($campos));
        $marcas = implode(', ', array_fill(0, count($campos), '?'));
        $pdo->prepare("INSERT INTO facturas ($cols) VALUES ($marcas)")->execute(array_values($campos));
        return (int) $pdo->lastInsertId();
    } catch (PDOException $ex) {
        if ((int) $ex->errorInfo[1] === 1062) {
            throw new RuntimeException('Ya hay una factura número «' . $numero . '» de ese proveedor en esta unidad.');
        }
        throw $ex;
    }
}

/**
 * Reparte un pago entre las facturas que cubre.
 *
 * Los montos llegan en bolívares, que es lo que de verdad salió del banco y lo
 * que auditoría va a cuadrar. Si la factura está en dólares se convierte con la
 * tasa del BCV del día del movimiento —no la de hoy— y esa tasa se guarda en el
 * reparto: cuando mañana cambie, lo anotado ayer no se mueve.
 *
 * Rehace el reparto entero de ese movimiento, así que quitar una factura es
 * simplemente no mandarla.
 */
function repartir_pago(int $movimientoId, array $repartos): array
{
    $pdo = db();
    $s = $pdo->prepare('SELECT m.id, m.fecha, m.debito FROM movimientos m
                         WHERE m.id = ? AND ' . filtro_sede());
    $s->execute([$movimientoId]);
    $m = $s->fetch();
    if (!$m) {
        throw new RuntimeException('Ese pago no es de esta unidad de negocio.');
    }
    $debito = (float) $m['debito'];

    $lineas = [];
    $repartido = 0.0;
    foreach ($repartos as $facturaId => $montoBs) {
        $facturaId = (int) $facturaId;
        $montoBs = round(a_monto((string) $montoBs), 2);
        if ($facturaId <= 0 || $montoBs <= 0) {
            continue;
        }
        $f = factura_de_sede($facturaId);
        if ($f === null) {
            throw new RuntimeException('Una de las facturas no es de esta unidad de negocio.');
        }

        $tasa = null;
        $monto = $montoBs;
        if ($f['moneda'] === 'USD') {
            $tasa = tasa_de((string) $m['fecha']);
            if ($tasa === null || $tasa <= 0) {
                throw new RuntimeException('No hay tasa del BCV para el '
                    . date('d/m/Y', strtotime((string) $m['fecha']))
                    . ', y la factura ' . $f['numero'] . ' está en dólares. Actualiza las tasas en Ajustes.');
            }
            $monto = round($montoBs / $tasa, 2);
        }

        // Lo que esa factura ya tiene aplicado por OTROS movimientos: el de
        // este se va a reescribir entero.
        $otros = $pdo->prepare('SELECT COALESCE(SUM(monto), 0) FROM pagos_factura
                                 WHERE factura_id = ? AND movimiento_id <> ?');
        $otros->execute([$facturaId, $movimientoId]);
        $yaAplicado = (float) $otros->fetchColumn();

        if ($yaAplicado + $monto > $f['saldo']['pagable'] + 0.01) {
            $falta = round($f['saldo']['pagable'] - $yaAplicado, 2);
            // Decir quién la pagó y desde dónde: «ya está cubierta» no le dice a
            // nadie a quién preguntarle, y esto es exactamente el pago repetido
            // que se quiere atajar.
            throw new RuntimeException(
                $falta <= 0.01
                    ? 'La factura ' . $f['numero'] . ' ya está pagada por completo. '
                      . quien_pago_factura($facturaId)
                      . ' Si de verdad se pagó dos veces, hay que reclamarla, no anotarla otra vez.'
                    : 'A la factura ' . $f['numero'] . ' solo le faltan ' . monto_moneda($falta, $f['moneda'])
                      . ' y se le están cargando ' . monto_moneda($monto, $f['moneda']) . '. '
                      . quien_pago_factura($facturaId));
        }

        $lineas[] = ['factura_id' => $facturaId, 'monto' => $monto, 'monto_bs' => $montoBs, 'tasa' => $tasa];
        $repartido = round($repartido + $montoBs, 2);
    }

    if ($repartido > $debito + 0.01) {
        throw new RuntimeException('El pago fue de Bs ' . bs($debito) . ' y se está repartiendo Bs '
            . bs($repartido) . '. No se puede repartir más de lo que salió del banco.');
    }

    $uid = (int) ($_SESSION['uid'] ?? 0) ?: null;
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM pagos_factura WHERE movimiento_id = ?')->execute([$movimientoId]);
        $ins = $pdo->prepare('INSERT INTO pagos_factura (factura_id, movimiento_id, monto, monto_bs, tasa, usuario_id)
                              VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($lineas as $l) {
            $ins->execute([$l['factura_id'], $movimientoId, $l['monto'], $l['monto_bs'], $l['tasa'], $uid]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    return ['facturas' => count($lineas), 'repartido' => $repartido,
            'sin_repartir' => round($debito - $repartido, 2)];
}
