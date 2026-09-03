<?php
/**
 * Ficha de un proveedor: sus datos, sus facturas en esta unidad y sus pagos.
 *
 * El proveedor es del grupo entero, pero lo que se ve aquí —facturas y pagos—
 * es solo de la unidad en la que se está trabajando: la deuda la tiene una
 * empresa concreta, no el consorcio.
 */
exigir_login();
$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$p = proveedor($id);

if ($p === null) {
    encabezado_html('Proveedor', 'proveedores');
    echo '<div class="marco-tabla"><div class="vacio"><b>Ese proveedor ya no existe</b>'
       . 'Puede haberse unido a otra ficha.</div></div>';
    pie_html();
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    if (($_POST['accion'] ?? '') === 'factura') {
        $facId = (int) ($_POST['factura_id'] ?? 0);
        try {
            guardar_factura([
                'proveedor_id'   => $id,
                'numero'         => (string) ($_POST['numero'] ?? ''),
                'numero_control' => (string) ($_POST['numero_control'] ?? ''),
                'fecha'          => (string) ($_POST['fecha'] ?? ''),
                'monto'          => (string) ($_POST['monto'] ?? '0'),
                'moneda'         => (string) ($_POST['moneda'] ?? 'VES'),
                'retencion_iva'  => (string) ($_POST['retencion_iva'] ?? '0'),
                'retencion_islr' => (string) ($_POST['retencion_islr'] ?? '0'),
                'nota_credito'   => (string) ($_POST['nota_credito'] ?? '0'),
                'nota'           => (string) ($_POST['nota'] ?? ''),
            ], $facId);
            bitacora($facId > 0 ? 'factura_editada' : 'factura_creada', '#' . ($facId ?: 'nueva') . " prov $id");
            flash('ok', $facId > 0 ? 'Factura actualizada.' : 'Factura anotada.');
        } catch (Throwable $ex) {
            flash('mal', $ex->getMessage());
        }
        redirigir('?r=proveedor&id=' . $id);
    }

    if (($_POST['accion'] ?? '') === 'borrar_factura') {
        $facId = (int) ($_POST['factura_id'] ?? 0);
        // El id llega del formulario: sin comprobar la sede se podría borrar la
        // factura de otra unidad de negocio.
        if (factura_de_sede($facId) !== null) {
            $pdo->prepare('DELETE FROM facturas WHERE id = ? AND sede_id = ?')
                ->execute([$facId, (int) sede_actual()]);
            bitacora('factura_borrada', "#$facId");
            flash('ok', 'Factura eliminada. Los pagos siguen ahí, solo dejan de estar atados a ella.');
        } else {
            flash('mal', 'Esa factura no es de esta unidad de negocio.');
        }
        redirigir('?r=proveedor&id=' . $id);
    }
}

$editar = null;
if (($fid = (int) ($_GET['factura'] ?? 0)) > 0) {
    $editar = factura_de_sede($fid);
    if ($editar !== null && (int) $editar['proveedor_id'] !== $id) {
        $editar = null;
    }
}

$facturas = facturas_de_proveedor($id);
$pagos = $pdo->prepare("SELECT m.*, c.nombre cuenta, cat.nombre categoria,
                               (SELECT COALESCE(SUM(pf.monto_bs), 0) FROM pagos_factura pf
                                 WHERE pf.movimiento_id = m.id) repartido
                          FROM movimientos m
                          JOIN cuentas c ON c.id = m.cuenta_id
                     LEFT JOIN categorias cat ON cat.id = m.categoria_id
                         WHERE m.proveedor_id = ? AND m.tipo = 'D' AND " . filtro_sede() . '
                      ORDER BY m.fecha DESC, m.id DESC
                         LIMIT 200');
$pagos->execute([$id]);
$pagos = $pagos->fetchAll();

$abiertas = array_filter($facturas, fn($f) => !in_array($f['saldo']['estado'], ['cubierta', 'excedida'], true));
$totalPagado = array_sum(array_map(fn($m) => (float) $m['debito'], $pagos));

encabezado_html($p['nombre'], 'proveedores',
    trim(($p['codigo'] !== '' ? e($p['codigo']) . ' · ' : '') . ($p['rif'] !== '' ? e($p['rif']) : 'sin RIF'))
      . ' · en ' . e(sede_nombre()),
    '<a class="btn" href="?r=proveedores&editar=' . $id . '">Editar la ficha</a>'
      . '<a class="btn" href="?r=movimientos&proveedor=' . $id . '&tipo=D">Ver sus pagos</a>');
?>
<div class="rejilla rejilla-4" style="margin-bottom:18px">
  <div class="cifra salida">
    <div class="rotulo">Se le ha pagado</div>
    <div class="valor">Bs <?= bs($totalPagado, 0) ?></div>
    <div class="pie"><?= count($pagos) ?> pago<?= count($pagos) === 1 ? '' : 's' ?></div>
  </div>
  <div class="cifra">
    <div class="rotulo">Facturas anotadas</div>
    <div class="valor"><?= count($facturas) ?></div>
  </div>
  <div class="cifra <?= count($abiertas) > 0 ? 'aviso' : '' ?>">
    <div class="rotulo">Sin cubrir</div>
    <div class="valor"><?= count($abiertas) ?></div>
  </div>
  <div class="cifra">
    <div class="rotulo">Teléfono</div>
    <div class="valor" style="font-size:15px"><?= e($p['telefono']) ?: '—' ?></div>
  </div>
</div>

<div class="rejilla" style="grid-template-columns:minmax(0,1fr) 330px;align-items:start">
  <div class="pila">
    <div class="marco-tabla">
      <?php if ($facturas === []): ?>
        <div class="vacio"><b>Todavía no tiene facturas anotadas</b>
          Se anotan al justificar un pago, o aquí al lado.</div>
      <?php else: ?>
      <div class="tabla-scroll">
        <table>
          <thead><tr>
            <th>Factura</th><th>Fecha</th><th class="der">Monto</th><th class="der">Retenido</th>
            <th class="der">Cubierto</th><th class="der">Queda</th><th>Estado</th><th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($facturas as $f): $sal = $f['saldo']; ?>
              <tr>
                <td><b class="num"><?= e($f['numero']) ?></b>
                  <?php if ($f['numero_control'] !== ''): ?>
                    <span style="display:block;color:var(--mudo);font-size:11.5px">control <?= e($f['numero_control']) ?></span>
                  <?php endif ?></td>
                <td class="fecha"><?= $f['fecha'] ? e(date('d/m/y', strtotime((string) $f['fecha']))) : '—' ?></td>
                <td class="der num"><?= e(monto_moneda($sal['monto'], $sal['moneda'])) ?></td>
                <td class="der num"><?= $sal['retenido'] > 0 ? e(bs($sal['retenido'])) : '—' ?></td>
                <td class="der num"><?= e(bs($sal['aplicado'])) ?></td>
                <td class="der num" style="color:var(--<?= $sal['queda'] > 0 ? 'pendiente' : 'entrada' ?>)">
                  <?= e(bs($sal['queda'])) ?></td>
                <td><span class="etq<?= in_array($sal['estado'], ['abierta', 'sin monto'], true) ? ' vacia' : '' ?>">
                  <?= e($sal['estado']) ?></span>
                  <?php if ((int) $f['pagos'] > 1): ?>
                    <span style="display:block;color:var(--mudo);font-size:11.5px"><?= (int) $f['pagos'] ?> pagos</span>
                  <?php endif ?></td>
                <td style="text-align:right;white-space:nowrap">
                  <a class="btn btn-sm" href="?r=proveedor&id=<?= $id ?>&factura=<?= $f['id'] ?>">Editar</a>
                </td>
              </tr>
              <?php $sus = pagos_de_factura((int) $f['id']); if ($sus !== []): ?>
                <tr><td colspan="8" style="padding-top:0;color:var(--mudo);font-size:12.5px">
                  <?php $t = [];
                  foreach ($sus as $o) {
                      $t[] = '<a href="?r=movimiento&id=' . (int) $o['movimiento_id'] . '" style="text-decoration:underline">'
                           . 'Bs ' . e(bs((float) $o['monto_bs'])) . ' el ' . e(date('d/m/y', strtotime((string) $o['fecha'])))
                           . '</a>';
                  }
                  echo 'cubierta con ' . implode(' · ', $t); ?>
                </td></tr>
              <?php endif ?>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <?php endif ?>
    </div>

    <?php if ($pagos !== []): ?>
      <div class="marco-tabla">
        <div class="tabla-scroll">
          <table>
            <thead><tr><th>Fecha</th><th>Concepto</th><th>Categoría</th>
              <th class="der">Pagado Bs</th><th class="der">Atado a facturas</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($pagos as $m): $falta = round((float) $m['debito'] - (float) $m['repartido'], 2); ?>
                <tr>
                  <td class="fecha"><?= e(date('d/m/y', strtotime((string) $m['fecha']))) ?></td>
                  <td class="concepto"><span class="txt"><?= e($m['concepto']) ?></span></td>
                  <td style="font-size:12.5px;color:var(--mudo)"><?= e((string) $m['categoria']) ?: '—' ?></td>
                  <td class="der num"><?= e(bs((float) $m['debito'])) ?></td>
                  <td class="der num" style="color:var(--<?= $falta > 0.01 ? 'mudo' : 'entrada' ?>)">
                    <?= e(bs((float) $m['repartido'])) ?></td>
                  <td style="text-align:right"><a class="btn btn-sm" href="?r=movimiento&id=<?= $m['id'] ?>">Abrir</a></td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif ?>
  </div>

  <div class="tarjeta">
    <h2><?= $editar ? 'Corregir la factura' : 'Anotar una factura' ?></h2>
    <p style="color:var(--mudo);font-size:13px;margin:0 0 14px">
      <?= $editar
          ? 'Lo que cambie aquí no toca los pagos que ya tenga atados.'
          : 'Normalmente las facturas se anotan al justificar el pago. Aquí se anotan las que aún no se han pagado, o se corrige lo que se escribió mal.' ?>
    </p>
    <form method="post" class="pila">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="accion" value="factura">
      <input type="hidden" name="factura_id" value="<?= (int) ($editar['id'] ?? 0) ?>">
      <div class="par">
        <div><label>Nº de factura</label>
          <input type="text" name="numero" maxlength="60" required value="<?= e($editar['numero'] ?? '') ?>"></div>
        <div><label>Nº de control</label>
          <input type="text" name="numero_control" maxlength="40" value="<?= e($editar['numero_control'] ?? '') ?>"></div>
      </div>
      <div class="par">
        <div><label>Fecha</label>
          <input type="date" name="fecha" value="<?= e((string) ($editar['fecha'] ?? '')) ?>"></div>
        <div><label>Moneda</label>
          <select name="moneda">
            <option value="VES" <?= ($editar['moneda'] ?? 'VES') === 'VES' ? 'selected' : '' ?>>Bolívares</option>
            <option value="USD" <?= ($editar['moneda'] ?? '') === 'USD' ? 'selected' : '' ?>>Dólares</option>
          </select></div>
      </div>
      <div><label>Monto total</label>
        <input type="text" name="monto" inputmode="decimal" required
               value="<?= $editar ? e(bs((float) $editar['monto'])) : '' ?>" placeholder="0,00"></div>
      <div class="par">
        <div><label>Retención de IVA</label>
          <input type="text" name="retencion_iva" inputmode="decimal"
                 value="<?= $editar && (float) $editar['retencion_iva'] > 0 ? e(bs((float) $editar['retencion_iva'])) : '' ?>" placeholder="0,00"></div>
        <div><label>Retención de ISLR</label>
          <input type="text" name="retencion_islr" inputmode="decimal"
                 value="<?= $editar && (float) $editar['retencion_islr'] > 0 ? e(bs((float) $editar['retencion_islr'])) : '' ?>" placeholder="0,00"></div>
      </div>
      <div><label>Nota de crédito</label>
        <input type="text" name="nota_credito" inputmode="decimal"
               value="<?= $editar && (float) $editar['nota_credito'] > 0 ? e(bs((float) $editar['nota_credito'])) : '' ?>" placeholder="0,00"></div>
      <div><label>Nota</label>
        <input type="text" name="nota" maxlength="255" value="<?= e($editar['nota'] ?? '') ?>"></div>
      <div class="acciones">
        <button class="btn btn-oro"><?= $editar ? 'Guardar' : 'Anotar factura' ?></button>
        <?php if ($editar): ?>
          <a class="btn" href="?r=proveedor&id=<?= $id ?>">Cancelar</a>
        <?php endif ?>
      </div>
    </form>
    <?php if ($editar): ?>
      <form method="post" style="margin-top:12px">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="accion" value="borrar_factura">
        <input type="hidden" name="factura_id" value="<?= (int) $editar['id'] ?>">
        <button class="btn btn-sm btn-peligro"
          data-confirmar="Se borrará la factura <?= e($editar['numero']) ?>. Los pagos no se borran, solo dejan de estar atados a ella. ¿Continuar?">Borrar esta factura</button>
      </form>
    <?php endif ?>
  </div>
</div>
<?php pie_html(); ?>
