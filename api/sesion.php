<?php
/**
 * MINERVOX · Entrar y salir del sistema  (FASE 5)
 *
 * Este es el ÚNICO endpoint que se puede llamar sin haber entrado.
 * Todo lo demás exige sesión (ver api/auth.php).
 *
 *   GET  ?action=estado         -> ¿hay alguien adentro? ¿falta crear el
 *                                  primer administrador?
 *   POST ?action=entrar         -> {usuario, clave}
 *   POST ?action=salir          -> cierra la sesión
 *   POST ?action=primer_admin   -> {nombre, usuario, clave, clave2}
 *                                  Solo funciona si todavía no hay NINGÚN
 *                                  usuario. En cuanto exista uno, se cierra.
 *   POST ?action=cambiar_clave  -> {clave_actual, clave_nueva, clave_nueva2}
 *
 * Sobre las claves: se guardan cifradas con password_hash(); la original no
 * se puede recuperar. Ni el dueño puede leerla, solo cambiarla.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
require_once 'utilidades.php';
require_once 'auth.php';

$accion = $_GET['action'] ?? 'estado';
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    // -------------------------------------------------------------
    // ESTADO · lo primero que pregunta la pantalla al abrir
    // -------------------------------------------------------------
    if ($accion === 'estado' && $metodo === 'GET') {
        $usuario = minervox_usuario_actual($pdo);

        echo json_encode([
            'success'                => true,
            'autenticado'            => $usuario !== null,
            'necesita_primer_admin'  => !minervox_hay_usuarios($pdo),
            'usuario'                => $usuario,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo !== 'POST') {
        minervox_error(405, 'Método no permitido');
    }

    // -------------------------------------------------------------
    // SALIR · va antes de leer el cuerpo, para que funcione aunque el
    // navegador no mande nada. No falla nunca: si no había sesión,
    // igual responde bien.
    // -------------------------------------------------------------
    if ($accion === 'salir') {
        minervox_salir();
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $datos = minervox_leer_json();

    // -------------------------------------------------------------
    // ENTRAR
    // -------------------------------------------------------------
    if ($accion === 'entrar') {
        $usuario = minervox_entrar(
            $pdo,
            (string) ($datos['usuario'] ?? ''),
            (string) ($datos['clave'] ?? '')
        );

        echo json_encode(['success' => true, 'usuario' => $usuario], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // PRIMER ADMINISTRADOR · solo si la tabla está vacía
    // -------------------------------------------------------------
    if ($accion === 'primer_admin') {
        if (minervox_hay_usuarios($pdo)) {
            minervox_error(403, 'Ya existe un administrador. Pida que le creen el usuario.');
        }

        $nombre  = trim((string) ($datos['nombre'] ?? ''));
        if ($nombre === '') {
            minervox_error(400, 'Escriba el nombre de quien va a administrar');
        }

        $usuario = minervox_validar_nombre_usuario((string) ($datos['usuario'] ?? ''));
        $clave   = minervox_validar_claves(
            (string) ($datos['clave'] ?? ''),
            (string) ($datos['clave2'] ?? '')
        );

        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (nombre, usuario, password_hash, rol, activo)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$nombre, $usuario, password_hash($clave, PASSWORD_DEFAULT), 'admin']);
        $nuevoId = (int) $pdo->lastInsertId();

        // Queda adentro de una vez: acaba de demostrar que es él.
        minervox_sesion_iniciar();
        session_regenerate_id(true);
        $_SESSION['usuario_id']        = $nuevoId;
        $_SESSION['rol']               = 'admin';
        $_SESSION['ultimo_movimiento'] = time();

        $pdo->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?')->execute([$nuevoId]);

        echo json_encode([
            'success' => true,
            'usuario' => [
                'id'       => $nuevoId,
                'nombre'   => $nombre,
                'usuario'  => $usuario,
                'rol'      => 'admin',
                'es_admin' => true,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------
    // CAMBIAR MI CLAVE
    // -------------------------------------------------------------
    if ($accion === 'cambiar_clave') {
        $actual = minervox_exigir_sesion($pdo);

        $stmt = $pdo->prepare('SELECT password_hash FROM usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([$actual['id']]);
        $hash = (string) $stmt->fetchColumn();

        if (!password_verify((string) ($datos['clave_actual'] ?? ''), $hash)) {
            // 400 y no 401: la sesión está bien; lo que está mal es la clave
            // que escribió. Con 401 la pantalla lo sacaría al login, que no
            // es lo que corresponde.
            minervox_error(400, 'La contraseña actual no es correcta');
        }

        $nueva = minervox_validar_claves(
            (string) ($datos['clave_nueva'] ?? ''),
            (string) ($datos['clave_nueva2'] ?? '')
        );

        $pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($nueva, PASSWORD_DEFAULT), $actual['id']]);

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    minervox_error(400, 'Acción no reconocida');

} catch (Throwable $e) {
    error_log('[MINERVOX][sesion] ' . $e->getMessage());
    minervox_error(500, 'No se pudo completar la operación');
}
