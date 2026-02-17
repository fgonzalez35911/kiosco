<?php
// catalogo_pdf.php - GENERADOR DE CATÁLOGO DESCARGABLE
require('fpdf/fpdf.php');
require_once 'includes/db.php';

// BUSCAR OFERTAS
$sql = "SELECT * FROM productos WHERE activo = 1 AND es_destacado_web = 1 ORDER BY descripcion ASC";
$productos = $conexion->query($sql)->fetchAll();
$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);

class PDF extends FPDF {
    function Header() {
        global $conf;
        $this->SetFont('Arial','B',16);
        $this->Cell(0,10,utf8_decode('REVISTA DE OFERTAS - ' . strtoupper($conf['nombre_negocio'])),0,1,'C');
        $this->SetFont('Arial','',10);
        $this->Cell(0,5,utf8_decode('Válido hasta agotar stock'),0,1,'C');
        $this->Ln(10);
    }
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,10,utf8_decode('Página ').$this->PageNo(),0,0,'C');
    }
}

$pdf = new PDF();
$pdf->AddPage();
$pdf->SetFont('Arial','',12);

if(count($productos) > 0) {
    // Configuración de grilla
    $x_inicial = 10;
    $y_inicial = 40;
    $ancho = 90;
    $alto = 60;
    $col = 0;

    foreach($productos as $p) {
        // Control de salto de página
        if($pdf->GetY() > 240) {
            $pdf->AddPage();
            $y_inicial = 40; // Reiniciar Y
            $col = 0;
            $pdf->SetY($y_inicial);
        }

        $x = $x_inicial + ($col * 95);
        $y = $pdf->GetY();

        // Cuadro producto
        $pdf->Rect($x, $y, $ancho, $alto);
        
        // Texto
        $pdf->SetXY($x+2, $y+5);
        $pdf->SetFont('Arial','B',11);
        $pdf->MultiCell(85, 5, utf8_decode(substr($p->descripcion, 0, 40)), 0, 'C');
        
        $pdf->SetXY($x+2, $y+25);
        $pdf->SetFont('Arial','B',20);
        $pdf->SetTextColor(220, 53, 69); // Rojo Precio
        $pdf->Cell(86, 10, '$ ' . number_format($p->precio_venta, 0, ',', '.'), 0, 1, 'C');
        
        $pdf->SetXY($x+2, $y+40);
        $pdf->SetFont('Arial','',9);
        $pdf->SetTextColor(0);
        $pdf->Cell(86, 5, 'COD: ' . $p->codigo_barras, 0, 1, 'C');

        // Mover cursor lógica
        $col++;
        if($col >= 2) {
            $col = 0;
            $pdf->SetY($y + $alto + 10); // Bajar renglón
        } else {
            $pdf->SetY($y); // Mantener Y para la siguiente columna
        }
    }
} else {
    $pdf->Cell(0,10,'No hay productos destacados para mostrar.',0,1,'C');
}

$pdf->Output('I', 'Catalogo_Ofertas.pdf');
?>