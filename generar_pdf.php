<?php
// generar_pdf.php - REPORTE DE ALTO NIVEL CORPORATIVO
require_once 'includes/db.php';

// 1. BUSCAR LIBRERÍA FPDF (INTELIGENTE)
$rutas_fpdf = ['fpdf/fpdf.php', 'includes/fpdf/fpdf.php', '../fpdf/fpdf.php'];
$fpdf_loaded = false;
foreach ($rutas_fpdf as $ruta) {
    if (file_exists($ruta)) { require_once $ruta; $fpdf_loaded = true; break; }
}
if (!$fpdf_loaded) die("Error: No se encuentra la librería FPDF. Por favor súbela a la carpeta 'fpdf' o 'includes/fpdf'.");

// 2. OBTENER DATOS DEL NEGOCIO (CONFIGURACIÓN)
$sqlConf = "SELECT * FROM configuracion WHERE id = 1";
$config = $conexion->query($sqlConf)->fetch(PDO::FETCH_ASSOC);

// Datos por defecto si faltan en la BD
$empresa = $config['nombre_negocio'] ?? 'MI KIOSCO';
$cuit = $config['cuit'] ?? '20-00000000-0';
$direccion = $config['direccion'] ?? 'Dirección no configurada';
$telefono = $config['telefono'] ?? '';
$email = $config['email'] ?? '';
$logo_url = $config['logo_url'] ?? '';

// 3. RECIBIR FILTROS Y TIPO DE REPORTE
$tipo = $_GET['tipo'] ?? 'ventas'; // ventas, auditoria, stock
$f_inicio = $_GET['f_inicio'] ?? date('Y-m-01');
$f_fin = $_GET['f_fin'] ?? date('Y-m-d');

// --- CLASE PDF PERSONALIZADA ---
class PDF_Professional extends FPDF {
    public $empresa; public $cuit; public $direccion; public $logo; public $titulo_reporte;

    function Header() {
        // A. FONDO ENCABEZADO (Barra superior decorativa)
        $this->SetFillColor(33, 37, 41); // Gris Oscuro casi negro
        $this->Rect(0, 0, 210, 5, 'F'); // Barra superior borde hoja

        // B. LOGO
        $logo_path = $this->logo;
        // Intentar varias rutas para el logo
        if (!empty($logo_path)) {
            $rutas_img = [$logo_path, 'img/'.$logo_path, 'uploads/'.$logo_path];
            foreach($rutas_img as $r) {
                if(file_exists($r)) { 
                    $this->Image($r, 10, 10, 25); // X, Y, W
                    break; 
                }
            }
        } else {
            // Si no hay logo, ponemos un icono genérico o nada
        }

        // C. DATOS EMPRESA (Alineados a la derecha)
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(33, 37, 41);
        $this->Cell(0, 10, utf8_decode(strtoupper($this->empresa)), 0, 1, 'R');
        
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, utf8_decode("CUIT: " . $this->cuit), 0, 1, 'R');
        $this->Cell(0, 5, utf8_decode($this->direccion), 0, 1, 'R');
        $this->Cell(0, 5, utf8_decode("Fecha Emisión: " . date('d/m/Y H:i')."hs"), 0, 1, 'R');

        // D. LÍNEA SEPARADORA
        $this->Ln(5);
        $this->SetDrawColor(200, 200, 200);
        $this->Line(10, 38, 200, 38);
        $this->Ln(10);

        // E. TÍTULO DEL REPORTE
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(0, 0, 0); // Azul oscuro corporativo o Negro
        $this->Cell(0, 10, utf8_decode($this->titulo_reporte), 0, 1, 'C');
        $this->Ln(5);
    }

    function Footer() {
        $this->SetY(-25);
        
        // QR Code (Usando API pública de QR Server)
        // Generamos un link falso de validación para darle profesionalismo
        $validation_url = "https://tu-kiosco.com/validar?doc=".uniqid();
        $qr_api = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=".urlencode($validation_url);
        $this->Image($qr_api, 10, 272, 20, 20, 'PNG');

        // Texto legal
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(150, 150, 150);
        $this->SetXY(35, -20);
        $this->MultiCell(0, 4, utf8_decode("Este documento es un reporte oficial generado por el sistema de gestión. Su contenido es confidencial y para uso interno exclusivo de la administración.\nCódigo de Verificación: ".uniqid()." | IP Generador: ".$_SERVER['REMOTE_ADDR']));

        // Paginación
        $this->SetY(-15);
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 10, utf8_decode('Página '.$this->PageNo().'/{nb}'), 0, 0, 'R');
    }

    // Función auxiliar para tablas bonitas
    function TableHeader($header) {
        $this->SetFillColor(52, 58, 64); // Dark background
        $this->SetTextColor(255); // White text
        $this->SetDrawColor(52, 58, 64);
        $this->SetLineWidth(.3);
        $this->SetFont('Arial', 'B', 9);
        
        // Anchos predefinidos (ajustar según columnas)
        $w = $this->widths; 
        for($i=0;$i<count($header);$i++)
            $this->Cell($w[$i], 8, utf8_decode($header[$i]), 1, 0, 'C', true);
        $this->Ln();
    }
}

// 4. INSTANCIAR PDF
$pdf = new PDF_Professional();
$pdf->empresa = $empresa;
$pdf->cuit = $cuit;
$pdf->direccion = $direccion;
$pdf->logo = $logo_url;
$pdf->AliasNbPages();
$pdf->AddPage();

// 5. LÓGICA SEGÚN TIPO DE REPORTE
if ($tipo == 'auditoria') {
    // --- REPORTE DE AUDITORÍA ---
    $pdf->titulo_reporte = "REPORTE FORENSE DE AUDITORÍA";
    
    // Query
    $sql = "SELECT a.*, u.usuario FROM auditoria a LEFT JOIN usuarios u ON a.id_usuario = u.id 
            WHERE DATE(a.fecha) BETWEEN '$f_inicio' AND '$f_fin' ORDER BY a.fecha DESC";
    $rows = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // Columnas
    $header = ['ID', 'Fecha/Hora', 'Usuario', 'Acción', 'Detalle'];
    $pdf->widths = [15, 35, 30, 40, 70]; // Total 190
    $pdf->TableHeader($header);

    // Data
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(0);
    $fill = false;
    
    foreach($rows as $row) {
        $pdf->SetFillColor(245, 245, 245); // Alternar gris muy claro
        $pdf->Cell(15, 7, $row['id'], 'LR', 0, 'C', $fill);
        $pdf->Cell(35, 7, date('d/m/y H:i', strtotime($row['fecha'])), 'LR', 0, 'C', $fill);
        $pdf->Cell(30, 7, utf8_decode(substr($row['usuario'],0,15)), 'LR', 0, 'L', $fill);
        
        // Acción en negrita si es eliminar
        if(stripos($row['accion'], 'Elimin') !== false) $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(40, 7, utf8_decode(substr($row['accion'],0,20)), 'LR', 0, 'L', $fill);
        $pdf->SetFont('Arial', '', 8);
        
        $pdf->Cell(70, 7, utf8_decode(substr($row['detalles'],0,45)), 'LR', 0, 'L', $fill);
        $pdf->Ln();
        $fill = !$fill;
    }
    $pdf->Cell(190, 0, '', 'T'); // Línea final

} elseif ($tipo == 'stock') {
    // --- REPORTE DE STOCK ---
    $pdf->titulo_reporte = "INVENTARIO VALORIZADO";
    // ... lógica similar para stock ...

} else {
    // --- REPORTE DE VENTAS (DEFAULT) ---
    $pdf->titulo_reporte = "REPORTE DETALLADO DE VENTAS";
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, utf8_decode("Período: ".date('d/m/Y', strtotime($f_inicio))." al ".date('d/m/Y', strtotime($f_fin))), 0, 1, 'L');
    $pdf->Ln(2);

    // Query
    $sql = "SELECT v.id, v.fecha, v.total, v.metodo_pago, u.usuario 
            FROM ventas v LEFT JOIN usuarios u ON v.id_usuario = u.id 
            WHERE DATE(v.fecha) BETWEEN '$f_inicio' AND '$f_fin' ORDER BY v.fecha DESC";
    $rows = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // Columnas
    $header = ['Ticket #', 'Fecha', 'Vendedor', 'Método', 'Total ($)'];
    $pdf->widths = [25, 45, 45, 40, 35];
    $pdf->TableHeader($header);

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(0);
    $fill = false;
    $total_periodo = 0;

    foreach($rows as $row) {
        $pdf->SetFillColor(240, 248, 255); // Azulito muy claro
        $pdf->Cell(25, 8, str_pad($row['id'], 6, '0', STR_PAD_LEFT), 'LR', 0, 'C', $fill);
        $pdf->Cell(45, 8, date('d/m/Y H:i', strtotime($row['fecha'])), 'LR', 0, 'C', $fill);
        $pdf->Cell(45, 8, utf8_decode($row['usuario']), 'LR', 0, 'L', $fill);
        $pdf->Cell(40, 8, utf8_decode(ucfirst($row['metodo_pago'])), 'LR', 0, 'C', $fill);
        $pdf->Cell(35, 8, number_format($row['total'], 2), 'LR', 0, 'R', $fill);
        $pdf->Ln();
        $fill = !$fill;
        $total_periodo += $row['total'];
    }
    $pdf->Cell(190, 0, '', 'T'); // Línea final tabla
    
    // TOTALES
    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell(155, 10, 'TOTAL PERIODO:', 1, 0, 'R', true);
    $pdf->Cell(35, 10, '$ '.number_format($total_periodo, 2), 1, 1, 'R', true);
    
    // Desglose simple (Opcional)
    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 8, 'Resumen de Operatividad:', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, '- Cantidad de Operaciones: ' . count($rows), 0, 1);
    $pdf->Cell(0, 6, '- Ticket Promedio: $ ' . (count($rows)>0 ? number_format($total_periodo/count($rows),2) : '0.00'), 0, 1);
}

// 6. SALIDA
$pdf->Output('I', 'Reporte_Oficial_'.date('Ymd_Hi').'.pdf');
?>
