<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utilidades.php';

function configuracion_publica(array $config): array
{
    $config['id'] = 1;
    $config['plazo_1'] = (int) $config['plazo_1'];
    $config['plazo_2'] = (int) $config['plazo_2'];
    $config['recargo_credito'] = (float) $config['recargo_credito'];
    $config['texto_pagare'] = $config['texto_pagare'] ?? '';
    $config['actualizado_en'] = $config['actualizado_en'] ?? null;

    return $config;
}

manejar_api(function () use ($pdo): void {
    $metodo = requerir_metodo('GET', 'PUT');

    if ($metodo === 'GET') {
        $config = $pdo->query('SELECT * FROM configuracion WHERE id = 1')->fetch();

        if (!$config) {
            responder(configuracion_publica([
                'nombre_comercial' => 'MINERVOX',
                'razon_social'     => '',
                'nit'              => '',
                'direccion'        => '',
                'telefonos'        => '',
                'plazo_1'          => 8,
                'plazo_2'          => 15,
                'recargo_credito'  => 0,
                'texto_pagare'     => '',
            ]));
        }

        responder(configuracion_publica($config));
    }

    $datos = leer_json();

    $config = [
        'nombre_comercial' => texto_requerido($datos, 'nombre_comercial', 'Nombre comercial', 120),
        'razon_social'     => texto_opcional($datos, 'razon_social', 'Razón social', 160),
        'nit'              => texto_opcional($datos, 'nit', 'NIT / Cédula', 40),
        'direccion'        => texto_opcional($datos, 'direccion', 'Dirección', 200),
        'telefonos'        => texto_opcional($datos, 'telefonos', 'Teléfonos', 120),
        'plazo_1'          => entero_requerido($datos, 'plazo_1', 'Plazo 1', 1),
        'plazo_2'          => entero_requerido($datos, 'plazo_2', 'Plazo 2', 1),
        'recargo_credito'  => round(numero_requerido($datos, 'recargo_credito', 'Recargo por mora', 0.0, 100.0), 2),
        'texto_pagare'     => texto_opcional($datos, 'texto_pagare', 'Texto del pagaré', 5000),
    ];

    if ($config['plazo_1'] > 365 || $config['plazo_2'] > 365) {
        throw new ErrorApi('Los plazos no pueden superar 365 días.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO configuracion
            (id, nombre_comercial, razon_social, nit, direccion, telefonos, plazo_1, plazo_2, recargo_credito, texto_pagare)
         VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            nombre_comercial = VALUES(nombre_comercial),
            razon_social = VALUES(razon_social),
            nit = VALUES(nit),
            direccion = VALUES(direccion),
            telefonos = VALUES(telefonos),
            plazo_1 = VALUES(plazo_1),
            plazo_2 = VALUES(plazo_2),
            recargo_credito = VALUES(recargo_credito),
            texto_pagare = VALUES(texto_pagare)'
    );
    $stmt->execute(array_values($config));

    responder(['success' => true]);
});
