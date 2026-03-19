<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Sesión expirada']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    
    // EL TRUCO: Si vienen vacíos, se convierten en NULL para que MySQL no bloquee por UNIQUE KEY
    $email = !empty(trim($_POST['email'] ?? '')) ? trim($_POST['email']) : null;
    $dni = !empty(trim($_POST['dni'] ?? '')) ? trim($_POST['dni']) : null;
    $usuario = !empty(trim($_POST['usuario'] ?? '')) ? trim($_POST['usuario']) : null;
    $whatsapp = !empty(trim($_POST['whatsapp'] ?? '')) ? trim($_POST['whatsapp']) : null;

    if (empty($nombre)) {
        echo json_encode(['status' => 'error', 'msg' => 'El nombre es obligatorio.']);
        exit;
    }

    $conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    $rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

    try {
        $sql = "INSERT INTO clientes (nombre, dni, dni_cuit, telefono, whatsapp, email, usuario, fecha_registro, saldo_deudor, puntos_acumulados, limite_credito, tipo_negocio) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 0, 0, 0, ?)";
        $stmt = $conexion->prepare($sql);
        $stmt->execute([$nombre, $dni, $dni, $whatsapp, $whatsapp, $email, $usuario, $rubro_actual]);
        
        $id = $conexion->lastInsertId();

        echo json_encode(['status' => 'success', 'id' => $id, 'nombre' => $nombre]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => 'Error BD: ' . $e->getMessage()]);
    }
}
?>