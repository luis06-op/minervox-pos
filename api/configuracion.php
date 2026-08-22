<?php
header('Content-Type: application/json');
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT * FROM configuracion LIMIT 1');
    $config = $stmt->fetch();
    echo json_encode($config);
} elseif ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $stmt = $pdo->prepare('UPDATE configuracion SET 
        nombre_comercial = ?, 
        razon_social = ?, 
        nit = ?, 
        direccion = ?, 
        telefonos = ?, 
        plazo_1 = ?, 
        plazo_2 = ?, 
        recargo_credito = ?, 
        texto_pagare = ? 
        WHERE id = 1');
    
    $success = $stmt->execute([
        $data['nombre_comercial'],
        $data['razon_social'],
        $data['nit'],
        $data['direccion'],
        $data['telefonos'],
        $data['plazo_1'],
        $data['plazo_2'],
        $data['recargo_credito'],
        $data['texto_pagare']
    ]);
    
    echo json_encode(['success' => $success]);
} else {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['error' => 'Method not allowed']);
}
?>
