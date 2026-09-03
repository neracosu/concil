<?php
/**
 * El panel de facturas de un pago, compartido por la pantalla del movimiento y
 * por la bandeja de justificar.
 *
 * Aquí se ve todo junto lo que antes había que deducir: a quién se le pagó, qué
 * facturas suyas están sin cubrir, cuánto le falta a cada una, qué otros pagos
 * la cubren ya y cuánto de este pago queda sin repartir. Un pago puede cubrir
 * varias facturas y una factura llevarse varios pagos: las dos cosas se hacen
 * desde esta misma vista.
 */

/**
 * Las filas de facturas. Va aparte del panel entero porque es lo único que se
 * vuelve a pedir al servidor cuando se cambia de proveedor.
 */
function lista_facturas(array $mov, ?int $provId, string $att = ''): void
{
    $movId  = (int) $mov['id'];
    $debito = (float) $mov['debito'];

    if ($provId === null || $provId <= 0) {
        echo '<p class="reparto-vacio">Diga arriba a quién se le pagó y aquí aparecerán sus facturas.</p>';
        return;
    }

    // Las abiertas, más las que este pago ya cubre aunque estén saldadas: si no,
    // al volver a entrar desaparecerían y parecería que se perdió el dato.
    $abiertas = facturas_de_proveedor($provId, true);
    $puestas  = [];
    foreach (facturas_de_movimiento($movId) as $f) {
        $puestas[(int) $f['id']] = (float) $f['monto_bs'];
    }
    foreach (facturas_de_proveedor($provId) as $f) {
        if (isset($puestas[(int) $f['id']])
            && !in_array((int) $f['id'], array_map('intval', array_column($abiertas, 'id')), true)) {
            $abiertas[] = $f;
        }
    }

    if ($abiertas === []) {
        echo '<p class="reparto-vacio">Este proveedor no tiene facturas sin cubrir. '
           . 'Anote una aquí abajo si este pago corresponde a alguna.</p>';
        return;
    }

    $tasa = tasa_de((string) $mov['fecha']);
    foreach ($abiertas as $f) {
        $sal   = $f['saldo'];
        $id    = (int) $f['id'];
        $puesta = $puestas[$id] ?? null;

        // Lo que le falta, en bolívares: es en bolívares como se reparte el
        // pago, porque es lo que de verdad salió del banco.
        $faltaBs = $sal['queda'];
        $sinTasa = false;
        if ($sal['moneda'] === 'USD') {
            if ($tasa === null || $tasa <= 0) {
                $sinTasa = true;
                $faltaBs = 0.0;
            } else {
                $faltaBs = round($sal['queda'] * $tasa, 2);
            }
        }
        // Lo ya puesto por este pago vuelve a estar disponible para él.
        $topeBs = round($faltaBs + (float) ($puesta ?? 0), 2);

        $otros = array_values(array_filter(pagos_de_factura($id),
            fn($p) => (int) $p['movimiento_id'] !== $movId)); ?>
        <div class="reparto-fila<?= $puesta !== null ? ' puesta' : '' ?>">
          <label class="reparto-marca">
            <input type="checkbox" class="reparto-check" <?= $puesta !== null ? 'checked' : '' ?>
                   data-tope="<?= number_format($topeBs, 2, '.', '') ?>" aria-label="Usar esta factura">
          </label>
          <div class="reparto-datos">
            <b><?= e($f['numero']) ?></b>
            <?php if ($f['fecha']): ?><span class="origen"><?= e(date('d/m/Y', strtotime((string) $f['fecha']))) ?></span><?php endif ?>
            <span class="reparto-total"><?= e(monto_moneda($sal['monto'], $sal['moneda'])) ?></span>
            <?php if ($sal['retenido'] > 0): ?>
              <span class="origen">retenido <?= e(monto_moneda($sal['retenido'], $sal['moneda'])) ?></span>
            <?php endif ?>
            <span class="reparto-falta">
              <?= $sal['estado'] === 'cubierta' ? 'ya está cubierta'
                  : 'faltan ' . e(monto_moneda($sal['queda'], $sal['moneda'])) ?>
            </span>
            <?php if ($otros !== []): ?>
              <span class="reparto-otros">
                lleva
                <?php $trozos = [];
                foreach ($otros as $o) {
                    $trozos[] = 'Bs ' . bs((float) $o['monto_bs']) . ' del ' . date('d/m', strtotime((string) $o['fecha']));
                }
                echo e(implode(' · ', $trozos)); ?>
              </span>
            <?php endif ?>
            <?php if ($sinTasa): ?>
              <span class="reparto-otros" style="color:var(--pendiente)">
                está en dólares y no hay tasa del BCV para ese día
              </span>
            <?php endif ?>
          </div>
          <div class="reparto-monto">
            <input type="text" name="rep[<?= $id ?>]" inputmode="decimal" class="reparto-input"
                   value="<?= $puesta !== null ? e(bs($puesta)) : '' ?>"
                   placeholder="0,00" <?= $sinTasa ? 'readonly' : '' ?><?= $att ?>>
            <?php if ($sal['moneda'] === 'USD' && !$sinTasa): ?>
              <span class="origen">a <?= e(tasa_texto($tasa)) ?></span>
            <?php endif ?>
          </div>
        </div>
        <?php
    }
}

/**
 * El panel entero: las facturas del proveedor, el pie con lo que queda sin
 * repartir y el bloque para anotar una factura que todavía no está.
 *
 * $att lleva el form="…" cuando el panel va dentro de una tabla, donde no se
 * pueden anidar formularios y los campos se atan al suyo por atributo.
 */
function panel_facturas(array $mov, ?int $provId, string $idForm = '', bool $diferido = false): void
{
    $debito = (float) $mov['debito'];
    $movId  = (int) $mov['id'];
    $att = $idForm !== '' ? ' form="' . e($idForm) . '"' : '';

    // En la bandeja hay cuarenta filas y solo se abre una: dibujar los cuarenta
    // paneles cuadruplicaba el peso de la página. El panel se pide al abrir la
    // fila, que es lo único que la persona llega a ver.
    if ($diferido) { ?>
        <div class="reparto-aplazado" data-reparto-aplazado data-mov="<?= $movId ?>"
             data-form="<?= e($idForm) ?>" data-prov="<?= e((string) ($mov['proveedor_nombre'] ?? '')) ?>">
          <p class="reparto-vacio">Abriendo…</p>
        </div>
        <?php
        return;
    }

    $s = db()->prepare('SELECT COALESCE(SUM(monto_bs), 0) FROM pagos_factura WHERE movimiento_id = ?');
    $s->execute([$movId]);
    $repartido = (float) $s->fetchColumn();
    $resto = round($debito - $repartido, 2); ?>
    <div class="reparto" data-reparto data-debito="<?= number_format($debito, 2, '.', '') ?>"
         data-mov="<?= $movId ?>" data-form="<?= e($idForm) ?>">
      <div class="reparto-lista" data-reparto-lista>
        <?php lista_facturas($mov, $provId, $att) ?>
      </div>

      <div class="reparto-pie<?= abs($resto) < 0.005 && $repartido > 0 ? ' justo' : '' ?>">
        <span>De los <b>Bs <?= bs($debito) ?></b> de este pago</span>
        <span>repartido <b data-reparto-hecho>Bs <?= bs($repartido) ?></b></span>
        <span>sin repartir <b data-reparto-resto>Bs <?= bs($resto) ?></b></span>
      </div>

      <details class="reparto-nueva">
        <summary>Anotar una factura que no está en la lista</summary>
        <div class="par">
          <div><label>Nº de factura</label>
            <input type="text" name="nf_numero" maxlength="60" placeholder="El que aparece impreso"<?= $att ?>></div>
          <div><label>Nº de control</label>
            <input type="text" name="nf_control" maxlength="40" placeholder="El pre-impreso del SENIAT"<?= $att ?>></div>
        </div>
        <div class="par">
          <div><label>Fecha de la factura</label>
            <input type="date" name="nf_fecha"<?= $att ?>></div>
          <div><label>Moneda</label>
            <select name="nf_moneda"<?= $att ?>>
              <option value="VES">Bolívares</option>
              <option value="USD">Dólares</option>
            </select></div>
        </div>
        <div class="par">
          <div><label>Monto total de la factura</label>
            <input type="text" name="nf_monto" inputmode="decimal" placeholder="0,00"<?= $att ?>></div>
          <div><label>De este pago, cuánto va a esa factura</label>
            <input type="text" name="nf_aplicar" inputmode="decimal" class="reparto-input"
                   placeholder="0,00"<?= $att ?>></div>
        </div>
        <div class="par">
          <div><label>Retención de IVA <span style="text-transform:none;letter-spacing:0">(si hubo)</span></label>
            <input type="text" name="nf_ret_iva" inputmode="decimal" placeholder="0,00"<?= $att ?>></div>
          <div><label>Retención de ISLR <span style="text-transform:none;letter-spacing:0">(si hubo)</span></label>
            <input type="text" name="nf_ret_islr" inputmode="decimal" placeholder="0,00"<?= $att ?>></div>
        </div>
        <p class="nota" style="margin:10px 0 0">
          Lo que se le retiene al proveedor no se le paga a él, se le entrega al SENIAT en su nombre.
          Anotándolo aquí, la factura queda saldada aunque del banco haya salido menos.
        </p>
      </details>
    </div>
    <?php
}

/**
 * Lee del formulario el reparto y la factura nueva, y los guarda.
 * Devuelve el aviso que hay que enseñar, o cadena vacía si no había nada.
 */
function guardar_reparto(int $movimientoId, ?int $provId, array $post): string
{
    $repartos = [];
    foreach ((array) ($post['rep'] ?? []) as $facturaId => $monto) {
        if (trim((string) $monto) !== '') {
            $repartos[(int) $facturaId] = (string) $monto;
        }
    }

    // La factura nueva se crea antes de repartir, para que entre en el mismo
    // reparto y el tope del pago la tenga en cuenta.
    $nueva = trim((string) ($post['nf_numero'] ?? ''));
    if ($nueva !== '') {
        if ($provId === null || $provId <= 0) {
            return 'Para anotar una factura hay que decir primero a quién se le pagó.';
        }

        // Lo que no cuadre se detecta antes de crear la factura: si se creara
        // primero y el reparto fallara después, quedaría una factura suelta que
        // nadie pidió. repartir_pago() lo vuelve a comprobar de todos modos,
        // porque es quien manda.
        $suma = 0.0;
        foreach ($repartos as $x) {
            $suma += a_monto((string) $x);
        }
        $suma += a_monto((string) ($post['nf_aplicar'] ?? '0'));
        $debito = (float) db()->query('SELECT debito FROM movimientos WHERE id = ' . $movimientoId)
                              ->fetchColumn();
        if (round($suma, 2) > $debito + 0.01) {
            throw new RuntimeException('El pago fue de Bs ' . bs($debito) . ' y se está repartiendo Bs '
                . bs(round($suma, 2)) . '. No se puede repartir más de lo que salió del banco.');
        }

        $facId = guardar_factura([
            'proveedor_id'   => $provId,
            'numero'         => $nueva,
            'numero_control' => (string) ($post['nf_control'] ?? ''),
            'fecha'          => (string) ($post['nf_fecha'] ?? ''),
            'monto'          => (string) ($post['nf_monto'] ?? '0'),
            'moneda'         => (string) ($post['nf_moneda'] ?? 'VES'),
            'retencion_iva'  => (string) ($post['nf_ret_iva'] ?? '0'),
            'retencion_islr' => (string) ($post['nf_ret_islr'] ?? '0'),
        ]);
        $aplicar = trim((string) ($post['nf_aplicar'] ?? ''));
        if ($aplicar !== '') {
            $repartos[$facId] = $aplicar;
        }
    }

    // Sin nada marcado no se toca el reparto que ya hubiera: vaciarlo por
    // guardar la justificación borraría trabajo hecho sin querer.
    if ($repartos === [] && $nueva === '') {
        return '';
    }

    $r = repartir_pago($movimientoId, $repartos);
    if ($r['facturas'] === 0) {
        return 'Se quitaron las facturas de este pago.';
    }
    return $r['facturas'] . ' factura(s) atadas a este pago por Bs ' . bs($r['repartido'])
         . ($r['sin_repartir'] > 0.01 ? ', quedan Bs ' . bs($r['sin_repartir']) . ' sin repartir.' : '.');
}
