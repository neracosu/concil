<?php
/**
 * Consulta y exportación. Los filtros arman la vista y el mismo filtro
 * es el que se exporta: lo que ves es lo que baja.
 */
exigir_login();

$f = filtros();
$pagina = max(1, (int) ($_GET['p'] ?? 1));

/* ---------- Exportación ---------- */
$export = $_GET['export'] ?? '';
if ($export === 'csv' || $export === 'xlsx') {
    [$w, $p] = where_filtros($f);
    $sql = "SELECT m.fecha, c.nombre cuenta, c.banco, m.referencia, m.concepto, m.nota_banco,
                   m.debito, m.credito, m.saldo,
                   COALESCE(cat.nombre,'Sin clasificar') categoria, COALESCE(cat.grupo,'') grupo,
                   m.beneficiario, m.justificacion, m.origen
              FROM movimientos m
              JOIN cuentas c ON c.id = m.cuenta_id
         LEFT JOIN categorias cat ON cat.id = m.categoria_id
             WHERE $w
          ORDER BY " . orden_sql($f);
    $s = db()->prepare($sql);
    $s->execute($p);

    $cab = ['Fecha', 'Cuenta', 'Banco', 'Referencia', 'Concepto del banco', 'Nota del banco',
            'Débito', 'Crédito', 'Saldo', 'Categoría', 'Grupo', 'Beneficiario', 'Justificación', 'Clasificado por'];

    $filas = (function () use ($s) {
        while ($r = $s->fetch()) {
            yield [
                date('d/m/Y', strtotime($r['fecha'])), $r['cuenta'], $r['banco'], $r['referencia'],
                $r['concepto'], $r['nota_banco'],
                (float) $r['debito'], (float) $r['credito'], $r['saldo'] === null ? '' : (float) $r['saldo'],
                $r['categoria'], $r['grupo'], $r['beneficiario'], $r['justificacion'],
                match ($r['origen']) { 'regla' => 'Regla automática', 'manual' => 'Manual', default => 'Sin clasificar' },
            ];
        }
    })();

    $nombre = 'movimientos_' . ($f['desde'] ?: 'inicio') . '_a_' . ($f['hasta'] ?: 'hoy');
    bitacora('exportacion', strtoupper($export) . ' · ' . $nombre);
    if ($export === 'csv') {
        exportar_csv($nombre, $cab, $filas);
    }
    exportar_xlsx($nombre, $cab, $filas, [6, 7, 8]);
}

$lista = listar_movimientos($f, $pagina);
$res = resumen($f);
$reparto = por_categoria($f, 14);
$cats = categorias();
$cuentasLista = cuentas();

$totalCol = $f['tipo'] === 'C' ? (float) ($res['cre'] ?? 0) : (float) ($res['deb'] ?? 0);
$maxMonto = 0.0;
foreach ($lista['filas'] as $m) {
    $maxMonto = max($maxMonto, (float) ($f['tipo'] === 'C' ? $m['credito'] : $m['debito']));
}

$acciones = '<span data-guia="exportar"><a class="btn" href="' . e(url(['export' => 'csv'])) . '">Exportar CSV</a>'
          . '<a class="btn btn-oro" href="' . e(url(['export' => 'xlsx'])) . '">Exportar Excel</a></span>';

encabezado_html('Movimientos', 'movimientos',
    number_format((int) ($res['n'] ?? 0), 0, ',', '.') . ' movimientos · <b class="num">Bs ' . bs($totalCol) . '</b>'
    . ' · exportas exactamente lo que estás viendo', $acciones);

/** Selector de categoría con grupos. */
function opciones_categoria(array $cats, ?int $sel): void
{
    echo '<option value="">Todas</option>';
    echo '<option value="0"' . ($sel === 0 ? ' selected' : '') . '>— Sin clasificar —</option>';
    $g = '';
    foreach ($cats as $c) {
        if ($c['grupo'] !== $g) {
            if ($g !== '') echo '</optgroup>';
            $g = $c['grupo'];
            echo '<optgroup label="' . e($g) . '">';
        }
        echo '<option value="' . $c['id'] . '"' . ($sel === (int) $c['id'] ? ' selected' : '') . '>' . e($c['nombre']) . '</option>';
    }
    if ($g !== '') echo '</optgroup>';
}
?>

<form method="get" class="filtros" data-guia="filtros">
  <input type="hidden" name="r" value="movimientos">
  <div class="ancho"><label>Buscar</label>
    <input type="text" name="texto" value="<?= e($f['texto']) ?>" placeholder="Concepto, referencia, beneficiario o justificación"></div>
  <div><label>Desde</label><input type="date" name="desde" value="<?= e($f['desde']) ?>"></div>
  <div><label>Hasta</label><input type="date" name="hasta" value="<?= e($f['hasta']) ?>"></div>
  <div><label>Cuenta</label>
    <select name="cuenta" data-auto><option value="">Todas</option>
      <?php foreach ($cuentasLista as $c): ?><option value="<?= $c['id'] ?>" <?= $f['cuenta'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach ?>
    </select></div>
  <div><label>Categoría</label>
    <select name="categoria" data-auto><?php opciones_categoria($cats, $f['categoria']) ?></select></div>
  <div><label>Tipo</label>
    <select name="tipo" data-auto>
      <option value="D" <?= $f['tipo'] === 'D' ? 'selected' : '' ?>>Débitos (salidas)</option>
      <option value="C" <?= $f['tipo'] === 'C' ? 'selected' : '' ?>>Créditos (ingresos)</option>
      <option value=""  <?= $f['tipo'] === ''  ? 'selected' : '' ?>>Todo</option>
    </select></div>
  <div><label>Estado</label>
    <select name="estado" data-auto>
      <option value="">Cualquiera</option>
      <option value="conciliado" <?= $f['estado'] === 'conciliado' ? 'selected' : '' ?>>Justificado</option>
      <option value="pendiente"  <?= $f['estado'] === 'pendiente' ? 'selected' : '' ?>>Sin justificar</option>
    </select></div>
  <div><label>Monto desde</label><input type="number" step="0.01" name="min" value="<?= e((string) $f['min']) ?>"></div>
  <div><label>Monto hasta</label><input type="number" step="0.01" name="max" value="<?= e((string) $f['max']) ?>"></div>
  <div><label>Ordenar por</label>
    <select name="orden" data-auto>
      <option value="fecha" <?= $f['orden'] === 'fecha' ? 'selected' : '' ?>>Fecha</option>
      <option value="monto" <?= $f['orden'] === 'monto' ? 'selected' : '' ?>>Monto</option>
      <option value="concepto" <?= $f['orden'] === 'concepto' ? 'selected' : '' ?>>Concepto</option>
    </select></div>
  <div><label>Sentido</label>
    <select name="dir" data-auto>
      <option value="desc" <?= $f['dir'] === 'desc' ? 'selected' : '' ?>>Mayor a menor</option>
      <option value="asc"  <?= $f['dir'] === 'asc'  ? 'selected' : '' ?>>Menor a mayor</option>
    </select></div>
  <div class="filtros-pie"><button class="btn btn-oro">Filtrar</button><a class="btn" href="?r=movimientos">Limpiar</a></div>
</form>

<?php if ($totalCol > 0 && $reparto): ?>
  <div class="tarjeta" style="margin-bottom:14px">
    <h2>Reparto de lo filtrado</h2>
    <?php cinta_html($reparto, $totalCol) ?>
  </div>
<?php endif ?>

<div class="marco-tabla" data-guia="tabla">
  <div class="tabla-scroll">
    <table>
      <thead><tr>
        <th>Fecha</th><th>Cuenta</th><th>Concepto</th><th>Referencia</th>
        <th>Categoría</th><th class="der"><?= $f['tipo'] === 'C' ? 'Crédito' : 'Débito' ?> Bs</th><th class="der">Saldo Bs</th>
      </tr></thead>
      <tbody>
      <?php foreach ($lista['filas'] as $m):
        $monto = (float) ($m['tipo'] === 'C' ? $m['credito'] : $m['debito']);
        $anch = $maxMonto > 0 ? $monto / $maxMonto * 100 : 0; ?>
        <tr>
          <td class="fecha"><a href="?r=movimiento&amp;id=<?= $m['id'] ?>" title="Ver y corregir"><?= e(date('d/m/y', strtotime($m['fecha']))) ?></a></td>
          <td style="font-size:12.5px;color:var(--mudo);white-space:nowrap"><?= e($m['cuenta']) ?></td>
          <td class="concepto"><a href="?r=movimiento&amp;id=<?= $m['id'] ?>"><span class="txt"><?= e($m['concepto']) ?></span></a>
            <?php if ($m['beneficiario'] || $m['justificacion'] || $m['nota_banco']): ?>
              <span class="nota"><?php if ($m['beneficiario']): ?><b style="color:var(--suave)"><?= e($m['beneficiario']) ?></b> · <?php endif ?>
                <?= e(mb_strimwidth((string) ($m['justificacion'] ?: $m['nota_banco']), 0, 60, '…')) ?></span>
            <?php endif ?></td>
          <td class="ref"><?= e($m['referencia']) ?></td>
          <td><?php if ($m['categoria']): ?>
              <span class="etq"><i style="background:<?= e($m['color']) ?>"></i><?= e($m['categoria']) ?></span>
              <?php if ($m['origen']): ?><span class="origen" style="display:block;margin-top:3px"><?= $m['origen'] === 'regla' ? 'automático' : 'manual' ?></span><?php endif ?>
            <?php else: ?><span class="etq vacia">Sin clasificar</span><?php endif ?></td>
          <td class="monto <?= $m['tipo'] === 'C' ? 'c' : 'd' ?>">
            <span class="barra" style="width:<?= number_format($anch, 1, '.', '') ?>%"></span>
            <span><?= bs($monto) ?></span></td>
          <td class="der num" style="color:var(--mudo);white-space:nowrap">
            <?= $m['saldo'] === null ? '—' : bs((float) $m['saldo']) ?></td>
        </tr>
      <?php endforeach ?>
      <?php if ($lista['filas'] === []): ?>
        <tr><td colspan="8" class="vacio"><b>Ningún movimiento coincide con el filtro</b>Prueba a ampliar el rango de fechas o limpiar la búsqueda.</td></tr>
      <?php endif ?>
      </tbody>
    </table>
  </div>
  <?php paginas_html($lista['pagina'], $lista['paginas'], $lista['total']) ?>
</div>

<?php pie_html(); ?>
