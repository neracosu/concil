<?php
/**
 * A quién se le paga. El listado lo comparte todo el grupo: así se puede
 * preguntar cuánto le pagó el consorcio entero a alguien, aunque cada empresa
 * lleve sus cuentas por separado.
 *
 * Se puede escribir uno a mano o traer el listado completo desde el archivo que
 * exporta contabilidad, en dos pasos: primero se muestra qué haría con cada
 * fila y solo al confirmar entra algo a la base.
 */
exigir_login();
$pdo = db();
$paso = 'listar';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && $_POST === [] && $_FILES === []
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('mal', 'El archivo pesa más de lo que el servidor acepta de una vez ('
        . ini_get('post_max_size') . ').');
    redirigir('?r=proveedores');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'guardar') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $nuevo = guardar_proveedor([
                'nombre'   => (string) ($_POST['nombre'] ?? ''),
                'codigo'   => (string) ($_POST['codigo'] ?? ''),
                'rif'      => (string) ($_POST['rif'] ?? ''),
                'nit'      => (string) ($_POST['nit'] ?? ''),
                'telefono' => (string) ($_POST['telefono'] ?? ''),
                'nota'     => (string) ($_POST['nota'] ?? ''),
                'activo'   => 1,
            ], $id);
            bitacora($id > 0 ? 'proveedor_editado' : 'proveedor_creado', '#' . $nuevo);
            flash('ok', $id > 0 ? 'Proveedor actualizado.' : 'Proveedor creado.');
        } catch (Throwable $ex) {
            flash('mal', $ex->getMessage());
        }
        redirigir('?r=proveedores');
    }

    if ($accion === 'fusionar') {
        $origen  = (int) ($_POST['origen'] ?? 0);
        $destino = (int) ($_POST['destino'] ?? 0);
        try {
            $r = fusionar_proveedores($origen, $destino);
            bitacora('proveedores_fusionados', "#$origen → #$destino: {$r['movidos']} pagos, {$r['facturas']} facturas");
            flash('ok', 'Fichas unidas. Se pasaron ' . $r['movidos'] . ' pago(s) y '
                . $r['facturas'] . ' factura(s)'
                . ($r['unidas'] > 0 ? ", y {$r['unidas']} factura(s) que estaban en las dos se juntaron en una." : '.'));
        } catch (Throwable $ex) {
            flash('mal', 'No se pudieron unir: ' . $ex->getMessage());
        }
        redirigir('?r=proveedores');
    }

    /* ---------- Paso 1: recibir el archivo y decir qué haría con él ---------- */
    if ($accion === 'analizar' || $accion === 'remapear') {
        $ruta = (string) ($_SESSION['prov_archivo']['ruta'] ?? '');
        $nombreArchivo = (string) ($_SESSION['prov_archivo']['nombre'] ?? '');
        $ext = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));

        if ($accion === 'analizar') {
            purgar_prov_lote();
            $f = $_FILES['archivo'] ?? null;
            if ($f === null || (int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
                flash('mal', $f === null || (string) $f['name'] === ''
                    ? 'No elegiste ningún archivo.'
                    : motivo_fallo_subida((int) $f['error']));
                redirigir('?r=proveedores');
            }
            $nombreArchivo = (string) $f['name'];
            $ext = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
            if (!in_array($ext, EXT_PERMITIDAS, true)) {
                flash('mal', 'Solo se aceptan archivos ' . implode(' y ', EXT_PERMITIDAS) . '.');
                redirigir('?r=proveedores');
            }
            $ruta = UPLOAD_DIR . '/prov_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file((string) $f['tmp_name'], $ruta)) {
                flash('mal', 'No se pudo guardar el archivo en el servidor.');
                redirigir('?r=proveedores');
            }
            @chmod($ruta, 0600);
            $_SESSION['prov_archivo'] = ['ruta' => $ruta, 'nombre' => $nombreArchivo];
        }

        if ($ruta === '' || !is_file($ruta)) {
            flash('mal', 'El archivo ya no está. Vuelva a subirlo.');
            redirigir('?r=proveedores');
        }

        // Cuando el archivo no trae encabezado reconocible, la persona dice a
        // mano qué es cada columna y se vuelve a analizar con ese mapa.
        $mapaManual = null;
        if ($accion === 'remapear') {
            $mapa = [];
            foreach ((array) ($_POST['col'] ?? []) as $col => $campo) {
                $campo = (string) $campo;
                if ($campo !== '' && isset(ROTULOS_PROVEEDOR[$campo]) && !isset($mapa[$campo])) {
                    $mapa[$campo] = (int) $col;
                }
            }
            if (!isset($mapa['nombre'])) {
                flash('mal', 'Marca cuál es la columna del nombre del proveedor.');
                redirigir('?r=proveedores');
            }
            $mapaManual = ['fila' => (int) ($_POST['fila_cab'] ?? 0), 'mapa' => $mapa];
        }

        try {
            $analisis = analizar_proveedores($ruta, $ext, $mapaManual);
        } catch (Throwable $ex) {
            @unlink($ruta);
            unset($_SESSION['prov_archivo']);
            flash('mal', $ex->getMessage());
            redirigir('?r=proveedores');
        }
        $_SESSION['prov_lote'] = $analisis;
        $paso = 'confirmar';
    }

    /* ---------- Paso 2: confirmar ---------- */
    if ($accion === 'importar') {
        $analisis = $_SESSION['prov_lote'] ?? null;
        if ($analisis === null) {
            flash('mal', 'La revisión caducó. Vuelva a subir el archivo.');
            redirigir('?r=proveedores');
        }
        try {
            $r = importar_proveedores($analisis['filas']);
            bitacora('proveedores_importados', $r['creados'] . ' nuevos, ' . $r['completados'] . ' completados');
            flash('ok', $r['creados'] . ' proveedor(es) nuevos'
                . ($r['completados'] > 0 ? ' y ' . $r['completados'] . ' al que le faltaban datos' : '')
                . '. Ya están disponibles para todas las unidades.');
        } catch (Throwable $ex) {
            flash('mal', 'No se pudo guardar el listado: ' . $ex->getMessage());
        }
        purgar_prov_lote();
        redirigir('?r=proveedores');
    }

    if ($accion === 'cancelar') {
        purgar_prov_lote();
        redirigir('?r=proveedores');
    }
}

/** Borra el archivo subido y la revisión a medias. */
function purgar_prov_lote(): void
{
    $ruta = (string) ($_SESSION['prov_archivo']['ruta'] ?? '');
    if ($ruta !== '' && is_file($ruta)) {
        @unlink($ruta);
    }
    unset($_SESSION['prov_archivo'], $_SESSION['prov_lote']);
}

$editar = null;
if (($id = (int) ($_GET['editar'] ?? 0)) > 0) {
    $editar = proveedor($id);
}
$busca = mb_substr(limpiar((string) ($_GET['q'] ?? '')), 0, 60);
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$pag = $paso === 'listar' ? buscar_proveedores($busca, false, $pagina) : ['filas' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1];
$lista = $pag['filas'];

if ($paso === 'listar') {
    purgar_prov_lote();
}

$totalProv = (int) $pdo->query('SELECT COUNT(*) FROM proveedores')->fetchColumn();

encabezado_html('Proveedores', 'proveedores',
    $totalProv > 0
        ? number_format($totalProv, 0, ',', '.') . ' en el listado · los ven todas las unidades'
        : 'Todavía no hay nadie en el listado.');
?>

<?php if ($paso === 'confirmar'): $a = $_SESSION['prov_lote']; $res = $a['resumen']; ?>
  <?php if ($a['fila_cab'] === null): ?>
    <div class="tarjeta">
      <h2>No se reconoció el encabezado</h2>
      <p style="color:var(--mudo);font-size:13.5px;margin:0 0 16px">
        El archivo no trae una fila que diga cuál columna es el nombre y cuál el RIF.
        Míralo abajo y marca tú qué es cada columna.
      </p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="accion" value="remapear">
        <div style="max-width:220px;margin-bottom:14px">
          <label>Los datos empiezan después de la fila</label>
          <input type="number" name="fila_cab" value="0" min="0" max="60">
        </div>
        <div class="tabla-scroll" style="border:1px solid var(--linea);border-radius:var(--r-sm)">
          <table>
            <thead><tr>
              <?php $ancho = max(array_map('count', $a['muestra'] ?: [[]])); ?>
              <?php for ($c = 0; $c < $ancho; $c++): ?>
                <th><select name="col[<?= $c ?>]" style="min-width:130px">
                  <option value="">— no usar —</option>
                  <option value="nombre">Nombre</option>
                  <option value="codigo">Código</option>
                  <option value="rif">RIF</option>
                  <option value="nit">NIT</option>
                  <option value="telefono">Teléfono</option>
                </select></th>
              <?php endfor ?>
            </tr></thead>
            <tbody>
              <?php foreach ($a['muestra'] as $fila): ?>
                <tr><?php for ($c = 0; $c < $ancho; $c++): ?>
                  <td class="ref"><?= e(mb_strimwidth(limpiar((string) ($fila[$c] ?? '')), 0, 30, '…')) ?></td>
                <?php endfor ?></tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
        <div class="acciones" style="margin-top:16px">
          <button class="btn btn-oro">Volver a revisar</button>
          <button class="btn" name="accion" value="cancelar" formnovalidate>Cancelar</button>
        </div>
      </form>
    </div>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <div class="tarjeta">
        <h2>Esto es lo que se va a guardar</h2>
        <p style="color:var(--mudo);font-size:13.5px;margin:0 0 16px">
          <b><?= e((string) ($_SESSION['prov_archivo']['nombre'] ?? '')) ?></b> ·
          los rótulos estaban en la fila <?= (int) $a['fila_cab'] + 1 ?>.
          Nada se ha guardado todavía.
        </p>
        <div class="rejilla rejilla-4">
          <div class="cifra">
            <div class="rotulo">Entran nuevos</div>
            <div class="valor" style="color:var(--entrada)"><?= $res['nuevos'] + $res['revisar'] ?></div>
          </div>
          <div class="cifra">
            <div class="rotulo">Ya estaban</div>
            <div class="valor"><?= $res['existen'] ?></div>
          </div>
          <div class="cifra">
            <div class="rotulo">Repetidos en el archivo</div>
            <div class="valor"><?= $res['repetidas'] + $res['descartadas'] ?></div>
            <div class="pie">no entran dos veces</div>
          </div>
          <div class="cifra <?= ($res['revisar'] + $res['conflictos']) > 0 ? 'aviso' : '' ?>">
            <div class="rotulo">Para revisar</div>
            <div class="valor"><?= $res['revisar'] + $res['conflictos'] ?></div>
          </div>
        </div>
      </div>

      <?php
      $avisos = array_values(array_filter($a['filas'],
          fn($f) => in_array($f['estado'], ['revisar', 'conflicto', 'repetida', 'descartada'], true)));
      if ($avisos !== []): ?>
        <div class="tarjeta" style="margin-top:18px">
          <h2>Lo que hay que mirar</h2>
          <div class="tabla-scroll">
            <table>
              <thead><tr><th>Proveedor</th><th>Qué pasa</th><th>Qué se hace</th></tr></thead>
              <tbody>
                <?php foreach ($avisos as $f): ?>
                  <tr>
                    <td><b><?= e($f['datos']['nombre'] ?: '(fila sin nombre)') ?></b></td>
                    <td class="ref"><?= e((string) ($f['motivo'] ?? '')) ?></td>
                    <td style="white-space:nowrap;color:var(--mudo);font-size:12.5px">
                      <?= $f['estado'] === 'revisar' ? 'Entra, pero sin usar ese RIF' : 'No entra' ?>
                    </td>
                  </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif ?>

      <div class="tarjeta" style="margin-top:18px">
        <h2>Los que entran</h2>
        <div class="tabla-scroll">
          <table>
            <thead><tr><th>Código</th><th>Proveedor</th><th>RIF</th><th>Teléfono</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($a['filas'] as $f):
                if (!in_array($f['estado'], ['nuevo', 'revisar', 'existe'], true)) { continue; } ?>
                <tr>
                  <td class="ref"><?= e($f['datos']['codigo']) ?></td>
                  <td><?= e($f['datos']['nombre']) ?></td>
                  <td class="ref"><?= e($f['datos']['rif']) ?></td>
                  <td class="ref"><?= e($f['datos']['telefono']) ?></td>
                  <td style="white-space:nowrap;font-size:12.5px;color:var(--mudo)">
                    <?php if ($f['estado'] === 'existe'): ?>
                      <?= ($f['falta'] ?? []) !== []
                          ? 'ya estaba · se le añade ' . e(implode(', ', $f['falta']))
                          : 'ya estaba, igual' ?>
                    <?php else: ?>nuevo<?php endif ?>
                  </td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="acciones" style="margin-top:18px">
        <button class="btn btn-oro" name="accion" value="importar">Guardar el listado</button>
        <button class="btn" name="accion" value="cancelar" formnovalidate>Cancelar</button>
      </div>
    </form>
  <?php endif ?>

<?php else: ?>
<div class="rejilla" style="grid-template-columns:minmax(0,1fr) 320px;align-items:start">
  <div>
    <form method="get" class="filtros">
      <input type="hidden" name="r" value="proveedores">
      <input type="text" name="q" value="<?= e($busca) ?>" placeholder="Buscar por nombre, RIF o código…">
      <button class="btn">Buscar</button>
      <?php if ($busca !== ''): ?><a class="btn" href="?r=proveedores">Ver todos</a><?php endif ?>
    </form>

    <div class="marco-tabla" data-guia="proveedores">
      <?php if ($lista === []): ?>
        <div class="vacio"><b><?= $busca !== '' ? 'Nadie con ese nombre' : 'El listado está vacío' ?></b>
          <?= $busca !== ''
              ? 'Prueba con otra parte del nombre, o con el RIF.'
              : 'Cree uno a mano aquí al lado, o traiga el listado completo desde el archivo de contabilidad.' ?></div>
      <?php else: ?>
      <div class="tabla-scroll">
        <table>
          <thead><tr>
            <th>Código</th><th>Proveedor</th><th>RIF</th>
            <th class="der">Pagos</th><th class="der">Total Bs</th><th class="der">Facturas</th><th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($lista as $p): ?>
              <tr>
                <td class="ref"><?= e($p['codigo']) ?></td>
                <td>
                  <b><?= e($p['nombre']) ?></b>
                  <?php if (trim((string) $p['telefono']) !== ''): ?>
                    <span style="display:block;color:var(--mudo);font-size:12px"><?= e($p['telefono']) ?></span>
                  <?php endif ?>
                </td>
                <td class="ref"><?= e($p['rif']) ?><?= trim((string) $p['rif']) !== '' && $p['rif_clave'] === ''
                      ? ' <span class="etq vacia">sin verificar</span>' : '' ?></td>
                <td class="der num"><?= number_format((int) $p['movs'], 0, ',', '.') ?></td>
                <td class="der num"><?= bs((float) $p['total'], 0) ?></td>
                <td class="der num"><?= number_format((int) $p['facturas'], 0, ',', '.') ?></td>
                <td style="text-align:right;white-space:nowrap">
                  <a class="btn btn-sm" href="?r=proveedor&id=<?= $p['id'] ?>">Abrir ficha</a>
                  <a class="btn btn-sm" href="?r=proveedores&editar=<?= $p['id'] ?>">Editar</a>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <?php paginas_html($pag['pagina'], $pag['paginas'], $pag['total'], 'proveedores') ?>
      <?php endif ?>
    </div>

    <?php $todosProv = $paso === 'listar' ? proveedores() : []; if (count($todosProv) > 1): ?>
      <div class="tarjeta" style="margin-top:18px">
        <h2>Unir dos fichas del mismo proveedor</h2>
        <p style="color:var(--mudo);font-size:13.5px;margin:0 0 14px">
          Si el mismo proveedor quedó anotado dos veces, únalos: los pagos y las facturas del
          primero pasan al segundo, y lo que al segundo le falte se completa con los datos del primero.
        </p>
        <form method="post" class="par">
          <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
          <input type="hidden" name="accion" value="fusionar">
          <div><label>Esta ficha desaparece</label>
            <select name="origen" required>
              <option value="">Elegir…</option>
              <?php foreach ($todosProv as $p): ?>
                <option value="<?= $p['id'] ?>"><?= e($p['nombre']) ?><?= $p['movs'] > 0 ? ' · ' . (int) $p['movs'] . ' pagos' : '' ?></option>
              <?php endforeach ?>
            </select></div>
          <div><label>Y todo pasa a esta</label>
            <select name="destino" required>
              <option value="">Elegir…</option>
              <?php foreach ($todosProv as $p): ?>
                <option value="<?= $p['id'] ?>"><?= e($p['nombre']) ?></option>
              <?php endforeach ?>
            </select></div>
          <div style="grid-column:1/-1">
            <button class="btn btn-peligro" data-confirmar="Los pagos y las facturas de la primera ficha pasarán a la segunda y la primera se borrará. Esto no se puede deshacer. ¿Continuar?">Unir las dos fichas</button>
          </div>
        </form>
      </div>
    <?php endif ?>
  </div>

  <div>
    <div class="tarjeta">
      <h2><?= $editar ? 'Editar proveedor' : 'Nuevo proveedor' ?></h2>
      <form method="post" class="pila">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" value="<?= (int) ($editar['id'] ?? 0) ?>">
        <div><label>Nombre</label>
          <input type="text" name="nombre" maxlength="160" required value="<?= e($editar['nombre'] ?? '') ?>"
                 placeholder="Ej.: DISTRIBUIDORA HORNO RIO, C.A."></div>
        <div><label>Código <span style="text-transform:none;letter-spacing:0">(el que usa contabilidad)</span></label>
          <input type="text" name="codigo" maxlength="40" value="<?= e($editar['codigo'] ?? '') ?>" placeholder="Ej.: HORNO"></div>
        <div><label>RIF</label>
          <input type="text" name="rif" maxlength="20" value="<?= e($editar['rif'] ?? '') ?>" placeholder="J-12345678-9"></div>
        <div class="par">
          <div><label>NIT</label>
            <input type="text" name="nit" maxlength="20" value="<?= e($editar['nit'] ?? '') ?>"></div>
          <div><label>Teléfono</label>
            <input type="text" name="telefono" maxlength="60" value="<?= e($editar['telefono'] ?? '') ?>"></div>
        </div>
        <div><label>Nota</label>
          <input type="text" name="nota" maxlength="255" value="<?= e($editar['nota'] ?? '') ?>"
                 placeholder="Lo que convenga recordar de él"></div>
        <div class="acciones">
          <button class="btn btn-oro"><?= $editar ? 'Guardar' : 'Crear proveedor' ?></button>
          <?php if ($editar): ?><a class="btn" href="?r=proveedores">Cancelar</a><?php endif ?>
        </div>
      </form>
    </div>

    <div class="tarjeta" style="margin-top:18px" data-guia="prov-archivo">
      <h2>Traer el listado completo</h2>
      <p style="color:var(--mudo);font-size:13.5px;margin:0 0 14px">
        Si contabilidad ya tiene la lista de proveedores en un archivo, súbala aquí y se
        cargan todos de una vez. Antes de guardar nada verá qué va a entrar.
      </p>
      <form method="post" enctype="multipart/form-data" class="pila">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="accion" value="analizar">
        <input type="file" name="archivo" accept=".xlsx,.xls,.csv" required>
        <button class="btn btn-oro">Revisar el archivo</button>
      </form>
      <p style="color:var(--tenue);font-size:12.5px;margin:12px 0 0">
        Excel (.xlsx) o CSV. Da igual cómo se llame el archivo: se mira por dentro.
        A quien ya esté en el listado no se le pisa ningún dato.
      </p>
    </div>
  </div>
</div>
<?php endif ?>
<?php pie_html(); ?>
