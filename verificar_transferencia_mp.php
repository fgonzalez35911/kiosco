<?php
// acciones/verificar_transferencia_mp.php - RADAR DE TRANSFERENCIAS
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) { die(json_encode(['status'=>'error','msg'=>'Sin sesión'])); }

$monto_esperado = floatval($_POST['monto'] ?? 0);
$nombre_esperado = trim(strtolower($_POST['nombre'] ?? ''));

if ($monto_esperado <= 0) {
    die(json_encode(['status'=>'error', 'msg'=>'Monto inválido']));
}

// 1. Obtener Token de MP de la BD
$conf = $conexion->query("SELECT mp_access_token FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
if (empty($conf['mp_access_token'])) {
    die(json_encode(['status'=>'error', 'msg'=>'Falta el Token de Mercado Pago en Configuración']));
}
$token = $conf['mp_access_token'];

// 2. Definir rango de tiempo: Buscamos en los últimos 15 minutos (por si el cliente se adelantó)
$fecha_limite = gmdate("Y-m-d\TH:i:s.000\Z", strtotime("-15 minutes"));

// 3. Consultar a Mercado Pago (Pagos Aprobados recientes)
$url = "https://api.mercadopago.com/v1/payments/search?status=approved&sort=date_created&criteria=desc&limit=20&begin_date={$fecha_limite}";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode != 200) {
    die(json_encode(['status'=>'esperando', 'msg'=>'Fallo al conectar con MP']));
}

$data = json_decode($response, true);

// 4. Analizar los pagos recibidos
if (isset($data['results']) && count($data['results']) > 0) {
    foreach ($data['results'] as $pago) {
        $monto_pago = floatval($pago['transaction_amount']);
        
        // A. ¿Coincide el monto exacto?
        if ($monto_pago === $monto_esperado) {
            
            // B. Si escribimos un nombre, verificamos que coincida
            if ($nombre_esperado !== '') {
                $descripcion = strtolower($pago['description'] ?? '');
                $nombre_mp = strtolower($pago['payer']['first_name'] ?? '');
                $apellido_mp = strtolower($pago['payer']['last_name'] ?? '');
                
                // Si la palabra clave está en la descripción, nombre o apellido
                if (strpos($descripcion, $nombre_esperado) !== false || 
                    strpos($nombre_mp, $nombre_esperado) !== false || 
                    strpos($apellido_mp, $nombre_esperado) !== false) {
                    
                    echo json_encode(['status'=>'success', 'msg'=>'Transferencia detectada']);
                    exit;
                }
            } else {
                // Si no pusimos nombre, con que coincida el monto es suficiente
                echo json_encode(['status'=>'success', 'msg'=>'Transferencia detectada']);
                exit;
            }
        }
    }
}

// Si llega acá, es porque no encontró la plata todavía
echo json_encode(['status'=>'esperando']);