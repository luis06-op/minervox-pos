<?php
/**
 * MINERVOX · Registro de ventas (POST)
 *
 * FASE 1 — cambio clave: se congela el costo de cada producto en
 * `venta_detalles.costo_unitario`. Sin esto, la vista `v_utilidad_ventas`
 * daba costo 0 y una utilidad falsa del 100%.
 *
 * Reglas que se cumplen aquí:
 *  - TODO el dinero se calcula en el servidor. El total que manda el
 *    frontend se ignora.
 *  - El cajero SÍ puede editar el precio a mano (el carrito lo permite),
 *    así que se acepta, pero se calcula el subtotal en el servidor y se
 *    avisa cuando el precio se aparta de la lista.
 *  - El costo se lee de la base, nunca del frontend.
 *  - Todo va en una transacción: si algo falla, no se guarda nada.
 *
 * Contrato de respuesta (se mantiene compatible con el frontend):
 *   { success: true, venta_id: N }   ·  o  { success: false, error: "..." }
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

$METODOS_PAGO = ['efectivo', 'transferencia', 'otro'];
$TIPOS_VENTA  = ['contado', 'credito'];

/**
 * Responde con error y termina la ejecución.
 */
function responder_error(int $codigo, string $mensaje): void
{
    http_response_code($codigo);
    echo json_encode(['success' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder_error(405, 'Método no permitido');
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    responder_error(400, 'Los datos de la venta no son válidos');
}

// ---------------------------------------------------------------
// 1. Validación de la entrada
// ---------------------------------------------------------------
$tipo_venta = (string) ($data['tipo_venta'] ?? '');
if (!in_array($tipo_venta, $TIPOS_VENTA, true)) {
    responder_error(400, 'El tipo de venta debe ser "contado" o "credito"');
}

$metodo_pago = (string) ($data['metodo_pago'] ?? 'efectivo');
if (!in_array($metodo_pago, $METODOS_PAGO, true)) {
    $metodo_pago = 'efectivo';
}

$detalles = $data['detalles'] ?? null;
if (!is_array($detalles) || count($detalles) === 0) {
    responder_error(400, 'La venta no tiene productos');
}

$abono_inicial = (float) ($data['abono_inicial'] ?? 0);
if ($abono_inicial < 0) {
    responder_error(400, 'El abono inicial no puede ser negativo');
}

$fecha_vencimiento = null;
if ($tipo_venta === 'credito') {
    $fecha_vencimiento = (string) ($data['fecha_vencimiento'] ?? '');
    $valida = DateTime::createFromFormat('Y-m-d', $fecha_vencimiento);
    if (!$valida || $valida->format('Y-m-d') !== $fecha_vencimiento) {
        responder_error(400, 'La fecha de vencimiento del fiao no es válida');
    }
}

$pdo->beginTransaction();

try {
    // -----------------------------------------------------------
    // 2. Cliente (en fiao siempre se registra la cédula: es el
    //    requisito legal para poder cobrar después).
    // -----------------------------------------------------------
    $cliente_id = null;

    if ($tipo_venta === 'credito') {
        $cli     = is_array($data['cliente'] ?? null) ? $data['cliente'] : [];
        $cedula  = trim((string) ($cli['cedula'] ?? ''));
        $nombre  = trim((string) ($cli['nombre'] ?? ''));

        if ($cedula === '' || $nombre === '') {
            throw new InvalidArgumentException('Para fiar se necesita la cédula y el nombre del cliente');
        }

        $telefono  = trim((string) ($cli['telefono'] ?? ''));
        $ubicacion = trim((string) ($cli['ubicacion'] ?? ''));

        $stmtCli = $pdo->prepare('SELECT id FROM clientes WHERE cedula = ? LIMIT 1');
        $stmtCli->execute([$cedula]);
        $existente = $stmtCli->fetch();

        if ($existente) {
            $cliente_id = (int) $existente['id'];
            $pdo->prepare('UPDATE clientes SET nombre = ?, telefono = ?, ubicacion = ? WHERE id = ?')
                ->execute([$nombre, $telefono, $ubicacion, $cliente_id]);
        } else {
            $pdo->prepare('INSERT INTO clientes (cedula, nombre, telefono, ubicacion) VALUES (?, ?, ?, ?)')
                ->execute([$cedula, $nombre, $telefono, $ubicacion]);
            $cliente_id = (int) $pdo->lastInsertId();
        }
    }

    // -----------------------------------------------------------
    // 3. Validar cada línea, congelar el costo y calcular el total
    //    en el servidor. FOR UPDATE evita que dos cajas vendan la
    //    última unidad al mismo tiempo.
    // -----------------------------------------------------------
    $stmtProd = $pdo->prepare(
        'SELECT id, nombre, precio_contado, precio_credito, precio_costo, stock, activo, controlado
           FROM productos WHERE id = ? FOR UPDATE'
    );

    $lineas       = [];
    $total        = 0.0;
    $advertencias = [];

    foreach ($detalles as $detalle) {
        $producto_id = (int) ($detalle['producto_id'] ?? 0);
        $cantidad    = (float) ($detalle['cantidad'] ?? 0);

        if ($producto_id <= 0) {
            throw new InvalidArgumentException('Hay un producto inválido en la venta');
        }
        if ($cantidad <= 0) {
            throw new InvalidArgumentException('Todas las cantidades deben ser mayores que cero');
        }

        $stmtProd->execute([$producto_id]);
        $prod = $stmtProd->fetch();

        if (!$prod) {
            throw new InvalidArgumentException("El producto $producto_id no existe");
        }
        if ((int) $prod['activo'] !== 1) {
            throw new InvalidArgumentException("El producto {$prod['nombre']} está inactivo");
        }

        // El precio lo define el cajero (puede negociar), pero el
        // subtotal y el total los calcula el servidor.
        $precio_lista   = (float) ($tipo_venta === 'contado' ? $prod['precio_contado'] : $prod['precio_credito']);
        $precio_vendido = (float) ($detalle['precio_unitario'] ?? $precio_lista);

        if ($precio_vendido < 0) {
            throw new InvalidArgumentException("El precio de {$prod['nombre']} no puede ser negativo");
        }

        // Auditoría: si el cajero se apartó de la lista, dejar constancia.
        if ($precio_lista > 0 && abs($precio_vendido - $precio_lista) > 0.01) {
            $advertencias[] = sprintf(
                'Se vendió %s a %s (precio de lista: %s)',
                $prod['nombre'],
                number_format($precio_vendido, 0, ',', '.'),
                number_format($precio_lista, 0, ',', '.')
            );
        }

        $subtotal = round($cantidad * $precio_vendido, 2);
        $total   += $subtotal;

        $lineas[] = [
            'producto_id'     => $producto_id,
            'nombre'          => $prod['nombre'],
            'cantidad'        => $cantidad,
            'precio_unitario' => $precio_vendido,
            'costo_unitario'  => (float) $prod['precio_costo'],   // <- el costo se congela aquí
            'subtotal'        => $subtotal,
            'stock_actual'    => (float) $prod['stock'],
            'controlado'      => (int) $prod['controlado'],
        ];
    }

    $total = round($total, 2);

    if ($abono_inicial > $total) {
        throw new InvalidArgumentException('El abono inicial no puede ser mayor que el total de la venta');
    }

    // -----------------------------------------------------------
    // 4. Cabecera de la venta
    // -----------------------------------------------------------
    $pdo->prepare('INSERT INTO ventas (tipo_venta, total, cliente_id, metodo_pago) VALUES (?, ?, ?, ?)')
        ->execute([$tipo_venta, $total, $cliente_id, $metodo_pago]);

    $venta_id = (int) $pdo->lastInsertId();

    // -----------------------------------------------------------
    // 5. Detalle, descuento de stock y rastro en el kardex
    // -----------------------------------------------------------
    $stmtDetalle = $pdo->prepare(
        'INSERT INTO venta_detalles (venta_id, producto_id, cantidad, precio_unitario, costo_unitario, subtotal)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmtStock = $pdo->prepare('UPDATE productos SET stock = stock - ? WHERE id = ?');
    $stmtMov   = $pdo->prepare(
        'INSERT INTO movimientos_stock (producto_id, tipo, cantidad, stock_resultante, referencia_id, nota)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    foreach ($lineas as $l) {
        $stmtDetalle->execute([
            $venta_id,
            $l['producto_id'],
            $l['cantidad'],
            $l['precio_unitario'],
            $l['costo_unitario'],
            $l['subtotal'],
        ]);

        $stmtStock->execute([$l['cantidad'], $l['producto_id']]);

        $stock_resultante = round($l['stock_actual'] - $l['cantidad'], 2);

        $stmtMov->execute([
            $l['producto_id'],
            'venta',
            -1 * $l['cantidad'],   // negativo porque sale del inventario
            $stock_resultante,
            $venta_id,
            'Venta ' . $tipo_venta,
        ]);

        // Aviso (no bloquea la venta): el mostrador a veces vende con el
        // conteo atrasado y prefiere cuadrar el stock después.
        if ($stock_resultante < 0) {
            $advertencias[] = "El stock de {$l['nombre']} quedó en negativo";
        }
    }

    // -----------------------------------------------------------
    // 6. Cartera cuando es fiao
    // -----------------------------------------------------------
    if ($tipo_venta === 'credito') {
        $saldo_pendiente = round($total - $abono_inicial, 2);
        $estado          = $saldo_pendiente <= 0 ? 'pagado' : 'activo';

        $pdo->prepare(
            'INSERT INTO cartera_creditos (venta_id, saldo_pendiente, fecha_vencimiento, estado)
             VALUES (?, ?, ?, ?)'
        )->execute([$venta_id, $saldo_pendiente, $fecha_vencimiento, $estado]);

        $credito_id = (int) $pdo->lastInsertId();

        if ($abono_inicial > 0) {
            $pdo->prepare('INSERT INTO abonos (credito_id, monto, metodo_pago) VALUES (?, ?, ?)')
                ->execute([$credito_id, $abono_inicial, $metodo_pago]);
        }
    }

    $pdo->commit();

    $utilidad = 0.0;
    foreach ($lineas as $l) {
        $utilidad += $l['subtotal'] - ($l['cantidad'] * $l['costo_unitario']);
    }

    echo json_encode([
        'success'      => true,
        'venta_id'     => $venta_id,
        'total'        => $total,
        'utilidad'     => round($utilidad, 2),
        'advertencias' => $advertencias,
    ], JSON_UNESCAPED_UNICODE);

} catch (InvalidArgumentException $e) {
    // Error de datos: se puede mostrar al usuario tal cual.
    $pdo->rollBack();
    responder_error(400, $e->getMessage());

} catch (Throwable $e) {
    // Error inesperado: no se filtra el detalle al frontend.
    $pdo->rollBack();
    error_log('[MINERVOX][ventas] ' . $e->getMessage());
    responder_error(500, 'No se pudo registrar la venta. Intente de nuevo.');
}
