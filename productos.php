<?php
// productos.php - VERSIÓN PREMIUM VANGUARD PRO (TOTALMENTE INTEGRADA)
session_start();

// 1. CONEXIÓN PREVIA PARA PROCESAMIENTO
$rutas_db = [__DIR__ . '/db.php', __DIR__ . '/includes/db.php', 'db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = ($_SESSION['rol'] <= 2);

if (!$es_admin && !in_array('ver_productos', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

// --- AJAX: OBTENER DATOS DEL PRODUCTO PARA EL MODAL (Clon Devoluciones) ---
if (isset($_GET['ajax_get_producto'])) {
    header('Content-Type: application/json');
    $id_p = intval($_GET['ajax_get_producto']);
    $stmt = $conexion->prepare("SELECT p.*, c.nombre as cat_nom FROM productos p LEFT JOIN categorias c ON p.id_categoria = c.id WHERE p.id = ?");
    $stmt->execute([$id_p]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    
    // Búsqueda inteligente del Dueño para la firma
    $u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol 
                                 FROM usuarios u 
                                 JOIN roles r ON u.id_rol = r.id 
                                 WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
    $owner = $u_owner->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode(['producto' => $producto, 'conf' => $conf, 'owner' => $owner]);
    exit;
}

// 2. PROCESAR ACCIONES (Toggle y Borrar)
if(isset($_GET['toggle_id'])) {
    if (!$es_admin && !in_array('toggle_producto', $permisos)) die("Acceso denegado.");
    $id_tog = intval($_GET['toggle_id']);
    $st_act = intval($_GET['estado']);
    $nuevo = $st_act == 1 ? 0 : 1;
    $conexion->prepare("UPDATE productos SET activo = ? WHERE id = ?")->execute([$nuevo, $id_tog]);
    header("Location: productos.php"); exit;
}

if (isset($_GET['borrar'])) {
    if (!$es_admin && !in_array('eliminar_producto', $permisos)) die("Acceso denegado.");
    if (!isset($_GET['token']) || $_GET['token'] !== $_SESSION['csrf_token']) die("Error de seguridad.");
    $id_borrar = intval($_GET['borrar']);
    $stmtP = $conexion->prepare("SELECT tipo, codigo_barras FROM productos WHERE id = ?");
    $stmtP->execute([$id_borrar]);
    $p_obj = $stmtP->fetch(PDO::FETCH_ASSOC);
    if ($p_obj) {
        if ($p_obj['tipo'] === 'combo') { 
            $stmtC = $conexion->prepare("SELECT id FROM combos WHERE codigo_barras = ?");
            $stmtC->execute([$p_obj['codigo_barras']]);
            $id_c = $stmtC->fetchColumn();
            if($id_c) {
                $conexion->prepare("DELETE FROM combo_items WHERE id_combo = ?")->execute([$id_c]);
                $conexion->prepare("DELETE FROM combos WHERE id = ?")->execute([$id_c]);
            }
        }
        $conexion->prepare("DELETE FROM productos WHERE id = ?")->execute([$id_borrar]);
    }
    header("Location: productos.php?msg=borrado"); exit;
}

// --- BORRADO MASIVO AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitud_borrar_masivo'])) {
    if (!$es_admin && !in_array('eliminar_producto', $permisos)) { echo "Sin permiso"; exit; }
    $ids = json_decode($_POST['ids_a_borrar'], true);
    if (!empty($ids) && is_array($ids)) {
        foreach($ids as $id_borrar) {
            $id_borrar = intval($id_borrar);
            $stmtP = $conexion->prepare("SELECT tipo, codigo_barras FROM productos WHERE id = ?");
            $stmtP->execute([$id_borrar]);
            $p_obj = $stmtP->fetch(PDO::FETCH_ASSOC);
            if ($p_obj) {
                if ($p_obj['tipo'] === 'combo') { 
                    $stmtC = $conexion->prepare("SELECT id FROM combos WHERE codigo_barras = ?");
                    $stmtC->execute([$p_obj['codigo_barras']]);
                    $id_c = $stmtC->fetchColumn();
                    if($id_c) {
                        $conexion->prepare("DELETE FROM combo_items WHERE id_combo = ?")->execute([$id_c]);
                        $conexion->prepare("DELETE FROM combos WHERE id = ?")->execute([$id_c]);
                    }
                }
                $conexion->prepare("DELETE FROM productos WHERE id = ?")->execute([$id_borrar]);
            }
        }
        echo "EXITO";
    }
    exit;
}

// 3. CARGA DE DATOS
$conf_global = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$dias_venc = intval($conf_global['dias_alerta_vencimiento'] ?? 30);
$usar_global = (isset($conf_global['stock_use_global']) && $conf_global['stock_use_global'] == 1);
$stock_critico_global = intval($conf_global['stock_global_valor'] ?? 5);

// Detectamos en qué rubro está el sistema ahora mismo
$rubro_actual = $conf_global['tipo_negocio'] ?? 'kiosco';

// Solo traemos las categorías y productos de ESE rubro
$categorias = $conexion->query("SELECT * FROM categorias WHERE activo=1 AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)")->fetchAll(PDO::FETCH_ASSOC);
$proveedores_list = $conexion->query("SELECT id, empresa FROM proveedores ORDER BY empresa ASC")->fetchAll(PDO::FETCH_ASSOC);
$sql = "SELECT p.*, c.nombre as cat FROM productos p LEFT JOIN categorias c ON p.id_categoria=c.id WHERE p.tipo_negocio = '$rubro_actual' OR p.tipo_negocio IS NULL ORDER BY p.id DESC";
$productos = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$total_prod = count($productos);
$bajo_stock = 0; $valor_inventario = 0;
foreach($productos as $p) {
    $limite_alerta = ($usar_global) ? $stock_critico_global : floatval($p['stock_minimo']);
    if($p['activo'] == 1 && $p['tipo'] !== 'combo' && floatval($p['stock_actual']) <= $limite_alerta) $bajo_stock++;
    $valor_inventario += (floatval($p['stock_actual']) * floatval($p['precio_costo']));
}

$conf_color_sis = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf_color_sis['color_barra_nav'] ?? '#102A57';
require_once 'includes/layout_header.php';

// --- DEFINICIÓN DEL BANNER DINÁMICO ESTANDARIZADO ---
$titulo = "Catálogo de Productos";
$subtitulo = "Administración de stock y precios del sistema.";
$icono_bg = "bi-grid-3x3-gap-fill";

$botones = [];
if ($es_admin || in_array('stock_crear_producto', $permisos)) {
    $botones[] = ['texto' => 'CREAR', 'icono' => 'bi-plus-circle-fill', 'class' => 'btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm', 'link' => 'javascript:abrirModalCrear()'];
}
$botones[] = ['texto' => 'REPORTE PDF', 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-4 shadow-sm', 'link' => 'javascript:lanzarReporteFiltrado()'];

$widgets = [
    ['label' => 'Total Productos', 'valor' => $total_prod, 'icono' => 'bi-box', 'icon_bg' => 'bg-white bg-opacity-10', 'extra' => 'onclick="verTodos()" style="cursor:pointer;"'],
    ['label' => 'Stock Bajo', 'valor' => $bajo_stock, 'icono' => 'bi-exclamation-triangle', 'border' => 'border-warning', 'icon_bg' => 'bg-warning bg-opacity-20', 'extra' => 'onclick="filtrarStockBajo()" id="widget-stock-bajo" style="cursor:pointer;"'],
    ['label' => 'Valor Inventario', 'valor' => '$'.number_format($valor_inventario, 0, ',', '.'), 'icono' => 'bi-currency-dollar', 'border' => 'border-success', 'icon_bg' => 'bg-success bg-opacity-20']
];

include 'includes/componente_banner.php'; 
?>

<style>
    .row-bajo-stock .card-prod { background-color: #fff5f5 !important; border: 1px solid #ffcccc !important; box-shadow: 0 0 15px rgba(220, 53, 69, 0.1) !important; }
    
    /* Filtros compactos, centrados y minimalistas */
    .filter-bar.sticky-desktop { 
        position: sticky; 
        top: 65px; 
        z-index: 1000; 
        background: rgba(255, 255, 255, 0.95); 
        backdrop-filter: blur(5px); 
        margin-top: 10px; 
        margin-bottom: 20px; 
        box-shadow: 0 2px 8px rgba(0,0,0,0.08); 
        padding: 8px 12px !important; /* Margen parejo arriba y abajo */
        border: 1px solid #eee; 
        border-radius: 8px !important; 
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    .filter-select { height: 32px !important; font-size: 0.85rem !important; padding: 2px 10px !important; border-radius: 6px !important; border: 1px solid #ddd !important; outline: none; box-shadow: none; background-color: #f8f9fa; }
    
    /* Contenedor del buscador */
    .search-group { position: relative; display: flex; align-items: center; background: #f8f9fa; border: 1px solid #ddd; border-radius: 6px; height: 32px; width: 100%; overflow: hidden; }
    .search-input { border: none !important; box-shadow: none !important; height: 100% !important; background: transparent; font-size: 0.85rem; padding: 0 10px 0 32px !important; outline: none; width: 100%; }
    
    /* Lupa flotante que desaparece al escribir */
    .search-icon { position: absolute; left: 10px; font-size: 0.9rem !important; color: #888; pointer-events: none; transition: opacity 0.15s ease; }
    .search-input:not(:placeholder-shown) ~ .search-icon { opacity: 0; visibility: hidden; }
    
    @media (max-width: 768px) { 
        .filter-bar.sticky-desktop { top: 5px; margin-top: 15px; padding: 10px !important; } 
        .filter-content-wrapper { display: none; flex-direction: column; gap: 8px; padding-top: 8px; }
        .filter-content-wrapper.show { display: flex; }
        .search-group, .filter-select { width: 100% !important; }
    }
    
    @media (min-width: 769px) {
        .filter-content-wrapper { display: flex !important; width: 100%; gap: 10px; align-items: center; margin: 0 !important; }
        .btn-toggle-filters { display: none; }
    }

    /* --- DISEÑO COMPACTO PARA TARJETAS DE PRODUCTOS --- */
    .card-prod { min-height: 100% !important; display: flex; flex-direction: column; }
    .img-area { height: 160px !important; padding: 10px !important; } 
    .prod-img { max-height: 145px !important; object-fit: contain; }
    .card-body { padding: 10px 12px !important; flex-grow: 1; display: flex; flex-direction: column; gap: 2px; } 
    .cat-label { font-size: 0.65rem !important; margin-bottom: 0 !important; }
    .prod-title { font-size: 0.95rem !important; line-height: 1.1 !important; margin-bottom: 0 !important; } 
    .prod-code { font-size: 0.70rem !important; margin-bottom: 0 !important; }
    .price-normal { font-size: 1.25rem !important; margin-bottom: 0 !important; }
    .card-footer-actions { padding-top: 6px !important; margin-top: auto !important; }
    .btn-action { width: 32px !important; height: 32px !important; font-size: 0.85rem !important; } 
    .stock-progress { height: 6px !important; margin-top: 2px !important; }
</style>

<div class="container pb-5 mt-n4" style="position: relative; z-index: 20;">

        <div class="filter-bar sticky-desktop rounded-4 p-3">
        
        <div class="d-flex gap-2 w-100 mb-md-0 mb-2">
            <button class="btn btn-primary fw-bold btn-toggle-filters flex-fill m-0 shadow-sm d-md-none" type="button" onclick="toggleFiltrosMovil()">
                <i class="bi bi-funnel-fill"></i> MOSTRAR FILTROS
            </button>
            <?php if($es_admin || in_array('stock_eliminar_producto', $permisos)): ?>
            <button type="button" id="btnBorrarMasivo" class="btn btn-danger fw-bold shadow-sm flex-fill flex-md-grow-0 d-none mb-md-0 mb-2" onclick="borrarSeleccionados()">
                <i class="bi bi-trash3-fill me-1"></i> BORRAR (<span id="cuentaSeleccionados">0</span>)
            </button>
            <?php endif; ?>
        </div>

        <div id="wrapperFiltros" class="filter-content-wrapper mt-md-0 mt-2">
            <div class="search-group flex-fill">
                <input type="text" id="buscador" class="search-input w-100" placeholder="Buscar nombre, código...">
                <i class="bi bi-search search-icon"></i>
            </div>
            <select id="filtroCat" class="filter-select">
                <option value="todos">📦 Todas las Categorías</option>
                <?php foreach($categorias as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo $c['nombre']; ?></option><?php endforeach; ?>
            </select>
            <select id="filtroEstado" class="filter-select">
                <option value="todos">⚡ Ver Todo</option>
                <option value="activos">✅ Solo Activos</option>
                <option value="pausados">⏸️ Pausados</option>
                <option value="bajo_stock">⚠️ Stock Bajo</option>
                <option value="vencimientos">⏳ Vencimientos</option>
            </select>
            <select id="ordenarPor" class="filter-select">
                <option value="recientes">📅 Más Recientes</option>
                <option value="nombre_asc">🔤 A-Z Nombre</option>
                <option value="precio_alto">💲 Mayor Precio</option>
                <option value="precio_bajo">💲 Menor Precio</option>
            </select>
        </div>
    </div>



    <div class="alert py-2 small mb-4 text-center fw-bold border-0 shadow-sm rounded-3" style="background-color: #e9f2ff; color: #102A57;">
        <i class="bi bi-hand-index-thumb-fill me-1"></i> Toca o haz clic en un producto para ver la ficha técnica y opciones
    </div>

    <div class="row g-4" id="gridProductos">
        <?php foreach($productos as $p): 
            $stk = floatval($p['stock_actual']);
            $min_ind = floatval($p['stock_minimo']);
            $limite_visual = ($usar_global) ? $stock_critico_global : $min_ind;
            $es_bajo_stock = ($p['tipo'] !== 'combo' && $stk <= $limite_visual);

            $max_ref = $min_ind > 0 ? $min_ind * 4 : 50; 
            $pct = ($max_ref > 0) ? ($stk / $max_ref) * 100 : 0;
            $pct = $pct > 100 ? 100 : $pct;
            
            $colorBarra = $stk <= $min_ind ? '#dc3545' : ($stk <= $min_ind * 2 ? '#ffc107' : '#198754');
            if($p['tipo'] === 'combo') $colorBarra = '#0d6efd';

            $precioV = !empty($p['precio_oferta']) && $p['precio_oferta'] > 0 ? $p['precio_oferta'] : $p['precio_venta'];
            
            $es_vencimiento = false;
            if (!empty($p['fecha_vencimiento'])) {
                if (strtotime($p['fecha_vencimiento']) <= strtotime("+$dias_venc days")) {
                    $es_vencimiento = true;
                }
            }
            $estadoData = ($p['activo'] ? 'activos' : 'pausados') . ($es_bajo_stock ? ' bajo_stock' : '') . ($es_vencimiento ? ' vencimientos' : '');
            
            // Formateo visual de stock para pesables
            $txt_stock = $stk . " u.";
            if ($p['tipo'] === 'pesable') {
                $kilos = floor($stk);
                $gramos = round(($stk - $kilos) * 1000);
                if ($kilos > 0 && $gramos > 0) $txt_stock = $kilos . "kg " . $gramos . "gr";
                else if ($kilos > 0) $txt_stock = $kilos . "kg";
                else $txt_stock = $gramos . "gr";
            }
        ?>
        <div class="col-12 col-md-6 col-xl-3 item-grid <?php echo $es_bajo_stock ? 'row-bajo-stock' : ''; ?>"
             data-nombre="<?php echo strtolower($p['descripcion']); ?>" 
             data-codigo="<?php echo strtolower($p['codigo_barras']); ?>"
             data-cat="<?php echo $p['id_categoria']; ?>"
             data-estado="<?php echo $estadoData; ?>"
             data-precio="<?php echo $p['precio_venta']; ?>"
             data-id="<?php echo $p['id']; ?>">

            <div class="card-prod <?php echo $p['activo'] ? '' : 'opacity-50 grayscale'; ?>" onclick="verFichaProducto(<?php echo $p['id']; ?>)" style="cursor:pointer;">
                <div class="badge-top-left d-flex flex-column gap-1 align-items-start">
                    <?php if($es_admin || in_array('eliminar_producto', $permisos)): ?>
                    <input type="checkbox" class="form-check-input checkProd" value="<?php echo $p['id']; ?>" onclick="event.stopPropagation(); revisarChecks()" style="transform: scale(1.3); margin: 5px; cursor:pointer;">
                    <?php endif; ?>
                    <?php if($es_bajo_stock): ?><div class="badge bg-danger text-white shadow-sm" style="font-size:0.6rem;">BAJO STOCK</div><?php endif; ?>
                </div>

                <div class="img-area">
                    <?php if(!empty($p['imagen_url'])): ?>
                        <img src="<?php echo $p['imagen_url']; ?>" class="prod-img" id="img-<?php echo $p['id']; ?>">
                    <?php else: ?>
                        <i class="bi bi-camera text-muted fs-1 opacity-25"></i>
                    <?php endif; ?>
                </div>

                <div class="card-body">
                    <div class="cat-label"><?php echo strtoupper($p['cat'] ?? 'GENERAL'); ?></div>
                    <div class="prod-title text-truncate-2"><?php echo $p['descripcion']; ?></div>
                    <div class="prod-code"><?php echo $p['codigo_barras']; ?></div>
                    <div class="price-block"><div class="price-normal">$<?php echo number_format($precioV, 0, ',', '.'); ?></div></div>
                    
                    <div class="mt-auto">
                        <div class="text-end mb-1"><span style="font-size:0.85rem; font-weight:700; color:<?php echo $colorBarra; ?>;"><?php echo $txt_stock; ?></span></div>
                        <div class="stock-progress"><div class="progress-fill" style="width: <?php echo $pct; ?>%; background-color: <?php echo $colorBarra; ?>;"></div></div>

                        <div class="card-footer-actions mt-2 pt-2 border-top">
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" onclick="event.stopPropagation();" onchange="window.location.href='productos.php?toggle_id=<?php echo $p['id']; ?>&estado=<?php echo $p['activo']; ?>'" <?php echo $p['activo'] ? 'checked' : ''; ?>>
                            </div>
                            <div class="d-flex gap-1 ms-auto">
                                <button type="button" class="btn-action btn-wallet" onclick="event.stopPropagation(); reponerStock(<?php echo $p['id']; ?>, '<?php echo addslashes($p['descripcion']); ?>', <?php echo $stk; ?>, '<?php echo $p['tipo']; ?>')"><i class="bi bi-plus-circle-fill"></i></button>
                                <?php if($es_admin || in_array('stock_editar_producto', $permisos)): ?>
                                <a href="<?php echo $p['tipo'] === 'combo' ? 'combos.php?editar_codigo='.trim($p['codigo_barras']).'&origen=productos' : 'producto_formulario.php?id='.$p['id']; ?>" class="btn-action btn-edit" onclick="event.stopPropagation();"><i class="bi bi-pencil-fill"></i></a>
                                <?php endif; ?>
                                <?php if($es_admin || in_array('eliminar_producto', $permisos)): ?>
                                <button type="button" class="btn-action btn-danger" style="background:#dc3545; color:white; border-radius:50%; width:32px; height:32px; border:none; display:flex; align-items:center; justify-content:center;" onclick="event.stopPropagation(); borrarId(<?php echo $p['id']; ?>)"><i class="bi bi-trash-fill"></i></button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
                </div>
        <?php endforeach; ?>
    </div>

    <div id="listaCategorias" class="d-none mt-2">
        <?php 
        // Agrupamos los productos por categoría dinámicamente
        $prod_cat = [];
        foreach($productos as $p) {
            $nc = strtoupper($p['cat'] ?? 'GENERAL');
            $prod_cat[$nc][] = $p;
        }
        ksort($prod_cat); // Ordena alfabéticamente las categorías
        foreach($prod_cat as $nom_cat => $items_cat):
        ?>
        <div class="card shadow-sm mb-4 seccion-categoria">
            <div class="card-header bg-dark text-white fw-bold py-2">
                <i class="bi bi-tags-fill me-2"></i> <?= $nom_cat ?> <span class="badge bg-secondary ms-2"><?= count($items_cat) ?> prod.</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0" style="font-size: 13px;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width: 5%;">
                                <?php if($es_admin || in_array('eliminar_producto', $permisos)): ?>
                                <input type="checkbox" class="form-check-input" onclick="alternarTodosCat(this, '<?php echo md5($nom_cat); ?>')" style="transform: scale(1.2); cursor:pointer;">
                                <?php endif; ?>
                            </th>
                            <th class="d-none d-md-table-cell" style="width: 15%;">CÓDIGO</th>
                            <th style="width: 30%;">PRODUCTO</th>
                            <th class="text-end" style="width: 15%;">PRECIO</th>
                            <th class="text-center" style="width: 15%;">STOCK</th>
                            <th class="text-center d-none d-md-table-cell" style="width: 10%;">ESTADO</th>
                            <th class="text-center" style="width: 10%;">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($items_cat as $p): 
                            $stk_cat = floatval($p['stock_actual']);
                            $min_cat = floatval($p['stock_minimo']);
                            $limite_cat = ($usar_global) ? $stock_critico_global : $min_cat;
                            $es_bajo_stock_cat = ($p['tipo'] !== 'combo' && $stk_cat <= $limite_cat);
                            $es_vencimiento_cat = false;
                            if (!empty($p['fecha_vencimiento']) && strtotime($p['fecha_vencimiento']) <= strtotime("+$dias_venc days")) {
                                $es_vencimiento_cat = true;
                            }
                            $estD = ($p['activo'] ? 'activos' : 'pausados') . ($es_bajo_stock_cat ? ' bajo_stock' : '') . ($es_vencimiento_cat ? ' vencimientos' : '');
                            
                            $pv = !empty($p['precio_oferta']) && $p['precio_oferta']>0 ? $p['precio_oferta'] : $p['precio_venta'];
                            $stk_lista = floatval($p['stock_actual']);
                            $txt_stock_lista = $stk_lista . " u.";
                            if ($p['tipo'] === 'pesable') {
                                $kilos_l = floor($stk_lista);
                                $gramos_l = round(($stk_lista - $kilos_l) * 1000);
                                if ($kilos_l > 0 && $gramos_l > 0) $txt_stock_lista = $kilos_l . "kg " . $gramos_l . "gr";
                                else if ($kilos_l > 0) $txt_stock_lista = $kilos_l . "kg";
                                else $txt_stock_lista = $gramos_l . "gr";
                            }
                        ?>
                        <tr class="item-lista" data-nombre="<?= strtolower($p['descripcion']) ?>" data-codigo="<?= strtolower($p['codigo_barras']) ?>" data-cat="<?= $p['id_categoria'] ?>" data-estado="<?= $estD ?>" data-precio="<?= $pv ?>" data-id="<?= $p['id'] ?>">
                            <td class="ps-3">
                                <?php if($es_admin || in_array('eliminar_producto', $permisos)): ?>
                                <input type="checkbox" class="form-check-input checkProd checkCat-<?php echo md5($nom_cat); ?>" value="<?= $p['id'] ?>" onclick="event.stopPropagation(); revisarChecks()" style="transform: scale(1.2); cursor:pointer;">
                                <?php endif; ?>
                            </td>
                            <td class="text-muted d-none d-md-table-cell"><?= $p['codigo_barras'] ?></td>
                            <td class="fw-bold" style="cursor:pointer; color: #102A57;" onclick="verFichaProducto(<?= $p['id'] ?>)">
                                <?= $p['descripcion'] ?>
                                <div class="d-md-none text-muted fw-normal mt-1" style="font-size: 10px;">Cód: <?= $p['codigo_barras'] ?></div>
                            </td>
                            <td class="text-end fw-bold text-success">$<?= number_format($pv, 2, ',', '.') ?></td>
                            <td class="text-center fw-bold"><?= $txt_stock_lista ?></td>
                            <td class="text-center d-none d-md-table-cell">
                                <div class="form-check form-switch m-0 d-flex justify-content-center">
                                    <input class="form-check-input" type="checkbox" onchange="window.location.href='productos.php?toggle_id=<?= $p['id'] ?>&estado=<?= $p['activo'] ?>'" <?= $p['activo'] ? 'checked' : '' ?>>
                                </div>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">
                                    <button class="btn btn-sm btn-primary py-0 px-2" onclick="reponerStock(<?= $p['id'] ?>, '<?= addslashes($p['descripcion']) ?>', <?= floatval($p['stock_actual']) ?>, '<?= $p['tipo'] ?>')">+ Stock</button>
                                    <a href="<?= $p['tipo'] === 'combo' ? 'combos.php?editar_codigo='.trim($p['codigo_barras']).'&origen=productos' : 'producto_formulario.php?id='.$p['id'] ?>" class="btn btn-sm btn-outline-dark py-0 px-2"><i class="bi bi-pencil"></i></a>
                                    <?php if($es_admin || in_array('eliminar_producto', $permisos)): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="event.stopPropagation(); borrarId(<?= $p['id'] ?>)"><i class="bi bi-trash-fill"></i></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

</div>



<div class="modal fade" id="modalReponerStock" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-box-arrow-in-down"></i> Ingreso Rápido de Stock</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formStockRapido" onsubmit="enviarStockAjax(event)">
                    <input type="hidden" name="id_producto" id="rep_id">
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">Producto</label>
                        <input type="text" id="rep_nombre" class="form-control fw-bold bg-light" readonly>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="small fw-bold text-muted">Stock Actual</label>
                            <input type="text" id="rep_actual" class="form-control text-center bg-light" readonly>
                        </div>
                        <div class="col-6" id="div_rep_unitario">
                            <label class="small fw-bold text-success">Cantidad a Sumar</label>
                            <input type="number" step="0.01" name="cantidad_sumar" id="rep_sumar" class="form-control border-success text-center fw-bold">
                        </div>
                        <div class="col-6" id="div_rep_pesable" style="display:none;">
                            <label class="small fw-bold text-success">Kilos y Gramos</label>
                            <div class="input-group">
                                <input type="number" min="0" step="1" id="rep_kilos" class="form-control border-success text-center fw-bold" placeholder="Kg">
                                <input type="number" min="0" step="1" id="rep_gramos" class="form-control border-success text-center fw-bold" placeholder="Gr">
                            </div>
                        </div>
                        <input type="hidden" id="rep_tipo" value="unitario">
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">Proveedor (Opcional)</label>
                        <select name="id_proveedor" class="form-select">
                            <option value="">-- Sin cambios / No aplica --</option>
                            <?php foreach($proveedores_list as $prov): ?>
                                <option value="<?php echo $prov['id']; ?>"><?php echo htmlspecialchars($prov['empresa']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row mb-4">
                        <?php if ($es_admin || in_array('stock_cambiar_costo', $permisos)): ?>
                        <div class="col-6">
                            <label class="small fw-bold text-muted">Actualizar Costo $</label>
                            <input type="number" step="0.01" name="nuevo_costo" class="form-control text-center" placeholder="Opcional">
                        </div>
                        <?php else: ?>
                        <div class="col-6 d-none">
                            <input type="hidden" name="nuevo_costo" value="">
                        </div>
                        <?php endif; ?>

                        <?php if ($es_admin || in_array('stock_cambiar_precio', $permisos)): ?>
                        <div class="col-6">
                            <label class="small fw-bold text-muted">Actualizar P. Venta $</label>
                            <input type="number" step="0.01" name="nuevo_precio" class="form-control text-center" placeholder="Opcional">
                        </div>
                        <?php else: ?>
                        <div class="col-6 d-none">
                            <input type="hidden" name="nuevo_precio" value="">
                        </div>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-success w-100 fw-bold py-2 shadow-sm"><i class="bi bi-check-lg"></i> GUARDAR INGRESO</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function enviarStockAjax(e) {
    e.preventDefault();
    let fd = new FormData(e.target);
    let datos = new FormData();
    
    let tipo = document.getElementById('rep_tipo').value;
    let cantidadFinal = 0;
    
    if (tipo === 'pesable') {
        let kg = parseFloat(document.getElementById('rep_kilos').value) || 0;
        let gr = parseFloat(document.getElementById('rep_gramos').value) || 0;
        cantidadFinal = kg + (gr / 1000);
        if (cantidadFinal <= 0) {
            Swal.fire('Atención', 'Ingresa una cantidad mayor a 0', 'warning');
            return;
        }
    } else {
        cantidadFinal = parseFloat(fd.get('cantidad_sumar')) || 0;
        if (cantidadFinal <= 0) {
            Swal.fire('Atención', 'Ingresa una cantidad válida', 'warning');
            return;
        }
    }

    datos.append('id', fd.get('id_producto'));
    datos.append('cantidad', cantidadFinal);
    datos.append('id_proveedor', fd.get('id_proveedor'));
    datos.append('nuevo_costo', fd.get('nuevo_costo'));
    datos.append('nuevo_precio', fd.get('nuevo_precio'));
    
    Swal.fire({ title: 'Guardando...', didOpen: () => { Swal.showLoading(); } });
    fetch('ajax_stock_reposicion.php', { method: 'POST', body: datos })
    .then(res => res.json())
    .then(d => {
        if(d.status === 'success') {
            Swal.fire('Éxito', d.msg, 'success').then(() => location.reload());
        } else {
            Swal.fire('Error', d.msg, 'error');
        }
    }).catch(() => Swal.fire('Error', 'Hubo un problema de conexión.', 'error'));
}

function reponerStock(id, nombre, actual, tipo) {
    document.getElementById('rep_id').value = id;
    document.getElementById('rep_nombre').value = nombre;
    
    // Formatear visualmente el stock actual en el input
    let txtActual = actual + " u.";
    if (tipo === 'pesable') {
        let kilos = Math.floor(actual);
        let gramos = Math.round((actual - kilos) * 1000);
        if (kilos > 0 && gramos > 0) txtActual = kilos + "kg " + gramos + "gr";
        else if (kilos > 0) txtActual = kilos + "kg";
        else txtActual = gramos + "gr";
    }
    document.getElementById('rep_actual').value = txtActual;
    
    document.getElementById('rep_tipo').value = tipo;

    // Resetear inputs
    document.getElementById('rep_sumar').value = '';
    document.getElementById('rep_kilos').value = '';
    document.getElementById('rep_gramos').value = '';

    // Alternar visibilidad según tipo
    if (tipo === 'pesable') {
        document.getElementById('div_rep_unitario').style.display = 'none';
        document.getElementById('rep_sumar').removeAttribute('required');
        document.getElementById('div_rep_pesable').style.display = 'block';
    } else {
        document.getElementById('div_rep_pesable').style.display = 'none';
        document.getElementById('div_rep_unitario').style.display = 'block';
        document.getElementById('rep_sumar').setAttribute('required', 'required');
    }

    var modalStock = new bootstrap.Modal(document.getElementById('modalReponerStock'));
    modalStock.show();
    
    setTimeout(() => {
        if (tipo === 'pesable') document.getElementById('rep_kilos').focus();
        else document.getElementById('rep_sumar').focus();
    }, 500);
}

<?php
// Carga de firmas para el modal JS (Idéntico a Devoluciones)

$firmas_base64 = [];
$res_f = $conexion->query("SELECT id FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
foreach($res_f as $u) {
    $path = "img/firmas/usuario_{$u['id']}.png";
    if(file_exists($path)) $firmas_base64[$u['id']] = 'data:image/png;base64,' . base64_encode(file_get_contents($path));
}
?>
const firmasB64 = <?php echo json_encode($firmas_base64); ?>;

function verFichaProducto(id) {
    Swal.fire({ title: 'Cargando ficha...', didOpen: () => { Swal.showLoading(); } });
    fetch(`productos.php?ajax_get_producto=${id}`).then(r => r.json()).then(data => {
        const p = data.producto; const c = data.conf; const owner = data.owner;
        
        // Lógica de Stock: Sin decimales para unidades, 3 para peso
        let stockF = (p.stock_actual % 1 === 0) ? parseInt(p.stock_actual) + " u." : parseFloat(p.stock_actual).toFixed(3) + " u.";
        if (p.tipo === 'pesable') {
            let stkFloat = parseFloat(p.stock_actual);
            let kilos = Math.floor(stkFloat);
            let gramos = Math.round((stkFloat - kilos) * 1000);
            if (kilos > 0 && gramos > 0) stockF = kilos + "kg " + gramos + "gr";
            else if (kilos > 0) stockF = kilos + "kg";
            else stockF = gramos + "gr";
        }
        let linkPdf = window.location.origin + "/ticket_producto_pdf.php?id=" + p.id;
        let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdf);
        
        // Firma del Dueño (Rompe el caché con ?v=timestamp)
        let aclaracion = owner ? (owner.nombre_completo + " | " + owner.nombre_rol).toUpperCase() : 'GERENCIA AUTORIZADA';
        let rutaFirma = owner ? `img/firmas/usuario_${owner.id}.png?v=${Date.now()}` : `img/firmas/firma_admin.png?v=${Date.now()}`;
        let firmaHtml = `<img src="${rutaFirma}" style="max-height: 80px; margin-bottom: -25px;" onerror="this.style.display='none'"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${aclaracion}</small>`;

        const html = `
            <div id="printTicket" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
                <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                    ${c.logo_url ? `<img src="${c.logo_url}?v=${Date.now()}" style="max-height: 50px; margin-bottom: 10px;">` : ''}
                    <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${c.nombre_negocio}</h4>
                    <small style="color: #666;">${c.direccion_local}</small>
                </div>
                <div style="text-align: center; margin-bottom: 15px;">
                    <h5 style="font-weight: 900; color: #102A57; letter-spacing: 1px; margin:0;">FICHA TÉCNICA PRODUCTO</h5>
                    <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">ID #${p.id}</span>
                </div>
                <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                    <div style="margin-bottom: 4px;"><strong>ARTÍCULO:</strong> ${p.descripcion.toUpperCase()}</div>
                    <div style="margin-bottom: 4px;"><strong>CÓDIGO:</strong> ${p.codigo_barras}</div>
                    <div><strong>CATEGORÍA:</strong> ${p.cat_nom || 'GENERAL'}</div>
                </div>
                <div style="background: #102A5710; border-left: 4px solid #102A57; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                    <span style="font-size: 1.1em; font-weight:800;">STOCK ACTUAL:</span>
                    <span style="font-size: 1.15em; font-weight:900; color: #102A57;">${stockF}</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
                    <div style="width: 45%; text-align: center;">${firmaHtml}</div>
                    <div style="width: 45%; text-align: center;">
                        <a href="${linkPdf}" target="_blank"><img src="${qrUrl}" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;"></a>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3 no-print">
                <button class="btn btn-sm btn-outline-dark fw-bold" onclick="window.open('${linkPdf}', '_blank')">PDF</button>
                <button class="btn btn-sm btn-success fw-bold" onclick="window.open('https://wa.me/?text=Ficha de ${p.descripcion}: ${linkPdf}', '_blank')">WA</button>
                <button class="btn btn-sm btn-primary fw-bold" onclick="mandarMailProducto(${p.id})">EMAIL</button>
            </div>`;
        Swal.fire({ html: html, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });
    });
}

function mandarMailProducto(id) {
    Swal.fire({ title: 'Enviar Ficha', text: 'Email del destinatario:', input: 'email', showCancelButton: true, confirmButtonText: 'ENVIAR', confirmButtonColor: '#102A57' }).then((r) => {
        if(r.isConfirmed && r.value) {
            Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            let f = new FormData(); f.append('id', id); f.append('email', r.value);
            fetch('acciones/enviar_email_producto.php', { method: 'POST', body: f }).then(res => res.json()).then(d => {
                Swal.fire(d.status === 'success' ? 'Enviado' : 'Error', d.msg || '', d.status);
            });
        }
    });
}

function lanzarReporteFiltrado() {
    let buscar = document.getElementById('buscador').value;
    let cat = document.getElementById('filtroCat').value;
    let est = document.getElementById('filtroEstado').value;
    window.open(`reporte_productos.php?buscar=${buscar}&id_categoria=${cat}&filtro=${est}`, '_blank');
} 

function abrirModalCrear() {
    Swal.fire({
        title: '¿Qué desea crear?', showConfirmButton: false, showCloseButton: true,
        html: `<div class="p-3">
            <a href="producto_formulario.php" class="btn btn-outline-primary w-100 fw-bold py-3 mb-3 rounded-pill shadow-sm"><i class="bi bi-box-seam me-2"></i> PRODUCTO UNITARIO</a>
            <a href="combos.php" class="btn btn-outline-info w-100 fw-bold py-3 rounded-pill shadow-sm"><i class="bi bi-stars me-2"></i> COMBO / PACK PROMO</a>
        </div>`
    });
}

function generarReporteCatalogo() {
    Swal.fire({
        title: 'Reporte de Catálogo', text: '¿Cómo desea recibir el inventario?', icon: 'info', showConfirmButton: false, showCloseButton: true,
        footer: `<div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-dark fw-bold" onclick="window.open('ticket_catalogo_pdf.php', '_blank')">PDF</button>
            <button class="btn btn-sm btn-primary fw-bold" onclick="enviarMailCatalogo()">EMAIL</button>
        </div>`
    });
}

function enviarMailCatalogo() {
    Swal.fire({ title: 'Enviar Catálogo', input: 'email', showCancelButton: true, confirmButtonText: 'ENVIAR' }).then((r) => {
        if(r.isConfirmed && r.value) {
            Swal.fire({ title: 'Enviando...', didOpen: () => { Swal.showLoading(); }});
            $.post('acciones/enviar_email_catalogo.php', { email: r.value }, function(res) {
                Swal.fire(res.status === 'success' ? '¡Enviado!' : 'Error', res.msg, res.status);
            }, 'json');
        }
    });
}

function cambiarDiseno(esLista) {
    if (esLista) {
        document.getElementById('gridProductos').classList.add('d-none');
        document.getElementById('listaCategorias').classList.remove('d-none');
    } else {
        document.getElementById('gridProductos').classList.remove('d-none');
        document.getElementById('listaCategorias').classList.add('d-none');
    }
}

function aplicarFiltros() {
    let txt = document.getElementById('buscador').value.toLowerCase();
    let cat = document.getElementById('filtroCat').value;
    let est = document.getElementById('filtroEstado').value;
    let ord = document.getElementById('ordenarPor').value;
    
    // 1. ORDENAR Y FILTRAR TARJETAS (GRID)
    let grid = document.getElementById('gridProductos');
    if (grid) {
        let itemsGrid = Array.from(grid.querySelectorAll('.item-grid'));
        
        // Primero ordenamos en memoria
        itemsGrid.sort((a, b) => {
            if (ord === 'nombre_asc') return a.dataset.nombre.localeCompare(b.dataset.nombre);
            if (ord === 'precio_alto') return parseFloat(b.dataset.precio) - parseFloat(a.dataset.precio);
            if (ord === 'precio_bajo') return parseFloat(a.dataset.precio) - parseFloat(b.dataset.precio);
            return parseInt(b.dataset.id) - parseInt(a.dataset.id); // recientes
        });
        
        // Luego las pegamos de nuevo y aplicamos visibilidad
        itemsGrid.forEach(item => {
            grid.appendChild(item);
            let cumpleTxt = (item.dataset.nombre.includes(txt) || item.dataset.codigo.includes(txt));
            let cumpleCat = (cat === 'todos' || item.dataset.cat === cat);
            let cumpleEst = (est === 'todos' || item.dataset.estado.includes(est));
            if(cumpleTxt && cumpleCat && cumpleEst) { item.classList.remove('d-none'); } else { item.classList.add('d-none'); }
        });
    }

    // 2. ORDENAR Y FILTRAR LISTAS (TABLAS)
    document.querySelectorAll('.seccion-categoria').forEach(sec => {
        let tbody = sec.querySelector('tbody');
        if (tbody) {
            let itemsLista = Array.from(tbody.querySelectorAll('.item-lista'));
            
            itemsLista.sort((a, b) => {
                if (ord === 'nombre_asc') return a.dataset.nombre.localeCompare(b.dataset.nombre);
                if (ord === 'precio_alto') return parseFloat(b.dataset.precio) - parseFloat(a.dataset.precio);
                if (ord === 'precio_bajo') return parseFloat(a.dataset.precio) - parseFloat(b.dataset.precio);
                return parseInt(b.dataset.id) - parseInt(a.dataset.id);
            });
            
            let filasVisibles = 0;
            itemsLista.forEach(item => {
                tbody.appendChild(item);
                let cumpleTxt = (item.dataset.nombre.includes(txt) || item.dataset.codigo.includes(txt));
                let cumpleCat = (cat === 'todos' || item.dataset.cat === cat);
                let cumpleEst = (est === 'todos' || item.dataset.estado.includes(est));
                if(cumpleTxt && cumpleCat && cumpleEst) { 
                    item.classList.remove('d-none'); 
                    filasVisibles++;
                } else { 
                    item.classList.add('d-none'); 
                }
            });
            
            if (filasVisibles === 0) { sec.classList.add('d-none'); } else { sec.classList.remove('d-none'); }
        }
    });
}

document.getElementById('buscador').addEventListener('keyup', aplicarFiltros);
document.getElementById('filtroCat').addEventListener('change', aplicarFiltros);
document.getElementById('filtroEstado').addEventListener('change', aplicarFiltros);
document.getElementById('ordenarPor').addEventListener('change', aplicarFiltros);

// Auto-seleccionar filtro si viene desde el Dashboard
document.addEventListener("DOMContentLoaded", function() {
    const urlParams = new URLSearchParams(window.location.search);
    const filtroUrl = urlParams.get('filtro');
    if (filtroUrl) {
        const selectEstado = document.getElementById('filtroEstado');
        if (selectEstado.querySelector('option[value="'+filtroUrl+'"]')) {
            selectEstado.value = filtroUrl;
            aplicarFiltros();
        }
    }
});

function revisarChecks() {
    let marcados = document.querySelectorAll('.checkProd:checked').length;
    let btn = document.getElementById('btnBorrarMasivo');
    let span = document.getElementById('cuentaSeleccionados');
    if(span) span.innerText = marcados;
    if(btn) {
        if(marcados > 0) btn.classList.remove('d-none');
        else btn.classList.add('d-none');
    }
}

function borrarId(id) { procesarBorrado([id]); }

function borrarSeleccionados() {
    let seleccionados = [];
    document.querySelectorAll('.checkProd:checked').forEach(c => seleccionados.push(c.value));
    if(seleccionados.length > 0) procesarBorrado(seleccionados);
}

function procesarBorrado(listaIds) {
    Swal.fire({
        title: '¿Confirmar borrado?',
        text: "Vas a eliminar " + listaIds.length + " producto(s). Esto borrará también combos asociados.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sí, Eliminar'
    }).then((result) => {
        if (result.isConfirmed) {
            let formData = new FormData();
            formData.append('solicitud_borrar_masivo', '1');
            formData.append('ids_a_borrar', JSON.stringify(listaIds));
            fetch(window.location.pathname, { method: 'POST', body: formData })
            .then(res => res.text())
            .then(texto => {
                if(texto.includes("EXITO")) { Swal.fire('Eliminado', 'Se borraron los registros', 'success').then(()=>location.reload()); }
                else { Swal.fire('Error', texto, 'error'); }
            });
        }
    });
}

function toggleFiltrosMovil() {
    const wrapper = document.getElementById('wrapperFiltros');
    const btn = document.querySelector('.btn-toggle-filters');
    wrapper.classList.toggle('show');
    if (wrapper.classList.contains('show')) {
        btn.innerHTML = '<i class="bi bi-chevron-up"></i> OCULTAR FILTROS';
        btn.classList.replace('btn-primary', 'btn-outline-primary');
    } else {
        btn.innerHTML = '<i class="bi bi-funnel-fill"></i> MOSTRAR FILTROS';
        btn.classList.replace('btn-outline-primary', 'btn-primary');
    }
}
</script>
<?php include 'includes/layout_footer.php'; ?>