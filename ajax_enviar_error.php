<?php
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Verifica que la ruta a libs/PHPMailer sea la misma que en test_mail.php
require 'libs/PHPMailer/src/Exception.php';
require 'libs/PHPMailer/src/PHPMailer.php';
require 'libs/PHPMailer/src/SMTP.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mensaje = $_POST['mensaje'] ?? 'Sin detalle';
    $url = $_POST['url'] ?? 'Desconocida';
    // Intentamos sacar el nombre del usuario logueado
    $usuario = $_SESSION['usuario_nombre'] ?? 'Empleado (ID: '.($_SESSION['usuario_id'] ?? 'Desconocido').')';
    $fecha = date('d/m/Y H:i:s');

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

        $mail->setFrom('info@federicogonzalez.net', 'Vanguard POS - Reportes');
        $mail->addAddress('info@federicogonzalez.net'); // A este correo llega el reporte

        $mail->isHTML(true);
        $mail->Subject = "🚨 Error Reportado en $url";
        
        $html = "
        <div style='font-family:Arial; border:1px solid #ddd; padding:20px; border-radius:10px; max-width:600px;'>
            <h2 style='color:#dc3545; margin-top:0;'>⚠️ Nuevo Reporte de Error</h2>
            <p><strong>Usuario:</strong> $usuario</p>
            <p><strong>Fecha y Hora:</strong> $fecha</p>
            <p><strong>Pantalla del Error:</strong> $url</p>
            <hr style='border:0; border-top:1px solid #eee; margin:20px 0;'>
            <h4 style='margin-bottom:10px;'>Detalle escrito por el usuario:</h4>
            <div style='background:#f8f9fa; padding:15px; border-left:4px solid #dc3545; color:#333; font-style:italic;'>
                " . nl2br(htmlspecialchars($mensaje)) . "
            </div>
        </div>";
        
        $mail->Body = $html;
        $mail->send();
        echo "OK";
    } catch (Exception $e) {
        echo "ERROR";
    }
}
?>
