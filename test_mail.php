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

    // CONFIGURACIÓN DE CARACTERES FORZADA
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'quoted-printable';

    $mail->setFrom('info@federicogonzalez.net', 'Prueba del Sistema');
    $mail->addAddress('info@federicogonzalez.net');
    $mail->isHTML(true);
    
    // Función para limpiar cualquier texto antes de enviarlo
    function limpiar_utf8($texto) {
        return mb_convert_encoding($texto, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    }

    $mail->Subject = limpiar_utf8('Prueba de conexión: ¡Acción Exitosa!');
    
    $mail->Body = limpiar_utf8("
    <div style='font-family: sans-serif;'>
        <h1>¡Conexión Confirmada!</h1>
        <p>Este mensaje comprueba que los acentos (á, é, í, ó, ú) y la eñe (ñ) se ven correctamente.</p>
        <p>Drogstore El 10 - Gestión de Ventas.</p>
    </div>");

    $mail->send();
    echo "<h1>✅ Enviado. Revisá ahora tu correo.</h1>";

} catch (Exception $e) {
    echo "<h1>❌ Error:</h1><p>{$mail->ErrorInfo}</p>";
}