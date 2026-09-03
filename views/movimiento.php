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
                              m.origen = '', m.regla_id = NULL, m.actualizado_en = NOW()
                        WHERE m.id = ? AND " . filtro_sede())->execute([$id]);
        flash('ok', 'El movimiento volvió a la bandeja de pendientes.');
        redirigir('?r=movimiento&id=' . $id);
    }

    if ($accion === 'guardar') {
        $cat    = (int) ($_POST['categoria_id'] ?? 0) ?: null;
        $prov   = mb_substr(limpiar((string) ($_POST['proveedor'] ?? '')), 0, 160);
        $benef  = $prov;
        $justif = mb_substr(limpiar((string) ($_POST['justificacion'] ?? '')), 0, 1000);
        $pdo->prepare("UPDATE movimientos m
                          SET m.categoria_id = ?, m.beneficiario = ?, m.justificacion = ?,
                              m.estado = ?, m.origen = 'manual', m.regla_id = NULL, m.actualizado_en = NOW()
                        WHERE m.id = ? AND " . filtro_sede())
            ->execute([$cat, $benef, $justif, $cat ? 'conciliado' : 'pendiente', $id]);
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
                           r.nombre regla, i.archivo, i.creado_en cargado, t.tasa tasa_bcv
                      FROM movimientos m
                      JOIN cuentas c ON c.id = m.cuenta_id
                 LEFT JOIN tasas t ON t.fecha = m.fecha
                 LEFT JOIN categorias cat ON cat.id = m.categoria_id
                 LEFT JOIN reglas r ON r.id = m.regla_id
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
      <div class="dato"><dt>Tasa del BCV ese día</dt><dd><?= e(tasa_texto($m['tasa_bcv'])) ?></dd></div>
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
    </dl>

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
