const { createApp, ref, computed, onMounted } = Vue;

const app = createApp({
    setup() {
        const currentView = ref('pos');
        const API_BASE = 'api/';

        // Global config
        const config = ref({
            nombre_comercial: '', razon_social: '', nit: '', direccion: '', telefonos: '', 
            plazo_1: 8, plazo_2: 15, recargo_credito: 0, texto_pagare: ''
        });

        // Toast
        const toast = ref({ show: false, message: '', type: 'success' });
        const showToast = (message, type = 'success') => {
            toast.value = { show: true, message, type };
            setTimeout(() => { toast.value.show = false; }, 3000);
        };

        // Data
        const productos = ref([]);
        const clientes = ref([]);
        
        // Load Initial Data
        const loadConfig = async () => {
            try {
                const res = await fetch(`${API_BASE}configuracion.php`);
                config.value = await res.json();
            } catch (e) { showToast('Error cargando configuración', 'error'); }
        };

        const loadProductos = async () => {
            try {
                const res = await fetch(`${API_BASE}productos.php`);
                productos.value = await res.json();
            } catch (e) { showToast('Error cargando productos', 'error'); }
        };

        const loadClientes = async () => {
            try {
                const res = await fetch(`${API_BASE}clientes.php`);
                clientes.value = await res.json();
            } catch (e) { showToast('Error cargando clientes', 'error'); }
        };

        onMounted(async () => {
            await loadConfig();
            await loadProductos();
            await loadClientes();
            loadCarteraDashboard();
        });

        // --- POS Logic ---
        const cart = ref([]);
        const tipoVenta = ref('contado');
        // Con qué pagó el cliente. Importa para el arqueo de caja: solo el
        // efectivo entra a la caja física.
        const metodoPago = ref('efectivo');
        const clienteForm = ref({ cedula: '', nombre: '', telefono: '', ubicacion: '', encontrado: false, id: null });
        const buscandoCliente = ref(false);
        const abonoInicial = ref(0);
        const diasPlazoCredito = ref(8);
        const isMobileCartOpen = ref(false);
        const processingVenta = ref(false);

        const buscarCliente = async () => {
            if (!clienteForm.value.cedula) {
                clienteForm.value = { cedula: '', nombre: '', telefono: '', ubicacion: '', encontrado: false, id: null };
                return;
            }
            buscandoCliente.value = true;
            try {
                const res = await fetch(`${API_BASE}clientes.php?cedula=${clienteForm.value.cedula}`);
                const data = await res.json();
                if (data.success && data.cliente) {
                    clienteForm.value = {
                        ...clienteForm.value,
                        nombre: data.cliente.nombre,
                        telefono: data.cliente.telefono,
                        ubicacion: data.cliente.ubicacion,
                        encontrado: true,
                        id: data.cliente.id
                    };
                } else {
                    clienteForm.value.encontrado = false;
                    clienteForm.value.id = null;
                }
            } catch (e) {
                showToast('Error buscando cliente', 'error');
            } finally {
                buscandoCliente.value = false;
            }
        };

        const toggleTipoVenta = () => {
            tipoVenta.value = tipoVenta.value === 'contado' ? 'credito' : 'contado';
            // Update cart prices when switching
            cart.value.forEach(item => {
                const prod = productos.value.find(p => p.id === item.producto_id);
                if(prod) {
                    item.precio_unitario = parseFloat(tipoVenta.value === 'contado' ? prod.precio_contado : prod.precio_credito);
                }
            });
        };

        const addToCart = (prod) => {
            const existing = cart.value.find(i => i.producto_id === prod.id);
            if (existing) {
                existing.cantidad++;
            } else {
                cart.value.push({
                    producto_id: prod.id,
                    nombre: prod.nombre,
                    cantidad: 1,
                    precio_unitario: parseFloat(tipoVenta.value === 'contado' ? prod.precio_contado : prod.precio_credito)
                });
            }
            showToast(`${prod.nombre} añadido`);
        };

        const removeFromCart = (index) => {
            cart.value.splice(index, 1);
        };

        const cartTotal = computed(() => {
            return cart.value.reduce((sum, item) => sum + (item.cantidad * item.precio_unitario), 0);
        });

        const fechaVencimiento = computed(() => {
            if (diasPlazoCredito.value === 'custom') return '';
            const d = new Date();
            d.setDate(d.getDate() + parseInt(diasPlazoCredito.value));
            return d.toISOString().split('T')[0];
        });

        const procesarVenta = async () => {
            if (tipoVenta.value === 'credito') {
                if (!clienteForm.value.cedula || !clienteForm.value.nombre) {
                    return showToast('Complete cédula y nombre del cliente', 'error');
                }
                if (!fechaVencimiento.value) return showToast('Defina fecha de vencimiento', 'error');
            }

            processingVenta.value = true;
            
            const payload = {
                tipo_venta: tipoVenta.value,
                metodo_pago: metodoPago.value,
                total: cartTotal.value,
                cliente_id: clienteForm.value.id,
                cliente: clienteForm.value, // Envía toda la info para que el backend haga upsert
                abono_inicial: abonoInicial.value,
                fecha_vencimiento: fechaVencimiento.value,
                detalles: cart.value.map(item => ({
                    producto_id: item.producto_id,
                    nombre: item.nombre,
                    cantidad: item.cantidad,
                    precio_unitario: item.precio_unitario,
                    subtotal: item.cantidad * item.precio_unitario
                }))
            };

            try {
                const res = await fetch(`${API_BASE}ventas.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                
                if (data.success) {
                    showToast('Venta registrada correctamente');
                    
                    payload.cliente_nombre = clienteForm.value.nombre;
                    
                    // Generate PDF Receipt
                    const html = generateReceiptHTML(payload, config.value);
                    printDocument(html, `Recibo_${data.venta_id}.pdf`);

                    // Reset POS
                    cart.value = [];
                    clienteForm.value = { cedula: '', nombre: '', telefono: '', ubicacion: '', encontrado: false, id: null };
                    abonoInicial.value = 0;
                    tipoVenta.value = 'contado';
                    metodoPago.value = 'efectivo';
                    isMobileCartOpen.value = false;
                    loadProductos(); // update stock
                    loadCarteraDashboard(); // update dashboard
                    loadClientes(); // Reload clients globally
                } else {
                    showToast(data.error || 'Error procesando venta', 'error');
                }
            } catch (e) {
                showToast('Error de red al procesar venta', 'error');
            } finally {
                processingVenta.value = false;
            }
        };

        // --- Inventory Logic ---
        const lowStockProducts = computed(() => {
            return productos.value.filter(p => parseFloat(p.stock) <= parseFloat(p.stock_minimo));
        });

        const productModal = ref({ show: false, isEdit: false, data: {} });

        const openProductModal = (prod = null) => {
            if (prod) {
                productModal.value = { show: true, isEdit: true, data: { ...prod } };
            } else {
                productModal.value = { 
                    show: true, isEdit: false, 
                    data: { nombre: '', descripcion: '', unidad: '', precio_costo: 0, precio_contado: 0, precio_credito: 0, stock: 0, stock_minimo: 0 } 
                };
            }
        };

        const saveProduct = async () => {
            const method = productModal.value.isEdit ? 'PUT' : 'POST';
            try {
                const res = await fetch(`${API_BASE}productos.php`, {
                    method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(productModal.value.data)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Producto guardado');
                    productModal.value.show = false;
                    loadProductos();
                } else {
                    showToast('Error guardando', 'error');
                }
            } catch(e) { showToast('Error de red', 'error'); }
        };

        const deleteProduct = async (id) => {
            if(!confirm('¿Seguro que desea eliminar este producto?')) return;
            try {
                const res = await fetch(`${API_BASE}productos.php?id=${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (data.success) {
                    showToast('Producto eliminado');
                    loadProductos();
                } else {
                    showToast('Error al eliminar', 'error');
                }
            } catch(e) { showToast('Error de red', 'error'); }
        };

        // --- Cartera (Credits) Logic ---
        const dashboardStats = ref({ capital_inventario: 0, dinero_caja: 0, plata_calle: 0 });
        const deudoresActivos = ref([]);
        const abonoModal = ref({ show: false, data: null, monto: 0 });

        const loadCarteraDashboard = async () => {
            try {
                const res = await fetch(`${API_BASE}cartera.php?action=dashboard`);
                dashboardStats.value = await res.json();
            } catch(e) { console.error('Error stats'); }
        };

        const loadDeudores = async () => {
            try {
                const res = await fetch(`${API_BASE}cartera.php?action=list`);
                deudoresActivos.value = await res.json();
            } catch(e) { showToast('Error cargando cartera', 'error'); }
        };

        const openAbonoModal = (credito) => {
            abonoModal.value = {
                show: true,
                data: credito,
                monto: credito.saldo_pendiente
            };
        };

        const procesarAbono = async () => {
            try {
                const res = await fetch(`${API_BASE}cartera.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        credito_id: abonoModal.value.data.credito_id,
                        monto: abonoModal.value.monto
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Abono registrado');
                    abonoModal.value.show = false;
                    loadDeudores();
                    loadCarteraDashboard();
                } else {
                    showToast('Error registrando abono', 'error');
                }
            } catch(e) { showToast('Error de red', 'error'); }
        };

        const sendWhatsApp = (credito) => {
            let phone = credito.cliente_telefono.replace(/\D/g, '');
            if (phone.length === 10 && phone.startsWith('3')) {
                phone = '57' + phone; // Prefix Colombia
            }
            const msg = `Hola ${credito.cliente_nombre}. Te saludamos de ${config.value.nombre_comercial}. Te recordamos que tienes un saldo pendiente de $${parseFloat(credito.saldo_pendiente).toLocaleString('es-CO')} que vence el ${credito.fecha_vencimiento}. Quedamos atentos. ¡Gracias!`;
            const url = `https://wa.me/${phone}?text=${encodeURIComponent(msg)}`;
            window.open(url, '_blank');
        };

        // --- Config Logic ---
        const saveConfig = async () => {
            try {
                const res = await fetch(`${API_BASE}configuracion.php`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(config.value)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Configuración guardada');
                } else {
                    showToast('Error guardando config', 'error');
                }
            } catch(e) { showToast('Error de red', 'error'); }
        };

        // ============================================================
        // MI PLATA (contabilidad)
        // ============================================================
        const plataTab = ref('resumen');
        const plataRango = ref({ periodo: 'mes', desde: '', hasta: '' });
        const plataResumen = ref({});
        const plataSerie = ref({ serie: [], totales: { ventas: 0, costo: 0, utilidad: 0 } });
        const plataProductos = ref([]);
        const plataTipos = ref([]);

        const gastos = ref([]);
        const gastosTotal = ref(0);
        const gastosFiltro = ref({ desde: '', hasta: '' });
        const categoriasGasto = ref([]);
        const gastoForm = ref({ categoria_id: '', descripcion: '', monto: 0, metodo_pago: 'efectivo' });

        const proveedores = ref([]);
        const comprasPendientes = ref({ compras: [], total: 0 });
        const compraForm = ref({ proveedor_id: null, tipo_pago: 'contado', fecha_vencimiento: '', nota: '', detalles: [] });
        const compraLinea = ref({ producto_id: '', cantidad: 1, costo_unitario: 0 });
        const pagoProveedorModal = ref({ show: false, data: null, monto: 0, metodo_pago: 'efectivo' });

        const cierreFecha = ref(new Date().toLocaleDateString('en-CA')); // Y-m-d local
        const cierre = ref({ efectivo_inicial: 0, efectivo_contado: null, observacion: '' });

        /** Arma el query string del rango elegido. */
        const rangoQuery = () => {
            const p = plataRango.value;
            const params = new URLSearchParams();
            params.set('periodo', p.periodo);
            if (p.periodo === 'rango') {
                if (p.desde) params.set('desde', p.desde);
                if (p.hasta) params.set('hasta', p.hasta);
            }
            return params.toString();
        };

        /** Qué agrupación tiene sentido: por año conviene ver meses. */
        const agrupacion = () => (plataRango.value.periodo === 'anio' ? 'mes' : 'dia');

        const cargarPlata = async () => {
            await Promise.all([
                cargarResumenPlata(),
                cargarSerieUtilidad(),
                cargarUtilidadProductos(),
                cargarUtilidadTipos(),
            ]);
        };

        const cargarResumenPlata = async () => {
            try {
                const res = await fetch(`${API_BASE}contabilidad.php?action=resumen`);
                plataResumen.value = await res.json();
            } catch(e) { showToast('No se pudo cargar el resumen de plata', 'error'); }
        };

        const cargarSerieUtilidad = async () => {
            try {
                const res = await fetch(`${API_BASE}contabilidad.php?action=utilidad&agrupar=${agrupacion()}&${rangoQuery()}`);
                plataSerie.value = await res.json();
            } catch(e) { showToast('No se pudo cargar la utilidad', 'error'); }
        };

        const cargarUtilidadProductos = async () => {
            try {
                const res = await fetch(`${API_BASE}contabilidad.php?action=utilidad_productos&${rangoQuery()}`);
                const data = await res.json();
                plataProductos.value = data.productos || [];
            } catch(e) { showToast('No se pudo cargar la utilidad por producto', 'error'); }
        };

        const cargarUtilidadTipos = async () => {
            try {
                const res = await fetch(`${API_BASE}contabilidad.php?action=utilidad_tipo&${rangoQuery()}`);
                const data = await res.json();
                plataTipos.value = data.tipos || [];
            } catch(e) { /* silencioso: es un detalle del tablero */ }
        };

        // --- Gastos ---
        const cargarCategoriasGasto = async () => {
            try {
                const res = await fetch(`${API_BASE}gastos.php?action=categorias`);
                categoriasGasto.value = await res.json();
            } catch(e) { /* silencioso */ }
        };

        const cargarGastos = async () => {
            await cargarCategoriasGasto();
            try {
                const params = new URLSearchParams({ action: 'list' });
                if (gastosFiltro.value.desde) params.set('desde', gastosFiltro.value.desde);
                if (gastosFiltro.value.hasta) params.set('hasta', gastosFiltro.value.hasta);
                const res = await fetch(`${API_BASE}gastos.php?${params.toString()}`);
                const data = await res.json();
                gastos.value = data.gastos || [];
                gastosTotal.value = data.total || 0;
            } catch(e) { showToast('No se pudieron cargar los gastos', 'error'); }
        };

        const guardarGasto = async () => {
            try {
                const res = await fetch(`${API_BASE}gastos.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(gastoForm.value)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Gasto guardado');
                    gastoForm.value = { categoria_id: '', descripcion: '', monto: 0, metodo_pago: 'efectivo' };
                    await cargarGastos();
                    await cargarResumenPlata();
                } else {
                    showToast(data.error || 'No se pudo guardar el gasto', 'error');
                }
            } catch(e) { showToast('Error de red', 'error'); }
        };

        const eliminarGasto = async (id) => {
            if (!confirm('¿Seguro que quiere borrar este gasto?')) return;
            try {
                const res = await fetch(`${API_BASE}gastos.php?id=${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (data.success) {
                    showToast('Gasto eliminado');
                    await cargarGastos();
                    await cargarResumenPlata();
                } else {
                    showToast(data.error || 'No se pudo eliminar', 'error');
                }
            } catch(e) { showToast('Error de red', 'error'); }
        };

        // --- Compras y cuentas por pagar ---
        const cargarCompras = async () => {
            await Promise.all([cargarProveedores(), cargarComprasPendientes()]);
        };

        const cargarProveedores = async () => {
            try {
                const res = await fetch(`${API_BASE}proveedores.php`);
                proveedores.value = await res.json();
            } catch(e) { /* silencioso */ }
        };

        const cargarComprasPendientes = async () => {
            try {
                const res = await fetch(`${API_BASE}compras.php?action=pendientes`);
                comprasPendientes.value = await res.json();
            } catch(e) { showToast('No se pudieron cargar las cuentas por pagar', 'error'); }
        };

        const compraTotal = computed(() =>
            compraForm.value.detalles.reduce((suma, l) => suma + (parseFloat(l.cantidad) * parseFloat(l.costo_unitario)), 0)
        );

        const agregarLineaCompra = () => {
            const l = compraLinea.value;
            const prod = productos.value.find(p => p.id === l.producto_id);
            if (!prod) { showToast('Elija un producto', 'error'); return; }
            if (!l.cantidad || l.cantidad <= 0) { showToast('La cantidad debe ser mayor que cero', 'error'); return; }

            compraForm.value.detalles.push({
                producto_id: prod.id,
                nombre: prod.nombre,
                cantidad: parseFloat(l.cantidad),
                costo_unitario: parseFloat(l.costo_unitario) || 0
            });
            compraLinea.value = { producto_id: '', cantidad: 1, costo_unitario: 0 };
        };

        const guardarCompra = async () => {
            try {
                const res = await fetch(`${API_BASE}compras.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(compraForm.value)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Entrada registrada por ' + formatMoney(data.total));
                    compraForm.value = { proveedor_id: null, tipo_pago: 'contado', fecha_vencimiento: '', nota: '', detalles: [] };
                    await loadProductos();
                    await cargarComprasPendientes();
                    await cargarResumenPlata();
                } else {
                    showToast(data.error || 'No se pudo registrar la compra', 'error');
                }
            } catch(e) { showToast('Error de red', 'error'); }
        };

        const abrirPagoProveedor = (compra) => {
            pagoProveedorModal.value = {
                show: true,
                data: compra,
                monto: parseFloat(compra.saldo_pendiente),
                metodo_pago: 'efectivo'
            };
        };

        const procesarPagoProveedor = async () => {
            try {
                const res = await fetch(`${API_BASE}compras.php?action=pago`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        compra_id: pagoProveedorModal.value.data.id,
                        monto: pagoProveedorModal.value.monto,
                        metodo_pago: pagoProveedorModal.value.metodo_pago
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Pago registrado');
                    pagoProveedorModal.value.show = false;
                    await cargarComprasPendientes();
                    await cargarResumenPlata();
                } else {
                    showToast(data.error || 'No se pudo registrar el pago', 'error');
                }
            } catch(e) { showToast('Error de red', 'error'); }
        };

        // --- Cierre de caja ---
        const cargarCierre = async () => {
            try {
                const res = await fetch(`${API_BASE}cierres_caja.php?action=calcular&fecha=${cierreFecha.value}`);
                const data = await res.json();
                if (data.efectivo_contado === null) data.efectivo_contado = null;
                cierre.value = data;
            } catch(e) { showToast('No se pudo cargar el arqueo', 'error'); }
        };

        // Misma cuenta que hace el servidor, para que el cajero la vea en vivo.
        const efectivoEsperado = computed(() => {
            const c = cierre.value;
            return Math.round((
                (parseFloat(c.efectivo_inicial) || 0)
                + (parseFloat(c.ventas_efectivo) || 0)
                + (parseFloat(c.abonos_efectivo) || 0)
                - (parseFloat(c.gastos_efectivo) || 0)
                - (parseFloat(c.pagos_proveedor_efectivo) || 0)
            ) * 100) / 100;
        });

        const diferenciaCaja = computed(() => {
            const contado = cierre.value.efectivo_contado;
            if (contado === null || contado === '' || contado === undefined) return 0;
            return Math.round(((parseFloat(contado) || 0) - efectivoEsperado.value) * 100) / 100;
        });

        const guardarCierre = async () => {
            try {
                const res = await fetch(`${API_BASE}cierres_caja.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        fecha: cierreFecha.value,
                        efectivo_inicial: cierre.value.efectivo_inicial,
                        efectivo_contado: cierre.value.efectivo_contado,
                        observacion: cierre.value.observacion
                    })
                });
                const data = await res.json();
                if (data.success) {
                    let mensaje = 'Caja cuadrada';
                    let tipo = 'success';
                    if (data.resultado === 'falto') { mensaje = 'Faltó ' + formatMoney(Math.abs(data.diferencia)); tipo = 'error'; }
                    if (data.resultado === 'sobro') { mensaje = 'Sobró ' + formatMoney(data.diferencia); tipo = 'error'; }
                    showToast(mensaje, tipo);
                    await cargarCierre();
                    await cargarResumenPlata();
                } else {
                    showToast(data.error || 'No se pudo guardar el cierre', 'error');
                }
            } catch(e) { showToast('Error de red', 'error'); }
        };

        // --- Presentación del gráfico (sin librerías: funciona sin internet) ---
        const plataMaxUtilidad = computed(() => {
            const valores = plataSerie.value.serie.map(p => parseFloat(p.utilidad) || 0);
            return valores.length ? Math.max(...valores, 1) : 1;
        });

        const alturaBarra = (valor) => {
            const proporcion = Math.max(0, (parseFloat(valor) || 0) / plataMaxUtilidad.value);
            return Math.round(proporcion * 150) + 4 + 'px';
        };

        const formatMoneyCorto = (val) => {
            const n = parseFloat(val) || 0;
            if (Math.abs(n) >= 1000000) return '$' + (n / 1000000).toFixed(1) + 'M';
            if (Math.abs(n) >= 1000) return '$' + Math.round(n / 1000) + 'k';
            return '$' + Math.round(n);
        };

        const etiquetaPeriodo = (fecha) => {
            const partes = String(fecha || '').split('-');
            if (partes.length < 3) return fecha;
            if (plataRango.value.periodo === 'anio') {
                const meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
                return meses[parseInt(partes[1], 10) - 1] || partes[1];
            }
            return partes[2] + '/' + partes[1];
        };

        // --- Impresión de reportes ---
        const imprimirResumenPlata = () => {
            try {
                const html = generateReportePlataHTML(
                    plataResumen.value, plataSerie.value, plataProductos.value, config.value
                );
                printDocumentA4(html, 'Reporte_Mi_Plata_' + plataSerie.value.hasta + '.pdf');
            } catch(e) { showToast('No se pudo generar el reporte', 'error'); }
        };

        const imprimirCierre = () => {
            try {
                const datos = {
                    fecha: cierreFecha.value,
                    efectivo_inicial: cierre.value.efectivo_inicial,
                    ventas_efectivo: cierre.value.ventas_efectivo,
                    abonos_efectivo: cierre.value.abonos_efectivo,
                    gastos_efectivo: cierre.value.gastos_efectivo,
                    pagos_proveedor_efectivo: cierre.value.pagos_proveedor_efectivo,
                    efectivo_esperado: efectivoEsperado.value,
                    efectivo_contado: cierre.value.efectivo_contado,
                    diferencia: diferenciaCaja.value,
                    observacion: cierre.value.observacion
                };
                printDocumentA4(generateCierreCajaHTML(datos, config.value), 'Cierre_Caja_' + cierreFecha.value + '.pdf');
            } catch(e) { showToast('No se pudo generar el cierre', 'error'); }
        };

        // Utilities
        const formatMoney = (val) => {
            return '$ ' + parseFloat(val).toLocaleString('es-CO');
        };

        const formatDate = (dateStr) => {
            if(!dateStr) return '';
            const d = new Date(dateStr);
            return d.toLocaleDateString('es-CO');
        };

        return {
            currentView, API_BASE, config, toast, productos, clientes, lowStockProducts,
            cart, tipoVenta, metodoPago, clienteForm, buscandoCliente, buscarCliente, abonoInicial, diasPlazoCredito, isMobileCartOpen, processingVenta,
            toggleTipoVenta, addToCart, removeFromCart, cartTotal, fechaVencimiento, procesarVenta,
            productModal, openProductModal, saveProduct, deleteProduct,
            dashboardStats, deudoresActivos, abonoModal, loadCarteraDashboard, loadDeudores, openAbonoModal, procesarAbono, sendWhatsApp,
            saveConfig, loadConfig, loadProductos,
            // Mi Plata (contabilidad)
            plataTab, plataRango, plataResumen, plataSerie, plataProductos, plataTipos,
            gastos, gastosTotal, gastosFiltro, categoriasGasto, gastoForm,
            proveedores, comprasPendientes, compraForm, compraLinea, pagoProveedorModal,
            cierreFecha, cierre, efectivoEsperado, diferenciaCaja,
            cargarPlata, cargarResumenPlata, cargarGastos, guardarGasto, eliminarGasto,
            cargarCompras, compraTotal, agregarLineaCompra, guardarCompra,
            abrirPagoProveedor, procesarPagoProveedor, cargarCierre, guardarCierre,
            alturaBarra, formatMoneyCorto, etiquetaPeriodo,
            imprimirResumenPlata, imprimirCierre,
            formatMoney, formatDate
        };
    }
});

app.mount('#app');
