<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utilidades.php';

/**
 * Normaliza tipos numéricos para que el frontend reciba números y no cadenas.
 */
function producto_publico(array $producto): array
{
    $producto['id'] = (int) $producto['id'];
    $producto['activo'] = (int) $producto['activo'];

    foreach (['precio_costo', 'precio_contado', 'precio_credito', 'stock', 'stock_minimo'] as $campo) {
        $producto[$campo] = (float) $producto[$campo];
    }

    return $producto;
}

/**
 * Valida y normaliza los campos de un producto.
 * En modo "completo" (PUT) todos los valores son obligatorios.
 */
function datos_producto(array $datos, bool $completo): array
{
    $leerNumero = static function (string $campo, string $etiqueta, float $maximo, int $decimales) use ($datos, $completo): float {
        $valor = $completo
            ? numero_requerido($datos, $campo, $etiqueta, 0.0, $maximo)
            : numero_opcional($datos, $campo, $etiqueta, 0.0, 0.0, $maximo);

        return round($valor, $decimales);
    };

    return [
        'nombre'         => texto_requerido($datos, 'nombre', 'Nombre', 150),
        'unidad'         => texto_requerido($datos, 'unidad', 'Unidad', 20),
        'precio_costo'   => $leerNumero('precio_costo', 'Precio de costo', 9999999999.99, 2),
        'precio_contado' => $leerNumero('precio_contado', 'Precio de contado', 9999999999.99, 2),
        'precio_credito' => $leerNumero('precio_credito', 'Precio de crédito', 9999999999.99, 2),
        'stock'          => $leerNumero('stock', 'Stock', 999999999.999, 3),
        'stock_minimo'   => $leerNumero('stock_minimo', 'Stock mínimo', 999999999.999, 3),
    ];
}

manejar_api(function () use ($pdo): void {
    $metodo = requerir_metodo('GET', 'POST', 'PUT', 'DELETE');

    if ($metodo === 'GET') {
        if (isset($_GET['id'])) {
            $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($id === false) {
                throw new ErrorApi('El identificador del producto no es válido.', 400);
            }

            $stmt = $pdo->prepare('SELECT * FROM productos WHERE id = ? AND activo = 1');
            $stmt->execute([$id]);
            $producto = $stmt->fetch();

            if (!$producto) {
                throw new ErrorApi('Producto no encontrado.', 404);
            }

            responder(producto_publico($producto));
        }

        $stmt = $pdo->query('SELECT * FROM productos WHERE activo = 1 ORDER BY nombre ASC');
        responder(array_map('producto_publico', $stmt->fetchAll()));
    }

    if ($metodo === 'DELETE') {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            throw new ErrorApi('Indica el id del producto a eliminar.', 400);
        }

        $stmt = $pdo->prepare('SELECT id FROM productos WHERE id = ? AND activo = 1');
        $stmt->execute([$id]);

        if (!$stmt->fetch()) {
            throw new ErrorApi('Producto no encontrado.', 404);
        }

        // Baja lógica: se conserva el historial de ventas.
        $pdo->prepare('UPDATE productos SET activo = 0 WHERE id = ?')->execute([$id]);

        responder(['success' => true]);
    }

    $datos = leer_json();

    if ($metodo === 'POST') {
        $producto = datos_producto($datos, false);

        $stmt = $pdo->prepare(
            'INSERT INTO productos (nombre, unidad, precio_costo, precio_contado, precio_credito, stock, stock_minimo)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array_values($producto));

        responder(['success' => true, 'id' => (int) $pdo->lastInsertId()], 201);
    }

    // PUT
    $id = entero_requerido($datos, 'id', 'Identificador del producto');
    $producto = datos_producto($datos, true);

    $stmt = $pdo->prepare('SELECT id FROM productos WHERE id = ? AND activo = 1');
    $stmt->execute([$id]);

    if (!$stmt->fetch()) {
        throw new ErrorApi('Producto no encontrado.', 404);
    }

    $stmt = $pdo->prepare(
        'UPDATE productos
            SET nombre = ?, unidad = ?, precio_costo = ?, precio_contado = ?,
                precio_credito = ?, stock = ?, stock_minimo = ?
          WHERE id = ? AND activo = 1'
    );
    $stmt->execute([...array_values($producto), $id]);

    responder(['success' => true, 'id' => $id]);
});
