<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['usuario_id'])) { echo json_encode(['status' => 'error', 'msg' => 'Sesión expirada.']); exit; }

$total = isset($_POST['total']) ? floatval($_POST['total']) : 0;
if ($total <= 0) { echo json_encode(['status' => 'error', 'msg' => 'Monto inválido.']); exit; }

$stmt = $conexion->query("SELECT mp_access_token, mp_pos_id FROM configuracion WHERE id = 1");
$conf = $stmt->fetch(PDO::FETCH_ASSOC);

if (empty($conf['mp_access_token']) || empty($conf['mp_pos_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Faltan credenciales de Mercado Pago en la Configuración.']); exit;
}

$access_token = $conf['mp_access_token'];
$device_id = $conf['mp_pos_id']; 

$url = "https://api.mercadopago.com/point/integration-api/devices/$device_id/payment-intents";
$data = [
    "amount" => $total,
    "description" => "Venta en Caja",
    "payment" => [ "installments" => 1, "type" => "credit_card", "installments_cost" => "seller" ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [ "Authorization: Bearer $access_token", "Content-Type: application/json" ]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$resData = json_decode($response, true);

if ($httpCode == 200 || $httpCode == 201) {
    echo json_encode(['status' => 'success', 'payment_intent_id' => $resData['id']]);
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Error MP: ' . ($resData['message'] ?? 'Desconocido')]);
}
?>