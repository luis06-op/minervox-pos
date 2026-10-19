-- =====================================================================
-- MINERVOX · Esquema base (v1)  ·  Base de datos: minervox_db
-- Motor: MariaDB (XAMPP)  ·  Codificación: utf8mb4
--
-- Este archivo crea la ESTRUCTURA ORIGINAL del sistema (las 7 tablas
-- que ya están en producción). NO borra nada: usa CREATE ... IF NOT EXISTS.
--
-- ORDEN DE INSTALACIÓN:
--   1) Importar este archivo            -> database.sql
--   2) Importar la migración v2         -> migracion_minervox_v2.sql
--   (Las mejoras de contabilidad, usuarios y trazabilidad viven en la
--    migración, para que una base de datos que ya tiene datos se pueda
--    actualizar sin perderlos.)
--
-- NOTA TÉCNICA: las llaves foráneas de `ventas` y `venta_detalles` se
-- declaran SIN nombre y en un orden específico, porque MariaDB las bautiza
-- automáticamente como `ventas_ibfk_1` y `venta_detalles_ibfk_2`. La
-- sección 7 de la migración v2 las elimina por ese nombre exacto.
-- NO renombrar ni reordenar esas llaves.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `minervox_db`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

USE `minervox_db`;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. CONFIGURACIÓN DEL NEGOCIO
--    Una sola fila (id = 1). La API siempre lee/escribe WHERE id = 1.
--    La migración v2 le agrega ciudad, logo, mora_mensual, etc.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `configuracion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_comercial` varchar(150) NOT NULL DEFAULT 'MINERVOX',
  `razon_social` varchar(150) DEFAULT NULL,
  `nit` varchar(50) DEFAULT NULL,
  `direccion` varchar(150) DEFAULT NULL,
  `telefonos` varchar(100) DEFAULT NULL,
  `plazo_1` int(11) NOT NULL DEFAULT 8 COMMENT 'plazo corto en días (fiao 8-15 días)',
  `plazo_2` int(11) NOT NULL DEFAULT 15 COMMENT 'plazo largo en días',
  `recargo_credito` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT '% de recargo por vender a crédito',
  `texto_pagare` text DEFAULT NULL COMMENT 'texto legal que se imprime en el pagaré',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fila única de configuración (no pisa datos existentes)
INSERT IGNORE INTO `configuracion`
  (`id`, `nombre_comercial`, `razon_social`, `nit`, `direccion`, `telefonos`, `plazo_1`, `plazo_2`, `recargo_credito`, `texto_pagare`)
VALUES
  (1, 'MINERVOX', 'MINERVOX', '', 'Puerto Claver, El Bagre, Antioquia', '', 8, 15, 0.00,
   'Yo, el deudor, me obligo a pagar a la orden de MINERVOX la suma aquí consignada, en el plazo pactado. En caso de mora pagaré los intereses correspondientes.');

-- ---------------------------------------------------------------------
-- 2. PRODUCTOS E INVENTARIO
--    Insumos mineros: cianuro, ácido nítrico, zinc en viruta, bórax, cal.
--    La migración v2 agrega controlado / activo / categoria.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `productos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `unidad` varchar(20) DEFAULT NULL COMMENT 'caneca, galón, saca, bulto, unidad',
  `precio_costo` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'lo que cuesta comprarlo',
  `precio_contado` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'lista de precios de contado',
  `precio_credito` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'lista de precios fiao (con recargo)',
  `stock` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stock_minimo` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'alerta de reabastecimiento',
  PRIMARY KEY (`id`),
  KEY `idx_productos_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 3. CLIENTES (mineros, bahuqueros, operadores de draga, entables)
--    La migración v2 agrega activo y vuelve la cédula ÚNICA.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cedula` varchar(30) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `ubicacion` varchar(150) DEFAULT NULL COMMENT 'vereda, sector o mina',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 4. VENTAS (POS)
--    tipo_venta: contado (paga ya) o credito (fiao).
--    cliente_id queda NULL en ventas de mostrador sin cliente registrado.
--    OJO: la FK de cliente_id debe ser la PRIMERA llave de esta tabla
--    para que MariaDB la nombre `ventas_ibfk_1` (ver nota de arriba).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ventas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo_venta` enum('contado','credito') NOT NULL DEFAULT 'contado',
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cliente_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ventas_cliente` (`cliente_id`),
  FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 5. DETALLE DE VENTA (productos vendidos en cada venta)
--    OJO: la FK de venta_id va PRIMERO (venta_detalles_ibfk_1) y la de
--    producto_id DESPUÉS (venta_detalles_ibfk_2), porque la sección 7 de
--    la migración v2 elimina `venta_detalles_ibfk_2` por su nombre.
--    La migración v2 agrega costo_unitario (costo congelado al vender).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `venta_detalles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(10,2) NOT NULL DEFAULT 0.00,
  `precio_unitario` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_detalles_venta` (`venta_id`),
  KEY `idx_detalles_producto` (`producto_id`),
  FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 6. CARTERA / "FIAOS"
--    estado: activo = debe plata; pagado = ya saldó.
--    El semáforo (verde/amarillo/rojo) se calcula con fecha_vencimiento.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cartera_creditos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `saldo_pendiente` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fecha_vencimiento` date NOT NULL,
  `estado` enum('activo','pagado') NOT NULL DEFAULT 'activo',
  PRIMARY KEY (`id`),
  KEY `idx_cartera_venta` (`venta_id`),
  FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 7. ABONOS (pagos que el minero hace contra su fiao)
--    La migración v2 agrega metodo_pago y usuario_id (para el arqueo).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `abonos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `credito_id` int(11) NOT NULL,
  `monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_abonos_credito` (`credito_id`),
  FOREIGN KEY (`credito_id`) REFERENCES `cartera_creditos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- FIN DEL ESQUEMA BASE v1
-- Siguiente paso: importar `migracion_minervox_v2.sql`
-- =====================================================================
