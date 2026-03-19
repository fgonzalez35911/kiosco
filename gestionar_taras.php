<?php
// gestionar_taras.php - VERSIÓN PREMIUM VANGUARD PRO (ESTILO GASTOS)
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);

if (!$es_admin && !in_array('ver_productos', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

// PROCESAR PETICIONES (CREAR, EDITAR, BORRAR)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    $nombre = trim($_POST['nombre'] ?? '');
    $peso = floatval($_POST['peso'] ?? 0);
    $costo = floatval($_POST['precio_costo'] ?? 0);
    $venta = floatval($_POST['precio_venta'] ?? 0);
    $cobrar = isset($_POST['cobrar']) ? 1 : 0;
    
    // Subir imagen si hay
    $imagen_url = '';
    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] == 0) {
        if (!is_dir('uploads')) mkdir('uploads', 0777, true);
        $ext = pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION);
        $ruta = 'uploads/tara_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['imagen']['tmp_name'], $ruta)) {
            $imagen_url = $ruta;
        }
    }

    if ($accion === 'crear' && !empty($nombre) && $peso >= 0) {
        $stmt = $conexion->prepare("INSERT INTO taras_predefinidas (nombre, peso, precio_costo, precio_venta, cobrar, imagen_url, tipo_negocio) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$nombre, $peso, $costo, $venta, $cobrar, $imagen_url, $rubro_actual]);
        header("Location: gestionar_taras.php?msg=creado"); exit;
    }
    
    if ($accion === 'editar') {
        $id = intval($_POST['id']);
        if ($id > 0 && !empty($nombre) && $peso >= 0) {
            if ($imagen_url !== '') {
                $stmt = $conexion->prepare("UPDATE taras_predefinidas SET nombre = ?, peso = ?, precio_costo = ?, precio_venta = ?, cobrar = ?, imagen_url = ? WHERE id = ?");
                $stmt->execute([$nombre, $peso, $costo, $venta, $cobrar, $imagen_url, $id]);
            } else {
                $stmt = $conexion->prepare("UPDATE taras_predefinidas SET nombre = ?, peso = ?, precio_costo = ?, precio_venta = ?, cobrar = ? WHERE id = ?");
                $stmt->execute([$nombre, $peso, $costo, $venta, $cobrar, $id]);
            }
        }
        header("Location: gestionar_taras.php?msg=editado"); exit;
    }
}

if (isset($_GET['borrar'])) {
    $id = intval($_GET['borrar']);
    if ($id > 0) {
        $conexion->prepare("DELETE FROM taras_predefinidas WHERE id = ?")->execute([$id]);
    }
    header("Location: gestionar_taras.php?msg=borrado"); exit;
}

// OBTENER FILTROS
$buscar = trim($_GET['buscar'] ?? '');
$f_cobro = $_GET['estado_cobro'] ?? '';

// OBTENER LISTA DE TARAS
$sql = "SELECT * FROM taras_predefinidas WHERE (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)";
$params = [];

if (!empty($buscar)) {
    $sql .= " AND (nombre LIKE ?)";
    $params[] = "%$buscar%";
}

if ($f_cobro !== '') {
    $sql .= " AND cobrar = ?";
    $params[] = intval($f_cobro);
}

$sql .= " ORDER BY peso ASC";
$stmt = $conexion->prepare($sql);
$stmt->execute($params);
$taras = $stmt->fetchAll(PDO::FETCH_ASSOC);

// CONTAR COBRABLES (Global, sin filtros, para el widget)
$todas_taras = $conexion->query("SELECT cobrar FROM taras_predefinidas WHERE (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)")->fetchAll(PDO::FETCH_ASSOC);
$cobrables = 0;
foreach($todas_taras as $t) { if($t['cobrar'] == 1) $cobrables++; }

$conf_color_sis = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf_color_sis['color_barra_nav'] ?? '#102A57';
require_once 'includes/layout_header.php';

// --- DEFINICIÓN DEL BANNER DINÁMICO ESTANDARIZADO ---
$titulo = "Administrador de Envases";
$subtitulo = "Gestión de pesos y recipientes para la balanza.";
$icono_bg = "bi-box-seam-fill";

$query_filtros = !empty($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : "";
$botones = [
    ['texto' => 'NUEVO ENVASE', 'icono' => 'bi-plus-circle-fill', 'class' => 'btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm me-2', 'link' => 'javascript:abrirModalCrear()'],
    ['texto' => 'REPORTE PDF', 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-4 shadow-sm', 'link' => "reporte_taras.php?$query_filtros", 'target' => '_blank']
];

$widgets = [
    ['label' => 'Total Envases', 'valor' => count($todas_taras), 'icono' => 'bi-box', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Cobrables', 'valor' => $cobrables, 'icono' => 'bi-currency-dollar', 'border' => 'border-warning', 'icon_bg' => 'bg-warning bg-opacity-20'],
    ['label' => 'Estado Balanza', 'valor' => 'Lista', 'icono' => 'bi-speedometer2', 'border' => 'border-success', 'icon_bg' => 'bg-success bg-opacity-20']
];

include 'includes/componente_banner.php'; 
?>

<div class="container-fluid container-md mt-n4 px-2 px-md-3" style="position: relative; z-index: 20;">
    
    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border: none !important; border-left: 5px solid #ff9800 !important;">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="row g-2 align-items-center mb-0">
                <input type="hidden" name="estado_cobro" value="<?php echo htmlspecialchars($f_cobro); ?>">
                
                <div class="col-md-8 col-12 text-center text-md-start">
                    <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador Rápido</h6>
                    <p class="small mb-0 opacity-75 d-none d-md-block">Busca un recipiente o envase por su nombre.</p>
                </div>
                <div class="col-md-4 col-12 text-end mt-2 mt-md-0">
                    <div class="input-group input-group-sm">
                        <input type="text" name="buscar" class="form-control border-0 fw-bold shadow-none" placeholder="Ej: Bandeja Mediana..." value="<?php echo htmlspecialchars($buscar); ?>">
                        <button class="btn btn-dark px-3 shadow-none border-0" type="submit" style="border: none !important;"><i class="bi bi-arrow-right-circle-fill"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <style>
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

    <div class="filter-bar sticky-desktop rounded-4 p-3">
        <div class="d-flex gap-2 w-100 d-md-none mb-2">
            <button class="btn btn-primary fw-bold btn-toggle-filters flex-fill m-0 shadow-sm" type="button" onclick="document.getElementById('wrapperFiltros').classList.toggle('show');">
                <i class="bi bi-funnel-fill"></i> MOSTRAR FILTROS
            </button>
        </div>
        <form method="GET" id="wrapperFiltros" class="filter-content-wrapper mt-md-0 mt-2">
            <input type="hidden" name="buscar" value="<?php echo htmlspecialchars($buscar); ?>">
            <select name="estado_cobro" class="filter-select flex-grow-1" onchange="this.form.submit()">
                <option value="">📦 Todos los envases</option>
                <option value="1" <?php echo ($f_cobro == '1') ? 'selected' : ''; ?>>💲 Solo los que se cobran</option>
                <option value="0" <?php echo ($f_cobro == '0') ? 'selected' : ''; ?>>🆓 Solo los gratuitos</option>
            </select>
            <a href="gestionar_taras.php" class="btn btn-light fw-bold rounded-3 border px-3 text-muted" style="height: 42px; display: flex; align-items: center;">
                <i class="bi bi-trash3-fill"></i>
            </a>
        </form>
    </div>

    <div class="alert py-2 small mb-4 text-center fw-bold border-0 shadow-sm rounded-3" style="background-color: #e9f2ff; color: #102A57;">
        <i class="bi bi-hand-index-thumb-fill me-1"></i> Toca o haz clic en un envase para ver la ficha técnica y opciones
    </div>

    <div class="row g-4 pb-5" id="gridProductos">
        <?php if(count($taras) > 0): ?>
            <?php foreach($taras as $t): 
                $img = !empty($t['imagen_url']) ? $t['imagen_url'] : '';
                $se_cobra = isset($t['cobrar']) && $t['cobrar'] == 1;
                $jsonTara = htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8');
            ?>
            <div class="col-12 col-md-6 col-xl-3 item-grid">
                <div class="card-prod h-100 shadow-sm" onclick="verFichaTara(<?php echo $jsonTara; ?>)" style="cursor:pointer; position:relative; overflow:hidden; border-radius: 12px; background: #fff;">
                    <?php if($se_cobra): ?>
                        <div class="badge bg-danger position-absolute top-0 end-0 m-2 shadow-sm" style="z-index: 2;"><i class="bi bi-currency-dollar"></i> SE COBRA</div>
                    <?php endif; ?>
                    
                    <div class="img-area" style="height: 160px; background: #f8f9fa; display:flex; align-items:center; justify-content:center; border-bottom: 1px solid #eee;">
                        <?php if($img): ?>
                            <img src="<?php echo $img; ?>" style="max-height:100%; max-width:100%; object-fit:contain; padding:10px;">
                        <?php else: ?>
                            <i class="bi bi-box-seam text-muted opacity-25" style="font-size: 5rem;"></i>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card-body p-3">
                        <div class="cat-label text-uppercase text-muted small fw-bold">ENVASE / TARA</div>
                        <div class="prod-title text-truncate-2 mt-1 fw-bold text-dark" style="font-size: 1.15rem;"><?php echo htmlspecialchars($t['nombre']); ?></div>
                        
                        <div class="d-flex justify-content-between align-items-end mt-3 border-top pt-2">
                            <div>
                                <span class="badge bg-primary fs-6"><?php echo number_format($t['peso'], 3, '.', ''); ?> Kg</span>
                                <div class="text-muted small mt-1 fw-bold"><?php echo intval($t['peso'] * 1000); ?> gramos</div>
                            </div>
                            <?php if($se_cobra): ?>
                                <div class="text-success fw-bold fs-5">$<?php echo number_format($t['precio_venta'] ?? 0, 2, ',', '.'); ?></div>
                            <?php else: ?>
                                <div class="text-muted small fw-bold">Gratis</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="col-12 text-center py-5">
                <i class="bi bi-inbox text-muted opacity-25" style="font-size: 5rem;"></i>
                <h5 class="mt-3 text-muted">No se encontraron envases con esos filtros.</h5>
            </div>
        <?php endif; ?>
    </div>

    <div id="vistaListaGenerica" class="d-none mt-2 pb-5">
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="ps-4">ENVASE</th>
                            <th class="text-center">PESO (Kg)</th>
                            <th class="text-center d-none d-md-table-cell">COBRO</th>
                            <th class="text-end pe-4">PRECIO</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($taras as $t): 
                            $se_cobra = isset($t['cobrar']) && $t['cobrar'] == 1;
                            $jsonTara = htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr style="cursor: pointer;" onclick="verFichaTara(<?php echo $jsonTara; ?>)">
                            <td class="ps-4 fw-bold text-dark py-3">
                                <i class="bi bi-box-seam me-2 text-primary opacity-75"></i> <?php echo htmlspecialchars($t['nombre']); ?>
                            </td>
                            <td class="text-center fw-bold text-primary"><?php echo number_format($t['peso'], 3, '.', ''); ?></td>
                            <td class="text-center d-none d-md-table-cell">
                                <?php if($se_cobra): ?>
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25">SE COBRA</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">GRATIS</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4 fw-bold">
                                <?php if($se_cobra): ?>
                                    <span class="text-success">$<?php echo number_format($t['precio_venta'] ?? 0, 2, ',', '.'); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalTara" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold" id="tituloModal"><i class="bi bi-box-seam"></i> Nuevo Envase</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form action="gestionar_taras.php" method="POST" id="formTara" enctype="multipart/form-data">
                    <input type="hidden" name="accion" id="accionForm" value="crear">
                    <input type="hidden" name="id" id="idTara" value="">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted small">Nombre del Envase (Ej: Bandeja Mediana)</label>
                        <input type="text" class="form-control fw-bold" name="nombre" id="nombreTara" required>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold text-muted small">Peso (Kg)</label>
                            <input type="number" step="0.001" min="0" class="form-control text-center fw-bold text-success" name="peso" id="pesoTara" placeholder="0.000" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold text-muted small">Foto (Opcional)</label>
                            <input type="file" class="form-control form-control-sm mt-1" name="imagen" accept="image/*">
                        </div>
                    </div>

                    <div class="form-check form-switch mb-3 bg-light p-2 rounded border">
                        <input class="form-check-input ms-1 me-2" type="checkbox" name="cobrar" id="cobrarTara" value="1" onchange="document.getElementById('divPrecios').style.display = this.checked ? 'flex' : 'none';">
                        <label class="form-check-label fw-bold text-danger pt-1" for="cobrarTara" style="cursor:pointer;">¿Se cobra este envase al cliente?</label>
                    </div>

                    <div class="row mb-4" id="divPrecios" style="display:none;">
                        <div class="col-6">
                            <label class="form-label fw-bold text-muted small">Costo $</label>
                            <input type="number" step="0.01" min="0" class="form-control text-center" name="precio_costo" id="costoTara" placeholder="0.00">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold text-muted small">Precio Venta $</label>
                            <input type="number" step="0.01" min="0" class="form-control text-center fw-bold" name="precio_venta" id="ventaTara" placeholder="0.00">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-success w-100 fw-bold py-2 shadow-sm"><i class="bi bi-check-lg"></i> GUARDAR ENVASE</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function abrirModalCrear() {
    document.getElementById('tituloModal').innerHTML = '<i class="bi bi-box-seam"></i> Nuevo Envase';
    document.getElementById('accionForm').value = 'crear';
    document.getElementById('idTara').value = '';
    document.getElementById('nombreTara').value = '';
    document.getElementById('pesoTara').value = '';
    document.getElementById('costoTara').value = '';
    document.getElementById('ventaTara').value = '';
    document.getElementById('cobrarTara').checked = false;
    document.getElementById('divPrecios').style.display = 'none';
    
    const modalObj = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTara'));
    modalObj.show();
    setTimeout(() => document.getElementById('nombreTara').focus(), 500);
}

function verFichaTara(t) {
    let gramos = Math.round(parseFloat(t.peso) * 1000);
    let imgHtml = t.imagen_url ? `<img src="${t.imagen_url}" style="height: 100px; object-fit:contain; border-radius:8px;">` : `<i class="bi bi-box-seam" style="font-size: 3rem; color: #102A57;"></i>`;
    let cobroHtml = (t.cobrar == 1) ? `<div style="margin-top: 8px; color:#dc3545; font-weight:bold;">SE COBRA: $${parseFloat(t.precio_venta).toFixed(2)}</div>` : `<div style="margin-top: 8px; color:#28a745; font-weight:bold;">ENVASE GRATIS</div>`;

    const html = `
        <div style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
            <div style="text-align: center; margin-bottom: 15px;">
                ${imgHtml}
                <h5 style="font-weight: 900; color: #102A57; letter-spacing: 1px; margin-top:10px;">FICHA TÉCNICA ENVASE</h5>
                <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">ID #${t.id}</span>
            </div>
            <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                <div style="margin-bottom: 4px;"><strong>NOMBRE:</strong> ${t.nombre.toUpperCase()}</div>
                <div style="margin-bottom: 4px;"><strong>TIPO:</strong> RECIPIENTE / TARA</div>
                ${cobroHtml}
                ${t.cobrar == 1 ? `<div style="color:#6c757d; font-size:0.85em;">Costo interno: $${parseFloat(t.precio_costo).toFixed(2)}</div>` : ''}
            </div>
            <div style="background: #102A5710; border-left: 4px solid #102A57; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 10px;">
                <span style="font-size: 1.1em; font-weight:800;">PESO KG:</span>
                <span style="font-size: 1.15em; font-weight:900; color: #102A57;">${parseFloat(t.peso).toFixed(3)} Kg</span>
            </div>
            <div style="background: #e9ecef; border-left: 4px solid #6c757d; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <span style="font-size: 1.1em; font-weight:800;">GRAMOS:</span>
                <span style="font-size: 1.15em; font-weight:900; color: #495057;">${gramos} gr</span>
            </div>
        </div>
        <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3">
            <button class="btn btn-primary fw-bold flex-fill" onclick="prepararEdicion(${t.id}, '${t.nombre.replace(/'/g, "\\'")}', '${t.peso}', '${t.precio_costo}', '${t.precio_venta}', ${t.cobrar})"><i class="bi bi-pencil"></i> EDITAR</button>
            <button class="btn btn-danger fw-bold flex-fill" onclick="confirmarBorrar(${t.id})"><i class="bi bi-trash"></i> BORRAR</button>
        </div>`;
    Swal.fire({ html: html, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });
}

function prepararEdicion(id, nombre, peso, costo, venta, cobrar) {
    Swal.close();
    setTimeout(() => { 
        abrirModalEditar(id, nombre, peso, costo, venta, cobrar);
    }, 400); 
}

function abrirModalEditar(id, nombre, peso, costo, venta, cobrar) {
    document.getElementById('tituloModal').innerHTML = '<i class="bi bi-pencil-square"></i> Editar Envase';
    document.getElementById('accionForm').value = 'editar';
    document.getElementById('idTara').value = id;
    document.getElementById('nombreTara').value = nombre;
    document.getElementById('pesoTara').value = peso;
    document.getElementById('costoTara').value = costo || '';
    document.getElementById('ventaTara').value = venta || '';
    document.getElementById('cobrarTara').checked = (cobrar == 1);
    document.getElementById('divPrecios').style.display = (cobrar == 1) ? 'flex' : 'none';
    
    const modalObj = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTara'));
    modalObj.show();
}

function confirmarBorrar(id) {
    Swal.fire({
        title: '¿Estás seguro?',
        text: "Este envase se borrará permanentemente.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, borrar envase',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'gestionar_taras.php?borrar=' + id;
        }
    });
}
</script>

<?php require_once 'includes/layout_footer.php'; ?>