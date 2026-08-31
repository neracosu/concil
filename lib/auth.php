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

function verificar_pin(string $pin): bool
{
    if (!preg_match('/^\d{6}$/', $pin)) {
        return false;
    }
    if (password_verify($pin, pin_hash())) {
        guardar_ajuste('intentos', '0');
        guardar_ajuste('bloqueo_hasta', '0');
        return true;
    }
    $n = (int) ajuste('intentos', '0') + 1;
    guardar_ajuste('intentos', (string) $n);
    if ($n >= MAX_INTENTOS) {
        guardar_ajuste('bloqueo_hasta', (string) (time() + BLOQUEO_SEGS));
        guardar_ajuste('intentos', '0');
        bitacora('bloqueo', 'Demasiados intentos fallidos');
    }
    return false;
}

function cambiar_pin(string $nuevo): ?string
{
    if (!preg_match('/^\d{6}$/', $nuevo)) {
        return 'El PIN debe tener exactamente 6 dígitos.';
    }
    if (preg_match('/^(\d)\1{5}$/', $nuevo)) {
        return 'No uses un PIN con los 6 dígitos iguales.';
    }
    if (in_array($nuevo, ['123456', '654321', '012345', '111111', '000000'], true)) {
        return 'Ese PIN es demasiado predecible.';
    }
    guardar_ajuste('pin_hash', password_hash($nuevo, PASSWORD_DEFAULT));
    guardar_ajuste('pin_inicial_pendiente', '0');
    bitacora('pin', 'PIN actualizado');
    return null;
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

function entrar(): void
{
    session_regenerate_id(true);
    $_SESSION['auth'] = true;
    // La unidad de negocio se elige en cada inicio de sesión: quien concilia
    // lleva varias y arrastrar la del día anterior invita a equivocarse.
    unset($_SESSION['sede']);
    $_SESSION['visto'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    bitacora('acceso', 'Ingreso correcto');
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
