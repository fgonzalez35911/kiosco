<?php
// acciones/enviar_email_credencial.php - RECIBE EL BLOB Y LO MANDA POR EMAIL
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../libs/PHPMailer/src/Exception.php';
require '../libs/PHPMailer/src/PHPMailer.php';
require '../libs/PHPMailer/src/SMTP.php';
require_once '../includes/db.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) { echo json_encode(['status' => 'error', 'msg' => 'Sesión expirada']); exit; }

$email_destino = $_POST['email'] ?? '';
$id_usuario = $_POST['id'] ?? 0;

// Verificar que Javascript nos mandó el archivo PDF clonado
if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'msg' => 'No se recibió el documento PDF correctamente.']);
    exit;
}

try {
    $stmt = $conexion->prepare("SELECT nombre_completo, usuario FROM usuarios WHERE id = ?");
    $stmt->execute([$id_usuario]);
    $empleado = $stmt->fetch(PDO::FETCH_ASSOC);

    $conf = $conexion->query("SELECT nombre_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    $empresa = $conf['nombre_negocio'] ?? 'EMPRESA';
    
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.hostinger.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'info@federicogonzalez.net';
    $mail->Password   = 'Fmg35911@';
    $mail->SMTPSecure = 'ssl';
    $mail->Port       = 465;
    $mail->CharSet    = 'UTF-8';
    
    $mail->setFrom('info@federicogonzalez.net', $empresa);
    $mail->addAddress($email_destino);
    
    // Adjuntamos directamente el archivo que nos envió JS
    $mail->addAttachment($_FILES['pdf_file']['tmp_name'], 'Credencial_ID_' . $id_usuario . '.pdf');
    
    $mail->isHTML(true);
    $mail->Subject = "Tu Credencial de Acceso Oficial - " . $empresa;

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #102A57; border-radius: 10px; overflow: hidden;'>
        <div style='background: #102A57; padding: 20px; text-align: center; border-bottom: 4px solid #ff9800;'>
            <h2 style='color: white; margin: 0; letter-spacing: 1px;'>CREDENCIAL DIGITAL</h2>
        </div>
        <div style='padding: 30px; color: #333; background: #fafafa;'>
            <h3 style='color: #102A57; margin-top: 0;'>¡Hola " . strtoupper($empleado['nombre_completo']) . "!</h3>
            <p>Adjunto a este correo encontrarás tu <strong>Credencial Oficial de Personal</strong> generada por el sistema.</p>
            <p>En este documento (PDF) encontrarás tu número de ID y tu nombre de usuario de acceso (<strong>@" . $empleado['usuario'] . "</strong>).</p>
            <p style='margin-top:30px; font-weight: bold; color: #102A57;'>Gerencia / Recursos Humanos<br>{$empresa}</p>
        </div>
    </div>";

    $mail->send();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) { 
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); 
}
