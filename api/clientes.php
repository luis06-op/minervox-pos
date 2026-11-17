<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utilidades.php';

function cliente_publico(array $cliente): array
{
    $cliente['id'] = (int) $cliente['id'];
    return $cliente;
}

manejar_api(function () use ($pdo): void {
    $metodo = requerir_metodo('GET', 'POST');

    if ($metodo === 'GET') {
        if (isset($_GET['cedula'])) {
            if (!is_string($_GET['cedula'])) {
                throw new ErrorApi('La cédula no es válida.', 400);
            }

            $cedula = trim($_GET['cedula']);

            if ($cedula === '') {
                throw new ErrorApi('Indica la cédula a buscar.', 400);
            }

            $stmt = $pdo->prepare('SELECT * FROM clientes WHERE cedula = ? LIMIT 1');
            $stmt->execute([$cedula]);
            $cliente = $stmt->fetch();

            if (!$cliente) {
                responder(['success' => false, 'error' => 'Cliente no encontrado.']);
            }

            responder(['success' => true, 'cliente' => cliente_publico($cliente)]);
        }

        $stmt = $pdo->query('SELECT * FROM clientes ORDER BY nombre ASC');
        responder(array_map('cliente_publico', $stmt->fetchAll()));
    }

    $datos = leer_json();

    $cliente = [
        'cedula'    => texto_requerido($datos, 'cedula', 'Cédula / NIT', 40),
        'nombre'    => texto_requerido($datos, 'nombre', 'Nombre', 150),
        'telefono'  => texto_opcional($datos, 'telefono', 'Teléfono', 40),
        'ubicacion' => texto_opcional($datos, 'ubicacion', 'Ubicación', 150),
    ];

    $stmt = $pdo->prepare('SELECT id FROM clientes WHERE cedula = ?');
    $stmt->execute([$cliente['cedula']]);

    if ($stmt->fetch()) {
        throw new ErrorApi('Ya existe un cliente con esa cédula. Actualízalo desde la venta o usa otra cédula.', 409);
    }

    $stmt = $pdo->prepare('INSERT INTO clientes (cedula, nombre, telefono, ubicacion) VALUES (?, ?, ?, ?)');
    $stmt->execute(array_values($cliente));

    responder(['success' => true, 'id' => (int) $pdo->lastInsertId()], 201);
});
