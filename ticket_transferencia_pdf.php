<?php
// ticket_transferencia_pdf.php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'includes/db.php';
require_once 'fpdf/fpdf.php';

session_start();

$id_mov = $_GET['id'] ?? 0;
if (!$id_mov) die('Acceso inválido.');

$stmt = $conexion->prepare("SELECT * FROM transferencias WHERE id = ?");
$stmt->execute([$id_mov]);
$mov = $stmt->fetch(PDO::FETCH_OBJ);
if (!$mov) die('El comprobante no existe.');

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_OBJ);

$d = json_decode($mov->datos_json, true);
$texto = $mov->texto_completo ?? '';

$op = $d['op'] ?? $d['operacion'] ?? $d['nro_op'] ?? '-';
$nom_emisor = $d['nom_e'] ?? '-';
$doc_emisor = $d['doc_e'] ?? '-';
$cbu_emisor = $d['cbu_e'] ?? '-';
$banco      = $d['banco_e'] ?? '-';

$nom_receptor = $d['nom_r'] ?? '-';
$doc_receptor = $d['doc_r'] ?? '-';
$cbu_receptor = $d['cbu_r'] ?? '-';

if (stripos($texto, 'MODO') !== false || stripos($mov->datos_json ?? '', 'MODO') !== false) {
    $doc_emisor = '-'; $doc_receptor = '-'; $cbu_emisor = '-'; 
    if (preg_match('/Ref\.\s*([a-zA-Z0-9\-]+)/i', $texto, $m)) $op = trim($m[1]);
    if (preg_match('/Transferencia de\s+(.*?)\s+Desde la cuenta/i', $texto, $m)) $nom_emisor = trim($m[1]);
    if (preg_match('/Desde la cuenta\s+(.*?)\s*(?:•|\.|CA|CC|\d)/i', $texto, $m)) $banco = 'MODO / ' . trim($m[1]); else $banco = 'MODO';
    if (preg_match('/Para\s+(.*?)\s+A su cuenta/i', $texto, $m)) $nom_receptor = trim($m[1]);
    if (preg_match('/CBU\/CVU\s*(\d{22})/i', $texto, $m)) $cbu_receptor = trim($m[1]);
} 
elseif (stripos($banco, 'Nación') !== false || stripos($banco, 'BNA') !== false) {
    if ($doc_emisor !== '-' && $doc_emisor !== '---') {
        $doc_receptor = $doc_emisor; $doc_emisor = '-';           
    }
}

$monto = (float)$mov->monto;

function es_valido_dato($val) {
    $v = trim((string)$val);
    return !empty($v) && $v !== '-' && $v !== '---' && stripos($v, 'no detectado') === false;
}

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

$titulo = 'COMPROBANTE TRANSFERENCIA';
$pdf->SetFont('Courier', 'B', 11);
$pdf->Cell(0, 6, utf8_decode($titulo), 0, 1, 'C');
$pdf->SetFont('Courier', '', 9);
$pdf->Cell(0, 4, "OP: #" . $op, 0, 1, 'C');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

$pdf->Ln(3);
$pdf->Cell(0, 5, "Fecha: " . date('d/m/Y H:i', strtotime($mov->fecha_registro)), 0, 1, 'L');

if (es_valido_dato($nom_emisor)) $pdf->MultiCell(0, 4, "Origen: " . utf8_decode(strtoupper($nom_emisor)), 0, 'L');
if (es_valido_dato($cbu_emisor)) $pdf->MultiCell(0, 4, "  CBU: " . utf8_decode($cbu_emisor), 0, 'L');
if (es_valido_dato($doc_emisor)) $pdf->MultiCell(0, 4, "  DNI/CUIT: " . utf8_decode($doc_emisor), 0, 'L');

if (es_valido_dato($banco)) $pdf->MultiCell(0, 4, "Banco: " . utf8_decode(strtoupper($banco)), 0, 'L');

if (es_valido_dato($nom_receptor)) $pdf->MultiCell(0, 4, "Destino: " . utf8_decode(strtoupper($nom_receptor)), 0, 'L');
if (es_valido_dato($cbu_receptor)) $pdf->MultiCell(0, 4, "  CBU: " . utf8_decode($cbu_receptor), 0, 'L');
if (es_valido_dato($doc_receptor)) $pdf->MultiCell(0, 4, "  DNI/CUIT: " . utf8_decode($doc_receptor), 0, 'L');

$pdf->Ln(3);
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');
$pdf->SetFont('Courier', 'B', 14);
$pdf->Cell(30, 8, "TOTAL:", 0, 0, 'L');
$pdf->Cell(40, 8, "$" . number_format($monto, 2, ',', '.'), 0, 1, 'R');
$pdf->Cell(0, 1, "------------------------------------------", 0, 1, 'C');

$pdf->Ln(8);
$y_firma = $pdf->GetY();
$stmtDueño = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id WHERE u.id_rol = 2 LIMIT 1");
$dueño = $stmtDueño->fetch(PDO::FETCH_OBJ);

$aclaracion = "";
$ruta_firma = "";

if ($dueño) {
    $aclaracion = strtoupper($dueño->nombre_completo . " | " . $dueño->nombre_rol);
    if (file_exists("img/firmas/usuario_" . $dueño->id . ".png")) {
        $ruta_firma = "img/firmas/usuario_" . $dueño->id . ".png";
    }
}
if (empty($ruta_firma)) {
    $ruta_firma = "img/firmas/firma_admin.png";
}
if (empty($aclaracion)) {
    $aclaracion = "OPERADOR AUTORIZADO";
}

if (file_exists($ruta_firma)) {
    $pdf->Image($ruta_firma, 28, $y_firma - 2, 32);
    $pdf->SetY($y_firma + 10);
} else {
    $pdf->SetY($y_firma + 10);
}

$pdf->SetFont('Courier', '', 8);
$pdf->Cell(0, 4, "________________________", 0, 1, 'C');
$pdf->SetFont('Courier', 'B', 8);
$pdf->Cell(0, 4, utf8_decode($aclaracion), 0, 1, 'C');

$pdf->Ln(4);
$protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$linkPdfPublico = $protocolo . "://" . $_SERVER['HTTP_HOST'] . "/ticket_transferencia_pdf.php?id=" . $id_mov . "&publico=1";
$url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
$y_qr = $pdf->GetY();
$pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
$pdf->SetY($y_qr + 27);
$pdf->SetFont('Courier', '', 7);
$pdf->Cell(0, 4, "VALIDADO POR IA", 0, 1, 'C');

$pdf->Output('I', "Comprobante_Transf_N{$id_mov}.pdf");
?>