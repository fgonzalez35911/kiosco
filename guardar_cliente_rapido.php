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
    // BLINDAJE PARA ERROR DB:
    // Si el DNI está vacío, pasamos NULL para que la base de datos no tire error de duplicado (si el campo es UNIQUE)
    $dni_final = empty($dni) ? null : $dni;

    // Usamos las columnas correctas según tu base de datos
    // dni_cuit es la clave única importante, dni es secundario. Llenamos ambos por consistencia.
    $sql = "INSERT INTO clientes (nombre, dni_cuit, dni, telefono, fecha_registro, limite_credito, saldo_deudor, puntos_acumulados) 
            VALUES (?, ?, ?, ?, NOW(), 0, 0, 0)";
    
    $stmt = $conexion->prepare($sql);
    // Pasamos $dni_final dos veces (para dni_cuit y dni)
    $stmt->execute([$nombre, $dni_final, $dni_final, $telefono]);
    
    $id = $conexion->lastInsertId();
    
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
