<?php
// combos.php - ARCHIVO COMPLETO FINAL (CON CANDADOS Y VISTAS)
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);

// Candado de Página
if (!$es_admin && !in_array('stock_gestionar_combos', $permisos)) { header("Location: dashboard.php"); exit; }

// --- 1. PROCESAR IMAGEN (CROPPER) ---
function procesarImagenBase64($base64, $url_texto, $actual) {
    if (!empty($base64)) {
        $data = explode(',', $base64);
        $decoded = base64_decode($data[1]);
        $nombre = 'combo_' . time() . '_' . rand(100,999) . '.png';
        if (!is_dir('uploads')) mkdir('uploads', 0777, true);
        file_put_contents('uploads/' . $nombre, $decoded);
        return 'uploads/' . $nombre;
    }
    return (!empty($url_texto)) ? $url_texto : $actual;
}

// --- 2. LÓGICA DE GUARDADO (CREAR/EDITAR/BORRAR) ---

$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

if (isset($_POST['crear_combo'])) {
    if (!$es_admin && !in_array('crear_combo', $permisos)) die("Sin permiso para crear.");
    try {
        $conexion->beginTransaction();
        $img = procesarImagenBase64($_POST['imagen_base64'], '', 'default.jpg');
        
        $stmt = $conexion->prepare("INSERT INTO combos (nombre, precio, codigo_barras, activo, fecha_inicio, fecha_fin, es_ilimitado, tipo_negocio) VALUES (?, ?, ?, 1, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['nombre'], $_POST['precio'], !empty($_POST['codigo']) ? $_POST['codigo'] : 'COMBO-'.time(), 
            !empty($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : date('Y-m-d'), 
            !empty($_POST['fecha_fin']) ? $_POST['fecha_fin'] : date('Y-m-d'), isset($_POST['es_ilimitado']) ? 1 : 0, $rubro_actual
        ]);
        $id_nuevo = $conexion->lastInsertId();

        $sqlP = "INSERT INTO productos (descripcion, precio_venta, precio_oferta, codigo_barras, tipo, id_categoria, stock_actual, activo, es_destacado_web, imagen_url, tipo_negocio) VALUES (?, ?, ?, ?, 'combo', ?, 1, 1, ?, ?, ?)";
        $conexion->prepare($sqlP)->execute([
            $_POST['nombre'], $_POST['precio'], !empty($_POST['precio_oferta']) ? $_POST['precio_oferta'] : NULL,
            !empty($_POST['codigo']) ? $_POST['codigo'] : 'COMBO-'.time(), $_POST['id_categoria'],
            isset($_POST['es_destacado']) ? 1 : 0, $img, $rubro_actual
        ]);
        
        if (isset($_POST['prod_ids'])) {
            $stmtAdd = $conexion->prepare("INSERT INTO combo_items (id_combo, id_producto, cantidad) VALUES (?, ?, ?)");
            foreach ($_POST['prod_ids'] as $idx => $p_id) {
                if(!empty($p_id)) $stmtAdd->execute([$id_nuevo, $p_id, $_POST['prod_cants'][$idx]]);
            }
        }
        $conexion->commit();
        $detalles_audit = "Combo Creado: " . $_POST['nombre'] . " | Precio: $" . $_POST['precio'];
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'COMBO_NUEVO', ?, NOW())")->execute([$_SESSION['usuario_id'], $detalles_audit]);
        header("Location: combos.php?msg=creado"); exit;
    } catch (Exception $e) { $conexion->rollBack(); die($e->getMessage()); }
}

if (isset($_POST['editar_combo'])) {
    if (!$es_admin && !in_array('editar_combo', $permisos)) die("Sin permiso para editar.");
    try {
        $conexion->beginTransaction();
        $id = $_POST['id_combo'];
        $img = procesarImagenBase64($_POST['imagen_base64'], '', $_POST['imagen_actual']);
        
        $stmtOld = $conexion->prepare("SELECT c.*, p.precio_oferta, p.id_categoria, p.es_destacado_web FROM combos c LEFT JOIN productos p ON c.codigo_barras = p.codigo_barras WHERE c.id = ?");
        $stmtOld->execute([$id]);
        $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
        $cod_viejo = $old['codigo_barras'];

        $stmtOldItems = $conexion->prepare("SELECT ci.id_producto, ci.cantidad, p.descripcion FROM combo_items ci JOIN productos p ON ci.id_producto = p.id WHERE ci.id_combo = ?");
        $stmtOldItems->execute([$id]);
        $oldItemsArr = $stmtOldItems->fetchAll(PDO::FETCH_ASSOC);
        $oldItemsStr = implode(", ", array_map(function($i) { return $i['cantidad']."x ".$i['descripcion']; }, $oldItemsArr));
        if(!$oldItemsStr) $oldItemsStr = "Vacío";

        $n_nombre = $_POST['nombre']; $n_precio = $_POST['precio']; $n_oferta = !empty($_POST['precio_oferta']) ? $_POST['precio_oferta'] : 0;
        $n_cat = $_POST['id_categoria']; $n_cod = !empty($_POST['codigo']) ? $_POST['codigo'] : $cod_viejo;
        $n_ilim = isset($_POST['es_ilimitado']) ? 1 : 0; $n_fini = $_POST['fecha_inicio']; $n_ffin = $_POST['fecha_fin'];
        $n_dest = isset($_POST['es_destacado']) ? 1 : 0;

        $cambios = [];
        if($old['nombre'] != $n_nombre) $cambios[] = "Nombre: " . $old['nombre'] . " -> " . $n_nombre;
        if(floatval($old['precio']) != floatval($n_precio)) $cambios[] = "Precio: $" . floatval($old['precio']) . " -> $" . floatval($n_precio);
        if(floatval($old['precio_oferta']) != floatval($n_oferta)) $cambios[] = "Oferta: $" . floatval($old['precio_oferta']) . " -> $" . floatval($n_oferta);
        if($old['codigo_barras'] != $n_cod) $cambios[] = "Código: " . $old['codigo_barras'] . " -> " . $n_cod;
        if($old['es_ilimitado'] != $n_ilim) $cambios[] = "Ilimitado: " . ($old['es_ilimitado']?"Sí":"No") . " -> " . ($n_ilim?"Sí":"No");

        $conexion->prepare("UPDATE combos SET nombre=?, precio=?, codigo_barras=?, fecha_inicio=?, fecha_fin=?, es_ilimitado=? WHERE id=?")
            ->execute([$n_nombre, $n_precio, $n_cod, $n_fini, $n_ffin, $n_ilim, $id]);

        $conexion->prepare("UPDATE productos SET descripcion=?, precio_venta=?, precio_oferta=?, id_categoria=?, es_destacado_web=?, imagen_url=?, codigo_barras=? WHERE codigo_barras=?")
            ->execute([$n_nombre, $n_precio, $n_oferta>0?$n_oferta:NULL, $n_cat, $n_dest, $img, $n_cod, $cod_viejo]);

        $conexion->prepare("DELETE FROM combo_items WHERE id_combo = ?")->execute([$id]);
        $newItemsArr = [];
        if (isset($_POST['prod_ids'])) {
            $stmtAdd = $conexion->prepare("INSERT INTO combo_items (id_combo, id_producto, cantidad) VALUES (?, ?, ?)");
            foreach ($_POST['prod_ids'] as $idx => $p_id) {
                if(!empty($p_id)) {
                    $stmtAdd->execute([$id, $p_id, $_POST['prod_cants'][$idx]]);
                    $n_desc = $conexion->query("SELECT descripcion FROM productos WHERE id=".intval($p_id))->fetchColumn();
                    $newItemsArr[] = $_POST['prod_cants'][$idx] . "x " . $n_desc;
                }
            }
        }
        $newItemsStr = implode(", ", $newItemsArr);
        if(!$newItemsStr) $newItemsStr = "Vacío";
        if($oldItemsStr != $newItemsStr) $cambios[] = "Contenido: [" . $oldItemsStr . "] -> [" . $newItemsStr . "]";

        $conexion->commit();
        if(!empty($cambios)) {
            $detalles_audit = "Combo Editado: " . $old['nombre'] . " | " . implode(" | ", $cambios);
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'COMBO_EDITADO', ?, NOW())")->execute([$_SESSION['usuario_id'], $detalles_audit]);
        }
        if (isset($_POST['origen']) && $_POST['origen'] === 'productos') {
            header("Location: productos.php?msg=editado"); exit;
        }
        header("Location: combos.php?msg=editado"); exit;
    } catch (Exception $e) { $conexion->rollBack(); die($e->getMessage()); }
}

// --- BORRADO MASIVO AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitud_borrar_masivo'])) {
    if (!$es_admin && !in_array('eliminar_combo', $permisos)) { echo "Sin permiso"; exit; }
    $ids = json_decode($_POST['ids_a_borrar'], true);
    if (!empty($ids) && is_array($ids)) {
        try {
            $conexion->beginTransaction();
            foreach($ids as $id) {
                $id = intval($id);
                $stmtC = $conexion->prepare("SELECT codigo_barras FROM combos WHERE id = ?");
                $stmtC->execute([$id]);
                $cod = $stmtC->fetchColumn();

                $conexion->prepare("DELETE FROM combo_items WHERE id_combo = ?")->execute([$id]);
                $conexion->prepare("DELETE FROM combos WHERE id = ?")->execute([$id]);
                if($cod) $conexion->prepare("DELETE FROM productos WHERE codigo_barras = ?")->execute([$cod]);
            }
            $conexion->commit();
            echo "EXITO";
        } catch(Exception $e) { $conexion->rollBack(); echo "ERROR: ".$e->getMessage(); }
    }
    exit;
}

if (isset($_GET['eliminar_id'])) {
    if (!$es_admin && !in_array('eliminar_combo', $permisos)) die("Sin permiso para eliminar.");
    try {
        $conexion->beginTransaction();
        $id = $_GET['eliminar_id'];
        $stmtC = $conexion->prepare("SELECT nombre, precio, codigo_barras FROM combos WHERE id = ?");
        $stmtC->execute([$id]);
        $old = $stmtC->fetch(PDO::FETCH_ASSOC);
        $cod = $old ? $old['codigo_barras'] : null;

        $conexion->prepare("DELETE FROM combo_items WHERE id_combo = ?")->execute([$id]);
        $conexion->prepare("DELETE FROM combos WHERE id = ?")->execute([$id]);
        if($cod) $conexion->prepare("DELETE FROM productos WHERE codigo_barras = ?")->execute([$cod]);
        
        $conexion->commit();
        if($old) {
            $detalles_audit = "Combo Eliminado: " . $old['nombre'] . " | Precio: $" . floatval($old['precio']) . " | Código: " . $old['codigo_barras'];
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'COMBO_ELIMINADO', ?, NOW())")->execute([$_SESSION['usuario_id'], $detalles_audit]);
        }
        header("Location: combos.php?msg=eliminado"); exit;
    } catch (Exception $e) { $conexion->rollBack(); die($e->getMessage()); }
}

// --- 3. DATOS ---
$combos = $conexion->query("SELECT c.*, p.precio_oferta, p.imagen_url, p.id_categoria, p.es_destacado_web FROM combos c LEFT JOIN productos p ON c.codigo_barras = p.codigo_barras WHERE c.activo=1 AND (c.tipo_negocio = '$rubro_actual' OR c.tipo_negocio IS NULL) ORDER BY c.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$categorias = $conexion->query("SELECT * FROM categorias WHERE activo=1 AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)")->fetchAll(PDO::FETCH_ASSOC);
$productos_lista = $conexion->query("SELECT id, descripcion, stock_actual, precio_venta, precio_costo FROM productos WHERE activo=1 AND tipo != 'combo' AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL) ORDER BY descripcion ASC")->fetchAll(PDO::FETCH_ASSOC);

$recetas_data = [];
foreach($combos as $c) {
    $stmtItems = $conexion->prepare("SELECT ci.id, p.id as id_producto, ci.cantidad, p.descripcion, p.precio_venta FROM combo_items ci JOIN productos p ON ci.id_producto = p.id WHERE ci.id_combo = ?");
    $stmtItems->execute([$c['id']]);
    $recetas_data[$c['id']] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
}

$recetas_json = json_encode($recetas_data);
$productos_json = json_encode($productos_lista);
$combos_json = json_encode($combos);

// WIDGETS
$total = count($combos);
$ofertas = 0; $destacados = 0;
foreach($combos as $c) {
    if($c['precio_oferta'] > 0) $ofertas++;
    if($c['es_destacado_web']) $destacados++;
}

$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1");
    if ($resColor && $dataC = $resColor->fetch(PDO::FETCH_ASSOC)) {
        if (isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav'];
    }
} catch (Exception $e) { }

include 'includes/layout_header.php'; 

// --- DEFINICIÓN DEL BANNER ESTANDARIZADO ---
$titulo = "Mis Packs y Combos";
$subtitulo = "Gestión de ofertas y promociones";
$icono_bg = "bi-basket2-fill";

$botones = [];
// 1. Agregamos el botón de PDF (visible para todos los que pueden entrar a la página)


// 2. Mantenemos el botón de Nuevo Combo original
if($es_admin || in_array('crear_combo', $permisos)) {
    $botones[] = ['texto' => 'NUEVO COMBO', 'icono' => 'bi-plus-circle-fill', 'class' => 'btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm ms-2', 'link' => 'javascript:void(0)" data-bs-toggle="modal" data-bs-target="#modalCrear"'];
}
$botones[] = ['texto' => 'REPORTE PDF', 'link' => 'reporte_combos.php', 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-4 shadow-sm', 'target' => '_blank'];
$widgets = [
    ['label' => 'Total Combos', 'valor' => $total, 'icono' => 'bi-basket', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'En Oferta', 'valor' => $ofertas, 'icono' => 'bi-percent', 'border' => 'border-warning', 'icon_bg' => 'bg-warning bg-opacity-20'],
    ['label' => 'Destacados Web', 'valor' => $destacados, 'icono' => 'bi-star-fill', 'border' => 'border-success', 'icon_bg' => 'bg-success bg-opacity-20']
];

include 'includes/componente_banner.php'; 
?>

<link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">

<style>
    /* Estilos Filtros */
    .filter-bar.sticky-desktop { position: sticky; top: 65px; z-index: 1000; background: #fff; margin-bottom: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    @media (max-width: 768px) { 
        .filter-bar.sticky-desktop { top: 10px; position: relative; padding: 10px; } 
        .filter-content-wrapper { display: none; flex-direction: column; gap: 10px; padding-top: 15px; }
        .filter-content-wrapper.show { display: flex; }
        .search-group, .filter-select { width: 100% !important; }
    }
    @media (min-width: 769px) {
        .filter-content-wrapper { display: flex !important; width: 100%; gap: 10px; align-items: center; }
        .btn-toggle-filters { display: none; }
    }
</style>

<div class="container pb-5 mt-n4" style="position: relative; z-index: 20;">

    <div class="filter-bar sticky-desktop rounded-4 p-3">
        <div class="d-flex gap-2 w-100 mb-2">
            <button class="btn btn-primary fw-bold btn-toggle-filters flex-fill m-0 shadow-sm d-md-none" type="button" onclick="toggleFiltrosMovil()">
                <i class="bi bi-funnel-fill"></i> MOSTRAR BUSCADOR
            </button>
            <?php if($es_admin || in_array('eliminar_combo', $permisos)): ?>
            <button type="button" id="btnBorrarMasivo" class="btn btn-danger fw-bold shadow-sm flex-fill flex-md-grow-0 d-none" onclick="borrarSeleccionados()">
                <i class="bi bi-trash3-fill me-1"></i> BORRAR (<span id="cuentaSeleccionados">0</span>)
            </button>
            <?php endif; ?>
        </div>

        <div id="wrapperFiltros" class="filter-content-wrapper mt-md-0 mt-2">
            <div class="search-group flex-fill mb-0">
                <i class="bi bi-search search-icon"></i>
                <input type="text" id="buscador" class="search-input w-100" placeholder="Buscar nombre o código de combo..." onkeyup="aplicarFiltros()">
            </div>
        </div>
    </div>

    <div class="row g-3" id="gridProductos">
        <?php foreach($combos as $c): $items = $recetas_data[$c['id']]; ?>
        <div class="col-12 col-md-6 col-lg-4 item-grid" data-nombre="<?php echo strtolower($c['nombre'] . ' ' . $c['codigo_barras']); ?>">
            <div class="card-combo h-100 d-flex flex-column border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="img-combo-box" style="height: 180px; background: #f8f9fa; position: relative;">
                    <?php if($es_admin || in_array('eliminar_combo', $permisos)): ?>
                    <input type="checkbox" class="form-check-input checkCombo position-absolute top-0 start-0 m-2 shadow-sm" value="<?php echo $c['id']; ?>" onclick="event.stopPropagation(); revisarChecks()" style="transform: scale(1.4); cursor:pointer; z-index: 10;">
                    <?php endif; ?>
                    <img src="<?php echo $c['imagen_url'] ?: 'img/no-image.png'; ?>" loading="lazy" style="width: 100%; height: 100%; object-fit: cover;">
                    <?php if($c['es_destacado_web']): ?><span class="badge bg-warning text-dark position-absolute top-0 end-0 m-2 shadow-sm"><i class="bi bi-star-fill"></i> Destacado</span><?php endif; ?>
                </div>
                <div class="p-3 flex-grow-1 bg-white">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h5 class="fw-bold m-0 text-dark"><?php echo $c['nombre']; ?></h5>
                            <small class="text-muted font-monospace"><?php echo $c['codigo_barras']; ?></small>
                        </div>
                        <div class="text-end">
                            <?php if($c['precio_oferta']): ?>
                                <div class="text-decoration-line-through text-muted small">$<?php echo number_format($c['precio'], 0); ?></div>
                                <div class="fw-black text-danger fs-5">$<?php echo number_format($c['precio_oferta'], 0); ?></div>
                            <?php else: ?>
                                <div class="fw-black text-primary fs-5">$<?php echo number_format($c['precio'], 0); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <?php if($c['es_ilimitado']): ?><span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">ILIMITADO</span><?php else: ?>
                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle rounded-pill">Vigente: <?php echo date('d/m', strtotime($c['fecha_inicio'])); ?> al <?php echo date('d/m', strtotime($c['fecha_fin'])); ?></span><?php endif; ?>
                    </div>
                    <div class="bg-light p-2 rounded small border">
                        <b class="d-block mb-1 border-bottom pb-1 text-muted">Contenido del Pack:</b>
                        <?php if(empty($items)): ?><span class="text-muted fst-italic">Vacío.</span><?php else: foreach($items as $i): ?>
                            <div><b><?php echo $i['cantidad']; ?>x</b> <?php echo $i['descripcion']; ?></div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                <div class="p-3 bg-light d-flex justify-content-end gap-2 border-top">
                    <?php if($es_admin || in_array('editar_combo', $permisos)): ?>
                    <button class="btn btn-sm btn-outline-primary fw-bold px-3 rounded-pill" onclick="abrirEditar(<?php echo $c['id']; ?>)"><i class="bi bi-pencil-fill"></i> EDITAR</button>
                    <?php endif; ?>
                    
                    <?php if($es_admin || in_array('eliminar_combo', $permisos)): ?>
                    <button class="btn btn-sm btn-outline-danger fw-bold px-3 rounded-pill" onclick="borrarPack(<?php echo $c['id']; ?>)"><i class="bi bi-trash-fill"></i></button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div id="vistaListaGenerica" class="d-none">
        <div class="card shadow-sm mb-4 border-0 rounded-4 overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 14px;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width: 5%;">
                                <?php if($es_admin || in_array('eliminar_combo', $permisos)): ?>
                                <input type="checkbox" class="form-check-input" onclick="alternarTodos(this)" style="transform: scale(1.2); cursor:pointer;">
                                <?php endif; ?>
                            </th>
                            <th style="width: 15%;">CÓDIGO</th>
                            <th style="width: 30%;">COMBO / PACK</th>
                            <th class="text-center" style="width: 15%;">ESTADO</th>
                            <th class="text-end" style="width: 15%;">PRECIO</th>
                            <th class="text-center" style="width: 20%;">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($combos as $c): ?>
                        <tr class="item-lista" data-nombre="<?php echo strtolower($c['nombre'] . ' ' . $c['codigo_barras']); ?>">
                            <td class="ps-3">
                                <?php if($es_admin || in_array('eliminar_combo', $permisos)): ?>
                                <input type="checkbox" class="form-check-input checkCombo" value="<?php echo $c['id']; ?>" onclick="event.stopPropagation(); revisarChecks()" style="transform: scale(1.2); cursor:pointer;">
                                <?php endif; ?>
                            </td>
                            <td class="text-muted fw-bold"><?php echo $c['codigo_barras']; ?></td>
                            <td>
                                <div class="fw-bold text-dark fs-6"><?php echo $c['nombre']; ?></div>
                                <div class="small text-muted">Contiene <?php echo count($recetas_data[$c['id']]); ?> productos</div>
                            </td>
                            <td class="text-center">
                                <?php if($c['es_ilimitado']): ?><span class="badge bg-success">Activo</span><?php else: ?><span class="badge bg-info">Temporal</span><?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if($c['precio_oferta']): ?>
                                    <div class="text-danger fw-bold fs-6">$<?php echo number_format($c['precio_oferta'], 0); ?></div>
                                    <div class="text-muted small text-decoration-line-through">$<?php echo number_format($c['precio'], 0); ?></div>
                                <?php else: ?>
                                    <div class="text-primary fw-bold fs-6">$<?php echo number_format($c['precio'], 0); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">
                                    <?php if($es_admin || in_array('editar_combo', $permisos)): ?>
                                    <button class="btn btn-sm btn-outline-primary py-0 px-2" onclick="event.stopPropagation(); abrirEditar(<?php echo $c['id']; ?>)"><i class="bi bi-pencil"></i></button>
                                    <?php endif; ?>
                                    <?php if($es_admin || in_array('eliminar_combo', $permisos)): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="event.stopPropagation(); borrarPack(<?php echo $c['id']; ?>)"><i class="bi bi-trash-fill"></i></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<div class="modal fade" id="modalCrear" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="crear_combo" value="1">
            <input type="hidden" name="imagen_base64" id="c_base64">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold"><i class="bi bi-basket"></i> Nuevo Combo</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4 row">
                <div class="col-md-4 text-center border-end">
                    <img src="img/no-image.png" id="c_preview" class="img-thumbnail mb-2 rounded-4 shadow-sm" style="width:150px; height:150px; object-fit:cover;">
                    <label class="btn btn-sm btn-outline-primary w-100 fw-bold rounded-pill">SUBIR FOTO <input type="file" class="d-none" onchange="prepararCrop(this, 'c')" accept="image/*"></label>
                </div>
                <div class="col-md-8 row g-3">
                    <div class="col-12"><label class="fw-bold small text-muted">Nombre del Combo</label><input type="text" name="nombre" class="form-control fw-bold" required></div>
                    <div class="col-6"><label class="fw-bold small text-muted">Precio ($)</label><input type="number" name="precio" id="c_precio_input" class="form-control fw-bold text-primary" required></div>
                    <div class="col-6"><label class="fw-bold small text-danger">Oferta ($)</label><input type="number" name="precio_oferta" class="form-control fw-bold border-danger text-danger"></div>
                    <div class="col-6"><label class="fw-bold small text-muted">Categoría</label><select name="id_categoria" class="form-select" required><?php foreach($categorias as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo $cat['nombre']; ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><label class="fw-bold small text-muted">Código de Barras</label><input type="text" name="codigo" class="form-control"></div>
                    <div class="col-12 bg-light p-3 rounded-3 border">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="es_ilimitado" id="c_ilim" checked onchange="toggleDates('c')"><label class="fw-bold text-dark">Promoción Ilimitada</label></div>
                        <div class="row g-2 mt-2" id="c_dates" style="display:none"><div class="col-6"><small class="text-muted fw-bold">Desde</small><input type="date" name="fecha_inicio" class="form-control form-control-sm"></div><div class="col-6"><small class="text-muted fw-bold">Hasta</small><input type="date" name="fecha_fin" class="form-control form-control-sm"></div></div>
                    </div>
                    <div class="col-12 border-top pt-2">
                        <label class="fw-bold text-success small mb-2"><i class="bi bi-box-seam"></i> PRODUCTOS DEL COMBO</label>
                        <div id="lista-items-nuevo"></div>
                        <button type="button" class="btn btn-outline-success btn-sm w-100 mt-2 fw-bold border-dashed" onclick="agregarFila('nuevo')">+ AGREGAR PRODUCTO AL PACK</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light"><div class="me-auto text-muted fw-bold" id="c_costo_total"></div><button class="btn btn-primary fw-bold px-4 rounded-pill">GUARDAR COMBO</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalEditar" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="editar_combo" value="1">
            <input type="hidden" name="id_combo" id="e_id">
            <input type="hidden" name="imagen_actual" id="e_actual">
            <input type="hidden" name="imagen_base64" id="e_base64">
            <input type="hidden" name="origen" id="e_origen" value="">
            <div class="modal-header bg-warning"><h5 class="modal-title fw-bold text-dark"><i class="bi bi-pencil-square"></i> Editar Combo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4 row">
                <div class="col-md-4 text-center border-end">
                    <img src="" id="e_preview" class="img-thumbnail mb-2 rounded-4 shadow-sm" style="width:150px; height:150px; object-fit:cover;">
                    <label class="btn btn-sm btn-outline-dark w-100 fw-bold rounded-pill">CAMBIAR FOTO <input type="file" class="d-none" onchange="prepararCrop(this, 'e')" accept="image/*"></label>
                </div>
                <div class="col-md-8 row g-3">
                    <div class="col-12"><label class="fw-bold small text-muted">Nombre</label><input type="text" name="nombre" id="e_nombre" class="form-control fw-bold" required></div>
                    <div class="col-6"><label class="fw-bold small text-muted">Precio ($)</label><input type="number" name="precio" id="e_precio" class="form-control fw-bold text-primary" required></div>
                    <div class="col-6"><label class="fw-bold small text-danger">Oferta ($)</label><input type="number" name="precio_oferta" id="e_oferta" class="form-control fw-bold border-danger text-danger"></div>
                    <div class="col-6"><label class="fw-bold small text-muted">Categoría</label><select name="id_categoria" id="e_cat" class="form-select" required><?php foreach($categorias as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo $cat['nombre']; ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><label class="fw-bold small text-muted">Código</label><input type="text" name="codigo" id="e_codigo" class="form-control"></div>
                    <div class="col-12 bg-light p-3 rounded-3 border">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="es_ilimitado" id="e_ilim" onchange="toggleDates('e')"><label class="fw-bold">Ilimitado</label></div>
                        <div class="row g-2 mt-2" id="e_dates"><div class="col-6"><small class="text-muted fw-bold">Desde</small><input type="date" name="fecha_inicio" id="e_ini" class="form-control form-control-sm"></div><div class="col-6"><small class="text-muted fw-bold">Hasta</small><input type="date" name="fecha_fin" id="e_fin" class="form-control form-control-sm"></div></div>
                        <div class="form-check mt-2 border-top pt-2"><input class="form-check-input" type="checkbox" name="es_destacado" id="e_dest"><label class="fw-bold text-warning-dark"><i class="bi bi-star-fill text-warning"></i> Destacar en Tienda Web</label></div>
                    </div>
                    <div class="col-12 border-top pt-2">
                        <label class="fw-bold text-primary small mb-2"><i class="bi bi-box-seam"></i> PRODUCTOS</label>
                        <div id="lista-items-editar"></div>
                        <button type="button" class="btn btn-outline-primary btn-sm w-100 mt-2 fw-bold border-dashed" onclick="agregarFila('editar')">+ AGREGAR PRODUCTO AL PACK</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light"><div class="me-auto text-muted fw-bold" id="e_costo_total"></div><button class="btn btn-warning fw-bold px-4 rounded-pill">GUARDAR CAMBIOS</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalCrop" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-body p-0 bg-dark text-center"><img id="imageToCrop" src="" style="max-width: 100%;"></div><div class="modal-footer bg-dark border-0"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary btn-sm" id="btnRecortar">RECORTAR</button></div></div></div></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    function aplicarFiltros() {
        let txt = document.getElementById('buscador').value.toLowerCase();
        document.querySelectorAll('.item-grid').forEach(item => {
            if(item.dataset.nombre.includes(txt)) { item.classList.remove('d-none'); } else { item.classList.add('d-none'); }
        });
        document.querySelectorAll('.item-lista').forEach(item => {
            if(item.dataset.nombre.includes(txt)) { item.classList.remove('d-none'); } else { item.classList.add('d-none'); }
        });
    }

    function toggleFiltrosMovil() {
        const wrapper = document.getElementById('wrapperFiltros');
        const btn = document.querySelector('.btn-toggle-filters');
        wrapper.classList.toggle('show');
        if (wrapper.classList.contains('show')) {
            btn.innerHTML = '<i class="bi bi-chevron-up"></i> OCULTAR';
            btn.classList.replace('btn-primary', 'btn-outline-primary');
        } else {
            btn.innerHTML = '<i class="bi bi-funnel-fill"></i> MOSTRAR BUSCADOR';
            btn.classList.replace('btn-outline-primary', 'btn-primary');
        }
    }

    let cropper, prefijoGlobal;
    let modalCrop, modalEditar;

    document.addEventListener('DOMContentLoaded', function() {
        if(typeof bootstrap !== 'undefined') {
            modalCrop = new bootstrap.Modal(document.getElementById('modalCrop'));
            modalEditar = new bootstrap.Modal(document.getElementById('modalEditar'));
        }

        $('#btnRecortar').on('click', function() {
            if(!cropper) return;
            let base64 = cropper.getCroppedCanvas({width:600, height:600}).toDataURL('image/png');
            $(`#${prefijoGlobal}_preview`).attr('src', base64);
            $(`#${prefijoGlobal}_base64`).val(base64);
            modalCrop.hide();
        });
    });
    
    const prodsDB = <?php echo $productos_json; ?>;
    const recetasOriginales = <?php echo $recetas_json; ?>;
    const combosDB = <?php echo $combos_json; ?>;

    function toggleDates(p) {
        let ck = document.getElementById(p+'_ilim');
        document.getElementById(p+'_dates').style.display = ck.checked ? 'none' : 'flex';
    }

    function agregarFila(tipo, idProd='', cant=1) {
        let opts = '<option value="">Seleccionar producto...</option>';
        prodsDB.forEach(p => {
            opts += `<option value="${p.id}" ${p.id==idProd?'selected':''} data-costo="${p.precio_costo}" data-venta="${p.precio_venta}">${p.descripcion}</option>`;
        });
        $((tipo=='nuevo')?'#lista-items-nuevo':'#lista-items-editar').append(`
            <div class="row g-2 mb-2 item-row align-items-center">
                <div class="col-7"><select name="prod_ids[]" class="form-select form-select-sm sel-p" onchange="recalcular('${tipo}')" required>${opts}</select></div>
                <div class="col-3"><input type="number" name="prod_cants[]" class="form-control form-control-sm text-center cant-p" value="${cant}" min="1" oninput="recalcular('${tipo}')" required></div>
                <div class="col-2"><button type="button" class="btn btn-sm btn-danger w-100" onclick="$(this).closest('.row').remove();recalcular('${tipo}')">×</button></div>
            </div>`);
        if(idProd=='') recalcular(tipo);
    }

    function recalcular(tipo) {
        let costo=0, venta=0;
        let base = (tipo=='nuevo')?'#lista-items-nuevo':'#lista-items-editar';
        $(base+' .item-row').each(function(){
            let sel = $(this).find('.sel-p option:selected');
            let cant = parseFloat($(this).find('.cant-p').val())||0;
            if(sel.val()){
                costo += (parseFloat(sel.data('costo'))||0)*cant;
                venta += (parseFloat(sel.data('venta'))||0)*cant;
            }
        });
        $((tipo=='nuevo')?'#c_costo_total':'#e_costo_total').html(`<small>Costo Armado: $${costo} | Venta Sugerida: $${(venta*0.85).toFixed(0)}</small>`);
        let inp = $(tipo=='nuevo'?'#c_precio_input':'#e_precio');
        if(tipo=='nuevo' && (inp.val()=='' || inp.val()==0)) inp.val((venta*0.85).toFixed(0));
    }

    window.abrirEditar = function(id) {
        let obj = combosDB.find(c => c.id == id);
        if(!obj) return;
        
        $('#e_id').val(obj.id);
        $('#e_nombre').val(obj.nombre);
        $('#e_precio').val(obj.precio);
        $('#e_oferta').val(obj.precio_oferta);
        $('#e_codigo').val(obj.codigo_barras);
        $('#e_cat').val(obj.id_categoria);
        $('#e_actual').val(obj.imagen_url);
        $('#e_preview').attr('src', obj.imagen_url || 'img/no-image.png');
        $('#e_base64').val('');
        
        $('#e_dest').prop('checked', obj.es_destacado_web==1);
        $('#e_ilim').prop('checked', obj.es_ilimitado==1);
        $('#e_ini').val(obj.fecha_inicio);
        $('#e_fin').val(obj.fecha_fin);
        toggleDates('e');

        $('#lista-items-editar').html('');
        let items = recetasOriginales[obj.id]||[];
        items.forEach(i => agregarFila('editar', i.id_producto, i.cantidad));
        recalcular('editar');
        modalEditar.show();
    }

    window.prepararCrop = function(input, p) {
        if(input.files && input.files[0]) {
            prefijoGlobal = p;
            let reader = new FileReader();
            reader.onload = function(e){
                $('#imageToCrop').attr('src', e.target.result);
                if(cropper) cropper.destroy();
                modalCrop.show();
            }
            reader.readAsDataURL(input.files[0]);
            input.value = '';
        }
    }
    document.getElementById('modalCrop').addEventListener('shown.bs.modal', ()=>{
        cropper = new Cropper(document.getElementById('imageToCrop'), {aspectRatio:1, viewMode:1});
    });
    
    window.borrarPack = function(id) { procesarBorrado([id]); }

    window.revisarChecks = function() {
        let marcados = document.querySelectorAll('.checkCombo:checked').length;
        let btn = document.getElementById('btnBorrarMasivo');
        let span = document.getElementById('cuentaSeleccionados');
        if(span) span.innerText = marcados;
        if(btn) {
            if(marcados > 0) btn.classList.remove('d-none');
            else btn.classList.add('d-none');
        }
    }

    window.borrarSeleccionados = function() {
        let seleccionados = [];
        document.querySelectorAll('.checkCombo:checked').forEach(c => seleccionados.push(c.value));
        if(seleccionados.length > 0) procesarBorrado(seleccionados);
    }

    window.procesarBorrado = function(listaIds) {
        Swal.fire({
            title: '¿Confirmar borrado?',
            text: "Vas a eliminar " + listaIds.length + " combo(s). Esto no afecta el stock de los productos internos.",
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

    // Auto-abrir infalible desde productos.php
    $(window).on('load', function() {
        const params = new URLSearchParams(window.location.search);
        const editarCodigo = params.get('editar_codigo');
        const origen = params.get('origen');
        
        if (editarCodigo) {
            // Limpiamos espacios y mayúsculas para que no falle jamás
            let cod = String(editarCodigo).trim().toLowerCase();
            let comboEncontrado = combosDB.find(c => String(c.codigo_barras || '').trim().toLowerCase() === cod);
            
            if (comboEncontrado) {
                setTimeout(() => { 
                    window.abrirEditar(comboEncontrado.id); 
                    if(origen) document.getElementById('e_origen').value = origen;
                }, 300); // 300ms garantizan que la pantalla ya cargó todo lo visual
            } else {
                Swal.fire('Aviso', 'No se encontró el combo asociado al código de barras.', 'warning');
            }
        }
    });

    // Auto-abrir el editor si venimos desde productos.php
    document.addEventListener("DOMContentLoaded", function() {
        const params = new URLSearchParams(window.location.search);
        const editarCodigo = params.get('editar_codigo');
        if (editarCodigo) {
            let comboEncontrado = combosDB.find(c => c.codigo_barras === editarCodigo);
            if (comboEncontrado) {
                setTimeout(() => { abrirEditar(comboEncontrado.id); }, 400);
            }
        }
    });
</script>
<?php include 'includes/layout_footer.php'; ?>