<?php
/**
 * Unidades de negocio. Hace de dos cosas: la pantalla donde se elige con cuál
 * trabajar al entrar, y donde se crean y renombran.
 *
 * Al no haber ninguna elegida, el front controller manda aquí, así que esta
 * vista no puede dar por hecho que sede_actual() devuelva algo.
 */
exigir_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $nombre = mb_substr(limpiar((string) ($_POST['nombre'] ?? '')), 0, 120);
        if ($nombre === '') {
            flash('mal', 'Escribe el nombre de la unidad de negocio.');
            redirigir('?r=sede');
        }
        try {
            $id = sede_id($nombre);
        } catch (PDOException $ex) {
            flash('mal', 'Ya existe una unidad de negocio con ese nombre.');
            redirigir('?r=sede');
        }
        fijar_sede($id);
        bitacora('sede_creada', $nombre);
        flash('ok', 'Unidad «' . $nombre . '» creada. Ya estás trabajando en ella: '
                  . 'lo primero es cargar sus extractos.');
        redirigir('?r=carga');
    }

    if ($accion === 'renombrar') {
        $id = (int) ($_POST['id'] ?? 0);
        $nombre = mb_substr(limpiar((string) ($_POST['nombre'] ?? '')), 0, 120);
        if ($id > 0 && $nombre !== '') {
            try {
                $pdo->prepare('UPDATE sedes SET nombre = ? WHERE id = ?')->execute([$nombre, $id]);
                flash('ok', 'Nombre actualizado.');
                bitacora('sede_renombrada', "id=$id → $nombre");
            } catch (PDOException $ex) {
                flash('mal', 'Ya hay otra unidad de negocio con ese nombre.');
            }
        }
        redirigir('?r=sede');
    }

    if ($accion === 'elegir') {
        fijar_sede((int) ($_POST['id'] ?? 0));
        redirigir('?r=panel');
    }
}

/* Cuántas cuentas y movimientos tiene cada unidad, para que la elección se
   haga con contexto y no a ciegas. */
$lista = $pdo->query("SELECT s.id, s.nombre,
                             COUNT(DISTINCT c.id) cuentas,
                             COUNT(m.id) movs,
                             MAX(m.fecha) ultima
                        FROM sedes s
                   LEFT JOIN cuentas c ON c.sede_id = s.id
                   LEFT JOIN movimientos m ON m.cuenta_id = c.id
                    GROUP BY s.id ORDER BY s.nombre")->fetchAll();

$activa = sede_elegida() ? sede_actual() : null;
$eligiendo = !sede_elegida();

/* ------------------------------------------------------------------ */
/* Al entrar: carta centrada sobre un velo, con el mismo lenguaje que  */
/* la visita guiada. No lleva menú porque no hay a dónde ir todavía.   */
/* ------------------------------------------------------------------ */
if ($eligiendo):
    $flash = $mensaje['texto'] ?? '';
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Elegir unidad · <?= e(APP_CREDITO) ?></title>
<link rel="icon" type="image/png" href="/icon.png">
<link rel="stylesheet" href="assets/app.css?v=13">
</head>
<body class="elegir-cuerpo">
<div class="elegir-velo">
  <div class="elegir-carta">
    <div class="elegir-kicker"><?= e(APP_NOMBRE) ?> <span>by <?= e(APP_MARCA) ?></span></div>
    <h1 class="elegir-titulo">¿Con cuál vas a trabajar?</h1>
    <p class="elegir-texto">
      Cada empresa o tienda del grupo lleva <b>sus propias cuentas y sus propios pagos</b>.
      Elija una y verá solo lo suyo.
    </p>

    <?php if ($flash !== ''): ?>
      <div class="elegir-aviso"><?= e($flash) ?></div>
    <?php endif ?>

    <div class="elegir-lista">
      <?php foreach ($lista as $s): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
          <input type="hidden" name="accion" value="elegir">
          <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
          <button class="elegir-opcion">
            <span class="elegir-opcion__nombre"><?= e($s['nombre']) ?></span>
            <span class="elegir-opcion__dato">
              <?php if ((int) $s['movs'] > 0): ?>
                <?= number_format((int) $s['cuentas'], 0, ',', '.') ?> cuenta<?= $s['cuentas'] == 1 ? '' : 's' ?> ·
                <?= number_format((int) $s['movs'], 0, ',', '.') ?> pagos ·
                hasta el <?= e(date('d/m/Y', strtotime((string) $s['ultima']))) ?>
              <?php else: ?>
                Todavía sin pagos cargados
              <?php endif ?>
            </span>
            <span class="elegir-opcion__flecha" aria-hidden="true">→</span>
          </button>
        </form>
      <?php endforeach ?>
    </div>

    <details class="elegir-nueva"<?= $lista === [] ? ' open' : '' ?>>
      <summary>Añadir otra empresa o tienda</summary>
      <p class="elegir-nota">
        Sus cuentas y sus pagos quedan aparte. Los tipos de gasto se comparten,
        así que lo que el sistema ya aprendió le sirve también a la nueva.
      </p>
      <form method="post" class="elegir-form">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="accion" value="crear">
        <input type="text" name="nombre" maxlength="120" required
               placeholder="Ej.: DISTRIBUIDORA CENTRO" aria-label="Nombre de la unidad">
        <button class="btn btn-oro">Crear y entrar</button>
      </form>
    </details>

    <a class="elegir-salir" href="?r=salir">Cerrar sesión</a>
  </div>
</div>
</body>
</html>
<?php
    return;
endif;

encabezado_html('Unidades de negocio', 'sede',
    count($lista) . ' unidades · las categorías y las reglas son las mismas para todas');
?>

<div class="pila">
  <?php foreach ($lista as $s): $esActiva = (int) $s['id'] === $activa; ?>
    <div class="tarjeta<?= $esActiva ? ' tarjeta-activa' : '' ?>">
      <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <div style="flex:1;min-width:220px">
          <b style="font-size:16px"><?= e($s['nombre']) ?></b>
          <?php if ($esActiva): ?><span class="etq" style="margin-left:8px">Trabajando aquí</span><?php endif ?>
          <p class="nota" style="margin:6px 0 0">
            <?php if ((int) $s['movs'] > 0): ?>
              <?= number_format((int) $s['cuentas'], 0, ',', '.') ?> cuenta<?= $s['cuentas'] == 1 ? '' : 's' ?> ·
              <?= number_format((int) $s['movs'], 0, ',', '.') ?> movimientos ·
              último el <?= e(date('d/m/Y', strtotime((string) $s['ultima']))) ?>
            <?php else: ?>
              Todavía sin movimientos. Al entrar, lo primero es cargar sus extractos.
            <?php endif ?>
          </p>
        </div>
        <?php if (!$esActiva): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="accion" value="elegir">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button class="btn btn-oro">Entrar aquí</button>
          </form>
        <?php endif ?>
      </div>
      <?php if (!$eligiendo): ?>
        <form method="post" class="par" style="margin-top:14px;align-items:end">
          <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
          <input type="hidden" name="accion" value="renombrar">
          <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
          <div>
            <label>Cambiar el nombre</label>
            <input type="text" name="nombre" value="<?= e($s['nombre']) ?>" maxlength="120" required>
          </div>
          <div><button class="btn">Guardar</button></div>
        </form>
      <?php endif ?>
    </div>
  <?php endforeach ?>

  <div class="tarjeta">
    <h2>Añadir otra unidad de negocio</h2>
    <p class="nota" style="margin:0 0 12px">
      Por ejemplo, otra tienda o empresa del consorcio. Sus cuentas y sus movimientos
      quedan aparte; las categorías de gasto y las reglas se siguen compartiendo,
      así lo que ya aprendió el sistema sirve también aquí.
    </p>
    <form method="post" class="par" style="align-items:end">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="accion" value="crear">
      <div>
        <label>Nombre</label>
        <input type="text" name="nombre" maxlength="120" required placeholder="Ej.: DISTRIBUIDORA CENTRO">
      </div>
      <div><button class="btn btn-oro">Crear y entrar</button></div>
    </form>
  </div>
</div>

<?php pie_html(); ?>
