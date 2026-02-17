<?php
// historial_cajas.php - AUDITORÍA INTERACTIVA (VERSIÓN CORREGIDA)
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id']) || (isset($_SESSION['rol']) && $_SESSION['rol'] > 2)) {
    header("Location: dashboard.php"); exit;
}

// === A. MOTOR DE AUDITORÍA RÁPIDA (AJAX) ===
if (isset($_GET['ajax_detalle'])) {
    header('Content-Type: application/json');
    $id_ses = intval($_GET['ajax_detalle']);
    
    try {
        // 1. Cabecera de Caja
        $stmtC = $conexion->prepare("SELECT c.*, u.nombre_completo FROM cajas_sesion c JOIN usuarios u ON c.id_usuario = u.id WHERE c.id = ?");
        $stmtC->execute([$id_ses]);
        $caja = $stmtC->fetch(PDO::FETCH_ASSOC);

        if (!$caja) { echo json_encode(['status' => 'error']); exit; }

        // 2. Ventas por método
        $stmtV = $conexion->prepare("SELECT metodo_pago, SUM(total) as monto FROM ventas WHERE id_caja_sesion = ? AND estado='completada' GROUP BY metodo_pago");
        $stmtV->execute([$id_ses]);
        $ventas = $stmtV->fetchAll(PDO::FETCH_ASSOC);

        // 3. Gastos y su Suma Total (Evita el NaN)
        $stmtG = $conexion->prepare("SELECT descripcion, monto, categoria FROM gastos WHERE id_caja_sesion = ?");
        $stmtG->execute([$id_ses]);
        $gastos = $stmtG->fetchAll(PDO::FETCH_ASSOC);
        
        $total_gastos_calc = 0;
        foreach($gastos as $g) { $total_gastos_calc += floatval($g['monto']); }

        echo json_encode([
            'status' => 'success',
            'caja' => $caja,
            'total_gastos' => $total_gastos_calc,
            'ventas' => $ventas,
            'gastos' => $gastos
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); exit;
    }
}

// === B. LÓGICA DE CARGA NORMAL ===
$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_principal FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (!empty($dataC['color_principal'])) $color_sistema = $dataC['color_principal'];
    }
} catch (Exception $e) { }

$sql = "SELECT c.*, u.usuario, u.nombre_completo FROM cajas_sesion c JOIN usuarios u ON c.id_usuario = u.id ORDER BY c.id DESC LIMIT 50";
$cajas = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$total_ventas_hist = 0; $dif_neta = 0; $cajas_con_error = 0;
foreach($cajas as $c) {
    if($c['estado'] == 'cerrada') {
        $total_ventas_hist += floatval($c['total_ventas']);
        $dif_neta += floatval($c['diferencia']);
        if(abs(floatval($c['diferencia'])) > 0.01) $cajas_con_error++;
    }
}
?>

<?php include 'includes/layout_header.php'; ?>

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden;">
    <i class="bi bi-clock-history bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h2 class="font-cancha mb-0 text-white">Historial de Cajas</h2>
                <p class="opacity-75 mb-0 text-white small">Auditoría microscópica de cierres y control de diferencias.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm">VOLVER</a>
                <a href="cierre_caja.php" class="btn btn-warning fw-bold rounded-pill px-4 shadow-sm">CAJA ACTUAL</a>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4"><div class="header-widget">
                <div><div class="widget-label">Ventas (Últ. 50)</div><div class="widget-value text-white">$<?php echo number_format($total_ventas_hist, 0, ',', '.'); ?></div></div>
                <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-cash-stack"></i></div>
            </div></div>
            <div class="col-12 col-md-4"><div class="header-widget">
                <div><div class="widget-label">Balance de Diferencia</div><div class="widget-value <?php echo ($dif_neta < 0) ? 'text-danger' : 'text-success'; ?>">$<?php echo number_format($dif_neta, 2, ',', '.'); ?></div></div>
                <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-intersect"></i></div>
            </div></div>
            <div class="col-12 col-md-4"><div class="header-widget">
                <div><div class="widget-label">Alertas</div><div class="widget-value text-white"><?php echo $cajas_con_error; ?></div></div>
                <div class="icon-box bg-danger bg-opacity-20 text-white"><i class="bi bi-exclamation-octagon"></i></div>
            </div></div>
        </div>
    </div>
</div>

<div class="container mt-4 pb-5">
    <div class="card card-custom border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small uppercase">
                    <tr><th class="ps-4">ID</th><th>Responsable</th><th>Apertura/Cierre</th><th class="text-end">Ventas</th><th class="text-center">Estado</th><th class="text-end pe-4">Acciones</th></tr>
                </thead>
                <tbody>
                    <?php foreach($cajas as $c): 
                        $dif = floatval($c['diferencia']);
                        $apertura = date('d/m H:i', strtotime($c['fecha_apertura']));
                        $cierre = $c['fecha_cierre'] ? date('d/m H:i', strtotime($c['fecha_cierre'])) : '-';
                        $badge = ($c['estado'] == 'abierta') ? '<span class="badge bg-primary">ABIERTA</span>' : (abs($dif) < 0.01 ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">ERROR</span>');
                    ?>
                    <tr style="cursor: pointer;" onclick="abrirAuditoria(<?php echo $c['id']; ?>)">
                        <td class="ps-4">#<?php echo $c['id']; ?></td>
                        <td><div class="fw-bold"><?php echo htmlspecialchars($c['usuario']); ?></div></td>
                        <td><div class="small">Entrada: <?php echo $apertura; ?><br>Salida: <?php echo $cierre; ?></div></td>
                        <td class="text-end fw-bold">$<?php echo number_format($c['total_ventas'], 2, ',', '.'); ?></td>
                        <td class="text-center"><?php echo $badge; ?></td>
                        <td class="text-end pe-4"><button class="btn btn-sm btn-outline-primary rounded-pill px-3">AUDITAR</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAuditoria" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content rounded-4 border-0">
            <div id="headerAuditoria" class="modal-header text-white">
                <h5 class="fw-bold mb-0" id="tituloModal">Auditoría de Sesión</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="cuerpoAuditoria"></div>
        </div>
    </div>
</div>

<script>
function abrirAuditoria(id) {
    const modal = new bootstrap.Modal(document.getElementById('modalAuditoria'));
    const cuerpo = document.getElementById('cuerpoAuditoria');
    const header = document.getElementById('headerAuditoria');
    const titulo = document.getElementById('tituloModal');

    cuerpo.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
    modal.show();

    fetch(`historial_cajas.php?ajax_detalle=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                const c = data.caja;
                const gastos = data.total_gastos;
                const dif = parseFloat(c.diferencia);
                const esperado = parseFloat(c.monto_inicial) + parseFloat(c.total_ventas) - gastos;

                header.className = `modal-header text-white ${Math.abs(dif) < 0.01 ? 'bg-success' : 'bg-danger'}`;
                titulo.innerText = `Sesión #${c.id} - ${c.nombre_completo}`;

                cuerpo.innerHTML = `
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h6 class="fw-bold text-muted small text-uppercase mb-3">Balance de Efectivo</h6>
                            <div class="list-group list-group-flush border rounded-3">
                                <div class="list-group-item d-flex justify-content-between"><span>Efectivo Inicial</span><span>$${format(c.monto_inicial)}</span></div>
                                <div class="list-group-item d-flex justify-content-between"><span>Ventas (+)</span><span class="text-success fw-bold">+$${format(c.total_ventas)}</span></div>
                                <div class="list-group-item d-flex justify-content-between"><span>Egresos (-)</span><span class="text-danger fw-bold">-$${format(gastos)}</span></div>
                                <div class="list-group-item d-flex justify-content-between bg-light fw-bold"><span>SALDO ESPERADO</span><span>$${format(esperado)}</span></div>
                                <div class="list-group-item d-flex justify-content-between bg-dark text-white"><span>EFECTIVO DECLARADO</span><span class="h5 mb-0 fw-bold">$${format(c.monto_final)}</span></div>
                            </div>
                            <div class="mt-3 p-3 rounded-3 text-center ${Math.abs(dif) < 0.01 ? 'bg-success' : 'bg-danger'} text-white">
                                <div class="small text-uppercase fw-bold">Diferencia</div>
                                <div class="h4 mb-0 fw-bold">$${format(dif)}</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold text-muted small text-uppercase mb-3">Ventas por Método</h6>
                            <table class="table table-sm mb-4">
                                ${data.ventas.map(v => `<tr><td>${v.metodo_pago.toUpperCase()}</td><td class="text-end fw-bold">$${format(v.monto)}</td></tr>`).join('')}
                            </table>
                            <h6 class="fw-bold text-muted small text-uppercase mb-3">Detalle de Gastos</h6>
                            <table class="table table-sm">
                                ${data.gastos.map(g => `<tr><td>${g.descripcion}<br><small class="text-muted">${g.categoria}</small></td><td class="text-end text-danger">-$${format(g.monto)}</td></tr>`).join('')}
                                ${data.gastos.length === 0 ? '<tr><td colspan="2" class="text-center text-muted">Sin gastos</td></tr>' : ''}
                            </table>
                        </div>
                    </div>`;
            }
        });
}
function format(n) { return parseFloat(n).toLocaleString('es-AR', { minimumFractionDigits: 2 }); }
</script>

<?php include 'includes/layout_footer.php'; ?>