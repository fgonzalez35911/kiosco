<?php
// bienes_uso.php - VERSIÓN VANGUARD PRO TOTAL (RESTAURADA Y SIN RECORTES)
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (file_exists('db.php')) { require_once 'db.php'; } 
elseif (file_exists('includes/db.php')) { require_once 'includes/db.php'; } 
else { die("Error crítico: No se encuentra db.php"); }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);
if (!$es_admin && !in_array('ver_activos', $permisos)) { header("Location: dashboard.php"); exit; }

// --- LÓGICA DE ELIMINACIÓN ---
if (isset($_GET['borrar'])) {
    if (!$es_admin && !in_array('eliminar_activo', $permisos)) die("Sin permiso para eliminar.");
    try {
        $id = $_GET['borrar'];
        $stmtFoto = $conexion->prepare("SELECT foto FROM bienes_uso WHERE id = ?");
        $stmtFoto->execute([$id]);
        $foto = $stmtFoto->fetchColumn();
        if($foto && file_exists($foto)) { unlink($foto); }
        $conexion->prepare("DELETE FROM bienes_uso WHERE id = ?")->execute([$id]);
        header("Location: bienes_uso.php?msg=eliminado"); exit;
    } catch (Exception $e) { }
}

// --- LÓGICA DE GUARDADO (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['nombre'])) {
    $id_edit = $_POST['id_edit'] ?? '';
    try {
        $nombre = trim($_POST['nombre']);
        $marca = trim($_POST['marca']);
        $modelo = trim($_POST['modelo']);
        $serie = trim($_POST['serie']);
        $estado = $_POST['estado']; // "Nuevo", "Bueno", "Regular", "Reparar", "Malo"
        $ubicacion = trim($_POST['ubicacion']);
        $fecha = !empty($_POST['fecha']) ? $_POST['fecha'] : NULL;
        $costo = !empty($_POST['costo']) ? $_POST['costo'] : 0;
        $notas = trim($_POST['notas']);

        // Procesar Foto si hay
        $ruta_foto = ''; 
        if (!empty($_FILES['foto']['name'])) {
            $dir = 'uploads/activos/';
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            $ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
            $nombre_archivo = uniqid('activo_') . '.' . $ext;
            $ruta_dest = $dir . $nombre_archivo;
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $ruta_dest)) { $ruta_foto = $ruta_dest; }
        }

        if (!empty($id_edit)) {
            $sql = "UPDATE bienes_uso SET nombre=?, marca=?, modelo=?, numero_serie=?, estado=?, ubicacion=?, fecha_compra=?, costo_compra=?, notas=?";
            $params = [$nombre, $marca, $modelo, $serie, $estado, $ubicacion, $fecha, $costo, $notas];
            if ($ruta_foto != '') { $sql .= ", foto=?"; $params[] = $ruta_foto; }
            $sql .= " WHERE id=?"; $params[] = $id_edit;
            $conexion->prepare($sql)->execute($params);
            $res = 'actualizado';
        } else {
            $sql = "INSERT INTO bienes_uso (nombre, marca, modelo, numero_serie, estado, ubicacion, fecha_compra, costo_compra, notas, foto, tipo_negocio) VALUES (?,?,?,?,?,?,?,?,?,?,?)";
            $conexion->prepare($sql)->execute([$nombre, $marca, $modelo, $serie, $estado, $ubicacion, $fecha, $costo, $notas, $ruta_foto, $rubro_actual]);
            $res = 'creado';
        }
        header("Location: bienes_uso.php?msg=$res"); exit;
    } catch (Exception $e) { die("Error: " . $e->getMessage()); }
}

// --- CONSULTA Y FILTROS ---
$desde = $_GET['desde'] ?? date('Y-01-01', strtotime('-5 years'));
$hasta = $_GET['hasta'] ?? date('Y-12-31');
$buscar = trim($_GET['buscar'] ?? '');
$cond = ["((DATE(fecha_compra) >= ? AND DATE(fecha_compra) <= ?) OR fecha_compra IS NULL)", "(tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)"];
$params = [$desde, $hasta];
if(!empty($buscar)) {
    $cond[] = "(nombre LIKE ? OR marca LIKE ? OR numero_serie LIKE ? OR ubicacion LIKE ?)";
    array_push($params, "%$buscar%", "%$buscar%", "%$buscar%", "%$buscar%");
}
$stmtActivos = $conexion->prepare("SELECT * FROM bienes_uso WHERE " . implode(" AND ", $cond) . " ORDER BY id DESC");
$stmtActivos->execute($params);
$activos = $stmtActivos->fetchAll(PDO::FETCH_ASSOC);

// Normalización de Estados para el JavaScript
$total_activos = count($activos);
$valor_total = 0; $reparar_cnt = 0;
foreach($activos as &$a) {
    $valor_total += (float)($a['costo_compra'] ?? 0);
    $est_norm = ucfirst(strtolower(trim((string)($a['estado'] ?? 'Bueno'))));
    $a['estado'] = $est_norm; // Sincronizamos
    if(strtolower($est_norm) == 'mantenimiento' || strtolower($est_norm) == 'roto') $reparar_cnt++;
}
unset($a);

$conf_color_sis = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf_color_sis['color_barra_nav'] ?? '#102A57';
require_once 'includes/layout_header.php';

// Banner Dinámico
$titulo = "Mis Activos";
$subtitulo = "Inventario y control de hardware.";
$icono_bg = "bi-pc-display-horizontal";
$botones = [
    ['texto' => 'REPORTE PDF', 'link' => "reporte_bienes.php?".$_SERVER['QUERY_STRING'], 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-4 shadow-sm', 'target' => '_blank'],
    ['texto' => 'NUEVO ACTIVO', 'link' => 'javascript:abrirModalCrear()', 'icono' => 'bi-plus-lg', 'class' => 'btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm ms-2']
];
$widgets = [
    ['label' => 'Equipos', 'valor' => $total_activos, 'icono' => 'bi-archive', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Inversión', 'valor' => '$'.number_format($valor_total, 0, ',', '.'), 'icono' => 'bi-currency-dollar', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'A Reparar', 'valor' => $reparar_cnt, 'icono' => 'bi-tools', 'border' => 'border-danger', 'icon_bg' => 'bg-danger bg-opacity-20']
];
include 'includes/componente_banner.php'; 
?>

<div class="container-fluid container-md mt-n4 px-3" style="position: relative; z-index: 20;">

    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border-left: 5px solid #ff9800 !important;">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center mb-0">
                <input type="hidden" name="desde" value="<?php echo $desde; ?>">
                <input type="hidden" name="hasta" value="<?php echo $hasta; ?>">
                <div class="col-md-8 fw-bold text-uppercase small"><i class="bi bi-search me-2"></i>Buscador de Equipos</div>
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <input type="text" name="buscar" class="form-control border-0 fw-bold shadow-none" placeholder="Buscar por nombre o serie..." value="<?php echo htmlspecialchars($buscar); ?>">
                        <button class="btn btn-dark px-3" type="submit"><i class="bi bi-arrow-right"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100">
                <input type="hidden" name="buscar" value="<?php echo htmlspecialchars($buscar); ?>">
                <div class="flex-grow-1">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Compra Desde</label>
                    <input type="date" name="desde" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $desde; ?>">
                </div>
                <div class="flex-grow-1">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Compra Hasta</label>
                    <input type="date" name="hasta" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $hasta; ?>">
                </div>
                <div class="flex-grow-0 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm fw-bold px-3 rounded-3 shadow-sm" style="height:31px;">FILTRAR</button>
                    <a href="bienes_uso.php" class="btn btn-light btn-sm fw-bold border rounded-3 px-3" style="height:31px; display:flex; align-items:center;">LIMPIAR</a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-4 mb-5" id="gridActivos">
        <?php foreach ($activos as $a): 
            $estadoClass = 'bg-secondary';
            $estLower = strtolower($a['estado'] ?? '');
            if ($estLower == 'nuevo') $estadoClass = 'bg-success';
            if ($estLower == 'bueno') $estadoClass = 'bg-primary';
            if ($estLower == 'mantenimiento') $estadoClass = 'bg-warning text-dark';
            if ($estLower == 'roto') $estadoClass = 'bg-danger';
            if ($estLower == 'baja') $estadoClass = 'bg-dark';
            
            $img = (!empty($a['foto']) && file_exists($a['foto'])) ? $a['foto'] : 'img/no-image.png';
            $jsonItem = htmlspecialchars(json_encode($a), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="col-12 col-sm-6 col-md-4 col-lg-3 item-activo" data-estado="<?php echo $a['estado']; ?>">
            <div class="card h-100 shadow-sm border-0 rounded-4 overflow-hidden position-relative">
                <div class="img-zone" style="height: 160px; background: #f8f9fa; position:relative; overflow:hidden; cursor:pointer;" onclick='verDetalle(<?php echo $jsonItem; ?>)'>
                    <img src="<?php echo $img; ?>" style="width: 100%; height: 100%; object-fit: contain; padding: 10px;">
                    <span class="badge position-absolute top-0 end-0 m-2 <?php echo $estadoClass; ?> shadow-sm"><?php echo $a['estado']; ?></span>
                </div>
                <div class="card-body p-3 bg-white" onclick='verDetalle(<?php echo $jsonItem; ?>)' style="cursor:pointer;">
                    <h6 class="fw-bold mb-1 text-truncate"><?php echo $a['nombre']; ?></h6>
                    <small class="text-muted d-block mb-2"><?php echo $a['marca']; ?> <?php echo $a['modelo']; ?></small>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                         <div class="small text-muted text-truncate w-50"><i class="bi bi-geo-alt-fill text-primary"></i> <?php echo $a['ubicacion'] ?: '-'; ?></div>
                         <div class="fw-bold text-success small">$<?php echo number_format($a['costo_compra'], 0, ',', '.'); ?></div>
                    </div>
                </div>
                <div class="card-footer bg-light border-0 p-2 d-flex justify-content-end gap-2">
                    <button class="btn btn-sm btn-outline-primary rounded-circle" onclick='editar(<?php echo $jsonItem; ?>)'><i class="bi bi-pencil-fill"></i></button>
                    <button class="btn btn-sm btn-outline-danger rounded-circle" onclick="confirmarBorrar(<?php echo $a['id']; ?>, '<?php echo addslashes($a['nombre']); ?>')"><i class="bi bi-trash3-fill"></i></button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="modalForm" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="modalTitle"><i class="bi bi-pc-display-horizontal"></i> Nuevo Activo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form action="bienes_uso.php" method="POST" id="formActivo" enctype="multipart/form-data">
                    <input type="hidden" name="id_edit" id="id_edit">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted">Nombre del Bien</label>
                            <input type="text" name="nombre" id="nombre" class="form-control fw-bold" required>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted">Marca</label>
                            <input type="text" name="marca" id="marca" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted">Modelo</label>
                            <input type="text" name="modelo" id="modelo" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted">Estado</label>
                            <select name="estado" id="estado" class="form-select" required>
                                <option value="nuevo">Nuevo</option>
                                <option value="bueno">Bueno</option>
                                <option value="mantenimiento">Mantenimiento / Reparar</option>
                                <option value="roto">Roto / Malo</option>
                                <option value="baja">Dado de baja</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted">Ubicación</label>
                            <input type="text" name="ubicacion" id="ubicacion" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted">Nro Serie</label>
                            <input type="text" name="serie" id="serie" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted">Fecha Compra</label>
                            <input type="date" name="fecha" id="fecha" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted">Costo Inversión ($)</label>
                            <input type="number" step="0.01" name="costo" id="costo" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="small fw-bold text-muted">Foto del Activo (Opcional)</label>
                            <input type="file" name="foto" id="foto" class="form-control" accept="image/*">
                        </div>
                        <div class="col-12">
                            <label class="small fw-bold text-muted">Notas Adicionales</label>
                            <textarea name="notas" id="notas" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="mt-4 text-end">
                        <button type="submit" class="btn btn-primary fw-bold px-4 rounded-pill shadow-sm"><i class="bi bi-check-lg"></i> GUARDAR DATOS</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    let modalFormObj;
    document.addEventListener('DOMContentLoaded', function() {
        modalFormObj = new bootstrap.Modal(document.getElementById('modalForm'));
        
        const msg = new URLSearchParams(window.location.search).get('msg');
        if(msg === 'actualizado') Swal.fire({ icon: 'success', title: '¡Listo!', text: 'Cambios guardados con éxito.', confirmButtonColor: '#102A57' });
        if(msg === 'creado') Swal.fire({ icon: 'success', title: '¡Excelente!', text: 'El activo se registró correctamente.', confirmButtonColor: '#102A57' });
        if(msg === 'eliminado') Swal.fire({ icon: 'success', title: 'Eliminado', text: 'El bien fue removido del sistema.', confirmButtonColor: '#102A57' });
    });

    function abrirModalCrear() {
        document.getElementById('formActivo').reset();
        document.getElementById('id_edit').value = '';
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pc-display-horizontal"></i> Nuevo Activo';
        modalFormObj.show();
    }

    function editar(item) {
        document.getElementById('formActivo').reset();
        document.getElementById('id_edit').value = item.id;
        document.getElementById('nombre').value = item.nombre;
        document.getElementById('marca').value = item.marca || '';
        document.getElementById('modelo').value = item.modelo || '';
        document.getElementById('serie').value = item.numero_serie || '';
        document.getElementById('ubicacion').value = item.ubicacion || '';
        document.getElementById('fecha').value = item.fecha_compra || '';
        document.getElementById('costo').value = item.costo_compra || '';
        document.getElementById('notas').value = item.notas || '';
        
        // Sincronizar el select para que marque el estado correcto
        document.getElementById('estado').value = item.estado;
        
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square"></i> Editar: ' + item.nombre;
        modalFormObj.show();
    }

    function verDetalle(item) {
        let costoStr = parseFloat(item.costo_compra || 0).toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        let imgHtml = item.foto ? `<img src="${item.foto}" style="height: 120px; object-fit:contain; border-radius:8px;">` : `<i class="bi bi-pc-display-horizontal" style="font-size: 3rem; color: #102A57;"></i>`;
        
        let estadoColor = '#6c757d';
        let estLower = (item.estado || '').toLowerCase();
        if (estLower === 'nuevo') estadoColor = '#28a745';
        if (estLower === 'bueno') estadoColor = '#007bff';
        if (estLower === 'mantenimiento') estadoColor = '#ffc107';
        if (estLower === 'roto') estadoColor = '#dc3545';
        if (estLower === 'baja') estadoColor = '#343a40';

        const html = `
            <div style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
                <div style="text-align: center; margin-bottom: 15px;">
                    ${imgHtml}
                    <h5 style="font-weight: 900; color: #102A57; letter-spacing: 1px; margin-top:10px;">FICHA DEL ACTIVO</h5>
                    <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">ID #${item.id}</span>
                </div>
                <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                    <div style="margin-bottom: 4px;"><strong>NOMBRE:</strong> ${item.nombre.toUpperCase()}</div>
                    <div style="margin-bottom: 4px;"><strong>MARCA/MOD:</strong> ${item.marca || '-'} ${item.modelo || ''}</div>
                    <div style="margin-bottom: 4px;"><strong>S/N:</strong> ${item.numero_serie || 'S/N'}</div>
                    <div style="margin-bottom: 4px;"><strong>UBICACIÓN:</strong> ${item.ubicacion || '-'}</div>
                    <div style="margin-top: 8px; font-weight:bold; color:${estadoColor};">ESTADO: ${item.estado.toUpperCase()}</div>
                </div>
                <div style="background: #102A5710; border-left: 4px solid #102A57; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 10px;">
                    <span style="font-size: 1.1em; font-weight:800;">INVERSIÓN:</span>
                    <span style="font-size: 1.15em; font-weight:900; color: #102A57;">$${costoStr}</span>
                </div>
                <div style="background: #e9ecef; border-left: 4px solid #6c757d; padding: 12px; font-size: 12px; margin-bottom: 20px;">
                    <strong>Notas:</strong> ${item.notas || 'Sin notas adicionales.'}
                </div>
            </div>
            <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3">
                <button class="btn btn-primary fw-bold flex-fill" onclick="prepararEdicion()"><i class="bi bi-pencil"></i> EDITAR</button>
                <button class="btn btn-danger fw-bold flex-fill" onclick="confirmarBorrar(${item.id}, '${item.nombre.replace(/'/g, "\\'")}')"><i class="bi bi-trash"></i> BORRAR</button>
            </div>`;
        
        window.itemActual = item; // Guardamos el item temporalmente para el botón editar
        
        Swal.fire({ html: html, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });
    }

    function prepararEdicion() {
        Swal.close();
        setTimeout(() => { 
            editar(window.itemActual);
        }, 400); 
    }

    function confirmarBorrar(id, nombre) {
        Swal.fire({ 
            title: '¿Borrar ' + nombre + '?', 
            text: "Esta acción no se puede deshacer.", 
            icon: 'warning', 
            showCancelButton: true, 
            confirmButtonColor: '#d33', 
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Sí, borrar', 
            cancelButtonText: 'Cancelar' 
        }).then((result) => { 
            if (result.isConfirmed) { window.location.href = 'bienes_uso.php?borrar=' + id; } 
        });
    }

    // Loader al guardar
    document.getElementById('formActivo').addEventListener('submit', function() {
        Swal.fire({ title: 'Guardando...', text: 'Actualizando inventario', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
    });
</script>
<?php include 'includes/layout_footer.php'; ?>