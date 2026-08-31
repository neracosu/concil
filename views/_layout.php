<?php
/** Armazón compartido. $titulo, $subtitulo y $acciones los define cada vista. */
function encabezado_html(string $titulo, string $ruta, ?string $subtitulo = null, string $acciones = ''): void
{
    global $mensaje;
    $pend = pendientes_total();
    ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($titulo) ?> · <?= e(APP_CREDITO) ?></title>
<meta name="application-name" content="<?= e(APP_NOMBRE) ?>">
<meta name="author" content="<?= e(APP_MARCA) ?>">
<link rel="icon" type="image/png" href="/icon.png">
<link rel="stylesheet" href="assets/app.css?v=12">
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
      <a href="?r=panel"       class="<?= $ruta === 'panel' ? 'on' : '' ?>">Panel</a>
      <a href="?r=carga"       class="<?= $ruta === 'carga' ? 'on' : '' ?>">Cargar extractos</a>
      <a href="?r=pendientes"  class="<?= $ruta === 'pendientes' ? 'on' : '' ?>">Por justificar
        <?php if ($pend > 0): ?><span class="cuenta"><?= $pend > 999 ? '999+' : $pend ?></span><?php endif ?></a>
      <a href="?r=movimientos" class="<?= $ruta === "movimientos" || $ruta === "movimiento" ? "on" : "" ?>">Movimientos</a>
      <a href="?r=reportes"    class="<?= $ruta === "reportes" ? "on" : "" ?>">Reportes</a>
      <div class="nav-titulo">Configuración</div>
      <a href="?r=reglas"     class="<?= $ruta === 'reglas' ? 'on' : '' ?>">Reglas de mapeo</a>
      <a href="?r=categorias" class="<?= $ruta === 'categorias' ? 'on' : '' ?>">Categorías</a>
      <a href="?r=cuentas"    class="<?= $ruta === 'cuentas' ? 'on' : '' ?>">Cuentas</a>
      <a href="?r=sede"       class="<?= $ruta === 'sede' ? 'on' : '' ?>">Unidades de negocio</a>
      <a href="?r=ajustes"    class="<?= $ruta === 'ajustes' ? 'on' : '' ?>">Ajustes</a>
      <a href="#" class="guia-abrir" data-guia-abrir>Visita guiada</a>
    </nav>
    <div class="lateral-pie">
      <a href="?r=salir">Cerrar sesión</a>
      <div class="credito">
        <b><?= e(APP_NOMBRE) ?></b> v<?= e(APP_VERSION) ?><br>
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
</script>
<script src="assets/app.js?v=11"></script>
<script src="assets/guia.js?v=11"></script>
</body>
</html>
<?php
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
