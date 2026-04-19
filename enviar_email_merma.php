<?php
// acciones/enviar_email_merma.php - VERSIÓN COMPLETA SIN SIMPLIFICACIONES
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

$id = $_POST['id'] ?? 0;
$email_destino = $_POST['email'] ?? '';

if (!$id || empty($email_destino)) { die(json_encode(['status' => 'error', 'msg' => 'Faltan datos.'])); }

try {
    // 1. OBTENER DATOS
    $stmt = $conexion->prepare("SELECT m.*, p.descripcion as prod, p.precio_costo, u.usuario, u.nombre_completo FROM mermas m JOIN productos p ON m.id_producto = p.id JOIN usuarios u ON m.id_usuario = u.id WHERE m.id = ?");
    $stmt->execute([$id]);
    $m = $stmt->fetch(PDO::FETCH_OBJ);
    if (!$m) throw new Exception('No existe.');
    
    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

    // 2. GENERAR PDF EN MEMORIA
    $pdf = new FPDF('P', 'mm', array(80, 200));
    $pdf->AddPage(); $pdf->SetMargins(4, 4, 4); $pdf->SetAutoPageBreak(true, 4);

    if (!empty($conf->logo_url) && file_exists("../" . $conf->logo_url)) { $pdf->Image("../" . $conf->logo_url, 25, 4, 30); $pdf->Ln(22); }
    $pdf->SetFont('Courier', 'B', 13); $pdf->Cell(0, 6, utf8_decode(strtoupper($conf->nombre_negocio)), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 8); $pdf->Cell(0, 4, utf8_decode($conf->direccion_local), 0, 1, 'C');
    $pdf->Ln(2); $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
    $pdf->SetFont('Courier', 'B', 11); $pdf->Cell(0, 6, "COMPROBANTE BAJA", 0, 1, 'C');
    $pdf->SetFont('Courier', '', 9); $pdf->Cell(0, 4, "OP: #" . str_pad($m->id, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C'); $pdf->Ln(3);
    $pdf->Cell(0, 5, "Fecha: " . date('d/m/Y H:i', strtotime($m->fecha)), 0, 1, 'L');
    $pdf->Cell(0, 5, "Producto: " . strtoupper($m->prod), 0, 1, 'L');
    $pdf->Cell(0, 5, "Operador: " . strtoupper($m->usuario), 0, 1, 'L');
    $pdf->Ln(3); $pdf->SetFont('Courier', 'B', 14); $pdf->Cell(30, 8, "PERDIDA:", 0, 0, 'L');
    $pdf->Cell(40, 8, "$" . number_format($m->cantidad * $m->precio_costo, 2, ',', '.'), 0, 1, 'R');
    $pdf->Ln(12); $ruta_firma = "../img/firmas/firma_admin.png";
    if (file_exists($ruta_firma)) { $pdf->Image($ruta_firma, 25, $pdf->GetY(), 30); $pdf->Ln(15); }
    $pdf->SetFont('Courier', 'B', 8); $pdf->Cell(0, 4, utf8_decode(strtoupper($m->nombre_completo ?? 'FIRMA')), 0, 1, 'C');
    
    $pdf_content = $pdf->Output('S');

    // 3. ENVIAR EMAIL
    $mail = new PHPMailer(true);
    $mail->isSMTP(); $mail->Host = 'smtp.hostinger.com'; $mail->SMTPAuth = true;
    $mail->Username = 'info@federicogonzalez.net'; $mail->Password = 'Fmg35911@'; $mail->SMTPSecure = 'ssl'; $mail->Port = 465;
    $mail->setFrom('info@federicogonzalez.net', $conf->nombre_negocio);
    $mail->addAddress($email_destino);
    $mail->addStringAttachment($pdf_content, "Ticket_Baja_#{$id}.pdf");
    $mail->isHTML(true); $mail->Subject = "Comprobante de Baja - " . $conf->nombre_negocio;
    $mail->Body = "<h3>Notificación de Merma</h3><p>Se adjunta el comprobante oficial de la baja realizada.</p>";
    $mail->send();

    echo json_encode(['status' => 'success']);
} catch (Exception $e) { echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); }