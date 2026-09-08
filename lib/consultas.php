<?php
/** Filtros, paginación y agregados. Todo el trabajo pesado ocurre en MySQL. */

/** Lee los filtros de la URL y los normaliza. Por defecto muestra débitos. */
function filtros(): array
{
    $g = $_GET;
    return [
        'desde'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $g['desde'] ?? '') ? $g['desde'] : '',
        'hasta'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $g['hasta'] ?? '') ? $g['hasta'] : '',
        'cuenta'    => (int) ($g['cuenta'] ?? 0),
        // Todas las cuentas de un banco a la vez. Hace falta desde que una
        // empresa puede tener cuatro cuentas en el mismo banco: mirarlas una
        // por una para saber cuánto se movió allí no es forma.
        'banco'     => trim((string) ($g['banco'] ?? '')),
        'categoria' => isset($g['categoria']) && $g['categoria'] !== '' ? (int) $g['categoria'] : null,
        'tipo'      => in_array($g['tipo'] ?? 'D', ['D', 'C', ''], true) ? ($g['tipo'] ?? 'D') : 'D',
        'estado'    => in_array($g['estado'] ?? '', ['pendiente', 'conciliado'], true) ? $g['estado'] : '',
        'texto'     => trim((string) ($g['texto'] ?? '')),
        'benef'     => trim((string) ($g['benef'] ?? '')),
        'proveedor' => (int) ($g['proveedor'] ?? 0),
        'min'       => $g['min'] ?? '',
        'max'       => $g['max'] ?? '',
        'orden'     => in_array($g['orden'] ?? '', ['fecha', 'monto', 'concepto'], true) ? $g['orden'] : 'fecha',
        'dir'       => ($g['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
    ];
}

/** Traduce los filtros a SQL. */
function where_filtros(array $f): array
{
    $w = ['1=1', filtro_sede()];      // nunca se sale de la sede activa
    $p = [];
    if ($f['desde'] !== '')            { $w[] = 'm.fecha >= ?';        $p[] = $f['desde']; }
    if ($f['hasta'] !== '')            { $w[] = 'm.fecha <= ?';        $p[] = $f['hasta']; }
    if ($f['cuenta'] > 0)              { $w[] = 'm.cuenta_id = ?';     $p[] = $f['cuenta']; }
    if (($f['banco'] ?? '') !== '') {
        $ids = array_column(array_filter(cuentas(), fn($c) => (string) $c['banco'] === $f['banco']), 'id');
        $w[] = $ids === [] ? '0' : 'm.cuenta_id IN (' . implode(',', array_map('intval', $ids)) . ')';
    }
    if ($f['tipo'] !== '')             { $w[] = 'm.tipo = ?';          $p[] = $f['tipo']; }
    if ($f['benef'] !== '')            { $w[] = 'm.beneficiario = ?';  $p[] = $f['benef']; }
    if (($f['proveedor'] ?? 0) > 0)    { $w[] = 'm.proveedor_id = ?'; $p[] = $f['proveedor']; }
    if ($f['categoria'] === 0)         { $w[] = 'm.categoria_id IS NULL'; }
    elseif ($f['categoria'] !== null)  { $w[] = 'm.categoria_id = ?';  $p[] = $f['categoria']; }
    if ($f['estado'] === 'pendiente')  { $w[] = 'm.categoria_id IS NULL'; }
    if ($f['estado'] === 'conciliado') { $w[] = 'm.categoria_id IS NOT NULL'; }
    if ($f['texto'] !== '') {
        $w[] = '(m.concepto LIKE ? OR m.nota_banco LIKE ? OR m.beneficiario LIKE ? OR m.referencia LIKE ? OR m.justificacion LIKE ?)';
        $like = '%' . $f['texto'] . '%';
        array_push($p, $like, $like, $like, $like, $like);
    }
    $monto = $f['tipo'] === 'C' ? 'm.credito' : 'm.debito';
    if (is_numeric($f['min'])) { $w[] = "$monto >= ?"; $p[] = (float) $f['min']; }
    if (is_numeric($f['max'])) { $w[] = "$monto <= ?"; $p[] = (float) $f['max']; }
    return [implode(' AND ', $w), $p];
}

function orden_sql(array $f): string
{
    $col = match ($f['orden']) {
        'monto'    => 'GREATEST(m.debito, m.credito)',
        'concepto' => 'm.concepto',
        default    => 'm.fecha',
    };
    $dir = strtoupper($f['dir']);
    return "$col $dir, m.id $dir";
}

const POR_PAGINA = 60;

function listar_movimientos(array $f, int $pagina, int $porPagina = POR_PAGINA): array
{
    [$w, $p] = where_filtros($f);
    $pdo = db();

    $tot = $pdo->prepare("SELECT COUNT(*) FROM movimientos m WHERE $w");
    $tot->execute($p);
    $total = (int) $tot->fetchColumn();

    $paginas = max(1, (int) ceil($total / $porPagina));
    $pagina = max(1, min($pagina, $paginas));
    $off = ($pagina - 1) * $porPagina;

    // Primero los ids de esta página, sin una sola unión. Con las cuatro uniones
    // dentro, MySQL juntaba los 300.000 movimientos que casan con el filtro y
    // los ordenaba enteros para enseñar sesenta: 3 segundos con medio millón de
    // filas. Así el orden se resuelve dentro del índice y se para al llegar a
    // los sesenta.
    $ids = $pdo->prepare("SELECT m.id FROM movimientos m WHERE $w
                        ORDER BY " . orden_sql($f) . "
                           LIMIT $porPagina OFFSET $off");
    $ids->execute($p);
    $pagIds = $ids->fetchAll(PDO::FETCH_COLUMN);
    if ($pagIds === []) {
        return ['filas' => [], 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    // La tasa se ata por la fecha del movimiento, que es la del extracto: un
    // archivo de julio cargado en septiembre se sigue leyendo con las de julio.
    $sql = "SELECT m.*, c.nombre AS cuenta, c.banco, cat.nombre AS categoria, cat.color, t.tasa AS tasa_bcv,
                   u.nombre AS autor
              FROM movimientos m
              JOIN cuentas c ON c.id = m.cuenta_id
         LEFT JOIN categorias cat ON cat.id = m.categoria_id
         LEFT JOIN usuarios u ON u.id = m.usuario_id
         LEFT JOIN tasas t ON t.fecha = m.fecha
             WHERE m.id IN (" . implode(',', array_map('intval', $pagIds)) . ")
          ORDER BY " . orden_sql($f);
    $s = $pdo->query($sql);

    return ['filas' => $s->fetchAll(), 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
}

/** Totales del conjunto filtrado (no solo de la página visible). */
function resumen(array $f): array
{
    [$w, $p] = where_filtros($f);
    $s = db()->prepare("SELECT COUNT(*) n,
            COALESCE(SUM(m.debito),0)  deb,
            COALESCE(SUM(m.credito),0) cre,
            SUM(m.tipo='D' AND m.categoria_id IS NULL) pend,
            COALESCE(SUM(CASE WHEN m.tipo='D' AND m.categoria_id IS NULL THEN m.debito ELSE 0 END),0) pend_bs,
            MIN(m.fecha) f1, MAX(m.fecha) f2
          FROM movimientos m WHERE $w");
    $s->execute($p);
    return $s->fetch() ?: [];
}

/** Reparto por categoría del conjunto filtrado. Alimenta la cinta de conciliación. */
function por_categoria(array $f, int $limite = 40): array
{
    [$w, $p] = where_filtros($f);
    $monto = $f['tipo'] === 'C' ? 'm.credito' : 'm.debito';
    $s = db()->prepare("SELECT COALESCE(cat.nombre,'Sin clasificar') categoria,
                               COALESCE(cat.color,'#ffd166') color,
                               cat.id AS categoria_id,
                               COUNT(*) n, SUM($monto) total
                          FROM movimientos m
                     LEFT JOIN categorias cat ON cat.id = m.categoria_id
                         WHERE $w
                      GROUP BY cat.id
                      ORDER BY total DESC
                         LIMIT $limite");
    $s->execute($p);
    return $s->fetchAll();
}

function cuentas(): array
{
    $id = sede_actual();
    if ($id === null) {
        return [];
    }
    $s = db()->prepare('SELECT * FROM cuentas WHERE sede_id = ? ORDER BY nombre');
    $s->execute([$id]);
    return $s->fetchAll();
}

/**
 * Cómo se nombra una cuenta cuando hay varias del mismo banco. El nombre solo
 * no basta: cuatro cuentas de Banco de Venezuela se llaman parecido y lo único
 * que las distingue es el número.
 */
function etiqueta_cuenta(array $c): string
{
    $n = preg_replace('/\D/', '', (string) ($c['numero'] ?? ''));
    return (string) $c['nombre']
         . ((string) ($c['banco'] ?? '') !== '' ? ' — ' . $c['banco'] : '')
         . (strlen($n) >= 4 ? ' ·' . substr($n, -4) : '');
}

/** Los bancos donde esta unidad de negocio tiene cuentas, sin repetir. */
function bancos_de_sede(): array
{
    $b = [];
    foreach (cuentas() as $c) {
        $n = trim((string) $c['banco']);
        if ($n !== '' && !in_array($n, $b, true)) {
            $b[] = $n;
        }
    }
    sort($b, SORT_LOCALE_STRING);
    return $b;
}

function categorias(): array
{
    return db()->query('SELECT * FROM categorias ORDER BY grupo, nombre')->fetchAll();
}

function pendientes_total(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM movimientos m
                              WHERE m.tipo='D' AND m.categoria_id IS NULL AND " . filtro_sede())->fetchColumn();
}

/** Reconstruye la URL actual cambiando algunos parámetros. */
function url(array $cambios = [], ?string $ruta = null): string
{
    $q = array_merge($_GET, $cambios);
    if ($ruta !== null) {
        $q['r'] = $ruta;
    }
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) {
            unset($q[$k]);
        }
    }
    return '?' . http_build_query($q);
}

/**
 * Saldo de una cuenta.
 * Si el banco entrega la columna de saldo (Bancamiga), manda ese dato: es el
 * saldo real informado. Si no, se calcula desde el saldo de arranque que se
 * cargue en la ficha de la cuenta.
 */
function saldo_cuenta(int $cuentaId, ?string $hasta = null): array
{
    return saldos_de_cuentas([$cuentaId], $hasta)[$cuentaId]
        ?? ['saldo' => 0.0, 'fuente' => 'parcial', 'fecha' => null];
}

/**
 * El saldo de varias cuentas de una vez, indexado por id de cuenta.
 *
 * Va agrupado a propósito. Preguntarlo cuenta por cuenta costaba nueve segundos
 * con medio millón de movimientos —medido el 08/09/2026—, y no por la suma: la
 * consulta que busca «el último saldo que informó el banco» recorría el índice
 * de fechas **entero** en las cuentas donde ninguna fila trae saldo, que son la
 * mayoría, porque solo Bancamiga y Bicentenario lo imprimen. Aquí esa búsqueda
 * ni se lanza si la pasada agrupada dice que esa cuenta no tiene ninguno.
 */
function saldos_de_cuentas(array $ids, ?string $hasta = null): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) {
        return [];
    }
    $pdo = db();
    $en   = implode(',', $ids);
    $tope = $hasta ? ' AND fecha <= ' . $pdo->quote($hasta) : '';

    // Una sola pasada, y sin tocar una fila de datos: todo lo que se pide está
    // dentro de idx_mov_saldos.
    $agr = $pdo->query("SELECT cuenta_id,
                               COALESCE(SUM(credito),0) cre, COALESCE(SUM(debito),0) deb,
                               MAX(fecha) f,
                               MAX(CASE WHEN saldo IS NOT NULL THEN fecha END) f_saldo
                          FROM movimientos
                         WHERE cuenta_id IN ($en) $tope
                      GROUP BY cuenta_id")->fetchAll(PDO::FETCH_ASSOC);

    $fichas = $pdo->query("SELECT id, saldo_inicial, saldo_fecha FROM cuentas WHERE id IN ($en)")
                  ->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    $r = [];
    foreach ($agr as $a) {
        $cid = (int) $a['cuenta_id'];
        // Si el banco informó saldo, ese manda: es el saldo real, no uno
        // reconstruido. Se busca solo en las cuentas que lo tienen.
        if ($a['f_saldo'] !== null) {
            $s = $pdo->prepare('SELECT saldo FROM movimientos
                                 WHERE cuenta_id = ? AND fecha = ? AND saldo IS NOT NULL
                              ORDER BY id DESC LIMIT 1');
            $s->execute([$cid, $a['f_saldo']]);
            $v = $s->fetchColumn();
            if ($v !== false) {
                $r[$cid] = ['saldo' => (float) $v, 'fuente' => 'banco', 'fecha' => $a['f_saldo']];
                continue;
            }
        }
        $ficha = $fichas[$cid] ?? [];
        $desde = $ficha['saldo_fecha'] ?? null;
        $cre = (float) $a['cre'];
        $deb = (float) $a['deb'];
        if ($desde !== null) {
            // El arranque vale desde su fecha: lo anterior no se suma.
            $p = $pdo->prepare("SELECT COALESCE(SUM(credito),0) cre, COALESCE(SUM(debito),0) deb
                                  FROM movimientos WHERE cuenta_id = ? AND fecha >= ?" . $tope);
            $p->execute([$cid, $desde]);
            $x = $p->fetch(PDO::FETCH_ASSOC);
            $cre = (float) $x['cre'];
            $deb = (float) $x['deb'];
        }
        $r[$cid] = [
            'saldo'  => (float) ($ficha['saldo_inicial'] ?? 0) + $cre - $deb,
            'fuente' => $desde ? 'calculado' : 'parcial',
            'fecha'  => $a['f'],
        ];
    }
    // Las cuentas sin un solo movimiento no salen del GROUP BY.
    foreach ($ids as $cid) {
        $r[$cid] ??= ['saldo' => (float) ($fichas[$cid]['saldo_inicial'] ?? 0),
                      'fuente' => 'parcial', 'fecha' => null];
    }
    return $r;
}

/** Entradas, salidas y saldo de cada cuenta en el período filtrado. */
function saldos_por_cuenta(array $f): array
{
    [$w, $p] = where_filtros(array_merge($f, ['tipo' => '', 'categoria' => null, 'estado' => '']));
    $s = db()->prepare("SELECT c.id, c.nombre, c.banco,
                               COALESCE(SUM(m.credito),0) entradas,
                               COALESCE(SUM(m.debito),0)  salidas,
                               COUNT(m.id) movs, MAX(m.fecha) ultima
                          FROM cuentas c
                     LEFT JOIN movimientos m ON m.cuenta_id = c.id AND $w
                         WHERE c.sede_id = " . (int) sede_actual() . "
                      GROUP BY c.id ORDER BY c.nombre");
    $s->execute($p);
    $filas = $s->fetchAll();
    $saldos = saldos_de_cuentas(array_column($filas, 'id'), $f['hasta'] ?: null);
    foreach ($filas as &$r) {
        $r['saldo'] = $saldos[(int) $r['id']] ?? ['saldo' => 0.0, 'fuente' => 'parcial', 'fecha' => null];
        $r['neto'] = (float) $r['entradas'] - (float) $r['salidas'];
    }
    return $filas;
}
