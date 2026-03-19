<?php
// ticket_producto_pdf.php - COMPROBANTE DE PRODUCTO INDIVIDUAL (VERSION DUEÑO)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
require_once 'includes/db.php';
require_once 'fpdf/fpdf.php';

$id_producto = $_GET['id'] ?? 0;
if (!$id_producto) die('Acceso invalido.');

// 1. CONSULTA DE DATOS DEL PRODUCTO
$stmt = $conexion->prepare("SELECT p.*, c.nombre as cat_nom FROM productos p LEFT JOIN categorias c ON p.id_categoria = c.id WHERE p.id = ?");
$stmt->execute([$id_producto]);
$p = $stmt->fetch(PDO::FETCH_OBJ);
if (!$p) die('El producto no existe.');

// 2. CONSULTA DEL DUEÑO PARA LA FIRMA (Idéntico a la lógica del modal)
$u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol 
                             FROM usuarios u 
                             JOIN roles r ON u.id_rol = r.id 
                             WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
$owner = $u_owner->fetch(PDO::FETCH_OBJ);

$firmante_aclaracion = $owner ? strtoupper($owner->nombre_completo . " | " . $owner->nombre_rol) : 'GERENCIA AUTORIZADA';
$ruta_firma = $owner ? "img/firmas/usuario_{$owner->id}.png" : "img/firmas/firma_admin.png";

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

// 3. CONFIGURACIÓN DEL PDF (80mm)
$pdf = new FPDF('P', 'mm', array(80, 180));
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
$pdf->Cell(0, 4, utf8_decode($conf->direccion_local), 0, 1, 'C');
$pdf->Ln(2);
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

$pdf->SetFont('Courier', 'B', 12);
$pdf->Cell(0, 6, utf8_decode('FICHA TECNICA PRODUCTO'), 0, 1, 'C');
$pdf->SetFont('Courier', '', 9);
$pdf->Cell(0, 4, "ID: #" . str_pad($id_producto, 6, '0', STR_PAD_LEFT), 0, 1, 'C');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

$pdf->Ln(3);
$pdf->SetFont('Courier', 'B', 9);
$pdf->MultiCell(0, 5, "ARTICULO: " . utf8_decode(strtoupper($p->descripcion)), 0, 'L');
$pdf->SetFont('Courier', '', 9);
$pdf->Cell(0, 5, utf8_decode("CÓDIGO: " . $p->codigo_barras), 0, 1, 'L');
$pdf->Cell(0, 5, utf8_decode("RUBRO: " . strtoupper($p->cat_nom ?? 'GENERAL')), 0, 1, 'L');

$pdf->Ln(3);
$pdf->SetFont('Courier', 'B', 14);
$stock_f = ($p->stock_actual % 1 === 0) ? intval($p->stock_actual) : number_format($p->stock_actual, 3);
$pdf->Cell(0, 8, "STOCK: " . $stock_f . " u.", 1, 1, 'C');

// 4. SECCIÓN DE FIRMA DEL DUEÑO (Actualizada y dinámica)
$pdf->Ln(10);
if (file_exists($ruta_firma)) {
    // Al ser generación de PDF en el servidor, lee el archivo directo de disco (siempre es el último guardado)
    $pdf->Image($ruta_firma, 25, $pdf->GetY(), 30);
    $pdf->SetY($pdf->GetY() + 12);
} else {
    $pdf->Ln(10);
}

$pdf->SetFont('Courier', '', 8);
$pdf->Cell(0, 4, "________________________", 0, 1, 'C'); 
$pdf->SetFont('Courier', 'B', 8);
$pdf->Cell(0, 4, utf8_decode($firmante_aclaracion), 0, 1, 'C');

$protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$link = $protocolo . "://" . $_SERVER['HTTP_HOST'] . "/ticket_producto_pdf.php?id=" . $id_producto;
$url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($link);
$pdf->Ln(5);
$pdf->Image($url_qr, 27, $pdf->GetY(), 26, 26, 'PNG');

$pdf->Output('I', "Ficha_Producto_{$id_producto}.pdf");