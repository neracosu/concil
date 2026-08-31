<?php
/**
 * Bandeja de justificación. Los débitos sin categoría se agrupan por concepto
 * (los números variables se colapsan), así una sola decisión resuelve decenas
 * de movimientos y, si se guarda como regla, también los de mañana.
 */
exigir_login();

const GRUPO_SQL = "UPPER(REGEXP_REPLACE(m.concepto, '[0-9]+', '#'))";

/** Del concepto agrupado saca un patrón usable como regla. */
function sugerir_patron(string $grupo): string
{
    $t = norm(str_replace('#', ' ', $grupo));
    $palabras = array_filter(explode(' ', $t), fn($p) => mb_strlen($p) >= 3);
    return implode(' ', array_slice($palabras, 0, 6)) ?: $t;
}

$pdo = db();
$modo = ($_GET['modo'] ?? 'grupos') === 'lista' ? 'lista' : 'grupos';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $accion  = $_POST['accion'] ?? '';
    $catId   = (int) ($_POST['categoria_id'] ?? 0);
    $benef   = mb_substr(limpiar((string) ($_POST['beneficiario'] ?? '')), 0, 160);
    $justif  = mb_substr(limpiar((string) ($_POST['justificacion'] ?? '')), 0, 1000);

    if ($catId <= 0) {
        flash('mal', 'Elige una categoría antes de guardar.');
        redirigir(url([], 'pendientes'));
    }

    $reglaId = null;
    if (!empty($_POST['crear_regla'])) {
        $patron = mb_substr(trim((string) ($_POST['patron'] ?? '')), 0, 255);
        $tipo   = in_array($_POST['tipo_regla'] ?? '', ['contiene', 'empieza', 'igual', 'regex'], true) ? $_POST['tipo_regla'] : 'contiene';
        $err = validar_patron($tipo, $patron);
        if ($err !== null) {
            flash('mal', $err);
            redirigir(url([], 'pendientes'));
        }
        $guardado = $tipo === 'regex' ? $patron : norm($patron);
        $nombreCat = $pdo->query('SELECT nombre FROM categorias WHERE id = ' . $catId)->fetchColumn();
        $pdo->prepare('INSERT INTO reglas (nombre, campo, tipo, patron, categoria_id, beneficiario, prioridad)
                       VALUES (?, ?, ?, ?, ?, ?, 60)')
            ->execute([mb_substr(($benef ?: $nombreCat) . ' · ' . $patron, 0, 160), 'concepto', $tipo, $guardado, $catId, $benef]);
        $reglaId = (int) $pdo->lastInsertId();
    }

    $set = "categoria_id = ?, beneficiario = ?, estado = 'conciliado',
            origen = ?, regla_id = ?, actualizado_en = NOW()"
         . ($justif !== '' ? ', justificacion = ?' : '');
    $base = [$catId, $benef, $reglaId ? 'regla' : 'manual', $reglaId];
    if ($justif !== '') {
        $base[] = $justif;
    }

    $n = 0;
    if ($accion === 'grupo') {
        $grupo = (string) ($_POST['grupo'] ?? '');
        $s = $pdo->prepare("UPDATE movimientos m SET $set
                             WHERE m.tipo='D' AND m.categoria_id IS NULL
                               AND " . filtro_sede() . ' AND ' . GRUPO_SQL . ' = ?');
        $s->execute([...$base, $grupo]);
        $n = $s->rowCount();
    } elseif ($accion === 'seleccion') {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        if ($ids !== []) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $s = $pdo->prepare("UPDATE movimientos m SET $set
                                 WHERE m.id IN ($marcas) AND " . filtro_sede());
            $s->execute([...$base, ...$ids]);
            $n = $s->rowCount();
        }
    }

    if ($reglaId) {
        $pdo->prepare('UPDATE reglas SET aciertos = aciertos + ? WHERE id = ?')->execute([$n, $reglaId]);
    }
    bitacora('justificacion', "$n movimiento(s)" . ($reglaId ? ' + regla nueva' : ''));
    flash('ok', $n . ' movimiento' . ($n === 1 ? '' : 's') . ' justificado' . ($n === 1 ? '' : 's')
        . ($reglaId ? '. La regla queda activa para las próximas cargas.' : '.'));
    redirigir(url([], 'pendientes'));
}

$cats = categorias();
$total = pendientes_total();
$totalBs = (float) $pdo->query("SELECT COALESCE(SUM(m.debito),0) FROM movimientos m
                                 WHERE m.tipo='D' AND m.categoria_id IS NULL
                                   AND " . filtro_sede())->fetchColumn();

$acciones = '<span data-guia="modo"><a class="btn' . ($modo === 'grupos' ? ' btn-oro' : '') . '" href="' . e(url(['modo' => 'grupos', 'p' => 1])) . '">Por patrón</a>'
          . '<a class="btn' . ($modo === 'lista' ? ' btn-oro' : '') . '" href="' . e(url(['modo' => 'lista', 'p' => 1])) . '">Uno por uno</a></span>';

encabezado_html('Por justificar', 'pendientes',
    $total > 0
        ? number_format($total, 0, ',', '.') . ' débitos sin clasificar · <b class="num" style="color:var(--pendiente)">Bs ' . bs($totalBs, 0) . '</b>'
        : 'Todo al día.',
    $acciones);

if ($total === 0) {
    echo '<div class="marco-tabla"><div class="vacio"><b>No queda nada por justificar</b>'
       . 'Cuando cargues el próximo extracto, aquí aparecerá lo que las reglas no reconozcan.</div></div>';
    pie_html();
    return;
}

/**
 * Formulario de clasificación reutilizable.
 *
 * En la lista, cada fila lleva su propia copia desplegable. Como no se pueden
 * anidar formularios dentro de la tabla, los campos se atan al suyo con el
 * atributo form="…" y la etiqueta <form> se emite aparte, fuera de la tabla.
 */
function form_clasificar(array $cats, string $accion, array $ocultos, string $patronSugerido, string $idForm, bool $suelto = false): void
{
    $att = $suelto ? ' form="' . e($idForm) . '"' : '';
    if (!$suelto): ?>
    <form method="post" class="pila" id="<?= e($idForm) ?>">
    <?php else: ?>
    <div class="pila">
    <?php endif ?>
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"<?= $att ?>>
      <input type="hidden" name="accion" value="<?= e($accion) ?>"<?= $att ?>>
      <?php foreach ($ocultos as $k => $v): ?>
        <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"<?= $att ?>>
      <?php endforeach ?>
      <div class="par">
        <div>
          <label>Categoría</label>
          <select name="categoria_id" required<?= $att ?>>
            <option value="">Elegir…</option>
            <?php $g = ''; foreach ($cats as $c):
              if ($c['grupo'] !== $g) { if ($g !== '') echo '</optgroup>'; $g = $c['grupo']; echo '<optgroup label="' . e($g) . '">'; } ?>
              <option value="<?= $c['id'] ?>"><?= e($c['nombre']) ?></option>
            <?php endforeach; if ($g !== '') echo '</optgroup>'; ?>
          </select>
        </div>
        <div>
          <label>A quién se le pagó <span style="text-transform:none;letter-spacing:0">(opcional)</span></label>
          <input type="text" name="beneficiario" maxlength="160" placeholder="Proveedor, empleado, organismo…"<?= $att ?>>
        </div>
      </div>
      <div>
        <label>Justificación <span style="text-transform:none;letter-spacing:0">(para qué se usó el dinero)</span></label>
        <textarea name="justificacion" maxlength="1000" rows="2" placeholder="Ej.: pago de la factura de agosto del servicio de internet de la sede Boleíta."<?= $att ?>></textarea>
      </div>
      <div style="border-top:1px solid var(--linea);padding-top:12px" <?= $idForm === 'g0' ? 'data-guia="regla"' : '' ?>>
        <label style="display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0;font-size:13.5px;color:var(--texto);margin-bottom:10px">
          <input type="checkbox" name="crear_regla" value="1" style="width:auto"<?= $att ?> <?= $suelto ? '' : 'checked' ?>>
          Guardar como regla para que se clasifique solo de aquí en adelante
        </label>
        <div class="par">
          <div>
            <label>Patrón a reconocer</label>
            <input type="text" name="patron" value="<?= e($patronSugerido) ?>" maxlength="255"<?= $att ?>>
          </div>
          <div>
            <label>Coincidencia</label>
            <select name="tipo_regla"<?= $att ?>>
              <option value="contiene">El concepto contiene el patrón</option>
              <option value="empieza">El concepto empieza con el patrón</option>
              <option value="igual">El concepto es exactamente el patrón</option>
              <option value="regex">Expresión regular</option>
            </select>
          </div>
        </div>
      </div>
    <?php if (!$suelto): ?>
    </form>
    <?php else: ?>
    </div>
    <?php endif ?>
    <?php
}

$pagina = max(1, (int) ($_GET['p'] ?? 1));
$porPagina = $modo === 'grupos' ? 15 : 40;
$off = ($pagina - 1) * $porPagina;

if ($modo === 'grupos'):
    $nGrupos = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT 1 FROM movimientos m
        WHERE m.tipo='D' AND m.categoria_id IS NULL AND " . filtro_sede()
        . ' GROUP BY ' . GRUPO_SQL . ') x')->fetchColumn();
    $paginas = max(1, (int) ceil($nGrupos / $porPagina));

    $grupos = $pdo->query("SELECT " . GRUPO_SQL . " grupo, COUNT(*) n, SUM(m.debito) total,
                                  MIN(m.fecha) f1, MAX(m.fecha) f2,
                                  SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT m.concepto SEPARATOR '§'), '§', 1) ejemplo,
                                  SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT NULLIF(m.nota_banco,'') SEPARATOR '§'), '§', 1) nota,
                                  GROUP_CONCAT(DISTINCT c.nombre SEPARATOR ', ') cuentas
                             FROM movimientos m JOIN cuentas c ON c.id = m.cuenta_id
                            WHERE m.tipo='D' AND m.categoria_id IS NULL AND " . filtro_sede() . "
                         GROUP BY grupo
                         ORDER BY total DESC
                            LIMIT $porPagina OFFSET $off")->fetchAll();
    ?>
    <div class="aviso aviso-nota">
      Se agrupan los conceptos que solo cambian en los números. Clasifica el grupo completo de una vez;
      si guardas la regla, los próximos extractos ya llegan clasificados.
    </div>

    <div class="pila">
      <?php foreach ($grupos as $k => $g): $idf = 'g' . $k; ?>
        <div class="tarjeta"<?= $k === 0 ? ' data-guia="grupo"' : '' ?>>
          <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-bottom:14px">
            <div style="min-width:0;flex:1">
              <div class="num" style="font-size:14px;color:var(--texto);word-break:break-word"><?= e($g['grupo']) ?></div>
              <div style="color:var(--mudo);font-size:12.5px;margin-top:5px">
                <?= e(mb_strimwidth($g['ejemplo'], 0, 76, '…')) ?>
                <?php if ($g['nota']): ?> · nota del banco: <b><?= e($g['nota']) ?></b><?php endif ?>
              </div>
              <div class="origen" style="margin-top:6px">
                <?= e($g['cuentas']) ?> ·
                <?= e(date('d/m/Y', strtotime($g['f1']))) ?><?= $g['f1'] !== $g['f2'] ? ' al ' . e(date('d/m/Y', strtotime($g['f2']))) : '' ?>
              </div>
            </div>
            <div style="text-align:right;white-space:nowrap">
              <div class="num" style="font-size:20px;color:var(--salida)">Bs <?= bs((float) $g['total']) ?></div>
              <div class="origen"><?= number_format((int) $g['n'], 0, ',', '.') ?> movimiento<?= $g['n'] == 1 ? '' : 's' ?></div>
            </div>
          </div>
          <?php form_clasificar($cats, 'grupo', ['grupo' => $g['grupo']], sugerir_patron($g['grupo']), $idf) ?>
          <div class="acciones" style="margin-top:14px">
            <button class="btn btn-oro" form="<?= $idf ?>">Justificar los <?= (int) $g['n'] ?></button>
            <a class="btn" href="<?= e(url(['modo' => 'lista', 'texto' => mb_substr($g['ejemplo'], 0, 40), 'p' => 1])) ?>">Ver uno por uno</a>
          </div>
        </div>
      <?php endforeach ?>
    </div>
    <div class="marco-tabla" style="margin-top:14px"><?php paginas_html($pagina, $paginas, $nGrupos, 'patrones distintos') ?></div>

<?php else:
    $_GET['estado'] = 'pendiente';
    $_GET['tipo'] = 'D';
    $f = filtros();
    $lista = listar_movimientos($f, $pagina, $porPagina);
    $maxMonto = 0.0;
    foreach ($lista['filas'] as $m) { $maxMonto = max($maxMonto, (float) $m['debito']); }
    ?>
    <form method="get" class="filtros">
      <input type="hidden" name="r" value="pendientes"><input type="hidden" name="modo" value="lista">
      <div class="ancho"><label>Buscar en el concepto</label>
        <input type="text" name="texto" value="<?= e($f['texto']) ?>" placeholder="Ej.: CORPOELEC, TRFOTJ, nombre del proveedor…"></div>
      <div><label>Cuenta</label>
        <select name="cuenta" data-auto><option value="">Todas</option>
          <?php foreach (cuentas() as $c): ?><option value="<?= $c['id'] ?>" <?= $f['cuenta'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach ?>
        </select></div>
      <div><label>Desde</label><input type="date" name="desde" value="<?= e($f['desde']) ?>"></div>
      <div><label>Hasta</label><input type="date" name="hasta" value="<?= e($f['hasta']) ?>"></div>
      <div class="filtros-pie"><button class="btn">Filtrar</button><a class="btn" href="?r=pendientes&modo=lista">Limpiar</a></div>
    </form>

    <form method="post" id="fSel">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="accion" value="seleccion">
    </form>

    <div class="marco-tabla">
        <div class="tabla-scroll">
          <table>
            <thead><tr>
              <th style="width:34px"><input type="checkbox" id="marcarTodos" style="width:auto" aria-label="Marcar todos"></th>
              <th>Fecha</th><th>Cuenta</th><th>Concepto</th><th>Referencia</th><th class="der">Débito Bs</th>
              <th style="width:120px"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($lista['filas'] as $m): $anch = $maxMonto > 0 ? (float) $m['debito'] / $maxMonto * 100 : 0; ?>
              <tr>
                <td><input type="checkbox" name="ids[]" value="<?= $m['id'] ?>" style="width:auto" form="fSel" aria-label="Seleccionar"></td>
                <td class="fecha"><?= e(date('d/m/y', strtotime($m['fecha']))) ?></td>
                <td style="font-size:12.5px;color:var(--mudo)"><?= e($m['cuenta']) ?></td>
                <td class="concepto"><span class="txt"><?= e($m['concepto']) ?></span>
                  <?php if ($m['nota_banco']): ?><span class="nota"><?= e($m['nota_banco']) ?></span><?php endif ?></td>
                <td class="ref"><?= e($m['referencia']) ?></td>
                <td class="monto d"><span class="barra" style="width:<?= number_format($anch, 1, '.', '') ?>%"></span><span><?= bs((float) $m['debito']) ?></span></td>
                <td class="der"><button type="button" class="btn btn-sm" data-abrir="j<?= $m['id'] ?>"
                        aria-expanded="false" aria-controls="j<?= $m['id'] ?>">Justificar</button></td>
              </tr>
              <tr class="fila-justificar" id="j<?= $m['id'] ?>" hidden>
                <td colspan="7">
                  <?php form_clasificar($cats, 'seleccion', ['ids[]' => (string) $m['id']],
                                        sugerir_patron((string) $m['concepto']), 'fr' . $m['id'], true) ?>
                  <div class="acciones" style="margin-top:12px">
                    <button class="btn btn-oro" form="fr<?= $m['id'] ?>">Guardar este movimiento</button>
                    <button type="button" class="btn" data-cerrar="j<?= $m['id'] ?>">Cancelar</button>
                  </div>
                </td>
              </tr>
            <?php endforeach ?>
            </tbody>
          </table>
        </div>
        <?php paginas_html($lista['pagina'], $lista['paginas'], $lista['total'], 'pendientes') ?>
    </div>

    <?php /* Un formulario por fila: los campos de arriba se atan con form="…". */
    foreach ($lista['filas'] as $m): ?>
      <form method="post" id="fr<?= $m['id'] ?>"></form>
    <?php endforeach ?>

    <div class="tarjeta" style="margin-top:14px">
      <h2>Clasificar varios de una vez</h2>
      <p class="nota" style="margin:0 0 12px">Marque las casillas de la izquierda y use este formulario.
        Para uno solo, es más rápido el botón <b>Justificar</b> de su propia fila.</p>
      <?php form_clasificar($cats, 'seleccion', [], '', 'fClas') ?>
      <div class="acciones" style="margin-top:14px">
        <button class="btn btn-oro" onclick="document.querySelectorAll('input[name=\'ids[]\']:checked').forEach(function(c){var h=document.createElement('input');h.type='hidden';h.name='ids[]';h.value=c.value;document.getElementById('fClas').appendChild(h)});">
          Justificar seleccionados
        </button>
      </div>
    </div>
<?php endif ?>

<?php pie_html(); ?>
