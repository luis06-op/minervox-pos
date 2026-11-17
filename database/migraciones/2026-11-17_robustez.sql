-- ============================================================
--  Migración: robustez del núcleo (2026-11-17)
--
--  Para instalaciones que ya tienen datos y fueron creadas con
--  una versión anterior del esquema. Haz un respaldo antes:
--
--    mysqldump -u root minervox_db > respaldo_minervox.sql
--    mysql -u root minervox_db < database/migraciones/2026-11-17_robustez.sql
--
--  Requiere MariaDB (XAMPP) por el "IF NOT EXISTS" en ADD COLUMN.
--  En MySQL 8 ejecuta las sentencias sin "IF NOT EXISTS" y omite
--  las que ya existan.
-- ============================================================

USE minervox_db;

-- Baja lógica de productos: conserva el historial de ventas.
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS activo TINYINT(1) NOT NULL DEFAULT 1 AFTER stock_minimo;

-- Costo congelado al momento de la venta.
ALTER TABLE venta_detalles
    ADD COLUMN IF NOT EXISTS costo_unitario DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER precio_unitario;

-- Índices de consulta frecuente.
CREATE INDEX IF NOT EXISTS idx_productos_activo ON productos (activo);
CREATE INDEX IF NOT EXISTS idx_clientes_nombre ON clientes (nombre);
CREATE INDEX IF NOT EXISTS idx_ventas_fecha ON ventas (fecha);
CREATE INDEX IF NOT EXISTS idx_ventas_tipo ON ventas (tipo_venta);
CREATE INDEX IF NOT EXISTS idx_cartera_estado_vencimiento ON cartera_creditos (estado, fecha_vencimiento);
CREATE INDEX IF NOT EXISTS idx_abonos_fecha ON abonos (fecha);

-- La cédula debe ser única para que el upsert de clientes funcione.
-- Si ya hay duplicados, corrige los datos antes de aplicar el índice.
ALTER TABLE clientes
    ADD UNIQUE KEY IF NOT EXISTS uq_clientes_cedula (cedula);

-- Un solo crédito por venta.
ALTER TABLE cartera_creditos
    ADD UNIQUE KEY IF NOT EXISTS uq_cartera_venta (venta_id);
