const { createApp, ref, computed, onMounted } = Vue;

const app = createApp({
    setup() {
        const currentView = ref('pos');
        const API_BASE = 'api/';

        // ------------------------------------------------------------------
        // Estado general
        // ------------------------------------------------------------------
        const config = ref({
            nombre_comercial: '', razon_social: '', nit: '', direccion: '', telefonos: '',
            plazo_1: 8, plazo_2: 15, recargo_credito: 0, texto_pagare: ''
        });

        const toast = ref({ show: false, message: '', type: 'success' });
        let toastTimer = null;

        const showToast = (message, type = 'success') => {
            toast.value = { show: true, message, type };
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => { toast.value.show = false; }, 3500);
        };

        const productos = ref([]);

        const redondear = (valor, decimales = 2) => {
            const numero = Number(valor);
            if (!Number.isFinite(numero)) return 0;
            const factor = 10 ** decimales;
            return Math.round((numero + Number.EPSILON) * factor) / factor;
        };

        /**
         * fetch con manejo uniforme de errores y tiempo de espera.
         */
        const apiFetch = async (ruta, opciones = {}) => {
            const controlador = new AbortController();
            const temporizador = setTimeout(() => controlador.abort(), 20000);

            try {
                const respuesta = await fetch(`${API_BASE}${ruta}`, {
                    ...opciones,
                    signal: controlador.signal,
                    headers: { Accept: 'application/json', ...(opciones.headers || {}) }
                });

                const texto = await respuesta.text();
                let datos = null;

                if (texto) {
                    try {
                        datos = JSON.parse(texto);
                    } catch {
                        throw new Error(`Respuesta inválida del servidor (${respuesta.status}).`);
                    }
                }

                if (!respuesta.ok) {
                    throw new Error((datos && datos.error) || `Error del servidor (${respuesta.status}).`);
                }

                if (datos === null) {
                    throw new Error('El servidor devolvió una respuesta vacía.');
                }

                return datos;
            } catch (error) {
                if (error.name === 'AbortError') {
                    throw new Error('El servidor tardó demasiado en responder.');
                }
                throw error;
            } finally {
                clearTimeout(temporizador);
            }
        };

        // ------------------------------------------------------------------
        // Carga de datos
        // ------------------------------------------------------------------
        const cargarConfig = async () => {
            try {
                const datos = await apiFetch('configuracion.php');
                config.value = { ...config.value, ...datos };

                // Si el plazo seleccionado ya no existe, vuelve al plazo 1.
                const plazos = [Number(config.value.plazo_1), Number(config.value.plazo_2)];
                if (diasPlazoCredito.value !== 'custom' && !plazos.includes(Number(diasPlazoCredito.value))) {
                    diasPlazoCredito.value = Number(config.value.plazo_1) || 8;
                }
            } catch (error) {
                showToast(`No se pudo cargar la configuración: ${error.message}`, 'error');
            }
        };

        const cargarProductos = async () => {
            try {
                const datos = await apiFetch('productos.php');

                if (!Array.isArray(datos)) {
                    throw new Error('Formato de productos inesperado.');
                }

                productos.value = datos;

                // Refresca el stock de las líneas que ya están en el carrito.
                cart.value.forEach(item => {
                    const producto = productos.value.find(p => Number(p.id) === Number(item.producto_id));
                    if (producto) item.stock = Number(producto.stock);
                });
            } catch (error) {
                showToast(`No se pudieron cargar los productos: ${error.message}`, 'error');
            }
        };

        // ------------------------------------------------------------------
        // POS
        // ------------------------------------------------------------------
        const cart = ref([]);
        const tipoVenta = ref('contado');
        const clienteForm = ref({ cedula: '', nombre: '', telefono: '', ubicacion: '', encontrado: false, id: null });
        const buscandoCliente = ref(false);
        const abonoInicial = ref(0);
        const diasPlazoCredito = ref(null);
        const fechaManual = ref('');
        const isMobileCartOpen = ref(false);
        const processingVenta = ref(false);

        const esCredito = computed(() => tipoVenta.value === 'credito');

        const aISOlocal = (fecha) => {
            const anio = fecha.getFullYear();
            const mes = String(fecha.getMonth() + 1).padStart(2, '0');
            const dia = String(fecha.getDate()).padStart(2, '0');
            return `${anio}-${mes}-${dia}`;
        };

        const hoyISO = aISOlocal(new Date());

        // Tiene setter para que v-model funcione (antes era un computed de solo lectura).
        const fechaVencimiento = computed({
            get() {
                if (diasPlazoCredito.value === 'custom') {
                    return fechaManual.value;
                }

                const dias = parseInt(diasPlazoCredito.value, 10);
                const fecha = new Date();
                fecha.setDate(fecha.getDate() + (Number.isFinite(dias) ? dias : 0));
                return aISOlocal(fecha);
            },
            set(valor) {
                fechaManual.value = valor;
            }
        });

        const buscarCliente = async () => {
            const cedula = String(clienteForm.value.cedula || '').trim();

            if (!cedula) {
                clienteForm.value = { cedula: '', nombre: '', telefono: '', ubicacion: '', encontrado: false, id: null };
                return;
            }

            buscandoCliente.value = true;

            try {
                const datos = await apiFetch(`clientes.php?cedula=${encodeURIComponent(cedula)}`);

                if (datos.success && datos.cliente) {
                    clienteForm.value = {
                        ...clienteForm.value,
                        cedula: datos.cliente.cedula,
                        nombre: datos.cliente.nombre || '',
                        telefono: datos.cliente.telefono || '',
                        ubicacion: datos.cliente.ubicacion || '',
                        encontrado: true,
                        id: datos.cliente.id
                    };
                    showToast('Cliente encontrado');
                } else {
                    clienteForm.value.encontrado = false;
                    clienteForm.value.id = null;
                }
            } catch (error) {
                showToast(`No se pudo buscar el cliente: ${error.message}`, 'error');
            } finally {
                buscandoCliente.value = false;
            }
        };

        const precioSegunTipo = (producto) => Number(
            tipoVenta.value === 'contado' ? producto.precio_contado : producto.precio_credito
        ) || 0;

        const toggleTipoVenta = () => {
            tipoVenta.value = tipoVenta.value === 'contado' ? 'credito' : 'contado';

            cart.value.forEach(item => {
                const producto = productos.value.find(p => Number(p.id) === Number(item.producto_id));
                if (producto) {
                    item.precio_unitario = precioSegunTipo(producto);
                }
            });
        };

        const sinStock = (producto) => Number(producto.stock) <= 0;

        const esStockBajo = (producto) => Number(producto.stock) <= Number(producto.stock_minimo);

        const addToCart = (producto) => {
            const stock = Number(producto.stock);

            if (!Number.isFinite(stock) || stock <= 0) {
                showToast(`${producto.nombre} no tiene stock disponible.`, 'error');
                return;
            }

            const existente = cart.value.find(item => Number(item.producto_id) === Number(producto.id));

            if (existente) {
                const nuevaCantidad = redondear(existente.cantidad + 1, 3);

                if (nuevaCantidad > stock) {
                    showToast(`Solo hay ${stock} ${producto.unidad} de ${producto.nombre}.`, 'error');
                    return;
                }

                existente.cantidad = nuevaCantidad;
            } else {
                cart.value.push({
                    producto_id: producto.id,
                    nombre: producto.nombre,
                    unidad: producto.unidad,
                    stock,
                    cantidad: 1,
                    precio_unitario: precioSegunTipo(producto)
                });
            }

            showToast(`${producto.nombre} añadido`);
        };

        const removeFromCart = (indice) => {
            cart.value.splice(indice, 1);
        };

        const normalizarCantidad = (item) => {
            let cantidad = Number(item.cantidad);
            const stock = Number(item.stock);

            if (!Number.isFinite(cantidad) || cantidad < 0.01) {
                cantidad = 0.01;
                showToast('La cantidad mínima es 0.01.', 'error');
            }

            if (Number.isFinite(stock) && stock > 0 && cantidad > stock) {
                cantidad = stock;
                showToast(`Solo hay ${stock} ${item.unidad || ''} disponibles.`, 'error');
            }

            item.cantidad = redondear(cantidad, 3);
        };

        const normalizarPrecio = (item) => {
            let precio = Number(item.precio_unitario);

            if (!Number.isFinite(precio) || precio < 0.01) {
                precio = 0.01;
                showToast('El precio mínimo es 0.01.', 'error');
            }

            item.precio_unitario = redondear(precio, 2);
        };

        const cartTotal = computed(() => redondear(
            cart.value.reduce((suma, item) => {
                return suma + (Number(item.cantidad) || 0) * (Number(item.precio_unitario) || 0);
            }, 0),
            2
        ));

        const reiniciarPOS = () => {
            cart.value = [];
            clienteForm.value = { cedula: '', nombre: '', telefono: '', ubicacion: '', encontrado: false, id: null };
            abonoInicial.value = 0;
            tipoVenta.value = 'contado';
            fechaManual.value = '';
            diasPlazoCredito.value = Number(config.value.plazo_1) || 8;
            isMobileCartOpen.value = false;
        };

        const procesarVenta = async () => {
            if (processingVenta.value) return;

            if (cart.value.length === 0) {
                showToast('Agrega al menos un producto.', 'error');
                return;
            }

            for (const item of cart.value) {
                if (!(Number(item.cantidad) > 0) || !(Number(item.precio_unitario) > 0)) {
                    showToast(`Revisa la cantidad y el precio de «${item.nombre}».`, 'error');
                    return;
                }

                if (Number(item.cantidad) > Number(item.stock)) {
                    showToast(`La cantidad de «${item.nombre}» supera el stock disponible.`, 'error');
                    return;
                }
            }

            const total = cartTotal.value;
            let datosCliente = null;
            let abono = 0;

            if (esCredito.value) {
                const cedula = String(clienteForm.value.cedula || '').trim();
                const nombre = String(clienteForm.value.nombre || '').trim();

                if (!cedula || !nombre) {
                    showToast('Completa la cédula y el nombre del cliente.', 'error');
                    return;
                }

                if (!fechaVencimiento.value) {
                    showToast('Define la fecha de vencimiento.', 'error');
                    return;
                }

                abono = redondear(Number(abonoInicial.value) || 0, 2);

                if (abono < 0) {
                    showToast('El abono inicial no puede ser negativo.', 'error');
                    return;
                }

                if (abono > total) {
                    showToast('El abono inicial no puede superar el total de la venta.', 'error');
                    return;
                }

                datosCliente = {
                    cedula,
                    nombre,
                    telefono: String(clienteForm.value.telefono || '').trim(),
                    ubicacion: String(clienteForm.value.ubicacion || '').trim()
                };
            }

            const payload = {
                tipo_venta: tipoVenta.value,
                total,
                cliente_id: esCredito.value ? (clienteForm.value.id || null) : null,
                cliente: datosCliente,
                abono_inicial: esCredito.value ? abono : 0,
                fecha_vencimiento: esCredito.value ? fechaVencimiento.value : null,
                detalles: cart.value.map(item => ({
                    producto_id: item.producto_id,
                    nombre: item.nombre,
                    cantidad: Number(item.cantidad),
                    precio_unitario: Number(item.precio_unitario),
                    subtotal: redondear(Number(item.cantidad) * Number(item.precio_unitario), 2)
                }))
            };

            processingVenta.value = true;

            try {
                const respuesta = await apiFetch('ventas.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                showToast(`Venta #${respuesta.venta_id} registrada correctamente`);

                const recibo = {
                    ...payload,
                    total: Number(respuesta.total) || total,
                    cliente_nombre: datosCliente ? datosCliente.nombre : ''
                };

                printDocument(generateReceiptHTML(recibo, config.value), `Recibo_${respuesta.venta_id}.pdf`);

                reiniciarPOS();

                await Promise.all([cargarProductos(), cargarCarteraDashboard()]);
            } catch (error) {
                showToast(`No se pudo registrar la venta: ${error.message}`, 'error');
            } finally {
                processingVenta.value = false;
            }
        };

        // ------------------------------------------------------------------
        // Inventario
        // ------------------------------------------------------------------
        const lowStockProducts = computed(() => productos.value.filter(esStockBajo));

        const productModal = ref({ show: false, isEdit: false, data: {} });

        const openProductModal = (producto = null) => {
            if (producto) {
                productModal.value = { show: true, isEdit: true, data: { ...producto } };
                return;
            }

            productModal.value = {
                show: true,
                isEdit: false,
                data: {
                    nombre: '', unidad: '', precio_costo: 0, precio_contado: 0,
                    precio_credito: 0, stock: 0, stock_minimo: 0
                }
            };
        };

        const saveProduct = async () => {
            const datos = productModal.value.data;

            if (!String(datos.nombre || '').trim() || !String(datos.unidad || '').trim()) {
                showToast('El nombre y la unidad son obligatorios.', 'error');
                return;
            }

            const numericos = ['precio_costo', 'precio_contado', 'precio_credito', 'stock', 'stock_minimo'];

            for (const campo of numericos) {
                const valor = Number(datos[campo]);

                if (!Number.isFinite(valor) || valor < 0) {
                    showToast('Los precios y el stock deben ser números mayores o iguales a cero.', 'error');
                    return;
                }

                datos[campo] = redondear(valor, campo.startsWith('precio') ? 2 : 3);
            }

            const metodo = productModal.value.isEdit ? 'PUT' : 'POST';

            try {
                await apiFetch('productos.php', {
                    method: metodo,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(datos)
                });

                showToast('Producto guardado');
                productModal.value.show = false;
                await cargarProductos();
            } catch (error) {
                showToast(`No se pudo guardar el producto: ${error.message}`, 'error');
            }
        };

        const deleteProduct = async (id) => {
            if (!confirm('¿Dar de baja este producto? Dejará de aparecer en el catálogo, pero se conserva el historial de ventas.')) {
                return;
            }

            try {
                await apiFetch(`productos.php?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
                showToast('Producto dado de baja');
                await cargarProductos();
            } catch (error) {
                showToast(`No se pudo dar de baja el producto: ${error.message}`, 'error');
            }
        };

        // ------------------------------------------------------------------
        // Cartera
        // ------------------------------------------------------------------
        const dashboardStats = ref({ capital_inventario: 0, dinero_caja: 0, plata_calle: 0 });
        const deudoresActivos = ref([]);
        const abonoModal = ref({ show: false, data: null, monto: 0 });

        const cargarCarteraDashboard = async () => {
            try {
                const datos = await apiFetch('cartera.php?action=dashboard');
                dashboardStats.value = { ...dashboardStats.value, ...datos };
            } catch (error) {
                console.error('Error cargando el dashboard:', error);
            }
        };

        const loadDeudores = async () => {
            try {
                const datos = await apiFetch('cartera.php?action=list');
                deudoresActivos.value = Array.isArray(datos) ? datos : [];
            } catch (error) {
                showToast(`No se pudo cargar la cartera: ${error.message}`, 'error');
            }
        };

        const openAbonoModal = (credito) => {
            abonoModal.value = {
                show: true,
                data: credito,
                monto: Number(credito.saldo_pendiente) || 0
            };
        };

        const procesarAbono = async () => {
            const saldo = Number(abonoModal.value.data.saldo_pendiente);
            let monto = redondear(Number(abonoModal.value.monto), 2);

            if (!Number.isFinite(monto) || monto <= 0) {
                showToast('El monto del abono debe ser mayor que cero.', 'error');
                return;
            }

            if (monto > saldo) {
                showToast('El abono no puede superar el saldo pendiente.', 'error');
                return;
            }

            try {
                const datos = await apiFetch('cartera.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        credito_id: abonoModal.value.data.credito_id,
                        monto
                    })
                });

                showToast('Abono registrado');
                abonoModal.value.show = false;

                await Promise.all([loadDeudores(), cargarCarteraDashboard()]);

                if (datos.estado === 'pagado') {
                    showToast('Crédito pagado en su totalidad');
                }
            } catch (error) {
                showToast(`No se pudo registrar el abono: ${error.message}`, 'error');
            }
        };

        const sendWhatsApp = (credito) => {
            const telefono = String(credito.cliente_telefono || '').replace(/\D/g, '');

            if (!telefono) {
                showToast('El cliente no tiene un teléfono registrado.', 'error');
                return;
            }

            let destino = telefono;
            if (destino.length === 10 && destino.startsWith('3')) {
                destino = `57${destino}`;
            }

            const mensaje = `Hola ${credito.cliente_nombre}. Te saludamos de ${config.value.nombre_comercial}. `
                + `Te recordamos que tienes un saldo pendiente de ${formatMoney(credito.saldo_pendiente)} `
                + `que vence el ${formatDate(credito.fecha_vencimiento)}. Quedamos atentos. ¡Gracias!`;

            window.open(`https://wa.me/${destino}?text=${encodeURIComponent(mensaje)}`, '_blank', 'noopener');
        };

        // ------------------------------------------------------------------
        // Configuración
        // ------------------------------------------------------------------
        const saveConfig = async () => {
            const plazo1 = parseInt(config.value.plazo_1, 10);
            const plazo2 = parseInt(config.value.plazo_2, 10);
            const recargo = Number(config.value.recargo_credito);

            if (!Number.isFinite(plazo1) || plazo1 < 1 || plazo1 > 365
                || !Number.isFinite(plazo2) || plazo2 < 1 || plazo2 > 365) {
                showToast('Los plazos deben estar entre 1 y 365 días.', 'error');
                return;
            }

            if (!Number.isFinite(recargo) || recargo < 0 || recargo > 100) {
                showToast('El recargo por mora debe estar entre 0 y 100.', 'error');
                return;
            }

            const payload = {
                ...config.value,
                plazo_1: plazo1,
                plazo_2: plazo2,
                recargo_credito: recargo
            };

            try {
                await apiFetch('configuracion.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                config.value = payload;

                const plazos = [plazo1, plazo2];
                if (diasPlazoCredito.value !== 'custom' && !plazos.includes(Number(diasPlazoCredito.value))) {
                    diasPlazoCredito.value = plazo1;
                }

                showToast('Configuración guardada');
            } catch (error) {
                showToast(`No se pudo guardar la configuración: ${error.message}`, 'error');
            }
        };

        // ------------------------------------------------------------------
        // Utilidades de formato
        // ------------------------------------------------------------------
        const formatMoney = (valor) => {
            const numero = Number(valor);
            const seguro = Number.isFinite(numero) ? numero : 0;

            return '$ ' + seguro.toLocaleString('es-CO', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 2
            });
        };

        const formatDate = (valor) => {
            if (!valor) return '';

            // Las fechas "AAAA-MM-DD" se interpretan como UTC con new Date();
            // se construyen en horario local para no mostrar el día anterior.
            const partes = String(valor).match(/^(\d{4})-(\d{2})-(\d{2})/);

            if (partes) {
                return new Date(Number(partes[1]), Number(partes[2]) - 1, Number(partes[3]))
                    .toLocaleDateString('es-CO');
            }

            const fecha = new Date(valor);
            return Number.isNaN(fecha.getTime()) ? '' : fecha.toLocaleDateString('es-CO');
        };

        onMounted(async () => {
            await cargarConfig();
            diasPlazoCredito.value = Number(config.value.plazo_1) || 8;

            await Promise.all([cargarProductos(), cargarCarteraDashboard()]);
        });

        return {
            currentView, API_BASE, config, toast, productos, lowStockProducts,
            cart, tipoVenta, esCredito, clienteForm, buscandoCliente, buscarCliente,
            abonoInicial, diasPlazoCredito, fechaVencimiento, hoyISO, isMobileCartOpen, processingVenta,
            toggleTipoVenta, addToCart, removeFromCart, normalizarCantidad, normalizarPrecio,
            cartTotal, procesarVenta, sinStock, esStockBajo,
            productModal, openProductModal, saveProduct, deleteProduct,
            dashboardStats, deudoresActivos, abonoModal, cargarCarteraDashboard, loadDeudores,
            openAbonoModal, procesarAbono, sendWhatsApp,
            saveConfig, cargarConfig, cargarProductos,
            formatMoney, formatDate
        };
    }
});

app.mount('#app');
