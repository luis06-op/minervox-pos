<?php
/**
 * Utilidades compartidas por la API: respuestas JSON, lectura del cuerpo,
 * validación de datos y manejo uniforme de errores.
 */

declare(strict_types=1);

/**
 * Error controlado de la API (datos inválidos, recurso inexistente, etc.).
 * El mensaje es seguro para mostrarse al usuario.
 */
final class ErrorApi extends RuntimeException
{
    public function __construct(string $mensaje, private int $codigoHttp = 422)
    {
        parent::__construct($mensaje);
    }

    public function codigoHttp(): int
    {
        return $this->codigoHttp;
    }
}

/**
 * Envía una respuesta JSON y termina la ejecución.
 */
function responder(array $datos, int $codigo = 200): void
{
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }

    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Ejecuta el manejador del endpoint traduciendo excepciones a respuestas JSON.
 * Nunca expone detalles internos (SQL, rutas, credenciales) al cliente.
 */
function manejar_api(callable $manejador): void
{
    try {
        $manejador();
    } catch (ErrorApi $e) {
        responder(['success' => false, 'error' => $e->getMessage()], $e->codigoHttp());
    } catch (PDOException $e) {
        error_log('[minervox] Error PDO: ' . $e->getMessage());

        if ($e->getCode() === '23000') {
            responder([
                'success' => false,
                'error'   => 'El registro entra en conflicto con datos existentes (por ejemplo, una cédula duplicada).',
            ], 409);
        }

        responder(['success' => false, 'error' => 'Error al procesar la operación en la base de datos.'], 500);
    } catch (Throwable $e) {
        error_log('[minervox] ' . get_class($e) . ': ' . $e->getMessage());
        responder(['success' => false, 'error' => 'Error interno del servidor.'], 500);
    }
}

/**
 * Comprueba el método HTTP y devuelve el método efectivo.
 */
function requerir_metodo(string ...$permitidos): string
{
    $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if (!in_array($metodo, $permitidos, true)) {
        if (!headers_sent()) {
            header('Allow: ' . implode(', ', $permitidos));
        }
        throw new ErrorApi('Método no permitido.', 405);
    }

    return $metodo;
}

/**
 * Lee y decodifica el cuerpo JSON de la petición.
 */
function leer_json(): array
{
    $cuerpo = file_get_contents('php://input');

    if ($cuerpo === false || trim($cuerpo) === '') {
        throw new ErrorApi('El cuerpo de la petición está vacío.', 400);
    }

    try {
        $datos = json_decode($cuerpo, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new ErrorApi('El cuerpo de la petición no es un JSON válido.', 400);
    }

    if (!is_array($datos)) {
        throw new ErrorApi('El cuerpo de la petición debe ser un objeto JSON.', 400);
    }

    return $datos;
}

function texto_requerido(array $datos, string $campo, string $etiqueta, int $maximo = 255): string
{
    $valor = $datos[$campo] ?? null;

    if (!is_string($valor) && !is_int($valor) && !is_float($valor)) {
        throw new ErrorApi(sprintf('El campo «%s» es obligatorio.', $etiqueta));
    }

    $texto = trim((string) $valor);

    if ($texto === '') {
        throw new ErrorApi(sprintf('El campo «%s» es obligatorio.', $etiqueta));
    }

    if (mb_strlen($texto) > $maximo) {
        throw new ErrorApi(sprintf('El campo «%s» no puede superar %d caracteres.', $etiqueta, $maximo));
    }

    return $texto;
}

function texto_opcional(array $datos, string $campo, string $etiqueta, int $maximo = 255): string
{
    $valor = $datos[$campo] ?? '';

    if ($valor === null) {
        return '';
    }

    if (!is_scalar($valor)) {
        throw new ErrorApi(sprintf('El campo «%s» no es válido.', $etiqueta));
    }

    $texto = trim((string) $valor);

    if (mb_strlen($texto) > $maximo) {
        throw new ErrorApi(sprintf('El campo «%s» no puede superar %d caracteres.', $etiqueta, $maximo));
    }

    return $texto;
}

function numero_requerido(array $datos, string $campo, string $etiqueta, float $minimo = 0.0, ?float $maximo = null): float
{
    $valor = $datos[$campo] ?? null;

    if ($valor === null || $valor === '' || is_bool($valor) || !is_numeric($valor)) {
        throw new ErrorApi(sprintf('El campo «%s» debe ser un número.', $etiqueta));
    }

    $numero = (float) $valor;

    if (!is_finite($numero)) {
        throw new ErrorApi(sprintf('El campo «%s» debe ser un número válido.', $etiqueta));
    }

    if ($numero < $minimo) {
        throw new ErrorApi(sprintf('El campo «%s» debe ser mayor o igual a %s.', $etiqueta, numero_a_texto($minimo)));
    }

    if ($maximo !== null && $numero > $maximo) {
        throw new ErrorApi(sprintf('El campo «%s» no puede ser mayor que %s.', $etiqueta, numero_a_texto($maximo)));
    }

    return $numero;
}

function numero_opcional(
    array $datos,
    string $campo,
    string $etiqueta,
    float $porDefecto = 0.0,
    float $minimo = 0.0,
    ?float $maximo = null
): float {
    $valor = $datos[$campo] ?? null;

    if ($valor === null || $valor === '') {
        return $porDefecto;
    }

    return numero_requerido($datos, $campo, $etiqueta, $minimo, $maximo);
}

function entero_requerido(array $datos, string $campo, string $etiqueta, int $minimo = 1): int
{
    $numero = numero_requerido($datos, $campo, $etiqueta, $minimo);

    if (floor($numero) !== $numero || $numero > PHP_INT_MAX) {
        throw new ErrorApi(sprintf('El campo «%s» debe ser un número entero.', $etiqueta));
    }

    return (int) $numero;
}

/**
 * Valida una fecha en formato YYYY-MM-DD y la devuelve tal cual.
 */
function fecha_requerida(array $datos, string $campo, string $etiqueta): string
{
    $valor = $datos[$campo] ?? null;

    if (!is_string($valor) || trim($valor) === '') {
        throw new ErrorApi(sprintf('El campo «%s» es obligatorio.', $etiqueta));
    }

    $valor = trim($valor);
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    $errores = DateTimeImmutable::getLastErrors();

    if ($fecha === false || ($errores !== false && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))) {
        throw new ErrorApi(sprintf('El campo «%s» debe tener el formato AAAA-MM-DD.', $etiqueta));
    }

    if ($fecha->format('Y-m-d') !== $valor) {
        throw new ErrorApi(sprintf('El campo «%s» no es una fecha válida.', $etiqueta));
    }

    return $valor;
}

function fecha_hoy(): string
{
    return (new DateTimeImmutable('today'))->format('Y-m-d');
}

function numero_a_texto(float $numero): string
{
    return number_format($numero, 2, ',', '.');
}
