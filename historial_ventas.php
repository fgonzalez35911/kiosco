<?php
// historial_ventas.php - GESTIÓN INTEGRAL DE TICKETS
session_start();
require_once 'includes/db.php';

// SEGURIDAD
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// KPIs (ESTADÍSTICAS)
$hoy = date('Y-m-d');
$stats = $conexion->query("SELECT 
    COUNT(*) as total_operaciones, 
    SUM(total) as monto_total 
    FROM ventas WHERE DATE(fecha) = '$hoy'")->fetch();

$ventas = $conexion->query("SELECT v.*, c.nombre as cliente, u.usuario 
    FROM ventas v 
    JOIN clientes c ON v.id_cliente = c.id 
    JOIN usuarios u ON v.id_usuario = u.id 
    ORDER BY v.fecha DESC LIMIT 100")->fetchAll();

include 'includes/layout_header.php';
?>

<div class="header-blue">
    <i class="bi bi-receipt bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Historial de Ventas</h2>
                <p class="opacity-75 mb-0 text-white small">Registro histórico de tickets y comprobantes.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Ventas Hoy</div>
                        <div class="widget-value text-white"><?php echo $stats->total_operaciones; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-ticket-perforated"></i></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Recaudación</div>
                        <div class="widget-value text-white">$<?php echo number_format($stats->monto_total, 0, ',', '.'); ?></div>
                    </div>
                    <div class="icon-box bg-success bg-opacity-20 text-white"><i class="bi bi-cash-stack"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <div class="card card-custom">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light small text-uppercase text-muted">
                    <tr>
                        <th class="ps-4 py-3">ID / Fecha</th>
                        <th>Cliente</th>
                        <th>Vendedor</th>
                        <th>Método</th>
                        <th class="text-end">Total</th>
                        <th class="text-center pe-4">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($ventas as $v): ?>
                    <tr>
                        <td class="ps-4">
                            <div class="fw-bold text-dark">#<?php echo str_pad($v->id, 6, '0', STR_PAD_LEFT); ?></div>
                            <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($v->fecha)); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($v->cliente); ?></td>
                        <td><span class="badge bg-light text-dark border"><i class="bi bi-person me-1"></i><?php echo strtoupper($v->usuario); ?></span></td>
                        <td><small class="fw-bold"><?php echo $v->metodo_pago; ?></small></td>
                        <td class="text-end fw-bold text-primary">$<?php echo number_format($v->total, 2, ',', '.'); ?></td>
                        <td class="text-center pe-4">
                            <button class="btn-action btn-eye" onclick="verTicketDetalle(<?php echo $v->id; ?>)">
                                <i class="bi bi-printer"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function verTicketDetalle(id) {
    Swal.fire({
        title: 'Cargando Ticket...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    fetch('ajax_ticket_detalle.php?id=' + id)
        .then(response => response.text())
        .then(html => {
            Swal.fire({
                width: 350,
                html: html,
                showConfirmButton: true,
                confirmButtonText: '<i class="bi bi-printer-fill"></i> IMPRIMIR',
                showCloseButton: true,
                customClass: {
                    confirmButton: 'btn btn-primary rounded-pill px-4'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const win = window.open('ticket.php?id=' + id, '_blank');
                    win.focus();
                }
            });
        });
}
</script>

<?php include 'includes/layout_footer.php'; ?>
