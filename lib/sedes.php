<?php
/**
 * Unidades de negocio del consorcio.
 *
 * Cada cuenta bancaria pertenece a una sede y los movimientos heredan la suya
 * de la cuenta, así que no hace falta repetir el dato en cada movimiento: basta
 * con filtrar por las cuentas de la sede activa.
 *
 * Las categorías y las reglas son comunes a todas las sedes, por decisión de
 * producto: así una regla aprendida en una unidad clasifica sola en las demás
 * y los informes de distintas unidades se pueden comparar.
 */

/** Todas las sedes, la activa primero en la lista del selector. */
function sedes(): array
{
    static $cache = null;
    return $cache ??= db()->query('SELECT * FROM sedes ORDER BY nombre')->fetchAll();
}

/**
 * Sede activa. Si la de la sesión ya no existe (la borraron desde otra
 * pestaña), cae a la primera para no dejar la pantalla en blanco.
 */
function sede_actual(): ?int
{
    $lista = sedes();
    if ($lista === []) {
        return null;
    }
    $ids = array_map('intval', array_column($lista, 'id'));
    $s = (int) ($_SESSION['sede'] ?? 0);
    if (in_array($s, $ids, true)) {
        return $s;
    }
    return count($lista) === 1 ? $ids[0] : null;
}

/** Nombre de la sede activa, para los títulos y los archivos exportados. */
function sede_nombre(): string
{
    $id = sede_actual();
    foreach (sedes() as $s) {
        if ((int) $s['id'] === $id) {
            return (string) $s['nombre'];
        }
    }
    return '';
}

function fijar_sede(int $id): void
{
    $_SESSION['sede'] = $id;
}

/** Ids de las cuentas de la sede activa. */
function cuentas_de_sede(): array
{
    static $cache = [];
    $id = sede_actual();
    if ($id === null) {
        return [];
    }
    if (!isset($cache[$id])) {
        $s = db()->prepare('SELECT id FROM cuentas WHERE sede_id = ?');
        $s->execute([$id]);
        $cache[$id] = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }
    return $cache[$id];
}

/**
 * Trozo de SQL que restringe una consulta a la sede activa.
 *
 * Devuelve '0' cuando la sede no tiene cuentas todavía: así la consulta no
 * devuelve nada en lugar de romperse con un IN () vacío, que no es SQL válido.
 */
function filtro_sede(string $alias = 'm'): string
{
    $ids = cuentas_de_sede();
    if ($ids === []) {
        return '0';
    }
    $col = $alias === '' ? 'cuenta_id' : "$alias.cuenta_id";
    return "$col IN (" . implode(',', $ids) . ')';
}

/** Crea una sede si no existe y devuelve su id. */
function sede_id(string $nombre): int
{
    $nombre = mb_substr(limpiar($nombre), 0, 120);
    if ($nombre === '') {
        return 0;
    }
    $pdo = db();
    $s = $pdo->prepare('SELECT id FROM sedes WHERE nombre = ?');
    $s->execute([$nombre]);
    $id = $s->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $pdo->prepare('INSERT INTO sedes (nombre) VALUES (?)')->execute([$nombre]);
    return (int) $pdo->lastInsertId();
}
