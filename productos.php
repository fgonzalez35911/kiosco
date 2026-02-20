<?php
// productos.php - VERSIÓN PREMIUM UNIFICADA
session_start();

// 1. CONEXIÓN PREVIA PARA PROCESAMIENTO (Evita pantalla en blanco al redireccionar)
$rutas_db = [__DIR__ . '/db.php', __DIR__ . '/includes/db.php', 'db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

// 2. PROCESAR ACCIONES ANTES DE CUALQUIER SALIDA HTML
if(isset($_GET['toggle_id'])) {
    $id_tog = intval($_GET['toggle_id']);
    $st_act = intval($_GET['estado']);
    $nuevo = $st_act == 1 ? 0 : 1;

    // AUDITORÍA: Obtenemos datos del producto (Respetando FETCH_OBJ)
    $stmtP = $conexion->prepare("SELECT descripcion FROM productos WHERE id = ?");
    $stmtP->execute([$id_tog]);
    $p_obj = $stmtP->fetch();
    $nombre_p = $p_obj ? $p_obj->descripcion : 'Desconocido';

    $conexion->prepare("UPDATE productos SET activo = ? WHERE id = ?")->execute([$nuevo, $id_tog]);

    // REGISTRO EN CAJA NEGRA
    $estado_txt = ($nuevo == 1) ? 'ACTIVADO' : 'DESACTIVADO';
    $detalles = "Producto/Combo: $nombre_p -> Cambio de Estado a: $estado_txt";
    $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'PRODUCTO_ESTADO', ?, NOW())")
             ->execute([$_SESSION['usuario_id'], $detalles]);

    header("Location: productos.php"); 
    exit;
}

if (isset($_GET['borrar'])) {
    if (!isset($_GET['token']) || $_GET['token'] !== $_SESSION['csrf_token']) die("Error de seguridad.");
    $id_borrar = intval($_GET['borrar']);

    // AUDITORÍA: Datos antes de la eliminación
    $stmtP = $conexion->prepare("SELECT descripcion, codigo_barras, tipo FROM productos WHERE id = ?");
    $stmtP->execute([$id_borrar]);
    $p_obj = $stmtP->fetch();

    if ($p_obj) {
        if ($p_obj->tipo === 'combo') { 
            $stmtC = $conexion->prepare("SELECT id FROM combos WHERE codigo_barras = ?");
            $stmtC->execute([$p_obj->codigo_barras]);
            $id_c = $stmtC->fetchColumn();
            if($id_c) {
                $conexion->prepare("DELETE FROM combo_items WHERE id_combo = ?")->execute([$id_c]);
                $conexion->prepare("DELETE FROM combos WHERE id = ?")->execute([$id_c]);
            }
        }
        $conexion->prepare("DELETE FROM productos WHERE id = ?")->execute([$id_borrar]);

        // REGISTRO EN CAJA NEGRA
        $detalles_b = "Producto/Combo Eliminado: " . $p_obj->descripcion . " (Cód: " . $p_obj->codigo_barras . ")";
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'PRODUCTO_ELIMINADO', ?, NOW())")
                 ->execute([$_SESSION['usuario_id'], $detalles_b]);
    }

    header("Location: productos.php?msg=borrado"); 
    exit;
}

// 3. CARGA DE CABECERA Y CONFIGURACIÓN DE VISTA
require_once 'includes/layout_header.php'; 

$conf_global = $conexion->query("SELECT stock_use_global, stock_global_valor, dias_alerta_vencimiento FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$dias_venc = intval($conf_global['dias_alerta_vencimiento'] ?? 30);
$usar_global = (isset($conf_global['stock_use_global']) && $conf_global['stock_use_global'] == 1);
$stock_critico_global = intval($conf_global['stock_global_valor'] ?? 5);

$categorias = $conexion->query("SELECT * FROM categorias WHERE activo=1")->fetchAll();
$proveedores_list = $conexion->query("SELECT id, empresa FROM proveedores ORDER BY empresa ASC")->fetchAll();
$sql = "SELECT p.*, c.nombre as cat, cb.fecha_inicio, cb.fecha_fin, cb.es_ilimitado FROM productos p LEFT JOIN categorias c ON p.id_categoria=c.id LEFT JOIN combos cb ON p.codigo_barras = cb.codigo_barras ORDER BY p.id DESC";
$productos = $conexion->query($sql)->fetchAll();

// OBTENER COLOR SEGURO (ESTÁNDAR PREMIUM)
$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_principal FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_principal'])) $color_sistema = $dataC['color_principal'];
    }
} catch (Exception $e) { }

$total_prod = count($productos);

$bajo_stock = 0; $valor_inventario = 0;
foreach($productos as $p) {
    $stk = is_object($p) ? $p->stock_actual : $p['stock_actual'];
    $min = is_object($p) ? $p->stock_minimo : $p['stock_minimo'];
    $cost = is_object($p) ? $p->precio_costo : $p['precio_costo'];
    $tipo = is_object($p) ? $p->tipo : $p['tipo'];
    $limite_para_alerta = $usar_global ? $stock_critico_global : $min;
    if($stk <= $limite_para_alerta && $tipo !== 'combo') $bajo_stock++;
    $valor_inventario += ($stk * $cost);
}
?>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>



<div class="header-blue">
    <i class="bi bi-grid-3x3-gap-fill bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h2 class="font-cancha mb-0 text-white">Catálogo de Productos</h2>
                <p class="opacity-75 mb-0 text-white small">Administración de stock y precios del sistema</p>
            </div>
            <div class="d-flex gap-2">
                <a href="combos.php" class="btn btn-warning fw-bold rounded-pill px-4 shadow-sm">
                    <i class="bi bi-box-seam-fill me-2"></i> Combos
                </a>
                <a href="producto_formulario.php" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm">
                    <i class="bi bi-plus-lg me-2"></i> Nuevo Producto
                </a>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="header-widget" onclick="verTodos()" style="cursor: pointer;">
                    <div>
                        <div class="widget-label">Total Productos</div>
                        <div class="widget-value text-white"><?php echo $total_prod; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white">
                        <i class="bi bi-box"></i>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="header-widget" onclick="filtrarStockBajo()" style="cursor: pointer;" id="widget-stock-bajo">
                    <div>
                        <div class="widget-label">Stock Bajo</div>
                        <div class="widget-value <?php echo ($bajo_stock > 0) ? 'text-warning' : 'text-white'; ?>">
                            <?php echo $bajo_stock; ?>
                        </div>
                    </div>
                    <div class="icon-box bg-warning bg-opacity-20 text-white">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Valor Stock (Costo)</div>
                        <div class="widget-value text-white">$<?php echo number_format($valor_inventario, 0, ',', '.'); ?></div>
                    </div>
                    <div class="icon-box bg-success bg-opacity-20 text-white">
                        <i class="bi bi-currency-dollar"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
   

    <div class="filter-bar sticky-desktop" style="border-top-left-radius: 0; border-top-right-radius: 0; border-top: 1px solid #eee;">
        <div class="search-group">
            <i class="bi bi-search search-icon"></i>
            <input type="text" id="buscador" class="search-input" placeholder="Buscar nombre, código...">
        </div>

        <select id="filtroCat" class="filter-select">
            <option value="todos">📦 Todas las Categorías</option>
            <?php foreach($categorias as $c): ?>
                <option value="<?php echo $c->id; ?>"><?php echo $c->nombre; ?></option>
            <?php endforeach; ?>
        </select>

        <select id="filtroEstado" class="filter-select">
            <option value="todos">⚡ Ver Todo</option>
            <option value="activos">✅ Solo Activos</option>
            <option value="pausados">⏸️ Pausados / Inactivos</option>
            <option value="bajo_stock">⚠️ Stock Bajo</option>
            <option value="vencimientos">📅 Por Vencer</option>
        </select>

        <select id="ordenarPor" class="filter-select">
            <option value="recientes">📅 Recientes</option>
            <option value="nombre_asc">A-Z Nombre</option>
            <option value="precio_alto">💲 Mayor Precio</option>
            <option value="precio_bajo">💲 Menor Precio</option>
        </select>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3 px-2">
        <small class="text-muted fw-bold"><span id="contadorVisible"><?php echo count($productos); ?></span> productos encontrados</small>
    </div>

    <div class="row g-4" id="gridProductos">
        <?php foreach($productos as $p): 
            $img = !empty($p->imagen_url) ? $p->imagen_url : '';
            // Cálculos
            $stock = floatval($p->stock_actual);
            $min = floatval($p->stock_minimo);
            $max_ref = $min > 0 ? $min * 4 : 50; 
            $pct = ($max_ref > 0) ? ($stock / $max_ref) * 100 : 0;
            if($pct > 100) $pct = 100;
            
            // Color Barra
            $colorBarra = '#198754'; // Verde
            if($stock <= $min * 2) $colorBarra = '#ffc107'; // Amarillo
            if($stock <= $min) $colorBarra = '#dc3545'; // Rojo
            if($p->tipo === 'combo') $colorBarra = '#0d6efd'; // Azul Combo

            // Costos y Ganancia
            $precioVenta = !empty($p->precio_oferta) && $p->precio_oferta > 0 ? $p->precio_oferta : $p->precio_venta;
            $costo = floatval($p->precio_costo);
            // Lógica simple para costo combo si es 0 (suma simple no incluida para no sobrecargar, se puede agregar)
            $ganancia = $precioVenta - $costo;

            // Filtros Data
            $claseCard = $p->activo ? '' : 'opacity-50 grayscale';
            $estadoData = $p->activo ? 'activos' : 'pausados';
            if($stock <= $min && $p->tipo !== 'combo') $estadoData .= ' bajo_stock';

            // AGREGADO: Lógica exacta del Dashboard para vencimientos
            if(!empty($p->fecha_vencimiento)) {
                $f_venc = strtotime($p->fecha_vencimiento);
                $f_hoy = strtotime(date('Y-m-d'));
                $f_limite = strtotime("+$dias_venc days", $f_hoy);
                
                // Si la fecha es Hoy o Futura Y está dentro del rango de alerta
                if($f_venc >= $f_hoy && $f_venc <= $f_limite) {
                    $estadoData .= ' vencimientos';
                }
            }
        ?>
        <?php 
            // Detectamos si es bajo stock para el filtro del banner
            $es_bajo_stock = ($stock <= $min && $p->tipo !== 'combo');
        ?>
        <div class="col-12 col-md-6 col-xl-3 item-grid <?php echo $es_bajo_stock ? 'row-bajo-stock' : ''; ?>"
             data-nombre="<?php echo strtolower($p->descripcion); ?>" 
             data-codigo="<?php echo strtolower($p->codigo_barras); ?>"
             data-cat="<?php echo $p->id_categoria; ?>"
             data-estado="<?php echo $estadoData; ?>"
             data-precio="<?php echo $p->precio_venta; ?>"
             data-id="<?php echo $p->id; ?>">

            <div class="card-prod <?php echo $claseCard; ?>">
                
                <div class="badge-top-left">
                    <?php if(!empty($p->precio_oferta) && $p->precio_oferta > 0): ?>
                        <div class="badge-offer"><i class="bi bi-fire"></i> OFERTA</div>
                    <?php endif; ?>
                    <?php if($stock <= $min && $p->tipo !== 'combo'): ?>
                        <div class="badge bg-warning text-dark mt-1 shadow-sm" style="font-size:0.7rem; font-weight:700;"><i class="bi bi-exclamation-triangle-fill"></i> AGOTÁNDOSE</div>
                    <?php endif; ?>
                </div>

                <div class="img-area" onclick="abrirCamara(<?php echo $p->id; ?>)">
                    <?php if($img): ?>
                        <img src="<?php echo $img; ?>" class="prod-img" id="img-<?php echo $p->id; ?>">
                    <?php else: ?>
                        <i class="bi bi-camera text-muted fs-1 opacity-25"></i>
                    <?php endif; ?>
                </div>

                <div class="card-body">
                    <div class="cat-label"><?php echo $p->cat ?? 'SIN CATEGORÍA'; ?></div>
                    <div class="prod-title text-truncate-2" title="<?php echo $p->descripcion; ?>">
                        <?php echo $p->descripcion; ?>
                    </div>
                    <?php if($p->tipo === 'combo'): ?>
                        <div class="prod-code text-primary fw-bold">COMBO-<?php echo $p->codigo_barras; ?></div>
                    <?php else: ?>
                        <div class="prod-code"><?php echo $p->codigo_barras; ?></div>
                    <?php endif; ?>

                    <div class="price-block">
                        <?php if(!empty($p->precio_oferta) && $p->precio_oferta > 0): ?>
                            <div class="price-old">$<?php echo number_format($p->precio_venta, 0, ',', '.'); ?></div>
                            <div class="price-main">$<?php echo number_format($p->precio_oferta, 0, ',', '.'); ?></div>
                        <?php else: ?>
                            <div class="price-normal">$<?php echo number_format($p->precio_venta, 0, ',', '.'); ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mt-auto">
                        <div class="text-end mb-1">
                             <span style="font-size:0.85rem; font-weight:700; color:<?php echo $colorBarra; ?>;">
                                 <?php echo $stock; ?> u.
                             </span>
                         </div>

                        <div class="financial-box">
                            <div>
                                <span class="cost-label">Costo</span>
                                <span class="cost-val">$<?php echo number_format($costo, 0, ',', '.'); ?></span>
                            </div>
                            <div>
                                <span class="gain-label">Ganancia</span>
                                <span class="gain-val">$<?php echo number_format($ganancia, 0, ',', '.'); ?></span>
                            </div>
                        </div>

                        <div class="stock-progress">
                            <div class="progress-fill" style="width: <?php echo $pct; ?>%; background-color: <?php echo $colorBarra; ?>;"></div>
                        </div>

                        <div class="card-footer-actions">
                            <div class="form-check form-switch m-0" title="Activar / Desactivar">
                                <input class="form-check-input" type="checkbox" 
                                    onchange="window.location.href='productos.php?toggle_id=<?php echo $p->id; ?>&estado=<?php echo $p->activo; ?>'" 
                                    <?php echo $p->activo ? 'checked' : ''; ?>>
                            </div>
                            
                            <div class="d-flex gap-2 ms-auto">
                                <button type="button" class="btn-action btn-wallet" title="Reponer Stock" onclick="reponerStock(<?php echo $p->id; ?>, '<?php echo addslashes($p->descripcion); ?>', <?php echo $stock; ?>)">
                                    <i class="bi bi-plus-circle-fill"></i>
                                </button>
                                <a href="producto_formulario.php?id=<?php echo $p->id; ?>" class="btn-action btn-edit" title="Editar">
                                    <i class="bi bi-pencil-fill"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div id="noResults" class="text-center py-5 d-none">
        <h5 class="text-muted">No se encontraron productos</h5>
    </div>
</div>

<input type="file" id="inputImageRapido" accept="image/*" hidden>
<div class="modal fade" id="modalCropRapido" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-body p-0"><div style="max-height:500px;"><img id="imageToCropRapido" style="max-width:100%;"></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-primary" id="btnGuardarFotoRapida">Guardar</button></div>
        </div>
    </div>
</div>

<script>
    // 1. FILTRADO (Javascript puro, rápido)
    const buscador = document.getElementById('buscador');
    const filtroCat = document.getElementById('filtroCat');
    const filtroEst = document.getElementById('filtroEstado');
    const orden = document.getElementById('ordenarPor');
    const grid = document.getElementById('gridProductos');
    const noRes = document.getElementById('noResults');
    const counter = document.getElementById('contadorVisible');

    function aplicarFiltros() {
        let txt = buscador.value.toLowerCase();
        let cat = filtroCat.value;
        let est = filtroEst.value;
        let sort = orden.value;
        
        let items = Array.from(document.querySelectorAll('.item-grid'));
        let visibles = 0;

        items.forEach(item => {
            let iNombre = item.dataset.nombre;
            let iCodigo = item.dataset.codigo;
            let iCat = item.dataset.cat;
            let iEst = item.dataset.estado; 

            let cumpleTxt = (iNombre.includes(txt) || iCodigo.includes(txt));
            let cumpleCat = (cat === 'todos' || iCat === cat);
            let cumpleEst = (est === 'todos' || iEst.includes(est));

            if(cumpleTxt && cumpleCat && cumpleEst) {
                item.classList.remove('d-none');
                visibles++;
            } else {
                item.classList.add('d-none');
            }
        });

        // Ordenamiento simple (DOM Reordering)
        items.sort((a, b) => {
            if(sort === 'nombre_asc') return a.dataset.nombre.localeCompare(b.dataset.nombre);
            if(sort === 'precio_alto') return parseFloat(b.dataset.precio) - parseFloat(a.dataset.precio);
            if(sort === 'precio_bajo') return parseFloat(a.dataset.precio) - parseFloat(b.dataset.precio);
            return parseInt(b.dataset.id) - parseInt(a.dataset.id); // Recientes
        });
        items.forEach(item => grid.appendChild(item));

        counter.innerText = visibles;
        if(visibles === 0) noRes.classList.remove('d-none');
        else noRes.classList.add('d-none');
    }

    buscador.addEventListener('keyup', aplicarFiltros);
    // Leer filtro de la URL al cargar la página
const urlParams = new URLSearchParams(window.location.search);
    const f = urlParams.get('filtro');
    if(f) {
        filtroEst.value = f;
        aplicarFiltros();
    }

    // 2. FOTO RÁPIDA
    let currentId = null;
    let cropper;
    const inputImg = document.getElementById('inputImageRapido');
    const modalEl = document.getElementById('modalCropRapido');
    const imgCrop = document.getElementById('imageToCropRapido');
    const modalObj = new bootstrap.Modal(modalEl);

    window.abrirCamara = function(id) { currentId = id; inputImg.click(); }
    inputImg.addEventListener('change', function(e) {
        if(e.target.files && e.target.files[0]) {
            imgCrop.src = URL.createObjectURL(e.target.files[0]);
            modalObj.show();
            inputImg.value = '';
        }
    });
    modalEl.addEventListener('shown.bs.modal', function() {
        cropper = new Cropper(imgCrop, { aspectRatio: 1, viewMode: 1, autoCropArea: 0.9 });
    });
    modalEl.addEventListener('hidden.bs.modal', function() { if(cropper) { cropper.destroy(); cropper = null; } });

    $('#btnGuardarFotoRapida').click(function() {
        if(!cropper) return;
        let canvas = cropper.getCroppedCanvas({ width: 800, height: 800 });
        $.post('acciones/subir_foto_rapida.php', {
            id_producto: currentId,
            imagen_base64: canvas.toDataURL('image/png')
        }, function(res) {
            if(res.status === 'success') {
                $('#img-' + currentId).attr('src', res.url + '?t=' + Date.now());
                modalObj.hide();
                Swal.fire({icon: 'success', title: 'Foto Actualizada', toast: true, position: 'top-end', showConfirmButton: false, timer: 2000});
            } else {
                Swal.fire('Error', res.msg, 'error');
            }
        }, 'json');
    });
    
</script>
<script>
    let filtroActivo = false;

    function filtrarStockBajo() {
        const filas = document.querySelectorAll('.item-grid');
        const widget = document.getElementById('widget-stock-bajo');
        const label = widget.querySelector('.widget-label');
        const value = widget.querySelector('.widget-value');
        const iconBox = widget.querySelector('.icon-box');

        filtroActivo = !filtroActivo;

        filas.forEach(fila => {
            if (filtroActivo) {
                fila.style.display = fila.classList.contains('row-bajo-stock') ? '' : 'none';
            } else {
                fila.style.display = '';
            }
        });

        if(filtroActivo) {
            // ESTADO ACTIVO: Fondo amarillo fuerte y letras oscuras para contraste
            widget.style.backgroundColor = "#ffc107";
            widget.style.borderColor = "#ffc107";
            label.style.color = "#000";
            label.style.opacity = "1";
            value.style.setProperty('color', '#000', 'important');
            iconBox.style.backgroundColor = "rgba(0,0,0,0.1)";
            iconBox.style.color = "#000";
        } else {
            // ESTADO INACTIVO: Vuelve al look transparente y letras blancas del banner
            widget.style.backgroundColor = "rgba(255, 255, 255, 0.1)";
            widget.style.borderColor = "rgba(255, 255, 255, 0.2)";
            label.style.color = "white";
            label.style.opacity = "0.8";
            value.style.setProperty('color', 'white', 'important');
            iconBox.style.backgroundColor = "rgba(255, 255, 255, 0.1)";
            iconBox.style.color = "white";
        }
    }
    function verTodos() {
        document.getElementById('buscador').value = '';
        document.getElementById('filtroCat').value = 'todos';
        document.getElementById('filtroEstado').value = 'todos';
        
        aplicarFiltros();

        // Forzamos que si el widget de stock bajo estaba encendido, se apague y recupere su color azul/transparente
        if (filtroActivo) {
            filtrarStockBajo(); 
        }
    }

    window.reponerStock = function(id, nombre, actual) {
        // Preparamos el HTML de los proveedores desde PHP
        let opcionesProvs = '<option value="">-- Seleccionar (Opcional) --</option>';
        <?php foreach($proveedores_list as $pr): ?>
            opcionesProvs += `<option value="<?php echo $pr->id; ?>"><?php echo addslashes($pr->empresa); ?></option>`;
        <?php endforeach; ?>

        Swal.fire({
            title: 'Ingreso de Mercadería',
            html: `
                <div class="text-start">
                    <p class="mb-3">Producto: <b class="text-primary">${nombre}</b><br>
                    <small class="text-muted">Stock actual: ${actual} unidades</small></p>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold small">CANTIDAD A SUMAR:</label>
                        <input type="number" id="cant_reposicion" class="form-control form-control-lg text-center fw-bold border-primary" placeholder="0.00" step="0.001">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">PROVEEDOR:</label>
                        <select id="prov_reposicion" class="form-select">${opcionesProvs}</select>
                    </div>

                    <div class="mb-0">
                        <label class="form-label fw-bold small">NUEVO COSTO UNITARIO (Opcional):</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" id="costo_reposicion" class="form-control" placeholder="Dejar vacío para no cambiar" step="0.01">
                        </div>
                        <small class="text-muted" style="font-size:0.7rem">Si ingresas un costo, se actualizará en la ficha del producto.</small>
                    </div>
                </div>
            `,
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: 'Confirmar Ingreso',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#102A57',
            preConfirm: () => {
                const cant = document.getElementById('cant_reposicion').value;
                if (!cant || cant <= 0) {
                    Swal.showValidationMessage('Debes ingresar una cantidad válida');
                    return false;
                }
                return {
                    id: id,
                    cantidad: cant,
                    id_proveedor: document.getElementById('prov_reposicion').value,
                    nuevo_costo: document.getElementById('costo_reposicion').value
                };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                $.post('ajax_stock_reposicion.php', result.value, function(res) {
                    if(res.status === 'success') {
                        Swal.fire({ icon: 'success', title: 'Operación Exitosa', text: res.msg, timer: 2000, showConfirmButton: false })
                        .then(() => { location.reload(); });
                    } else {
                        Swal.fire('Error', res.msg, 'error');
                    }
                }, 'json');
            }
        });
    }
</script>

<?php require_once 'includes/layout_footer.php'; ?>