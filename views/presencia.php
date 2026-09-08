<?php
/**
 * Quién está trabajando ahora mismo, en JSON.
 *
 * Es lo único de la aplicación que no dibuja una pantalla: lo pide el propio
 * navegador cada veinte segundos para encender los ojitos del menú sin que
 * nadie tenga que recargar. Sin websockets ni nada que instalar —el hosting es
 * compartido—: una consulta a una tabla de cuatro filas es más barata que
 * mantener una conexión abierta.
 *
 * La marca de presencia ya la dejó el front controller antes de llegar aquí.
 */
exigir_login();

$gente = [];
foreach (presencia_viva() as $g) {
    $gente[] = [
        'id'      => (int) $g['id'],
        'nombre'  => (string) $g['nombre'],
        'inicial' => mb_strtoupper(mb_substr((string) $g['nombre'], 0, 1)),
        'ruta'    => (string) $g['pantalla'],
        'donde'   => nombre_pantalla((string) $g['pantalla']),
        'ref'     => (int) $g['pantalla_ref'],
        'hace'    => (int) $g['hace'],
        'maestro' => (int) $g['maestro'],
        'sede'    => (string) $g['sede'],
        // ¿Está en lo mismo que yo? Es el aviso que de verdad ahorra trabajo:
        // dos personas justificando el mismo pago se pisan.
        'aqui'    => (string) $g['pantalla'] === (string) ($_GET['en'] ?? '')
                     && (int) $g['pantalla_ref'] === (int) ($_GET['ref'] ?? 0),
    ];
}

/* De paso viajan los dos contadores del menú: quien deja la pantalla abierta
   mientras otra persona carga un extracto ve subir lo que le falta por hacer
   sin recargar nada. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'gente' => $gente,
    'pend'  => sede_elegida() ? pendientes_total() : 0,
    'rep'   => sede_elegida() ? contar_repetidos() : 0,
], JSON_UNESCAPED_UNICODE);
exit;
