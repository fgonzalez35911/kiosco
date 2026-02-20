<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) { die(json_encode(['status' => 'error', 'msg' => 'Sesión expirada'])); }

$nombre = trim($_POST['nombre']);
$dni = trim($_POST['dni']);
$email = trim($_POST['email']);
$user_sug = trim($_POST['usuario_form'] ?? '');
$telefono = trim($_POST['telefono'] ?? '');
$fecha_nac = !empty($_POST['fecha_nacimiento']) ? $_POST['fecha_nacimiento'] : null;
$direccion = trim($_POST['direccion'] ?? '');
$limite = floatval($_POST['limite'] ?? 0);

// Validación estricta: Los 3 pilares obligatorios
if (empty($nombre) || empty($dni) || empty($email)) { 
    die(json_encode(['status' => 'error', 'msg' => 'Nombre, DNI y Email son obligatorios'])); 
}

try {
    // Sincronización con estructura de 20 campos
    $sql = "INSERT INTO clientes (nombre, dni, dni_cuit, email, usuario, telefono, whatsapp, fecha_nacimiento, direccion, limite_credito, fecha_registro) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $conexion->prepare($sql);
    // Guardamos DNI en ambos campos para búsqueda y vinculamos Teléfono/WhatsApp
    $stmt->execute([$nombre, $dni, $dni, $email, $user_sug, $telefono, $telefono, $fecha_nac, $direccion, $limite]);
    $nuevo_id = $conexion->lastInsertId();
    
    echo json_encode([
        'status' => 'success', 
        'id' => $nuevo_id, 
        'nombre' => $nombre, 
        'puntos' => 0, 
        'saldo' => 0
    ]);
} catch (Exception $e) {
    // Capturamos si el error es por duplicado (DNI, Email o Usuario)
    $errorMsg = (strpos($e->getMessage(), 'Duplicate entry') !== false) 
                ? "El DNI, Email o Usuario ya están registrados." 
                : "Error en base de datos: " . $e->getMessage();
    echo json_encode(['status' => 'error', 'msg' => $errorMsg]);
}