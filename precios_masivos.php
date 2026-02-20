<?php
// precios_masivos.php - VERSIÓN PREMIUM AZUL + MENÚ FIXED
session_start();
require_once 'includes/db.php';

// SEGURIDAD
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] > 2) { header("Location: dashboard.php"); exit; }

// CARGA DE DATOS PARA FILTROS Y WIDGETS
$categorias = $conexion->query("SELECT * FROM categorias WHERE activo=1")->fetchAll();
$proveedores = $conexion->query("SELECT * FROM proveedores ORDER BY empresa ASC")->fetchAll();
$total_cats = count($categorias);
$total_provs = count($proveedores);

$mensaje = '';

$tipo_filtro = $_GET['tipo'] ?? 'proveedor';
$id_filtro = $_GET['id'] ?? '';
$productos_filtrados = [];

// 1. CARGAR PRODUCTOS
if ($id_filtro) {
    $where = ($tipo_filtro == 'proveedor') ? "id_proveedor = ?" : "id_categoria = ?";
    $stmt = $conexion->prepare("SELECT * FROM productos WHERE $where AND activo = 1 ORDER BY descripcion ASC");
    $stmt->execute([$id_filtro]);
    $productos_filtrados = $stmt->fetchAll();
}

// 2. PROCESAR AUMENTO
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirmar_aumento'])) {
    $ids = $_POST['productos_seleccionados'] ?? [];
    $accion = $_POST['accion']; 
    $porcentaje = floatval($_POST['porcentaje']);
    $tipo_filtro = $_POST['tipo_hidden'];
    $id_filtro = $_POST['id_hidden'];

    if(count($ids) > 0 && $porcentaje > 0) {
        try {
            $conexion->beginTransaction();
            $ids_str = implode(',', array_map('intval', $ids));
            
            // Auditoría Detallada
            $nombre_grupo = "General";
            try {
                if ($tipo_filtro == 'proveedor') {
                    $st = $conexion->prepare("SELECT empresa FROM proveedores WHERE id = ?");
                } else {
                    $st = $conexion->prepare("SELECT nombre FROM categorias WHERE id = ?");
                }
                $st->execute([$id_filtro]);
                $nombre_grupo = $st->fetchColumn() ?: "ID #$id_filtro";
            } catch (Exception $e) { $nombre_grupo = "ID #$id_filtro"; }

            $detalles = "Aumento Masivo del $porcentaje% en " . strtoupper($accion) . " aplicado a " . count($ids) . " productos del grupo " . strtoupper($tipo_filtro) . ": " . $nombre_grupo;
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'INFLACION', ?, NOW())")
                     ->execute([$_SESSION['usuario_id'], $detalles]);

            $factor = 1 + ($porcentaje / 100);
            
            if ($accion == 'costo') {
                $sql = "UPDATE productos SET precio_costo = precio_costo * $factor, precio_venta = precio_venta * $factor WHERE id IN ($ids_str)";
            } else {
                $sql = "UPDATE productos SET precio_venta = precio_venta * $factor WHERE id IN ($ids_str)";
            }
            
            $conexion->exec($sql);
            $conexion->commit();
            header("Location: precios_masivos.php?tipo=$tipo_filtro&id=$id_filtro&msg=ok&count=" . count($ids));
            exit;

        } catch (Exception $e) { 
            $conexion->rollBack(); 
            $mensaje = "❌ Error: " . $e->getMessage(); 
        }
    }
}
// OBTENER COLOR SEGURO (ESTÁNDAR PREMIUM)
$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_principal FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_principal'])) $color_sistema = $dataC['color_principal'];
    }
} catch (Exception $e) { }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ajuste de Precios | Panel de Control</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    
</head>
<body class="bg-light">
    
    <?php include 'includes/layout_header.php'; ?></div>

    <div class="header-blue" style="background-color: <?php echo $color_sistema; ?> !important;">
    <i class="bi bi-graph-up-arrow bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Actualización de Precios</h2>
                <p class="opacity-75 mb-0 text-white small">Ajuste masivo de precios por inflación.</p>
            </div>
            <a href="productos.php" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm">
                <i class="bi bi-arrow-left me-2"></i> VOLVER
            </a>
        </div>

        <div class="row g-3">
            <div class="col-6 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Categorías</div>
                        <div class="widget-value text-white"><?php echo $total_cats; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white">
                        <i class="bi bi-tags"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Proveedores</div>
                        <div class="widget-value text-white"><?php echo $total_provs; ?></div>
                    </div>
                    <div class="icon-box bg-success bg-opacity-20 text-white">
                        <i class="bi bi-truck"></i>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="header-widget border-info">
                    <div>
                        <div class="widget-label">Productos en Filtro</div>
                        <div class="widget-value text-white"><?php echo count($productos_filtrados); ?></div>
                    </div>
                    <div class="icon-box bg-info bg-opacity-20 text-white">
                        <i class="bi bi-box-seam"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

    <div class="container pb-5">
        
        <div class="card step-card mb-4 border-0">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-3 text-dark"><span class="step-number">1</span> Seleccionar Grupo de Productos</h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="small text-muted fw-bold text-uppercase">Filtrar por:</label>
                        <select id="select_tipo" class="form-select form-select-lg border-0 bg-light fw-bold" onchange="actualizarVista()">
                            <option value="proveedor" <?php echo $tipo_filtro == 'proveedor' ? 'selected' : ''; ?>>Proveedor</option>
                            <option value="categoria" <?php echo $tipo_filtro == 'categoria' ? 'selected' : ''; ?>>Categoría</option>
                        </select>
                    </div>
                    <div class="col-md-9">
                        <label class="small text-muted fw-bold text-uppercase">Seleccionar ítem:</label>
                        
                        <select id="combo_proveedor" class="form-select form-select-lg border-primary shadow-sm" 
                                style="display: <?php echo $tipo_filtro == 'proveedor' ? 'block' : 'none'; ?>"
                                onchange="cargarProductos('proveedor', this.value)">
                            <option value="">-- Seleccionar Proveedor --</option>
                            <?php foreach($proveedores as $p): ?>
                                <option value="<?php echo $p->id; ?>" <?php echo $id_filtro == $p->id ? 'selected' : ''; ?>><?php echo $p->empresa; ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select id="combo_categoria" class="form-select form-select-lg border-primary shadow-sm"
                                style="display: <?php echo $tipo_filtro == 'categoria' ? 'block' : 'none'; ?>"
                                onchange="cargarProductos('categoria', this.value)">
                            <option value="">-- Seleccionar Categoría --</option>
                            <?php foreach($categorias as $c): ?>
                                <option value="<?php echo $c->id; ?>" <?php echo $id_filtro == $c->id ? 'selected' : ''; ?>><?php echo $c->nombre; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <?php if($id_filtro): ?>
        <form method="POST">
            <input type="hidden" name="confirmar_aumento" value="1">
            <input type="hidden" name="tipo_hidden" value="<?php echo $tipo_filtro; ?>">
            <input type="hidden" name="id_hidden" value="<?php echo $id_filtro; ?>">

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card step-card h-100 border-0 shadow-sm">
                        <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center">
                            <span><span class="step-number">2</span> Productos (<span id="contadorVisible"><?php echo count($productos_filtrados); ?></span>)</span>
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" id="checkAll" checked onchange="toggleAll(this)">
                                <label class="form-check-label small text-muted" for="checkAll">Todos</label>
                            </div>
                        </div>
                        <div class="p-2 bg-light border-bottom">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white border-0"><i class="bi bi-search"></i></span>
                                <input type="text" id="buscadorTabla" class="form-control border-0 bg-white" placeholder="Buscar en esta lista..." onkeyup="filtrarTabla()">
                            </div>
                        </div>
                        <div class="card-body p-0" style="max-height: 500px; overflow-y: auto;">
                            <?php if(empty($productos_filtrados)): ?>
                                <div class="text-center p-5 text-muted">No se encontraron productos activos en este grupo.</div>
                            <?php else: ?>
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light small text-uppercase">
                                        <tr>
                                            <th width="50" class="text-center">#</th>
                                            <th>Producto</th>
                                            <th class="text-end pe-3">Venta Actual</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($productos_filtrados as $p): ?>
                                        <tr class="fila-producto">
                                            <td class="text-center">
                                                <input type="checkbox" name="productos_seleccionados[]" value="<?php echo $p->id; ?>" class="form-check-input item-check" checked>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-dark nombre-prod"><?php echo htmlspecialchars($p->descripcion); ?></div>
                                                <small class="text-muted"><?php echo $p->codigo_barras; ?></small>
                                            </td>
                                            <td class="text-end pe-3">
                                                <span class="badge bg-white text-dark border fw-bold">$<?php echo number_format($p->precio_venta, 2, ',', '.'); ?></span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="sticky-action">
                        <div class="card step-card border-0 shadow-lg" style="border-left: 5px solid #dc3545 !important;">
                            <div class="card-body p-4">
                                <h5 class="fw-bold mb-4 text-dark"><span class="step-number">3</span> Aplicar el Ajuste</h5>
                                
                                <div class="mb-4">
                                    <label class="form-label fw-bold text-muted small text-uppercase">Porcentaje de Aumento (%)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-danger text-white border-0"><i class="bi bi-percent fs-5"></i></span>
                                        <input type="number" name="porcentaje" class="form-control form-control-lg fw-bold text-center fs-2 text-danger shadow-sm" placeholder="0.00" step="0.01" required autofocus>
                                    </div>
                                    <div class="form-text text-muted">Ej: 15 para un aumento del quince por ciento.</div>
                                </div>

                                <div class="row g-2 mb-4">
                                    <div class="col-6">
                                        <input type="radio" class="btn-check" name="accion" id="a_costo" value="costo" checked>
                                        <label class="btn btn-outline-secondary w-100 py-3 h-100 shadow-sm" for="a_costo">
                                            <i class="bi bi-shield-check fs-3 d-block mb-1"></i>
                                            Costo y Venta<br>
                                            <small class="opacity-75 fw-normal" style="font-size: 0.7rem;">Mantiene el Margen %</small>
                                        </label>
                                    </div>
                                    <div class="col-6">
                                        <input type="radio" class="btn-check" name="accion" id="a_venta" value="venta">
                                        <label class="btn btn-outline-primary w-100 py-3 h-100 shadow-sm" for="a_venta">
                                            <i class="bi bi-cash-stack fs-3 d-block mb-1"></i>
                                            Solo Venta<br>
                                            <small class="opacity-75 fw-normal" style="font-size: 0.7rem;">Aumenta la Ganancia</small>
                                        </label>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-danger w-100 btn-lg fw-bold py-3 shadow hover-zoom">
                                    <i class="bi bi-check-circle-fill me-2"></i> APLICAR CAMBIOS AHORA
                                </button>
                                
                                <div class="alert alert-warning mt-4 small border-0 shadow-sm">
                                    <i class="bi bi-info-circle-fill"></i> Los cambios se guardarán en la auditoría del sistema para control.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <script>
        function actualizarVista() { 
            window.location.href = "precios_masivos.php?tipo=" + document.getElementById('select_tipo').value; 
        }
        
        function cargarProductos(tipo, id) { 
            if(id) window.location.href = "precios_masivos.php?tipo=" + tipo + "&id=" + id; 
        }
        
        function toggleAll(source) { 
            const checks = document.querySelectorAll('.item-check');
            checks.forEach(cb => {
                if(cb.closest('tr').style.display !== 'none') {
                    cb.checked = source.checked;
                }
            });
        }
        
        function filtrarTabla() {
            const txt = document.getElementById('buscadorTabla').value.toLowerCase();
            let count = 0;
            document.querySelectorAll('.fila-producto').forEach(row => {
                const nombre = row.querySelector('.nombre-prod').textContent.toLowerCase();
                if(nombre.includes(txt)) {
                    row.style.display = '';
                    count++;
                } else {
                    row.style.display = 'none';
                }
            });
            document.getElementById('contadorVisible').innerText = count;
        }

        // Alerta SweetAlert
        const urlParams = new URLSearchParams(window.location.search);
        if(urlParams.get('msg') === 'ok') {
            Swal.fire({
                icon: 'success',
                title: '¡Ajuste aplicado!',
                text: 'Se actualizaron ' + urlParams.get('count') + ' productos correctamente.',
                timer: 3000,
                showConfirmButton: false
            });
        }
    </script>

    <?php include 'includes/layout_footer.php'; ?>
</body>
</html>