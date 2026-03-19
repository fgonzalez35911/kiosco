<?php
// acciones/enviar_email_reporte_clientes.php - ADAPTADO DE ENVIAR_EMAIL_GASTO.PHP
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../libs/PHPMailer/src/Exception.php';
require '../libs/PHPMailer/src/PHPMailer.php';
require '../libs/PHPMailer/src/SMTP.php';
require_once '../includes/db.php';
require_once '../fpdf/fpdf.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) { die(json_encode(['status' => 'error', 'msg' => 'Sesión expirada'])); }

$email_destino = $_POST['email'] ?? '';
$filtros_str = $_POST['filtros'] ?? '';
parse_str($filtros_str, $filtros);

try {
    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

    // --- LÓGICA DE DATOS (Mismos filtros que reporte_clientes.php) ---
    $cond = [];
    if (isset($filtros['filtro']) && $filtros['filtro'] == 'cumple') {
        $cond[] = "MONTH(c.fecha_nacimiento) = MONTH(CURDATE()) AND DAY(c.fecha_nacimiento) = DAY(CURDATE())";
    }
    if (isset($filtros['estado']) && $filtros['estado'] == 'deuda') {
        $cond[] = "(SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'debe') - (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'haber') > 0.1";
    }
    $where = (count($cond) > 0) ? " WHERE " . implode(" AND ", $cond) : "";
    $clientes = $conexion->query("SELECT c.*, (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'debe') - (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'haber') as saldo FROM clientes c $where ORDER BY c.nombre ASC")->fetchAll(PDO::FETCH_OBJ);

    // --- GENERACIÓN DE PDF ADJUNTO (Formato 80mm adaptado) ---
    $pdf = new FPDF('P', 'mm', array(80, 250));
    $pdf->AddPage();
    $pdf->SetMargins(4, 4, 4);

    if (!empty($conf->logo_url) && file_exists("../" . $conf->logo_url)) {
        $pdf->Image("../" . $conf->logo_url, 25, 4, 30);
        $pdf->Ln(22);
    } else { $pdf->Ln(5); }

    $pdf->SetFont('Courier', 'B', 12);
    $pdf->Cell(0, 6, utf8_decode('REPORTE DE CARTERA'), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 8);
    $pdf->Cell(0, 4, "FECHA: " . date('d/m/Y H:i'), 0, 1, 'C');
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

    $pdf->Ln(2);
    $pdf->SetFont('Courier', 'B', 7);
    $pdf->Cell(45, 4, "CLIENTE", 0, 0, 'L');
    $pdf->Cell(27, 4, "SALDO", 0, 1, 'R');
    $pdf->SetFont('Courier', '', 7);

    foreach($clientes as $c) {
        $pdf->Cell(45, 4, utf8_decode(substr($c->nombre, 0, 25)), 0, 0, 'L');
        $pdf->Cell(27, 4, "$" . number_format($c->saldo, 2, ',', '.'), 0, 1, 'R');
    }
    $pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
    $pdf_content = $pdf->Output('S');

    // --- ENVÍO PHPMailer (Diseño corporativo igual a enviar_email_gasto.php) ---
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
    $mail->addStringAttachment($pdf_content, "Reporte_Clientes.pdf");
    $mail->isHTML(true);
    $mail->Subject = "Reporte de Cartera de Clientes - " . $conf->nombre_negocio;

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #102A57; border-radius: 10px; overflow: hidden;'>
        <div style='background: #102A57; padding: 20px; text-align: center;'>
            <h2 style='color: white; margin: 0;'>Reporte de Clientes</h2>
        </div>
        <div style='padding: 30px; color: #333;'>
            <p>Hola, se adjunta el listado de clientes solicitado con fecha " . date('d/m/Y') . ".</p>
            <table width='100%' style='background: #f4f7fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <tr><td><strong>Total Clientes:</strong></td><td align='right'>" . count($clientes) . "</td></tr>
                <tr><td><strong>Fecha Generado:</strong></td><td align='right'>" . date('d/m/Y H:i') . "</td></tr>
            </table>
            <p style='margin-top:30px;'>Saludos cordiales,<br><strong>{$conf->nombre_negocio}</strong></p>
        </div>
    </div>";

    $mail->send();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) { echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); }