-- =====================================================================
-- MINERVOX · Migración v6  ·  FASE 4 (Controlados y anulación de ventas)
-- =====================================================================
--
-- Se puede ejecutar sobre una base que YA TIENE DATOS: no borra nada.
-- Está escrita para poder correrse dos veces sin dar error.
--
-- Qué hace:
--   1. Guarda en cada línea de venta si el producto era CONTROLADO en el
--      momento de venderlo. Sin esto, el libro de controlados se arma
--      mirando el catálogo de HOY: si mañana el dueño desmarca un
--      producto, las ventas viejas desaparecen del libro (y al revés).
--      Para un libro que puede pedir la autoridad, eso no sirve.
--   2. Guarda QUIÉN anuló una venta (`anulada_por`). `usuario_id` es el
--      que vendió; la anulación es otra persona y debe quedar aparte.
--   3. Agrega la categoría de gasto "Devoluciones", para que una
--      devolución de un día ya cerrado se registre como salida de plata
--      y el arqueo de hoy cuadre.
--   4. Rehace la vista `v_ventas_controlados` para que use el dato
--      congelado del detalle, no el catálogo actual.
--
-- ORDEN: después de database.sql y de las migraciones v2, v3, v4 y v5.
-- =====================================================================

USE `minervox_db`;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Bandera de controlado congelada en la línea de la venta
-- ---------------------------------------------------------------------
ALTER TABLE `venta_detalles`
  ADD COLUMN IF NOT EXISTS `controlado` tinyint(1) NOT NULL DEFAULT 0
    COMMENT '1 si el producto era controlado al momento de la venta';

-- Rellena las ventas que ya existían con el estado actual del catálogo.
-- Solo toca las que están en 0, para no pisar nada.
UPDATE `venta_detalles` d
  JOIN `productos` p ON p.id = d.producto_id
   SET d.controlado = 1
 WHERE p.controlado = 1
   AND d.controlado = 0;

-- ---------------------------------------------------------------------
-- 2. Quién anuló la venta (distinto de quién la vendió)
-- ---------------------------------------------------------------------
ALTER TABLE `ventas`
  ADD COLUMN IF NOT EXISTS `anulada_por` int(11) DEFAULT NULL
    COMMENT 'usuario que anuló la venta';

-- MariaDB no tiene "ADD CONSTRAINT IF NOT EXISTS": se pregunta primero.
SET @fk_existe := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = 'minervox_db'
     AND TABLE_NAME = 'ventas'
     AND CONSTRAINT_NAME = 'fk_ventas_anulada_por'
);
SET @sql := IF(@fk_existe = 0,
  'ALTER TABLE `ventas` ADD CONSTRAINT `fk_ventas_anulada_por`
     FOREIGN KEY (`anulada_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 3. Categoría de gasto para devoluciones
--    (una devolución en efectivo es plata que sale de la caja; si se
--     metiera como "Otros" nadie sabría después qué fue)
-- ---------------------------------------------------------------------
INSERT INTO `categorias_gasto` (`nombre`)
SELECT 'Devoluciones'
 WHERE NOT EXISTS (SELECT 1 FROM `categorias_gasto` WHERE `nombre` = 'Devoluciones');

-- ---------------------------------------------------------------------
-- 4. Vista del libro de controlados, con el dato congelado
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW `v_ventas_controlados` AS
SELECT
  v.`id`             AS `venta_id`,
  v.`fecha`          AS `fecha`,
  v.`tipo_venta`     AS `tipo_venta`,
  c.`cedula`         AS `cedula`,
  c.`nombre`         AS `cliente`,
  c.`telefono`       AS `telefono`,
  c.`ubicacion`      AS `ubicacion`,
  p.`nombre`         AS `producto`,
  d.`cantidad`       AS `cantidad`,
  d.`precio_unitario` AS `precio_unitario`,
  d.`subtotal`       AS `subtotal`
FROM `venta_detalles` d
JOIN `ventas` v      ON v.id = d.venta_id
JOIN `productos` p   ON p.id = d.producto_id
LEFT JOIN `clientes` c ON c.id = v.cliente_id
WHERE d.`controlado` = 1
  AND v.`anulada` = 0;

-- ---------------------------------------------------------------------
-- 5. Índice para el libro y para "¿esta venta tuvo controlados?"
-- ---------------------------------------------------------------------
ALTER TABLE `venta_detalles`
  ADD INDEX IF NOT EXISTS `idx_detalle_controlado` (`controlado`, `venta_id`);

-- =====================================================================
-- FIN DE LA MIGRACIÓN v6
-- =====================================================================
