<?php
ob_start();
session_start();
require_once 'includes/db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($_SESSION['cliente_id']) || empty($data['telefono'])) {
    ob_clean();
    echo json_encode(['exito' => false]);
    exit;
}

try {
    $stmt = $conexion->prepare("UPDATE clientes SET telefono = ? WHERE id = ?");
    $stmt->execute([$data['telefono'], $_SESSION['cliente_id']]);
    $_SESSION['cliente_telefono'] = $data['telefono'];
    session_write_close(); // CRÍTICO: Liberar sesión rápido
    ob_clean();
    echo json_encode(['exito' => true]);
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['exito' => false]);
}
?>