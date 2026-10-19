<?php
/**
 * MINERVOX · Productos e inventario
 *
 * GET    -> todos los productos
 * POST   {nombre, descripcion, unidad, precio_costo, precio_contado, precio_credito, stock, stock_minimo, controlado}
 * PUT    {id, ...los mismos campos}
 * DELETE ?id=N  -> solo si el producto nunca se ha vendido (la llave
 *                  foránea RESTRICT lo impide; para dejar de usarlo hay
 *                  que desactivarlo, cosa que llegará en la Fase 2).
 *
 * FASE 1: se agrega la descripción (`productos.descripcion`), que antes no
 * existía en la base, y se valida todo del lado del servidor.
 * FASE 2: se agrega `controlado` (sustancia controlada: exige cédula).
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
require_once 'utilidades.php';

$metodo = $_SERVER['REQUEST_METHOD'];

/** Valida y normaliza los campos que llegan del formulario de producto. */
function producto_desde_datos(array $datos): array
{
    $nombre = trim((string) ($datos['nombre'] ?? ''));
    if ($nombre === '') {
        minervox_error(400, 'El nombre del producto es obligatorio');
    }

    $unidad = trim((string) ($datos['unidad'] ?? ''));
    $campos = ['precio_costo', 'precio_contado', 'precio_credito', 'stock', 'stock_minimo'];

    foreach ($campos as $campo) {
        if (minervox_numero($datos[$campo] ?? 0, -1) < 0) {
            minervox_error(400, "El campo \"$campo\" no puede ser negativo");
        }
    }

    return [
        'nombre'         => $nombre,
        'descripcion'    => trim((string) ($datos['descripcion'] ?? '')) ?: null,
        'unidad'         => $unidad ?: null,
        'precio_costo'   => minervox_numero($datos['precio_costo'] ?? 0),
        'precio_contado' => minervox_numero($datos['precio_contado'] ?? 0),
        'precio_credito' => minervox_numero($datos['precio_credito'] ?? 0),
        'stock'          => minervox_numero($datos['stock'] ?? 0),
        'stock_minimo'   => minervox_numero($datos['stock_minimo'] ?? 0),
        'controlado'     => !empty($datos['controlado']) ? 1 : 0,
    ];
}

try {
    if ($metodo === 'GET') {
        $stmt = $pdo->query('SELECT * FROM productos ORDER BY nombre');
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);

    } elseif ($metodo === 'POST') {
        $p = producto_desde_datos(minervox_leer_json());

        $stmt = $pdo->prepare(
            'INSERT INTO productos (nombre, descripcion, unidad, precio_costo, precio_contado, precio_credito, stock, stock_minimo, controlado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $p['nombre'], $p['descripcion'], $p['unidad'], $p['precio_costo'],
            $p['precio_contado'], $p['precio_credito'], $p['stock'], $p['stock_minimo'],
            $p['controlado'],
        ]);

        echo json_encode(['success' => true, 'id' => (int) $pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);

    } elseif ($metodo === 'PUT') {
        $datos = minervox_leer_json();
        $id = (int) ($datos['id'] ?? 0);
        if ($id <= 0) {
            minervox_error(400, 'Falta el producto a modificar');
        }

        $p = producto_desde_datos($datos);

        $stmt = $pdo->prepare(
            'UPDATE productos
                SET nombre = ?, descripcion = ?, unidad = ?, precio_costo = ?,
                    precio_contado = ?, precio_credito = ?, stock = ?, stock_minimo = ?,
                    controlado = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $p['nombre'], $p['descripcion'], $p['unidad'], $p['precio_costo'],
            $p['precio_contado'], $p['precio_credito'], $p['stock'], $p['stock_minimo'],
            $p['controlado'],
            $id,
        ]);

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);

    } elseif ($metodo === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            minervox_error(400, 'Falta el producto a eliminar');
        }

        $stmt = $pdo->prepare('DELETE FROM productos WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);

    } else {
        minervox_error(405, 'Método no permitido');
    }
} catch (PDOException $e) {
    // 1451 = el producto ya se vendió y la llave foránea lo protege.
    if ($e->getCode() === '23000') {
        minervox_error(409, 'No se puede borrar: este producto ya tiene ventas registradas. Desactívelo en vez de borrarlo.');
    }
    error_log('[MINERVOX][productos] ' . $e->getMessage());
    minervox_error(500, 'No se pudo procesar el producto');
} catch (Throwable $e) {
    error_log('[MINERVOX][productos] ' . $e->getMessage());
    minervox_error(500, 'No se pudo procesar el producto');
}
