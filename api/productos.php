<?php
header('Content-Type: application/json');
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT * FROM productos');
    $productos = $stmt->fetchAll();
    echo json_encode($productos);
} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $stmt = $pdo->prepare('INSERT INTO productos (nombre, unidad, precio_costo, precio_contado, precio_credito, stock, stock_minimo) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $success = $stmt->execute([
        $data['nombre'],
        $data['unidad'],
        $data['precio_costo'],
        $data['precio_contado'],
        $data['precio_credito'],
        $data['stock'],
        $data['stock_minimo']
    ]);
    echo json_encode(['success' => $success, 'id' => $pdo->lastInsertId()]);
} elseif ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $stmt = $pdo->prepare('UPDATE productos SET nombre = ?, unidad = ?, precio_costo = ?, precio_contado = ?, precio_credito = ?, stock = ?, stock_minimo = ? WHERE id = ?');
    $success = $stmt->execute([
        $data['nombre'],
        $data['unidad'],
        $data['precio_costo'],
        $data['precio_contado'],
        $data['precio_credito'],
        $data['stock'],
        $data['stock_minimo'],
        $data['id']
    ]);
    echo json_encode(['success' => $success]);
} elseif ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if ($id) {
        $stmt = $pdo->prepare('DELETE FROM productos WHERE id = ?');
        $success = $stmt->execute([$id]);
        echo json_encode(['success' => $success]);
    } else {
        echo json_encode(['error' => 'Missing ID']);
    }
} else {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['error' => 'Method not allowed']);
}
?>
