<?php
// acciones/enviar_email_premio.php
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

$id_premio = $_POST['id'] ?? 0;
$email_destino = $_POST['email'] ?? '';

try {
    $stmt = $conexion->prepare("SELECT p.*, 
             u.nombre_completo as creador_nombre, u.usuario as creador_usuario, r.nombre as creador_rol,
             CASE 
                WHEN p.tipo_articulo = 'producto' THEN (SELECT descripcion FROM productos WHERE id = p.id_articulo)
                WHEN p.tipo_articulo = 'combo' THEN (SELECT nombre FROM combos WHERE id = p.id_articulo)
                ELSE NULL 
             END as nombre_vinculo
             FROM premios p 
             LEFT JOIN usuarios u ON p.id_usuario = u.id
             LEFT JOIN roles r ON u.id_rol = r.id
             WHERE p.id = ?");
    $stmt->execute([$id_premio]);
    $premio = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$premio) throw new Exception("El registro no existe.");

    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

    $tipoTxt = $premio->es_cupon == 1 ? 'CUPON DE DINERO' : 'ARTICULO FISICO';
    $vinculoTxt = $premio->es_cupon == 1 ? "Monto a favor: $" . $premio->monto_dinero : "Stock: " . $premio->stock . " u.\nVinculo: " . ($premio->nombre_vinculo ?: 'General');

    // GENERAR PDF EN MEMORIA
    $pdf = new FPDF('P', 'mm', array(80, 200));
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
    $pdf->Cell(0, 6, utf8_decode('FICHA DE PREMIO'), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(0, 4, "ID REF: #" . str_pad($premio->id, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->Ln(3);
    $pdf->SetFont('Courier', 'B', 10);
    $pdf->Cell(0, 5, "NOMBRE DEL PREMIO:", 0, 1, 'L');
    $pdf->SetFont('Courier', '', 9);
    $pdf->MultiCell(0, 5, utf8_decode(strtoupper($premio->nombre)), 0, 'L');

    $pdf->Ln(3);
    $pdf->SetFont('Courier', 'B', 9);
    $pdf->Cell(0, 5, "TIPO: " . $tipoTxt, 0, 1, 'L');
    $pdf->SetFont('Courier', '', 8);
    $pdf->MultiCell(0, 4, utf8_decode($vinculoTxt), 0, 'L');

    $pdf->Ln(3);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 12);
    $pdf->Cell(30, 8, "COSTO:", 0, 0, 'L');
    $pdf->Cell(40, 8, $premio->puntos_necesarios . " pts", 0, 1, 'R');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->Ln(8);
    $y_firma = $pdf->GetY();
    $ruta_firma = "../img/firmas/firma_admin.png";
    if (!empty($premio->id_usuario) && file_exists("../img/firmas/usuario_{$premio->id_usuario}.png")) {
        $ruta_firma = "../img/firmas/usuario_{$premio->id_usuario}.png";
    }

    if (file_exists($ruta_firma)) {
        $pdf->Image($ruta_firma, 25, $y_firma, 30);
        $pdf->SetY($y_firma + 12);
    } else {
        $pdf->SetY($y_firma + 10);
    }

    $pdf->SetFont('Courier', '', 8);
    $pdf->Cell(0, 4, "________________________", 0, 1, 'C'); 
    $pdf->SetFont('Courier', 'B', 8);
    $nombre_op = !empty($premio->creador_nombre) ? $premio->creador_nombre : (!empty($premio->creador_usuario) ? $premio->creador_usuario : 'SISTEMA');
    $rol_op = !empty($premio->creador_rol) ? $premio->creador_rol : "OPERADOR";
    $aclaracion = strtoupper($nombre_op) . " | " . strtoupper($rol_op);
    $pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

    $pdf->Ln(4);
    $protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $linkPdfPublico = "http://" . str_replace("acciones/", "", $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF'])) . "/ticket_premio_pdf.php?id=" . $premio->id;
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
    $mail->addStringAttachment($pdf_content, "Ficha_Premio_{$premio->id}.pdf");
    $mail->isHTML(true);
    $mail->Subject = "Ficha de Premio - " . $conf->nombre_negocio;

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #102A57; border-radius: 10px; overflow: hidden;'>
        <div style='background: #102A57; padding: 20px; text-align: center;'>
            <h2 style='color: white; margin: 0;'>Catálogo de Premios</h2>
        </div>
        <div style='padding: 30px; color: #333;'>
            <p>Hola, te adjuntamos la ficha técnica del premio <strong>{$premio->nombre}</strong> de nuestro programa de fidelización.</p>
            
            <table width='100%' style='background: #f4f7fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <tr><td><strong>Nombre:</strong></td><td align='right'>{$premio->nombre}</td></tr>
                <tr><td><strong>Costo en Puntos:</strong></td><td align='right' style='color:#102A57;'><strong>" . $premio->puntos_necesarios . " pts</strong></td></tr>
            </table>

            <p>El comprobante oficial adjunto contiene el detalle completo del artículo.</p>
            <p style='margin-top:30px;'>Saludos cordiales,<br><strong>{$conf->nombre_negocio}</strong></p>
        </div>
    </div>";

    $mail->send();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) { 
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); 
}
?>