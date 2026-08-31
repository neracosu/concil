<?php
/** Cuentas bancarias y lo que se ha cargado en cada una. */
exigir_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar') {
        $id     = (int) ($_POST['id'] ?? 0);
        $nombre = mb_substr(limpiar((string) ($_POST['nombre'] ?? '')), 0, 120);
        $banco  = mb_substr(limpiar((string) ($_POST['banco'] ?? '')), 0, 120);
        $numero  = mb_substr(limpiar((string) ($_POST['numero'] ?? '')), 0, 60);
        $titular = mb_substr(limpiar((string) ($_POST['titular'] ?? '')), 0, 160);
        $rif     = mb_substr(limpiar((string) ($_POST['rif'] ?? '')), 0, 20);
        $sIni   = a_monto((string) ($_POST['saldo_inicial'] ?? '0'));
        $sFecha = a_fecha((string) ($_POST['saldo_fecha'] ?? ''));
        if ($nombre === '') {
            flash('mal', 'La cuenta necesita un nombre.');
            redirigir('?r=cuentas');
        }
        if (sede_actual() === null) {
            flash('mal', 'Elige primero una unidad de negocio.');
            redirigir('?r=sede');
        }
        try {
            if ($id > 0) {
                // El sede_id del WHERE evita editar una cuenta de otra unidad
                // manipulando el id en el formulario.
                $pdo->prepare('UPDATE cuentas SET nombre=?, banco=?, numero=?, titular=?, rif=?,
                                      saldo_inicial=?, saldo_fecha=?
                                WHERE id=? AND sede_id=?')
                    ->execute([$nombre, $banco, $numero, $titular, $rif, $sIni, $sFecha, $id, (int) sede_actual()]);
                flash('ok', 'Cuenta actualizada.');
            } else {
                $pdo->prepare('INSERT INTO cuentas (nombre, banco, numero, titular, rif, saldo_inicial, saldo_fecha, sede_id)
                               VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$nombre, $banco, $numero, $titular, $rif, $sIni, $sFecha, (int) sede_actual()]);
                flash('ok', 'Cuenta creada.');
            }
        } catch (PDOException $ex) {
            flash('mal', 'Ya existe una cuenta con ese nombre en esta unidad de negocio.');
        }
        redirigir('?r=cuentas');
    }

    if ($accion === 'fusionar') {
        $origen  = (int) ($_POST['origen'] ?? 0);
        $destino = (int) ($_POST['destino'] ?? 0);
        try {
            $r = fusionar_cuentas($origen, $destino);
            bitacora('cuentas_fusionadas', "#$origen → #$destino: {$r['movidos']} movidos, {$r['repetidos']} repetidos");
            flash('ok', "Cuentas unidas. Se pasaron {$r['movidos']} movimientos"
                . ($r['repetidos'] > 0
                    ? " y se descartaron {$r['repetidos']} que ya estaban en la cuenta de destino."
                    : '.'));
        } catch (Throwable $ex) {
            flash('mal', 'No se pudieron unir: ' . $ex->getMessage());
        }
        redirigir('?r=cuentas');
    }

    if ($accion === 'borrar') {
        $id = (int) $_POST['id'];
        $n = (int) $pdo->query("SELECT COUNT(*) FROM movimientos m WHERE m.cuenta_id = $id
                                 AND " . filtro_sede())->fetchColumn();
        $pdo->prepare('DELETE FROM cuentas WHERE id = ? AND sede_id = ?')
            ->execute([$id, (int) sede_actual()]);
        bitacora('cuenta_borrada', "id=$id con $n movimientos");
        flash('ok', "Cuenta eliminada junto con sus $n movimientos.");
        redirigir('?r=cuentas');
    }
}

$editar = null;
if (($id = (int) ($_GET['editar'] ?? 0)) > 0) {
    $s = $pdo->prepare('SELECT * FROM cuentas WHERE id = ? AND sede_id = ?');
    $s->execute([$id, (int) sede_actual()]);
    $editar = $s->fetch() ?: null;
}

$lista = $pdo->query("SELECT c.*, COUNT(m.id) movs,
                             COALESCE(SUM(m.debito),0) deb, COALESCE(SUM(m.credito),0) cre,
                             MIN(m.fecha) f1, MAX(m.fecha) f2,
                             SUM(m.tipo='D' AND m.categoria_id IS NULL) pend
                        FROM cuentas c
                   LEFT JOIN movimientos m ON m.cuenta_id = c.id
                       WHERE c.sede_id = " . (int) sede_actual() . "
                    GROUP BY c.id ORDER BY c.nombre")->fetchAll();

encabezado_html('Cuentas', 'cuentas', count($lista) . ' cuentas registradas');
?>
<div class="rejilla" style="grid-template-columns:minmax(0,1fr) 320px;align-items:start">
  <div class="marco-tabla" data-guia="lista">
    <div class="tabla-scroll">
      <table>
        <thead><tr><th>Cuenta</th><th>Período cargado</th><th class="der">Entradas Bs</th><th class="der">Salidas Bs</th>
          <th class="der">Saldo Bs</th><th class="der">Pendientes</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($lista as $c): ?>
          <tr>
            <td><b><?= e($c['nombre']) ?></b>
              <span class="origen" style="display:block"><?= e($c['banco']) ?><?= $c['numero'] ? ' · ' . e($c['numero']) : '' ?><?= $c['titular'] ? ' · ' . e($c['titular']) : '' ?></span>
              <?php $falta = ficha_incompleta($c); if ($falta !== []): ?>
                <span class="etq vacia" style="margin-top:4px;display:inline-block">Falta <?= e(implode(', ', $falta)) ?> — no admite cargas</span>
              <?php endif ?></td>
            <td class="fecha"><?= $c['f1'] ? e(date('d/m/Y', strtotime($c['f1'])) . ' → ' . date('d/m/Y', strtotime($c['f2']))) : '—' ?></td>
            <td class="der num" style="color:var(--entrada)"><?= bs((float) $c['cre'], 0) ?></td>
            <td class="der num" style="color:var(--salida)"><?= bs((float) $c['deb'], 0) ?></td>
            <?php $sal = saldo_cuenta((int) $c['id']); ?>
            <td class="der num" style="color:<?= $sal['saldo'] < 0 ? 'var(--salida)' : 'var(--texto)' ?>">
              <?= bs($sal['saldo']) ?>
              <span class="origen" style="display:block"><?= $sal['fuente'] === 'banco' ? 'según el banco'
                  : ($sal['fuente'] === 'calculado' ? 'calculado' : 'falta saldo inicial') ?></span></td>
            <td class="der num" style="color:<?= $c['pend'] > 0 ? 'var(--pendiente)' : 'var(--tenue)' ?>"><?= number_format((int) $c['pend'], 0, ',', '.') ?></td>
            <td style="text-align:right;white-space:nowrap">
              <a class="btn btn-sm" href="?r=movimientos&cuenta=<?= $c['id'] ?>">Ver</a>
              <a class="btn btn-sm" href="?r=cuentas&editar=<?= $c['id'] ?>">Editar</a>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button class="btn btn-sm btn-peligro" name="accion" value="borrar"
                  data-confirmar="Se borrará «<?= e($c['nombre']) ?>» y sus <?= (int) $c['movs'] ?> movimientos. Esto no se puede deshacer. ¿Continuar?">Borrar</button>
              </form>
            </td>
          </tr>
        <?php endforeach ?>
        <?php if ($lista === []): ?>
          <tr><td colspan="7" class="vacio"><b>Sin cuentas todavía</b>Se crean solas al cargar el primer extracto.</td></tr>
        <?php endif ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php /* Solo tiene sentido ofrecerlo si hay al menos dos del mismo banco. */
  $porBanco = [];
  foreach ($lista as $c) { if ($c['banco'] !== '') { $porBanco[$c['banco']][] = $c; } }
  $repetidos = array_filter($porBanco, fn($g) => count($g) > 1);
  if ($repetidos !== []): ?>
  <div class="tarjeta">
    <h2>Unir dos cuentas en una</h2>
    <p class="nota" style="margin:0 0 12px">
      Tiene <b><?= count(reset($repetidos)) ?> cuentas del mismo banco</b>. Si en realidad son la misma
      —pasa cuando el banco escribe un título distinto en cada archivo— únalas aquí: los movimientos
      se pasan a la que elija y la otra desaparece. Lo que ya estuviera repetido no se duplica.
    </p>
    <form method="post" class="par" style="align-items:end">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="accion" value="fusionar">
      <div>
        <label>Pasar los movimientos de</label>
        <select name="origen" required>
          <?php foreach ($lista as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?> — <?= (int) $c['movs'] ?> movimientos</option>
          <?php endforeach ?>
        </select>
      </div>
      <div>
        <label>A esta cuenta</label>
        <select name="destino" required>
          <?php foreach ($lista as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?> — <?= (int) $c['movs'] ?> movimientos</option>
          <?php endforeach ?>
        </select>
      </div>
      <div>
        <button class="btn btn-peligro"
                data-confirmar="Los movimientos pasarán a la cuenta de destino y la de origen se eliminará. Esto no se puede deshacer. ¿Continuar?">
          Unir
        </button>
      </div>
    </form>
  </div>
  <?php endif ?>

  <div class="tarjeta">
    <h2><?= $editar ? 'Editar cuenta' : 'Nueva cuenta' ?></h2>
    <form method="post" class="pila">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" value="<?= (int) ($editar['id'] ?? 0) ?>">
      <div><label>Nombre</label>
        <input type="text" name="nombre" maxlength="120" required value="<?= e($editar['nombre'] ?? '') ?>"
               placeholder="Ej.: VENEZUELA ARMORMARKET"></div>
      <div><label>Banco</label>
        <input type="text" name="banco" maxlength="120" value="<?= e($editar['banco'] ?? '') ?>" placeholder="Banco de Venezuela"></div>
      <div class="par">
        <div><label>Número de cuenta</label>
          <input type="text" name="numero" maxlength="60" value="<?= e($editar['numero'] ?? '') ?>" placeholder="20 dígitos"></div>
        <div><label>RIF</label>
          <input type="text" name="rif" maxlength="20" value="<?= e($editar['rif'] ?? '') ?>" placeholder="J-12345678-9"></div>
      </div>
      <div><label>Titular</label>
        <input type="text" name="titular" maxlength="160" value="<?= e($editar['titular'] ?? '') ?>"
               placeholder="Nombre de la empresa dueña de la cuenta"></div>
      <div class="par" data-guia="saldoinicial">
        <div><label>Saldo de arranque</label>
          <input type="text" name="saldo_inicial" inputmode="decimal"
                 value="<?= $editar ? bs((float) $editar['saldo_inicial']) : '0,00' ?>"></div>
        <div><label>A la fecha</label>
          <input type="date" name="saldo_fecha" value="<?= e($editar['saldo_fecha'] ?? '') ?>"></div>
      </div>
      <p style="color:var(--tenue);font-size:12.5px;margin:0">
        Solo hace falta si el extracto de esta cuenta no trae columna de saldo. Pon el saldo que tenía
        la cuenta el día anterior al primer movimiento cargado.</p>
      <div class="acciones">
        <button class="btn btn-oro"><?= $editar ? 'Guardar' : 'Crear cuenta' ?></button>
        <?php if ($editar): ?><a class="btn" href="?r=cuentas">Cancelar</a><?php endif ?>
      </div>
    </form>
  </div>
</div>
<?php pie_html(); ?>
