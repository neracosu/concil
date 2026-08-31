<?php
/**
 * Conciliación Bancaria · VIP Soft
 * Front controller: resuelve la ruta, ejecuta las acciones POST y pinta la vista.
 */
declare(strict_types=1);

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/texto.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/sedes.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/xlsx.php';
require __DIR__ . '/lib/huella.php';
require __DIR__ . '/lib/proveedores.php';
require __DIR__ . '/lib/reglas.php';
require __DIR__ . '/lib/importador.php';
require __DIR__ . '/lib/consultas.php';
require __DIR__ . '/lib/exportar.php';
require __DIR__ . '/lib/seed.php';
require __DIR__ . '/lib/guia.php';
require __DIR__ . '/views/_layout.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

iniciar_sesion();

try {
    migrar();
    if ((int) db()->query('SELECT COUNT(*) FROM categorias')->fetchColumn() === 0) {
        sembrar();
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('<p style="font:15px system-ui;padding:2rem">No hay conexión con la base de datos. Revisa las credenciales en '
        . e(SECRETS) . '.</p>');
}

$ruta = preg_replace('/[^a-z_]/', '', (string) ($_GET['r'] ?? 'panel'));
$rutasPublicas = ['login'];

if (!in_array($ruta, $rutasPublicas, true) && !autenticado()) {
    $ruta = 'login';
}
if ($ruta === 'login' && autenticado()) {
    header('Location: ?r=panel');
    exit;
}

// Cambiar de unidad de negocio. Va por POST y con testigo porque descarta la
// carga pendiente y borra sus archivos: un GET lo dispararía cualquier página
// ajena con una imagen incrustada.
if (autenticado() && ($_POST['accion'] ?? '') === 'cambiar_sede') {
    exigir_csrf();
    $pedida = (int) ($_POST['sede'] ?? 0);
    if (!in_array($pedida, array_map('intval', array_column(sedes(), 'id')), true)) {
        redirigir('?r=' . $ruta);       // id inventado: no se toca nada
    }
    fijar_sede($pedida);

    // Una carga a medio confirmar quedó analizada contra las cuentas de la
    // unidad anterior: se descarta con sus archivos para no importarla aquí.
    foreach ($_SESSION['lote'] ?? [] as $a) {
        if (!empty($a['ruta']) && is_file($a['ruta'])) {
            @unlink($a['ruta']);
        }
    }
    unset($_SESSION['lote']);

    // El detalle de un movimiento no existe en la unidad a la que se entra,
    // así que se cae al listado en vez de dar «ese movimiento ya no existe».
    redirigir('?r=' . ($ruta === 'movimiento' ? 'movimientos' : $ruta));
}

// Con más de una sede y ninguna elegida, lo primero es elegirla.
if (autenticado() && $ruta !== 'salir' && sede_actual() === null) {
    $ruta = 'sede';
}

$vista = __DIR__ . "/views/$ruta.php";
if (!is_file($vista)) {
    http_response_code(404);
    $ruta = 'panel';
    $vista = __DIR__ . '/views/panel.php';
}

$mensaje = ['tipo' => '', 'texto' => ''];
if (isset($_SESSION['flash'])) {
    $mensaje = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

function flash(string $tipo, string $texto): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'texto' => $texto];
}

function redirigir(string $a): never
{
    header('Location: ' . $a);
    exit;
}

// Las vistas de descarga escriben directo a la salida y terminan ahí.
require $vista;
