<?php
header('Content-Type: application/json');
require_once 'db.php';
require_once 'utilidades.php';

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
    // Registrar abono (el minero viene a pagar su fiao)
    $data = minervox_leer_json();

    $credito_id  = (int) ($data['credito_id'] ?? 0);
    $monto       = minervox_numero($data['monto'] ?? 0);
    $metodo_pago = minervox_metodo_pago($data['metodo_pago'] ?? 'efectivo');

    if ($credito_id <= 0) {
        minervox_error(400, 'Falta el crédito al que se le abona');
    }
    if ($monto <= 0) {
        minervox_error(400, 'El monto del abono debe ser mayor que cero');
    }

    $pdo->beginTransaction();
    try {
        // Se bloquea el crédito para que dos abonos simultáneos no descuadren el saldo.
        $stmtCheck = $pdo->prepare('SELECT saldo_pendiente, estado FROM cartera_creditos WHERE id = ? FOR UPDATE');
        $stmtCheck->execute([$credito_id]);
        $credito = $stmtCheck->fetch();

        if (!$credito) {
            throw new InvalidArgumentException('El crédito no existe');
        }
        if ($credito['estado'] === 'pagado') {
            throw new InvalidArgumentException('Ese fiao ya está pagado');
        }

        $saldo = round((float) $credito['saldo_pendiente'] - $monto, 2);
        if ($saldo < 0) {
            throw new InvalidArgumentException('El abono es mayor que la deuda pendiente');
        }

        $pdo->prepare('INSERT INTO abonos (credito_id, monto, metodo_pago) VALUES (?, ?, ?)')
            ->execute([$credito_id, $monto, $metodo_pago]);

        $pdo->prepare('UPDATE cartera_creditos SET saldo_pendiente = ?, estado = ? WHERE id = ?')
            ->execute([$saldo, $saldo <= 0 ? 'pagado' : 'activo', $credito_id]);

        $pdo->commit();

        echo json_encode([
            'success'         => true,
            'saldo_pendiente' => $saldo,
            'metodo_pago'     => $metodo_pago,
        ], JSON_UNESCAPED_UNICODE);

    } catch (InvalidArgumentException $e) {
        $pdo->rollBack();
        minervox_error(400, $e->getMessage());
    } catch (\Throwable $e) {
        $pdo->rollBack();
        error_log('[MINERVOX][cartera] ' . $e->getMessage());
        minervox_error(500, 'No se pudo registrar el abono');
    }
} else {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['error' => 'Method not allowed']);
}
?>
