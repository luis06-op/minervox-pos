/**
 * Plantillas de impresión.
 *
 * Todos los valores que vienen del usuario o de la base de datos se escapan
 * antes de insertarse en el HTML para evitar inyección de código.
 */

const escapeHtml = (valor) => String(valor ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

const formatearNumero = (valor, decimales = 2) => {
    const numero = Number(valor);

    return (Number.isFinite(numero) ? numero : 0).toLocaleString('es-CO', {
        minimumFractionDigits: 0,
        maximumFractionDigits: decimales
    });
};

const formatearMoneda = (valor) => `$${formatearNumero(valor, 2)}`;

const formatearFecha = (valor) => {
    if (!valor) return '';

    const partes = String(valor).match(/^(\d{4})-(\d{2})-(\d{2})/);

    if (partes) {
        return new Date(Number(partes[1]), Number(partes[2]) - 1, Number(partes[3]))
            .toLocaleDateString('es-CO');
    }

    const fecha = new Date(valor);
    return Number.isNaN(fecha.getTime()) ? escapeHtml(valor) : fecha.toLocaleDateString('es-CO');
};

function generateReceiptHTML(data, config) {
    const fecha = new Date().toLocaleString('es-CO');
    const esCredito = data.tipo_venta === 'credito';
    const detalles = Array.isArray(data.detalles) ? data.detalles : [];
    const abonoInicial = Number(data.abono_inicial) || 0;
    const total = Number(data.total) || 0;
    const saldoPendiente = Math.max(0, total - abonoInicial);

    const filas = detalles.map(detalle => {
        const cantidad = Number(detalle.cantidad) || 0;
        const precio = Number(detalle.precio_unitario) || 0;

        return `
            <tr>
                <td style="padding: 2px 0; vertical-align: top; word-wrap: break-word;">${formatearNumero(cantidad, 3)}</td>
                <td style="padding: 2px 0; vertical-align: top; word-wrap: break-word;">
                    ${escapeHtml(detalle.nombre)}<br><small>${formatearMoneda(precio)}</small>
                </td>
                <td style="text-align: right; padding: 2px 0; vertical-align: top; word-wrap: break-word;">
                    ${formatearMoneda(cantidad * precio)}
                </td>
            </tr>
        `;
    }).join('');

    return `
    <div style="font-family: 'Courier New', Courier, monospace; width: 260px; padding: 5px; color: #000; font-size: 11px; line-height: 1.2;">
        <div style="text-align: center; margin-bottom: 10px;">
            <h2 style="margin: 0; font-size: 16px;">${escapeHtml(config.nombre_comercial)}</h2>
            <p style="margin: 2px 0;">${escapeHtml(config.razon_social)}</p>
            <p style="margin: 2px 0;">NIT: ${escapeHtml(config.nit)}</p>
            <p style="margin: 2px 0;">${escapeHtml(config.direccion)}</p>
            <p style="margin: 2px 0;">Tel: ${escapeHtml(config.telefonos)}</p>
        </div>

        <div style="border-bottom: 1px dashed #000; margin-bottom: 5px; padding-bottom: 5px;">
            <p style="margin: 2px 0;"><strong>RECIBO DE VENTA ${esCredito ? 'A CRÉDITO' : 'DE CONTADO'}</strong></p>
            <p style="margin: 2px 0;">Fecha: ${escapeHtml(fecha)}</p>
            ${data.cliente_nombre ? `<p style="margin: 2px 0;">Cliente: ${escapeHtml(data.cliente_nombre)}</p>` : ''}
            ${data.cliente && data.cliente.cedula ? `<p style="margin: 2px 0;">Cédula: ${escapeHtml(data.cliente.cedula)}</p>` : ''}
            ${esCredito ? `<p style="margin: 2px 0;">Vencimiento: ${escapeHtml(formatearFecha(data.fecha_vencimiento))}</p>` : ''}
        </div>

        <table style="width: 100%; text-align: left; border-collapse: collapse; margin-bottom: 10px; table-layout: fixed;">
            <thead>
                <tr style="border-bottom: 1px dashed #000;">
                    <th style="width: 15%; padding: 2px 0;">Cant</th>
                    <th style="width: 45%; padding: 2px 0;">Prod</th>
                    <th style="width: 40%; text-align: right; padding: 2px 0;">Subtotal</th>
                </tr>
            </thead>
            <tbody>${filas}</tbody>
        </table>

        <div style="border-top: 1px dashed #000; padding-top: 5px; text-align: right; font-size: 14px;">
            <strong>TOTAL: ${formatearMoneda(total)}</strong>
        </div>

        ${esCredito ? `
            ${abonoInicial > 0 ? `
                <div style="margin-top: 5px; text-align: right;">
                    <p style="margin: 2px 0;">Abono inicial: ${formatearMoneda(abonoInicial)}</p>
                    <p style="margin: 2px 0;"><strong>Saldo: ${formatearMoneda(saldoPendiente)}</strong></p>
                </div>
            ` : ''}
            <div style="margin-top: 15px; border: 1px solid #000; padding: 5px; text-align: justify; font-size: 10px;">
                <strong>PAGARÉ:</strong><br>
                ${escapeHtml(config.texto_pagare || 'Debo(emos) pagar incondicionalmente el saldo indicado en la fecha de vencimiento.').replace(/\n/g, '<br>')}
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
}

/**
 * Imprime el recibo como PDF. Si html2pdf no está disponible (sin internet),
 * abre una ventana con el documento y usa el diálogo de impresión del navegador.
 */
function printDocument(htmlContent, filename) {
    if (typeof html2pdf === 'undefined') {
        const ventana = window.open('', '_blank');

        if (!ventana) {
            alert('No se pudo abrir la ventana de impresión. Verifica el bloqueador de ventanas emergentes.');
            return;
        }

        ventana.document.write(`<!DOCTYPE html>
            <html lang="es">
            <head>
                <meta charset="UTF-8">
                <title>${escapeHtml(filename)}</title>
                <style>
                    @page { margin: 5mm; }
                    body { margin: 0; padding: 0; }
                </style>
            </head>
            <body>${htmlContent}</body>
            </html>`);
        ventana.document.close();
        ventana.focus();
        ventana.print();
        return;
    }

    const container = document.getElementById('pdf-container');
    container.innerHTML = htmlContent;
    container.style.display = 'block';

    const opciones = {
        margin: 5,
        filename: filename,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, backgroundColor: '#ffffff' },
        jsPDF: { unit: 'mm', format: [80, 297], orientation: 'portrait' }
    };

    html2pdf().set(opciones).from(container).save().finally(() => {
        container.style.display = 'none';
        container.innerHTML = '';
    });
}
