-- =====================================================================
-- MINERVOX · Migración v2 (contabilidad + ajustes ampliados + trazabilidad)
-- Base de datos: minervox_db  ·  Motor: MariaDB (XAMPP)
--
-- ANTES DE CORRER:
--   1) Haz un respaldo: phpMyAdmin > minervox_db > Exportar > Continuar.
--   2) Corre las secciones 0 a 6 completas (son seguras: usan IF NOT EXISTS).
--   3) La sección 7 corre al final y una instrucción a la vez, porque
--      phpMyAdmin se detiene en el primer error y esas instrucciones
--      dependen de los nombres de las llaves foráneas.
-- =====================================================================

USE `minervox_db`;

-- ---------------------------------------------------------------------
-- 0. VERIFICACIÓN PREVIA: ¿hay cédulas duplicadas?
--    Si esta consulta devuelve filas, corrige esos clientes ANTES de la
--    sección 7 (donde se vuelve única la cédula).
-- ---------------------------------------------------------------------
SELECT cedula, COUNT(*) AS veces
FROM clientes
GROUP BY cedula
HAVING COUNT(*) > 1;

-- ---------------------------------------------------------------------
-- 1. USUARIOS (login y roles)
--    La contraseña se guarda con password_hash() de PHP, nunca en texto.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `usuario` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `rol` enum('admin','cajero') NOT NULL DEFAULT 'cajero',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuarios_usuario` (`usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 2. COLUMNAS NUEVAS EN TABLAS EXISTENTES
-- ---------------------------------------------------------------------

-- 2.1 configuracion: más ajustes del sistema
ALTER TABLE `configuracion`
  ADD COLUMN IF NOT EXISTS `ciudad` varchar(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `correo` varchar(150) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `logo` varchar(255) DEFAULT NULL COMMENT 'ruta local, ej: assets/img/logo.png',
  ADD COLUMN IF NOT EXISTS `pie_ticket` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `prefijo_recibo` varchar(10) NOT NULL DEFAULT 'R',
  ADD COLUMN IF NOT EXISTS `prefijo_pagare` varchar(10) NOT NULL DEFAULT 'P',
  ADD COLUMN IF NOT EXISTS `dias_alerta` int(11) NOT NULL DEFAULT 2 COMMENT 'semáforo: amarillo si faltan <= N días',
  ADD COLUMN IF NOT EXISTS `mora_mensual` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT '% de interés de mora por mes',
  ADD COLUMN IF NOT EXISTS `plantilla_whatsapp` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `exigir_cedula_contado` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'los productos controlados siempre exigen cédula';

UPDATE `configuracion`
SET `plantilla_whatsapp` = 'Hola {nombre}, le saludamos de {negocio}. Le recordamos que tiene un saldo pendiente de {saldo} con vencimiento el {fecha}. Gracias por su confianza.'
WHERE `plantilla_whatsapp` IS NULL;

-- 2.2 productos: controlados, activo/inactivo y categoría
ALTER TABLE `productos`
  ADD COLUMN IF NOT EXISTS `controlado` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = sustancia controlada, exige cédula',
  ADD COLUMN IF NOT EXISTS `activo` tinyint(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `categoria` varchar(60) DEFAULT NULL;

UPDATE `productos` SET `controlado` = 1
WHERE `nombre` LIKE '%Cianuro%' OR `nombre` LIKE '%Nítrico%';

-- 2.3 clientes: activo/inactivo (en vez de borrar)
ALTER TABLE `clientes`
  ADD COLUMN IF NOT EXISTS `activo` tinyint(1) NOT NULL DEFAULT 1;

-- 2.4 venta_detalles: congelar el costo al momento de vender (utilidad real)
ALTER TABLE `venta_detalles`
  ADD COLUMN IF NOT EXISTS `costo_unitario` decimal(12,2) NOT NULL DEFAULT 0.00;

-- Ventas anteriores: se rellena con el costo actual (mejor aproximación disponible)
UPDATE `venta_detalles` d
JOIN `productos` p ON p.id = d.producto_id
SET d.costo_unitario = p.precio_costo
WHERE d.costo_unitario = 0;

-- 2.5 ventas: método de pago, anulación y usuario que vendió
ALTER TABLE `ventas`
  ADD COLUMN IF NOT EXISTS `metodo_pago` enum('efectivo','transferencia','otro') NOT NULL DEFAULT 'efectivo',
  ADD COLUMN IF NOT EXISTS `anulada` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `anulada_motivo` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `anulada_en` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `usuario_id` int(11) DEFAULT NULL;

-- 2.6 abonos: método de pago y usuario (para el arqueo de caja)
ALTER TABLE `abonos`
  ADD COLUMN IF NOT EXISTS `metodo_pago` enum('efectivo','transferencia','otro') NOT NULL DEFAULT 'efectivo',
  ADD COLUMN IF NOT EXISTS `usuario_id` int(11) DEFAULT NULL;

-- ---------------------------------------------------------------------
-- 3. TABLAS NUEVAS DE CONTABILIDAD
-- ---------------------------------------------------------------------

-- 3.1 Proveedores
CREATE TABLE IF NOT EXISTS `proveedores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `nit` varchar(50) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `ubicacion` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.2 Compras de mercancía (entradas de inventario)
CREATE TABLE IF NOT EXISTS `compras` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `proveedor_id` int(11) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tipo_pago` enum('contado','credito') NOT NULL DEFAULT 'contado',
  `saldo_pendiente` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fecha_vencimiento` date DEFAULT NULL,
  `estado` enum('pagada','pendiente') NOT NULL DEFAULT 'pagada',
  `nota` varchar(255) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_compras_estado` (`estado`),
  FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `compra_detalles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `compra_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(10,2) NOT NULL,
  `costo_unitario` decimal(12,2) NOT NULL,
  `subtotal` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`compra_id`) REFERENCES `compras`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`producto_id`) REFERENCES `productos`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.3 Pagos a proveedores (cuentas por pagar)
CREATE TABLE IF NOT EXISTS `pagos_proveedores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `compra_id` int(11) NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `fecha` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `metodo_pago` enum('efectivo','transferencia','otro') NOT NULL DEFAULT 'efectivo',
  `usuario_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`compra_id`) REFERENCES `compras`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.4 Gastos operativos
CREATE TABLE IF NOT EXISTS `categorias_gasto` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categoria_gasto_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `gastos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `categoria_id` int(11) NOT NULL,
  `descripcion` varchar(255) NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `fecha` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `metodo_pago` enum('efectivo','transferencia','otro') NOT NULL DEFAULT 'efectivo',
  `usuario_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_gastos_fecha` (`fecha`),
  FOREIGN KEY (`categoria_id`) REFERENCES `categorias_gasto`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.5 Cierre de caja diario (arqueo)
CREATE TABLE IF NOT EXISTS `cierres_caja` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `efectivo_inicial` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ventas_efectivo` decimal(12,2) NOT NULL DEFAULT 0.00,
  `abonos_efectivo` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gastos_efectivo` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pagos_proveedor_efectivo` decimal(12,2) NOT NULL DEFAULT 0.00,
  `efectivo_esperado` decimal(12,2) NOT NULL DEFAULT 0.00,
  `efectivo_contado` decimal(12,2) NOT NULL DEFAULT 0.00,
  `diferencia` decimal(12,2) NOT NULL DEFAULT 0.00,
  `observacion` varchar(255) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cierres_fecha` (`fecha`),
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.6 Kardex: historial de movimientos de inventario (trazabilidad legal)
--     cantidad > 0 entra, cantidad < 0 sale.
CREATE TABLE IF NOT EXISTS `movimientos_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `tipo` enum('venta','compra','ajuste','anulacion') NOT NULL,
  `cantidad` decimal(10,2) NOT NULL,
  `stock_resultante` decimal(10,2) NOT NULL,
  `referencia_id` int(11) DEFAULT NULL COMMENT 'id de venta o compra según el tipo',
  `nota` varchar(255) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `usuario_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mov_producto_fecha` (`producto_id`,`fecha`),
  FOREIGN KEY (`producto_id`) REFERENCES `productos`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 4. VISTAS PARA REPORTES (el PHP solo hace SELECT sobre ellas)
-- ---------------------------------------------------------------------

-- Utilidad por venta (no cuenta las anuladas)
CREATE OR REPLACE VIEW `v_utilidad_ventas` AS
SELECT
  v.id AS venta_id,
  v.fecha,
  v.tipo_venta,
  v.cliente_id,
  SUM(d.subtotal) AS total_vendido,
  SUM(d.cantidad * d.costo_unitario) AS costo_total,
  SUM(d.subtotal) - SUM(d.cantidad * d.costo_unitario) AS utilidad
FROM ventas v
JOIN venta_detalles d ON d.venta_id = v.id
WHERE v.anulada = 0
GROUP BY v.id, v.fecha, v.tipo_venta, v.cliente_id;

-- Ventas de sustancias controladas: quién, cuándo, qué y cuánto
CREATE OR REPLACE VIEW `v_ventas_controlados` AS
SELECT
  v.id AS venta_id,
  v.fecha,
  v.tipo_venta,
  c.cedula,
  c.nombre AS cliente,
  c.telefono,
  c.ubicacion,
  p.nombre AS producto,
  d.cantidad,
  d.precio_unitario,
  d.subtotal
FROM venta_detalles d
JOIN ventas v ON v.id = d.venta_id
JOIN productos p ON p.id = d.producto_id
LEFT JOIN clientes c ON c.id = v.cliente_id
WHERE p.controlado = 1 AND v.anulada = 0;

-- ---------------------------------------------------------------------
-- 5. DATOS INICIALES
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `categorias_gasto` (`nombre`) VALUES
('Transporte y fletes'),
('Arriendo'),
('Servicios públicos'),
('Nómina y ayudantes'),
('Mantenimiento'),
('Alimentación'),
('Impuestos y permisos'),
('Otros');

-- ---------------------------------------------------------------------
-- 6. ÍNDICES PARA QUE LOS REPORTES SEAN RÁPIDOS
-- ---------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS `idx_ventas_fecha` ON `ventas` (`fecha`);
CREATE INDEX IF NOT EXISTS `idx_abonos_fecha` ON `abonos` (`fecha`);
CREATE INDEX IF NOT EXISTS `idx_cartera_estado_venc` ON `cartera_creditos` (`estado`,`fecha_vencimiento`);
CREATE INDEX IF NOT EXISTS `idx_detalles_venta` ON `venta_detalles` (`venta_id`);

-- ---------------------------------------------------------------------
-- 7. INTEGRIDAD Y PROTECCIÓN DEL HISTORIAL
--    Corre UNA instrucción a la vez. Si alguna falla por el nombre de la
--    llave, mira el nombre real con:  SHOW CREATE TABLE ventas;
-- ---------------------------------------------------------------------

-- 7.1 La cédula debe ser única (falla si la sección 0 devolvió duplicados)
ALTER TABLE `clientes` ADD UNIQUE KEY `uq_clientes_cedula` (`cedula`);

-- 7.2 No permitir borrar un cliente que tiene ventas (antes quedaba sin dueño)
ALTER TABLE `ventas` DROP FOREIGN KEY `ventas_ibfk_1`;
ALTER TABLE `ventas`
  ADD CONSTRAINT `fk_ventas_cliente` FOREIGN KEY (`cliente_id`)
  REFERENCES `clientes`(`id`) ON DELETE RESTRICT;

-- 7.3 No permitir borrar un producto que ya se vendió
--     (antes, borrarlo eliminaba también el detalle de sus ventas)
ALTER TABLE `venta_detalles` DROP FOREIGN KEY `venta_detalles_ibfk_2`;
ALTER TABLE `venta_detalles`
  ADD CONSTRAINT `fk_detalles_producto` FOREIGN KEY (`producto_id`)
  REFERENCES `productos`(`id`) ON DELETE RESTRICT;

-- 7.4 Usuario que registró cada venta y cada abono
ALTER TABLE `ventas`
  ADD CONSTRAINT `fk_ventas_usuario` FOREIGN KEY (`usuario_id`)
  REFERENCES `usuarios`(`id`) ON DELETE RESTRICT;
ALTER TABLE `abonos`
  ADD CONSTRAINT `fk_abonos_usuario` FOREIGN KEY (`usuario_id`)
  REFERENCES `usuarios`(`id`) ON DELETE RESTRICT;
