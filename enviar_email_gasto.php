<?php
// acciones/enviar_email_gasto.php - DISEÑO PREMIUM CLONADO DE PROVEEDORES
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

$id_gasto = $_POST['id'] ?? 0;
$email_destino = $_POST['email'] ?? '';

if (!$id_gasto || empty($email_destino)) {
    die(json_encode(['status' => 'error', 'msg' => 'Faltan datos obligatorios.']));
}

try {
    // 1. OBTENER DATOS DEL GASTO (Operador que registró el gasto)
    $stmt = $conexion->prepare("SELECT g.*, u.usuario as operador, u.nombre_completo, r.nombre as nombre_rol 
                                 FROM gastos g 
                                 LEFT JOIN usuarios u ON g.id_usuario = u.id 
                                 LEFT JOIN roles r ON u.id_rol = r.id 
                                 WHERE g.id = ?");
    $stmt->execute([$id_gasto]);
    $mov = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$mov) throw new Exception('Registro de gasto inexistente.');

    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

    // 2. GENERACIÓN DEL PDF ADJUNTO (Réplica de ticket_gasto_pdf.php)
    $pdf = new FPDF('P', 'mm', array(80, 240));
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
    $pdf->Cell(0, 6, utf8_decode('COMPROBANTE GASTO'), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(0, 4, "OP: #" . str_pad($mov->id, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->Ln(3);
    $pdf->Cell(0, 5, "Fecha: " . date('d/m/Y H:i', strtotime($mov->fecha)), 0, 1, 'L');
    $pdf->MultiCell(0, 4, "Cat: " . utf8_decode(strtoupper($mov->categoria)), 0, 'L');
    $pdf->MultiCell(0, 4, "Operador: " . utf8_decode(strtoupper($mov->operador ?? 'ADMIN')), 0, 'L');

    $pdf->Ln(3);
    $pdf->SetFont('Courier', 'B', 9);
    $pdf->Cell(0, 5, "DETALLE:", 0, 1, 'L');
    $pdf->SetFont('Courier', '', 8);
    $pdf->MultiCell(0, 4, utf8_decode($mov->descripcion), 0, 'L');

    $pdf->Ln(3);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 14);
    $pdf->Cell(30, 8, "TOTAL:", 0, 0, 'L');
    $pdf->Cell(40, 8, "$" . number_format($mov->monto, 2, ',', '.'), 0, 1, 'R');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    // FIRMA
    $pdf->Ln(8);
    $y_firma = $pdf->GetY();
    $ruta_firma = "";
    
    if (isset($mov->id_usuario) && $mov->id_usuario > 0 && file_exists("../img/firmas/usuario_" . $mov->id_usuario . ".png")) {
        $ruta_firma = "../img/firmas/usuario_" . $mov->id_usuario . ".png";
    }

    if (!empty($ruta_firma) && file_exists($ruta_firma)) {
        $pdf->Image($ruta_firma, 25, $y_firma, 30);
        $pdf->SetY($y_firma + 12);
    } else { 
        $pdf->SetY($y_firma + 10); 
    }

    $pdf->SetFont('Courier', '', 8);
    $pdf->Cell(0, 4, "________________________", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 8);
    $n_op = $mov->nombre_completo ? $mov->nombre_completo : ($mov->operador ?? 'SISTEMA');
    $r_op = $mov->nombre_rol ? $mov->nombre_rol : "OPERADOR";
    $aclaracion = strtoupper($n_op) . " | " . strtoupper($r_op);
    $pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

    // QR
    $pdf->Ln(2);
    $protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $linkPdfPublico = $protocolo . "://" . str_replace("acciones/", "", $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF'])) . "/ticket_gasto_pdf.php?id=" . $id_gasto;
    $url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
    $y_qr = $pdf->GetY();
    $pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
    $pdf->SetY($y_qr + 27);
    $pdf->SetFont('Courier', '', 7);
    $pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

    $pdf_content = $pdf->Output('S');

    // 3. ENVÍO PHPMailer (Diseño corporativo)
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
    
    $mail->addStringAttachment($pdf_content, "Comprobante_Gasto_{$id_gasto}.pdf");
    $mail->isHTML(true);
    $mail->Subject = "Comprobante de Gasto - " . $conf->nombre_negocio;

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #dc3545; border-radius: 10px; overflow: hidden;'>
        <div style='background: #dc3545; padding: 20px; text-align: center;'>
            <h2 style='color: white; margin: 0;'>Gasto Operativo Registrado</h2>
        </div>
        <div style='padding: 30px; color: #333;'>
            <p>Se adjunta el comprobante del gasto registrado el " . date('d/m/Y', strtotime($mov->fecha)) . ".</p>
            <table width='100%' style='background: #f4f7fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <tr><td><strong>Operación Nro:</strong></td><td align='right'>#{$id_gasto}</td></tr>
                <tr><td><strong>Categoría:</strong></td><td align='right'>{$mov->categoria}</td></tr>
                <tr><td><strong>Importe:</strong></td><td align='right' style='color:#dc3545;'><strong>$" . number_format($mov->monto, 2, ',', '.') . "</strong></td></tr>
            </table>
            <p style='margin-top:30px;'>Saludos cordiales,<br><strong>{$conf->nombre_negocio}</strong></p>
        </div>
    </div>";

    $mail->send();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Error al enviar: ' . $e->getMessage()]);
}
?>