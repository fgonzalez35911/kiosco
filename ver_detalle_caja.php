<?php
// ver_detalle_caja.php - VERSIÓN CORREGIDA (Variables vinculadas estrictamente a DB)
session_start();
require_once 'includes/db.php';

// SEGURIDAD: Solo Admin (1) o Dueño (2)
if (!isset($_SESSION['usuario_id']) || (isset($_SESSION['rol']) && $_SESSION['rol'] > 2)) {
    header("Location: dashboard.php"); exit;
}

if (!isset($_GET['id'])) { header("Location: historial_cajas.php"); exit; }
$id_sesion = $_GET['id'];

// 1. OBTENER COLOR DEL SISTEMA
$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_principal FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_principal'])) $color_sistema = $dataC['color_principal'];
    }
} catch (Exception $e) { }

// 2. OBTENER CABECERA DE CAJA
$stmt = $conexion->prepare("SELECT c.*, u.usuario as cajero, u.nombre_completo FROM cajas_sesion c JOIN usuarios u ON c.id_usuario = u.id WHERE c.id = ?");
$stmt->execute([$id_sesion]);
$caja = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$caja) { die("Error: La sesión de caja no existe."); }

// 3. CÁLCULOS DE TOTALES
$sqlRifas = "SELECT SUM(total) FROM ventas WHERE id_caja_sesion = ? AND estado='completada' AND codigo_ticket LIKE 'RIFA-%'";
$stmtR = $conexion->prepare($sqlRifas); $stmtR->execute([$id_sesion]);
$total_rifas = floatval($stmtR->fetchColumn() ?: 0);

$sqlVentas = "SELECT SUM(total) FROM ventas WHERE id_caja_sesion = ? AND estado='completada' AND (codigo_ticket NOT LIKE 'RIFA-%' OR codigo_ticket IS NULL)";
$stmtV = $conexion->prepare($sqlVentas); $stmtV->execute([$id_sesion]);
$total_ventas = floatval($stmtV->fetchColumn() ?: 0);

$sqlGastos = "SELECT SUM(monto) FROM gastos WHERE id_caja_sesion = ?";
$stmtG = $conexion->prepare($sqlGastos); $stmtG->execute([$id_sesion]);
$total_gastos = floatval($stmtG->fetchColumn() ?: 0);

// 4. LISTADO DE MOVIMIENTOS
$movimientos = $conexion->prepare("
    SELECT 'Venta' as tipo, id, fecha, total as monto, metodo_pago as detalle, IFNULL(codigo_ticket, '') as codigo_ticket 
    FROM ventas WHERE id_caja_sesion = ? AND estado='completada'
    UNION ALL
    SELECT 'Gasto' as tipo, id, fecha, monto, categoria as detalle, descripcion as codigo_ticket 
    FROM gastos WHERE id_caja_sesion = ?
    ORDER BY fecha DESC
");
$movimientos->execute([$id_sesion, $id_sesion]);
$lista = $movimientos->fetchAll(PDO::FETCH_ASSOC);

// Lógica de Diferencia
$diferencia = floatval($caja['diferencia']);
$esFaltante = ($diferencia < -0.01);
?>

<?php include 'includes/layout_header.php'; ?>

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden;">
    <i class="bi bi-receipt-cutoff bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h2 class="font-cancha mb-0 text-white">Detalle de Caja #<?php echo $caja['id']; ?></h2>
                <p class="opacity-75 mb-0 text-white small">
                    Cajero: <strong><?php echo htmlspecialchars($caja['nombre_completo']); ?></strong> | 
                    Apertura: <?php echo date('d/m/y H:i', strtotime($caja['fecha_apertura'])); ?>
                </p>
            </div>
            <a href="historial_cajas.php" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm">
                <i class="bi bi-arrow-left me-2"></i> VOLVER AL HISTORIAL
            </a>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Ventas Mostrador</div>
                        <div class="widget-value text-white">$<?php echo number_format($total_ventas, 0, ',', '.'); ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-shop"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Ingresos Rifas</div>
                        <div class="widget-value text-white">$<?php echo number_format($total_rifas, 0, ',', '.'); ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-ticket-perforated"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Gastos y Retiros</div>
                        <div class="widget-value text-white">$<?php echo number_format($total_gastos, 0, ',', '.'); ?></div>
                    </div>
                    <div class="icon-box bg-danger bg-opacity-20 text-white"><i class="bi bi-cart-dash"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container mt-4 pb-5">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card card-custom border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-list-ul me-2"></i> Movimientos de la Sesión</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Hora</th>
                                <th>Tipo</th>
                                <th>Detalle</th>
                                <th class="text-end pe-4">Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($lista as $m): 
                                $esGasto = ($m['tipo'] == 'Gasto');
                                $esRifa = (strpos($m['codigo_ticket'], 'RIFA-') === 0);
                            ?>
                            <tr>
                                <td class="ps-4 text-muted small"><?php echo date('H:i', strtotime($m['fecha'])); ?> hs</td>
                                <td>
                                    <?php if($esGasto): ?><span class="badge bg-danger bg-opacity-10 text-danger">GASTO</span>
                                    <?php elseif($esRifa): ?><span class="badge bg-warning text-dark">RIFA</span>
                                    <?php else: ?><span class="badge bg-success bg-opacity-10 text-success">VENTA</span><?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark" style="font-size: 0.9rem;"><?php echo htmlspecialchars($m['detalle']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($m['codigo_ticket']); ?></small>
                                </td>
                                <td class="text-end pe-4 fw-bold <?php echo $esGasto ? 'text-danger' : 'text-dark'; ?>">
                                    <?php echo $esGasto ? '-' : ''; ?>$<?php echo number_format($m['monto'], 2, ',', '.'); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <?php if($esFaltante): ?>
                <div class="alert alert-danger border-0 shadow-sm rounded-4 mb-3 p-3 d-flex align-items-center">
                    <i class="bi bi-exclamation-octagon-fill fs-2 me-3"></i>
                    <div>
                        <div class="fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 1px;">Atención: Faltante Detectado</div>
                        <div class="h4 mb-0 fw-bold">$<?php echo number_format(abs($diferencia), 2, ',', '.'); ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card card-custom border-0 shadow-sm bg-light">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-4">Resumen de Cierre</h5>
                    
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Efectivo Inicial:</span>
                        <span class="fw-bold">$<?php echo number_format($caja['monto_inicial'], 2, ',', '.'); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Ventas Brutas (+):</span>
                        <span class="text-success fw-bold">$<?php echo number_format($total_ventas + $total_rifas, 2, ',', '.'); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Gastos/Retiros (-):</span>
                        <span class="text-danger fw-bold">-$<?php echo number_format($total_gastos, 2, ',', '.'); ?></span>
                    </div>
                    
                    <hr>
                    
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <span class="h6 mb-0 fw-bold text-muted">SALDO ESPERADO:</span>
                        <span class="h4 mb-0 fw-bold text-primary">$<?php echo number_format($caja['monto_inicial'] + ($total_ventas + $total_rifas) - $total_gastos, 2, ',', '.'); ?></span>
                    </div>

                    <div class="p-3 rounded-4 bg-white border border-2 <?php echo ($esFaltante) ? 'border-danger' : 'border-success'; ?>">
                        <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.65rem;">Efectivo Real declarado:</small>
                        <span class="h3 fw-bold d-block">$<?php echo number_format($caja['monto_final'], 2, ',', '.'); ?></span>
                        
                        <?php if($esFaltante): ?>
                            <div class="mt-2 text-danger fw-bold">
                                <i class="bi bi-dash-circle-fill"></i> FALTANTE: $<?php echo number_format(abs($diferencia), 2, ',', '.'); ?>
                            </div>
                        <?php elseif($diferencia > 0.01): ?>
                            <div class="mt-2 text-warning fw-bold">
                                <i class="bi bi-plus-circle-fill"></i> SOBRANTE: $<?php echo number_format($diferencia, 2, ',', '.'); ?>
                            </div>
                        <?php else: ?>
                            <div class="mt-2 text-success fw-bold">
                                <i class="bi bi-check-circle-fill"></i> Caja Perfecta
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/layout_footer.php'; ?>
