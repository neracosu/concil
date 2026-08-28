<?php
/**
 * Reporte agregado: la respuesta corta a «en qué se gastó».
 * El mismo corte que se ve es el que se exporta.
 */
exigir_login();

$cortes = [
    'categoria'    => ['Categoría',    'COALESCE(cat.nombre, "Sin clasificar")', 'COALESCE(cat.color, "#ffd166")', 'COALESCE(cat.id, 0)'],
    'beneficiario' => ['Beneficiario', 'NULLIF(m.beneficiario, "")',             '"#8fa6e0"',                      'NULL'],
    'cuenta'       => ['Cuenta',       'c.nombre',                               '"#6b82c4"',                      'c.id'],
    'mes'          => ['Mes',          'DATE_FORMAT(m.fecha, "%Y-%m")',          '"#d4a857"',                      'NULL'],
    'grupo'        => ['Grupo',        'COALESCE(cat.grupo, "Sin clasificar")',  '"#c83bff"',                      'NULL'],
];
$corte = isset($cortes[$_GET['corte'] ?? '']) ? $_GET['corte'] : 'categoria';
[$rotulo, $exprSel, $exprColor, $exprId] = $cortes[$corte];

$f = filtros();
[$w, $p] = where_filtros($f);
$monto = $f['tipo'] === 'C' ? 'm.credito' : 'm.debito';

$sql = "SELECT COALESCE($exprSel, '— sin indicar —') clave, $exprColor color, $exprId clave_id,
               COUNT(*) n, SUM($monto) total, MIN(m.fecha) f1, MAX(m.fecha) f2
          FROM movimientos m
          JOIN cuentas c ON c.id = m.cuenta_id
     LEFT JOIN categorias cat ON cat.id = m.categoria_id
         WHERE $w
      GROUP BY clave
      ORDER BY " . ($corte === 'mes' ? 'clave ASC' : 'total DESC');

$s = db()->prepare($sql);
$s->execute($p);
$filas = $s->fetchAll();
$granTotal = array_sum(array_column($filas, 'total'));

/* ---------- Exportación del corte ---------- */
$export = $_GET['export'] ?? '';
if ($export === 'csv' || $export === 'xlsx') {
    $cab = [$rotulo, 'Movimientos', 'Total Bs', '% del total', 'Desde', 'Hasta'];
    $datos = array_map(fn($r) => [
        $r['clave'],
        (int) $r['n'],
        (float) $r['total'],
        $granTotal > 0 ? round($r['total'] / $granTotal * 100, 2) : 0,
        date('d/m/Y', strtotime($r['f1'])),
        date('d/m/Y', strtotime($r['f2'])),
    ], $filas);
    $nombre = 'CONSIL_reporte_por_' . $corte . '_' . ($f['desde'] ?: 'inicio') . '_a_' . ($f['hasta'] ?: 'hoy');
    bitacora('exportacion', 'reporte ' . $corte);
    if ($export === 'csv') {
        exportar_csv($nombre, $cab, $datos);
    }
    exportar_xlsx($nombre, $cab, $datos, [1, 2, 3]);
}

$acciones = '<a class="btn" href="' . e(url(['export' => 'csv'])) . '">Exportar CSV</a>'
          . '<a class="btn btn-oro" href="' . e(url(['export' => 'xlsx'])) . '">Exportar Excel</a>';

encabezado_html('Reportes', 'reportes',
    'Salidas agrupadas por ' . mb_strtolower($rotulo) . ' · <b class="num">Bs ' . bs($granTotal) . '</b>',
    $acciones);
?>
<form method="get" class="filtros">
  <input type="hidden" name="r" value="reportes">
  <div data-guia="corte"><label>Agrupar por</label>
    <select name="corte" data-auto>
      <?php foreach ($cortes as $k => $v): ?>
        <option value="<?= $k ?>" <?= $corte === $k ? 'selected' : '' ?>><?= e($v[0]) ?></option>
      <?php endforeach ?>
    </select></div>
  <div><label>Desde</label><input type="date" name="desde" value="<?= e($f['desde']) ?>" data-auto></div>
  <div><label>Hasta</label><input type="date" name="hasta" value="<?= e($f['hasta']) ?>" data-auto></div>
  <div><label>Cuenta</label>
    <select name="cuenta" data-auto><option value="">Todas</option>
      <?php foreach (cuentas() as $c): ?><option value="<?= $c['id'] ?>" <?= $f['cuenta'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach ?>
    </select></div>
  <div><label>Tipo</label>
    <select name="tipo" data-auto>
      <option value="D" <?= $f['tipo'] === 'D' ? 'selected' : '' ?>>Débitos (salidas)</option>
      <option value="C" <?= $f['tipo'] === 'C' ? 'selected' : '' ?>>Créditos (ingresos)</option>
    </select></div>
  <div class="filtros-pie"><button class="btn">Aplicar</button><a class="btn" href="?r=reportes">Limpiar</a></div>
</form>

<div class="marco-tabla" data-guia="tabla">
  <div class="tabla-scroll">
    <table>
      <thead><tr><th><?= e($rotulo) ?></th><th class="der">Movs.</th><th class="der">Total Bs</th>
        <th class="der">%</th><th>Peso</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($filas as $r):
        $pct = $granTotal > 0 ? $r['total'] / $granTotal * 100 : 0;
        $etiqueta = $corte === 'mes' ? ucfirst(strftime_es($r['clave'])) : $r['clave']; ?>
        <tr>
          <td><span class="etq"><i style="background:<?= e($r['color']) ?>"></i><?= e($etiqueta) ?></span></td>
          <td class="der num"><?= number_format((int) $r['n'], 0, ',', '.') ?></td>
          <td class="der num" style="color:var(--salida)"><?= bs((float) $r['total']) ?></td>
          <td class="der num" style="color:var(--mudo)"><?= number_format($pct, 1, ',', '.') ?></td>
          <td style="width:180px">
            <div style="height:7px;background:var(--panel-2);border-radius:4px;overflow:hidden">
              <div style="height:100%;width:<?= number_format($pct, 2, '.', '') ?>%;background:<?= e($r['color']) ?>"></div>
            </div></td>
          <td style="text-align:right">
            <a class="btn btn-sm" href="<?= e(url(array_merge(
                ['p' => 1, 'export' => null, 'corte' => null],
                match ($corte) {
                    'categoria'    => ['categoria' => (string) $r['clave_id']],
                    'cuenta'       => ['cuenta' => (string) $r['clave_id']],
                    'beneficiario' => ['benef' => $r['clave'] === '— sin indicar —' ? null : $r['clave']],
                    'mes'          => ['desde' => $r['f1'], 'hasta' => $r['f2']],
                    default        => ['texto' => null],
                }
            ), 'movimientos')) ?>">Ver movimientos</a></td>
        </tr>
      <?php endforeach ?>
      <?php if ($filas === []): ?>
        <tr><td colspan="6" class="vacio"><b>Sin movimientos en este corte</b>Amplía el rango de fechas.</td></tr>
      <?php endif ?>
      </tbody>
      <?php if ($filas !== []): ?>
        <tfoot><tr style="background:var(--panel-2);font-weight:600">
          <td>Total</td>
          <td class="der num"><?= number_format(array_sum(array_column($filas, 'n')), 0, ',', '.') ?></td>
          <td class="der num">Bs <?= bs($granTotal) ?></td>
          <td class="der num">100,0</td><td></td><td></td>
        </tr></tfoot>
      <?php endif ?>
    </table>
  </div>
</div>
<?php pie_html(); ?>
