<?php
// ticket_pedido_pdf.php - VISOR PÚBLICO DEL COMPROBANTE WEB
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'includes/db.php';
require_once 'fpdf/fpdf.php';

$id_mov = $_GET['id'] ?? 0;
if (!$id_mov) die('Acceso inválido.');

// Consulta del pedido
$stmt = $conexion->prepare("SELECT p.*, c.email as cliente_email FROM pedidos_whatsapp p LEFT JOIN clientes c ON p.id_cliente = c.id WHERE p.id = ?");
$stmt->execute([$id_mov]);
$mov = $stmt->fetch(PDO::FETCH_OBJ);
if (!$mov) die('El comprobante no existe.');

// Consulta detalles
$detalles = $conexion->prepare("SELECT d.*, pr.descripcion as descripcion_prod FROM pedidos_whatsapp_detalle d JOIN productos pr ON d.id_producto = pr.id WHERE d.id_pedido = ?");
$detalles->execute([$id_mov]);
$items = $detalles->fetchAll(PDO::FETCH_OBJ);

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

$pdf = new FPDF('P', 'mm', array(80, 240));
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

// FIRMA DINÁMICA DEL DUEÑO (FILTRO ESTRICTO)
$stmtDueño = $conexion->query("SELECT u.id, u.nombre_completo, u.usuario, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
$dueño = $stmtDueño->fetch(PDO::FETCH_OBJ);
$id_dueño = $dueño->id ?? 1;
$nombre_dueño = !empty($dueño->nombre_completo) ? strtoupper($dueño->nombre_completo) : strtoupper($dueño->usuario ?? 'DUEÑO');
$rol_dueño = strtoupper($dueño->nombre_rol ?? 'DUEÑO');
$texto_firma = $nombre_dueño . " | " . $rol_dueño;

$pdf->Ln(8);
$y_firma = $pdf->GetY();
$ruta_firma = "img/firmas/usuario_" . $id_dueño . ".png";

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
$linkPdfPublico = $protocolo . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/ticket_pedido_pdf.php?id=" . $id_mov;
$url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
$y_qr = $pdf->GetY();
$pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
$pdf->SetY($y_qr + 27);
$pdf->SetFont('Courier', '', 7);
$pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

$pdf->Output('I', "Comprobante_Pedido_N{$id_mov}.pdf");
?>