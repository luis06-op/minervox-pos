<?php
/**
 * MINERVOX · Usuarios del sistema  (FASE 5)
 *
 * SOLO EL ADMINISTRADOR puede usar este endpoint. El cajero vende y cobra,
 * pero no crea usuarios ni cambia permisos.
 *
 *   GET    -> lista de usuarios (sin la clave, que no se puede leer)
 *   POST   -> {nombre, usuario, clave, clave2, rol}          crea
 *   PUT    -> {id, nombre, usuario, rol, activo, clave?, clave2?} cambia
 *
 * NO hay DELETE a propósito. Un cajero que se va se DESACTIVA, no se borra:
 * así las ventas, abonos y cierres que hizo siguen teniendo su nombre y el
 * arqueo de esos días se puede entender. Borrarlo dejaría los recibos
 * huérfanos.
 *
 * Dos candados para no dejarse afuera uno mismo:
 *   · nadie puede desactivarse ni quitarse el rol de administrador;
 *   · el sistema no deja quedar sin ningún administrador activo.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
require_once 'utilidades.php';
require_once 'auth.php';

$metodo  = $_SERVER['REQUEST_METHOD'];
$yo      = minervox_exigir_admin($pdo);
$usuario = $_GET['action'] ?? '';

try {
    // -------------------------------------------------------------
    // LISTAR
    // -------------------------------------------------------------
    if ($metodo === 'GET') {
        $stmt = $pdo->query(
            'SELECT id, nombre, usuario, rol, activo, creado_en, ultimo_acceso
               FROM usuarios
              ORDER BY activo DESC, rol = "cajero", nombre'
        );
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo !== 'POST' && $metodo !== 'PUT') {
        minervox_error(405, 'Método no permitido');
    }

    $datos = minervox_leer_json();

    /** Roles válidos, según lo que acepta la columna. */
    $rolDe = function ($valor) {
        $rol = strtolower(trim((string) $valor));
        if (!in_array($rol, ['admin', 'cajero'], true)) {
            minervox_error(400, 'El rol debe ser administrador o cajero');
        }
        return $rol;
    };

    /** Valida que el nombre de usuario no esté cogido por otro. */
    $usuarioLibre = function (string $usuario, int $exceptoId = 0) use ($pdo) {
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = ? AND id <> ? LIMIT 1');
        $stmt->execute([$usuario, $exceptoId]);
        if ($stmt->fetch()) {
            minervox_error(409, 'Ya existe un usuario con ese nombre');
        }
    };

    /** ¿Cuántos administradores activos quedan? */
    $adminsActivos = function () use ($pdo) {
        return (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1")
                         ->fetchColumn();
    };

    // -------------------------------------------------------------
    // CREAR
    // -------------------------------------------------------------
    if ($metodo === 'POST') {
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        if ($nombre === '') {
            minervox_error(400, 'Escriba el nombre de la persona');
        }

        $usuarioNuevo = minervox_validar_nombre_usuario((string) ($datos['usuario'] ?? ''));
        $usuarioLibre($usuarioNuevo);
        $rol   = $rolDe($datos['rol'] ?? 'cajero');
        $clave = minervox_validar_claves(
            (string) ($datos['clave'] ?? ''),
            (string) ($datos['clave2'] ?? '')
        );

        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (nombre, usuario, password_hash, rol, activo)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$nombre, $usuarioNuevo, password_hash($clave, PASSWORD_DEFAULT), $rol]);

        echo json_encode(['success' => true, 'id' => (int) $pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // CAMBIAR
    // -------------------------------------------------------------
    $id = (int) ($datos['id'] ?? 0);
    if ($id <= 0) {
        minervox_error(400, 'Falta el usuario a modificar');
    }

    $stmt = $pdo->prepare('SELECT id, nombre, usuario, rol, activo FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $objetivo = $stmt->fetch();
    if (!$objetivo) {
        minervox_error(404, 'Ese usuario no existe');
    }

    $nombre = trim((string) ($datos['nombre'] ?? $objetivo['nombre']));
    if ($nombre === '') {
        minervox_error(400, 'El nombre no puede quedar vacío');
    }

    $usuarioNuevo = minervox_validar_nombre_usuario((string) ($datos['usuario'] ?? $objetivo['usuario']));
    $usuarioLibre($usuarioNuevo, $id);
    $rol    = $rolDe($datos['rol'] ?? $objetivo['rol']);
    $activo = array_key_exists('activo', $datos)
        ? (!empty($datos['activo']) ? 1 : 0)
        : (int) $objetivo['activo'];

    // Candado 1: uno no se desactiva ni se quita el admin a sí mismo.
    if ($id === $yo['id']) {
        if ($activo !== 1) {
            minervox_error(400, 'No puede desactivar su propio usuario');
        }
        if ($rol !== 'admin') {
            minervox_error(400, 'No puede quitarse a sí mismo el rol de administrador');
        }
    }

    // Candado 2: no se puede quedar el sistema sin administrador activo.
    $eraAdminActivo = ($objetivo['rol'] === 'admin' && (int) $objetivo['activo'] === 1);
    $sigueAdminActivo = ($rol === 'admin' && $activo === 1);
    if ($eraAdminActivo && !$sigueAdminActivo && $adminsActivos() <= 1) {
        minervox_error(400, 'Debe quedar al menos un administrador activo');
    }

    // La clave solo se cambia si vino en el formulario.
    if (!empty($datos['clave'])) {
        $claveNueva = minervox_validar_claves(
            (string) $datos['clave'],
            (string) ($datos['clave2'] ?? '')
        );
        $pdo->prepare('UPDATE usuarios SET password_hash = ?, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?')
            ->execute([password_hash($claveNueva, PASSWORD_DEFAULT), $id]);
    }

    $pdo->prepare('UPDATE usuarios SET nombre = ?, usuario = ?, rol = ?, activo = ? WHERE id = ?')
        ->execute([$nombre, $usuarioNuevo, $rol, $activo, $id]);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        minervox_error(409, 'Ya existe un usuario con ese nombre');
    }
    error_log('[MINERVOX][usuarios] ' . $e->getMessage());
    minervox_error(500, 'No se pudo procesar el usuario');
}
