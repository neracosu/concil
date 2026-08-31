<?php
/**
 * Usuarios, presencia y rastro.
 *
 * Todos pueden hacer lo mismo dentro de la aplicación. La única diferencia es
 * el **maestro**, que además da de alta a los demás. No hay permisos por
 * pantalla: lo que se quiere no es limitar, es saber quién hizo qué.
 *
 * Se entra solo con seis dígitos, así que el PIN identifica a la persona. Para
 * no tener que probar el PIN contra cada usuario en cada intento, se guarda
 * además una huella HMAC del PIN con una sal del propio sistema, que sirve para
 * localizar la fila de un salto. La comprobación real sigue siendo el hash
 * lento de `password_verify`.
 */

/** Sal para la huella de búsqueda. Se genera sola la primera vez. */
function sal_pin(): string
{
    $s = ajuste('pin_sal');
    if ($s === null || $s === '') {
        $s = bin2hex(random_bytes(32));
        guardar_ajuste('pin_sal', $s);
    }
    return $s;
}

/** Huella con la que se localiza al usuario sin recorrerlos todos. */
function huella_pin(string $pin): string
{
    return hash_hmac('sha256', $pin, sal_pin());
}

function usuarios(): array
{
    return db()->query('SELECT * FROM usuarios ORDER BY maestro DESC, nombre')->fetchAll();
}

function usuario(int $id): ?array
{
    $s = db()->prepare('SELECT * FROM usuarios WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** El usuario de esta sesión, o null. */
function usuario_actual(): ?array
{
    static $cache = null;
    $id = (int) ($_SESSION['uid'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    return $cache ??= usuario($id);
}

function es_maestro(): bool
{
    return (int) (usuario_actual()['maestro'] ?? 0) === 1;
}

function nombre_usuario(): string
{
    return (string) (usuario_actual()['nombre'] ?? '');
}

/** Busca al usuario que corresponde a ese PIN, si está activo. */
function usuario_por_pin(string $pin): ?array
{
    if (!preg_match('/^\d{6}$/', $pin)) {
        return null;
    }
    $s = db()->prepare('SELECT * FROM usuarios WHERE pin_busqueda = ? AND activo = 1');
    $s->execute([huella_pin($pin)]);
    $u = $s->fetch();
    if ($u !== false && password_verify($pin, (string) $u['pin_hash'])) {
        return $u;
    }

    // Los usuarios creados antes de existir la huella —el maestro que salió del
    // PIN compartido— no la tienen, así que hay que probarlos uno a uno. En
    // cuanto entran, se les calcula y ya no vuelven por aquí.
    foreach (db()->query("SELECT * FROM usuarios WHERE activo = 1 AND pin_busqueda = '" . str_repeat('0', 64) . "'") as $v) {
        if (password_verify($pin, (string) $v['pin_hash'])) {
            db()->prepare('UPDATE usuarios SET pin_busqueda = ? WHERE id = ?')
                ->execute([huella_pin($pin), $v['id']]);
            return $v;
        }
    }
    return null;
}

/** Reglas de un PIN aceptable. Devuelve el motivo del rechazo, o null. */
function pin_valido(string $pin): ?string
{
    if (!preg_match('/^\d{6}$/', $pin)) {
        return 'El PIN debe tener exactamente 6 dígitos.';
    }
    if (preg_match('/^(\d)\1{5}$/', $pin)) {
        return 'No uses un PIN con los 6 dígitos iguales.';
    }
    if (in_array($pin, ['123456', '654321', '012345', '111111', '000000'], true)) {
        return 'Ese PIN es demasiado predecible.';
    }
    return null;
}

/**
 * Crea un usuario. Devuelve el motivo del fallo, o null si salió bien.
 * Dos personas no pueden compartir PIN: si se entra solo con seis dígitos,
 * un PIN repetido haría imposible saber quién es quién.
 */
function crear_usuario(string $nombre, string $pin): ?string
{
    $nombre = mb_substr(limpiar($nombre), 0, 120);
    if ($nombre === '') {
        return 'Escribe el nombre de la persona.';
    }
    if (($err = pin_valido($pin)) !== null) {
        return $err;
    }
    if (pin_ocupado($pin)) {
        return 'Ese PIN ya lo usa otra persona. Elige otro.';
    }
    db()->prepare('INSERT INTO usuarios (nombre, pin_hash, pin_busqueda) VALUES (?, ?, ?)')
        ->execute([$nombre, password_hash($pin, PASSWORD_DEFAULT), huella_pin($pin)]);
    bitacora('usuario_creado', $nombre);
    return null;
}

function pin_ocupado(string $pin, int $salvo = 0): bool
{
    $s = db()->prepare('SELECT COUNT(*) FROM usuarios WHERE pin_busqueda = ? AND id <> ?');
    $s->execute([huella_pin($pin), $salvo]);
    return (int) $s->fetchColumn() > 0;
}

/** Cambia el PIN de un usuario. */
function cambiar_pin_usuario(int $id, string $pin): ?string
{
    if (($err = pin_valido($pin)) !== null) {
        return $err;
    }
    if (pin_ocupado($pin, $id)) {
        return 'Ese PIN ya lo usa otra persona. Elige otro.';
    }
    db()->prepare('UPDATE usuarios SET pin_hash = ?, pin_busqueda = ? WHERE id = ?')
        ->execute([password_hash($pin, PASSWORD_DEFAULT), huella_pin($pin), $id]);
    guardar_ajuste('pin_inicial_pendiente', '0');
    bitacora('pin_cambiado', 'de ' . (usuario($id)['nombre'] ?? ''));
    return null;
}

function renombrar_usuario(int $id, string $nombre): ?string
{
    $nombre = mb_substr(limpiar($nombre), 0, 120);
    if ($nombre === '') {
        return 'El nombre no puede quedar vacío.';
    }
    db()->prepare('UPDATE usuarios SET nombre = ? WHERE id = ?')->execute([$nombre, $id]);
    return null;
}

/**
 * Da de baja o de alta a alguien. Nunca al último maestro que quede activo:
 * dejaría el sistema sin nadie que pueda crear usuarios.
 */
function activar_usuario(int $id, bool $activo): ?string
{
    $u = usuario($id);
    if ($u === null) {
        return 'Ese usuario no existe.';
    }
    if (!$activo && (int) $u['maestro'] === 1) {
        $otros = (int) db()->query('SELECT COUNT(*) FROM usuarios WHERE maestro = 1 AND activo = 1 AND id <> ' . $id)
                           ->fetchColumn();
        if ($otros === 0) {
            return 'No puedes desactivar al único maestro: nadie podría dar de alta a los demás.';
        }
    }
    db()->prepare('UPDATE usuarios SET activo = ? WHERE id = ?')->execute([$activo ? 1 : 0, $id]);
    bitacora($activo ? 'usuario_activado' : 'usuario_desactivado', (string) $u['nombre']);
    return null;
}

/* ------------------------------------------------------------------ */
/* Presencia: quién está dentro y en qué está trabajando ahora mismo.  */
/* ------------------------------------------------------------------ */

/** Nombre llano de cada pantalla, para que el seguimiento se lea sin traducir. */
function nombre_pantalla(string $ruta): string
{
    return [
        'panel'       => 'el panel',
        'carga'       => 'cargando extractos',
        'pendientes'  => 'justificando pagos',
        'movimientos' => 'consultando movimientos',
        'movimiento'  => 'revisando un movimiento',
        'reportes'    => 'viendo reportes',
        'reglas'      => 'las reglas',
        'categorias'  => 'las categorías',
        'cuentas'     => 'las cuentas',
        'sede'        => 'eligiendo unidad',
        'ajustes'     => 'los ajustes',
        'usuarios'    => 'los usuarios',
        'perfil'      => 'su perfil',
    ][$ruta] ?? $ruta;
}

/** Deja constancia de dónde está quien navega. Una sola escritura por página. */
function marcar_presencia(string $ruta): void
{
    $id = (int) ($_SESSION['uid'] ?? 0);
    if ($id <= 0) {
        return;
    }
    db()->prepare('UPDATE usuarios SET visto_en = NOW(), pantalla = ? WHERE id = ?')
        ->execute([mb_substr($ruta, 0, 40), $id]);
}

/** Quién ha dado señales de vida en los últimos minutos, y dónde. */
function usuarios_activos(int $minutos = 10): array
{
    // Los minutos se calculan en la base y no en PHP: los dos relojes no van
    // en la misma zona horaria, y restarlos daba «visto hace 420 minutos».
    $s = db()->prepare('SELECT id, nombre, pantalla, visto_en, maestro,
                               TIMESTAMPDIFF(MINUTE, visto_en, NOW()) hace
                          FROM usuarios
                         WHERE visto_en IS NOT NULL
                           AND visto_en > DATE_SUB(NOW(), INTERVAL ? MINUTE)
                      ORDER BY visto_en DESC');
    $s->execute([$minutos]);
    return $s->fetchAll();
}

/** Lo último que hizo cada quien, sacado de la bitácora. */
function ultimo_rastro(int $limite = 12): array
{
    return db()->query("SELECT b.accion, b.detalle, b.creado_en, b.ip,
                               COALESCE(u.nombre, '—') usuario
                          FROM bitacora b
                     LEFT JOIN usuarios u ON u.id = b.usuario_id
                      ORDER BY b.id DESC LIMIT $limite")->fetchAll();
}
