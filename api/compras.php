<?php
/**
 * MINERVOX · Compras a proveedores  (FASE 1)
 *
 * GET  ?action=list&desde=&hasta=   -> compras registradas
 * GET  ?action=pendientes           -> cuentas por pagar (lo que se debe)
 * POST (cuerpo normal)              -> registra una compra y mete mercancía
 * POST ?action=pago                 -> abona a una compra a crédito
 *
 * Una compra hace tres cosas en la misma transacción:
 *   1. Suma el stock de cada producto.
 *   2. Actualiza `productos.precio_costo` con el costo más reciente.
 *   3. Deja el rastro en el kardex (`movimientos_stock`).
 *
 * Si la compra es a crédito queda como cuenta por pagar (`saldo_pendiente`).
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
require_once 'utilidades.php';
require_once 'auth.php';

// FASE 5: comprar y pagar a proveedores mueve el costo del inventario y la
// plata del negocio. Es cosa del administrador, no del cajero.
$usuario = minervox_exigir_admin($pdo);

$metodo = $_SERVER['REQUEST_METHOD'];
$accion = $_GET['action'] ?? '';

try {
    // ---------------------------------------------------------------
    // LECTURA
    // ---------------------------------------------------------------
    if ($metodo === 'GET') {

        if ($accion === 'pendientes') {
            $sql = "SELECT c.id, c.fecha, c.total, c.saldo_pendiente, c.fecha_vencimiento, c.estado,
                           p.nombre AS proveedor, p.telefono
                      FROM compras c
                      LEFT JOIN proveedores p ON p.id = c.proveedor_id
                     WHERE c.saldo_pendiente > 0
                     ORDER BY c.fecha_vencimiento ASC, c.fecha ASC";
            $filas = $pdo->query($sql)->fetchAll();

            $total = 0.0;
            foreach ($filas as &$f) {
                $f['id']              = (int) $f['id'];
                $f['total']           = (float) $f['total'];
                $f['saldo_pendiente'] = (float) $f['saldo_pendiente'];
                $total               += $f['saldo_pendiente'];
            }
            unset($f);

            echo json_encode(['compras' => $filas, 'total' => round($total, 2)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($accion === 'detalle') {
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) {
                minervox_error(400, 'Falta la compra');
            }
            $stmt = $pdo->prepare(
                'SELECT d.id, d.producto_id, pr.nombre AS producto, d.cantidad, d.costo_unitario, d.subtotal
                   FROM compra_detalles d
                   JOIN productos pr ON pr.id = d.producto_id
                  WHERE d.compra_id = ?'
            );
            $stmt->execute([$id]);
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Listado general con filtro de fechas
        $condiciones = [];
        $parametros  = [];
        $desde = minervox_fecha_valida($_GET['desde'] ?? null);
        $hasta = minervox_fecha_valida($_GET['hasta'] ?? null);
        if ($desde) { $condiciones[] = 'c.fecha >= ?'; $parametros[] = $desde . ' 00:00:00'; }
        if ($hasta) { $condiciones[] = 'c.fecha <= ?'; $parametros[] = $hasta . ' 23:59:59'; }
        $where = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

        $sql = "SELECT c.id, c.fecha, c.total, c.tipo_pago, c.saldo_pendiente, c.estado,
                       c.fecha_vencimiento, c.nota, p.nombre AS proveedor
                  FROM compras c
                  LEFT JOIN proveedores p ON p.id = c.proveedor_id
                  $where
                 ORDER BY c.fecha DESC, c.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($parametros);
        $filas = $stmt->fetchAll();

        foreach ($filas as &$f) {
            $f['id']              = (int) $f['id'];
            $f['total']           = (float) $f['total'];
            $f['saldo_pendiente'] = (float) $f['saldo_pendiente'];
        }
        unset($f);

        echo json_encode($filas, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo !== 'POST') {
        minervox_error(405, 'Método no permitido');
    }

    $datos = minervox_leer_json();

    // ---------------------------------------------------------------
    // ABONO A UNA COMPRA A CRÉDITO
    // ---------------------------------------------------------------
    if ($accion === 'pago') {
        $compra_id   = (int) ($datos['compra_id'] ?? 0);
        $monto       = minervox_numero($datos['monto'] ?? 0);
        $metodo_pago = minervox_metodo_pago($datos['metodo_pago'] ?? 'efectivo');

        if ($compra_id <= 0) {
            minervox_error(400, 'Falta la compra a la que se le abona');
        }
        if ($monto <= 0) {
            minervox_error(400, 'El monto del pago debe ser mayor que cero');
        }

        $pdo->beginTransaction();

        // FOR UPDATE: se bloquea la compra para que dos pagos al mismo
        // tiempo no descuadren el saldo.
        $stmt = $pdo->prepare('SELECT saldo_pendiente FROM compras WHERE id = ? FOR UPDATE');
        $stmt->execute([$compra_id]);
        $compra = $stmt->fetch();

        if (!$compra) {
            throw new InvalidArgumentException('La compra no existe');
        }

        $saldo = round((float) $compra['saldo_pendiente'] - $monto, 2);
        if ($saldo < 0) {
            throw new InvalidArgumentException('El pago es mayor que el saldo que se debe');
        }

        $pdo->prepare('INSERT INTO pagos_proveedores (compra_id, monto, metodo_pago, usuario_id) VALUES (?, ?, ?, ?)')
            ->execute([$compra_id, $monto, $metodo_pago, $usuario['id']]);

        $pdo->prepare('UPDATE compras SET saldo_pendiente = ?, estado = ? WHERE id = ?')
            ->execute([$saldo, $saldo <= 0 ? 'pagada' : 'pendiente', $compra_id]);

        $pdo->commit();

        echo json_encode(['success' => true, 'saldo_pendiente' => $saldo], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------------------------------------------------------------
    // REGISTRO DE UNA COMPRA
    // ---------------------------------------------------------------
    $proveedor_id  = !empty($datos['proveedor_id']) ? (int) $datos['proveedor_id'] : null;
    $tipo_pago     = ($datos['tipo_pago'] ?? 'contado') === 'credito' ? 'credito' : 'contado';
    $nota          = trim((string) ($datos['nota'] ?? ''));
    $detalles      = $datos['detalles'] ?? null;

    if (!is_array($detalles) || count($detalles) === 0) {
        minervox_error(400, 'La compra no tiene productos');
    }

    $fecha_vencimiento = null;
    if ($tipo_pago === 'credito') {
        $fecha_vencimiento = minervox_fecha_valida($datos['fecha_vencimiento'] ?? null);
        if (!$fecha_vencimiento) {
            minervox_error(400, 'Una compra a crédito necesita fecha de vencimiento');
        }
    }

    $pdo->beginTransaction();

    try {
        if ($proveedor_id !== null) {
            $stmt = $pdo->prepare('SELECT id FROM proveedores WHERE id = ?');
            $stmt->execute([$proveedor_id]);
            if (!$stmt->fetch()) {
                throw new InvalidArgumentException('El proveedor indicado no existe');
            }
        }

        // Validar líneas y calcular el total en el servidor.
        $stmtProd = $pdo->prepare('SELECT id, nombre, stock FROM productos WHERE id = ? FOR UPDATE');
        $lineas = [];
        $total  = 0.0;

        foreach ($detalles as $detalle) {
            $producto_id = (int) ($detalle['producto_id'] ?? 0);
            $cantidad    = minervox_numero($detalle['cantidad'] ?? 0);
            $costo       = minervox_numero($detalle['costo_unitario'] ?? 0);

            if ($producto_id <= 0) {
                throw new InvalidArgumentException('Hay un producto inválido en la compra');
            }
            if ($cantidad <= 0) {
                throw new InvalidArgumentException('Todas las cantidades deben ser mayores que cero');
            }
            if ($costo < 0) {
                throw new InvalidArgumentException('El costo no puede ser negativo');
            }

            $stmtProd->execute([$producto_id]);
            $prod = $stmtProd->fetch();
            if (!$prod) {
                throw new InvalidArgumentException("El producto $producto_id no existe");
            }

            $subtotal = round($cantidad * $costo, 2);
            $total   += $subtotal;

            $lineas[] = [
                'producto_id'    => $producto_id,
                'nombre'         => $prod['nombre'],
                'cantidad'       => $cantidad,
                'costo_unitario' => $costo,
                'subtotal'       => $subtotal,
                'stock_actual'   => (float) $prod['stock'],
            ];
        }

        $total = round($total, 2);

        // Cabecera
        $saldo = $tipo_pago === 'credito' ? $total : 0.0;
        $pdo->prepare(
            'INSERT INTO compras (proveedor_id, total, tipo_pago, saldo_pendiente, fecha_vencimiento, estado, nota, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $proveedor_id,
            $total,
            $tipo_pago,
            $saldo,
            $fecha_vencimiento,
            $saldo > 0 ? 'pendiente' : 'pagada',
            $nota !== '' ? $nota : null,
            $usuario['id'],
        ]);
        $compra_id = (int) $pdo->lastInsertId();

        // Detalle + entrada de stock + costo más reciente + kardex
        $stmtDetalle = $pdo->prepare(
            'INSERT INTO compra_detalles (compra_id, producto_id, cantidad, costo_unitario, subtotal)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmtStock = $pdo->prepare('UPDATE productos SET stock = stock + ?, precio_costo = ? WHERE id = ?');
        $stmtMov   = $pdo->prepare(
            'INSERT INTO movimientos_stock (producto_id, tipo, cantidad, stock_resultante, referencia_id, nota, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($lineas as $l) {
            $stmtDetalle->execute([$compra_id, $l['producto_id'], $l['cantidad'], $l['costo_unitario'], $l['subtotal']]);
            $stmtStock->execute([$l['cantidad'], $l['costo_unitario'], $l['producto_id']]);
            $stmtMov->execute([
                $l['producto_id'],
                'compra',
                $l['cantidad'],
                round($l['stock_actual'] + $l['cantidad'], 2),
                $compra_id,
                'Entrada por compra',
                $usuario['id'],
            ]);
        }

        $pdo->commit();

        echo json_encode([
            'success'         => true,
            'compra_id'       => $compra_id,
            'total'           => $total,
            'saldo_pendiente' => $saldo,
        ], JSON_UNESCAPED_UNICODE);

    } catch (InvalidArgumentException $e) {
        $pdo->rollBack();
        minervox_error(400, $e->getMessage());
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Throwable $e) {
    error_log('[MINERVOX][compras] ' . $e->getMessage());
    minervox_error(500, 'No se pudo procesar la compra');
}
