<?php
// kiosco/acciones/verificar_pago_mp.php
require_once '../includes/db.php';
$ref = $_GET['referencia'] ?? '';
$stmt = $conexion->prepare("SELECT estado FROM pagos_mp_pendientes WHERE external_reference = ?");
$stmt->execute([$ref]);
$res = $stmt->fetch(); // FETCH_OBJ por defecto
echo json_encode(['estado' => $res->estado ?? 'esperando']);
?>