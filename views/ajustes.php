<?php
/** PIN, estado del sistema y bitácora de acceso. */
exigir_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'pin') {
        $actual = preg_replace('/\D/', '', (string) ($_POST['pin_actual'] ?? ''));
        $nuevo  = preg_replace('/\D/', '', (string) ($_POST['pin_nuevo'] ?? ''));
        $rep    = preg_replace('/\D/', '', (string) ($_POST['pin_repetir'] ?? ''));

        if (!password_verify((string) $actual, pin_hash())) {
            flash('mal', 'El PIN actual no es correcto.');
        } elseif ($nuevo !== $rep) {
            flash('mal', 'El PIN nuevo y su repetición no coinciden.');
        } else {
            $err = cambiar_pin((string) $nuevo);
            flash($err === null ? 'ok' : 'mal', $err ?? 'PIN actualizado. Úsalo en el próximo ingreso.');
        }
        redirigir('?r=ajustes');
    }

    if ($accion === 'purgar') {
        $dias = max(1, (int) ($_POST['dias'] ?? 90));
        $s = $pdo->prepare('DELETE FROM bitacora WHERE creado_en < DATE_SUB(NOW(), INTERVAL ? DAY)');
        $s->execute([$dias]);
        flash('ok', $s->rowCount() . ' registros de bitácora eliminados.');
        redirigir('?r=ajustes');
    }
}

$sedeId = (int) sede_actual();
$stats = $pdo->query("SELECT COUNT(*) movs,
        (SELECT COUNT(*) FROM cuentas WHERE sede_id = $sedeId) cuentas,
        (SELECT COUNT(*) FROM categorias) cats,
        (SELECT COUNT(*) FROM reglas WHERE activa=1) reglas,
        (SELECT COUNT(*) FROM importaciones i JOIN cuentas cu ON cu.id = i.cuenta_id
          WHERE cu.sede_id = $sedeId) imports,
        MIN(m.fecha) f1, MAX(m.fecha) f2 FROM movimientos m WHERE " . filtro_sede())->fetch();
$peso = $pdo->query("SELECT ROUND(SUM(data_length + index_length)/1048576, 2) mb
                       FROM information_schema.TABLES WHERE table_schema = DATABASE()")->fetchColumn();
$log = $pdo->query('SELECT * FROM bitacora ORDER BY id DESC LIMIT 25')->fetchAll();
$fallos = fallos_recientes(25);
$pendInicial = ajuste('pin_inicial_pendiente') === '1';

encabezado_html('Ajustes', 'ajustes', 'Acceso, estado del sistema y bitácora');
?>
<div class="rejilla rejilla-3" style="margin-bottom:16px">
  <div class="cifra"><div class="rotulo">Movimientos</div>
    <div class="valor"><?= number_format((int) $stats['movs'], 0, ',', '.') ?></div>
    <div class="pie"><?= $stats['f1'] ? e(date('d/m/Y', strtotime($stats['f1'])) . ' → ' . date('d/m/Y', strtotime($stats['f2']))) : 'sin datos' ?></div></div>
  <div class="cifra"><div class="rotulo">Reglas activas</div>
    <div class="valor"><?= (int) $stats['reglas'] ?></div>
    <div class="pie"><?= (int) $stats['cats'] ?> categorías · <?= (int) $stats['cuentas'] ?> cuentas</div></div>
  <div class="cifra"><div class="rotulo">Tamaño de la base</div>
    <div class="valor"><?= e((string) $peso) ?> MB</div>
    <div class="pie"><?= (int) $stats['imports'] ?> cargas registradas</div></div>
</div>

<div class="rejilla rejilla-2" style="align-items:start">
  <div class="tarjeta" data-guia="pin">
    <h2>Cambiar el PIN</h2>
    <p class="nota" style="margin:0 0 12px">Cambia <b>su</b> PIN, el de <?= e(nombre_usuario()) ?>.
      Los de las demás personas se cambian en <a href="?r=usuarios">Usuarios</a>.</p>
    <?php if ($pendInicial): ?>
      <div class="aviso aviso-nota">Sigue activo el PIN inicial. Cámbialo ahora.</div>
    <?php endif ?>
    <form method="post" class="pila">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="accion" value="pin">
      <div><label>PIN actual</label>
        <input type="password" name="pin_actual" inputmode="numeric" maxlength="6" required autocomplete="current-password"></div>
      <div class="par">
        <div><label>PIN nuevo</label>
          <input type="password" name="pin_nuevo" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="new-password"></div>
        <div><label>Repetir</label>
          <input type="password" name="pin_repetir" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="new-password"></div>
      </div>
      <p style="color:var(--tenue);font-size:12.5px;margin:0">
        6 dígitos. Tras <?= MAX_INTENTOS ?> intentos fallidos el acceso se bloquea <?= (int) (BLOQUEO_SEGS / 60) ?> minutos.</p>
      <div class="acciones"><button class="btn btn-oro">Cambiar PIN</button></div>
    </form>
  </div>

  <div class="tarjeta">
    <h2>Si algo falla</h2>
    <?php if ($fallos === []): ?>
      <p class="nota" style="margin:0">
        <b>No se ha registrado ningún fallo.</b>
        Cuando el sistema no pueda continuar, mostrará un código a quien lo esté usando
        y aquí quedará anotado qué ocurrió, en qué pantalla y en qué punto del programa.
      </p>
    <?php else: ?>
      <p class="nota" style="margin:0 0 12px">
        <b><?= count($fallos) ?> <?= count($fallos) === 1 ? 'fallo anotado' : 'fallos anotados' ?>.</b>
        Si alguien le da un código, búsquelo aquí. Los registros se guardan fuera de la web,
        en <?= e(DATA_DIR) ?>/registro.
      </p>
      <div class="tabla-scroll" style="border:1px solid var(--linea);border-radius:var(--r-sm)">
        <table>
          <thead><tr><th>Código</th><th>Cuándo</th><th>Qué pasó</th><th>Dónde</th><th>Pantalla</th></tr></thead>
          <tbody>
          <?php foreach ($fallos as $f): ?>
            <tr>
              <td class="ref"><b><?= e($f['codigo'] ?? '') ?></b></td>
              <td class="fecha"><?= e(date('d/m/y H:i', strtotime($f['cuando'] ?? 'now'))) ?></td>
              <td class="concepto">
                <span class="txt"><?= e($f['tipo'] ?? '') ?></span>
                <span class="nota"><?= e(mb_strimwidth((string) ($f['mensaje'] ?? ''), 0, 110, '…')) ?></span>
              </td>
              <td class="ref" style="font-size:12px"><?= e($f['donde'] ?? '') ?></td>
              <td style="font-size:12.5px;color:var(--mudo)"><?= e($f['pantalla'] ?? '') ?></td>
            </tr>
            <?php if (!empty($f['como'])): ?>
              <tr><td colspan="5" style="padding-top:0">
                <span class="nota" style="font-size:11.5px;color:var(--tenue)">Cómo se llegó: <?= e(mb_strimwidth((string) $f['como'], 0, 190, '…')) ?></span>
              </td></tr>
            <?php endif ?>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
  </div>

  <div class="tarjeta">
    <h2>Dónde viven los datos</h2>
    <dl style="margin:0">
      <div class="dato"><dt>Base de datos</dt><dd class="texto"><?= e(secretos()['db_name']) ?></dd></div>
      <div class="dato"><dt>Credenciales</dt><dd class="texto" style="font-size:12px"><?= e(SECRETS) ?></dd></div>
      <div class="dato"><dt>Archivos subidos</dt><dd class="texto" style="font-size:12px"><?= e(UPLOAD_DIR) ?></dd></div>
      <div class="dato"><dt>Registro de fallos</dt><dd class="texto" style="font-size:12px"><?= e(registro_dir()) ?></dd></div>
      <div class="dato"><dt>Fallos este mes</dt>
        <dd><?= fallos_del_mes() === 0
            ? '<span style="color:var(--entrada)">ninguno</span>'
            : '<span style="color:var(--pendiente)">' . fallos_del_mes() . '</span>' ?></dd></div>
      <div class="dato"><dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd></div>
      <div class="dato"><dt>OPcache</dt>
        <dd><?= function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false)
            ? '<span style="color:var(--entrada)">activo</span>'
            : '<span style="color:var(--pendiente)">apagado</span>' ?></dd></div>
    </dl>
    <p style="color:var(--mudo);font-size:12.5px;margin:14px 0 0">
      Ni la base ni los archivos subidos son accesibles desde la web: viven fuera de <b>public_html</b>.
      Para respaldar, usa el asistente de copias de seguridad de cPanel o exporta desde phpMyAdmin.
    </p>
  </div>
</div>

<div class="marco-tabla" style="margin-top:16px">
  <div class="tabla-scroll">
    <table>
      <thead><tr><th>Cuándo</th><th>Acción</th><th>Detalle</th><th>Origen</th></tr></thead>
      <tbody>
      <?php foreach ($log as $l): ?>
        <tr>
          <td class="fecha"><?= e(date('d/m/Y H:i', strtotime($l['creado_en']))) ?></td>
          <td><span class="etq"><?= e($l['accion']) ?></span></td>
          <td style="font-size:13px;color:var(--suave)"><?= e($l['detalle']) ?></td>
          <td class="ref"><?= e($l['ip']) ?></td>
        </tr>
      <?php endforeach ?>
      <?php if ($log === []): ?><tr><td colspan="4" class="vacio">Sin registros todavía.</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
  <div class="paginas">
    <span>Últimos 25 registros</span>
    <form method="post" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="accion" value="purgar">
      <input type="number" name="dias" value="90" min="1" max="3650" style="width:80px" aria-label="Días a conservar">
      <button class="btn btn-sm" data-confirmar="¿Borrar los registros de bitácora más antiguos?">Limpiar bitácora anterior a N días</button>
    </form>
  </div>
</div>
<?php pie_html(); ?>
