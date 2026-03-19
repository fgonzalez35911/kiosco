<?php
// acciones/guardar_comprobante.php - VERSIÓN MEJORADA CON DATOS EXTRAÍDOS
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) { die(json_encode(['status'=>'error','msg'=>'Sin sesión'])); }

$imagen_base64 = $_POST['imagen'] ?? '';
$texto_extraido = $_POST['texto'] ?? '';
$monto_esperado = floatval($_POST['monto'] ?? 0);
$metodo = $_POST['metodo'] ?? 'ocr';
$estado = $_POST['estado'] ?? 'Aprobado';

// Datos extraídos por IA
$cvu = $_POST['cvu'] ?? '';
$cuit = $_POST['cuit'] ?? '';
$operacion = $_POST['operacion'] ?? '';

if (empty($imagen_base64)) { die(json_encode(['status'=>'error', 'msg'=>'No se recibió imagen'])); }

$image_parts = explode(";base64,", $imagen_base64);
$image_type_aux = explode("image/", $image_parts[0]);
$image_type = $image_type_aux[1];
$image_base64 = base64_decode($image_parts[1]);

$directorio = '../uploads/comprobantes/';
if (!is_dir($directorio)) { mkdir($directorio, 0777, true); }

$nombre_archivo = 'comp_' . time() . '_' . uniqid() . '.' . $image_type;
$ruta_final = $directorio . $nombre_archivo;
$ruta_db = 'uploads/comprobantes/' . $nombre_archivo;

if (file_put_contents($ruta_final, $image_base64)) {
    try {
        $stmt = $conexion->prepare("INSERT INTO comprobantes_transferencia 
            (monto_esperado, texto_extraido, cvu_cbu, cuit_cuil, numero_operacion, imagen_ruta, metodo_captura, estado, fecha) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$monto_esperado, $texto_extraido, $cvu, $cuit, $operacion, $ruta_db, $metodo, $estado]);
        
        echo json_encode(['status'=>'success', 'id_comprobante' => $conexion->lastInsertId()]);
    } catch(Exception $e) {
        echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]);
    }
} else {
    echo json_encode(['status'=>'error', 'msg'=>'Error al guardar foto']);
}