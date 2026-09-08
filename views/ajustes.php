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

    if ($accion === 'tasas') {
        $r = sincronizar_tasas(true);
        if ($r['error'] !== '') {
            flash('mal', $r['error'] . ' Vuelva a intentarlo en un rato; las tasas ya guardadas siguen ahí.');
        } else {
            flash('ok', 'Tasas del BCV al día: ' . number_format($r['guardadas'], 0, ',', '.') . ' días guardados.');
        }
        bitacora('tasas', 'Actualización manual · ' . $r['guardadas'] . ' días');
        redirigir('?r=ajustes');
    }

    if ($accion === 'tasa_manual') {
        $r = corregir_tasa((string) ($_POST['fecha'] ?? ''), a_monto((string) ($_POST['tasa'] ?? '')));
        flash($r['ok'] ? 'ok' : 'mal', $r['mensaje']);
        redirigir('?r=ajustes');
    }

    if ($accion === 'purgar') {
        $dias = max(1, (int) ($_POST['dias'] ?? 90));
        $r = purgar_rastro($dias);
        flash('ok', 'Se borraron ' . number_format($r['acciones'] + $r['pantallas'], 0, ',', '.')
                  . ' anotaciones de hace más de ' . $dias . ' días.');
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
/* Los fallos, la bitácora y las rutas del servidor son cosa de quien lleva el
   sistema: un código de fallo enseña en qué archivo y en qué línea se rompió, y
   la bitácora dice desde qué conexión trabaja cada quien. Lo demás de esta
   pantalla —el PIN de uno, las tasas, el estado— lo puede ver cualquiera. */
$soyMaestro = es_maestro();
$log    = $soyMaestro ? ultimo_rastro(25) : [];
$fallos = $soyMaestro ? fallos_recientes(25) : [];
$pendInicial = ajuste('pin_inicial_pendiente') === '1';

encabezado_html('Ajustes', 'ajustes',
    $soyMaestro ? 'Acceso, estado del sistema y bitácora' : 'Su clave de entrada y el estado del sistema');
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

  <div class="tarjeta" data-guia="tasas">
    <h2>Tasa del dólar</h2>
    <?php $t = estado_tasas(); ?>
    <p class="nota" style="margin:0 0 12px">
      Junto a cada operación se muestra la tasa oficial del BCV <b>del día en que ocurrió</b>,
      no la de hoy. Así administración puede sacar sus cuentas con el valor que regía entonces,
      aunque el archivo del banco se haya cargado meses después.
    </p>
    <dl style="margin:0 0 14px">
      <div class="dato"><dt>Días guardados</dt><dd><?= number_format($t['dias'], 0, ',', '.') ?></dd></div>
      <?php if ($t['ultima']): ?>
        <div class="dato"><dt>Van desde</dt><dd><?= e(date('d/m/Y', strtotime($t['primera']))) ?></dd></div>
        <div class="dato"><dt>Hasta</dt><dd><?= e(date('d/m/Y', strtotime($t['ultima']))) ?></dd></div>
        <div class="dato"><dt>Tasa de ese día</dt><dd>Bs <?= e(tasa_texto(tasa_de($t['ultima']))) ?></dd></div>
      <?php endif ?>
      <?php if ($t['sincronizado']): ?>
        <div class="dato"><dt>Última consulta</dt><dd><?= e(date('d/m/Y H:i', strtotime($t['sincronizado']))) ?></dd></div>
      <?php endif ?>
    </dl>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="accion" value="tasas">
      <div class="acciones"><button class="btn">Buscar las tasas que falten</button></div>
    </form>

    <details class="tasa-mano" data-guia="tasa-mano">
      <summary>Corregir la tasa de un día</summary>
      <p class="nota" style="margin:0 0 12px">
        Si ese día se trabajó con otro valor, escríbalo aquí. Vale para
        <b>todas las operaciones de esa fecha</b>, y la próxima consulta al BCV
        ya no lo cambia. Los pagos que ya se repartieron entre facturas
        conservan la tasa con la que se guardaron.
      </p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="accion" value="tasa_manual">
        <div class="par">
          <div><label>Día</label>
            <input type="date" name="fecha" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"></div>
          <div><label>Bolívares por dólar</label>
            <input type="text" name="tasa" required inputmode="decimal" placeholder="Ej.: 807,38"></div>
        </div>
        <div class="acciones" style="margin-top:12px"><button class="btn btn-oro">Guardar esa tasa</button></div>
      </form>
    </details>
  </div>

  <?php if ($soyMaestro): ?>
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
  <?php endif ?>

  <?php if ($soyMaestro): ?>
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
  <?php endif ?>
</div>

<?php if ($soyMaestro): ?>
<div class="marco-tabla" style="margin-top:16px">
  <div class="tabla-scroll">
    <table>
      <thead><tr><th>Cuándo</th><th>Quién</th><th>Acción</th><th>Detalle</th><th>Desde</th><th>Equipo</th></tr></thead>
      <tbody>
      <?php foreach ($log as $l): ?>
        <tr>
          <td class="fecha"><?= e(date('d/m/Y H:i', strtotime($l['creado_en']))) ?></td>
          <td><?= persona_enlace($l) ?></td>
          <td><span class="etq"><?= e(str_replace('_', ' ', (string) $l['accion'])) ?></span></td>
          <td style="font-size:0.8125rem;color:var(--suave)"><?= e($l['detalle']) ?></td>
          <td class="ref"><?= e((string) $l['ip']) ?></td>
          <td class="ref"><?= e((string) $l['dispositivo']) ?></td>
        </tr>
      <?php endforeach ?>
      <?php if ($log === []): ?><tr><td colspan="6" class="vacio">Sin registros todavía.</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
  <div class="paginas">
    <span>Últimos 25 registros<?= es_maestro() ? ' · <a href="?r=auditoria">ver el rastro completo</a>' : '' ?></span>
    <form method="post" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="accion" value="purgar">
      <input type="number" name="dias" value="90" min="1" max="3650" style="width:80px" aria-label="Días a conservar">
      <button class="btn btn-sm" data-confirmar="¿Borrar los registros de bitácora más antiguos?">Limpiar bitácora anterior a N días</button>
    </form>
  </div>
</div>
<?php endif ?>
<?php pie_html(); ?>
