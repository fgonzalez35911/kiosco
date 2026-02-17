<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) { die(json_encode(['status' => 'error', 'msg' => 'Sesión expirada'])); }

$nombre = trim($_POST['nombre']);
$dni = trim($_POST['dni']);
$wa = trim($_POST['whatsapp']);
$email = trim($_POST['email']);

if (empty($nombre) || empty($dni)) { die(json_encode(['status' => 'error', 'msg' => 'Nombre y DNI son obligatorios'])); }

try {
    $sql = "INSERT INTO clientes (nombre, dni, whatsapp, email, fecha_registro) VALUES (?, ?, ?, ?, NOW())";
    $stmt = $conexion->prepare($sql);
    $stmt->execute([$nombre, $dni, $wa, $email]);
    $nuevo_id = $conexion->lastInsertId();
    
    echo json_encode([
        'status' => 'success', 
        'id' => $nuevo_id, 
        'nombre' => $nombre, 
        'puntos' => 0, 
        'saldo' => 0
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'DNI o Correo ya registrados']);
}
