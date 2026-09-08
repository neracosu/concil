<?php
/** Armazón compartido. $titulo, $subtitulo y $acciones los define cada vista. */
function encabezado_html(string $titulo, string $ruta, ?string $subtitulo = null, string $acciones = ''): void
{
    global $mensaje;
    $pend = pendientes_total();
    // Quién más está dentro ahora mismo. Se pinta ya servido para que la
    // pantalla no llegue vacía y luego dé un salto cuando conteste el latido.
    $gente   = presencia_viva();
    $porRuta = presencia_por_ruta($gente);
    $miRef   = referencia_pantalla();
    ?><!doctype html>
<html lang="es"<?= tema() !== '' ? ' data-tema="' . e(tema()) . '"' : '' ?><?= escala() !== '' ? ' data-escala="' . e(escala()) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($titulo) ?> · <?= e(APP_CREDITO) ?></title>
<meta name="application-name" content="<?= e(APP_NOMBRE) ?>">
<meta name="author" content="<?= e(APP_MARCA) ?>">
<link rel="icon" type="image/png" href="/icon.png">
<link rel="stylesheet" href="assets/app.css?v=22">
</head>
<body>
<div class="app">
  <aside class="lateral">
    <div class="marca">
      <b><?= e(APP_NOMBRE) ?></b><span>by <?= e(APP_MARCA) ?></span>
    </div>
    <?php /* Mientras no se haya elegido unidad no se enseña ninguna: la
             pantalla está pidiendo justamente esa decisión. */
    $lasSedes = sedes(); if ($lasSedes !== [] && sede_elegida()): ?>
      <div class="sede-caja" data-guia="sede">
        <span class="sede-rotulo">Unidad de negocio</span>
        <?php if (count($lasSedes) > 1): ?>
          <?php /* Por POST y con testigo: cambiar de unidad descarta una carga
                    a medio confirmar, así que no puede dispararlo una imagen
                    incrustada en otra página. */ ?>
          <form method="post" action="?r=<?= e($ruta) ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="accion" value="cambiar_sede">
            <select name="sede" onchange="this.form.requestSubmit()" aria-label="Cambiar de unidad">
              <?php foreach ($lasSedes as $sd): ?>
                <option value="<?= (int) $sd['id'] ?>" <?= (int) $sd['id'] === sede_actual() ? 'selected' : '' ?>>
                  <?= e($sd['nombre']) ?></option>
              <?php endforeach ?>
            </select>
            <noscript><button class="btn btn-sm" style="margin-top:6px">Cambiar</button></noscript>
          </form>
        <?php else: ?>
          <?php /* Con una sola unidad no hay nada que desplegar, pero la caja
                    tiene que llevar a algún sitio: es donde la gente busca
                    crear la siguiente. */ ?>
          <a class="sede-unica" href="?r=sede"><?= e(sede_nombre()) ?></a>
        <?php endif ?>
        <a class="sede-gestion" href="?r=sede"><?= count($lasSedes) > 1 ? 'Ver y crear unidades' : 'Añadir otra unidad' ?></a>
      </div>
    <?php endif ?>
    <?php /* Mientras se elige unidad no hay a dónde ir: cualquier otra ruta
             rebota aquí, así que se ocultan los enlaces y la pantalla se lee
             como lo que es, una decisión antes de empezar. */ ?>
    <nav class="nav"<?= sede_elegida() ? '' : ' hidden' ?>>
      <div class="nav-titulo">Trabajo diario</div>
      <a href="?r=panel"       data-ruta="panel" class="<?= $ruta === 'panel' ? 'on' : '' ?>">Panel<?= ojito_html($porRuta, 'panel') ?></a>
      <a href="?r=carga"       data-ruta="carga" class="<?= $ruta === 'carga' ? 'on' : '' ?>">Cargar extractos<?= ojito_html($porRuta, 'carga') ?></a>
      <?php /* Es a lo que la gente entra. Mientras quede algo, el renglón se
               enciende y el número va en grande: no hay que acercarse a la
               pantalla para saber cuánto trabajo queda. */ ?>
      <a href="?r=pendientes" data-ruta="pendientes" class="<?= $ruta === 'pendientes' ? 'on' : '' ?><?= $pend > 0 ? ' tiene-pendientes' : '' ?>">Por justificar
        <span class="nav-marcas"><?= ojito_html($porRuta, 'pendientes') ?><span class="cuenta" data-pend<?= $pend > 0 ? '' : ' hidden' ?>><?= $pend > 999 ? '999+' : $pend ?></span></span></a>
      <a href="?r=movimientos" data-ruta="movimientos" class="<?= $ruta === "movimientos" || $ruta === "movimiento" ? "on" : "" ?>">Movimientos<?= ojito_html($porRuta, 'movimientos', 'movimiento') ?></a>
      <?php /* Solo aparece cuando hay algo que revisar: un enlace que casi
               siempre lleva a «no hay nada» enseña a no mirarlo. */
      $rep = contar_repetidos(); ?>
        <a href="?r=repetidos" data-ruta="repetidos" class="<?= $ruta === 'repetidos' ? 'on' : '' ?>"
           data-rep-renglon<?= $rep > 0 || $ruta === 'repetidos' ? '' : ' hidden' ?>>Repetidos
          <span class="nav-marcas"><?= ojito_html($porRuta, 'repetidos') ?><span class="cuenta cuenta-aviso" data-rep><?= $rep > 999 ? '999+' : $rep ?></span></span></a>
      <a href="?r=reportes"    data-ruta="reportes" class="<?= $ruta === "reportes" ? "on" : "" ?>">Reportes<?= ojito_html($porRuta, 'reportes') ?></a>
      <div class="nav-titulo">Configuración</div>
      <a href="?r=reglas"     data-ruta="reglas" class="<?= $ruta === 'reglas' ? 'on' : '' ?>">Reglas de mapeo<?= ojito_html($porRuta, 'reglas') ?></a>
      <a href="?r=categorias" data-ruta="categorias" class="<?= $ruta === 'categorias' ? 'on' : '' ?>">Categorías<?= ojito_html($porRuta, 'categorias') ?></a>
      <a href="?r=proveedores" data-ruta="proveedores" class="<?= $ruta === 'proveedores' ? 'on' : '' ?>">Proveedores<?= ojito_html($porRuta, 'proveedores', 'proveedor') ?></a>
      <a href="?r=cuentas"    data-ruta="cuentas" class="<?= $ruta === 'cuentas' ? 'on' : '' ?>">Cuentas<?= ojito_html($porRuta, 'cuentas') ?></a>
      <a href="?r=sede"       data-ruta="sede" class="<?= $ruta === 'sede' ? 'on' : '' ?>">Unidades de negocio<?= ojito_html($porRuta, 'sede') ?></a>
      <?php if (es_maestro()): ?>
        <a href="?r=usuarios" data-ruta="usuarios" class="<?= $ruta === 'usuarios' || $ruta === 'persona' ? 'on' : '' ?>">Usuarios<?= ojito_html($porRuta, 'usuarios', 'persona') ?></a>
        <a href="?r=auditoria" data-ruta="auditoria" class="<?= $ruta === 'auditoria' ? 'on' : '' ?>">Rastro y auditoría<?= ojito_html($porRuta, 'auditoria') ?></a>
      <?php endif ?>
      <a href="?r=ajustes"    data-ruta="ajustes" class="<?= $ruta === 'ajustes' ? 'on' : '' ?>">Ajustes<?= ojito_html($porRuta, 'ajustes') ?></a>
      <a href="#" class="guia-abrir" data-guia-abrir>Visita guiada</a>
    </nav>
    <div class="lateral-pie">
      <?php $yo = usuario_actual(); if ($yo !== null): ?>
        <a href="?r=perfil" class="quien">
          <span class="quien-inicial"><?= e(mb_strtoupper(mb_substr((string) $yo['nombre'], 0, 1))) ?></span>
          <span><b><?= e($yo['nombre']) ?></b><span><?= $yo['maestro'] ? 'Maestro' : 'Mi perfil' ?></span></span>
        </a>
      <?php endif ?>
      <p class="tema-rotulo">Cómo se ve</p>
      <form method="post" class="tema" data-guia="tema">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="accion" value="tema">
        <?php $ahora = tema();
        foreach ([['', 'Automático', 'Como esté la computadora'],
                  ['claro', 'Claro', 'Fondo blanco'],
                  ['oscuro', 'Oscuro', 'Fondo negro']] as [$v, $rot, $ayuda]): ?>
          <button name="tema" value="<?= e($v) ?>" title="<?= e($ayuda) ?>"
                  class="<?= $ahora === $v ? 'on' : '' ?>"><?= e($rot) ?></button>
        <?php endforeach ?>
      </form>
      <p class="tema-rotulo">Tamaño de la letra</p>
      <form method="post" class="tema tema-escala" data-guia="escala">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="accion" value="escala">
        <?php $tam = escala();
        foreach ([['', 'A', 'Letra normal'],
                  ['grande', 'A', 'Letra grande'],
                  ['enorme', 'A', 'Letra muy grande']] as $k => [$v, $rot, $ayuda]): ?>
          <button name="escala" value="<?= e($v) ?>" title="<?= e($ayuda) ?>"
                  style="font-size:<?= [13, 16, 19][$k] ?>px"
                  class="<?= $tam === $v ? 'on' : '' ?>"><?= e($rot) ?></button>
        <?php endforeach ?>
      </form>
      <a href="?r=salir">Cerrar sesión</a>
      <div class="credito">
        <?php // El número de versión lleva a lo que trae: suelto no le dice nada a nadie. ?>
        <a href="?r=mejoras" class="credito-version<?= $ruta === 'mejoras' ? ' on' : '' ?>"
           data-guia="mejoras" title="Ver todo lo que se ha mejorado">
          <b><?= e(APP_NOMBRE) ?></b> v<?= e(APP_VERSION) ?>
        </a>
        <span><?= e(APP_LEMA) ?></span><br>
        <span>by <?= e(APP_MARCA) ?></span>
      </div>
    </div>
  </aside>
  <main class="principal">
    <div class="encabezado">
      <div>
        <h1><?= e($titulo) ?></h1>
        <?php if ($subtitulo !== null): ?><p><?= $subtitulo ?></p><?php endif ?>
      </div>
      <?php if ($acciones !== ''): ?><div class="acciones"><?= $acciones ?></div><?php endif ?>
    </div>
    <?php /* Quién más está dentro. La caja existe siempre aunque esté vacía:
             el latido la llena sin recargar, y si apareciera de la nada
             empujaría la pantalla hacia abajo mientras alguien lee. */ ?>
    <div class="presentes" data-presentes<?= $gente === [] ? ' hidden' : '' ?>>
      <span class="presentes-rotulo">Trabajando ahora</span>
      <div class="presentes-gente" data-presentes-gente>
        <?php foreach ($gente as $g): ?>
          <?= presente_html($g, $ruta, $miRef) ?>
        <?php endforeach ?>
      </div>
    </div>
    <?php $ayuda = ayuda_pantalla($ruta); if ($ayuda !== ''): ?>
      <div class="ayuda-pantalla">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><circle cx="12" cy="8" r=".7" fill="currentColor" stroke="none"/></svg>
        <div><?= $ayuda ?></div>
      </div>
    <?php endif ?>
    <?php if ($mensaje['texto'] !== ''): ?>
      <div class="aviso aviso-<?= e($mensaje['tipo']) ?>"><?= e($mensaje['texto']) ?></div>
    <?php endif ?>
<?php
}

function pie_html(): void
{
    global $ruta;
    ?>
  </main>
</div>
<script>
window.GUIA = <?= json_encode([
    'ruta'  => $ruta,
    'pasos' => guia_pasos(),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.PRESENCIA = <?= json_encode([
    'ruta' => $ruta,
    'ref'  => referencia_pantalla(),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="assets/app.js?v=15"></script>
<script src="assets/guia.js?v=12"></script>
</body>
</html>
<?php
}

/**
 * El ojito del menú: enseña que hay alguien parado en esa pantalla.
 *
 * Se dibuja siempre, escondido cuando no hay nadie, porque el latido solo
 * cambia el título y lo enseña: si tuviera que crearlo, el renglón del menú
 * daría un salto cada vez que alguien entra o sale de esa sección.
 */
function ojito_html(array $porRuta, string $ruta, string $tambien = ''): string
{
    $quien = array_merge($porRuta[$ruta] ?? [], $tambien === '' ? [] : ($porRuta[$tambien] ?? []));
    $n = count($quien);
    // role + aria-label y no solo `title`: un `title` en un span no lo anuncia
    // un lector de pantalla, y el ojito dice algo que no está escrito en otro
    // sitio del renglón.
    return '<span class="ojito" role="img"' . ($n === 0 ? ' hidden' : '')
        . ' data-ojito="' . e($ruta) . '" title="' . e(quien_esta($quien)) . '"'
        . ' aria-label="' . e(quien_esta($quien)) . '">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true">'
        . '<path d="M1.8 12S5.6 5.5 12 5.5 22.2 12 22.2 12 18.4 18.5 12 18.5 1.8 12 1.8 12Z"/>'
        . '<circle cx="12" cy="12" r="3.1"/></svg>'
        . '<b' . ($n > 1 ? '' : ' hidden') . '>' . $n . '</b></span>';
}

/** «Erika Varela está aquí» o «Erika Varela y Larry Manrique están aquí». */
function quien_esta(array $nombres): string
{
    if ($nombres === []) {
        return '';
    }
    $ultimo = array_pop($nombres);
    $lista  = $nombres === [] ? $ultimo : implode(', ', $nombres) . ' y ' . $ultimo;
    return $lista . ($nombres === [] ? ' está aquí' : ' están aquí');
}

/**
 * El nombre de quien hizo algo, enlazado a su ficha.
 *
 * Solo el maestro llega a la ficha, así que a los demás se les enseña el
 * nombre pelado: un enlace que rebota con «no puede» es peor que no tenerlo.
 * Las líneas viejas del rastro no tienen autor —se anotaban antes de que
 * hubiera usuarios—; ahí queda la raya.
 */
function persona_enlace(array $fila): string
{
    $id = (int) ($fila['usuario_id'] ?? 0);
    $nombre = (string) ($fila['usuario'] ?? '—');
    if ($id <= 0 || !es_maestro()) {
        return '<b>' . e($nombre) . '</b>';
    }
    return '<a href="?r=persona&amp;id=' . $id . '" title="Ver todo lo de esta persona"><b>'
        . e($nombre) . '</b></a>';
}

/**
 * La pastilla de una persona conectada: su inicial y en qué anda.
 *
 * «Está aquí» compara pantalla **y** a qué se refiere, igual que el latido: si
 * solo mirara la pantalla, en el detalle de un movimiento se pondría verde
 * quien está en otro movimiento distinto, y a los veinte segundos —cuando
 * contesta el servidor— se apagaría solo. El verde promete «cuidado, están en
 * lo mismo que usted»; equivocarse en eso es peor que no ponerlo.
 */
function presente_html(array $g, string $ruta = '', int $ref = 0): string
{
    $aqui = (string) $g['pantalla'] === $ruta && (int) $g['pantalla_ref'] === $ref;
    $rotulo = $g['nombre'] . ' · está en ' . nombre_pantalla((string) $g['pantalla'])
        . ((int) $g['hace'] <= 0 ? ' · ahora mismo' : ' · visto hace ' . (int) $g['hace'] . ' min');
    return '<span class="presente' . ($aqui ? ' presente-aqui' : '') . '"'
        . ' data-presente="' . (int) $g['id'] . '" title="' . e($rotulo) . '">'
        . '<i>' . e(mb_strtoupper(mb_substr((string) $g['nombre'], 0, 1))) . '</i>'
        . '<span>' . e((string) $g['nombre']) . '</span></span>';
}

/** Barra segmentada: cómo se reparte el dinero del período. */
function cinta_html(array $reparto, float $total): void
{
    if ($total <= 0 || $reparto === []) {
        return;
    }
    echo '<div class="cinta">';
    foreach ($reparto as $r) {
        $pct = $r['total'] / $total * 100;
        if ($pct < 0.35) {
            continue;
        }
        $corto = $pct < 6 ? ' data-corto="1"' : '';
        printf('<div style="flex:%F 0 0;background:%s" data-pct="%s%%" title="%s · Bs %s"%s></div>',
            $pct, e($r['color']), number_format($pct, $pct < 10 ? 1 : 0, ',', '.'),
            e($r['categoria']), bs((float) $r['total']), $corto);
    }
    echo '</div><div class="cinta-leyenda">';
    foreach ($reparto as $r) {
        $pct = $r['total'] / $total * 100;
        if ($pct < 0.35) {
            continue;
        }
        printf('<span><i style="background:%s"></i>%s <b>%s</b></span>',
            e($r['color']), e($r['categoria']), bs((float) $r['total'], 0));
    }
    echo '</div>';
}

/** Paginación con saltos compactos. */
function paginas_html(int $pagina, int $paginas, int $total, string $etiqueta = 'movimientos'): void
{
    echo '<div class="paginas"><span>' . number_format($total, 0, ',', '.') . ' ' . e($etiqueta);
    if ($paginas > 1) {
        echo ' · página ' . $pagina . ' de ' . $paginas;
    }
    echo '</span>';
    if ($paginas > 1) {
        echo '<span class="saltos">';
        if ($pagina > 1) {
            echo '<a href="' . e(url(['p' => 1])) . '">««</a><a href="' . e(url(['p' => $pagina - 1])) . '">‹</a>';
        }
        $ini = max(1, $pagina - 2);
        $fin = min($paginas, $ini + 4);
        for ($i = $ini; $i <= $fin; $i++) {
            echo $i === $pagina
                ? '<span style="color:var(--oro)">' . $i . '</span>'
                : '<a href="' . e(url(['p' => $i])) . '">' . $i . '</a>';
        }
        if ($pagina < $paginas) {
            echo '<a href="' . e(url(['p' => $pagina + 1])) . '">›</a><a href="' . e(url(['p' => $paginas])) . '">»»</a>';
        }
        echo '</span>';
    }
    echo '</div>';
}
