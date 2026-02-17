<?php
// ver_recaudacion.php - EL TABLERO DE CONTROL DEFINITIVO (VERSIÓN 360° PREMIUM)
require_once 'includes/db.php';
session_start();

// SEGURIDAD: Solo Dueño/Admin
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] > 2) { 
    header("Location: dashboard.php"); exit; 
}

$hoy = date('Y-m-d');

// 1. OBTENER CONFIGURACIÓN VISUAL (Para el banner dinámico)
$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_principal FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (!empty($dataC['color_principal'])) $color_sistema = $dataC['color_principal'];
    }
} catch (Exception $e) { }

// --- 2. MOTOR DE DATOS (FORZADO A FETCH_ASSOC PARA EVITAR ERROR 500) ---

// A. VENTAS Y MÉTODOS DE PAGO (Auditoría de Caja)
$stmtV = $conexion->prepare("SELECT * FROM ventas WHERE DATE(fecha) = ? AND estado = 'completada'");
$stmtV->execute([$hoy]);
$ventas = $stmtV->fetchAll(PDO::FETCH_ASSOC);

$ingresoVentasTotal = 0; $totalDescMonto = 0; $metodos = [];
foreach($ventas as $v) {
    $ingresoVentasTotal += floatval($v['total']);
    $totalDescMonto += floatval($v['descuento_monto_cupon']) + floatval($v['descuento_manual']);
    $m = !empty($v['metodo_pago']) ? strtoupper($v['metodo_pago']) : 'EFECTIVO';
    if(!isset($metodos[$m])) $metodos[$m] = 0;
    $metodos[$m] += floatval($v['total']);
}

// B. UTILIDAD BRUTA (Análisis de CMV y Margen de Producto)
$sqlUtil = "SELECT p.descripcion, SUM(dv.cantidad) as cant, SUM(dv.subtotal) as total_v, 
                   SUM(dv.cantidad * p.precio_costo) as total_c,
                   SUM(dv.subtotal - (dv.cantidad * p.precio_costo)) as utilidad
            FROM detalle_ventas dv 
            JOIN productos p ON dv.id_producto = p.id 
            JOIN ventas v ON dv.id_venta = v.id 
            WHERE DATE(v.fecha) = ? AND v.estado = 'completada' 
            GROUP BY p.id ORDER BY utilidad DESC";
$stmtU = $conexion->prepare($sqlUtil); $stmtU->execute([$hoy]);
$productosDetalle = $stmtU->fetchAll(PDO::FETCH_ASSOC);

$utilidadVentas = 0; $cmvTotal = 0;
foreach($productosDetalle as $pd) {
    $utilidadVentas += floatval($pd['utilidad']);
    $cmvTotal += floatval($pd['total_c']);
}

// C. EGRESOS (Gastos Operativos)
$stmtG = $conexion->prepare("SELECT * FROM gastos WHERE DATE(fecha) = ? AND categoria != 'Sorteo'");
$stmtG->execute([$hoy]);
$listaGastos = $stmtG->fetchAll(PDO::FETCH_ASSOC);
$totalGastos = 0; foreach($listaGastos as $g) $totalGastos += floatval($g['monto']);

// D. FUGAS Y MERMAS (Costo de productos perdidos)
$sqlM = "SELECT m.*, p.descripcion, p.precio_costo FROM mermas m 
          JOIN productos p ON m.id_producto = p.id WHERE DATE(m.fecha) = ?";
$stmtM = $conexion->prepare($sqlM); $stmtM->execute([$hoy]);
$listaMermas = $stmtM->fetchAll(PDO::FETCH_ASSOC);
$totalMermas = 0; foreach($listaMermas as $lm) $totalMermas += (floatval($lm['cantidad']) * floatval($lm['precio_costo']));

// E. FIDELIZACIÓN Y PREMIOS (Lógica de Recetas Completa)
$sqlP = "SELECT s.titulo as sorteo_nombre, sp.*, p.descripcion as prod_nombre, p.precio_costo, p.tipo as tipo_prod, p.codigo_barras 
         FROM sorteo_premios sp JOIN sorteos s ON s.id = sp.id_sorteo LEFT JOIN productos p ON sp.id_producto = p.id 
         WHERE s.estado = 'activo' OR (s.estado = 'finalizado' AND DATE(s.fecha_sorteo) = ?)";
$stmtP = $conexion->prepare($sqlP); $stmtP->execute([$hoy]);
$premiosData = $stmtP->fetchAll(PDO::FETCH_ASSOC);

$costoPremiosTotal = 0; $premiosHtml = "";
foreach($premiosData as $pr) {
    $cPr = 0; $receta = "";
    if($pr['tipo'] == 'interno') {
        if($pr['tipo_prod'] == 'combo') {
            $stmtR = $conexion->prepare("SELECT p.descripcion, p.precio_costo, ci.cantidad FROM combo_items ci JOIN productos p ON ci.id_producto = p.id JOIN combos c ON ci.id_combo = c.id WHERE c.codigo_barras = ?");
            $stmtR->execute([$pr['codigo_barras']]);
            foreach($stmtR->fetchAll(PDO::FETCH_ASSOC) as $ing) {
                $cPr += (floatval($ing['precio_costo']) * floatval($ing['cantidad']));
                $receta .= "<li>{$ing['cantidad']}x {$ing['descripcion']}</li>";
            }
        } else { $cPr = floatval($pr['precio_costo']); $receta = "Producto unitario"; }
    } else { $cPr = floatval($pr['costo_manual']); $receta = "Costo manual"; }
    $costoPremiosTotal += $cPr;
    $premiosHtml .= "<tr><td>{$pr['sorteo_nombre']}</td><td>{$pr['prod_nombre']}</td><td><ul class='small mb-0'>$receta</ul></td><td class='text-end fw-bold'>$".number_format($cPr,2)."</td></tr>";
}

// F. KPIs Y RESULTADO FINAL
$cantVentas = count($ventas);
$ticketProm = ($cantVentas > 0) ? ($ingresoVentasTotal / $cantVentas) : 0;
$gananciaLimpia = $utilidadVentas - $totalGastos - $totalMermas - $costoPremiosTotal;

require_once 'includes/layout_header.php'; 
?>

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden;">
    <i class="bi bi-graph-up-arrow bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h2 class="font-cancha mb-0 text-white">Tablero de Recaudación</h2>
                <p class="opacity-75 mb-0 text-white small">Estado de resultados microscópico del día.</p>
            </div>
            <a href="dashboard.php" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm">
                <i class="bi bi-arrow-left me-2"></i> VOLVER AL INICIO
            </a>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-3" data-bs-toggle="modal" data-bs-target="#modalIngresos" style="cursor:pointer;">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Ingresos Brutos</div>
                        <div class="widget-value text-white">$<?php echo number_format($ingresoVentasTotal, 0, ',', '.'); ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-cash-stack"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-3" data-bs-toggle="modal" data-bs-target="#modalUtilidad" style="cursor:pointer;">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Utilidad Bruta</div>
                        <div class="widget-value text-white">$<?php echo number_format($utilidadVentas, 0, ',', '.'); ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-trophy"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-3" data-bs-toggle="modal" data-bs-target="#modalGastos" style="cursor:pointer;">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Egresos Operativos</div>
                        <div class="widget-value text-white">$<?php echo number_format($totalGastos, 0, ',', '.'); ?></div>
                    </div>
                    <div class="icon-box bg-danger bg-opacity-20 text-white"><i class="bi bi-cart-dash"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-3" data-bs-toggle="modal" data-bs-target="#modalGanancia" style="cursor:pointer;">
                <div class="header-widget" style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3);">
                    <div>
                        <div class="widget-label fw-bold text-white">Ganancia Limpia</div>
                        <div class="widget-value text-white">$<?php echo number_format($gananciaLimpia, 0, ',', '.'); ?></div>
                    </div>
                    <div class="icon-box bg-white text-primary"><i class="bi bi-check2-circle"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container mt-4 pb-5">
    <div class="row g-3 mb-4">
        <div class="col-md-4" data-bs-toggle="modal" data-bs-target="#modalMermas" style="cursor:pointer;">
            <div class="card border-0 shadow-sm p-3 border-start border-danger border-4">
                <small class="text-muted fw-bold d-block">FUGAS Y MERMAS</small>
                <span class="h4 fw-bold text-danger">$<?php echo number_format($totalMermas, 0, ',', '.'); ?></span>
            </div>
        </div>
        <div class="col-md-4" data-bs-toggle="modal" data-bs-target="#modalMarketing" style="cursor:pointer;">
            <div class="card border-0 shadow-sm p-3 border-start border-warning border-4">
                <small class="text-muted fw-bold d-block">MARKETING Y DESCUENTOS</small>
                <span class="h4 fw-bold text-warning">$<?php echo number_format($totalDescMonto + $costoPremiosTotal, 0, ',', '.'); ?></span>
            </div>
        </div>
        <div class="col-md-4" data-bs-toggle="modal" data-bs-target="#modalKPIs" style="cursor:pointer;">
            <div class="card border-0 shadow-sm p-3 border-start border-primary border-4">
                <small class="text-muted fw-bold d-block">TICKET PROMEDIO</small>
                <span class="h4 fw-bold text-primary">$<?php echo number_format($ticketProm, 2, ',', '.'); ?></span>
            </div>
        </div>
    </div>

    <div class="card card-custom border-0 shadow-sm">
        <div class="card-header bg-white py-3 fw-bold"><i class="bi bi-ticket-detailed me-2 text-primary"></i> Desglose de Costos de Premios</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="bg-light small uppercase text-muted">
                    <tr><th>Sorteo</th><th>Premio</th><th>Receta</th><th class="text-end pe-4">Costo</th></tr>
                </thead>
                <tbody>
                    <?php echo $premiosHtml ?: '<tr><td colspan="4" class="text-center py-4">No hay premios entregados hoy.</td></tr>'; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalIngresos" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content rounded-4 border-0">
        <div class="modal-header bg-primary text-white">
            <h5 class="fw-bold mb-0">Desglose de Ingresos</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-0">
            <table class="table mb-0">
                <?php foreach($metodos as $m => $val): ?>
                <tr><td class="ps-4 fw-bold"><?php echo $m; ?></td><td class="text-end pe-4 h6">$<?php echo number_format($val,0,',','.'); ?></td></tr>
                <?php endforeach; ?>
                <tr class="bg-light fw-bold"><td class="ps-4">TOTAL VENTAS</td><td class="text-end pe-4">$<?php echo number_format($ingresoVentasTotal,0,',','.'); ?></td></tr>
            </table>
        </div>
    </div></div>
</div>

<div class="modal fade" id="modalUtilidad" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content rounded-4 border-0">
        <div class="modal-header bg-success text-white">
            <h5 class="fw-bold mb-0">Utilidad Detallada por Producto</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-0">
            <div class="p-3 bg-light small text-muted"><strong>Utilidad Bruta:</strong> Es el dinero ganado por producto (Venta neta - Precio de costo original).</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light small uppercase">
                        <tr><th class="ps-3">Producto</th><th class="text-center">Cant</th><th>Venta</th><th class="text-end pe-3 text-success">Ganancia</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($productosDetalle as $p): ?>
                        <tr><td class="ps-3 fw-bold"><?php echo $p['descripcion']; ?></td><td class="text-center"><?php echo $p['cant']; ?></td><td>$<?php echo number_format($p['total_v'],0); ?></td><td class="text-end pe-3 text-success fw-bold">$<?php echo number_format($p['utilidad'],0); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div></div>
</div>

<div class="modal fade" id="modalGastos" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content rounded-4 border-0">
        <div class="modal-header bg-danger text-white">
            <h5 class="fw-bold mb-0">Listado de Gastos del Día</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-0">
            <table class="table mb-0">
                <thead class="bg-light small"><tr><th class="ps-4">Motivo / Categoría</th><th class="text-end pe-4">Monto</th></tr></thead>
                <tbody>
                    <?php foreach($listaGastos as $g): ?>
                    <tr><td class="ps-4"><?php echo $g['descripcion']; ?> <small class="text-muted d-block"><?php echo $g['categoria']; ?></small></td><td class="text-end pe-4 text-danger fw-bold">-$<?php echo number_format($g['monto'],0); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if(empty($listaGastos)): ?><tr><td colspan="2" class="text-center py-4 text-muted">No hubo egresos operativos hoy.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div></div>
</div>

<div class="modal fade" id="modalGanancia" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content rounded-4 border-0">
        <div class="modal-header bg-dark text-white">
            <h5 class="fw-bold mb-0">Cálculo de Ganancia Final</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4 text-center">
            <p class="text-muted small mb-4">A continuación, el desglose real de tu bolsillo hoy:</p>
            <div class="d-flex justify-content-between mb-2"><span>Utilidad Bruta (Ventas - Costos)</span><span class="text-success fw-bold">+$<?php echo number_format($utilidadVentas,0,',','.'); ?></span></div>
            <div class="d-flex justify-content-between mb-2"><span>Egresos Operativos</span><span class="text-danger">-$<?php echo number_format($totalGastos,0,',','.'); ?></span></div>
            <div class="d-flex justify-content-between mb-2"><span>Pérdidas por Mermas</span><span class="text-danger">-$<?php echo number_format($totalMermas,0,',','.'); ?></span></div>
            <div class="d-flex justify-content-between mb-2"><span>Inversión en Premios</span><span class="text-danger">-$<?php echo number_format($costoPremiosTotal,0,',','.'); ?></span></div>
            <hr>
            <div class="d-flex justify-content-between h4 fw-bold mb-0"><span>GANANCIA LIMPIA</span><span class="text-primary">$<?php echo number_format($gananciaLimpia,0,',','.'); ?></span></div>
        </div>
    </div></div>
</div>

<div class="modal fade" id="modalMermas" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content rounded-4 border-0">
        <div class="modal-header bg-danger text-white">
            <h5 class="fw-bold mb-0">Detalle de Fugas (Mermas)</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-0">
            <table class="table mb-0">
                <thead class="bg-light small"><tr><th class="ps-4">Producto / Motivo</th><th class="text-end pe-4">Costo Perdido</th></tr></thead>
                <tbody>
                    <?php foreach($listaMermas as $me): ?>
                    <tr><td class="ps-4"><?php echo $me['descripcion']; ?> <small class="text-muted d-block"><?php echo $me['motivo']; ?></small></td><td class="text-end pe-4 fw-bold text-danger">-$<?php echo number_format($me['cantidad'] * $me['precio_costo'],0); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div></div>
</div>

<div class="modal fade" id="modalMarketing" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content rounded-4 border-0">
        <div class="modal-header bg-warning">
            <h5 class="fw-bold mb-0">Marketing y Descuentos</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-0">
            <table class="table mb-0">
                <tr><td class="ps-4">Inversión en Premios (Recetas)</td><td class="text-end pe-4 fw-bold text-danger">-$<?php echo number_format($costoPremiosTotal,0); ?></td></tr>
                <tr><td class="ps-4">Cupones y Descuentos Aplicados</td><td class="text-end pe-4 fw-bold text-warning">-$<?php echo number_format($totalDescMonto,0); ?></td></tr>
            </table>
        </div>
    </div></div>
</div>

<div class="modal fade" id="modalKPIs" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content rounded-4 border-0">
        <div class="modal-header bg-primary text-white">
            <h5 class="fw-bold mb-0">Métricas de Rendimiento</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4 text-center">
            <div class="mb-4">
                <small class="text-muted uppercase fw-bold">Ticket Promedio Hoy</small>
                <div class="h2 fw-bold text-primary">$<?php echo number_format($ticketProm, 2, ',', '.'); ?></div>
            </div>
            <hr>
            <div>
                <small class="text-muted uppercase fw-bold">Hora de Mayor Actividad</small>
                <div class="h3 fw-bold text-dark"><?php echo $horaPico ? $horaPico['h'].":00 hs" : "--:--"; ?></div>
            </div>
        </div>
    </div></div>
</div>

<?php include 'includes/layout_footer.php'; ?>