<?php
// activos.php - GESTIÓN DE BIENES DE USO CON FOTO (VERSIÓN PREMIUM)
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] > 2) {
    header("Location: dashboard.php"); exit;
}

// 1. OBTENER COLOR DEL SISTEMA Y ESTADÍSTICAS
$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_principal FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (!empty($dataC['color_principal'])) $color_sistema = $dataC['color_principal'];
    }
} catch (Exception $e) { }

// Estadísticas para Widgets
$total_bienes = $conexion->query("SELECT COUNT(*) FROM bienes_uso")->fetchColumn();
$valor_patrimonio = $conexion->query("SELECT SUM(costo_compra) FROM bienes_uso")->fetchColumn() ?: 0;
$en_alerta = $conexion->query("SELECT COUNT(*) FROM bienes_uso WHERE estado IN ('roto', 'mantenimiento')")->fetchColumn();

// 2. LÓGICA DE GUARDADO/ELIMINACIÓN (SE MANTIENE IGUAL - CIRUGÍA)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion'])) {
    $nombre = $_POST['nombre'];
    $marca = $_POST['marca'];
    $modelo = $_POST['modelo'];
    $serie = $_POST['numero_serie'];
    $ubicacion = $_POST['ubicacion'];
    $estado = $_POST['estado'];
    $fecha = !empty($_POST['fecha_compra']) ? $_POST['fecha_compra'] : null;
    $costo = !empty($_POST['costo_compra']) ? $_POST['costo_compra'] : 0;
    $notas = $_POST['notas'];

    $ruta_foto = null;
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $nombre_archivo = 'activo_' . time() . '_' . rand(100, 999) . '.' . pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
        $destino = 'uploads/' . $nombre_archivo;
        if (move_uploaded_file($_FILES['foto']['tmp_name'], $destino)) { $ruta_foto = $destino; }
    }

    try {
        if ($_POST['accion'] == 'crear') {
            $sql = "INSERT INTO bienes_uso (nombre, marca, modelo, numero_serie, ubicacion, estado, fecha_compra, costo_compra, notas, foto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $conexion->prepare($sql)->execute([$nombre, $marca, $modelo, $serie, $ubicacion, $estado, $fecha, $costo, $notas, $ruta_foto]);
        } elseif ($_POST['accion'] == 'editar') {
            $id = $_POST['id_bien'];
            if ($ruta_foto) {
                $sql = "UPDATE bienes_uso SET nombre=?, marca=?, modelo=?, numero_serie=?, ubicacion=?, estado=?, fecha_compra=?, costo_compra=?, notas=?, foto=? WHERE id=?";
                $params = [$nombre, $marca, $modelo, $serie, $ubicacion, $estado, $fecha, $costo, $notas, $ruta_foto, $id];
            } else {
                $sql = "UPDATE bienes_uso SET nombre=?, marca=?, modelo=?, numero_serie=?, ubicacion=?, estado=?, fecha_compra=?, costo_compra=?, notas=? WHERE id=?";
                $params = [$nombre, $marca, $modelo, $serie, $ubicacion, $estado, $fecha, $costo, $notas, $id];
            }
            $conexion->prepare($sql)->execute($params);
        }
        header("Location: activos.php?msg=ok"); exit;
    } catch (Exception $e) { $error = "Error: " . $e->getMessage(); }
}

if (isset($_GET['borrar'])) {
    $stmt = $conexion->prepare("SELECT foto FROM bienes_uso WHERE id = ?");
    $stmt->execute([$_GET['borrar']]);
    $foto = $stmt->fetchColumn();
    if($foto && file_exists($foto)) { unlink($foto); }
    $conexion->prepare("DELETE FROM bienes_uso WHERE id = ?")->execute([$_GET['borrar']]);
    header("Location: activos.php?msg=borrado"); exit;
}

$bienes = $conexion->query("SELECT * FROM bienes_uso ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/layout_header.php'; 
?>

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 0 30px 30px !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden;">
    <i class="bi bi-pc-display-horizontal bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h2 class="font-cancha mb-0 text-white">Bienes de Uso</h2>
                <p class="opacity-75 mb-0 text-white small">Control patrimonial y estado de equipos.</p>
            </div>
            <button class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm" onclick="abrirModal('crear')">
                <i class="bi bi-plus-lg me-2"></i> AGREGAR BIEN
            </button>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4" style="cursor: pointer;" onclick="abrirModalPatrimonio()">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Patrimonio Total</div>
                        <div class="widget-value text-white">$<?php echo number_format($valor_patrimonio, 0, ',', '.'); ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-currency-dollar"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Equipos Registrados</div>
                        <div class="widget-value text-white"><?php echo $total_bienes; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-archive"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4" style="cursor: pointer;" onclick="abrirModalAlerta()">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">En Mantenimiento / Roto</div>
                        <div class="widget-value <?php echo ($en_alerta > 0) ? 'text-warning' : 'text-white'; ?>"><?php echo $en_alerta; ?></div>
                    </div>
                    <div class="icon-box bg-danger bg-opacity-20 text-white"><i class="bi bi-tools"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container mt-4 pb-5">
    <div class="card card-custom border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small uppercase">
                    <tr>
                        <th class="ps-4">Foto</th>
                        <th>Equipo</th>
                        <th>Identificación</th>
                        <th>Ubicación</th>
                        <th>Estado</th>
                        <th class="text-end pe-4">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($bienes as $b): 
                        $badge_class = ($b['estado'] == 'nuevo' || $b['estado'] == 'bueno') ? 'bg-success' : (($b['estado'] == 'roto') ? 'bg-danger' : 'bg-warning text-dark');
                    ?>
                    <tr>
                        <td class="ps-4">
                            <?php if($b['foto']): ?>
                                <img src="<?php echo $b['foto']; ?>" style="width: 45px; height: 45px; object-fit: cover; border-radius: 10px;" onclick="verFoto('<?php echo $b['foto']; ?>', '<?php echo $b['nombre']; ?>')">
                            <?php else: ?>
                                <div class="bg-light rounded-3 d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;"><i class="bi bi-camera text-muted"></i></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-bold"><?php echo $b['nombre']; ?></div>
                            <small class="text-muted"><?php echo $b['marca']; ?> <?php echo $b['modelo']; ?></small>
                        </td>
                        <td class="small font-monospace text-muted"><?php echo $b['numero_serie'] ?: 'S/N'; ?></td>
                        <td><span class="badge bg-light text-dark border"><i class="bi bi-geo-alt me-1"></i><?php echo $b['ubicacion']; ?></span></td>
                        <td><span class="badge <?php echo $badge_class; ?> rounded-pill"><?php echo strtoupper($b['estado']); ?></span></td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-outline-primary rounded-circle" onclick='editar(<?php echo json_encode($b); ?>)'><i class="bi bi-pencil"></i></button>
                            <a href="activos.php?borrar=<?php echo $b['id']; ?>" class="btn btn-sm btn-outline-danger rounded-circle ms-1" onclick="return confirm('¿Eliminar activo?')"><i class="bi bi-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalBien" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-dark text-white border-0">
                <h5 class="fw-bold mb-0" id="modalTitulo">Nuevo Activo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST" id="formBien" enctype="multipart/form-data">
                    <input type="hidden" name="accion" id="accion" value="crear">
                    <input type="hidden" name="id_bien" id="id_bien">
                    
                    <div class="row g-3">
                        <div class="col-md-12 text-center mb-3">
                            <label class="btn btn-outline-primary w-100 py-4 border-2 border-dashed rounded-4">
                                <i class="bi bi-camera fs-2 d-block mb-2"></i>
                                <span class="fw-bold">Tomar Foto / Subir Imagen</span>
                                <input type="file" name="foto" class="d-none" accept="image/*" onchange="previewImage(this)">
                            </label>
                            <div id="preview-box" class="mt-3 d-none">
                                <img id="preview-img" src="" class="img-fluid rounded-4 shadow-sm" style="max-height: 200px;">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold">Nombre del Bien *</label>
                            <input type="text" name="nombre" id="nombre" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold">Marca</label>
                            <input type="text" name="marca" id="marca" class="form-control rounded-3">
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold">Modelo</label>
                            <input type="text" name="modelo" id="modelo" class="form-control rounded-3">
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold">N° Serie</label>
                            <input type="text" name="numero_serie" id="numero_serie" class="form-control rounded-3">
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold">Ubicación</label>
                            <input type="text" name="ubicacion" id="ubicacion" class="form-control rounded-3">
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold">Estado</label>
                            <select name="estado" id="estado" class="form-select rounded-3">
                                <option value="nuevo">Nuevo</option>
                                <option value="bueno">Bueno / Usado</option>
                                <option value="mantenimiento">Mantenimiento</option>
                                <option value="roto">Roto</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold">Costo Compra ($)</label>
                            <input type="number" step="0.01" name="costo_compra" id="costo_compra" class="form-control rounded-3">
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold">Fecha Compra</label>
                            <input type="date" name="fecha_compra" id="fecha_compra" class="form-control rounded-3">
                        </div>
                        <div class="col-12">
                            <label class="small fw-bold">Notas</label>
                            <textarea name="notas" id="notas" class="form-control rounded-4" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold rounded-pill">GUARDAR ACTIVO</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    const modalBien = new bootstrap.Modal(document.getElementById('modalBien'));

    function abrirModal() {
        document.getElementById('formBien').reset();
        document.getElementById('accion').value = 'crear';
        document.getElementById('preview-box').classList.add('d-none');
        document.getElementById('modalTitulo').innerText = 'Nuevo Activo';
        modalBien.show();
    }

    function editar(b) {
        document.getElementById('accion').value = 'editar';
        document.getElementById('id_bien').value = b.id;
        document.getElementById('nombre').value = b.nombre;
        document.getElementById('marca').value = b.marca;
        document.getElementById('modelo').value = b.modelo;
        document.getElementById('numero_serie').value = b.numero_serie;
        document.getElementById('ubicacion').value = b.ubicacion;
        document.getElementById('estado').value = b.estado;
        document.getElementById('costo_compra').value = b.costo_compra;
        document.getElementById('fecha_compra').value = b.fecha_compra;
        document.getElementById('notas').value = b.notas;
        if(b.foto) {
            document.getElementById('preview-img').src = b.foto;
            document.getElementById('preview-box').classList.remove('d-none');
        }
        document.getElementById('modalTitulo').innerText = 'Editar Activo';
        modalBien.show();
    }

    function previewImage(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('preview-img').src = e.target.result;
                document.getElementById('preview-box').classList.remove('d-none');
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function verFoto(url, t) {
        Swal.fire({ title: t, imageUrl: url, imageAlt: t, showConfirmButton: false, width: '600px' });
    }

    function abrirModalPatrimonio() {
        Swal.fire({
            title: 'Desglose de Patrimonio',
            html: `<div class="text-start">El valor actual de tus equipos en cancha es de <b>$${parseFloat(<?php echo $valor_patrimonio; ?>).toLocaleString('es-AR')}</b>.<br><br><small class="text-muted">Calculado sobre el costo de compra de cada bien.</small></div>`,
            icon: 'info'
        });
    }

    function abrirModalAlerta() {
        Swal.fire({
            title: 'Equipos en Alerta',
            text: 'Tenés <?php echo $en_alerta; ?> equipo(s) fuera de servicio o en reparación. Revisalos en la lista roja.',
            icon: 'warning'
        });
    }
</script>

<?php include 'includes/layout_footer.php'; ?>