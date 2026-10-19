<?php
/**
 * MINERVOX · Contabilidad "Mi Plata"  (FASE 1)
 *
 * Solo lectura (GET). Devuelve JSON para el tablero y los reportes.
 *
 * Acciones:
 *   ?action=resumen             -> las tarjetas grandes del tablero
 *   ?action=utilidad            -> serie de utilidad agrupada por día/semana/mes
 *   ?action=utilidad_productos  -> utilidad por producto
 *   ?action=utilidad_tipo       -> contado vs fiao
 *
 * Todas aceptan el rango con: periodo=hoy|semana|mes|anio|rango&desde=Y-m-d&hasta=Y-m-d
 *
 * La utilidad se calcula con `venta_detalles.costo_unitario` (el costo
 * congelado al momento de vender), así que los reportes de meses pasados
 * no cambian aunque hoy se actualice el costo de un producto.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
require_once 'auth.php';

// FASE 5: "Mi Plata" muestra la utilidad, el capital y los costos del
// negocio. Es información del dueño, no del mostrador.
minervox_exigir_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Devuelve la fecha si viene en formato Y-m-d válido; si no, null. */
function fecha_valida($valor): ?string
{
    if (!is_string($valor) || $valor === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $valor);
    if (!$d || $d->format('Y-m-d') !== $valor) {
        return null;
    }
    return $valor;
}

/**
 * Traduce el periodo pedido a un rango de fechas con hora.
 * Devuelve [desde_datetime, hasta_datetime, desde_dia, hasta_dia].
 */
function resolver_rango(array $q): array
{
    $hoy = new DateTimeImmutable('today');
    $periodo = is_string($q['periodo'] ?? null) ? $q['periodo'] : 'mes';

    switch ($periodo) {
        case 'hoy':
            $desde = $hoy;
            $hasta = $hoy;
            break;
        case 'semana':
            $desde = $hoy->modify('monday this week');
            $hasta = $hoy;
            break;
        case 'anio':
            $desde = $hoy->modify('first day of january');
            $hasta = $hoy;
            break;
        case 'rango':
            $d = fecha_valida($q['desde'] ?? null);
            $h = fecha_valida($q['hasta'] ?? null);
            $desde = $d ? new DateTimeImmutable($d) : $hoy->modify('first day of this month');
            $hasta = $h ? new DateTimeImmutable($h) : $hoy;
            break;
        case 'mes':
        default:
            $desde = $hoy->modify('first day of this month');
            $hasta = $hoy;
            break;
    }

    // Si el usuario los puso al revés, los enderezamos en vez de fallar.
    if ($desde > $hasta) {
        [$desde, $hasta] = [$hasta, $desde];
    }

    return [
        $desde->format('Y-m-d 00:00:00'),
        $hasta->format('Y-m-d 23:59:59'),
        $desde->format('Y-m-d'),
        $hasta->format('Y-m-d'),
    ];
}

/** Ejecuta un SELECT escalar y devuelve el número como float. */
function escalar(PDO $pdo, string $sql, array $params = []): float
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) ($stmt->fetchColumn() ?: 0);
}

$action = $_GET['action'] ?? 'resumen';
[$desde, $hasta, $desdeDia, $hastaDia] = resolver_rango($_GET);

try {
    switch ($action) {

        // -----------------------------------------------------------
        // Tarjetas del tablero
        // -----------------------------------------------------------
        case 'resumen':
            $inicioMes = (new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00');

            // Lo que hay guardado en el estante, a precio de compra.
            $capital = escalar(
                $pdo,
                'SELECT COALESCE(SUM(precio_costo * stock), 0) FROM productos WHERE activo = 1'
            );

            // Arqueo de hoy: solo dinero en efectivo que entró o salió.
            $ventasEfectivo = escalar(
                $pdo,
                "SELECT COALESCE(SUM(total), 0) FROM ventas
                  WHERE anulada = 0 AND tipo_venta = 'contado'
                    AND metodo_pago = 'efectivo' AND DATE(fecha) = CURDATE()"
            );
            $abonosEfectivo = escalar(
                $pdo,
                "SELECT COALESCE(SUM(monto), 0) FROM abonos
                  WHERE metodo_pago = 'efectivo' AND DATE(fecha) = CURDATE()"
            );
            $gastosEfectivo = escalar(
                $pdo,
                "SELECT COALESCE(SUM(monto), 0) FROM gastos
                  WHERE metodo_pago = 'efectivo' AND DATE(fecha) = CURDATE()"
            );
            $pagosProveedor = escalar(
                $pdo,
                "SELECT COALESCE(SUM(monto), 0) FROM pagos_proveedores
                  WHERE metodo_pago = 'efectivo' AND DATE(fecha) = CURDATE()"
            );

            $plataCalle = escalar(
                $pdo,
                "SELECT COALESCE(SUM(saldo_pendiente), 0) FROM cartera_creditos WHERE estado = 'activo'"
            );

            $utilidadMes = escalar(
                $pdo,
                'SELECT COALESCE(SUM(utilidad), 0) FROM v_utilidad_ventas WHERE fecha >= ?',
                [$inicioMes]
            );
            $ventasMes = escalar(
                $pdo,
                'SELECT COALESCE(SUM(total), 0) FROM ventas WHERE anulada = 0 AND fecha >= ?',
                [$inicioMes]
            );
            $gastosMes = escalar(
                $pdo,
                'SELECT COALESCE(SUM(monto), 0) FROM gastos WHERE fecha >= ?',
                [$inicioMes]
            );
            $porPagar = escalar(
                $pdo,
                "SELECT COALESCE(SUM(saldo_pendiente), 0) FROM compras WHERE estado = 'pendiente'"
            );
            $ventasHoy = escalar(
                $pdo,
                'SELECT COALESCE(SUM(total), 0) FROM ventas WHERE anulada = 0 AND DATE(fecha) = CURDATE()'
            );

            echo json_encode([
                'capital_inventario'  => $capital,
                'dinero_en_caja'      => $ventasEfectivo + $abonosEfectivo - $gastosEfectivo - $pagosProveedor,
                'plata_en_la_calle'   => $plataCalle,
                'utilidad_mes'        => $utilidadMes,
                'gastos_mes'          => $gastosMes,
                'cuentas_por_pagar'   => $porPagar,
                'ventas_hoy'          => $ventasHoy,
                'ventas_mes'          => $ventasMes,
                // Desglose del efectivo de hoy, para poder explicar el arqueo.
                'caja' => [
                    'ventas_efectivo'  => $ventasEfectivo,
                    'abonos_efectivo'  => $abonosEfectivo,
                    'gastos_efectivo'  => $gastosEfectivo,
                    'pagos_proveedor'  => $pagosProveedor,
                ],
                'desde' => $desdeDia,
                'hasta' => $hastaDia,
            ], JSON_UNESCAPED_UNICODE);
            break;

        // -----------------------------------------------------------
        // Serie de utilidad para el gráfico
        // -----------------------------------------------------------
        case 'utilidad':
            // Lista blanca: nunca se concatena algo que venga del usuario.
            $agrupadores = [
                'dia'    => "DATE(fecha)",
                'semana' => "DATE(DATE_SUB(fecha, INTERVAL WEEKDAY(fecha) DAY))",
                'mes'    => "DATE_FORMAT(fecha, '%Y-%m-01')",
            ];
            $agrupar = $_GET['agrupar'] ?? 'dia';
            if (!isset($agrupadores[$agrupar])) {
                $agrupar = 'dia';
            }
            $expresion = $agrupadores[$agrupar];

            $sql = "SELECT $expresion AS periodo,
                           SUM(total_vendido) AS ventas,
                           SUM(costo_total)   AS costo,
                           SUM(utilidad)      AS utilidad,
                           COUNT(*)           AS num_ventas
                      FROM v_utilidad_ventas
                     WHERE fecha BETWEEN ? AND ?
                     GROUP BY periodo
                     ORDER BY periodo ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$desde, $hasta]);
            $filas = $stmt->fetchAll();

            $serie = [];
            $totales = ['ventas' => 0.0, 'costo' => 0.0, 'utilidad' => 0.0, 'num_ventas' => 0];
            foreach ($filas as $f) {
                $serie[] = [
                    'periodo'    => $f['periodo'],
                    'ventas'     => (float) $f['ventas'],
                    'costo'      => (float) $f['costo'],
                    'utilidad'   => (float) $f['utilidad'],
                    'num_ventas' => (int) $f['num_ventas'],
                ];
                $totales['ventas']     += (float) $f['ventas'];
                $totales['costo']      += (float) $f['costo'];
                $totales['utilidad']   += (float) $f['utilidad'];
                $totales['num_ventas'] += (int) $f['num_ventas'];
            }

            echo json_encode([
                'desde'    => $desdeDia,
                'hasta'    => $hastaDia,
                'agrupar'  => $agrupar,
                'serie'    => $serie,
                'totales'  => $totales,
            ], JSON_UNESCAPED_UNICODE);
            break;

        // -----------------------------------------------------------
        // Utilidad por producto
        // -----------------------------------------------------------
        case 'utilidad_productos':
            $sql = 'SELECT p.id AS producto_id,
                           p.nombre,
                           p.unidad,
                           SUM(d.cantidad)                                AS cantidad,
                           SUM(d.subtotal)                                AS ventas,
                           SUM(d.cantidad * d.costo_unitario)             AS costo,
                           SUM(d.subtotal - (d.cantidad * d.costo_unitario)) AS utilidad
                      FROM venta_detalles d
                      JOIN ventas v    ON v.id = d.venta_id AND v.anulada = 0
                      JOIN productos p ON p.id = d.producto_id
                     WHERE v.fecha BETWEEN ? AND ?
                     GROUP BY p.id, p.nombre, p.unidad
                     ORDER BY utilidad DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$desde, $hasta]);
            $filas = $stmt->fetchAll();

            $productos = [];
            foreach ($filas as $f) {
                $ventas = (float) $f['ventas'];
                $productos[] = [
                    'producto_id' => (int) $f['producto_id'],
                    'nombre'      => $f['nombre'],
                    'unidad'      => $f['unidad'],
                    'cantidad'    => (float) $f['cantidad'],
                    'ventas'      => $ventas,
                    'costo'       => (float) $f['costo'],
                    'utilidad'    => (float) $f['utilidad'],
                    // Margen: qué tanto de cada peso vendido queda de ganancia.
                    'margen'      => $ventas > 0 ? round(((float) $f['utilidad'] / $ventas) * 100, 1) : 0.0,
                ];
            }

            echo json_encode([
                'desde'     => $desdeDia,
                'hasta'     => $hastaDia,
                'productos' => $productos,
            ], JSON_UNESCAPED_UNICODE);
            break;

        // -----------------------------------------------------------
        // Contado vs fiao
        // -----------------------------------------------------------
        case 'utilidad_tipo':
            $sql = 'SELECT tipo_venta,
                           SUM(total_vendido) AS ventas,
                           SUM(costo_total)   AS costo,
                           SUM(utilidad)      AS utilidad,
                           COUNT(*)           AS num_ventas
                      FROM v_utilidad_ventas
                     WHERE fecha BETWEEN ? AND ?
                     GROUP BY tipo_venta
                     ORDER BY tipo_venta';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$desde, $hasta]);
            $filas = $stmt->fetchAll();

            $tipos = [];
            foreach ($filas as $f) {
                $tipos[] = [
                    'tipo_venta' => $f['tipo_venta'],
                    'ventas'     => (float) $f['ventas'],
                    'costo'      => (float) $f['costo'],
                    'utilidad'   => (float) $f['utilidad'],
                    'num_ventas' => (int) $f['num_ventas'],
                ];
            }

            echo json_encode([
                'desde' => $desdeDia,
                'hasta' => $hastaDia,
                'tipos' => $tipos,
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Acción no reconocida'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    error_log('[MINERVOX][contabilidad] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo generar el reporte'], JSON_UNESCAPED_UNICODE);
}
