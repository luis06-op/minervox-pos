<?php
/**
 * MINERVOX · Ventas
 *
 * GET  ?action=list    -> lista de ventas del periodo (solo administrador)
 * GET  ?action=libro   -> libro de sustancias controladas (solo administrador)
 * POST                 -> registrar una venta
 * POST ?action=anular  -> anular una venta (solo administrador)
 *
 * FASE 1 — se congela el costo de cada producto en
 * `venta_detalles.costo_unitario`. Sin esto, la vista `v_utilidad_ventas`
 * daba costo 0 y una utilidad falsa del 100%.
 *
 * FASE 4 — controlados y anulación:
 *  · Si la venta lleva una sustancia controlada, se EXIGE cédula y nombre
 *    del cliente, así sea de contado. No es un capricho: es el rastro que
 *    pide la autoridad para este tipo de productos.
 *  · Si el negocio marcó "pedir cédula en todo contado", se exige en toda
 *    venta de contado.
 *  · Anular devuelve el producto al inventario, deja el rastro en el
 *    kardex y NO toca la plata que ya entró: si el fiao tenía abonos, o
 *    el día ya tenía cierre de caja, la anulación se rechaza y se explica
 *    qué hacer en su lugar.
 *
 * Reglas que se cumplen aquí:
 *  - TODO el dinero se calcula en el servidor. El total que manda el
 *    frontend se ignora.
 *  - El cajero SÍ puede editar el precio a mano (el carrito lo permite),
 *    así que se acepta, pero se calcula el subtotal en el servidor y se
 *    avisa cuando el precio se aparta de la lista.
 *  - El costo se lee de la base, nunca del frontend.
 *  - Todo va en una transacción: si algo falla, no se guarda nada.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
require_once 'utilidades.php';
require_once 'auth.php';

$METODOS_PAGO = ['efectivo', 'transferencia', 'otro'];
$TIPOS_VENTA  = ['contado', 'credito'];

/**
 * Responde con error y termina la ejecución.
 */
function responder_error(int $codigo, string $mensaje): void
{
    http_response_code($codigo);
    echo json_encode(['success' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Lee y valida el rango de fechas de la barra de filtros. */
function ventas_rango(): array
{
    $desde = minervox_fecha_valida($_GET['desde'] ?? null) ?? date('Y-m-d');
    $hasta = minervox_fecha_valida($_GET['hasta'] ?? null) ?? $desde;
    if ($hasta < $desde) {
        [$desde, $hasta] = [$hasta, $desde];
    }
    return [$desde, $hasta];
}

/**
 * Lista de ventas del periodo. Se muestran también las anuladas (en gris
 * y con el motivo): esconderlas haría creer que nunca existieron.
 */
function ventas_listar(PDO $pdo): void
{
    [$desde, $hasta] = ventas_rango();

    $q             = trim((string) ($_GET['q'] ?? ''));
    $solo_control  = ($_GET['controladas'] ?? '') === '1';

    $where  = 'DATE(v.fecha) BETWEEN ? AND ?';
    $params = [$desde, $hasta];

    if ($q !== '') {
        $like   = '%' . $q . '%';
        $where .= ' AND (c.cedula LIKE ? OR c.nombre LIKE ? OR u.nombre LIKE ?
                         OR EXISTS (SELECT 1 FROM venta_detalles d2
                                      JOIN productos p2 ON p2.id = d2.producto_id
                                     WHERE d2.venta_id = v.id AND p2.nombre LIKE ?))';
        array_push($params, $like, $like, $like, $like);
    }

    if ($solo_control) {
        $where .= ' AND EXISTS (SELECT 1 FROM venta_detalles d3
                                 WHERE d3.venta_id = v.id AND d3.controlado = 1)';
    }

    $sql = "SELECT v.id, v.fecha, v.tipo_venta, v.total, v.metodo_pago, v.anulada,
                   v.anulada_motivo, v.anulada_en,
                   c.cedula, c.nombre AS cliente_nombre,
                   u.nombre  AS vendedor,
                   ua.nombre AS anulada_por,
                   (SELECT COUNT(*) FROM venta_detalles d
                     WHERE d.venta_id = v.id AND d.controlado = 1) AS lineas_controladas,
                   (SELECT GROUP_CONCAT(CONCAT(p.nombre, ' x', CAST(d.cantidad AS DOUBLE))
                                        ORDER BY d.id SEPARATOR ', ')
                      FROM venta_detalles d
                      JOIN productos p ON p.id = d.producto_id
                     WHERE d.venta_id = v.id) AS detalle
              FROM ventas v
              LEFT JOIN clientes c ON c.id = v.cliente_id
              LEFT JOIN usuarios u ON u.id = v.usuario_id
              LEFT JOIN usuarios ua ON ua.id = v.anulada_por
             WHERE $where
             ORDER BY v.fecha DESC, v.id DESC
             LIMIT 300";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $filas = $stmt->fetchAll();

    // Los totales se calculan sobre TODO el periodo filtrado, no solo sobre
    // las 300 filas que se muestran.
    $stmtResumen = $pdo->prepare(
        "SELECT COUNT(*) AS cantidad,
                COALESCE(SUM(CASE WHEN v.anulada = 0 THEN v.total ELSE 0 END), 0) AS total,
                COALESCE(SUM(CASE WHEN v.anulada = 1 THEN 1 ELSE 0 END), 0) AS anuladas,
                COALESCE(SUM(EXISTS (SELECT 1 FROM venta_detalles d4
                                      WHERE d4.venta_id = v.id AND d4.controlado = 1)), 0) AS controladas
           FROM ventas v
           LEFT JOIN clientes c ON c.id = v.cliente_id
           LEFT JOIN usuarios u ON u.id = v.usuario_id
          WHERE $where"
    );
    $stmtResumen->execute($params);
    $resumen = $stmtResumen->fetch();

    $resumen['cantidad']   = (int) $resumen['cantidad'];
    $resumen['total']      = round((float) $resumen['total'], 2);
    $resumen['anuladas']   = (int) $resumen['anuladas'];
    $resumen['controladas'] = (int) $resumen['controladas'];

    foreach ($filas as $i => $f) {
        $filas[$i]['total'] = (float) $f['total'];
    }

    echo json_encode([
        'success'  => true,
        'desde'    => $desde,
        'hasta'    => $hasta,
        'ventas'   => $filas,
        'resumen'  => $resumen,
        'truncada' => $resumen['cantidad'] > count($filas),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Libro de sustancias controladas: una línea por producto vendido, con la
 * cédula y el nombre de quien lo compró. Es el papel que pide la autoridad.
 */
function ventas_libro(PDO $pdo): void
{
    [$desde, $hasta] = ventas_rango();

    $stmt = $pdo->prepare(
        'SELECT v.id AS venta_id, v.fecha, v.tipo_venta,
                c.cedula, c.nombre AS cliente, c.ubicacion,
                p.nombre AS producto, p.unidad,
                d.cantidad, d.precio_unitario, d.subtotal,
                u.nombre AS vendedor
           FROM venta_detalles d
           JOIN ventas v         ON v.id = d.venta_id AND v.anulada = 0
           JOIN productos p      ON p.id = d.producto_id
           LEFT JOIN clientes c  ON c.id = v.cliente_id
           LEFT JOIN usuarios u  ON u.id = v.usuario_id
          WHERE d.controlado = 1
            AND DATE(v.fecha) BETWEEN ? AND ?
          ORDER BY v.fecha ASC, v.id ASC'
    );
    $stmt->execute([$desde, $hasta]);
    $filas = $stmt->fetchAll();

    $totales = ['lineas' => 0, 'unidades' => 0.0, 'valor' => 0.0];
    foreach ($filas as $i => $f) {
        $totales['lineas']++;
        $totales['unidades'] += (float) $f['cantidad'];
        $totales['valor']    += (float) $f['subtotal'];
        $filas[$i]['cantidad']        = (float) $f['cantidad'];
        $filas[$i]['precio_unitario'] = (float) $f['precio_unitario'];
        $filas[$i]['subtotal']        = (float) $f['subtotal'];
    }
    $totales['unidades'] = round($totales['unidades'], 2);
    $totales['valor']    = round($totales['valor'], 2);

    echo json_encode([
        'success' => true,
        'desde'   => $desde,
        'hasta'   => $hasta,
        'filas'   => $filas,
        'totales' => $totales,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Anula una venta. Devuelve el producto al inventario y deja el rastro.
 *
 * Lo que NO hace, a propósito: devolver plata. Anular no puede sacar
 * efectivo de la caja sin que nadie lo cuente. Por eso:
 *   · si el fiao ya tiene abonos o acuerdos -> se rechaza,
 *   · si el día ya tiene cierre de caja     -> se rechaza (el arqueo de
 *     ese día ya se contó y no se puede reescribir ayer),
 *   · si el cierre es de HOY -> se permite y se avisa que hay que volver
 *     a guardar el cierre.
 * En esos casos la devolución se registra como gasto "Devoluciones".
 */
function ventas_anular(PDO $pdo, array $usuario): void
{
    $data     = minervox_leer_json();
    $venta_id = (int) ($data['venta_id'] ?? 0);
    $motivo   = trim((string) ($data['motivo'] ?? ''));

    if ($venta_id <= 0) {
        responder_error(400, 'Falta la venta que se quiere anular');
    }
    if (mb_strlen($motivo) < 5) {
        responder_error(400, 'Escriba el motivo de la anulación (mínimo 5 letras)');
    }
    if (mb_strlen($motivo) > 255) {
        $motivo = mb_substr($motivo, 0, 255);
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT id, tipo_venta, fecha, total, anulada
               FROM ventas WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$venta_id]);
        $venta = $stmt->fetch();

        if (!$venta) {
            throw new InvalidArgumentException('Esa venta no existe');
        }
        if ((int) $venta['anulada'] === 1) {
            throw new InvalidArgumentException('Esa venta ya estaba anulada');
        }

        $advertencias = [];

        // --- Cartera: si es fiao, mirar si ya entró plata -----------------
        $credito = null;
        if ($venta['tipo_venta'] === 'credito') {
            $stmt = $pdo->prepare(
                'SELECT id, saldo_pendiente FROM cartera_creditos WHERE venta_id = ? FOR UPDATE'
            );
            $stmt->execute([$venta_id]);
            $credito = $stmt->fetch();

            if ($credito) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM abonos WHERE credito_id = ?');
                $stmt->execute([$credito['id']]);
                if ((int) $stmt->fetchColumn() > 0) {
                    throw new InvalidArgumentException(
                        'Ese fiao ya tiene abonos registrados: no se puede anular. ' .
                        'Devuelva la plata y regístrela como gasto en "Devoluciones".'
                    );
                }

                $stmt = $pdo->prepare('SELECT COUNT(*) FROM acuerdos_pago WHERE credito_id = ?');
                $stmt->execute([$credito['id']]);
                if ((int) $stmt->fetchColumn() > 0) {
                    throw new InvalidArgumentException(
                        'Ese fiao tiene acuerdos de pago registrados: no se puede anular.'
                    );
                }
            }
        }

        // --- Cierre de caja ------------------------------------------------
        $fecha_venta = substr((string) $venta['fecha'], 0, 10);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM cierres_caja WHERE DATE(fecha) = ?');
        $stmt->execute([$fecha_venta]);
        $hay_cierre = (int) $stmt->fetchColumn() > 0;
        $es_hoy     = $fecha_venta === date('Y-m-d');

        if ($hay_cierre && !$es_hoy) {
            throw new InvalidArgumentException(
                'Ese día ya tiene cierre de caja guardado: no se puede anular. ' .
                'Si es una devolución, regístrela como gasto en "Devoluciones".'
            );
        }
        if ($hay_cierre && $es_hoy) {
            $advertencias[] = 'Vuelva a guardar el cierre de caja de hoy para que el arqueo quede al día.';
        }

        // --- Devolver el producto al inventario ----------------------------
        $stmt = $pdo->prepare(
            'SELECT producto_id, cantidad FROM venta_detalles WHERE venta_id = ? ORDER BY id'
        );
        $stmt->execute([$venta_id]);
        $lineas = $stmt->fetchAll();

        $stmtStock = $pdo->prepare('UPDATE productos SET stock = stock + ? WHERE id = ?');
        $stmtLeer  = $pdo->prepare('SELECT stock FROM productos WHERE id = ?');
        $stmtMov   = $pdo->prepare(
            'INSERT INTO movimientos_stock
                (producto_id, tipo, cantidad, stock_resultante, referencia_id, nota, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($lineas as $l) {
            $stmtStock->execute([$l['cantidad'], $l['producto_id']]);

            $stmtLeer->execute([$l['producto_id']]);
            $stock_resultante = (float) $stmtLeer->fetchColumn();

            $stmtMov->execute([
                $l['producto_id'],
                'anulacion',
                (float) $l['cantidad'],   // positivo: vuelve a entrar
                $stock_resultante,
                $venta_id,
                'Anulación venta #' . $venta_id,
                $usuario['id'],
            ]);
        }

        // --- El fiao sin hijos se borra: esa deuda nunca existió ----------
        if ($credito) {
            $pdo->prepare('DELETE FROM cartera_creditos WHERE id = ?')->execute([$credito['id']]);
        }

        $pdo->prepare(
            'UPDATE ventas
                SET anulada = 1, anulada_motivo = ?, anulada_en = NOW(), anulada_por = ?
              WHERE id = ?'
        )->execute([$motivo, $usuario['id'], $venta_id]);

        $pdo->commit();

        echo json_encode([
            'success'      => true,
            'venta_id'     => $venta_id,
            'advertencias' => $advertencias,
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (InvalidArgumentException $e) {
        $pdo->rollBack();
        responder_error(400, $e->getMessage());

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[MINERVOX][ventas:anular] ' . $e->getMessage());
        responder_error(500, 'No se pudo anular la venta. Intente de nuevo.');
    }
}

// =====================================================================
// Reparto de rutas
// =====================================================================
$metodo = $_SERVER['REQUEST_METHOD'];
$accion = $_GET['action'] ?? '';

if ($metodo === 'GET') {
    minervox_exigir_admin($pdo);

    if ($accion === 'libro') {
        ventas_libro($pdo);
    }
    ventas_listar($pdo);
}

if ($metodo !== 'POST') {
    responder_error(405, 'Método no permitido');
}

if ($accion === 'anular') {
    ventas_anular($pdo, minervox_exigir_admin($pdo));
}

// FASE 5: solo vende quien haya entrado al sistema, y la venta queda con
// el nombre de quien la hizo (para poder reclamar después).
$usuario = minervox_exigir_sesion($pdo);

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    responder_error(400, 'Los datos de la venta no son válidos');
}

// ---------------------------------------------------------------
// 1. Validación de la entrada
// ---------------------------------------------------------------
$tipo_venta = (string) ($data['tipo_venta'] ?? '');
if (!in_array($tipo_venta, $TIPOS_VENTA, true)) {
    responder_error(400, 'El tipo de venta debe ser "contado" o "credito"');
}

$metodo_pago = (string) ($data['metodo_pago'] ?? 'efectivo');
if (!in_array($metodo_pago, $METODOS_PAGO, true)) {
    $metodo_pago = 'efectivo';
}

$detalles = $data['detalles'] ?? null;
if (!is_array($detalles) || count($detalles) === 0) {
    responder_error(400, 'La venta no tiene productos');
}

// El abono inicial solo tiene sentido en un fiao.
$abono_inicial = $tipo_venta === 'credito' ? (float) ($data['abono_inicial'] ?? 0) : 0.0;
if ($abono_inicial < 0) {
    responder_error(400, 'El abono inicial no puede ser negativo');
}

$fecha_vencimiento = null;
if ($tipo_venta === 'credito') {
    $fecha_vencimiento = (string) ($data['fecha_vencimiento'] ?? '');
    $valida = DateTime::createFromFormat('Y-m-d', $fecha_vencimiento);
    if (!$valida || $valida->format('Y-m-d') !== $fecha_vencimiento) {
        responder_error(400, 'La fecha de vencimiento del fiao no es válida');
    }
}

$pdo->beginTransaction();

try {
    // -----------------------------------------------------------
    // 2. Validar cada línea, congelar el costo y calcular el total
    //    en el servidor. FOR UPDATE evita que dos cajas vendan la
    //    última unidad al mismo tiempo.
    // -----------------------------------------------------------
    $stmtProd = $pdo->prepare(
        'SELECT id, nombre, precio_contado, precio_credito, precio_costo, stock, activo, controlado
           FROM productos WHERE id = ? FOR UPDATE'
    );

    $lineas         = [];
    $total          = 0.0;
    $advertencias   = [];
    $hay_controlado = false;

    foreach ($detalles as $detalle) {
        $producto_id = (int) ($detalle['producto_id'] ?? 0);
        $cantidad    = (float) ($detalle['cantidad'] ?? 0);

        if ($producto_id <= 0) {
            throw new InvalidArgumentException('Hay un producto inválido en la venta');
        }
        if ($cantidad <= 0) {
            throw new InvalidArgumentException('Todas las cantidades deben ser mayores que cero');
        }

        $stmtProd->execute([$producto_id]);
        $prod = $stmtProd->fetch();

        if (!$prod) {
            throw new InvalidArgumentException("El producto $producto_id no existe");
        }
        if ((int) $prod['activo'] !== 1) {
            throw new InvalidArgumentException("El producto {$prod['nombre']} está inactivo");
        }

        // El precio lo define el cajero (puede negociar), pero el
        // subtotal y el total los calcula el servidor.
        $precio_lista   = (float) ($tipo_venta === 'contado' ? $prod['precio_contado'] : $prod['precio_credito']);
        $precio_vendido = (float) ($detalle['precio_unitario'] ?? $precio_lista);

        if ($precio_vendido < 0) {
            throw new InvalidArgumentException("El precio de {$prod['nombre']} no puede ser negativo");
        }

        // Auditoría: si el cajero se apartó de la lista, dejar constancia.
        if ($precio_lista > 0 && abs($precio_vendido - $precio_lista) > 0.01) {
            $advertencias[] = sprintf(
                'Se vendió %s a %s (precio de lista: %s)',
                $prod['nombre'],
                number_format($precio_vendido, 0, ',', '.'),
                number_format($precio_lista, 0, ',', '.')
            );
        }

        $subtotal = round($cantidad * $precio_vendido, 2);
        $total   += $subtotal;

        if ((int) $prod['controlado'] === 1) {
            $hay_controlado = true;
        }

        $lineas[] = [
            'producto_id'     => $producto_id,
            'nombre'          => $prod['nombre'],
            'cantidad'        => $cantidad,
            'precio_unitario' => $precio_vendido,
            'costo_unitario'  => (float) $prod['precio_costo'],   // <- el costo se congela aquí
            'subtotal'        => $subtotal,
            'stock_actual'    => (float) $prod['stock'],
            'controlado'      => (int) $prod['controlado'],       // <- el flag también
        ];
    }

    $total = round($total, 2);

    if ($abono_inicial > $total) {
        throw new InvalidArgumentException('El abono inicial no puede ser mayor que el total de la venta');
    }

    // -----------------------------------------------------------
    // 3. Cliente
    //
    //  · Fiao: siempre (es a quién se le cobra).
    //  · Sustancia controlada: siempre, así sea de contado (es el rastro
    //    legal de quién la compró).
    //  · Contado normal: solo si el negocio lo pidió en Ajustes.
    // -----------------------------------------------------------
    $cliente_id = null;

    $exigir_contado = (int) $pdo->query(
        'SELECT exigir_cedula_contado FROM configuracion WHERE id = 1'
    )->fetchColumn() === 1;

    $exige_cliente = $tipo_venta === 'credito' || $hay_controlado || $exigir_contado;

    if ($exige_cliente) {
        $cli     = is_array($data['cliente'] ?? null) ? $data['cliente'] : [];
        $cedula  = trim((string) ($cli['cedula'] ?? ''));
        $nombre  = trim((string) ($cli['nombre'] ?? ''));

        if ($cedula === '' || $nombre === '') {
            if ($tipo_venta === 'credito') {
                throw new InvalidArgumentException('Para fiar se necesita la cédula y el nombre del cliente');
            }
            if ($hay_controlado) {
                throw new InvalidArgumentException(
                    'Para vender una sustancia controlada se necesita la cédula y el nombre del cliente'
                );
            }
            throw new InvalidArgumentException(
                'El negocio pide cédula y nombre del cliente en toda venta de contado'
            );
        }

        $telefono  = trim((string) ($cli['telefono'] ?? ''));
        $ubicacion = trim((string) ($cli['ubicacion'] ?? ''));

        $stmtCli = $pdo->prepare('SELECT id FROM clientes WHERE cedula = ? LIMIT 1');
        $stmtCli->execute([$cedula]);
        $existente = $stmtCli->fetch();

        if ($existente) {
            $cliente_id = (int) $existente['id'];
            $pdo->prepare('UPDATE clientes SET nombre = ?, telefono = ?, ubicacion = ? WHERE id = ?')
                ->execute([$nombre, $telefono, $ubicacion, $cliente_id]);
        } else {
            $pdo->prepare('INSERT INTO clientes (cedula, nombre, telefono, ubicacion) VALUES (?, ?, ?, ?)')
                ->execute([$cedula, $nombre, $telefono, $ubicacion]);
            $cliente_id = (int) $pdo->lastInsertId();
        }
    }

    // -----------------------------------------------------------
    // 4. Cabecera de la venta
    // -----------------------------------------------------------
    $pdo->prepare('INSERT INTO ventas (tipo_venta, total, cliente_id, metodo_pago, usuario_id) VALUES (?, ?, ?, ?, ?)')
        ->execute([$tipo_venta, $total, $cliente_id, $metodo_pago, $usuario['id']]);

    $venta_id = (int) $pdo->lastInsertId();

    // -----------------------------------------------------------
    // 5. Detalle, descuento de stock y rastro en el kardex
    // -----------------------------------------------------------
    $stmtDetalle = $pdo->prepare(
        'INSERT INTO venta_detalles (venta_id, producto_id, cantidad, precio_unitario, costo_unitario, subtotal, controlado)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmtStock = $pdo->prepare('UPDATE productos SET stock = stock - ? WHERE id = ?');
    $stmtMov   = $pdo->prepare(
        'INSERT INTO movimientos_stock (producto_id, tipo, cantidad, stock_resultante, referencia_id, nota, usuario_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($lineas as $l) {
        $stmtDetalle->execute([
            $venta_id,
            $l['producto_id'],
            $l['cantidad'],
            $l['precio_unitario'],
            $l['costo_unitario'],
            $l['subtotal'],
            $l['controlado'],
        ]);

        $stmtStock->execute([$l['cantidad'], $l['producto_id']]);

        $stock_resultante = round($l['stock_actual'] - $l['cantidad'], 2);

        $stmtMov->execute([
            $l['producto_id'],
            'venta',
            -1 * $l['cantidad'],   // negativo porque sale del inventario
            $stock_resultante,
            $venta_id,
            'Venta ' . $tipo_venta,
            $usuario['id'],
        ]);

        // Aviso (no bloquea la venta): el mostrador a veces vende con el
        // conteo atrasado y prefiere cuadrar el stock después.
        if ($stock_resultante < 0) {
            $advertencias[] = "El stock de {$l['nombre']} quedó en negativo";
        }
    }

    // -----------------------------------------------------------
    // 6. Cartera cuando es fiao
    // -----------------------------------------------------------
    if ($tipo_venta === 'credito') {
        $saldo_pendiente = round($total - $abono_inicial, 2);
        $estado          = $saldo_pendiente <= 0 ? 'pagado' : 'activo';

        // La tasa de mora se congela aquí, igual que el costo: si mañana
        // el negocio sube el interés, este fiao conserva el suyo.
        $mora = (float) $pdo->query('SELECT mora_mensual FROM configuracion WHERE id = 1')->fetchColumn();

        $pdo->prepare(
            'INSERT INTO cartera_creditos (venta_id, saldo_pendiente, fecha_vencimiento, estado, mora_mensual)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$venta_id, $saldo_pendiente, $fecha_vencimiento, $estado, $mora]);

        $credito_id = (int) $pdo->lastInsertId();

        if ($abono_inicial > 0) {
            $pdo->prepare('INSERT INTO abonos (credito_id, monto, metodo_pago, usuario_id) VALUES (?, ?, ?, ?)')
                ->execute([$credito_id, $abono_inicial, $metodo_pago, $usuario['id']]);
        }
    }

    $pdo->commit();

    $respuesta = [
        'success'      => true,
        'venta_id'     => $venta_id,
        'total'        => $total,
        'advertencias' => $advertencias,
    ];

    // FASE 5: cuánto se ganó en la venta es información del dueño; al cajero
    // no se le manda.
    if ($usuario['es_admin']) {
        $utilidad = 0.0;
        foreach ($lineas as $l) {
            $utilidad += $l['subtotal'] - ($l['cantidad'] * $l['costo_unitario']);
        }
        $respuesta['utilidad'] = round($utilidad, 2);
    }

    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);

} catch (InvalidArgumentException $e) {
    // Error de datos: se puede mostrar al usuario tal cual.
    $pdo->rollBack();
    responder_error(400, $e->getMessage());

} catch (Throwable $e) {
    // Error inesperado: no se filtra el detalle al frontend.
    $pdo->rollBack();
    error_log('[MINERVOX][ventas] ' . $e->getMessage());
    responder_error(500, 'No se pudo registrar la venta. Intente de nuevo.');
}
