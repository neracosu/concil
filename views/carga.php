<?php
/**
 * Carga en dos pasos: primero se analiza cada archivo y se muestra a qué cuenta
 * y banco corresponde; solo al confirmar entran los movimientos a la base.
 */
exigir_login();

$paso = 'subir';
$lote = [];
$resultados = [];

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
        flash('mal', 'No elegiste ningún archivo. Pulsa «Elegir archivos» y busca los del banco en tu computadora.');
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
            flash('mal', implode(' ', $errores));
        }
    }

    /* ---------- Paso 2: importar ---------- */
    if ($accion === 'importar') {
        $lote = $_SESSION['lote'] ?? [];
        $elegidas = (array) ($_POST['cuenta'] ?? []);
        $nuevas   = (array) ($_POST['cuenta_nueva'] ?? []);
        $omitir   = (array) ($_POST['omitir'] ?? []);

        foreach ($lote as $i => $a) {
            if (!$a['ok'] || isset($omitir[$i]) || !is_file($a['ruta'])) {
                continue;
            }
            try {
                $cid = ($elegidas[$i] ?? '') === 'nueva'
                    ? cuenta_id((string) ($nuevas[$i] ?? $a['cuenta']), $a['banco'])
                    : (int) ($elegidas[$i] ?? 0);
                if ($cid <= 0) {
                    $cid = cuenta_id($a['cuenta'], $a['banco']);
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

                // La ficha se completa con lo que se escribió en la pantalla y,
                // si aún falta algo, no se carga: dos cuentas del mismo banco
                // sin número ni titular son indistinguibles.
                completar_ficha($cid,
                    (string) ($_POST['f_numero'][$i] ?? ''),
                    (string) ($_POST['f_titular'][$i] ?? ''),
                    (string) ($_POST['f_rif'][$i] ?? ''));
                $ficha = db()->prepare('SELECT nombre, numero, titular, rif FROM cuentas WHERE id = ?');
                $ficha->execute([$cid]);
                $datos = $ficha->fetch() ?: [];
                $falta = ficha_incompleta($datos);
                if ($falta !== []) {
                    throw new RuntimeException('a la cuenta «' . ($datos['nombre'] ?? '') . '» le falta '
                        . implode(', ', $falta) . '. Complétala en Cuentas y vuelve a subir el archivo.');
                }
                $r = importar($a['ruta'], $a['ext'], $cid, $a['nombre'], $a['info'] ?? null);
                $r['nombre'] = $a['nombre'];
                $r['cuenta'] = db()->query('SELECT nombre FROM cuentas WHERE id = ' . $cid)->fetchColumn();
                $resultados[] = $r;
            } catch (Throwable $ex) {
                $resultados[] = ['nombre' => $a['nombre'], 'error' => $ex->getMessage()];
            }
        }
        limpiar_lote();
        $paso = 'resultado';
        $tot = array_sum(array_column($resultados, 'insertados'));
        bitacora('importacion', count($resultados) . ' archivo(s), ' . $tot . ' movimientos nuevos');
    }
}

if ($paso === 'subir') {
    limpiar_lote();
}

function limpiar_lote(): void
{
    foreach ($_SESSION['lote'] ?? [] as $a) {
        if (!empty($a['ruta']) && is_file($a['ruta'])) {
            @unlink($a['ruta']);
        }
    }
    unset($_SESSION['lote']);
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
      <b>Los archivos del banco van aquí</b>
      <span>Arrástralos hasta este recuadro, o pulsa el botón para buscarlos en tu computadora.</span>
      <input type="file" name="archivos[]" id="archivos" multiple accept=".xlsx,.xls,.csv" hidden>
      <label for="archivos" class="btn btn-oro" style="margin-top:18px;display:inline-flex">Elegir archivos</label>
      <span style="display:block;margin-top:12px;font-size:12.5px;color:var(--tenue)">
        Excel (.xlsx) o CSV · hasta <?= $limite ?> MB por archivo · puedes elegir varios a la vez
      </span>
    </div>
    <ul class="lista-archivos" id="listaArchivos"></ul>
    <div class="acciones" style="margin-top:16px">
      <button class="btn btn-oro" id="btnAnalizar">Revisar archivos</button>
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

<?php elseif ($paso === 'confirmar'): $lote = $_SESSION['lote'] ?? []; ?>
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
                  $sug = null;
                  if (($a['numero'] ?? '') !== '' && !str_contains($a['numero'], '*')) {
                      foreach ($cuentasLista as $c) {
                          if ($c['numero'] !== '' && $c['numero'] === $a['numero']) { $sug = (int) $c['id']; }
                      }
                  }
                  if ($sug === null) {
                      foreach ($cuentasLista as $c) {
                          if (norm($c['nombre']) === norm($a['cuenta'])) { $sug = (int) $c['id']; }
                      }
                  }
                  foreach ($cuentasLista as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $sug === (int) $c['id'] ? 'selected' : '' ?>>
                      <?= e($c['nombre']) ?><?= $c['banco'] ? ' — ' . e($c['banco']) : '' ?></option>
                  <?php endforeach ?>
                  <option value="nueva" <?= $sug === null ? 'selected' : '' ?>>➕ Crear cuenta nueva</option>
                </select>
              </div>
              <div>
                <label>Nombre si es cuenta nueva</label>
                <input type="text" name="cuenta_nueva[<?= $i ?>]" value="<?= e($a['cuenta']) ?>" maxlength="120">
              </div>
            </div>
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
                <b>Faltan datos de esta cuenta.</b>
                Se piden una sola vez, para no volver a registrar dos veces la misma cuenta.
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
      <a class="btn" href="?r=carga">Cancelar</a>
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
              <?php elseif (!empty($r['cuadre']['detalle'])): ?>
              <span class="nota" style="color:var(--entrada);display:block;font-size:12px">Cuadra con el resumen del banco · <?= e(implode(' · ', $r['cuadre']['detalle'])) ?></span>
              <?php endif ?></td>
            <td><?= e($r['cuenta'] ?? '—') ?></td>
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
    <a class="btn btn-oro" href="?r=pendientes">Ir a justificar</a>
    <a class="btn" href="?r=carga">Cargar más archivos</a>
    <a class="btn" href="?r=panel">Ver el panel</a>
  </div>
<?php endif ?>

<?php pie_html(); ?>
