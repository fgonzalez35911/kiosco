<?php
session_start();
require_once 'includes/db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $dni = trim($_POST['dni'] ?? '');
    $usuario = trim($_POST['usuario'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');

    if (empty($nombre)) {
        echo json_encode(['status' => 'error', 'message' => 'El nombre es obligatorio.']);
        exit;
    }

    try {
        $sql = "INSERT INTO clientes (nombre, email, dni, dni_cuit, usuario, telefono, fecha_registro, saldo_deudor, puntos_acumulados, limite_credito) 
                VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0, 0)";
        $stmt = $conexion->prepare($sql);
        $stmt->execute([$nombre, $email, $dni, $dni, $usuario, $telefono]);
        
        $id = $conexion->lastInsertId();
        
        echo json_encode([
            'status' => 'success', 
            'cliente' => ['id' => $id, 'nombre' => $nombre]
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error al guardar en BD. Verifica que el DNI o Email no estén duplicados.']);
    }
}
?>