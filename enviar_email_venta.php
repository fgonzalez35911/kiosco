<?php
// acciones/enviar_email_venta.php - DISEÑO PREMIUM (TICKET FPDF ADJUNTO)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../libs/PHPMailer/src/Exception.php';
require '../libs/PHPMailer/src/PHPMailer.php';
require '../libs/PHPMailer/src/SMTP.php';
require_once '../includes/db.php';
require_once '../fpdf/fpdf.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) { 
    die(json_encode(['status' => 'error', 'msg' => 'Sesión expirada'])); 
}

$id_venta = $_POST['id'] ?? 0;
$email_destino = $_POST['email'] ?? '';

if (!$id_venta || empty($email_destino)) {
    die(json_encode(['status' => 'error', 'msg' => 'Faltan datos obligatorios.']));
}

try {
    // 1. OBTENER DATOS DE LA VENTA
    $stmt = $conexion->prepare("SELECT v.*, c.nombre as cliente, u.usuario as operador, u.nombre_completo, r.nombre as nombre_rol 
                                 FROM ventas v 
                                 LEFT JOIN clientes c ON v.id_cliente = c.id 
                                 LEFT JOIN usuarios u ON v.id_usuario = u.id 
                                 LEFT JOIN roles r ON u.id_rol = r.id 
                                 WHERE v.id = ?");
    $stmt->execute([$id_venta]);
    $mov = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$mov) throw new Exception('Registro de venta inexistente.');

    // Traer los productos
    $stmtD = $conexion->prepare("SELECT dv.*, p.descripcion FROM detalle_ventas dv JOIN productos p ON dv.id_producto = p.id WHERE dv.id_venta = ?");
    $stmtD->execute([$id_venta]);
    $detalles = $stmtD->fetchAll(PDO::FETCH_OBJ);

    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

    // 2. GENERACIÓN DEL PDF ADJUNTO (TICKET 80MM)
    $altura_total = 180 + (count($detalles) * 5);
    if($altura_total < 240) $altura_total = 240;

    $pdf = new FPDF('P', 'mm', array(80, $altura_total));
    $pdf->AddPage();
    $pdf->SetMargins(4, 4, 4);
    $pdf->SetAutoPageBreak(true, 4);

    if (!empty($conf->logo_url)) {
        $ruta_logo = "../" . $conf->logo_url;
        if (file_exists($ruta_logo)) {
            $pdf->Image($ruta_logo, 25, 4, 30);
            $pdf->Ln(22);
        } else { $pdf->Ln(5); }
    } else { $pdf->Ln(5); }

    $pdf->SetFont('Courier', 'B', 13);
    $pdf->Cell(0, 6, utf8_decode(strtoupper($conf->nombre_negocio)), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 8);
    if ($conf->cuit) $pdf->Cell(0, 4, "CUIT: " . $conf->cuit, 0, 1, 'C');
    $pdf->Cell(0, 4, utf8_decode($conf->direccion_local), 0, 1, 'C');
    $pdf->Ln(2);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->SetFont('Courier', 'B', 12);
    $pdf->Cell(0, 6, utf8_decode('COMPROBANTE VENTA'), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 9);
    $num_ticket = !empty($mov->codigo_ticket) ? $mov->codigo_ticket : str_pad($mov->id, 6, '0', STR_PAD_LEFT);
    $pdf->Cell(0, 4, "TICKET: #" . $num_ticket, 0, 1, 'C');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->Ln(3);
    $pdf->Cell(0, 5, "Fecha: " . date('d/m/Y H:i', strtotime($mov->fecha)), 0, 1, 'L');
    $cliente_nom = !empty($mov->cliente) ? $mov->cliente : 'Consumidor Final';
    $pdf->MultiCell(0, 4, "Cliente: " . utf8_decode(strtoupper($cliente_nom)), 0, 'L');
    $pdf->MultiCell(0, 4, "Metodo: " . utf8_decode(strtoupper($mov->metodo_pago ?: 'EFECTIVO')), 0, 'L');
    $pdf->MultiCell(0, 4, "Cajero: " . utf8_decode(strtoupper($mov->nombre_completo ?: $mov->operador ?: 'ADMIN')), 0, 'L');

    $pdf->Ln(3);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 9);
    $pdf->Cell(10, 5, "CANT", 0, 0, 'L');
    $pdf->Cell(40, 5, "DESCRIPCION", 0, 0, 'L');
    $pdf->Cell(22, 5, "SUBTOT", 0, 1, 'R');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
    $pdf->SetFont('Courier', '', 8);

    foreach ($detalles as $d) {
        $pdf->Cell(10, 4, floatval($d->cantidad), 0, 0, 'C');
        $desc = substr(utf8_decode($d->descripcion), 0, 18);
        $pdf->Cell(40, 4, $desc, 0, 0, 'L');
        $pdf->Cell(22, 4, "$" . number_format($d->subtotal, 2, ',', '.'), 0, 1, 'R');
    }

    $pdf->Ln(3);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $descuentos = (float)$mov->descuento_monto_cupon + (float)$mov->descuento_manual;
    if ($descuentos > 0) {
        $pdf->SetFont('Courier', '', 9);
        $pdf->Cell(30, 5, "Descuentos:", 0, 0, 'L');
        $pdf->Cell(42, 5, "-$" . number_format($descuentos, 2, ',', '.'), 0, 1, 'R');
    }

    $pdf->SetFont('Courier', 'B', 14);
    $pdf->Cell(30, 8, "TOTAL:", 0, 0, 'L');
    $pdf->Cell(42, 8, "$" . number_format($mov->total, 2, ',', '.'), 0, 1, 'R');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    // FIRMA
    $pdf->Ln(2);
    $y_firma = $pdf->GetY();
    $ruta_firma = "";
    
    if (isset($mov->id_usuario) && $mov->id_usuario > 0 && file_exists("../img/firmas/usuario_" . $mov->id_usuario . ".png")) {
        $ruta_firma = "../img/firmas/usuario_" . $mov->id_usuario . ".png";
    }

    if (!empty($ruta_firma) && file_exists($ruta_firma)) {
        $pdf->Image($ruta_firma, 30, $y_firma - 8, 20);
        $pdf->SetY($y_firma + 12);
    } else { 
        $pdf->SetY($y_firma + 10); 
    }

    $pdf->SetFont('Courier', '', 8);
    $pdf->Cell(0, 4, "________________________", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 8);
    $n_op = $mov->nombre_completo ? $mov->nombre_completo : ($mov->operador ?? 'SISTEMA');
    $r_op = $mov->nombre_rol ? $mov->nombre_rol : "CAJERO";
    $aclaracion = strtoupper($n_op) . " | " . strtoupper($r_op);
    $pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

    // QR
    $pdf->Ln(2);
    $protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $linkPdfPublico = $protocolo . "://" . str_replace("acciones/", "", $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF'])) . "/ticket_digital.php?id=" . $id_venta;
    $url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
    $y_qr = $pdf->GetY();
    $pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
    $pdf->SetY($y_qr + 27);
    $pdf->SetFont('Courier', '', 7);
    $pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

    $pdf_content = $pdf->Output('S');

    // 3. ENVÍO PHPMailer (Diseño corporativo en VERDE para VENTAS)
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
    
    $mail->addStringAttachment($pdf_content, "Ticket_Venta_{$num_ticket}.pdf");
    $mail->isHTML(true);
    $mail->Subject = "Comprobante de Venta #" . $num_ticket . " - " . $conf->nombre_negocio;

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #198754; border-radius: 10px; overflow: hidden;'>
        <div style='background: #198754; padding: 20px; text-align: center;'>
            <h2 style='color: white; margin: 0;'>Comprobante de Compra</h2>
        </div>
        <div style='padding: 30px; color: #333;'>
            <p>Hola, adjuntamos tu comprobante oficial por la compra realizada el " . date('d/m/Y', strtotime($mov->fecha)) . ".</p>
            <table width='100%' style='background: #f4f7fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <tr><td><strong>Operación Nro:</strong></td><td align='right'>#{$num_ticket}</td></tr>
                <tr><td><strong>Cliente:</strong></td><td align='right'>{$cliente_nom}</td></tr>
                <tr><td><strong>Importe Total:</strong></td><td align='right' style='color:#198754; font-size:1.2em;'><strong>$" . number_format($mov->total, 2, ',', '.') . "</strong></td></tr>
            </table>
            <p style='text-align:center;'>Gracias por elegirnos.</p>
            <p style='margin-top:30px;'>Saludos cordiales,<br><strong>{$conf->nombre_negocio}</strong></p>
        </div>
    </div>";

    $mail->send();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Error al enviar: ' . $e->getMessage()]);
}
?>