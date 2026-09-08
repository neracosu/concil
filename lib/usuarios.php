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

/**
 * Claro, oscuro o lo que diga el equipo.
 *
 * Se guarda en la ficha de la persona para que la lleve a cualquier
 * computadora, y además en una galleta: la pantalla de acceso se dibuja antes
 * de saber quién entra, y llegar a un fogonazo blanco de madrugada molesta.
 */
function tema(): string
{
    $u = usuario_actual();
    $t = (string) ($u['tema'] ?? ($_COOKIE['CONCILTEMA'] ?? ''));
    return in_array($t, ['claro', 'oscuro'], true) ? $t : '';
}

/** Deja anotada la preferencia en la ficha y en la galleta. */
function fijar_tema(string $t): void
{
    $t = in_array($t, ['claro', 'oscuro'], true) ? $t : '';
    $id = usuario_id_actual();
    if ($id !== null) {
        db()->prepare('UPDATE usuarios SET tema = ? WHERE id = ?')->execute([$t, $id]);
    }
    setcookie('CONCILTEMA', $t, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * El tamaño de letra que eligió esta persona. Vacío quiere decir el normal.
 * Como toda la hoja de estilos va en rem, esto mueve también los botones y los
 * renglones del menú, no solo el texto.
 */
function escala(): string
{
    $u = usuario_actual();
    $e = (string) ($u['escala'] ?? ($_COOKIE['CONCILESCALA'] ?? ''));
    return in_array($e, ['grande', 'enorme'], true) ? $e : '';
}

/** Deja anotado el tamaño en la ficha y en la galleta, como el modo claro. */
function fijar_escala(string $e): void
{
    $e = in_array($e, ['grande', 'enorme'], true) ? $e : '';
    $id = usuario_id_actual();
    if ($id !== null) {
        db()->prepare('UPDATE usuarios SET escala = ? WHERE id = ?')->execute([$e, $id]);
    }
    setcookie('CONCILESCALA', $e, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** El id de quien está trabajando, para dejarlo anotado en lo que toque. */
function usuario_id_actual(): ?int
{
    $id = (int) (usuario_actual()['id'] ?? 0);
    return $id > 0 ? $id : null;
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

/**
 * Qué navegador y qué sistema, en legible.
 *
 * A mano y con cuatro expresiones: meter una librería de reconocimiento por
 * esto sería traer mil reglas para leer una cadena. El orden importa —Edge y
 * Opera se anuncian además como Chrome, y Chrome como Safari—, así que se
 * mira de lo más específico a lo más general.
 */
function dispositivo_de(string $ua): string
{
    if ($ua === '') {
        return '';
    }
    $nav = 'Navegador desconocido';
    foreach ([
        'Edg/'            => 'Edge',
        'OPR/'            => 'Opera',
        'YaBrowser/'      => 'Yandex',
        'SamsungBrowser/' => 'Samsung Internet',
        'Firefox/'        => 'Firefox',
        'CriOS/'          => 'Chrome',
        'FxiOS/'          => 'Firefox',
        'Chrome/'         => 'Chrome',
        'Safari/'         => 'Safari',
        'curl/'           => 'curl',
    ] as $marca => $rotulo) {
        if (str_contains($ua, $marca)) {
            // Safari no pone su versión en «Safari/», que es el número de
            // compilación del motor: la pone en «Version/».
            $donde = $rotulo === 'Safari' ? 'Version/' : $marca;
            $nav = $rotulo;
            if (preg_match('~' . preg_quote($donde, '~') . '(\\d+)~', $ua, $m)) {
                $nav .= ' ' . $m[1];
            }
            break;
        }
    }

    $so = '';
    foreach ([
        'Windows NT 10' => 'Windows 10/11',
        'Windows NT'    => 'Windows',
        'iPhone'        => 'iPhone',
        'iPad'          => 'iPad',
        'Android'       => 'Android',
        'Mac OS X'      => 'Mac',
        'CrOS'          => 'Chromebook',
        'Linux'         => 'Linux',
    ] as $marca => $rotulo) {
        if (str_contains($ua, $marca)) {
            $so = $rotulo;
            break;
        }
    }

    return mb_substr($so === '' ? $nav : "$nav · $so", 0, 60);
}

/**
 * Nombre llano de cada pantalla, para que el seguimiento se lea sin traducir.
 *
 * Todos van en forma de lugar y no de acción —«los pagos por justificar», no
 * «justificando pagos»— porque el texto que los envuelve siempre dice «está
 * en», y así la frase queda bien dicha en las tres pantallas donde aparece.
 */
function nombre_pantalla(string $ruta): string
{
    return [
        'panel'          => 'el panel',
        'carga'          => 'la carga de extractos',
        'pendientes'     => 'los pagos por justificar',
        'movimientos'    => 'la lista de movimientos',
        'movimiento'     => 'el detalle de un movimiento',
        'repetidos'      => 'los pagos repetidos',
        'reportes'       => 'los reportes',
        'reglas'         => 'las reglas',
        'categorias'     => 'las categorías',
        'proveedores'    => 'los proveedores',
        'proveedor'      => 'la ficha de un proveedor',
        'facturas_panel' => 'las facturas',
        'cuentas'        => 'las cuentas',
        'sede'           => 'las unidades de negocio',
        'ajustes'        => 'los ajustes',
        'usuarios'       => 'los usuarios',
        'auditoria'      => 'el rastro y la auditoría',
        'mejoras'        => 'las mejoras',
        'perfil'         => 'su perfil',
        'salir'          => 'la salida',
    ][$ruta] ?? $ruta;
}

/**
 * A qué se refiere la pantalla que se está mirando: el movimiento, el
 * proveedor o la factura. Sin esto solo se sabe «está en Movimientos», y lo
 * que evita el trabajo repetido es saber que dos personas están sobre el
 * **mismo** pago.
 */
function referencia_pantalla(): int
{
    return max(0, (int) ($_GET['id'] ?? 0));
}

/**
 * Deja constancia de dónde está quien navega. Una escritura por página.
 *
 * El latido de la presencia llega por la ruta `presencia`, que no es una
 * pantalla: cuando viene de ahí se conserva la pantalla real —la manda el
 * navegador en `en`— y no se anota la visita, o el recorrido se llenaría de
 * un renglón cada veinte segundos.
 */
function marcar_presencia(string $ruta): void
{
    $id = (int) ($_SESSION['uid'] ?? 0);
    if ($id <= 0) {
        return;
    }
    $latido = $ruta === 'presencia';
    $ref    = referencia_pantalla();
    if ($latido) {
        $ruta = preg_replace('/[^a-z_]/', '', (string) ($_GET['en'] ?? '')) ?: 'panel';
        $ref  = max(0, (int) ($_GET['ref'] ?? 0));
    }
    $ruta = mb_substr($ruta, 0, 40);

    db()->prepare('UPDATE usuarios
                      SET visto_en = NOW(), pantalla = ?, pantalla_ref = ?, sede_activa = ?
                    WHERE id = ?')
        ->execute([$ruta, $ref, (int) ($_SESSION['sede'] ?? 0), $id]);

    if (!$latido) {
        anotar_visita($ruta, $ref);
    }
}

/** Al cerrar sesión: se borra la marca para que deje de salir como presente. */
function borrar_presencia(): void
{
    $id = (int) ($_SESSION['uid'] ?? 0);
    if ($id > 0) {
        db()->prepare('UPDATE usuarios SET visto_en = NULL, pantalla = \'\', pantalla_ref = 0 WHERE id = ?')
            ->execute([$id]);
    }
}

/** ¿Se está guardando el recorrido, pantalla por pantalla? Se puede apagar. */
function rastro_navegacion(): bool
{
    return ajuste('rastro_navegacion', '1') === '1';
}

/**
 * Una línea por pantalla abierta. Es lo que convierte la bitácora en un rastro
 * que se puede auditar: sin esto solo consta lo que alguien cambió, no por
 * dónde anduvo ni cuánto tiempo estuvo.
 */
function anotar_visita(string $ruta, int $ref = 0): void
{
    if (!rastro_navegacion()) {
        return;
    }
    db()->prepare('INSERT INTO visitas (usuario_id, ruta, ref, sede_id, ip, dispositivo, sesion)
                   VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            (int) $_SESSION['uid'],
            $ruta,
            $ref,
            (int) ($_SESSION['sede'] ?? 0),
            ip_cliente(),
            dispositivo_de((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
            huella_sesion(),
        ]);
}

/** Quién ha dado señales de vida en los últimos minutos, y dónde. */
function usuarios_activos(int $minutos = 10): array
{
    // Los minutos se calculan en la base y no en PHP: los dos relojes no van
    // en la misma zona horaria, y restarlos daba «visto hace 420 minutos».
    $s = db()->prepare("SELECT u.id, u.nombre, u.pantalla, u.pantalla_ref, u.visto_en, u.maestro,
                               u.sede_activa, COALESCE(s.nombre, '') sede,
                               TIMESTAMPDIFF(MINUTE, u.visto_en, NOW()) hace,
                               TIMESTAMPDIFF(SECOND, u.visto_en, NOW()) hace_segs
                          FROM usuarios u
                     LEFT JOIN sedes s ON s.id = u.sede_activa
                         WHERE u.visto_en IS NOT NULL
                           AND u.visto_en > DATE_SUB(NOW(), INTERVAL ? MINUTE)
                      ORDER BY u.visto_en DESC");
    $s->execute([$minutos]);
    return $s->fetchAll();
}

/**
 * Quién está trabajando ahora mismo, para los avisos en vivo.
 *
 * Cuatro minutos: el navegador avisa cada veinte segundos mientras la pestaña
 * está abierta y alguien la está usando, así que quien siga ahí no se cae de
 * la lista, y quien se levantó de la silla desaparece solo.
 */
function presencia_viva(bool $incluirme = false): array
{
    $yo = (int) ($_SESSION['uid'] ?? 0);
    $gente = [];
    foreach (usuarios_activos(4) as $a) {
        if (!$incluirme && (int) $a['id'] === $yo) {
            continue;
        }
        $gente[] = $a;
    }
    return $gente;
}

/** Cuánta gente hay en cada pantalla, para el ojito del menú. */
function presencia_por_ruta(array $gente): array
{
    $mapa = [];
    foreach ($gente as $g) {
        $r = (string) $g['pantalla'];
        $mapa[$r][] = (string) $g['nombre'];
    }
    return $mapa;
}

/** Lo último que hizo cada quien, sacado de la bitácora. */
function ultimo_rastro(int $limite = 12): array
{
    return db()->query("SELECT b.accion, b.detalle, b.creado_en, b.ip, b.dispositivo, b.sesion,
                               COALESCE(u.nombre, '—') usuario
                          FROM bitacora b
                     LEFT JOIN usuarios u ON u.id = b.usuario_id
                      ORDER BY b.id DESC LIMIT $limite")->fetchAll();
}

/* ------------------------------------------------------------------ */
/* Auditoría: el rastro completo, con filtros.                         */
/* ------------------------------------------------------------------ */

/**
 * Acciones y pantallas mezcladas en una sola línea de tiempo.
 *
 * Van en dos tablas pero se leen juntas, que es como se reconstruye lo que
 * pasó: «entró, abrió el panel, abrió este movimiento, lo corrigió, salió».
 * Siempre con un rango de fechas encima: sin él, la consulta acabaría
 * ordenando el registro entero para enseñar cuarenta renglones.
 */
function rastro_filtrado(array $f, int $pagina = 1, int $porPagina = 60): array
{
    // Al pedir una visita concreta se quitan las fechas: la huella de la sesión
    // ya es un filtro estrecho y va por su propio índice. Si no, «ver esta
    // visita» de algo de hace un mes no enseñaría nada, que era justo lo que
    // uno viene a buscar.
    $cond = [];
    $arg  = [];
    if (!empty($f['sesion'])) {
        $cond[] = 'b.sesion = ?';
        $arg[]  = (string) $f['sesion'];
    } else {
        $desde = $f['desde'] ?: date('Y-m-d', strtotime('-7 days'));
        $hasta = $f['hasta'] ?: date('Y-m-d');
        $cond  = ['b.creado_en >= ?', 'b.creado_en < ?'];
        $arg   = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];
    }

    if (!empty($f['usuario'])) {
        $cond[] = 'b.usuario_id = ?';
        $arg[]  = (int) $f['usuario'];
    }
    if (!empty($f['ip'])) {
        $cond[] = 'b.ip = ?';
        $arg[]  = (string) $f['ip'];
    }
    $where = implode(' AND ', $cond);

    $partes = [];
    // Las acciones. `orden` es lo que permite ordenar las dos mitades juntas
    // sin que la base tenga que mirar el texto de la fecha.
    if ($f['que'] !== 'pantallas') {
        $partes[] = "SELECT 'accion' clase, b.creado_en, b.usuario_id, b.accion, b.detalle,
                            b.ip, b.dispositivo, b.agente, b.ruta, b.sesion, b.sede_id
                       FROM bitacora b WHERE $where";
    }
    // El recorrido. Se disfraza de acción «pantalla» para poder unirlas.
    if ($f['que'] !== 'acciones') {
        $partes[] = "SELECT 'pantalla' clase, b.creado_en, b.usuario_id, 'pantalla' accion,
                            b.ruta detalle, b.ip, b.dispositivo, '' agente, b.ruta, b.sesion, b.sede_id
                       FROM visitas b WHERE $where";
    }
    if ($partes === []) {
        return ['filas' => [], 'total' => 0, 'paginas' => 1, 'pagina' => 1];
    }

    $total = 0;
    foreach ($partes as $p) {
        $c = db()->prepare('SELECT COUNT(*) FROM (' . $p . ') x');
        $c->execute($arg);
        $total += (int) $c->fetchColumn();
    }

    $paginas = max(1, (int) ceil($total / $porPagina));
    $pagina  = max(1, min($pagina, $paginas));
    $salto   = ($pagina - 1) * $porPagina;

    $sql = '(' . implode(') UNION ALL (', $partes) . ')
            ORDER BY creado_en DESC, clase LIMIT ' . $porPagina . ' OFFSET ' . $salto;
    $s = db()->prepare($sql);
    $s->execute(count($partes) === 2 ? array_merge($arg, $arg) : $arg);
    $filas = $s->fetchAll();

    // Los nombres se pegan aparte: unir usuarios dentro de cada mitad del
    // UNION obliga a la base a ordenar la unión entera con las dos tablas
    // encima, y son cuatro nombres que caben en memoria.
    $nombres = [];
    foreach (usuarios() as $u) {
        $nombres[(int) $u['id']] = (string) $u['nombre'];
    }
    $sedes = [];
    foreach (sedes() as $sd) {
        $sedes[(int) $sd['id']] = (string) $sd['nombre'];
    }
    foreach ($filas as &$fila) {
        $fila['usuario'] = $nombres[(int) $fila['usuario_id']] ?? '—';
        $fila['sede']    = $sedes[(int) $fila['sede_id']] ?? '';
    }
    unset($fila);

    return ['filas' => $filas, 'total' => $total, 'paginas' => $paginas, 'pagina' => $pagina];
}

/** Las visitas de una sesión, para reconstruirla de principio a fin. */
function resumen_sesion(string $sesion): array
{
    $s = db()->prepare("SELECT MIN(creado_en) inicio, MAX(creado_en) fin, COUNT(*) pantallas,
                               MAX(ip) ip, MAX(dispositivo) dispositivo, MAX(usuario_id) usuario_id
                          FROM visitas WHERE sesion = ?");
    $s->execute([$sesion]);
    return $s->fetch() ?: [];
}

/** Borra lo más viejo de las dos tablas. Devuelve cuántas líneas se fueron. */
function purgar_rastro(int $dias): array
{
    $a = db()->prepare('DELETE FROM bitacora WHERE creado_en < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $a->execute([$dias]);
    $b = db()->prepare('DELETE FROM visitas WHERE creado_en < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $b->execute([$dias]);
    return ['acciones' => $a->rowCount(), 'pantallas' => $b->rowCount()];
}
