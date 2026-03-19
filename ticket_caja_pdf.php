<?php
// ticket_caja_pdf.php - COMPROBANTE EXCLUSIVO DE CAJA
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'includes/db.php';
require_once 'fpdf/fpdf.php';

$id_caja = $_GET['id'] ?? 0;
if (!$id_caja) die('Acceso inválido.');

$stmt = $conexion->prepare("SELECT c.*, u.usuario as operador, u.nombre_completo, r.nombre as nombre_rol 
                             FROM cajas_sesion c 
                             LEFT JOIN usuarios u ON c.id_usuario = u.id 
                             LEFT JOIN roles r ON u.id_rol = r.id 
                             WHERE c.id = ?");
$stmt->execute([$id_caja]);
$mov = $stmt->fetch(PDO::FETCH_OBJ);
if (!$mov) die('El comprobante no existe.');

$stmtG = $conexion->prepare("SELECT COALESCE(SUM(monto), 0) as total FROM gastos WHERE id_caja_sesion = ?");
$stmtG->execute([$id_caja]);
$gastosTotal = $stmtG->fetch(PDO::FETCH_OBJ)->total;

$es_abierta = ($mov->estado === 'abierta');
$esperado = $mov->monto_inicial + $mov->total_ventas - $gastosTotal;
$dif = $es_abierta ? 0 : $mov->diferencia;
$txt_dif = $es_abierta ? 'PENDIENTE' : '$' . number_format($dif, 2, ',', '.');

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

$pdf = new FPDF('P', 'mm', array(80, 210));
$pdf->AddPage();
$pdf->SetMargins(4, 4, 4);
$pdf->SetAutoPageBreak(true, 4);

if (!empty($conf->logo_url) && file_exists($conf->logo_url)) {
    $pdf->Image($conf->logo_url, 25, 4, 30);
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
$ruta_firma = "img/firmas/firma_admin.png";
if (isset($mov->id_usuario) && $mov->id_usuario > 0 && file_exists("img/firmas/usuario_" . $mov->id_usuario . ".png")) {
    $ruta_firma = "img/firmas/usuario_" . $mov->id_usuario . ".png";
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
$linkPdfPublico = "http://" . $_SERVER['HTTP_HOST'] . "/ticket_caja_pdf.php?id=" . $id_caja . "&v=" . time();
$url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
$y_qr = $pdf->GetY();
$pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
$pdf->SetY($y_qr + 27);
$pdf->SetFont('Courier', '', 7);
$pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

$pdf->Output('I', "Comprobante_Caja_N{$id_caja}.pdf");
?>