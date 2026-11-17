<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utilidades.php';

/**
 * Valida las líneas de la venta y normaliza cantidades y precios.
 * Los subtotales y el total se recalculan siempre en el servidor.
 */
function normalizar_detalles(array $datos): array
{
    $detalles = $datos['detalles'] ?? null;

    if (!is_array($detalles) || $detalles === []) {
        throw new ErrorApi('La venta debe incluir al menos un producto.');
    }

    if (count($detalles) > 200) {
        throw new ErrorApi('La venta supera el máximo de 200 líneas.');
    }

    $normalizados = [];

    foreach (array_values($detalles) as $indice => $detalle) {
        if (!is_array($detalle)) {
            throw new ErrorApi('Una de las líneas de la venta no es válida.');
        }

        $etiqueta = 'línea ' . ($indice + 1);

        $normalizados[] = [
            'producto_id'     => entero_requerido($detalle, 'producto_id', 'Producto de la ' . $etiqueta),
            'cantidad'        => round(numero_requerido($detalle, 'cantidad', 'Cantidad de la ' . $etiqueta, 0.001, 999999999.999), 3),
            'precio_unitario' => round(numero_requerido($detalle, 'precio_unitario', 'Precio unitario de la ' . $etiqueta, 0.01, 9999999999.99), 2),
        ];
    }

    return $normalizados;
}

/**
 * Resuelve el cliente de la venta: lo crea o actualiza por cédula, o usa
 * el id recibido. En ventas a crédito el cliente es obligatorio.
 */
function resolver_cliente(PDO $pdo, array $datos, bool $esCredito): ?int
{
    $clienteDatos = $datos['cliente'] ?? null;

    if ($clienteDatos !== null && !is_array($clienteDatos)) {
        throw new ErrorApi('Los datos del cliente no son válidos.');
    }

    $clienteId = null;
    if (isset($datos['cliente_id']) && $datos['cliente_id'] !== null && $datos['cliente_id'] !== '') {
        $clienteId = entero_requerido($datos, 'cliente_id', 'Cliente');
    }

    $cedula = '';
    $nombre = '';
    $telefono = '';
    $ubicacion = '';

    if (is_array($clienteDatos)) {
        $cedula = texto_opcional($clienteDatos, 'cedula', 'Cédula del cliente', 40);
        $nombre = texto_opcional($clienteDatos, 'nombre', 'Nombre del cliente', 150);
        $telefono = texto_opcional($clienteDatos, 'telefono', 'Teléfono del cliente', 40);
        $ubicacion = texto_opcional($clienteDatos, 'ubicacion', 'Ubicación del cliente', 150);
    }

    if ($cedula !== '') {
        if ($nombre === '') {
            throw new ErrorApi('El nombre del cliente es obligatorio.');
        }

        $stmt = $pdo->prepare('SELECT id FROM clientes WHERE cedula = ?');
        $stmt->execute([$cedula]);
        $existente = $stmt->fetch();

        if ($existente) {
            $stmt = $pdo->prepare('UPDATE clientes SET nombre = ?, telefono = ?, ubicacion = ? WHERE id = ?');
            $stmt->execute([$nombre, $telefono, $ubicacion, (int) $existente['id']]);

            return (int) $existente['id'];
        }

        $stmt = $pdo->prepare('INSERT INTO clientes (cedula, nombre, telefono, ubicacion) VALUES (?, ?, ?, ?)');
        $stmt->execute([$cedula, $nombre, $telefono, $ubicacion]);

        return (int) $pdo->lastInsertId();
    }

    if ($clienteId !== null) {
        $stmt = $pdo->prepare('SELECT id FROM clientes WHERE id = ?');
        $stmt->execute([$clienteId]);

        if (!$stmt->fetch()) {
            throw new ErrorApi('El cliente indicado no existe.');
        }

        return $clienteId;
    }

    if ($esCredito) {
        throw new ErrorApi('Para una venta a crédito indica la cédula y el nombre del cliente.');
    }

    return null;
}

manejar_api(function () use ($pdo): void {
    requerir_metodo('POST');

    $datos = leer_json();
    $tipoVenta = strtolower(trim((string) ($datos['tipo_venta'] ?? '')));

    if (!in_array($tipoVenta, ['contado', 'credito'], true)) {
        throw new ErrorApi('El tipo de venta debe ser «contado» o «credito».');
    }

    $esCredito = $tipoVenta === 'credito';
    $detalles = normalizar_detalles($datos);

    $fechaVencimiento = null;
    $abonoInicial = 0.0;

    if ($esCredito) {
        $fechaVencimiento = fecha_requerida($datos, 'fecha_vencimiento', 'Fecha de vencimiento');

        if ($fechaVencimiento < fecha_hoy()) {
            throw new ErrorApi('La fecha de vencimiento no puede ser anterior a hoy.');
        }

        $abonoInicial = round(numero_opcional($datos, 'abono_inicial', 'Abono inicial', 0.0, 0.0, 9999999999.99), 2);
    }

    $pdo->beginTransaction();

    try {
        $clienteId = resolver_cliente($pdo, $datos, $esCredito);

        // Se bloquean los productos para evitar sobreventa por peticiones simultáneas.
        $stmtProducto = $pdo->prepare(
            'SELECT id, nombre, precio_costo, stock, activo FROM productos WHERE id = ? FOR UPDATE'
        );

        $total = 0.0;
        $lineas = [];

        foreach ($detalles as $detalle) {
            $stmtProducto->execute([$detalle['producto_id']]);
            $producto = $stmtProducto->fetch();

            if (!$producto || (int) $producto['activo'] !== 1) {
                throw new ErrorApi('Producto no disponible (id ' . $detalle['producto_id'] . ').');
            }

            $stockDisponible = (float) $producto['stock'];

            if ($stockDisponible < $detalle['cantidad']) {
                throw new ErrorApi(sprintf(
                    'Stock insuficiente para «%s»: disponible %s y solicitado %s.',
                    $producto['nombre'],
                    numero_a_texto($stockDisponible),
                    numero_a_texto($detalle['cantidad'])
                ));
            }

            $subtotal = round($detalle['cantidad'] * $detalle['precio_unitario'], 2);
            $total += $subtotal;

            $lineas[] = [
                'producto_id'     => $detalle['producto_id'],
                'cantidad'        => $detalle['cantidad'],
                'precio_unitario' => $detalle['precio_unitario'],
                'costo_unitario'  => (float) $producto['precio_costo'],
                'subtotal'        => $subtotal,
            ];
        }

        $total = round($total, 2);

        if ($total <= 0) {
            throw new ErrorApi('El total de la venta debe ser mayor que cero.');
        }

        if ($total > 9999999999.99) {
            throw new ErrorApi('El total de la venta excede el máximo permitido.');
        }

        if ($abonoInicial > $total) {
            throw new ErrorApi('El abono inicial no puede superar el total de la venta.');
        }

        $stmt = $pdo->prepare('INSERT INTO ventas (tipo_venta, total, cliente_id) VALUES (?, ?, ?)');
        $stmt->execute([$tipoVenta, $total, $clienteId]);
        $ventaId = (int) $pdo->lastInsertId();

        $stmtDetalle = $pdo->prepare(
            'INSERT INTO venta_detalles
                (venta_id, producto_id, cantidad, precio_unitario, costo_unitario, subtotal)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmtStock = $pdo->prepare('UPDATE productos SET stock = stock - ? WHERE id = ? AND stock >= ?');

        foreach ($lineas as $linea) {
            $stmtDetalle->execute([
                $ventaId,
                $linea['producto_id'],
                $linea['cantidad'],
                $linea['precio_unitario'],
                $linea['costo_unitario'],
                $linea['subtotal'],
            ]);

            $stmtStock->execute([$linea['cantidad'], $linea['producto_id'], $linea['cantidad']]);

            if ($stmtStock->rowCount() !== 1) {
                throw new ErrorApi('No se pudo descontar el stock. Intenta registrar la venta de nuevo.');
            }
        }

        $saldoPendiente = null;

        if ($esCredito) {
            $saldoPendiente = round($total - $abonoInicial, 2);
            $estado = $saldoPendiente <= 0 ? 'pagado' : 'activo';

            $stmt = $pdo->prepare(
                'INSERT INTO cartera_creditos (venta_id, saldo_pendiente, fecha_vencimiento, estado)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$ventaId, $saldoPendiente, $fechaVencimiento, $estado]);
            $creditoId = (int) $pdo->lastInsertId();

            if ($abonoInicial > 0) {
                $stmt = $pdo->prepare('INSERT INTO abonos (credito_id, monto) VALUES (?, ?)');
                $stmt->execute([$creditoId, $abonoInicial]);
            }
        }

        $pdo->commit();

        responder([
            'success'         => true,
            'venta_id'        => $ventaId,
            'cliente_id'      => $clienteId,
            'total'           => $total,
            'saldo_pendiente' => $saldoPendiente,
        ], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
});
