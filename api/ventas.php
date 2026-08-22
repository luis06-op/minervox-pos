<?php
header('Content-Type: application/json');
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Iniciar transacción
    $pdo->beginTransaction();
    
    try {
        $cliente_id = $data['cliente_id'] ?? null;
        
        // 0. Upsert Cliente if it's credit
        if ($data['tipo_venta'] === 'credito' && isset($data['cliente'])) {
            $cli = $data['cliente'];
            if (!empty($cli['cedula'])) {
                // Check if exists
                $stmtCheck = $pdo->prepare('SELECT id FROM clientes WHERE cedula = ?');
                $stmtCheck->execute([$cli['cedula']]);
                $cliente_existente = $stmtCheck->fetch();
                
                if ($cliente_existente) {
                    $cliente_id = $cliente_existente['id'];
                    $stmtUpd = $pdo->prepare('UPDATE clientes SET nombre = ?, telefono = ?, ubicacion = ? WHERE id = ?');
                    $stmtUpd->execute([$cli['nombre'], $cli['telefono'], $cli['ubicacion'], $cliente_id]);
                } else {
                    $stmtIns = $pdo->prepare('INSERT INTO clientes (cedula, nombre, telefono, ubicacion) VALUES (?, ?, ?, ?)');
                    $stmtIns->execute([$cli['cedula'], $cli['nombre'], $cli['telefono'], $cli['ubicacion']]);
                    $cliente_id = $pdo->lastInsertId();
                }
            }
        }

        // 1. Crear venta
        $stmt = $pdo->prepare('INSERT INTO ventas (tipo_venta, total, cliente_id) VALUES (?, ?, ?)');
        $stmt->execute([
            $data['tipo_venta'],
            $data['total'],
            $cliente_id
        ]);
        $venta_id = $pdo->lastInsertId();
        
        // 2. Crear detalles y actualizar stock
        $stmtDetalle = $pdo->prepare('INSERT INTO venta_detalles (venta_id, producto_id, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?)');
        $stmtStock = $pdo->prepare('UPDATE productos SET stock = stock - ? WHERE id = ?');
        
        foreach ($data['detalles'] as $detalle) {
            $stmtDetalle->execute([
                $venta_id,
                $detalle['producto_id'],
                $detalle['cantidad'],
                $detalle['precio_unitario'],
                $detalle['subtotal']
            ]);
            
            $stmtStock->execute([
                $detalle['cantidad'],
                $detalle['producto_id']
            ]);
        }
        
        // 3. Si es a crédito, registrar en cartera
        if ($data['tipo_venta'] === 'credito') {
            $saldo_pendiente = $data['total'] - ($data['abono_inicial'] ?? 0);
            
            $stmtCartera = $pdo->prepare('INSERT INTO cartera_creditos (venta_id, saldo_pendiente, fecha_vencimiento) VALUES (?, ?, ?)');
            $stmtCartera->execute([
                $venta_id,
                $saldo_pendiente,
                $data['fecha_vencimiento']
            ]);
            
            // Si hubo abono inicial, registrarlo
            if (isset($data['abono_inicial']) && $data['abono_inicial'] > 0) {
                $credito_id = $pdo->lastInsertId();
                $stmtAbono = $pdo->prepare('INSERT INTO abonos (credito_id, monto) VALUES (?, ?)');
                $stmtAbono->execute([$credito_id, $data['abono_inicial']]);
                
                // Si el saldo quedó en 0, marcar como pagado
                if ($saldo_pendiente <= 0) {
                    $pdo->prepare('UPDATE cartera_creditos SET estado = "pagado" WHERE id = ?')->execute([$credito_id]);
                }
            }
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'venta_id' => $venta_id]);
    } catch (\Exception $e) {
        $pdo->rollBack();
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['error' => 'Method not allowed']);
}
?>
