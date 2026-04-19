<?php
// acciones/enviar_email_comprobante_pedido.php
// BLINDAJE: Ocultamos errores por pantalla para que no rompan la conexión JS
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../libs/PHPMailer/src/Exception.php';
require '../libs/PHPMailer/src/PHPMailer.php';
require '../libs/PHPMailer/src/SMTP.php';
require_once '../includes/db.php';
require_once '../fpdf/fpdf.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) { 
    ob_end_clean();
    die(json_encode(['status' => 'error', 'msg' => 'Sesión expirada'])); 
}

$id_mov = $_POST['id'] ?? 0;
$email_destino = $_POST['email'] ?? '';

if (!$id_mov || empty($email_destino)) {
    ob_end_clean();
    die(json_encode(['status' => 'error', 'msg' => 'Faltan datos obligatorios.']));
}

try {
    $stmt = $conexion->prepare("SELECT p.*, c.email as cliente_email FROM pedidos_whatsapp p LEFT JOIN clientes c ON p.id_cliente = c.id WHERE p.id = ?");
    $stmt->execute([$id_mov]);
    $mov = $stmt->fetch(PDO::FETCH_OBJ);
    if (!$mov) throw new Exception('Pedido inexistente.');

    $detalles = $conexion->prepare("SELECT d.*, pr.descripcion as descripcion_prod FROM pedidos_whatsapp_detalle d JOIN productos pr ON d.id_producto = pr.id WHERE d.id_pedido = ?");
    $detalles->execute([$id_mov]);
    $items = $detalles->fetchAll(PDO::FETCH_OBJ);

    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

    // FIRMA DINÁMICA DEL DUEÑO (FILTRO ESTRICTO)
    $stmtDueño = $conexion->query("SELECT u.id, u.nombre_completo, u.usuario, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
    $dueño = $stmtDueño->fetch(PDO::FETCH_OBJ);
    $id_dueño = $dueño->id ?? 1;
    $nombre_dueño = !empty($dueño->nombre_completo) ? strtoupper($dueño->nombre_completo) : strtoupper($dueño->usuario ?? 'DUEÑO');
    $rol_dueño = strtoupper($dueño->nombre_rol ?? 'DUEÑO');
    $texto_firma = $nombre_dueño . " | " . $rol_dueño;

    // GENERAR PDF EN MEMORIA (CLON EXACTO DEL VISOR PÚBLICO)
    $pdf = new FPDF('P', 'mm', array(80, 240));
    $pdf->AddPage();
    $pdf->SetMargins(4, 4, 4);
    $pdf->SetAutoPageBreak(true, 4);

    if (!empty($conf->logo_url) && file_exists("../".$conf->logo_url)) {
        $pdf->Image("../".$conf->logo_url, 25, 4, 30);
        $pdf->Ln(22);
    } else { 
        $pdf->Ln(5); 
    }

    $pdf->SetFont('Courier', 'B', 13);
    $pdf->Cell(0, 6, utf8_decode(strtoupper($conf->nombre_negocio)), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 8);
    if ($conf->cuit) $pdf->Cell(0, 4, "CUIT: " . $conf->cuit, 0, 1, 'C');
    $pdf->Cell(0, 4, utf8_decode($conf->direccion_local), 0, 1, 'C');
    $pdf->Ln(2);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $titulo = 'COMPROBANTE PEDIDO';
    $pdf->SetFont('Courier', 'B', 12);
    $pdf->Cell(0, 6, utf8_decode($titulo), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(0, 4, "OP: #" . str_pad($mov->id, 6, '0', STR_PAD_LEFT) . " - " . strtoupper($mov->estado), 0, 1, 'C');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->Ln(3);
    $pdf->Cell(0, 4, "Fecha: " . date('d/m/Y H:i', strtotime($mov->fecha_pedido)), 0, 1, 'L');
    $pdf->MultiCell(0, 4, "Cliente: " . utf8_decode(strtoupper($mov->nombre_cliente)), 0, 'L');
    $pdf->MultiCell(0, 4, "Contacto: " . utf8_decode($mov->email_cliente ?? 'N/A'), 0, 'L');

    $pdf->Ln(2);
    $pdf->SetFont('Courier', 'B', 9);
    $pdf->Cell(0, 5, "DETALLE DE PRODUCTOS:", 0, 1, 'L');
    $pdf->SetFont('Courier', '', 8);

    foreach($items as $it) {
        $sub = "$" . number_format($it->subtotal, 2, ',', '.');
        $linea = floatval($it->cantidad) . "x " . utf8_decode(substr($it->descripcion_prod, 0, 15));
        $pdf->Cell(28, 4, $linea, 0, 0, 'L');
        $pdf->Cell(44, 4, $sub, 0, 1, 'R');
    }

    $pdf->Ln(3);
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 14);
    $pdf->Cell(30, 8, "TOTAL:", 0, 0, 'L');
    $pdf->Cell(42, 8, "$" . number_format($mov->total, 2, ',', '.'), 0, 1, 'R');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    // FIRMA DINÁMICA DEL DUEÑO
    $pdf->Ln(8);
    $y_firma = $pdf->GetY();
    $ruta_firma = "../img/firmas/usuario_" . $id_dueño . ".png";

    if (file_exists($ruta_firma)) {
        $pdf->Image($ruta_firma, 25, $y_firma, 30);
        $pdf->SetY($y_firma + 12);
    } else {
        $pdf->SetY($y_firma + 10);
    }

    $pdf->SetFont('Courier', '', 8);
    $pdf->Cell(0, 4, "________________________", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 8);
    $pdf->Cell(0, 4, utf8_decode($texto_firma), 0, 1, 'C');

    // QR
    $pdf->Ln(2);
    $protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    // Ajustamos la ruta para salir de la carpeta /acciones/ y apuntar a la raíz del sistema
    $ruta_base = str_replace('/acciones', '', dirname($_SERVER['PHP_SELF']));
    $linkPdfPublico = $protocolo . "://" . $_SERVER['HTTP_HOST'] . $ruta_base . "/ticket_pedido_pdf.php?id=" . $id_mov;
    $url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
    $y_qr = $pdf->GetY();
    $pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
    $pdf->SetY($y_qr + 27);
    $pdf->SetFont('Courier', '', 7);
    $pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

    $pdf_content = $pdf->Output('S');

    // ENVÍO PHPMailer (HOSTINGER)
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.hostinger.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'info@federicogonzalez.net';
    $mail->Password   = 'Fmg35911@';
    $mail->SMTPSecure = 'ssl';
    $mail->Port       = 465;
    $mail->CharSet    = 'UTF-8';
    
    $mail->setFrom('info@federicogonzalez.net', $conf->nombre_negocio ?? 'Sistema');
    $mail->addAddress($email_destino);
    
    $mail->addStringAttachment($pdf_content, "Comprobante_Pedido_{$id_mov}.pdf");
    $mail->isHTML(true);
    $mail->Subject = "Comprobante de Pedido #" . $id_mov . " - " . ($conf->nombre_negocio ?? 'Sistema');

    $color = $mov->estado === 'entregado' ? '#198754' : '#0d6efd';

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid {$color}; border-radius: 10px; overflow: hidden;'>
        <div style='background: {$color}; padding: 20px; text-align: center;'>
            <h2 style='color: white; margin: 0;'>Comprobante de Pedido Web</h2>
        </div>
        <div style='padding: 30px; color: #333;'>
            <p>Hola <strong>{$mov->nombre_cliente}</strong>,</p>
            <p>Se adjunta el comprobante detallado de tu pedido realizado el " . date('d/m/Y', strtotime($mov->fecha_pedido)) . ".</p>
            <table width='100%' style='background: #f4f7fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <tr><td><strong>Operación Nro:</strong></td><td align='right'>#{$id_mov}</td></tr>
                <tr><td><strong>Estado:</strong></td><td align='right'>" . strtoupper($mov->estado) . "</td></tr>
                <tr><td><strong>Importe Total:</strong></td><td align='right' style='color:{$color};'><strong>$" . number_format($mov->total, 2, ',', '.') . "</strong></td></tr>
            </table>
            <p style='margin-top:30px;'>Gracias por confiar en nosotros.</p>
        </div>
    </div>";

    $mail->send();
    
    // Limpiamos la basura del buffer y enviamos el JSON puro para que SweetAlert funcione bien
    ob_end_clean();
    echo json_encode(['status' => 'success', 'msg' => 'Correo enviado exitosamente.']);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'msg' => 'Error al enviar: ' . $e->getMessage()]);
}
?>
