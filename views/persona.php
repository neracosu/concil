<?php
/**
 * Todo lo de una persona en un sitio: quién es, desde dónde entra, qué hace y
 * su historial completo.
 *
 * La pantalla de auditoría mira a todo el mundo y sirve para buscar; esta mira
 * a uno solo y sirve para entender. Se llega haciendo clic en cualquier nombre
 * del rastro, que es donde nace la pregunta.
 *
 * Solo el maestro, por lo mismo que la auditoría: es información sobre una
 * persona. Quien quiera ver lo suyo lo tiene en Mi perfil.
 */
exigir_login();

$id = (int) ($_GET['id'] ?? 0);

if (!es_maestro()) {
    // Mirarse a uno mismo no se prohíbe, se redirige a donde eso vive.
    if ($id === (int) (usuario_actual()['id'] ?? 0)) {
        redirigir('?r=perfil');
    }
    flash('mal', 'Solo el maestro puede ver la ficha de otra persona.');
    redirigir('?r=perfil');
}

$p = usuario($id);
if ($p === null) {
    flash('mal', 'Esa persona ya no está en el sistema.');
    redirigir('?r=usuarios');
}

$resumen  = resumen_persona($id);
$labores  = labores_persona($id);
$equipos  = origenes_persona($id, 'dispositivo');
$lugares  = origenes_persona($id, 'ip');
$visitas  = visitas_persona($id);
$historia = historial_persona($id, max(1, (int) ($_GET['p'] ?? 1)));

/* Si está dentro ahora mismo, se dice arriba del todo: cambia lo que uno
   viene a hacer con la pantalla. */
$ahora = null;
if ($id !== (int) usuario_actual()['id']) {          // decirse a uno mismo que está dentro no aporta
    foreach (presencia_viva() as $g) {
        if ((int) $g['id'] === $id) {
            $ahora = $g;
        }
    }
}

$acciones = '<a class="btn btn-sm" href="?r=auditoria&u=' . $id . '">Ver su rastro con filtros</a>'
          . '<a class="btn btn-sm" href="?r=usuarios">Volver a Usuarios</a>';

// El título lo escapa el armazón; el subtítulo no, por eso este sí lleva e().
encabezado_html((string) $p['nombre'], 'persona',
    ($p['maestro'] ? 'Maestro · ' : '')
    . ($p['activo'] ? 'puede entrar' : 'dado de baja')
    . ' · dado de alta el ' . e(date('d/m/Y', strtotime((string) $p['creado_en']))), $acciones);
?>

<?php if ($ahora !== null): ?>
  <div class="aviso aviso-ok" style="margin-bottom:14px">
    <b><?= e($p['nombre']) ?> está trabajando ahora mismo</b>, en
    <?= e(nombre_pantalla((string) $ahora['pantalla'])) ?><?= $ahora['sede'] !== '' ? ' · ' . e((string) $ahora['sede']) : '' ?>.
  </div>
<?php endif ?>

<div class="rejilla rejilla-3" style="margin-bottom:16px">
  <div class="cifra"><div class="rotulo">Veces que ha entrado</div>
    <div class="valor"><?= number_format((int) ($resumen['entradas'] ?? 0), 0, ',', '.') ?></div>
    <div class="pie"><?= $p['ultimo_acceso']
        ? 'la última, el ' . e(date('d/m/Y \a \l\a\s H:i', strtotime((string) $p['ultimo_acceso'])))
        : 'todavía no ha entrado' ?></div></div>
  <div class="cifra"><div class="rotulo">Cosas que ha hecho</div>
    <div class="valor"><?= number_format((int) ($resumen['acciones'] ?? 0), 0, ',', '.') ?></div>
    <div class="pie"><?= $resumen['primera']
        ? 'desde el ' . e(date('d/m/Y', strtotime((string) $resumen['primera'])))
        : 'nada anotado todavía' ?></div></div>
  <div class="cifra"><div class="rotulo">Pantallas que ha abierto</div>
    <div class="valor"><?= number_format((int) ($resumen['pantallas'] ?? 0), 0, ',', '.') ?></div>
    <div class="pie">en <?= number_format((int) ($resumen['visitas'] ?? 0), 0, ',', '.') ?> visitas</div></div>
</div>

<div class="par" style="margin-bottom:16px">
  <div class="tarjeta">
    <h2>Desde dónde entra</h2>
    <?php if ($lugares === []): ?>
      <p class="nota" style="margin:0">Todavía no hay ninguna conexión anotada.</p>
    <?php else: ?>
      <table class="tabla-lista">
        <?php foreach ($lugares as $l): ?>
          <tr>
            <td><a href="?r=auditoria&ip=<?= e((string) $l['dato']) ?>"><?= e((string) $l['dato']) ?></a></td>
            <td class="der nota"><?= veces((int) $l['veces']) ?></td>
            <td class="der nota">hasta el <?= e(date('d/m/y', strtotime((string) $l['ultima']))) ?></td>
          </tr>
        <?php endforeach ?>
      </table>
      <p class="nota" style="margin:12px 0 0">
        Si aquí aparece una conexión que no reconoce, es la señal de que alguien más usó su clave.
      </p>
    <?php endif ?>
  </div>

  <div class="tarjeta">
    <h2>Con qué trabaja</h2>
    <?php if ($equipos === []): ?>
      <p class="nota" style="margin:0">Todavía no hay ningún equipo anotado.</p>
    <?php else: ?>
      <table class="tabla-lista">
        <?php foreach ($equipos as $q): ?>
          <tr>
            <td><?= e((string) $q['dato']) ?></td>
            <td class="der nota"><?= veces((int) $q['veces']) ?></td>
            <td class="der nota">hasta el <?= e(date('d/m/y', strtotime((string) $q['ultima']))) ?></td>
          </tr>
        <?php endforeach ?>
      </table>
    <?php endif ?>
  </div>
</div>

<?php if ($labores !== []): ?>
  <div class="tarjeta" style="margin-bottom:16px">
    <h2>En qué se le va el tiempo</h2>
    <div class="etiquetas">
      <?php foreach ($labores as $l): ?>
        <span class="etq etq-cuenta">
          <?= e(str_replace('_', ' ', (string) $l['accion'])) ?>
          <b><?= number_format((int) $l['veces'], 0, ',', '.') ?></b>
        </span>
      <?php endforeach ?>
    </div>
  </div>
<?php endif ?>

<?php if ($visitas !== []): ?>
  <div class="marco-tabla" style="margin-bottom:16px">
    <div class="tabla-scroll">
      <table>
        <thead><tr><th>Visita</th><th>Empezó</th><th>Terminó</th><th>Duró</th>
          <th>Pantallas</th><th>Desde</th><th>Equipo</th></tr></thead>
        <tbody>
        <?php foreach ($visitas as $v): ?>
          <tr>
            <td><a href="?r=auditoria&s=<?= e((string) $v['sesion']) ?>"
                   title="Ver esta visita paso a paso"><?= e(substr((string) $v['sesion'], 0, 6)) ?></a></td>
            <td class="fecha"><?= e(date('d/m/y H:i', strtotime((string) $v['inicio']))) ?></td>
            <td class="fecha"><?= e(date('H:i', strtotime((string) $v['fin']))) ?></td>
            <td class="ref"><?= (int) $v['minutos'] <= 0 ? 'un momento' : (int) $v['minutos'] . ' min' ?></td>
            <td class="der"><?= (int) $v['pantallas'] ?></td>
            <td class="ref"><?= e((string) $v['ip']) ?></td>
            <td class="ref"><?= e((string) $v['dispositivo']) ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <div class="paginas"><span>Sus últimas <?= count($visitas) ?> visitas · haga clic en una para verla paso a paso</span></div>
  </div>
<?php endif ?>

<div class="marco-tabla">
  <div class="tabla-scroll">
    <table>
      <thead><tr><th>Cuándo</th><th>Qué</th><th>Detalle</th><th>Desde</th><th>Equipo</th></tr></thead>
      <tbody>
      <?php foreach ($historia['filas'] as $f): $esPantalla = $f['clase'] === 'pantalla'; ?>
        <tr<?= $esPantalla ? ' class="fila-suave"' : '' ?>>
          <td class="fecha"><?= e(date('d/m/y H:i:s', strtotime((string) $f['creado_en']))) ?></td>
          <td><?= $esPantalla
              ? '<span class="etq vacia">abrió</span>'
              : '<span class="etq">' . e(str_replace('_', ' ', (string) $f['accion'])) . '</span>' ?></td>
          <td class="concepto"><span class="txt"><?= $esPantalla
              ? e(nombre_pantalla((string) $f['detalle']))
              : e((string) $f['detalle']) ?></span></td>
          <td class="ref"><?= e((string) $f['ip']) ?></td>
          <td class="ref"><?= e((string) $f['dispositivo']) ?></td>
        </tr>
      <?php endforeach ?>
      <?php if ($historia['filas'] === []): ?>
        <tr><td colspan="5" class="vacio">Todavía no ha quedado nada anotado a su nombre.</td></tr>
      <?php endif ?>
      </tbody>
    </table>
  </div>
  <?php paginas_html($historia['pagina'], $historia['paginas'], $historia['total'], 'anotaciones suyas') ?>
</div>

<?php pie_html(); ?>
