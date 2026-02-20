<?php
// tienda.php - VERSIÓN FINAL: COLOR SINCRONIZADO CON CONFIGURACIÓN
session_start();
require_once 'includes/db.php';

// 1. DATA USUARIO
$cliente_logueado = null;
if(isset($_SESSION['cliente_id'])) {
    $stmtCli = $conexion->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmtCli->execute([$_SESSION['cliente_id']]);
    $cliente_logueado = $stmtCli->fetch(PDO::FETCH_ASSOC);
}

// 2. CONFIGURACIÓN (RECUPERAMOS EL WHATSAPP ESPECIAL Y EL COLOR)
$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);

// Prioridad: 1. WhatsApp Pedidos, 2. Teléfono General, 3. Vacío
$telefono_wa = !empty($conf['whatsapp_pedidos']) ? $conf['whatsapp_pedidos'] : ($conf['telefono'] ?? ''); 
$nombre_negocio = $conf['nombre_negocio'] ?? 'Kiosco';

// 3. CATEGORIAS
$categorias = $conexion->query("SELECT * FROM categorias WHERE activo=1")->fetchAll(PDO::FETCH_ASSOC);

// 4. FILTROS Y PRODUCTOS
$filtro = $_GET['q'] ?? '';
$cat_filtro = $_GET['cat'] ?? '';
$salud_filtro = $_GET['salud'] ?? ''; 
$tipo_filtro = $_GET['tipo'] ?? ''; 
$sql = "SELECT p.*, c.nombre as categoria, cb.fecha_inicio, cb.fecha_fin, cb.es_ilimitado 
        FROM productos p 
        JOIN categorias c ON p.id_categoria = c.id 
        LEFT JOIN combos cb ON p.codigo_barras = cb.codigo_barras
        WHERE p.activo = 1 AND (p.stock_actual > 0 OR p.tipo = 'combo')";

$params = [];
if($filtro) { $sql .= " AND p.descripcion LIKE ?"; $params[] = "%$filtro%"; }
if($cat_filtro) { $sql .= " AND p.id_categoria = ?"; $params[] = $cat_filtro; }

// Ajuste de nombres de columnas de salud
if($salud_filtro == 'celiaco') { $sql .= " AND p.es_celiaco = 1"; }
if($salud_filtro == 'vegano') { $sql .= " AND p.es_vegano = 1"; }

// Filtros de Tipo y Ofertas
if($tipo_filtro == 'combos') { $sql .= " AND p.tipo = 'combo'"; }
elseif($tipo_filtro == 'ofertas') { $sql .= " AND p.precio_oferta > 0"; }

$sql .= " ORDER BY p.es_destacado_web DESC, p.descripcion ASC";
$stmt = $conexion->prepare($sql);
$stmt->execute($params);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- DISEÑO (CORREGIDO: TOMA EL COLOR DE LA BASE DE DATOS) ---
$color_db = $conf['color_principal'] ?? '#102A57'; // Color por defecto si no hay nada
$color_pri = $color_db;
$color_sec = $color_db; // Usamos el mismo para mantener consistencia, o podrías aclararlo
$deg_dir = '135deg';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($nombre_negocio); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f4f6f9; padding-bottom: 80px; }
        /* BANNER DINÁMICO CON TU COLOR */
        .hero-banner { 
            background: <?php echo $color_pri; ?>; /* Fallback solido */
            background: linear-gradient(<?php echo $deg_dir; ?>, <?php echo $color_pri; ?>, <?php echo $color_sec; ?>); 
            color: white; 
            padding: 30px 20px; 
            border-radius: 0 0 25px 25px; 
            margin-bottom: 20px; 
            text-align: center; 
        }
        .card-producto { border: none; border-radius: 15px; overflow: hidden; background: white; box-shadow: 0 4px 6px rgba(0,0,0,0.02); transition: transform 0.2s; height: 100%; }
        .img-container { height: 180px; width: 100%; background-color: #fff; display: flex; align-items: center; justify-content: center; position: relative; }
        .img-producto { max-height: 150px; max-width: 90%; object-fit: contain; }
        .btn-add-circle { width: 35px; height: 35px; border-radius: 50%; background: #28a745; color: white; border: none; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(0,0,0,0.2); transition: transform 0.2s; cursor: pointer; }
        .btn-add-circle:active { transform: scale(0.9); }
        .dock-container { position: fixed; bottom: 20px; right: 20px; z-index: 9999; background: rgba(20, 20, 20, 0.95); backdrop-filter: blur(10px); padding: 15px; border-radius: 50%; box-shadow: 0 10px 30px rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.2); cursor: pointer; transition: transform 0.2s; display: flex; align-items: center; justify-content: center; width: 60px; height: 60px; }
        .dock-container:active { transform: scale(0.95); }
        .cart-badge { position: absolute; top: -5px; right: -5px; background: #ffde00; color: black; font-size: 0.75rem; font-weight: 800; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 5px rgba(0,0,0,0.5); border: 2px solid #222; }
        .cart-item { padding: 10px 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        #cartList { max-height: 40vh; overflow-y: auto !important; width: 100%; display: block; padding-right: 5px; margin-bottom: 15px; }
        #cartList::-webkit-scrollbar { width: 8px; }
        #cartList::-webkit-scrollbar-thumb { background: #999; border-radius: 4px; }
        .modal { z-index: 10000 !important; } .modal-backdrop { z-index: 9999 !important; }
        .logo-tienda { height: 35px; margin-right: 5px; }
        .avatar-nav { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 2px solid #eee; }
        .btn-nav-sm { font-size: 0.85rem; padding: 5px 10px; white-space: nowrap; }
        
        /* ESTILOS FORMULARIO MODAL */
        .form-label-sm { font-size: 0.85rem; font-weight: 600; margin-bottom: 2px; }
        .form-control-sm { font-size: 0.9rem; padding: 8px; }
        /* Asegurar que SweetAlert esté por encima del modal de 10000 */
.swal2-container { z-index: 20001 !important; }
        
    </style>
</head>
<body>

    <nav class="navbar navbar-expand bg-white shadow-sm sticky-top">
        <div class="container d-flex justify-content-between align-items-center">
            <a href="tienda.php" class="navbar-brand fw-bold d-flex align-items-center text-decoration-none text-truncate" style="color: <?php echo $color_pri; ?>; max-width: 60%;">
                <?php if(!empty($conf['logo_url']) && file_exists($conf['logo_url'])): ?>
                    <img src="<?php echo $conf['logo_url']; ?>" class="logo-tienda" alt="Logo">
                <?php else: ?>
                    <i class="bi bi-shop me-2"></i> 
                <?php endif; ?>
                <span class="text-truncate"><?php echo htmlspecialchars($nombre_negocio); ?></span>
            </a>
            <div class="d-flex gap-2 align-items-center">
                <?php if($cliente_logueado): ?>
                    <div class="dropdown">
                        <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
                            <img src="<?php echo !empty($cliente_logueado['foto_perfil']) ? $cliente_logueado['foto_perfil'] : 'img/default_user.png'; ?>" class="avatar-nav">
                            <span class="d-none d-sm-block ms-2 small fw-bold text-dark"><?php echo explode(' ', $cliente_logueado['nombre'])[0]; ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                            <li><a class="dropdown-item" href="perfil_cliente.php"><i class="bi bi-person-badge me-2"></i> Mi Perfil</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="logout_cliente.php"><i class="bi bi-box-arrow-right me-2"></i> Salir</a></li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a href="login_cliente.php" class="btn btn-outline-primary btn-nav-sm rounded-pill fw-bold">Ingresar</a>
                    <a href="registro_cliente.php" class="btn btn-primary btn-nav-sm rounded-pill fw-bold">Registrarme</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="hero-banner shadow">
        <h2 class="fw-bold">¡Pedí Online!</h2>
        <form action="" method="GET" class="d-flex justify-content-center mt-3">
            <div class="input-group" style="max-width: 500px;">
                <input type="text" name="q" class="form-control border-0 rounded-start-pill py-2 ps-4" placeholder="Buscar..." value="<?php echo htmlspecialchars($filtro); ?>">
                <button class="btn btn-light border-0 rounded-end-pill" style="color: <?php echo $color_pri; ?>;" type="submit"><i class="bi bi-search"></i></button>
            </div>
        </form>
        <div class="mt-3">
            <a href="revista.php" class="btn btn-warning fw-bold rounded-pill shadow-sm text-dark px-4"><i class="bi bi-book-half me-1"></i> Ver Revista</a>
        </div>
    </div>

    <div class="container mb-3">
        <div class="d-flex gap-2 overflow-auto w-100 pb-2" style="white-space: nowrap;">
            <a href="tienda.php" class="btn btn-sm rounded-pill shadow-sm <?php echo ($cat_filtro == '' && $salud_filtro == '') ? 'btn-primary' : 'btn-light'; ?>">Todo</a>
            <?php foreach($categorias as $c): ?>
                <a href="tienda.php?cat=<?php echo $c['id']; ?>" class="btn btn-sm rounded-pill shadow-sm <?php echo $cat_filtro == $c['id'] ? 'btn-primary' : 'btn-light'; ?>"><?php echo $c['nombre']; ?></a>
            <?php endforeach; ?>
            <div class="vr mx-1"></div>
            <a href="tienda.php?salud=celiaco" class="btn btn-sm rounded-pill shadow-sm <?php echo $salud_filtro == 'celiaco' ? 'btn-primary' : 'btn-outline-warning text-dark'; ?>">🌾 Sin TACC</a>
            <a href="tienda.php?salud=vegano" class="btn btn-sm rounded-pill shadow-sm <?php echo $salud_filtro == 'vegano' ? 'btn-primary' : 'btn-outline-success text-dark'; ?>">🌱 Vegano</a>
            <a href="tienda.php?tipo=combos" class="btn btn-sm rounded-pill shadow-sm <?php echo $tipo_filtro == 'combos' ? 'btn-primary' : 'btn-outline-dark'; ?>">🎁 Packs/Combos</a>
<a href="tienda.php?tipo=ofertas" class="btn btn-sm rounded-pill shadow-sm <?php echo $tipo_filtro == 'ofertas' ? 'btn-primary' : 'btn-outline-danger'; ?>">🔥 Super Ofertas</a>
        </div>
    </div>

    <div class="container">
        <div class="row g-3">
            <?php if(count($productos) > 0): ?>
                <?php foreach($productos as $p): ?>
                <div class="col-6 col-md-4 col-lg-3">
                    <div class="card card-producto h-100">
                        <div class="img-container">
                            <img src="<?php echo $p['imagen_url'] ?: 'img/no-image.png'; ?>" class="img-producto">
                            <?php if($p['stock_actual'] < $p['stock_minimo']): ?>
                                <div class="position-absolute top-0 start-0 m-2">
    <?php if($p['tipo'] === 'combo'): ?>
        <?php if($p['es_ilimitado']): ?>
            <span class="badge bg-warning text-dark shadow-sm">🔥 ÚLTIMA OFERTA</span>
        <?php else: ?>
            <span class="badge bg-info text-dark shadow-sm" style="font-size:0.65rem;">
                ⏳ Oferta: <?php echo date('d/m', strtotime($p['fecha_inicio'])); ?> al <?php echo date('d/m', strtotime($p['fecha_fin'])); ?>
            </span>
        <?php endif; ?>
    <?php elseif($p['stock_actual'] < $p['stock_minimo']): ?>
        <span class="badge bg-danger shadow-sm">Poco Stock</span>
    <?php endif; ?>
</div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-3 d-flex flex-column" style="position:relative;">
                            <?php 
                                $tiene_oferta = (!empty($p['precio_oferta']) && $p['precio_oferta'] > 0);
                                $precio_final = $tiene_oferta ? $p['precio_oferta'] : $p['precio_venta'];
                            ?>
                            
                            <?php if($tiene_oferta): ?>
                                <span class="badge bg-danger position-absolute top-0 end-0 m-2 shadow-sm">OFERTA</span>
                            <?php endif; ?>

                            <h6 class="card-title fw-bold mb-1 text-dark text-truncate"><?php echo $p['descripcion']; ?></h6>
                            <small class="text-muted mb-2"><?php echo $p['categoria']; ?></small>
                            
                            <div class="mt-auto d-flex justify-content-between align-items-center">
                                <div class="d-flex flex-column">
                                    <?php if($tiene_oferta): ?>
                                        <small class="text-decoration-line-through text-muted" style="font-size: 0.8rem;">$<?php echo number_format($p['precio_venta'], 0); ?></small>
                                        <span class="fs-5 fw-bold text-danger">$<?php echo number_format($p['precio_oferta'], 0); ?></span>
                                    <?php else: ?>
                                        <span class="fs-5 fw-bold" style="color: <?php echo $color_pri; ?>;">$<?php echo number_format($p['precio_venta'], 0); ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <button class="btn-add-circle" onclick="addCart(<?php echo $p['id']; ?>, '<?php echo addslashes($p['descripcion']); ?>', <?php echo $precio_final; ?>, <?php echo $p['stock_actual']; ?>)">
    <i class="bi bi-plus-lg"></i>
</button>

                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12 text-center py-5 text-muted"><i class="bi bi-search fs-1"></i><p class="mt-2">No hay productos.</p></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dock-container" onclick="openCart()">
        <i class="bi bi-cart4 text-white fs-3"></i>
        <span class="cart-badge" id="badgeCount">0</span>
    </div>

    <div class="modal fade" id="modalC" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold">Tu Pedido</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="cartList"></div>
                    
                    <div class="d-flex justify-content-between fw-bold fs-5 mb-3 border-top pt-2">
                        <span>Total:</span>
                        <span class="text-danger" id="cartTotal">$0</span>
                    </div>

                    <div class="bg-light p-3 rounded-3">
                        <h6 class="fw-bold mb-2"><i class="bi bi-person-vcard"></i> Datos para el envío</h6>
                        
                        <div class="mb-2">
                            <label class="form-label-sm text-danger">* Nombre (Obligatorio)</label>
                            <input type="text" id="cli_nombre" class="form-control form-control-sm" placeholder="Tu nombre..." value="<?php echo $cliente_logueado['nombre'] ?? ''; ?>">
                        </div>
                        
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label-sm">Teléfono (Opcional)</label>
                                <input type="tel" id="cli_tel" class="form-control form-control-sm" placeholder="WhatsApp..." value="<?php echo $cliente_logueado['telefono'] ?? ''; ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label-sm">Email (Opcional)</label>
                                <input type="email" id="cli_email" class="form-control form-control-sm" placeholder="@email..." value="<?php echo $cliente_logueado['email'] ?? ''; ?>">
                            </div>
                        </div>
                        <div class="mt-2">
                            <label class="form-label-sm">Dirección de Entrega (Opcional)</label>
                            <input type="text" id="cli_dir" class="form-control form-control-sm" placeholder="Calle, Altura, Localidad..." value="<?php echo $cliente_logueado['direccion'] ?? ''; ?>">
                        </div>
                    </div>

                </div>
                <div class="modal-footer flex-column align-items-stretch border-top-0 pt-0">
                    <div class="d-flex gap-2 w-100 mt-2">
                        <button class="btn btn-outline-danger w-50 rounded-pill" onclick="clearCart()">Vaciar</button>

                        <button class="btn btn-success w-50 fw-bold rounded-pill" onclick="sendWA()">
                            <i class="bi bi-whatsapp"></i> ENVIAR
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        let cart = [];
        let modalCart;

        function initCart() {
            modalCart = new bootstrap.Modal(document.getElementById('modalC'));
            try {
                let r = localStorage.getItem('carrito_kiosco');
                if(r) cart = JSON.parse(r).filter(i => i && !isNaN(parseFloat(i.precio)) && !isNaN(parseInt(i.cant)));
            } catch(e) { cart=[]; localStorage.removeItem('carrito_kiosco'); }
            updBadge();
        }

        function addCart(id, n, p, s) {
    let ex = cart.find(i => i.id == id);
    // Si ya existe y el siguiente click supera el stock (y no es combo ilimitado)
    if(ex) {
        if(ex.cant + 1 > s) {
            const Toast = Swal.mixin({toast: true, position: 'top', showConfirmButton: false, timer: 2000, background: '#dc3545', color: '#fff'});
            Toast.fire({icon: 'error', title: 'Sin stock suficiente ('+s+' disp.)'});
            return;
        }
        ex.cant++;
    } else { 
        // Si es el primero, verificamos que al menos haya 1 (aunque el SQL ya filtra, por seguridad)
        if(s <= 0) {
            const Toast = Swal.mixin({toast: true, position: 'top', showConfirmButton: false, timer: 2000, background: '#dc3545', color: '#fff'});
            Toast.fire({icon: 'error', title: 'Producto sin stock'});
            return;
        }
        cart.push({id, nombre:n, precio:p, cant:1, stock_max:s}); 
    }
        save(); updBadge();
    const Toast = Swal.mixin({toast: true, position: 'top', showConfirmButton: false, timer: 1000, background: '#28a745', color: '#fff'});
    Toast.fire({icon: 'success', title: '¡Agregado!'});
}

// --- FUNCIONES VITALES DE SINCRONIZACIÓN ---
function save() {
    localStorage.setItem('carrito_kiosco', JSON.stringify(cart));
}

function updBadge() {
    let count = cart.reduce((acc, item) => acc + parseInt(item.cant), 0);
    document.getElementById('badgeCount').innerText = count;
}

function clearCart() {
    Swal.fire({
        title: '¿Vaciar carrito?',
        text: "Se eliminarán todos los productos seleccionados.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'SÍ, VACIAR',
        cancelButtonText: 'CANCELAR'
    }).then((result) => {
        if (result.isConfirmed) {
            cart = [];
            save();
            updBadge();
            // Refrescar la lista visual
            let list = document.getElementById('cartList');
            if(list) list.innerHTML = '<p class="text-center text-muted py-3">Tu carrito está vacío</p>';
            document.getElementById('cartTotal').innerText = '$0';
            
            const Toast = Swal.mixin({toast: true, position: 'top', showConfirmButton: false, timer: 1500, background: '#dc3545', color: '#fff'});
            Toast.fire({icon: 'success', title: 'Carrito vaciado'});
        }
    });
}


function openCart() {

            let html = '', tot=0;
            if(cart.length==0) html='<p class="text-center text-muted py-3">Tu carrito está vacío</p>';
            else {
                cart.forEach((i,x) => {
                    let s = i.precio*i.cant; tot+=s;
                    html += `<div class="cart-item">
                        <div style="flex:1"><div class="fw-bold small text-truncate" style="max-width: 180px;">${i.nombre}</div><div class="small text-muted">$${i.precio}</div></div>
                        <div class="d-flex gap-2 align-items-center">
                            <button class="btn btn-sm btn-light border rounded-circle" style="width:28px;height:28px;padding:0;" onclick="mod(${x},-1)">-</button>
                            <span class="fw-bold small" style="min-width:20px;text-align:center;">${i.cant}</span>
                            <button class="btn btn-sm btn-light border rounded-circle" style="width:28px;height:28px;padding:0;" onclick="mod(${x},1)">+</button>
                        </div>
                        <div class="fw-bold ms-3" style="min-width:60px;text-align:right;">$${s}</div>
                    </div>`;
                });
            }
            document.getElementById('cartList').innerHTML = html;
            document.getElementById('cartTotal').innerText = '$'+tot;
            modalCart.show();
        }

        function mod(x, v) {
    let item = cart[x];
    if(v > 0 && item.cant + v > item.stock_max) {
        const Toast = Swal.mixin({toast: true, position: 'top', showConfirmButton: false, timer: 2000, background: '#dc3545', color: '#fff'});
        Toast.fire({icon: 'error', title: 'Límite de stock alcanzado'});
        return;
    }
    item.cant += v;
    if(item.cant <= 0) cart.splice(x, 1);
    save(); updBadge(); openCart();
}

        
        // --- FUNCIÓN WHATSAPP MEJORADA ---
        function sendWA() {
            if(cart.length == 0) return Swal.fire('Carrito Vacío', 'Agrega productos antes de enviar.', 'warning');
            
            // VALIDAR NOMBRE OBLIGATORIO
            let cli_nom = document.getElementById('cli_nombre').value.trim();
            if(cli_nom === '') {
                Swal.fire({icon: 'error', title: 'Falta tu Nombre', text: 'Por favor, escribí tu nombre para saber quién sos.'});
                return;
            }

            let cli_tel = document.getElementById('cli_tel').value.trim();
            let cli_email = document.getElementById('cli_email').value.trim();
            let cli_dir = document.getElementById('cli_dir').value.trim();
            
            // CONSTRUIR MENSAJE ATRACTIVO
            let msg = `Hola *<?php echo $nombre_negocio; ?>*! 👋%0A`;
            msg += `Quiero realizar el siguiente pedido:%0A%0A`;
            
            let tot = 0;
            cart.forEach(i => { 
                let sub = i.precio * i.cant;
                tot += sub;
                // Emojis y negritas para cada ítem
                msg += `🔹 *${i.cant}x* ${i.nombre} ($${sub})%0A`; 
            });
            
            msg += `%0A💰 *TOTAL: $${tot}*%0A`;
            msg += `--------------------------------%0A`;
            msg += `👤 *Cliente:* ${cli_nom}%0A`;
            
            if(cli_tel) msg += `📱 *Tel:* ${cli_tel}%0A`;
            if(cli_email) msg += `📧 *Email:* ${cli_email}%0A`;
            if(cli_dir) msg += `📍 *Dirección:* ${cli_dir}%0A`;
            
            msg += `--------------------------------%0A`;
            msg += `Espero confirmación. ¡Gracias!`;

            // NÚMERO DINÁMICO DESDE BASE DE DATOS
            let telefonoDestino = "<?php echo $telefono_wa; ?>";
            
            window.open(`https://wa.me/${telefonoDestino}?text=${msg}`, '_blank');
        }

        document.addEventListener('DOMContentLoaded', () => { initCart(); });
    </script>
</body>
</html>