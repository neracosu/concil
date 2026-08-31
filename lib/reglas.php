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
        // Las reglas de proporción no miran el texto de un movimiento sino su
        // monto frente al de su pareja, así que se aplican en otra pasada.
        if ($r['tipo'] === 'proporcion') {
            continue;
        }
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
    if ($tipo === 'proporcion') {
        $n = (float) str_replace(',', '.', $patron);
        if ($n <= 0 || $n >= 100) {
            return 'La comisión se escribe como porcentaje, por ejemplo 0,3 para el 0,3 %.';
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


/**
 * Comisiones que no se pueden reconocer por su texto.
 *
 * Algunos bancos cobran la comisión con el mismo concepto que la operación que
 * la origina: en Banesco, la comisión de un pago móvil se llama también
 * «Banesco Pago Movil», así que ninguna regla de texto puede separarlas. Lo que
 * sí las distingue es que comparten la referencia con el movimiento que las
 * causó y son un porcentaje fijo de él: 0,3 % en la mayoría de los bancos.
 *
 * Medido sobre julio de 2026: 162 de 163 parejas de Banesco están exactamente
 * en el 0,3 %. La única que se salía era un cargo de Movistar al 14 %, que la
 * tolerancia estrecha descarta sola.
 *
 * Se aplica como pasada aparte porque necesita ver la pareja, y no un
 * movimiento aislado como el resto del motor.
 */
function aplicar_comisiones(?int $cuentaId = null): int
{
    $pdo = db();
    $reglas = array_filter(cargar_reglas($cuentaId), fn($r) => $r['tipo'] === 'proporcion');
    if ($reglas === []) {
        return 0;
    }

    $where = "m.tipo = 'D' AND m.referencia <> '' AND " . filtro_sede();
    if ($cuentaId) {
        $where .= ' AND m.cuenta_id = ' . (int) $cuentaId;
    }

    // Referencias con más de un débito: solo ahí puede haber una pareja.
    $grupos = $pdo->query("SELECT m.cuenta_id, m.referencia,
                                  MIN(m.debito) menor, MAX(m.debito) mayor
                             FROM movimientos m
                            WHERE $where
                         GROUP BY m.cuenta_id, m.referencia
                           HAVING COUNT(*) > 1 AND MIN(m.debito) > 0 AND MAX(m.debito) > MIN(m.debito)")
                  ->fetchAll();

    $marcar = $pdo->prepare("UPDATE movimientos
                                SET categoria_id = ?, estado = 'conciliado', origen = 'regla',
                                    regla_id = ?, actualizado_en = NOW()
                              WHERE cuenta_id = ? AND referencia = ? AND debito = ?
                                AND tipo = 'D' AND categoria_id IS NULL");
    $sumar = $pdo->prepare('UPDATE reglas SET aciertos = aciertos + ? WHERE id = ?');

    $n = 0;
    $porRegla = [];
    foreach ($grupos as $g) {
        $proporcion = (float) $g['menor'] / (float) $g['mayor'] * 100;
        foreach ($reglas as $r) {
            $tasa = (float) str_replace(',', '.', (string) $r['patron']);
            // Tolerancia estrecha a propósito: con ±0,02 puntos, el 0,3 % de la
            // mayoría no se confunde con el 0,35 % de Bancrecer.
            if (abs($proporcion - $tasa) > 0.02) {
                continue;
            }
            $marcar->execute([$r['categoria_id'], $r['id'], $g['cuenta_id'], $g['referencia'], $g['menor']]);
            $hechos = $marcar->rowCount();
            if ($hechos > 0) {
                $n += $hechos;
                $porRegla[$r['id']] = ($porRegla[$r['id']] ?? 0) + $hechos;
            }
            break;
        }
    }
    foreach ($porRegla as $id => $c) {
        $sumar->execute([$c, $id]);
    }
    return $n;
}
