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
 * Imprime/exporta un reporte en tamaño carta (los recibos van en 80 mm).
 */
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
