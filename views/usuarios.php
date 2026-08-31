<?php
/**
 * Quién puede entrar, y qué está haciendo cada uno ahora mismo.
 *
 * Solo el maestro llega aquí. No porque los demás no puedan hacer su trabajo
 * —todos pueden hacer todo— sino porque dar de alta a alguien es decidir quién
 * entra, y esa decisión tiene un dueño.
 */
exigir_login();

if (!es_maestro()) {
    flash('mal', 'Solo el maestro puede administrar los usuarios.');
    redirigir('?r=perfil');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $pin = preg_replace('/\D/', '', implode('', (array) ($_POST['d'] ?? [])));
        $err = crear_usuario((string) ($_POST['nombre'] ?? ''), (string) $pin);
        flash($err === null ? 'ok' : 'mal',
              $err ?? 'Usuario creado. Dígale su PIN en persona, no por escrito.');
        redirigir('?r=usuarios');
    }

    if ($accion === 'renombrar') {
        $err = renombrar_usuario((int) ($_POST['id'] ?? 0), (string) ($_POST['nombre'] ?? ''));
        flash($err === null ? 'ok' : 'mal', $err ?? 'Nombre actualizado.');
        redirigir('?r=usuarios');
    }

    if ($accion === 'pin') {
        $pin = preg_replace('/\D/', '', implode('', (array) ($_POST['d'] ?? [])));
        $err = cambiar_pin_usuario((int) ($_POST['id'] ?? 0), (string) $pin);
        flash($err === null ? 'ok' : 'mal', $err ?? 'PIN cambiado. Dígaselo en persona.');
        redirigir('?r=usuarios');
    }

    if ($accion === 'activar') {
        $err = activar_usuario((int) ($_POST['id'] ?? 0), (string) ($_POST['valor'] ?? '0') === '1');
        flash($err === null ? 'ok' : 'mal', $err ?? 'Listo.');
        redirigir('?r=usuarios');
    }
}

$lista = usuarios();
$activos = usuarios_activos(10);
$rastro = ultimo_rastro(20);
$enPantalla = [];
foreach ($activos as $a) {
    $enPantalla[(int) $a['id']] = $a;
}

encabezado_html('Usuarios', 'usuarios',
    count($lista) . ' personas · ' . count($activos) . ' trabajando ahora');
?>

<?php if ($activos !== []): ?>
  <div class="tarjeta" style="margin-bottom:14px">
    <h2>Quién está trabajando ahora</h2>
    <div class="presencia">
      <?php foreach ($activos as $a): ?>
        <div class="presencia-uno">
          <span class="presencia-punto"></span>
          <div>
            <b><?= e($a['nombre']) ?></b><?= $a['maestro'] ? ' <span class="etq">maestro</span>' : '' ?>
            <span class="nota">Está en <?= e(nombre_pantalla((string) $a['pantalla'])) ?> ·
              <?= (int) $a['hace'] <= 0 ? 'ahora mismo' : 'visto hace ' . (int) $a['hace'] . ' min' ?></span>
          </div>
        </div>
      <?php endforeach ?>
    </div>
  </div>
<?php endif ?>

<div class="marco-tabla">
  <div class="tabla-scroll">
    <table>
      <thead><tr><th>Persona</th><th>Ahora</th><th>Última entrada</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($lista as $u): $act = $enPantalla[(int) $u['id']] ?? null; ?>
        <tr>
          <td><b><?= e($u['nombre']) ?></b>
            <?php if ($u['maestro']): ?><span class="etq">maestro</span><?php endif ?></td>
          <td style="font-size:12.5px;color:var(--mudo)">
            <?= $act ? 'En ' . e(nombre_pantalla((string) $act['pantalla'])) : '—' ?></td>
          <td class="fecha"><?= $u['ultimo_acceso']
              ? e(date('d/m/y H:i', strtotime((string) $u['ultimo_acceso']))) : 'nunca' ?></td>
          <td><?= $u['activo']
              ? '<span class="etq"><i style="background:var(--entrada)"></i>puede entrar</span>'
              : '<span class="etq vacia">dado de baja</span>' ?></td>
          <td class="der">
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
              <input type="hidden" name="accion" value="activar">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <input type="hidden" name="valor" value="<?= $u['activo'] ? '0' : '1' ?>">
              <button class="btn btn-sm"><?= $u['activo'] ? 'Dar de baja' : 'Reactivar' ?></button>
            </form>
          </td>
        </tr>
        <tr>
          <td colspan="5" style="padding-top:0">
            <div class="usuario-edicion">
              <form method="post" class="usuario-form">
                <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                <input type="hidden" name="accion" value="renombrar">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <input type="text" name="nombre" maxlength="120" value="<?= e($u['nombre']) ?>" aria-label="Nombre">
                <button class="btn btn-sm">Renombrar</button>
              </form>
              <form method="post" class="usuario-form" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                <input type="hidden" name="accion" value="pin">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <div class="pin-campos pin-mini">
                  <?php for ($i = 0; $i < 6; $i++): ?>
                    <input type="password" name="d[]" inputmode="numeric" pattern="\d" maxlength="1"
                           aria-label="Dígito <?= $i + 1 ?>">
                  <?php endfor ?>
                </div>
                <button class="btn btn-sm">Poner PIN nuevo</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>

<div class="tarjeta" style="margin-top:14px">
  <h2>Dar de alta a alguien</h2>
  <p class="nota" style="margin:0 0 12px">
    Cada persona entra con sus propios seis dígitos, y todo lo que haga queda a su nombre.
    Dos personas no pueden compartir PIN: si lo compartieran, no habría forma de saber quién hizo qué.
    <b>Dígale el PIN en persona</b>, no por mensaje.
  </p>
  <form method="post" class="pila" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
    <input type="hidden" name="accion" value="crear">
    <div class="par" style="align-items:end">
      <div>
        <label>Nombre de la persona</label>
        <input type="text" name="nombre" maxlength="120" required placeholder="Ej.: María Rodríguez">
      </div>
      <div>
        <label>PIN de 6 dígitos</label>
        <div class="pin-campos pin-mini">
          <?php for ($i = 0; $i < 6; $i++): ?>
            <input type="password" name="d[]" inputmode="numeric" pattern="\d" maxlength="1"
                   aria-label="Dígito <?= $i + 1 ?>" required>
          <?php endfor ?>
        </div>
      </div>
    </div>
    <div class="acciones"><button class="btn btn-oro">Crear usuario</button></div>
  </form>
</div>

<div class="tarjeta" style="margin-top:14px">
  <h2>Lo último que ha hecho cada quien</h2>
  <div class="tabla-scroll" style="border:1px solid var(--linea);border-radius:var(--r-sm)">
    <table>
      <thead><tr><th>Cuándo</th><th>Quién</th><th>Qué</th><th>Detalle</th></tr></thead>
      <tbody>
      <?php foreach ($rastro as $r): ?>
        <tr>
          <td class="fecha"><?= e(date('d/m/y H:i', strtotime((string) $r['creado_en']))) ?></td>
          <td><b><?= e((string) $r['usuario']) ?></b></td>
          <td><?= e(str_replace('_', ' ', (string) $r['accion'])) ?></td>
          <td class="concepto"><span class="txt"><?= e((string) $r['detalle']) ?></span></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>

<?php pie_html(); ?>
