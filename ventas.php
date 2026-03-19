<?php
// ventas.php - CONTROL DE APERTURA CON SESIÓN SEGURA
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }
require_once 'includes/db.php';

// --- CARGA DE PERMISOS ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);

require_once 'check_security.php';

$usuario_id = $_SESSION['usuario_id'];

// 1. Buscamos si tenés una caja abierta y cuándo se abrió
$stmt = $conexion->prepare("SELECT id, fecha_apertura FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta'");
$stmt->execute([$usuario_id]);
$caja = $stmt->fetch(PDO::FETCH_ASSOC);

// 2. Si NO hay ninguna caja abierta (ni de hoy ni vieja), te manda a abrir una
if(!$caja) {
    header("Location: apertura_caja.php"); 
    exit;
}

// 3. Si hay una caja, guardamos su ID para las ventas
$id_caja_actual = $caja['id'];

// 4. Comparamos la fecha: ¿Se abrió antes de hoy?
$fecha_caja = date('Y-m-d', strtotime($caja['fecha_apertura']));
$hoy = date('Y-m-d');
$caja_vencida = ($fecha_caja < $hoy);

// --- FIX ERROR 500: CONSULTA SEGURA DE CONFIGURACIÓN ---
$metodo_transferencia = 'manual'; // Por defecto
try {
    $resConf = $conexion->query("SELECT metodo_transferencia FROM configuracion WHERE id=1");
    if($resConf) {
        $conf_sys = $resConf->fetch(PDO::FETCH_ASSOC);
        if($conf_sys && isset($conf_sys['metodo_transferencia'])) {
            $metodo_transferencia = $conf_sys['metodo_transferencia'];
        }
    }
} catch (Exception $e) { }

// Detectar el rubro actual para filtrar datos visuales
$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

try {
    $sqlCupones = "SELECT * FROM cupones WHERE activo = 1 AND (fecha_limite IS NULL OR fecha_limite >= CURDATE()) AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)";
    $cupones_db = $conexion->query($sqlCupones)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $cupones_db = []; }

try {
    $taras_lista = $conexion->query("SELECT * FROM taras_predefinidas WHERE tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL ORDER BY peso ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) { $taras_lista = []; }
?>
<?php require_once 'includes/layout_header.php'; ?>

<style>
    /* 1. OCULTAR BARRA DE ATAJOS POR DEFECTO (Para celulares y modo normal) */
    #pos-footer { display: none !important; }

    /* 2. MODO CAJA - SOLO PARA COMPUTADORAS (Pantalla grande) */
    @media (min-width: 992px) {
        body.modo-caja-activo header, body.modo-caja-activo .navbar, body.modo-caja-activo .topbar, 
        body.modo-caja-activo aside, body.modo-caja-activo .sidebar, body.modo-caja-activo footer { display: none !important; }
        body.modo-caja-activo .container, body.modo-caja-activo .container-fluid { max-width: 100% !important; width: 100% !important; padding: 10px 20px 60px 20px !important; margin: 0 !important; }
        
        /* Solo se activa la barra si el modo caja está encendido en PC */
        body.modo-caja-activo #pos-footer { 
            display: flex !important; 
            position: fixed; bottom: 0; left: 0; width: 100%; 
            background: #111; color: white; padding: 8px 15px; 
            z-index: 9999; border-top: 3px solid #0d6efd; 
            overflow-x: auto; white-space: nowrap; 
            gap: 15px; align-items: center; 
        }
        #pos-footer kbd { background: #333; color: #0dfd05; font-family: monospace; padding: 3px 6px; border-radius: 4px; border: 1px solid #555; margin-right: 5px; font-weight: bold; }
        #pos-footer span { font-size: 0.85rem; font-weight: bold; text-transform: uppercase; color: #ddd; }
    }

    /* SELECCIÓN EN BUSCADOR */
    .item-resultado.seleccionado { background-color: #0d6efd !important; color: white !important; border-left: 4px solid #0a58ca !important; }
    .item-resultado.seleccionado > div > .text-muted, .item-resultado.seleccionado > div > .text-danger { color: #e9ecef !important; }
    .item-resultado.seleccionado .badge { background-color: white !important; color: #0d6efd !important; border-color: white !important; }
    .item-resultado.seleccionado .badge .text-muted { color: #6c757d !important; text-decoration: line-through; }
    .item-resultado.seleccionado .badge .text-danger { color: #dc3545 !important; }

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

    /* INDICADOR DE NAVEGACIÓN POR TECLADO (Recuadro Naranja Perfecto) */
    button:focus, .btn:focus, input:focus, select:focus, a:focus, .list-group-item:focus {
        /* Usamos box-shadow para que el brillo naranja quede dentro del área visible y no se corte */
        box-shadow: inset 0 0 0 3px #ff9800, 0 0 0 3px #ff9800 !important;
        outline: none !important; /* Quitamos el anterior que se veía mal */
        position: relative;
        z-index: 50; /* Lo trae al frente para que no lo tape el vecino */
        border-radius: 4px;
        border-color: #ff9800 !important;
    }
    /* =========================================
       TEMA OSCURO EXCLUSIVO PARA MODO CAJA
       ========================================= */
    body.modo-caja-activo { background-color: #121212 !important; color: #e0e0e0 !important; }
    body.modo-caja-activo .bg-white, body.modo-caja-activo .bg-light { background-color: #1e1e1e !important; }
    body.modo-caja-activo .card { background-color: #1e1e1e !important; border-color: #333 !important; }
    body.modo-caja-activo .card-header { background-color: #2a2a2a !important; border-bottom-color: #444 !important; color: #fff !important; }
    
    /* Ajustes de contraste para textos oscuros en modo caja */
    body.modo-caja-activo .text-dark, 
    body.modo-caja-activo label, 
    body.modo-caja-activo .producto-rapido .fw-bold { color: #ffffff !important; }
    body.modo-caja-activo .text-muted { color: #ced4da !important; } /* Gris más claro para que resalte */
    
    /* Botones de Categorías */
    body.modo-caja-activo .btn-outline-secondary { color: #ffffff !important; border-color: #6c757d !important; }
    body.modo-caja-activo .btn-outline-secondary:hover { background-color: #343a40 !important; }

    /* =========================================
       MODALES, SWEETALERT Y AUTOCOMPLETADO OSCUROS
       ========================================= */
    body.modo-caja-activo .modal-content { background-color: #1e1e1e !important; color: #f8f9fa !important; border: 1px solid #444 !important; }
    body.modo-caja-activo .modal-header { border-bottom: 1px solid #333 !important; }
    body.modo-caja-activo .modal-footer { border-top: 1px solid #333 !important; }
    body.modo-caja-activo .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
    body.modo-caja-activo .modal-body label, body.modo-caja-activo .modal-body .text-muted { color: #ced4da !important; }
    
    /* Alertas SweetAlert2 */
    /* Alertas SweetAlert2 y Listas de Clientes */
    body.modo-caja-activo .swal2-popup, 
    body.modo-caja-activo .list-group-item { background-color: #1e1e1e !important; color: #f8f9fa !important; border: 1px solid #333 !important; }
    
    body.modo-caja-activo .list-group-item:hover { background-color: #2a2a2a !important; }
    body.modo-caja-activo .list-group-item .text-muted { color: #aaa !important; }
    body.modo-caja-activo .list-group-item .text-primary { color: #4dabf7 !important; } /* Un azul más claro para que resalte */
    body.modo-caja-activo .swal2-title, body.modo-caja-activo .swal2-html-container { color: #f8f9fa !important; }
    body.modo-caja-activo .swal2-input, body.modo-caja-activo .swal2-select, body.modo-caja-activo .swal2-textarea { 
        background-color: #2a2a2a !important; color: #fff !important; border-color: #555 !important; 
    }
    body.modo-caja-activo .swal2-input:focus { border-color: #ff9800 !important; box-shadow: 0 0 0 3px rgba(255, 152, 0, 0.5) !important; }
    
    /* Desplegable de Autocompletado (ej: Buscar por DNI) */
    body.modo-caja-activo .ui-autocomplete { background-color: #2a2a2a !important; color: #fff !important; border: 1px solid #444 !important; }
    body.modo-caja-activo .ui-menu-item-wrapper { color: #f8f9fa !important; }
    body.modo-caja-activo .ui-state-active, body.modo-caja-activo .ui-widget-content .ui-state-active { 
        background-color: #ff9800 !important; color: #fff !important; border-color: #ff9800 !important; 
    }

    
    /* Tablas */
    body.modo-caja-activo .tabla-ventas { background-color: #1e1e1e !important; border-color: #444 !important; }
    body.modo-caja-activo .table { color: #e0e0e0 !important; }
    body.modo-caja-activo .table-light, body.modo-caja-activo .table thead th { background-color: #2a2a2a !important; color: #fff !important; border-color: #444 !important; }
    body.modo-caja-activo .table td { border-color: #444 !important; background-color: transparent !important; color: #e0e0e0 !important; }
    body.modo-caja-activo .table-hover tbody tr:hover td { background-color: #333 !important; color: #fff !important; }
    
    /* Inputs y Selects */
    body.modo-caja-activo .form-control, body.modo-caja-activo .form-select, body.modo-caja-activo .input-group-text {
        background-color: #2a2a2a !important; color: #fff !important; border-color: #444 !important;
    }
    body.modo-caja-activo input::placeholder { color: #888 !important; }
    
    /* Buscador Resultados */
    body.modo-caja-activo .item-resultado { background-color: #2a2a2a !important; border-bottom-color: #444 !important; color: #fff !important; }
    body.modo-caja-activo .item-resultado:hover { background-color: #3a3a3a !important; }
    body.modo-caja-activo #lista-resultados { background-color: #2a2a2a !important; border: 1px solid #444 !important; }
    body.modo-caja-activo .item-resultado .badge.bg-light { background-color: #444 !important; color: #fff !important; border-color: #555 !important; }
</style>

    <div class="container pb-5"> 
        <div class="row g-4">
            
            <div class="col-lg-8 col-12 order-2 order-lg-1">
                <div class="card shadow border-0 mb-3" style="position: relative; z-index: 100;">
                    <div class="card-body position-relative">
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                            <input type="text" id="buscar-producto" class="form-control border-start-0" placeholder="Buscar producto o escanear (F1)..." autocomplete="off">
                            <button class="btn btn-primary fw-bold d-none d-lg-inline-block" id="btn-modo-caja" onclick="toggleModoCaja()" style="border-radius: 0 8px 8px 0;">
                                <i class="bi bi-arrows-fullscreen"></i> MODO CAJA
                            </button>
                        </div>
                        <div id="lista-resultados" class="list-group position-absolute w-100 shadow mt-1" style="z-index: 2000; display:none;"></div>
                    </div>
                </div>

                <div class="card shadow border-0 mb-3">
                    <div class="card-header bg-white py-2">
                        <div class="d-flex gap-2 overflow-auto pb-1" id="filtros-rapidos">
                            <button class="btn btn-sm btn-dark fw-bold rounded-pill text-nowrap" onclick="cargarRapidos('')">Todos</button>
                            <?php foreach($conexion->query("SELECT * FROM categorias WHERE activo=1 AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)") as $c): ?>
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
                            <?php if (in_array('caja_aplicar_descuento', $permisos) || $es_admin): ?>
                            <div class="col-6">
                                <label class="small fw-bold text-muted mb-1">Desc. Manual $</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text text-danger fw-bold">- $</span>
                                    <input type="number" id="input-desc-manual" class="form-control fw-bold text-danger" placeholder="0" min="0">
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="col-6 d-none">
                                <input type="hidden" id="input-desc-manual" value="0">
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="total-box text-center mb-4">
                            <small class="text-uppercase text-secondary">Total a Pagar</small>
                            <h1 id="total-venta" class="display-4 fw-bold mb-0">$ 0.00</h1>
                            <div id="info-subtotal" class="small text-muted text-decoration-line-through" style="display:none; font-size: 0.9rem;">$ 0.00</div>
                        </div>

                        <div class="mb-3">
                            <label class="fw-bold small mb-1">Forma de Pago</label>
                            <select id="metodo-pago" class="form-select form-select-lg">
                                <?php if (in_array('caja_cobrar_efectivo', $permisos) || $es_admin): ?>
                                    <option value="Efectivo">💵 Efectivo</option>
                                <?php endif; ?>
                                <?php if (in_array('caja_cobrar_mp', $permisos) || $es_admin): ?>
                                    <option value="mercadopago">📱 MercadoPago</option>
                                <?php endif; ?>
                                <?php if (in_array('caja_cobrar_transferencia', $permisos) || $es_admin): ?>
                                    <option value="Transferencia">🏦 Transferencia</option>
                                <?php endif; ?>
                                <?php if (in_array('caja_cobrar_tarjetas', $permisos) || $es_admin): ?>
                                    <option value="Debito">💳 Débito</option>
                                    <option value="Credito">💳 Crédito</option>
                                <?php endif; ?>
                                <option value="Mixto">💸 PAGO MIXTO</option>
                                <?php if (in_array('caja_cobrar_fiado', $permisos) || $es_admin): ?>
                                    <option value="CtaCorriente" class="fw-bold text-danger">🗒️ FIADO / CC</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div id="btn-sync-mp" class="d-none mb-3">
                            <button type="button" class="btn btn-primary w-100 fw-bold" onclick="enviarMontoAMercadoPago()">
                                <i class="bi bi-cloud-upload"></i> CARGAR PRECIO AL QR
                            </button>
                        </div>
                        <div id="btn-escaner" class="d-none mb-3">
                            <button type="button" class="btn btn-info w-100 fw-bold text-white shadow py-3" onclick="abrirEscanerTransferencia()">
                                <i class="bi bi-camera-video fs-5 me-2"></i> ACTIVAR LECTOR
                            </button>
                            <button type="button" class="btn btn-link w-100 text-muted small mt-1 text-decoration-none" onclick="ejecutarVentaFinalEnBD()">
                                <small>Omitir escáner y cobrar manual</small>
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

                        <?php if (in_array('caja_venta_espera', $permisos) || $es_admin): ?>
                        <div class="d-flex gap-2 mb-2">
                            <button type="button" class="btn btn-warning fw-bold flex-fill" onclick="suspenderVentaActual()">
                                <i class="bi bi-pause-circle"></i> ESPERA
                            </button>
                            <button type="button" class="btn btn-info fw-bold flex-fill text-white" onclick="abrirModalSuspendidas()">
                                <i class="bi bi-arrow-counterclockwise"></i> RECUPERAR
                            </button>
                        </div>
                        <?php endif; ?>

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

    <div id="pos-footer">
        <span><kbd>F1</kbd>BUSCAR</span>
        <span><kbd>F2</kbd>EFECTIVO</span>
        <span><kbd>F3</kbd>TRANSF.</span>
        <span><kbd>F4</kbd>M.PAGO</span>
        <span class="text-warning"><kbd>F6</kbd>ESPERA</span>
        <span class="text-info"><kbd>F7</kbd>RECUPERAR</span>
        <span><kbd>F8</kbd>DESC $</span>
        <span class="text-info"><kbd>F9</kbd>CLIENTES</span>
        <span class="text-danger"><kbd>F10</kbd>CANCELAR VENTA</span>
        <span class="text-success"><kbd>F12</kbd>COBRAR</span>
        <span><kbd>INS</kbd>CLI NUEVO</span>
        <span class="ms-auto" style="color:#888;"><kbd>SUPR</kbd>BORRAR ITEM</span>
        <span style="color:#888;"><kbd>TAB / &uarr; &darr;</kbd>NAVEGAR</span>
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

    <div class="modal fade" id="modalPesable" tabindex="-1" data-bs-backdrop="static">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
          <div class="modal-header bg-success text-white">
            <h5 class="modal-title fw-bold">⚖️ Pesar: <span id="nombreProdPesable"></span></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-4">
            <input type="hidden" id="idProdPesable">
            <input type="hidden" id="precioKgPesable">
            <div class="mb-3 mt-2">
                <label class="form-label fw-bold small text-muted">Peso en Balanza (Kg) - Ej: 0.250 para 250gr</label>
                <input type="number" step="0.001" class="form-control form-control-lg text-center fw-bold text-success" id="inputPesoManual" placeholder="0.000" style="font-size: 2rem;">
            </div>
            <div class="row mb-3">
                <div class="col-6">
                    <label class="form-label fw-bold small text-muted">Descontar Tara (Kg)</label>
                    <select class="form-select form-select-sm mb-1 border-secondary" id="selectTaraRapida" onchange="$('#inputTaraManual').val(this.value).trigger('change');">
                        <option value="0.000">Sin envase (0.000)</option>
                        <?php if(!empty($taras_lista)) { foreach($taras_lista as $t): ?>
                            <option value="<?php echo $t['peso']; ?>"><?php echo htmlspecialchars($t['nombre']); ?> (<?php echo $t['peso']; ?> Kg)</option>
                        <?php endforeach; } ?>
                    </select>
                    <input type="number" step="0.001" class="form-control fw-bold" id="inputTaraManual" value="0.000">
                </div>
                <div class="col-6 text-end">
                    <label class="form-label fw-bold small text-muted">Total a Cobrar</label>
                    <h3 id="totalCalculadoPesable" class="text-dark fw-bold m-0">$0.00</h3>
                </div>
            </div>
            <button type="button" class="btn btn-success btn-lg w-100 fw-bold shadow" id="btnAgregarPesableCarrito">
                <i class="bi bi-cart-plus"></i> AGREGAR A LA VENTA
            </button>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    
    <script>
        const metodoTransferenciaConf = '<?php echo $metodo_transferencia; ?>';

        let carrito = []; 
        let pagosMixtosConfirmados = null;
        const cuponesDB = <?php echo json_encode($cupones_db); ?>;
        const modalCliente = { show: function(){ $('#modalBuscarCliente').modal('show'); }, hide: function(){ $('#modalBuscarCliente').modal('hide'); } };
        const modalMixto = { show: function(){ $('#modalPagoMixto').modal('show'); }, hide: function(){ $('#modalPagoMixto').modal('hide'); } };

        $(document).ready(function() { 
            <?php if($caja_vencida): ?>
                $('#btn-finalizar').prop('disabled', true).removeClass('btn-success').addClass('btn-secondary');
                Swal.fire({
                    icon: 'error', title: 'Caja de día anterior', text: 'La sesión abierta es del <?php echo date('d/m/Y', strtotime($caja['fecha_apertura'])); ?>. Por seguridad, debés cerrarla antes de realizar ventas nuevas.',
                    confirmButtonText: 'Ir a cerrar caja', confirmButtonColor: '#dc3545', allowOutsideClick: false
                }).then((result) => { if (result.isConfirmed) { window.location.href = 'cierre_caja.php'; } });
            <?php endif; ?>

            verificarVentaPausada(); 
            cargarRapidos('');
            
            // FIX: Filtramos el evento de clic para que no colapse el AJAX
            $('#btn-finalizar').off('click').on('click', function(e) {
                e.preventDefault();
                ejecutarVentaFinalEnBD('completada', null);
            });
        });

        // =====================================
        // MODO CAJA (FULLSCREEN)
        // =====================================
        function toggleModoCaja() {
            let elem = document.documentElement;
            if (!document.fullscreenElement) {
                let req = elem.requestFullscreen || elem.webkitRequestFullscreen || elem.msRequestFullscreen;
                if(req) {
                    req.call(elem).then(() => {
                        // Magia: Evita que ESC salga de pantalla completa
                        if('keyboard' in navigator && navigator.keyboard.lock) { navigator.keyboard.lock(['Escape']).catch(e => console.log(e)); }
                    }).catch(err => console.log(err));
                }
                document.body.classList.add('modo-caja-activo');
                $('#btn-modo-caja').html('<i class="bi bi-fullscreen-exit"></i> SALIR MODO CAJA').removeClass('btn-primary').addClass('btn-danger');
                $('#buscar-producto').focus();
            } else {
                let exit = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
                if(exit) exit.call(document);
                if('keyboard' in navigator && navigator.keyboard.unlock) { navigator.keyboard.unlock(); }
                document.body.classList.remove('modo-caja-activo');
                $('#btn-modo-caja').html('<i class="bi bi-arrows-fullscreen"></i> MODO CAJA').removeClass('btn-danger').addClass('btn-primary');
            }
        }

        document.addEventListener('fullscreenchange', (event) => {
            if (!document.fullscreenElement) {
                if('keyboard' in navigator && navigator.keyboard.unlock) { navigator.keyboard.unlock(); }
                document.body.classList.remove('modo-caja-activo');
                $('#btn-modo-caja').html('<i class="bi bi-arrows-fullscreen"></i> MODO CAJA').removeClass('btn-danger').addClass('btn-primary');
            }
        });

        // =====================================
        // SUPER MAPA DE ATAJOS DE TECLADO (LIMPIO Y CORREGIDO)
        // =====================================
        document.addEventListener('keydown', function(e) {
            if(e.key === 'F5') return; // Dejamos que F5 actualice, pero capturamos F11

            // Atajos especiales para cerrar alertas SIN tocar ESC (para no salir de Fullscreen)
            if(Swal.isVisible()) {
                if(e.key === 'Enter') { 
                    e.preventDefault();
                    if(Swal.getTitle() && Swal.getTitle().innerText === 'Esperando Pago') {
                        Swal.close();
                        if(intervaloMP) clearInterval(intervaloMP);
                        $('#btn-sync-mp button').prop('disabled', false).html('<i class="bi bi-cloud-upload"></i> CARGAR PRECIO AL QR');
                        $('#metodo-pago').val('Efectivo').trigger('change');
                    } else {
                        const confirmBtn = Swal.getConfirmButton();
                        if(confirmBtn) confirmBtn.click();
                    }
                }
                if(e.key === '-' || e.key === 'Backspace' || e.key === 'Escape') {
                    e.preventDefault();
                    Swal.close();
                }
                return; 
            }

            switch(e.key) {
                case 'F1': e.preventDefault(); $('#buscar-producto').focus(); break;
                case 'F2': e.preventDefault(); $('#metodo-pago').val('Efectivo').trigger('change'); setTimeout(() => $('#paga-con').focus(), 100); break;
                case 'F3': e.preventDefault(); $('#metodo-pago').val('Transferencia').trigger('change'); abrirEscanerTransferencia(); break;
                case 'F4': e.preventDefault(); $('#metodo-pago').val('mercadopago').trigger('change'); enviarMontoAMercadoPago(); break;
                case 'F6': e.preventDefault(); suspenderVentaActual(); break;
                case 'F7': e.preventDefault(); abrirModalSuspendidas(); break;
                case 'F8': e.preventDefault(); $('#input-desc-manual').focus(); break;
                case 'F9': e.preventDefault(); abrirModalClientes(); break;
                case 'F10': 
                    e.preventDefault(); 
                    cancelarVentaTotal();
                    break;
                case 'F12':
                    e.preventDefault(); 
                    if(!$('#btn-finalizar').hasClass('d-none') && !$('#btn-finalizar').prop('disabled')) { 
                        ejecutarVentaFinalEnBD('completada', null); 
                    } 
                    break;
                case 'Insert': e.preventDefault(); abrirModalClienteRapido(); break;
                case 'Delete': 
                    if (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA') return;
                    e.preventDefault();
                    if(carrito.length === 0) return;
                    
                    if(carrito.length === 1) {
                        let n = carrito[0].descripcion;
                        Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 }).fire({ icon: 'warning', title: `Eliminado: ${n}` });
                        del(0);
                    } else {
                        let opciones = {};
                        carrito.forEach((item, idx) => { opciones[idx] = `[${item.cantidad}x] ${item.descripcion} ($${item.precio})`; });
                        Swal.fire({
                            title: '🗑️ Eliminar Producto', text: 'Usá las flechitas y apretá Enter', input: 'select', inputOptions: opciones, inputValue: carrito.length - 1,
                            showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc3545',
                            didOpen: () => { const select = Swal.getInput(); select.style.padding = '10px'; select.style.fontSize = '1.1rem'; select.focus(); }
                        }).then((result) => {
                            if (result.isConfirmed && result.value !== '') {
                                let idx = parseInt(result.value);
                                let n = carrito[idx].descripcion;
                                Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 }).fire({ icon: 'warning', title: `Eliminado: ${n}` });
                                del(idx);
                            }
                            setTimeout(() => $('#buscar-producto').focus(), 100);
                        });
                    }
                    break;
            }
        });
        
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

        function pausarVenta() {
            if(carrito.length === 0) return Swal.fire('Error', 'No hay nada para pausar', 'info');
            let estado = { carrito: carrito, cliente_id: $('#id-cliente').val(), cliente_nombre: $('#lbl-nombre-cliente').text(), cupon: $('#input-cupon').val(), desc_manual: $('#input-desc-manual').val() };
            localStorage.setItem('venta_pausada', JSON.stringify(estado));
            vaciarCarrito(); verificarVentaPausada();
            const Toast = Swal.mixin({toast: true, position: 'top-end', showConfirmButton: false, timer: 2000});
            Toast.fire({icon: 'info', title: 'Venta puesta en espera (F6)'});
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

        // =====================================
        // BUSCADOR INTELIGENTE CON TECLADO
        // =====================================
        let indexResultados = -1;
        $('#buscar-producto').on('keydown', function(e) {
            let items = $('.item-resultado');
            if (items.length > 0) {
                if (e.key === 'ArrowDown' || (e.key === 'Tab' && !e.shiftKey)) {
                    e.preventDefault();
                    indexResultados++;
                    if (indexResultados >= items.length) indexResultados = 0;
                    items.removeClass('seleccionado');
                    $(items[indexResultados]).addClass('seleccionado')[0].scrollIntoView({ block: 'nearest' });
                    return;
                } else if (e.key === 'ArrowUp' || (e.key === 'Tab' && e.shiftKey)) {
                    e.preventDefault();
                    indexResultados--;
                    if (indexResultados < 0) indexResultados = items.length - 1;
                    items.removeClass('seleccionado');
                    $(items[indexResultados]).addClass('seleccionado')[0].scrollIntoView({ block: 'nearest' });
                    return;
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (indexResultados >= 0) {
                        $(items[indexResultados]).click();
                        indexResultados = -1;
                        return;
                    }
                }
            }
            
            if (e.key === 'Enter') {
                e.preventDefault();
                let term = $(this).val(); 
                if(term.length < 1) return;
                
                if (items.length === 1) {
                    $(items[0]).click();
                    return;
                }

                if(term.startsWith('20') && term.length === 13) {
                    let pluBalanza = term.substring(2, 6); 
                    let pesoGramos = term.substring(6, 11);
                    let pesoKgFinal = parseInt(pesoGramos) / 1000;
                    $.getJSON('acciones/buscar_producto.php', { term: pluBalanza }, function(res) {
                        if(res.status == 'success' && res.data.length > 0) {
                            let p = res.data.find(x => x.plu == parseInt(pluBalanza) || x.codigo_barras == pluBalanza);
                            if(p) {
                                let pFinal = (parseFloat(p.precio_oferta) > 0) ? parseFloat(p.precio_oferta) : parseFloat(p.precio_venta);
                                let pesoNeto = pesoKgFinal - (parseFloat(p.tara_defecto) || 0);
                                if(pesoNeto < 0) pesoNeto = 0;
                                carrito.push({id:p.id, descripcion:p.descripcion, precio:pFinal, cantidad:pesoNeto});
                                render(); $('#buscar-producto').val('').focus(); $('#lista-resultados').hide();
                            } else { Swal.fire('Error', 'PLU de balanza no encontrado', 'error'); }
                        }
                    });
                    return;
                }
                $.getJSON('acciones/buscar_producto.php', { term: term }, function(res) {
                    if(res.status == 'success' && res.data.length > 0) {
                        let exacto = res.data.find(p => p.codigo_barras == term);
                        exacto ? seleccionarProducto(exacto) : (res.data.length === 1 ? seleccionarProducto(res.data[0]) : null);
                    }
                });
            }
        });

        $('#buscar-producto').on('keyup', function(e) {
            if(e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Tab' || e.key === 'Enter') return;
            indexResultados = -1;
            let term = $(this).val(); 
            if(term.length < 1) { $('#lista-resultados').hide(); return; }
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
                                if(parseFloat(p.precio_oferta) > 0) {
                                    etiquetaStock = `<span class="badge bg-danger me-1">OFERTA</span> <span class="small ${colorStock}">Stock: ${stock}</span>`;
                                } else {
                                    etiquetaStock = `<div class="small ${colorStock}" style="font-size:0.75rem;">Stock: ${stock}</div>`;
                                }
                            }

                            let precioMostrar = `$${p.precio_venta}`;
                            if(parseFloat(p.precio_oferta) > 0) {
                                precioMostrar = `<s class="text-muted small me-1">$${p.precio_venta}</s> <span class="text-danger fw-bold">$${p.precio_oferta}</span>`;
                            }

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
            let pFinal = (parseFloat(p.precio_oferta) > 0) ? parseFloat(p.precio_oferta) : parseFloat(p.precio_venta);
            if(p.tipo === 'pesable') {
                $('#idProdPesable').val(p.id);
                $('#nombreProdPesable').text(p.descripcion);
                $('#precioKgPesable').val(pFinal);
                
                let taraD = parseFloat(p.tara_defecto || 0).toFixed(3);
                $('#inputTaraManual').val(taraD);
                if($('#selectTaraRapida option[value="'+taraD+'"]').length > 0) { 
                    $('#selectTaraRapida').val(taraD); 
                } else { 
                    $('#selectTaraRapida').val('0.000'); 
                }

                $('#inputPesoManual').val('');
                $('#totalCalculadoPesable').text('$0.00');
                var myModal = new bootstrap.Modal(document.getElementById('modalPesable'));
                myModal.show();
                $('#modalPesable').on('shown.bs.modal', function () { $('#inputPesoManual').focus(); });
                $('#buscar-producto').val('').focus(); $('#lista-resultados').hide();
                return;
            }
            let ex = carrito.find(i => i.id === p.id); 
            if(ex) ex.cantidad++; else carrito.push({id:p.id, descripcion:p.descripcion, precio:pFinal, cantidad:1});
            render(); $('#buscar-producto').val('').focus(); $('#lista-resultados').hide();
        };

        $(document).on('input change', '#inputPesoManual, #inputTaraManual', function() {
            let pesoIngresado = parseFloat($('#inputPesoManual').val()) || 0;
            let tara = parseFloat($('#inputTaraManual').val()) || 0;
            let precioKg = parseFloat($('#precioKgPesable').val());
            let pesoNeto = pesoIngresado - tara;
            if(pesoNeto < 0) pesoNeto = 0;
            $('#totalCalculadoPesable').text('$' + (pesoNeto * precioKg).toFixed(2));
        });

        $(document).on('click', '#btnAgregarPesableCarrito', function() {
            let idProd = $('#idProdPesable').val();
            let nombreProd = $('#nombreProdPesable').text();
            let precioKg = parseFloat($('#precioKgPesable').val());
            let pesoIngresado = parseFloat($('#inputPesoManual').val()) || 0;
            let tara = parseFloat($('#inputTaraManual').val()) || 0;
            
            if(pesoIngresado <= 0) return Swal.fire('Atención', 'Ingrese un peso válido', 'warning');
            
            let pesoNetoFinal = pesoIngresado - tara;
            if(pesoNetoFinal <= 0) return Swal.fire('Atención', 'El peso debe ser mayor a la tara del envase', 'warning');

            let ex = carrito.find(i => i.id == idProd);
            if(ex) ex.cantidad += pesoNetoFinal; else carrito.push({id: idProd, descripcion: nombreProd, precio: precioKg, cantidad: pesoNetoFinal});
            render();
            bootstrap.Modal.getInstance(document.getElementById('modalPesable')).hide();
        });

        window.seleccionarCliente = function(id, nombre, saldo, puntos) {
            $('#id-cliente').val(id);
            $('#lbl-nombre-cliente').text(nombre);
            
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

            // --- INTELIGENCIA DE FOCO POST-SELECCIÓN ---
            setTimeout(() => {
                if(!$('#info-puntos').hasClass('d-none')) {
                    // Si tiene puntos, el foco va al interruptor de puntos
                    $('#usar-puntos').focus();
                } else if(!$('#info-saldo').hasClass('d-none')) {
                    // Si tiene saldo a favor, va al interruptor de saldo
                    $('#usar-saldo').focus();
                } else {
                    // Si no tiene nada de eso, va directo al campo de Cupón
                    $('#input-cupon').focus();
                }
            }, 300); // Pequeña espera para que el modal termine de cerrar
        };

        const permEliminarItem = <?php echo (in_array('caja_eliminar_item', $permisos) || $es_admin) ? 'true' : 'false'; ?>;
        
        function render() {
            let h = '', subtotal = 0; 
            carrito.forEach((i, x) => { 
                subtotal += i.precio * i.cantidad; 
                let btnEliminar = permEliminarItem ? `<button class="btn btn-sm text-danger" onclick="del(${x})"><i class="bi bi-trash"></i></button>` : '';
                h += `<tr><td class="ps-3">${i.descripcion}</td><td>$${i.precio}</td><td><input type="number" class="form-control form-control-sm text-center" value="${i.cantidad}" onchange="upd(${x},this.value)"></td><td>$${(i.precio*i.cantidad).toFixed(2)}</td><td>${btnEliminar}</td></tr>`; 
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
            $('#usar-puntos').prop('checked', false);
            $('#val-puntos-usados').val(0);
            render(); 
        }
        
        $('#paga-con').on('keyup', calc); $('#input-desc-manual').on('keyup change', calc); $('#input-cupon').on('keyup', validarCupon);

        function validarCupon() {
            let codigo = $('#input-cupon').val().toUpperCase(); 
            let idCliente = parseInt($('#id-cliente').val()); 
            let msg = $('#msg-cupon');
            let hoy = new Date();
            let offset = hoy.getTimezoneOffset();
            hoy = new Date(hoy.getTime() - (offset*60*1000));
            let fechaHoy = hoy.toISOString().split('T')[0];

            if(codigo.length === 0) { 
                msg.hide(); $('#total-venta').attr('data-porc-desc', 0); calc(); return; 
            }

            let cupon = cuponesDB.find(c => c.codigo === codigo);

            if(cupon) {
                if(cupon.fecha_limite < fechaHoy) {
                    msg.text('❌ CUPÓN VENCIDO (' + cupon.fecha_limite + ')').attr('class','small fw-bold mt-1 text-danger').show();
                    $('#total-venta').attr('data-porc-desc', 0);
                } else if(parseInt(cupon.cantidad_limite) > 0 && parseInt(cupon.usos_actuales) >= parseInt(cupon.cantidad_limite)) {
                    msg.text('❌ LÍMITE DE USOS AGOTADO').attr('class','small fw-bold mt-1 text-danger').show();
                    $('#total-venta').attr('data-porc-desc', 0);
                } else if(cupon.id_cliente && cupon.id_cliente != idCliente) {
                    msg.text('❌ NO VÁLIDO PARA ESTE CLIENTE').attr('class','small fw-bold mt-1 text-danger').show();
                    $('#total-venta').attr('data-porc-desc', 0);
                } else {
                    msg.text('✅ DESCUENTO ' + cupon.descuento_porcentaje + '% APLICADO').attr('class','small fw-bold mt-1 text-success').show();
                    $('#total-venta').attr('data-porc-desc', cupon.descuento_porcentaje);
                }
            } else {
                msg.text('❌ CÓDIGO INEXISTENTE').attr('class','small fw-bold mt-1 text-danger').show();
                $('#total-venta').attr('data-porc-desc', 0);
            }
            calc();
        }

        function calc(){ 
            let subtotal = parseFloat($('#total-venta').attr('data-subtotal')) || 0;
            let porcDesc = parseFloat($('#total-venta').attr('data-porc-desc')) || 0;
            let manualDesc = parseFloat($('#input-desc-manual').val()) || 0;
            let descuentoCupon = (subtotal * porcDesc) / 100;
            let aPagarTemp = subtotal - descuentoCupon - manualDesc;

            let descuentoPuntos = 0;
            if($('#usar-puntos').is(':checked') && aPagarTemp > 0) {
                let puntosDisponibles = parseFloat($('#val-puntos').val()) || 0;
                let valorPuntos = puntosDisponibles * 1; 
                descuentoPuntos = (valorPuntos >= aPagarTemp) ? aPagarTemp : valorPuntos;
            }
            aPagarTemp -= descuentoPuntos;
            $('#val-puntos-usados').val(descuentoPuntos);

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
            $('#btn-escaner').addClass('d-none'); 
            $('#btn-finalizar').removeClass('d-none');

            if(val == 'Mixto') {
                $('#box-vuelto').hide();
                $('#box-mixto-info').removeClass('d-none');
                abrirModalMixto();
            } else if(val == 'Efectivo') {
                $('#box-vuelto').slideDown();
                $('#box-mixto-info').addClass('d-none');
            } else if(val == 'mercadopago') { 
                $('#box-vuelto').slideUp();
                $('#btn-sync-mp').removeClass('d-none'); 
                $('#btn-finalizar').addClass('d-none'); 
                $('#box-mixto-info').addClass('d-none');
            } else if(val == 'Transferencia') {
                $('#box-vuelto').slideUp();
                $('#box-mixto-info').addClass('d-none');
                
                $('#btn-escaner').removeClass('d-none');
                $('#btn-finalizar').addClass('d-none');
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

let fotosEscaner = []; 

function abrirEscanerTransferencia(esSuma = false) {
    if (!esSuma) fotosEscaner = []; 
    
    let input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.capture = 'environment';

    input.onchange = e => {
        let file = e.target.files[0];
        if (file) {
            let img = new Image();
            img.onload = () => {
                URL.revokeObjectURL(img.src); 
                let canvas = document.createElement('canvas');
                let ctx = canvas.getContext('2d');
                
                let scale = 1280 / Math.max(img.width, img.height);
                if (scale > 1) scale = 1; 
                canvas.width = img.width * scale;
                canvas.height = img.height * scale;
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                
                let fotoBase64 = canvas.toDataURL('image/jpeg', 0.80);
                fotosEscaner.push(fotoBase64);

                Swal.fire({
                    title: fotosEscaner.length > 1 ? '¡Parte agregada!' : '¿La foto está nítida?',
                    text: `Llevás ${fotosEscaner.length} captura(s) lista(s)`,
                    imageUrl: fotoBase64,
                    imageWidth: 300,
                    showCancelButton: true,
                    showDenyButton: true,
                    confirmButtonText: '<i class="bi bi-check-lg"></i> Listo, Analizar',
                    denyButtonText: '<i class="bi bi-plus-circle"></i> Sumar otra parte',
                    cancelButtonText: '<i class="bi bi-arrow-repeat"></i> Reintentar',
                    confirmButtonColor: '#28a745',
                    denyButtonColor: '#17a2b8',
                    cancelButtonColor: '#dc3545',
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({ title: 'Analizando captura(s)...', html: 'Extrayendo datos con IA<br><i>Esto puede demorar unos segundos</i>', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                        
                        let totalEsperado = parseFloat($('#total-venta').attr('data-total-final')) || 0;

                        $.ajax({
                            url: 'acciones/procesar_ocr_transferencia.php',
                            type: 'POST',
                            dataType: 'json',
                            data: { imagenes_base64: fotosEscaner, monto_esperado: totalEsperado }, 
                            timeout: 25000, 
                            success: function(res) {
                                if (res.status === 'success' || res.status === 'warning') {
                                    let titulo = res.status === 'success' ? '¡Validación OK!' : '⚠️ ALERTA DE MONTO';
                                    let msjHtml = res.status === 'success' 
                                        ? `La IA detectó el pago por <b>$${res.monto_leido}</b>.<br><br>¿El dinero ya impactó en tu cuenta bancaria?` 
                                        : `Esperado: <b>$${res.monto_esperado}</b><br>Leído por IA: <b class="text-danger">$${res.monto_leido}</b><br><br>¿Deseás aprobar bajo tu responsabilidad?`;

                                    Swal.fire({
                                        title: titulo,
                                        html: msjHtml,
                                        icon: res.status === 'success' ? 'success' : 'warning',
                                        showDenyButton: true,
                                        showCancelButton: true,
                                        allowOutsideClick: false,
                                        confirmButtonText: '<i class="bi bi-check-circle"></i> Sí, ya impactó',
                                        denyButtonText: '<i class="bi bi-clock-history"></i> Pendiente',
                                        cancelButtonText: 'Rechazar y Borrar',
                                        confirmButtonColor: '#198754',
                                        denyButtonColor: '#ffc107',
                                        cancelButtonColor: '#dc3545'
                                    }).then((conf) => {
                                        if (conf.isConfirmed) {
                                            ejecutarVentaFinalEnBD('completada', res.id_transferencia);
                                        } else if (conf.isDenied) {
                        // Enviamos a confirmar la venta sin interrumpir el proceso
                        ejecutarVentaFinalEnBD('pendiente_transferencia', res.id_transferencia);
                    } else {
                                            $.post('ver_transferencias_ia.php', { solicitud_borrar: 1, ids_a_borrar: JSON.stringify([res.id_transferencia]) });
                                            Swal.fire('Cancelada', 'Venta cancelada y foto eliminada.', 'info');
                                        }
                                    });
                                } else {
                                    Swal.fire('Error de IA', res.msg || 'No se detectó una transferencia válida.', 'error');
                                }
                            },
                            error: function(jqXHR, textStatus) {
                                if (textStatus === 'timeout') {
                                    Swal.fire('Aviso', 'La IA tardó demasiado. Volvé a intentarlo.', 'warning');
                                } else {
                                    Swal.fire('Error', 'Se cortó la conexión con el servidor.', 'error');
                                }
                            }
                        });
                    } else if (result.isDenied) {
                        abrirEscanerTransferencia(true); 
                    } else {
                        fotosEscaner.pop(); 
                        abrirEscanerTransferencia(true); 
                    }
                });
            };
            img.src = URL.createObjectURL(file); 
        }
    };
    input.click();
}
        window.ejecutarVentaFinalEnBD = function(estadoTransf = 'completada', idTransf = null) {
            let total = parseFloat($('#total-venta').attr('data-total-final')) || 0;
            let metodo = $('#metodo-pago').val();

            if (carrito.length === 0) {
                return Swal.fire('Atención', 'El carrito está vacío. Agregá productos para cobrar.', 'warning');
            }

            // Confirmación obligatoria para evitar cobros accidentales
            if (metodo === 'Efectivo') {
                let paga = parseFloat($('#paga-con').val()) || 0;
                let vuelto = (paga > total) ? (paga - total) : 0;
                let textoVuelto = (paga > 0) ? `<br><br><div class="p-2 bg-light border rounded text-dark">Abona con: <b>$${paga.toFixed(2)}</b><br><h4 class="text-success mb-0 mt-1 fw-bold">Vuelto: $${vuelto.toFixed(2)}</h4></div>` : '';

                Swal.fire({
                    title: '¿Confirmar Cobro?',
                    html: `Total a cobrar: <b class="fs-4">$${total.toFixed(2)}</b> en <b>EFECTIVO</b>${textoVuelto}`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#198754',
                    cancelButtonColor: '#dc3545',
                    confirmButtonText: '<i class="bi bi-check-circle"></i> SÍ, COBRAR AHORA',
                    cancelButtonText: 'Cancelar'
                }).then((result) => {
                    if (result.isConfirmed) procesarVentaBackend(estadoTransf, idTransf);
                });
            } else {
                Swal.fire({
                    title: '¿Confirmar Venta?',
                    html: `Monto total: <b class="fs-4">$${total.toFixed(2)}</b><br>Medio de pago: <b>${metodo}</b>`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#198754',
                    cancelButtonColor: '#dc3545',
                    confirmButtonText: '<i class="bi bi-check-circle"></i> SÍ, COBRAR AHORA',
                    cancelButtonText: 'Cancelar'
                }).then((result) => {
                    if (result.isConfirmed) procesarVentaBackend(estadoTransf, idTransf);
                });
            }
        };

        // El motor que envía los datos a la Base de Datos (Blindado contra cuelgues)
        function procesarVentaBackend(estadoTransf, idTransf) {
            let total = parseFloat($('#total-venta').attr('data-total-final')) || 0;
            let metodo = $('#metodo-pago').val();
            let idCliente = $('#id-cliente').val() || 1;
            let cupon = $('#input-cupon').val() || '';
            let descManual = $('#input-desc-manual').val() || 0;
            let saldoUsado = ($('#usar-saldo').is(':checked')) ? ($('#val-saldo').val() || 0) : 0;
            let puntosUsados = $('#val-puntos-usados').val() || 0;

            let pagosMixtos = null;
            if(metodo === 'Mixto') {
                if(!pagosMixtosConfirmados) return Swal.fire('Atención', 'Debes confirmar el pago mixto.', 'warning');
                pagosMixtos = JSON.stringify(pagosMixtosConfirmados);
            }

            let pagoDeuda = $('#pago-deuda-calculado').val() || 0;
            let boton = $('#btn-finalizar');

            boton.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Procesando...');

            let payload = {
                items: carrito, total: total, metodo_pago: metodo, id_cliente: idCliente,
                cupon_codigo: cupon, desc_manual_monto: descManual, saldo_favor_usado: saldoUsado,
                pago_deuda: pagoDeuda, pagos_mixtos: pagosMixtos, descuento_puntos_monto: puntosUsados,
                estado_venta: estadoTransf, id_transferencia: idTransf
            };

            $.post('acciones/procesar_venta.php', payload, function(res) {
                boton.prop('disabled', false).html('<i class="bi bi-check-lg"></i> CONFIRMAR VENTA');

                if(res && res.status === 'success') {
                    let idVenta = res.id_venta;
                    let idClienteActual = $('#id-cliente').val();

                    // TU DISEÑO ORIGINAL RESTAURADO (Con mejoras internas)
                    let botonesHtml = `
                        <div class="d-grid gap-2">
                            <button onclick="imprimirTicketOculto(${idVenta})" class="btn btn-dark btn-lg py-3 shadow-sm">
                                <i class="bi bi-printer"></i> IMPRIMIR TICKET
                            </button>`;
                    if(idClienteActual && idClienteActual != "1") {
                        botonesHtml += `<button id="btnMail_${idVenta}" onclick="enviarTicketEmailDirecto(${idVenta})" class="btn btn-outline-primary py-2 shadow-sm"><i class="bi bi-envelope"></i> Enviar por Correo</button>`;
                    }
                    botonesHtml += `<hr class="my-2"><button onclick="limpiarPantallaVenta()" class="btn btn-success btn-lg py-3 shadow-sm"><i class="bi bi-plus-circle"></i> NUEVA VENTA</button></div>`;

                    let tituloFinal = (estadoTransf === 'pendiente_transferencia') ? '<span style="color:#ff9800">VENTA GUARDADA (PENDIENTE)</span>' : '<span style="color:#198754">¡VENTA EXITOSA!</span>';
                    let iconoFinal = (estadoTransf === 'pendiente_transferencia') ? 'info' : 'success';

                    Swal.fire({ icon: iconoFinal, title: tituloFinal, html: botonesHtml, showConfirmButton: false, allowOutsideClick: false });
                } else {
                    Swal.fire('Error', res?.msg || 'Error del servidor.', 'error');
                }
            }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                boton.prop('disabled', false).html('<i class="bi bi-check-lg"></i> CONFIRMAR VENTA');
                Swal.fire('Error', 'Fallo de conexión o timeout del servidor.', 'error');
                console.error("Error AJAX:", textStatus, errorThrown, jqXHR.responseText);
            });
        }

        // --- MAGIA: IMPRIMIR EN TICKETERA SIN ABRIR PESTAÑAS NUEVAS ---
        window.imprimirTicketOculto = function(id) {
            let iframe = document.getElementById('iframe-impresion');
            if (!iframe) {
                iframe = document.createElement('iframe');
                iframe.id = 'iframe-impresion';
                iframe.style.display = 'none';
                document.body.appendChild(iframe);
            }
            iframe.src = 'ticket.php?id=' + id;
        };

        // --- MAGIA: ENVIAR CORREO DESDE EL MISMO BOTÓN (Se pone verde al enviar) ---
        window.enviarTicketEmailDirecto = function(idVenta) {
            let btn = $('#btnMail_' + idVenta);
            let txtOriginal = btn.html();
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Enviando...');
            $.post('acciones/enviar_ticket_email.php', { id: idVenta }, function(res) {
                if(res && res.status === 'success') {
                    btn.removeClass('btn-outline-primary').addClass('btn-success text-white').html('<i class="bi bi-check-circle"></i> Correo Enviado');
                } else {
                    btn.prop('disabled', false).html(txtOriginal);
                    Swal.mixin({toast: true, position: 'top-end', showConfirmButton: false, timer: 3000}).fire({icon: 'error', title: res?.msg || 'Error'});
                }
            }, 'json').fail(function() {
                btn.prop('disabled', false).html(txtOriginal);
                Swal.mixin({toast: true, position: 'top-end', showConfirmButton: false, timer: 3000}).fire({icon: 'error', title: 'Error de red'});
            });
        };

        // --- MAGIA: LIMPIEZA EXTREMADAMENTE SEGURA PARA NO PERDER MODO CAJA ---
        window.limpiarPantallaVenta = function() {
            Swal.close();
            vaciarCarrito();
            
            // Reseteo de Cliente
            $('#id-cliente').val(1);
            $('#lbl-nombre-cliente').text('Consumidor Final');
            $('#val-deuda').val(0);
            $('#info-deuda').addClass('d-none');
            $('#info-saldo').addClass('d-none');
            $('#box-puntos-cliente').hide();
            $('#info-puntos').addClass('d-none');
            $('#val-puntos').val(0);
            $('#usar-puntos').prop('checked', false);
            
            // Limpieza TOTAL de Cupones, Descuentos y Medio de Pago
            $('#input-cupon').val('');
            $('#input-desc-manual').val('');
            $('#metodo-pago').val('Efectivo').trigger('change');
            if(window.pagosMixtosActuales) window.pagosMixtosActuales = [];
            if(window.puntosUsadosMonto) window.puntosUsadosMonto = 0;
            
            $('#btn-finalizar').prop('disabled', false).html('<i class="bi bi-check-lg"></i> CONFIRMAR VENTA');
            setTimeout(() => $('#buscar-producto').focus(), 200);
        };

        // --- FUNCIÓN GLOBAL PARA CANCELAR CON AVISO ---
        window.cancelarVentaTotal = function() {
            if(carrito.length === 0 && $('#id-cliente').val() == 1 && $('#input-cupon').val() === '' && $('#input-desc-manual').val() === '') {
                Swal.fire('Atención', 'La pantalla ya está limpia.', 'info');
                return;
            }
            
            Swal.fire({
                title: '¿Cancelar Venta?',
                text: 'Se borrarán los productos, descuentos y el cliente seleccionado.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-trash"></i> Sí, limpiar todo',
                confirmButtonColor: '#dc3545',
                cancelButtonText: 'Volver'
            }).then((result) => {
                if (result.isConfirmed) {
                    limpiarPantallaVenta();
                    Swal.mixin({toast: true, position: 'top-end', showConfirmButton: false, timer: 1500}).fire({icon: 'success', title: 'Pantalla reseteada a Cero'});
                }
            });
        };

        // --- INTERCEPTAR EL BOTÓN CANCELAR DEL MOUSE ---
        $(document).ready(function() {
            setTimeout(() => {
                // Si el cajero hace clic en el botón rojo de cancelar, ejecutamos la limpieza extrema
                $('button[onclick="vaciarCarrito()"]').removeAttr('onclick').off('click').on('click', function(e) {
                    e.preventDefault();
                    cancelarVentaTotal();
                });
            }, 500);
        });

        
        
        function suspenderVentaActual() {
            if (carrito.length === 0) return Swal.fire('Atención', 'No hay productos para suspender.', 'warning');
            let totalVenta = parseFloat($('#total-venta').attr('data-total-final')) || 0;
            let nombreCliente = $('#lbl-nombre-cliente').text();
            let valorInicial = (nombreCliente !== 'Consumidor Final') ? nombreCliente : '';
            Swal.fire({
                title: 'Suspender Venta',
                text: 'Escribí una referencia para identificar al cliente (Ej: "Chico gorra roja"):',
                input: 'text',
                inputValue: valorInicial,
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-save"></i> Suspender',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#ffc107',
                cancelButtonColor: '#6c757d',
                inputValidator: (value) => { if (!value) return '¡Escribí alguna referencia!'; }
            }).then((result) => {
                if (result.isConfirmed) {
                    let referencia = result.value;
                    fetch('acciones/suspender_guardar.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ carrito: carrito, total: totalVenta, referencia: referencia })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            vaciarCarrito();
                            Swal.fire({ icon: 'success', title: '¡Venta Suspendida!', text: 'Se guardó: "' + referencia + '"', timer: 2000 });
                        } else { Swal.fire('Error', data.msg || 'No se pudo suspender', 'error'); }
                    })
                    .catch(error => { console.error(error); Swal.fire('Error', 'Error de conexión', 'error'); });
                }
            });
        }

        function abrirModalSuspendidas() {
            $.get('acciones/suspender_listar.php', function(html) {
                $('#listaSuspendidasBody').html(html);
                
                // Inyectamos navegación por teclado para que el Tab no se salte el botón principal
                $('#listaSuspendidasBody').find('[onclick^="recuperarVentaId"]').each(function() {
                    $(this).attr('tabindex', '0'); // Lo hace seleccionable con TAB
                    $(this).css('outline', 'none'); 
                    
                    // Efecto visual al hacer foco con el teclado
                    $(this).on('focus', function() { $(this).css('box-shadow', 'inset 0 0 0 3px rgba(13, 110, 253, 0.5)'); });
                    $(this).on('blur', function() { $(this).css('box-shadow', 'none'); });
                    
                    // Si aprieta Enter estando posicionado, hace el clic de recuperar
                    $(this).on('keydown', function(e) {
                        if (e.key === 'Enter') { e.preventDefault(); $(this).click(); }
                    });
                });

                $('#modalSuspendidas').modal('show');
                
                // Hacemos foco automático en la primera venta de la lista al abrir
                setTimeout(() => {
                    let primero = $('#listaSuspendidasBody').find('[onclick^="recuperarVentaId"]').first();
                    if(primero.length) primero.focus();
                }, 500);
            });
        }

        window.recuperarVentaId = function(idVentaSusp) {
            $.getJSON('acciones/suspender_recuperar.php', { id: idVentaSusp }, function(res) {
                if(res.status === 'success') {
                    vaciarCarrito();
                    if(res.items && res.items.length > 0) {
                        res.items.forEach(item => {
                            carrito.push({ id: item.id, descripcion: item.nombre, precio: parseFloat(item.precio), cantidad: parseInt(item.cantidad) });
                        });
                    }
                    if(res.cliente) { seleccionarCliente(res.cliente.id, res.cliente.nombre, res.cliente.saldo, res.cliente.puntos); }
                    render(); 
                    bootstrap.Modal.getInstance(document.getElementById('modalSuspendidas')).hide();
                    Swal.fire({icon: 'success', title: 'Venta recuperada', toast: true, position: 'top-end', timer: 2000, showConfirmButton: false});
                } else { Swal.fire('Error', 'No se pudo recuperar', 'error'); }
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
                confirmButtonText: 'Sí, eliminar'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('acciones/suspender_eliminar.php', { id: id }, function(res) {
                        if(res.status === 'success') {
                            $.get('acciones/suspender_listar.php', function(html) { $('#listaSuspendidasBody').html(html); });
                            Swal.fire({icon: 'success', title: 'Eliminada', toast: true, position: 'top-end', timer: 1500, showConfirmButton: false});
                        } else { Swal.fire('Error', res.msg, 'error'); }
                    }, 'json');
                }
            });
        }

        // =====================================
        // CLIENTE RÁPIDO CON SWEETALERT2
        // =====================================
        function abrirModalClienteRapido() {
            Swal.fire({
                title: '<i class="bi bi-person-plus-fill text-success"></i> Cliente Rápido',
                html: `
                    <div class="text-start">
                        <div class="mb-2">
                            <label class="small fw-bold text-muted">Nombre Completo *</label>
                            <input type="text" id="sa2-rapido-nombre" class="form-control fw-bold" placeholder="Ej: Juan Perez" required onkeyup="generarUsuarioRapido()">
                        </div>
                        <div class="mb-2">
                            <label class="small fw-bold text-muted">Email (Opcional - Para tickets)</label>
                            <input type="email" id="sa2-rapido-email" class="form-control" placeholder="correo@ejemplo.com">
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="small fw-bold text-muted">DNI / CUIT</label>
                                <input type="number" id="sa2-rapido-dni" class="form-control" placeholder="Opcional">
                            </div>
                            <div class="col-6">
                                <label class="small fw-bold text-muted">Usuario Web</label>
                                <input type="text" id="sa2-rapido-usuario" class="form-control bg-light text-primary fw-bold" readonly>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="small fw-bold text-muted">WhatsApp (Ej: 54911...)</label>
                            <input type="number" id="sa2-rapido-telefono" class="form-control" placeholder="Opcional">
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-save"></i> Guardar y Seleccionar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#198754',
                didOpen: () => {
                    setTimeout(() => document.getElementById('sa2-rapido-nombre').focus(), 100);
                },
                preConfirm: () => {
                    const nombre = document.getElementById('sa2-rapido-nombre').value.trim();
                    const email = document.getElementById('sa2-rapido-email').value.trim();
                    const dni = document.getElementById('sa2-rapido-dni').value.trim();
                    const usuario = document.getElementById('sa2-rapido-usuario').value.trim();
                    const telefono = document.getElementById('sa2-rapido-telefono').value.trim();
                    
                    if (!nombre) {
                        Swal.showValidationMessage('El nombre es obligatorio');
                        return false;
                    }
                    
                    return $.post('acciones/cliente_rapido.php', {
                        nombre: nombre, email: email, dni: dni, usuario: usuario, whatsapp: telefono
                    }).then(res => {
                        if (res.status !== 'success') { throw new Error(res.msg || 'Error al guardar en la Base de Datos'); }
                        return res;
                    }).catch(err => {
                        Swal.showValidationMessage(err.message || 'Error de conexión');
                    });
                }
            }).then((result) => {
                if (result.isConfirmed && result.value && result.value.status === 'success') {
                    // Cierra el modal viejo por si acaso y selecciona al cliente
                    $('#modalClienteRapido').modal('hide');
                    seleccionarCliente(result.value.id, result.value.nombre, 0, 0); 
                    Swal.fire({ icon: 'success', title: '¡Creado!', text: 'El cliente ya está cargado en la venta.', timer: 2000, showConfirmButton: false });
                }
            });
        }

        function generarUsuarioRapido() {
            let nombreVal = document.getElementById('sa2-rapido-nombre').value.trim().toLowerCase();
            nombreVal = nombreVal.replace(/ñ/g, 'n').replace(/Ñ/g, 'n');
            let partes = nombreVal.split(' ').filter(p => p.length > 0);
            let userSugerido = '';
            if (partes.length === 1) { userSugerido = partes[0]; } 
            else if (partes.length >= 2) { userSugerido = partes[0].charAt(0) + partes[partes.length - 1]; }
            userSugerido = userSugerido.normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]/g, '');
            document.getElementById('sa2-rapido-usuario').value = userSugerido;
        }

        function enviarTicketWhatsApp(idVenta) {
            Swal.fire({
                title: 'Enviar WhatsApp', input: 'text', inputLabel: 'Confirmar número',
                inputValue: '549', showCancelButton: true, confirmButtonText: 'Enviar'
            }).then((result) => {
                if (result.isConfirmed) {
                    let msg = encodeURIComponent('Hola! Te adjuntamos tu ticket de compra #'+idVenta+'. Míralo aquí: http://'+window.location.host+'/ticket_digital.php?id='+idVenta);
                    window.open(`https://wa.me/${result.value}?text=${msg}`, '_blank');
                }
            });
        }

        function enviarTicketEmail(idVenta) {
            Swal.fire({ title: 'Enviando correo...', didOpen: () => { Swal.showLoading(); } });
            $.post('acciones/enviar_ticket_email.php', { id: idVenta }, function(res) {
                if(res.status === 'success') { Swal.fire('Enviado', 'Ticket enviado al cliente', 'success'); }
                else { Swal.fire('Error', res.msg, 'error'); }
            }, 'json');
        }

        let intervaloMP = null;
        function enviarMontoAMercadoPago() {
            let total = parseFloat($('#total-venta').attr('data-total-final')) || 0;
            if(total <= 0) return Swal.fire('Error', 'El monto debe ser mayor a 0', 'error');

            const btn = $('#btn-sync-mp button');
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Esperando...');

            $.post('acciones/mp_sync.php', { total: total }, function(res) {
                if(res.status === 'success') {
                    const ref = res.referencia;
                    Swal.fire({ icon: 'info', title: 'Esperando Pago', text: 'El cliente debe escanear el QR', showConfirmButton: false, showCancelButton: true, cancelButtonText: '🛑 Cancelar Espera', cancelButtonColor: '#dc3545', allowOutsideClick: false }).then((result) => {
                        if (result.dismiss === Swal.DismissReason.cancel) {
                            if(intervaloMP) clearInterval(intervaloMP);
                            btn.prop('disabled', false).html('<i class="bi bi-cloud-upload"></i> CARGAR PRECIO AL QR');
                        }
                    });

                    if(intervaloMP) clearInterval(intervaloMP);
                    intervaloMP = setInterval(function() {
                        $.getJSON('acciones/verificar_pago_mp.php', { referencia: ref }, function(statusRes) {
                            if(statusRes.estado === 'pagado') {
                                clearInterval(intervaloMP);
                                Swal.close();
                                $('#metodo-pago').val('mercadopago');
                                $('#btn-finalizar').removeClass('d-none').click(); 
                            }
                        });
                    }, 3000);
                } else {
                    btn.prop('disabled', false).html('<i class="bi bi-cloud-upload"></i> CARGAR PRECIO AL QR');
                    Swal.fire('Error', res.msg, 'error');
                }
            }, 'json');
        }
        
    </script>

<?php require_once 'includes/layout_footer.php'; ?>