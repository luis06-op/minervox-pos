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
