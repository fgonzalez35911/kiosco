<?php
// acciones/enviar_email_transferencia.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../libs/PHPMailer/src/Exception.php';
require '../libs/PHPMailer/src/PHPMailer.php';
require '../libs/PHPMailer/src/SMTP.php';
require_once '../includes/db.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) { 
    die(json_encode(['status' => 'error', 'msg' => 'Sesión expirada'])); 
}

$id_transf = $_POST['id'] ?? 0;
$email_destino = $_POST['email'] ?? '';

if (!$id_transf || empty($email_destino)) {
    die(json_encode(['status' => 'error', 'msg' => 'Faltan datos obligatorios.']));
}

try {
    $stmt = $conexion->prepare("SELECT * FROM transferencias WHERE id = ?");
    $stmt->execute([$id_transf]);
    $transf = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transf) throw new Exception('Registro de transferencia inexistente.');

    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    $d = json_decode($transf['datos_json'], true);
    $monto = number_format((float)$transf['monto'], 2, ',', '.');
    $op = $d['op'] ?? $d['operacion'] ?? $d['nro_op'] ?? '-';
    $emisor = $d['nom_e'] ?? '-';
    $fecha = date('d/m/Y H:i', strtotime($transf['fecha_registro']));

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.hostinger.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'info@federicogonzalez.net';
    $mail->Password   = 'Fmg35911@';
    $mail->SMTPSecure = 'ssl';
    $mail->Port       = 465;
    $mail->CharSet    = 'UTF-8';
    
    $mail->setFrom('info@federicogonzalez.net', $conf['nombre_negocio']);
    $mail->addAddress($email_destino);
    
    // ADJUNTAR LA FOTO DEL COMPROBANTE QUE LEYÓ LA IA
    $rutas_cruda = $transf['imagen_base64'] ?? '';
    $rutas = [];
    $es_json = json_decode($rutas_cruda, true);
    if (is_array($es_json)) {
        foreach($es_json as $img) { if(!empty(trim($img))) $rutas[] = trim($img); }
    } else {
        $array_temp = preg_split('/[,;|]/', $rutas_cruda);
        foreach($array_temp as $img) {
            $img_limpia = trim(str_replace(['"', "'", '[', ']'], '', $img)); 
            if(!empty($img_limpia)) $rutas[] = $img_limpia;
        }
    }

    $contador = 1;
    foreach($rutas as $ruta) {
        if (strpos($ruta, 'data:image') === 0) {
            $parts = explode(";base64,", $ruta);
            if (count($parts) == 2) {
                $decoded = base64_decode($parts[1]);
                $mail->addStringAttachment($decoded, "Comprobante_{$id_transf}_parte{$contador}.jpg");
            }
        } else {
            $ruta_fisica = "../" . $ruta;
            if (file_exists($ruta_fisica)) {
                $mail->addAttachment($ruta_fisica, "Comprobante_{$id_transf}_parte{$contador}.jpg");
            }
        }
        $contador++;
    }

    $mail->isHTML(true);
    $mail->Subject = "Comprobante de Pago - " . $conf['nombre_negocio'];

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #102A57; border-radius: 10px; overflow: hidden;'>
        <div style='background: #102A57; padding: 20px; text-align: center;'>
            <h2 style='color: white; margin: 0;'>Detalle de Transferencia</h2>
        </div>
        <div style='padding: 30px; color: #333;'>
            <p>Hola,</p>
            <p>Se adjunta el comprobante de la transferencia validada por el sistema el día {$fecha}.</p>
            <table width='100%' style='background: #f4f7fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <tr><td><strong>Operación Nro:</strong></td><td align='right'>#{$op}</td></tr>
                <tr><td><strong>Remitente:</strong></td><td align='right'>{$emisor}</td></tr>
                <tr><td><strong>Monto:</strong></td><td align='right' style='color:#198754;'><strong>$" . $monto . "</strong></td></tr>
            </table>
            <p>Adjunto a este correo encontrará la captura de pantalla oficial.</p>
            <p style='margin-top:30px;'>Saludos cordiales,<br><strong>{$conf['nombre_negocio']}</strong></p>
        </div>
    </div>";

    $mail->send();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Error al enviar: ' . $e->getMessage()]);
}
?>