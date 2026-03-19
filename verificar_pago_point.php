<?php
session_start();
require_once '../includes/db.php';

$intent_id = $_GET['intent_id'] ?? '';
if (empty($intent_id)) { echo json_encode(['estado' => 'ERROR']); exit; }

$stmt = $conexion->query("SELECT mp_access_token FROM configuracion WHERE id = 1");
$conf = $stmt->fetch(PDO::FETCH_ASSOC);

$url = "https://api.mercadopago.com/point/integration-api/payment-intents/$intent_id";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $conf['mp_access_token']]);
$response = curl_exec($ch);
curl_close($ch);

$resData = json_decode($response, true);

if (isset($resData['state'])) { echo json_encode(['estado' => $resData['state']]); } 
else { echo json_encode(['estado' => 'UNKNOWN']); }
?>