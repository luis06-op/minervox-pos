<?php
/**
 * MINERVOX · Sesiones, claves y permisos  (FASE 5)
 *
 * CÓMO SE PROTEGE EL SISTEMA:
 *   1. Cada endpoint incluye este archivo y luego llama a una de estas dos
 *      funciones, según lo que haga falta:
 *          minervox_exigir_sesion($pdo)  -> tiene que haber alguien adentro
 *          minervox_exigir_admin($pdo)   -> además tiene que ser el dueño
 *      La decisión de quién puede qué se toma SIEMPRE aquí, en el servidor.
 *      Esconder un botón en la pantalla no protege nada: si alguien llama
 *      la API a mano, se topa con el 401 / 403.
 *
 *   2. La sesión viaja en una cookie (no se guarda nada en el navegador).
 *      Se marca HttpOnly (no la puede leer JavaScript) y SameSite=Strict
 *      (no la manda si la página la abre otro sitio: corta el CSRF).
 *
 *   3. Las claves se guardan cifradas con password_hash(); la clave
 *      original no se puede recuperar ni leyendo la base.
 *
 * POR QUÉ EL PRIMER ADMINISTRADOR NO VIENE EN LA MIGRACIÓN:
 *   El repositorio es público. Una clave por defecto escrita en un .sql es
 *   una puerta abierta para cualquiera que la lea. El sistema arranca sin
 *   usuarios y el dueño crea el suyo desde la pantalla, la primera vez.
 */

require_once __DIR__ . '/utilidades.php';

/** Se cierra sola después de 12 horas sin usar el sistema (un turno largo). */
const MINERVOX_INACTIVIDAD_SEGUNDOS = 12 * 3600;

/** Claves erradas seguidas antes de bloquear al usuario. */
const MINERVOX_INTENTOS_MAXIMOS = 5;

/** Minutos que dura el bloqueo al pasarse de intentos. */
const MINERVOX_BLOQUEO_MINUTOS = 15;

/** Largo mínimo de una clave. */
const MINERVOX_CLAVE_MINIMA = 8;

/** Largo mínimo y máximo del nombre de usuario. */
const MINERVOX_USUARIO_MINIMO = 3;
const MINERVOX_USUARIO_MAXIMO = 50;

/**
 * Hash de relleno: si el usuario no existe, se verifica contra esto para
 * que el login tarde lo mismo que cuando sí existe. Sin esto, la demora
 * delataría qué nombres de usuario están registrados.
 */
const MINERVOX_HASH_RELLENO = '$2y$10$xUs4BTgE3xrOgHbZxgxuyuhY9lgwzT5ZPQZ90ciiD.x58lxPmOvlC';

/**
 * Arranca la sesión con las banderas de seguridad puestas.
 */
function minervox_sesion_iniciar(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,          // se muere al cerrar el navegador
        'path'     => '/',
        'httponly' => true,       // JavaScript no puede leer la cookie
        'samesite' => 'Strict',   // no viaja desde otros sitios
    ]);

    session_start();
}

/**
 * Cierra la sesión y borra la cookie.
 *
 * OJO: hay que ARRANCAR la sesión antes de destruirla. Si no, PHP ve que no
 * hay sesión activa y este método no hace nada: la cookie sigue viva en el
 * navegador y el usuario vuelve a entrar sin clave. Pasó y se corrigió.
 */
function minervox_salir(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        minervox_sesion_iniciar();
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => 'Strict',
            ]
        );
    }

    session_destroy();
}

/**
 * Quién está adentro (o null). Se consulta la base en cada llamada para
 * que, si el dueño desactiva a un cajero, deje de entrar de inmediato.
 */
function minervox_usuario_actual(PDO $pdo): ?array
{
    minervox_sesion_iniciar();

    $id = (int) ($_SESSION['usuario_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    // Caducidad por inactividad.
    $ultimo = (int) ($_SESSION['ultimo_movimiento'] ?? 0);
    if ($ultimo > 0 && (time() - $ultimo) > MINERVOX_INACTIVIDAD_SEGUNDOS) {
        minervox_salir();
        return null;
    }
    $_SESSION['ultimo_movimiento'] = time();

    $stmt = $pdo->prepare('SELECT id, nombre, usuario, rol, activo FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $u = $stmt->fetch();

    if (!$u || (int) $u['activo'] !== 1) {
        minervox_salir();
        return null;
    }

    return [
        'id'       => (int) $u['id'],
        'nombre'   => $u['nombre'],
        'usuario'  => $u['usuario'],
        'rol'      => $u['rol'],
        'es_admin' => $u['rol'] === 'admin',
    ];
}

/**
 * Corta con 401 si no hay nadie adentro.
 */
function minervox_exigir_sesion(PDO $pdo): array
{
    $usuario = minervox_usuario_actual($pdo);
    if (!$usuario) {
        minervox_error(401, 'Debe iniciar sesión');
    }
    return $usuario;
}

/**
 * Corta con 403 si el que está adentro no es administrador.
 */
function minervox_exigir_admin(PDO $pdo): array
{
    $usuario = minervox_exigir_sesion($pdo);
    if (!$usuario['es_admin']) {
        minervox_error(403, 'Esta acción es solo del administrador');
    }
    return $usuario;
}

/**
 * ¿Hay al menos un usuario creado? Si no, la pantalla muestra
 * "Crear el administrador" en vez del login.
 */
function minervox_hay_usuarios(PDO $pdo): bool
{
    return (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;
}

/**
 * Valida el nombre de usuario (para crearlo o cambiarlo).
 */
function minervox_validar_nombre_usuario(string $usuario): string
{
    $usuario = trim($usuario);

    if (strlen($usuario) < MINERVOX_USUARIO_MINIMO || strlen($usuario) > MINERVOX_USUARIO_MAXIMO) {
        minervox_error(400, 'El usuario debe tener entre ' . MINERVOX_USUARIO_MINIMO
            . ' y ' . MINERVOX_USUARIO_MAXIMO . ' caracteres');
    }
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $usuario)) {
        minervox_error(400, 'El usuario solo admite letras, números, punto, guion y guion bajo');
    }

    return strtolower($usuario);
}

/**
 * Valida la contraseña y que las dos veces coincidan.
 */
function minervox_validar_claves(string $clave, string $confirmacion): string
{
    if (strlen($clave) < MINERVOX_CLAVE_MINIMA) {
        minervox_error(400, 'La contraseña debe tener al menos ' . MINERVOX_CLAVE_MINIMA . ' caracteres');
    }
    if ($clave !== $confirmacion) {
        minervox_error(400, 'Las dos contraseñas no son iguales');
    }
    return $clave;
}

/**
 * Anota un intento fallido y bloquea al llegar al máximo.
 */
function minervox_anotar_intento_fallido(PDO $pdo, int $usuarioId, int $intentosActuales): void
{
    $intentos = $intentosActuales + 1;

    if ($intentos >= MINERVOX_INTENTOS_MAXIMOS) {
        $pdo->prepare(
            'UPDATE usuarios
                SET intentos_fallidos = 0,
                    bloqueado_hasta = DATE_ADD(NOW(), INTERVAL ? MINUTE)
              WHERE id = ?'
        )->execute([MINERVOX_BLOQUEO_MINUTOS, $usuarioId]);
        return;
    }

    $pdo->prepare('UPDATE usuarios SET intentos_fallidos = ? WHERE id = ?')
        ->execute([$intentos, $usuarioId]);
}

/**
 * Intenta entrar. Devuelve los datos del usuario o corta con un error.
 */
function minervox_entrar(PDO $pdo, string $usuario, string $clave): array
{
    $usuario = trim($usuario);
    if ($usuario === '' || $clave === '') {
        minervox_error(400, 'Escriba el usuario y la contraseña');
    }

    // El mismo mensaje para usuario inexistente y para clave errada: así
    // nadie puede averiguar qué usuarios están registrados.
    $errorGenerico = 'Usuario o contraseña incorrectos';

    $stmt = $pdo->prepare(
        'SELECT id, nombre, usuario, password_hash, rol, activo, intentos_fallidos, bloqueado_hasta
           FROM usuarios WHERE usuario = ? LIMIT 1'
    );
    $stmt->execute([strtolower($usuario)]);
    $u = $stmt->fetch();

    if (!$u) {
        // Se gasta el mismo tiempo que si el usuario existiera.
        password_verify($clave, MINERVOX_HASH_RELLENO);
        minervox_error(401, $errorGenerico);
    }

    if (!empty($u['bloqueado_hasta']) && strtotime($u['bloqueado_hasta']) > time()) {
        $minutos = max(1, (int) ceil((strtotime($u['bloqueado_hasta']) - time()) / 60));
        minervox_error(429, 'Demasiados intentos fallidos. Vuelva a intentar en ' . $minutos . ' minuto(s)');
    }

    if (!password_verify($clave, $u['password_hash'])) {
        minervox_anotar_intento_fallido($pdo, (int) $u['id'], (int) $u['intentos_fallidos']);
        minervox_error(401, $errorGenerico);
    }

    if ((int) $u['activo'] !== 1) {
        minervox_error(403, 'Ese usuario está desactivado');
    }

    // Entró: se limpia el contador y se cambia el id de sesión (evita que
    // una sesión vieja sirva para entrar).
    $pdo->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = NOW() WHERE id = ?')
        ->execute([$u['id']]);

    minervox_sesion_iniciar();
    session_regenerate_id(true);
    $_SESSION['usuario_id']        = (int) $u['id'];
    $_SESSION['rol']               = $u['rol'];
    $_SESSION['ultimo_movimiento'] = time();

    return [
        'id'       => (int) $u['id'],
        'nombre'   => $u['nombre'],
        'usuario'  => $u['usuario'],
        'rol'      => $u['rol'],
        'es_admin' => $u['rol'] === 'admin',
    ];
}
