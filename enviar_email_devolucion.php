<?php
// acciones/enviar_email_devolucion.php - MOTOR DE ENVÍO CORREGIDO
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
    echo json_encode(['status' => 'error', 'msg' => 'Sesion expirada']);
    exit;
}

$id_venta = $_POST['id'] ?? 0;
$email_destino = $_POST['email'] ?? '';

try {
    // 1. OBTENER DATOS
    $stmtV = $conexion->prepare("SELECT v.*, u.usuario, c.nombre as cliente_n FROM ventas v JOIN usuarios u ON v.id_usuario = u.id JOIN clientes c ON v.id_cliente = c.id WHERE v.id = ?");
    $stmtV->execute([$id_venta]);
    $venta = $stmtV->fetch(PDO::FETCH_OBJ);
    
    if (!$venta) throw new Exception("La venta no existe.");

    $stmtD = $conexion->prepare("SELECT d.*, p.descripcion, u.usuario as operador, u.id as id_operador, u.nombre_completo, r.nombre as nombre_rol 
                             FROM devoluciones d 
                             JOIN productos p ON d.id_producto = p.id 
                             JOIN usuarios u ON d.id_usuario = u.id 
                             JOIN roles r ON u.id_rol = r.id 
                             WHERE d.id_venta_original = ?");
    $stmtD->execute([$id_venta]);
    $devoluciones = $stmtD->fetchAll(PDO::FETCH_OBJ);
    
    if (empty($devoluciones)) throw new Exception("No hay devoluciones para este ticket.");

    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

    // 2. GENERAR PDF EN MEMORIA (Sin salida al navegador)
    $pdf = new FPDF('P', 'mm', array(80, 200));
    $pdf->AddPage();
    $pdf->SetMargins(4, 4, 4);
    $pdf->SetAutoPageBreak(true, 4);

    // LOGO (Ruta ajustada desde /acciones)
    $ruta_logo = "../" . $conf->logo_url;
    if (!empty($conf->logo_url) && file_exists($ruta_logo)) {
        $pdf->Image($ruta_logo, 25, 4, 30);
        $pdf->Ln(22);
    } else { $pdf->Ln(5); }

    $pdf->SetFont('Courier', 'B', 13);
    $pdf->Cell(0, 6, utf8_decode(strtoupper($conf->nombre_negocio)), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 8);
    $pdf->Cell(0, 4, utf8_decode($conf->direccion_local), 0, 1, 'C');
    $pdf->Ln(2);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->SetFont('Courier', 'B', 12);
    $pdf->Cell(0, 6, utf8_decode('COMPROBANTE REINTEGRO'), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(0, 4, "TICKET ORIGEN: #" . str_pad($id_venta, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->Ln(3);
    $pdf->Cell(0, 5, "Fecha Venta: " . date('d/m/Y', strtotime($venta->fecha)), 0, 1, 'L');
    $pdf->Cell(0, 5, "Cliente: " . utf8_decode(strtoupper($venta->cliente_n)), 0, 1, 'L');
    $pdf->Cell(0, 5, "Operador: " . utf8_decode(strtoupper($devoluciones[0]->operador)), 0, 1, 'L');

    $pdf->Ln(3);
    $pdf->SetFont('Courier', 'B', 9);
    $pdf->Cell(0, 5, "DETALLE DE DEVOLUCION:", 0, 1, 'L');
    $pdf->SetFont('Courier', '', 8);

    $total_dev = 0;
    foreach($devoluciones as $d) {
        $pdf->MultiCell(0, 4, utf8_decode($d->cantidad . "x " . $d->descripcion . " (-$" . number_format($d->monto_devuelto, 2, ',', '.') . ")"), 0, 'L');
        $total_dev += $d->monto_devuelto;
    }

    $pdf->Ln(3);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 14);
    $pdf->Cell(30, 8, "TOTAL DEV:", 0, 0, 'L');
    $pdf->Cell(40, 8, "-$" . number_format($total_dev, 2, ',', '.'), 0, 1, 'R');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    // FIRMA EN EL PDF ADJUNTO
    $pdf->Ln(8);
    $y_firma = $pdf->GetY();
    $ruta_firma = "../img/firmas/firma_admin.png";
    if (isset($devoluciones[0]->id_operador) && file_exists("../img/firmas/usuario_" . $devoluciones[0]->id_operador . ".png")) {
        $ruta_firma = "../img/firmas/usuario_" . $devoluciones[0]->id_operador . ".png";
    }

    if (file_exists($ruta_firma)) {
        $pdf->Image($ruta_firma, 25, $y_firma, 30);
        $pdf->SetY($y_firma + 12);
    } else { $pdf->SetY($y_firma + 10); }

    $pdf->SetFont('Courier', '', 8);
    $pdf->Cell(0, 4, "________________________", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 8);
    $nom_op = !empty($devoluciones[0]->nombre_completo) ? $devoluciones[0]->nombre_completo : $devoluciones[0]->operador;
    $aclaracion = strtoupper($nom_op) . " | " . strtoupper($devoluciones[0]->nombre_rol ?? 'OPERADOR');
    $pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

    // QR DE VALIDACIÓN EN EL ADJUNTO
    $pdf->Ln(4);
    $protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $linkPdfPublico = "http://" . str_replace("acciones/", "", $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF'])) . "/ticket_devolucion_pdf.php?id=" . $id_venta;
    $url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
    $y_qr = $pdf->GetY();
    $pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
    $pdf->SetY($y_qr + 27);
    $pdf->SetFont('Courier', '', 7);
    $pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

    $pdf_content = $pdf->Output('S');

    // 3. ENVIAR EMAIL
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
    $mail->addStringAttachment($pdf_content, "Comprobante_Reintegro_{$id_venta}.pdf");
    $mail->isHTML(true);
    $mail->Subject = "Comprobante de Reintegro - " . $conf->nombre_negocio;

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #102A57; border-radius: 10px; overflow: hidden;'>
        <div style='background: #102A57; padding: 20px; text-align: center;'>
            <h2 style='color: white; margin: 0;'>Comprobante de Reintegro</h2>
        </div>
        <div style='padding: 30px; color: #333;'>
            <p>Hola <strong>{$venta->cliente_n}</strong>, se ha registrado una devolución de productos asociada al ticket <strong>#{$id_venta}</strong>.</p>
            
            <table width='100%' style='background: #f4f7fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <tr><td><strong>Ticket Original:</strong></td><td align='right'>#{$id_venta}</td></tr>
                <tr><td><strong>Fecha Original:</strong></td><td align='right'>" . date('d/m/Y', strtotime($venta->fecha)) . "</td></tr>
                <tr><td><strong>Monto Reintegrado:</strong></td><td align='right' style='color:#28a745;'><strong>$" . number_format($total_dev, 2, ',', '.') . "</strong></td></tr>
            </table>

            <p>Se adjunta el comprobante oficial con el detalle de los productos devueltos.</p>
            <p style='margin-top:30px;'>Saludos cordiales,<br><strong>{$conf->nombre_negocio}</strong></p>
        </div>
    </div>";

    $mail->send();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) { 
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); 
}