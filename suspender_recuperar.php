<?php
// CORRECCIÓN: Ruta relativa correcta desde carpeta 'acciones'
require_once '../includes/db.php';
header('Content-Type: application/json');

if(!isset($_GET['id'])) { die(json_encode(['status'=>'error', 'msg'=>'ID faltante'])); }
$id = $_GET['id'];

try {
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

    echo json_encode(['status'=>'success', 'items'=>$items]);

} catch (Exception $e) {
    echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]);
}
?>