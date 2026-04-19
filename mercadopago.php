<?php
// kiosco/webhooks/mercadopago.php
require_once '../includes/db.php';

// 1. CAPTURA TOTAL
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// 2. LOG DE ENTRADA (Esto tiene que aparecer en tu log_mp.txt sí o sí)
file_put_contents('log_mp.txt', "[" . date('Y-m-d H:i:s') . "] NOTIFICACIÓN: " . $json . PHP_EOL, FILE_APPEND);

// 3. EXTRAER ID (De la raíz o de 'data', como venga)
$id = $data['id'] ?? $data['data']['id'] ?? null;
$tipo = $data['type'] ?? $data['topic'] ?? null;

if ($id && $id != '123456') {
    // Obtenemos el Token (Usando -> porque db.php tiene FETCH_OBJ)
    $conf = $conexion->query("SELECT mp_access_token FROM configuracion WHERE id=1")->fetch();
    $token = $conf->mp_access_token;

    // Si es una merchant_order (como la que me pasaste), consultamos esa API
    if ($tipo == 'topic_merchant_order_wh' || $tipo == 'merchant_order') {
        $url = "https://api.mercadopago.com/merchant_orders/$id";
    } else {
        $url = "https://api.mercadopago.com/v1/payments/$id";
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    $res = json_decode(curl_exec($ch), true);

    // Buscamos el estado y la referencia
    $estado = $res['status'] ?? $res['order_status'] ?? '';
    $ref = $res['external_reference'] ?? '';

    // Si la orden está CERRADA o el pago APROBADO
    if ($estado == 'closed' || $estado == 'approved' || $estado == 'paid') {
        if (!empty($ref)) {
            $stmt = $conexion->prepare("UPDATE pagos_mp_pendientes SET estado = 'pagado' WHERE external_reference = ?");
            $stmt->execute([$ref]);
            file_put_contents('log_mp.txt', "[OK] Referencia $ref actualizada a PAGADO" . PHP_EOL, FILE_APPEND);
        }
    }
}

// Responder 200 siempre
http_response_code(200);
?>