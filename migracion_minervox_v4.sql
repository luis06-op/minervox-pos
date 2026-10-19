-- =====================================================================
-- MINERVOX · Migración v4  ·  FASE 2 (Cartera)
-- =====================================================================
--
-- Se puede ejecutar sobre una base que YA TIENE DATOS: no borra nada.
-- Está escrita para poder correrse dos veces sin dar error.
--
-- Qué hace:
--   1. Guarda en cada fiao el % de mora que estaba vigente cuando se
--      hizo la venta. Así, si mañana el negocio cambia la tasa, las
--      deudas viejas conservan la suya (igual que el costo congelado
--      de la Fase 1).
--   2. Crea la tabla `acuerdos_pago`: cuando un cliente no puede pagar
--      y se le corre la fecha, queda la huella de quién pactó qué.
--   3. Agrega un índice para que la consulta del semáforo vuele.
--
-- ORDEN: después de database.sql y de las migraciones v2 y v3.
-- =====================================================================

USE `minervox_db`;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Tasa de mora congelada por crédito
-- ---------------------------------------------------------------------
ALTER TABLE `cartera_creditos`
  ADD COLUMN IF NOT EXISTS `mora_mensual` decimal(5,2) NOT NULL DEFAULT 0.00
    COMMENT '% de mora mensual congelado al momento del fiao';

-- Rellena los fiaos que ya existían con la tasa que hoy tiene configurada
-- el negocio. Solo toca los que quedaron en 0, para no pisar nada.
UPDATE `cartera_creditos` cc
  JOIN `configuracion` cfg ON cfg.id = 1
   SET cc.mora_mensual = cfg.mora_mensual
 WHERE cc.mora_mensual = 0.00;

-- ---------------------------------------------------------------------
-- 2. Acuerdos de pago
--    `fecha_nueva` es el nuevo compromiso; `fecha_antes` guarda la que
--    había, para poder mostrar el historial completo del fiao.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `acuerdos_pago` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `credito_id` int(11) NOT NULL,
  `fecha_acuerdo` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_antes` date DEFAULT NULL COMMENT 'vencimiento que tenía antes del acuerdo',
  `fecha_nueva` date NOT NULL COMMENT 'nuevo vencimiento pactado',
  `monto_pactado` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'cuánto se comprometió a pagar',
  `observacion` varchar(255) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_acuerdos_credito` (`credito_id`),
  FOREIGN KEY (`credito_id`) REFERENCES `cartera_creditos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 3. Índice para el semáforo (busca por estado y fecha de vencimiento)
-- ---------------------------------------------------------------------
ALTER TABLE `cartera_creditos`
  ADD INDEX IF NOT EXISTS `idx_cartera_estado_venc` (`estado`, `fecha_vencimiento`);

-- =====================================================================
-- FIN DE LA MIGRACIÓN v4
-- =====================================================================
