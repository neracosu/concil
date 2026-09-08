<?php
/**
 * Carga en dos pasos: primero se analiza cada archivo y se muestra a qué cuenta
 * y banco corresponde; solo al confirmar entran los movimientos a la base.
 */
exigir_login();

$paso = 'subir';
$lote = [];
$resultados = [];
$errFila = [];      // índice del archivo → qué le falta, para señalarlo en su tarjeta

$limite = limite_subida();

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && $_POST === [] && $_FILES === []
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('mal', 'Los archivos pesan más de lo que el servidor acepta de una vez ('
        . ini_get('post_max_size') . '). Súbelos en dos tandas.');
    redirigir('?r=carga');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'analizar' && empty($_FILES['archivos']['name'][0])) {
        flash('mal', 'No elegiste ningún archivo. Haz clic en «Elegir archivos» y busca los del banco en tu computadora.');
        redirigir('?r=carga');
    }

    /* ---------- Paso 1: recibir y analizar ---------- */
    if ($accion === 'analizar' && !empty($_FILES['archivos']['name'][0])) {
        limpiar_lote();
        $errores = [];
        $n = count($_FILES['archivos']['name']);
        for ($i = 0; $i < $n; $i++) {
            $nombre = (string) $_FILES['archivos']['name'][$i];
            $tmp    = (string) $_FILES['archivos']['tmp_name'][$i];
            $err    = (int) $_FILES['archivos']['error'][$i];
            $tam    = (int) $_FILES['archivos']['size'][$i];

            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                $errores[] = "$nombre: " . motivo_fallo_subida($err);
                continue;
            }
            $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
            if (!in_array($ext, EXT_PERMITIDAS, true)) {
                $errores[] = "$nombre: solo se aceptan archivos " . implode(' y ', EXT_PERMITIDAS) . '.';
                continue;
            }
            if ($tam > $limite * 1048576) {
                $errores[] = "$nombre: pesa más de $limite MB, que es el tope del servidor.";
                continue;
            }
            $destino = UPLOAD_DIR . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($tmp, $destino)) {
                $errores[] = "$nombre: no se pudo guardar en el servidor.";
                continue;
            }
            @chmod($destino, 0600);

            try {
                $info = analizar($destino, $ext);
            } catch (Throwable $ex) {
                @unlink($destino);
                $errores[] = "$nombre: " . $ex->getMessage();
                continue;
            }
            $lote[] = [
                'nombre'  => $nombre,
                'ruta'    => $destino,
                'ext'     => $ext,
                'banco'   => $info['banco'],
                'cuenta'  => $info['cuenta'],
                'ok'      => $info['mapa'] !== null,
                'cab'     => $info['cabecera'] ? array_map('limpiar', $info['cabecera']) : [],
                'muestra' => array_slice($info['muestra'], 0, 3),
                'tam'     => $tam,
                'numero'  => $info['numero'],
                'rif'     => $info['rif'],
                'titular' => $info['titular'],
                'codigo'  => $info['codigo'],
                'info'    => $info,
                'cadena'  => $info['cadena'],
                'conocido'=> $info['conocido'],
                'arranque'=> $info['saldo_inicial'],
            ];
        }
        $_SESSION['lote'] = $lote;
        $paso = 'confirmar';
        if ($errores !== []) {
            $mensaje = ['tipo' => 'mal', 'texto' => implode(' ', $errores)];
        }

        // Subir el extracto es cargarlo. Si el archivo no deja nada por
        // confirmar —se sabe a qué cuenta va y su ficha está completa— entra
        // solo, sin un botón de por medio que no aporta nada. La pantalla de
        // preguntas aparece únicamente cuando hace falta preguntar algo.
        $cuentas = cuentas();
        $destinos = [];
        $pendientes = [];
        foreach ($lote as $i => $a) {
            $sug = cuenta_sugerida($a, $cuentas);
            $destinos[$i] = $sug ?? 'nueva';
            $q = preguntas_de($a, $cuentas, $sug);
            if ($q !== []) {
                $pendientes[$i] = $q;
            }
        }
        if ($lote !== [] && $pendientes === []) {
            [$resultados, $fallidos] = procesar_lote($lote, $destinos, [], [], [], [[], [], []]);
            limpiar_lote($fallidos === [] ? null : array_keys($fallidos));
            $paso = 'resultado';
        }
    }

    /* ---------- Deshacer una carga entera ---------- */
    if ($accion === 'deshacer') {
        try {
            $r = deshacer_importacion((int) ($_POST['importacion'] ?? 0));
            bitacora('importacion_deshecha', $r['archivo'] . ' · ' . $r['movimientos'] . ' movimientos');
            flash('ok', 'Se deshizo la carga de «' . $r['archivo'] . '». Se quitaron '
                . number_format($r['movimientos'], 0, ',', '.') . ' movimientos de ' . $r['cuenta'] . '.');
        } catch (Throwable $ex) {
            flash('mal', 'No se pudo deshacer: ' . $ex->getMessage());
        }
        redirigir('?r=carga');
    }

    /* ---------- Volver a la pantalla anterior sin perder lo subido ---------- */
    if ($accion === 'reintentar') {
        $lote = $_SESSION['lote'] ?? [];
        $paso = $lote === [] ? 'subir' : 'confirmar';
    }

    /* ---------- Paso 2: importar ---------- */
    if ($accion === 'importar') {
        $lote = $_SESSION['lote'] ?? [];
        $elegidas = (array) ($_POST['cuenta'] ?? []);
        $nuevas   = (array) ($_POST['cuenta_nueva'] ?? []);
        $omitir   = (array) ($_POST['omitir'] ?? []);

        // Nada se importa hasta que todos los archivos tengan a dónde ir. Antes
        // se empezaba de una y el primer error se llevaba por delante el lote
        // entero: los archivos ya subidos se borraban y la pantalla de
        // resultado no tenía vuelta atrás, así que había que subirlo todo otra
        // vez solo por no haberle puesto nombre a una cuenta.
        $errFila = [];
        foreach ($lote as $i => $a) {
            if (!$a['ok'] || isset($omitir[$i]) || !is_file($a['ruta'])) {
                continue;
            }
            $elegida = (string) ($elegidas[$i] ?? '');
            if ($elegida === 'nueva' || (int) $elegida <= 0) {
                if ((trim((string) ($nuevas[$i] ?? '')) ?: $a['cuenta']) === '') {
                    $errFila[$i] = 'Escriba cómo se va a llamar esta cuenta. Sin nombre no se puede crear, ni elegirla después.';
                }
            }
        }
        if ($errFila !== []) {
            $paso = 'confirmar';
            $mensaje = ['tipo' => 'mal', 'texto' => 'Falta el nombre de ' . count($errFila)
                . ' cuenta(s). Sus archivos siguen aquí: complete lo que falta y vuelva a darle a importar.'];
        }
    }

    if ($accion === 'importar' && $errFila === []) {
        [$resultados, $fallidos] = procesar_lote($lote, $elegidas, $nuevas, $omitir,
            (array) ($_POST['banco_nuevo'] ?? []),
            [(array) ($_POST['f_numero'] ?? []), (array) ($_POST['f_titular'] ?? []), (array) ($_POST['f_rif'] ?? [])]);
        limpiar_lote($fallidos === [] ? null : array_keys($fallidos));
        $paso = 'resultado';
    }
}

if ($paso === 'subir') {
    limpiar_lote();
}

/**
 * Importa lo que haya en el lote y devuelve [lo que pasó, los que fallaron].
 *
 * Vive en una función porque hay dos caminos hasta aquí: el archivo que entra
 * solo en cuanto se sube, y el que necesitó que alguien confirmara algo antes.
 */
function procesar_lote(array $lote, array $elegidas, array $nuevas, array $omitir,
                       array $bancos, array $ficha): array
{
    [$fNums, $fTits, $fRifs] = $ficha;
    $resultados = [];
    $fallidos = [];

        foreach ($lote as $i => $a) {
            if (!$a['ok'] || isset($omitir[$i]) || !is_file($a['ruta'])) {
                continue;
            }
            try {
                // Lo que se sepa de la ficha: lo escrito en la pantalla y, si
                // no, lo que el propio extracto declara.
                $fNum = trim((string) ($fNums[$i] ?? '')) ?: (string) ($a['numero'] ?? '');
                $fTit = trim((string) ($fTits[$i] ?? '')) ?: (string) ($a['titular'] ?? '');
                $fRif = trim((string) ($fRifs[$i] ?? '')) ?: (string) ($a['rif'] ?? '');
                if (str_contains($fNum, '*')) {
                    $fNum = '';     // el número tapado no identifica la cuenta
                }

                $nueva = ($elegidas[$i] ?? '') === 'nueva' || (int) ($elegidas[$i] ?? 0) <= 0;
                if ($nueva) {
                    $nombre = trim((string) ($nuevas[$i] ?? '')) ?: $a['cuenta'];
                    if ($nombre === '') {
                        // Lo único imprescindible es cómo se va a llamar: una
                        // cuenta sin nombre no se puede ni elegir después.
                        throw new RuntimeException('escribe un nombre para la cuenta y vuelve a intentarlo.');
                    }
                    // El banco puede venir escrito a mano: cinco de los once
                    // extractos no dicen de qué banco son.
                    $cid = cuenta_id($nombre, trim((string) ($bancos[$i] ?? '')) ?: (string) $a['banco']);
                } else {
                    $cid = (int) $elegidas[$i];
                }
                // El id viene del formulario y podría estar manipulado: sin
                // esto se podría cargar un extracto en una cuenta de otra
                // unidad de negocio.
                $suya = db()->prepare('SELECT COUNT(*) FROM cuentas WHERE id = ? AND sede_id = ?');
                $suya->execute([$cid, (int) sede_actual()]);
                if ((int) $suya->fetchColumn() === 0) {
                    throw new RuntimeException('esa cuenta no es de esta unidad de negocio.');
                }

                $choque = choque_de_banco($cid, $a);
                if ($choque !== '') {
                    throw new RuntimeException($choque);
                }

                // La ficha se completa con lo que se escribió en la pantalla.
                // Si aún falta algo la carga sigue: solo se avisa, porque sin
                // esos datos el sistema protege peor, no deja de funcionar.
                completar_ficha($cid, $fNum, $fTit, $fRif);
                $ficha = db()->prepare('SELECT nombre, numero, titular, rif FROM cuentas WHERE id = ?');
                $ficha->execute([$cid]);
                $datos = $ficha->fetch() ?: [];
                $incompleta = ficha_incompleta($datos);
                $r = importar($a['ruta'], $a['ext'], $cid, $a['nombre'], $a['info'] ?? null);
                $r['aviso'] = $incompleta === [] ? '' : 'A esta cuenta le falta ' . implode(', ', $incompleta)
                    . '. Complétala en Cuentas: sin esos datos no se puede avisar si un archivo va a la cuenta equivocada.';
                $r['nombre'] = $a['nombre'];
                $r['cuenta'] = db()->query('SELECT nombre FROM cuentas WHERE id = ' . $cid)->fetchColumn();
                $resultados[] = $r;
            } catch (Throwable $ex) {
                $resultados[] = ['nombre' => $a['nombre'], 'error' => $ex->getMessage()];
                $fallidos[$i] = true;
            }
        }
        // Los que entraron ya no hacen falta. Los que fallaron se quedan en el
        // servidor para poder corregir el destino y reintentar sin volver a
        // subirlos: es lo que antes obligaba a empezar de cero.
        $tot = array_sum(array_column($resultados, 'insertados'));
        bitacora('importacion', count($resultados) . ' archivo(s), ' . $tot . ' movimientos nuevos');
    return [$resultados, $fallidos];
}

/**
 * Borra los archivos del lote. Con $conservar se guardan esos índices, que son
 * los que fallaron: sin archivo no hay forma de reintentar y quien carga tiene
 * que volver a subirlo todo.
 */
function limpiar_lote(?array $conservar = null): void
{
    $queda = [];
    foreach ($_SESSION['lote'] ?? [] as $i => $a) {
        if ($conservar !== null && in_array($i, $conservar, true)) {
            $queda[$i] = $a;
            continue;
        }
        if (!empty($a['ruta']) && is_file($a['ruta'])) {
            @unlink($a['ruta']);
        }
    }
    if ($queda === []) {
        unset($_SESSION['lote']);
    } else {
        $_SESSION['lote'] = $queda;
    }
    purgar_subidas();
}

/** Borra los archivos que quedaron a medias en cargas abandonadas. */
function purgar_subidas(int $horas = 6): void
{
    $limite = time() - $horas * 3600;
    foreach (glob(UPLOAD_DIR . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < $limite) {
            @unlink($f);
        }
    }
}

$cuentasLista = cuentas();
encabezado_html('Cargar extractos', 'carga',
    'Arrastra los archivos del banco. Se reconoce el formato solo y las líneas repetidas no se duplican.');
?>

<?php if ($paso === 'subir'): ?>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
    <input type="hidden" name="accion" value="analizar">
    <div class="soltar" id="zonaSoltar" data-guia="soltar">
      <b>El extracto del banco va aquí</b>
      <span>Arrástrelo hasta este recuadro, o haga clic para buscarlo en su computadora.
            Se carga solo: únicamente le preguntamos lo que el archivo no diga.</span>
      <input type="file" name="archivos[]" id="archivos" multiple accept=".xlsx,.xls,.csv" hidden>
      <label for="archivos" class="btn btn-oro btn-grande" style="margin-top:18px;display:inline-flex">Elegir el archivo</label>
      <span style="display:block;margin-top:12px;font-size:13px;color:var(--tenue)">
        Excel (.xlsx) o CSV · hasta <?= $limite ?> MB por archivo · puede elegir varios a la vez
      </span>
    </div>
    <ul class="lista-archivos" id="listaArchivos"></ul>
    <div class="acciones" style="margin-top:16px">
      <button class="btn btn-oro btn-grande" id="btnAnalizar">Cargar el extracto</button>
      <span id="avisoArchivos" style="align-self:center;color:var(--mudo);font-size:13px"></span>
    </div>
  </form>

  <div class="tarjeta" style="margin-top:22px" data-guia="formatos">
    <h2>Formatos reconocidos</h2>
    <div class="tabla-scroll">
      <table>
        <thead><tr><th>Banco</th><th>Cómo se identifica</th><th>Columnas</th></tr></thead>
        <tbody>
          <tr><td><span class="etq">Bancamiga</span></td><td>Sin título, con columna <b>Saldo</b></td><td class="ref">Fecha · Referencia · Concepto · DESCRIP · Débito · Crédito · Saldo</td></tr>
          <tr><td><span class="etq">Banco del Tesoro</span></td><td>Título <b>TESORO</b></td><td class="ref">Fecha · Referencia · Concepto · DESCRIPCION · Débito · Crédito</td></tr>
          <tr><td><span class="etq">Banco de Venezuela</span></td><td>Título con <b>VENEZUELA</b></td><td class="ref">Fecha · Referencia · Descripción · CONCEPTO · Débito · Crédito</td></tr>
          <tr><td><span class="etq">Banesco</span></td><td>Título <b>BANESCO</b></td><td class="ref">Fecha · Referencia · Descripción · nota · Monto con signo</td></tr>
        </tbody>
      </table>
    </div>
    <p style="color:var(--mudo);font-size:13px;margin:12px 0 0">
      Otros bancos también funcionan: el archivo se reconoce por cómo está armado por dentro, así que
      da igual con qué nombre se lo guarden. Antes de guardar avisa si algo no encaja.
      Si el archivo trae el nombre de la cuenta arriba, se propone automáticamente.
    </p>
  </div>

<?php elseif ($paso === 'confirmar'): $lote = $_SESSION['lote'] ?? [];
      // Lo que ya venía elegido en el intento anterior: si la pantalla se
      // repinta por un error, nadie tiene que volver a escribirlo.
      $prevCuenta = (array) ($_POST['cuenta'] ?? []);
      $prevNombre = (array) ($_POST['cuenta_nueva'] ?? []);
      $prevBanco  = (array) ($_POST['banco_nuevo'] ?? []); ?>
  <datalist id="listaBancos">
    <?php foreach (bancos_conocidos() as $b): ?><option value="<?= e($b) ?>"><?php endforeach ?>
  </datalist>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
    <input type="hidden" name="accion" value="importar">
    <div class="pila">
      <?php foreach ($lote as $i => $a): ?>
        <div class="tarjeta">
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
            <b style="font-size:15px"><?= e($a['nombre']) ?></b>
            <?php if ($a['ok']): ?>
              <span class="etq"><i style="background:var(--entrada)"></i><?= e($a['banco'] ?: 'Formato genérico') ?></span>
            <?php else: ?>
              <span class="etq vacia">No se reconoció el formato</span>
            <?php endif ?>
            <span class="origen" style="margin-left:auto"><?= number_format($a['tam'] / 1024, 0, ',', '.') ?> KB</span>
          </div>

          <?php if ($a['ok']): ?>
            <div class="par">
              <div>
                <label>Cuenta destino</label>
                <select name="cuenta[<?= $i ?>]">
                  <?php
                  // El número de cuenta manda sobre el nombre: el título que
                  // imprime el banco cambia de un archivo a otro y por eso una
                  // misma cuenta acababa registrada dos veces.
                  // La misma función que decide si el archivo puede entrar
                  // solo: aquí y allá tiene que proponer lo mismo.
                  $sug = cuenta_sugerida($a, $cuentasLista);
                  $mismoBanco = cuentas_del_banco((string) $a['banco'], $cuentasLista);
                  if (isset($prevCuenta[$i])) {
                      $sug = $prevCuenta[$i] === 'nueva' ? null : (int) $prevCuenta[$i];
                  }
                  foreach ($cuentasLista as $c): ?>
                    <?php $ult = preg_replace('/\D/', '', (string) $c['numero']); ?>
                    <option value="<?= $c['id'] ?>" <?= $sug === (int) $c['id'] ? 'selected' : '' ?>>
                      <?= e($c['nombre']) ?><?= $c['banco'] ? ' — ' . e($c['banco']) : '' ?><?=
                        strlen($ult) >= 4 ? ' · termina en ' . e(substr($ult, -4)) : '' ?></option>
                  <?php endforeach ?>
                  <option value="nueva" <?= $sug === null ? 'selected' : '' ?>>➕ Crear cuenta nueva</option>
                </select>
              </div>
              <div>
                <label>Nombre si es cuenta nueva</label>
                <input type="text" name="cuenta_nueva[<?= $i ?>]" maxlength="120"
                       value="<?= e($prevNombre[$i] ?? $a['cuenta']) ?>" placeholder="Ej.: BANESCO corriente">
              </div>
            </div>
            <?php if ((string) $a['banco'] === ''): ?>
              <div style="margin-top:10px;max-width:320px">
                <label>¿De qué banco es este extracto?</label>
                <input type="text" name="banco_nuevo[<?= $i ?>]" maxlength="120"
                       value="<?= e($prevBanco[$i] ?? '') ?>"
                       placeholder="Ej.: Banco de Venezuela" list="listaBancos">
                <span class="nota">Este archivo no lo dice por ninguna parte, así que hay que escribirlo una vez.</span>
              </div>
            <?php endif ?>
            <?php if (count($mismoBanco) > 1): ?>
              <div class="aviso aviso-nota" style="margin-top:14px">
                <b>Hay <?= count($mismoBanco) ?> cuentas suyas en <?= e($a['banco']) ?>.</b>
                El sistema no adivina cuál es: la misma empresa, con el mismo RIF, puede tener
                varias cuentas en un banco y solo el número las distingue. Revise que sea la correcta
                antes de importar.
              </div>
            <?php endif ?>
            <?php if (isset($errFila[$i])): ?>
              <div class="aviso aviso-mal" style="margin-top:14px"><b>Falta un dato.</b> <?= e($errFila[$i]) ?></div>
            <?php endif ?>
            <?php $chequeos = comprobaciones($a); if ($chequeos !== []): ?>
              <ul class="comprobaciones">
                <?php foreach ($chequeos as $c): ?>
                  <li class="<?= e($c[0]) ?>"><?= e($c[1]) ?></li>
                <?php endforeach ?>
              </ul>
            <?php endif ?>
            <?php
            /* Datos de la ficha que falten en la cuenta propuesta. Se piden una
               vez por cuenta, no en cada carga, y vienen escritos cuando el
               extracto los trae. */
            $ctaSug = null;
            foreach ($cuentasLista as $c) { if ((int) $c['id'] === $sug) { $ctaSug = $c; } }
            $faltan = $ctaSug ? ficha_incompleta($ctaSug) : ['el número de cuenta', 'el titular', 'el RIF'];
            if ($faltan !== []): ?>
              <div class="aviso aviso-nota" style="margin-top:14px">
                <b>Esta cuenta aún no está identificada.</b>
                Puede cargar igual, pero si los rellena —una sola vez— el sistema podrá avisarle
                cuando un archivo vaya a la cuenta equivocada.
              </div>
              <div class="par" style="margin-top:10px">
                <div>
                  <label>Nº de cuenta</label>
                  <input type="text" name="f_numero[<?= $i ?>]" maxlength="60"
                         value="<?= e($a['numero'] ?? '') ?>" placeholder="20 dígitos"
                         <?= ($ctaSug && trim((string) $ctaSug['numero']) !== '') ? 'readonly' : '' ?>>
                </div>
                <div>
                  <label>Titular</label>
                  <input type="text" name="f_titular[<?= $i ?>]" maxlength="160"
                         value="<?= e($a['titular'] ?? '') ?>" placeholder="Nombre de la empresa"
                         <?= ($ctaSug && trim((string) $ctaSug['titular']) !== '') ? 'readonly' : '' ?>>
                </div>
              </div>
              <div style="margin-top:10px;max-width:260px">
                <label>RIF</label>
                <input type="text" name="f_rif[<?= $i ?>]" maxlength="20"
                       value="<?= e($a['rif'] ?? '') ?>" placeholder="J-12345678-9"
                       <?= ($ctaSug && trim((string) $ctaSug['rif']) !== '') ? 'readonly' : '' ?>>
              </div>
            <?php endif ?>
            <div class="tabla-scroll" style="margin-top:14px;border:1px solid var(--linea);border-radius:var(--r-sm)">
              <table>
                <thead><tr><?php foreach ($a['cab'] as $h): ?><th><?= e($h ?: '·') ?></th><?php endforeach ?></tr></thead>
                <tbody>
                  <?php foreach ($a['muestra'] as $fila): ?>
                    <tr><?php foreach (array_keys($a['cab']) as $k): ?>
                      <td class="ref"><?= e(mb_strimwidth(limpiar((string) ($fila[$k] ?? '')), 0, 34, '…')) ?></td>
                    <?php endforeach ?></tr>
                  <?php endforeach ?>
                </tbody>
              </table>
            </div>
            <label style="display:flex;align-items:center;gap:8px;margin-top:12px;text-transform:none;letter-spacing:0;font-size:13px;color:var(--mudo)">
              <input type="checkbox" name="omitir[<?= $i ?>]" value="1" style="width:auto"> Omitir este archivo
            </label>
          <?php else: ?>
            <p style="color:var(--salida);margin:0">No se encontró la columna de fecha o la de montos. Revisa que el archivo sea el extracto del banco sin filas de resumen arriba.</p>
          <?php endif ?>
        </div>
      <?php endforeach ?>
    </div>
    <div class="acciones" style="margin-top:16px">
      <button class="btn btn-oro">Importar a la base</button>
      <a class="btn" href="?r=carga">← Volver a elegir archivos</a>
    </div>
  </form>

<?php else: ?>
  <div class="marco-tabla">
    <div class="tabla-scroll">
      <table>
        <thead><tr><th>Archivo</th><th>Cuenta</th><th class="der">Leídos</th><th class="der">Nuevos</th>
          <th class="der">Repetidos</th><th class="der">Mapeados solos</th></tr></thead>
        <tbody>
        <?php foreach ($resultados as $r): ?>
          <tr>
            <td><?= e($r['nombre']) ?><?php if (isset($r['error'])): ?>
              <span class="nota" style="color:var(--salida);display:block;font-size:12px"><?= e($r['error']) ?></span>
              <?php else: ?>
                <?php if (!empty($r['cuadre']['detalle'])): ?>
                  <span class="nota" style="color:var(--entrada);display:block;font-size:12px">Sumado por el sistema · <?= e(implode(' · ', $r['cuadre']['detalle'])) ?></span>
                <?php endif ?>
                <?php if (!empty($r['cuadre']['discrepa'])): ?>
                  <span class="nota" style="color:var(--pendiente);display:block;font-size:12px"><?= e($r['cuadre']['discrepa']) ?></span>
                <?php endif ?>
                <?php if (!empty($r['aviso'])): ?>
                  <span class="nota" style="color:var(--pendiente);display:block;font-size:12px"><?= e($r['aviso']) ?></span>
                <?php endif ?>
              <?php endif ?></td>
            <td><?= e($r['cuenta'] ?? '—') ?>
              <?php if (!empty($r['importacion'])): $res = resumen_importacion((int) $r['importacion']); ?>
                <form method="post" style="margin-top:6px">
                  <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                  <input type="hidden" name="accion" value="deshacer">
                  <input type="hidden" name="importacion" value="<?= (int) $r['importacion'] ?>">
                  <button class="btn btn-sm" data-confirmar="Se van a quitar <?= number_format($res['movimientos'] ?? 0, 0, ',', '.') ?> movimientos de esta carga<?=
                      !empty($res['clasificados']) ? ', ' . number_format($res['clasificados'], 0, ',', '.') . ' de ellos ya clasificados' : '' ?><?=
                      !empty($res['pagos']) ? ', y se perderá el reparto de ' . $res['pagos'] . ' pago(s) entre facturas' : '' ?>. Esto no se puede deshacer. ¿Continuar?">
                    Deshacer esta carga
                  </button>
                </form>
              <?php endif ?>
            </td>
            <td class="der num"><?= number_format((int) ($r['filas'] ?? 0), 0, ',', '.') ?></td>
            <td class="der num" style="color:var(--entrada)"><?= number_format((int) ($r['insertados'] ?? 0), 0, ',', '.') ?></td>
            <td class="der num" style="color:var(--mudo)"><?= number_format((int) ($r['duplicados'] ?? 0), 0, ',', '.') ?></td>
            <td class="der num" style="color:var(--oro)"><?= number_format((int) ($r['auto'] ?? 0), 0, ',', '.') ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="acciones" style="margin-top:16px">
    <?php if (($_SESSION['lote'] ?? []) !== []): ?>
      <form method="post" style="display:contents">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="accion" value="reintentar">
        <button class="btn btn-oro">← Corregir y volver a intentar</button>
      </form>
    <?php endif ?>
    <a class="btn <?= ($_SESSION['lote'] ?? []) === [] ? 'btn-oro' : '' ?>" href="?r=pendientes">Ir a justificar</a>
    <a class="btn" href="?r=carga">Cargar más archivos</a>
    <a class="btn" href="?r=panel">Ver el panel</a>
  </div>
<?php endif ?>

<?php pie_html(); ?>
