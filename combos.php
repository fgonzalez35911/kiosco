<?php
// combos.php - ARCHIVO COMPLETO FINAL
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

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

// CREAR
if (isset($_POST['crear_combo'])) {
    try {
        $conexion->beginTransaction();
        $img = procesarImagenBase64($_POST['imagen_base64'], '', 'default.jpg');
        
        // Insertar Combo
        $stmt = $conexion->prepare("INSERT INTO combos (nombre, precio, codigo_barras, activo, fecha_inicio, fecha_fin, es_ilimitado) VALUES (?, ?, ?, 1, ?, ?, ?)");
        $stmt->execute([
            $_POST['nombre'], 
            $_POST['precio'], 
            !empty($_POST['codigo']) ? $_POST['codigo'] : 'COMBO-'.time(), 
            !empty($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : date('Y-m-d'), 
            !empty($_POST['fecha_fin']) ? $_POST['fecha_fin'] : date('Y-m-d'), 
            isset($_POST['es_ilimitado']) ? 1 : 0
        ]);
        $id_nuevo = $conexion->lastInsertId();

        // Insertar Producto Espejo
        $sqlP = "INSERT INTO productos (descripcion, precio_venta, precio_oferta, codigo_barras, tipo, id_categoria, stock_actual, activo, es_destacado_web, imagen_url) VALUES (?, ?, ?, ?, 'combo', ?, 1, 1, ?, ?)";
        $conexion->prepare($sqlP)->execute([
            $_POST['nombre'], 
            $_POST['precio'], 
            !empty($_POST['precio_oferta']) ? $_POST['precio_oferta'] : NULL,
            !empty($_POST['codigo']) ? $_POST['codigo'] : 'COMBO-'.time(),
            $_POST['id_categoria'],
            isset($_POST['es_destacado']) ? 1 : 0,
            $img
        ]);

        // Items
        if (isset($_POST['prod_ids'])) {
            $stmtAdd = $conexion->prepare("INSERT INTO combo_items (id_combo, id_producto, cantidad) VALUES (?, ?, ?)");
            foreach ($_POST['prod_ids'] as $idx => $p_id) {
                if(!empty($p_id)) $stmtAdd->execute([$id_nuevo, $p_id, $_POST['prod_cants'][$idx]]);
            }
        }
        $conexion->commit();
        header("Location: combos.php?msg=creado"); exit;
    } catch (Exception $e) { $conexion->rollBack(); die($e->getMessage()); }
}

// EDITAR
if (isset($_POST['editar_combo'])) {
    try {
        $conexion->beginTransaction();
        $id = $_POST['id_combo'];
        $img = procesarImagenBase64($_POST['imagen_base64'], '', $_POST['imagen_actual']);
        
        // Obtener código viejo
        $stmtCod = $conexion->prepare("SELECT codigo_barras FROM combos WHERE id = ?");
        $stmtCod->execute([$id]);
        $cod = $stmtCod->fetchColumn();

        // Update Combo
        $conexion->prepare("UPDATE combos SET nombre=?, precio=?, fecha_inicio=?, fecha_fin=?, es_ilimitado=? WHERE id=?")
            ->execute([
                $_POST['nombre'], 
                $_POST['precio'], 
                $_POST['fecha_inicio'], 
                $_POST['fecha_fin'], 
                isset($_POST['es_ilimitado']) ? 1 : 0, 
                $id
            ]);

        // Update Producto
        $conexion->prepare("UPDATE productos SET descripcion=?, precio_venta=?, precio_oferta=?, id_categoria=?, es_destacado_web=?, imagen_url=? WHERE codigo_barras=?")
            ->execute([
                $_POST['nombre'], 
                $_POST['precio'], 
                !empty($_POST['precio_oferta']) ? $_POST['precio_oferta'] : NULL,
                $_POST['id_categoria'],
                isset($_POST['es_destacado']) ? 1 : 0,
                $img,
                $cod
            ]);

        // Update Items
        $conexion->prepare("DELETE FROM combo_items WHERE id_combo = ?")->execute([$id]);
        if (isset($_POST['prod_ids'])) {
            $stmtAdd = $conexion->prepare("INSERT INTO combo_items (id_combo, id_producto, cantidad) VALUES (?, ?, ?)");
            foreach ($_POST['prod_ids'] as $idx => $p_id) {
                if(!empty($p_id)) $stmtAdd->execute([$id, $p_id, $_POST['prod_cants'][$idx]]);
            }
        }
        $conexion->commit();
        header("Location: combos.php?msg=editado"); exit;
    } catch (Exception $e) { $conexion->rollBack(); die($e->getMessage()); }
}

// ELIMINAR
if (isset($_GET['eliminar_id'])) {
    try {
        $conexion->beginTransaction();
        $id = $_GET['eliminar_id'];
        $stmtC = $conexion->prepare("SELECT codigo_barras FROM combos WHERE id = ?");
        $stmtC->execute([$id]);
        $cod = $stmtC->fetchColumn();

        $conexion->prepare("DELETE FROM combo_items WHERE id_combo = ?")->execute([$id]);
        $conexion->prepare("DELETE FROM combos WHERE id = ?")->execute([$id]);
        if($cod) $conexion->prepare("DELETE FROM productos WHERE codigo_barras = ?")->execute([$cod]);
        
        $conexion->commit();
        header("Location: combos.php?msg=eliminado"); exit;
    } catch (Exception $e) { $conexion->rollBack(); die($e->getMessage()); }
}

// --- 3. DATOS ---
$combos = $conexion->query("SELECT c.*, p.precio_oferta, p.imagen_url, p.id_categoria, p.es_destacado_web FROM combos c LEFT JOIN productos p ON c.codigo_barras = p.codigo_barras WHERE c.activo=1 ORDER BY c.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$categorias = $conexion->query("SELECT * FROM categorias WHERE activo=1")->fetchAll(PDO::FETCH_ASSOC);
$productos_lista = $conexion->query("SELECT id, descripcion, stock_actual, precio_venta, precio_costo FROM productos WHERE activo=1 AND tipo != 'combo' ORDER BY descripcion ASC")->fetchAll(PDO::FETCH_ASSOC);

$recetas_data = [];
foreach($combos as $c) {
    $stmtItems = $conexion->prepare("SELECT ci.id, p.id as id_producto, ci.cantidad, p.descripcion, p.precio_venta FROM combo_items ci JOIN productos p ON ci.id_producto = p.id WHERE ci.id_combo = ?");
    $stmtItems->execute([$c['id']]);
    $recetas_data[$c['id']] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
}

// DATOS PARA JS
$recetas_json = json_encode($recetas_data);
$productos_json = json_encode($productos_lista);
$combos_json = json_encode($combos); // IMPORTANTE PARA EDITAR

// WIDGETS
$total = count($combos);
$ofertas = 0; $destacados = 0;
foreach($combos as $c) {
    if($c['precio_oferta'] > 0) $ofertas++;
    if($c['es_destacado_web']) $destacados++;
}
?>

<?php include 'includes/layout_header.php'; ?>

<link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden;">
    <i class="bi bi-basket2-fill bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h2 class="font-cancha mb-0 text-white">Mis Packs y Combos</h2>
                <p class="opacity-75 mb-0 text-white small">Gestión de ofertas y promociones</p>
            </div>
            <div>
                <button class="btn btn-light text-dark fw-bold rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalCrear">
                    <i class="bi bi-plus-lg me-2"></i> Nuevo Combo
                </button>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-12 col-md-4"><div class="header-widget"><div><div class="widget-label">Total Combos</div><div class="widget-value text-white"><?php echo $total; ?></div></div><div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-basket"></i></div></div></div>
            <div class="col-12 col-md-4"><div class="header-widget"><div><div class="widget-label">En Oferta</div><div class="widget-value text-white"><?php echo $ofertas; ?></div></div><div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-percent"></i></div></div></div>
            <div class="col-12 col-md-4"><div class="header-widget"><div><div class="widget-label">Destacados Web</div><div class="widget-value text-white"><?php echo $destacados; ?></div></div><div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-star-fill"></i></div></div></div>
        </div>
    </div>
</div>

<div class="container mt-4 pb-5">
    <div class="row g-3">
        <?php foreach($combos as $c): $items = $recetas_data[$c['id']]; ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card-combo h-100 d-flex flex-column">
                <div class="img-combo-box">
                    <img src="<?php echo $c['imagen_url'] ?: 'img/no-image.png'; ?>" loading="lazy">
                    <?php if($c['es_destacado_web']): ?><span class="badge bg-warning text-dark position-absolute top-0 end-0 m-2 shadow-sm"><i class="bi bi-star-fill"></i></span><?php endif; ?>
                </div>
                <div class="p-3 flex-grow-1">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div><h5 class="fw-bold m-0"><?php echo $c['nombre']; ?></h5><small class="text-muted font-monospace"><?php echo $c['codigo_barras']; ?></small></div>
                        <div class="text-end">
                            <?php if($c['precio_oferta']): ?>
                                <div class="old-price">$<?php echo number_format($c['precio'], 0); ?></div><div class="price-tag text-danger">$<?php echo number_format($c['precio_oferta'], 0); ?></div>
                            <?php else: ?>
                                <div class="price-tag">$<?php echo number_format($c['precio'], 0); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <?php if($c['es_ilimitado']): ?><span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">ILIMITADO</span><?php else: ?>
                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle rounded-pill">Vigente: <?php echo date('d/m', strtotime($c['fecha_inicio'])); ?> al <?php echo date('d/m', strtotime($c['fecha_fin'])); ?></span><?php endif; ?>
                    </div>
                    <div class="bg-light p-2 rounded small border">
                        <b class="d-block mb-1 border-bottom pb-1 text-muted">Contenido:</b>
                        <?php if(empty($items)): ?><span class="text-muted fst-italic">Vacío.</span><?php else: foreach($items as $i): ?>
                            <div><b><?php echo $i['cantidad']; ?>x</b> <?php echo $i['descripcion']; ?></div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                <div class="p-3 bg-white d-flex justify-content-end gap-2 border-top">
                    <button class="btn-action bg-warning-subtle text-warning-emphasis" onclick="abrirEditar(<?php echo $c['id']; ?>)"><i class="bi bi-pencil-fill"></i></button>
                    <button class="btn-action bg-danger-subtle text-danger" onclick="borrarPack(<?php echo $c['id']; ?>)"><i class="bi bi-trash-fill"></i></button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="modalCrear" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="crear_combo" value="1">
            <input type="hidden" name="imagen_base64" id="c_base64">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold">Nuevo Combo</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4 row">
                <div class="col-md-4 text-center border-end">
                    <img src="img/no-image.png" id="c_preview" class="img-thumbnail mb-2" style="width:150px; height:150px; object-fit:cover;">
                    <label class="btn btn-sm btn-outline-primary w-100">Imagen <input type="file" class="d-none" onchange="prepararCrop(this, 'c')" accept="image/*"></label>
                </div>
                <div class="col-md-8 row g-3">
                    <div class="col-12"><label class="fw-bold">Nombre</label><input type="text" name="nombre" class="form-control" required></div>
                    <div class="col-6"><label class="fw-bold">Precio ($)</label><input type="number" name="precio" id="c_precio_input" class="form-control" required></div>
                    <div class="col-6"><label class="fw-bold text-danger">Oferta ($)</label><input type="number" name="precio_oferta" class="form-control border-danger"></div>
                    <div class="col-6"><label class="fw-bold">Categoría</label><select name="id_categoria" class="form-select" required><?php foreach($categorias as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo $cat['nombre']; ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><label class="fw-bold">Código</label><input type="text" name="codigo" class="form-control"></div>
                    <div class="col-12 bg-light p-3 rounded">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="es_ilimitado" id="c_ilim" checked onchange="toggleDates('c')"><label class="fw-bold">Ilimitado</label></div>
                        <div class="row g-2 mt-2" id="c_dates" style="display:none"><div class="col-6"><small>Desde</small><input type="date" name="fecha_inicio" class="form-control"></div><div class="col-6"><small>Hasta</small><input type="date" name="fecha_fin" class="form-control"></div></div>
                    </div>
                    <div class="col-12 border-top pt-2">
                        <label class="fw-bold text-success small">PRODUCTOS</label>
                        <div id="lista-items-nuevo"></div>
                        <button type="button" class="btn btn-outline-success btn-sm w-100 mt-2" onclick="agregarFila('nuevo')">+ Producto</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light"><div class="me-auto" id="c_costo_total"></div><button class="btn btn-primary fw-bold">GUARDAR</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalEditar" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="editar_combo" value="1">
            <input type="hidden" name="id_combo" id="e_id">
            <input type="hidden" name="imagen_actual" id="e_actual">
            <input type="hidden" name="imagen_base64" id="e_base64">
            <div class="modal-header bg-warning"><h5 class="modal-title fw-bold">Editar Combo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4 row">
                <div class="col-md-4 text-center border-end">
                    <img src="" id="e_preview" class="img-thumbnail mb-2" style="width:150px; height:150px; object-fit:cover;">
                    <label class="btn btn-sm btn-outline-dark w-100">Cambiar <input type="file" class="d-none" onchange="prepararCrop(this, 'e')" accept="image/*"></label>
                </div>
                <div class="col-md-8 row g-3">
                    <div class="col-12"><label class="fw-bold">Nombre</label><input type="text" name="nombre" id="e_nombre" class="form-control" required></div>
                    <div class="col-6"><label class="fw-bold">Precio ($)</label><input type="number" name="precio" id="e_precio" class="form-control" required></div>
                    <div class="col-6"><label class="fw-bold text-danger">Oferta ($)</label><input type="number" name="precio_oferta" id="e_oferta" class="form-control"></div>
                    <div class="col-6"><label class="fw-bold">Categoría</label><select name="id_categoria" id="e_cat" class="form-select" required><?php foreach($categorias as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo $cat['nombre']; ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><label class="fw-bold">Código</label><input type="text" name="codigo" id="e_codigo" class="form-control"></div>
                    <div class="col-12 bg-light p-3 rounded">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="es_ilimitado" id="e_ilim" onchange="toggleDates('e')"><label class="fw-bold">Ilimitado</label></div>
                        <div class="row g-2 mt-2" id="e_dates"><div class="col-6"><small>Desde</small><input type="date" name="fecha_inicio" id="e_ini" class="form-control"></div><div class="col-6"><small>Hasta</small><input type="date" name="fecha_fin" id="e_fin" class="form-control"></div></div>
                        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="es_destacado" id="e_dest"><label>Destacado Web</label></div>
                    </div>
                    <div class="col-12 border-top pt-2">
                        <label class="fw-bold text-primary small">PRODUCTOS</label>
                        <div id="lista-items-editar"></div>
                        <button type="button" class="btn btn-outline-primary btn-sm w-100 mt-2" onclick="agregarFila('editar')">+ Producto</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light"><div class="me-auto" id="e_costo_total"></div><button class="btn btn-warning fw-bold">GUARDAR</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalCrop" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-body p-0 bg-dark text-center"><img id="imageToCrop" src="" style="max-width: 100%;"></div><div class="modal-footer bg-dark border-0"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary btn-sm" id="btnRecortar">RECORTAR</button></div></div></div></div>


<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    let cropper, prefijoGlobal;
    let modalCrop, modalEditar;

    // Inicialización segura de modales y eventos
    document.addEventListener('DOMContentLoaded', function() {
        if(typeof bootstrap !== 'undefined') {
            modalCrop = new bootstrap.Modal(document.getElementById('modalCrop'));
            modalEditar = new bootstrap.Modal(document.getElementById('modalEditar'));
        }

        // Mover el click del recorte aquí adentro para asegurar que jQuery ($) ya cargó
        $('#btnRecortar').on('click', function() {
            if(!cropper) return;
            let base64 = cropper.getCroppedCanvas({width:600, height:600}).toDataURL('image/png');
            $(`#${prefijoGlobal}_preview`).attr('src', base64);
            $(`#${prefijoGlobal}_base64`).val(base64);
            modalCrop.hide();
        });
    });
    
    // DATOS SEGUROS
    const prodsDB = <?php echo $productos_json; ?>;
    const recetasOriginales = <?php echo $recetas_json; ?>;
    const combosDB = <?php echo $combos_json; ?>;

    function toggleDates(p) {
        let ck = document.getElementById(p+'_ilim');
        document.getElementById(p+'_dates').style.display = ck.checked ? 'none' : 'flex';
    }

    function agregarFila(tipo, idProd='', cant=1) {
        let opts = '<option value="">Seleccionar...</option>';
        prodsDB.forEach(p => {
            opts += `<option value="${p.id}" ${p.id==idProd?'selected':''} data-costo="${p.precio_costo}" data-venta="${p.precio_venta}">${p.descripcion}</option>`;
        });
        $((tipo=='nuevo')?'#lista-items-nuevo':'#lista-items-editar').append(`
            <div class="row g-2 mb-2 item-row align-items-center">
                <div class="col-7"><select name="prod_ids[]" class="form-select form-select-sm sel-p" onchange="recalcular('${tipo}')">${opts}</select></div>
                <div class="col-3"><input type="number" name="prod_cants[]" class="form-control form-control-sm text-center cant-p" value="${cant}" min="1" oninput="recalcular('${tipo}')"></div>
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
        $((tipo=='nuevo')?'#c_costo_total':'#e_costo_total').html(`<small>Costo: $${costo} | Sug: $${(venta*0.85).toFixed(0)}</small>`);
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
    

    window.borrarPack = function(id) {
        Swal.fire({title:'¿Borrar?', icon:'warning', showCancelButton:true, confirmButtonText:'Sí', confirmButtonColor:'#d33'}).then((r)=>{
            if(r.isConfirmed) window.location.href='combos.php?eliminar_id='+id;
        });
    }
</script>
<?php include 'includes/layout_footer.php'; ?>