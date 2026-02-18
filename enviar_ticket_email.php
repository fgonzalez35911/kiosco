<?php
// acciones/enviar_ticket_email.php - CLON DEFINITIVO DE TICKET.PHP
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../libs/PHPMailer/src/Exception.php';
require '../libs/PHPMailer/src/PHPMailer.php';
require '../libs/PHPMailer/src/SMTP.php';
require_once '../fpdf/fpdf.php'; 
require_once '../includes/db.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) { 
    die(json_encode(['status' => 'error', 'msg' => 'Sesion expirada'])); 
}

$id_venta = $_POST['id'] ?? 0;

try {
    // 1. DATOS VENTA (Forzamos FETCH_OBJ para estabilidad)
    $stmt = $conexion->prepare("SELECT v.*, u.usuario, c.nombre as cliente_nombre, c.dni_cuit, c.puntos_acumulados, c.email 
                                FROM ventas v 
                                JOIN usuarios u ON v.id_usuario = u.id 
                                JOIN clientes c ON v.id_cliente = c.id 
                                WHERE v.id = ?");
    $stmt->execute([$id_venta]);
    $data = $stmt->fetch(PDO::FETCH_OBJ);

    if (!$data || empty($data->email)) { 
        throw new Exception('Cliente sin correo o venta inexistente.'); 
    }

    // 2. DETALLES PRODUCTOS
    $stmtDet = $conexion->prepare("SELECT d.*, p.descripcion, p.precio_venta as precio_regular 
                                  FROM detalle_ventas d 
                                  JOIN productos p ON d.id_producto = p.id 
                                  WHERE d.id_venta = ?");
    $stmtDet->execute([$id_venta]);
    $items = $stmtDet->fetchAll(PDO::FETCH_OBJ);

    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

    // --- CÁLCULO DE AHORROS (CLONADO DE TICKET.PHP) ---
    $subtotal_real_productos = 0;
    $ahorro_ofertas = 0;
    foreach($items as $item) {
        $subtotal_real_productos += $item->subtotal;
        if($item->precio_regular > $item->precio_historico) {
            $ahorro_ofertas += ($item->precio_regular - $item->precio_historico) * $item->cantidad;
        }
    }
    $ahorro_total = ($data->descuento_monto_cupon ?? 0) + ($data->descuento_manual ?? 0);
    $saldo_favor_usado = $subtotal_real_productos - $ahorro_total - $data->total;
    if($saldo_favor_usado < 0.05) $saldo_favor_usado = 0;
    $ahorro_final_cliente = $ahorro_total + $saldo_favor_usado + $ahorro_ofertas;

    // --- GENERACIÓN DEL PDF (RÉPLICA DE TICKET.PHP) ---
    $pdf = new FPDF('P', 'mm', array(80, 260)); 
    $pdf->AddPage();
    $pdf->SetMargins(4, 4, 4);
    $pdf->SetAutoPageBreak(true, 4);

    // LOGO (Sin tapar el texto)
    if(!empty($conf->logo_url)){
        $ruta_logo = '../' . $conf->logo_url;
        if(file_exists($ruta_logo)) {
            $pdf->Image($ruta_logo, 25, 6, 30);
            $pdf->Ln(25); // Espacio para que no tape el nombre
        }
    }

    $pdf->SetFont('Courier', 'B', 14);
    $pdf->Cell(0, 6, utf8_decode(strtoupper($conf->nombre_negocio)), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 9);
    if($conf->cuit) $pdf->Cell(0, 4, "CUIT: " . $conf->cuit, 0, 1, 'C');
    $pdf->Cell(0, 4, utf8_decode($conf->direccion_local), 0, 1, 'C');
    $pdf->Cell(0, 4, "Tel: " . $conf->telefono_whatsapp, 0, 1, 'C');
    $pdf->Ln(2);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
    
    $pdf->Cell(0, 4, "Ticket: #" . str_pad($id_venta, 6, '0', STR_PAD_LEFT), 0, 1, 'L');
    $pdf->Cell(0, 4, "Fecha: " . date('d/m/Y H:i', strtotime($data->fecha)), 0, 1, 'L');
    $pdf->Cell(0, 4, "Cajero: " . strtoupper($data->usuario), 0, 1, 'L');
    $pdf->Cell(0, 4, "Cliente: " . utf8_decode(substr($data->cliente_nombre, 0, 25)), 0, 1, 'L');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->SetFont('Courier', 'B', 9);
    $pdf->Cell(10, 5, 'Cant', 0, 0, 'L');
    $pdf->Cell(40, 5, 'Producto', 0, 0, 'L');
    $pdf->Cell(22, 5, 'Subt', 0, 1, 'R');
    $pdf->SetFont('Courier', '', 9);

    foreach($items as $item) {
        $pdf->Cell(10, 4, number_format($item->cantidad, 0), 0, 0, 'L');
        $pdf->Cell(40, 4, utf8_decode(substr($item->descripcion, 0, 20)), 0, 0, 'L');
        $pdf->Cell(22, 4, "$" . number_format($item->subtotal, 0, ',', '.'), 0, 1, 'R');
        if($item->precio_regular > 0 && $item->precio_historico < $item->precio_regular) {
            $pdf->SetFont('Courier', '', 7);
            $pdf->Cell(10, 3, "", 0, 0);
            $pdf->Cell(0, 3, utf8_decode("(Antes: $" . number_format($item->precio_regular, 0, ',', '.') . ")"), 0, 1, 'L');
            $pdf->SetFont('Courier', '', 9);
        }
    }

    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(45, 5, "Subtotal:", 0, 0, 'R');
    $pdf->Cell(27, 5, "$" . number_format($subtotal_real_productos, 0, ',', '.'), 0, 1, 'R');
    
    if($saldo_favor_usado > 0) {
        $pdf->Cell(45, 5, "Saldo Favor:", 0, 0, 'R');
        $pdf->Cell(27, 5, "-$" . number_format($saldo_favor_usado, 0, ',', '.'), 0, 1, 'R');
    }

    $pdf->SetFont('Courier', 'B', 12);
    $pdf->Cell(45, 7, "TOTAL:", 0, 0, 'R');
    $pdf->Cell(27, 7, "$" . number_format($data->total, 0, ',', '.'), 0, 1, 'R');
    
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(45, 5, "Metodo:", 0, 0, 'R');
    $pdf->Cell(27, 5, utf8_decode($data->metodo_pago), 0, 1, 'R');

    // CUADRO DE AHORRO
    if($ahorro_final_cliente > 0) {
        $pdf->Ln(2);
        $pdf->SetFont('Courier', 'B', 10);
        $pdf->Cell(0, 8, utf8_decode("!USTED AHORRO: $" . number_format($ahorro_final_cliente, 0, ',', '.') . "!"), 1, 1, 'C');
    }

    // PUNTOS
    if($data->dni_cuit !== '00000000') {
        $pdf->Ln(2);
        $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
        $pdf->SetFont('Courier', 'B', 10);
        $pdf->Cell(0, 6, "PUNTOS ACUMULADOS: " . number_format($data->puntos_acumulados ?? 0, 0), 0, 1, 'C');
        $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
    }
    
    $pdf->Ln(2);
    $pdf->SetFont('Courier', 'B', 9);
    $pdf->Cell(0, 4, utf8_decode("!REGISTRATE Y SUMA PUNTOS!"), 0, 1, 'C');
    $url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=http://" . $_SERVER['HTTP_HOST'] . "/registro_cliente.php?ref_venta=" . $id_venta;
    $pdf->Image($url_qr, 25, $pdf->GetY() + 2, 30, 30, 'PNG');
    $pdf->SetY($pdf->GetY() + 35);

    $pdf->SetFont('Courier', '', 8);
    $pdf->Cell(0, 4, utf8_decode("¿Hacer un pedido? WhatsApp:"), 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 10);
    $pdf->Cell(0, 5, $conf->telefono_whatsapp, 0, 1, 'C');
    $pdf->Ln(2);
    $pdf->SetFont('Courier', 'B', 9);
    $pdf->Cell(0, 4, utf8_decode($conf->mensaje_ticket), 0, 1, 'C');
    $pdf->Cell(0, 4, utf8_decode($conf->nombre_negocio), 0, 1, 'C');
    
    $pdf_content = $pdf->Output('S');

    // 4. ENVÍO PHPMailer
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
    $mail->addAddress($data->email, $data->cliente_nombre);
    $mail->addStringAttachment($pdf_content, "Ticket_#{$id_venta}.pdf");
    $mail->isHTML(true);
    $mail->Subject = "⚽ Tu Ticket de " . $conf->nombre_negocio;

    // 5. CUERPO DEL MENSAJE (Tono Positivo y Profesional)
    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #102A57; border-radius: 15px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1);'>
        <div style='background: #102A57; padding: 30px; text-align: center;'>
            <h1 style='color: white; margin: 0; font-size: 24px;'>¡Muchas gracias por elegirnos!</h1>
        </div>
        <div style='padding: 30px; color: #333; line-height: 1.6;'>
            <h2 style='color: #102A57; margin-top: 0;'>¡Hola, " . $data->cliente_nombre . "!</h2>
            <p>Es un gusto saludarte. Queríamos agradecerte por tu reciente visita a <strong>" . $conf->nombre_negocio . "</strong>. Para nosotros es un placer brindarte la mejor atención en cada compra.</p>
            
            <p>Adjuntamos a este correo tu <strong>ticket digital</strong> con el detalle de tu operación para que lo tengas disponible en cualquier momento.</p>
            
            <div style='background: #f4f7fa; padding: 20px; border-radius: 10px; border-left: 5px solid #102A57; margin: 20px 0;'>
                <table width='100%'>
                    <tr>
                        <td style='font-weight: bold; color: #555;'>Comprobante:</td>
                        <td align='right' style='color: #333;'>#" . str_pad($id_venta, 6, '0', STR_PAD_LEFT) . "</td>
                    </tr>
                    <tr>
                        <td style='font-weight: bold; color: #555;'>Monto de la compra:</td>
                        <td align='right' style='color: #102A57; font-size: 18px;'><strong>$" . number_format($data->total, 0, ',', '.') . "</strong></td>
                    </tr>
                </table>
            </div>

            <p style='text-align: center; margin-top: 30px; font-weight: bold; color: #102A57; font-size: 16px;'>
                ¡Esperamos verte pronto nuevamente!
            </p>
        </div>
        <div style='background: #f8f9fa; text-align: center; padding: 15px; font-size: 11px; color: #777; border-top: 1px solid #eee;'>
            Este es un correo automático enviado por <strong>" . $conf->nombre_negocio . "</strong>.<br>
            " . $conf->direccion_local . "
        </div>
    </div>";

    $mail->send();
    echo json_encode(['status' => 'success', 'msg' => 'Ticket enviado']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Error: ' . $e->getMessage()]);
}
?>