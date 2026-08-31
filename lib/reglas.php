<?php
/** Motor de mapeo automático: primera regla que coincide, por prioridad. */

function cargar_reglas(?int $cuentaId = null): array
{
    $sql = 'SELECT r.*, c.nombre AS categoria
              FROM reglas r
              JOIN categorias c ON c.id = r.categoria_id
             WHERE r.activa = 1
               AND (r.cuenta_id IS NULL' . ($cuentaId ? ' OR r.cuenta_id = ' . (int) $cuentaId : '') . ')
          ORDER BY r.prioridad ASC, r.id ASC';
    return db()->query($sql)->fetchAll();
}

/**
 * Devuelve la primera regla que coincide con el movimiento, o null.
 * $campos = ['concepto' => texto normalizado, 'nota' => ..., 'referencia' => ...]
 */
function casar_regla(array $reglas, array $campos): ?array
{
    foreach ($reglas as $r) {
        $texto = $campos[$r['campo']] ?? $campos['concepto'];
        if ($texto === '') {
            continue;
        }
        if (coincide((string) $r['tipo'], (string) $r['patron'], $texto)) {
            return $r;
        }
    }
    return null;
}

function coincide(string $tipo, string $patron, string $texto): bool
{
    if ($patron === '') {
        return false;
    }
    return match ($tipo) {
        'igual'    => $texto === $patron,
        'empieza'  => str_starts_with($texto, $patron),
        'termina'  => str_ends_with($texto, $patron),
        'regex'    => @preg_match('/' . str_replace('/', '\/', $patron) . '/u', $texto) === 1,
        default    => str_contains($texto, $patron),   // 'contiene'
    };
}

/** Valida un patrón antes de guardarlo. Devuelve mensaje de error o null. */
function validar_patron(string $tipo, string $patron): ?string
{
    if (trim($patron) === '') {
        return 'El patrón no puede estar vacío.';
    }
    if ($tipo === 'regex') {
        if (@preg_match('/' . str_replace('/', '\/', $patron) . '/u', '') === false) {
            return 'La expresión regular no es válida.';
        }
    }
    return null;
}

/**
 * Re-aplica las reglas sobre movimientos de débito que aún no fueron
 * clasificados a mano. Devuelve cuántos quedaron mapeados.
 */
function reaplicar_reglas(bool $incluirYaMapeados = false, ?int $cuentaId = null): int
{
    $pdo = db();
    $reglas = cargar_reglas($cuentaId);
    if ($reglas === []) {
        return 0;
    }
    $where = "tipo = 'D'";
    $where .= $incluirYaMapeados
        ? " AND origen <> 'manual'"
        : ' AND categoria_id IS NULL';
    if ($cuentaId) {
        $where .= ' AND cuenta_id = ' . (int) $cuentaId;
    }
    // Reaplicar solo alcanza a la sede activa: las reglas son comunes, pero
    // los movimientos de otra unidad de negocio no se tocan desde aquí.
    $where .= ' AND ' . filtro_sede('');

    $sel = $pdo->query("SELECT id, concepto, nota_banco, referencia FROM movimientos WHERE $where");
    $upd = $pdo->prepare("UPDATE movimientos
                             SET categoria_id = ?, beneficiario = ?, regla_id = ?,
                                 origen = 'regla', estado = 'conciliado', actualizado_en = NOW()
                           WHERE id = ?");
    $hit = $pdo->prepare('UPDATE reglas SET aciertos = aciertos + 1 WHERE id = ?');

    $n = 0;
    $porRegla = [];
    $pdo->beginTransaction();
    foreach ($sel as $m) {
        $r = casar_regla($reglas, [
            'concepto'   => norm((string) $m['concepto']),
            'nota'       => norm((string) $m['nota_banco']),
            'referencia' => norm((string) $m['referencia']),
        ]);
        if ($r === null) {
            continue;
        }
        $upd->execute([$r['categoria_id'], $r['beneficiario'], $r['id'], $m['id']]);
        $porRegla[$r['id']] = ($porRegla[$r['id']] ?? 0) + 1;
        $n++;
    }
    foreach (array_keys($porRegla) as $rid) {
        $hit->execute([$rid]);
    }
    $pdo->commit();
    return $n;
}
