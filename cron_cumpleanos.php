<?php
// cron_cumpleanos.php - VERSIÓN MARKETING DE ALTO IMPACTO
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/libs/PHPMailer/src/Exception.php';
require __DIR__ . '/libs/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/libs/PHPMailer/src/SMTP.php';
require __DIR__ . '/includes/db.php';

$log_file = __DIR__ . '/log_cumpleanos.txt';
$base_url = "http://" . $_SERVER['HTTP_HOST'] . "/el10"; // Ajustá si tu URL es distinta

try {
    // 1. Buscamos cumpleañeros con sus puntos
    $sql = "SELECT nombre, email, puntos_acumulados FROM clientes 
            WHERE MONTH(fecha_nacimiento) = MONTH(CURDATE()) 
            AND DAY(fecha_nacimiento) = DAY(CURDATE()) 
            AND email IS NOT NULL AND email != ''";
    $cumpleaneros = $conexion->query($sql)->fetchAll(PDO::FETCH_OBJ);

    if (count($cumpleaneros) == 0) exit;

    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);
    $color = $conf->color_barra_nav ?? '#102A57';

    // 2. Cargamos el catálogo de premios para ver qué pueden canjear
    $premios = $conexion->query("SELECT nombre, puntos_necesarios FROM premios WHERE activo=1 AND stock > 0 ORDER BY puntos_necesarios ASC LIMIT 3")->fetchAll(PDO::FETCH_OBJ);

    foreach ($cumpleaneros as $cliente) {
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
        $mail->addAddress($cliente->email, $cliente->nombre);
        $mail->isHTML(true);
        $mail->Subject = "🎈 ¡Sorpresa de Cumpleaños de " . $conf->nombre_negocio . "!";

        // Construcción de la lista de premios que puede alcanzar
        $html_premios = "";
        foreach($premios as $p) {
            $estado = ($cliente->puntos_acumulados >= $p->puntos_necesarios) ? "✅ ¡Ya lo tenés!" : "🎯 Te falta poco";
            $html_premios .= "
            <div style='background: #ffffff; padding: 10px; margin-bottom: 5px; border-radius: 8px; border: 1px solid #eee;'>
                <span style='font-weight: bold; color: $color;'>$p->nombre</span><br>
                <small style='color: #777;'>$p->puntos_necesarios Puntos | $estado</small>
            </div>";
        }

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f9f9f9; border-radius: 20px; overflow: hidden;'>
            <div style='background: $color; padding: 40px 20px; text-align: center; color: white;'>
                <h1 style='margin: 0; font-size: 32px;'>¡FELIZ CUMPLE! 🎂</h1>
                <p style='font-size: 18px; opacity: 0.9;'>Hoy el regalo lo hacés vos.</p>
            </div>
            
            <div style='padding: 30px; text-align: center;'>
                <h2 style='color: #333;'>¡Hola, " . explode(' ', $cliente->nombre)[0] . "!</h2>
                <p style='color: #555; font-size: 16px;'>En <strong>$conf->nombre_negocio</strong> no queríamos dejar pasar este día sin saludarte y recordarte que sos parte de nuestra hinchada.</p>
                
                <div style='background: white; border: 2px dashed $color; padding: 20px; border-radius: 15px; margin: 20px 0;'>
                    <span style='display: block; color: #777; font-size: 12px; text-transform: uppercase;'>Tenés acumulados:</span>
                    <span style='display: block; color: $color; font-size: 40px; font-weight: bold;'>$cliente->puntos_acumulados</span>
                    <span style='display: block; color: $color; font-weight: bold;'>PUNTOS EL 10</span>
                </div>

                <h3 style='color: #333; font-size: 18px;'>🎁 Mirá lo que podés canjear hoy:</h3>
                <div style='text-align: left; margin-bottom: 25px;'>
                    $html_premios
                </div>

               <a href='https://el10.federicogonzalez.net/tienda.php' style='display: inline-block; background: $color; color: white; padding: 15px 35px; border-radius: 50px; text-decoration: none; font-weight: bold; font-size: 18px; box-shadow: 0 4px 15px rgba(0,0,0,0.2);'>
                    🛍️ IR A LA TIENDA ONLINE
                </a>

                <p style='margin-top: 25px; font-size: 13px; color: #888;'>
                    O pasate por el local: <br>
                    <strong>$conf->direccion_local</strong>
                </p>
            </div>

            <div style='background: #eeeeee; padding: 20px; text-align: center; font-size: 11px; color: #999;'>
                Enviado con ❤️ por $conf->nombre_negocio <br>
                
            </div>
        </div>";

        $mail->AltBody = "¡Feliz Cumpleaños " . $cliente->nombre . "! Tenés " . $cliente->puntos_acumulados . " puntos para canjear en " . $conf->nombre_negocio;

        $mail->send();
    }
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Marketing enviado a " . count($cumpleaneros) . " personas.\n", FILE_APPEND);

} catch (Exception $e) {
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - ERROR MARKETING: " . $e->getMessage() . "\n", FILE_APPEND);
}