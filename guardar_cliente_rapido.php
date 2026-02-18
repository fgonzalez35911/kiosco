<?php
// acciones/guardar_cliente_rapido.php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) { 
    echo json_encode(['status' => 'error', 'msg' => 'Sesión expirada']); exit; 
}

$nombre = trim($_POST['nombre'] ?? '');
$dni = trim($_POST['dni'] ?? '');
$telefono = trim($_POST['telefono'] ?? '');

if (empty($nombre)) {
    echo json_encode(['status' => 'error', 'msg' => 'El nombre es obligatorio']); exit;
}

try {
    // Tomamos el whatsapp de la caja como teléfono
    $tel_final = !empty($_POST['telefono']) ? $_POST['telefono'] : ($_POST['whatsapp'] ?? '');

    if (!empty($dni)) {
        // Verificamos si ya existe para no crear duplicados que rompan el buscador
        $check = $conexion->prepare("SELECT id, nombre FROM clientes WHERE dni = ? OR dni_cuit = ?");
        $check->execute([$dni, $dni]);
        $existe = $check->fetch(); 

        if ($existe) {
            echo json_encode(['status' => 'success', 'id' => $existe->id, 'nombre' => $existe->nombre, 'msg' => 'Ya existe']);
            exit;
        }
    }

    $dni_final = empty($dni) ? null : $dni;

    // Llenamos dni y dni_cuit con el mismo valor para que el buscador lo vea
    $sql = "INSERT INTO clientes (nombre, dni_cuit, dni, telefono, fecha_registro, limite_credito, saldo_deudor, puntos_acumulados) 
            VALUES (?, ?, ?, ?, NOW(), 0, 0, 0)";
    
    $stmt = $conexion->prepare($sql);
    $stmt->execute([$nombre, $dni_final, $dni_final, $tel_final]);
    
    $id = $conexion->lastInsertId();
    
    // ENVÍO DE BIENVENIDA (FUTBOLERO)
    $email_cli = $_POST['email'] ?? ''; 
    if(!empty($email_cli)) {
        enviarBienvenidaFutbolera($email_cli, $nombre, $conexion);
    }

    echo json_encode(['status' => 'success', 'id' => $id, 'nombre' => $nombre]);

} catch (PDOException $e) {
    // Si sigue fallando, devolvemos el error exacto para que sepas qué es
    $msg = 'Error BD';
    if(strpos($e->getMessage(), 'Duplicate entry') !== false) {
        $msg = 'Ese DNI ya está registrado en el sistema.';
    } else {
        $msg = 'Error técnico: ' . $e->getMessage();
    }
    echo json_encode(['status' => 'error', 'msg' => $msg]);
}
?>
