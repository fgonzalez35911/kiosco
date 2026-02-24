<?php
// ventas.php - CONTROL DE APERTURA (FINAL CORREGIDO RUTAS)
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }
require_once 'includes/db.php';
require_once 'check_security.php';

$usuario_id = $_SESSION['usuario_id'];
$stmt = $conexion->prepare("SELECT id FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta'");
$stmt->execute([$usuario_id]);
$caja = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$caja) {
    header("Location: apertura_caja.php"); exit;
}
$id_caja_actual = $caja['id'];

try {
    $sqlCupones = "SELECT * FROM cupones WHERE activo = 1 AND (fecha_limite IS NULL OR fecha_limite >= CURDATE())";
    $cupones_db = $conexion->query($sqlCupones)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $cupones_db = []; }
?>
<?php require_once 'includes/layout_header.php'; ?>


<style>
    /* TUS ESTILOS ORIGINALES */
    .tabla-ventas { height: 450px; overflow-y: auto; background: white; border: 1px solid #dee2e6; border-radius: 8px; }
    @media (max-width: 992px) { .tabla-ventas { height: 300px; } }
    
    .total-box { 
        background: #212529; color: #0dfd05; padding: 20px; border-radius: 12px; 
        font-family: 'Courier New', monospace; letter-spacing: 1px; border: 4px solid #333;
        box-shadow: inset 0 0 20px rgba(0,255,0,0.1);
    }
    
    #lista-resultados, #lista-clientes-modal { max-height: 250px; overflow-y: auto; }
    
    .item-resultado { padding: 12px; cursor: pointer; border-bottom: 1px solid #eee; transition: 0.2s; background: white; }
    .item-resultado:hover { background-color: #e3f2fd; padding-left: 15px; border-left: 4px solid #75AADB; }
    
    @keyframes parpadeo { 0% { opacity: 1; } 50% { opacity: 0.6; } 100% { opacity: 1; } }
    .btn-pausada-activa { animation: parpadeo 1.5s infinite; background-color: #ffc107 !important; color: #000 !important; border: 2px solid #e0a800 !important; }

    .card-pos { border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-radius: 12px; }
    
</style>

    <div class="container pb-5"> 
        <div class="row g-4">
            
            <div class="col-lg-8 col-12 order-2 order-lg-1">
                <div class="card shadow border-0 mb-3" style="position: relative; z-index: 100;">
                    <div class="card-body position-relative">
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                            <input type="text" id="buscar-producto" class="form-control border-start-0" placeholder="Buscar producto o escanear..." autocomplete="off">
                        </div>
                        <div class="d-flex justify-content-between mt-1 px-1">
                            <div class="small text-muted">
                                <i class="bi bi-keyboard"></i> Atajos: <b>F2</b> Buscar | <b>F4</b> Clientes | <b>F7</b> Pausar | <b>F8</b> Recuperar | <b>F9</b> Cobrar
                            </div>
                        </div>
                        <div id="lista-resultados" class="list-group position-absolute w-100 shadow mt-1" style="z-index: 2000; display:none;"></div>
                    </div>
                </div>

                <div class="card shadow border-0 mb-3">
                    <div class="card-header bg-white py-2">
                        <div class="d-flex gap-2 overflow-auto pb-1" id="filtros-rapidos">
                            <button class="btn btn-sm btn-dark fw-bold rounded-pill text-nowrap" onclick="cargarRapidos('')">Todos</button>
                            <?php foreach($conexion->query("SELECT * FROM categorias WHERE activo=1") as $c): ?>
                                <button class="btn btn-sm btn-outline-secondary rounded-pill text-nowrap" onclick="cargarRapidos(<?php echo $c->id; ?>)">
                                    <?php echo $c->nombre; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="card-body bg-light p-2" style="max-height: 200px; overflow-y: auto;">
                        <div class="row g-2" id="grid-rapidos">
                            <div class="text-center w-100 text-muted small py-3">Selecciona una categoría arriba...</div>
                        </div>
                    </div>
                </div>

                <div class="card shadow border-0" style="position: relative; z-index: 1;">
                    <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-cart3"></i> Carrito de Compras</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="tabla-ventas table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th class="ps-3">Descripción</th>
                                        <th width="100">Precio</th>
                                        <th width="80">Cant.</th>
                                        <th width="100">Subtotal</th>
                                        <th width="50"></th>
                                    </tr>
                                </thead>
                                <tbody id="carrito-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-12 order-1 order-lg-2">
                <div class="card shadow border-0 h-100">
                    <div class="card-body d-flex flex-column p-4">
                        
                        <div class="d-flex justify-content-between align-items-center mb-3 p-2 border rounded bg-white">
                            <div>
                                <small class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">Cliente</small>
                                <div id="lbl-nombre-cliente" class="fw-bold text-dark text-truncate" style="max-width: 150px;">Consumidor Final</div>
                                <div id="box-puntos-cliente" style="display:none;" class="small text-warning fw-bold mt-1">
                                    <i class="bi bi-star-fill"></i> <span id="lbl-puntos">0</span> Puntos
                                </div>
                                <input type="hidden" id="id-cliente" value="1">
                                <input type="hidden" id="val-deuda" value="0">
                                <input type="hidden" id="pago-deuda-calculado" value="0">
                                <input type="hidden" id="val-puntos" value="0">
                                <input type="hidden" id="val-puntos-usados" value="0">
                            </div>
                            <div class="d-flex gap-1">
    <button class="btn btn-sm btn-outline-primary" onclick="abrirModalClientes()"><i class="bi bi-search"></i></button>
    <button class="btn btn-sm btn-outline-success" onclick="abrirModalClienteRapido()"><i class="bi bi-person-plus-fill"></i></button>
</div>

                        </div>
                        
                        <div id="info-deuda" class="d-none mb-3 text-center">
                            <div class="alert alert-danger py-1 mb-0 fw-bold">Deuda: <span id="lbl-deuda"></span></div>
                        </div>

                        <div id="info-puntos" class="d-none mb-3 text-center">
                            <div class="alert alert-warning py-1 mb-0 d-flex justify-content-between align-items-center px-2">
                                <span class="fw-bold small">Usar Puntos (-$<span id="lbl-dinero-puntos">0</span>)</span>
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" id="usar-puntos" onchange="calc()">
                                </div>
                            </div>
                        </div>
                        
                        <div id="info-saldo" class="d-none mb-3 text-center">
                            <div class="alert alert-success py-1 mb-0 d-flex justify-content-between align-items-center px-2">
                                <span class="fw-bold small">Saldo a favor: <span id="lbl-saldo">$0.00</span></span>
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" id="usar-saldo" onchange="calc()">
                                    <label class="form-check-label small fw-bold" for="usar-saldo">USAR</label>
                                </div>
                                <input type="hidden" id="val-saldo" value="0">
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="small fw-bold text-muted mb-1">Cupón %</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="bi bi-ticket-perforated"></i></span>
                                    <input type="text" id="input-cupon" class="form-control text-uppercase fw-bold" placeholder="CÓDIGO" autocomplete="off">
                                </div>
                                <div id="msg-cupon" class="small fw-bold mt-1" style="font-size: 0.75rem; display:none;"></div>
                            </div>
                            <div class="col-6">
                                <label class="small fw-bold text-muted mb-1">Desc. Manual $</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text text-danger fw-bold">- $</span>
                                    <input type="number" id="input-desc-manual" class="form-control fw-bold text-danger" placeholder="0" min="0">
                                </div>
                            </div>
                        </div>

                        <div class="total-box text-center mb-4">
                            <small class="text-uppercase text-secondary">Total a Pagar</small>
                            <h1 id="total-venta" class="display-4 fw-bold mb-0">$ 0.00</h1>
                            <div id="info-subtotal" class="small text-muted text-decoration-line-through" style="display:none; font-size: 0.9rem;">$ 0.00</div>
                        </div>

                        <div class="mb-3">
                            <label class="fw-bold small mb-1">Forma de Pago</label>
                            <select id="metodo-pago" class="form-select form-select-lg">
                                <option value="Efectivo">💵 Efectivo</option>
                                <option value="mercadopago">📱 MercadoPago</option>
                                <option value="Debito">💳 Débito</option>
                                <option value="Credito">💳 Crédito</option>
                                <option value="Mixto">💸 PAGO MIXTO</option>
                                <option value="CtaCorriente" class="fw-bold text-danger">🗒️ FIADO / CC</option>
                            </select>
                        </div>

                        <div id="btn-sync-mp" class="d-none mb-3">
                            <button type="button" class="btn btn-primary w-100 fw-bold" onclick="enviarMontoAMercadoPago()">
                                <i class="bi bi-cloud-upload"></i> CARGAR PRECIO AL QR
                            </button>
                        </div>

                        <div id="box-vuelto" class="mb-4">
                            <div class="input-group">
                                <span class="input-group-text bg-white">Paga con $</span>
                                <input type="number" id="paga-con" class="form-control form-control-lg fw-bold">
                            </div>
                            <div class="d-flex justify-content-between mt-2 px-1">
                                <span class="text-muted">Su vuelto:</span>
                                <span id="monto-vuelto" class="h5 fw-bold text-success">$ 0.00</span>
                                <div id="desglose-billetes" class="alert alert-info mt-2 small mb-0" style="display:none;"></div>
                            </div>
                        </div>
                        
                        <div id="box-mixto-info" class="alert alert-info d-none text-center">
                            <i class="bi bi-info-circle"></i> Pago Mixto Seleccionado<br>
                            <small>Detalle se confirmará al finalizar.</small>
                        </div>

                        <div class="d-flex gap-2 mb-2">
                            <button type="button" class="btn btn-warning fw-bold flex-fill" onclick="suspenderVentaActual()">
    <i class="bi bi-pause-circle"></i> ESPERA
</button>
<button type="button" class="btn btn-info fw-bold flex-fill text-white" onclick="abrirModalSuspendidas()">
    <i class="bi bi-arrow-counterclockwise"></i> RECUPERAR
</button>

                        </div>


                        <div class="d-grid gap-2 mt-auto">
                            <button id="btn-finalizar" class="btn btn-success btn-lg py-3 fw-bold shadow">
                                <i class="bi bi-check-lg"></i> CONFIRMAR VENTA
                            </button>
                            <button onclick="vaciarCarrito()" class="btn btn-outline-danger btn-sm">Cancelar Venta</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalBuscarCliente" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">Seleccionar Cliente</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" id="input-search-modal" class="form-control form-control-lg mb-3" placeholder="Escribe nombre o DNI..." autocomplete="off">
                    <div id="lista-clientes-modal" class="list-group mb-3"></div>
                    <button class="btn btn-secondary w-100" onclick="seleccionarCliente(1, 'Consumidor Final', 0, 0)">Usar Consumidor Final</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalPagoMixto" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">💸 Desglose Pago Mixto</h5>
                    <button type="button" class="btn-close btn-close-white" onclick="cerrarModalMixto()"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <span class="text-muted">Total a Pagar:</span>
                        <h2 class="fw-bold" id="total-mixto-display">$0.00</h2>
                    </div>
                    <div class="mb-2">
                        <label class="small fw-bold">Efectivo</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control input-mixto" id="mix-efectivo" placeholder="0">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="small fw-bold">MercadoPago / QR</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control input-mixto" id="mix-mp" placeholder="0">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="small fw-bold">Tarjeta Débito</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control input-mixto" id="mix-debito" placeholder="0">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="small fw-bold">Tarjeta Crédito</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control input-mixto" id="mix-credito" placeholder="0">
                        </div>
                    </div>
                    
                    <div class="alert alert-secondary mt-3 text-center py-2">
                        <div class="d-flex justify-content-between">
                            <span>Suma Pagos:</span>
                            <span class="fw-bold" id="mix-suma">$0.00</span>
                        </div>
                        <div class="d-flex justify-content-between text-danger fw-bold mt-1" id="mix-restante-box">
                            <span>Faltan:</span>
                            <span id="mix-faltan">$0.00</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalMixto()">Cancelar</button>
                    <button type="button" class="btn btn-success fw-bold" id="btn-confirmar-mixto" disabled onclick="confirmarMixto()">CONFIRMAR PAGO</button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="modalSuspendidas" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pause-circle-fill"></i> Ventas en Espera</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" id="listaSuspendidasBody"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        let carrito = []; 
        let pagosMixtosConfirmados = null;
        const cuponesDB = <?php echo json_encode($cupones_db); ?>;
        // Modo compatible para evitar error de "bootstrap is not defined"
        const modalCliente = { show: function(){ $('#modalBuscarCliente').modal('show'); }, hide: function(){ $('#modalBuscarCliente').modal('hide'); } };
        const modalMixto = { show: function(){ $('#modalPagoMixto').modal('show'); }, hide: function(){ $('#modalPagoMixto').modal('hide'); } };

        $(document).ready(function() { 
            verificarVentaPausada(); 
            // Cargar rápidos inicial
            cargarRapidos('');
        });

        document.addEventListener('keydown', function(e) {
            if(e.key === 'F2') { e.preventDefault(); $('#buscar-producto').focus(); }
            if(e.key === 'F4') { e.preventDefault(); abrirModalClientes(); }
                        if(e.key === 'F7') { e.preventDefault(); suspenderVentaActual(); }
            if(e.key === 'F8') { e.preventDefault(); abrirModalSuspendidas(); }

            if(e.key === 'F9') { e.preventDefault(); $('#btn-finalizar').click(); }
            if(Swal.isVisible()) {
                if(e.key === 'Enter') { 
                    const confirmBtn = Swal.getConfirmButton();
                    if(confirmBtn) confirmBtn.click();
                }
            }
        });
        
        // --- FUNCIÓN AGREGADA PARA QUE FUNCIONE EL GRID ---
        function cargarRapidos(categoria) {
            $('#grid-rapidos').html('<div class="text-center w-100"><div class="spinner-border spinner-border-sm"></div></div>');
            $('#filtros-rapidos button').removeClass('btn-dark fw-bold').addClass('btn-outline-secondary');
            
            $.getJSON('acciones/listar_rapidos.php', { cat: categoria }, function(data) {
                let html = '';
                if(data.length > 0) {
                    data.forEach(p => {
                        let nombre = p.descripcion.length > 15 ? p.descripcion.substring(0,15)+'..' : p.descripcion;
                        let stock = parseFloat(p.stock_actual);
                        let min = parseFloat(p.stock_minimo) || 5;
                        let bordeClass = 'border-0';
                        let textStock = '';

                        if(stock <= 0) {
                            bordeClass = 'border-2 border-danger bg-danger bg-opacity-10'; 
                            textStock = '<span class="badge bg-danger position-absolute top-0 start-0 m-1" style="font-size:0.6em">SIN STOCK</span>';
                        } else if (stock <= min) {
                            bordeClass = 'border-2 border-warning';
                        }
                        
                        let jsonProducto = JSON.stringify(p).replace(/'/g, "&#39;");

                        html += `
                        <div class="col-4 col-md-3 col-lg-2">
                            <div class="card h-100 shadow-sm ${bordeClass} producto-rapido" onclick='seleccionarProducto(${jsonProducto})' style="cursor:pointer; position:relative;">
                                ${textStock}
                                <div class="card-body p-2 text-center">
                                    <div class="fw-bold small text-truncate" title="${p.descripcion}">${nombre}</div>
                                   <div class="text-primary fw-bold small">$${(parseFloat(p.precio_oferta) > 0) ? p.precio_oferta : p.precio_venta}</div>
                                    <div class="text-muted" style="font-size:0.65rem">Stock: ${stock}</div>
                                </div>
                            </div>
                        </div>`;
                    });
                } else { html = '<div class="text-center w-100 text-muted small">No hay productos en esta categoría.</div>'; }
                $('#grid-rapidos').html(html);
            });
        }
        // --------------------------------------------------

        // FUNCIONES DE PAUSA
        function pausarVenta() {
            if(carrito.length === 0) return Swal.fire('Error', 'No hay nada para pausar', 'info');
            let estado = { carrito: carrito, cliente_id: $('#id-cliente').val(), cliente_nombre: $('#lbl-nombre-cliente').text(), cupon: $('#input-cupon').val(), desc_manual: $('#input-desc-manual').val() };
            localStorage.setItem('venta_pausada', JSON.stringify(estado));
            vaciarCarrito(); verificarVentaPausada();
            const Toast = Swal.mixin({toast: true, position: 'top-end', showConfirmButton: false, timer: 2000});
            Toast.fire({icon: 'info', title: 'Venta puesta en espera (F8)'});
        }
        function recuperarVenta() {
            let data = localStorage.getItem('venta_pausada'); if(!data) return;
            if(carrito.length > 0) { Swal.fire({title: '¿Sobreescribir?', text: "Tenés productos en pantalla.", icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, recuperar'}).then((r) => { if(r.isConfirmed) cargarVentaRecuperada(JSON.parse(data)); }); } 
            else { cargarVentaRecuperada(JSON.parse(data)); }
        }
        function cargarVentaRecuperada(estado) {
            carrito = estado.carrito;
            seleccionarCliente(estado.cliente_id, estado.cliente_nombre, 0, 0); 
            $('#input-cupon').val(estado.cupon); $('#input-desc-manual').val(estado.desc_manual);
            render(); validarCupon(); localStorage.removeItem('venta_pausada'); verificarVentaPausada();
            const Toast = Swal.mixin({toast: true, position: 'top-end', showConfirmButton: false, timer: 2000}); Toast.fire({icon: 'success', title: 'Venta recuperada'});
        }
        function verificarVentaPausada() {
            if(localStorage.getItem('venta_pausada')) $('#btn-recuperar').removeClass('d-none').addClass('btn-pausada-activa');
            else $('#btn-recuperar').addClass('d-none').removeClass('btn-pausada-activa');
        }

        // BUSCADOR CON SEMÁFORO Y CORRECCIÓN DE COMILLAS
        $('#buscar-producto').on('keyup', function(e) {
            if(e.key === 'Enter') {
                let term = $(this).val(); if(term.length < 1) return;
                $.getJSON('acciones/buscar_producto.php', { term: term }, function(res) {
                    if(res.status == 'success' && res.data.length > 0) {
                        let exacto = res.data.find(p => p.codigo_barras == term);
                        exacto ? seleccionarProducto(exacto) : (res.data.length === 1 ? seleccionarProducto(res.data[0]) : null);
                    }
                }); return;
            }
            let term = $(this).val(); if(term.length < 1) { $('#lista-resultados').hide(); return; }
            $.getJSON('acciones/buscar_producto.php', { term: term }, function(res) {
                if(res.status == 'success') {
                    if(res.data.length === 1 && res.data[0].codigo_barras == term) seleccionarProducto(res.data[0]);
                    else {
                        let html = ''; 
                        res.data.forEach(p => { 
                            let stock = parseFloat(p.stock_actual);
let colorStock = stock <= (p.stock_minimo||5) ? 'text-danger fw-bold' : 'text-muted';
let aviso = (stock <= 0 && p.tipo !== 'combo') ? '(AGOTADO)' : '';

let etiquetaStock = '';

if(p.tipo === 'combo') {
    if(p.es_ilimitado == 1) {
        etiquetaStock = '<span class="badge bg-primary"><i class="bi bi-infinity"></i> ILIMITADO</span>';
    } else if(p.fecha_inicio && p.fecha_fin) {
        let dIni = new Date(p.fecha_inicio + 'T00:00:00').toLocaleDateString('es-AR', {day:'2-digit', month:'2-digit'});
        let dFin = new Date(p.fecha_fin + 'T00:00:00').toLocaleDateString('es-AR', {day:'2-digit', month:'2-digit'});
        etiquetaStock = `<span class="badge bg-info text-dark">${dIni} al ${dFin}</span>`;
    } else {
        etiquetaStock = '<span class="badge bg-success">OFERTA</span>';
    }
} else {
    // [NUEVO] Si es producto normal y tiene precio oferta > 0
    if(parseFloat(p.precio_oferta) > 0) {
        etiquetaStock = `<span class="badge bg-danger me-1">OFERTA</span> <span class="small ${colorStock}">Stock: ${stock}</span>`;
        // Tachamos visualmente el precio viejo en la siguiente línea
    } else {
        etiquetaStock = `<div class="small ${colorStock}" style="font-size:0.75rem;">Stock: ${stock}</div>`;
    }
}

let precioMostrar = `$${p.precio_venta}`;
// Si hay oferta, mostramos el precio nuevo
if(parseFloat(p.precio_oferta) > 0) {
    precioMostrar = `<s class="text-muted small me-1">$${p.precio_venta}</s> <span class="text-danger fw-bold">$${p.precio_oferta}</span>`;
}

                            // Escape de comillas para evitar errores
                            let jsonProducto = JSON.stringify(p).replace(/'/g, "&#39;");

                            html += `
                            <div class="item-resultado d-flex justify-content-between align-items-center" onclick='seleccionarProducto(${jsonProducto})'>
                                <div>
                                    <div class="fw-bold">${p.descripcion} <small class="text-danger">${aviso}</small></div>
                                    ${etiquetaStock}
                                </div>
                                <span class="badge bg-light text-dark border">${precioMostrar}</span>
                            </div>`;
                        });
                        $('#lista-resultados').html(html).show();
                    }
                } else $('#lista-resultados').hide();
            });
        });

        window.seleccionarProducto = function(p) {
            let ex = carrito.find(i => i.id === p.id); 
            let pFinal = (parseFloat(p.precio_oferta) > 0) ? parseFloat(p.precio_oferta) : parseFloat(p.precio_venta); if(ex) ex.cantidad++; else carrito.push({id:p.id, descripcion:p.descripcion, precio:pFinal, cantidad:1});
            render(); $('#buscar-producto').val('').focus(); $('#lista-resultados').hide();
        };

        // FUNCIÓN SELECCIONAR CLIENTE MEJORADA
        window.seleccionarCliente = function(id, nombre, saldo, puntos) {
            $('#id-cliente').val(id);
            $('#lbl-nombre-cliente').text(nombre);
            
            // Lógica de Saldos
            $('#val-deuda').val(parseFloat(saldo) || 0);
            if(saldo > 0) {
                $('#lbl-deuda').text('$' + parseFloat(saldo).toFixed(2));
                $('#info-deuda').removeClass('d-none');
                $('#info-saldo').addClass('d-none');
            } else if(saldo < 0) {
                let aFavor = Math.abs(parseFloat(saldo));
                $('#val-saldo').val(aFavor);
                $('#lbl-saldo').text('$' + aFavor.toFixed(2));
                $('#info-saldo').removeClass('d-none');
                $('#info-deuda').addClass('d-none');
            } else {
                $('#info-deuda').addClass('d-none');
                $('#info-saldo').addClass('d-none');
            }

            // Lógica de Puntos (NUEVO)
            let pts = puntos ? parseFloat(puntos.toString().replace(/,/g, '')) : 0;
            $('#val-puntos').val(pts);
            
            if(pts > 0) {
                $('#lbl-puntos').text(pts);
                let dinero = pts * 1; 
                $('#lbl-dinero-puntos').text(dinero.toFixed(2));
                $('#box-puntos-cliente').show();
                $('#info-puntos').removeClass('d-none');
            } else {
                $('#box-puntos-cliente').hide();
                $('#info-puntos').addClass('d-none');
            }
            $('#usar-puntos').prop('checked', false); 
            modalCliente.hide();
            calc();
        };

        function render() {
            let h = '', subtotal = 0; 
            carrito.forEach((i, x) => { 
                subtotal += i.precio * i.cantidad; 
                h += `<tr><td class="ps-3">${i.descripcion}</td><td>$${i.precio}</td><td><input type="number" class="form-control form-control-sm text-center" value="${i.cantidad}" onchange="upd(${x},this.value)"></td><td>$${(i.precio*i.cantidad).toFixed(2)}</td><td><button class="btn btn-sm text-danger" onclick="del(${x})"><i class="bi bi-trash"></i></button></td></tr>`; 
            });
            $('#carrito-body').html(h); $('#total-venta').attr('data-subtotal', subtotal); calc();
        }

        function upd(i,v){ if(v<=0)del(i); else carrito[i].cantidad=v; render(); } 
        function del(i){ carrito.splice(i,1); render(); } 
        function vaciarCarrito(){ 
            carrito = []; 
            $('#input-cupon').val(''); 
            $('#input-desc-manual').val(''); 
            $('#paga-con').val(''); 
            $('#monto-vuelto').text('$ 0.00'); 
            $('#desglose-billetes').hide().html(''); 
            $('#usar-saldo').prop('checked', false); 
            $('#pago-deuda-calculado').val(0);
            pagosMixtosConfirmados = null;
            $('#metodo-pago').val('Efectivo');
            $('#box-vuelto').show();
            $('#box-mixto-info').addClass('d-none');
            // Resetear puntos
            $('#usar-puntos').prop('checked', false);
            $('#val-puntos-usados').val(0);
            render(); 
        }
        
        $('#paga-con').on('keyup', calc); $('#input-desc-manual').on('keyup change', calc); $('#input-cupon').on('keyup', validarCupon);

                        function validarCupon() {
            let codigo = $('#input-cupon').val().toUpperCase(); 
            let idCliente = parseInt($('#id-cliente').val()); 
            let msg = $('#msg-cupon');
            
            // Obtenemos la fecha de hoy en formato YYYY-MM-DD
            let hoy = new Date();
            let offset = hoy.getTimezoneOffset();
            hoy = new Date(hoy.getTime() - (offset*60*1000));
            let fechaHoy = hoy.toISOString().split('T')[0];

            if(codigo.length === 0) { 
                msg.hide(); 
                $('#total-venta').attr('data-porc-desc', 0); 
                calc(); 
                return; 
            }

            // Buscamos el cupón en el array que viene de la BD
            let cupon = cuponesDB.find(c => c.codigo === codigo);

            if(cupon) {
                // 1. PRIORIDAD: VALIDAR VENCIMIENTO
                if(cupon.fecha_limite < fechaHoy) {
                    msg.text('❌ CUPÓN VENCIDO (' + cupon.fecha_limite + ')').attr('class','small fw-bold mt-1 text-danger').show();
                    $('#total-venta').attr('data-porc-desc', 0);
                } 
                // 2. VALIDAR LÍMITE DE USOS
                else if(parseInt(cupon.cantidad_limite) > 0 && parseInt(cupon.usos_actuales) >= parseInt(cupon.cantidad_limite)) {
                    msg.text('❌ LÍMITE DE USOS AGOTADO').attr('class','small fw-bold mt-1 text-danger').show();
                    $('#total-venta').attr('data-porc-desc', 0);
                }
                // 3. VALIDAR CLIENTE (SI TIENE UNO ASIGNADO)
                else if(cupon.id_cliente && cupon.id_cliente != idCliente) {
                    msg.text('❌ NO VÁLIDO PARA ESTE CLIENTE').attr('class','small fw-bold mt-1 text-danger').show();
                    $('#total-venta').attr('data-porc-desc', 0);
                } 
                // 4. TODO CORRECTO
                else {
                    msg.text('✅ DESCUENTO ' + cupon.descuento_porcentaje + '% APLICADO').attr('class','small fw-bold mt-1 text-success').show();
                    $('#total-venta').attr('data-porc-desc', cupon.descuento_porcentaje);
                }
            } else {
                // Si no se encuentra el código en el array
                msg.text('❌ CÓDIGO INEXISTENTE').attr('class','small fw-bold mt-1 text-danger').show();
                $('#total-venta').attr('data-porc-desc', 0);
            }
            calc();
        }



        function calc(){ 
            let subtotal = parseFloat($('#total-venta').attr('data-subtotal')) || 0;
            let porcDesc = parseFloat($('#total-venta').attr('data-porc-desc')) || 0;
            let manualDesc = parseFloat($('#input-desc-manual').val()) || 0;
            
            // 1. Calcular descuento cupón
            let descuentoCupon = (subtotal * porcDesc) / 100;
            let aPagarTemp = subtotal - descuentoCupon - manualDesc;

            // 2. Calcular Puntos
            let descuentoPuntos = 0;
            if($('#usar-puntos').is(':checked') && aPagarTemp > 0) {
                let puntosDisponibles = parseFloat($('#val-puntos').val()) || 0;
                let valorPuntos = puntosDisponibles * 1; 
                descuentoPuntos = (valorPuntos >= aPagarTemp) ? aPagarTemp : valorPuntos;
            }
            aPagarTemp -= descuentoPuntos;
            $('#val-puntos-usados').val(descuentoPuntos);

            // 3. Calcular Saldo a Favor
            let saldoUsado = 0;
            if($('#usar-saldo').is(':checked') && aPagarTemp > 0){
                let disponible = parseFloat($('#val-saldo').val()) || 0;
                saldoUsado = (disponible >= aPagarTemp) ? aPagarTemp : disponible;
            }

            let totalFinal = subtotal - descuentoCupon - manualDesc - descuentoPuntos - saldoUsado; 
            if(totalFinal < 0) totalFinal = 0; 
            
            $('#total-venta').text('$ ' + totalFinal.toFixed(2));
            $('#total-venta').attr('data-total-final', totalFinal);
            
            let infoTxt = '';
            if(descuentoCupon > 0) infoTxt += 'Cupón: -$' + descuentoCupon.toFixed(2) + ' | ';
            if(manualDesc > 0) infoTxt += 'Manual: -$' + manualDesc.toFixed(2) + ' | ';
            if(descuentoPuntos > 0) infoTxt += 'Puntos: -$' + descuentoPuntos.toFixed(2) + ' | '; 
            if(saldoUsado > 0) infoTxt += 'Saldo Favor: -$' + saldoUsado.toFixed(2);
            
            if(infoTxt != '') $('#info-subtotal').text(infoTxt).show(); 
            else $('#info-subtotal').hide();

            // LOGICA DEUDA (SOLO SI NO ES MIXTO)
            if($('#metodo-pago').val() !== 'Mixto') {
                let paga = parseFloat($('#paga-con').val()) || 0; 
                let vueltoPre = paga - totalFinal; 
                
                let deudaCliente = parseFloat($('#val-deuda').val()) || 0;
                let montoDeudaCobrar = 0;

                if(vueltoPre > 0 && deudaCliente > 0) {
                    montoDeudaCobrar = (vueltoPre >= deudaCliente) ? deudaCliente : vueltoPre;
                }
                
                $('#pago-deuda-calculado').val(montoDeudaCobrar);

                let vueltoFinal = vueltoPre - montoDeudaCobrar;

                if(vueltoFinal >= 0 && paga > 0) { 
                    let textoVuelto = '$ ' + vueltoFinal.toFixed(2);
                    if(montoDeudaCobrar > 0) textoVuelto += ' <span class="badge bg-danger ms-2" style="font-size:0.6em">Se cobró deuda: $' + montoDeudaCobrar.toFixed(2) + '</span>';
                    $('#monto-vuelto').html(textoVuelto);
                    
                    let resto = vueltoFinal;
                    let textoBilletes = '<strong>Entregar:</strong><br>';
                    const billetes = [20000, 10000, 2000, 1000, 500, 200, 100, 50, 20, 10];
                    billetes.forEach(b => {
                        if(resto >= b) {
                            let cant = Math.floor(resto / b);
                            if(cant > 0) {
                                textoBilletes += `${cant} x $${b}<br>`;
                                resto -= (cant * b);
                            }
                        }
                    });
                    if(resto > 0) textoBilletes += `Monedas: $${resto.toFixed(2)}`;
                    $('#desglose-billetes').html(textoBilletes).show();
                } else {
                    $('#monto-vuelto').text('$ 0.00');
                    $('#desglose-billetes').hide();
                }
            }
        }
        
                $('#metodo-pago').change(function(){ 
            let val = $(this).val();
            $('#btn-sync-mp').addClass('d-none');
            $('#btn-finalizar').removeClass('d-none'); // Mostrar por defecto

            if(val == 'Mixto') {
                $('#box-vuelto').hide();
                $('#box-mixto-info').removeClass('d-none');
                abrirModalMixto();
            } else if(val == 'Efectivo') {
                $('#box-vuelto').slideDown();
                $('#box-mixto-info').addClass('d-none');
            } else if(val == 'mercadopago') { // NOMBRE CORREGIDO
                $('#box-vuelto').slideUp();
                $('#btn-sync-mp').removeClass('d-none'); 
                $('#btn-finalizar').addClass('d-none'); // <--- ACÁ ESCONDEMOS EL BOTÓN DE CONFIRMAR
                $('#box-mixto-info').addClass('d-none');
            } else {
                $('#box-vuelto').slideUp();
                $('#box-mixto-info').addClass('d-none');
            }
            calc();
        });
        function abrirModalMixto() {
            if(carrito.length === 0) {
                Swal.fire('Error', 'Carrito vacío', 'error');
                $('#metodo-pago').val('Efectivo').trigger('change');
                return;
            }
            calc(); 
            let total = parseFloat($('#total-venta').attr('data-total-final')) || 0;
            $('#total-mixto-display').text('$' + total.toFixed(2));
            $('.input-mixto').val(''); 
            calcRestanteMixto();
            modalMixto.show();
        }

        $('.input-mixto').on('keyup change', calcRestanteMixto);

        function calcRestanteMixto() {
            let total = parseFloat($('#total-venta').attr('data-total-final')) || 0;
            let ef = parseFloat($('#mix-efectivo').val()) || 0;
            let mp = parseFloat($('#mix-mp').val()) || 0;
            let db = parseFloat($('#mix-debito').val()) || 0;
            let cr = parseFloat($('#mix-credito').val()) || 0;
            
            let suma = ef + mp + db + cr;
            let faltan = total - suma;
            
            $('#mix-suma').text('$' + suma.toFixed(2));
            
            if(Math.abs(faltan) < 0.1) {
                $('#mix-restante-box').html('<span class="text-success fw-bold">¡Completo!</span>');
                $('#btn-confirmar-mixto').prop('disabled', false);
            } else if(faltan > 0) {
                $('#mix-restante-box').html('<span>Faltan:</span> <span class="text-danger">$' + faltan.toFixed(2) + '</span>');
                $('#btn-confirmar-mixto').prop('disabled', true);
            } else {
                $('#mix-restante-box').html('<span>Excede por:</span> <span class="text-warning">$' + Math.abs(faltan).toFixed(2) + '</span>');
                $('#btn-confirmar-mixto').prop('disabled', true);
            }
        }

        function confirmarMixto() {
            pagosMixtosConfirmados = {
                'Efectivo': parseFloat($('#mix-efectivo').val()) || 0,
                'MP': parseFloat($('#mix-mp').val()) || 0,
                'Debito': parseFloat($('#mix-debito').val()) || 0,
                'Credito': parseFloat($('#mix-credito').val()) || 0
            };
            modalMixto.hide();
        }

        function cerrarModalMixto() {
            modalMixto.hide();
            if(!pagosMixtosConfirmados) {
                $('#metodo-pago').val('Efectivo').trigger('change');
            }
        }

        window.abrirModalClientes = function() { $('#input-search-modal').val(''); $('#lista-clientes-modal').html(''); modalCliente.show(); setTimeout(()=>$('#input-search-modal').focus(),500); };
        
        // BUSCADOR DE CLIENTES (CORREGIDO PARA PASAR PUNTOS)
        $('#input-search-modal').on('keyup', function() {
            let term = $(this).val(); 
            if(term.length < 2) return;
            
            $.getJSON('acciones/buscar_cliente_ajax.php', { term: term }, function(res) {
                let html = ''; 
                if(res.length > 0) {
                    res.forEach(c => { 
                        let dni = c.dni ? c.dni : '--'; 
                        
                        let saldoVal = parseFloat(c.saldo.toString().replace(/,/g, '')) || 0;
                        let saldoClass = saldoVal > 0 ? 'text-danger fw-bold' : (saldoVal < 0 ? 'text-success fw-bold' : 'text-muted');
                        let saldoTexto = saldoVal > 0 ? 'Debe: $' + c.saldo : (saldoVal < 0 ? 'Favor: $' + Math.abs(saldoVal) : 'Al día');

                        html += `
                        <a href="#" class="list-group-item list-group-item-action p-3 border-bottom" 
                           onclick="seleccionarCliente(${c.id}, '${c.nombre}', '${c.saldo}', '${c.puntos}')">
                            <div class="d-flex w-100 justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1 fw-bold text-primary">${c.nombre}</h6>
                                    <small class="text-muted"><i class="bi bi-person-vcard"></i> ${dni}</small>
                                </div>
                                <div class="text-end">
                                    <div class="${saldoClass}" style="font-size:0.85rem;">${saldoTexto}</div>
                                    <small class="text-warning fw-bold"><i class="bi bi-star-fill"></i> ${c.puntos} pts</small>
                                </div>
                            </div>
                        </a>`; 
                    });
                } else {
                    html = '<div class="p-3 text-center text-muted">No se encontraron clientes.</div>';
                }
                $('#lista-clientes-modal').html(html);
            });
        });

        // PROCESAR VENTA (FINALIZAR - CORREGIDO RUTA)
        // PROCESAR VENTA (FINALIZAR)
        // PROCESAR VENTA (FINALIZAR)
        // PROCESAR VENTA (FINALIZAR)
$('#btn-finalizar').click(function() {
    if(carrito.length === 0) return Swal.fire('Error', 'El carrito está vacío.', 'error');
    
    let total = parseFloat($('#total-venta').attr('data-total-final'));
    let metodo = $('#metodo-pago').val();
    let idCliente = $('#id-cliente').val();
    
    let cupon = $('#input-cupon').val();
    let descManual = $('#input-desc-manual').val();
    let saldoUsado = ($('#usar-saldo').is(':checked')) ? $('#val-saldo').val() : 0;
    let puntosUsados = $('#val-puntos-usados').val(); 
    
    let pagosMixtos = null;
    if(metodo === 'Mixto') {
        if(!pagosMixtosConfirmados) return Swal.fire('Atención', 'Debes confirmar el desglose del Pago Mixto.', 'warning');
        pagosMixtos = JSON.stringify(pagosMixtosConfirmados);
    }

    let pagoDeuda = $('#pago-deuda-calculado').val();

    let boton = $(this);
    boton.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Procesando...');

    $.post('acciones/procesar_venta.php', {
        items: carrito,
        total: total,
        metodo_pago: metodo,
        id_cliente: idCliente,
        cupon_codigo: cupon,
        desc_manual_monto: descManual,
        saldo_favor_usado: saldoUsado,
        pago_deuda: pagoDeuda,
        pagos_mixtos: pagosMixtos,
        descuento_puntos_monto: puntosUsados 
    }, function(res) {
        boton.prop('disabled', false).html('<i class="bi bi-check-lg"></i> CONFIRMAR VENTA');
        
        if(res.status === 'success') {
            let idVenta = res.id_venta;
            let idClienteActual = $('#id-cliente').val(); 
            
            // Botones de la Modal
            let botonesHtml = `
                <div class="d-grid gap-2">
                    <button onclick="window.open('ticket.php?id=${idVenta}', 'pop-up', 'width=300,height=600')" class="btn btn-dark btn-lg py-3">
                        <i class="bi bi-printer"></i> IMPRIMIR TICKET
                    </button>`;

            // Solo mostrar correo si NO es Consumidor Final (ID 1)
            if(idClienteActual && idClienteActual != "1") { 
                botonesHtml += `
                    <button onclick="enviarTicketEmail(${idVenta})" class="btn btn-outline-primary py-2">
                        <i class="bi bi-envelope"></i> Enviar por Correo
                    </button>`;
            }

            botonesHtml += `
                    <hr>
                    <button onclick="location.reload()" class="btn btn-success btn-lg py-3">
                        <i class="bi bi-plus-circle"></i> NUEVA VENTA
                    </button>
                </div>`;

            Swal.fire({
                icon: 'success',
                title: '<span style="color:#198754">¡VENTA EXITOSA!</span>',
                html: botonesHtml,
                showConfirmButton: false,
                allowOutsideClick: false
            });
        } else {
            Swal.fire('Error', res.msg, 'error');
        }
    }, 'json');
});
        
                // --- FUNCIONES DE SUSPENSIÓN (AGREGADAS AL FINAL) ---

        function suspenderVentaActual() {
            if (carrito.length === 0) return Swal.fire('Atención', 'No hay productos para suspender.', 'warning');

            let totalVenta = parseFloat($('#total-venta').attr('data-total-final')) || 0;
            // Tomamos el nombre del cliente actual como sugerencia inicial
            let nombreCliente = $('#lbl-nombre-cliente').text();
            let valorInicial = (nombreCliente !== 'Consumidor Final') ? nombreCliente : '';

            // 1. Pedir Referencia con ventana en el medio
            Swal.fire({
                title: 'Suspender Venta',
                text: 'Escribí una referencia para identificar al cliente (Ej: "Chico gorra roja", "Señora rubia"):',
                input: 'text',
                inputValue: valorInicial,
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-save"></i> Suspender',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#ffc107', // Color amarillo de advertencia/pausa
                cancelButtonColor: '#6c757d',
                inputValidator: (value) => {
                    if (!value) {
                        return '¡Por favor escribí alguna referencia para no perderla!';
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    let referencia = result.value;

                    // 2. Enviar a guardar
                    fetch('acciones/suspender_guardar.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            carrito: carrito,
                            total: totalVenta,
                            referencia: referencia // Acá va lo que escribiste
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            vaciarCarrito();
                            
                            // 3. Cartel de éxito en el medio (SweetAlert standard)
                            Swal.fire({
                                icon: 'success',
                                title: '¡Venta Suspendida!',
                                text: 'La venta se guardó correctamente bajo la referencia: "' + referencia + '"',
                                showConfirmButton: true,
                                timer: 2000 // Se cierra solo a los 2 segundos o si tocas OK
                            });
                        } else {
                            Swal.fire('Error', data.msg || 'No se pudo suspender', 'error');
                        }
                    })
                    .catch(error => {
                        console.error(error);
                        Swal.fire('Error', 'Error de conexión al suspender', 'error');
                    });
                }
            });
        }

        function abrirModalSuspendidas() {
            $.get('acciones/suspender_listar.php', function(html) {
                $('#listaSuspendidasBody').html(html);
                $('#modalSuspendidas').modal('show');
            });
        }

        // Esta función la usa el botón "Recuperar" que viene del PHP
        window.recuperarVentaId = function(idVentaSusp) {
            $.getJSON('acciones/suspender_recuperar.php', { id: idVentaSusp }, function(res) {
                if(res.status === 'success') {
                    vaciarCarrito();
                    
                    // Reconstruir carrito con los datos que vienen de la base de datos
                    if(res.items && res.items.length > 0) {
                        res.items.forEach(item => {
                            carrito.push({
                                id: item.id,
                                descripcion: item.nombre, // Adaptamos nombre -> descripcion
                                precio: parseFloat(item.precio),
                                cantidad: parseInt(item.cantidad)
                            });
                        });
                    }

                    // Recuperar Cliente si existe
                    if(res.cliente) {
                        seleccionarCliente(res.cliente.id, res.cliente.nombre, res.cliente.saldo, res.cliente.puntos);
                    }
                    
                    render(); // Actualizar tabla visual
                    
                    // Cerrar modal
                    const modalEl = document.getElementById('modalSuspendidas');
                    const modalInstance = bootstrap.Modal.getInstance(modalEl);
                    if(modalInstance) modalInstance.hide();
                    
                    const Toast = Swal.mixin({toast: true, position: 'top-end', showConfirmButton: false, timer: 2000});
                    Toast.fire({icon: 'success', title: 'Venta recuperada'});
                } else {
                    Swal.fire('Error', 'No se pudo recuperar la venta', 'error');
                }
            });
        };
        function eliminarVentaSuspendida(id) {
            Swal.fire({
                title: '¿Eliminar esta espera?',
                text: "No podrás recuperarla después.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('acciones/suspender_eliminar.php', { id: id }, function(res) {
                        if(res.status === 'success') {
                            // Recargar la lista para que desaparezca la eliminada
                            $.get('acciones/suspender_listar.php', function(html) {
                                $('#listaSuspendidasBody').html(html);
                            });
                            
                            // Pequeña notificación
                            const Toast = Swal.mixin({toast: true, position: 'top-end', showConfirmButton: false, timer: 1500});
                            Toast.fire({icon: 'success', title: 'Eliminada'});
                        } else {
                            Swal.fire('Error', res.msg, 'error');
                        }
                    }, 'json');
                }
            });
        }
        function abrirModalClienteRapido() { $('#modalClienteRapido').modal('show'); }

function guardarClienteRapido() {
    let datos = {
        nombre: $('#rapido-nombre').val(),
        dni: $('#rapido-dni').val(),
        whatsapp: $('#rapido-wa').val(),
        email: $('#rapido-email').val()
    };
    $.post('acciones/cliente_rapido.php', datos, function(res) {
        if(res.status === 'success') {
            seleccionarCliente(res.id, res.nombre, 0, 0);
            $('#modalClienteRapido').modal('hide');
            Swal.fire('¡Listo!', 'Cliente registrado y seleccionado', 'success');
        } else { Swal.fire('Error', res.msg, 'error'); }
    }, 'json');
}

function enviarTicketWhatsApp(idVenta) {
    // Extraemos número de WhatsApp de los datos cargados o pedimos confirmación
    Swal.fire({
        title: 'Enviar WhatsApp',
        input: 'text',
        inputLabel: 'Confirmar número (incluir código de país sin +)',
        inputValue: $('#rapido-wa').val() || '549',
        showCancelButton: true,
        confirmButtonText: 'Enviar'
    }).then((result) => {
        if (result.isConfirmed) {
            let msg = encodeURIComponent('Hola! Te adjuntamos tu ticket de compra #'+idVenta+' de Drogstore El 10. Podés verlo aquí: http://'+window.location.host+'/ticket_digital.php?id='+idVenta);
            window.open(`https://wa.me/${result.value}?text=${msg}`, '_blank');
        }
    });
}

function enviarTicketEmail(idVenta) {
    Swal.fire({
        title: 'Enviando correo...',
        didOpen: () => { Swal.showLoading(); }
    });
    $.post('acciones/enviar_ticket_email.php', { id: idVenta }, function(res) {
        if(res.status === 'success') { Swal.fire('Enviado', 'El ticket fue enviado al correo del cliente', 'success'); }
        else { Swal.fire('Error', res.msg, 'error'); }
    }, 'json');
}

// Variable global para el control del tiempo
let intervaloMP = null;

function enviarMontoAMercadoPago() {
    let total = parseFloat($('#total-venta').attr('data-total-final')) || 0;
    if(total <= 0) return Swal.fire('Error', 'El monto debe ser mayor a 0', 'error');

    const btn = $('#btn-sync-mp button');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Esperando...');

    $.post('acciones/mp_sync.php', { total: total }, function(res) {
        if(res.status === 'success') {
            const ref = res.referencia;
            Swal.fire({ icon: 'info', title: 'Esperando Pago', text: 'El cliente debe escanear el QR', showConfirmButton: false });

            if(intervaloMP) clearInterval(intervaloMP);
            intervaloMP = setInterval(function() {
                $.getJSON('acciones/verificar_pago_mp.php', { referencia: ref }, function(statusRes) {
                    if(statusRes.estado === 'pagado') {
                        clearInterval(intervaloMP);
                        Swal.close();

                        // 1. Forzamos que el selector diga "mercadopago"
                        $('#metodo-pago').val('mercadopago');
                        
                        // 2. Mostramos el botón un segundo y le damos clic solo
                        $('#btn-finalizar').removeClass('d-none').click(); 
                    }
                });
            }, 3000);
        }
    }, 'json');
}
    </script>
<div class="modal fade" id="modalClienteRapido" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold">Registro Rápido de Cliente</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="small fw-bold">Nombre Completo *</label>
                    <input type="text" id="rapido-nombre" class="form-control fw-bold">
                </div>
                <div class="mb-3">
                    <label class="small fw-bold">DNI / CUIT *</label>
                    <input type="text" id="rapido-dni" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="small fw-bold">WhatsApp (Con código de país, ej: 54911...)</label>
                    <input type="text" id="rapido-wa" class="form-control" placeholder="54911...">
                </div>
                <div class="mb-3">
                    <label class="small fw-bold">Correo Electrónico</label>
                    <input type="email" id="rapido-email" class="form-control">
                </div>
                <button class="btn btn-success w-100 fw-bold py-2" onclick="guardarClienteRapido()">
                    <i class="bi bi-save"></i> GUARDAR Y SELECCIONAR
                </button>
            </div>
        </div>
    </div>
</div>


<?php require_once 'includes/layout_footer.php'; ?>