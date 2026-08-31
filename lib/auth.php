<?php
/** Acceso por PIN de 6 dígitos, con bloqueo por intentos fallidos. */

function iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Strict',
    ]);
    session_name('CONCILSESS');
    session_start();
}

function pin_hash(): string
{
    $h = ajuste('pin_hash');
    if ($h === null) {
        $h = password_hash(pin_inicial(), PASSWORD_DEFAULT);
        guardar_ajuste('pin_hash', $h);
        guardar_ajuste('pin_inicial_pendiente', '1');
    }
    return $h;
}

function bloqueado(): int
{
    $hasta = (int) ajuste('bloqueo_hasta', '0');
    return max(0, $hasta - time());
}

/**
 * Busca a quién pertenece ese PIN. Devuelve el usuario, o null.
 * El contador de intentos es del sistema, no de la persona: un PIN fallido no
 * dice de quién era, así que no se le puede achacar a nadie.
 */
function verificar_pin(string $pin): ?array
{
    $u = usuario_por_pin($pin);
    if ($u !== null) {
        guardar_ajuste('intentos', '0');
        guardar_ajuste('bloqueo_hasta', '0');
        return $u;
    }
    $n = (int) ajuste('intentos', '0') + 1;
    guardar_ajuste('intentos', (string) $n);
    if ($n >= MAX_INTENTOS) {
        guardar_ajuste('bloqueo_hasta', (string) (time() + BLOQUEO_SEGS));
        guardar_ajuste('intentos', '0');
        bitacora('bloqueo', 'Demasiados intentos fallidos');
    }
    return null;
}

/**
 * A partir del tercer intento fallido se pide una suma sencilla. Es para los
 * robots que prueban PINs en serie, no para complicarle la vida a quien se
 * equivocó de tecla: dos intentos son gratis.
 */
function captcha_necesario(): bool
{
    return (int) ajuste('intentos', '0') >= 3;
}

/** Prepara la suma y guarda el resultado esperado en la sesión. */
function captcha_nuevo(): array
{
    $a = random_int(2, 9);
    $b = random_int(2, 9);
    $_SESSION['captcha'] = $a + $b;
    return [$a, $b];
}

function captcha_correcto(string $respuesta): bool
{
    $esperado = $_SESSION['captcha'] ?? null;
    unset($_SESSION['captcha']);        // de un solo uso
    return $esperado !== null && trim($respuesta) !== '' && (int) $respuesta === (int) $esperado;
}

/** Cambia el PIN de quien está dentro. Vive en Mi perfil. */
function cambiar_pin(string $nuevo): ?string
{
    $id = (int) ($_SESSION['uid'] ?? 0);
    if ($id <= 0) {
        return 'No hay sesión activa.';
    }
    return cambiar_pin_usuario($id, $nuevo);
}

function autenticado(): bool
{
    if (empty($_SESSION['auth'])) {
        return false;
    }
    if (time() - (int) ($_SESSION['visto'] ?? 0) > SESION_SEGS) {
        cerrar_sesion();
        return false;
    }
    $_SESSION['visto'] = time();
    return true;
}

function entrar(array $usuario): void
{
    session_regenerate_id(true);
    $_SESSION['auth'] = true;
    $_SESSION['uid'] = (int) $usuario['id'];
    db()->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?')->execute([$usuario['id']]);
    // La unidad de negocio se elige en cada inicio de sesión: quien concilia
    // lleva varias y arrastrar la del día anterior invita a equivocarse.
    unset($_SESSION['sede']);
    $_SESSION['visto'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    bitacora('acceso', 'Entró ' . $usuario['nombre']);
}

function cerrar_sesion(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function exigir_csrf(): void
{
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(419);
        exit('Sesión expirada. Vuelve a cargar la página.');
    }
}

function exigir_login(): void
{
    if (!autenticado()) {
        header('Location: ?r=login');
        exit;
    }
}
