<?php
session_start();
// CORRECCIÓN: Al estar en la carpeta 'acciones', salimos una atrás (../) para buscar includes
require_once '../includes/db.php'; 

if (!isset($_SESSION['usuario_id'])) { die(json_encode(['status'=>'error', 'msg'=>'Sesión no iniciada'])); }

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) { die(json_encode(['status'=>'error', 'msg'=>'Datos no recibidos'])); }

$nombre_ref = $data['referencia'];
$carrito = $data['carrito'];
$total = $data['total'];
$usuario = $_SESSION['usuario_id'];

try {
    $conexion->beginTransaction();
    
    // 1. Guardar Cabecera
    $stmt = $conexion->prepare("INSERT INTO ventas_suspendidas (fecha, nombre_cliente_temporal, total, id_usuario) VALUES (NOW(), ?, ?, ?)");
    $stmt->execute([$nombre_ref, $total, $usuario]);
    $id_sus = $conexion->lastInsertId();
    
    // 2. Guardar Items
    $stmtItem = $conexion->prepare("INSERT INTO ventas_suspendidas_items (id_suspendida, id_producto, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?)");
    
    foreach($carrito as $prod) {
        $subtotal = $prod['precio'] * $prod['cantidad'];
        $stmtItem->execute([$id_sus, $prod['id'], $prod['cantidad'], $prod['precio'], $subtotal]);
    }
    $detalles_audit = "Venta dejada en espera. Ref: " . $nombre_ref . " | Total: $" . $total;
    $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'ESPERA', ?, NOW())")->execute([$usuario, $detalles_audit]);
    $conexion->commit();
    echo json_encode(['status'=>'success']);
} catch (Exception $e) {
    if($conexion->inTransaction()) $conexion->rollBack();
    echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]);
}
?>