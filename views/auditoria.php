<?php
/**
 * El rastro completo: quién hizo qué, desde dónde y con qué equipo.
 *
 * La pantalla de Usuarios enseña lo último de cada quien, que es lo que se
 * mira a diario. Esto es lo otro: el día que haya que reconstruir lo que pasó
 * —una carga que nadie recuerda haber hecho, un acceso a deshora—, aquí está
 * cada paso con su hora, su IP y su navegador.
 *
 * Solo el maestro. No es información de trabajo: es información sobre las
 * personas, y quien la mira tiene que ser el mismo que decide quién entra.
 */
exigir_login();

if (!es_maestro()) {
    flash('mal', 'Solo el maestro puede ver el rastro.');
    redirigir('?r=panel');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'purgar') {
        $dias = max(1, (int) ($_POST['dias'] ?? 90));
        $r = purgar_rastro($dias);
        bitacora('rastro_purgado', "Anterior a $dias días · {$r['acciones']} acciones y {$r['pantallas']} pantallas");
        flash('ok', 'Se borraron ' . number_format($r['acciones'], 0, ',', '.') . ' acciones y '
                  . number_format($r['pantallas'], 0, ',', '.') . ' pantallas de hace más de ' . $dias . ' días.');
        redirigir('?r=auditoria');
    }

    if ($accion === 'navegacion') {
        $on = ($_POST['valor'] ?? '') === '1';
        guardar_ajuste('rastro_navegacion', $on ? '1' : '0');
        bitacora('rastro_navegacion', $on ? 'Activado' : 'Desactivado');
        flash('ok', $on
            ? 'Desde ahora queda anotada cada pantalla que abre cada quien.'
            : 'Ya no se anotan las pantallas. Lo que alguien cambie sí se sigue guardando.');
        redirigir('?r=auditoria');
    }
}

$filtros = [
    'que'     => in_array($_GET['que'] ?? 'todo', ['todo', 'acciones', 'pantallas'], true)
                 ? (string) ($_GET['que'] ?? 'todo') : 'todo',
    'usuario' => (int) ($_GET['u'] ?? 0),
    'desde'   => preg_replace('/[^0-9-]/', '', (string) ($_GET['d1'] ?? '')),
    'hasta'   => preg_replace('/[^0-9-]/', '', (string) ($_GET['d2'] ?? '')),
    'sesion'  => preg_replace('/[^0-9a-f]/', '', (string) ($_GET['s'] ?? '')),
    'ip'      => preg_replace('/[^0-9a-fA-F.:]/', '', (string) ($_GET['ip'] ?? '')),
];
$r = rastro_filtrado($filtros, max(1, (int) ($_GET['p'] ?? 1)));

/* El rastro se baja a Excel para adjuntarlo a un informe: quien pide una
   auditoría no se lleva una pantalla, se lleva un archivo. */
if (($_GET['export'] ?? '') !== '') {
    $todo = rastro_filtrado($filtros, 1, 5000);
    $filas = [];
    foreach ($todo['filas'] as $f) {
        $filas[] = [
            date('d/m/Y H:i:s', strtotime((string) $f['creado_en'])),
            $f['usuario'],
            $f['clase'] === 'pantalla' ? 'abrió una pantalla' : str_replace('_', ' ', (string) $f['accion']),
            $f['clase'] === 'pantalla' ? nombre_pantalla((string) $f['detalle']) : (string) $f['detalle'],
            (string) $f['sede'],
            (string) $f['ip'],
            (string) $f['dispositivo'],
            (string) $f['sesion'],
        ];
    }
    $enc = ['Cuándo', 'Quién', 'Qué', 'Detalle', 'Unidad', 'Desde (IP)', 'Equipo', 'Visita'];
    $nombre = APP_NOMBRE . '-rastro-' . date('Y-m-d');
    if (($_GET['export'] ?? '') === 'csv') {
        exportar_csv($nombre, $enc, $filas);
    }
    exportar_xlsx($nombre, $enc, $filas);
    exit;
}

$sesion = $filtros['sesion'] !== '' ? resumen_sesion($filtros['sesion']) : [];

$acciones = '<a class="btn btn-sm" href="' . e(url(['export' => 'xlsx'])) . '">Bajar a Excel</a>';
encabezado_html('Rastro y auditoría', 'auditoria',
    number_format($r['total'], 0, ',', '.') . ' anotaciones'
    . ($filtros['sesion'] !== '' ? ' de una sola visita'
       : ($filtros['desde'] === '' ? ' · últimos 7 días' : '')), $acciones);
?>

<form method="get" class="tarjeta filtros" style="margin-bottom:14px">
  <input type="hidden" name="r" value="auditoria">
  <div>
    <label for="f-que">Qué mirar</label>
    <select name="que" id="f-que">
      <option value="todo"      <?= $filtros['que'] === 'todo' ? 'selected' : '' ?>>Todo</option>
      <option value="acciones"  <?= $filtros['que'] === 'acciones' ? 'selected' : '' ?>>Solo lo que alguien cambió</option>
      <option value="pantallas" <?= $filtros['que'] === 'pantallas' ? 'selected' : '' ?>>Solo por dónde anduvo</option>
    </select>
  </div>
  <div>
    <label for="f-u">Persona</label>
    <select name="u" id="f-u">
      <option value="">Todas</option>
      <?php foreach (usuarios() as $u): ?>
        <option value="<?= (int) $u['id'] ?>" <?= $filtros['usuario'] === (int) $u['id'] ? 'selected' : '' ?>>
          <?= e($u['nombre']) ?></option>
      <?php endforeach ?>
    </select>
  </div>
  <div>
    <label for="f-d1">Desde</label>
    <input type="date" name="d1" id="f-d1" value="<?= e($filtros['desde'] ?: date('Y-m-d', strtotime('-7 days'))) ?>">
  </div>
  <div>
    <label for="f-d2">Hasta</label>
    <input type="date" name="d2" id="f-d2" value="<?= e($filtros['hasta'] ?: date('Y-m-d')) ?>">
  </div>
  <div>
    <label for="f-ip">Desde qué conexión</label>
    <input type="text" name="ip" id="f-ip" value="<?= e($filtros['ip']) ?>" placeholder="Ej.: 190.202.1.5">
  </div>
  <div class="acciones">
    <button class="btn btn-oro">Buscar</button>
    <a class="btn btn-sm" href="?r=auditoria">Limpiar</a>
  </div>
</form>

<?php if ($sesion !== [] && $sesion['pantallas'] > 0): ?>
  <div class="aviso aviso-ok" style="margin-bottom:14px">
    Está viendo <b>una sola visita</b>: <?= e((string) (usuario((int) $sesion['usuario_id'])['nombre'] ?? '—')) ?>,
    desde <?= e((string) $sesion['ip']) ?> con <?= e((string) $sesion['dispositivo']) ?>.
    Empezó a las <?= e(date('H:i', strtotime((string) $sesion['inicio']))) ?> del
    <?= e(date('d/m/Y', strtotime((string) $sesion['inicio']))) ?> y lo último que hizo fue a las
    <?= e(date('H:i', strtotime((string) $sesion['fin']))) ?>. Abrió <?= (int) $sesion['pantallas'] ?> pantallas.
    <a href="<?= e(url(['s' => ''])) ?>">Ver todo otra vez</a>
  </div>
<?php endif ?>

<div class="marco-tabla">
  <div class="tabla-scroll">
    <table>
      <thead><tr>
        <th>Cuándo</th><th>Quién</th><th>Qué</th><th>Detalle</th>
        <th>Unidad</th><th>Desde</th><th>Equipo</th><th>Visita</th>
      </tr></thead>
      <tbody>
      <?php foreach ($r['filas'] as $f): $esPantalla = $f['clase'] === 'pantalla'; ?>
        <tr<?= $esPantalla ? ' class="fila-suave"' : '' ?>>
          <td class="fecha"><?= e(date('d/m/y H:i:s', strtotime((string) $f['creado_en']))) ?></td>
          <td><?= persona_enlace($f) ?></td>
          <td><?= $esPantalla
              ? '<span class="etq vacia">abrió</span>'
              : '<span class="etq">' . e(str_replace('_', ' ', (string) $f['accion'])) . '</span>' ?></td>
          <td class="concepto"><span class="txt"><?= $esPantalla
              ? e(nombre_pantalla((string) $f['detalle']))
              : e((string) $f['detalle']) ?></span></td>
          <td class="ref"><?= e((string) $f['sede']) ?></td>
          <td class="ref"><a href="<?= e(url(['ip' => (string) $f['ip'], 'p' => 1])) ?>"><?= e((string) $f['ip']) ?></a></td>
          <td class="ref"><?= e((string) $f['dispositivo']) ?></td>
          <td class="ref"><?php if ($f['sesion'] !== ''): ?>
            <a href="<?= e(url(['s' => (string) $f['sesion'], 'p' => 1, 'd1' => '', 'd2' => ''])) ?>"
               title="Ver esta visita completa"><?= e(substr((string) $f['sesion'], 0, 6)) ?></a>
          <?php endif ?></td>
        </tr>
      <?php endforeach ?>
      <?php if ($r['filas'] === []): ?>
        <tr><td colspan="8" class="vacio">No hay nada anotado en esas fechas.</td></tr>
      <?php endif ?>
      </tbody>
    </table>
  </div>
  <?php paginas_html($r['pagina'], $r['paginas'], $r['total'], 'anotaciones') ?>
</div>

<div class="par" style="margin-top:16px">
  <div class="tarjeta">
    <h2>Guardar por dónde anduvo cada quien</h2>
    <p class="nota" style="margin:0 0 12px">
      Lo que alguien <b>cambia</b> se guarda siempre y eso no se puede apagar. Esto es lo otro:
      dejar anotada también cada pantalla que abre. Es lo que permite reconstruir una jornada
      completa, y ocupa poco: una línea por pantalla.
    </p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="accion" value="navegacion">
      <input type="hidden" name="valor" value="<?= rastro_navegacion() ? '0' : '1' ?>">
      <button class="btn <?= rastro_navegacion() ? '' : 'btn-oro' ?>">
        <?= rastro_navegacion() ? 'Dejar de anotar las pantallas' : 'Anotar también las pantallas' ?>
      </button>
      <span class="nota" style="margin-left:10px"><?= rastro_navegacion()
          ? 'Ahora mismo se están anotando.' : 'Ahora mismo no se anotan.' ?></span>
    </form>
  </div>
  <div class="tarjeta">
    <h2>Limpiar lo más viejo</h2>
    <p class="nota" style="margin:0 0 12px">
      El registro crece solo. Borre lo anterior a los días que quiera conservar; lo que se
      borra no se recupera, así que si lo va a necesitar, bájelo antes a Excel.
    </p>
    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="accion" value="purgar">
      <input type="number" name="dias" value="180" min="1" max="3650" style="width:90px" aria-label="Días a conservar">
      <button class="btn" data-confirmar="¿Borrar lo anterior a esos días? No se puede deshacer.">
        Borrar lo anterior a esos días</button>
    </form>
  </div>
</div>

<?php pie_html(); ?>
