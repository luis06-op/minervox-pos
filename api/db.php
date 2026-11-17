<?php
/**
 * Conexión PDO compartida por todos los endpoints.
 *
 * Orden de configuración:
 *   1. api/config.local.php  (recomendado, fuera de Git)
 *   2. Variables de entorno  (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS)
 *   3. Valores por defecto de XAMPP
 */

declare(strict_types=1);

// Toda la aplicación trabaja en hora de Colombia (UTC-5, sin horario de verano).
date_default_timezone_set('America/Bogota');

$config = [
    'host' => '127.0.0.1',
    'port' => '3306',
    'name' => 'minervox_db',
    'user' => 'root',
    'pass' => '',
];

$archivoLocal = __DIR__ . '/config.local.php';

foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'name' => 'DB_NAME', 'user' => 'DB_USER'] as $clave => $variable) {
    $valor = getenv($variable);
    if ($valor !== false && $valor !== '') {
        $config[$clave] = $valor;
    }
}
$passwordEntorno = getenv('DB_PASS');
if ($passwordEntorno !== false) {
    $config['pass'] = $passwordEntorno;
}

// El archivo local tiene la última palabra sobre las variables de entorno.
if (is_file($archivoLocal)) {
    $local = require $archivoLocal;
    if (is_array($local)) {
        $config = array_merge($config, array_intersect_key($local, $config));
    }
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $config['host'],
    $config['port'],
    $config['name']
);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_STRINGIFY_FETCHES  => false,
];

try {
    $pdo = new PDO($dsn, $config['user'], $config['pass'], $options);
    // Alinea NOW()/CURDATE() de MySQL con la zona horaria de PHP.
    $pdo->exec("SET time_zone = '-05:00'");
} catch (PDOException $e) {
    error_log('[minervox] Error de conexión a la base de datos: ' . $e->getMessage());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }

    echo json_encode([
        'success' => false,
        'error'   => 'No se pudo conectar con la base de datos. Revisa api/config.local.php.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
