<?php
// acciones/test_mail.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../libs/PHPMailer/src/Exception.php';
require '../libs/PHPMailer/src/PHPMailer.php';
require '../libs/PHPMailer/src/SMTP.php';

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.hostinger.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'info@federicogonzalez.net';
    $mail->Password   = 'Fmg35911@';
    $mail->SMTPSecure = 'ssl';
    $mail->Port       = 465;

    // CONFIGURACIÓN DE CARACTERES
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';

    $mail->setFrom('info@federicogonzalez.net', 'Prueba del Sistema');
    $mail->addAddress('info@federicogonzalez.net');

    $mail->isHTML(true);
    
    // Asunto codificado para evitar "Â¡" o "Ã³"
    $subject = 'Prueba de conexión: ¡Acción Exitosa!';
    $mail->Subject = "=?UTF-8?B?".base64_encode($subject)."?=";

    $mail->Body = "
    <div style='font-family: sans-serif;'>
        <h1>¡Conexión Confirmada!</h1>
        <p>Este mensaje comprueba que los acentos (á, é, í, ó, ú) y la eñe (ñ) se ven correctamente.</p>
        <p>Drogstore El 10 - Gestión de Ventas.</p>
    </div>";

    $mail->send();
    echo "<h1>✅ Enviado sin errores de símbolos.</h1>";

} catch (Exception $e) {
    echo "<h1>❌ Error:</h1><p>{$mail->ErrorInfo}</p>";
}