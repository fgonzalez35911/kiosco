<?php
// acciones/enviar_email_ganador.php - NOTIFICACIÓN PREMIUM
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../libs/PHPMailer/src/Exception.php';
require '../libs/PHPMailer/src/PHPMailer.php';
require '../libs/PHPMailer/src/SMTP.php';
require_once '../includes/db.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) { die(json_encode(['status' => 'error', 'msg' => 'Sesion expirada'])); }

$id_sorteo = $_POST['id_sorteo'] ?? 0;
$nombre_cliente = $_POST['cliente'] ?? '';
$email_destino = $_POST['email'] ?? '';
$premio = $_POST['premio'] ?? '';
$puesto = $_POST['puesto'] ?? '';
$ticket = $_POST['ticket'] ?? '';

if (empty($email_destino) || !$id_sorteo) {
    die(json_encode(['status' => 'error', 'msg' => 'Faltan datos.']));
}

try {
    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);
    $sorteo_info = $conexion->query("SELECT titulo FROM sorteos WHERE id = $id_sorteo")->fetch(PDO::FETCH_OBJ);

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.hostinger.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'info@federicogonzalez.net';
    $mail->Password   = 'Fmg35911@';
    $mail->SMTPSecure = 'ssl';
    $mail->Port       = 465;
    $mail->CharSet    = 'UTF-8';
    
    $mail->setFrom('info@federicogonzalez.net', $conf->nombre_negocio);
    $mail->addAddress($email_destino, $nombre_cliente);
    
    $mail->isHTML(true);
    $mail->Subject = "🏆 ¡FELICITACIONES! Ganaste en " . $conf->nombre_negocio;

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #102A57; border-radius: 15px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1);'>
        <div style='background: #102A57; padding: 30px; text-align: center;'>
            <h1 style='color: white; margin: 0;'>¡SOS EL GANADOR! 🏆</h1>
        </div>
        <div style='padding: 30px; color: #333; line-height: 1.6; text-align: center;'>
            <h2 style='color: #102A57;'>¡Hola, {$nombre_cliente}!</h2>
            <p>Resultaste ganador en el sorteo: <br><strong>\"{$sorteo_info->titulo}\"</strong></p>
            <div style='background: #f4f7fa; padding: 25px; border-radius: 10px; border: 2px dashed #102A57; margin: 25px 0;'>
                <p style='margin: 5px 0;'><strong>Puesto:</strong> #{$puesto}</p>
                <p style='margin: 5px 0; font-size: 20px; color: #198754;'><strong>PREMIO: {$premio}</strong></p>
                <p style='margin: 5px 0;'>Ticket Nro: #{$ticket}</p>
            </div>
            <p style='font-weight: bold; color: #102A57;'>¡Te esperamos en la tienda para retirar tu premio!</p>
        </div>
        <div style='background: #f8f9fa; text-align: center; padding: 15px; font-size: 11px; color: #777;'>
            Enviado por <strong>{$conf->nombre_negocio}</strong> - {$conf->direccion_local}
        </div>
    </div>";

    $mail->AltBody = "¡Felicidades {$nombre_cliente}! Ganaste el premio {$premio} en el sorteo {$sorteo_info->titulo}. Te esperamos en la tienda.";

    $mail->send();
    echo json_encode(['status' => 'success']);
} catch (Exception $e) { echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); }