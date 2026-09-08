<?php
/** Catálogo de categorías: el vocabulario con el que se explica cada salida. */
exigir_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar') {
        $id     = (int) ($_POST['id'] ?? 0);
        $escrito = mb_substr(limpiar((string) ($_POST['nombre'] ?? '')), 0, 120);
        // Se corrige lo que se escribió mal y se le dice a quien lo escribió:
        // corregir en silencio deja a la persona sin saber qué quedó guardado.
        $arreglo = normalizar_nombre($escrito);
        $nombre  = $arreglo['texto'];
        $aviso   = aviso_correccion($arreglo, $escrito);
        $grupo   = normalizar_nombre(mb_substr(limpiar((string) ($_POST['grupo'] ?? 'General')), 0, 60))['texto'] ?: 'General';
        $color  = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST['color'] ?? '')) ? $_POST['color'] : '#d4a857';
        $padre  = max(0, (int) ($_POST['padre'] ?? 0));
        if ($nombre === '') {
            flash('mal', 'La categoría necesita un nombre.');
            redirigir('?r=categorias');
        }
        // Colgar una categoría de su propia hija dejaría un círculo: la
        // pantalla no la volvería a dibujar nunca y no se podría deshacer.
        $porId = [];
        foreach ($pdo->query('SELECT id, nombre, padre_id FROM categorias') as $c) {
            $porId[(int) $c['id']] = $c;
        }
        if (!madre_valida($porId, $id, $padre)) {
            flash('mal', 'Esa categoría no puede depender de una de las suyas.');
            redirigir('?r=categorias');
        }
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE categorias SET nombre=?, grupo=?, color=?, padre_id=? WHERE id=?')
                    ->execute([$nombre, $grupo, $color, $padre ?: null, $id]);
                flash('ok', 'Categoría actualizada.' . $aviso);
            } else {
                $pdo->prepare('INSERT INTO categorias (nombre, grupo, color, padre_id) VALUES (?,?,?,?)')
                    ->execute([$nombre, $grupo, $color, $padre ?: null]);
                flash('ok', 'Categoría creada.' . $aviso);
            }
        } catch (PDOException $ex) {
            flash('mal', 'Ya existe una categoría con ese nombre.');
        }
        redirigir('?r=categorias');
    }

    if ($accion === 'borrar') {
        $id = (int) $_POST['id'];
        // Las categorías son comunes a todas las unidades de negocio, así que
        // el conteo tiene que serlo también: si no, el aviso diría «3
        // movimientos» mientras se desclasifican 900 en otra unidad.
        $n = (int) $pdo->query("SELECT COUNT(*) FROM movimientos WHERE categoria_id = $id")->fetchColumn();
        $aqui = (int) $pdo->query("SELECT COUNT(*) FROM movimientos m WHERE m.categoria_id = $id
                                    AND " . filtro_sede())->fetchColumn();
        // Las hijas suben al sitio de la madre en vez de quedar sueltas: si no,
        // desaparecerían del árbol con sus movimientos dentro.
        $pdo->prepare('UPDATE categorias SET padre_id = (SELECT * FROM (SELECT padre_id FROM categorias WHERE id = ?) x)
                        WHERE padre_id = ?')->execute([$id, $id]);
        $pdo->prepare('DELETE FROM categorias WHERE id = ?')->execute([$id]);
        flash('ok', $n > 0
            ? "Categoría eliminada. $n movimiento(s) volvieron a quedar sin clasificar"
              . ($n > $aqui ? ", $aqui en esta unidad de negocio y el resto en las demás." : '.')
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

$porId = [];
foreach ($lista as $c) {
    $porId[(int) $c['id']] = $c;
}
$arbol = categorias_arbol($lista);

encabezado_html('Categorías', 'categorias', count($lista) . ' categorías activas');
?>
<div class="rejilla" style="grid-template-columns:minmax(0,1fr) 320px;align-items:start">
  <div class="marco-tabla" data-guia="lista">
    <div class="tabla-scroll">
      <table>
        <thead><tr><th>Grupo</th><th>Categoría</th><th class="der">Movimientos</th><th class="der">Total Bs</th><th></th></tr></thead>
        <tbody>
        <?php $g = ''; foreach ($arbol as $c): $nivel = (int) $c['nivel']; ?>
          <tr>
            <td style="color:var(--mudo);font-size:0.7812rem"><?= $nivel === 0 && $c['grupo'] !== $g ? e($g = $c['grupo']) : '' ?></td>
            <td><span class="rama" style="padding-left:<?= $nivel * 1.375 ?>rem"><?php if ($nivel > 0): ?><i class="rama-guion"></i><?php endif ?><span class="etq"><i style="background:<?= e($c['color']) ?>"></i><?= e($c['nombre']) ?></span></span></td>
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
      <div><label>Depende de</label>
        <select name="padre">
          <option value="0">De nada: es una categoría principal</option>
          <?php foreach ($arbol as $op): if ((int) $op['id'] === (int) ($editar['id'] ?? 0)) continue;
                if (!madre_valida($porId, (int) ($editar['id'] ?? 0), (int) $op['id'])) continue; ?>
            <option value="<?= (int) $op['id'] ?>" <?= (int) ($editar['padre_id'] ?? 0) === (int) $op['id'] ? 'selected' : '' ?>>
              <?= e(categoria_camino($porId, (int) $op['id'])) ?></option>
          <?php endforeach ?>
        </select>
        <span class="nota">Una categoría que depende de otra suma en su total y se puede ver aparte.</span></div>
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
