<?php
// ticket_canje_puntos_pdf.php - COMPROBANTE DE CANJE PROFESIONAL
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
require_once 'includes/db.php';
require_once 'fpdf/fpdf.php';

$id_audit = $_GET['id'] ?? 0;
if (!$id_audit) die('Acceso invalido.');

$stmt = $conexion->prepare("SELECT a.*, u.usuario as operador, u.id as id_operador, u.nombre_completo, r.nombre as nombre_rol 
                             FROM auditoria a 
                             JOIN usuarios u ON a.id_usuario = u.id 
                             JOIN roles r ON u.id_rol = r.id 
                             WHERE a.id = ? AND a.accion = 'CANJE'");
$stmt->execute([$id_audit]);
$canje = $stmt->fetch(PDO::FETCH_OBJ);

if (!$canje) die('El registro de canje no existe.');

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

$detalle_texto = $canje->detalles;
$cliente_nombre = "Cliente General";
$pts = "0";
if (strpos($detalle_texto, '| Cliente:') !== false) {
    $partes = explode('| Cliente:', $detalle_texto);
    $detalle_texto = trim($partes[0]);
    $cliente_nombre = trim($partes[1]);
}
if (preg_match('/\(-(\d+)\s*pts\)/', $detalle_texto, $matches)) {
    $pts = $matches[1];
}
$detalle_texto = preg_replace('/\(-(\d+)\s*pts\)/', '', $detalle_texto);

// CONFIGURACIÓN DEL PDF AUMENTADA
$pdf = new FPDF('P', 'mm', array(80, 240)); 
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
if ($conf->cuit) $pdf->Cell(0, 4, "CUIT: " . $conf->cuit, 0, 1, 'C'); 
$pdf->Cell(0, 4, utf8_decode($conf->direccion_local), 0, 1, 'C');
$pdf->Ln(2);
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

// TÍTULO DEL COMPROBANTE
$pdf->SetFont('Courier', 'B', 12);
$pdf->Cell(0, 6, utf8_decode('COMPROBANTE DE CANJE'), 0, 1, 'C');
$pdf->SetFont('Courier', '', 9);
$pdf->Cell(0, 4, "TICKET ORIGEN: #" . str_pad($canje->id, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

// INFORMACIÓN DE OPERACIÓN
$pdf->Ln(3);
$pdf->Cell(0, 5, "Fecha Canje: " . date('d/m/Y', strtotime($canje->fecha)), 0, 1, 'L');
$pdf->Cell(0, 5, "Cliente: " . utf8_decode(strtoupper($cliente_nombre)), 0, 1, 'L');
$pdf->Cell(0, 5, "Operador: " . utf8_decode(strtoupper($canje->operador)), 0, 1, 'L');

// DETALLE DE ÍTEMS
$pdf->Ln(3);
$pdf->SetFont('Courier', 'B', 9);
$pdf->Cell(0, 5, "DETALLE DE CANJE:", 0, 1, 'L');
$pdf->SetFont('Courier', '', 8);
$pdf->MultiCell(0, 4, utf8_decode(trim($detalle_texto)), 0, 'L');

// TOTALIZACIÓN
$pdf->Ln(3);
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
$pdf->SetFont('Courier', 'B', 14);
$pdf->Cell(30, 8, "PUNTOS:", 0, 0, 'L');
$pdf->Cell(40, 8, "-" . $pts . " pts", 0, 1, 'R');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

// SECCIÓN DE FIRMA (DINÁMICA) 
$pdf->Ln(8);
$y_firma = $pdf->GetY();
$ruta_firma = "img/firmas/firma_admin.png";
if (isset($canje->id_operador) && $canje->id_operador > 0 && file_exists("img/firmas/usuario_" . $canje->id_operador . ".png")) {
    $ruta_firma = "img/firmas/usuario_" . $canje->id_operador . ".png";
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
$nombre_op = !empty($canje->nombre_completo) ? $canje->nombre_completo : $canje->operador;
$rol_op = !empty($canje->nombre_rol) ? $canje->nombre_rol : "OPERADOR";
$aclaracion = strtoupper($nombre_op) . " | " . strtoupper($rol_op);
$pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

// SECCIÓN DE QR DE VALIDACIÓN 
$pdf->Ln(2);
$protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$linkPdfPublico = $protocolo . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/ticket_canje_puntos_pdf.php?id=" . $canje->id;
$url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
$y_qr = $pdf->GetY();
$pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
$pdf->SetY($y_qr + 27);
$pdf->SetFont('Courier', '', 7);
$pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

$pdf->Output('I', "Comprobante_Canje_{$canje->id}.pdf");
?>