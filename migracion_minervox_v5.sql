-- =====================================================================
-- MINERVOX · Migración v5  ·  FASE 5 (Multiusuario y roles)
-- =====================================================================
--
-- Se puede ejecutar sobre una base que YA TIENE DATOS: no borra nada.
-- Está escrita para poder correrse dos veces sin dar error.
--
-- Qué hace:
--   1. A la tabla `usuarios` (que existe desde la v2 y estaba vacía) le
--      agrega tres datos que hacían falta para poder entrar con seguridad:
--        · ultimo_acceso      -> para saber cuándo entró por última vez
--        · intentos_fallidos  -> para frenar a quien adivine la clave
--        · bloqueado_hasta    -> el castigo temporal tras 5 intentos
--
--   2. NO crea ningún usuario. A propósito: el repositorio es público y
--      una clave por defecto escrita en un .sql es una puerta abierta.
--      La primera vez, la aplicación muestra la pantalla "Crear el
--      administrador" y la clave la elige el dueño. Una vez exista un
--      administrador, esa pantalla se cierra sola.
--
-- ORDEN: después de database.sql y de las migraciones v2, v3 y v4.
-- =====================================================================

USE `minervox_db`;
SET NAMES utf8mb4;

ALTER TABLE `usuarios`
  ADD COLUMN IF NOT EXISTS `ultimo_acceso` datetime DEFAULT NULL
    COMMENT 'última vez que este usuario entró al sistema',
  ADD COLUMN IF NOT EXISTS `intentos_fallidos` int(11) NOT NULL DEFAULT 0
    COMMENT 'claves erradas seguidas; al llegar a 5 se bloquea',
  ADD COLUMN IF NOT EXISTS `bloqueado_hasta` datetime DEFAULT NULL
    COMMENT 'hasta cuándo está bloqueado por intentos fallidos';

-- =====================================================================
-- FIN DE LA MIGRACIÓN v5
-- =====================================================================
