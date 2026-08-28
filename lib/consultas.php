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
        'categoria' => isset($g['categoria']) && $g['categoria'] !== '' ? (int) $g['categoria'] : null,
        'tipo'      => in_array($g['tipo'] ?? 'D', ['D', 'C', ''], true) ? ($g['tipo'] ?? 'D') : 'D',
        'estado'    => in_array($g['estado'] ?? '', ['pendiente', 'conciliado'], true) ? $g['estado'] : '',
        'texto'     => trim((string) ($g['texto'] ?? '')),
        'benef'     => trim((string) ($g['benef'] ?? '')),
        'min'       => $g['min'] ?? '',
        'max'       => $g['max'] ?? '',
        'orden'     => in_array($g['orden'] ?? '', ['fecha', 'monto', 'concepto'], true) ? $g['orden'] : 'fecha',
        'dir'       => ($g['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
    ];
}

/** Traduce los filtros a SQL. */
function where_filtros(array $f): array
{
    $w = ['1=1'];
    $p = [];
    if ($f['desde'] !== '')            { $w[] = 'm.fecha >= ?';        $p[] = $f['desde']; }
    if ($f['hasta'] !== '')            { $w[] = 'm.fecha <= ?';        $p[] = $f['hasta']; }
    if ($f['cuenta'] > 0)              { $w[] = 'm.cuenta_id = ?';     $p[] = $f['cuenta']; }
    if ($f['tipo'] !== '')             { $w[] = 'm.tipo = ?';          $p[] = $f['tipo']; }
    if ($f['benef'] !== '')            { $w[] = 'm.beneficiario = ?';  $p[] = $f['benef']; }
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

    $sql = "SELECT m.*, c.nombre AS cuenta, c.banco, cat.nombre AS categoria, cat.color
              FROM movimientos m
              JOIN cuentas c ON c.id = m.cuenta_id
         LEFT JOIN categorias cat ON cat.id = m.categoria_id
             WHERE $w
          ORDER BY " . orden_sql($f) . "
             LIMIT $porPagina OFFSET $off";
    $s = $pdo->prepare($sql);
    $s->execute($p);

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
    return db()->query('SELECT * FROM cuentas ORDER BY nombre')->fetchAll();
}

function categorias(): array
{
    return db()->query('SELECT * FROM categorias ORDER BY grupo, nombre')->fetchAll();
}

function pendientes_total(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM movimientos WHERE tipo='D' AND categoria_id IS NULL")->fetchColumn();
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
    $pdo = db();
    $tope = $hasta ? ' AND fecha <= ' . $pdo->quote($hasta) : '';

    $s = $pdo->query("SELECT saldo, fecha FROM movimientos
                       WHERE cuenta_id = $cuentaId AND saldo IS NOT NULL $tope
                    ORDER BY fecha DESC, id DESC LIMIT 1")->fetch();
    if ($s) {
        return ['saldo' => (float) $s['saldo'], 'fuente' => 'banco', 'fecha' => $s['fecha']];
    }

    $c = $pdo->query("SELECT saldo_inicial, saldo_fecha FROM cuentas WHERE id = $cuentaId")->fetch();
    $desde = $c['saldo_fecha'] ?? null;
    $filtroDesde = $desde ? ' AND fecha >= ' . $pdo->quote($desde) : '';

    $m = $pdo->query("SELECT COALESCE(SUM(credito),0) cre, COALESCE(SUM(debito),0) deb, MAX(fecha) f
                        FROM movimientos WHERE cuenta_id = $cuentaId $filtroDesde $tope")->fetch();

    return [
        'saldo'  => (float) ($c['saldo_inicial'] ?? 0) + (float) $m['cre'] - (float) $m['deb'],
        'fuente' => $desde ? 'calculado' : 'parcial',
        'fecha'  => $m['f'],
    ];
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
                      GROUP BY c.id ORDER BY c.nombre");
    $s->execute($p);
    $filas = $s->fetchAll();
    foreach ($filas as &$r) {
        $r['saldo'] = saldo_cuenta((int) $r['id'], $f['hasta'] ?: null);
        $r['neto'] = (float) $r['entradas'] - (float) $r['salidas'];
    }
    return $filas;
}
