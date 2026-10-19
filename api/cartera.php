<?php
/**
 * MINERVOX · Cartera ("los fiaos")  (FASE 2)
 *
 * GET  ?action=resumen                    -> tarjetas y conteos del semáforo
 * GET  ?action=list&filtro=...&q=...      -> listado de deudores
 *        filtro: todos | vencidos | por_vencer | al_dia   (por defecto todos)
 *        q: busca por nombre o cédula
 *        incluir_pagados=1 para ver también los ya saldados
 * GET  ?action=estado_cuenta&credito_id=N -> la hoja de vida completa del fiao
 * POST (sin action)                       -> registrar un abono
 * POST ?action=acuerdo                    -> acuerdo de pago (corre el vencimiento)
 *
 * EL SEMÁFORO (los mismos colores de un semáforo de verdad):
 *   rojo     = ya venció  -> tiene días de mora
 *   amarillo = le faltan `configuracion.dias_alerta` días o menos
 *   verde    = todavía tiene tiempo
 *   gris     = ya pagó
 *
 * LA MORA se calcula por días sobre el saldo pendiente, con la tasa que
 * quedó CONGELADA en el fiao (`cartera_creditos.mora_mensual`), para que
 * subir la tasa mañana no le cambie la deuda a los fiaos viejos:
 *
 *     mora = saldo × (mora_mensual / 100) × (días de mora / 30)
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
require_once 'utilidades.php';

/**
 * Lee los parámetros de cartera que el dueño configura en Ajustes.
 */
function cartera_config(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT dias_alerta, mora_mensual, plantilla_whatsapp, plazo_1, plazo_2
           FROM configuracion WHERE id = 1'
    );
    $cfg = $stmt->fetch() ?: [];

    return [
        'dias_alerta'        => (int) ($cfg['dias_alerta'] ?? 2),
        'mora_mensual'       => (float) ($cfg['mora_mensual'] ?? 0),
        'plantilla_whatsapp' => $cfg['plantilla_whatsapp'] ?? null,
        'plazo_1'            => (int) ($cfg['plazo_1'] ?? 8),
        'plazo_2'            => (int) ($cfg['plazo_2'] ?? 15),
    ];
}

/**
 * Calcula los días que faltan, los de mora, el semáforo y la mora en pesos.
 */
function cartera_analizar(array $credito, DateTimeImmutable $hoy, int $diasAlerta): array
{
    if ($credito['estado'] === 'pagado') {
        return [
            'dias'          => null,
            'dias_mora'     => 0,
            'semaforo'      => 'gris',
            'mora'          => 0.0,
            'total_a_pagar' => round((float) $credito['saldo_pendiente'], 2),
        ];
    }

    $vence = new DateTimeImmutable($credito['fecha_vencimiento']);
    $dias  = (int) $hoy->diff($vence)->format('%R%a'); // + futuro, - vencido

    $diasMora = $dias < 0 ? -$dias : 0;

    if ($diasMora > 0) {
        $semaforo = 'rojo';
    } elseif ($dias <= $diasAlerta) {
        $semaforo = 'amarillo';
    } else {
        $semaforo = 'verde';
    }

    $saldo = (float) $credito['saldo_pendiente'];
    $mora  = $diasMora > 0
        ? round($saldo * ((float) $credito['mora_mensual'] / 100) * ($diasMora / 30), 2)
        : 0.0;

    return [
        'dias'          => $dias,
        'dias_mora'     => $diasMora,
        'semaforo'      => $semaforo,
        'mora'          => $mora,
        'total_a_pagar' => round($saldo + $mora, 2),
    ];
}

/** Trae los créditos con su venta y su cliente, ya con el análisis hecho. */
function cartera_creditos(PDO $pdo, DateTimeImmutable $hoy, int $diasAlerta, bool $incluirPagados): array
{
    $sql = "SELECT cc.id AS credito_id,
                   cc.saldo_pendiente,
                   cc.fecha_vencimiento,
                   cc.estado,
                   cc.mora_mensual,
                   v.id AS venta_id,
                   v.fecha AS fecha_venta,
                   v.total AS total_venta,
                   c.id AS cliente_id,
                   c.nombre AS cliente_nombre,
                   c.cedula AS cliente_cedula,
                   c.telefono AS cliente_telefono,
                   c.ubicacion AS cliente_ubicacion,
                   (SELECT COALESCE(SUM(a.monto), 0) FROM abonos a WHERE a.credito_id = cc.id) AS total_abonado,
                   (SELECT COUNT(*) FROM acuerdos_pago ap WHERE ap.credito_id = cc.id) AS num_acuerdos
              FROM cartera_creditos cc
              JOIN ventas v ON v.id = cc.venta_id
              LEFT JOIN clientes c ON c.id = v.cliente_id
             WHERE v.anulada = 0"
        . ($incluirPagados ? '' : " AND cc.estado = 'activo'")
        . ' ORDER BY cc.fecha_vencimiento ASC, cc.id ASC';

    $creditos = $pdo->query($sql)->fetchAll();

    foreach ($creditos as &$credito) {
        $credito['credito_id']    = (int) $credito['credito_id'];
        $credito['saldo_pendiente'] = (float) $credito['saldo_pendiente'];
        $credito['total_venta']   = (float) $credito['total_venta'];
        $credito['total_abonado'] = (float) $credito['total_abonado'];
        $credito['mora_mensual']  = (float) $credito['mora_mensual'];
        $credito['num_acuerdos']  = (int) $credito['num_acuerdos'];

        $credito = array_merge($credito, cartera_analizar($credito, $hoy, $diasAlerta));
    }
    unset($credito);

    // El más urgente primero: los vencidos, luego los que están por vencer.
    $orden = ['rojo' => 0, 'amarillo' => 1, 'verde' => 2, 'gris' => 3];
    usort($creditos, function ($a, $b) use ($orden) {
        return $orden[$a['semaforo']] <=> $orden[$b['semaforo']]
            ?: strcmp($a['fecha_vencimiento'], $b['fecha_vencimiento']);
    });

    return $creditos;
}

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    $config = cartera_config($pdo);
    $hoy    = new DateTimeImmutable('today');

    // ===============================================================
    // LECTURA
    // ===============================================================
    if ($metodo === 'GET') {
        $accion = $_GET['action'] ?? 'list';

        // ---------------------------------------------------------
        // Tarjetas del semáforo
        // ---------------------------------------------------------
        if ($accion === 'resumen' || $accion === 'dashboard') {
            $creditos = cartera_creditos($pdo, $hoy, $config['dias_alerta'], false);

            $grupos = [
                'vencido'    => ['cantidad' => 0, 'monto' => 0.0, 'mora' => 0.0],
                'por_vencer' => ['cantidad' => 0, 'monto' => 0.0, 'mora' => 0.0],
                'al_dia'     => ['cantidad' => 0, 'monto' => 0.0, 'mora' => 0.0],
            ];
            $total = 0.0;
            $moraTotal = 0.0;

            foreach ($creditos as $c) {
                $llave = $c['semaforo'] === 'rojo' ? 'vencido'
                    : ($c['semaforo'] === 'amarillo' ? 'por_vencer' : 'al_dia');
                $grupos[$llave]['cantidad']++;
                $grupos[$llave]['monto'] += $c['saldo_pendiente'];
                $grupos[$llave]['mora']  += $c['mora'];
                $total     += $c['saldo_pendiente'];
                $moraTotal += $c['mora'];
            }

            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(monto), 0) FROM abonos
                  WHERE fecha >= ?'
            );
            $stmt->execute([(new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00')]);
            $recuperadoMes = (float) $stmt->fetchColumn();

            echo json_encode([
                'total_cartera'   => round($total, 2),
                'num_fiaos'       => count($creditos),
                'mora_total'      => round($moraTotal, 2),
                'recuperado_mes'  => $recuperadoMes,
                'vencido'         => $grupos['vencido'],
                'por_vencer'      => $grupos['por_vencer'],
                'al_dia'          => $grupos['al_dia'],
                'dias_alerta'     => $config['dias_alerta'],
                'mora_mensual'    => $config['mora_mensual'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---------------------------------------------------------
        // Estado de cuenta de un fiao
        // ---------------------------------------------------------
        if ($accion === 'estado_cuenta') {
            $creditoId = (int) ($_GET['credito_id'] ?? 0);
            if ($creditoId <= 0) {
                minervox_error(400, 'Falta el fiao a consultar');
            }

            $stmt = $pdo->prepare(
                "SELECT cc.id AS credito_id, cc.saldo_pendiente, cc.fecha_vencimiento, cc.estado,
                        cc.mora_mensual, cc.venta_id,
                        v.fecha AS fecha_venta, v.total AS total_venta,
                        c.nombre AS cliente_nombre, c.cedula AS cliente_cedula,
                        c.telefono AS cliente_telefono, c.ubicacion AS cliente_ubicacion
                   FROM cartera_creditos cc
                   JOIN ventas v ON v.id = cc.venta_id
                   LEFT JOIN clientes c ON c.id = v.cliente_id
                  WHERE cc.id = ?"
            );
            $stmt->execute([$creditoId]);
            $credito = $stmt->fetch();

            if (!$credito) {
                minervox_error(404, 'Ese fiao no existe');
            }

            $credito['credito_id']      = (int) $credito['credito_id'];
            $credito['saldo_pendiente'] = (float) $credito['saldo_pendiente'];
            $credito['total_venta']     = (float) $credito['total_venta'];
            $credito['mora_mensual']    = (float) $credito['mora_mensual'];
            $credito = array_merge($credito, cartera_analizar($credito, $hoy, $config['dias_alerta']));

            // Qué se llevó
            $stmt = $pdo->prepare(
                'SELECT d.cantidad, d.precio_unitario, d.subtotal, p.nombre, p.unidad
                   FROM venta_detalles d
                   JOIN productos p ON p.id = d.producto_id
                  WHERE d.venta_id = ?'
            );
            $stmt->execute([$credito['venta_id']]);
            $productos = $stmt->fetchAll();

            // Qué ha pagado
            $stmt = $pdo->prepare(
                'SELECT id, fecha, monto, metodo_pago FROM abonos WHERE credito_id = ? ORDER BY fecha'
            );
            $stmt->execute([$creditoId]);
            $abonos = $stmt->fetchAll();

            // Qué se ha pactado
            $stmt = $pdo->prepare(
                'SELECT id, fecha_acuerdo, fecha_antes, fecha_nueva, monto_pactado, observacion
                   FROM acuerdos_pago WHERE credito_id = ? ORDER BY fecha_acuerdo'
            );
            $stmt->execute([$creditoId]);
            $acuerdos = $stmt->fetchAll();

            echo json_encode([
                'credito'   => $credito,
                'productos' => $productos,
                'abonos'    => $abonos,
                'acuerdos'  => $acuerdos,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---------------------------------------------------------
        // Listado de deudores
        // ---------------------------------------------------------
        $incluirPagados = !empty($_GET['incluir_pagados']);
        $creditos = cartera_creditos($pdo, $hoy, $config['dias_alerta'], $incluirPagados);

        $filtro = $_GET['filtro'] ?? 'todos';
        if (in_array($filtro, ['vencidos', 'por_vencer', 'al_dia', 'pagados'], true)) {
            $mapa = ['vencidos' => 'rojo', 'por_vencer' => 'amarillo', 'al_dia' => 'verde', 'pagados' => 'gris'];
            $creditos = array_values(array_filter(
                $creditos,
                fn($c) => $c['semaforo'] === $mapa[$filtro]
            ));
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $aguja = mb_strtolower($q, 'UTF-8');
            $creditos = array_values(array_filter($creditos, function ($c) use ($aguja) {
                return str_contains(mb_strtolower((string) $c['cliente_nombre'], 'UTF-8'), $aguja)
                    || str_contains((string) $c['cliente_cedula'], $aguja);
            }));
        }

        echo json_encode($creditos, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===============================================================
    // ESCRITURA
    // ===============================================================
    if ($metodo !== 'POST') {
        minervox_error(405, 'Método no permitido');
    }

    $datos  = minervox_leer_json();
    $accion = $_GET['action'] ?? '';

    // ---------------------------------------------------------------
    // Acuerdo de pago: el cliente no pudo, se le corre la fecha
    // ---------------------------------------------------------------
    if ($accion === 'acuerdo') {
        $creditoId   = (int) ($datos['credito_id'] ?? 0);
        $fechaNueva  = minervox_fecha_valida($datos['fecha_nueva'] ?? null);
        $montoPactado = minervox_numero($datos['monto_pactado'] ?? 0);
        $observacion = trim((string) ($datos['observacion'] ?? ''));

        if ($creditoId <= 0) {
            minervox_error(400, 'Falta el fiao del acuerdo');
        }
        if (!$fechaNueva) {
            minervox_error(400, 'La nueva fecha de pago no es válida');
        }
        if ($fechaNueva < $hoy->format('Y-m-d')) {
            minervox_error(400, 'La nueva fecha no puede ser anterior a hoy');
        }
        if ($montoPactado <= 0) {
            minervox_error(400, 'El monto pactado debe ser mayor que cero');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT estado, fecha_vencimiento, saldo_pendiente FROM cartera_creditos WHERE id = ? FOR UPDATE');
        $stmt->execute([$creditoId]);
        $credito = $stmt->fetch();

        if (!$credito) {
            $pdo->rollBack();
            minervox_error(404, 'Ese fiao no existe');
        }
        if ($credito['estado'] === 'pagado') {
            $pdo->rollBack();
            minervox_error(400, 'Ese fiao ya está pagado: no tiene sentido un acuerdo');
        }
        if ($montoPactado < (float) $credito['saldo_pendiente']) {
            $pdo->rollBack();
            minervox_error(400, 'El monto pactado no puede ser menor que el saldo que se debe');
        }

        $pdo->prepare(
            'INSERT INTO acuerdos_pago (credito_id, fecha_antes, fecha_nueva, monto_pactado, observacion)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $creditoId,
            $credito['fecha_vencimiento'],
            $fechaNueva,
            $montoPactado,
            $observacion !== '' ? $observacion : null,
        ]);

        $pdo->prepare('UPDATE cartera_creditos SET fecha_vencimiento = ? WHERE id = ?')
            ->execute([$fechaNueva, $creditoId]);

        $pdo->commit();

        echo json_encode([
            'success'         => true,
            'fecha_anterior'  => $credito['fecha_vencimiento'],
            'fecha_nueva'     => $fechaNueva,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------------------------------------------------------------
    // Abono (el minero viene a pagar su fiao)
    // ---------------------------------------------------------------
    $creditoId   = (int) ($datos['credito_id'] ?? 0);
    $monto       = minervox_numero($datos['monto'] ?? 0);
    $metodoPago  = minervox_metodo_pago($datos['metodo_pago'] ?? 'efectivo');

    if ($creditoId <= 0) {
        minervox_error(400, 'Falta el crédito al que se le abona');
    }
    if ($monto <= 0) {
        minervox_error(400, 'El monto del abono debe ser mayor que cero');
    }

    $pdo->beginTransaction();
    try {
        // Se bloquea el crédito para que dos abonos simultáneos no descuadren el saldo.
        $stmt = $pdo->prepare(
            'SELECT saldo_pendiente, estado, fecha_vencimiento, mora_mensual
               FROM cartera_creditos WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$creditoId]);
        $credito = $stmt->fetch();

        if (!$credito) {
            throw new InvalidArgumentException('El crédito no existe');
        }
        if ($credito['estado'] === 'pagado') {
            throw new InvalidArgumentException('Ese fiao ya está pagado');
        }

        // La mora se recalcula al momento de cobrar, con la tasa congelada
        // del fiao. Se puede abonar hasta el saldo + la mora: si el cliente
        // paga también la mora, ese dinero entra a la caja y queda registrado
        // en el abono (si no, el arqueo del día no cuadraría).
        $mora   = cartera_analizar($credito, $hoy, $config['dias_alerta'])['mora'];
        $saldo  = (float) $credito['saldo_pendiente'];
        $maximo = round($saldo + $mora, 2);

        if ($monto > $maximo) {
            throw new InvalidArgumentException('El abono es mayor que la deuda más la mora');
        }

        $nuevoSaldo  = max(0, round($saldo - $monto, 2));
        $moraCobrada = max(0, round($monto - $saldo, 2));

        $pdo->prepare('INSERT INTO abonos (credito_id, monto, metodo_pago) VALUES (?, ?, ?)')
            ->execute([$creditoId, $monto, $metodoPago]);

        $pdo->prepare('UPDATE cartera_creditos SET saldo_pendiente = ?, estado = ? WHERE id = ?')
            ->execute([$nuevoSaldo, $nuevoSaldo <= 0 ? 'pagado' : 'activo', $creditoId]);

        $pdo->commit();

        echo json_encode([
            'success'         => true,
            'saldo_pendiente' => $nuevoSaldo,
            'mora_cobrada'    => $moraCobrada,
            'metodo_pago'     => $metodoPago,
            'saldado'         => $nuevoSaldo <= 0,
        ], JSON_UNESCAPED_UNICODE);

    } catch (InvalidArgumentException $e) {
        $pdo->rollBack();
        minervox_error(400, $e->getMessage());
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[MINERVOX][cartera] ' . $e->getMessage());
        minervox_error(500, 'No se pudo registrar el abono');
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[MINERVOX][cartera] ' . $e->getMessage());
    minervox_error(500, 'No se pudo consultar la cartera');
}
