<?php
/**
 * Configuración local de la base de datos (ejemplo).
 *
 * Copia este archivo como api/config.local.php y ajusta tus datos.
 * api/config.local.php está en .gitignore: nunca lo subas al repositorio.
 *
 * También puedes usar variables de entorno (tienen prioridad sobre los
 * valores por defecto, pero no sobre config.local.php):
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 */
return [
    'host' => '127.0.0.1',
    'port' => '3306',
    'name' => 'minervox_db',
    'user' => 'root',
    'pass' => '', // En XAMPP la contraseña de root viene vacía por defecto.
];
