<?php
// ajax_ticket_detalle.php
session_start();
require_once 'includes/db.php';
if (!isset($_SESSION['usuario_id'])) { die("Denegado"); }

$id_venta = $_GET['id'] ?? 0;

$stmt = $conexion->prepare("SELECT v.*, u.usuario, c.nombre as cliente, c.dni_cuit as dni, c.puntos_acumulados
                            FROM ventas v 
                            JOIN usuarios u ON v.id_usuario = u.id 
                            JOIN clientes c ON v.id_cliente = c.id 
                            WHERE v.id = ?");
$stmt->execute([$id_venta]);
$venta = $stmt->fetch();

if(!$venta) die("No encontrado");

$stmtDet = $conexion->prepare("SELECT d.*, p.descripcion, p.precio_venta as precio_regular
                              FROM detalle_ventas d 
                              JOIN productos p ON d.id_producto = p.id 
                              WHERE d.id_venta = ?");
$stmtDet->execute([$id_venta]);
$detalles = $stmtDet->fetchAll();

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch();

$subtotal_real = 0;
foreach($detalles as $d) { $subtotal_real += $d->subtotal; }
?>
<div style="font-family: 'Courier New', monospace; text-align: left; color: #000; font-size: 12px; border: 1px solid #eee; padding: 10px; background: #fff;">
    <div style="text-align: center;">
        <h4 style="margin:0;"><?php echo $conf->nombre_negocio; ?></h4>
        <small>TICKET: #<?php echo str_pad($venta->id, 6, '0', STR_PAD_LEFT); ?></small><br>
        <small><?php echo date('d/m/Y H:i', strtotime($venta->fecha)); ?></small>
    </div>
    <hr style="border-top: 1px dashed #000; margin: 10px 0;">
    <table style="width: 100%;">
        <?php foreach($detalles as $d): ?>
        <tr>
            <td><?php echo floatval($d->cantidad); ?>x <?php echo substr($d->descripcion, 0, 18); ?></td>
            <td style="text-align: right;">$<?php echo number_format($d->subtotal, 2); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <hr style="border-top: 1px dashed #000; margin: 10px 0;">
    <div style="text-align: right; font-weight: bold; font-size: 14px;">
        TOTAL: $<?php echo number_format($venta->total, 2, ',', '.'); ?>
    </div>
    <div style="text-align: center; margin-top: 10px;">
        <small>Método: <?php echo $venta->metodo_pago; ?></small>
    </div>
</div>
