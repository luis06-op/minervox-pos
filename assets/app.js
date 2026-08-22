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
                    data: { nombre: '', unidad: '', precio_costo: 0, precio_contado: 0, precio_credito: 0, stock: 0, stock_minimo: 0 } 
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
            cart, tipoVenta, clienteForm, buscandoCliente, buscarCliente, abonoInicial, diasPlazoCredito, isMobileCartOpen, processingVenta,
            toggleTipoVenta, addToCart, removeFromCart, cartTotal, fechaVencimiento, procesarVenta,
            productModal, openProductModal, saveProduct, deleteProduct,
            dashboardStats, deudoresActivos, abonoModal, loadCarteraDashboard, loadDeudores, openAbonoModal, procesarAbono, sendWhatsApp,
            saveConfig, loadConfig, loadProductos,
            formatMoney, formatDate
        };
    }
});

app.mount('#app');
