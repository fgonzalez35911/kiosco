<?php
// ticket_digital.php - VISOR PDF DE VENTA (CLON EXACTO DE TICKET 80MM FPDF)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'includes/db.php';
require_once 'fpdf/fpdf.php';

$id_venta = $_GET['id'] ?? 0;
if (!$id_venta) die('Acceso inválido.');

// Consulta de venta con cajeros y clientes
$stmt = $conexion->prepare("SELECT v.*, c.nombre as cliente, u.usuario as operador, u.nombre_completo, r.nombre as nombre_rol 
                             FROM ventas v 
                             LEFT JOIN clientes c ON v.id_cliente = c.id 
                             LEFT JOIN usuarios u ON v.id_usuario = u.id 
                             LEFT JOIN roles r ON u.id_rol = r.id 
                             WHERE v.id = ?");
$stmt->execute([$id_venta]);
$mov = $stmt->fetch(PDO::FETCH_OBJ);
if (!$mov) die('El comprobante no existe.');

// Traer los productos de esta venta
$stmtD = $conexion->prepare("SELECT dv.*, p.descripcion FROM detalle_ventas dv JOIN productos p ON dv.id_producto = p.id WHERE dv.id_venta = ?");
$stmtD->execute([$id_venta]);
$detalles = $stmtD->fetchAll(PDO::FETCH_OBJ);

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

// Calcular la altura dinámica del ticket según la cantidad de productos
$altura_total = 180 + (count($detalles) * 5);
if($altura_total < 240) $altura_total = 240;

$pdf = new FPDF('P', 'mm', array(80, $altura_total));
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

// ENCABEZADO LOCAL
$pdf->SetFont('Courier', 'B', 13);
$pdf->Cell(0, 6, utf8_decode(strtoupper($conf->nombre_negocio)), 0, 1, 'C');
$pdf->SetFont('Courier', '', 8);
if ($conf->cuit) $pdf->Cell(0, 4, "CUIT: " . $conf->cuit, 0, 1, 'C');
$pdf->Cell(0, 4, utf8_decode($conf->direccion_local), 0, 1, 'C');
$pdf->Ln(2);
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

// TITULO TICKET
$titulo = 'COMPROBANTE VENTA';
$pdf->SetFont('Courier', 'B', 12);
$pdf->Cell(0, 6, utf8_decode($titulo), 0, 1, 'C');
$pdf->SetFont('Courier', '', 9);
$num_ticket = !empty($mov->codigo_ticket) ? $mov->codigo_ticket : str_pad($mov->id, 6, '0', STR_PAD_LEFT);
$pdf->Cell(0, 4, "TICKET: #" . $num_ticket, 0, 1, 'C');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

// DATOS OPERACION
$pdf->Ln(3);
$pdf->Cell(0, 5, "Fecha: " . date('d/m/Y H:i', strtotime($mov->fecha)), 0, 1, 'L');
$cliente_nom = !empty($mov->cliente) ? $mov->cliente : 'Consumidor Final';
$pdf->MultiCell(0, 4, "Cliente: " . utf8_decode(strtoupper($cliente_nom)), 0, 'L');
$metodo = strtoupper($mov->metodo_pago ?: 'EFECTIVO');
$pdf->MultiCell(0, 4, "Metodo: " . utf8_decode($metodo), 0, 'L');
$pdf->MultiCell(0, 4, "Cajero: " . utf8_decode(strtoupper($mov->nombre_completo ?: $mov->operador ?: 'ADMIN')), 0, 'L');

// LISTA DE PRODUCTOS
$pdf->Ln(3);
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
$pdf->SetFont('Courier', 'B', 9);
$pdf->Cell(10, 5, "CANT", 0, 0, 'L');
$pdf->Cell(40, 5, "DESCRIPCION", 0, 0, 'L');
$pdf->Cell(22, 5, "SUBTOT", 0, 1, 'R');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
$pdf->SetFont('Courier', '', 8);

foreach ($detalles as $d) {
    $pdf->Cell(10, 4, floatval($d->cantidad), 0, 0, 'C');
    $desc = substr(utf8_decode($d->descripcion), 0, 18); // Cortamos si es muy largo
    $pdf->Cell(40, 4, $desc, 0, 0, 'L');
    $pdf->Cell(22, 4, "$" . number_format($d->subtotal, 2, ',', '.'), 0, 1, 'R');
}

$pdf->Ln(3);
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

// DESCUENTOS Y TOTAL
$descuentos = (float)$mov->descuento_monto_cupon + (float)$mov->descuento_manual;
if ($descuentos > 0) {
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(30, 5, "Descuentos:", 0, 0, 'L');
    $pdf->Cell(42, 5, "-$" . number_format($descuentos, 2, ',', '.'), 0, 1, 'R');
}

$pdf->SetFont('Courier', 'B', 14);
$pdf->Cell(30, 8, "TOTAL:", 0, 0, 'L');
$pdf->Cell(42, 8, "$" . number_format($mov->total, 2, ',', '.'), 0, 1, 'R');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

// FIRMA
$pdf->Ln(2);
$y_firma = $pdf->GetY();
$ruta_firma = "";

if (isset($mov->id_usuario) && $mov->id_usuario > 0 && file_exists("img/firmas/usuario_" . $mov->id_usuario . ".png")) {
    $ruta_firma = "img/firmas/usuario_" . $mov->id_usuario . ".png";
}

if (!empty($ruta_firma) && file_exists($ruta_firma)) {
    $pdf->Image($ruta_firma, 30, $y_firma - 8, 20);
    $pdf->SetY($y_firma + 12);
} else {
    $pdf->SetY($y_firma + 10);
}

$pdf->SetFont('Courier', '', 8);
$pdf->Cell(0, 4, "________________________", 0, 1, 'C');
$pdf->SetFont('Courier', 'B', 8);
$n_op = $mov->nombre_completo ? $mov->nombre_completo : ($mov->operador ?: 'ADMIN');
$r_op = $mov->nombre_rol ? $mov->nombre_rol : "CAJERO";
$aclaracion = strtoupper($n_op) . " | " . strtoupper($r_op);
$pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

// QR
$pdf->Ln(2);
$protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$linkPdfPublico = $protocolo . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/ticket_digital.php?id=" . $id_venta;
$url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
$y_qr = $pdf->GetY();
$pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
$pdf->SetY($y_qr + 27);
$pdf->SetFont('Courier', '', 7);
$pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

$pdf->Output('I', "Comprobante_Venta_N{$num_ticket}.pdf");
?>