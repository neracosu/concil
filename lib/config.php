<?php
/**
 * Configuración base.
 * Los datos sensibles (credenciales de la base, PIN) viven en DATA_DIR,
 * fuera de public_html: no son accesibles por web.
 */

// Identidad del producto
const APP_NOMBRE  = 'CONSIL';
const APP_MARCA   = 'VIP Soft';
const APP_LEMA    = 'Conciliación bancaria';
const APP_CREDITO = 'CONSIL by VIP Soft';
const APP_VERSION = '1.0';
const APP_NAME    = APP_CREDITO;   // usado en los títulos del navegador
const DATA_DIR    = '/home/mardenli/conciliacion_data';
const UPLOAD_DIR  = DATA_DIR . '/uploads';
const SECRETS     = DATA_DIR . '/secrets.php';

// El PIN de la primera instalación no se escribe en el código. Si no viene en
// secrets.php, se genera uno al azar y se deja en DATA_DIR/PIN-INICIAL.txt.

// Seguridad de acceso
const MAX_INTENTOS = 5;      // intentos fallidos seguidos antes de bloquear
const BLOQUEO_SEGS = 900;    // 15 minutos de bloqueo
const SESION_SEGS  = 28800;  // 8 horas de sesión

// Carga de archivos
const MAX_UPLOAD_MB  = 25;
const EXT_PERMITIDAS = ['xlsx', 'csv'];

/**
 * PIN de arranque de una instalación nueva.
 * Solo se usa mientras la base no tenga todavía un PIN guardado.
 */
function pin_inicial(): string
{
    $s = secretos();
    if (!empty($s['pin_inicial']) && preg_match('/^\d{6}$/', (string) $s['pin_inicial'])) {
        return (string) $s['pin_inicial'];
    }
    $archivo = DATA_DIR . '/PIN-INICIAL.txt';
    if (is_readable($archivo)) {
        $guardado = trim((string) file_get_contents($archivo));
        if (preg_match('/^\d{6}$/', $guardado)) {
            return $guardado;
        }
    }
    $pin = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    file_put_contents($archivo, $pin . "\n");
    @chmod($archivo, 0600);
    return $pin;
}

function secretos(): array
{
    static $s = null;
    if ($s === null) {
        if (!is_readable(SECRETS)) {
            http_response_code(500);
            exit('Falta el archivo de credenciales en ' . SECRETS);
        }
        $s = require SECRETS;
    }
    return $s;
}

/** Tope real de subida del servidor, en MB (el menor entre los dos límites). */
function limite_subida(): int
{
    $a = tamano_ini(ini_get('upload_max_filesize'));
    $b = tamano_ini(ini_get('post_max_size'));
    $mb = (int) floor(min($a, $b) / 1048576);
    return max(1, min($mb, MAX_UPLOAD_MB));
}

function tamano_ini(string $v): int
{
    $v = trim($v);
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1073741824,
        'm' => $n * 1048576,
        'k' => $n * 1024,
        default => $n,
    };
}

/** Traduce el código de error de PHP a algo que se entienda. */
function motivo_fallo_subida(int $codigo): string
{
    return match ($codigo) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'pesa más de lo que el servidor acepta.',
        UPLOAD_ERR_PARTIAL    => 'la subida se cortó a medias. Vuelve a intentarlo.',
        UPLOAD_ERR_NO_FILE    => 'no llegó ningún archivo.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'el servidor no pudo guardarlo. Avisa a soporte.',
        UPLOAD_ERR_EXTENSION  => 'el servidor bloqueó este tipo de archivo.',
        default               => 'no se pudo subir.',
    };
}
