<?php
/**
 * MINERVOX · Configuración del negocio  (FASE 2)
 *
 * GET -> devuelve la fila única de configuración.
 * PUT -> guarda los cambios.
 *
 * FASE 2: antes esta API solo guardaba las columnas originales, así que
 * `dias_alerta`, `mora_mensual`, `plantilla_whatsapp`, `ciudad`, `logo`,
 * etc. se perdían al guardar (y el rótulo "Recargo Mora (%)" del
 * formulario escribía en `recargo_credito`, que es otra cosa). Ya se
 * guardan todas.
 *
 * La actualización es PARCIAL: si un campo no viene en el cuerpo, se
 * conserva el valor que ya estaba. Así una pantalla vieja no borra datos.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
require_once 'utilidades.php';
require_once 'auth.php';

// FASE 5: leer la configuración lo hace cualquiera que haya entrado (el
// recibo la necesita, igual que el pagaré). Cambiarla es solo del dueño:
// ahí viven los plazos, la mora y el nombre del negocio.
$usuario = minervox_exigir_sesion($pdo);

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    if ($metodo === 'GET') {
        $config = $pdo->query('SELECT * FROM configuracion WHERE id = 1')->fetch();
        if (!$config) {
            minervox_error(500, 'No hay configuración del negocio');
        }
        echo json_encode($config, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($metodo !== 'PUT') {
        minervox_error(405, 'Método no permitido');
    }

    if (!$usuario['es_admin']) {
        minervox_error(403, 'Solo el administrador puede cambiar la configuración');
    }

    $datos   = minervox_leer_json();
    $actual  = $pdo->query('SELECT * FROM configuracion WHERE id = 1')->fetch();
    if (!$actual) {
        minervox_error(500, 'No hay configuración del negocio');
    }

    /** Toma el valor nuevo si vino; si no, deja el que ya estaba. */
    $texto = function (string $campo, bool $permitirVacio = true) use ($datos, $actual) {
        if (!array_key_exists($campo, $datos)) {
            return $actual[$campo];
        }
        $valor = trim((string) $datos[$campo]);
        if (!$permitirVacio && $valor === '') {
            minervox_error(400, "El campo \"$campo\" no puede quedar vacío");
        }
        return $valor !== '' ? $valor : null;
    };

    /** Igual, pero para números que no pueden ser negativos. */
    $numero = function (string $campo, float $minimo = 0) use ($datos, $actual) {
        if (!array_key_exists($campo, $datos)) {
            return (float) $actual[$campo];
        }
        $valor = minervox_numero($datos[$campo], -1);
        if ($valor < $minimo) {
            minervox_error(400, "El campo \"$campo\" no es válido");
        }
        return $valor;
    };

    $nombreComercial = trim((string) ($datos['nombre_comercial'] ?? $actual['nombre_comercial']));
    if ($nombreComercial === '') {
        minervox_error(400, 'El nombre comercial no puede quedar vacío');
    }

    $stmt = $pdo->prepare(
        'UPDATE configuracion SET
            nombre_comercial = ?,
            razon_social = ?,
            nit = ?,
            direccion = ?,
            telefonos = ?,
            ciudad = ?,
            correo = ?,
            logo = ?,
            pie_ticket = ?,
            prefijo_recibo = ?,
            prefijo_pagare = ?,
            plazo_1 = ?,
            plazo_2 = ?,
            recargo_credito = ?,
            dias_alerta = ?,
            mora_mensual = ?,
            plantilla_whatsapp = ?,
            exigir_cedula_contado = ?,
            texto_pagare = ?
          WHERE id = 1'
    );

    $stmt->execute([
        $nombreComercial,
        $texto('razon_social'),
        $texto('nit'),
        $texto('direccion'),
        $texto('telefonos'),
        $texto('ciudad'),
        $texto('correo'),
        $texto('logo'),
        $texto('pie_ticket'),
        $texto('prefijo_recibo', false) ?: 'R',
        $texto('prefijo_pagare', false) ?: 'P',
        (int) $numero('plazo_1'),
        (int) $numero('plazo_2'),
        $numero('recargo_credito'),
        (int) $numero('dias_alerta'),
        $numero('mora_mensual'),
        $texto('plantilla_whatsapp'),
        !empty($datos['exigir_cedula_contado']) ? 1 : 0,
        $texto('texto_pagare'),
    ]);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[MINERVOX][configuracion] ' . $e->getMessage());
    minervox_error(500, 'No se pudo guardar la configuración');
}
