<?php
// ticket_proveedor_pdf.php - VISOR PÚBLICO REPARADO AL 100%
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'includes/db.php';
require_once 'fpdf/fpdf.php';

$id_mov = $_GET['id'] ?? 0;
if (!$id_mov) die('Acceso inválido.');

// ACA ESTABA EL ERROR: Primero consultamos la BD, y DESPUÉS armamos el PDF.
$stmt = $conexion->prepare("SELECT m.*, p.empresa, u.usuario as operador, u.nombre_completo, f.comprobante as comp_asociado FROM movimientos_proveedores m JOIN proveedores p ON m.id_proveedor = p.id LEFT JOIN usuarios u ON m.id_usuario = u.id LEFT JOIN movimientos_proveedores f ON m.id_factura_asociada = f.id WHERE m.id = ?");
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

$titulo = ($mov->tipo == 'compra') ? 'FACTURA (DEUDA)' : 'RECIBO DE PAGO';
$pdf->SetFont('Courier', 'B', 12);
$pdf->Cell(0, 6, utf8_decode($titulo), 0, 1, 'C');
$pdf->SetFont('Courier', '', 9);
$pdf->Cell(0, 4, "OP: #" . str_pad($mov->id, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

$pdf->Ln(3);
$pdf->Cell(0, 5, "Fecha: " . date('d/m/Y H:i', strtotime($mov->fecha)), 0, 1, 'L');
$pdf->Cell(0, 5, "Proveedor: " . utf8_decode(strtoupper($mov->empresa)), 0, 1, 'L');
$pdf->Cell(0, 5, "Operador: " . utf8_decode(strtoupper($mov->operador ?? 'ADMIN')), 0, 1, 'L');

if ($mov->comprobante) {
    $pdf->Cell(0, 5, "Comp. Nro: " . utf8_decode($mov->comprobante), 0, 1, 'L');
}

// MUESTRA A QUÉ FACTURA SE APLICÓ EL PAGO
if ($mov->id_factura_asociada) {
    $pdf->SetFont('Courier', 'B', 8);
    $texto_asoc = "PAGA FACTURA OP:#" . $mov->id_factura_asociada . ($mov->comp_asociado ? " (".$mov->comp_asociado.")" : "");
    $pdf->Cell(0, 5, utf8_decode($texto_asoc), 0, 1, 'L');
    $pdf->SetFont('Courier', '', 9);
}

$pdf->Ln(3);
$pdf->SetFont('Courier', 'B', 9);
$pdf->Cell(0, 5, "CONCEPTO/DETALLE:", 0, 1, 'L');
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
$ruta_firma = "img/firmas/firma_admin.png";
if (!file_exists($ruta_firma) && file_exists("img/firmas/usuario_" . $mov->id_usuario . ".png")) {
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
$aclaracion = $mov->nombre_completo ? strtoupper($mov->nombre_completo) : "FIRMA AUTORIZADA";
$pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

// QR
$pdf->Ln(4);
$linkPdfPublico = "http://" . $_SERVER['HTTP_HOST'] . "/ticket_proveedor_pdf.php?id=" . $id_mov . "&v=" . time();
$url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
$y_qr = $pdf->GetY();
$pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
$pdf->SetY($y_qr + 27);
$pdf->SetFont('Courier', '', 7);
$pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

$pdf->Output('I', "Comprobante_{$mov->empresa}_N{$id_mov}.pdf");
?>