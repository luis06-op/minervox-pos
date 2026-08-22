<?php
header('Content-Type: application/json');
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['cedula'])) {
        $stmt = $pdo->prepare('SELECT * FROM clientes WHERE cedula = ? LIMIT 1');
        $stmt->execute([$_GET['cedula']]);
        $cliente = $stmt->fetch();
        if ($cliente) {
            echo json_encode(['success' => true, 'cliente' => $cliente]);
        } else {
            echo json_encode(['success' => false]);
        }
    } else {
        $stmt = $pdo->query('SELECT * FROM clientes');
        $clientes = $stmt->fetchAll();
        echo json_encode($clientes);
    }
} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $stmt = $pdo->prepare('INSERT INTO clientes (cedula, nombre, telefono, ubicacion) VALUES (?, ?, ?, ?)');
    $success = $stmt->execute([
        $data['cedula'],
        $data['nombre'],
        $data['telefono'],
        $data['ubicacion']
    ]);
    echo json_encode(['success' => $success, 'id' => $pdo->lastInsertId()]);
} else {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['error' => 'Method not allowed']);
}
?>
