<?php
// gestionar_cupones.php - VERSIÓN ESTANDARIZADA (FILTROS + PDF)
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }


// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);
if (!$es_admin && !in_array('mkt_gestionar_cupones', $permisos)) { header("Location: dashboard.php"); exit; }


if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] > 2) { header("Location: dashboard.php"); exit; }

$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

if (isset($_GET['borrar'])) {
    if (!$es_admin && !in_array('mkt_gestionar_cupones', $permisos)) die("Sin permiso.");
    $id_borrar = intval($_GET['borrar']);
    $stmtC = $conexion->prepare("SELECT codigo FROM cupones WHERE id = ?");
    $stmtC->execute([$id_borrar]);
    $codigo_cup = $stmtC->fetchColumn();

    $conexion->prepare("DELETE FROM cupones WHERE id = ?")->execute([$id_borrar]);
    try {
        $detalles_audit = "Cupón eliminado: " . ($codigo_cup ?? 'ID #' . $id_borrar);
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha, tipo_negocio) VALUES (?, 'CUPON_ELIMINADO', ?, NOW(), ?)")->execute([$_SESSION['usuario_id'], $detalles_audit, $rubro_actual]);
    } catch (Exception $e) { }

    header("Location: gestionar_cupones.php?msg=del"); exit;
}

$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['action'])) {
    if (!$es_admin && !in_array('mkt_gestionar_cupones', $permisos)) die("Sin permiso.");
    $codigo = strtoupper(trim($_POST['codigo']));
    $porcentaje = (int)$_POST['porcentaje'];
    $vencimiento = $_POST['vencimiento'];
    $limite = (int)$_POST['limite'];

    $stmt = $conexion->prepare("SELECT COUNT(*) FROM cupones WHERE codigo = ?");
    $stmt->execute([$codigo]);
    
    if ($stmt->fetchColumn() > 0) {
        $mensaje = '<div class="alert alert-danger shadow-sm border-0"><i class="bi bi-x-circle-fill me-2"></i> Código duplicado.</div>';
    } else {
        $sql = "INSERT INTO cupones (codigo, descuento_porcentaje, fecha_limite, cantidad_limite, usos_actuales, activo, id_usuario, tipo_negocio) VALUES (?, ?, ?, ?, 0, 1, ?, ?)";
        $conexion->prepare($sql)->execute([$codigo, $porcentaje, $vencimiento, $limite, $_SESSION['usuario_id'], $rubro_actual]);
        try {
            $txt_limite = ($limite > 0) ? $limite . " usos" : "Ilimitado";
            $detalles_audit = "Nuevo cupón: " . $codigo . " (" . $porcentaje . "% OFF). Límite: " . $txt_limite . ". Vence: " . date('d/m/Y', strtotime($vencimiento));
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha, tipo_negocio) VALUES (?, 'CUPON_NUEVO', ?, NOW(), ?)")->execute([$_SESSION['usuario_id'], $detalles_audit, $rubro_actual]);
        } catch (Exception $e) { }
        header("Location: gestionar_cupones.php?msg=ok"); exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] == 'edit') {
    if (!$es_admin && !in_array('mkt_gestionar_cupones', $permisos)) die("Sin permiso.");
    $id_edit = intval($_POST['id_cupon']);
    $codigo = strtoupper(trim($_POST['codigo']));
    $porcentaje = (int)$_POST['porcentaje'];
    $vencimiento = $_POST['vencimiento'];
    $limite = (int)$_POST['limite'];

    $sql = "UPDATE cupones SET codigo = ?, descuento_porcentaje = ?, fecha_limite = ?, cantidad_limite = ? WHERE id = ?";
    $conexion->prepare($sql)->execute([$codigo, $porcentaje, $vencimiento, $limite, $id_edit]);
    header("Location: gestionar_cupones.php?msg=edit"); exit;
}

// FILTROS VANGUARD PRO
$desde = $_GET['desde'] ?? date('Y-01-01');
$hasta = $_GET['hasta'] ?? date('Y-12-31', strtotime('+1 year'));
$f_usu = $_GET['id_usuario'] ?? '';
$buscar = trim($_GET['buscar'] ?? '');

// 🛑 PARCHE AUTOMÁTICO VANGUARD PRO: Asigna los cupones huérfanos al Admin (ID 1)
$conexion->query("UPDATE cupones SET id_usuario = 1 WHERE id_usuario IS NULL OR id_usuario = 0");

$condiciones = ["DATE(c.fecha_limite) >= ?", "DATE(c.fecha_limite) <= ?", "(c.tipo_negocio = '$rubro_actual' OR c.tipo_negocio IS NULL)"];
$parametros = [$desde, $hasta];

if (!empty($buscar)) {
    $condiciones[] = "c.codigo LIKE ?";
    $parametros[] = "%$buscar%";
}

if ($f_usu !== '') {
    $condiciones[] = "c.id_usuario = ?";
    $parametros[] = $f_usu;
}

// CONSULTA UNIFICADA
$sql = "SELECT c.*, u.usuario as creador 
        FROM cupones c 
        LEFT JOIN usuarios u ON c.id_usuario = u.id 
        WHERE " . implode(" AND ", $condiciones) . " 
        ORDER BY c.fecha_limite DESC";

$stmtCupones = $conexion->prepare($sql);
$stmtCupones->execute($parametros);
$cupones = $stmtCupones->fetchAll(PDO::FETCH_ASSOC) ?: [];

$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav'];
    }
} catch (Exception $e) { }

$total_usos = $conexion->query("SELECT SUM(usos_actuales) FROM cupones WHERE (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)")->fetchColumn() ?: 0;
$activos = 0; $por_vencer = 0;
$hoy = date('Y-m-d'); $proxima_semana = date('Y-m-d', strtotime('+7 days'));

foreach($cupones as $c) { 
    if($c['fecha_limite'] >= $hoy) {
        $activos++;
        if($c['fecha_limite'] <= $proxima_semana) $por_vencer++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Marketing & Cupones</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @media (max-width: 768px) {
            .tabla-movil-ajustada td, .tabla-movil-ajustada th { padding: 0.5rem 0.3rem !important; font-size: 0.75rem !important; }
            .tabla-movil-ajustada .fw-bold { font-size: 0.85rem !important; }
            .tabla-movil-ajustada .badge { font-size: 0.65rem !important; }
        }
    </style>
</head>
<body class="bg-light">
    
    <?php include 'includes/layout_header.php'; ?> 

    <?php
    // --- DEFINICIÓN DEL BANNER DINÁMICO ESTANDARIZADO ---
    $titulo = "Marketing & Cupones";
    $subtitulo = "Gestioná descuentos y promociones para fidelizar clientes.";
    $icono_bg = "bi-ticket-perforated";

    $query_filtros = "desde=$desde&hasta=$hasta&id_usuario=$f_usu&buscar=$buscar";
    $botones = [
        ['texto' => 'NUEVO CUPÓN', 'icono' => 'bi-plus-circle-fill', 'class' => 'btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm me-2', 'link' => 'javascript:abrirModalCrear()'],
        ['texto' => 'Reporte PDF', 'link' => "reporte_cupones.php?$query_filtros", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-3 px-md-4 py-2 shadow-sm', 'target' => '_blank']
    ];

    $widgets = [
        ['label' => 'Cupones Activos', 'valor' => $activos, 'icono' => 'bi-ticket-detailed', 'icon_bg' => 'bg-success bg-opacity-20'],
        ['label' => 'Usos Totales', 'valor' => $total_usos, 'icono' => 'bi-people', 'icon_bg' => 'bg-white bg-opacity-10'],
        ['label' => 'Vencen Pronto', 'valor' => $por_vencer, 'icono' => 'bi-calendar-x', 'border' => 'border-warning', 'icon_bg' => 'bg-warning bg-opacity-20']
    ];

    include 'includes/componente_banner.php'; 
    ?>

    <div class="container-fluid mt-n4 pb-5 px-2 px-md-4" style="position: relative; z-index: 20;">
        
        <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border-left: 5px solid #ff9800 !important;">
            <div class="card-body p-2 p-md-3">
                <form method="GET" class="row g-2 align-items-center mb-0">
                    <input type="hidden" name="desde" value="<?php echo htmlspecialchars($desde); ?>">
                    <input type="hidden" name="hasta" value="<?php echo htmlspecialchars($hasta); ?>">
                    <div class="col-md-8 col-12 text-center text-md-start">
                        <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador de Cupones</h6>
                        <p class="small mb-0 opacity-75 d-none d-md-block">Ingresá el código del cupón para verificar su estado.</p>
                    </div>
                    <div class="col-md-4 col-12 text-end">
                        <div class="input-group input-group-sm">
                            <input type="text" name="buscar" class="form-control border-0 fw-bold shadow-none" placeholder="Código..." value="<?php echo htmlspecialchars($_GET['buscar'] ?? ''); ?>">
                            <button class="btn btn-dark px-3 border-0" type="submit"><i class="bi bi-arrow-right-circle-fill"></i></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-2 p-md-3">
                <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100">
                    <div class="flex-grow-1" style="min-width: 120px;">
                        <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Vencimiento Desde</label>
                        <input type="date" name="desde" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $desde; ?>">
                    </div>
                    <div class="flex-grow-1" style="min-width: 120px;">
                        <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Vencimiento Hasta</label>
                        <input type="date" name="hasta" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $hasta; ?>">
                    </div>
                    <div class="flex-grow-1" style="min-width: 140px;">
                        <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Responsable</label>
                        <select name="id_usuario" class="form-select form-select-sm border-light-subtle fw-bold">
                            <option value="">Todos</option>
                            <?php 
                            $usuarios_lista = $conexion->query("SELECT id, usuario FROM usuarios ORDER BY usuario ASC")->fetchAll(PDO::FETCH_ASSOC);
                            foreach($usuarios_lista as $usu): ?>
                                <option value="<?php echo $usu['id']; ?>" <?php echo ($f_usu == $usu['id']) ? 'selected' : ''; ?>><?php echo strtoupper($usu['usuario']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="wrapperFiltros" class="flex-grow-0 d-flex gap-2 mt-2 mt-md-0">
                        <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;">
                            <i class="bi bi-funnel-fill me-1"></i> FILTRAR
                        </button>
                        <a href="gestionar_cupones.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;">
                            <i class="bi bi-trash3-fill me-1"></i> LIMPIAR
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php echo $mensaje; ?>

        <div class="row g-4 pb-5" id="gridProductos">
            <?php if(count($cupones) > 0): ?>
                <?php foreach($cupones as $c): 
                    $vencido = ($c['fecha_limite'] < date('Y-m-d'));
                    $agotado = ($c['cantidad_limite'] > 0 && $c['usos_actuales'] >= $c['cantidad_limite']);
                    
                    if($vencido) { $color_borde = '#6c757d'; $texto_estado = 'VENCIDO'; } 
                    elseif($agotado) { $color_borde = '#ffc107'; $texto_estado = 'AGOTADO'; } 
                    else { $color_borde = '#198754'; $texto_estado = 'ACTIVO'; }
                ?>
                <div class="col-12 col-md-6 col-xl-4 item-grid">
                    <div class="card border-0 shadow-sm rounded-4 h-100 <?php echo ($vencido || $agotado) ? 'opacity-75 grayscale' : ''; ?>" style="border-top: 5px solid <?php echo $color_borde; ?> !important; overflow: hidden;">
                        <div class="card-body p-4 position-relative">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="badge" style="background-color: <?php echo $color_borde; ?>"><?php echo $texto_estado; ?></span>
                                <small class="text-muted fw-bold"><i class="bi bi-calendar me-1"></i> Vence: <?php echo date('d/m/y', strtotime($c['fecha_limite'])); ?></small>
                            </div>
                            
                            <h3 class="fw-bold text-dark mt-3 mb-0" style="font-family: 'Oswald', sans-serif; letter-spacing: 1px;"><?php echo $c['codigo']; ?></h3>
                            <h2 class="text-primary fw-bold my-2"><?php echo $c['descuento_porcentaje']; ?>% <span class="fs-6 text-muted">OFF</span></h2>
                            
                            <div class="mt-3 bg-light p-2 rounded text-center small">
                                <span class="fw-bold text-dark"><?php echo $c['usos_actuales']; ?></span> usos de <span class="fw-bold"><?php echo $c['cantidad_limite'] > 0 ? $c['cantidad_limite'] : '∞'; ?></span>
                            </div>
                        </div>
                        <div class="card-footer bg-white border-top p-3 d-flex justify-content-end gap-2">
                            <button onclick='abrirModalEditar(<?php echo json_encode($c); ?>)' class="btn btn-sm btn-outline-primary border-0 rounded-circle"><i class="bi bi-pencil-square"></i></button>
                            <?php if($es_admin || in_array('mkt_gestionar_cupones', $permisos)): ?>
                                <button onclick="confirmarBorrado(<?php echo $c['id']; ?>)" class="btn btn-sm btn-outline-danger border-0 rounded-circle"><i class="bi bi-trash3-fill"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12 text-center py-5">
                    <i class="bi bi-ticket-detailed text-muted opacity-25" style="font-size: 5rem;"></i>
                    <h5 class="mt-3 text-muted">No se encontraron cupones con esos filtros.</h5>
                </div>
            <?php endif; ?>
        </div>

        <div id="vistaListaGenerica" class="d-none mt-2 pb-5">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 tabla-movil-ajustada">
                        <thead class="bg-light text-muted small text-uppercase">
                            <tr>
                                <th class="ps-4">CÓDIGO / DESCUENTO</th>
                                <th>ESTADO / VENCE</th>
                                <th class="d-none d-md-table-cell">USO / LÍMITE</th>
                                <th class="text-end pe-4">ACCIÓN</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($cupones) > 0): ?>
                                <?php foreach($cupones as $c): 
                                    $vencido = ($c['fecha_limite'] < date('Y-m-d'));
                                    $agotado = ($c['cantidad_limite'] > 0 && $c['usos_actuales'] >= $c['cantidad_limite']);
                                    
                                    if($vencido) { $badge_estado = '<span class="badge bg-secondary">Vencido</span>'; } 
                                    elseif($agotado) { $badge_estado = '<span class="badge bg-warning text-dark">Agotado</span>'; } 
                                    else { $badge_estado = '<span class="badge bg-success">Activo</span>'; }
                                ?>
                                <tr class="<?php echo ($vencido || $agotado) ? 'opacity-50' : ''; ?>">
                                    <td class="ps-4">
                                        <div class="fw-bold text-dark fs-5"><?php echo $c['codigo']; ?></div>
                                        <span class="badge bg-primary"><?php echo $c['descuento_porcentaje']; ?>% OFF</span>
                                        <div class="small text-muted mt-1"><i class="bi bi-person-fill"></i> Op: <strong><?php echo $c['creador'] ? strtoupper($c['creador']) : 'S/D'; ?></strong></div>
                                    </td>
                                    <td>
                                        <?php echo $badge_estado; ?>
                                        <div class="small text-muted mt-1">Vence: <?php echo date('d/m/y', strtotime($c['fecha_limite'])); ?></div>
                                        <div class="d-block d-md-none small mt-1">
                                            <b><?php echo $c['usos_actuales']; ?></b> / <?php echo $c['cantidad_limite'] > 0 ? $c['cantidad_limite'] : '∞'; ?> usos
                                        </div>
                                    </td>
                                    <td class="d-none d-md-table-cell">
                                        <div class="fw-bold text-dark"><?php echo $c['usos_actuales']; ?> <small class="text-muted fw-normal">usos</small></div>
                                        <small class="text-muted">Límite: <?php echo $c['cantidad_limite'] > 0 ? $c['cantidad_limite'] : '∞'; ?></small>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button onclick='abrirModalEditar(<?php echo json_encode($c); ?>)' class="btn btn-sm btn-outline-primary border-0 rounded-circle shadow-sm me-1"><i class="bi bi-pencil-square"></i></button>
                                        <?php if($es_admin || in_array('eliminar_cupon', $permisos)): ?>
                                            <button onclick="confirmarBorrado(<?php echo $c['id']; ?>)" class="btn btn-sm btn-outline-danger border-0 rounded-circle"><i class="bi bi-trash3-fill"></i></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center py-5 text-muted">No hay cupones creados en este rango de fechas.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <div class="modal fade" id="modalCrearCupon" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-ticket-detailed"></i> Nuevo Cupón</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="small fw-bold text-muted text-uppercase">Código del Cupón</label>
                            <input type="text" name="codigo" class="form-control form-control-lg text-uppercase fw-bold shadow-sm" placeholder="EJ: PROMO20" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="small fw-bold text-muted text-uppercase">% Descuento</label>
                                <div class="input-group">
                                    <input type="number" name="porcentaje" class="form-control fw-bold" min="1" max="100" required>
                                    <span class="input-group-text bg-white border-start-0 text-muted">%</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="small fw-bold text-muted text-uppercase">Límite Usos</label>
                                <input type="number" name="limite" class="form-control" value="0" placeholder="0 = ∞">
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="small fw-bold text-muted text-uppercase">Fecha de Vencimiento</label>
                            <input type="date" name="vencimiento" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-sm"><i class="bi bi-save me-2"></i> GUARDAR CUPÓN</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function abrirModalCrear() {
            var modal = new bootstrap.Modal(document.getElementById('modalCrearCupon'));
            modal.show();
        }

        function confirmarBorrado(id) {
            Swal.fire({ title: '¿Eliminar cupón?', text: "Esta acción no se puede deshacer.", icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar' })
            .then((result) => { if (result.isConfirmed) { window.location.href = "gestionar_cupones.php?borrar=" + id; } })
        }
        const urlParams = new URLSearchParams(window.location.search);
        if(urlParams.get('msg') === 'ok') { Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Cupón creado', showConfirmButton: false, timer: 3000 }); } 
        else if(urlParams.get('msg') === 'del') { Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Cupón eliminado', showConfirmButton: false, timer: 3000 }); } 
        else if(urlParams.get('msg') === 'edit') { Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Cupón actualizado', showConfirmButton: false, timer: 3000 }); }

        function abrirModalEditar(cupon) {
            Swal.fire({
                title: 'Editar Cupón', confirmButtonColor: '#102A57', confirmButtonText: 'Guardar', showCancelButton: true,
                html: `
                    <div class="text-start">
                        <label class="small fw-bold text-muted text-uppercase">Código</label><input type="text" id="edit-codigo" class="form-control mb-3 text-uppercase fw-bold" value="${cupon.codigo}">
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="small fw-bold text-muted text-uppercase">% OFF</label><input type="number" id="edit-porcentaje" class="form-control" value="${cupon.descuento_porcentaje}"></div>
                            <div class="col-6"><label class="small fw-bold text-muted text-uppercase">Límite</label><input type="number" id="edit-limite" class="form-control" value="${cupon.cantidad_limite}"></div>
                        </div>
                        <label class="small fw-bold text-muted text-uppercase">Vencimiento</label><input type="date" id="edit-vencimiento" class="form-control" value="${cupon.fecha_limite}">
                    </div>
                `,
                preConfirm: () => { return { action: 'edit', id_cupon: cupon.id, codigo: document.getElementById('edit-codigo').value, porcentaje: document.getElementById('edit-porcentaje').value, limite: document.getElementById('edit-limite').value, vencimiento: document.getElementById('edit-vencimiento').value } }
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form'); form.method = 'POST';
                    for (const key in result.value) { const input = document.createElement('input'); input.type = 'hidden'; input.name = key; input.value = result.value[key]; form.appendChild(input); }
                    document.body.appendChild(form); form.submit();
                }
            });
        }
    </script>
    <?php include 'includes/layout_footer.php'; ?>
</body>
</html>