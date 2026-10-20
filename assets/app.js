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

        // =================================================================
        // SESIÓN Y PERMISOS (Fase 5)
        //
        // El sistema arranca en la pantalla de entrada. Hasta que no haya
        // alguien adentro NO se carga nada: ni catálogo, ni cartera, ni
        // configuración.
        //
        // Ojo con una idea equivocada: esconder un botón en la pantalla NO
        // es seguridad. Si el cajero abre la consola del navegador y llama
        // `cartera.php` a mano, el servidor igual le responde. Por eso cada
        // endpoint exige la sesión (y el rol) del lado del servidor; esto de
        // aquí es solo para que la pantalla muestre lo que corresponde.
        // =================================================================
        const sesion = ref({
            cargando: true,              // todavía no sabemos quién entró
            autenticado: false,
            necesita_primer_admin: false, // la base no tiene ningún usuario
            usuario: null,
        });

        /** ¿El que está adentro es el dueño? */
        const esAdmin = computed(() => sesion.value.usuario?.es_admin === true);

        const loginForm       = ref({ usuario: '', clave: '' });
        const primerAdminForm = ref({ nombre: '', usuario: '', clave: '', clave2: '' });
        const claveForm       = ref({ show: false, actual: '', nueva: '', nueva2: '' });
        const sesionError     = ref('');

        // Toast
        const toast = ref({ show: false, message: '', type: 'success' });
        /**
         * Aviso emergente. Se calla los errores cuando no hay sesión: si el
         * servidor contestó 401 la pantalla ya muestra el login, y llenarla
         * de "Error cargando…" solo confunde.
         */
        const showToast = (message, type = 'success') => {
            if (type === 'error' && !sesion.value.autenticado) return;
            toast.value = { show: true, message, type };
            setTimeout(() => { toast.value.show = false; }, 3000);
        };

        /**
         * Envoltorio de `fetch`. Hace tres cosas que, repetidas 29 veces a
         * mano, se terminan olvidando en alguna:
         *   · manda la cookie de la sesión,
         *   · si el servidor responde 401 (se venció la sesión, o alguien
         *     la cerró), devuelve al usuario a la pantalla de entrada,
         *   · si responde 403, avisa que esa acción no es suya.
         *
         * El último parámetro es para el login: ahí un 401 significa "clave
         * equivocada", no "sesión vencida", y hay que mostrar el mensaje.
         */
        const api = async (url, opciones = {}, manejarSesion = true) => {
            const res = await fetch(url, { credentials: 'same-origin', ...opciones });

            if (manejarSesion && res.status === 401) {
                cerrarLocal();
                throw new Error('sesion-vencida');
            }
            if (manejarSesion && res.status === 403) {
                let mensaje = 'Esa acción no es suya';
                try { mensaje = (await res.clone().json()).error || mensaje; } catch (e) { /* da igual */ }
                showToast(mensaje, 'error');
                throw new Error('sin-permiso');
            }
            return res;
        };

        /** Deja la pantalla en el login, sin llamar al servidor. */
        const cerrarLocal = () => {
            sesion.value = { cargando: false, autenticado: false, necesita_primer_admin: false, usuario: null };
            currentView.value = 'pos';
            Object.assign(claveForm.value, { show: false, actual: '', nueva: '', nueva2: '' });
        };

        /** Pregunta al servidor quién está adentro. */
        const cargarSesion = async () => {
            try {
                const res  = await api(`${API_BASE}sesion.php?action=estado`);
                const data = await res.json();
                sesion.value = {
                    cargando: false,
                    autenticado: !!data.autenticado,
                    necesita_primer_admin: !!data.necesita_primer_admin,
                    usuario: data.usuario || null,
                };
            } catch (e) {
                sesion.value = { cargando: false, autenticado: false, necesita_primer_admin: false, usuario: null };
                sesionError.value = 'No se pudo conectar con el servidor. ¿Está encendido XAMPP?';
            }
        };

        /** Trae todo lo que la pantalla necesita una vez hay sesión. */
        const cargarDatosIniciales = async () => {
            currentView.value = 'pos';
            plataTab.value = 'resumen';
            await loadConfig();
            await loadProductos();
            await loadClientes();
            cargarCartera();
        };

        /** Entrar con usuario y clave. */
        const entrar = async () => {
            sesionError.value = '';
            try {
                const res  = await api(`${API_BASE}sesion.php?action=entrar`, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(loginForm.value),
                }, false);
                const data = await res.json();

                if (!data.success) {
                    sesionError.value = data.error || 'No se pudo entrar';
                    return;
                }

                sesion.value = { cargando: false, autenticado: true, necesita_primer_admin: false, usuario: data.usuario };
                loginForm.value = { usuario: '', clave: '' };
                await cargarDatosIniciales();
            } catch (e) {
                sesionError.value = 'No se pudo conectar con el servidor';
            }
        };

        /** La primera vez: crear el administrador del negocio. */
        const crearPrimerAdmin = async () => {
            sesionError.value = '';
            try {
                const res  = await api(`${API_BASE}sesion.php?action=primer_admin`, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(primerAdminForm.value),
                }, false);
                const data = await res.json();

                if (!data.success) {
                    sesionError.value = data.error || 'No se pudo crear el administrador';
                    return;
                }

                sesion.value = { cargando: false, autenticado: true, necesita_primer_admin: false, usuario: data.usuario };
                primerAdminForm.value = { nombre: '', usuario: '', clave: '', clave2: '' };
                await cargarDatosIniciales();
                showToast('Administrador creado. ¡Bienvenido!');
            } catch (e) {
                sesionError.value = 'No se pudo conectar con el servidor';
            }
        };

        /** Salir del sistema. */
        const salir = async () => {
            try {
                await api(`${API_BASE}sesion.php?action=salir`, { method: 'POST' });
            } catch (e) { /* si falla, igual se cierra la pantalla */ }
            cerrarLocal();
        };

        /** Cambiar mi propia contraseña. */
        const cambiarMiClave = async () => {
            try {
                const res  = await api(`${API_BASE}sesion.php?action=cambiar_clave`, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({
                        clave_actual: claveForm.value.actual,
                        clave_nueva:  claveForm.value.nueva,
                        clave_nueva2: claveForm.value.nueva2,
                    }),
                });
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || 'No se pudo cambiar la contraseña', 'error');
                    return;
                }

                Object.assign(claveForm.value, { show: false, actual: '', nueva: '', nueva2: '' });
                showToast('Contraseña cambiada');
            } catch (e) { /* api() ya avisó */ }
        };

        // -----------------------------------------------------------------
        // USUARIOS (Fase 5) — solo el administrador
        // -----------------------------------------------------------------
        const usuarios = ref([]);
        const usuarioModal = ref({
            show: false,
            isEdit: false,
            data: { id: null, nombre: '', usuario: '', rol: 'cajero', activo: 1, clave: '', clave2: '' },
        });

        const cargarUsuarios = async () => {
            try {
                const res = await api(`${API_BASE}usuarios.php`);
                usuarios.value = await res.json();
            } catch (e) { /* api() ya avisó */ }
        };

        const openUsuarioModal = (u = null) => {
            usuarioModal.value = u
                ? { show: true, isEdit: true, data: { id: u.id, nombre: u.nombre, usuario: u.usuario, rol: u.rol, activo: Number(u.activo), clave: '', clave2: '' } }
                : { show: true, isEdit: false, data: { id: null, nombre: '', usuario: '', rol: 'cajero', activo: 1, clave: '', clave2: '' } };
        };

        const guardarUsuario = async () => {
            const d = usuarioModal.value.data;
            const cuerpo = { nombre: d.nombre, usuario: d.usuario, rol: d.rol, activo: d.activo };
            if (d.clave) {
                cuerpo.clave  = d.clave;
                cuerpo.clave2 = d.clave2;
            }
            if (usuarioModal.value.isEdit) cuerpo.id = d.id;

            try {
                const res = await api(`${API_BASE}usuarios.php`, {
                    method:  usuarioModal.value.isEdit ? 'PUT' : 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(cuerpo),
                });
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || 'No se pudo guardar el usuario', 'error');
                    return;
                }

                usuarioModal.value.show = false;
                showToast(usuarioModal.value.isEdit ? 'Usuario actualizado' : 'Usuario creado');
                cargarUsuarios();
            } catch (e) { /* api() ya avisó */ }
        };

        /** Desactivar en vez de borrar: el historial lo necesita. */
        const desactivarUsuario = async (u) => {
            if (!confirm(`¿Desactivar a ${u.nombre}? No podrá entrar, pero su historial queda intacto.`)) return;

            try {
                const res = await api(`${API_BASE}usuarios.php`, {
                    method:  'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ id: u.id, nombre: u.nombre, usuario: u.usuario, rol: u.rol, activo: 0 }),
                });
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || 'No se pudo desactivar', 'error');
                    return;
                }

                showToast('Usuario desactivado');
                cargarUsuarios();
            } catch (e) { /* api() ya avisó */ }
        };

        // Data
        const productos = ref([]);
        const clientes = ref([]);
        
        // Load Initial Data
        const loadConfig = async () => {
            try {
                const res = await api(`${API_BASE}configuracion.php`);
                config.value = await res.json();
            } catch (e) { showToast('Error cargando configuración', 'error'); }
        };

        const loadProductos = async () => {
            try {
                const res = await api(`${API_BASE}productos.php`);
                productos.value = await res.json();
            } catch (e) { showToast('Error cargando productos', 'error'); }
        };

        const loadClientes = async () => {
            try {
                const res = await api(`${API_BASE}clientes.php`);
                clientes.value = await res.json();
            } catch (e) { showToast('Error cargando clientes', 'error'); }
        };

        // Al abrir: primero se pregunta al servidor quién está adentro. Solo
        // si hay sesión se cargan los datos (si no, todo daría 401).
        onMounted(async () => {
            await cargarSesion();
            if (sesion.value.autenticado) {
                await cargarDatosIniciales();
            }
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
                const res = await api(`${API_BASE}clientes.php?cedula=${clienteForm.value.cedula}`);
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
                    precio_unitario: parseFloat(tipoVenta.value === 'contado' ? prod.precio_contado : prod.precio_credito),
                    // FASE 4: se guarda en el carrito para saber, sin volver a
                    // mirar el catálogo, si hay que pedir la cédula.
                    controlado: prod.controlado ? 1 : 0
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

        // =================================================================
        // QUIÉN DEBE QUEDAR REGISTRADO EN LA VENTA (Fase 4)
        //
        // Tres casos obligan a pedir cédula y nombre:
        //   · un fiao (siempre),
        //   · una sustancia controlada (así sea de contado: es el rastro
        //     que pide la autoridad),
        //   · toda venta de contado, si el dueño lo marcó en Ajustes.
        // El backend vuelve a exigirlo; esto es solo para no dejar llegar
        // la venta hasta allá y avisar a tiempo.
        // =================================================================
        const carritoControlado = computed(() => cart.value.some(i => Number(i.controlado) === 1));
        const exigeCedulaContado = computed(() => Number(config.value.exigir_cedula_contado) === 1);
        const requiereCliente = computed(() =>
            tipoVenta.value === 'credito' || carritoControlado.value || exigeCedulaContado.value
        );

        const fechaVencimiento = computed(() => {
            if (diasPlazoCredito.value === 'custom') return '';
            const d = new Date();
            d.setDate(d.getDate() + parseInt(diasPlazoCredito.value));
            return d.toISOString().split('T')[0];
        });

        const procesarVenta = async () => {
            if (requiereCliente.value && (!clienteForm.value.cedula || !clienteForm.value.nombre)) {
                if (tipoVenta.value === 'credito') {
                    return showToast('Complete cédula y nombre del cliente', 'error');
                }
                if (carritoControlado.value) {
                    return showToast('Sustancia controlada: escriba la cédula y el nombre del cliente', 'error');
                }
                return showToast('Escriba la cédula y el nombre del cliente', 'error');
            }
            if (tipoVenta.value === 'credito') {
                if (!fechaVencimiento.value) return showToast('Defina fecha de vencimiento', 'error');
                if (abonoInicial.value > cartTotal.value) {
                    return showToast('El abono inicial no puede ser mayor que el total', 'error');
                }
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
                const res = await api(`${API_BASE}ventas.php`, {
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
                    cargarResumenCartera(); // refresca el semáforo de cartera
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
                    data: { nombre: '', descripcion: '', unidad: '', precio_costo: 0, precio_contado: 0, precio_credito: 0, stock: 0, stock_minimo: 0, controlado: 0 } 
                };
            }
        };

        const saveProduct = async () => {
            const method = productModal.value.isEdit ? 'PUT' : 'POST';
            try {
                const res = await api(`${API_BASE}productos.php`, {
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
                    showToast(data.error || 'Error guardando', 'error');
                }
            } catch(e) { showToast('Error de red', 'error'); }
        };

        const deleteProduct = async (id) => {
            if(!confirm('¿Seguro que desea eliminar este producto?')) return;
            try {
                const res = await api(`${API_BASE}productos.php?id=${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (data.success) {
                    showToast('Producto eliminado');
                    loadProductos();
                } else {
                    showToast('Error al eliminar', 'error');
                }
            } catch(e) { showToast('Error de red', 'error'); }
        };

        // --- Cartera (los fiaos) · FASE 2 ---
        // Resumen del semáforo. Se inicializa con la forma completa para que
        // las tarjetas no se rompan mientras llega la primera respuesta.
        const carteraResumen = ref({
            total_cartera: 0, num_fiaos: 0, mora_total: 0, recuperado_mes: 0,
            vencido:    { cantidad: 0, monto: 0, mora: 0 },
            por_vencer: { cantidad: 0, monto: 0, mora: 0 },
            al_dia:     { cantidad: 0, monto: 0, mora: 0 },
            dias_alerta: 0, mora_mensual: 0
        });
        const deudoresActivos = ref([]);
        const filtroCartera = ref('todos');
        const buscarCartera = ref('');
        const abonoModal = ref({ show: false, data: null, monto: 0, metodo_pago: 'efectivo' });
        const acuerdoModal = ref({ show: false, data: null, fecha_nueva: '', monto_pactado: 0, observacion: '' });
        const estadoCuentaModal = ref({ show: false, data: null });

        // Fecha de hoy en formato YYYY-MM-DD, sin líos de zona horaria.
        const hoyISO = new Date().toLocaleDateString('en-CA');

        const cargarCartera = async () => {
            await Promise.all([cargarResumenCartera(), cargarDeudores()]);
        };

        const cargarResumenCartera = async () => {
            try {
                const res = await api(`${API_BASE}cartera.php?action=resumen`);
                carteraResumen.value = await res.json();
            } catch(e) { showToast('No se pudo cargar la cartera', 'error'); }
        };

        const cargarDeudores = async () => {
            try {
                const params = new URLSearchParams({ action: 'list', filtro: filtroCartera.value });
                if (buscarCartera.value.trim()) params.set('q', buscarCartera.value.trim());
                const res = await api(`${API_BASE}cartera.php?${params.toString()}`);
                deudoresActivos.value = await res.json();
            } catch(e) { showToast('Error cargando cartera', 'error'); }
        };

        const filtrarCartera = (filtro) => {
            filtroCartera.value = filtro;
            cargarDeudores();
        };

        const colorSemaforo = (semaforo) => ({
            rojo:     'bg-red-500',
            amarillo: 'bg-yellow-400',
            verde:    'bg-green-500',
            gris:     'bg-gray-400'
        }[semaforo] || 'bg-gray-300');

        const textoVencimiento = (d) => {
            if (d.semaforo === 'gris') return { texto: 'Ya pagó', clase: 'text-gray-400' };
            if (d.dias_mora > 0) return { texto: `Vencido hace ${d.dias_mora} día(s)`, clase: 'text-red-600 font-semibold' };
            if (d.dias === 0) return { texto: 'Vence hoy', clase: 'text-amber-600 font-semibold' };
            return { texto: `Faltan ${d.dias} día(s)`, clase: 'text-gray-500' };
        };

        const openAbonoModal = (credito) => {
            abonoModal.value = {
                show: true,
                data: credito,
                monto: parseFloat(credito.saldo_pendiente),
                metodo_pago: 'efectivo'
            };
        };

        const procesarAbono = async () => {
            try {
                const res = await api(`${API_BASE}cartera.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        credito_id: abonoModal.value.data.credito_id,
                        monto: abonoModal.value.monto,
                        metodo_pago: abonoModal.value.metodo_pago
                    })
                });
                const data = await res.json();
                if (data.success) {
                    let mensaje = 'Abono registrado';
                    if (data.mora_cobrada > 0) mensaje += ' · mora cobrada ' + formatMoney(data.mora_cobrada);
                    if (data.saldado) mensaje = '¡Fiao saldado!';
                    showToast(mensaje);
                    abonoModal.value.show = false;
                    await cargarCartera();
                    await cargarResumenPlata();
                } else {
                    showToast(data.error || 'Error registrando el abono', 'error');
                }
            } catch(e) { showToast('Error de red', 'error'); }
        };

        const openAcuerdoModal = (credito) => {
            // Propone una fecha según el plazo largo que tenga configurado el negocio.
            const dias = parseInt(config.value.plazo_2) || 15;
            const propuesta = new Date();
            propuesta.setDate(propuesta.getDate() + dias);
            acuerdoModal.value = {
                show: true,
                data: credito,
                fecha_nueva: propuesta.toLocaleDateString('en-CA'),
                monto_pactado: parseFloat(credito.total_a_pagar),
                observacion: ''
            };
        };

        const procesarAcuerdo = async () => {
            try {
                const res = await api(`${API_BASE}cartera.php?action=acuerdo`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        credito_id: acuerdoModal.value.data.credito_id,
                        fecha_nueva: acuerdoModal.value.fecha_nueva,
                        monto_pactado: acuerdoModal.value.monto_pactado,
                        observacion: acuerdoModal.value.observacion
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Acuerdo guardado · nuevo vencimiento ' + formatDate(data.fecha_nueva));
                    acuerdoModal.value.show = false;
                    await cargarCartera();
                } else {
                    showToast(data.error || 'Error guardando el acuerdo', 'error');
                }
            } catch(e) { showToast('Error de red', 'error'); }
        };

        const abrirEstadoCuenta = async (credito) => {
            try {
                const res = await api(`${API_BASE}cartera.php?action=estado_cuenta&credito_id=${credito.credito_id}`);
                estadoCuentaModal.value = { show: true, data: await res.json() };
            } catch(e) { showToast('No se pudo abrir el estado de cuenta', 'error'); }
        };

        const imprimirEstadoCuenta = () => {
            try {
                const d = estadoCuentaModal.value.data;
                printDocumentA4(generateEstadoCuentaHTML(d, config.value), 'Estado_Cuenta_' + d.credito.cliente_cedula + '.pdf');
            } catch(e) { showToast('No se pudo generar el estado de cuenta', 'error'); }
        };

        const imprimirCartera = () => {
            try {
                printDocumentA4(generateCarteraHTML(carteraResumen.value, deudoresActivos.value, config.value), 'Cartera_' + hoyISO + '.pdf');
            } catch(e) { showToast('No se pudo generar el reporte', 'error'); }
        };

        const sendWhatsApp = (credito) => {
            let phone = String(credito.cliente_telefono || '').replace(/\D/g, '');
            if (phone.length === 10 && phone.startsWith('3')) {
                phone = '57' + phone; // Prefijo Colombia
            }
            if (!phone) {
                showToast('Ese cliente no tiene teléfono registrado', 'error');
                return;
            }

            let mensaje = config.value.plantilla_whatsapp;
            if (!mensaje || !String(mensaje).trim()) {
                mensaje = 'Hola {nombre}, le saludamos de {negocio}. Le recordamos que tiene un saldo pendiente de {saldo} con vencimiento el {fecha}. Gracias por su confianza.';
            }

            // Los nombres de los reemplazos siguen la plantilla que ya trae la
            // base: {nombre} = el cliente, {negocio} = el comercio. Se aceptan
            // también {cliente} y {vence} por si alguien los escribe así.
            mensaje = String(mensaje)
                .replace(/\{nombre\}/g,   credito.cliente_nombre || '')
                .replace(/\{cliente\}/g,  credito.cliente_nombre || '')
                .replace(/\{negocio\}/g,  config.value.nombre_comercial || 'MINERVOX')
                .replace(/\{saldo\}/g,    formatMoney(credito.saldo_pendiente))
                .replace(/\{mora\}/g,     formatMoney(credito.mora))
                .replace(/\{fecha\}/g,    formatDate(credito.fecha_vencimiento))
                .replace(/\{vence\}/g,    formatDate(credito.fecha_vencimiento));

            window.open(`https://wa.me/${phone}?text=${encodeURIComponent(mensaje)}`, '_blank');
        };

        // --- Config Logic ---
        const saveConfig = async () => {
            try {
                const res = await api(`${API_BASE}configuracion.php`, {
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

        /**
         * Abre "Mi Plata". Al cajero solo le corresponde el cierre de caja:
         * la utilidad, el capital y los gastos son del dueño.
         */
        const abrirPlata = () => {
            if (!esAdmin.value) plataTab.value = 'cierre';
            currentView.value = 'plata';
            // Si se quedó en la pestaña de ventas, refrescarla al volver.
            if (esAdmin.value && plataTab.value === 'ventas') cargarVentas();
            cargarPlata();
        };

        const cargarPlata = async () => {
            if (!esAdmin.value) {
                // El cajero entra directo al cierre: lo demás le daría 403.
                plataTab.value = 'cierre';
                await cargarCierre();
                return;
            }
            await Promise.all([
                cargarResumenPlata(),
                cargarSerieUtilidad(),
                cargarUtilidadProductos(),
                cargarUtilidadTipos(),
            ]);
        };

        const cargarResumenPlata = async () => {
            try {
                const res = await api(`${API_BASE}contabilidad.php?action=resumen`);
                plataResumen.value = await res.json();
            } catch(e) { showToast('No se pudo cargar el resumen de plata', 'error'); }
        };

        const cargarSerieUtilidad = async () => {
            try {
                const res = await api(`${API_BASE}contabilidad.php?action=utilidad&agrupar=${agrupacion()}&${rangoQuery()}`);
                plataSerie.value = await res.json();
            } catch(e) { showToast('No se pudo cargar la utilidad', 'error'); }
        };

        const cargarUtilidadProductos = async () => {
            try {
                const res = await api(`${API_BASE}contabilidad.php?action=utilidad_productos&${rangoQuery()}`);
                const data = await res.json();
                plataProductos.value = data.productos || [];
            } catch(e) { showToast('No se pudo cargar la utilidad por producto', 'error'); }
        };

        const cargarUtilidadTipos = async () => {
            try {
                const res = await api(`${API_BASE}contabilidad.php?action=utilidad_tipo&${rangoQuery()}`);
                const data = await res.json();
                plataTipos.value = data.tipos || [];
            } catch(e) { /* silencioso: es un detalle del tablero */ }
        };

        // --- Gastos ---
        const cargarCategoriasGasto = async () => {
            try {
                const res = await api(`${API_BASE}gastos.php?action=categorias`);
                categoriasGasto.value = await res.json();
            } catch(e) { /* silencioso */ }
        };

        const cargarGastos = async () => {
            await cargarCategoriasGasto();
            try {
                const params = new URLSearchParams({ action: 'list' });
                if (gastosFiltro.value.desde) params.set('desde', gastosFiltro.value.desde);
                if (gastosFiltro.value.hasta) params.set('hasta', gastosFiltro.value.hasta);
                const res = await api(`${API_BASE}gastos.php?${params.toString()}`);
                const data = await res.json();
                gastos.value = data.gastos || [];
                gastosTotal.value = data.total || 0;
            } catch(e) { showToast('No se pudieron cargar los gastos', 'error'); }
        };

        const guardarGasto = async () => {
            try {
                const res = await api(`${API_BASE}gastos.php`, {
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
                const res = await api(`${API_BASE}gastos.php?id=${id}`, { method: 'DELETE' });
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
                const res = await api(`${API_BASE}proveedores.php`);
                proveedores.value = await res.json();
            } catch(e) { /* silencioso */ }
        };

        const cargarComprasPendientes = async () => {
            try {
                const res = await api(`${API_BASE}compras.php?action=pendientes`);
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
                const res = await api(`${API_BASE}compras.php`, {
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
                const res = await api(`${API_BASE}compras.php?action=pago`, {
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
                const res = await api(`${API_BASE}cierres_caja.php?action=calcular&fecha=${cierreFecha.value}`);
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
                const res = await api(`${API_BASE}cierres_caja.php`, {
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

        // ============================================================
        // VENTAS: lista, anulación y libro de controlados (Fase 4)
        // ============================================================
        const ventasFiltro = ref({ desde: '', hasta: '', q: '', controladas: false });
        const ventasLista = ref([]);
        const ventasResumen = ref({ cantidad: 0, total: 0, anuladas: 0, controladas: 0 });
        const ventasTruncada = ref(false);
        const cargandoVentas = ref(false);
        const anularModal = ref({ show: false, venta: null, motivo: '' });
        const anulando = ref(false);

        /** La fecha de hoy en el reloj del negocio (Y-m-d, sin líos de UTC). */
        const hoyLocal = () => new Date().toLocaleDateString('en-CA');

        const iniciarFiltroVentas = () => {
            if (!ventasFiltro.value.desde) ventasFiltro.value.desde = hoyLocal();
            if (!ventasFiltro.value.hasta) ventasFiltro.value.hasta = hoyLocal();
        };

        const cargarVentas = async () => {
            iniciarFiltroVentas();
            cargandoVentas.value = true;
            try {
                const params = new URLSearchParams({
                    action: 'list',
                    desde:  ventasFiltro.value.desde,
                    hasta:  ventasFiltro.value.hasta,
                });
                if (ventasFiltro.value.q) params.set('q', ventasFiltro.value.q);
                if (ventasFiltro.value.controladas) params.set('controladas', '1');

                const res  = await api(`${API_BASE}ventas.php?${params.toString()}`);
                const data = await res.json();
                ventasLista.value   = data.ventas || [];
                ventasResumen.value = data.resumen || { cantidad: 0, total: 0, anuladas: 0, controladas: 0 };
                ventasTruncada.value = !!data.truncada;
            } catch (e) {
                showToast('No se pudieron cargar las ventas', 'error');
            } finally {
                cargandoVentas.value = false;
            }
        };

        const abrirAnularModal = (venta) => {
            anularModal.value = { show: true, venta, motivo: '' };
        };

        /**
         * Anula una venta. El servidor decide si se puede: no se anula un
         * fiao con abonos ni un día que ya tiene cierre de caja. Aquí solo
         * se pide el motivo y se muestra lo que el servidor responda.
         */
        const confirmarAnulacion = async () => {
            const m = anularModal.value;
            if (!m.venta) return;
            if (m.motivo.trim().length < 5) {
                return showToast('Escriba el motivo (mínimo 5 letras)', 'error');
            }

            anulando.value = true;
            try {
                const res = await api(`${API_BASE}ventas.php?action=anular`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ venta_id: m.venta.id, motivo: m.motivo.trim() })
                });
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || 'No se pudo anular la venta', 'error');
                    return;
                }

                const aviso = (data.advertencias && data.advertencias.length)
                    ? data.advertencias[0]
                    : `Venta #${m.venta.id} anulada`;
                showToast(aviso, (data.advertencias && data.advertencias.length) ? 'error' : 'success');

                anularModal.value = { show: false, venta: null, motivo: '' };
                await Promise.all([cargarVentas(), cargarResumenPlata(), loadProductos()]);
            } catch (e) {
                /* api() ya avisó (401/403) */
            } finally {
                anulando.value = false;
            }
        };

        /** Imprime el libro de controlados del mismo periodo del filtro. */
        const imprimirLibroControlados = async () => {
            iniciarFiltroVentas();
            try {
                const params = new URLSearchParams({
                    action: 'libro',
                    desde:  ventasFiltro.value.desde,
                    hasta:  ventasFiltro.value.hasta,
                });
                const res  = await api(`${API_BASE}ventas.php?${params.toString()}`);
                const data = await res.json();

                if (!data.success) {
                    showToast(data.error || 'No se pudo armar el libro', 'error');
                    return;
                }
                if (!data.filas || data.filas.length === 0) {
                    showToast('No hay ventas de controlados en ese periodo', 'error');
                    return;
                }

                printDocumentA4(
                    generateLibroControladosHTML(data, config.value),
                    `Libro_Controlados_${data.desde}_${data.hasta}.pdf`,
                    'landscape'
                );
            } catch (e) {
                showToast('No se pudo generar el libro', 'error');
            }
        };

        // Utilities
        const formatMoney = (val) => {
            return '$ ' + parseFloat(val).toLocaleString('es-CO');
        };

        const formatDate = (dateStr) => {
            if(!dateStr) return '';
            // OJO: no usar new Date('2026-10-30') — JavaScript lo interpreta
            // como medianoche UTC y en Colombia (UTC-5) mostraría el día
            // anterior. Las fechas de la base se muestran tal cual vienen.
            const m = String(dateStr).match(/^(\d{4})-(\d{2})-(\d{2})/);
            if (m) return `${m[3]}/${m[2]}/${m[1]}`;
            return new Date(dateStr).toLocaleDateString('es-CO');
        };

        return {
            currentView, API_BASE, config, toast, productos, clientes, lowStockProducts,
            // Sesión y usuarios (Fase 5)
            sesion, esAdmin, loginForm, primerAdminForm, claveForm, sesionError,
            entrar, salir, crearPrimerAdmin, cambiarMiClave, abrirPlata,
            usuarios, usuarioModal, cargarUsuarios, openUsuarioModal, guardarUsuario, desactivarUsuario,
            cart, tipoVenta, metodoPago, clienteForm, buscandoCliente, buscarCliente, abonoInicial, diasPlazoCredito, isMobileCartOpen, processingVenta,
            toggleTipoVenta, addToCart, removeFromCart, cartTotal, fechaVencimiento, procesarVenta,
            carritoControlado, exigeCedulaContado, requiereCliente,
            productModal, openProductModal, saveProduct, deleteProduct,
            // Cartera (Fase 2)
            carteraResumen, deudoresActivos, filtroCartera, buscarCartera,
            abonoModal, acuerdoModal, estadoCuentaModal, hoyISO,
            cargarCartera, cargarResumenCartera, cargarDeudores, filtrarCartera,
            colorSemaforo, textoVencimiento, openAbonoModal, procesarAbono,
            openAcuerdoModal, procesarAcuerdo, abrirEstadoCuenta,
            imprimirEstadoCuenta, imprimirCartera, sendWhatsApp,
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
            // Ventas y controlados (Fase 4)
            ventasFiltro, ventasLista, ventasResumen, ventasTruncada, cargandoVentas, anularModal, anulando,
            cargarVentas, abrirAnularModal, confirmarAnulacion, imprimirLibroControlados,
            formatMoney, formatDate
        };
    }
});

app.mount('#app');
