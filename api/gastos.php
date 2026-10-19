<?php
/**
 * MINERVOX · Gastos operativos  (FASE 1)
 *
 * GET    ?action=categorias                  -> lista de categorías activas
 * GET    ?action=list&desde=&hasta=&categoria_id=  -> gastos con filtros
 * POST   {categoria_id, descripcion, monto, metodo_pago, fecha?}
 * DELETE ?id=N                               -> elimina un gasto
 *
 * Los gastos con `metodo_pago = efectivo` son los que descuentan del
 * arqueo de caja del día.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
require_once 'utilidades.php';
require_once 'auth.php';

// FASE 5: los gastos son plata que sale del negocio. Solo el administrador.
$usuario = minervox_exigir_admin($pdo);

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    if ($metodo === 'GET') {
        $accion = $_GET['action'] ?? 'list';

        if ($accion === 'categorias') {
            $stmt = $pdo->query('SELECT id, nombre FROM categorias_gasto WHERE activo = 1 ORDER BY nombre');
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($accion !== 'list') {
            minervox_error(400, 'Acción no reconocida');
        }

        // ---- Filtros opcionales: rango de fechas y categoría ----
        $condiciones = [];
        $parametros  = [];

        $desde = minervox_fecha_valida($_GET['desde'] ?? null);
        $hasta = minervox_fecha_valida($_GET['hasta'] ?? null);
        if ($desde) {
            $condiciones[] = 'g.fecha >= ?';
            $parametros[]  = $desde . ' 00:00:00';
        }
        if ($hasta) {
            $condiciones[] = 'g.fecha <= ?';
            $parametros[]  = $hasta . ' 23:59:59';
        }
        if (!empty($_GET['categoria_id'])) {
            $condiciones[] = 'g.categoria_id = ?';
            $parametros[]  = (int) $_GET['categoria_id'];
        }

        $where = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

        $sql = "SELECT g.id, g.fecha, g.descripcion, g.monto, g.metodo_pago,
                       g.categoria_id, c.nombre AS categoria
                  FROM gastos g
                  JOIN categorias_gasto c ON c.id = g.categoria_id
                  $where
                 ORDER BY g.fecha DESC, g.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($parametros);
        $gastos = $stmt->fetchAll();

        $total = 0.0;
        foreach ($gastos as &$g) {
            $g['id']     = (int) $g['id'];
            $g['monto']  = (float) $g['monto'];
            $total      += $g['monto'];
        }
        unset($g);

        echo json_encode([
            'gastos' => $gastos,
            'total'  => round($total, 2),
            'desde'  => $desde,
            'hasta'  => $hasta,
        ], JSON_UNESCAPED_UNICODE);

    } elseif ($metodo === 'POST') {
        $datos = minervox_leer_json();

        $categoria_id = (int) ($datos['categoria_id'] ?? 0);
        $descripcion  = trim((string) ($datos['descripcion'] ?? ''));
        $monto        = minervox_numero($datos['monto'] ?? 0);
        $metodo_pago  = minervox_metodo_pago($datos['metodo_pago'] ?? 'efectivo');

        if ($categoria_id <= 0) {
            minervox_error(400, 'Seleccione una categoría de gasto');
        }
        if ($descripcion === '') {
            minervox_error(400, 'Escriba una descripción del gasto');
        }
        if ($monto <= 0) {
            minervox_error(400, 'El monto del gasto debe ser mayor que cero');
        }

        // La categoría tiene que existir y estar activa.
        $stmt = $pdo->prepare('SELECT id FROM categorias_gasto WHERE id = ? AND activo = 1');
        $stmt->execute([$categoria_id]);
        if (!$stmt->fetch()) {
            minervox_error(400, 'La categoría seleccionada no existe');
        }

        // La fecha es opcional: si no viene, se usa la de hoy (la del servidor).
        $fecha = minervox_fecha_valida($datos['fecha'] ?? null);

        if ($fecha) {
            $stmt = $pdo->prepare(
                'INSERT INTO gastos (categoria_id, descripcion, monto, metodo_pago, fecha, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$categoria_id, $descripcion, $monto, $metodo_pago, $fecha . ' 00:00:00', $usuario['id']]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO gastos (categoria_id, descripcion, monto, metodo_pago, usuario_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$categoria_id, $descripcion, $monto, $metodo_pago, $usuario['id']]);
        }

        echo json_encode([
            'success' => true,
            'id'      => (int) $pdo->lastInsertId(),
        ], JSON_UNESCAPED_UNICODE);

    } elseif ($metodo === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            minervox_error(400, 'Falta el gasto a eliminar');
        }

        $stmt = $pdo->prepare('DELETE FROM gastos WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            minervox_error(404, 'El gasto no existe');
        }

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);

    } else {
        minervox_error(405, 'Método no permitido');
    }
} catch (Throwable $e) {
    error_log('[MINERVOX][gastos] ' . $e->getMessage());
    minervox_error(500, 'No se pudo procesar el gasto');
}
