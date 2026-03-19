<?php
// ticket_devolucion_pdf.php - COMPROBANTE DE REINTEGRO PROFESIONAL
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
require_once 'includes/db.php';
require_once 'fpdf/fpdf.php';

$id_venta = $_GET['id'] ?? 0;
if (!$id_venta) die('Acceso invalido.');

// 1. CONSULTA DE DATOS
$stmtV = $conexion->prepare("SELECT v.*, u.usuario, c.nombre as cliente FROM ventas v JOIN usuarios u ON v.id_usuario = u.id JOIN clientes c ON v.id_cliente = c.id WHERE v.id = ?");
$stmtV->execute([$id_venta]);
$venta = $stmtV->fetch(PDO::FETCH_OBJ);
if (!$venta) die('La venta no existe.');

$stmtD = $conexion->prepare("SELECT d.*, p.descripcion, u.usuario as operador, u.id as id_operador, u.nombre_completo, r.nombre as nombre_rol 
                             FROM devoluciones d 
                             JOIN productos p ON d.id_producto = p.id 
                             JOIN usuarios u ON d.id_usuario = u.id 
                             JOIN roles r ON u.id_rol = r.id 
                             WHERE d.id_venta_original = ?");
$stmtD->execute([$id_venta]);
$devoluciones = $stmtD->fetchAll(PDO::FETCH_OBJ);
if (empty($devoluciones)) die('No hay devoluciones para este ticket.');

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

// 2. CONFIGURACIÓN DEL PDF
$pdf = new FPDF('P', 'mm', array(80, 215)); // Un poco más largo para que entre el QR
$pdf->AddPage();
$pdf->SetMargins(4, 4, 4);
$pdf->SetAutoPageBreak(true, 4);

// LOGO
if (!empty($conf->logo_url) && file_exists($conf->logo_url)) {
    $pdf->Image($conf->logo_url, 25, 4, 30);
    $pdf->Ln(22);
} else { $pdf->Ln(5); }

// ENCABEZADO CORPORATIVO
$pdf->SetFont('Courier', 'B', 13);
$pdf->Cell(0, 6, utf8_decode(strtoupper($conf->nombre_negocio)), 0, 1, 'C');
$pdf->SetFont('Courier', '', 8);
if ($conf->cuit) $pdf->Cell(0, 4, "CUIT: " . $conf->cuit, 0, 1, 'C'); // Agregado CUIT 
$pdf->Cell(0, 4, utf8_decode($conf->direccion_local), 0, 1, 'C');
$pdf->Ln(2);
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

// TÍTULO DEL COMPROBANTE
$pdf->SetFont('Courier', 'B', 12);
$pdf->Cell(0, 6, utf8_decode('COMPROBANTE REINTEGRO'), 0, 1, 'C');
$pdf->SetFont('Courier', '', 9);
$pdf->Cell(0, 4, "TICKET ORIGEN: #" . str_pad($id_venta, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

// INFORMACIÓN DE OPERACIÓN
$pdf->Ln(3);
$pdf->Cell(0, 5, "Fecha Venta: " . date('d/m/Y', strtotime($venta->fecha)), 0, 1, 'L');
$pdf->Cell(0, 5, "Cliente: " . utf8_decode(strtoupper($venta->cliente)), 0, 1, 'L');
$pdf->Cell(0, 5, "Operador: " . utf8_decode(strtoupper($devoluciones[0]->operador)), 0, 1, 'L');

// DETALLE DE ÍTEMS
$pdf->Ln(3);
$pdf->SetFont('Courier', 'B', 9);
$pdf->Cell(0, 5, "DETALLE DE DEVOLUCION:", 0, 1, 'L');
$pdf->SetFont('Courier', '', 8);

$total_dev = 0;
foreach($devoluciones as $d) {
    $pdf->MultiCell(0, 4, utf8_decode(floatval($d->cantidad) . "x " . $d->descripcion . " (-$" . number_format($d->monto_devuelto, 2, ',', '.') . ")"), 0, 'L');
    $total_dev += $d->monto_devuelto;
}

// TOTALIZACIÓN
$pdf->Ln(3);
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
$pdf->SetFont('Courier', 'B', 14);
$pdf->Cell(30, 8, "TOTAL:", 0, 0, 'L');
$pdf->Cell(40, 8, "-$" . number_format($total_dev, 2, ',', '.'), 0, 1, 'R');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

// SECCIÓN DE FIRMA (DINÁMICA) 
$pdf->Ln(8);
$y_firma = $pdf->GetY();
$id_op = $devoluciones[0]->id_operador;
$ruta_firma = "img/firmas/firma_admin.png";

// CORRECCIÓN: Prioridad absoluta a la firma del operador que realizó la devolución
if (isset($devoluciones[0]->id_operador) && $devoluciones[0]->id_operador > 0 && file_exists("img/firmas/usuario_" . $devoluciones[0]->id_operador . ".png")) {
    $ruta_firma = "img/firmas/usuario_" . $devoluciones[0]->id_operador . ".png";
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
$nombre_op = !empty($devoluciones[0]->nombre_completo) ? $devoluciones[0]->nombre_completo : $devoluciones[0]->operador;
$rol_op = !empty($devoluciones[0]->nombre_rol) ? $devoluciones[0]->nombre_rol : "OPERADOR";
$aclaracion = strtoupper($nombre_op) . " | " . strtoupper($rol_op);
$pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

// SECCIÓN DE QR DE VALIDACIÓN 
$pdf->Ln(4);
$protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$linkPdfPublico = $protocolo . "://" . $_SERVER['HTTP_HOST'] . "/ticket_devolucion_pdf.php?id=" . $id_venta . "&v=" . time();
$url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
$y_qr = $pdf->GetY();
$pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
$pdf->SetY($y_qr + 27);
$pdf->SetFont('Courier', '', 7);
$pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

$pdf->Output('I', "Comprobante_Reintegro_{$id_venta}.pdf");