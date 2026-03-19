<?php
// acciones/enviar_email_caja.php - DISEÑO PREMIUM PARA CAJAS
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

$id_caja = $_POST['id'] ?? 0;
$email_destino = $_POST['email'] ?? '';

if (!$id_caja || empty($email_destino)) {
    die(json_encode(['status' => 'error', 'msg' => 'Faltan datos obligatorios.']));
}

try {
    $stmt = $conexion->prepare("SELECT c.*, u.usuario as operador, u.nombre_completo, r.nombre as nombre_rol 
                                 FROM cajas_sesion c 
                                 LEFT JOIN usuarios u ON c.id_usuario = u.id 
                                 LEFT JOIN roles r ON u.id_rol = r.id 
                                 WHERE c.id = ?");
    $stmt->execute([$id_caja]);
    $mov = $stmt->fetch(PDO::FETCH_OBJ);
    if (!$mov) throw new Exception('Registro de caja inexistente.');

    $stmtG = $conexion->prepare("SELECT COALESCE(SUM(monto), 0) as total FROM gastos WHERE id_caja_sesion = ?");
    $stmtG->execute([$id_caja]);
    $gastosTotal = $stmtG->fetch(PDO::FETCH_OBJ)->total;

    $es_abierta = ($mov->estado === 'abierta');
    $esperado = $mov->monto_inicial + $mov->total_ventas - $gastosTotal;
    $dif = $es_abierta ? 0 : $mov->diferencia;
    $txt_dif = $es_abierta ? 'PENDIENTE' : '$' . number_format($dif, 2, ',', '.');
    $color_dif = $es_abierta ? '#666666' : ($dif < -0.01 ? '#dc3545' : '#198754');

    $u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol 
                                 FROM usuarios u 
                                 JOIN roles r ON u.id_rol = r.id 
                                 WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
    $ownerRow = $u_owner->fetch(PDO::FETCH_ASSOC);
    $firmante = $ownerRow ? $ownerRow : ['nombre_completo' => 'RESPONSABLE', 'nombre_rol' => 'AUTORIZADO', 'id' => 0];

    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

    $pdf = new FPDF('P', 'mm', array(80, 210));
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
    $pdf->Cell(0, 6, utf8_decode('COMPROBANTE DE CAJA'), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(0, 4, "SESION: CJ-" . str_pad($mov->id, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->Ln(3);
    $pdf->Cell(0, 4, "Apertura: " . date('d/m/y H:i', strtotime($mov->fecha_apertura)), 0, 1, 'L');
    $pdf->Cell(0, 4, "Cierre: " . ($es_abierta ? 'PENDIENTE' : date('d/m/y H:i', strtotime($mov->fecha_cierre))), 0, 1, 'L');
    $pdf->MultiCell(0, 4, "Operador: " . utf8_decode(strtoupper($mov->nombre_completo ?? $mov->operador)), 0, 'L');

    $pdf->Ln(3);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 9);
    $pdf->Cell(0, 5, "BALANCE EFECTIVO:", 0, 1, 'L');
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(40, 5, "Inicial:", 0, 0, 'L');
    $pdf->Cell(30, 5, "$" . number_format($mov->monto_inicial, 2, ',', '.'), 0, 1, 'R');
    $pdf->Cell(40, 5, "Ventas Efectivo:", 0, 0, 'L');
    $pdf->Cell(30, 5, "+$" . number_format($mov->total_ventas, 2, ',', '.'), 0, 1, 'R');
    $pdf->Cell(40, 5, "Egresos Caja:", 0, 0, 'L');
    $pdf->Cell(30, 5, "-$" . number_format($gastosTotal, 2, ',', '.'), 0, 1, 'R');
    
    $pdf->SetFont('Courier', 'B', 9);
    $pdf->Cell(40, 5, "ESPERADO CAJA:", 0, 0, 'L');
    $pdf->Cell(30, 5, "$" . number_format($esperado, 2, ',', '.'), 0, 1, 'R');
    $pdf->Cell(40, 5, "F. DECLARADO:", 0, 0, 'L');
    $pdf->Cell(30, 5, ($es_abierta ? "PEND." : "$" . number_format($mov->monto_final, 2, ',', '.')), 0, 1, 'R');

    $pdf->Ln(2);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 11);
    $pdf->Cell(30, 8, "DIFERENCIA:", 0, 0, 'L');
    $pdf->Cell(40, 8, utf8_decode($txt_dif), 0, 1, 'R');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->Ln(8);
    $y_firma = $pdf->GetY();
    $ruta_firma = "../img/firmas/firma_admin.png";
    if (isset($mov->id_usuario) && $mov->id_usuario > 0 && file_exists("../img/firmas/usuario_" . $mov->id_usuario . ".png")) {
        $ruta_firma = "../img/firmas/usuario_" . $mov->id_usuario . ".png";
    }

    if (file_exists($ruta_firma)) {
        $pdf->Image($ruta_firma, 25, $y_firma, 30);
        $pdf->SetY($y_firma + 12);
    } else { $pdf->SetY($y_firma + 10); }

    $pdf->SetFont('Courier', '', 8);
    $pdf->Cell(0, 4, "________________________", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 8);
    $n_op = $mov->nombre_completo ? $mov->nombre_completo : $mov->operador;
    $r_op = $mov->nombre_rol ? $mov->nombre_rol : "OPERADOR";
    $aclaracion = strtoupper($n_op) . " | " . strtoupper($r_op);
    $pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

    $pdf->Ln(4);
    $linkPdfPublico = "http://" . $_SERVER['HTTP_HOST'] . "/ticket_caja_pdf.php?id=" . $id_caja;
    $url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
    $y_qr = $pdf->GetY();
    $pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
    $pdf->SetY($y_qr + 27);
    $pdf->SetFont('Courier', '', 7);
    $pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

    $pdf_content = $pdf->Output('S');

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
    
    $mail->addStringAttachment($pdf_content, "Comprobante_Caja_{$id_caja}.pdf");
    $mail->isHTML(true);
    $mail->Subject = "Comprobante de Caja - " . $conf->nombre_negocio;

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #0d6efd; border-radius: 10px; overflow: hidden;'>
        <div style='background: #0d6efd; padding: 20px; text-align: center;'>
            <h2 style='color: white; margin: 0;'>Comprobante de Caja Registrado</h2>
        </div>
        <div style='padding: 30px; color: #333;'>
            <p>Se adjunta el comprobante del cierre de caja detallado a continuación:</p>
            <table width='100%' style='background: #f4f7fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <tr><td><strong>Sesión Nro:</strong></td><td align='right'>#CJ-" . str_pad($id_caja, 6, '0', STR_PAD_LEFT) . "</td></tr>
                <tr><td><strong>Apertura:</strong></td><td align='right'>" . date('d/m/Y H:i', strtotime($mov->fecha_apertura)) . "</td></tr>
                <tr><td><strong>Diferencia:</strong></td><td align='right' style='color:{$color_dif};'><strong>{$txt_dif}</strong></td></tr>
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