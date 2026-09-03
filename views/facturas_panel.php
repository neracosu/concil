<?php
/**
 * Solo las filas de facturas de un proveedor para un pago.
 *
 * Lo pide el navegador cuando se cambia de proveedor, para no recargar la
 * pantalla entera. Devuelve el mismo trozo de HTML que dibuja la vista, así que
 * la lista se escribe en un solo sitio.
 */
exigir_login();

$movId = (int) ($_GET['mov'] ?? 0);
$s = db()->prepare('SELECT m.id, m.fecha, m.debito FROM movimientos m
                     WHERE m.id = ? AND ' . filtro_sede());
$s->execute([$movId]);
$mov = $s->fetch();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

if (!$mov) {
    echo '<p class="reparto-vacio">Ese pago no es de esta unidad de negocio.</p>';
    return;
}

// El proveedor llega por su nombre, que es lo que hay escrito en el campo. Se
// busca, no se crea: escribir en una casilla no debe dar de alta a nadie.
$nombre = mb_substr(limpiar((string) ($_GET['prov'] ?? '')), 0, 160);
$prov = $nombre === '' ? null : proveedor_que_choca($nombre, '')['nombre'];

// El form="…" al que atar los campos cuando el panel va dentro de una tabla.
$idForm = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['form'] ?? ''));
$att = $idForm !== '' ? ' form="' . e($idForm) . '"' : '';

// Con panel=1 se devuelve el panel completo, que es lo que pide la bandeja al
// abrir una fila; sin él, solo las filas de facturas, que es lo que se cambia
// al elegir otro proveedor.
$entero = ($_GET['panel'] ?? '') === '1';

if ($prov === null && $entero) {
    panel_facturas($mov, null, $idForm);
    return;
}

if ($prov === null) {
    echo '<p class="reparto-vacio">'
       . ($nombre === ''
            ? 'Diga arriba a quién se le pagó y aquí aparecerán sus facturas.'
            : 'A «' . e($nombre) . '» todavía no lo tiene en el listado. Al guardar se crea, y desde '
              . 'entonces sus facturas aparecerán aquí.')
       . '</p>';
    return;
}

if ($entero) {
    panel_facturas($mov, (int) $prov['id'], $idForm);
} else {
    lista_facturas($mov, (int) $prov['id'], $att);
}
