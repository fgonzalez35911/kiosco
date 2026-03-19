<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

$imagen_base64 = $_POST['imagen'] ?? '';
$monto_esperado = floatval($_POST['monto'] ?? 0);

if (empty($imagen_base64)) die(json_encode(['status'=>'error', 'msg'=>'Imagen vacía']));

// 1. Guardar copia de seguridad
$image_parts = explode(";base64,", $imagen_base64);
$image_decoded = base64_decode($image_parts[1]);
$nombre = 'comp_' . time() . '.jpg';
file_put_contents('../uploads/comprobantes/' . $nombre, $image_decoded);

// 2. OCR.SPACE ENGINE 2 (Gratis y mejor que Engine 1 para tickets)
$ch = curl_init('https://api.ocr.space/parse/image');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'apikey' => 'K82347384888957', 
    'base64Image' => $imagen_base64,
    'language' => 'spa',
    'OCREngine' => 2, // MOTOR 2 es la clave
    'scale' => true
]);
$response = curl_exec($ch);
curl_close($ch);

$res = json_decode($response, true);
$texto = $res['ParsedResults'][0]['ParsedText'] ?? '';

// 3. COMPARACIÓN NUMÉRICA PURA (Ignoramos todo lo que no sea número)
$solo_numeros_ticket = preg_replace('/[^0-9]/', '', $texto);
$solo_numeros_esperado = preg_replace('/[^0-9]/', '', number_format($monto_esperado, 2, '.', ''));
$solo_entero_esperado = preg_replace('/[^0-9]/', '', strval(floor($monto_esperado)));

// Buscamos si el monto (con o sin decimales) aparece en el chorro de números
$coincide = (strpos($solo_numeros_ticket, $solo_numeros_esperado) !== false || strpos($solo_numeros_ticket, $solo_entero_esperado) !== false);

// Extraer un número de operación probable (6 a 12 dígitos)
preg_match('/\b\d{6,12}\b/', $solo_numeros_ticket, $matches);
$operacion = $matches[0] ?? 'S/N';

$estado = $coincide ? 'Aprobado (OCR_Engine2)' : 'Revisión Manual';

try {
    $stmt = $conexion->prepare("INSERT INTO comprobantes_transferencia 
        (monto_esperado, texto_extraido, numero_operacion, imagen_ruta, metodo_captura, estado, fecha) 
        VALUES (?, ?, ?, ?, 'ENGINE_2_FILTERED', ?, NOW())");
    $stmt->execute([$monto_esperado, substr($texto, 0, 500), $operacion, 'uploads/comprobantes/'.$nombre, $estado]);
    
    echo json_encode(['status'=>'success', 'monto_leido' => $monto_esperado, 'operacion' => $operacion, 'coincide' => $coincide]);
} catch(Exception $e) {
    echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]);
}