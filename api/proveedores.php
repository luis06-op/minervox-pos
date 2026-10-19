<?php
/**
 * MINERVOX · Proveedores  (FASE 1)
 *
 * GET    ?todos=1   -> todos; sin el parámetro, solo los activos
 * GET    ?id=N      -> un proveedor
 * POST   {nombre, nit, telefono, ubicacion}
 * PUT    {id, nombre, nit, telefono, ubicacion, activo}
 *
 * No se borran proveedores: se desactivan (hay compras que los referencian
 * y la llave foránea lo impide, para no perder el historial).
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
require_once 'utilidades.php';

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    if ($metodo === 'GET') {
        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare('SELECT * FROM proveedores WHERE id = ?');
            $stmt->execute([(int) $_GET['id']]);
            $proveedor = $stmt->fetch();
            if (!$proveedor) {
                minervox_error(404, 'El proveedor no existe');
            }
            echo json_encode($proveedor, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $soloActivos = empty($_GET['todos']);
        $sql = 'SELECT * FROM proveedores' . ($soloActivos ? ' WHERE activo = 1' : '') . ' ORDER BY nombre';
        echo json_encode($pdo->query($sql)->fetchAll(), JSON_UNESCAPED_UNICODE);

    } elseif ($metodo === 'POST') {
        $datos = minervox_leer_json();
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        if ($nombre === '') {
            minervox_error(400, 'El nombre del proveedor es obligatorio');
        }

        $stmt = $pdo->prepare('INSERT INTO proveedores (nombre, nit, telefono, ubicacion) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $nombre,
            trim((string) ($datos['nit'] ?? '')),
            trim((string) ($datos['telefono'] ?? '')),
            trim((string) ($datos['ubicacion'] ?? '')),
        ]);

        echo json_encode(['success' => true, 'id' => (int) $pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);

    } elseif ($metodo === 'PUT') {
        $datos = minervox_leer_json();
        $id = (int) ($datos['id'] ?? 0);
        $nombre = trim((string) ($datos['nombre'] ?? ''));

        if ($id <= 0 || $nombre === '') {
            minervox_error(400, 'Faltan datos del proveedor');
        }

        $stmt = $pdo->prepare(
            'UPDATE proveedores SET nombre = ?, nit = ?, telefono = ?, ubicacion = ?, activo = ? WHERE id = ?'
        );
        $stmt->execute([
            $nombre,
            trim((string) ($datos['nit'] ?? '')),
            trim((string) ($datos['telefono'] ?? '')),
            trim((string) ($datos['ubicacion'] ?? '')),
            !empty($datos['activo']) ? 1 : 0,
            $id,
        ]);

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);

    } else {
        minervox_error(405, 'Método no permitido');
    }
} catch (Throwable $e) {
    error_log('[MINERVOX][proveedores] ' . $e->getMessage());
    minervox_error(500, 'No se pudo procesar el proveedor');
}
