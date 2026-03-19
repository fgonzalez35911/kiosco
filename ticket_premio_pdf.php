<?php
// ticket_premio_pdf.php - FICHA TÉCNICA DEL PREMIO
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
require_once 'includes/db.php';
require_once 'fpdf/fpdf.php';

$id_premio = $_GET['id'] ?? 0;
if (!$id_premio) die('Acceso invalido.');

$stmt = $conexion->prepare("SELECT p.*, 
             u.nombre_completo as creador_nombre, u.usuario as creador_usuario, r.nombre as creador_rol,
             CASE 
                WHEN p.tipo_articulo = 'producto' THEN (SELECT descripcion FROM productos WHERE id = p.id_articulo)
                WHEN p.tipo_articulo = 'combo' THEN (SELECT nombre FROM combos WHERE id = p.id_articulo)
                ELSE NULL 
             END as nombre_vinculo
             FROM premios p 
             LEFT JOIN usuarios u ON p.id_usuario = u.id
             LEFT JOIN roles r ON u.id_rol = r.id
             WHERE p.id = ?");
$stmt->execute([$id_premio]);
$premio = $stmt->fetch(PDO::FETCH_OBJ);

if (!$premio) die('El registro del premio no existe.');

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

$tipoTxt = $premio->es_cupon == 1 ? 'CUPON DE DINERO' : 'ARTICULO FISICO';
$vinculoTxt = $premio->es_cupon == 1 ? "Monto a favor: $" . $premio->monto_dinero : "Stock: " . $premio->stock . " u.\nVinculo: " . ($premio->nombre_vinculo ?: 'General');

// CONFIGURACIÓN DEL PDF AUMENTADA
$pdf = new FPDF('P', 'mm', array(80, 200));
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
$pdf->Cell(0, 6, utf8_decode('FICHA DE PREMIO'), 0, 1, 'C');
$pdf->SetFont('Courier', '', 9);
$pdf->Cell(0, 4, "ID REF: #" . str_pad($premio->id, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

// INFORMACIÓN DE OPERACIÓN
$pdf->Ln(3);
$pdf->SetFont('Courier', 'B', 10);
$pdf->Cell(0, 5, "NOMBRE DEL PREMIO:", 0, 1, 'L');
$pdf->SetFont('Courier', '', 9);
$pdf->MultiCell(0, 5, utf8_decode(strtoupper($premio->nombre)), 0, 'L');

$pdf->Ln(3);
$pdf->SetFont('Courier', 'B', 9);
$pdf->Cell(0, 5, "TIPO: " . $tipoTxt, 0, 1, 'L');
$pdf->SetFont('Courier', '', 8);
$pdf->MultiCell(0, 4, utf8_decode($vinculoTxt), 0, 'L');

// TOTALIZACIÓN
$pdf->Ln(3);
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
$pdf->SetFont('Courier', 'B', 12);
$pdf->Cell(30, 8, "COSTO:", 0, 0, 'L');
$pdf->Cell(40, 8, $premio->puntos_necesarios . " pts", 0, 1, 'R');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

// SECCIÓN DE FIRMA DEL CREADOR
$pdf->Ln(8);
$y_firma = $pdf->GetY();
$ruta_firma = "img/firmas/firma_admin.png";
if (!empty($premio->id_usuario) && file_exists("img/firmas/usuario_{$premio->id_usuario}.png")) {
    $ruta_firma = "img/firmas/usuario_{$premio->id_usuario}.png";
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
$nombre_op = !empty($premio->creador_nombre) ? $premio->creador_nombre : (!empty($premio->creador_usuario) ? $premio->creador_usuario : 'SISTEMA');
$rol_op = !empty($premio->creador_rol) ? $premio->creador_rol : "OPERADOR";
$aclaracion = strtoupper($nombre_op) . " | " . strtoupper($rol_op);
$pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

// SECCIÓN DE QR DE VALIDACIÓN
$pdf->Ln(4);
$protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$linkPdfPublico = $protocolo . "://" . $_SERVER['HTTP_HOST'] . "/ticket_premio_pdf.php?id=" . $premio->id;
$url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
$y_qr = $pdf->GetY();
$pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
$pdf->SetY($y_qr + 27);
$pdf->SetFont('Courier', '', 7);
$pdf->Cell(0, 4, "ESCANEE PARA VALIDAR", 0, 1, 'C');

$pdf->Output('I', "Ficha_Premio_{$premio->id}.pdf");
?>