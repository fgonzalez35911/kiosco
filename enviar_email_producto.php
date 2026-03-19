<?php
// acciones/enviar_email_producto.php - MOTOR DE ENVÍO PROFESIONAL (CLON DEVOLUCIONES)
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

$id_producto = $_POST['id'] ?? 0;
$email_destino = $_POST['email'] ?? '';

try {
    // 1. DATOS DEL PRODUCTO
    $stmt = $conexion->prepare("SELECT p.*, c.nombre as cat_nom FROM productos p LEFT JOIN categorias c ON p.id_categoria = c.id WHERE p.id = ?");
    $stmt->execute([$id_producto]);
    $p = $stmt->fetch(PDO::FETCH_OBJ);
    if (!$p) throw new Exception("El producto no existe.");

    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

    // 2. BUSCAR AL DUEÑO REAL PARA LA FIRMA (Igual al modal)
    $u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol 
                                 FROM usuarios u 
                                 JOIN roles r ON u.id_rol = r.id 
                                 WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
    $ownerRow = $u_owner->fetch(PDO::FETCH_OBJ);
    $firmante_n = $ownerRow ? $ownerRow->nombre_completo : 'GERENCIA';
    $firmante_r = $ownerRow ? $ownerRow->nombre_rol : 'AUTORIZADO';

    // 3. GENERAR PDF EN MEMORIA (Diseño Ticket 80mm)
    $pdf = new FPDF('P', 'mm', array(80, 180));
    $pdf->AddPage(); 
    $pdf->SetMargins(4, 4, 4); 
    $pdf->SetAutoPageBreak(true, 4);

    // LOGO (Ruta ajustada para carpeta acciones)
    $ruta_logo = "../" . $conf->logo_url;
    if (!empty($conf->logo_url) && file_exists($ruta_logo)) { 
        $pdf->Image($ruta_logo, 25, 4, 30); 
        $pdf->Ln(22); 
    } else { 
        $pdf->Ln(5); 
    }

    $pdf->SetFont('Courier', 'B', 13);
    $pdf->Cell(0, 6, utf8_decode(strtoupper($conf->nombre_negocio)), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 8);
    $pdf->Cell(0, 4, utf8_decode($conf->direccion_local), 0, 1, 'C');
    $pdf->Ln(2); 
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->SetFont('Courier', 'B', 12);
    $pdf->Cell(0, 6, utf8_decode('FICHA TECNICA PRODUCTO'), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(0, 4, "ID: #" . str_pad($id_producto, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->Ln(3); 
    $pdf->SetFont('Courier', 'B', 9);
    $pdf->MultiCell(0, 5, "ARTICULO: " . utf8_decode(strtoupper($p->descripcion)), 0, 'L');
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(0, 5, utf8_decode("CODIGO: " . $p->codigo_barras), 0, 1, 'L');
    $pdf->Cell(0, 5, utf8_decode("RUBRO: " . strtoupper($p->cat_nom ?? 'GENERAL')), 0, 1, 'L');

    $pdf->Ln(3); 
    $pdf->SetFont('Courier', 'B', 14);
    // Formateo de Stock: Entero si no tiene decimales, sino 3 decimales (peso)
    $stock_val = floatval($p->stock_actual);
    $stock_f = ($stock_val == intval($stock_val)) ? intval($stock_val) : number_format($stock_val, 3, ',', '.');
    $pdf->Cell(0, 8, "STOCK: " . $stock_f . " u.", 1, 1, 'C');

    // FIRMA DEL DUEÑO (Desde disco, sin caché)
    $pdf->Ln(8);
    $y_firma = $pdf->GetY();
    $ruta_f = $ownerRow ? "../img/firmas/usuario_{$ownerRow->id}.png" : "../img/firmas/firma_admin.png";
    
    if (file_exists($ruta_f)) { 
        $pdf->Image($ruta_f, 25, $y_firma, 30); 
        $pdf->SetY($y_firma + 12); 
    } else {
        $pdf->SetY($y_firma + 10);
    }
    
    $pdf->SetFont('Courier', '', 8);
    $pdf->Cell(0, 4, "________________________", 0, 1, 'C'); 
    $pdf->SetFont('Courier', 'B', 8);
    $aclaracion = strtoupper($firmante_n) . " | " . strtoupper($firmante_r);
    $pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

    // QR DE VALIDACIÓN
    $pdf->Ln(4);
    $protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $link_pub = $protocolo . "://" . $_SERVER['HTTP_HOST'] . "/ticket_producto_pdf.php?id=" . $id_producto;
    $url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($link_pub);
    $y_qr = $pdf->GetY();
    $pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
    $pdf->SetY($y_qr + 27);
    $pdf->SetFont('Courier', '', 7);
    $pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

    $pdf_content = $pdf->Output('S');

    // 4. CONFIGURAR Y ENVIAR EMAIL (Look Devoluciones)
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
    $mail->addStringAttachment($pdf_content, "Ficha_Tecnica_{$p->id}.pdf");
    $mail->isHTML(true);
    $mail->Subject = "Ficha Tecnica de Producto - " . $p->descripcion;

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #102A57; border-radius: 10px; overflow: hidden;'>
        <div style='background: #102A57; padding: 20px; text-align: center;'>
            <h2 style='color: white; margin: 0;'>Ficha Técnica de Producto</h2>
        </div>
        <div style='padding: 30px; color: #333;'>
            <p>Se adjunta la información oficial y estado de stock del producto solicitado desde el sistema.</p>
            
            <table width='100%' style='background: #f4f7fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <tr><td><strong>Artículo:</strong></td><td align='right'>{$p->descripcion}</td></tr>
                <tr><td><strong>Código de Barras:</strong></td><td align='right'>{$p->codigo_barras}</td></tr>
                <tr><td><strong>Stock Actual:</strong></td><td align='right' style='color:#102A57;'><strong>{$stock_f} u.</strong></td></tr>
            </table>

            <p>Encuentra adjunto el ticket oficial con la firma de control de inventario.</p>
            <p style='margin-top:30px;'>Saludos cordiales,<br><strong>{$conf->nombre_negocio}</strong></p>
        </div>
    </div>";

    $mail->send();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) { 
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); 
}