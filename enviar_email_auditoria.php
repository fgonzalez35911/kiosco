<?php
// acciones/enviar_email_auditoria.php - VERSIÓN CON PHPMAILER Y SMTP
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../libs/PHPMailer/src/Exception.php';
require '../libs/PHPMailer/src/PHPMailer.php';
require '../libs/PHPMailer/src/SMTP.php';
require_once '../includes/db.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'No autorizado']);
    exit;
}

$id = $_POST['id'] ?? null;
$email = $_POST['email'] ?? null;

if (!$id || !$email) {
    echo json_encode(['status' => 'error', 'msg' => 'Faltan datos']);
    exit;
}

// Configuración y Link
$conf = $conexion->query("SELECT nombre_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$nombre_negocio = $conf['nombre_negocio'] ?? 'Sistema EL 10';

$protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$ruta_base = str_replace("/acciones/enviar_email_auditoria.php", "", $_SERVER['PHP_SELF']);
$link_ticket = $protocolo . "://" . $_SERVER['HTTP_HOST'] . $ruta_base . "/ticket_auditoria_pdf.php?id=" . $id;

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.hostinger.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'info@federicogonzalez.net';
    $mail->Password   = 'Fmg35911@';
    $mail->SMTPSecure = 'ssl';
    $mail->Port       = 465;
    $mail->CharSet    = 'UTF-8';
    
    $mail->setFrom('info@federicogonzalez.net', $nombre_negocio);
    $mail->addAddress($email);
    
    $mail->isHTML(true);
    $mail->Subject = "Comprobante de Auditoría #" . $id . " - " . $nombre_negocio;

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #102A57; border-radius: 10px; overflow: hidden;'>
        <div style='background: #102A57; padding: 20px; text-align: center;'>
            <h2 style='color: white; margin: 0;'>Registro de Sistema</h2>
        </div>
        <div style='padding: 30px; color: #333; text-align: center;'>
            <p>Hola,</p>
            <p>Se ha generado un comprobante de auditoría <strong>(Operación #$id)</strong>.</p>
            <p>Puedes ver y descargar el documento oficial haciendo clic en el siguiente enlace seguro:</p>
            <div style='margin: 30px 0;'>
                <a href='$link_ticket' style='background-color: #102A57; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;'>VER COMPROBANTE</a>
            </div>
            <p style='margin-top:30px;'>Saludos cordiales,<br><strong>{$nombre_negocio}</strong></p>
        </div>
    </div>";

    $mail->send();
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Error de servidor de correo: ' . $mail->ErrorInfo]);
}