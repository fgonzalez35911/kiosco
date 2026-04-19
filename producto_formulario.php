<?php
// producto_formulario.php - VERSIÓN ESTABLE VANGUARD PRO
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
$proveedores = $conexion->query("SELECT * FROM proveedores")->fetchAll(PDO::FETCH_ASSOC);

// Carga de taras reales de tu SQL
$taras_lista = [];
try {
    $taras_lista = $conexion->query("SELECT nombre, peso FROM taras_predefinidas ORDER BY peso ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// --- LÓGICA DE PROCESAMIENTO ORIGINAL (SIN CAMBIOS) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $codigo = trim($_POST['codigo'] ?? '');
        $codigo = ($codigo === '') ? null : $codigo; 
        $descripcion = trim($_POST['descripcion'] ?? '');
        $id_cat = !empty($_POST['id_categoria']) ? $_POST['id_categoria'] : null;
        $id_prov = !empty($_POST['id_proveedor']) ? $_POST['id_proveedor'] : null;
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

        $imagen_url = !empty($_POST['imagen_actual']) ? $_POST['imagen_actual'] : 'default.jpg';
        if (isset($_FILES['imagen_nueva']) && $_FILES['imagen_nueva']['error'] === UPLOAD_ERR_OK) {
            $dir_uploads = 'uploads/';
            if (!file_exists($dir_uploads)) mkdir($dir_uploads, 0777, true);
            $ext = strtolower(pathinfo($_FILES['imagen_nueva']['name'], PATHINFO_EXTENSION));
            $nombre_img = 'prod_' . time() . '_' . rand(1000,9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['imagen_nueva']['tmp_name'], $dir_uploads . $nombre_img)) { $imagen_url = $dir_uploads . $nombre_img; }
        }

        if ($id) {
            $sql = "UPDATE productos SET codigo_barras=?, descripcion=?, id_categoria=?, id_proveedor=?, precio_costo=?, precio_venta=?, precio_oferta=?, stock_actual=?, stock_minimo=?, fecha_vencimiento=?, dias_alerta=?, es_vegano=?, es_celiaco=?, es_destacado_web=?, plu=?, tara_defecto=?, tipo=?, imagen_url=? WHERE id=?";
            $conexion->prepare($sql)->execute([$codigo, $descripcion, $id_cat, $id_prov, $p_costo, $p_venta, $p_oferta, $s_actual, $s_min, $f_venc, $d_alerta, $es_vegano, $es_celiaco, $es_destacado, $plu, $tara_defecto, $tipo, $imagen_url, $id]);
        } else {
            $sql = "INSERT INTO productos (codigo_barras, descripcion, id_categoria, id_proveedor, precio_costo, precio_venta, precio_oferta, stock_actual, stock_minimo, fecha_vencimiento, dias_alerta, es_vegano, es_celiaco, es_destacado_web, plu, tara_defecto, tipo, imagen_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $conexion->prepare($sql)->execute([$codigo, $descripcion, $id_cat, $id_prov, $p_costo, $p_venta, $p_oferta, $s_actual, $s_min, $f_venc, $d_alerta, $es_vegano, $es_celiaco, $es_destacado, $plu, $tara_defecto, $tipo, $imagen_url]);
        }
        echo "<script>window.location.href='productos.php?msg=ok';</script>"; exit;
    } catch (Exception $e) { die("Error: " . $e->getMessage()); }
}

// --- DATOS DEL SISTEMA ---
$color_sistema = '#102A57';
try {
    $resC = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if($resC) $color_sistema = $resC['color_barra_nav'];
} catch(Exception $e) {}

$costo_ini = floatval($producto['precio_costo'] ?? 0);
$venta_ini = floatval($producto['precio_venta'] ?? 0);
$ganancia_ini = $venta_ini - $costo_ini;
$margen_ini = ($costo_ini > 0) ? ($ganancia_ini / $costo_ini) * 100 : 0;

// SOLUCIÓN: Definimos la ruta de la imagen real para la previsualización
$imgSrc = "https://ui-avatars.com/api/?name=Producto&background=f4f6f9&color=adb5bd&size=250&font-size=0.33"; // Placeholder elegante por defecto
if (!empty($producto['imagen_url']) && $producto['imagen_url'] !== 'default.jpg' && file_exists($producto['imagen_url'])) {
    $imgSrc = $producto['imagen_url'];
}

$titulo = $id ? "Configurar Producto" : "Nuevo Producto";
$subtitulo = $id ? htmlspecialchars($producto['descripcion']) : "Configuración de precios, stock y balanza.";
$icono_bg = "bi-box-seam-fill";

$botones = [['texto' => 'VOLVER', 'link' => 'productos.php', 'icono' => 'bi-arrow-left', 'class' => 'btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm']];

$widgets = [
    ['label' => 'Ganancia x Unidad', 'valor' => '<span id="widget_ganancia">$'.number_format($ganancia_ini, 2, ',', '.').'</span>', 'icono' => 'bi-cash-coin', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Margen ROI', 'valor' => '<span id="widget_margen">'.number_format($margen_ini, 1).'%</span>', 'icono' => 'bi-graph-up-arrow', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Estado Stock', 'valor' => '<span id="widget_stock_status">'.($id ? ($producto['stock_actual'] <= $producto['stock_minimo'] ? 'REPONER' : 'OK') : 'NUEVO').'</span>', 'icono' => 'bi-database-check', 'border' => 'border-info', 'icon_bg' => 'bg-info bg-opacity-20']
];

include 'includes/layout_header.php'; 
include 'includes/componente_banner.php'; 
?>

<style>
    .tipo-btn { cursor: pointer; transition: all 0.2s; border: 2px solid; border-radius: 12px; padding: 12px; flex: 1; text-align: center; }
    .tipo-btn.active { transform: scale(1.05); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    .label-pro { font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: #6c757d; margin-bottom: 5px; display: block; }
    
    /* Mejoras de interfaz para los switches */
    .switch-box { background: #fff; border: 1px solid #e9ecef; border-radius: 10px; padding: 12px 15px; margin-bottom: 10px; transition: 0.2s ease; display: flex; align-items: center; justify-content: space-between; }
    .switch-box:hover { border-color: #dee2e6; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
    .switch-box .form-check { margin-bottom: 0 !important; padding-left: 0; display: flex; align-items: center; width: 100%; justify-content: space-between; }
    .switch-box .form-check-input { margin-left: 10px !important; margin-top: 0; float: right; transform: scale(1.2); cursor: pointer; }
    .switch-box .form-check-label { font-weight: 700; color: #495057; cursor: pointer; margin-bottom: 0; }
</style>

<div class="container-fluid container-md mt-n4 px-3 mb-5" style="position: relative; z-index: 20;">
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="imagen_actual" value="<?php echo $producto['imagen_url'] ?? 'default.jpg'; ?>">
        <input type="hidden" name="tipo" id="tipo_final" value="<?php echo $producto['tipo'] ?? 'unitario'; ?>">

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-body p-3 bg-light rounded-4">
                        <div class="d-flex gap-3">
                            <div id="sel-uni" class="tipo-btn <?php echo (!isset($producto['tipo']) || $producto['tipo'] == 'unitario') ? 'active border-primary text-primary bg-white' : 'border-light-subtle text-muted'; ?>" onclick="cambiarTipo('unitario')">
                                <i class="bi bi-box-seam fs-4 d-block mb-1"></i>Venta x Unidad
                            </div>
                            <div id="sel-pes" class="tipo-btn <?php echo (isset($producto['tipo']) && $producto['tipo'] == 'pesable') ? 'active border-success text-success bg-white' : 'border-light-subtle text-muted'; ?>" onclick="cambiarTipo('pesable')">
                                <i class="bi bi-speedometer2 fs-4 d-block mb-1"></i>Venta x Kilo (Balanza)
                            </div>
                        </div>
                    </div>
                </div>

                <div id="box-balanza" class="card border-0 shadow-sm rounded-4 mb-4 border-start border-success border-5" style="display:none;">
                    <div class="card-header bg-white py-3 border-0">
                        <h6 class="fw-bold mb-0 text-success text-uppercase"><i class="bi bi-cpu-fill me-2"></i>Configuración de Balanza</h6>
                    </div>
                    <div class="card-body p-4 pt-0">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="label-pro">Código PLU Balanza</label>
                                <input type="number" name="plu" id="plu_val" class="form-control form-control-lg fw-bold border-success text-success" value="<?php echo $producto['plu'] ?? ''; ?>" placeholder="Ej: 105">
                            </div>
                            <div class="col-md-6">
                                <label class="label-pro">Tara x Defecto (Kg)</label>
                                <div class="input-group">
                                    <select class="form-select border-success fw-bold" onchange="document.getElementById('tara_val').value = this.value">
                                        <option value="0.000">Seleccionar Envase...</option>
                                        <?php foreach($taras_lista as $t): ?>
                                            <option value="<?php echo $t['peso']; ?>"><?php echo $t['nombre']; ?> (<?php echo $t['peso']; ?> Kg)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" step="0.001" name="tara_defecto" id="tara_val" class="form-control border-success fw-bold" value="<?php echo $producto['tara_defecto'] ?? '0.000'; ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="label-pro">Nombre del Producto</label>
                                <input type="text" name="descripcion" class="form-control form-control-lg fw-bold border-light-subtle" value="<?php echo htmlspecialchars($producto['descripcion'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="label-pro">Categoría</label>
                                <select name="id_categoria" class="form-select border-light-subtle fw-bold" required>
                                    <option value="">-- Seleccionar --</option>
                                    <?php foreach($categorias as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>" <?php echo (isset($producto['id_categoria']) && $producto['id_categoria'] == $cat['id']) ? 'selected' : ''; ?>><?php echo $cat['nombre']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div id="col-bar" class="col-md-6">
                                <label class="label-pro">Código de Barras</label>
                                <input type="text" name="codigo" class="form-control border-light-subtle fw-bold" value="<?php echo $producto['codigo_barras'] ?? ''; ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white py-3 border-0"><h6 class="fw-bold mb-0 text-primary text-uppercase"><i class="bi bi-currency-dollar me-2"></i>Costos y Precios</h6></div>
                    <div class="card-body p-4 pt-0">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="label-pro" id="lbl-costo">Costo ($)</label>
                                <input type="number" step="0.01" name="precio_costo" id="precio_costo" class="form-control form-control-lg fw-bold bg-light" value="<?php echo isset($producto['precio_costo']) ? (float)$producto['precio_costo'] : ''; ?>" required oninput="calc()">
                            </div>
                            <div class="col-md-4">
                                <label class="label-pro" id="lbl-venta">Venta ($)</label>
                                <input type="number" step="0.01" name="precio_venta" id="precio_venta" class="form-control form-control-lg fw-bold text-primary" value="<?php echo isset($producto['precio_venta']) ? (float)$producto['precio_venta'] : ''; ?>" required oninput="calc()">
                            </div>
                            <div class="col-md-4">
                                <label class="label-pro text-success" id="lbl-oferta">Oferta ($)</label>
                                <input type="number" step="0.01" name="precio_oferta" id="precio_oferta" class="form-control form-control-lg fw-bold border-success text-success shadow-none" value="<?php echo isset($producto['precio_oferta']) ? (float)$producto['precio_oferta'] : ''; ?>" placeholder="Opcional" oninput="calc()">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 mb-4 text-center p-4 bg-light">
                    <label class="label-pro mb-3 text-dark">Foto del Producto</label>
                    <div class="bg-white rounded-4 shadow-sm mb-3 mx-auto overflow-hidden d-flex align-items-center justify-content-center border" style="height: 180px; width: 100%; max-width: 220px;">
                        <img src="<?php echo $imgSrc; ?>" id="preview" style="max-height: 100%; max-width: 100%; object-fit: contain;">
                    </div>
                    <label class="btn btn-primary fw-bold w-100 rounded-pill shadow-sm"><i class="bi bi-camera me-1"></i> CAMBIAR FOTO<input type="file" name="imagen_nueva" accept="image/*" hidden onchange="updPreview(this)"></label>
                </div>

                <div class="card border-0 shadow-sm rounded-4 mb-4 p-4">
                    <div class="mb-3">
                        <label class="label-pro" id="lbl-stk">Stock Actual</label>
                        <input type="number" step="0.001" name="stock_actual" id="stock_actual" class="form-control fw-bold" value="<?php echo isset($producto['stock_actual']) ? (float)$producto['stock_actual'] : ''; ?>" required oninput="calc()">
                        <div id="hint-stk" class="alert alert-warning py-1 px-2 mt-2 mb-0" style="display:none; font-size: 0.65rem; border:none; border-left: 3px solid #ffc107;">
                            <i class="bi bi-info-circle-fill"></i> Ejemplo: Para 10 kg y 500 gr, ingresá <strong>10.500</strong>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="label-pro" id="lbl-min">Stock Mínimo Crítico</label>
                        <input type="number" step="0.001" name="stock_minimo" id="stock_minimo" class="form-control fw-bold border-warning text-warning" value="<?php echo isset($producto['stock_minimo']) ? (float)$producto['stock_minimo'] : 5; ?>" required oninput="calc()">
                    </div>
                    <hr class="text-muted">
                    <div class="mb-4">
                        <label class="label-pro">Proveedor Asociado</label>
                        <select name="id_proveedor" class="form-select fw-bold border-light-subtle" required>
                            <option value="">-- Seleccionar --</option>
                            <?php foreach($proveedores as $p): ?><option value="<?php echo $p['id']; ?>" <?php echo (isset($producto['id_proveedor']) && $producto['id_proveedor'] == $p['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['empresa']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="bg-light p-2 rounded-4">
                        <div class="switch-box">
                            <div class="form-check">
                                <label class="form-check-label" for="switchDestacado"><i class="bi bi-star-fill text-warning me-2"></i>Destacar en Tienda Web</label>
                                <input class="form-check-input bg-primary border-primary" type="checkbox" id="switchDestacado" name="es_destacado_web" <?php if(!empty($producto['es_destacado_web'])) echo 'checked'; ?>>
                            </div>
                        </div>
                        <div class="switch-box">
                            <div class="form-check">
                                <label class="form-check-label" for="switchVegano"><i class="bi bi-leaf-fill text-success me-2"></i>Apto Vegano</label>
                                <input class="form-check-input bg-success border-success" type="checkbox" id="switchVegano" name="es_vegano" <?php if(!empty($producto['es_vegano'])) echo 'checked'; ?>>
                            </div>
                        </div>
                        <div class="switch-box mb-0">
                            <div class="form-check">
                                <label class="form-check-label" for="switchCeliaco"><i class="bi bi-info-circle-fill text-info me-2"></i>Sin TACC</label>
                                <input class="form-check-input bg-info border-info" type="checkbox" id="switchCeliaco" name="es_celiaco" <?php if(!empty($producto['es_celiaco'])) echo 'checked'; ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-success btn-lg w-100 py-3 rounded-4 fw-bold shadow-lg border-0" style="background: linear-gradient(135deg, #198754 0%, #157347 100%); letter-spacing: 1px;"><i class="bi bi-save2-fill me-2"></i> GUARDAR PRODUCTO</button>
            </div>
        </div>
    </form>
</div>

<script>
    function cambiarTipo(tipo) {
        const btnU = document.getElementById('sel-uni');
        const btnP = document.getElementById('sel-pes');
        const boxB = document.getElementById('box-balanza');
        const inPlu = document.getElementById('plu_val');
        const inBar = document.getElementById('col-bar');
        const hint = document.getElementById('hint-stk');

        const lCos = document.getElementById('lbl-costo');
        const lVen = document.getElementById('lbl-venta');
        const lOfe = document.getElementById('lbl-oferta');
        const lStk = document.getElementById('lbl-stk');
        const lMin = document.getElementById('lbl-min');

        document.getElementById('tipo_final').value = tipo;

        if(tipo === 'unitario') {
            btnU.className = 'tipo-btn active border-primary text-primary bg-white';
            btnP.className = 'tipo-btn border-light-subtle text-muted';
            boxB.style.display = 'none';
            inPlu.removeAttribute('required');
            inBar.style.display = 'block';
            hint.style.display = 'none';
            lCos.innerText = "Costo x Unidad ($)";
            lVen.innerText = "Precio Venta x Unidad ($)";
            lOfe.innerText = "Precio Oferta x Unidad ($)";
            lStk.innerText = "Unidades en Stock";
            lMin.innerText = "Mínimo Crítico (Uni)";
        } else {
            btnP.className = 'tipo-btn active border-success text-success bg-white';
            btnU.className = 'tipo-btn border-light-subtle text-muted';
            boxB.style.display = 'block';
            inPlu.setAttribute('required', 'required');
            inBar.style.display = 'none';
            hint.style.display = 'block';
            lCos.innerText = "Costo por KILO ($)";
            lVen.innerText = "Precio Venta por KILO ($)";
            lOfe.innerText = "Precio Oferta por KILO ($)";
            lStk.innerText = "Kilos actuales en Stock (KG)";
            lMin.innerText = "Mínimo Crítico (KG)";
        }
        calc();
    }

    function updPreview(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = e => document.getElementById('preview').src = e.target.result;
            reader.readAsDataURL(input.files[0]);
        }
    }

    function calc() {
        const cost = parseFloat(document.getElementById('precio_costo').value) || 0;
        const vent = parseFloat(document.getElementById('precio_venta').value) || 0;
        const ofer = parseFloat(document.getElementById('precio_oferta').value) || 0;
        const stock = parseFloat(document.getElementById('stock_actual').value) || 0;
        const minim = parseFloat(document.getElementById('stock_minimo').value) || 0;

        const realV = (ofer > 0) ? ofer : vent;
        const profit = realV - cost;
        const margin = (cost > 0) ? (profit / cost) * 100 : 0;

        // Actualiza los widgets dinámicos del banner
        let spanGanancia = document.getElementById('widget_ganancia');
        let spanMargen = document.getElementById('widget_margen');
        if(spanGanancia) spanGanancia.innerText = `$${profit.toLocaleString('es-AR', {minimumFractionDigits: 2})}`;
        if(spanMargen) spanMargen.innerText = `${margin.toFixed(1)}%`;

        const stat = document.getElementById('widget_stock_status');
        if(stat) {
            if(stock <= minim) { stat.innerText = "REPONER"; stat.className = "text-warning"; } 
            else { stat.innerText = "OK"; stat.className = "text-white"; }
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        cambiarTipo('<?php echo $producto['tipo'] ?? 'unitario'; ?>');
        calc(); // Ejecuta el cálculo inicial al cargar la página
    });
</script>

<?php include 'includes/layout_footer.php'; ?>