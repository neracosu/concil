<?php
/** Reglas de mapeo: lo que hace que mañana no tengas que clasificar nada a mano. */
exigir_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar') {
        $id     = (int) ($_POST['id'] ?? 0);
        $nombre = mb_substr(limpiar((string) ($_POST['nombre'] ?? '')), 0, 160);
        $tipo   = in_array($_POST['tipo'] ?? '', ['contiene', 'empieza', 'termina', 'igual', 'regex', 'proporcion'], true) ? $_POST['tipo'] : 'contiene';
        $campo  = in_array($_POST['campo'] ?? '', ['concepto', 'nota', 'referencia'], true) ? $_POST['campo'] : 'concepto';
        $patron = mb_substr(trim((string) ($_POST['patron'] ?? '')), 0, 255);
        $cat    = (int) ($_POST['categoria_id'] ?? 0);
        $benef  = mb_substr(limpiar((string) ($_POST['beneficiario'] ?? '')), 0, 160);
        $cuenta = (int) ($_POST['cuenta_id'] ?? 0) ?: null;
        $prio   = max(1, min(999, (int) ($_POST['prioridad'] ?? 100)));

        $err = validar_patron($tipo, $patron) ?? ($cat > 0 ? null : 'Elige la categoría que asigna la regla.');
        if ($nombre === '') {
            $err ??= 'Ponle un nombre a la regla.';
        }
        if ($err !== null) {
            flash('mal', $err);
            redirigir('?r=reglas');
        }
        $guardado = in_array($tipo, ['regex', 'proporcion'], true) ? $patron : norm($patron);

        if ($id > 0) {
            $pdo->prepare('UPDATE reglas SET nombre=?, campo=?, tipo=?, patron=?, categoria_id=?, beneficiario=?, cuenta_id=?, prioridad=? WHERE id=?')
                ->execute([$nombre, $campo, $tipo, $guardado, $cat, $benef, $cuenta, $prio, $id]);
            flash('ok', 'Regla actualizada.');
        } else {
            $pdo->prepare('INSERT INTO reglas (nombre, campo, tipo, patron, categoria_id, beneficiario, cuenta_id, prioridad) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$nombre, $campo, $tipo, $guardado, $cat, $benef, $cuenta, $prio]);
            flash('ok', 'Regla creada. Aplícala a lo que ya está cargado con «Reaplicar reglas».');
        }
        bitacora('regla', $nombre);
        redirigir('?r=reglas');
    }

    if ($accion === 'activar') {
        $pdo->prepare('UPDATE reglas SET activa = 1 - activa WHERE id = ?')->execute([(int) $_POST['id']]);
        redirigir('?r=reglas');
    }

    if ($accion === 'borrar') {
        $pdo->prepare('DELETE FROM reglas WHERE id = ?')->execute([(int) $_POST['id']]);
        flash('ok', 'Regla eliminada. Los movimientos ya clasificados con ella no cambian.');
        redirigir('?r=reglas');
    }

    if ($accion === 'sugerencia') {
        $nota = mb_substr(limpiar((string) ($_POST['nota'] ?? '')), 0, 255);
        $cat  = (int) ($_POST['categoria_id'] ?? 0);
        if ($nota === '' || $cat <= 0) {
            flash('mal', 'Elige la categoría para esa nota.');
            redirigir('?r=reglas');
        }
        $pdo->prepare('INSERT INTO reglas (nombre, campo, tipo, patron, categoria_id, prioridad) VALUES (?,?,?,?,?,50)')
            ->execute(['Nota del archivo · ' . $nota, 'nota', 'igual', norm($nota), $cat]);
        $n = reaplicar_reglas();
        bitacora('regla_sugerida', $nota);
        flash('ok', "Regla creada desde la nota «$nota». $n movimiento(s) quedaron clasificados.");
        redirigir('?r=reglas');
    }

    if ($accion === 'reaplicar') {
        $todos = !empty($_POST['incluir_mapeados']);
        $n = reaplicar_reglas($todos);
        bitacora('reaplicar', "$n movimientos");
        flash('ok', $n . ' movimiento' . ($n === 1 ? '' : 's') . ' clasificado' . ($n === 1 ? '' : 's') . ' con las reglas actuales.');
        redirigir('?r=reglas');
    }
}

$editar = null;
if (($id = (int) ($_GET['editar'] ?? 0)) > 0) {
    $s = $pdo->prepare('SELECT * FROM reglas WHERE id = ?');
    $s->execute([$id]);
    $editar = $s->fetch() ?: null;
}

/* Notas que tú ya escribiste en los archivos y siguen sin categoría:
   cada una es una regla lista para crear. */
$sugerencias = $pdo->query("SELECT m.nota_banco nota, COUNT(*) n, SUM(m.debito) total
                              FROM movimientos m
                             WHERE m.tipo = 'D' AND m.categoria_id IS NULL AND m.nota_banco <> ''
                               AND " . filtro_sede() . "
                          GROUP BY m.nota_banco
                          ORDER BY total DESC
                             LIMIT 12")->fetchAll();

$reglas = $pdo->query('SELECT r.*, c.nombre categoria, c.color, cu.nombre cuenta
                         FROM reglas r
                         JOIN categorias c ON c.id = r.categoria_id
                    LEFT JOIN cuentas cu ON cu.id = r.cuenta_id
                     ORDER BY r.prioridad, r.id')->fetchAll();
$cats = categorias();

encabezado_html('Reglas de mapeo', 'reglas',
    count($reglas) . ' reglas · se evalúan de menor a mayor prioridad y gana la primera que coincide');
?>

<form method="post" style="margin-bottom:16px">
  <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
  <input type="hidden" name="accion" value="reaplicar">
  <div class="tarjeta" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap" data-guia="reaplicar">
    <div style="flex:1;min-width:240px">
      <b style="display:block;margin-bottom:3px">Aplicar las reglas a lo ya cargado</b>
      <span style="color:var(--mudo);font-size:13px">Recorre los movimientos y clasifica los que ahora sí coincidan.</span>
    </div>
    <label style="display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0;font-size:13px;color:var(--suave);margin:0">
      <input type="checkbox" name="incluir_mapeados" value="1" style="width:auto">
      Rehacer también los ya clasificados por regla
    </label>
    <button class="btn btn-oro" data-confirmar="Se recorrerán todos los débitos aplicando las reglas activas. ¿Continuar?">Reaplicar reglas</button>
  </div>
</form>

<?php if ($sugerencias !== []): ?>
  <div class="marco-tabla" style="margin-bottom:16px" data-guia="sugerencias">
    <div style="padding:14px 16px;border-bottom:1px solid var(--linea)">
      <b>Reglas sugeridas por tus propias notas</b>
      <span style="color:var(--mudo);font-size:13px;display:block;margin-top:3px">
        Estas notas ya venían escritas en los extractos. Asígnale una categoría a cada una y quedan clasificadas,
        hoy y en las próximas cargas.</span>
    </div>
    <div class="tabla-scroll">
      <table>
        <thead><tr><th>Nota en el archivo</th><th class="der">Movs.</th><th class="der">Total Bs</th><th style="width:320px">Categoría</th></tr></thead>
        <tbody>
        <?php foreach ($sugerencias as $sg): ?>
          <tr>
            <td><b><?= e($sg['nota']) ?></b></td>
            <td class="der num"><?= number_format((int) $sg['n'], 0, ',', '.') ?></td>
            <td class="der num" style="color:var(--salida)"><?= bs((float) $sg['total']) ?></td>
            <td>
              <form method="post" style="display:flex;gap:8px">
                <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                <input type="hidden" name="accion" value="sugerencia">
                <input type="hidden" name="nota" value="<?= e($sg['nota']) ?>">
                <select name="categoria_id" required style="flex:1">
                  <option value="">Elegir categoría…</option>
                  <?php $gg = ''; foreach ($cats as $c):
                    if ($c['grupo'] !== $gg) { if ($gg !== '') echo '</optgroup>'; $gg = $c['grupo']; echo '<optgroup label="' . e($gg) . '">'; } ?>
                    <option value="<?= $c['id'] ?>"><?= e($c['nombre']) ?></option>
                  <?php endforeach; if ($gg !== '') echo '</optgroup>'; ?>
                </select>
                <button class="btn btn-sm btn-oro">Crear</button>
              </form>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif ?>

<div class="rejilla" style="grid-template-columns:minmax(0,1fr) 340px;align-items:start">
  <div class="marco-tabla" data-guia="listareglas">
    <div class="tabla-scroll">
      <table>
        <thead><tr><th class="der">Pri.</th><th>Regla</th><th>Busca</th><th>Categoría</th>
          <th class="der">Aciertos</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($reglas as $r): ?>
          <tr style="<?= $r['activa'] ? '' : 'opacity:.45' ?>">
            <td class="der num" style="color:var(--mudo)"><?= (int) $r['prioridad'] ?></td>
            <td><span class="txt"><?= e($r['nombre']) ?></span>
              <?php if ($r['cuenta']): ?><span class="origen" style="display:block">solo en <?= e($r['cuenta']) ?></span><?php endif ?></td>
            <td class="ref" style="max-width:250px">
              <span class="origen"><?= e($r['campo']) ?> <?= e($r['tipo']) ?></span><br>
              <span style="color:var(--suave)"><?= e(mb_strimwidth($r['patron'], 0, 40, '…')) ?></span></td>
            <td><span class="etq"><i style="background:<?= e($r['color']) ?>"></i><?= e($r['categoria']) ?></span>
              <?php if ($r['beneficiario']): ?><span class="origen" style="display:block;margin-top:3px"><?= e($r['beneficiario']) ?></span><?php endif ?></td>
            <td class="der num"><?= number_format((int) $r['aciertos'], 0, ',', '.') ?></td>
            <td style="white-space:nowrap;text-align:right">
              <a class="btn btn-sm" href="?r=reglas&editar=<?= $r['id'] ?>">Editar</a>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm" name="accion" value="activar"><?= $r['activa'] ? 'Pausar' : 'Activar' ?></button>
                <button class="btn btn-sm btn-peligro" name="accion" value="borrar"
                        data-confirmar="¿Eliminar la regla «<?= e($r['nombre']) ?>»?">Borrar</button>
              </form>
            </td>
          </tr>
        <?php endforeach ?>
        <?php if ($reglas === []): ?>
          <tr><td colspan="6" class="vacio"><b>Todavía no hay reglas</b>Créalas desde la bandeja de pendientes o con el formulario de al lado.</td></tr>
        <?php endif ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="tarjeta">
    <h2><?= $editar ? 'Editar regla' : 'Nueva regla' ?></h2>
    <form method="post" class="pila">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" value="<?= (int) ($editar['id'] ?? 0) ?>">
      <div><label>Nombre</label>
        <input type="text" name="nombre" maxlength="160" required value="<?= e($editar['nombre'] ?? '') ?>"
               placeholder="Ej.: Comisión pago móvil"></div>
      <div><label>Dónde busca</label>
        <select name="campo">
          <?php foreach (['concepto' => 'Concepto del banco', 'nota' => 'Nota del banco', 'referencia' => 'Referencia'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($editar['campo'] ?? 'concepto') === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach ?>
        </select></div>
      <div><label>Cómo compara</label>
        <select name="tipo">
          <?php foreach (['contiene' => 'Contiene', 'empieza' => 'Empieza con', 'termina' => 'Termina con',
                          'igual' => 'Es exactamente', 'regex' => 'Expresión regular',
                          'proporcion' => 'Es un % de otro movimiento con la misma referencia'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($editar['tipo'] ?? 'contiene') === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach ?>
        </select></div>
      <div><label>Patrón</label>
        <input type="text" name="patron" maxlength="255" required value="<?= e($editar['patron'] ?? '') ?>"
               placeholder="CORPOELEC">
        <span style="display:block;color:var(--tenue);font-size:12px;margin-top:5px">
          Se compara en mayúsculas, sin acentos ni signos. «Comisión» y «COMISION» son lo mismo.</span></div>
      <div><label>Asigna la categoría</label>
        <select name="categoria_id" required>
          <option value="">Elegir…</option>
          <?php $g = ''; foreach ($cats as $c):
            if ($c['grupo'] !== $g) { if ($g !== '') echo '</optgroup>'; $g = $c['grupo']; echo '<optgroup label="' . e($g) . '">'; } ?>
            <option value="<?= $c['id'] ?>" <?= (int) ($editar['categoria_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
          <?php endforeach; if ($g !== '') echo '</optgroup>'; ?>
        </select></div>
      <div><label>Beneficiario</label>
        <input type="text" name="beneficiario" maxlength="160" value="<?= e($editar['beneficiario'] ?? '') ?>"></div>
      <div class="par">
        <div><label>Solo en la cuenta</label>
          <select name="cuenta_id"><option value="">Todas</option>
            <?php foreach (cuentas() as $c): ?>
              <option value="<?= $c['id'] ?>" <?= (int) ($editar['cuenta_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
            <?php endforeach ?>
          </select></div>
        <div><label>Prioridad</label>
          <input type="number" name="prioridad" min="1" max="999" value="<?= (int) ($editar['prioridad'] ?? 100) ?>"></div>
      </div>
      <div class="acciones">
        <button class="btn btn-oro"><?= $editar ? 'Guardar cambios' : 'Crear regla' ?></button>
        <?php if ($editar): ?><a class="btn" href="?r=reglas">Cancelar</a><?php endif ?>
      </div>
    </form>
  </div>
</div>

<?php pie_html(); ?>
