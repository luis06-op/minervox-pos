function generateReceiptHTML(data, config) {
    let date = new Date().toLocaleString('es-CO');
    
    let html = `
    <div style="font-family: 'Courier New', Courier, monospace; width: 260px; padding: 5px; color: #000; font-size: 11px; line-height: 1.2;">
        <div style="text-align: center; margin-bottom: 10px;">
            <h2 style="margin: 0; font-size: 16px;">${config.nombre_comercial}</h2>
            <p style="margin: 2px 0;">${config.razon_social}</p>
            <p style="margin: 2px 0;">NIT: ${config.nit}</p>
            <p style="margin: 2px 0;">${config.direccion}</p>
            <p style="margin: 2px 0;">Tel: ${config.telefonos}</p>
        </div>
        
        <div style="border-bottom: 1px dashed #000; margin-bottom: 5px; padding-bottom: 5px;">
            <p style="margin: 2px 0;"><strong>RECIBO DE VENTA ${data.tipo_venta.toUpperCase()}</strong></p>
            <p style="margin: 2px 0;">Fecha: ${date}</p>
            ${data.cliente_nombre ? `<p style="margin: 2px 0;">Cliente: ${data.cliente_nombre}</p>` : ''}
            ${data.tipo_venta === 'credito' ? `<p style="margin: 2px 0;">Vencimiento: ${data.fecha_vencimiento}</p>` : ''}
        </div>
        
        <table style="width: 100%; text-align: left; border-collapse: collapse; margin-bottom: 10px; table-layout: fixed;">
            <thead>
                <tr style="border-bottom: 1px dashed #000;">
                    <th style="width: 15%; padding: 2px 0;">Cant</th>
                    <th style="width: 45%; padding: 2px 0;">Prod</th>
                    <th style="width: 40%; text-align: right; padding: 2px 0;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                ${data.detalles.map(d => `
                <tr>
                    <td style="padding: 2px 0; vertical-align: top; word-wrap: break-word;">${d.cantidad}</td>
                    <td style="padding: 2px 0; vertical-align: top; word-wrap: break-word;">${d.nombre}<br><small>$${d.precio_unitario.toLocaleString('es-CO')}</small></td>
                    <td style="text-align: right; padding: 2px 0; vertical-align: top; word-wrap: break-word;">$${(d.cantidad * d.precio_unitario).toLocaleString('es-CO')}</td>
                </tr>
                `).join('')}
            </tbody>
        </table>
        
        <div style="border-top: 1px dashed #000; padding-top: 5px; text-align: right; font-size: 14px;">
            <strong>TOTAL: $${data.total.toLocaleString('es-CO')}</strong>
        </div>
        
        ${data.tipo_venta === 'credito' ? `
        <div style="margin-top: 15px; border: 1px solid #000; padding: 5px; text-align: justify; font-size: 10px;">
            <strong>PAGARÉ:</strong><br>
            ${config.texto_pagare}
            <br><br><br>
            __________________________<br>
            Firma del Cliente
        </div>
        ` : ''}
        
        <div style="text-align: center; margin-top: 15px;">
            <p style="margin: 0;">¡Gracias por su compra!</p>
        </div>
    </div>
    `;
    return html;
}

function printDocument(htmlContent, filename) {
    const container = document.getElementById('pdf-container');
    container.innerHTML = htmlContent;
    container.style.display = 'block'; // Make it measurable

    let opt = {
        margin:       5,
        filename:     filename,
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2 },
        jsPDF:        { unit: 'mm', format: [80, 200], orientation: 'portrait' } // Ticket format roughly
    };

    html2pdf().set(opt).from(container).save().then(() => {
        container.style.display = 'none';
        container.innerHTML = '';
    });
}

/**
 * Da formato de pesos colombianos: 1200000 -> "$ 1.200.000".
 */
function fmtPesos(valor) {
    return '$ ' + (parseFloat(valor) || 0).toLocaleString('es-CO');
}

/**
 * Escapa texto que viene de la base para que no rompa el HTML del reporte.
 */
function escaparHtml(texto) {
    return String(texto == null ? '' : texto)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/**
 * Encabezado y pie comunes a los reportes de contabilidad (tamaño carta).
 */
function _encabezadoReporte(config, titulo, subtitulo) {
    return `
    <div style="font-family: Arial, Helvetica, sans-serif; color: #111; padding: 10px;">
        <div style="text-align: center; border-bottom: 3px solid #1e3a8a; padding-bottom: 12px; margin-bottom: 16px;">
            <h1 style="margin: 0; font-size: 22px; color: #1e3a8a;">${escaparHtml(config.nombre_comercial)}</h1>
            <p style="margin: 3px 0; font-size: 12px;">${escaparHtml(config.razon_social)} &nbsp;|&nbsp; NIT: ${escaparHtml(config.nit)}</p>
            <p style="margin: 3px 0; font-size: 12px;">${escaparHtml(config.direccion)} &nbsp;|&nbsp; Tel: ${escaparHtml(config.telefonos)}</p>
            <h2 style="margin: 12px 0 0; font-size: 18px;">${escaparHtml(titulo)}</h2>
            <p style="margin: 4px 0 0; font-size: 13px; color: #555;">${escaparHtml(subtitulo)}</p>
        </div>`;
}

function _pieReporte() {
    return `
        <div style="margin-top: 20px; border-top: 1px solid #ccc; padding-top: 8px; font-size: 10px; color: #666; text-align: center;">
            Reporte generado por MINERVOX el ${new Date().toLocaleString('es-CO')}
        </div>
    </div>`;
}

/**
 * Reporte de "Mi Plata": tarjetas del tablero, utilidad por día y por producto.
 */
function generateReportePlataHTML(resumen, serie, productos, config) {
    const filas = (serie.serie || []).map(p => `
        <tr>
            <td style="padding: 6px; border-bottom: 1px solid #eee;">${escaparHtml(p.periodo)}</td>
            <td style="padding: 6px; border-bottom: 1px solid #eee; text-align: right;">${fmtPesos(p.ventas)}</td>
            <td style="padding: 6px; border-bottom: 1px solid #eee; text-align: right;">${fmtPesos(p.costo)}</td>
            <td style="padding: 6px; border-bottom: 1px solid #eee; text-align: right; font-weight: bold; color: #047857;">${fmtPesos(p.utilidad)}</td>
            <td style="padding: 6px; border-bottom: 1px solid #eee; text-align: center;">${p.num_ventas}</td>
        </tr>`).join('');

    const filasProductos = (productos || []).map(p => `
        <tr>
            <td style="padding: 6px; border-bottom: 1px solid #eee;">${escaparHtml(p.nombre)}</td>
            <td style="padding: 6px; border-bottom: 1px solid #eee; text-align: center;">${p.cantidad}</td>
            <td style="padding: 6px; border-bottom: 1px solid #eee; text-align: right;">${fmtPesos(p.ventas)}</td>
            <td style="padding: 6px; border-bottom: 1px solid #eee; text-align: right;">${fmtPesos(p.costo)}</td>
            <td style="padding: 6px; border-bottom: 1px solid #eee; text-align: right; font-weight: bold; color: #047857;">${fmtPesos(p.utilidad)}</td>
            <td style="padding: 6px; border-bottom: 1px solid #eee; text-align: right;">${p.margen}%</td>
        </tr>`).join('');

    const tarjeta = (titulo, valor, color) => `
        <td style="padding: 10px; border: 1px solid #ddd; border-radius: 6px; width: 33%;">
            <div style="font-size: 11px; color: #666;">${titulo}</div>
            <div style="font-size: 17px; font-weight: bold; color: ${color};">${valor}</div>
        </td>`;

    return `
    ${_encabezadoReporte(config, 'Mi Plata · Reporte de contabilidad',
        'Del ' + escaparHtml(resumen.desde || '') + ' al ' + escaparHtml(resumen.hasta || ''))}

        <table style="width: 100%; border-collapse: separate; border-spacing: 8px; margin-bottom: 16px;">
            <tr>
                ${tarjeta('Capital en inventario', fmtPesos(resumen.capital_inventario), '#1e3a8a')}
                ${tarjeta('Dinero en caja hoy', fmtPesos(resumen.dinero_en_caja), '#047857')}
                ${tarjeta('Plata en la calle', fmtPesos(resumen.plata_en_la_calle), '#b91c1c')}
            </tr>
            <tr>
                ${tarjeta('Utilidad del mes', fmtPesos(resumen.utilidad_mes), '#047857')}
                ${tarjeta('Gastos del mes', fmtPesos(resumen.gastos_mes), '#b45309')}
                ${tarjeta('Cuentas por pagar', fmtPesos(resumen.cuentas_por_pagar), '#6d28d9')}
            </tr>
        </table>

        <h3 style="font-size: 14px; margin: 16px 0 6px; color: #1e3a8a;">Utilidad por periodo</h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <thead>
                <tr style="background: #f3f4f6;">
                    <th style="padding: 6px; text-align: left;">Periodo</th>
                    <th style="padding: 6px; text-align: right;">Vendido</th>
                    <th style="padding: 6px; text-align: right;">Costó</th>
                    <th style="padding: 6px; text-align: right;">Utilidad</th>
                    <th style="padding: 6px; text-align: center;">Ventas</th>
                </tr>
            </thead>
            <tbody>
                ${filas || '<tr><td colspan="5" style="padding: 10px; text-align: center; color: #888;">Sin ventas en el periodo</td></tr>'}
            </tbody>
            <tfoot>
                <tr style="background: #f9fafb; font-weight: bold;">
                    <td style="padding: 6px;">TOTALES</td>
                    <td style="padding: 6px; text-align: right;">${fmtPesos(serie.totales ? serie.totales.ventas : 0)}</td>
                    <td style="padding: 6px; text-align: right;">${fmtPesos(serie.totales ? serie.totales.costo : 0)}</td>
                    <td style="padding: 6px; text-align: right; color: #047857;">${fmtPesos(serie.totales ? serie.totales.utilidad : 0)}</td>
                    <td style="padding: 6px; text-align: center;">${serie.totales ? serie.totales.num_ventas : 0}</td>
                </tr>
            </tfoot>
        </table>

        <h3 style="font-size: 14px; margin: 20px 0 6px; color: #1e3a8a;">Qué deja más plata (por producto)</h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <thead>
                <tr style="background: #f3f4f6;">
                    <th style="padding: 6px; text-align: left;">Producto</th>
                    <th style="padding: 6px; text-align: center;">Cant.</th>
                    <th style="padding: 6px; text-align: right;">Vendido</th>
                    <th style="padding: 6px; text-align: right;">Costó</th>
                    <th style="padding: 6px; text-align: right;">Utilidad</th>
                    <th style="padding: 6px; text-align: right;">Margen</th>
                </tr>
            </thead>
            <tbody>
                ${filasProductos || '<tr><td colspan="6" style="padding: 10px; text-align: center; color: #888;">Sin ventas en el periodo</td></tr>'}
            </tbody>
        </table>
    ${_pieReporte()}`;
}

/**
 * Comprobante de cierre de caja del día.
 */
function generateCierreCajaHTML(datos, config) {
    const linea = (etiqueta, valor, color) => `
        <tr>
            <td style="padding: 8px; border-bottom: 1px solid #eee; font-size: 13px;">${etiqueta}</td>
            <td style="padding: 8px; border-bottom: 1px solid #eee; text-align: right; font-weight: bold; color: ${color || '#111'};">${valor}</td>
        </tr>`;

    const diferencia = parseFloat(datos.diferencia) || 0;
    const estado = diferencia === 0 ? 'La caja cuadró' : (diferencia < 0 ? 'Faltó plata' : 'Sobró plata');
    const colorDif = diferencia === 0 ? '#047857' : (diferencia < 0 ? '#b91c1c' : '#b45309');

    return `
    ${_encabezadoReporte(config, 'Cierre de caja', 'Día: ' + escaparHtml(datos.fecha))}

        <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px;">
            ${linea('Efectivo inicial', fmtPesos(datos.efectivo_inicial))}
            ${linea('+ Ventas de contado en efectivo', fmtPesos(datos.ventas_efectivo))}
            ${linea('+ Abonos de fiaos en efectivo', fmtPesos(datos.abonos_efectivo))}
            ${linea('- Gastos en efectivo', fmtPesos(datos.gastos_efectivo), '#b45309')}
            ${linea('- Pagos a proveedores en efectivo', fmtPesos(datos.pagos_proveedor_efectivo), '#b45309')}
            ${linea('= Efectivo esperado', fmtPesos(datos.efectivo_esperado), '#1e3a8a')}
            ${linea('Efectivo contado por el cajero', fmtPesos(datos.efectivo_contado))}
        </table>

        <div style="border: 2px solid ${colorDif}; border-radius: 8px; padding: 12px; text-align: center; margin-bottom: 20px;">
            <div style="font-size: 12px; color: #666;">Diferencia</div>
            <div style="font-size: 24px; font-weight: bold; color: ${colorDif};">${fmtPesos(diferencia)}</div>
            <div style="font-size: 14px; font-weight: bold; color: ${colorDif};">${estado}</div>
        </div>

        <div style="font-size: 12px; margin-bottom: 30px;">
            <strong>Observación:</strong> ${escaparHtml(datos.observacion || 'Sin observaciones')}
        </div>

        <table style="width: 100%; margin-top: 40px; font-size: 11px; text-align: center;">
            <tr>
                <td style="width: 50%; border-top: 1px solid #000; padding-top: 4px;">Firma del cajero</td>
                <td style="width: 50%; border-top: 1px solid #000; padding-top: 4px;">Firma del administrador</td>
            </tr>
        </table>
    ${_pieReporte()}`;
}

/**
 * Reporte de cartera: el semáforo, quién debe, cuánto y desde cuándo.
 */
function generateCarteraHTML(resumen, deudores, config) {
    const color = { rojo: '#b91c1c', amarillo: '#b45309', verde: '#047857', gris: '#6b7280' };
    const etiqueta = { rojo: 'VENCIDO', amarillo: 'POR VENCER', verde: 'AL DÍA', gris: 'PAGADO' };

    // Fecha de hoy en hora local (no usar toISOString: en Colombia después
    // de las 7 p.m. devolvería el día siguiente).
    const ahora = new Date();
    const hoyISO = ahora.getFullYear() + '-' +
        String(ahora.getMonth() + 1).padStart(2, '0') + '-' +
        String(ahora.getDate()).padStart(2, '0');

    const filas = (deudores || []).map(d => `
        <tr>
            <td style="padding: 6px; border-bottom: 1px solid #eee; color: ${color[d.semaforo] || '#333'}; font-weight: bold; font-size: 10px;">${etiqueta[d.semaforo] || ''}</td>
            <td style="padding: 6px; border-bottom: 1px solid #eee;">${escaparHtml(d.cliente_nombre)}<br><span style="font-size: 10px; color: #666;">C.C. ${escaparHtml(d.cliente_cedula)}</span></td>
            <td style="padding: 6px; border-bottom: 1px solid #eee; font-size: 11px;">${escaparHtml(_fechaBonita(d.fecha_vencimiento))}</td>
            <td style="padding: 6px; border-bottom: 1px solid #eee; font-size: 11px; text-align: right; color: ${d.dias_mora > 0 ? '#b91c1c' : '#555'};">${d.dias_mora > 0 ? 'Mora ' + d.dias_mora + ' d.' : (d.dias + ' d.')}</td>
            <td style="padding: 6px; border-bottom: 1px solid #eee; text-align: right; color: #b91c1c;">${fmtPesos(d.mora)}</td>
            <td style="padding: 6px; border-bottom: 1px solid #eee; text-align: right;">${fmtPesos(d.saldo_pendiente)}</td>
            <td style="padding: 6px; border-bottom: 1px solid #eee; text-align: right; font-weight: bold;">${fmtPesos(d.total_a_pagar)}</td>
        </tr>`).join('');

    return `
    ${_encabezadoReporte(config, 'Cartera · Plata en la calle', 'Al ' + escaparHtml(_fechaBonita(hoyISO)))}

        <table style="width: 100%; font-size: 12px; margin-bottom: 16px; border-collapse: collapse;">
            <tr>
                <td style="padding: 8px; background: #eff6ff;">
                    <div style="color: #1e3a8a; font-weight: bold;">Plata en la calle</div>
                    <div style="font-size: 16px; font-weight: bold;">${fmtPesos(resumen.total_cartera)}</div>
                    <div style="font-size: 10px; color: #666;">${resumen.num_fiaos} fiao(s)</div>
                </td>
                <td style="padding: 8px; background: #fef2f2;">
                    <div style="color: #b91c1c; font-weight: bold;">Vencido</div>
                    <div style="font-size: 16px; font-weight: bold;">${fmtPesos(resumen.vencido ? resumen.vencido.monto : 0)}</div>
                    <div style="font-size: 10px; color: #666;">${resumen.vencido ? resumen.vencido.cantidad : 0} cliente(s)</div>
                </td>
                <td style="padding: 8px; background: #fffbeb;">
                    <div style="color: #b45309; font-weight: bold;">Por vencer</div>
                    <div style="font-size: 16px; font-weight: bold;">${fmtPesos(resumen.por_vencer ? resumen.por_vencer.monto : 0)}</div>
                    <div style="font-size: 10px; color: #666;">${resumen.por_vencer ? resumen.por_vencer.cantidad : 0} cliente(s)</div>
                </td>
                <td style="padding: 8px; background: #ecfdf5;">
                    <div style="color: #047857; font-weight: bold;">Al día</div>
                    <div style="font-size: 16px; font-weight: bold;">${fmtPesos(resumen.al_dia ? resumen.al_dia.monto : 0)}</div>
                    <div style="font-size: 10px; color: #666;">${resumen.al_dia ? resumen.al_dia.cantidad : 0} cliente(s)</div>
                </td>
            </tr>
        </table>

        <p style="font-size: 12px; color: #555;">
            Mora acumulada: <strong style="color: #b91c1c;">${fmtPesos(resumen.mora_total)}</strong>
            &nbsp;·&nbsp; Recuperado este mes: <strong style="color: #047857;">${fmtPesos(resumen.recuperado_mes)}</strong>
        </p>

        <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
            <thead>
                <tr style="background: #1e3a8a; color: #fff;">
                    <th style="padding: 6px; text-align: left;">Estado</th>
                    <th style="padding: 6px; text-align: left;">Cliente</th>
                    <th style="padding: 6px; text-align: left;">Vence</th>
                    <th style="padding: 6px; text-align: right;">Días</th>
                    <th style="padding: 6px; text-align: right;">Mora</th>
                    <th style="padding: 6px; text-align: right;">Saldo</th>
                    <th style="padding: 6px; text-align: right;">Total</th>
                </tr>
            </thead>
            <tbody>${filas || '<tr><td colspan="7" style="padding: 12px; text-align: center; color: #666;">No hay fiaos activos.</td></tr>'}</tbody>
        </table>

        <p style="font-size: 10px; color: #666; margin-top: 10px;">
            El semáforo se pone amarillo a los ${resumen.dias_alerta} días del vencimiento y la mora es del ${resumen.mora_mensual}% mensual sobre el saldo vencido.
        </p>
    ${_pieReporte()}`;
}

/**
 * Estado de cuenta de un fiao: qué se llevó, qué ha pagado y qué debe.
 */
function generateEstadoCuentaHTML(datos, config) {
    const c = datos.credito;
    const etiquetaMetodo = { efectivo: 'Efectivo', transferencia: 'Transferencia', otro: 'Otro' };

    const productos = (datos.productos || []).map(p => `
        <tr>
            <td style="padding: 5px; border-bottom: 1px solid #eee;">${escaparHtml(p.nombre)} <span style="color:#666; font-size: 11px;">(${escaparHtml(p.cantidad)} ${escaparHtml(p.unidad || '')})</span></td>
            <td style="padding: 5px; border-bottom: 1px solid #eee; text-align: right;">${fmtPesos(p.subtotal)}</td>
        </tr>`).join('');

    const abonos = (datos.abonos || []).map(a => `
        <tr>
            <td style="padding: 5px; border-bottom: 1px solid #eee;">${escaparHtml(_fechaBonita(a.fecha))} <span style="color:#666; font-size: 11px;">(${escaparHtml(etiquetaMetodo[a.metodo_pago] || a.metodo_pago)})</span></td>
            <td style="padding: 5px; border-bottom: 1px solid #eee; text-align: right; color: #047857;">${fmtPesos(a.monto)}</td>
        </tr>`).join('');

    const acuerdos = (datos.acuerdos || []).map(ac => `
        <tr>
            <td style="padding: 5px; border-bottom: 1px solid #eee; font-size: 11px;">
                Se corrió de ${escaparHtml(_fechaBonita(ac.fecha_antes))} a ${escaparHtml(_fechaBonita(ac.fecha_nueva))}
                ${ac.observacion ? '<br><span style="color:#666;">' + escaparHtml(ac.observacion) + '</span>' : ''}
            </td>
            <td style="padding: 5px; border-bottom: 1px solid #eee; text-align: right;">${fmtPesos(ac.monto_pactado)}</td>
        </tr>`).join('');

    return `
    ${_encabezadoReporte(config, 'Estado de cuenta', 'Fiao del ' + escaparHtml(_fechaBonita(c.fecha_venta)))}

        <p style="font-size: 13px; margin: 0 0 4px;"><strong>${escaparHtml(c.cliente_nombre)}</strong></p>
        <p style="font-size: 12px; color: #555; margin: 0 0 12px;">
            C.C. ${escaparHtml(c.cliente_cedula)}
            ${c.cliente_telefono ? ' &nbsp;|&nbsp; Tel: ' + escaparHtml(c.cliente_telefono) : ''}
            ${c.cliente_ubicacion ? ' &nbsp;|&nbsp; ' + escaparHtml(c.cliente_ubicacion) : ''}
        </p>

        <h3 style="font-size: 14px; margin: 12px 0 4px;">Qué se llevó</h3>
        <table style="width: 100%; font-size: 12px; border-collapse: collapse;">${productos}</table>

        <h3 style="font-size: 14px; margin: 12px 0 4px;">Qué ha pagado</h3>
        <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
            ${abonos || '<tr><td style="padding: 5px; color: #666;">Todavía no ha abonado nada.</td></tr>'}
        </table>

        ${acuerdos ? '<h3 style="font-size: 14px; margin: 12px 0 4px;">Acuerdos de pago</h3><table style="width: 100%; font-size: 12px; border-collapse: collapse;">' + acuerdos + '</table>' : ''}

        <table style="width: 60%; margin-left: 40%; margin-top: 16px; font-size: 13px; border-collapse: collapse;">
            <tr><td style="padding: 4px;">Saldo:</td><td style="padding: 4px; text-align: right; font-weight: bold;">${fmtPesos(c.saldo_pendiente)}</td></tr>
            <tr><td style="padding: 4px;">Mora (${c.dias_mora || 0} días):</td><td style="padding: 4px; text-align: right; font-weight: bold; color: #b91c1c;">${fmtPesos(c.mora)}</td></tr>
            <tr><td style="padding: 6px; border-top: 2px solid #1e3a8a; font-weight: bold;">Total a pagar:</td><td style="padding: 6px; border-top: 2px solid #1e3a8a; text-align: right; font-weight: bold; color: #1e3a8a;">${fmtPesos(c.total_a_pagar)}</td></tr>
        </table>

        <table style="width: 100%; margin-top: 50px; font-size: 11px; text-align: center;">
            <tr>
                <td style="width: 50%; border-top: 1px solid #000; padding-top: 4px;">Firma del cliente</td>
                <td style="width: 50%; border-top: 1px solid #000; padding-top: 4px;">${escaparHtml(config.nombre_comercial)}</td>
            </tr>
        </table>
    ${_pieReporte()}`;
}

/**
 * Muestra una fecha de la base (YYYY-MM-DD o YYYY-MM-DD HH:MM:SS) como
 * DD/MM/AAAA, sin que la zona horaria la corra un día.
 */
function _fechaBonita(fecha) {
    const m = String(fecha == null ? '' : fecha).match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? `${m[3]}/${m[2]}/${m[1]}` : String(fecha || '');
}

function printDocumentA4(htmlContent, filename) {
    const container = document.getElementById('pdf-container');
    container.innerHTML = htmlContent;
    container.style.display = 'block';

    let opt = {
        margin:       10,
        filename:     filename,
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2 },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    html2pdf().set(opt).from(container).save().then(() => {
        container.style.display = 'none';
        container.innerHTML = '';
    });
}
