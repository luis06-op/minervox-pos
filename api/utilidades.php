<?php
/**
 * MINERVOX · Utilidades compartidas de la API
 *
 * Funciones pequeñas que usan los endpoints nuevos. Se incluyen después
 * de `db.php`. Todas llevan guarda con function_exists para que no haya
 * problema si el archivo se incluye más de una vez.
 */

if (!function_exists('minervox_error')) {
    /**
     * Corta la ejecución devolviendo un error en JSON.
     */
    function minervox_error(int $codigo, string $mensaje): void
    {
        http_response_code($codigo);
        echo json_encode(['success' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('minervox_leer_json')) {
    /**
     * Lee el cuerpo JSON del request. Falla con 400 si no es válido.
     */
    function minervox_leer_json(): array
    {
        $crudo = file_get_contents('php://input');
        $datos = json_decode($crudo, true);
        if (!is_array($datos)) {
            minervox_error(400, 'Los datos enviados no son válidos');
        }
        return $datos;
    }
}

if (!function_exists('minervox_fecha_valida')) {
    /**
     * Devuelve la fecha si viene como Y-m-d real; si no, null.
     * Ojo: DateTime::createFromFormat acepta "2026-02-31" (la desborda),
     * por eso se compara el resultado formateado contra el original.
     */
    function minervox_fecha_valida($valor): ?string
    {
        if (!is_string($valor) || $valor === '') {
            return null;
        }
        $fecha = DateTime::createFromFormat('Y-m-d', $valor);
        if (!$fecha || $fecha->format('Y-m-d') !== $valor) {
            return null;
        }
        return $valor;
    }
}

if (!function_exists('minervox_metodo_pago')) {
    /**
     * Normaliza el método de pago contra los valores que acepta la base.
     */
    function minervox_metodo_pago($valor, string $porDefecto = 'efectivo'): string
    {
        $permitidos = ['efectivo', 'transferencia', 'otro'];
        return (is_string($valor) && in_array($valor, $permitidos, true)) ? $valor : $porDefecto;
    }
}

if (!function_exists('minervox_numero')) {
    /**
     * Convierte a número de forma segura (evita "1.200.000" y textos).
     */
    function minervox_numero($valor, float $porDefecto = 0.0): float
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }
        if (is_string($valor) && is_numeric(trim($valor))) {
            return (float) trim($valor);
        }
        return $porDefecto;
    }
}
