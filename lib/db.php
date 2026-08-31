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
    return $pdo;
}

/** Crea el esquema si no existe. Idempotente. */
function migrar(): void
{
    $pdo = db();
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

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

    // Todo lo cargado antes de existir las sedes es de ARMOR MARKET: se crea
    // esa sede y se le adjudican las cuentas huérfanas. Es idempotente porque
    // solo toca las que aún tienen sede_id = 0.
    if ((int) $pdo->query('SELECT COUNT(*) FROM cuentas WHERE sede_id = 0')->fetchColumn() > 0) {
        $pdo->exec("INSERT IGNORE INTO sedes (nombre) VALUES ('ARMOR MARKET')");
        $primera = (int) $pdo->query("SELECT id FROM sedes ORDER BY id LIMIT 1")->fetchColumn();
        $pdo->prepare('UPDATE cuentas SET sede_id = ? WHERE sede_id = 0')->execute([$primera]);
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
        KEY idx_mov_estado (tipo, estado),
        KEY idx_mov_cat    (categoria_id),
        CONSTRAINT fk_mov_cuenta FOREIGN KEY (cuenta_id)      REFERENCES cuentas(id)       ON DELETE CASCADE,
        CONSTRAINT fk_mov_imp    FOREIGN KEY (importacion_id) REFERENCES importaciones(id) ON DELETE SET NULL,
        CONSTRAINT fk_mov_cat    FOREIGN KEY (categoria_id)   REFERENCES categorias(id)    ON DELETE SET NULL,
        CONSTRAINT fk_mov_regla  FOREIGN KEY (regla_id)       REFERENCES reglas(id)        ON DELETE SET NULL
    ) $t");

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

    $pdo->exec("CREATE TABLE IF NOT EXISTS ajustes (
        clave VARCHAR(60) PRIMARY KEY,
        valor TEXT NOT NULL
    ) $t");
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

function guardar_ajuste(string $clave, string $valor): void
{
    $s = db()->prepare('INSERT INTO ajustes (clave, valor) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
    $s->execute([$clave, $valor]);
}

function bitacora(string $accion, string $detalle = ''): void
{
    $s = db()->prepare('INSERT INTO bitacora (accion, detalle, ip) VALUES (?, ?, ?)');
    $s->execute([$accion, mb_substr($detalle, 0, 500), $_SERVER['REMOTE_ADDR'] ?? 'cli']);
}
