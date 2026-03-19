<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) { die(json_encode(['status'=>'error','msg'=>'Sin sesión'])); }

$total = floatval($_POST['total'] ?? 0);
$conf = $conexion->query("SELECT mp_access_token, mp_pos_id FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);

if (empty($conf['mp_access_token']) || empty($conf['mp_pos_id'])) {
    die(json_encode(['status'=>'error', 'msg'=>'Faltan datos en Configuración.']));
}

$token = $conf['mp_access_token'];
$pos_id_config = trim($conf['mp_pos_id']);

// 1. Obtener User ID
$chId = curl_init("https://api.mercadopago.com/users/me");
curl_setopt($chId, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chId, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
$resId = json_decode(curl_exec($chId), true);
$realUserId = $resId['id'] ?? null;
curl_close($chId);

// 2. Intentar mandar el precio
$url = "https://api.mercadopago.com/instore/qr/seller/collectors/{$realUserId}/pos/{$pos_id_config}/orders";
$referencia = "V_" . time(); // Generamos la referencia única para este cobro
$data = [
    "external_reference" => $referencia,
    "title" => "Venta Kiosco",
    "description" => "Compra general",
    "total_amount" => $total,
    "items" => [[
        "title" => "Productos", 
        "unit_price" => $total, 
        "quantity" => 1, 
        "unit_measure" => "unit", 
        "total_amount" => $total
    ]]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode == 200 || $httpCode == 204) {
    // ANOTAMOS EN LA BD QUE ESPERAMOS ESTE PAGO (Usando la conexión de db.php)
    $stmt = $conexion->prepare("INSERT INTO pagos_mp_pendientes (external_reference, monto) VALUES (?, ?)");
    $stmt->execute([$referencia, $total]);
    
    // Devolvemos la referencia al JS para que pueda empezar a consultar
    echo json_encode(['status' => 'success', 'referencia' => $referencia]);

} else {
    // SI FALLA, BUSCAMOS LAS CAJAS REALES PARA DECIRTE EL NOMBRE
    $chBusca = curl_init("https://api.mercadopago.com/pos");
    curl_setopt($chBusca, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chBusca, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
    $resBusca = json_decode(curl_exec($chBusca), true);
    curl_close($chBusca);

    $cajas_encontradas = [];
    if(isset($resBusca['results'])) {
        foreach($resBusca['results'] as $c) { $cajas_encontradas[] = $c['external_id']; }
    }
    
    $lista = count($cajas_encontradas) > 0 ? implode(", ", $cajas_encontradas) : "NINGUNA";
    echo json_encode([
        'status' => 'error', 
        'msg' => "La caja '$pos_id_config' no existe. Tus cajas reales son: [$lista]. Verificá mayúsculas y espacios."
    ]);
}