<?php
header('Content-Type: application/json');
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    if ($action === 'dashboard') {
        // Dashboard Stats
        $stats = [];
        
        // Capital en inventario (costo * stock)
        $stmt = $pdo->query('SELECT SUM(precio_costo * stock) as capital_inventario FROM productos');
        $stats['capital_inventario'] = $stmt->fetch()['capital_inventario'] ?? 0;
        
        // Dinero en caja (Ventas de contado hoy + abonos de hoy)
        $stmtCaja = $pdo->query('SELECT SUM(total) as ventas_contado FROM ventas WHERE tipo_venta = "contado" AND DATE(fecha) = CURDATE()');
        $ventas_contado = $stmtCaja->fetch()['ventas_contado'] ?? 0;
        
        $stmtAbonos = $pdo->query('SELECT SUM(monto) as total_abonos FROM abonos WHERE DATE(fecha) = CURDATE()');
        $total_abonos = $stmtAbonos->fetch()['total_abonos'] ?? 0;
        
        $stats['dinero_caja'] = $ventas_contado + $total_abonos;
        
        // Plata en la calle (Saldo total pendiente de créditos activos)
        $stmtDeuda = $pdo->query('SELECT SUM(saldo_pendiente) as plata_calle FROM cartera_creditos WHERE estado = "activo"');
        $stats['plata_calle'] = $stmtDeuda->fetch()['plata_calle'] ?? 0;
        
        echo json_encode($stats);
        
    } elseif ($action === 'list') {
        // Listado de deudores
        $sql = "
            SELECT 
                cc.id as credito_id,
                cc.saldo_pendiente,
                cc.fecha_vencimiento,
                cc.estado,
                v.fecha as fecha_venta,
                v.total as total_venta,
                c.nombre as cliente_nombre,
                c.telefono as cliente_telefono
            FROM cartera_creditos cc
            JOIN ventas v ON cc.venta_id = v.id
            JOIN clientes c ON v.cliente_id = c.id
            ORDER BY cc.fecha_vencimiento ASC
        ";
        $stmt = $pdo->query($sql);
        $creditos = $stmt->fetchAll();
        
        // Calcular estado (Verde, Amarillo, Rojo)
        $hoy = new DateTime();
        $hoy->setTime(0,0,0);
        
        foreach ($creditos as &$cred) {
            if ($cred['estado'] === 'pagado') {
                $cred['color'] = 'gray';
                continue;
            }
            
            $vence = new DateTime($cred['fecha_vencimiento']);
            $vence->setTime(0,0,0);
            $interval = $hoy->diff($vence);
            $dias = (int)$interval->format('%R%a'); // Ej: +3, -1
            
            if ($dias < 0) {
                $cred['color'] = 'red'; // Mora
            } elseif ($dias <= 2) {
                $cred['color'] = 'yellow'; // Alerta (0, 1, 2)
            } else {
                $cred['color'] = 'green'; // Al día
            }
        }
        
        echo json_encode($creditos);
    }
} elseif ($method === 'POST') {
    // Registrar abono
    $data = json_decode(file_get_contents('php://input'), true);
    
    $credito_id = $data['credito_id'];
    $monto = $data['monto'];
    
    $pdo->beginTransaction();
    try {
        // Insertar abono
        $stmt = $pdo->prepare('INSERT INTO abonos (credito_id, monto) VALUES (?, ?)');
        $stmt->execute([$credito_id, $monto]);
        
        // Actualizar saldo
        $stmtUpdate = $pdo->prepare('UPDATE cartera_creditos SET saldo_pendiente = saldo_pendiente - ? WHERE id = ?');
        $stmtUpdate->execute([$monto, $credito_id]);
        
        // Verificar si se pagó todo
        $stmtCheck = $pdo->prepare('SELECT saldo_pendiente FROM cartera_creditos WHERE id = ?');
        $stmtCheck->execute([$credito_id]);
        $saldo = $stmtCheck->fetch()['saldo_pendiente'];
        
        if ($saldo <= 0) {
            $pdo->prepare('UPDATE cartera_creditos SET estado = "pagado", saldo_pendiente = 0 WHERE id = ?')->execute([$credito_id]);
        }
        
        $pdo->commit();
        echo json_encode(['success' => true]);
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
