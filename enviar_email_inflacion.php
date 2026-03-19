<?php
// acciones/enviar_email_inflacion.php - DISEÑO PREMIUM ADAPTADO DE GASTOS
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
    die(json_encode(['status' => 'error', 'msg' => 'Sesion expirada'])); 
}

$id_inf = $_POST['id'] ?? 0;
$email_destino = $_POST['email'] ?? '';

if (!$id_inf || empty($email_destino)) {
    die(json_encode(['status' => 'error', 'msg' => 'Faltan datos obligatorios.']));
}

try {
    // 1. OBTENER DATOS DE LA INFLACIÓN
    $stmt = $conexion->prepare("SELECT h.*, u.usuario as operador 
                                 FROM historial_inflacion h 
                                 LEFT JOIN usuarios u ON h.id_usuario = u.id 
                                 WHERE h.id = ?");
    $stmt->execute([$id_inf]);
    $mov = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$mov) throw new Exception('Registro de inflacion inexistente.');

    // 2. OBTENER DATOS DEL DUEÑO PARA LA FIRMA OFICIAL (Igual que en Gastos)
    $u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol 
                                 FROM usuarios u 
                                 JOIN roles r ON u.id_rol = r.id 
                                 WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
    $ownerRow = $u_owner->fetch(PDO::FETCH_ASSOC);
    $firmante = $ownerRow ? $ownerRow : ['nombre_completo' => 'RESPONSABLE', 'nombre_rol' => 'AUTORIZADO', 'id' => 0];

    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

    // 3. GENERACIÓN DEL PDF ADJUNTO
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
    $pdf->Cell(0, 6, utf8_decode('COMPROBANTE INFLACION'), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(0, 4, "OP: #" . str_pad($mov->id, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->Ln(3);
    $pdf->Cell(0, 5, "Fecha: " . date('d/m/Y H:i', strtotime($mov->fecha)), 0, 1, 'L');
    $pdf->Cell(0, 5, "Grupo: " . utf8_decode(strtoupper($mov->grupo_afectado)), 0, 1, 'L');
    $pdf->Cell(0, 5, "Operador: " . utf8_decode(strtoupper($mov->operador ?? 'ADMIN')), 0, 1, 'L');

    $pdf->Ln(3);
    $pdf->SetFont('Courier', 'B', 9);
    $pdf->Cell(0, 5, "DETALLE DE IMPACTO:", 0, 1, 'L');
    $pdf->SetFont('Courier', '', 8);
    $impacto = (strtoupper($mov->accion) == 'COSTO') ? 'COSTO Y VENTA' : 'SOLO VENTA';
    $detalle = "Aumento masivo aplicado al grupo " . $mov->grupo_afectado . ". Afectando a " . $mov->cantidad_productos . " productos en su precio de " . $impacto . ".";
    $pdf->MultiCell(0, 4, utf8_decode($detalle), 0, 'L');

    $pdf->Ln(3);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 14);
    $pdf->Cell(30, 8, "AUMENTO:", 0, 0, 'L');
    $pdf->Cell(40, 8, "+" . number_format($mov->porcentaje, 2, ',', '.') . "%", 0, 1, 'R');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    // FIRMA (Misma lógica que Gastos)
    $pdf->Ln(8);
    $y_firma = $pdf->GetY();
    $ruta_firma = "../img/firmas/firma_admin.png";
    if ($ownerRow && file_exists("../img/firmas/usuario_" . $ownerRow['id'] . ".png")) {
        $ruta_firma = "../img/firmas/usuario_" . $ownerRow['id'] . ".png";
    }

    if (file_exists($ruta_firma)) {
        $pdf->Image($ruta_firma, 25, $y_firma, 30);
        $pdf->SetY($y_firma + 12);
    } else { $pdf->SetY($y_firma + 10); }

    $pdf->SetFont('Courier', '', 8);
    $pdf->Cell(0, 4, "________________________", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 8);
    $aclaracion = strtoupper($firmante['nombre_completo']) . " | " . strtoupper($firmante['nombre_rol']);
    $pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

    // QR
    $pdf->Ln(4);
    $protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $linkPdfPublico = $protocolo . "://" . $_SERVER['HTTP_HOST'] . "/ticket_inflacion_pdf.php?id=" . $id_inf . "&publico=1";
    $url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
    $y_qr = $pdf->GetY();
    $pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
    $pdf->SetY($y_qr + 27);
    $pdf->SetFont('Courier', '', 7);
    $pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

    $pdf_content = $pdf->Output('S');

    // 4. ENVÍO PHPMailer (Diseño corporativo idéntico a Gastos)
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
    
    $mail->addStringAttachment($pdf_content, "Comprobante_Inflacion_{$id_inf}.pdf");
    $mail->isHTML(true);
    $mail->Subject = "Actualización de Precios - " . $conf->nombre_negocio;

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #102A57; border-radius: 10px; overflow: hidden;'>
        <div style='background: #102A57; padding: 20px; text-align: center;'>
            <h2 style='color: white; margin: 0;'>Ajuste de Precios Registrado</h2>
        </div>
        <div style='padding: 30px; color: #333;'>
            <p>Se adjunta el comprobante del ajuste de precios por inflación realizado el " . date('d/m/Y', strtotime($mov->fecha)) . ".</p>
            <table width='100%' style='background: #f4f7fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <tr><td><strong>Operación Nro:</strong></td><td align='right'>#{$id_inf}</td></tr>
                <tr><td><strong>Grupo Afectado:</strong></td><td align='right'>{$mov->grupo_afectado}</td></tr>
                <tr><td><strong>Productos:</strong></td><td align='right'>{$mov->cantidad_productos} ítems</td></tr>
                <tr><td><strong>Porcentaje:</strong></td><td align='right' style='color:#dc3545;'><strong>+" . number_format($mov->porcentaje, 2, ',', '.') . "%</strong></td></tr>
            </table>
            <p style='margin-top:30px;'>Este ajuste ha sido validado y aplicado al sistema de inventario.</p>
            <p>Saludos cordiales,<br><strong>{$conf->nombre_negocio}</strong></p>
        </div>
    </div>";

    $mail->send();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Error al enviar: ' . $e->getMessage()]);
}
?>