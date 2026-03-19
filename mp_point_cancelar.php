<?php
session_start();
require_once '../includes/db.php';

$intent_id = $_POST['intent_id'] ?? '';
if (empty($intent_id)) exit;

$stmt = $conexion->query("SELECT mp_access_token, mp_pos_id FROM configuracion WHERE id = 1");
$conf = $stmt->fetch(PDO::FETCH_ASSOC);

$url = "https://api.mercadopago.com/point/integration-api/devices/{$conf['mp_pos_id']}/payment-intents/$intent_id";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $conf['mp_access_token']]);
curl_exec($ch);
curl_close($ch);
?>