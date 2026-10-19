<?php
/**
 * MINERVOX · Conexión a la base de datos (PDO)
 *
 * La caja vive en El Bagre (Colombia, UTC-5, sin horario de verano).
 * La zona horaria se fija aquí, y no en php.ini, porque este archivo lo
 * incluyen todos los endpoints.
 *
 * ¿Por qué importa? PHP venía trabajando en UTC y MariaDB en la hora del
 * equipo. A las 8 de la noche PHP ya creía que era el día siguiente,
 * así que el cierre de caja del día salía en cero. Ahora los dos cuentan
 * el mismo día.
 */
date_default_timezone_set('America/Bogota');

$host = 'localhost';
$db = 'minervox_db';
$user = 'root'; // Usuario por defecto de XAMPP
$pass = '';     // Contraseña por defecto vacía en XAMPP

$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // Mismo huso horario en MariaDB, para que NOW() y CURDATE() den la
    // misma hora que PHP y no se cruce el día en medio de la jornada.
    $pdo->exec("SET time_zone = '-05:00'");
} catch (\PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}
?>
