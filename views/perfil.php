<?php
/** Mi perfil: el nombre con el que quedo firmado y mi PIN de entrada. */
exigir_login();

$yo = usuario_actual();
if ($yo === null) {
    redirigir('?r=salir');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'nombre') {
        $err = renombrar_usuario((int) $yo['id'], (string) ($_POST['nombre'] ?? ''));
        flash($err === null ? 'ok' : 'mal', $err ?? 'Listo. Así aparecerás en el rastro de ahora en adelante.');
        if ($err === null) {
            bitacora('perfil', 'Cambió su nombre');
        }
        redirigir('?r=perfil');
    }

    if ($accion === 'pin') {
        $nuevo = preg_replace('/\D/', '', implode('', (array) ($_POST['d'] ?? [])));
        $err = cambiar_pin((string) $nuevo);
        flash($err === null ? 'ok' : 'mal', $err ?? 'PIN cambiado. Úsalo la próxima vez que entres.');
        redirigir('?r=perfil');
    }
}

/* Lo que he hecho yo, para poder revisar mi propio rastro. */
$mio = db()->prepare('SELECT accion, detalle, creado_en FROM bitacora
                       WHERE usuario_id = ? ORDER BY id DESC LIMIT 20');
$mio->execute([$yo['id']]);
$mias = $mio->fetchAll();

encabezado_html('Mi perfil', 'perfil',
    e($yo['nombre']) . ($yo['maestro'] ? ' · maestro' : '')
    . ($yo['ultimo_acceso'] ? ' · última entrada el ' . e(date('d/m/Y H:i', strtotime((string) $yo['ultimo_acceso']))) : ''));
?>

<div class="par-ancho">
  <div class="tarjeta">
    <h2>Cómo aparezco</h2>
    <p class="nota" style="margin:0 0 12px">
      Este nombre es el que queda escrito cada vez que carga un archivo, justifica un pago
      o corrige algo. Póngalo tal como lo conocen en la empresa.
    </p>
    <form method="post" class="par" style="align-items:end">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="accion" value="nombre">
      <div>
        <label>Nombre</label>
        <input type="text" name="nombre" maxlength="120" required value="<?= e($yo['nombre']) ?>">
      </div>
      <div><button class="btn btn-oro">Guardar</button></div>
    </form>
  </div>

  <div class="tarjeta">
    <h2>Mi PIN</h2>
    <p class="nota" style="margin:0 0 12px">
      Con estos seis dígitos entra usted y solo usted. No lo comparta: todo lo que se haga
      con él quedará a su nombre.
    </p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="accion" value="pin">
      <div class="pin-campos" style="justify-content:flex-start">
        <?php for ($i = 0; $i < 6; $i++): ?>
          <input type="password" name="d[]" inputmode="numeric" pattern="\d" maxlength="1"
                 aria-label="Dígito <?= $i + 1 ?>" required>
        <?php endfor ?>
      </div>
      <div class="acciones" style="margin-top:14px"><button class="btn btn-oro">Cambiar mi PIN</button></div>
    </form>
  </div>
</div>

<div class="tarjeta" style="margin-top:14px">
  <h2>Lo último que he hecho</h2>
  <?php if ($mias === []): ?>
    <p class="nota" style="margin:0">Todavía no ha quedado nada anotado a su nombre.</p>
  <?php else: ?>
    <div class="tabla-scroll" style="border:1px solid var(--linea);border-radius:var(--r-sm)">
      <table>
        <thead><tr><th>Cuándo</th><th>Qué</th><th>Detalle</th></tr></thead>
        <tbody>
        <?php foreach ($mias as $m): ?>
          <tr>
            <td class="fecha"><?= e(date('d/m/y H:i', strtotime((string) $m['creado_en']))) ?></td>
            <td><?= e(str_replace('_', ' ', (string) $m['accion'])) ?></td>
            <td class="concepto"><span class="txt"><?= e((string) $m['detalle']) ?></span></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  <?php endif ?>
</div>

<?php pie_html(); ?>
