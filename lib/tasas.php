<?php
/**
 * Tasa oficial del BCV, un valor por día.
 *
 * Administración necesita leer cada operación con la tasa que regía **el día en
 * que ocurrió**, no con la de hoy. Por eso se guarda una fila por fecha y no se
 * vuelve a tocar: es un dato histórico, no un valor que se recalcula.
 *
 * La fecha que manda es la del movimiento —la que trae el extracto del banco—,
 * nunca la de la carga del archivo. Un extracto de julio subido en septiembre
 * sigue leyéndose con las tasas de julio.
 *
 * La fuente publica un valor por día de calendario: los fines de semana y
 * feriados repiten la última tasa vigente, así que no quedan huecos.
 */

const TASAS_HISTORICO = 'https://bcv.today/api/v1/history.json';
const TASAS_HOY       = 'https://bcv.today/api/v1/rate.json';

/**
 * Descarga un JSON. Devuelve null ante cualquier problema: quedarse sin tasas
 * nunca puede tumbar una pantalla, porque son un dato de apoyo.
 */
function traer_json(string $url, int $espera = 8): ?array
{
    $cuerpo = null;
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $espera,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => APP_NOMBRE . '/' . APP_VERSION,
        ]);
        $r = curl_exec($c);
        $codigo = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        $cuerpo = ($r !== false && $codigo === 200) ? (string) $r : null;
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => $espera, 'ignore_errors' => true]]);
        $r = @file_get_contents($url, false, $ctx);
        $cuerpo = $r === false ? null : $r;
    }
    if ($cuerpo === null) {
        return null;
    }
    $datos = json_decode($cuerpo, true);
    return is_array($datos) ? $datos : null;
}

/**
 * Guarda las tasas que vengan en la lista. Una tasa escrita a mano no se pisa
 * nunca: si alguien la corrigió, sabe algo que la fuente no sabe.
 */
function guardar_tasas(array $dias): int
{
    $limpias = [];
    foreach ($dias as $d) {
        // Se guarda por `date`, que es el día de calendario, con la tasa que
        // regía ese día. `effective_date` no sirve como clave: el sábado y el
        // domingo llevan la del viernes, así que los tres días comparten
        // `effective_date` y guardar por ahí dejaría el fin de semana sin fila.
        $fecha = (string) ($d['date'] ?? $d['effective_date'] ?? '');
        $tasa  = (float) ($d['USD'] ?? 0);
        if ($tasa > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $limpias[$fecha] = $tasa;
        }
    }
    if ($limpias === []) {
        return 0;
    }

    $pdo = db();
    $n = 0;
    foreach (array_chunk($limpias, 200, true) as $trozo) {
        $huecos = implode(',', array_fill(0, count($trozo), '(?,?,\'bcv\')'));
        $args = [];
        foreach ($trozo as $fecha => $tasa) {
            $args[] = $fecha;
            $args[] = $tasa;
        }
        $s = $pdo->prepare("INSERT INTO tasas (fecha, tasa, origen) VALUES $huecos
                            ON DUPLICATE KEY UPDATE tasa = IF(origen = 'manual', tasa, VALUES(tasa))");
        $s->execute($args);
        $n += count($trozo);
    }
    return $n;
}

/**
 * Trae las tasas del BCV. Con $todo se pide el histórico completo (cinco años
 * en una sola llamada, que es como se rellena lo ya importado); sin él, solo
 * la del día.
 */
function sincronizar_tasas(bool $todo = false): array
{
    $datos = traer_json($todo ? TASAS_HISTORICO : TASAS_HOY, $todo ? 20 : 6);
    if ($datos === null) {
        guardar_ajuste('tasas_intento', date('Y-m-d'));
        return ['guardadas' => 0, 'error' => 'No se pudo consultar la tasa del BCV en este momento.'];
    }
    // El histórico llega como lista; la del día, como un solo objeto.
    $dias = $todo ? $datos : [$datos];
    $n = guardar_tasas($dias);
    guardar_ajuste('tasas_intento', date('Y-m-d'));
    guardar_ajuste('tasas_sync', date('Y-m-d H:i:s'));
    return ['guardadas' => $n, 'error' => ''];
}

/**
 * Se asegura de tener la tasa de hoy, como mucho una vez al día. Si la consulta
 * falla tampoco se reintenta en cada página: la aplicación tiene que seguir
 * respondiendo igual de rápido aunque la fuente esté caída.
 */
function tasas_al_dia(): void
{
    $hoy = date('Y-m-d');
    if (ajuste('tasas_intento') === $hoy) {
        return;
    }
    // La primera vez no hay nada guardado: se trae el histórico entero para que
    // lo ya importado quede con su tasa desde el primer momento.
    $vacio = (int) db()->query('SELECT COUNT(*) FROM tasas')->fetchColumn() === 0;
    sincronizar_tasas($vacio);
}

/**
 * Tasa vigente el día indicado. Si ese día no está guardado se usa la última
 * anterior, que es la que seguía rigiendo.
 */
function tasa_de(?string $fecha): ?float
{
    static $cache = [];
    if ($fecha === null || $fecha === '') {
        return null;
    }
    $fecha = substr($fecha, 0, 10);
    if (array_key_exists($fecha, $cache)) {
        return $cache[$fecha];
    }
    $s = db()->prepare('SELECT tasa FROM tasas WHERE fecha <= ? ORDER BY fecha DESC LIMIT 1');
    $s->execute([$fecha]);
    $v = $s->fetchColumn();
    return $cache[$fecha] = ($v === false ? null : (float) $v);
}

/** Qué hay guardado, para poder decirlo en Ajustes. */
function estado_tasas(): array
{
    $r = db()->query('SELECT COUNT(*) dias, MIN(fecha) primera, MAX(fecha) ultima FROM tasas')->fetch();
    return [
        'dias'         => (int) ($r['dias'] ?? 0),
        'primera'      => $r['primera'] ?? null,
        'ultima'       => $r['ultima'] ?? null,
        'sincronizado' => ajuste('tasas_sync'),
    ];
}

/**
 * La tasa como se escribe: dos decimales y coma, igual que los bolívares.
 * Admite el valor crudo de la base (una cadena) para poder usarse en las vistas
 * sin convertir en cada tabla.
 */
function tasa_texto(int|float|string|null $t): string
{
    return ($t === null || $t === '') ? '—' : number_format((float) $t, 2, ',', '.');
}
