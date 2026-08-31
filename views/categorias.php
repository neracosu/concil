<?php
/** Catálogo de categorías: el vocabulario con el que se explica cada salida. */
exigir_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar') {
        $id     = (int) ($_POST['id'] ?? 0);
        $nombre = mb_substr(limpiar((string) ($_POST['nombre'] ?? '')), 0, 120);
        $grupo  = mb_substr(limpiar((string) ($_POST['grupo'] ?? 'General')), 0, 60) ?: 'General';
        $color  = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST['color'] ?? '')) ? $_POST['color'] : '#d4a857';
        if ($nombre === '') {
            flash('mal', 'La categoría necesita un nombre.');
            redirigir('?r=categorias');
        }
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE categorias SET nombre=?, grupo=?, color=? WHERE id=?')->execute([$nombre, $grupo, $color, $id]);
                flash('ok', 'Categoría actualizada.');
            } else {
                $pdo->prepare('INSERT INTO categorias (nombre, grupo, color) VALUES (?,?,?)')->execute([$nombre, $grupo, $color]);
                flash('ok', 'Categoría creada.');
            }
        } catch (PDOException $ex) {
            flash('mal', 'Ya existe una categoría con ese nombre.');
        }
        redirigir('?r=categorias');
    }

    if ($accion === 'borrar') {
        $id = (int) $_POST['id'];
        $n = (int) $pdo->query("SELECT COUNT(*) FROM movimientos m WHERE m.categoria_id = $id
                                 AND " . filtro_sede())->fetchColumn();
        $pdo->prepare('DELETE FROM categorias WHERE id = ?')->execute([$id]);
        flash('ok', $n > 0
            ? "Categoría eliminada. $n movimiento(s) volvieron a quedar sin clasificar."
            : 'Categoría eliminada.');
        redirigir('?r=categorias');
    }
}

$editar = null;
if (($id = (int) ($_GET['editar'] ?? 0)) > 0) {
    $s = $pdo->prepare('SELECT * FROM categorias WHERE id = ?');
    $s->execute([$id]);
    $editar = $s->fetch() ?: null;
}

$lista = $pdo->query("SELECT c.*, COUNT(m.id) usos, COALESCE(SUM(m.debito),0) total
                        FROM categorias c
                   LEFT JOIN movimientos m ON m.categoria_id = c.id AND m.tipo = 'D'
                                          AND " . filtro_sede() . "
                    GROUP BY c.id
                    ORDER BY c.grupo, c.nombre")->fetchAll();

encabezado_html('Categorías', 'categorias', count($lista) . ' categorías activas');
?>
<div class="rejilla" style="grid-template-columns:minmax(0,1fr) 320px;align-items:start">
  <div class="marco-tabla" data-guia="lista">
    <div class="tabla-scroll">
      <table>
        <thead><tr><th>Grupo</th><th>Categoría</th><th class="der">Movimientos</th><th class="der">Total Bs</th><th></th></tr></thead>
        <tbody>
        <?php $g = ''; foreach ($lista as $c): ?>
          <tr>
            <td style="color:var(--mudo);font-size:12.5px"><?= $c['grupo'] !== $g ? e($g = $c['grupo']) : '' ?></td>
            <td><span class="etq"><i style="background:<?= e($c['color']) ?>"></i><?= e($c['nombre']) ?></span></td>
            <td class="der num"><?= number_format((int) $c['usos'], 0, ',', '.') ?></td>
            <td class="der num"><?= bs((float) $c['total'], 0) ?></td>
            <td style="text-align:right;white-space:nowrap">
              <a class="btn btn-sm" href="?r=movimientos&categoria=<?= $c['id'] ?>&tipo=D">Ver</a>
              <a class="btn btn-sm" href="?r=categorias&editar=<?= $c['id'] ?>">Editar</a>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button class="btn btn-sm btn-peligro" name="accion" value="borrar"
                  data-confirmar="Se eliminará «<?= e($c['nombre']) ?>»<?= $c['usos'] > 0 ? ' y sus ' . (int) $c['usos'] . ' movimientos volverán a pendientes' : '' ?>. ¿Continuar?">Borrar</button>
              </form>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="tarjeta">
    <h2><?= $editar ? 'Editar categoría' : 'Nueva categoría' ?></h2>
    <form method="post" class="pila">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="id" value="<?= (int) ($editar['id'] ?? 0) ?>">
      <div><label>Nombre</label>
        <input type="text" name="nombre" maxlength="120" required value="<?= e($editar['nombre'] ?? '') ?>"
               placeholder="Ej.: Fletes y encomiendas"></div>
      <div><label>Grupo</label>
        <input type="text" name="grupo" maxlength="60" list="grupos" value="<?= e($editar['grupo'] ?? '') ?>" placeholder="Operativo">
        <datalist id="grupos">
          <?php foreach (array_unique(array_column($lista, 'grupo')) as $gr): ?><option value="<?= e($gr) ?>"><?php endforeach ?>
        </datalist></div>
      <div><label>Color</label>
        <input type="color" name="color" value="<?= e($editar['color'] ?? '#d4a857') ?>" style="height:40px;padding:4px"></div>
      <div class="acciones">
        <button class="btn btn-oro"><?= $editar ? 'Guardar' : 'Crear categoría' ?></button>
        <?php if ($editar): ?><a class="btn" href="?r=categorias">Cancelar</a><?php endif ?>
      </div>
    </form>
  </div>
</div>
<?php pie_html(); ?>
