<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../libs/PHPMailer/src/Exception.php';
require '../libs/PHPMailer/src/PHPMailer.php';
require '../libs/PHPMailer/src/SMTP.php';
require_once '../includes/db.php';
session_start();

$id_pedido = $_GET['id'] ?? 0;
$status = $_GET['status'] ?? ''; // 'aprobado', 'rechazado', 'no_retirado'

if (!$id_pedido) { header("Location: ../admin_pedidos_whatsapp.php"); exit; }

try {
    $stmt = $conexion->prepare("SELECT p.*, COALESCE(p.email_cliente, c.email) as email_final FROM pedidos_whatsapp p LEFT JOIN clientes c ON p.id_cliente = c.id WHERE p.id = ?");
    $stmt->execute([$id_pedido]);
    $p = $stmt->fetch(PDO::FETCH_OBJ);

    if (!$p || empty($p->email_final)) {
        header("Location: ../admin_pedidos_whatsapp.php?msg=SinEmail"); exit;
    }

    // Traemos el detalle para la tabla
    $stmtDet = $conexion->prepare("SELECT d.*, prod.descripcion FROM pedidos_whatsapp_detalle d JOIN productos prod ON d.id_producto = prod.id WHERE d.id_pedido = ?");
    $stmtDet->execute([$id_pedido]);
    $detalles = $stmtDet->fetchAll(PDO::FETCH_OBJ);

    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);
    $color_base = $conf->color_principal ?? '#102A57';
    $logo_img = !empty($conf->logo_url) ? "<img src='https://{$_SERVER['HTTP_HOST']}/{$conf->logo_url}' style='max-height: 60px;'>" : "<h1 style='color: white; margin: 0;'>{$conf->nombre_negocio}</h1>";

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
    $mail->addAddress($p->email_final);
    $mail->isHTML(true);

    // Armando la tabla de productos
    $tabla_html = "<table style='width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 14px;'>
                    <thead>
                        <tr style='background-color: #f8f9fa; border-bottom: 2px solid #ddd;'>
                            <th style='text-align: left; padding: 10px;'>Producto</th>
                            <th style='text-align: center; padding: 10px;'>Cant.</th>
                            <th style='text-align: right; padding: 10px;'>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>";
    foreach($detalles as $d) {
        $tabla_html .= "<tr>
                            <td style='padding: 10px; border-bottom: 1px solid #eee;'>{$d->descripcion}</td>
                            <td style='text-align: center; padding: 10px; border-bottom: 1px solid #eee;'>" . floatval($d->cantidad) . "</td>
                            <td style='text-align: right; padding: 10px; border-bottom: 1px solid #eee; font-weight: bold;'>$" . number_format($d->subtotal, 2) . "</td>
                        </tr>";
    }
    $tabla_html .= "</tbody>
                    <tfoot>
                        <tr>
                            <td colspan='2' style='text-align: right; padding: 15px 10px; font-size: 16px; font-weight: bold;'>TOTAL:</td>
                            <td style='text-align: right; padding: 15px 10px; font-size: 18px; font-weight: bold; color: {$color_base};'>$" . number_format($p->total, 2) . "</td>
                        </tr>
                    </tfoot>
                   </table>";

    // DISEÑO DEPENDIENDO DEL ESTADO
    if ($status === 'aprobado') {
        $mail->Subject = "¡Tu pedido está confirmado! - #" . $id_pedido;
        $fecha_r = date('d/m/Y H:i', strtotime($p->fecha_retiro));
        $cuerpo = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #ffffff; border: 1px solid #e0e0e0; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05);'>
            <div style='background: {$color_base}; padding: 25px; text-align: center;'>
                {$logo_img}
            </div>
            <div style='padding: 30px; color: #444;'>
                <h2 style='color: #198754; margin-top: 0;'>¡Pedido Confirmado! ✔️</h2>
                <p>Hola <strong>{$p->nombre_cliente}</strong>,</p>
                <p>Tu pedido <strong>#{$id_pedido}</strong> ha sido aprobado y la mercadería ya está separada para vos.</p>
                
                <div style='background: #e8f5e9; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 5px solid #198754;'>
                    <p style='margin: 0; font-size: 13px; color: #2e7d32; text-transform: uppercase; font-weight: bold;'>Límite para retirar:</p>
                    <p style='font-size: 18px; font-weight: bold; margin: 5px 0 0 0; color: #1b5e20;'>{$fecha_r}</p>
                </div>

                <h4 style='border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 30px;'>Detalle de tu compra</h4>
                {$tabla_html}
                
                <p style='margin-top: 30px; font-size: 13px; text-align: center; color: #888;'>Te esperamos en <strong>{$conf->nombre_negocio}</strong>.<br>{$conf->direccion_local}</p>
            </div>
        </div>";

    } else {
        $mail->Subject = "Aviso sobre tu pedido - #" . $id_pedido;
        $motivo_txt = !empty($p->motivo_cancelacion) ? $p->motivo_cancelacion : "Problemas de disponibilidad.";
        
        $titulo_canc = ($status === 'rechazado') ? "Pedido Rechazado" : "Reserva Cancelada";
        $intro_canc = ($status === 'rechazado') 
            ? "Lamentamos informarte que no pudimos aprobar tu pedido <strong>#{$id_pedido}</strong>." 
            : "Te informamos que tu reserva <strong>#{$id_pedido}</strong> ha sido dada de baja.";

        $cuerpo = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #ffffff; border: 1px solid #e0e0e0; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05);'>
            <div style='background: #333333; padding: 25px; text-align: center;'>
                {$logo_img}
            </div>
            <div style='padding: 30px; color: #444;'>
                <h2 style='color: #dc3545; margin-top: 0;'>{$titulo_canc} ❌</h2>
                <p>Hola <strong>{$p->nombre_cliente}</strong>,</p>
                <p>{$intro_canc}</p>
                
                <div style='background: #fff5f5; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 5px solid #dc3545;'>
                    <p style='margin: 0; font-size: 13px; color: #c62828; text-transform: uppercase; font-weight: bold;'>Motivo:</p>
                    <p style='font-size: 16px; font-weight: bold; margin: 5px 0 0 0; color: #b71c1c;'>{$motivo_txt}</p>
                </div>

                <h4 style='border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 30px;'>Lo que habías solicitado:</h4>
                {$tabla_html}
                
                <p style='margin-top: 30px; font-size: 13px; text-align: center; color: #888;'>Te pedimos disculpas por los inconvenientes.<br><strong>{$conf->nombre_negocio}</strong></p>
            </div>
        </div>";
    }

    $mail->Body = $cuerpo;
    $mail->send();
    header("Location: ../admin_pedidos_whatsapp.php?msg=EmailEnviado&correo=" . urlencode($p->email_final));
} catch (Exception $e) {
    header("Location: ../admin_pedidos_whatsapp.php?msg=ErrorEmail");
}
?>