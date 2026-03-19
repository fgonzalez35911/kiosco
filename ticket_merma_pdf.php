<?php
// ticket_merma_pdf.php - ESTRUCTURA CLONADA DE TICKET_PROVEEDOR_PDF.PHP
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'includes/db.php';
require_once 'fpdf/fpdf.php';

$id_mov = $_GET['id'] ?? 0;
if (!$id_mov) die('Acceso inválido.');

// Consulta adaptada a mermas pero manteniendo el estilo de ticket_proveedor_pdf.php
$stmt = $conexion->prepare("SELECT m.*, p.descripcion as producto, p.precio_costo, u.usuario as operador, u.nombre_completo, r.nombre as nombre_rol 
                             FROM mermas m 
                             JOIN productos p ON m.id_producto = p.id 
                             JOIN usuarios u ON m.id_usuario = u.id 
                             JOIN roles r ON u.id_rol = r.id 
                             WHERE m.id = ?");
$stmt->execute([$id_mov]);
$mov = $stmt->fetch(PDO::FETCH_OBJ);
if (!$mov) die('El comprobante no existe.');

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

$pdf = new FPDF('P', 'mm', array(80, 210));
$pdf->AddPage();
$pdf->SetMargins(4, 4, 4);
$pdf->SetAutoPageBreak(true, 4);

// LOGO
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

$titulo = 'COMPROBANTE DE BAJA';
$pdf->SetFont('Courier', 'B', 12);
$pdf->Cell(0, 6, utf8_decode($titulo), 0, 1, 'C');
$pdf->SetFont('Courier', '', 9);
$pdf->Cell(0, 4, "OP: #" . str_pad($mov->id, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

$pdf->Ln(3);
$pdf->Cell(0, 5, "Fecha: " . date('d/m/Y H:i', strtotime($mov->fecha)), 0, 1, 'L');
$pdf->MultiCell(0, 4, "Producto: " . utf8_decode(strtoupper($mov->producto)), 0, 'L');
$pdf->MultiCell(0, 4, "Operador: " . utf8_decode(strtoupper($mov->operador ?? 'ADMIN')), 0, 'L');
$pdf->MultiCell(0, 4, "Motivo: " . utf8_decode($mov->motivo), 0, 'L');

$pdf->Ln(3);
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
$pdf->SetFont('Courier', 'B', 14);
$pdf->Cell(30, 8, "PERDIDA:", 0, 0, 'L');
$perdida_total = $mov->cantidad * $mov->precio_costo;
$pdf->Cell(40, 8, "$" . number_format($perdida_total, 2, ',', '.'), 0, 1, 'R');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

// FIRMA
$pdf->Ln(8);
$y_firma = $pdf->GetY();
$ruta_firma = "img/firmas/firma_admin.png";

// CORRECCIÓN: Si el usuario que registró la merma tiene firma, la usamos por sobre la de admin
if (isset($mov->id_usuario) && $mov->id_usuario > 0 && file_exists("img/firmas/usuario_" . $mov->id_usuario . ".png")) {
    $ruta_firma = "img/firmas/usuario_" . $mov->id_usuario . ".png";
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
$n_op = $mov->nombre_completo ? $mov->nombre_completo : $mov->operador;
$r_op = $mov->nombre_rol ? $mov->nombre_rol : "OPERADOR";
$aclaracion = strtoupper($n_op) . " | " . strtoupper($r_op);
$pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

// QR
$pdf->Ln(4);
$protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$linkPdfPublico = $protocolo . "://" . $_SERVER['HTTP_HOST'] . "/ticket_merma_pdf.php?id=" . $id_mov . "&publico=1";
$url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
$y_qr = $pdf->GetY();
$pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
$pdf->SetY($y_qr + 27);
$pdf->SetFont('Courier', '', 7);
$pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

$pdf->Output('I', "Comprobante_Baja_N{$id_mov}.pdf");
?>