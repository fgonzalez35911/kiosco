<?php
ob_start();
session_start();
require_once 'includes/db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['carrito'])) {
    ob_clean();
    echo json_encode(['exito' => false, 'error' => 'Carrito vacío']);
    exit;
}

$id_cliente = $_SESSION['cliente_id'] ?? null;
$nombre = trim($data['nombre'] ?? '');
$telefono = trim($data['telefono'] ?? '');
$email = trim($data['email'] ?? '');
$total = floatval($data['total'] ?? 0);
$carrito = $data['carrito'];

if (empty($nombre) || empty($email)) {
    ob_clean();
    echo json_encode(['exito' => false, 'error' => 'Nombre y Email son obligatorios']);
    exit;
}

// BLINDAJE FUERA DE LA TRANSACCIÓN (Para que no corte el proceso)
try {
    $conexion->exec("ALTER TABLE pedidos_whatsapp ADD COLUMN IF NOT EXISTS email_cliente VARCHAR(150) NULL AFTER telefono_cliente");
} catch(Exception $e) { /* Ignorar si ya existe */ }

try {
    $conexion->exec("ALTER TABLE productos ADD COLUMN IF NOT EXISTS stock_reservado DECIMAL(10,3) DEFAULT 0.000");
} catch(Exception $e) { /* Ignorar si ya existe */ }

try {
    $conexion->beginTransaction();

    // SI ESTÁ LOGUEADO, ACTUALIZAMOS
    if ($id_cliente) {
        $updCli = $conexion->prepare("UPDATE clientes SET telefono = ?, email = ? WHERE id = ?");
        $updCli->execute([$telefono, $email, $id_cliente]);
    }

    $stmt = $conexion->prepare("INSERT INTO pedidos_whatsapp (id_cliente, nombre_cliente, telefono_cliente, email_cliente, total) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$id_cliente, $nombre, $telefono, $email, $total]);
    $id_pedido = $conexion->lastInsertId();

    $stmtDet = $conexion->prepare("INSERT INTO pedidos_whatsapp_detalle (id_pedido, id_producto, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?)");

    foreach ($carrito as $item) {
        $sub = floatval($item['precio']) * floatval($item['cant']);
        $stmtDet->execute([$id_pedido, $item['id'], $item['cant'], $item['precio'], $sub]);
        
        // Sumamos al stock reservado
        $conexion->prepare("UPDATE productos SET stock_reservado = stock_reservado + ? WHERE id = ?")
                 ->execute([$item['cant'], $item['id']]);
    }

    $conexion->commit();
    
    ob_clean();
    echo json_encode(['exito' => true, 'id_pedido' => $id_pedido]);

} catch (Exception $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    ob_clean();
    echo json_encode(['exito' => false, 'error' => 'Error Interno: ' . $e->getMessage()]);
}
?>