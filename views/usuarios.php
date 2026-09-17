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

    if ($accion === 'maestro') {
        $id  = (int) ($_POST['id'] ?? 0);
        $dar = (string) ($_POST['valor'] ?? '0') === '1';
        $err = cambiar_maestro($id, $dar);
        // Quien se quita el rol a sí mismo ya no puede volver aquí: si se le
        // mandara a esta pantalla, el «solo el maestro» de arriba le pisaría
        // el mensaje y no sabría si el cambio se hizo.
        if ($err === null && !$dar && $id === usuario_id_actual()) {
            flash('ok', 'Listo. Usted ya no es maestro: dejó de ver Usuarios y Rastro y auditoría. '
                      . 'Si lo necesita de vuelta, pídaselo a otro maestro.');
            redirigir('?r=perfil');
        }
        $nombre = (string) (usuario($id)['nombre'] ?? '');
        flash($err === null ? 'ok' : 'mal', $err ?? ($dar
            ? "«{$nombre}» ahora es maestro: ya puede dar de alta a otras personas y ver el rastro de todos."
            : "«{$nombre}» ya no es maestro. Todo lo demás lo sigue haciendo igual."));
        redirigir('?r=usuarios');
    }
}

$lista = usuarios();
// El botón de quitar el rol no se le dibuja al único maestro que queda. La
// puerta de verdad está en `cambiar_maestro()`; esto solo evita ofrecer algo
// que se va a rechazar.
$maestrosActivos = count(array_filter($lista, fn($u) => $u['maestro'] && $u['activo']));
$yoId = usuario_id_actual();
$principalId = usuario_principal_id();
// La misma lista y la misma ventana que la barra de arriba: con diez minutos
// aquí y cuatro allá, la misma persona salía en una y no en la otra.
$activos = presencia_viva(true);
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
            <a href="?r=persona&amp;id=<?= (int) $a['id'] ?>"><b><?= e($a['nombre']) ?></b></a><?= $a['maestro'] ? ' <span class="etq">maestro</span>' : '' ?>
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
      <thead><tr><th>Persona</th><th>Ahora</th><th>Última entrada</th><th>Estado</th><th class="acciones-fijas"></th></tr></thead>
      <tbody>
      <?php foreach ($lista as $u):
          $act = $enPantalla[(int) $u['id']] ?? null;
          $esPrincipal = (int) $u['id'] === $principalId; ?>
        <tr>
          <td><a href="?r=persona&amp;id=<?= (int) $u['id'] ?>" title="Ver todo lo de esta persona"><b><?= e($u['nombre']) ?></b></a>
            <?php if ($u['maestro']): ?><span class="etq"><?= $esPrincipal ? 'maestro principal' : 'maestro' ?></span><?php endif ?></td>
          <td style="font-size:12.5px;color:var(--mudo)">
            <?= $act ? 'En ' . e(nombre_pantalla((string) $act['pantalla'])) : '—' ?></td>
          <td class="fecha"><?= $u['ultimo_acceso']
              ? e(date('d/m/y H:i', strtotime((string) $u['ultimo_acceso']))) : 'nunca' ?></td>
          <td><?= $u['activo']
              ? '<span class="etq"><i style="background:var(--entrada)"></i>puede entrar</span>'
              : '<span class="etq vacia">dado de baja</span>' ?></td>
          <?php if ($esPrincipal): ?>
          <td class="acciones-fijas"><span class="nota" style="color:var(--mudo);font-size:0.8125rem">protegido</span></td>
          <?php else: ?>
          <td class="acciones-fijas">
            <?php
            $puedeQuitar = $u['maestro'] && !($u['activo'] && $maestrosActivos <= 1);
            $puedeDar    = !$u['maestro'] && $u['activo'];
            if ($puedeQuitar || $puedeDar):
                $aviso = $puedeDar
                    ? "«{$u['nombre']}» podrá dar de alta y de baja a otras personas, cambiarles el PIN y ver el rastro de todos. ¿Continuar?"
                    : ((int) $u['id'] === $yoId
                        ? 'Usted dejará de ver Usuarios y Rastro y auditoría, y solo otro maestro podrá devolverle el rol. ¿Continuar?'
                        : "«{$u['nombre']}» dejará de ver Usuarios y Rastro y auditoría. Todo lo demás lo sigue haciendo igual. ¿Continuar?");
            ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                <input type="hidden" name="accion" value="maestro">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <input type="hidden" name="valor" value="<?= $puedeDar ? '1' : '0' ?>">
                <button class="btn btn-sm" data-confirmar="<?= e($aviso) ?>"><?= $puedeDar ? 'Hacer maestro' : 'Quitar maestro' ?></button>
              </form>
            <?php endif ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
              <input type="hidden" name="accion" value="activar">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <input type="hidden" name="valor" value="<?= $u['activo'] ? '0' : '1' ?>">
              <button class="btn btn-sm"><?= $u['activo'] ? 'Dar de baja' : 'Reactivar' ?></button>
            </form>
          </td>
          <?php endif ?>
        </tr>
        <tr>
          <td colspan="5" style="padding-top:0">
            <?php if ($esPrincipal && (int) $u['id'] !== $yoId): ?>
            <p class="nota" style="margin:2px 0 12px;color:var(--mudo);font-size:0.8125rem">Su nombre y su PIN solo los cambia esta persona, desde Mi perfil.</p>
            <?php else: ?>
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
            <?php endif ?>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>
<p class="nota" style="margin:10px 2px 0">
  El <b>maestro</b> es quien da de alta y de baja a los demás, les cambia el PIN y ve el rastro de todos.
  En lo demás, todos hacen lo mismo. Puede haber más de un maestro; lo que el sistema no permite es quedarse sin ninguno.
  Al <b>maestro principal</b> nadie puede darlo de baja ni quitarle el rol, y su nombre y su PIN solo los cambia esa persona.
</p>

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
      <thead><tr><th>Cuándo</th><th>Quién</th><th>Qué</th><th>Detalle</th><th>Desde</th><th>Equipo</th></tr></thead>
      <tbody>
      <?php foreach ($rastro as $r): ?>
        <tr>
          <td class="fecha"><?= e(date('d/m/y H:i', strtotime((string) $r['creado_en']))) ?></td>
          <td><?= persona_enlace($r) ?></td>
          <td><?= e(str_replace('_', ' ', (string) $r['accion'])) ?></td>
          <td class="concepto"><span class="txt"><?= e((string) $r['detalle']) ?></span></td>
          <td class="ref"><?= e((string) $r['ip']) ?></td>
          <td class="ref"><?= e((string) $r['dispositivo']) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <p class="nota" style="margin:12px 0 0">
    Esto es lo último, para mirarlo de un vistazo. Si necesita revisar a fondo —a qué hora entró
    alguien, desde qué conexión, qué pantallas abrió— está todo en
    <a href="?r=auditoria">Rastro y auditoría</a>.
  </p>
</div>

<?php pie_html(); ?>
