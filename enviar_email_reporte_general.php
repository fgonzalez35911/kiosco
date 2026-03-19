<?php
// acciones/enviar_email_reporte_general.php - MOTOR DE ENVÍO DE REPORTE GENERAL (VANGUARD PRO)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../libs/PHPMailer/src/Exception.php';
require '../libs/PHPMailer/src/PHPMailer.php';
require '../libs/PHPMailer/src/SMTP.php';
require_once '../includes/db.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Sesión expirada']);
    exit;
}

$email_destino = $_POST['email'] ?? '';

// Verificamos que recibamos el archivo PDF real desde el navegador
if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'msg' => 'No se recibió el documento PDF correctamente.']);
    exit;
}

try {
    $conf = $conexion->query("SELECT nombre_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);
    
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
    $mail->addAddress($email_destino);
    
    // Adjuntamos el archivo que generó el navegador
    $mail->addAttachment($_FILES['pdf_file']['tmp_name'], 'Reporte_Inventario_Oficial_' . date('d-m-Y') . '.pdf');
    
    $mail->isHTML(true);
    $mail->Subject = "Reporte de Inventario Oficial - " . $conf->nombre_negocio;

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #102A57; border-radius: 10px; overflow: hidden;'>
        <div style='background: #102A57; padding: 20px; text-align: center;'>
            <h2 style='color: white; margin: 0;'>Reporte de Inventario Corporativo</h2>
        </div>
        <div style='padding: 30px; color: #333;'>
            <p>Hola, se adjunta el reporte de inventario oficial generado desde el sistema.</p>
            <p>Este documento contiene el detalle completo de existencias, la valorización por categorías y el resumen ejecutivo solicitado.</p>
            <table width='100%' style='background: #f4f7fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <tr><td><strong>Tipo de Reporte:</strong></td><td align='right'>Inventario Filtrado</td></tr>
                <tr><td><strong>Fecha de Emisión:</strong></td><td align='right'>" . date('d/m/Y H:i') . "</td></tr>
            </table>
            <p style='margin-top:30px;'>Saludos cordiales,<br><strong>{$conf->nombre_negocio}</strong></p>
        </div>
    </div>";

    $mail->send();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) { 
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); 
}