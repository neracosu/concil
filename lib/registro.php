<?php
/**
 * Registro de fallos.
 *
 * Cuando algo se rompe, quien usa el sistema no sabe explicar qué pasó, y sin
 * eso no hay forma de arreglarlo. Esto deja constancia sola de **qué** ocurrió,
 * **dónde** (archivo, línea y pantalla) y **cómo** se llegó hasta ahí (la
 * cadena de llamadas), y le da a la persona un código corto que puede leer por
 * teléfono.
 *
 * Se escribe en un archivo dentro de DATA_DIR, no en la base: el fallo más
 * probable y más grave es justamente que la base no responda, y entonces una
 * tabla no serviría de nada.
 *
 * Nunca se guarda el contenido de los formularios: por ahí viaja el PIN.
 */

/** Carpeta donde viven los registros, fuera de public_html. */
function registro_dir(): string
{
    $d = DATA_DIR . '/registro';
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    return $d;
}

/** Un archivo por mes: se revisa fácil y no crece sin límite. */
function registro_archivo(?string $mes = null): string
{
    return registro_dir() . '/fallos-' . ($mes ?? date('Y-m')) . '.log';
}

/**
 * Anota un fallo y devuelve su código, que es lo que se le enseña a la persona.
 * Corto a propósito: tiene que poder dictarse por teléfono.
 */
function registrar_fallo(string $tipo, string $mensaje, string $archivo = '', int $linea = 0, string $traza = ''): string
{
    $codigo = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

    $entrada = [
        'codigo'  => $codigo,
        'cuando'  => date('c'),
        'tipo'    => $tipo,
        'mensaje' => mb_substr($mensaje, 0, 1000),
        'donde'   => $archivo !== '' ? basename($archivo) . ':' . $linea : '',
        'pantalla' => preg_replace('/[^a-z_]/', '', (string) ($_GET['r'] ?? '')) ?: '(ninguna)',
        'metodo'  => $_SERVER['REQUEST_METHOD'] ?? 'cli',
        'sede'    => (int) ($_SESSION['sede'] ?? 0),
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
        // La cadena de llamadas, sin argumentos: ahí podrían ir contraseñas.
        'como'    => mb_substr($traza, 0, 2000),
    ];

    @file_put_contents(
        registro_archivo(),
        json_encode($entrada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
    @chmod(registro_archivo(), 0600);

    return $codigo;
}

/** Traza de llamadas legible y sin argumentos. */
function registro_traza(array $traza): string
{
    $lineas = [];
    foreach (array_slice($traza, 0, 12) as $p) {
        $lineas[] = ($p['function'] ?? '?') . '()'
            . (isset($p['file']) ? ' en ' . basename($p['file']) . ':' . ($p['line'] ?? 0) : '');
    }
    return implode(' ← ', $lineas);
}

/**
 * Engancha los tres caminos por los que PHP puede fallar: un aviso, una
 * excepción que nadie atrapó, y un error fatal que corta la ejecución.
 */
function vigilar_fallos(): void
{
    set_error_handler(function (int $n, string $s, string $f = '', int $l = 0): bool {
        // Los avisos no interrumpen, pero quedan anotados: suelen ser el aviso
        // previo de algo que se romperá del todo más adelante.
        if (!(error_reporting() & $n)) {
            return false;
        }
        registrar_fallo(nombre_error($n), $s, $f, $l, registro_traza(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8)));
        return true;
    });

    set_exception_handler(function (Throwable $e): void {
        $codigo = registrar_fallo('Excepción ' . get_class($e), $e->getMessage(),
            $e->getFile(), $e->getLine(), registro_traza($e->getTrace()));
        pantalla_de_fallo($codigo);
    });

    register_shutdown_function(function (): void {
        $e = error_get_last();
        if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        $codigo = registrar_fallo(nombre_error($e['type']), $e['message'], $e['file'], $e['line'], '');
        pantalla_de_fallo($codigo);
    });
}

function nombre_error(int $n): string
{
    return match ($n) {
        E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR => 'Error fatal',
        E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'Aviso',
        E_NOTICE, E_USER_NOTICE => 'Nota',
        E_DEPRECATED, E_USER_DEPRECATED => 'Obsoleto',
        E_PARSE => 'Error de sintaxis',
        default => 'Error ' . $n,
    };
}

/**
 * Lo que ve la persona. Sin detalles técnicos —no le sirven y pueden ayudar a
 * quien no debe— pero con el código, que es lo que hace falta para investigar.
 */
function pantalla_de_fallo(string $codigo): void
{
    if (headers_sent()) {
        echo '<p style="font:15px system-ui;padding:1rem">Algo falló. Código <b>' . $codigo . '</b>.</p>';
        return;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Algo falló · ' . e(APP_CREDITO) . '</title>'
       . '<link rel="stylesheet" href="assets/app.css?v=13"></head>'
       . '<body class="elegir-cuerpo"><div class="elegir-velo"><div class="elegir-carta">'
       . '<div class="elegir-kicker">' . e(APP_NOMBRE) . ' <span>by ' . e(APP_MARCA) . '</span></div>'
       . '<h1 class="elegir-titulo">Algo falló y no pudimos continuar</h1>'
       . '<p class="elegir-texto">No se perdió nada de lo que ya estaba guardado. '
       . 'Vuelva a intentarlo; si sigue pasando, dé este código a quien lleva el sistema:</p>'
       . '<div class="elegir-aviso" style="text-align:center;font-size:22px;letter-spacing:.18em">'
       . '<b>' . e($codigo) . '</b></div>'
       . '<p class="elegir-texto" style="margin-top:18px;font-size:13.5px">'
       . 'Queda anotado qué ocurrió, en qué pantalla y en qué punto del programa.</p>'
       . '<a class="elegir-salir" href="?r=panel">Volver al panel</a>'
       . '</div></div></body></html>';
    exit;
}

/** Últimos fallos anotados, del más reciente al más antiguo. */
function fallos_recientes(int $limite = 40): array
{
    $todos = [];
    foreach (glob(registro_dir() . '/fallos-*.log') ?: [] as $f) {
        foreach (array_reverse(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $l) {
            $d = json_decode($l, true);
            if (is_array($d)) {
                $todos[] = $d;
            }
            if (count($todos) >= $limite * 3) {
                break 2;
            }
        }
    }
    usort($todos, fn($a, $b) => strcmp($b['cuando'] ?? '', $a['cuando'] ?? ''));
    return array_slice($todos, 0, $limite);
}

/** Cuántos fallos van este mes, para avisar sin tener que entrar a mirar. */
function fallos_del_mes(): int
{
    $f = registro_archivo();
    return is_file($f) ? count(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : 0;
}

/** Borra los registros de meses anteriores al indicado. */
function purgar_fallos(int $mesesAConservar = 3): int
{
    $corte = date('Y-m', strtotime("-$mesesAConservar months"));
    $n = 0;
    foreach (glob(registro_dir() . '/fallos-*.log') ?: [] as $f) {
        if (preg_match('/fallos-(\d{4}-\d{2})\.log$/', $f, $m) && $m[1] < $corte) {
            @unlink($f);
            $n++;
        }
    }
    return $n;
}
