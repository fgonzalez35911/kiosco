<?php
// acciones/analizar_con_gemini.php - MOTOR IA CON FALLBACK
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

$imagen_base64 = $_POST['imagen'] ?? '';
$monto_esperado = floatval($_POST['monto'] ?? 0);
$api_key = 'AIzaSyAwYAO-l3DP--C7ns9HLoJpQiHzjDJCAx8';

if (empty($imagen_base64)) { die(json_encode(['status'=>'error', 'msg'=>'No se recibió imagen'])); }

$image_parts = explode(";base64,", $imagen_base64);
$image_data = $image_parts[1];

// PROBAMOS LOS 3 NOMBRES DE MODELO POSIBLES PARA CUENTAS PRO
$modelos = ['gemini-1.5-flash-latest', 'gemini-1.5-flash', 'gemini-1.5-pro'];
$response_final = null;
$error_acumulado = "";

foreach ($modelos as $modelo) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/$modelo:generateContent?key=$api_key";
    
    $payload = [
        "contents" => [["parts" => [
            ["text" => "Analiza este comprobante. Monto esperado: $$monto_esperado. Devuelve SOLO un JSON: {\"monto_leido\":0.0, \"cuit\":\"\", \"cvu\":\"\", \"operacion\":\"\", \"coincide_monto\":true, \"texto_bruto\":\"\"}"],
            ["inline_data" => ["mime_type" => "image/jpeg", "data" => $image_data]]
        ]]],
        "generationConfig" => ["response_mime_type" => "application/json"]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $response_final = json_decode($res, true);
        break; // ¡FUNCIONÓ! Dejamos de probar.
    } else {
        $error_acumulado .= "Modelo $modelo falló con código $http_code. ";
    }
}

if (!$response_final) {
    die(json_encode(['status'=>'error', 'msg'=>'Google rechazó la conexión. Errores: ' . $error_acumulado]));
}

$gemini_text = $response_final['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
$datos = json_decode(trim($gemini_text), true);

// GUARDAR EN SERVIDOR Y BD
$directorio = '../uploads/comprobantes/';
if (!is_dir($directorio)) { mkdir($directorio, 0777, true); }
$nombre_archivo = 'comp_' . time() . '.jpg';
file_put_contents($directorio . $nombre_archivo, base64_decode($image_data));

$ruta_db = 'uploads/comprobantes/' . $nombre_archivo;
$cuit = preg_replace('/[^0-9]/', '', $datos['cuit'] ?? '');

try {
    $stmt = $conexion->prepare("INSERT INTO comprobantes_transferencia 
        (monto_esperado, texto_extraido, cvu_cbu, cuit_cuil, numero_operacion, imagen_ruta, metodo_captura, estado, fecha) 
        VALUES (?, ?, ?, ?, ?, ?, 'gemini_pro', ?, NOW())");
    $stmt->execute([$monto_esperado, $datos['texto_bruto'] ?? '', $datos['cvu'] ?? '', $cuit, $datos['operacion'] ?? '', $ruta_db, 'Aprobado (IA)']);
    
    echo json_encode(['status' => 'success', 'gemini' => $datos]);
} catch(Exception $e) {
    echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]);
}