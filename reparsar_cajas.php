<?php
// REPARADOR DE EMERGENCIA - SIN DEPENDENCIAS
$token = "APP_USR-939518205127495-022316-9085b48e6e6a698f1ab0d7509316ac00-149438850";
$nuevo_id = "caja1";

// 1. Buscamos tus cajas
$ch = curl_init("https://api.mercadopago.com/pos");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
$res = json_decode(curl_exec($ch), true);

if (isset($res['results'][0]['id'])) {
    $internal_id = $res['results'][0]['id'];
    // 2. Le grabamos el ID que el sistema necesita
    $ch2 = curl_init("https://api.mercadopago.com/pos/$internal_id");
    curl_setopt($ch2, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode(["external_id" => $nuevo_id]));
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
    $exec = curl_exec($ch2);
    echo "<h1>¡CAJA REPARADA!</h1><p>Ahora Mercado Pago sabe que tu caja se llama técnicamente <b>$nuevo_id</b>.</p>";
} else {
    echo "No se encontraron cajas para reparar. Verificá el Token.";
}
?>