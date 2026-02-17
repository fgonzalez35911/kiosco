<?php
// exportar_revista.php - VERSIÓN BLINDADA (NO CRASHEA CON IMAGENES ROTAS)
require('fpdf/fpdf.php');
require_once 'includes/db.php';

// PREVENIR SALIDA DE TEXTO ANTES DEL PDF (Causa común de errores)
ob_clean(); 

// 1. DATOS
$titulo = isset($_POST['titulo']) ? utf8_decode(strtoupper($_POST['titulo'])) : 'OFERTAS';
$vigencia = isset($_POST['vigencia']) ? utf8_decode($_POST['vigencia']) : '';
$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);

$sql = "SELECT * FROM productos WHERE activo = 1 AND es_destacado_web = 1 ORDER BY descripcion ASC";
$productos = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);

class PDF extends FPDF {
    var $titulo_pdf;
    var $vigencia_pdf;
    var $nombre_negocio;

    function Header() {
        // FONDO AMARILLO YAGUAR
        $this->SetFillColor(255, 193, 7); 
        $this->Rect(0, 0, 210, 35, 'F');
        // LINEA ROJA
        $this->SetFillColor(220, 53, 69);
        $this->Rect(0, 35, 210, 2, 'F');

        // TITULO GRANDE
        $this->SetFont('Arial', 'B', 28);
        $this->SetTextColor(220, 53, 69);
        $this->SetXY(0, 8);
        $this->Cell(210, 12, $this->titulo_pdf, 0, 1, 'C');

        // SUBTITULO
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(210, 6, $this->vigencia_pdf . ' | ' . $this->nombre_negocio, 0, 1, 'C');
        
        $this->Ln(10);
    }

    function Footer() {
        $this->SetY(-10);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(150);
        $this->Cell(0, 10, 'Pagina '.$this->PageNo(), 0, 0, 'C');
    }

    // CAJA DE PRODUCTO
    function ProductoBox($x, $y, $w, $h, $p) {
        $this->SetDrawColor(200);
        $this->Rect($x, $y, $w, $h);

        // BADGE OFERTA
        $this->SetFillColor(220, 53, 69);
        $this->SetTextColor(255);
        $this->SetFont('Arial', 'B', 7);
        $this->Rect($x + $w - 15, $y, 15, 5, 'F');
        $this->SetXY($x + $w - 15, $y);
        $this->Cell(15, 5, 'OFERTA', 0, 0, 'C');

        // IMAGEN SEGURA
        $img_h = 30;
        $ruta = $this->resolverImagen($p['imagen_url']);
        
        if($ruta) {
            // Intentar cargar imagen dentro de un TRY/CATCH visual (FPDF no tiene try/catch nativo para Image, validamos antes)
            try {
                // Centrar imagen
                $this->Image($ruta, $x + ($w/2) - 15, $y + 8, 30, 30);
            } catch (Exception $e) {
                $this->placeholder($x, $y, $w, "Err. IMG");
            }
        } else {
            $this->placeholder($x, $y, $w, "Sin Foto");
        }

        // DESCRIPCION
        $this->SetXY($x + 2, $y + 42);
        $this->SetTextColor(0);
        $this->SetFont('Arial', 'B', 9);
        $this->MultiCell($w - 4, 3.5, utf8_decode(substr($p['descripcion'],0,45)), 0, 'C');

        // PRECIOS
        $precio_antes = $p['precio_venta'] * 1.2;
        
        $this->SetXY($x, $y + 58);
        $this->SetTextColor(100);
        $this->SetFont('Arial', '', 8);
        $txt = 'Antes: $'.number_format($precio_antes,0,',','.');
        $this->Cell($w, 4, $txt, 0, 0, 'C');
        
        // TACHADO
        $ancho_txt = $this->GetStringWidth($txt);
        $inicio_x = $x + ($w - $ancho_txt)/2;
        $this->Line($inicio_x, $y + 60, $inicio_x + $ancho_txt, $y + 60);

        // PRECIO FINAL
        $this->SetXY($x, $y + 63);
        $this->SetTextColor(220, 53, 69);
        $this->SetFont('Arial', 'B', 20); // FUENTE GRANDE
        $this->Cell($w, 8, '$'.number_format($p['precio_venta'],0,',','.'), 0, 0, 'C');

        // CODIGO
        $this->SetXY($x, $y + 74);
        $this->SetTextColor(150);
        $this->SetFont('Arial', '', 6);
        $this->Cell($w, 3, 'COD: '.$p['codigo_barras'], 0, 0, 'C');
    }

    function placeholder($x, $y, $w, $txt) {
        $this->SetFillColor(240);
        $this->Rect($x + 15, $y + 10, $w - 30, 25, 'F');
        $this->SetXY($x, $y + 20);
        $this->SetTextColor(150);
        $this->SetFont('Arial', '', 8);
        $this->Cell($w, 5, $txt, 0, 0, 'C');
    }

    function resolverImagen($url) {
        if(empty($url)) return false;
        // 1. Ruta absoluta directa
        if(file_exists($url)) return $url;
        // 2. Solo nombre de archivo en uploads/
        $nombre = basename($url);
        if(file_exists('uploads/'.$nombre)) return 'uploads/'.$nombre;
        if(file_exists('img/'.$nombre)) return 'img/'.$nombre;
        // 3. Fallback (si existe)
        if(file_exists('img/no-image.png')) return 'img/no-image.png';
        return false;
    }
}

$pdf = new PDF();
$pdf->titulo_pdf = $titulo;
$pdf->vigencia_pdf = $vigencia;
$pdf->nombre_negocio = utf8_decode($conf['nombre_negocio']);
$pdf->AliasNbPages();
$pdf->AddPage();

// GRILLA 3x3
$margen_x = 10;
$margen_y = 45;
$ancho = 60;
$alto = 80; // Más altura para que entre todo cómodo
$gap = 5;

$col = 0; $fila = 0;

if(count($productos) > 0) {
    foreach($productos as $p) {
        $x = $margen_x + ($col * ($ancho + $gap));
        $y = $margen_y + ($fila * ($alto + $gap));

        $pdf->ProductoBox($x, $y, $ancho, $alto, $p);

        $col++;
        if($col >= 3) {
            $col = 0; $fila++;
        }
        if($fila >= 3) { // 3 filas = 9 productos por hoja
            $pdf->AddPage();
            $col = 0; $fila = 0;
        }
    }
} else {
    $pdf->SetFont('Arial','',14);
    $pdf->Cell(0,20,'No hay productos seleccionados.',0,1,'C');
}

$pdf->Output('I', 'Revista_Ofertas.pdf');
?>