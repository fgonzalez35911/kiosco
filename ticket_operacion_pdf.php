<?php
// ticket_operacion_pdf.php - COMPROBANTE DE COMPRA PROFESIONAL (PDF)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
require_once 'includes/db.php';
require_once 'fpdf/fpdf.php';

$id_venta = $_GET['id'] ?? 0;
if (!$id_venta) die('Acceso invalido.');

// 1. CONSULTA DE DATOS
$stmtV = $conexion->prepare("SELECT v.*, u.usuario as vendedor, u.id as id_usuario, u.nombre_completo, r.nombre as nombre_rol, c.nombre as cliente FROM ventas v JOIN usuarios u ON v.id_usuario = u.id JOIN roles r ON u.id_rol = r.id LEFT JOIN clientes c ON v.id_cliente = c.id WHERE v.id = ?");
$stmtV->execute([$id_venta]);
$venta = $stmtV->fetch(PDO::FETCH_OBJ);
if (!$venta) die('La venta no existe.');

$stmtDet = $conexion->prepare("SELECT dv.*, p.descripcion FROM detalle_ventas dv JOIN productos p ON dv.id_producto = p.id WHERE dv.id_venta = ?");
$stmtDet->execute([$id_venta]);
$detalles = $stmtDet->fetchAll(PDO::FETCH_OBJ);

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

// 2. CONFIGURACIÓN DEL PDF
$pdf = new FPDF('P', 'mm', array(80, 215)); 
$pdf->AddPage();
$pdf->SetMargins(4, 4, 4);
$pdf->SetAutoPageBreak(true, 4);

if (!empty($conf->logo_url) && file_exists($conf->logo_url)) {
    $pdf->Image($conf->logo_url, 25, 4, 30);
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
$pdf->Cell(0, 6, utf8_decode('COMPROBANTE OPERACIÓN'), 0, 1, 'C');
$pdf->SetFont('Courier', '', 9);
$pdf->Cell(0, 4, "TICKET: #" . str_pad($id_venta, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

$nombre_vendedor = !empty($venta->nombre_completo) ? $venta->nombre_completo : $venta->vendedor;

$pdf->Ln(3);
$pdf->Cell(0, 5, "Fecha: " . date('d/m/Y H:i', strtotime($venta->fecha)), 0, 1, 'L');
$pdf->Cell(0, 5, "Cliente: " . utf8_decode(strtoupper($venta->cliente ?? 'C. Final')), 0, 1, 'L');
$pdf->Cell(0, 5, "Vendedor: " . utf8_decode(strtoupper($nombre_vendedor)), 0, 1, 'L');
$pdf->Cell(0, 5, "Pago: " . utf8_decode(strtoupper($venta->metodo_pago)), 0, 1, 'L');

$pdf->Ln(3);
$pdf->SetFont('Courier', 'B', 9);
$pdf->Cell(0, 5, "DETALLE DE COMPRA:", 0, 1, 'L');
$pdf->SetFont('Courier', '', 8);

foreach($detalles as $d) {
    $pdf->MultiCell(0, 4, utf8_decode(floatval($d->cantidad) . "x " . $d->descripcion . " ($" . number_format($d->subtotal, 2, ',', '.') . ")"), 0, 'L');
}

$pdf->Ln(3);
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
$pdf->SetFont('Courier', 'B', 14);
$pdf->Cell(30, 8, "TOTAL:", 0, 0, 'L');
$pdf->Cell(40, 8, "$" . number_format($venta->total, 2, ',', '.'), 0, 1, 'R');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

$pdf->Ln(8);
$y_firma = $pdf->GetY();
$ruta_firma = "img/firmas/firma_admin.png";
if ($venta->id_usuario > 0 && file_exists("img/firmas/usuario_" . $venta->id_usuario . ".png")) {
    $ruta_firma = "img/firmas/usuario_" . $venta->id_usuario . ".png";
}

if (file_exists($ruta_firma)) {
    $pdf->Image($ruta_firma, 25, $y_firma, 30);
    $pdf->SetY($y_firma + 12);
} else { $pdf->SetY($y_firma + 10); }

$pdf->SetFont('Courier', '', 8);
$pdf->Cell(0, 4, "________________________", 0, 1, 'C'); 
$pdf->SetFont('Courier', 'B', 8);
$rol_vendedor = !empty($venta->nombre_rol) ? $venta->nombre_rol : "VENDEDOR";
$aclaracion = strtoupper($nombre_vendedor) . " | " . strtoupper($rol_vendedor);
$pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

$pdf->Ln(4);
$protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$linkPdfPublico = $protocolo . "://" . $_SERVER['HTTP_HOST'] . "/ticket_operacion_pdf.php?id=" . $id_venta . "&v=" . time();
$url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
$y_qr = $pdf->GetY();
$pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
$pdf->SetY($y_qr + 27);
$pdf->SetFont('Courier', '', 7);
$pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

$pdf->Output('I', "Comprobante_Operacion_{$id_venta}.pdf");