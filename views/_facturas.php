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
/**
 * El aviso de pago repetido. Se dibuja en la bandeja, en el detalle y en el
 * trozo que pide el navegador al cambiar de proveedor, porque los tres pasan
 * por aquí.
 */
function aviso_pagos_repetidos(array $mov, ?int $provId): void
{
    if ($provId === null || $provId <= 0) {
        return;
    }
    $otros = pagos_repetidos((int) $mov['id'], $provId, (float) $mov['debito'], (string) $mov['fecha']);
    if ($otros === []) {
        return;
    } ?>
    <div class="aviso aviso-mal repetido">
      <b>Ojo: a esta persona ya se le pagó lo mismo</b>
      <?= count($otros) === 1 ? 'Hay otro pago' : 'Hay otros ' . count($otros) . ' pagos' ?>
      del mismo monto, <b>Bs <?= bs((float) $mov['debito']) ?></b>, en menos de un mes.
      Compruebe que no sea el mismo dos veces antes de guardar.
      <ul>
        <?php foreach ($otros as $o): ?>
          <li><a href="?r=movimiento&amp;id=<?= (int) $o['id'] ?>"><?= e(date('d/m/Y', strtotime($o['fecha']))) ?></a>
            desde <b><?= e($o['cuenta']) ?></b><?= $o['autor'] ? ' · lo justificó ' . e($o['autor']) : '' ?>
            <?php if ($o['justificacion']): ?><span class="origen"><?= e(mb_strimwidth((string) $o['justificacion'], 0, 70, '…')) ?></span><?php endif ?>
          </li>
        <?php endforeach ?>
      </ul>
    </div>
    <?php
}

function lista_facturas(array $mov, ?int $provId, string $att = ''): void
{
    $movId  = (int) $mov['id'];
    $debito = (float) $mov['debito'];

    if ($provId === null || $provId <= 0) {
        echo '<p class="reparto-vacio">Diga arriba a quién se le pagó y aquí aparecerán sus facturas.</p>';
        return;
    }

    // Lo primero, antes que las facturas: si a ese proveedor ya se le fue el
    // mismo monto hace poco, hay que decirlo aquí, que es donde todavía se
    // puede evitar. Va antes de cualquier salida temprana a propósito: el pago
    // duplicado no necesita que existan facturas para ocurrir.
    aviso_pagos_repetidos($mov, $provId);

    $puestas = [];
    foreach (facturas_de_movimiento($movId) as $f) {
        $puestas[(int) $f['id']] = (float) $f['monto_bs'];
    }

    /* Las que hay que poder marcar: las que están sin cubrir, más las que este
       mismo pago ya cubre —si desaparecieran, el siguiente guardado borraría el
       reparto sin que nadie se entere—.

       Las demás **no se esconden**: se dibujan abajo, cerradas, diciendo quién
       las pagó y desde qué banco. Esconderlas es lo que hacía que la segunda
       persona creyera que la factura no estaba anotada, la anotara otra vez y
       la pagara otra vez desde su banco. */
    $abiertas = $cubiertas = [];
    foreach (facturas_de_proveedor($provId) as $f) {
        $cerrada = in_array($f['saldo']['estado'], ['cubierta', 'excedida'], true);
        if (!$cerrada || isset($puestas[(int) $f['id']])) {
            $abiertas[] = $f;
        } else {
            $cubiertas[] = $f;
        }
    }

    if ($abiertas === []) {
        echo '<p class="reparto-vacio">Este proveedor no tiene facturas sin cubrir. '
           . 'Anote una aquí abajo si este pago corresponde a alguna.</p>';
        facturas_ya_pagadas($cubiertas);
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

    facturas_ya_pagadas($cubiertas);
}

/**
 * Las facturas de esa persona que ya están pagadas.
 *
 * No se pueden marcar —ya no deben nada— pero tienen que **verse**: quien va a
 * pagar desde el segundo banco necesita enterarse de que alguien ya la pagó
 * desde el primero. Van cerradas para no estorbar a quien solo quiere trabajar.
 */
function facturas_ya_pagadas(array $cubiertas): void
{
    if ($cubiertas === []) {
        return;
    }
    $tope = 20;
    $lista = array_slice($cubiertas, 0, $tope); ?>
    <details class="pagadas" data-guia="pagadas">
      <summary>Ya pagadas: <?= count($cubiertas) ?></summary>
      <p class="nota" style="margin:0 0 10px">Estas ya están cubiertas. Se enseñan para que se vea
        que alguien las pagó, y desde qué banco: así no se pagan dos veces.</p>
      <ul>
        <?php foreach ($lista as $f): ?>
          <li><b><?= e($f['numero']) ?></b>
            · <?= e(monto_moneda($f['saldo']['monto'], $f['saldo']['moneda'])) ?>
            <?php if ($f['fecha']): ?> · del <?= e(date('d/m/Y', strtotime((string) $f['fecha']))) ?><?php endif ?>
            <span class="origen"><?= e(quien_pago_factura((int) $f['id'])) ?></span>
          </li>
        <?php endforeach ?>
      </ul>
      <?php if (count($cubiertas) > $tope): ?>
        <p class="origen" style="margin:8px 0 0">Se enseñan las <?= $tope ?> más recientes,
          de <?= count($cubiertas) ?>.</p>
      <?php endif ?>
    </details>
    <?php
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

      <details class="reparto-nueva" data-guia="factura-nueva">
        <summary>Anotar facturas que no están en la lista</summary>
        <div data-nuevas>
          <?php factura_nueva_campos($att) ?>
        </div>
        <div class="acciones" style="margin-top:6px">
          <button type="button" class="btn" data-nueva-otra>Anotar otra factura</button>
        </div>
        <p class="nota" style="margin:10px 0 0">
          Un mismo pago puede cubrir varias facturas: añada las que haga falta.
          Y lo que se le retiene al proveedor no se le paga a él, se le entrega al SENIAT en su
          nombre; anotándolo aquí, la factura queda saldada aunque del banco haya salido menos.
        </p>
      </details>
    </div>
    <?php
}

/**
 * Los campos de una factura nueva. Van en función aparte porque el navegador
 * copia este mismo bloque cada vez que se pide anotar otra, y los nombres
 * terminan en «[]» para que lleguen todas juntas.
 */
function factura_nueva_campos(string $att = ''): void
{ ?>
    <div class="nueva-factura" data-nueva>
      <div class="par">
        <div><label>Nº de factura</label>
          <input type="text" name="nf_numero[]" maxlength="60" placeholder="El que aparece impreso"<?= $att ?>></div>
        <div><label>Nº de control</label>
          <input type="text" name="nf_control[]" maxlength="40" placeholder="El pre-impreso del SENIAT"<?= $att ?>></div>
      </div>
      <div class="par">
        <div><label>Fecha de la factura</label>
          <input type="date" name="nf_fecha[]"<?= $att ?>></div>
        <div><label>Moneda</label>
          <select name="nf_moneda[]"<?= $att ?>>
            <option value="VES">Bolívares</option>
            <option value="USD">Dólares</option>
          </select></div>
      </div>
      <div class="par">
        <div><label>Monto total de la factura</label>
          <input type="text" name="nf_monto[]" inputmode="decimal" placeholder="0,00"<?= $att ?>></div>
        <div><label>De este pago, cuánto va a esa factura</label>
          <input type="text" name="nf_aplicar[]" inputmode="decimal" class="reparto-input"
                 placeholder="0,00"<?= $att ?>></div>
      </div>
      <div class="par">
        <div><label>Retención de IVA <span style="text-transform:none;letter-spacing:0">(si hubo)</span></label>
          <input type="text" name="nf_ret_iva[]" inputmode="decimal" placeholder="0,00"<?= $att ?>></div>
        <div><label>Retención de ISLR <span style="text-transform:none;letter-spacing:0">(si hubo)</span></label>
          <input type="text" name="nf_ret_islr[]" inputmode="decimal" placeholder="0,00"<?= $att ?>></div>
      </div>
    </div>
    <?php
}

/**
 * Lee del formulario el reparto y las facturas nuevas, y los guarda.
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

    // Las facturas nuevas se crean antes de repartir, para que entren en el
    // mismo reparto y el tope del pago las tenga en cuenta. Pueden venir
    // varias: un pago cubre a menudo más de una factura.
    $numeros = array_map('trim', array_map('strval', (array) ($post['nf_numero'] ?? [])));
    $conNumero = array_keys(array_filter($numeros, fn($n) => $n !== ''));
    $nuevas = 0;

    if ($conNumero !== []) {
        if ($provId === null || $provId <= 0) {
            return 'Para anotar una factura hay que decir primero a quién se le pagó.';
        }

        /** Un campo de la factura número $i, que puede no venir. */
        $campo = function (string $nombre, int $i, string $defecto = '') use ($post): string {
            $v = (array) ($post[$nombre] ?? []);
            return isset($v[$i]) ? (string) $v[$i] : $defecto;
        };

        // Lo que no cuadre se detecta antes de crear nada: si se crearan
        // primero y el reparto fallara después, quedarían facturas sueltas que
        // nadie pidió. repartir_pago() lo vuelve a comprobar de todos modos,
        // porque es quien manda.
        $suma = 0.0;
        foreach ($repartos as $x) {
            $suma += a_monto((string) $x);
        }
        foreach ($conNumero as $i) {
            $suma += a_monto($campo('nf_aplicar', $i, '0'));
        }
        // Con el filtro de sede, como todo id que llega de un formulario: sin
        // él se leía el monto de un pago de otra unidad de negocio y el aviso
        // de error lo enseñaba. `repartir_pago()` acaba rechazándolo, pero
        // para entonces la cifra ya se dijo.
        $mv = db()->prepare('SELECT m.debito FROM movimientos m WHERE m.id = ? AND ' . filtro_sede());
        $mv->execute([$movimientoId]);
        $debito = $mv->fetchColumn();
        if ($debito === false) {
            throw new RuntimeException('Ese pago no es de esta unidad de negocio.');
        }
        $debito = (float) $debito;
        if (round($suma, 2) > $debito + 0.01) {
            throw new RuntimeException('El pago fue de Bs ' . bs($debito) . ' y se está repartiendo Bs '
                . bs(round($suma, 2)) . '. No se puede repartir más de lo que salió del banco.');
        }

        foreach ($conNumero as $i) {
            $facId = guardar_factura([
                'proveedor_id'   => $provId,
                'numero'         => $numeros[$i],
                'numero_control' => $campo('nf_control', $i),
                'fecha'          => $campo('nf_fecha', $i),
                'monto'          => $campo('nf_monto', $i, '0'),
                'moneda'         => $campo('nf_moneda', $i, 'VES'),
                'retencion_iva'  => $campo('nf_ret_iva', $i, '0'),
                'retencion_islr' => $campo('nf_ret_islr', $i, '0'),
            ]);
            $nuevas++;
            $aplicar = trim($campo('nf_aplicar', $i));
            if ($aplicar !== '') {
                $repartos[$facId] = $aplicar;
            }
        }
    }

    // Sin nada marcado no se toca el reparto que ya hubiera: vaciarlo por
    // guardar la justificación borraría trabajo hecho sin querer.
    if ($repartos === [] && $nuevas === 0) {
        return '';
    }

    $r = repartir_pago($movimientoId, $repartos);
    if ($r['facturas'] === 0) {
        return 'Se quitaron las facturas de este pago.';
    }
    return $r['facturas'] . ' factura(s) atadas a este pago por Bs ' . bs($r['repartido'])
         . ($r['sin_repartir'] > 0.01 ? ', quedan Bs ' . bs($r['sin_repartir']) . ' sin repartir.' : '.');
}
