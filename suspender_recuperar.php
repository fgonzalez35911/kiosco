<?php
// suspender_recuperar.php - CON REGISTRO DE AUDITORÍA Y SESIÓN
session_start(); // FUNDAMENTAL para saber qué usuario recuperó la venta
require_once '../includes/db.php';
header('Content-Type: application/json');

if(!isset($_GET['id'])) { die(json_encode(['status'=>'error', 'msg'=>'ID faltante'])); }
$id = $_GET['id'];

// Obtener ID del usuario (si no hay sesión, se registra como admin=1 por seguridad)
$usuario_audit = isset($_SESSION['usuario_id']) ? $_SESSION['usuario_id'] : 1;

try {
    // 0. Obtener la referencia de la venta antes de borrarla para la auditoría
    $stmtRef = $conexion->prepare("SELECT nombre_cliente_temporal, total FROM ventas_suspendidas WHERE id = ?");
    $stmtRef->execute([$id]);
    $venta_rec = $stmtRef->fetch(PDO::FETCH_ASSOC);
    $referencia = $venta_rec ? $venta_rec['nombre_cliente_temporal'] : "Desconocida";
    $total_rec = $venta_rec ? $venta_rec['total'] : 0;

    // 1. Obtener items con datos actualizados
    $sql = "SELECT i.id_producto as id, i.cantidad, i.precio_unitario as precio, p.descripcion as nombre, p.codigo_barras as codigo
            FROM ventas_suspendidas_items i
            JOIN productos p ON i.id_producto = p.id
            WHERE i.id_suspendida = ?";
    $stmt = $conexion->prepare($sql);
    $stmt->execute([$id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Borrar de suspendidas
    $conexion->prepare("DELETE FROM ventas_suspendidas_items WHERE id_suspendida = ?")->execute([$id]);
    $conexion->prepare("DELETE FROM ventas_suspendidas WHERE id = ?")->execute([$id]);

    // 3. Registrar en Auditoría
    $detalles_audit = "Se recuperó la venta en espera: " . $referencia . " | Total aproximado: $" . number_format($total_rec, 2, ',', '.');
    $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'RECUPERADA', ?, NOW())")->execute([$usuario_audit, $detalles_audit]);

    // 4. Devolver éxito
    echo json_encode(['status'=>'success', 'items'=>$items]);

} catch (Exception $e) {
    echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]);
}
?>