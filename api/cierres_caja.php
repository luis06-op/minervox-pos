<?php
/**
 * MINERVOX · Cierre de caja diario (arqueo)  (FASE 1)
 *
 * GET  ?action=calcular&fecha=Y-m-d  -> el arqueo del día, sin guardarlo
 * GET  ?action=list&desde=&hasta=    -> historial de cierres
 * POST {fecha, efectivo_inicial, efectivo_contado, observacion}
 *
 * La cuenta que hace el sistema:
 *   efectivo inicial
 *   + ventas de contado en efectivo
 *   + abonos en efectivo
 *   - gastos en efectivo
 *   - pagos a proveedores en efectivo
 *   = efectivo esperado
 *   y se compara contra lo que el cajero contó (diferencia).
 *
 * IMPORTANTE: las sumas SIEMPRE las recalcula el servidor. Lo único que
 * se recibe del frontend es cuánto contó el cajero.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
require_once 'utilidades.php';

/** Suma los movimientos en efectivo de un día concreto. */
function arqueo_del_dia(PDO $pdo, string $fecha): array
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total), 0) FROM ventas
          WHERE anulada = 0 AND tipo_venta = 'contado'
            AND metodo_pago = 'efectivo' AND DATE(fecha) = ?"
    );
    $stmt->execute([$fecha]);
    $ventas = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(monto), 0) FROM abonos
          WHERE metodo_pago = 'efectivo' AND DATE(fecha) = ?"
    );
    $stmt->execute([$fecha]);
    $abonos = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(monto), 0) FROM gastos
          WHERE metodo_pago = 'efectivo' AND DATE(fecha) = ?"
    );
    $stmt->execute([$fecha]);
    $gastos = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(monto), 0) FROM pagos_proveedores
          WHERE metodo_pago = 'efectivo' AND DATE(fecha) = ?"
    );
    $stmt->execute([$fecha]);
    $pagos = (float) $stmt->fetchColumn();

    return [
        'ventas_efectivo'         => $ventas,
        'abonos_efectivo'         => $abonos,
        'gastos_efectivo'         => $gastos,
        'pagos_proveedor_efectivo'=> $pagos,
    ];
}

/** Efectivo con el que se arrancó: lo contado en el último cierre anterior. */
function efectivo_inicial_sugerido(PDO $pdo, string $fecha): float
{
    $stmt = $pdo->prepare(
        'SELECT efectivo_contado FROM cierres_caja
          WHERE fecha < ? ORDER BY fecha DESC LIMIT 1'
    );
    $stmt->execute([$fecha]);
    $valor = $stmt->fetchColumn();

    return $valor === false ? 0.0 : (float) $valor;
}

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    if ($metodo === 'GET') {
        $accion = $_GET['action'] ?? 'calcular';

        if ($accion === 'list') {
            $condiciones = [];
            $parametros  = [];
            $desde = minervox_fecha_valida($_GET['desde'] ?? null);
            $hasta = minervox_fecha_valida($_GET['hasta'] ?? null);
            if ($desde) { $condiciones[] = 'fecha >= ?'; $parametros[] = $desde; }
            if ($hasta) { $condiciones[] = 'fecha <= ?'; $parametros[] = $hasta; }
            $where = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

            $stmt = $pdo->prepare("SELECT * FROM cierres_caja $where ORDER BY fecha DESC");
            $stmt->execute($parametros);
            $filas = $stmt->fetchAll();

            foreach ($filas as &$f) {
                foreach ([
                    'efectivo_inicial', 'ventas_efectivo', 'abonos_efectivo', 'gastos_efectivo',
                    'pagos_proveedor_efectivo', 'efectivo_esperado', 'efectivo_contado', 'diferencia',
                ] as $campo) {
                    $f[$campo] = (float) $f[$campo];
                }
            }
            unset($f);

            echo json_encode($filas, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- Calcular el arqueo del día ----
        $fecha = minervox_fecha_valida($_GET['fecha'] ?? null)
            ?? (new DateTimeImmutable('today'))->format('Y-m-d');

        // Si ya se guardó un cierre ese día, se devuelve tal cual.
        $stmt = $pdo->prepare('SELECT * FROM cierres_caja WHERE fecha = ? LIMIT 1');
        $stmt->execute([$fecha]);
        $guardado = $stmt->fetch();

        $movimientos = arqueo_del_dia($pdo, $fecha);

        if ($guardado) {
            $inicial = (float) $guardado['efectivo_inicial'];
        } else {
            $inicial = efectivo_inicial_sugerido($pdo, $fecha);
        }

        $esperado = round(
            $inicial
            + $movimientos['ventas_efectivo']
            + $movimientos['abonos_efectivo']
            - $movimientos['gastos_efectivo']
            - $movimientos['pagos_proveedor_efectivo'],
            2
        );

        echo json_encode([
            'fecha'                    => $fecha,
            'ya_cerrado'               => (bool) $guardado,
            'efectivo_inicial'         => $inicial,
            'ventas_efectivo'          => $movimientos['ventas_efectivo'],
            'abonos_efectivo'          => $movimientos['abonos_efectivo'],
            'gastos_efectivo'          => $movimientos['gastos_efectivo'],
            'pagos_proveedor_efectivo' => $movimientos['pagos_proveedor_efectivo'],
            'efectivo_esperado'        => $esperado,
            'efectivo_contado'         => $guardado ? (float) $guardado['efectivo_contado'] : null,
            'diferencia'               => $guardado ? (float) $guardado['diferencia'] : null,
            'observacion'              => $guardado['observacion'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

    } elseif ($metodo === 'POST') {
        $datos = minervox_leer_json();

        $fecha            = minervox_fecha_valida($datos['fecha'] ?? null)
            ?? (new DateTimeImmutable('today'))->format('Y-m-d');
        $efectivo_inicial = minervox_numero($datos['efectivo_inicial'] ?? 0);
        $efectivo_contado = minervox_numero($datos['efectivo_contado'] ?? 0);
        $observacion      = trim((string) ($datos['observacion'] ?? ''));

        if ($efectivo_inicial < 0 || $efectivo_contado < 0) {
            minervox_error(400, 'Los montos de efectivo no pueden ser negativos');
        }

        $pdo->beginTransaction();

        // Las sumas se recalculan aquí: nunca se confía en lo que manda el navegador.
        $movimientos = arqueo_del_dia($pdo, $fecha);

        $esperado = round(
            $efectivo_inicial
            + $movimientos['ventas_efectivo']
            + $movimientos['abonos_efectivo']
            - $movimientos['gastos_efectivo']
            - $movimientos['pagos_proveedor_efectivo'],
            2
        );
        $diferencia = round($efectivo_contado - $esperado, 2);

        $stmt = $pdo->prepare('SELECT id FROM cierres_caja WHERE fecha = ? LIMIT 1');
        $stmt->execute([$fecha]);
        $existente = $stmt->fetch();

        $parametros = [
            $efectivo_inicial,
            $movimientos['ventas_efectivo'],
            $movimientos['abonos_efectivo'],
            $movimientos['gastos_efectivo'],
            $movimientos['pagos_proveedor_efectivo'],
            $esperado,
            $efectivo_contado,
            $diferencia,
            $observacion !== '' ? $observacion : null,
        ];

        if ($existente) {
            // Volver a hacer el arqueo del mismo día lo actualiza, no lo duplica.
            $parametros[] = (int) $existente['id'];
            $pdo->prepare(
                'UPDATE cierres_caja
                    SET efectivo_inicial = ?, ventas_efectivo = ?, abonos_efectivo = ?,
                        gastos_efectivo = ?, pagos_proveedor_efectivo = ?, efectivo_esperado = ?,
                        efectivo_contado = ?, diferencia = ?, observacion = ?,
                        creado_en = CURRENT_TIMESTAMP
                  WHERE id = ?'
            )->execute($parametros);
            $cierre_id = (int) $existente['id'];
        } else {
            $pdo->prepare(
                'INSERT INTO cierres_caja
                   (fecha, efectivo_inicial, ventas_efectivo, abonos_efectivo, gastos_efectivo,
                    pagos_proveedor_efectivo, efectivo_esperado, efectivo_contado, diferencia, observacion)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute(array_merge([$fecha], $parametros));
            $cierre_id = (int) $pdo->lastInsertId();
        }

        $pdo->commit();

        echo json_encode([
            'success'           => true,
            'id'                => $cierre_id,
            'fecha'             => $fecha,
            'efectivo_esperado' => $esperado,
            'efectivo_contado'  => $efectivo_contado,
            'diferencia'        => $diferencia,
            // Lenguaje del negocio: faltó, sobró o cuadró.
            'resultado'         => $diferencia === 0.0 ? 'cuadro'
                                    : ($diferencia < 0 ? 'falto' : 'sobro'),
        ], JSON_UNESCAPED_UNICODE);

    } else {
        minervox_error(405, 'Método no permitido');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[MINERVOX][cierres_caja] ' . $e->getMessage());
    minervox_error(500, 'No se pudo procesar el cierre de caja');
}
