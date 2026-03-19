<?php
// acciones/enviar_email_transferencia.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../libs/PHPMailer/src/Exception.php';
require '../libs/PHPMailer/src/PHPMailer.php';
require '../libs/PHPMailer/src/SMTP.php';
require_once '../includes/db.php';
require_once '../fpdf/fpdf.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) { 
    die(json_encode(['status' => 'error', 'msg' => 'Sesión expirada'])); 
}

$id_transf = $_POST['id'] ?? 0;
$email_destino = $_POST['email'] ?? '';

if (!$id_transf || empty($email_destino)) {
    die(json_encode(['status' => 'error', 'msg' => 'Faltan datos obligatorios.']));
}

function es_valido_dato($val) {
    $v = trim((string)$val);
    return !empty($v) && $v !== '-' && $v !== '---' && stripos($v, 'no detectado') === false;
}

try {
    $stmt = $conexion->prepare("SELECT * FROM transferencias WHERE id = ?");
    $stmt->execute([$id_transf]);
    $mov = $stmt->fetch(PDO::FETCH_OBJ);
    if (!$mov) throw new Exception('Registro inexistente.');

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

    // -- GENERAR PDF FISICO EN LA MEMORIA --
    $pdf = new FPDF('P', 'mm', array(80, 210));
    $pdf->AddPage();
    $pdf->SetMargins(4, 4, 4);
    $pdf->SetAutoPageBreak(true, 4);

    if (!empty($conf->logo_url) && file_exists("../" . $conf->logo_url)) {
        $pdf->Image("../" . $conf->logo_url, 25, 4, 30);
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

    $pdf->SetFont('Courier', 'B', 11);
    $pdf->Cell(0, 6, utf8_decode('COMPROBANTE TRANSFERENCIA'), 0, 1, 'C');
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
        if (file_exists("../img/firmas/usuario_" . $dueño->id . ".png")) {
            $ruta_firma = "../img/firmas/usuario_" . $dueño->id . ".png";
        }
    }
    if (empty($ruta_firma)) {
        $ruta_firma = "../img/firmas/firma_admin.png";
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
    $linkPdfPublico = $protocolo . "://" . $_SERVER['HTTP_HOST'] . "/ticket_transferencia_pdf.php?id=" . $id_transf . "&publico=1";
    $url_qr = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=1&data=" . urlencode($linkPdfPublico);
    $y_qr = $pdf->GetY();
    $pdf->Image($url_qr, 27, $y_qr, 26, 26, 'PNG');
    $pdf->SetY($y_qr + 27);
    $pdf->SetFont('Courier', '', 7);
    $pdf->Cell(0, 4, "VALIDADO POR IA", 0, 1, 'C');

    $pdf_content = $pdf->Output('S');

    // -- ARMADO DEL HTML DEL CORREO --
    $html_tabla = "<table width='100%' style='background: #f4f7fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    $html_tabla .= "<tr><td style='padding-bottom:5px;'><strong>Operación Nro:</strong></td><td align='right'>#{$op}</td></tr>";

    if (es_valido_dato($nom_emisor)) $html_tabla .= "<tr><td style='padding-bottom:5px;'><strong>Origen:</strong></td><td align='right'>".htmlspecialchars(strtoupper($nom_emisor))."</td></tr>";
    if (es_valido_dato($cbu_emisor)) $html_tabla .= "<tr><td colspan='2' align='right' style='font-size:12px; color:#555; padding-bottom:3px;'>CBU: ".htmlspecialchars($cbu_emisor)."</td></tr>";
    if (es_valido_dato($doc_emisor)) $html_tabla .= "<tr><td colspan='2' align='right' style='font-size:12px; color:#555; padding-bottom:5px;'>DNI/CUIT: ".htmlspecialchars($doc_emisor)."</td></tr>";

    if (es_valido_dato($banco)) $html_tabla .= "<tr><td style='padding-bottom:5px;'><strong>Banco:</strong></td><td align='right'>".htmlspecialchars(strtoupper($banco))."</td></tr>";

    if (es_valido_dato($nom_receptor)) $html_tabla .= "<tr><td style='padding-bottom:5px;'><strong>Destino:</strong></td><td align='right'>".htmlspecialchars(strtoupper($nom_receptor))."</td></tr>";
    if (es_valido_dato($cbu_receptor)) $html_tabla .= "<tr><td colspan='2' align='right' style='font-size:12px; color:#555; padding-bottom:3px;'>CBU: ".htmlspecialchars($cbu_receptor)."</td></tr>";
    if (es_valido_dato($doc_receptor)) $html_tabla .= "<tr><td colspan='2' align='right' style='font-size:12px; color:#555; padding-bottom:5px;'>DNI/CUIT: ".htmlspecialchars($doc_receptor)."</td></tr>";

    $html_tabla .= "<tr><td style='padding-top:10px; border-top:1px solid #ddd;'><strong>Monto Aprobado:</strong></td><td align='right' style='color:#198754; padding-top:10px; border-top:1px solid #ddd;'><strong>$" . number_format($monto, 2, ',', '.') . "</strong></td></tr>";
    $html_tabla .= "</table>";


    // -- ENVIAR EMAIL --
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.hostinger.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'info@federicogonzalez.net';
    $mail->Password   = 'Fmg35911@';
    $mail->SMTPSecure = 'ssl';
    $mail->Port       = 465;
    $mail->CharSet    = 'UTF-8';
    
    $mail->setFrom('info@federicogonzalez.net', $conf->nombre_negocio);
    $mail->addAddress($email_destino);
    
    // ADJUNTAR 1: TICKET PDF
    $mail->addStringAttachment($pdf_content, "Ticket_Transferencia_N{$id_transf}.pdf");

    // ADJUNTAR 2: FOTOS ESCANEADAS
    $rutas_cruda = $mov->imagen_base64 ?? '';
    $rutas = [];
    $es_json = json_decode($rutas_cruda, true);
    if (is_array($es_json)) {
        foreach($es_json as $img) { if(!empty(trim($img))) $rutas[] = trim($img); }
    } else {
        $array_temp = preg_split('/[,;|]/', $rutas_cruda);
        foreach($array_temp as $img) {
            $img_limpia = trim(str_replace(['"', "'", '[', ']'], '', $img)); 
            if(!empty($img_limpia)) $rutas[] = $img_limpia;
        }
    }

    $contador = 1;
    foreach($rutas as $ruta) {
        if (strpos($ruta, 'data:image') === 0) {
            $parts = explode(";base64,", $ruta);
            if (count($parts) == 2) {
                $decoded = base64_decode($parts[1]);
                $mail->addStringAttachment($decoded, "Captura_Original_{$id_transf}_parte{$contador}.jpg");
            }
        } else {
            $ruta_fisica = "../" . $ruta;
            if (file_exists($ruta_fisica)) {
                $mail->addAttachment($ruta_fisica, "Captura_Original_{$id_transf}_parte{$contador}.jpg");
            }
        }
        $contador++;
    }

    $mail->isHTML(true);
    $mail->Subject = "Comprobante de Transferencia Validada - " . $conf->nombre_negocio;

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #198754; border-radius: 10px; overflow: hidden;'>
        <div style='background: #198754; padding: 20px; text-align: center;'>
            <h2 style='color: white; margin: 0;'>Transferencia Validada con IA</h2>
        </div>
        <div style='padding: 30px; color: #333;'>
            <p>Hola,</p>
            <p>Se ha validado una transferencia en el sistema de manera exitosa el día " . date('d/m/Y', strtotime($mov->fecha_registro)) . ".</p>
            {$html_tabla}
            <p>Adjunto a este correo encontrarás el <b>Ticket Oficial en PDF</b> y la <b>Captura de Pantalla Original</b> de la transferencia.</p>
            <p style='margin-top:30px;'>Saludos cordiales,<br><strong>{$conf->nombre_negocio}</strong></p>
        </div>
    </div>";

    $mail->send();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => 'Error al enviar: ' . $e->getMessage()]);
}
?>