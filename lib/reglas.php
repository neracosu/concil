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
    // El autor se borra a propósito: si una regla vuelve a clasificar el
    // movimiento, la clasificación ya no es obra de nadie y dejar el nombre
    // anterior sería atribuirle a una persona algo que no decidió.
    $upd = $pdo->prepare("UPDATE movimientos
                             SET categoria_id = ?, beneficiario = ?, regla_id = ?,
                                 origen = 'regla', estado = 'conciliado',
                                 usuario_id = NULL, actualizado_en = NOW()
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
                                    regla_id = ?, usuario_id = NULL, actualizado_en = NOW()
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

/* ------------------------------------------- Traspasos entre cuentas propias */

/**
 * Cuántos días caben entre las dos caras de un traspaso. El dinero sale de una
 * cuenta y entra en la otra el mismo día casi siempre, pero un fin de semana
 * de por medio lo corre dos o tres.
 */
const DIAS_TRASPASO = 3;

/**
 * Busca la pareja de cada traspaso entre cuentas propias y las ata.
 *
 * Un traspaso son dos apuntes: el débito que sale de una cuenta y el crédito
 * que entra en la otra. Sueltos parecen un gasto y un ingreso, y ni el gasto
 * lo es ni el ingreso tampoco: el dinero no salió del grupo.
 *
 * **Solo ata lo que no admite discusión**: mismo monto, cuentas distintas de la
 * misma unidad, dentro de la ventana, y que no haya más de un candidato de cada
 * lado. Con dos candidatos iguales no se elige a la suerte —se quedan para que
 * lo diga una persona desde el detalle del movimiento—, porque un enlace
 * equivocado esconde un pago de verdad.
 */
function enlazar_traspasos(?int $cuentaId = null): int
{
    $pdo = db();
    $donde = 'm.traspaso_id IS NULL AND ' . filtro_sede();
    if ($cuentaId) {
        $donde .= ' AND (m.cuenta_id = ' . (int) $cuentaId . ' OR 1=1)';
    }
    $sueltos = $pdo->query("SELECT m.id, m.cuenta_id, m.fecha, m.tipo,
                                   CASE WHEN m.tipo = 'D' THEN m.debito ELSE m.credito END monto
                              FROM movimientos m
                             WHERE $donde
                               AND (m.debito > 0 OR m.credito > 0)")->fetchAll();

    // Por monto, que es lo único que las dos caras comparten seguro: el banco
    // que recibe escribe su propio concepto.
    $porMonto = [];
    foreach ($sueltos as $m) {
        $porMonto[(string) round((float) $m['monto'], 2)][$m['tipo']][] = $m;
    }

    $atar = $pdo->prepare('UPDATE movimientos SET traspaso_id = ?, actualizado_en = NOW() WHERE id = ?');
    $n = 0;
    foreach ($porMonto as $grupo) {
        $debitos  = $grupo['D'] ?? [];
        $creditos = $grupo['C'] ?? [];
        foreach ($debitos as $d) {
            $candidatos = [];
            foreach ($creditos as $c) {
                if ((int) $c['cuenta_id'] === (int) $d['cuenta_id']) {
                    continue;   // dentro de la misma cuenta no hay traspaso
                }
                $dias = abs((strtotime($c['fecha']) - strtotime($d['fecha'])) / 86400);
                if ($dias <= DIAS_TRASPASO) {
                    $candidatos[] = $c;
                }
            }
            // Uno y solo uno: con dos no se adivina.
            if (count($candidatos) !== 1) {
                continue;
            }
            $c = $candidatos[0];
            // Y que ese crédito tampoco tenga dos pretendientes.
            $suyos = 0;
            foreach ($debitos as $d2) {
                $dias = abs((strtotime($c['fecha']) - strtotime($d2['fecha'])) / 86400);
                if ((int) $d2['cuenta_id'] !== (int) $c['cuenta_id'] && $dias <= DIAS_TRASPASO) {
                    $suyos++;
                }
            }
            if ($suyos !== 1) {
                continue;
            }
            $atar->execute([(int) $c['id'], (int) $d['id']]);
            $atar->execute([(int) $d['id'], (int) $c['id']]);
            $creditos = array_values(array_filter($creditos, fn($x) => (int) $x['id'] !== (int) $c['id']));
            $n++;
        }
    }
    return $n;
}

/**
 * Los movimientos que podrían ser el otro lado de este, para que lo diga una
 * persona cuando el sistema no se atrevió a decidirlo solo.
 */
function traspasos_posibles(array $mov, int $tope = 6): array
{
    $contrario = $mov['tipo'] === 'D' ? 'C' : 'D';
    $monto = (float) ($mov['tipo'] === 'D' ? $mov['debito'] : $mov['credito']);
    if ($monto <= 0) {
        return [];
    }
    $campo = $contrario === 'D' ? 'm.debito' : 'm.credito';
    $s = db()->prepare("SELECT m.id, m.fecha, m.concepto, m.referencia, $campo monto, c.nombre cuenta
                          FROM movimientos m
                          JOIN cuentas c ON c.id = m.cuenta_id
                         WHERE m.tipo = ? AND m.traspaso_id IS NULL
                           AND m.cuenta_id <> ? AND ABS($campo - ?) < 0.01
                           AND ABS(DATEDIFF(m.fecha, ?)) <= " . (DIAS_TRASPASO * 3) . '
                           AND ' . filtro_sede() . "
                      ORDER BY ABS(DATEDIFF(m.fecha, ?)), m.id
                         LIMIT " . max(1, $tope));
    $s->execute([$contrario, (int) $mov['cuenta_id'], $monto, $mov['fecha'], $mov['fecha']]);
    return $s->fetchAll();
}

/** Ata dos movimientos como las dos caras de un traspaso, o los suelta. */
function atar_traspaso(int $unoId, ?int $otroId): void
{
    $pdo = db();
    $uno = $pdo->prepare('SELECT m.id, m.traspaso_id FROM movimientos m WHERE m.id = ? AND ' . filtro_sede());
    $uno->execute([$unoId]);
    $m = $uno->fetch();
    if (!$m) {
        throw new RuntimeException('Ese movimiento no es de esta unidad de negocio.');
    }
    $limpiar = $pdo->prepare('UPDATE movimientos SET traspaso_id = NULL WHERE id = ? OR traspaso_id = ?');
    $limpiar->execute([(int) $m['traspaso_id'] ?: 0, $unoId]);

    if ($otroId === null) {
        $pdo->prepare('UPDATE movimientos SET traspaso_id = NULL WHERE id = ?')->execute([$unoId]);
        return;
    }
    $otro = $pdo->prepare('SELECT m.id FROM movimientos m WHERE m.id = ? AND m.cuenta_id <>
                             (SELECT cuenta_id FROM movimientos WHERE id = ?) AND ' . filtro_sede());
    $otro->execute([$otroId, $unoId]);
    if (!$otro->fetch()) {
        throw new RuntimeException('El otro lado tiene que ser un movimiento de otra cuenta de esta unidad.');
    }
    $at = $pdo->prepare('UPDATE movimientos SET traspaso_id = ?, actualizado_en = NOW() WHERE id = ?');
    $at->execute([$otroId, $unoId]);
    $at->execute([$unoId, $otroId]);
}
