-- =====================================================================
-- MINERVOX · Migración v3 (Fase 1: contabilidad "Mi Plata")
-- Base: minervox_db  ·  Motor: MariaDB (XAMPP)
--
-- Es incremental y segura: se puede correr sobre una base que ya tiene
-- datos sin perder nada. Todas las instrucciones usan IF NOT EXISTS.
--
-- Requiere haber corrido antes `database.sql` y `migracion_minervox_v2.sql`.
-- =====================================================================

USE `minervox_db`;

-- ---------------------------------------------------------------------
-- 1. productos: descripción larga del insumo
--    (el esquema base no la tenía; ahora el mostrador puede explicar
--     para qué sirve cada reactivo, ej. qué es el zinc en viruta).
-- ---------------------------------------------------------------------
ALTER TABLE `productos`
  ADD COLUMN IF NOT EXISTS `descripcion` text DEFAULT NULL AFTER `nombre`;

-- ---------------------------------------------------------------------
-- 2. Índices de apoyo para los reportes de contabilidad
-- ---------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS `idx_gastos_metodo_fecha` ON `gastos` (`metodo_pago`, `fecha`);
CREATE INDEX IF NOT EXISTS `idx_compras_fecha`        ON `compras` (`fecha`);
CREATE INDEX IF NOT EXISTS `idx_ventas_anulada_fecha` ON `ventas` (`anulada`, `fecha`);

-- =====================================================================
-- FIN DE LA MIGRACIÓN v3
-- =====================================================================
