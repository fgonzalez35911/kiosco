<?php
// acciones/test_mail.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 1. Carga de librerías (Asegurate de que la carpeta libs esté en la raíz)
require '../libs/PHPMailer/src/Exception.php';
require '../libs/PHPMailer/src/PHPMailer.php';
require '../libs/PHPMailer/src/SMTP.php';

try {
    $mail = new PHPMailer(true);
    
    // 2. Configuración del servidor SMTP
    $mail->isSMTP();
    $mail->Host       = 'smtp.hostinger.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'info@federicogonzalez.net'; // Tu correo
    $mail->Password   = 'Fmg35911@';                // Tu contraseña
    $mail->SMTPSecure = 'ssl';
    $mail->Port       = 465;

    // 3. Configuración del envío
    $mail->setFrom('info@federicogonzalez.net', 'Prueba de Sistema');
    $mail->addAddress('info@federicogonzalez.net'); // Enviate el correo a vos mismo

    $mail->isHTML(true);
    $mail->Subject = 'Prueba de conexión PHPMailer';
    $mail->Body    = '<h1>¡Conexión Exitosa!</h1><p>Si recibiste esto, la librería y el SMTP están configurados correctamente.</p>';

    $mail->send();
    echo "<h1>✅ El correo se envió correctamente.</h1><p>Revisá tu bandeja de entrada (e incluso la carpeta de SPAM).</p>";

} catch (Exception $e) {
    echo "<h1>❌ Error al enviar:</h1><p>{$mail->ErrorInfo}</p>";
    echo "<p>Verificá que la carpeta <b>libs/PHPMailer/src/</b> contenga los archivos necesarios.</p>";
}