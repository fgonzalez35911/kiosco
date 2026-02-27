<?php
// producto_formulario.php - V5: CALCULADORA DE RENTABILIDAD EN VIVO (CORREGIDO)
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$id = $_GET['id'] ?? null;
$producto = null;

if ($id) {
    $stmt = $conexion->prepare("SELECT * FROM productos WHERE id = ?");
    $stmt->execute([$id]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);
}

$categorias = $conexion->query("SELECT * FROM categorias WHERE activo=1")->fetchAll(PDO::FETCH_ASSOC);

// OBTENER COLOR SEGURO
$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav'];
    }
} catch (Exception $e) { }

$proveedores = $conexion->query("SELECT * FROM proveedores")->fetchAll(PDO::FETCH_ASSOC);

$taras_lista = [];
try {
    $taras_lista = $conexion->query("SELECT * FROM taras_predefinidas ORDER BY peso ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// Lógica de procesamiento
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Recepción segura de datos. Convertimos vacíos en NULL para evitar que la Base de Datos colapse.
        $codigo = trim($_POST['codigo'] ?? '');
        $codigo = ($codigo === '') ? null : $codigo; // PREVIENE EL CRASH DE CÓDIGO DUPLICADO
        
        $descripcion = trim($_POST['descripcion'] ?? '');
        
        $id_cat = !empty($_POST['id_categoria']) ? $_POST['id_categoria'] : null;
        $id_prov = !empty($_POST['id_proveedor']) ? $_POST['id_proveedor'] : null;
        
        // Usamos isset y comparamos con '' para que acepte el valor 0 sin interpretarlo como "vacío"
        $p_costo = (isset($_POST['precio_costo']) && $_POST['precio_costo'] !== '') ? floatval($_POST['precio_costo']) : 0;
        $p_venta = (isset($_POST['precio_venta']) && $_POST['precio_venta'] !== '') ? floatval($_POST['precio_venta']) : 0;
        $p_oferta = (isset($_POST['precio_oferta']) && $_POST['precio_oferta'] !== '') ? floatval($_POST['precio_oferta']) : null;
        
        $s_actual = (isset($_POST['stock_actual']) && $_POST['stock_actual'] !== '') ? floatval($_POST['stock_actual']) : 0;
        $s_min = (isset($_POST['stock_minimo']) && $_POST['stock_minimo'] !== '') ? floatval($_POST['stock_minimo']) : 0;
        
        $f_venc = !empty($_POST['fecha_vencimiento']) ? $_POST['fecha_vencimiento'] : null;
        $d_alerta = (isset($_POST['dias_alerta']) && $_POST['dias_alerta'] !== '') ? intval($_POST['dias_alerta']) : 30;
        
        $es_vegano = isset($_POST['es_vegano']) ? 1 : 0;
        $es_celiaco = isset($_POST['es_celiaco']) ? 1 : 0;
        $es_destacado = isset($_POST['es_destacado_web']) ? 1 : 0;
        
        $plu = (isset($_POST['plu']) && $_POST['plu'] !== '') ? intval($_POST['plu']) : null;
        $tara_defecto = (isset($_POST['tara_defecto']) && $_POST['tara_defecto'] !== '') ? floatval($_POST['tara_defecto']) : 0;
        $tipo = $_POST['tipo'] ?? 'unitario';

        // Manejo de Imagen
        $imagen_url = !empty($_POST['imagen_actual']) ? $_POST['imagen_actual'] : 'default.jpg';
        
        if (isset($_FILES['imagen_nueva']) && $_FILES['imagen_nueva']['error'] === UPLOAD_ERR_OK) {
            $dir_uploads = 'uploads/';
            if (!file_exists($dir_uploads)) mkdir($dir_uploads, 0777, true);
            $ext = strtolower(pathinfo($_FILES['imagen_nueva']['name'], PATHINFO_EXTENSION));
            $nombre_img = 'prod_' . time() . '_' . rand(1000,9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['imagen_nueva']['tmp_name'], $dir_uploads . $nombre_img)) {
                $imagen_url = $dir_uploads . $nombre_img;
            }
        }

        if ($id) {
            $stmtOld = $conexion->prepare("SELECT * FROM productos WHERE id = ?");
            $stmtOld->execute([$id]);
            $old = $stmtOld->fetch(PDO::FETCH_ASSOC);

            // Actualización
            $sql = "UPDATE productos SET codigo_barras=?, descripcion=?, id_categoria=?, id_proveedor=?, precio_costo=?, precio_venta=?, precio_oferta=?, stock_actual=?, stock_minimo=?, fecha_vencimiento=?, dias_alerta=?, es_vegano=?, es_celiaco=?, es_destacado_web=?, plu=?, tara_defecto=?, tipo=?, imagen_url=? WHERE id=?";
            $stmt = $conexion->prepare($sql);
            $stmt->execute([$codigo, $descripcion, $id_cat, $id_prov, $p_costo, $p_venta, $p_oferta, $s_actual, $s_min, $f_venc, $d_alerta, $es_vegano, $es_celiaco, $es_destacado, $plu, $tara_defecto, $tipo, $imagen_url, $id]);

            // Detección Quirúrgica
            $cambios = [];
            if($old['descripcion'] != $descripcion) $cambios[] = "Nombre: " . $old['descripcion'] . " -> " . $descripcion;
            if(floatval($old['precio_costo']) != floatval($p_costo)) $cambios[] = "Costo: $" . floatval($old['precio_costo']) . " -> $" . floatval($p_costo);
            if(floatval($old['precio_venta']) != floatval($p_venta)) $cambios[] = "Venta: $" . floatval($old['precio_venta']) . " -> $" . floatval($p_venta);
            
            if(floatval($old['stock_actual']) != floatval($s_actual)) {
                $detalles_audit_stock = "Ajuste manual de stock para '" . $descripcion . "': " . floatval($old['stock_actual']) . " -> " . floatval($s_actual);
                $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'AJUSTE_STOCK_MANUAL', ?, NOW())")->execute([$_SESSION['usuario_id'], $detalles_audit_stock]);
            }
            
            if(!empty($cambios)) {
                $detalles_audit_prod = "Producto Editado: " . $descripcion . " | " . implode(" | ", $cambios);
                $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'PRODUCTO_EDITADO', ?, NOW())")->execute([$_SESSION['usuario_id'], $detalles_audit_prod]);
            }
        
        } else {
            // Inserción
            $sql = "INSERT INTO productos (codigo_barras, descripcion, id_categoria, id_proveedor, precio_costo, precio_venta, precio_oferta, stock_actual, stock_minimo, fecha_vencimiento, dias_alerta, es_vegano, es_celiaco, es_destacado_web, plu, tara_defecto, tipo, imagen_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $stmt = $conexion->prepare($sql);
            $stmt->execute([$codigo, $descripcion, $id_cat, $id_prov, $p_costo, $p_venta, $p_oferta, $s_actual, $s_min, $f_venc, $d_alerta, $es_vegano, $es_celiaco, $es_destacado, $plu, $tara_defecto, $tipo, $imagen_url]);
        }
        
        $accion_audit = $id ? 'PRODUCTO_EDITADO' : 'PRODUCTO_NUEVO';
        $detalles_audit = ($id ? "Editado: " : "Creado: ") . $descripcion . " | Costo: $" . $p_costo . " | Venta: $" . $p_venta;
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, ?, ?, NOW())")->execute([$_SESSION['usuario_id'], $accion_audit, $detalles_audit]);
        
        echo "<script>window.location.href='productos.php?msg=ok';</script>"; exit;
    } catch (Exception $e) { die("Error: " . $e->getMessage()); }
}

$imgSrc = !empty($producto['imagen_url']) ? $producto['imagen_url'] : 'default.jpg';

// CÁLCULOS INICIALES PARA PHP
$costo_ini = floatval($producto['precio_costo'] ?? 0);
$venta_ini = floatval($producto['precio_venta'] ?? 0);
$ganancia_ini = $venta_ini - $costo_ini;
$margen_ini = ($costo_ini > 0) ? ($ganancia_ini / $costo_ini) * 100 : 0;
?>

<?php include 'includes/layout_header.php'; ?>

<link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden;">
    <i class="bi bi-calculator bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white"><?php echo $id ? 'Analizando: ' . htmlspecialchars($producto['descripcion']) : 'Analizador de Producto'; ?></h2>
                <p class="opacity-75 mb-0 text-white small">Gestión de rentabilidad y salud del inventario.</p>
            </div>
            <a href="productos.php" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm">
                <i class="bi bi-arrow-left me-2"></i> VOLVER
            </a>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4" onclick="auditoriaProducto('ganancia')" style="cursor: pointer;">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Ganancia x Unidad</div>
                        <div class="widget-value" id="widget_ganancia">$<?php echo number_format($ganancia_ini, 2, ',', '.'); ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-cash-coin"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4" onclick="auditoriaProducto('margen')" style="cursor: pointer;">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Margen de Retorno</div>
                        <div class="widget-value" id="widget_margen"><?php echo number_format($margen_ini, 1); ?>%</div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-graph-up-arrow"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4" onclick="auditoriaProducto('stock')" style="cursor: pointer;">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Estado de Stock</div>
                        <div class="widget-value text-white" id="widget_stock_status">
                            <?php 
                                if(!$id) echo "Nuevo";
                                else if($producto['stock_actual'] <= $producto['stock_minimo']) echo "Reponer";
                                else echo "Saludable";
                            ?>
                        </div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-exclamation-triangle"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5 mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow border-0" style="border-radius: 20px;">
                <div class="card-body p-4 p-md-5">
                    <form method="POST" enctype="multipart/form-data" id="formProducto">
                        <input type="hidden" name="imagen_actual" value="<?php echo $producto['imagen_url'] ?? 'default.jpg'; ?>">
                        <input type="hidden" name="imagen_base64" id="imagen_base64">

                        <div class="row g-4">
                            <div class="col-12 mb-2">
                                <label class="form-label fw-bold text-primary">Tipo de Producto</label>
                                <select name="tipo" class="form-select border-primary" onchange="togglePesables()">
                                    <option value="unitario" <?php echo (isset($producto['tipo']) && $producto['tipo'] == 'unitario') ? 'selected' : ''; ?>>Normal (Unitario)</option>
                                    <option value="pesable" <?php echo (isset($producto['tipo']) && $producto['tipo'] == 'pesable') ? 'selected' : ''; ?>>Pesable (Fiambrería/Verdulería)</option>
                                    <option value="combo" <?php echo (isset($producto['tipo']) && $producto['tipo'] == 'combo') ? 'selected' : ''; ?>>Combo / Pack</option>
                                </select>
                            </div>
                            
                            <div class="col-md-4 text-center border-end">
                                <label class="fw-bold d-block mb-3">Imagen del Producto</label>
                                
                                <div class="position-relative d-inline-block">
                                    <img src="<?php echo $imgSrc; ?>" id="vista_previa_actual" class="img-thumbnail rounded shadow-sm mb-3" style="height: 180px; width: 180px; object-fit: contain; background: white; transition: 0.3s;">
                                    
                                    <div id="loading_ia" class="position-absolute top-50 start-50 translate-middle d-none" style="z-index: 10;">
                                        <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;"></div>
                                        
                                    </div>
                                </div>

                                <div class="d-flex flex-column gap-2 px-3">
                                    
                                    
                                    <label class="btn btn-outline-primary btn-sm fw-bold w-100 mb-2">
                                        <i class="bi bi-upload me-1"></i> Subir Manual
                                        <input type="file" name="imagen_nueva" id="inputImage" accept="image/png, image/jpeg, image/jpg" hidden onchange="document.getElementById('vista_previa_actual').src = window.URL.createObjectURL(this.files[0])">
                                    </label>
                                </div>
                            </div>

                            <div class="col-md-8">
                                <div class="row g-3">
                                    <div class="col-md-6 div-pesables" style="display:none;">
                                        <label class="form-label fw-bold text-success">Código PLU (Balanza)</label>
                                        <input type="number" name="plu" class="form-control border-success" value="<?php echo $producto['plu'] ?? ''; ?>" placeholder="Ej: 150">
                                    </div>
                                    <div class="col-md-6 div-pesables" style="display:none;">
                                        <label class="form-label fw-bold text-success">Envase Frecuente (Tara)</label>
                                        <select class="form-select border-success mb-2" onchange="document.getElementById('tara_defecto').value = this.value">
                                            <option value="0.000">Elegir envase o ingresar manual...</option>
                                            <?php foreach($taras_lista as $t): ?>
                                                <option value="<?php echo $t['peso']; ?>"><?php echo $t['nombre']; ?> (<?php echo $t['peso']; ?> Kg)</option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="number" step="0.001" name="tara_defecto" id="tara_defecto" class="form-control border-success" value="<?php echo $producto['tara_defecto'] ?? '0.000'; ?>" placeholder="O ingresá el peso manual aquí">
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Código de Barras</label>
                                        <input type="text" name="codigo" class="form-control" value="<?php echo $producto['codigo_barras'] ?? ''; ?>" placeholder="Opcional">
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Descripción</label>
                                        <input type="text" name="descripcion" class="form-control" value="<?php echo $producto['descripcion'] ?? ''; ?>" required>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted uppercase">Categoría</label>
                                        <select name="id_categoria" class="form-select rounded-3" required>
                                            <option value="">-- Seleccionar --</option>
                                            <?php foreach($categorias as $cat): ?>
                                                <option value="<?php echo $cat['id']; ?>" <?php echo (isset($producto['id_categoria']) && $producto['id_categoria'] == $cat['id']) ? 'selected' : ''; ?>>
                                                    <?php echo $cat['nombre']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted uppercase">Proveedor</label>
                                        <select name="id_proveedor" class="form-select rounded-3" required>
                                            <option value="">-- Seleccionar --</option>
                                            <?php foreach($proveedores as $prov): ?>
                                                <option value="<?php echo $prov['id']; ?>" <?php echo (isset($producto['id_proveedor']) && $producto['id_proveedor'] == $prov['id']) ? 'selected' : ''; ?>>
                                                    <?php echo $prov['empresa']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted uppercase">Fecha de Vencimiento</label>
                                        <input type="date" name="fecha_vencimiento" class="form-control" value="<?php echo $producto['fecha_vencimiento'] ?? ''; ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted uppercase">Alerta Días Previos</label>
                                        <input type="number" name="dias_alerta" class="form-control" value="<?php echo $producto['dias_alerta'] ?? 30; ?>">
                                    </div>
                                    <div class="col-12">
                                        <div class="p-3 bg-light rounded-4 border d-flex gap-4">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="es_vegano" id="es_vegano" <?php echo (isset($producto['es_vegano']) && $producto['es_vegano']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small fw-bold" for="es_vegano">Apto Vegano</label>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="es_celiaco" id="es_celiaco" <?php echo (isset($producto['es_celiaco']) && $producto['es_celiaco']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small fw-bold" for="es_celiaco">Apto Celíaco</label>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="es_destacado_web" id="es_destacado" <?php echo (isset($producto['es_destacado_web']) && $producto['es_destacado_web']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small fw-bold" for="es_destacado">Destacado Web</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-muted uppercase">Costo ($)</label>
                                        <input type="number" step="0.01" name="precio_costo" id="precio_costo" class="form-control" value="<?php echo isset($producto['precio_costo']) ? (float)$producto['precio_costo'] : ''; ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-muted uppercase">Venta ($)</label>
                                        <input type="number" step="0.01" name="precio_venta" id="precio_venta" class="form-control fw-bold" value="<?php echo isset($producto['precio_venta']) ? (float)$producto['precio_venta'] : ''; ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-success uppercase">Oferta ($)</label>
                                        <input type="number" step="0.01" name="precio_oferta" id="precio_oferta" class="form-control fw-bold border-success" value="<?php echo isset($producto['precio_oferta']) ? (float)$producto['precio_oferta'] : ''; ?>" placeholder="Opcional">
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted uppercase">Stock Actual</label>
                                        <input type="number" step="0.001" name="stock_actual" id="stock_actual" class="form-control" value="<?php echo isset($producto['stock_actual']) ? (float)$producto['stock_actual'] : ''; ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-warning uppercase">Stock Mínimo</label>
                                        <input type="number" step="0.001" name="stock_minimo" id="stock_minimo" class="form-control" value="<?php echo isset($producto['stock_minimo']) ? (float)$producto['stock_minimo'] : 5; ?>" required>
                                    </div>

                                    <div class="col-12 mt-4">
                                        <button type="submit" class="btn btn-primary w-100 py-3 fw-bold shadow-sm">
                                            <i class="bi bi-save2-fill me-2"></i> GUARDAR PRODUCTO
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// LÓGICA DE CÁLCULO EN TIEMPO REAL
function calcularRentabilidad() {
    const costo = parseFloat(document.getElementById('precio_costo').value) || 0;
    const ventaNormal = parseFloat(document.getElementById('precio_venta').value) || 0;
    const ventaOferta = parseFloat(document.getElementById('precio_oferta').value) || 0;
    const stock = parseFloat(document.getElementById('stock_actual').value) || 0;
    const minimo = parseFloat(document.getElementById('stock_minimo').value) || 0;

    const ventaFinal = (ventaOferta > 0) ? ventaOferta : ventaNormal;

    const ganancia = ventaFinal - costo;
    document.getElementById('widget_ganancia').innerText = `$${ganancia.toLocaleString('es-AR', {minimumFractionDigits: 2})}`;

    const margen = (costo > 0) ? (ganancia / costo) * 100 : 0;
    document.getElementById('widget_margen').innerText = `${margen.toFixed(1)}%`;

    const statusBox = document.getElementById('widget_stock_status');
    if(stock <= minimo) {
        statusBox.innerText = "Reponer Ya";
        statusBox.style.color = "#ffc107";
    } else {
        statusBox.innerText = "Saludable";
        statusBox.style.color = "white";
    }
}

document.getElementById('precio_oferta').addEventListener('input', calcularRentabilidad);

function auditoriaProducto(tipo) {
    if(tipo === 'ganancia') {
        Swal.fire({
            title: 'Análisis de Utilidad',
            text: 'Muestra la diferencia bruta entre tu costo y precio de venta. Es lo que realmente te queda por cada unidad vendida.',
            icon: 'info',
            confirmButtonColor: '#102A57'
        });
    } else if(tipo === 'margen') {
        Swal.fire({
            title: 'Margen Comercial',
            text: 'Indica el porcentaje de beneficio sobre el costo. En kioscos, un margen saludable oscila entre el 35% y el 50%.',
            icon: 'success',
            confirmButtonColor: '#102A57'
        });
    } else if(tipo === 'stock') {
        Swal.fire({
            title: 'Control de Inventario',
            text: 'Si el stock es igual o menor al mínimo, el producto aparecerá en los reportes de faltantes.',
            icon: 'warning',
            confirmButtonColor: '#102A57'
        });
    }
}

function togglePesables() {
    const tipo = document.querySelector('select[name="tipo"]').value;
    const divs = document.querySelectorAll('.div-pesables');
    const pluInput = document.querySelector('input[name="plu"]');

    divs.forEach(div => div.style.display = (tipo === 'pesable') ? 'block' : 'none');

    // Hace que el PLU sea obligatorio SOLO si es pesable
    if (tipo === 'pesable') {
        pluInput.setAttribute('required', 'required');
    } else {
        pluInput.removeAttribute('required');
        pluInput.value = ''; // Limpiamos el PLU si cambiaron de opinión
    }
}
document.addEventListener('DOMContentLoaded', togglePesables);



</script>

<?php include 'includes/layout_footer.php'; ?>