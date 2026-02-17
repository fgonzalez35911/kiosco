<?php
// acciones/suspender_eliminar.php
require_once '../includes/db.php';
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['usuario_id'])) { die(json_encode(['status'=>'error', 'msg'=>'Acceso denegado'])); }
if (!isset($_POST['id'])) { die(json_encode(['status'=>'error', 'msg'=>'ID faltante'])); }

$id = $_POST['id'];

try {
    $conexion->beginTransaction();

    // 1. Borrar items
    $stmt = $conexion->prepare("DELETE FROM ventas_suspendidas_items WHERE id_suspendida = ?");
    $stmt->execute([$id]);

    // 2. Borrar cabecera
    $stmt2 = $conexion->prepare("DELETE FROM ventas_suspendidas WHERE id = ?");
    $stmt2->execute([$id]);

    $conexion->commit();
    echo json_encode(['status'=>'success']);

} catch (Exception $e) {
    $conexion->rollBack();
    echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]);
}
?>