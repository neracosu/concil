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
        $factura = mb_substr(limpiar((string) ($_POST['factura'] ?? '')), 0, 60);
        $benef  = $prov;
        $justif = mb_substr(limpiar((string) ($_POST['justificacion'] ?? '')), 0, 1000);
        $pdo->prepare("UPDATE movimientos m
                          SET m.categoria_id = ?, m.beneficiario = ?, m.justificacion = ?,
                              m.estado = ?, m.origen = 'manual', m.regla_id = NULL, m.actualizado_en = NOW()
                        WHERE m.id = ? AND " . filtro_sede())
            ->execute([$cat, $benef, $justif, $cat ? 'conciliado' : 'pendiente', $id]);
        // Proveedor y factura se anotan aparte del UPDATE porque viven en sus
        // propias tablas: el movimiento solo guarda a quién se le pagó.
        $montoMov = (float) $pdo->query("SELECT debito FROM movimientos WHERE id = $id")->fetchColumn();
        anotar_proveedor($id, $prov, $factura, $montoMov);
        bitacora('correccion', "movimiento $id");
        flash('ok', 'Movimiento actualizado.');
        redirigir('?r=movimiento&id=' . $id);
    }
}

$s = $pdo->prepare("SELECT m.*, c.nombre cuenta, c.banco, cat.nombre categoria, cat.color,
                           r.nombre regla, i.archivo, i.creado_en cargado
                      FROM movimientos m
                      JOIN cuentas c ON c.id = m.cuenta_id
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

/* Lo ya anotado: el proveedor del movimiento y, si la hay, su factura. */
$facturas = facturas_de_movimiento($id);
$provActual = (string) ($m['proveedor_id']
    ? $pdo->query('SELECT nombre FROM proveedores WHERE id = ' . (int) $m['proveedor_id'])->fetchColumn()
    : $m['beneficiario']);
$facturaActual = (string) ($facturas[0]['numero'] ?? '');
encabezado_html('Movimiento', 'movimientos',
    e(date('d/m/Y', strtotime($m['fecha']))) . ' · ' . e($m['cuenta']),
    '<a class="btn" href="?r=movimientos">Volver a la lista</a>');
?>
<div class="rejilla rejilla-2" style="align-items:start">
  <div class="tarjeta">
    <h2>Lo que dice el banco</h2>
    <dl style="margin:0">
      <div class="dato"><dt>Fecha</dt><dd><?= e(date('d/m/Y', strtotime($m['fecha']))) ?></dd></div>
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
    <form method="post" class="pila">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
      <input type="hidden" name="accion" value="guardar">
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
               value="<?= e($provActual) ?>" placeholder="Proveedor, empleado, organismo…">
        <datalist id="listaProveedores">
          <?php foreach (nombres_proveedor() as $sp): ?><option value="<?= e($sp) ?>"><?php endforeach ?>
        </datalist></div>
      <div><label>Nº de factura <span style="text-transform:none;letter-spacing:0">(opcional)</span></label>
        <input type="text" name="factura" maxlength="60" value="<?= e($facturaActual) ?>"
               placeholder="El número que aparece en la factura">
        <?php if ($facturas !== []): ?>
          <p class="nota" style="margin:6px 0 0">Facturas anotadas:
            <?php foreach ($facturas as $fa): ?>
              <b><?= e($fa['numero']) ?></b> de <?= e($fa['proveedor']) ?><?= $fa['numero_control'] ? ' · control ' . e($fa['numero_control']) : '' ?>.
            <?php endforeach ?></p>
        <?php endif ?></div>
      <div><label>Justificación</label>
        <textarea name="justificacion" rows="5" maxlength="1000" placeholder="Para qué se usó este dinero."><?= e((string) $m['justificacion']) ?></textarea></div>
      <div class="acciones">
        <button class="btn btn-oro">Guardar</button>
        <?php if ($m['categoria_id']): ?>
          <button class="btn btn-peligro" name="accion" value="quitar"
                  data-confirmar="El movimiento volverá a la bandeja de pendientes. ¿Continuar?">Quitar clasificación</button>
        <?php endif ?>
      </div>
      <p style="color:var(--tenue);font-size:12.5px;margin:0">
        Al guardar aquí, el movimiento queda marcado como manual y ninguna regla lo volverá a tocar.
        <?php if ((int) $parecidos['n'] > 0): ?>
          Para resolver los <?= (int) $parecidos['n'] ?> parecidos de una vez, usa
          <a href="?r=pendientes" style="text-decoration:underline">Por justificar</a>.
        <?php endif ?>
      </p>
    </form>
  </div>
</div>
<?php pie_html(); ?>
