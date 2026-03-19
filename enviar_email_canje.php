<?php
// acciones/enviar_email_canje.php
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

$id_audit = $_POST['id'] ?? 0;
$email_destino = $_POST['email'] ?? '';

try {
    $stmt = $conexion->prepare("SELECT a.*, u.usuario as operador, u.id as id_operador, u.nombre_completo, r.nombre as nombre_rol 
                             FROM auditoria a 
                             JOIN usuarios u ON a.id_usuario = u.id 
                             JOIN roles r ON u.id_rol = r.id 
                             WHERE a.id = ? AND a.accion = 'CANJE'");
    $stmt->execute([$id_audit]);
    $canje = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$canje) throw new Exception("El registro no existe.");

    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

    $detalle_texto = $canje->detalles;
    $cliente_nombre = "Cliente General";
    $pts = "0";
    if (strpos($detalle_texto, '| Cliente:') !== false) {
        $partes = explode('| Cliente:', $detalle_texto);
        $detalle_texto = trim($partes[0]);
        $cliente_nombre = trim($partes[1]);
    }
    if (preg_match('/\(-(\d+)\s*pts\)/', $detalle_texto, $matches)) {
        $pts = $matches[1];
    }
    $detalle_texto = preg_replace('/\(-(\d+)\s*pts\)/', '', $detalle_texto);

    // GENERAR PDF EN MEMORIA
    $pdf = new FPDF('P', 'mm', array(80, 215));
    $pdf->AddPage();
    $pdf->SetMargins(4, 4, 4);
    $pdf->SetAutoPageBreak(true, 4);

    $ruta_logo = "../" . $conf->logo_url;
    if (!empty($conf->logo_url) && file_exists($ruta_logo)) {
        $pdf->Image($ruta_logo, 25, 4, 30);
        $pdf->Ln(22);
    } else { $pdf->Ln(5); }

    $pdf->SetFont('Courier', 'B', 13);
    $pdf->Cell(0, 6, utf8_decode(strtoupper($conf->nombre_negocio)), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 8);
    if ($conf->cuit) $pdf->Cell(0, 4, "CUIT: " . $conf->cuit, 0, 1, 'C'); 
    $pdf->Cell(0, 4, utf8_decode($conf->direccion_local), 0, 1, 'C');
    $pdf->Ln(2);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->SetFont('Courier', 'B', 12);
    $pdf->Cell(0, 6, utf8_decode('COMPROBANTE DE CANJE'), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(0, 4, "TICKET ORIGEN: #" . str_pad($canje->id, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->Ln(3);
    $pdf->Cell(0, 5, "Fecha Canje: " . date('d/m/Y', strtotime($canje->fecha)), 0, 1, 'L');
    $pdf->Cell(0, 5, "Cliente: " . utf8_decode(strtoupper($cliente_nombre)), 0, 1, 'L');
    $pdf->Cell(0, 5, "Operador: " . utf8_decode(strtoupper($canje->operador)), 0, 1, 'L');

    $pdf->Ln(3);
    $pdf->SetFont('Courier', 'B', 9);
    $pdf->Cell(0, 5, "DETALLE DE CANJE:", 0, 1, 'L');
    $pdf->SetFont('Courier', '', 8);
    $pdf->MultiCell(0, 4, utf8_decode(trim($detalle_texto)), 0, 'L');

    $pdf->Ln(3);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 14);
    $pdf->Cell(30, 8, "PUNTOS:", 0, 0, 'L');
    $pdf->Cell(40, 8, "-" . $pts . " pts", 0, 1, 'R');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->Ln(8);
    $y_firma = $pdf->GetY();
    $ruta_firma = "../img/firmas/firma_admin.png";
    if (isset($canje->id_operador) && file_exists("../img/firmas/usuario_" . $canje->id_operador . ".png")) {
        $ruta_firma = "../img/firmas/usuario_" . $canje->id_operador . ".png";
    }
    if (file_exists($ruta_firma)) {
        $pdf->Image($ruta_firma, 25, $y_firma, 30);
        $pdf->SetY($y_firma + 12);
    } else { $pdf->SetY($y_firma + 10); }

    $pdf->SetFont('Courier', '', 8);
    $pdf->Cell(0, 4, "________________________", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 8);
    $nom_op = !empty($canje->nombre_completo) ? $canje->nombre_completo : $canje->operador;
    $aclaracion = strtoupper($nom_op) . " | " . strtoupper($canje->nombre_rol ?? 'OPERADOR');
    $pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

    $pdf->Ln(4);
    $protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $linkPdfPublico = "http://" . str_replace("acciones/", "", $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF'])) . "/ticket_canje_puntos_pdf.php?id=" . $canje->id;
    $url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
    $y_qr = $pdf->GetY();
    $pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');

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
    $mail->addStringAttachment($pdf_content, "Comprobante_Canje_{$canje->id}.pdf");
    $mail->isHTML(true);
    $mail->Subject = "Comprobante de Canje - " . $conf->nombre_negocio;

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #102A57; border-radius: 10px; overflow: hidden;'>
        <div style='background: #102A57; padding: 20px; text-align: center;'>
            <h2 style='color: white; margin: 0;'>Comprobante de Canje</h2>
        </div>
        <div style='padding: 30px; color: #333;'>
            <p>Hola <strong>{$cliente_nombre}</strong>, se ha registrado un canje de puntos exitoso bajo el ticket <strong>#{$canje->id}</strong>.</p>
            
            <table width='100%' style='background: #f4f7fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <tr><td><strong>Ticket Original:</strong></td><td align='right'>#{$canje->id}</td></tr>
                <tr><td><strong>Fecha de Canje:</strong></td><td align='right'>" . date('d/m/Y', strtotime($canje->fecha)) . "</td></tr>
                <tr><td><strong>Puntos Canjeados:</strong></td><td align='right' style='color:#102A57;'><strong>-" . $pts . " pts</strong></td></tr>
            </table>

            <p>Se adjunta el comprobante oficial con el detalle del canje.</p>
            <p style='margin-top:30px;'>Saludos cordiales,<br><strong>{$conf->nombre_negocio}</strong></p>
        </div>
    </div>";

    $mail->send();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) { 
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); 
}
?>