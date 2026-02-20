<?php
// gestionar_cupones.php - DISEÑO PREMIUM AZUL + MENÚ FIXED
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. CONEXIÓN
$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

// 2. SEGURIDAD
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] > 2) { header("Location: dashboard.php"); exit; }

// 3. LÓGICA DE BORRADO
if (isset($_GET['borrar'])) {
    $id_borrar = intval($_GET['borrar']);
    
    // Obtenemos el código del cupón para la auditoría antes de borrarlo
    $stmtC = $conexion->prepare("SELECT codigo FROM cupones WHERE id = ?");
    $stmtC->execute([$id_borrar]);
    $codigo_cup = $stmtC->fetchColumn();

    $conexion->prepare("DELETE FROM cupones WHERE id = ?")->execute([$id_borrar]);

    // AUDITORÍA: ELIMINACIÓN DE CUPÓN
    try {
        $detalles_audit = "Cupón de descuento eliminado: " . ($codigo_cup ?? 'ID #' . $id_borrar);
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'CUPON_ELIMINADO', ?, NOW())")
                 ->execute([$_SESSION['usuario_id'], $detalles_audit]);
    } catch (Exception $e) { }

    header("Location: gestionar_cupones.php?msg=del"); exit;
}

$mensaje = '';

// 4. LÓGICA DE CREACIÓN
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $codigo = strtoupper(trim($_POST['codigo']));
    $porcentaje = (int)$_POST['porcentaje'];
    $vencimiento = $_POST['vencimiento'];
    $limite = (int)$_POST['limite'];

    $stmt = $conexion->prepare("SELECT COUNT(*) FROM cupones WHERE codigo = ?");
    $stmt->execute([$codigo]);
    
    if ($stmt->fetchColumn() > 0) {
        $mensaje = '<div class="alert alert-danger shadow-sm border-0"><i class="bi bi-x-circle-fill me-2"></i> Código duplicado.</div>';
    } else {
        $sql = "INSERT INTO cupones (codigo, descuento_porcentaje, fecha_limite, cantidad_limite, usos_actuales, activo) VALUES (?, ?, ?, ?, 0, 1)";
        $conexion->prepare($sql)->execute([$codigo, $porcentaje, $vencimiento, $limite]);

        // AUDITORÍA: CREACIÓN DE CUPÓN
        try {
            $txt_limite = ($limite > 0) ? $limite . " usos" : "Ilimitado";
            $detalles_audit = "Nuevo cupón creado: " . $codigo . " (" . $porcentaje . "% OFF). Límite: " . $txt_limite . ". Vence: " . date('d/m/Y', strtotime($vencimiento));
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'CUPON_NUEVO', ?, NOW())")
                     ->execute([$_SESSION['usuario_id'], $detalles_audit]);
        } catch (Exception $e) { }

                header("Location: gestionar_cupones.php?msg=ok"); exit;
    }
}

// 4.1 LÓGICA DE EDICIÓN
if (isset($_POST['action']) && $_POST['action'] == 'edit') {
    $id_edit = intval($_POST['id_cupon']);
    $codigo = strtoupper(trim($_POST['codigo']));
    $porcentaje = (int)$_POST['porcentaje'];
    $vencimiento = $_POST['vencimiento'];
    $limite = (int)$_POST['limite'];

    $sql = "UPDATE cupones SET codigo = ?, descuento_porcentaje = ?, fecha_limite = ?, cantidad_limite = ? WHERE id = ?";
    $conexion->prepare($sql)->execute([$codigo, $porcentaje, $vencimiento, $limite, $id_edit]);

    try {
        $detalles_audit = "Cupón editado: " . $codigo . " (" . $porcentaje . "% OFF). ID: " . $id_edit;
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'CUPON_EDITADO', ?, NOW())")
                 ->execute([$_SESSION['usuario_id'], $detalles_audit]);
    } catch (Exception $e) { }

    header("Location: gestionar_cupones.php?msg=edit"); exit;
}

// 5. CONSULTAS PARA LISTADO Y WIDGETS

$cupones = $conexion->query("SELECT * FROM cupones ORDER BY fecha_limite DESC")->fetchAll(PDO::FETCH_ASSOC);
// OBTENER COLOR SEGURO (ESTÁNDAR PREMIUM)
$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_principal FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_principal'])) $color_sistema = $dataC['color_principal'];
    }
} catch (Exception $e) { }
// KPIs
$total_usos = $conexion->query("SELECT SUM(usos_actuales) FROM cupones")->fetchColumn() ?: 0;
$activos = 0;
$por_vencer = 0;
$hoy = date('Y-m-d');
$proxima_semana = date('Y-m-d', strtotime('+7 days'));

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

    
</head>
<body class="bg-light">
    
    <?php include 'includes/layout_header.php'; ?> </div>

    <div class="header-blue" style="background-color: <?php echo $color_sistema; ?> !important;">
    <i class="bi bi-ticket-perforated bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Marketing & Cupones</h2>
                <p class="opacity-75 mb-0 text-white small">Gestioná descuentos y promociones para fidelizar clientes.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Cupones Activos</div>
                        <div class="widget-value text-white"><?php echo $activos; ?></div>
                    </div>
                    <div class="icon-box bg-success bg-opacity-20 text-white">
                        <i class="bi bi-ticket-detailed"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Usos Totales</div>
                        <div class="widget-value text-white"><?php echo $total_usos; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Vencen pronto</div>
                        <div class="widget-value text-white"><?php echo $por_vencer; ?></div>
                    </div>
                    <div class="icon-box bg-warning bg-opacity-20 text-white">
                        <i class="bi bi-calendar-x"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget border-info">
                    <div>
                        <div class="widget-label">Estrategia</div>
                        <div class="widget-value text-white" style="font-size: 1.1rem;">FIDELIZACIÓN</div>
                    </div>
                    <div class="icon-box bg-info bg-opacity-20 text-white">
                        <i class="bi bi-star"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

    <div class="container pb-5">
        <div class="row g-4">
            
            <div class="col-md-4">
                <div class="card card-custom h-100">
                    <div class="card-header bg-white fw-bold py-3 border-bottom-0 text-primary">
                        <i class="bi bi-plus-circle-fill me-2"></i> Crear Nuevo Cupón
                    </div>
                    <div class="card-body bg-light rounded-bottom">
                        <?php echo $mensaje; ?>
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
                            
                            <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-sm">
                                <i class="bi bi-save me-2"></i> GUARDAR CUPÓN
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card card-custom h-100">
                    <div class="card-header bg-white fw-bold py-3 border-bottom">
                        <i class="bi bi-list-task me-2 text-primary"></i> Cupones Generados
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light small text-uppercase text-muted">
                                <tr>
                                    <th class="ps-4">Código / Descuento</th>
                                    <th>Estado</th>
                                    <th>Uso / Límite</th>
                                    <th class="text-end pe-4">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($cupones) > 0): ?>
                                    <?php foreach($cupones as $c): 
                                        $venc = $c['fecha_limite'];
                                        $vencido = ($venc < date('Y-m-d'));
                                        $agotado = ($c['cantidad_limite'] > 0 && $c['usos_actuales'] >= $c['cantidad_limite']);
                                        
                                        if($vencido) {
                                            $badge_estado = '<span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25">Vencido</span>';
                                        } elseif($agotado) {
                                            $badge_estado = '<span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25">Agotado</span>';
                                        } else {
                                            $badge_estado = '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25">Activo</span>';
                                        }
                                    ?>
                                    <tr class="<?php echo ($vencido || $agotado) ? 'opacity-50' : ''; ?>">
                                        <td class="ps-4">
                                            <div class="fw-bold text-dark fs-5"><?php echo $c['codigo']; ?></div>
                                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">
                                                <?php echo $c['descuento_porcentaje']; ?>% OFF
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $badge_estado; ?>
                                            <div class="small text-muted mt-1">Vence: <?php echo date('d/m/y', strtotime($venc)); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo $c['usos_actuales']; ?> <small class="text-muted fw-normal">usos</small></div>
                                            <small class="text-muted">Límite: <?php echo $c['cantidad_limite'] > 0 ? $c['cantidad_limite'] : '∞'; ?></small>
                                        </td>
                                                                               <td class="text-end pe-4">
                                            <button onclick="abrirModalEditar(<?php echo htmlspecialchars(json_encode($c)); ?>)" class="btn btn-sm btn-outline-primary border-0 rounded-circle shadow-sm me-1">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            <button onclick="confirmarBorrado(<?php echo $c['id']; ?>)" class="btn btn-sm btn-outline-danger border-0 rounded-circle shadow-sm">
                                                <i class="bi bi-trash3-fill"></i>
                                            </button>
                                        </td>

                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted">No hay cupones creados.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
        </div>
    </div>

    <script>
        // CONFIRMACIÓN DE BORRADO
        function confirmarBorrado(id) {
            Swal.fire({
                title: '¿Eliminar cupón?',
                text: "Esta acción no se puede deshacer.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = "gestionar_cupones.php?borrar=" + id;
                }
            })
        }

        // Alertas Toast de éxito
        const urlParams = new URLSearchParams(window.location.search);
        if(urlParams.get('msg') === 'ok') {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Cupón creado correctamente', showConfirmButton: false, timer: 3000 });
                } else if(urlParams.get('msg') === 'del') {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Cupón eliminado', showConfirmButton: false, timer: 3000 });
        } else if(urlParams.get('msg') === 'edit') {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Cupón actualizado', showConfirmButton: false, timer: 3000 });
        }

        function abrirModalEditar(cupon) {
            Swal.fire({
                title: 'Editar Cupón',
                confirmButtonColor: '#102A57',
                confirmButtonText: 'Guardar Cambios',
                cancelButtonText: 'Cancelar',
                showCancelButton: true,
                html: `
                    <div class="text-start">
                        <label class="small fw-bold text-muted text-uppercase">Código</label>
                        <input type="text" id="edit-codigo" class="form-control mb-3 text-uppercase fw-bold" value="${cupon.codigo}">
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="small fw-bold text-muted text-uppercase">% OFF</label>
                                <input type="number" id="edit-porcentaje" class="form-control" value="${cupon.descuento_porcentaje}">
                            </div>
                            <div class="col-6">
                                <label class="small fw-bold text-muted text-uppercase">Límite</label>
                                <input type="number" id="edit-limite" class="form-control" value="${cupon.cantidad_limite}">
                            </div>
                        </div>
                        <label class="small fw-bold text-muted text-uppercase">Vencimiento</label>
                        <input type="date" id="edit-vencimiento" class="form-control" value="${cupon.fecha_limite}">
                    </div>
                `,
                preConfirm: () => {
                    return {
                        action: 'edit',
                        id_cupon: cupon.id,
                        codigo: document.getElementById('edit-codigo').value,
                        porcentaje: document.getElementById('edit-porcentaje').value,
                        limite: document.getElementById('edit-limite').value,
                        vencimiento: document.getElementById('edit-vencimiento').value
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    for (const key in result.value) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = key;
                        input.value = result.value[key];
                        form.appendChild(input);
                    }
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
    </script>


    <?php include 'includes/layout_footer.php'; ?>
</body>
</html>