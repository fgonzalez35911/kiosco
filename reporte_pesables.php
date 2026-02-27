<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }
require 'includes/db.php';
require 'includes/layout_header.php';

$query = "SELECT dv.fecha, p.descripcion, p.precio_costo, p.precio_venta, SUM(dv.cantidad) as total_kg_vendidos, SUM(dv.subtotal) as recaudacion
          FROM detalle_ventas dv
          JOIN productos p ON dv.id_producto = p.id
          JOIN ventas v ON dv.id_venta = v.id
          WHERE p.tipo = 'pesable' AND v.estado = 'completada'
          GROUP BY p.id, dv.fecha
          ORDER BY dv.fecha DESC LIMIT 100";
$stmt = $conexion->prepare($query);
$stmt->execute();
$pesables = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h2><i class="bi bi-heptagon-half"></i> Reporte de Mermas y Pesables</h2>
            <button class="btn btn-success" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
        </div>
    </div>
    <div class="card shadow">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-bordered">
                    <thead class="table-dark">
                        <tr>
                            <th>Fecha</th>
                            <th>Producto</th>
                            <th>Total Vendido (Kg)</th>
                            <th>Recaudación</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($pesables as $p): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($p['fecha'])) ?></td>
                            <td><?= htmlspecialchars($p['descripcion']) ?></td>
                            <td class="fw-bold text-primary"><?= number_format($p['total_kg_vendidos'], 3) ?> Kg</td>
                            <td class="text-success">$<?= number_format($p['recaudacion'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($pesables)): ?>
                        <tr><td colspan="4" class="text-center">No hay registros de ventas de pesables.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require 'includes/layout_footer.php'; ?>