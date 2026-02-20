<?php
// Archivo: generar_pedido_pdf.php (v20 - Base "perfecto" + PHP QR Code Público + Marca Agua)
// *** MODIFICADO (v6) PARA FORMATEAR NÚMERO DE WHATSAPP ***
// *** MODIFICADO (v9) POR GEMINI PARA CORREGIR ERROR 500 (bMargin) Y AÑADIR SELLO "CANCELADO" ***

// --- MANEJO DE ERRORES ---
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar errores en pantalla
ini_set('log_errors', 1);    // Registrar errores

// --- CARGA LIBRERÍAS Y CLASES ---
require('fpdf/fpdf.php');
// ---> INCLUIR LIBRERÍA PHP QR CODE (con verificación) <---\
$phpqrcode_loaded = false;
$phpqrcode_path = __DIR__ . '/phpqrcode/qrlib.php'; // Ruta relativa al script actual
if (file_exists($phpqrcode_path)) {
    include_once($phpqrcode_path); // Usar include_once
    $phpqrcode_loaded = true;
} else {
    error_log("Error CRÍTICO: No se encontró la librería QR en " . $phpqrcode_path);
    // Continuaremos sin QR
}

// --- Conexión (con manejo de error temprano) ---
$pdo = null;
$session_active = false;
if (session_status() == PHP_SESSION_NONE) { session_start(); }
$es_llamada_interna = $es_llamada_interna ?? false;
// Verificación de sesión mantenida
if (isset($_SESSION['usuario_id']) || (isset($es_llamada_interna) && $es_llamada_interna)) {
    $session_active = true;
    try {
        if (!file_exists('conexion.php')) throw new Exception("Archivo conexion.php no encontrado.");
        include_once 'conexion.php';
        if (!isset($pdo)) { throw new Exception("Variable \$pdo no definida por conexion.php"); }
    } catch (Exception $e) {
        if (isset($es_llamada_interna) && $es_llamada_interna) { throw $e; }
        $pdo = null; require_once('fpdf/fpdf.php'); $errorPdf = new FPDF(); $errorPdf->AddPage(); $errorPdf->SetFont('Arial', 'B', 12); $errorPdf->SetTextColor(255, 0, 0);
        $errorPdf->MultiCell(0, 10, utf8_decode("ERROR CRÍTICO:\nNo se pudo conectar a la base de datos.\n\nDetalles:\n" . $e->getMessage()), 1, 'C');
        $errorPdf->Output('I', 'error_conexion.pdf'); exit;
    }
} else { 
    header("Location: login.php"); exit(); 
}

// --- DEFINIR ANCHO ÚTIL Y MÁRGENES (Igual que tu archivo) ---
define('USABLE_WIDTH', 210 - 20 - 10);
define('LEFT_MARGIN', 20);

// Si ya viene definido por firma_externa, lo usamos, sino lo buscamos en la URL
$id_pedido = (int)($id_pedido ?? ($_GET['id'] ?? 0));
$modo = $modo ?? ($_GET['modo'] ?? 'inicial');

if ($id_pedido <= 0) {
    require_once('fpdf/fpdf.php'); $errorPdf = new FPDF(); $errorPdf->AddPage(); $errorPdf->SetFont('Arial','B',12); $errorPdf->SetTextColor(255,0,0);
    $errorPdf->Cell(0, 10, utf8_decode("Error: ID de pedido no válido."), 1, 1, 'C'); 
    if (isset($es_llamada_interna) && $es_llamada_interna) return;
    $errorPdf->Output('I', 'error_id_pedido.pdf'); exit;
}

// 2. Inicializar variables (Igual que tu archivo)
$pedido_data = null; $tarea_data = null; $tecnico_data = null; $auxiliar_data = null;
$encargado_data = null; $actualizaciones = []; $adjuntos = [];
$area_nombre = 'N/A'; $destino_nombre = 'N/A'; $num_orden_display = 'Error';
$database_error = null;

// --- 3. Consultas a la Base de Datos (Igual que tu archivo) ---
try {
    // Consulta Pedido
    // (p.* incluye 'solicitante_telefono', 'firma_solicitante_path' y 'solicitante_real_nombre')
    $sql_pedido = "SELECT p.*, a.nombre AS area_nombre, d.nombre AS destino_nombre, u_aux.nombre_completo AS auxiliar_nombre, u_aux.firma_imagen_path AS auxiliar_firma FROM pedidos_trabajo p LEFT JOIN areas a ON p.id_area = a.id_area LEFT JOIN destinos_internos d ON p.id_destino_interno = d.id_destino LEFT JOIN usuarios u_aux ON p.id_auxiliar = u_aux.id_usuario WHERE p.id_pedido = :id_pedido";
    $stmt_pedido = $pdo->prepare($sql_pedido); $stmt_pedido->execute([':id_pedido' => $id_pedido]);
    $pedido_data = $stmt_pedido->fetch(PDO::FETCH_ASSOC);

    if (!$pedido_data) { $database_error = "Pedido #{$id_pedido} no encontrado."; }
    else {
        $num_orden_display = $pedido_data['numero_orden'] ?? ('ID ' . $id_pedido);
        $area_nombre = $pedido_data['area_nombre'] ?? ($pedido_data['area_solicitante'] ?? 'N/A');
        $destino_nombre = $pedido_data['destino_nombre'] ?? ($pedido_data['destino_interno'] ?? 'N/A');
        $auxiliar_data = ['nombre' => $pedido_data['auxiliar_nombre'] ?? 'Usuario Desconocido', 'firma' => $pedido_data['auxiliar_firma'] ?? null];

        if ($modo === 'final' && !empty($pedido_data['id_tarea_generada'])) {
            // Consultas Tarea, Actualizaciones, Adjuntos
            $id_tarea = $pedido_data['id_tarea_generada'];
            $sql_tarea = "SELECT t.nota_final, t.fecha_cierre, t.fecha_creacion AS tarea_fecha_creacion, t.estado, u_tec.nombre_completo AS tecnico_nombre, u_tec.firma_imagen_path AS tecnico_firma, u_creador.id_usuario AS encargado_id, u_creador.nombre_completo AS encargado_nombre, u_creador.firma_imagen_path AS encargado_firma FROM tareas t LEFT JOIN usuarios u_tec ON t.id_asignado = u_tec.id_usuario LEFT JOIN usuarios u_creador ON t.id_creador = u_creador.id_usuario WHERE t.id_tarea = :id_tarea";
            $stmt_tarea = $pdo->prepare($sql_tarea); $stmt_tarea->execute([':id_tarea' => $id_tarea]); $tarea_data = $stmt_tarea->fetch(PDO::FETCH_ASSOC);
            
            // --- INICIO MODIFICACIÓN GEMINI (v9) ---
            // Permitir 'modo=final' si la tarea está 'verificada' O 'cancelada'
            if ($tarea_data && in_array($tarea_data['estado'], ['verificada', 'cancelada'])) {
            // --- FIN MODIFICACIÓN GEMINI (v9) ---
            
                $tecnico_data = ['nombre' => $tarea_data['tecnico_nombre'] ?? 'No asignado', 'firma' => $tarea_data['tecnico_firma'] ?? null];
                $encargado_data = ['id' => $tarea_data['encargado_id'] ?? null, 'nombre' => $tarea_data['encargado_nombre'] ?? 'No registrado', 'firma' => $tarea_data['encargado_firma'] ?? null];
                $sql_updates = "SELECT u.nombre_completo, a.contenido, a.fecha_actualizacion FROM actualizaciones_tarea a JOIN usuarios u ON a.id_usuario = u.id_usuario WHERE a.id_tarea = :id_tarea ORDER BY a.fecha_actualizacion ASC";
                $stmt_updates = $pdo->prepare($sql_updates); $stmt_updates->execute([':id_tarea' => $id_tarea]); $actualizaciones = $stmt_updates->fetchAll(PDO::FETCH_ASSOC);
                $sql_adjuntos = "SELECT nombre_archivo, tipo_adjunto, ruta_archivo FROM adjuntos_tarea WHERE id_tarea = :id_tarea ORDER BY FIELD(tipo_adjunto, 'inicial', 'actualizacion', 'remito', 'final'), fecha_subida";
                $stmt_adjuntos = $pdo->prepare($sql_adjuntos); $stmt_adjuntos->execute([':id_tarea' => $id_tarea]); $adjuntos = $stmt_adjuntos->fetchAll(PDO::FETCH_ASSOC);
            } else { 
                // Si la tarea no está 'verificada' o 'cancelada' (ej: 'en_proceso'), forzar modo inicial
                $modo = 'inicial'; 
            }
        } else { $modo = 'inicial'; }
    }
} catch (PDOException $e) { $database_error = "Error al obtener datos: " . $e->getMessage(); error_log("Error BD generar_pedido_pdf: " . $e->getMessage()); }
// --- FIN Consultas BD ---


// ---> DEFINIR NOMBRE Y RUTA PÚBLICA DEL PDF ANTES DE GENERAR QR <---
$pdf_public_dir = 'pdfs_publicos';
// Limpiamos el número de orden de espacios y barras para evitar problemas de URL
$num_limpio = str_replace(['/', ' '], ['-', ''], $num_orden_display);
$pdf_filename_base = 'Pedido_Trabajo_' . $num_limpio;
$pdf_filename = $pdf_filename_base . ($modo === 'final' ? '_Final' : '_Inicial') . '.pdf';

// RUTA ABSOLUTA PARA EL DISCO (FTP)
$pdf_save_path = __DIR__ . DIRECTORY_SEPARATOR . $pdf_public_dir . DIRECTORY_SEPARATOR . $pdf_filename;

// Verificar y crear directorio con ruta absoluta
$pdf_dir_abs = __DIR__ . DIRECTORY_SEPARATOR . $pdf_public_dir;
if (!is_dir($pdf_dir_abs)) {
    @mkdir($pdf_dir_abs, 0775, true);
}
$pdf_dir_ok = is_writable($pdf_dir_abs);

// CONSTRUIR URL SIN DOBLE BARRA
$host = $_SERVER['HTTP_HOST'];
$protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
// Forzamos la base a la carpeta del proyecto sin barras sobrantes
$urlParaPDFPublico = $protocolo . "://" . $host . "/logistica/" . $pdf_public_dir . "/" . rawurlencode($pdf_filename);
// ---> FIN DEFINICIÓN RUTAS PDF <---


// ---> GENERACIÓN DEL QR (ANTES DE LA CLASE PDF - USANDO PHP QR CODE) <---
$qr_code_file_path = null;
if ($phpqrcode_loaded && $pedido_data && !$database_error && $pdf_dir_ok)
{
    try {
        // Definir ruta archivo temporal QR
        $temp_dir = sys_get_temp_dir();
        if (!is_writable($temp_dir)) {
            $temp_dir = __DIR__ . DIRECTORY_SEPARATOR . 'temp_qrcodes'; // Fallback
            if (!is_dir($temp_dir)) { if (!@mkdir($temp_dir, 0775, true) && !is_dir($temp_dir)) { throw new Exception("No se pudo crear directorio temporal local."); } }
            if (!is_writable($temp_dir)) { throw new Exception("Directorio temporal (" . $temp_dir . ") no escribible."); }
        }
        $qr_filename_temp = 'qr_pdf_' . $id_pedido . '_' . time() . '.png';
        $qr_code_file_path = rtrim($temp_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $qr_filename_temp;

        // Generar QR apuntando a la URL del PDF PÚBLICO
        QRcode::png($urlParaPDFPublico, $qr_code_file_path, QR_ECLEVEL_L, 4, 1); // Nivel L, tamaño 4, margen 1

        if (!file_exists($qr_code_file_path) || filesize($qr_code_file_path) === 0) {
            throw new Exception("No se pudo generar archivo QR temporal en " . $qr_code_file_path);
        }
        error_log("QR Code generado (phpqrcode) en: " . $qr_code_file_path);

    } catch (Throwable $e) {
        error_log("Error CRÍTICO al generar QR Code (phpqrcode): " . $e->getMessage());
        $qr_code_file_path = null;
        $database_error .= "\nAdvertencia: No se pudo generar el código QR.";
    }
} elseif (!$phpqrcode_loaded) { $database_error .= "\nAdvertencia: Librería QR (phpqrcode) no encontrada.";
} elseif (!$pdf_dir_ok) { $database_error .= "\nAdvertencia: No se pudo generar QR (dir PDFs inaccesible)."; }
// ---> FIN GENERACIÓN QR <---


// --- 4. Clase PDF (CON MARCA DE AGUA y ajustes condensados) ---
class PDF extends FPDF
{
    public $qrFilePath = null;
    private $watermarkPath = 'assets/img/logo.png'; // Ruta a la marca de agua
    public $is_cancelled = false; // <-- AÑADIDO POR GEMINI (v9)

    // --- AÑADIDO POR GEMINI (v9): Función de Rotación ---
    var $angle = 0;
    function Rotate($angle, $x=-1, $y=-1)
    {
        if ($x==-1) $x = $this->x;
        if ($y==-1) $y = $this->y;
        if ($this->angle!=0) $this->_out('Q');
        $this->angle = $angle;
        if ($angle!=0) {
            $angle *= M_PI/180;
            $c = cos($angle);
            $s = sin($angle);
            $cx = $x * $this->k;
            $cy = ($this->h - $y) * $this->k;
            $this->_out(sprintf('q %.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm', $c, $s, -$s, $c, $cx, $cy, -$cx, -$cy));
        }
    }
    function _endpage()
    {
        if ($this->angle!=0) {
            $this->angle = 0;
            $this->_out('Q');
        }
        parent::_endpage();
    }
    // --- FIN Función de Rotación ---

    // Header (CON MARCA DE AGUA y Sellos - Código de v16)
    function Header() {
        // ---> AÑADIR MARCA DE AGUA (GRANDE, CENTRADA, DETRÁS) <---
        $watermarkPath = __DIR__ . '/assets/img/logo_watermark_gris.png';

        if (file_exists($watermarkPath)) {
            $pageW = $this->GetPageWidth();
            $pageH = $this->GetPageHeight();
            $imgW = 90; 
            $imgH = 0;   
            $imgX = ($pageW - $imgW) / 2;
            $imgY = ($pageH / 2) - ($imgW / 2) - 10; 

            try{
                list($w_orig, $h_orig) = @getimagesize($watermarkPath);
                if ($w_orig && $h_orig) {
                    $aspect = $w_orig / $h_orig;
                    $imgH = $imgW / $aspect;
                    $this->Image($watermarkPath, $imgX, $imgY, $imgW, $imgH, 'PNG');
                } else {
                     error_log("No se pudieron obtener dimensiones de la marca de agua: " . $watermarkPath);
                }
            } catch (Exception $e) {
                 error_log("Error al dibujar marca de agua: ".$e->getMessage());
            }
        } else {
             error_log("No se encontró la imagen de marca de agua en: " . $watermarkPath);
        }
        // ---> FIN MARCA DE AGUA <---

        // --- INICIO MODIFICACIÓN GEMINI (v9): Sello "CANCELADO" ---
        // Se dibuja *DESPUÉS* de la marca de agua gris, pero *ANTES* de los sellos (DUPLICADO)
        if ($this->is_cancelled) {
            $this->SetFont('Arial', 'B', 60); // Más grande
            $this->SetTextColor(200,100, 100); // Rojo
            
            // Guardar estado actual
            $current_x = $this->GetX();
            $current_y = $this->GetY();
            
            // Posición central (aprox)
            $x_pos = 70; // Ajustado
            $y_pos = 160; // Ajustado

            // Rotar y escribir
            $this->Rotate(45, $x_pos, $y_pos); 
            $this->Text($x_pos, $y_pos, 'CANCELADO');
            $this->Rotate(0); // Resetear rotación
            
            // Restaurar estado
            $this->SetXY($current_x, $current_y);
            $this->SetTextColor(0, 0, 0);
        }
        // --- FIN MODIFICACIÓN GEMINI (v9) ---

        // --- Sellos (Original Grande Centrado, Trabajo Derecha) ---
        $sello_original_path = __DIR__ . '/assets/img/sello_duplicado.png'; 
        $sello_trabajo_path = __DIR__ . '/assets/img/sello_trabajo.png';
        $sello_width_orig = 50; $sello_width_trab = 25; $sello_margin_top = 5; $sello_margin_right = $this->rMargin; $max_sello_height = 0;
        if (file_exists($sello_original_path)) { $x_original = ($this->GetPageWidth() - $sello_width_orig) / 2; try { list($w, $h) = @getimagesize($sello_original_path); $aspect = ($w && $h) ? $w / $h : 1; $sello_height_orig = $sello_width_orig / $aspect; $this->Image($sello_original_path, $x_original, $sello_margin_top, $sello_width_orig); $max_sello_height = max($max_sello_height, $sello_height_orig); } catch (Exception $e) { /*...*/ } }
        if (file_exists($sello_trabajo_path)) { $x_trabajo = $this->GetPageWidth() - $sello_margin_right - $sello_width_trab; try { list($w, $h) = @getimagesize($sello_trabajo_path); $aspect = ($w && $h) ? $w / $h : 1; $sello_height_trab = $sello_width_trab / $aspect; $this->Image($sello_trabajo_path, $x_trabajo, $sello_margin_top, $sello_width_trab); $max_sello_height = max($max_sello_height, $sello_height_trab); } catch (Exception $e) { /*...*/ } }
        // --- Títulos del Encabezado ---
        
        $y_start_text = ($max_sello_height > 0) ? ($sello_margin_top + $max_sello_height + 2) : 10;
        
        $this->SetY($y_start_text);
        $this->SetFont('Arial', 'B', 10); $this->Cell(0, 5, utf8_decode('SUBGERENCIA DE EFECTORES SANITARIOS PROPIOS'), 0, 1, 'L');
        $this->SetFont('Arial', '', 10); $this->Cell(0, 5, utf8_decode('APOYO LOGÍSTICO'), 0, 1, 'L');
        $this->Ln(3);
    } // Fin Header

    // --- OTRAS FUNCIONES DE CLASE (CheckBox, SectionTitle, DrawSignatureBlock, Footer) ---
    function CheckBox($label, $value, $prioridad_pedido) {
        $this->SetFont('Arial', '', 9); $current_y = $this->GetY();
        $box_size = 4; $space_after_box = 1.5; $label_width = 35; $space_after_label = 5;
        $total_item_width = $box_size + $space_after_box + $label_width + $space_after_label; $current_x = $this->GetX();
        $this->Rect($current_x, $current_y, $box_size, $box_size, 'D');
        if (strtolower($value) === strtolower($prioridad_pedido)) { $this->SetFont('Arial', 'B', 10); $this->SetXY($current_x, $current_y); $this->Cell($box_size, $box_size, 'X', 0, 0, 'C'); $this->SetFont('Arial', '', 9); }
        $label_start_x = $current_x + $box_size + $space_after_box; $this->SetXY($label_start_x, $current_y); $this->Cell($label_width, $box_size, utf8_decode($label), 0, 0, 'L');
        $next_item_start_x = $current_x + $total_item_width; $this->SetXY($next_item_start_x, $current_y);
    }
    function SectionTitle($title) {
        $this->SetFont('Arial', 'B', 10); $this->SetFillColor(230, 230, 230); $this->Cell(USABLE_WIDTH, 5, utf8_decode($title), 1, 1, 'L', true); $this->SetFont('Arial', '', 9);
    }
    
    function DrawSignatureBlock($title, $name, $date_event, $signature_path_relative, $x, $y, $w, $h) {
        $current_x_sig = $this->GetX(); $current_y_sig = $this->GetY(); $this->SetXY($x, $y); $this->SetFont('Arial', 'B', 9); $this->Cell($w, 4, utf8_decode($title), 0, 1, 'L');
        $y_content_start = $this->GetY(); $content_height = $h - 6; $signature_area_height = $content_height * 0.90; $name_area_height = $content_height * 0.30;
        
        $firma_path_completa = null;
        if (!empty($signature_path_relative)) {
            if (strpos($signature_path_relative, 'uploads/') === 0) {
                $potential_path = $signature_path_relative;
            } else {
                $potential_path = 'uploads/firmas/' . $signature_path_relative;
            }
            if (file_exists($potential_path)) {
                $firma_path_completa = $potential_path;
            } else {
                error_log("Firma no encontrada (Draw): " . $potential_path);
            }
        }
        
        $this->SetFont('Arial', '', 7); if ($firma_path_completa) { try { list($img_w, $img_h) = @getimagesize($firma_path_completa); if ($img_w && $img_h) { $aspect_ratio = $img_w / $img_h; $max_img_h = $signature_area_height - 0.5; $max_img_w = $w - 1; $new_w = $max_img_w; $new_h = $new_w / $aspect_ratio; if ($new_h > $max_img_h) { $new_h = $max_img_h; $new_w = $new_h * $aspect_ratio; } $img_draw_x = $x + ($w - $new_w) / 2; $img_draw_y = $y_content_start + ($signature_area_height - $new_h) / 2; $this->Image($firma_path_completa, $img_draw_x, $img_draw_y, $new_w, $new_h); } else { throw new Exception("getimagesize falló"); } } catch (Exception $e) { $this->SetXY($x + 1, $y_content_start + 1); $fecha_f = $date_event ? date('d/m/y H:i', strtotime($date_event)) : 'N/A'; $this->MultiCell($w - 2, 3.5, utf8_decode("Fecha: " . $fecha_f . "\n(Error al cargar firma)"), 0, 'C'); } } else { $this->SetXY($x + 1, $y_content_start + 1); $fecha_f = $date_event ? date('d/m/y H:i', strtotime($date_event)) : 'N/A'; $etiqueta_fecha = (strpos($title, 'Registrado') !== false) ? 'Fec. Reg.:' : ((strpos($title, 'Autorizado') !== false) ? 'Fec. Aut.:' : ((strpos($title, 'Realizado') !== false) ? 'Fec. Cierre:' : ((strpos($title, 'Solicitado') !== false) ? 'Fec. Solicitud:' : 'Fec. Ctrl:'))); $this->MultiCell($w - 2, 3.5, utf8_decode($etiqueta_fecha . " " . $fecha_f . "\n(Firma Pendiente)"), 0, 'C'); }
        $y_name_start = $y_content_start + $signature_area_height + 0.5; $this->SetXY($x, $y_name_start); $this->SetFont('Arial', '', 8); $this->Cell($w, $name_area_height - 1, utf8_decode($name), 'T', 0, 'C'); $this->SetXY($current_x_sig, $current_y_sig);
    }
    
    // Footer (CON QR usando $this->qrFilePath)
    function Footer() {
        $this->SetY(-18); $this->SetFont('Arial','I',8); $qr_size = 15; $text_start_x = LEFT_MARGIN;
        if ($this->qrFilePath && file_exists($this->qrFilePath)) {
            $qr_x = LEFT_MARGIN; $qr_y = $this->GetY() - 2;
            try {
                $this->Image($this->qrFilePath, $qr_x, $qr_y, $qr_size);
                $text_start_x = $qr_x + $qr_size + 2;
            } catch (Exception $e) {
                error_log("Error dibujar QR Footer: ".$e->getMessage());
            }
        }
        $pagination_width = 20; $text_width = USABLE_WIDTH - ($text_start_x - LEFT_MARGIN) - $pagination_width;
        $this->SetX($text_start_x); $this->Cell($text_width, 4, utf8_decode('Pedido de trabajo generado: ') . date('d/m/Y H:i:s'), 0, 1, 'L');
        $this->SetX($text_start_x); $this->SetFont('Arial','',7); $this->Cell($text_width, 4, utf8_decode('Apoyo Logístico - Encargado SM I Marcelo Cañete - Interno 254 '), 0, 1, 'L');
        $this->SetX($text_start_x); $this->SetFont('Arial','',7); $this->Cell($text_width, 4, utf8_decode('Sistema desarrollado por SG Mec Info Federico GONZÁLEZ - marcelo.gonzalez@iosfa.gob.ar'), 0, 0, 'L');
        $this->SetY(-18); $this->SetX(LEFT_MARGIN + USABLE_WIDTH - $pagination_width); $this->SetFont('Arial','I',8);
        $this->Cell($pagination_width, 10, utf8_decode('Página ').$this->PageNo().'/{nb}', 0, 0, 'R');
    }
} // Fin Class PDF


// --- 5. Generación del PDF ---
$pdf = new PDF('P', 'mm', 'A4');
if ($qr_code_file_path) { $pdf->qrFilePath = $qr_code_file_path; }

// --- INICIO MODIFICACIÓN GEMINI (v9) ---
// Si la tarea se cargó y su estado es 'cancelada', activar el flag en la clase PDF
if ($tarea_data && $tarea_data['estado'] === 'cancelada') {
    $pdf->is_cancelled = true;
}
// --- FIN MODIFICACIÓN GEMINI (v9) ---

$pdf->SetLeftMargin(LEFT_MARGIN); $pdf->SetRightMargin(10); $pdf->AliasNbPages(); $pdf->AddPage();
$pdf->SetFont('Arial', '', 10);
$pdf->SetAutoPageBreak(true, 20); // Margen inferior de 20mm (2cm)

// --- MOSTRAR ERROR SI EXISTE ---
if ($database_error) {
    $pdf->SetFont('Arial', 'B', 12); $pdf->SetTextColor(255, 0, 0);
    $pdf->MultiCell(USABLE_WIDTH, 8, utf8_decode("ERROR AL GENERAR PDF:\n" . $database_error), 1, 'C');
    $pdf->SetTextColor(0, 0, 0); $pdf->Ln(5);
}

// === CONTENIDO DEL PDF (Restaurado completamente, condensado) ===
if ($pedido_data && strpos($database_error ?? '', 'Error al obtener datos') === false)
{
    // === TÍTULO Y N° DE ORDEN ===
    $title_width = USABLE_WIDTH - 60; $order_date_width = 30;
    $pdf->SetFont('Arial', 'B', 14); $pdf->Cell($title_width, 6, utf8_decode('PEDIDO DE TRABAJO'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 9); $pdf->Cell($order_date_width, 6, utf8_decode('N° Orden:'), 0, 0, 'R');
    $pdf->SetFont('Arial', 'B', 11); $pdf->Cell($order_date_width, 6, utf8_decode($num_orden_display), 1, 1, 'C');
    $pdf->SetFont('Arial', '', 9); $pdf->Cell($title_width, 6, '', 0, 0, 'L'); $pdf->Cell($order_date_width, 6, 'Fecha Emision:', 0, 0, 'R'); $pdf->Cell($order_date_width, 6, date('d/m/Y', strtotime($pedido_data['fecha_emision'])), 1, 1, 'C');
    $pdf->Ln(2);
    // === PRIORIDAD Y FECHA REQUERIDA ===
    $prio_cell_width = USABLE_WIDTH * 0.7; $date_cell_width = USABLE_WIDTH - $prio_cell_width;
    $pdf->SectionTitle('Prioridad y Plazo'); $y_after_title_prio = $pdf->GetY();
    $pdf->Cell($prio_cell_width, 6, '', 1, 0, 'L'); $pdf->Cell($date_cell_width, 6, '', 1, 1, 'L');
    $pdf->SetY($y_after_title_prio + 1); $pdf->SetX($pdf->GetX() + 3);
    $pdf->CheckBox('URGENTE', 'urgente', $pedido_data['prioridad']); $pdf->CheckBox('IMPORTANTE', 'importante', $pedido_data['prioridad']); $pdf->CheckBox('RUTINA', 'rutina', $pedido_data['prioridad']);
    $pdf->SetY($y_after_title_prio + 1); $pdf->SetX(LEFT_MARGIN + $prio_cell_width + 2);
    $pdf->SetFont('Arial','B',8); $pdf->Cell(28, 4, utf8_decode('Fecha Requerida:'), 0, 0, 'L');
    $pdf->SetFont('Arial','',8); $fecha_req_str = $pedido_data['fecha_requerida'] ? date('d/m/Y', strtotime($pedido_data['fecha_requerida'])) : 'No especificada';
    $pdf->Cell(0, 4, $fecha_req_str, 0, 1, 'L');
    $pdf->SetY($y_after_title_prio + 6); $pdf->Ln(1);
    
    // === SOLICITANTE Y UBICACIÓN ===
    $pdf->SectionTitle('Solicitud y Ubicación');
    
    // *** INICIO MODIFICACIÓN (v6): Lógica de link WhatsApp ***
    
    $label_width = 45; 
    $value_width = USABLE_WIDTH - $label_width;
    $line_height = 5;

    // 1. Solicitante (Ext.):
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell($label_width, $line_height, utf8_decode('Solicitante (Ext.):'), 'LRB', 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell($value_width, $line_height, utf8_decode($pedido_data['solicitante_real_nombre'] ?? 'N/A'), 'RB', 1, 'L');
    $pdf->SetX(LEFT_MARGIN); $pdf->Cell($label_width, 0, '', 'LR', 0); $pdf->Cell($value_width, 0, '', 'R', 1); // Línea invisible
    
    // 2. Teléfono (WhatsApp)
    if (!empty($pedido_data['solicitante_telefono'])) {
        
        $telefono_numerico = preg_replace('/[^0-9]/', '', $pedido_data['solicitante_telefono']);
        $link_whatsapp = '';

        if (!empty($telefono_numerico)) {
            if (substr($telefono_numerico, 0, 2) === '54') {
                $link_whatsapp = 'https://wa.me/' . $telefono_numerico;
            } 
            elseif (strlen($telefono_numerico) == 10) {
                $link_whatsapp = 'https://wa.me/549' . $telefono_numerico;
            } 
            elseif (substr($telefono_numerico, 0, 2) === '15' && strlen($telefono_numerico) == 10) {
                 $numero_base = substr($telefono_numerico, 2);
                 $link_whatsapp = 'https://wa.me/54911' . $numero_base; 
            }
            else {
                 $link_whatsapp = 'https://wa.me/' . $telefono_numerico;
            }
        }
        $valor_telefono = utf8_decode($pedido_data['solicitante_telefono']);

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell($label_width, $line_height, utf8_decode('Teléfono (WhatsApp):'), 'LRB', 0, 'L');
        
        $pdf->SetFont('Arial', 'U', 9); 
        $pdf->SetTextColor(0, 0, 255); 
        
        if (!empty($link_whatsapp)) {
            $pdf->Cell($value_width, $line_height, $valor_telefono, 'RB', 1, 'L', false, $link_whatsapp);
        } else {
            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell($value_width, $line_height, $valor_telefono, 'RB', 1, 'L', false, '');
        }
        
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetX(LEFT_MARGIN); $pdf->Cell($label_width, 0, '', 'LR', 0); $pdf->Cell($value_width, 0, '', 'R', 1); // Línea invisible
    }
    
    // 3. Área Solicitante:
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell($label_width, $line_height, utf8_decode('Área Solicitante:'), 'LRB', 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell($value_width, $line_height, utf8_decode($area_nombre), 'RB', 1, 'L');
    $pdf->SetX(LEFT_MARGIN); $pdf->Cell($label_width, 0, '', 'LR', 0); $pdf->Cell($value_width, 0, '', 'R', 1); // Línea invisible

    // 4. Destino Interno:
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell($label_width, $line_height, utf8_decode('Destino Interno:'), 'LRB', 0, 'L');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell($value_width, $line_height, utf8_decode($destino_nombre), 'RB', 1, 'L');
    $pdf->SetX(LEFT_MARGIN); $pdf->Cell($label_width, 0, '', 'LR', 0); $pdf->Cell($value_width, 0, '', 'R', 1); // Línea invisible
    // *** FIN MODIFICACIÓN (v6) ***

    
    // === DESCRIPCIÓN SÍNTOMAS ===
    $pdf->SectionTitle('Descripción del Pedido / Síntomas');
    $pdf->SetFont('Arial', '', 9); $pdf->MultiCell(USABLE_WIDTH, 4, utf8_decode($pedido_data['descripcion_sintomas']), 1, 'L');
    
    // *** INICIO MODIFICACIÓN (v4): Lógica de 1 o 2 columnas ***
    $pdf->Ln(2); $pdf->SectionTitle('Registro y Autorización del Pedido');
    $y_start_bloque1 = $pdf->GetY(); 
    $alto_bloque1 = 25;

    // Comprobar si hay firma externa. Si no la hay, es un pedido de Admin.
    if (!empty($pedido_data['firma_solicitante_path'])) {
        // CASO 1: Hay firma externa (Auxiliar creó) -> DIBUJAR 2 COLUMNAS
        $ancho_columna = USABLE_WIDTH / 2;
        
        // Dibujar celdas de 2 columnas
        $pdf->Cell($ancho_columna, $alto_bloque1, '', 'LRB', 0, 'L'); 
        $pdf->Cell($ancho_columna, $alto_bloque1, '', 'RB', 1, 'L');

        // Columna 1: Registrado Por (Auxiliar)
        $pdf->DrawSignatureBlock(
            'Pedido Registrado Por:', 
            $auxiliar_data['nombre'], 
            $pedido_data['fecha_emision'], 
            $auxiliar_data['firma'],
            LEFT_MARGIN, 
            $y_start_bloque1 + 1, 
            $ancho_columna - 0.5, 
            $alto_bloque1 - 2
        );

        // Columna 2: Solicitado Por (Firma Externa)
        $pdf->DrawSignatureBlock(
            'Solicitado Por (Firma Externa):', 
            $pedido_data['solicitante_real_nombre'], 
            $pedido_data['fecha_emision'], 
            $pedido_data['firma_solicitante_path'],
            LEFT_MARGIN + $ancho_columna, 
            $y_start_bloque1 + 1, 
            $ancho_columna - 0.5, 
            $alto_bloque1 - 2
        );

    } else {
        // CASO 2: NO hay firma externa (Admin creó) -> DIBUJAR 1 COLUMNA
        $ancho_columna_unica = USABLE_WIDTH; // Ancho completo
        
        // Dibujar celda de 1 columna
        $pdf->Cell($ancho_columna_unica, $alto_bloque1, '', 'LRB', 1, 'L'); 

        // Columna Única: Registrado Por (Admin)
        // (Centramos la firma para que se vea mejor)
        $ancho_firma_unica = $ancho_columna_unica / 2; // Usar la mitad del ancho total
        $posicion_x_unica = LEFT_MARGIN + ($ancho_columna_unica - $ancho_firma_unica) / 2; // Centrar el bloque

        $pdf->DrawSignatureBlock(
            'Pedido Registrado Por:', 
            $auxiliar_data['nombre'], // (Este es el Admin)
            $pedido_data['fecha_emision'], 
            $auxiliar_data['firma'],
            $posicion_x_unica, // Posición X centrada
            $y_start_bloque1 + 1, 
            $ancho_firma_unica, // Ancho del bloque
            $alto_bloque1 - 2
        );
    }
    // *** FIN MODIFICACIÓN (v4) ***
    
    $pdf->SetY($y_start_bloque1 + $alto_bloque1);
    // *** FIN MODIFICACIÓN ***

    // ===========================================
    // === SECCIÓN FINAL (SOLO SI MODO FINAL) ===
    // ===========================================
    if ($modo === 'final' && $tarea_data) {
        // --- Documentos Adjuntos ---
        $pdf->Ln(2); $pdf->SectionTitle('Documentos Adjuntos');
        if (empty($adjuntos)) { $pdf->Cell(USABLE_WIDTH, 6, '----------', 1, 1, 'C'); }
        else { $tipo_actual_adj = ''; $pdf->SetFont('Arial', '', 8); foreach ($adjuntos as $adj) { $tipo_display = ucfirst($adj['tipo_adjunto'] == 'remito' ? 'Remito/Fact.' : $adj['tipo_adjunto']); if ($tipo_display != $tipo_actual_adj) { $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(USABLE_WIDTH, 4, utf8_decode("-- {$tipo_display} --"), 1, 1, 'L'); $pdf->SetFont('Arial', '', 8); $tipo_actual_adj = $tipo_display; } $pdf->Cell(USABLE_WIDTH, 4, ' - ' . utf8_decode($adj['nombre_archivo']), 'LR', 1, 'L'); } $pdf->Cell(USABLE_WIDTH, 0, '', 'T', 1); $pdf->SetFont('Arial', '', 9); }
        
        // --- Historial de Actualizaciones ---
        // (Esto se mostrará siempre, e incluirá el motivo de cancelación si existe)
        $pdf->Ln(2); $pdf->SectionTitle('Historial de Actualizaciones');
         if (empty($actualizaciones)) { $pdf->Cell(USABLE_WIDTH, 6, '----------', 1, 1, 'C'); }
         else { foreach ($actualizaciones as $upd) { $fecha_upd = date('d/m/y H:i', strtotime($upd['fecha_actualizacion'])); $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(USABLE_WIDTH, 4, utf8_decode($fecha_upd . ' - ' . ($upd['nombre_completo'] ?? '?') . ':'), 'LR', 1, 'L'); $pdf->SetFont('Arial', '', 8); $pdf->MultiCell(USABLE_WIDTH, 3.5, utf8_decode($upd['contenido']), 'LRB', 'L'); } $pdf->SetFont('Arial', '', 9); }

        
        // --- INICIO MODIFICACIÓN GEMINI (v9) ---
        // Solo mostrar "Trabajo Realizado" y "Firmas" si la tarea fue VERIFICADA.
        // Si fue CANCELADA, estas secciones se omiten.
        if ($tarea_data['estado'] === 'verificada') {
        // --- FIN MODIFICACIÓN GEMINI (v9) ---

            // --- Trabajo Realizado (Texto) ---
            $pdf->Ln(2); $pdf->SectionTitle('Trabajo Realizado');
            $pdf->SetFont('Arial', '', 9); $pdf->MultiCell(USABLE_WIDTH, 4, utf8_decode($tarea_data['nota_final'] ?: '(Sin reporte)'), 1, 'L');
            
            // --- INICIO MODIFICACIÓN GEMINI (v8): Control de Salto de Página (Corrección de $pdf->bMargin) ---
            // Se calcula la altura total del bloque de firmas + su título
            $alto_bloque_firmas_final = 25 + 2 + 5; // alto_bloque2 (25) + Ln(2) + SectionTitle(5)
            
            // Se calcula el espacio restante en la página actual antes del margen inferior (footer)
            // Se reemplaza $pdf->bMargin (que es protected y da ERROR 500) por el valor 20 (definido en SetAutoPageBreak)
            $espacio_disponible = $pdf->GetPageHeight() - $pdf->GetY() - 20; // 20 es el bMargin
            
            // Si el bloque de firmas NO CABE en el espacio restante...
            if ($alto_bloque_firmas_final > $espacio_disponible) {
                $pdf->AddPage(); // ...forzamos un salto de página AHORA.
            }
            // --- FIN MODIFICACIÓN GEMINI (v8) ---
            
            // --- SECCIÓN EJECUCIÓN Y CONTROL (2 COLUMNAS) ---
            // (Este código ahora se ejecutará en la página actual si cabe, o en una nueva página si no cabía)
            $pdf->Ln(2); $pdf->SectionTitle('Ejecución y Control del Trabajo');
            $y_start_bloque2 = $pdf->GetY(); $alto_bloque2 = 25;
            $ancho_columna = USABLE_WIDTH / 2;
            $pdf->Cell($ancho_columna, $alto_bloque2, '', 'LRB', 0, 'L'); $pdf->Cell($ancho_columna, $alto_bloque2, '', 'RB', 1, 'L');
            if ($tecnico_data) { $pdf->DrawSignatureBlock('Realizado Por (Técnico):', $tecnico_data['nombre'], $tarea_data['fecha_cierre'], $tecnico_data['firma'], LEFT_MARGIN, $y_start_bloque2 + 1, $ancho_columna - 0.5, $alto_bloque2 - 2); }
            if ($encargado_data) { $fecha_control = $tarea_data['fecha_cierre']; $pdf->DrawSignatureBlock('Controlado Por:', $encargado_data['nombre'], $fecha_control, $encargado_data['firma'], LEFT_MARGIN + $ancho_columna, $y_start_bloque2 + 1, $ancho_columna - 0.5, $alto_bloque2 - 2); }
             $pdf->SetY($y_start_bloque2 + $alto_bloque2);

        // --- INICIO MODIFICACIÓN GEMINI (v9) ---
        } // <-- Cierre del if ($tarea_data['estado'] === 'verificada')
        // --- FIN MODIFICACIÓN GEMINI (v9) ---

    } // <-- FIN DEL IF ($modo === 'final' && $tarea_data)

} // <-- FIN DEL IF ($pedido_data && !$database_error)


// --- 6. Guardar el PDF en el servidor ANTES de enviarlo ---
$pdf_guardado_ok = false;
if ($pedido_data && !$database_error && $pdf_dir_ok && !empty($pdf_save_path)) {
    try {
        $pdf->Output('F', $pdf_save_path); // 'F' para guardar
        if (file_exists($pdf_save_path)) { $pdf_guardado_ok = true; error_log("PDF guardado: " . $pdf_save_path); }
        else { error_log("Fallo Output('F') sin excepción? Path: " . $pdf_save_path); }
    } catch (Exception $e) { error_log("Error al guardar PDF con Output('F'): " . $e->getMessage()); }
} else { error_log("No se intentó guardar PDF público (faltan datos, error previo o dir inaccesible)."); }


// --- 7. Enviar el PDF al navegador ---
if (isset($es_llamada_interna) && $es_llamada_interna) {
    if ($qr_code_file_path && file_exists($qr_code_file_path)) { @unlink($qr_code_file_path); }
    return; // Volvemos a firma_externa
}

// Si llegamos acá es visualización directa
if (ob_get_length()) { ob_end_clean(); } 
header("Location: " . $urlParaPDFPublico);
exit;
?>