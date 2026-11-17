-- ============================================================
--  MINERVOX POS - Esquema base
--  MySQL 8 / MariaDB (XAMPP). Base: minervox_db
--
--  Uso:
--    mysql -u root < database.sql
--
--  El script es idempotente: usa CREATE TABLE IF NOT EXISTS y
--  no borra datos existentes. Para actualizar una instalación
--  anterior ejecuta los archivos de database/migraciones/.
-- ============================================================

CREATE DATABASE IF NOT EXISTS minervox_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE minervox_db;

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Configuración del negocio (fila única, id = 1)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS configuracion (
    id               TINYINT UNSIGNED NOT NULL,
    nombre_comercial VARCHAR(120)     NOT NULL DEFAULT '',
    razon_social     VARCHAR(160)     NOT NULL DEFAULT '',
    nit              VARCHAR(40)      NOT NULL DEFAULT '',
    direccion        VARCHAR(200)     NOT NULL DEFAULT '',
    telefonos        VARCHAR(120)     NOT NULL DEFAULT '',
    plazo_1          SMALLINT UNSIGNED NOT NULL DEFAULT 8,
    plazo_2          SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    recargo_credito  DECIMAL(5,2)     NOT NULL DEFAULT 0.00,
    texto_pagare     TEXT             NULL,
    actualizado_en   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO configuracion (id, nombre_comercial, plazo_1, plazo_2)
VALUES (1, 'MINERVOX', 8, 15)
ON DUPLICATE KEY UPDATE id = id;

-- ------------------------------------------------------------
-- Productos / inventario
--   activo = 0 permite dar de baja sin perder el historial
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS productos (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre        VARCHAR(150) NOT NULL,
    unidad        VARCHAR(20)  NOT NULL DEFAULT 'Und',
    precio_costo  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    precio_contado DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    precio_credito DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    stock         DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    stock_minimo  DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    activo        TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_productos_activo (activo),
    KEY idx_productos_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Clientes (cédula / NIT único)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clientes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cedula      VARCHAR(40)  NOT NULL,
    nombre      VARCHAR(150) NOT NULL,
    telefono    VARCHAR(40)  NOT NULL DEFAULT '',
    ubicacion   VARCHAR(150) NOT NULL DEFAULT '',
    creado_en   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clientes_cedula (cedula),
    KEY idx_clientes_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Ventas
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ventas (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cliente_id  INT UNSIGNED NULL,
    tipo_venta  ENUM('contado','credito') NOT NULL,
    total       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    fecha       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ventas_fecha (fecha),
    KEY idx_ventas_tipo (tipo_venta),
    KEY idx_ventas_cliente (cliente_id),
    CONSTRAINT fk_ventas_cliente
        FOREIGN KEY (cliente_id) REFERENCES clientes (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Detalle de venta
--   costo_unitario congela el costo del producto al momento
--   de la venta (sirve para calcular utilidad real).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS venta_detalles (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    venta_id        INT UNSIGNED NOT NULL,
    producto_id     INT UNSIGNED NOT NULL,
    cantidad        DECIMAL(12,3) NOT NULL,
    precio_unitario DECIMAL(12,2) NOT NULL,
    costo_unitario  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    subtotal        DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_detalles_venta (venta_id),
    KEY idx_detalles_producto (producto_id),
    CONSTRAINT fk_detalles_venta
        FOREIGN KEY (venta_id) REFERENCES ventas (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_detalles_producto
        FOREIGN KEY (producto_id) REFERENCES productos (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Cartera (un crédito por venta a crédito)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cartera_creditos (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    venta_id          INT UNSIGNED NOT NULL,
    saldo_pendiente   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    fecha_vencimiento DATE NOT NULL,
    estado            ENUM('activo','pagado') NOT NULL DEFAULT 'activo',
    creado_en         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cartera_venta (venta_id),
    KEY idx_cartera_estado_vencimiento (estado, fecha_vencimiento),
    CONSTRAINT fk_cartera_venta
        FOREIGN KEY (venta_id) REFERENCES ventas (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Abonos a créditos (incluye el abono inicial de la venta)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS abonos (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    credito_id INT UNSIGNED NOT NULL,
    monto      DECIMAL(12,2) NOT NULL,
    fecha      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_abonos_credito (credito_id),
    KEY idx_abonos_fecha (fecha),
    CONSTRAINT fk_abonos_credito
        FOREIGN KEY (credito_id) REFERENCES cartera_creditos (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Datos de ejemplo (opcional, descomenta si quieres probar)
-- ------------------------------------------------------------
-- INSERT INTO productos (nombre, unidad, precio_costo, precio_contado, precio_credito, stock, stock_minimo)
-- VALUES ('Reactivo de ejemplo', 'Kg', 10000, 13000, 15000, 25, 5);
