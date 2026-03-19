<?php
// ver_detalle_caja.php - VERSIÓN FINAL GARANTIZADA (Lógica PHP pura para movimientos)
session_start();
require_once 'includes/db.php';

// SEGURIDAD: Solo Admin (1) o Dueño (2)
if (!isset($_SESSION['usuario_id']) || (isset($_SESSION['rol']) && $_SESSION['rol'] > 2)) {
    header("Location: dashboard.php"); exit;
}

if (!isset($_GET['id'])) { header("Location: historial_cajas.php"); exit; }
$id_sesion = $_GET['id'];

// 1. OBTENER COLOR DEL SISTEMA (Corregido para tu DB)
$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav'];
    }
} catch (Exception $e) { }

// 2. OBTENER CABECERA DE CAJA
$stmt = $conexion->prepare("SELECT c.*, u.usuario as cajero, u.nombre_completo FROM cajas_sesion c JOIN usuarios u ON c.id_usuario = u.id WHERE c.id = ?");
$stmt->execute([$id_sesion]);
$caja = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$caja) { die("Error: La sesión de caja no existe."); }

// Detectar rubro actual para filtrar
$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

// 3. CÁLCULOS DE TOTALES (Aislados por rubro)
$stmtR = $conexion->prepare("SELECT SUM(total) FROM ventas WHERE id_caja_sesion = ? AND estado='completada' AND codigo_ticket LIKE 'RIFA-%' AND (tipo_negocio = ? OR tipo_negocio IS NULL)");
$stmtR->execute([$id_sesion, $rubro_actual]);
$total_rifas = floatval($stmtR->fetchColumn() ?: 0);

$stmtV = $conexion->prepare("SELECT SUM(total) FROM ventas WHERE id_caja_sesion = ? AND estado='completada' AND (codigo_ticket NOT LIKE 'RIFA-%' OR codigo_ticket IS NULL) AND (tipo_negocio = ? OR tipo_negocio IS NULL)");
$stmtV->execute([$id_sesion, $rubro_actual]);
$total_ventas = floatval($stmtV->fetchColumn() ?: 0);

$stmtG = $conexion->prepare("SELECT SUM(monto) FROM gastos WHERE id_caja_sesion = ? AND (tipo_negocio = ? OR tipo_negocio IS NULL)");
$stmtG->execute([$id_sesion, $rubro_actual]);
$total_gastos = floatval($stmtG->fetchColumn() ?: 0);

// 4. CONSTRUCCIÓN DE LA TABLA DE MOVIMIENTOS (Sin UNION SQL para evitar errores de servidor)
$lista_movimientos = [];

// A. Agregar la Apertura
$lista_movimientos[] = [
    'hora' => date('H:i', strtotime($caja['fecha_apertura'])),
    'tipo' => 'Apertura',
    'detalle' => 'Fondo Inicial Recibido',
    'monto' => floatval($caja['monto_inicial']),
    'ticket' => 'SISTEMA',
    'id' => 0
];

// B. Obtener Ventas y sus productos (Lógica de Auditoría)
$stmtVentas = $conexion->prepare("SELECT id, fecha, total, metodo_pago, codigo_ticket FROM ventas WHERE id_caja_sesion = ? AND estado='completada' AND (tipo_negocio = ? OR tipo_negocio IS NULL)");
$stmtVentas->execute([$id_sesion, $rubro_actual]);
$ventas_db = $stmtVentas->fetchAll(PDO::FETCH_ASSOC);

foreach($ventas_db as $v) {
    // Buscar productos de esta venta
    $stmtD = $conexion->prepare("SELECT d.cantidad, p.descripcion FROM detalle_ventas d LEFT JOIN productos p ON d.id_producto = p.id WHERE d.id_venta = ?");
    $stmtD->execute([$v['id']]);
    $prods = $stmtD->fetchAll(PDO::FETCH_ASSOC);
    $detalle_prods = "";
    foreach($prods as $p) { $detalle_prods .= round($p['cantidad']) . "x " . ($p['descripcion'] ?: 'Item') . "<br>"; }

    $lista_movimientos[] = [
        'hora' => date('H:i', strtotime($v['fecha'])),
        'tipo' => (strpos($v['codigo_ticket'], 'RIFA-') === 0) ? 'Rifa' : 'Venta',
        'detalle' => $detalle_prods ?: $v['metodo_pago'],
        'monto' => floatval($v['total']),
        'ticket' => $v['codigo_ticket'] ?: 'Venta #'.$v['id'],
        'id' => $v['id']
    ];
}

// C. Obtener Gastos
$stmtGastos = $conexion->prepare("SELECT id, fecha, monto, categoria, descripcion FROM gastos WHERE id_caja_sesion = ? AND (tipo_negocio = ? OR tipo_negocio IS NULL)");
$stmtGastos->execute([$id_sesion, $rubro_actual]);
$gastos_db = $stmtGastos->fetchAll(PDO::FETCH_ASSOC);

foreach($gastos_db as $g) {
    $lista_movimientos[] = [
        'hora' => date('H:i', strtotime($g['fecha'])),
        'tipo' => 'Gasto',
        'detalle' => $g['descripcion'],
        'monto' => floatval($g['monto']),
        'ticket' => $g['categoria'],
        'id' => 0
    ];
}

// Ordenar todos los movimientos por hora de menor a mayor
usort($lista_movimientos, function($a, $b) { return strcmp($a['hora'], $b['hora']); });

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
                        <thead class="bg-light text-center">
                            <tr>
                                <th class="ps-4 text-start">Hora</th>
                                <th>Tipo</th>
                                <th class="text-start">Detalle / Productos</th>
                                <th class="text-end">Monto</th>
                                <th class="pe-4 text-end">Ticket</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($lista_movimientos as $m): 
                                $esGasto = ($m['tipo'] == 'Gasto');
                                $esApertura = ($m['tipo'] == 'Apertura');
                            ?>
                            <tr class="<?php echo $esApertura ? 'bg-light fw-bold text-primary' : ''; ?>">
                                <td class="ps-4 text-start text-muted small"><?php echo $m['hora']; ?> hs</td>
                                <td class="text-center">
                                    <?php if($esApertura): ?><span class="badge bg-primary">INICIO</span>
                                    <?php elseif($esGasto): ?><span class="badge bg-danger bg-opacity-10 text-danger">GASTO</span>
                                    <?php else: ?><span class="badge bg-success bg-opacity-10 text-success">VENTA</span><?php endif; ?>
                                </td>
                                <td class="text-start">
                                    <div class="fw-bold text-dark" style="font-size: 0.85rem;">
                                        <?php if($esApertura) echo $m['detalle']; else echo $m['ticket']; ?>
                                    </div>
                                    <div class="text-muted" style="font-size: 0.75rem; line-height: 1.2;"><?php echo $m['detalle']; ?></div>
                                </td>
                                <td class="text-end fw-bold <?php echo $esGasto ? 'text-danger' : ($esApertura ? 'text-primary' : 'text-dark'); ?>">
                                    <?php echo $esGasto ? '-' : ''; ?>$<?php echo number_format($m['monto'], 2, ',', '.'); ?>
                                </td>
                                <td class="pe-4 text-end">
                                    <?php if($m['tipo'] == 'Venta' || $m['tipo'] == 'Rifa'): ?>
                                        <a href="ticket.php?id=<?php echo $m['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary border-0 rounded-pill">
                                            <i class="bi bi-receipt fs-5"></i>
                                        </a>
                                    <?php endif; ?>
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
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted small">Efectivo Inicial:</span><span class="fw-bold">$<?php echo number_format($caja['monto_inicial'], 2, ',', '.'); ?></span></div>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted small">Ventas Brutas (+):</span><span class="text-success fw-bold">$<?php echo number_format($total_ventas + $total_rifas, 2, ',', '.'); ?></span></div>
                    <div class="d-flex justify-content-between mb-3"><span class="text-muted small">Gastos/Retiros (-):</span><span class="text-danger fw-bold">-$<?php echo number_format($total_gastos, 2, ',', '.'); ?></span></div>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-4"><span class="h6 mb-0 fw-bold text-muted">SALDO ESPERADO:</span><span class="h4 mb-0 fw-bold text-primary">$<?php echo number_format($caja['monto_inicial'] + ($total_ventas + $total_rifas) - $total_gastos, 2, ',', '.'); ?></span></div>
                    <div class="p-3 rounded-4 bg-white border border-2 <?php echo ($esFaltante) ? 'border-danger' : 'border-success'; ?>">
                        <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.65rem;">Efectivo Real declarado:</small>
                        <span class="h3 fw-bold d-block">$<?php echo number_format($caja['monto_final'], 2, ',', '.'); ?></span>
                        <div class="mt-2 <?php echo $esFaltante ? 'text-danger' : 'text-success'; ?> fw-bold">
                            <?php if($esFaltante): ?><i class="bi bi-dash-circle-fill"></i> FALTANTE: $<?php echo number_format(abs($diferencia), 2, ',', '.'); ?>
                            <?php elseif($diferencia > 0.01): ?><i class="bi bi-plus-circle-fill"></i> SOBRANTE: $<?php echo number_format($diferencia, 2, ',', '.'); ?>
                            <?php else: ?><i class="bi bi-check-circle-fill"></i> Caja Perfecta<?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/layout_footer.php'; ?>