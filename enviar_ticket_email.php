<?php
// acciones/enviar_ticket_email.php - VERSIÓN PRODUCCIÓN FINAL
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../libs/PHPMailer/src/Exception.php';
require '../libs/PHPMailer/src/PHPMailer.php';
require '../libs/PHPMailer/src/SMTP.php';
require_once '../includes/db.php'; // Usa FETCH_OBJ

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) { 
    die(json_encode(['status' => 'error', 'msg' => 'Sesion expirada'])); 
}

$id_venta = $_POST['id'] ?? 0;

try {
    // 1. OBTENER DATOS (La conexión db.php ya usa FETCH_OBJ)
    $stmt = $conexion->prepare("SELECT v.*, c.email, c.nombre as cliente_nombre 
                                FROM ventas v 
                                JOIN clientes c ON v.id_cliente = c.id 
                                WHERE v.id = ?");
    $stmt->execute([$id_venta]);
    $data = $stmt->fetch();

    if (!$data || empty($data->email)) { 
        throw new Exception('El cliente no tiene un correo registrado.'); 
    }

    $conf = $conexion->query("SELECT nombre_negocio FROM configuracion WHERE id=1")->fetch();

    // 2. CONFIGURACIÓN SMTP (info@federicogonzalez.net)
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.hostinger.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'info@federicogonzalez.net';
    $mail->Password   = 'Fmg35911@';
    $mail->SMTPSecure = 'ssl';
    $mail->Port       = 465;
    
    // 3. BLINDAJE DE CARACTERES (Solución definitiva para Ã³)
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64'; 
    
    $mail->setFrom('info@federicogonzalez.net', $conf->nombre_negocio);
    $mail->addAddress($data->email, $data->cliente_nombre);
    $mail->isHTML(true);

    // ASUNTO
    $mail->Subject = "Ticket de Compra #$id_venta - " . $conf->nombre_negocio;

    // 4. CUERPO DEL MENSAJE (Premium Aesthetics)
    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #eee; padding: 20px;'>
        <div style='text-align: center; background: #102A57; padding: 20px;'>
            <h2 style='color: white; margin: 0;'>" . $conf->nombre_negocio . "</h2>
        </div>
        <div style='padding: 20px; color: #333;'>
            <h3>¡Hola, " . $data->cliente_nombre . "!</h3>
            <p>Gracias por tu compra. Acá tenés los detalles de tu ticket digital:</p>
            <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; font-family: monospace; border: 1px solid #ddd;'>
                <strong>TICKET:</strong> #" . str_pad($id_venta, 6, '0', STR_PAD_LEFT) . "<br>
                <strong>TOTAL:</strong> $" . number_format($data->total, 2, ',', '.') . "<br>
                <strong>MÉTODO:</strong> " . $data->metodo_pago . "
            </div>
            <p style='text-align: center; margin-top: 25px;'>
                <a href='https://" . $_SERVER['HTTP_HOST'] . "/ticket_digital.php?id=" . $id_venta . "' 
                   style='background: #102A57; color: white; padding: 12px 25px; text-decoration: none; border-radius: 50px; font-weight: bold;'>
                   VER MI TICKET ONLINE
                </a>
            </p>
        </div>
        <div style='text-align: center; font-size: 11px; color: #777; margin-top: 20px; border-top: 1px solid #eee; padding-top: 10px;'>
            Enviado desde el sistema de gestión de " . $conf->nombre_negocio . ".
        </div>
    </div>";

    $mail->send();
    echo json_encode(['status' => 'success', 'msg' => 'Ticket enviado correctamente']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Error: ' . $e->getMessage()]);
}
?>