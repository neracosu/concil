<?php
/** Detalle de un movimiento: corregir su clasificación o su justificación. */
exigir_login();
$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $id     = (int) ($_POST['id'] ?? 0);
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'quitar') {
        $pdo->prepare("UPDATE movimientos m SET m.categoria_id = NULL, m.beneficiario = '', m.estado = 'pendiente',
                              m.origen = '', m.regla_id = NULL, m.usuario_id = NULL, m.actualizado_en = NOW()
                        WHERE m.id = ? AND " . filtro_sede())->execute([$id]);
        flash('ok', 'El movimiento volvió a la bandeja de pendientes.');
        redirigir('?r=movimiento&id=' . $id);
    }

    if ($accion === 'atar' || $accion === 'soltar') {
        try {
            atar_traspaso($id, $accion === 'atar' ? (int) ($_POST['otro_id'] ?? 0) : null);
            bitacora('traspaso', $accion === 'atar'
                ? "movimiento $id con " . (int) ($_POST['otro_id'] ?? 0)
                : "movimiento $id suelto");
            flash('ok', $accion === 'atar'
                ? 'Quedaron unidos: es el mismo dinero pasando de una cuenta a la otra.'
                : 'Ya no están unidos.');
        } catch (Throwable $ex) {
            flash('mal', $ex->getMessage());
        }
        redirigir('?r=movimiento&id=' . $id);
    }

    if ($accion === 'tasa') {
        $r = corregir_tasa((string) ($_POST['fecha'] ?? ''), a_monto((string) ($_POST['tasa'] ?? '')));
        flash($r['ok'] ? 'ok' : 'mal', $r['mensaje']);
        redirigir('?r=movimiento&id=' . $id);
    }

    if ($accion === 'guardar') {
        $cat    = (int) ($_POST['categoria_id'] ?? 0) ?: null;
        $prov   = mb_substr(limpiar((string) ($_POST['proveedor'] ?? '')), 0, 160);
        $benef  = $prov;
        $justif = mb_substr(limpiar((string) ($_POST['justificacion'] ?? '')), 0, 1000);
        $pdo->prepare("UPDATE movimientos m
                          SET m.categoria_id = ?, m.beneficiario = ?, m.justificacion = ?,
                              m.estado = ?, m.origen = 'manual', m.regla_id = NULL,
                              m.usuario_id = ?, m.actualizado_en = NOW()
                        WHERE m.id = ? AND " . filtro_sede())
            ->execute([$cat, $benef, $justif, $cat ? 'conciliado' : 'pendiente', usuario_id_actual(), $id]);
        // Proveedor y facturas se anotan aparte del UPDATE porque viven en sus
        // propias tablas: el movimiento solo guarda a quién se le pagó.
        $provId = anotar_proveedor($id, $prov, '', 0.0);
        bitacora('correccion', "movimiento $id");
        try {
            $aviso = guardar_reparto($id, $provId, $_POST);
        } catch (Throwable $ex) {
            // La clasificación ya quedó guardada; lo que falló es el reparto, y
            // decirlo así evita que la persona repita todo el formulario.
            flash('mal', $ex->getMessage() . ' Lo demás sí se guardó.');
            redirigir('?r=movimiento&id=' . $id);
        }
        flash('ok', 'Movimiento actualizado.' . ($aviso !== '' ? ' ' . $aviso : ''));
        redirigir('?r=movimiento&id=' . $id);
    }
}

$s = $pdo->prepare("SELECT m.*, c.nombre cuenta, c.banco, cat.nombre categoria, cat.color,
                           r.nombre regla, i.archivo, i.creado_en cargado, t.tasa tasa_bcv,
                           t.origen tasa_origen, ut.nombre tasa_autor,
                           tr.fecha tr_fecha, tr.tipo tr_tipo, tr.concepto tr_concepto,
                           tr.debito tr_debito, tr.credito tr_credito, ctr.nombre tr_cuenta,
                           u.nombre autor
                      FROM movimientos m
                      JOIN cuentas c ON c.id = m.cuenta_id
                 LEFT JOIN tasas t ON t.fecha = m.fecha
                 LEFT JOIN categorias cat ON cat.id = m.categoria_id
                 LEFT JOIN reglas r ON r.id = m.regla_id
                 LEFT JOIN usuarios u ON u.id = m.usuario_id
                 LEFT JOIN usuarios ut ON ut.id = t.usuario_id
                 LEFT JOIN movimientos tr ON tr.id = m.traspaso_id
                 LEFT JOIN cuentas ctr ON ctr.id = tr.cuenta_id
                 LEFT JOIN importaciones i ON i.id = m.importacion_id
                     WHERE m.id = ? AND " . filtro_sede());
$s->execute([$id]);
$m = $s->fetch();

if (!$m) {
    encabezado_html('Movimiento', 'movimientos');
    echo '<div class="marco-tabla"><div class="vacio"><b>Ese movimiento ya no existe</b>'
       . 'Puede haberse eliminado junto con su cuenta.</div></div>';
    pie_html();
    return;
}

/* Otros movimientos con el mismo concepto: sirven para decidir con contexto. */
$sim = $pdo->prepare("SELECT COUNT(*) n, SUM(m.debito) t FROM movimientos m
                       WHERE m.tipo='D' AND UPPER(REGEXP_REPLACE(m.concepto,'[0-9]+','#')) =
                             UPPER(REGEXP_REPLACE(?,'[0-9]+','#')) AND m.id <> ?
                         AND " . filtro_sede());
$sim->execute([$m['concepto'], $id]);
$parecidos = $sim->fetch();

$cats = categorias();

/* A quién se le pagó, para arrancar el panel de facturas con sus datos. */
$provActual = (string) ($m['proveedor_id']
    ? $pdo->query('SELECT nombre FROM proveedores WHERE id = ' . (int) $m['proveedor_id'])->fetchColumn()
    : $m['beneficiario']);
encabezado_html('Movimiento', 'movimientos',
    e(date('d/m/Y', strtotime($m['fecha']))) . ' · ' . e($m['cuenta']),
    '<a class="btn" href="?r=movimientos">Volver a la lista</a>');
?>
<form method="post">
<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
<input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
<input type="hidden" name="accion" value="guardar">
<div class="rejilla rejilla-2" style="align-items:start">
  <div class="tarjeta">
    <h2>Lo que dice el banco</h2>
    <dl style="margin:0">
      <div class="dato"><dt>Fecha</dt><dd><?= e(date('d/m/Y', strtotime($m['fecha']))) ?></dd></div>
      <div class="dato"><dt>Tasa del BCV ese día</dt><dd><?= e(tasa_texto($m['tasa_bcv'])) ?>
        <?php if (($m['tasa_origen'] ?? '') === 'manual'): ?>
          <span class="origen" style="display:block">escrita a mano<?= $m['tasa_autor'] ? ' por ' . e($m['tasa_autor']) : '' ?></span>
        <?php endif ?></dd></div>
      <div class="dato"><dt>Cuenta</dt><dd class="texto"><?= e($m['cuenta']) ?><?= $m['banco'] ? ' · ' . e($m['banco']) : '' ?></dd></div>
      <div class="dato"><dt>Referencia</dt><dd><?= e($m['referencia']) ?: '—' ?></dd></div>
      <div class="dato"><dt>Concepto</dt><dd class="texto"><?= e($m['concepto']) ?></dd></div>
      <?php if ($m['nota_banco']): ?>
        <div class="dato"><dt>Nota en el archivo</dt><dd class="texto"><?= e($m['nota_banco']) ?></dd></div><?php endif ?>
      <div class="dato"><dt><?= $m['tipo'] === 'D' ? 'Débito' : 'Crédito' ?></dt>
        <dd style="color:var(--<?= $m['tipo'] === 'D' ? 'salida' : 'entrada' ?>);font-size:16px">
          Bs <?= bs((float) ($m['tipo'] === 'D' ? $m['debito'] : $m['credito'])) ?></dd></div>
      <?php if ($m['saldo'] !== null): ?>
        <div class="dato"><dt>Saldo tras el movimiento</dt><dd>Bs <?= bs((float) $m['saldo']) ?></dd></div><?php endif ?>
      <?php if ($m['archivo']): ?>
        <div class="dato"><dt>Vino del archivo</dt><dd class="texto" style="font-size:12px"><?= e($m['archivo']) ?><br>
          <span class="origen"><?= e(date('d/m/Y H:i', strtotime($m['cargado']))) ?></span></dd></div><?php endif ?>
      <?php if ($m['regla']): ?>
        <div class="dato"><dt>Clasificado por</dt><dd class="texto"><?= e($m['regla']) ?></dd></div><?php endif ?>
      <?php if ($m['autor']): ?>
        <div class="dato"><dt>Lo hizo</dt><dd class="texto"><?= e($m['autor']) ?>
          <span class="origen" style="display:block"><?= e(date('d/m/Y H:i', strtotime((string) $m['actualizado_en']))) ?></span></dd></div><?php endif ?>
    </dl>

    <?php if ($m['traspaso_id']): ?>
      <div class="aviso aviso-nota traspaso" data-guia="traspaso">
        <b>Es un traspaso entre cuentas suyas</b>
        Este <?= $m['tipo'] === 'D' ? 'pago salió de' : 'ingreso entró en' ?>
        <b><?= e($m['cuenta']) ?></b> y el otro lado
        <?= $m['tr_tipo'] === 'C' ? 'entró en' : 'salió de' ?> <b><?= e($m['tr_cuenta']) ?></b>
        el <?= e(date('d/m/Y', strtotime((string) $m['tr_fecha']))) ?>.
        El dinero no salió del grupo.
        <span class="origen"><?= e(mb_strimwidth((string) $m['tr_concepto'], 0, 80, '…')) ?></span>
        <div class="acciones" style="margin-top:10px">
          <a class="btn" href="?r=movimiento&amp;id=<?= (int) $m['traspaso_id'] ?>">Ver el otro lado</a>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="accion" value="soltar">
            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <button class="btn">No son el mismo dinero</button>
          </form>
        </div>
      </div>
    <?php else: $posibles = traspasos_posibles($m); if ($posibles !== []): ?>
      <details class="tasa-mano" data-guia="traspaso">
        <summary>¿Es un traspaso a otra cuenta suya?</summary>
        <p class="nota" style="margin:0 0 12px">
          <?= count($posibles) === 1 ? 'Hay un movimiento' : 'Hay ' . count($posibles) . ' movimientos' ?>
          del mismo monto en otra cuenta de esta unidad, por esas fechas. Si es el mismo dinero
          pasando de una cuenta a la otra, únalos: dejará de parecer un gasto y un ingreso.
        </p>
        <?php foreach ($posibles as $p): ?>
          <form method="post" class="traspaso-opcion">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="accion" value="atar">
            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <input type="hidden" name="otro_id" value="<?= (int) $p['id'] ?>">
            <span>
              <b><?= e($p['cuenta']) ?></b> · <?= e(date('d/m/Y', strtotime((string) $p['fecha']))) ?>
              · Bs <?= bs((float) $p['monto']) ?>
              <span class="origen"><?= e(mb_strimwidth((string) $p['concepto'], 0, 64, '…')) ?></span>
            </span>
            <button class="btn">Es este</button>
          </form>
        <?php endforeach ?>
      </details>
    <?php endif; endif ?>

    <details class="tasa-mano">
      <summary>La tasa de ese día no es la correcta</summary>
      <p class="nota" style="margin:0 0 12px">
        Escriba la que de verdad se usó el <b><?= e(date('d/m/Y', strtotime((string) $m['fecha']))) ?></b>.
        Vale para todas las operaciones de esa fecha, no solo para esta, y la
        próxima consulta al BCV ya no la cambia.
      </p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="accion" value="tasa">
        <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
        <input type="hidden" name="fecha" value="<?= e(substr((string) $m['fecha'], 0, 10)) ?>">
        <div class="par">
          <div><label>Bolívares por dólar</label>
            <input type="text" name="tasa" required inputmode="decimal"
                   placeholder="Ej.: 807,38" value="<?= e($m['tasa_bcv'] === null ? '' : tasa_texto($m['tasa_bcv'])) ?>"></div>
          <div style="display:flex;align-items:flex-end">
            <button class="btn btn-oro">Guardar esa tasa</button></div>
        </div>
      </form>
    </details>

    <?php if ((int) $parecidos['n'] > 0): ?>
      <div class="aviso aviso-nota" style="margin:16px 0 0">
        Hay <b><?= number_format((int) $parecidos['n'], 0, ',', '.') ?></b> movimientos más con este mismo concepto,
        por <b>Bs <?= bs((float) $parecidos['t'], 0) ?></b>.
        <a href="<?= e(url(['texto' => mb_substr($m['concepto'], 0, 40), 'tipo' => 'D', 'p' => 1], 'movimientos')) ?>"
           style="text-decoration:underline">Verlos todos</a>
      </div>
    <?php endif ?>
  </div>

  <div class="tarjeta">
    <h2>Cómo se justifica</h2>
    <div class="pila">
      <div><label>Categoría</label>
        <select name="categoria_id">
          <option value="">— Sin clasificar —</option>
          <?php $g = ''; foreach ($cats as $c):
            if ($c['grupo'] !== $g) { if ($g !== '') echo '</optgroup>'; $g = $c['grupo']; echo '<optgroup label="' . e($g) . '">'; } ?>
            <option value="<?= $c['id'] ?>" <?= (int) $m['categoria_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
          <?php endforeach; if ($g !== '') echo '</optgroup>'; ?>
        </select></div>
      <div><label>A quién se le pagó</label>
        <input type="text" name="proveedor" maxlength="160" list="listaProveedores"
               data-prov-de="<?= (int) $m['id'] ?>"
               value="<?= e($provActual) ?>" placeholder="Proveedor, empleado, organismo…">
        <datalist id="listaProveedores">
          <?php foreach (nombres_proveedor() as $sp): ?><option value="<?= e($sp) ?>"><?php endforeach ?>
        </datalist></div>
      <div><label>Justificación</label>
        <textarea name="justificacion" rows="5" maxlength="1000" placeholder="Para qué se usó este dinero."><?= e((string) $m['justificacion']) ?></textarea></div>
    </div>
  </div>
</div>

<div class="tarjeta" style="margin-top:18px" data-guia="facturas">
  <h2>De qué facturas era este pago</h2>
  <p style="color:var(--mudo);font-size:13.5px;margin:0 0 16px">
    Marque las facturas que cubre este pago y escriba cuánto va a cada una. Un pago puede
    cubrir varias facturas, y una factura puede irse cubriendo con varios pagos.
  </p>
  <?php panel_facturas($m, $m['proveedor_id'] ? (int) $m['proveedor_id'] : null) ?>
</div>

<div class="acciones" style="margin-top:18px">
  <button class="btn btn-oro">Guardar</button>
  <?php if ($m['categoria_id']): ?>
    <button class="btn btn-peligro" name="accion" value="quitar" formnovalidate
            data-confirmar="El movimiento volverá a la bandeja de pendientes. ¿Continuar?">Quitar clasificación</button>
  <?php endif ?>
</div>
<p style="color:var(--tenue);font-size:12.5px;margin:12px 0 0">
  Al guardar aquí, el movimiento queda marcado como manual y ninguna regla lo volverá a tocar.
  <?php if ((int) $parecidos['n'] > 0): ?>
    Para resolver los <?= (int) $parecidos['n'] ?> parecidos de una vez, usa
    <a href="?r=pendientes" style="text-decoration:underline">Por justificar</a>.
  <?php endif ?>
</p>
</form>
<?php pie_html(); ?>
