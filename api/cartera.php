<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utilidades.php';

manejar_api(function () use ($pdo): void {
    $metodo = requerir_metodo('GET', 'POST');

    if ($metodo === 'GET') {
        $accion = $_GET['action'] ?? 'list';

        if ($accion === 'dashboard') {
            $capitalInventario = (float) $pdo->query(
                'SELECT COALESCE(SUM(precio_costo * stock), 0) FROM productos WHERE activo = 1'
            )->fetchColumn();

            $ventasContadoHoy = (float) $pdo->query(
                "SELECT COALESCE(SUM(total), 0) FROM ventas
                  WHERE tipo_venta = 'contado'
                    AND fecha >= CURDATE() AND fecha < CURDATE() + INTERVAL 1 DAY"
            )->fetchColumn();

            $abonosHoy = (float) $pdo->query(
                'SELECT COALESCE(SUM(monto), 0) FROM abonos
                  WHERE fecha >= CURDATE() AND fecha < CURDATE() + INTERVAL 1 DAY'
            )->fetchColumn();

            $plataCalle = (float) $pdo->query(
                "SELECT COALESCE(SUM(saldo_pendiente), 0) FROM cartera_creditos WHERE estado = 'activo'"
            )->fetchColumn();

            $creditosActivos = (int) $pdo->query(
                "SELECT COUNT(*) FROM cartera_creditos WHERE estado = 'activo'"
            )->fetchColumn();

            $creditosVencidos = (int) $pdo->query(
                "SELECT COUNT(*) FROM cartera_creditos
                  WHERE estado = 'activo' AND fecha_vencimiento < CURDATE()"
            )->fetchColumn();

            responder([
                'capital_inventario'  => round($capitalInventario, 2),
                'dinero_caja'         => round($ventasContadoHoy + $abonosHoy, 2),
                'plata_calle'         => round($plataCalle, 2),
                'ventas_contado_hoy'  => round($ventasContadoHoy, 2),
                'abonos_hoy'          => round($abonosHoy, 2),
                'creditos_activos'    => $creditosActivos,
                'creditos_vencidos'   => $creditosVencidos,
            ]);
        }

        if ($accion !== 'list') {
            throw new ErrorApi('Acción no válida.', 400);
        }

        $sql = "
            SELECT
                cc.id                 AS credito_id,
                cc.saldo_pendiente,
                cc.fecha_vencimiento,
                cc.estado,
                v.fecha               AS fecha_venta,
                v.total               AS total_venta,
                c.nombre              AS cliente_nombre,
                c.cedula              AS cliente_cedula,
                c.telefono            AS cliente_telefono,
                DATEDIFF(cc.fecha_vencimiento, CURDATE()) AS dias_restantes,
                CASE
                    WHEN cc.estado = 'pagado' THEN 'gray'
                    WHEN cc.fecha_vencimiento < CURDATE() THEN 'red'
                    WHEN cc.fecha_vencimiento <= CURDATE() + INTERVAL 2 DAY THEN 'yellow'
                    ELSE 'green'
                END                   AS color
            FROM cartera_creditos cc
            JOIN ventas v ON cc.venta_id = v.id
            JOIN clientes c ON v.cliente_id = c.id
            ORDER BY (cc.estado = 'activo') DESC, cc.fecha_vencimiento ASC, cc.id ASC
        ";

        $creditos = $pdo->query($sql)->fetchAll();

        foreach ($creditos as &$credito) {
            $credito['credito_id'] = (int) $credito['credito_id'];
            $credito['saldo_pendiente'] = (float) $credito['saldo_pendiente'];
            $credito['total_venta'] = (float) $credito['total_venta'];
            $credito['dias_restantes'] = (int) $credito['dias_restantes'];
        }
        unset($credito);

        responder($creditos);
    }

    // POST: registrar abono
    $datos = leer_json();
    $creditoId = entero_requerido($datos, 'credito_id', 'Crédito');
    $monto = round(numero_requerido($datos, 'monto', 'Monto del abono', 0.01, 9999999999.99), 2);

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT saldo_pendiente, estado FROM cartera_creditos WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$creditoId]);
        $credito = $stmt->fetch();

        if (!$credito) {
            throw new ErrorApi('El crédito no existe.', 404);
        }

        if ($credito['estado'] !== 'activo') {
            throw new ErrorApi('Este crédito ya está pagado.', 409);
        }

        $saldoActual = (float) $credito['saldo_pendiente'];

        if ($monto > $saldoActual + 0.004) {
            throw new ErrorApi('El abono no puede superar el saldo pendiente (' . numero_a_texto($saldoActual) . ').');
        }

        $stmt = $pdo->prepare('INSERT INTO abonos (credito_id, monto) VALUES (?, ?)');
        $stmt->execute([$creditoId, $monto]);

        $nuevoSaldo = max(0.0, round($saldoActual - $monto, 2));

        $stmt = $pdo->prepare(
            'UPDATE cartera_creditos SET saldo_pendiente = ?, estado = ? WHERE id = ?'
        );
        $stmt->execute([$nuevoSaldo, $nuevoSaldo <= 0 ? 'pagado' : 'activo', $creditoId]);

        $pdo->commit();

        responder([
            'success'         => true,
            'saldo_pendiente' => $nuevoSaldo,
            'estado'          => $nuevoSaldo <= 0 ? 'pagado' : 'activo',
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
});
