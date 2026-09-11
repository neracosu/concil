<?php
/** Conexión MySQL/MariaDB y esquema. */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $s = secretos();
    $pdo = new PDO(
        "mysql:host={$s['db_host']};dbname={$s['db_name']};charset=utf8mb4",
        $s['db_user'],
        $s['db_pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    // La base habla en la misma hora que PHP. Sin esto, NOW() y date() daban
    // horas distintas y cualquier resta entre ambas salía mal.
    $pdo->exec("SET time_zone = '" . ZONA_OFFSET . "'");
    return $pdo;
}

/** Crea el esquema si no existe. Idempotente. */
/**
 * Número del esquema. **Súbelo cada vez que añadas una columna o un índice
 * aquí**, o la migración no llegará a correr en el servidor: se salta cuando la
 * base ya dice tener esta versión.
 */
const ESQUEMA_VERSION = 9;

function migrar(): void
{
    $pdo = db();
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    // Sigue siendo idempotente; lo que cambia es que además es barata. Las 55
    // comprobaciones contra information_schema costaban 80 ms en **cada**
    // petición —medido el 08/09/2026— y crecen con cada columna que se añada.
    // El try es por la instalación nueva, donde la tabla ajustes todavía no
    // existe y la consulta revienta: ahí hay que migrar de todas formas.
    try {
        if ((int) ajuste('esquema', '0') === ESQUEMA_VERSION) {
            return;
        }
    } catch (Throwable) {
        // base recién creada: seguir y crearlo todo
    }

    // Unidades de negocio del consorcio. Cada cuenta pertenece a una.
    $pdo->exec("CREATE TABLE IF NOT EXISTS sedes (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        nombre    VARCHAR(120) NOT NULL,
        activa    TINYINT(1)   NOT NULL DEFAULT 1,
        creado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sede_nombre (nombre)
    ) $t");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cuentas (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        nombre    VARCHAR(120) NOT NULL,
        banco     VARCHAR(120) NOT NULL DEFAULT '',
        numero    VARCHAR(60)  NOT NULL DEFAULT '',
        activa    TINYINT(1)   NOT NULL DEFAULT 1,
        creado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cuenta_nombre (nombre)
    ) $t");

    // Saldo de arranque, para las cuentas cuyo extracto no trae columna de saldo.
    columna_si_falta($pdo, 'cuentas', 'saldo_inicial', 'DECIMAL(18,2) NOT NULL DEFAULT 0');
    columna_si_falta($pdo, 'cuentas', 'saldo_fecha', 'DATE NULL');
    columna_si_falta($pdo, 'cuentas', 'sede_id', 'INT NOT NULL DEFAULT 0');
    // Identifican la cuenta más allá del nombre, que el banco escribe distinto
    // en cada archivo. Sin los tres no se admiten cargas.
    columna_si_falta($pdo, 'cuentas', 'titular', "VARCHAR(160) NOT NULL DEFAULT ''");
    columna_si_falta($pdo, 'cuentas', 'rif', "VARCHAR(20) NOT NULL DEFAULT ''");

    // Todo lo cargado antes de existir las sedes es de ARMOR MARKET: se crea
    // esa sede y se le adjudican las cuentas huérfanas. Es idempotente porque
    // solo toca las que aún tienen sede_id = 0.
    if ((int) $pdo->query('SELECT COUNT(*) FROM cuentas WHERE sede_id = 0')->fetchColumn() > 0) {
        $pdo->exec("INSERT IGNORE INTO sedes (nombre) VALUES ('ARMOR MARKET')");
        // La de ARMOR MARKET por nombre, no la de menor id: si ya existían
        // otras unidades, INSERT IGNORE le da un id mayor y las cuentas
        // huérfanas acabarían dentro de una unidad que no es la suya.
        $destino = (int) $pdo->query("SELECT id FROM sedes WHERE nombre = 'ARMOR MARKET'")->fetchColumn();
        $pdo->prepare('UPDATE cuentas SET sede_id = ? WHERE sede_id = 0')->execute([$destino]);
    }

    // El nombre de cuenta era único en toda la base; ahora solo dentro de su
    // sede, porque dos unidades de negocio pueden tener cada una su «BANESCO».
    if (indice_existe($pdo, 'cuentas', 'uq_cuenta_nombre')) {
        $pdo->exec('ALTER TABLE cuentas DROP INDEX uq_cuenta_nombre,
                                        ADD UNIQUE KEY uq_cuenta_sede (sede_id, nombre)');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS categorias (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        nombre    VARCHAR(120) NOT NULL,
        grupo     VARCHAR(60)  NOT NULL DEFAULT 'General',
        color     VARCHAR(9)   NOT NULL DEFAULT '#d4a857',
        fija      TINYINT(1)   NOT NULL DEFAULT 0,
        creado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cat_nombre (nombre)
    ) $t");

    // Categorías anidadas: contabilidad desglosa las comisiones en doce
    // conceptos y quiere verlos por separado sin perder el total. El padre
    // sigue siendo una categoría normal —se le pueden asignar movimientos—,
    // así que lo ya clasificado no se mueve de sitio.
    columna_si_falta($pdo, 'categorias', 'padre_id', 'INT NULL');
    if (!indice_existe($pdo, 'categorias', 'idx_cat_padre')) {
        $pdo->exec('ALTER TABLE categorias ADD KEY idx_cat_padre (padre_id)');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS reglas (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        nombre       VARCHAR(160) NOT NULL,
        campo        VARCHAR(20)  NOT NULL DEFAULT 'concepto',
        tipo         VARCHAR(20)  NOT NULL DEFAULT 'contiene',
        patron       VARCHAR(255) NOT NULL,
        cuenta_id    INT NULL,
        categoria_id INT NOT NULL,
        beneficiario VARCHAR(160) NOT NULL DEFAULT '',
        prioridad    INT NOT NULL DEFAULT 100,
        activa       TINYINT(1) NOT NULL DEFAULT 1,
        aciertos     INT NOT NULL DEFAULT 0,
        creado_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_regla_orden (activa, prioridad),
        CONSTRAINT fk_regla_cuenta FOREIGN KEY (cuenta_id)    REFERENCES cuentas(id)    ON DELETE SET NULL,
        CONSTRAINT fk_regla_cat    FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE CASCADE
    ) $t");

    $pdo->exec("CREATE TABLE IF NOT EXISTS importaciones (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        archivo    VARCHAR(255) NOT NULL,
        cuenta_id  INT NULL,
        formato    VARCHAR(60)  NOT NULL DEFAULT '',
        filas      INT NOT NULL DEFAULT 0,
        insertados INT NOT NULL DEFAULT 0,
        duplicados INT NOT NULL DEFAULT 0,
        auto_map   INT NOT NULL DEFAULT 0,
        creado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_imp_cuenta FOREIGN KEY (cuenta_id) REFERENCES cuentas(id) ON DELETE SET NULL
    ) $t");

    // Lo que suma el archivo según nosotros, y en qué se diferenciaba del
    // resumen que el banco imprime al pie. Se guarda porque el pie del banco
    // falla a veces y conviene poder mirar atrás qué se leyó ese día.
    columna_si_falta($pdo, 'importaciones', 'suma_debito',  'DECIMAL(18,2) NOT NULL DEFAULT 0');
    columna_si_falta($pdo, 'importaciones', 'suma_credito', 'DECIMAL(18,2) NOT NULL DEFAULT 0');
    columna_si_falta($pdo, 'importaciones', 'descuadre',    "VARCHAR(255) NOT NULL DEFAULT ''");

    $pdo->exec("CREATE TABLE IF NOT EXISTS movimientos (
        id             BIGINT AUTO_INCREMENT PRIMARY KEY,
        cuenta_id      INT NOT NULL,
        importacion_id INT NULL,
        fecha          DATE NOT NULL,
        referencia     VARCHAR(80)  NOT NULL DEFAULT '',
        concepto       VARCHAR(400) NOT NULL DEFAULT '',
        nota_banco     VARCHAR(255) NOT NULL DEFAULT '',
        debito         DECIMAL(18,2) NOT NULL DEFAULT 0,
        credito        DECIMAL(18,2) NOT NULL DEFAULT 0,
        saldo          DECIMAL(18,2) NULL,
        tipo           CHAR(1) NOT NULL,
        categoria_id   INT NULL,
        beneficiario   VARCHAR(160) NOT NULL DEFAULT '',
        justificacion  TEXT NULL,
        estado         VARCHAR(12) NOT NULL DEFAULT 'pendiente',
        origen         VARCHAR(12) NOT NULL DEFAULT '',
        regla_id       INT NULL,
        firma          CHAR(40) NOT NULL,
        ocurrencia     INT NOT NULL DEFAULT 1,
        creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en DATETIME NULL,
        UNIQUE KEY uq_mov (firma, ocurrencia),
        KEY idx_mov_fecha  (fecha),
        KEY idx_mov_cuenta (cuenta_id, fecha),
        KEY idx_mov_cat    (categoria_id),
        CONSTRAINT fk_mov_cuenta FOREIGN KEY (cuenta_id)      REFERENCES cuentas(id)       ON DELETE CASCADE,
        CONSTRAINT fk_mov_imp    FOREIGN KEY (importacion_id) REFERENCES importaciones(id) ON DELETE SET NULL,
        CONSTRAINT fk_mov_cat    FOREIGN KEY (categoria_id)   REFERENCES categorias(id)    ON DELETE SET NULL,
        CONSTRAINT fk_mov_regla  FOREIGN KEY (regla_id)       REFERENCES reglas(id)        ON DELETE SET NULL
    ) $t");

    // A quién se le pagó, ya como registro y no como texto suelto. El campo
    // beneficiario se queda: lo llenan las reglas con etiquetas gruesas y sirve
    // para otra cosa.
    columna_si_falta($pdo, 'movimientos', 'proveedor_id', 'INT NULL');

    // Quién dejó puesta la clasificación. Va en el movimiento y no solo en la
    // bitácora porque auditoría lo pregunta fila por fila. Solo se llena hacia
    // adelante: lo clasificado antes de que existiera la columna no se puede
    // reconstruir, y se queda en blanco antes que inventar un nombre.
    columna_si_falta($pdo, 'movimientos', 'usuario_id', 'INT NULL');

    // El otro lado de un traspaso entre cuentas propias: el crédito que entró
    // en la otra cuenta. Se apunta en las dos filas, cada una a la otra, para
    // que desde cualquiera de las dos se llegue a su pareja sin buscarla.
    columna_si_falta($pdo, 'movimientos', 'traspaso_id', 'INT NULL');
    if (!indice_existe($pdo, 'movimientos', 'idx_mov_traspaso')) {
        $pdo->exec('ALTER TABLE movimientos ADD KEY idx_mov_traspaso (traspaso_id)');
    }
    if (!indice_existe($pdo, 'movimientos', 'idx_mov_prov')) {
        $pdo->exec('ALTER TABLE movimientos ADD KEY idx_mov_prov (proveedor_id)');
    }

    // Operaciones que parecen la misma corrida de fecha. Bicentenario y el
    // Tesoro mueven al mes siguiente operaciones de los últimos días, y como la
    // fecha entra en la firma, la misma operación entra dos veces sin que el
    // control de duplicados la vea. Se marcan y se revisan a mano: el equipo
    // pidió que entren todas y se señalen, no que se queden fuera.
    columna_si_falta($pdo, 'movimientos', 'posible_repetido', 'TINYINT(1) NOT NULL DEFAULT 0');
    columna_si_falta($pdo, 'movimientos', 'repetido_de',      'BIGINT NULL');
    if (!indice_existe($pdo, 'movimientos', 'idx_mov_repetido')) {
        $pdo->exec('ALTER TABLE movimientos ADD KEY idx_mov_repetido (cuenta_id, posible_repetido)');
    }
    // Los dos caminos por los que se busca la pareja de una operación.
    if (!indice_existe($pdo, 'movimientos', 'idx_mov_ref')) {
        $pdo->exec('ALTER TABLE movimientos ADD KEY idx_mov_ref (cuenta_id, referencia)');
    }
    if (!indice_existe($pdo, 'movimientos', 'idx_mov_concepto')) {
        $pdo->exec('ALTER TABLE movimientos ADD KEY idx_mov_concepto (cuenta_id, concepto(60))');
    }

    // Índices pensados para cuando la tabla sea grande. Medidos el 08/09/2026
    // sobre 500.000 movimientos sintéticos, que son unos cinco años al ritmo
    // actual: sin ellos el panel tardaba 18 segundos.
    //
    // El contador de pendientes y la búsqueda del último mes con datos recorrían
    // los dos la tabla entera. Este índice sirve a ambos: cuenta_id va primero
    // porque el filtro por sede es lo primero que se aplica siempre, y fecha
    // antes que categoria_id para que MIN/MAX salgan del propio índice.
    if (!indice_existe($pdo, 'movimientos', 'idx_mov_ctf')) {
        $pdo->exec('ALTER TABLE movimientos ADD KEY idx_mov_ctf (cuenta_id, tipo, fecha, categoria_id)');
    }
    // Este DROP se queda aunque el CREATE de arriba ya no lo cree: las bases
    // que existían antes del 08/09/2026 sí lo tienen.
    // idx_mov_estado (tipo, estado) no lo usa ninguna consulta: la columna estado
    // se escribe pero nunca se lee —el filtro «pendiente/conciliado» de la
    // interfaz mira categoria_id, no esta columna—. Y además hacía daño: el
    // optimizador lo prefería para «tipo='D' AND cuenta_id IN (...)», y como no
    // lleva cuenta_id, terminaba leyendo 251.000 filas una a una. Quitándolo,
    // esas consultas pasan a resolverse dentro de idx_mov_ctf.
    if (indice_existe($pdo, 'movimientos', 'idx_mov_estado')) {
        $pdo->exec('ALTER TABLE movimientos DROP INDEX idx_mov_estado');
    }

    // Y este va aparte, no fundido con el anterior. Se probó a juntarlos y el
    // contador de pendientes pasó de 6 ms a 479: con fecha en medio, MySQL deja
    // de poder saltar directamente a las filas sin categoría y recorre todos
    // los débitos de la cuenta. Dos índices con prefijo parecido cuestan poco
    // al insertar; esa diferencia se paga en cada pantalla.
    // Tres columnas, no cuatro. Se probó a añadirle debito para que el resumen
    // de Movimientos saliera entero del índice: el reparto por categoría bajó de
    // 617 a 345 ms, pero resumen() subió de 373 a 1.449 y la pantalla salió
    // perdiendo. Medido el 08/09/2026 sobre 500.000 movimientos.
    if (!indice_existe($pdo, 'movimientos', 'idx_mov_pend')) {
        $pdo->exec('ALTER TABLE movimientos ADD KEY idx_mov_pend (cuenta_id, tipo, categoria_id)');
    }
    // Los saldos: el último saldo informado por el banco y la suma de la
    // cuenta salen los dos de aquí sin tocar una sola fila de datos.
    if (!indice_existe($pdo, 'movimientos', 'idx_mov_saldos')) {
        $pdo->exec('ALTER TABLE movimientos ADD KEY idx_mov_saldos (cuenta_id, fecha, debito, credito, saldo)');
    }
    // El aviso de montos repetidos agrupa por proveedor y monto.
    if (!indice_existe($pdo, 'movimientos', 'idx_mov_prov_monto')) {
        $pdo->exec('ALTER TABLE movimientos ADD KEY idx_mov_prov_monto (proveedor_id, debito)');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS bitacora (
        id        BIGINT AUTO_INCREMENT PRIMARY KEY,
        accion    VARCHAR(60) NOT NULL,
        detalle   VARCHAR(500) NOT NULL DEFAULT '',
        ip        VARCHAR(45) NOT NULL DEFAULT '',
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_bit_fecha (creado_en)
    ) $t");

    // Catálogo de formatos de banco reconocidos por su estructura. Se llena
    // solo: cada importación confirmada deja aquí su huella, así que el mes
    // siguiente ese mismo formato ya no hay que deducirlo.
    $pdo->exec("CREATE TABLE IF NOT EXISTS formatos (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        clave     CHAR(32)     NOT NULL,
        banco     VARCHAR(120) NOT NULL DEFAULT '',
        fila_cab  SMALLINT     NOT NULL DEFAULT 0,
        mapa      TEXT         NOT NULL,
        rotulos   VARCHAR(500) NOT NULL DEFAULT '',
        forma     VARCHAR(64)  NOT NULL DEFAULT '',
        ancho     SMALLINT     NOT NULL DEFAULT 0,
        veces     INT          NOT NULL DEFAULT 0,
        creado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        visto_en  DATETIME     NULL,
        UNIQUE KEY uq_formato (clave)
    ) $t");

    // Tasa oficial del BCV, una por día de calendario. Va aparte de los
    // movimientos porque es un dato del día, no del movimiento: la misma tasa
    // sirve para todas las operaciones de esa fecha y para todas las sedes.
    // Se guarda con ocho decimales, que es como la publica la fuente.
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasas (
        fecha     DATE           NOT NULL PRIMARY KEY,
        tasa      DECIMAL(18,8)  NOT NULL,
        origen    VARCHAR(10)    NOT NULL DEFAULT 'bcv',
        creado_en DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $t");

    // Quién corrigió la tasa a mano. Una tasa escrita por una persona es una
    // afirmación, no un dato descargado, y hay que poder preguntarle a alguien.
    columna_si_falta($pdo, 'tasas', 'usuario_id', 'INT NULL');

    // Proveedores y facturas. El proveedor cuelga del movimiento porque todo
    // pago tiene destinatario; las facturas van aparte para que quepan los
    // casos reales: una factura pagada en partes, o un pago que cubre varias.
    $pdo->exec("CREATE TABLE IF NOT EXISTS proveedores (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        nombre    VARCHAR(160) NOT NULL,
        clave     VARCHAR(160) NOT NULL,
        rif       VARCHAR(20)  NOT NULL DEFAULT '',
        nota      VARCHAR(255) NOT NULL DEFAULT '',
        creado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_proveedor (clave)
    ) $t");

    // numero es el de la factura; numero_control, el pre-impreso que exige la
    // Providencia 00071 del SENIAT y que nunca se reinicia. Se pide después.
    $pdo->exec("CREATE TABLE IF NOT EXISTS facturas (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        proveedor_id   INT          NOT NULL,
        numero         VARCHAR(60)  NOT NULL,
        numero_control VARCHAR(40)  NOT NULL DEFAULT '',
        fecha          DATE         NULL,
        monto          DECIMAL(18,2) NOT NULL DEFAULT 0,
        creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_factura (proveedor_id, numero),
        CONSTRAINT fk_factura_prov FOREIGN KEY (proveedor_id)
            REFERENCES proveedores (id) ON DELETE CASCADE
    ) $t");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pagos_factura (
        id            BIGINT AUTO_INCREMENT PRIMARY KEY,
        factura_id    INT    NOT NULL,
        movimiento_id BIGINT NOT NULL,
        monto         DECIMAL(18,2) NOT NULL DEFAULT 0,
        creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pago (factura_id, movimiento_id),
        KEY idx_pago_mov (movimiento_id),
        CONSTRAINT fk_pago_factura FOREIGN KEY (factura_id)
            REFERENCES facturas (id) ON DELETE CASCADE,
        CONSTRAINT fk_pago_mov FOREIGN KEY (movimiento_id)
            REFERENCES movimientos (id) ON DELETE CASCADE
    ) $t");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ajustes (
        clave VARCHAR(60) PRIMARY KEY,
        valor TEXT NOT NULL
    ) $t");

    // Quién entra y qué hace cada uno. Todos pueden hacer todo; el maestro es
    // el único que además da de alta a los demás.
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        nombre        VARCHAR(120) NOT NULL,
        pin_hash      VARCHAR(255) NOT NULL,
        pin_busqueda  CHAR(64)     NOT NULL,
        maestro       TINYINT(1)   NOT NULL DEFAULT 0,
        activo        TINYINT(1)   NOT NULL DEFAULT 1,
        creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ultimo_acceso DATETIME     NULL,
        UNIQUE KEY uq_usuario_pin (pin_busqueda)
    ) $t");

    // Dónde está cada quien ahora mismo, para el seguimiento en vivo.
    columna_si_falta($pdo, 'usuarios', 'visto_en', 'DATETIME NULL');
    columna_si_falta($pdo, 'usuarios', 'pantalla', "VARCHAR(40) NOT NULL DEFAULT ''");
    // Claro u oscuro. Vacío quiere decir «como esté el equipo».
    columna_si_falta($pdo, 'usuarios', 'tema',     "VARCHAR(6) NOT NULL DEFAULT ''");
    // Tamaño de la letra. El equipo pidió poder agrandarla: hay quien trabaja
    // aquí todo el día y a quien 15px le obliga a acercarse a la pantalla.
    columna_si_falta($pdo, 'usuarios', 'escala',   "VARCHAR(8) NOT NULL DEFAULT ''");

    // La bitácora pasa a registrar también el autor, no solo la acción.
    columna_si_falta($pdo, 'bitacora', 'usuario_id', 'INT NULL');
    if (!indice_existe($pdo, 'bitacora', 'idx_bit_usuario')) {
        $pdo->exec('ALTER TABLE bitacora ADD KEY idx_bit_usuario (usuario_id)');
    }

    // «Ver esta visita» busca por huella de sesión y **sin** rango de fechas, así
    // que sin este índice recorría la bitácora entera, dos veces por página.
    if (!indice_existe($pdo, 'bitacora', 'idx_bit_sesion')) {
        $pdo->exec('ALTER TABLE bitacora ADD KEY idx_bit_sesion (sesion, id)');
    }

    // Lo que hace falta para responder «quién, desde dónde y con qué» meses
    // después. El agente completo se guarda crudo porque es lo que un perito
    // pide; «dispositivo» es el mismo dato en legible, para no leer cadenas de
    // 200 caracteres en pantalla.
    columna_si_falta($pdo, 'bitacora', 'agente',      "VARCHAR(255) NOT NULL DEFAULT ''");
    columna_si_falta($pdo, 'bitacora', 'dispositivo', "VARCHAR(60)  NOT NULL DEFAULT ''");
    columna_si_falta($pdo, 'bitacora', 'ruta',        "VARCHAR(40)  NOT NULL DEFAULT ''");
    columna_si_falta($pdo, 'bitacora', 'metodo',      "VARCHAR(4)   NOT NULL DEFAULT ''");
    columna_si_falta($pdo, 'bitacora', 'sede_id',     'INT NOT NULL DEFAULT 0');
    columna_si_falta($pdo, 'bitacora', 'sesion',      "CHAR(12) NOT NULL DEFAULT ''");
    // La IP que declara el navegador, cuando llega por un proxy. Va aparte de
    // «ip» a propósito: esa cabecera la escribe quien quiera, así que sirve de
    // pista pero no de prueba.
    columna_si_falta($pdo, 'bitacora', 'via',         "VARCHAR(45) NOT NULL DEFAULT ''");

    // El paso a paso de cada visita. Va en su propia tabla y no en la bitácora
    // porque son cosas distintas: la bitácora es lo que alguien cambió —se lee
    // entera— y esto es por dónde anduvo, que crece mil veces más rápido y se
    // limpia sin tocar lo otro.
    $pdo->exec("CREATE TABLE IF NOT EXISTS visitas (
        id          BIGINT AUTO_INCREMENT PRIMARY KEY,
        usuario_id  INT NULL,
        ruta        VARCHAR(40) NOT NULL DEFAULT '',
        ref         INT NOT NULL DEFAULT 0,
        sede_id     INT NOT NULL DEFAULT 0,
        ip          VARCHAR(45) NOT NULL DEFAULT '',
        dispositivo VARCHAR(60) NOT NULL DEFAULT '',
        sesion      CHAR(12)    NOT NULL DEFAULT '',
        creado_en   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_vis_fecha (creado_en),
        KEY idx_vis_sesion (sesion, id)
    ) $t");

    // El primer índice por usuario era `(usuario_id, id)` y no lo usaba nadie:
    // mientras la única pantalla fue la auditoría, toda consulta partía del
    // rango de fechas y ganaba `idx_vis_fecha`.
    if (indice_existe($pdo, 'visitas', 'idx_vis_usuario')) {
        $pdo->exec('ALTER TABLE visitas DROP INDEX idx_vis_usuario');
    }
    // La ficha de una persona trajo una forma de consulta que antes no existía
    // —filtrar por alguien **sin** rango de fechas— y ahí sí hace falta, con la
    // fecha dentro para que sirva también de orden. Medido con 100.000 visitas:
    // agrupar sus visitas pasa de 160 ms a 55, y la auditoría filtrando por
    // persona mira 533 filas en vez de 3.200. Las consultas que solo llevan
    // fechas siguen escogiendo `idx_vis_fecha`, así que no estorba.
    if (!indice_existe($pdo, 'visitas', 'idx_vis_persona')) {
        $pdo->exec('ALTER TABLE visitas ADD KEY idx_vis_persona (usuario_id, creado_en)');
    }

    // Qué movimiento o qué proveedor está mirando, no solo en qué pantalla:
    // es lo que permite avisar de que dos personas están sobre lo mismo.
    columna_si_falta($pdo, 'usuarios', 'pantalla_ref', 'INT NOT NULL DEFAULT 0');
    // La unidad de negocio en la que está trabajando ahora mismo. No es una
    // asignación: es dónde está parada, y cambia cada vez que cambia de unidad.
    columna_si_falta($pdo, 'usuarios', 'sede_activa',  'INT NOT NULL DEFAULT 0');

    // --------------------------------------------------------- Proveedores
    // Lo que trae el listado que exporta contabilidad. El código es el nombre
    // corto con que lo llaman («TOTTI», «40 GRADOS») y por ahí lo buscan; no es
    // único porque cada empresa del grupo lleva el suyo.
    columna_si_falta($pdo, 'proveedores', 'codigo',    "VARCHAR(40) NOT NULL DEFAULT ''");
    columna_si_falta($pdo, 'proveedores', 'nit',       "VARCHAR(20) NOT NULL DEFAULT ''");
    columna_si_falta($pdo, 'proveedores', 'telefono',  "VARCHAR(60) NOT NULL DEFAULT ''");
    // El RIF limpio es la mejor forma de no duplicar un proveedor, pero llega
    // sucio y a veces ni siquiera es un RIF, así que se guarda aparte del texto
    // original y queda vacío cuando no lo es. No es único: el vacío se repite.
    columna_si_falta($pdo, 'proveedores', 'rif_clave', "VARCHAR(20) NOT NULL DEFAULT ''");
    columna_si_falta($pdo, 'proveedores', 'activo',    'TINYINT(1)  NOT NULL DEFAULT 1');
    if (!indice_existe($pdo, 'proveedores', 'idx_prov_rif')) {
        $pdo->exec('ALTER TABLE proveedores ADD KEY idx_prov_rif (rif_clave)');
    }
    if (!indice_existe($pdo, 'proveedores', 'idx_prov_codigo')) {
        $pdo->exec('ALTER TABLE proveedores ADD KEY idx_prov_codigo (codigo)');
    }

    // Al proveedor lo comparte todo el grupo, pero una factura la debe una
    // empresa concreta: sin sede_id, una unidad vería las facturas de otra.
    columna_si_falta($pdo, 'facturas', 'sede_id',        'INT NOT NULL DEFAULT 0');
    columna_si_falta($pdo, 'facturas', 'moneda',         "CHAR(3) NOT NULL DEFAULT 'VES'");
    // Retener no es dejar de pagar: la factura queda cubierta con lo pagado más
    // lo retenido. Van en la misma moneda de la factura.
    columna_si_falta($pdo, 'facturas', 'retencion_iva',  'DECIMAL(18,2) NOT NULL DEFAULT 0');
    columna_si_falta($pdo, 'facturas', 'retencion_islr', 'DECIMAL(18,2) NOT NULL DEFAULT 0');
    columna_si_falta($pdo, 'facturas', 'nota_credito',   'DECIMAL(18,2) NOT NULL DEFAULT 0');
    columna_si_falta($pdo, 'facturas', 'nota',           "VARCHAR(255) NOT NULL DEFAULT ''");
    columna_si_falta($pdo, 'facturas', 'usuario_id',     'INT NULL');
    columna_si_falta($pdo, 'facturas', 'origen',         "VARCHAR(12) NOT NULL DEFAULT 'manual'");
    // El número en su forma comparable, para reconocer «0001» y «1» como la
    // misma factura. Va en columna aparte y con índice suelto: la clave única
    // sigue siendo la del número tal como se escribió, y cambiarla es la clase
    // de migración que rompe cosas en caliente.
    columna_si_falta($pdo, 'facturas', 'numero_clave',   "VARCHAR(60) NOT NULL DEFAULT ''");
    if (!indice_existe($pdo, 'facturas', 'idx_factura_clave')) {
        $pdo->exec('ALTER TABLE facturas ADD KEY idx_factura_clave (proveedor_id, sede_id, numero_clave)');
    }
    // Las que ya estaban se rellenan una sola vez; después la escribe el alta.
    if (ajuste('facturas_clave') !== '1') {
        $viejas = $pdo->query("SELECT id, numero FROM facturas WHERE numero_clave = ''")->fetchAll();
        $up = $pdo->prepare('UPDATE facturas SET numero_clave = ? WHERE id = ?');
        foreach ($viejas as $v) {
            $up->execute([clave_factura((string) $v['numero']), (int) $v['id']]);
        }
        guardar_ajuste('facturas_clave', '1');
    }
    // Dos empresas del grupo pueden recibir facturas con el mismo número del
    // mismo proveedor, así que la sede entra en la clave. El índice suelto de
    // proveedor_id se crea antes de soltar la clave vieja: es el que sostiene
    // la clave foránea, y sin él MySQL no deja borrarla.
    if (!indice_existe($pdo, 'facturas', 'uq_factura_sede')) {
        if (!indice_existe($pdo, 'facturas', 'idx_factura_prov')) {
            $pdo->exec('ALTER TABLE facturas ADD KEY idx_factura_prov (proveedor_id)');
        }
        $pdo->exec('ALTER TABLE facturas ADD UNIQUE KEY uq_factura_sede (sede_id, proveedor_id, numero)');
        if (indice_existe($pdo, 'facturas', 'uq_factura')) {
            $pdo->exec('ALTER TABLE facturas DROP INDEX uq_factura');
        }
    }
    if (!indice_existe($pdo, 'facturas', 'idx_factura_sede')) {
        $pdo->exec('ALTER TABLE facturas ADD KEY idx_factura_sede (sede_id)');
    }

    // Un pago se reparte entre varias facturas y una factura recibe varios
    // pagos. «monto» es lo aplicado en la moneda de la factura; «monto_bs», lo
    // que salió del banco. La tasa se congela en el reparto: si mañana el BCV
    // cambia, lo que se anotó ayer no se mueve.
    columna_si_falta($pdo, 'pagos_factura', 'monto_bs',   'DECIMAL(18,2) NOT NULL DEFAULT 0');
    columna_si_falta($pdo, 'pagos_factura', 'tasa',       'DECIMAL(18,8) NULL');
    columna_si_falta($pdo, 'pagos_factura', 'usuario_id', 'INT NULL');

    sembrar_comisiones($pdo);
    sembrar_desglose_comisiones($pdo);
    sembrar_comision_cobros($pdo);
    sembrar_maestro($pdo);

    // Al final del todo: si algo de arriba falló, la próxima petición reintenta.
    guardar_ajuste('esquema', (string) ESQUEMA_VERSION);
}

/**
 * Sin usuarios no se puede entrar, así que la primera migración convierte el
 * PIN compartido que había en un usuario maestro. Nadie se queda fuera.
 */
function sembrar_maestro(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0) {
        return;
    }
    $hash = ajuste('pin_hash');
    if ($hash === null) {
        // Base recién creada: no hay PIN anterior que heredar. Si aquí no se
        // crea a nadie, la tabla se queda vacía y la pantalla de acceso pide un
        // PIN que no existe en ninguna parte: la instalación nace cerrada y no
        // hay forma de entrar. El PIN de arranque se genera solo y queda en
        // DATA_DIR/PIN-INICIAL.txt.
        $hash = password_hash(pin_inicial(), PASSWORD_DEFAULT);
        guardar_ajuste('pin_hash', $hash);
        guardar_ajuste('pin_inicial_pendiente', '1');
    }
    // La huella del PIN se queda en ceros: aquí no siempre se conoce el PIN en
    // claro. La calcula el primer acceso.
    $pdo->prepare('INSERT INTO usuarios (nombre, pin_hash, pin_busqueda, maestro) VALUES (?, ?, ?, 1)')
        ->execute(['Maestro', $hash, str_repeat('0', 64)]);
}

/**
 * Dos reglas para las comisiones bancarias, sembradas una sola vez.
 *
 * Las que ya venían son muy específicas y dejaban fuera formas evidentes
 * («COM.CREDITO INM.OB» de Bancrecer, «COM PAGO OB JURIDICO» del Exterior).
 * La primera regla las cubre todas sin tragarse las compras: sobre el texto ya
 * normalizado, COMPRA no tiene un límite de palabra tras COM.
 *
 * La segunda es de otro tipo: reconoce la comisión por ser el 0,3 % de un
 * movimiento con su misma referencia. Es la única forma de ver las de Banesco,
 * que se llaman igual que el pago que las origina.
 */
function sembrar_comisiones(PDO $pdo): void
{
    if (ajuste('reglas_comision') === '1') {
        return;
    }
    $cat = $pdo->query("SELECT id FROM categorias WHERE nombre LIKE '%omisiones bancarias%' LIMIT 1")
               ->fetchColumn();
    if ($cat === false) {
        return;                 // aún no se sembraron las categorías
    }
    $ins = $pdo->prepare('INSERT INTO reglas (nombre, campo, tipo, patron, categoria_id, beneficiario, prioridad)
                          VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute(['Comisiones · cualquier concepto que diga COM o COMISIÓN', 'concepto', 'regex',
                   '(^|\\s)COMIS(ION)?(\\s|$)|(^|\\s)COM(\\s|$)', $cat, 'Banco', 70]);
    $ins->execute(['Comisiones · 0,3 % de un movimiento con la misma referencia', 'monto', 'proporcion',
                   '0.3', $cat, 'Banco', 75]);
    guardar_ajuste('reglas_comision', '1');
}

/**
 * La comisión del pago móvil que ENTRA: el 1,5 % del cobro, con su misma
 * referencia y su mismo texto. La regla del 0,3 % solo cubría el pago móvil
 * que sale, y el equipo encontró el 10/09/2026 que las comisiones de Banesco
 * CASHEA se quedaban todas en pendientes. Va a «Pago móvil», que es la
 * categoría del desglose de comisiones; si no existiera, a la general.
 */
function sembrar_comision_cobros(PDO $pdo): void
{
    if (ajuste('reglas_comision_cobro') === '1') {
        return;
    }
    $cat = $pdo->query("SELECT id FROM categorias WHERE nombre = 'Pago móvil' LIMIT 1")->fetchColumn();
    if ($cat === false) {
        $cat = $pdo->query("SELECT id FROM categorias WHERE nombre LIKE '%omisiones bancarias%' LIMIT 1")
                   ->fetchColumn();
    }
    if ($cat === false) {
        return;                 // aún no se sembraron las categorías
    }
    $pdo->prepare('INSERT INTO reglas (nombre, campo, tipo, patron, categoria_id, beneficiario, prioridad)
                   VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute(['Comisiones · 1,5 % de un cobro con la misma referencia (pago móvil que entra)',
                   'monto', 'proporcion', '1.5', (int) $cat, 'Banco', 75]);
    guardar_ajuste('reglas_comision_cobro', '1');
}

/** ¿Existe ese índice? Se usa para migrar claves sin repetir el ALTER. */
function indice_existe(PDO $pdo, string $tabla, string $indice): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS
                         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $s->execute([$tabla, $indice]);
    return (int) $s->fetchColumn() > 0;
}

/** Añade una columna solo si todavía no existe (migración idempotente). */
function columna_si_falta(PDO $pdo, string $tabla, string $columna, string $definicion): void
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $s->execute([$tabla, $columna]);
    if ((int) $s->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$tabla` ADD COLUMN `$columna` $definicion");
    }
}

function ajuste(string $clave, ?string $defecto = null): ?string
{
    $s = db()->prepare('SELECT valor FROM ajustes WHERE clave = ?');
    $s->execute([$clave]);
    $v = $s->fetchColumn();
    return $v === false ? $defecto : $v;
}

/** Varios ajustes de un tirón: una consulta en vez de una por clave. */
function ajustes_varios(array $claves): array
{
    if ($claves === []) {
        return [];
    }
    $huecos = implode(',', array_fill(0, count($claves), '?'));
    $s = db()->prepare("SELECT clave, valor FROM ajustes WHERE clave IN ($huecos)");
    $s->execute($claves);
    $out = array_fill_keys($claves, null);
    foreach ($s->fetchAll() as $f) {
        $out[$f['clave']] = $f['valor'];
    }
    return $out;
}

function guardar_ajuste(string $clave, string $valor): void
{
    $s = db()->prepare('INSERT INTO ajustes (clave, valor) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
    $s->execute([$clave, $valor]);
}

/**
 * De dónde viene la petición. Se queda con REMOTE_ADDR, que es la única que no
 * puede falsear quien llama: `X-Forwarded-For` la escribe el propio navegador
 * si quiere. La declarada se guarda aparte, como pista.
 */
function ip_cliente(): string
{
    return mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
}

/** La IP que dice el proxy, si hay proxy. Vacío en la mayoría de los casos. */
function ip_declarada(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $c) {
        $v = trim(explode(',', (string) ($_SERVER[$c] ?? ''))[0]);
        if ($v !== '' && $v !== ip_cliente()) {
            return mb_substr($v, 0, 45);
        }
    }
    return '';
}

/**
 * Qué navegador y qué sistema, en legible.
 *
 * A mano y con cuatro expresiones: meter una librería de reconocimiento por
 * esto sería traer mil reglas para leer una cadena. El orden importa —Edge y
 * Opera se anuncian además como Chrome, y Chrome como Safari—, así que se
 * mira de lo más específico a lo más general.
 *
 * Vive aquí y no en usuarios.php porque la usa `bitacora()`, y este archivo no
 * requiere a ninguno: un script que solo abriera la base se caía al escribir.
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
 * Huella de la sesión, para poder seguir una visita entera de principio a fin.
 * Se guarda el resumen y no el identificador: con el identificador, quien lea
 * el registro podría hacerse pasar por esa persona; con la huella, no.
 */
function huella_sesion(): string
{
    $id = session_id();
    return $id === '' ? '' : substr(hash('sha256', $id), 0, 12);
}

/**
 * Deja constancia de algo que alguien hizo.
 *
 * Guarda además desde dónde y con qué: es lo que hace falta el día que haya
 * que auditar en serio. Lo que **no** entra nunca aquí es el contenido de
 * `$_POST`: por ahí viaja el PIN.
 */
function bitacora(string $accion, string $detalle = ''): void
{
    // El autor sale de la sesión: así ninguna de las 18 llamadas repartidas por
    // la aplicación tuvo que cambiar para empezar a dejar rastro con nombre.
    $uid = (int) ($_SESSION['uid'] ?? 0) ?: null;
    $ua  = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $s = db()->prepare('INSERT INTO bitacora
            (accion, detalle, ip, via, usuario_id, agente, dispositivo, ruta, metodo, sede_id, sesion)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $s->execute([
        $accion,
        mb_substr($detalle, 0, 500),
        ip_cliente(),
        ip_declarada(),
        $uid,
        mb_substr($ua, 0, 255),
        dispositivo_de($ua),
        mb_substr((string) ($GLOBALS['ruta'] ?? ''), 0, 40),
        mb_substr((string) ($_SERVER['REQUEST_METHOD'] ?? ''), 0, 4),
        (int) ($_SESSION['sede'] ?? 0),
        huella_sesion(),
    ]);
}
