<?php
// acciones/enviar_email_proveedor.php - CLON DEL DISEÑO DE TICKET.PHP
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../libs/PHPMailer/src/Exception.php';
require '../libs/PHPMailer/src/PHPMailer.php';
require '../libs/PHPMailer/src/SMTP.php';
require_once '../includes/db.php';
require_once '../fpdf/fpdf.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) { die(json_encode(['status' => 'error', 'msg' => 'Sesion expirada'])); }

$id_mov = $_POST['id'] ?? 0;
$email_destino = $_POST['email'] ?? '';

if (!$id_mov || empty($email_destino)) {
    die(json_encode(['status' => 'error', 'msg' => 'Faltan datos obligatorios.']));
}

try {
    $stmt = $conexion->prepare("SELECT m.*, p.empresa, u.usuario as operador, u.nombre_completo FROM movimientos_proveedores m JOIN proveedores p ON m.id_proveedor = p.id LEFT JOIN usuarios u ON m.id_usuario = u.id WHERE m.id = ?");
    $stmt->execute([$id_mov]);
    $mov = $stmt->fetch(PDO::FETCH_OBJ);
    if (!$mov) throw new Exception('Movimiento inexistente.');

    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

    // --- GENERACIÓN DEL PDF IDÉNTICO AL DESCARGABLE ---
    $pdf = new FPDF('P', 'mm', array(80, 210));
    $pdf->AddPage();
    $pdf->SetMargins(4, 4, 4);
    $pdf->SetAutoPageBreak(true, 4);

    if (!empty($conf->logo_url) && file_exists("../" . $conf->logo_url)) {
        $pdf->Image("../" . $conf->logo_url, 25, 4, 30);
        $pdf->Ln(22);
    } else { $pdf->Ln(5); }

    $pdf->SetFont('Courier', 'B', 13);
    $pdf->Cell(0, 6, utf8_decode(strtoupper($conf->nombre_negocio)), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 8);
    if ($conf->cuit) $pdf->Cell(0, 4, "CUIT: " . $conf->cuit, 0, 1, 'C');
    $pdf->Cell(0, 4, utf8_decode($conf->direccion_local), 0, 1, 'C');
    $pdf->Ln(2);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $titulo = ($mov->tipo == 'compra') ? 'FACTURA RECIBIDA' : 'RECIBO DE PAGO';
    $pdf->SetFont('Courier', 'B', 12);
    $pdf->Cell(0, 6, utf8_decode($titulo), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(0, 4, "OP: #" . str_pad($mov->id, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->Ln(3);
    $pdf->Cell(0, 5, "Fecha: " . date('d/m/Y H:i', strtotime($mov->fecha)), 0, 1, 'L');
    $pdf->Cell(0, 5, "Proveedor: " . utf8_decode(strtoupper($mov->empresa)), 0, 1, 'L');
    $pdf->Cell(0, 5, "Operador: " . utf8_decode(strtoupper($mov->operador ?? 'ADMIN')), 0, 1, 'L');

    $pdf->Ln(3);
    $pdf->SetFont('Courier', 'B', 9);
    $pdf->Cell(0, 5, "CONCEPTO/DETALLE:", 0, 1, 'L');
    $pdf->SetFont('Courier', '', 8);
    $pdf->MultiCell(0, 4, utf8_decode($mov->descripcion), 0, 'L');

    $pdf->Ln(3);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 14);
    $pdf->Cell(30, 8, "TOTAL:", 0, 0, 'L');
    $pdf->Cell(40, 8, "$" . number_format($mov->monto, 2, ',', '.'), 0, 1, 'R');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->Ln(8);
    $y_firma = $pdf->GetY();
    $ruta_firma = "../img/firmas/firma_admin.png";
    if (!file_exists($ruta_firma) && file_exists("../img/firmas/usuario_" . $mov->id_usuario . ".png")) {
        $ruta_firma = "../img/firmas/usuario_" . $mov->id_usuario . ".png";
    }

    if (file_exists($ruta_firma)) {
        $pdf->Image($ruta_firma, 25, $y_firma, 30);
        $pdf->SetY($y_firma + 12);
    } else { $pdf->SetY($y_firma + 10); }

    $pdf->SetFont('Courier', '', 8);
    $pdf->Cell(0, 4, "________________________", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 8);
    $aclaracion = $mov->nombre_completo ? strtoupper($mov->nombre_completo) : "FIRMA AUTORIZADA";
    $pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

    $pdf->Ln(4);
    $linkPdfPublico = "http://" . $_SERVER['HTTP_HOST'] . "/ticket_proveedor_pdf.php?id=" . $id_mov;
    $url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
    $y_qr = $pdf->GetY();
    $pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
    $pdf->SetY($y_qr + 27);
    $pdf->SetFont('Courier', '', 7);
    $pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

    $pdf_content = $pdf->Output('S');

    // --- ENVÍO PHPMailer ---
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
    $mail->addAddress($email_destino, $mov->empresa);
    
    $mail->addStringAttachment($pdf_content, "Comprobante_OP_{$id_mov}.pdf");
    $mail->isHTML(true);
    $mail->Subject = "Comprobante de Operación - " . $conf->nombre_negocio;

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #102A57; border-radius: 10px; overflow: hidden;'>
        <div style='background: #102A57; padding: 20px; text-align: center;'>
            <h2 style='color: white; margin: 0;'>Operación Registrada</h2>
        </div>
        <div style='padding: 30px; color: #333;'>
            <p>Hola <strong>{$mov->empresa}</strong>,</p>
            <p>Te enviamos adjunto en formato PDF el comprobante de la operación registrada el ".date('d/m/Y', strtotime($mov->fecha)).".</p>
            <table width='100%' style='background: #f4f7fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <tr><td><strong>Operación Nro:</strong></td><td align='right'>#{$id_mov}</td></tr>
                <tr><td><strong>Importe:</strong></td><td align='right' style='color:#102A57;'><strong>$" . number_format($mov->monto, 2, ',', '.') . "</strong></td></tr>
            </table>
            <p style='margin-top:30px;'>Saludos cordiales,<br><strong>{$conf->nombre_negocio}</strong></p>
        </div>
    </div>";

    $mail->send();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Error: ' . $e->getMessage()]);
}
?>