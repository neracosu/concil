<?php
/**
 * Mejoras: qué ha ido recibiendo el sistema, en orden, y en qué versión va.
 *
 * No consulta la base ni recibe formularios. Solo lee lib/mejoras.php y lo
 * dibuja: quien entra aquí quiere saber qué se ha hecho, no configurar nada.
 */
exigir_login();

$grupos = mejoras_por_mes();
$tipos  = tipos_mejora();
$cuenta = cuenta_mejoras();
$total  = count(mejoras());

encabezado_html('Mejoras', 'mejoras',
    $total . ' mejoras desde que arrancó · versión ' . APP_VERSION);
?>

<div class="mejoras-cabecera">
  <div class="mejoras-etiquetas">
    <span class="etq mejoras-total">Todas: <b><?= $total ?></b></span>
    <?php foreach ($tipos as $clave => $t): ?>
      <?php if (!empty($cuenta[$clave])): ?>
        <span class="etq"><i style="background:<?= e($t['color']) ?>"></i>
          <?= e($t['rotulo']) ?>: <b><?= (int) $cuenta[$clave] ?></b></span>
      <?php endif ?>
    <?php endforeach ?>
  </div>
  <div class="mejoras-version">
    <div class="rotulo">Versión de hoy</div>
    <div class="valor">v<?= e(APP_VERSION) ?></div>
  </div>
</div>

<details class="mejoras-que-es">
  <summary>¿Qué quiere decir ese número?</summary>
  <div>
    <p>El número tiene dos partes, por ejemplo <b>v<?= e(APP_VERSION) ?></b>:</p>
    <ul>
      <li>La <b>primera</b> cambia cuando el sistema cambia de cara. Ha pasado una vez.</li>
      <li>La <b>segunda</b> sube cada vez que el sistema aprende a hacer algo que antes no hacía.</li>
    </ul>
    <p>Sirve para una cosa muy concreta: si algo se ve distinto a como se lo explicaron,
       este número dice qué versión está usando.</p>
  </div>
</details>

<?php foreach ($grupos as $grupo): ?>
  <h2 class="mejoras-mes"><?= e($grupo['rotulo']) ?></h2>
  <div class="mejoras">
    <?php foreach ($grupo['mejoras'] as $m): ?>
      <?php $t = $tipos[$m['tipo']] ?? $tipos['mejora']; ?>
      <article class="mejora">
        <span class="mejora-punto" style="background:<?= e($t['color']) ?>"></span>
        <div class="mejora-alto">
          <span class="etq"><i style="background:<?= e($t['color']) ?>"></i><?= e($t['rotulo']) ?></span>
          <time><?= e(fecha_mejora($m['fecha'])) ?></time>
          <span class="mejora-version">v<?= e($m['version']) ?></span>
        </div>
        <h3><?= e($m['titulo']) ?></h3>
        <p><?= e($m['resumen']) ?></p>
        <?php if (!empty($m['detalles'])): ?>
          <ul>
            <?php foreach ($m['detalles'] as $d): ?>
              <li><?= e($d) ?></li>
            <?php endforeach ?>
          </ul>
        <?php endif ?>
      </article>
    <?php endforeach ?>
  </div>
<?php endforeach ?>

<p class="mejoras-pie">
  ¿Echa algo en falta o se le ocurre una mejora? Dígaselo a
  <b><?= e(APP_SOPORTE) ?></b><?php $tel = soporte_telefono(); if ($tel !== ''): ?>,
  <a href="tel:<?= e(preg_replace('/\D/', '', $tel)) ?>" class="mejoras-tel"><?= e($tel) ?></a><?php endif ?>.
  Así fue como entraron casi todas las de esta lista.
</p>

<?php pie_html(); ?>
