<?php
// kiosco/acciones/verificar_pago_mp.php
require_once '../includes/db.php';
$ref = $_GET['referencia'] ?? '';

// 1. Buscamos rápido en la base de datos local
$stmt = $conexion->prepare("SELECT estado FROM pagos_mp_pendientes WHERE external_reference = ?");
$stmt->execute([$ref]);
$res = $stmt->fetch(PDO::FETCH_OBJ);
$estado = $res->estado ?? 'esperando';

// 2. Si la BD sigue esperando, consultamos directamente a la API de Mercado Pago (Magia para Cuenta DNI/MODO)
if ($estado === 'esperando') {
    $conf = $conexion->query("SELECT mp_access_token FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if (!empty($conf['mp_access_token'])) {
        $url = "https://api.mercadopago.com/merchant_orders/search?external_reference=" . urlencode($ref);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $conf['mp_access_token']]);
        $api_res = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!empty($api_res['elements']) && count($api_res['elements']) > 0) {
            $order = $api_res['elements'][0];
            // Si la orden está pagada o cerrada, confirmamos la venta
            if (isset($order['order_status']) && ($order['order_status'] === 'paid' || $order['status'] === 'closed')) {
                $estado = 'pagado';
                $conexion->prepare("UPDATE pagos_mp_pendientes SET estado = 'pagado' WHERE external_reference = ?")->execute([$ref]);
            }
        }
    }
}

echo json_encode(['estado' => $estado]);
?>