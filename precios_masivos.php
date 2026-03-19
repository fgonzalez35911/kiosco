<?php
// precios_masivos.php - VERSIÓN ESTANDARIZADA Y REPARADA
session_start();
require_once 'includes/db.php';

// SEGURIDAD
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] > 2) { 
    header("Location: dashboard.php"); exit; 
}

$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

// CARGA DE DATOS PARA FILTROS Y WIDGETS
$categorias = $conexion->query("SELECT * FROM categorias WHERE activo=1 AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)")->fetchAll(PDO::FETCH_OBJ);
$proveedores = $conexion->query("SELECT * FROM proveedores WHERE (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL) ORDER BY empresa ASC")->fetchAll(PDO::FETCH_OBJ);
$total_cats = count($categorias);
$total_provs = count($proveedores);

$tipo_filtro = $_GET['tipo'] ?? 'proveedor';
$id_filtro = $_GET['id'] ?? '';

// AUTO-FILTRADO PREDETERMINADO: Si no hay ID, tomamos el primer elemento disponible
if (!$id_filtro) {
    if ($tipo_filtro == 'proveedor' && !empty($proveedores)) {
        $id_filtro = $proveedores[0]->id;
    } elseif ($tipo_filtro == 'categoria' && !empty($categorias)) {
        $id_filtro = $categorias[0]->id;
    }
}

$productos_filtrados = [];

// 1. CARGAR PRODUCTOS SEGÚN FILTRO
if ($id_filtro) {
    $where = ($tipo_filtro == 'proveedor') ? "id_proveedor = ?" : "id_categoria = ?";
    $stmt = $conexion->prepare("SELECT * FROM productos WHERE $where AND activo = 1 AND (tipo_negocio = ? OR tipo_negocio IS NULL) ORDER BY descripcion ASC");
    $stmt->execute([$id_filtro, $rubro_actual]);
    $productos_filtrados = $stmt->fetchAll(PDO::FETCH_OBJ);
}

// 2. PROCESAR AUMENTO (LÓGICA ORIGINAL PRESERVADA)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirmar_aumento'])) {
    $ids = $_POST['productos_seleccionados'] ?? [];
    $accion = $_POST['accion']; 
    $porcentaje = floatval($_POST['porcentaje']);
    $tipo_h = $_POST['tipo_hidden'];
    $id_h = $_POST['id_hidden'];

    if(count($ids) > 0 && $porcentaje > 0) {
        try {
            $conexion->beginTransaction();
            $ids_str = implode(',', array_map('intval', $ids));
            
            $nombre_grupo = "General";
            if ($tipo_h == 'proveedor') {
                $st = $conexion->prepare("SELECT empresa FROM proveedores WHERE id = ?");
            } else {
                $st = $conexion->prepare("SELECT nombre FROM categorias WHERE id = ?");
            }
            $st->execute([$id_h]);
            $nombre_grupo = $st->fetchColumn() ?: "ID #$id_h";

           $detalles = "Aumento Masivo del $porcentaje% en " . strtoupper($accion) . " aplicado a " . count($ids) . " productos del grupo " . strtoupper($tipo_h) . ": " . $nombre_grupo;
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha, tipo_negocio) VALUES (?, 'INFLACION', ?, NOW(), ?)")->execute([$_SESSION['usuario_id'], $detalles, $rubro_actual]);
            $conexion->prepare("INSERT INTO historial_inflacion (fecha, porcentaje, accion, grupo_afectado, cantidad_productos, id_usuario, tipo_negocio) VALUES (NOW(), ?, ?, ?, ?, ?, ?)")->execute([$porcentaje, strtoupper($accion), $nombre_grupo, count($ids), $_SESSION['usuario_id'], $rubro_actual]);

            $factor = 1 + ($porcentaje / 100);
            if ($accion == 'costo') {
                $sql = "UPDATE productos SET precio_costo = precio_costo * $factor, precio_venta = precio_venta * $factor WHERE id IN ($ids_str)";
            } else {
                $sql = "UPDATE productos SET precio_venta = precio_venta * $factor WHERE id IN ($ids_str)";
            }
            
            $conexion->exec($sql);
            $conexion->commit();
            header("Location: precios_masivos.php?tipo=$tipo_h&id=$id_h&msg=ok&count=" . count($ids));
            exit;
        } catch (Exception $e) { 
            if($conexion->inTransaction()) $conexion->rollBack(); 
            die("Error: " . $e->getMessage());
        }
    }
}

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf['color_barra_nav'] ?? '#102A57';

require_once 'includes/layout_header.php'; ?>

<?php
// --- DEFINICIÓN DEL BANNER DINÁMICO ESTANDARIZADO ---
$titulo = "Actualización de Precios";
$subtitulo = "Ajuste masivo de precios por inflación.";
$icono_bg = "bi-graph-up-arrow";

$botones = [
    ['texto' => 'Ver Historial', 'link' => "historial_inflacion.php", 'icono' => 'bi-clock-history', 'class' => 'btn btn-warning text-dark fw-bold rounded-pill px-3 px-md-4 py-2 shadow-sm']
];

$widgets = [
    ['label' => 'Categorías', 'valor' => $total_cats, 'icono' => 'bi-tags', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Proveedores', 'valor' => $total_provs, 'icono' => 'bi-truck', 'border' => 'border-success', 'icon_bg' => 'bg-success bg-opacity-20'],
    ['label' => 'Productos', 'valor' => count($productos_filtrados), 'icono' => 'bi-box-seam', 'border' => 'border-info', 'icon_bg' => 'bg-info bg-opacity-20']
];

include 'includes/componente_banner.php'; 
?>

<div class="container pb-5 mt-n4" style="position: relative; z-index: 20;">
    
    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border-left: 5px solid #ff9800 !important;">
        <div class="card-body p-2 p-md-3">
            <div class="row g-2 align-items-center mb-0">
                <div class="col-md-8 col-12 text-center text-md-start">
                    <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador en Lista</h6>
                    <p class="small mb-0 opacity-75 d-none d-md-block">Primero seleccioná un proveedor/categoría, luego usá este buscador para encontrar un artículo.</p>
                </div>
                <div class="col-md-4 col-12 text-end mt-2 mt-md-0">
                    <div class="input-group input-group-sm">
                        <input type="text" id="buscadorNaranja" class="form-control border-0 fw-bold shadow-none" placeholder="Escribí para filtrar la lista..." onkeyup="document.getElementById('buscadorTabla').value = this.value; filtrarTabla();">
                        <button class="btn btn-dark px-3 shadow-none border-0" type="button"><i class="bi bi-search"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100">
                <div class="flex-grow-1" style="min-width: 140px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Filtrar por</label>
                    <select id="select_tipo" name="tipo" class="form-select form-select-sm border-light-subtle fw-bold" onchange="actualizarVista()">
                        <option value="proveedor" <?php echo $tipo_filtro == 'proveedor' ? 'selected' : ''; ?>>Proveedor</option>
                        <option value="categoria" <?php echo $tipo_filtro == 'categoria' ? 'selected' : ''; ?>>Categoría</option>
                    </select>
                </div>
                <div class="flex-grow-1" style="min-width: 200px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Seleccionar ítem</label>
                    <select name="id" class="form-select form-select-sm border-light-subtle fw-bold" onchange="this.form.submit()">
                        <option value="">-- Seleccionar --</option>
                        <?php if($tipo_filtro == 'proveedor'): ?>
                            <?php foreach($proveedores as $p): ?>
                                <option value="<?php echo $p->id; ?>" <?php echo $id_filtro == $p->id ? 'selected' : ''; ?>><?php echo strtoupper($p->empresa); ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php foreach($categorias as $c): ?>
                                <option value="<?php echo $c->id; ?>" <?php echo $id_filtro == $c->id ? 'selected' : ''; ?>><?php echo strtoupper($c->nombre); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="flex-grow-0 d-flex gap-2 mt-2 mt-md-0">
                    <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;">
                        <i class="bi bi-funnel-fill me-1"></i> FILTRAR
                    </button>
                    <a href="precios_masivos.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;">
                        <i class="bi bi-trash3-fill me-1"></i> LIMPIAR
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if($id_filtro): ?>
    <form method="POST" id="formInflacion">
        <input type="hidden" name="confirmar_aumento" value="1">
        <input type="hidden" name="tipo_hidden" value="<?php echo $tipo_filtro; ?>">
        <input type="hidden" name="id_hidden" value="<?php echo $id_filtro; ?>">

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                    <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center">
                        <span class="text-dark">Productos en Lista (<span id="contadorVisible"><?php echo count($productos_filtrados); ?></span>)</span>
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
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light small text-uppercase">
                                <tr>
                                    <th width="50" class="text-center">#</th>
                                    <th>Producto</th>
                                    <th class="text-end pe-3">P. Venta</th>
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
                                        <span class="fw-bold text-primary">$<?php echo number_format($p->precio_venta, 2, ',', '.'); ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card border-0 shadow-lg rounded-4" style="border-left: 5px solid #dc3545 !important; position: sticky; top: 20px;">
                    <div class="card-body p-4">
                        <h5 class="fw-bold mb-4 text-dark">Aplicar el Ajuste</h5>
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold text-muted small text-uppercase">Porcentaje de Aumento (%)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-danger text-white border-0"><i class="bi bi-percent fs-5"></i></span>
                                <input type="number" name="porcentaje" class="form-control form-control-lg fw-bold text-center fs-2 text-danger shadow-sm" placeholder="0.00" step="0.01" required>
                            </div>
                        </div>

                        <div class="row g-2 mb-4">
                            <div class="col-6">
                                <input type="radio" class="btn-check" name="accion" id="a_costo" value="costo" checked>
                                <label class="btn btn-outline-secondary w-100 py-3 h-100 shadow-sm" for="a_costo">
                                    <i class="bi bi-shield-check fs-4 d-block mb-1"></i> Costo y Venta
                                </label>
                            </div>
                            <div class="col-6">
                                <input type="radio" class="btn-check" name="accion" id="a_venta" value="venta">
                                <label class="btn btn-outline-primary w-100 py-3 h-100 shadow-sm" for="a_venta">
                                    <i class="bi bi-cash-stack fs-4 d-block mb-1"></i> Solo Venta
                                </label>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-danger w-100 btn-lg fw-bold py-3 shadow">
                            <i class="bi bi-check-circle-fill me-2"></i> APLICAR AHORA
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    function actualizarVista() { 
        window.location.href = "precios_masivos.php?tipo=" + document.getElementById('select_tipo').value; 
    }
    
    function toggleAll(source) { 
        document.querySelectorAll('.item-check').forEach(cb => {
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

    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('msg') === 'ok') {
        Swal.fire({
            icon: 'success',
            title: '¡Ajuste aplicado!',
            text: 'Se actualizaron los productos correctamente.',
            timer: 3000,
            showConfirmButton: false
        });
    }
</script>

<?php include 'includes/layout_footer.php'; ?>