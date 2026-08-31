<?php
/** Panel: estado del período y a dónde fue el dinero. */

$hoy = date('Y-m-d');
if (!isset($_GET['desde']) && !isset($_GET['hasta'])) {
    $r = db()->query("SELECT MIN(m.fecha) a, MAX(m.fecha) b FROM movimientos m
                       WHERE m.tipo='D' AND " . filtro_sede())->fetch();
    $_GET['desde'] = $r['b'] ? date('Y-m-01', strtotime($r['b'])) : date('Y-m-01');
    $_GET['hasta'] = $r['b'] ?: $hoy;
}
$f = filtros();
$res = resumen($f);
$reparto = por_categoria($f);
$pend = pendientes_total();

$totalDeb = (float) ($res['deb'] ?? 0);
$justificado = $totalDeb - (float) ($res['pend_bs'] ?? 0);
$pctJust = $totalDeb > 0 ? $justificado / $totalDeb * 100 : 0;

$cuentasLista = cuentas();
/* Solo las cargas de esta unidad de negocio, y solo las que dejaron
   movimientos vivos: si después se borró la cuenta y se recargó, la fila vieja
   sigue en el historial y mostrarla hace creer que algo entró dos veces. */
$ultimas = db()->query("SELECT i.*, c.nombre cuenta FROM importaciones i
                          JOIN cuentas c ON c.id = i.cuenta_id
                         WHERE c.sede_id = " . (int) sede_actual() . "
                           AND EXISTS (SELECT 1 FROM movimientos m WHERE m.importacion_id = i.id)
                      ORDER BY i.id DESC LIMIT 6")->fetchAll();

$acciones = '<a class="btn btn-oro" href="?r=carga">Cargar extractos</a>'
    . ($pend > 0 ? '<a class="btn" href="?r=pendientes">Justificar ' . $pend . '</a>' : '');

encabezado_html('Panel', 'panel',
    'Débitos del ' . e(date('d/m/Y', strtotime($f['desde'] ?: $hoy))) . ' al ' . e(date('d/m/Y', strtotime($f['hasta'] ?: $hoy))),
    $acciones);
?>

<form method="get" class="filtros" data-guia="fechas">
  <input type="hidden" name="r" value="panel">
  <div><label>Desde</label><input type="date" name="desde" value="<?= e($f['desde']) ?>" data-auto></div>
  <div><label>Hasta</label><input type="date" name="hasta" value="<?= e($f['hasta']) ?>" data-auto></div>
  <div><label>Cuenta</label>
    <select name="cuenta" data-auto>
      <option value="">Todas las cuentas</option>
      <?php foreach ($cuentasLista as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $f['cuenta'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
      <?php endforeach ?>
    </select>
  </div>
  <div class="filtros-pie"><button class="btn">Aplicar</button></div>
</form>

<div class="rejilla rejilla-4" style="margin-bottom:16px" data-guia="cifras">
  <div class="cifra salida">
    <div class="rotulo">Salidas del período</div>
    <div class="valor">Bs <?= bs($totalDeb, 0) ?></div>
    <div class="pie"><?= number_format((int) ($res['n'] ?? 0), 0, ',', '.') ?> débitos</div>
  </div>
  <div class="cifra">
    <div class="rotulo">Justificado</div>
    <div class="valor" style="color:var(--entrada)"><?= number_format($pctJust, 1, ',', '.') ?>%</div>
    <div class="pie">Bs <?= bs($justificado, 0) ?></div>
  </div>
  <div class="cifra <?= ($res['pend'] ?? 0) > 0 ? 'aviso' : '' ?>">
    <div class="rotulo">Por justificar</div>
    <div class="valor"><?= number_format((int) ($res['pend'] ?? 0), 0, ',', '.') ?></div>
    <div class="pie">Bs <?= bs((float) ($res['pend_bs'] ?? 0), 0) ?></div>
  </div>
  <?php $dispon = 0.0; foreach ($cuentasLista as $cc) { $dispon += saldo_cuenta((int) $cc['id'], $f['hasta'] ?: null)['saldo']; } ?>
  <div class="cifra">
    <div class="rotulo">Disponible en <?= count($cuentasLista) ?> cuentas</div>
    <div class="valor" style="color:<?= $dispon < 0 ? 'var(--salida)' : 'var(--texto)' ?>">Bs <?= bs($dispon, 0) ?></div>
    <div class="pie"><?= $ultimas ? 'Última carga ' . e(date('d/m H:i', strtotime($ultimas[0]['creado_en']))) : 'Sin cargas aún' ?></div>
  </div>
</div>

<div class="tarjeta" style="margin-bottom:16px" data-guia="cinta">
  <h2>A dónde fue el dinero</h2>
  <?php if ($totalDeb > 0): ?>
    <?php cinta_html($reparto, $totalDeb) ?>
  <?php else: ?>
    <div class="vacio"><b>Todavía no hay débitos en este período</b>Ajusta las fechas o carga un extracto.</div>
  <?php endif ?>
</div>

<div class="marco-tabla" style="margin-bottom:16px" data-guia="saldos">
  <div class="tabla-scroll">
    <table>
      <thead><tr><th>Cuenta</th><th class="der">Entradas Bs</th><th class="der">Salidas Bs</th>
        <th class="der">Neto del período</th><th class="der">Saldo Bs</th><th>Última carga</th></tr></thead>
      <tbody>
      <?php $saldoTotal = 0.0; foreach (saldos_por_cuenta($f) as $sc): $saldoTotal += $sc['saldo']['saldo']; ?>
        <tr>
          <td><a href="<?= e(url(['cuenta' => $sc['id'], 'p' => 1], 'movimientos')) ?>"><b><?= e($sc['nombre']) ?></b></a>
            <span class="origen" style="display:block"><?= e($sc['banco']) ?></span></td>
          <td class="der num" style="color:var(--entrada)"><?= bs((float) $sc['entradas'], 0) ?></td>
          <td class="der num" style="color:var(--salida)"><?= bs((float) $sc['salidas'], 0) ?></td>
          <td class="der num" style="color:<?= $sc['neto'] < 0 ? 'var(--salida)' : 'var(--entrada)' ?>">
            <?= ($sc['neto'] >= 0 ? '+' : '') . bs($sc['neto'], 0) ?></td>
          <td class="der num" style="color:<?= $sc['saldo']['saldo'] < 0 ? 'var(--salida)' : 'var(--texto)' ?>">
            <?= bs($sc['saldo']['saldo']) ?>
            <span class="origen" style="display:block"><?= $sc['saldo']['fuente'] === 'banco' ? 'según el banco'
                : ($sc['saldo']['fuente'] === 'calculado' ? 'calculado' : '⚠ falta saldo inicial') ?></span></td>
          <td class="fecha"><?= $sc['ultima'] ? e(date('d/m/Y', strtotime($sc['ultima']))) : '—' ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
      <tfoot><tr style="background:var(--panel-2);font-weight:600">
        <td>Disponible consolidado</td><td colspan="3"></td>
        <td class="der num" style="font-size:15px"><?= bs($saldoTotal) ?></td><td></td>
      </tr></tfoot>
    </table>
  </div>
</div>

<div class="rejilla rejilla-2">
  <div class="marco-tabla">
    <div class="tabla-scroll">
      <table>
        <thead><tr><th>Categoría</th><th class="der">Movs.</th><th class="der">Total Bs</th><th class="der">%</th></tr></thead>
        <tbody>
        <?php foreach ($reparto as $r): $pct = $totalDeb > 0 ? $r['total'] / $totalDeb * 100 : 0; ?>
          <tr>
            <td><a href="<?= e(url(['categoria' => $r['categoria_id'] ?? '0', 'p' => 1], 'movimientos')) ?>">
              <span class="etq"><i style="background:<?= e($r['color']) ?>"></i><?= e($r['categoria']) ?></span></a></td>
            <td class="der num"><?= number_format((int) $r['n'], 0, ',', '.') ?></td>
            <td class="der num"><?= bs((float) $r['total']) ?></td>
            <td class="der num" style="color:var(--mudo)"><?= number_format($pct, 1, ',', '.') ?></td>
          </tr>
        <?php endforeach ?>
        <?php if ($reparto === []): ?><tr><td colspan="4" class="vacio">Sin datos en el período.</td></tr><?php endif ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="marco-tabla">
    <div class="tabla-scroll">
      <table>
        <thead><tr><th>Última carga</th><th>Cuenta</th><th class="der">Nuevos</th><th class="der">Repetidos</th><th class="der">Auto</th></tr></thead>
        <tbody>
        <?php foreach ($ultimas as $u): ?>
          <tr>
            <td><span class="txt"><?= e(mb_strimwidth($u['archivo'], 0, 30, '…')) ?></span>
                <span class="origen"><?= e(date('d/m/Y H:i', strtotime($u['creado_en']))) ?></span></td>
            <td><?= e($u['cuenta'] ?? '—') ?></td>
            <td class="der num" style="color:var(--entrada)"><?= number_format((int) $u['insertados'], 0, ',', '.') ?></td>
            <td class="der num" style="color:var(--mudo)"><?= number_format((int) $u['duplicados'], 0, ',', '.') ?></td>
            <td class="der num" style="color:var(--oro)"><?= number_format((int) $u['auto_map'], 0, ',', '.') ?></td>
          </tr>
        <?php endforeach ?>
        <?php if ($ultimas === []): ?>
          <tr><td colspan="5" class="vacio"><b>Aún no has cargado extractos</b>Empieza por «Cargar extractos».</td></tr>
        <?php endif ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php pie_html(); ?>
