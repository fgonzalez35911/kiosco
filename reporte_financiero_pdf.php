<?php
// reporte_financiero_pdf.php - TU VERSIÓN (FIXED)
require_once 'includes/db.php';

// 1. CARGAR FPDF
$rutas_fpdf = ['fpdf/fpdf.php', 'includes/fpdf/fpdf.php', '../fpdf/fpdf.php'];
$fpdf_loaded = false;
foreach ($rutas_fpdf as $ruta) {
    if (file_exists($ruta)) { require_once $ruta; $fpdf_loaded = true; break; }
}
if (!$fpdf_loaded) die("Error: No se encuentra FPDF.");

// 2. DATOS CONFIGURACIÓN
$sqlConf = "SELECT * FROM configuracion WHERE id = 1";
$config = $conexion->query($sqlConf)->fetch(PDO::FETCH_ASSOC);
$empresa = $config['nombre_negocio'] ?? 'MI NEGOCIO';
$cuit = $config['cuit'] ?? '';
$logo_url = $config['logo_url'] ?? '';

// Filtros
$f_inicio = $_GET['f_inicio'] ?? date('Y-m-01');
$f_fin = $_GET['f_fin'] ?? date('Y-m-d');

// --- CÁLCULOS DE DATOS ---

// A. VENTAS
$sqlVentas = "SELECT 
                COUNT(*) as cant_tickets,
                SUM(v.total) as venta_total,
                SUM((SELECT SUM(d.cantidad * COALESCE(NULLIF(d.costo_historico,0), p.precio_costo)) 
                     FROM detalle_ventas d JOIN productos p ON d.id_producto = p.id 
                     WHERE d.id_venta = v.id)) as costo_total
              FROM ventas v WHERE v.fecha BETWEEN '$f_inicio 00:00:00' AND '$f_fin 23:59:59' AND v.estado = 'completada'";
$resVentas = $conexion->query($sqlVentas)->fetch(PDO::FETCH_ASSOC);
$ingresos = $resVentas['venta_total'] ?? 0;
$costos_mercaderia = $resVentas['costo_total'] ?? 0;
$cant_tickets = $resVentas['cant_tickets'] ?? 0;
$ticket_promedio = ($cant_tickets > 0) ? $ingresos / $cant_tickets : 0;

// B. MÉTODOS DE PAGO
$sqlMetodos = "SELECT metodo_pago, SUM(total) as total FROM ventas 
               WHERE fecha BETWEEN '$f_inicio 00:00:00' AND '$f_fin 23:59:59' AND estado = 'completada'
               GROUP BY metodo_pago ORDER BY total DESC";
$lista_metodos = $conexion->query($sqlMetodos)->fetchAll(PDO::FETCH_ASSOC);
$data_metodos_chart = []; // Para el gráfico
foreach($lista_metodos as $m) $data_metodos_chart[$m['metodo_pago']] = $m['total'];

// C. GASTOS
$sqlGastos = "SELECT categoria, SUM(monto) as total FROM gastos 
              WHERE fecha BETWEEN '$f_inicio 00:00:00' AND '$f_fin 23:59:59' 
              GROUP BY categoria ORDER BY total DESC";
$lista_gastos = $conexion->query($sqlGastos)->fetchAll(PDO::FETCH_ASSOC);
$gastos_operativos = 0;
$retiros_dueno = 0;
$data_gastos_chart = []; // Para el gráfico
foreach($lista_gastos as $g) {
    if($g['categoria'] == 'Retiro') $retiros_dueno += $g['total'];
    else {
        $gastos_operativos += $g['total'];
        $data_gastos_chart[$g['categoria']] = $g['total'];
    }
}

// 1. Días
$sqlDias = "SELECT DAYNAME(fecha) as dia, SUM(total) as total FROM ventas WHERE fecha BETWEEN '$f_inicio 00:00:00' AND '$f_fin 23:59:59' AND estado='completada' GROUP BY DAYOFWEEK(fecha)";
$data_dias = $conexion->query($sqlDias)->fetchAll(PDO::FETCH_KEY_PAIR);

// 2. Horas
$sqlHoras = "SELECT HOUR(fecha) as hora, COUNT(*) as c FROM ventas WHERE fecha BETWEEN '$f_inicio 00:00:00' AND '$f_fin 23:59:59' AND estado='completada' GROUP BY hora";
$data_horas = $conexion->query($sqlHoras)->fetchAll(PDO::FETCH_KEY_PAIR);

// 3. Clientes (CORREGIDO: Rango de horas y estado completada)
$sqlCli = "SELECT c.nombre, SUM(v.total) as t FROM ventas v JOIN clientes c ON v.id_cliente=c.id WHERE v.fecha BETWEEN '$f_inicio 00:00:00' AND '$f_fin 23:59:59' AND c.id > 1 AND v.estado='completada' GROUP BY c.id ORDER BY t DESC LIMIT 5";
$data_clientes = $conexion->query($sqlCli)->fetchAll(PDO::FETCH_KEY_PAIR);

// 4. Vendedores
$sqlVend = "SELECT u.usuario, SUM(v.total) as t FROM ventas v JOIN usuarios u ON v.id_usuario=u.id WHERE v.fecha BETWEEN '$f_inicio 00:00:00' AND '$f_fin 23:59:59' AND v.estado='completada' GROUP BY u.id ORDER BY t DESC";
$data_vend = $conexion->query($sqlVend)->fetchAll(PDO::FETCH_KEY_PAIR);

// RESULTADOS
$utilidad_bruta = $ingresos - $costos_mercaderia;
$utilidad_neta = $utilidad_bruta - $gastos_operativos;
$flujo_caja = $utilidad_neta - $retiros_dueno;
$margen_porc = ($ingresos > 0) ? ($utilidad_neta / $ingresos) * 100 : 0;

// --- TU CLASE PDF PREMIUM (CON GRÁFICOS AGREGADOS) ---
class PDF_Premium extends FPDF {
    public $empresa; public $cuit; public $rango; public $logo;

    function RoundedRect($x, $y, $w, $h, $r, $style = '', $angle = '1234') {
        $k = $this->k; $hp = $this->h;
        if($style=='F') $op='f'; elseif($style=='FD' || $style=='DF') $op='B'; else $op='S';
        $MyArc = 4/3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m',($x+$r)*$k,($hp-$y)*$k ));
        $xc = $x+$w-$r; $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l', $xc*$k,($hp-$y)*$k ));
        if (strpos($angle, '2')!==false) $this->_Arc($xc + $r*$MyArc, $yc - $r, $xc + $r, $yc - $r*$MyArc, $xc + $r, $yc);
        else $this->_out(sprintf('%.2F %.2F l', ($x+$w)*$k,($hp-$y)*$k ));
        $xc = $x+$w-$r; $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',($x+$w)*$k,($hp-$yc)*$k));
        if (strpos($angle, '3')!==false) $this->_Arc($xc + $r, $yc + $r*$MyArc, $xc + $r*$MyArc, $yc + $r, $xc, $yc + $r);
        else $this->_out(sprintf('%.2F %.2F l',($x+$w)*$k,($hp-($y+$h))*$k));
        $xc = $x+$r; $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',$xc*$k,($hp-($y+$h))*$k));
        if (strpos($angle, '4')!==false) $this->_Arc($xc - $r*$MyArc, $yc + $r, $xc - $r, $yc + $r*$MyArc, $xc - $r, $yc);
        else $this->_out(sprintf('%.2F %.2F l',($x)*$k,($hp-($y+$h))*$k));
        $xc = $x+$r; $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l',($x)*$k,($hp-$yc)*$k));
        if (strpos($angle, '1')!==false) $this->_Arc($xc - $r, $yc - $r*$MyArc, $xc - $r*$MyArc, $yc - $r, $xc, $yc - $r);
        else $this->_out(sprintf('%.2F %.2F l',($x)*$k,($hp-$y)*$k));
        $this->_out($op);
    }
    function _Arc($x1, $y1, $x2, $y2, $x3, $y3){
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x1*$this->k, ($h-$y1)*$this->k, $x2*$this->k, ($h-$y2)*$this->k, $x3*$this->k, ($h-$y3)*$this->k));
    }
    function Header() {
        $this->SetFillColor(245, 247, 250); 
        $this->Rect(0, 0, 210, 40, 'F');
        if(!empty($this->logo) && file_exists($this->logo)) {
            $this->Image($this->logo, 10, 8, 0, 24);
        } else {
            $this->SetFillColor(50); $this->RoundedRect(10, 8, 24, 24, 5, 'F');
            $this->SetTextColor(255); $this->SetFont('Arial','B',16); $this->Text(15, 22, 'MK');
        }
        $this->SetXY(40, 10); $this->SetFont('Arial', 'B', 18); $this->SetTextColor(30);
        $this->Cell(0, 8, utf8_decode(strtoupper($this->empresa)), 0, 1, 'L');
        $this->SetXY(40, 18); $this->SetFont('Arial', '', 10); $this->SetTextColor(100);
        $this->Cell(0, 5, utf8_decode("CUIT: " . $this->cuit), 0, 1, 'L');
        $this->SetXY(40, 23); $this->Cell(0, 5, utf8_decode("Reporte Ejecutivo Generado el: " . date('d/m/Y H:i')), 0, 1, 'L');
        $this->SetXY(140, 10); $this->SetFont('Arial', 'B', 10); $this->SetFillColor(255); $this->SetDrawColor(200);
        $this->RoundedRect(140, 10, 60, 20, 3, 'DF');
        $this->SetXY(140, 13); $this->Cell(60, 5, "PERIODO ANALIZADO", 0, 1, 'C');
        $this->SetFont('Arial', '', 9); $this->SetXY(140, 20);
        $this->Cell(60, 5, utf8_decode($this->rango), 0, 1, 'C');
        $this->Ln(15);
    }
    function Card($x, $y, $w, $h, $title, $value, $subtitle, $color_type='primary') {
        if($color_type=='success') $fill = [39, 174, 96]; elseif($color_type=='danger') $fill = [192, 57, 43]; elseif($color_type=='dark') $fill = [44, 62, 80]; else $fill = [41, 128, 185];
        $this->SetFillColor(220); $this->RoundedRect($x+1, $y+1, $w, $h, 4, 'F');
        $this->SetFillColor(255); $this->RoundedRect($x, $y, $w, $h, 4, 'F');
        $this->SetFillColor($fill[0], $fill[1], $fill[2]); $this->RoundedRect($x, $y, 3, $h, 2, 'F');
        $this->SetXY($x+5, $y+3); $this->SetFont('Arial', 'B', 8); $this->SetTextColor(150); $this->Cell($w-5, 5, utf8_decode(strtoupper($title)), 0, 1);
        $this->SetXY($x+5, $y+9); $this->SetFont('Arial', 'B', 14); $this->SetTextColor(50); $this->Cell($w-5, 8, utf8_decode($value), 0, 1);
        $this->SetXY($x+5, $y+18); $this->SetFont('Arial', '', 7); $this->SetTextColor(100); $this->Cell($w-5, 4, utf8_decode($subtitle), 0, 1);
    }
    // Funciones para gráficos
    // NUEVA TECNOLOGÍA: Gráficos renderizados por API (Chart.js)
    function PieChart($x, $y, $w, $h, $data, $titulo) {
        $this->SetXY($x, $y);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(33);
        $this->Cell($w, 8, utf8_decode($titulo), 0, 1, 'C');

        if(array_sum($data) <= 0) return;

        $labels = []; $values = [];
        foreach($data as $lbl => $val) {
            if($val <= 0) continue;
            $labels[] = "'" . str_replace(["'", "\""], "", $lbl) . "'";
            $values[] = $val;
        }

        $chart = "{
            type: 'pie',
            data: {
                labels: [" . implode(",", $labels) . "],
                datasets: [{
                    data: [" . implode(",", $values) . "],
                    backgroundColor: ['#3498db','#e74c3c','#f1c40f','#2ecc71','#9b59b6','#34495e'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: { 
                plugins: { 
                    legend: { position: 'right' },
                    datalabels: { display: false } 
                } 
            }
        }";

        $url = "https://quickchart.io/chart?c=" . urlencode(preg_replace('/\r|\n/', '', $chart)) . "&w=400&h=200&format=png";
        $this->Image($url, $x, $y + 10, $w, $h - 10, 'PNG');
    }
    function BarChart($x, $y, $w, $h, $data, $titulo) {
        $this->SetXY($x, $y);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(33);
        $this->Cell($w, 8, utf8_decode($titulo), 0, 1, 'C');

        if(count($data) == 0) return;

        $labels = []; $values = [];
        foreach($data as $lbl => $val) {
            $labels[] = "'" . substr(str_replace(["'", "\""], "", $lbl), 0, 12) . "'";
            $values[] = $val;
        }

        $chart = "{
            type: 'bar',
            data: {
                labels: [" . implode(",", $labels) . "],
                datasets: [{
                    label: 'Ventas',
                    data: [" . implode(",", $values) . "],
                    backgroundColor: '#3498db',
                    borderRadius: 4
                }]
            },
            options: {
                layout: { padding: { top: 25 } },
                plugins: {
                    legend: { display: false },
                    datalabels: { 
                        color: '#000000', 
                        align: 'end', 
                        anchor: 'end', 
                        font: { weight: 'bold' },
                        formatter: function(value) { return '$' + value; }
                    }
                },
                scales: { yAxes: [{ ticks: { beginAtZero: true } }] }
            }
        }";

        $url = "https://quickchart.io/chart?c=" . urlencode(preg_replace('/\r|\n/', '', $chart)) . "&w=400&h=200&format=png";
        $this->Image($url, $x, $y + 10, $w, $h - 10, 'PNG');
    }
}

$pdf = new PDF_Premium();
$pdf->empresa = $empresa;
$pdf->cuit = $cuit;
$pdf->logo = $logo_url;
$pdf->rango = date('d/m/Y', strtotime($f_inicio))." - ".date('d/m/Y', strtotime($f_fin));
$pdf->AddPage();

// --- 1. DASHBOARD DE TARJETAS ---
$pdf->Ln(5);
$y_cards = 45;
$pdf->Card(10, $y_cards, 45, 25, 'Ingresos Totales', '$ '.number_format($ingresos,0,',','.'), $cant_tickets.' Operaciones', 'primary');
$pdf->Card(60, $y_cards, 45, 25, 'Costo Mercaderia', '$ '.number_format($costos_mercaderia,0,',','.'), 'Margen Bruto: $'.number_format($utilidad_bruta,0), 'danger');
$pdf->Card(110, $y_cards, 45, 25, 'Gastos Operativos', '$ '.number_format($gastos_operativos,0,',','.'), 'Luz, Alquiler, etc.', 'danger');
$pdf->Card(160, $y_cards, 45, 25, 'Ganancia Neta', '$ '.number_format($utilidad_neta,0,',','.'), 'Rentabilidad: '.number_format($margen_porc,1).'%', 'success');

// --- 2. DETALLE ESTRUCTURAL ---
$pdf->SetY(80);
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(33);
$pdf->Cell(0, 10, utf8_decode("1. ESTADO DE RESULTADOS"), 0, 1);
$pdf->SetLineWidth(0.5);
$pdf->Line(10, 89, 200, 89);
$pdf->Ln(2);

$pdf->SetFillColor(240, 240, 240);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(120, 8, utf8_decode("CONCEPTO"), 0, 0, 'L', true);
$pdf->Cell(35, 8, "IMPORTE", 0, 0, 'R', true);
$pdf->Cell(35, 8, "% VENTA", 0, 1, 'R', true);

$pdf->SetFont('Arial', '', 9);
$pdf->Cell(120, 8, utf8_decode(" (+) Ingresos por Ventas"), 'B');
$pdf->Cell(35, 8, number_format($ingresos, 2), 'B', 0, 'R');
$pdf->Cell(35, 8, "100.0 %", 'B', 1, 'R');

$pdf->SetTextColor(192, 57, 43);
$pdf->Cell(120, 8, utf8_decode(" (-) Costo de Mercadería Vendida (CMV)"), 'B');
$pdf->Cell(35, 8, number_format($costos_mercaderia, 2), 'B', 0, 'R');
$porc_cmv = ($ingresos > 0) ? ($costos_mercaderia/$ingresos)*100 : 0;
$pdf->Cell(35, 8, number_format($porc_cmv, 1)." %", 'B', 1, 'R');

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(33);
$pdf->SetFillColor(250, 250, 250);
$pdf->Cell(120, 8, utf8_decode(" (=) UTILIDAD BRUTA"), 'B', 0, 'L', true);
$pdf->Cell(35, 8, number_format($utilidad_bruta, 2), 'B', 0, 'R', true);
$porc_bruta = ($ingresos > 0) ? ($utilidad_bruta/$ingresos)*100 : 0;
$pdf->Cell(35, 8, number_format($porc_bruta, 1)." %", 'B', 1, 'R', true);

$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(192, 57, 43);
$pdf->Cell(120, 8, utf8_decode(" (-) Gastos Operativos (Fijos/Variables)"), 'B');
$pdf->Cell(35, 8, number_format($gastos_operativos, 2), 'B', 0, 'R');
$porc_gastos = ($ingresos > 0) ? ($gastos_operativos/$ingresos)*100 : 0;
$pdf->Cell(35, 8, number_format($porc_gastos, 1)." %", 'B', 1, 'R');

$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(39, 174, 96); // Verde
$pdf->SetFillColor(235, 250, 235);
$pdf->Cell(120, 10, utf8_decode(" (=) GANANCIA NETA OPERATIVA"), 'B', 0, 'L', true);
$pdf->Cell(35, 10, number_format($utilidad_neta, 2), 'B', 0, 'R', true);
$pdf->Cell(35, 10, number_format($margen_porc, 1)." %", 'B', 1, 'R', true);

$pdf->Ln(2);
$pdf->SetFont('Arial', 'I', 9);
$pdf->SetTextColor(100);
$pdf->Cell(120, 8, utf8_decode(" (-) Retiros Personales / Dividendos"), 0);
$pdf->Cell(35, 8, number_format($retiros_dueno, 2), 0, 0, 'R');
$pdf->Cell(35, 8, "", 0, 1, 'R');

$pdf->SetFont('Arial', 'B', 11);
$pdf->SetTextColor(255);
$pdf->SetFillColor(52, 73, 94);
$pdf->Cell(120, 10, utf8_decode(" (=) FLUJO DE CAJA REAL"), 0, 0, 'L', true);
$pdf->Cell(35, 10, "$ ".number_format($flujo_caja, 2), 0, 0, 'R', true);
$pdf->Cell(35, 10, "", 0, 1, 'R', true);

// --- 3. SECCIÓN GRÁFICOS Y ANÁLISIS ---
$pdf->Ln(10);
$pdf->SetTextColor(33);
$pdf->Cell(0, 10, utf8_decode("2. ANÁLISIS DE PAGOS Y GASTOS"), 0, 1);
$pdf->SetLineWidth(0.5); $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(5);

$y_start_charts = $pdf->GetY();

// A. TABLA MÉTODOS DE PAGO (Columna Izquierda X=10)
$pdf->SetXY(10, $y_start_charts);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(240);
$pdf->Cell(90, 8, utf8_decode("Ingresos por Medio de Pago"), 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 9);

foreach($lista_metodos as $m) {
    $porc = ($ingresos > 0) ? ($m['total']/$ingresos)*100 : 0;
    
    // Filtro para detectar vacíos
    $nombre_metodo = trim($m['metodo_pago']);
    if($nombre_metodo === '') {
        $nombre_metodo = 'Sin Especificar';
    }
    
    // Formatear Mercado Pago
    if(strtolower($nombre_metodo) === 'mercadopago') {
        $nombre_metodo = 'Mercado Pago';
    }

    $pdf->SetX(10); 
    $pdf->Cell(45, 7, utf8_decode(ucfirst($nombre_metodo)), 1);
    $pdf->Cell(25, 7, "$ ".number_format($m['total'], 0, ',', '.'), 1, 0, 'R');
    $pdf->Cell(20, 7, number_format($porc, 1)."%", 1, 1, 'R');
}

// B. TICKET PROMEDIO (Separado, abajo de la tabla)
$pdf->Ln(5);
$pdf->SetX(10);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(45, 8, "Ticket Promedio:", 1);
$pdf->Cell(45, 8, "$ ".number_format($ticket_promedio, 2), 1, 1, 'R');

// C. GRÁFICO ESTRUCTURA RENTABILIDAD (Columna Derecha X=110)
$pdf->SetXY(110, $y_start_charts); // ARREGLO DE SUPERPOSICIÓN
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(90, 8, utf8_decode("Estructura de Rentabilidad"), 0, 1, 'C');

$pdf->SetXY(110, $y_start_charts + 10);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(20, 5, "Ventas", 0, 0, 'R');
$pdf->SetFillColor(41, 128, 185); 
$pdf->Rect(135, $pdf->GetY(), 60, 5, 'F'); // Barra 100% fija en 60mm
$pdf->SetXY(196, $pdf->GetY()); 
$pdf->Cell(10, 5, "100%", 0, 1, 'L'); // ARREGLO LIMITE HOJA

$pdf->Ln(6);
$pdf->SetX(110);
$pdf->Cell(20, 5, "Costos", 0, 0, 'R');
$w_cmv = ($porc_cmv/100) * 60; if($w_cmv>60) $w_cmv=60; // TOPE MAX
$pdf->SetFillColor(192, 57, 43); 
$pdf->Rect(135, $pdf->GetY(), $w_cmv, 5, 'F');
$pdf->SetXY(136 + $w_cmv, $pdf->GetY());
$pdf->Cell(15, 5, number_format($porc_cmv,0)."%", 0, 1, 'L');

$pdf->Ln(6);
$pdf->SetX(110);
$pdf->Cell(20, 5, "Neta", 0, 0, 'R');
$w_gan = ($margen_porc/100) * 60; if($w_gan<0) $w_gan=0; if($w_gan>60) $w_gan=60;
$pdf->SetFillColor(39, 174, 96); 
$pdf->Rect(135, $pdf->GetY(), $w_gan, 5, 'F');
$pdf->SetXY(136 + $w_gan, $pdf->GetY());
$pdf->Cell(15, 5, number_format($margen_porc,0)."%", 0, 1, 'L');

// TABLA GASTOS (ARREGLO DE CORTE DE HOJA - SALTO PAGINA)
if($pdf->GetY() > 240) $pdf->AddPage(); // Control manual de salto
$pdf->SetXY(110, $pdf->GetY() + 15);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(90, 8, utf8_decode("Top Gastos Operativos"), 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 9);
$count=0;
foreach($data_gastos_chart as $cat => $val) {
    if($count > 4) break;
    $pdf->SetX(110);
    $pdf->Cell(50, 7, utf8_decode($cat), 1);
    $pdf->Cell(40, 7, "$ ".number_format($val,0), 1, 1, 'R');
    $count++;
}

// --- PÁGINA 3: ESTADÍSTICAS VISUALES (LIMPIO Y ESPACIADO) ---
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 14); $pdf->SetTextColor(33);
$pdf->Cell(0, 10, utf8_decode("3. ESTADÍSTICAS VISUALES"), 0, 1, 'C');
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(15); // ESPACIO AGREGADO PARA QUE NO SE ENCINME CON LA LÍNEA

// Grilla empujada hacia abajo
$col1 = 10; $col2 = 110;
$row1 = 60; $row2 = 135; $row3 = 210;
$w_chart = 90; $h_chart = 55;

// 1. Rentabilidad 
$data_rent = ['Costos'=>(float)$costos_mercaderia, 'Gastos'=>(float)$gastos_operativos, 'Ganancia'=>($utilidad_neta > 0 ? (float)$utilidad_neta : 0)];
$pdf->PieChart($col1, $row1, $w_chart, $h_chart, $data_rent, 'Distribucion de Ingresos');

// 2. Gastos 
$pdf->PieChart($col2, $row1, $w_chart, $h_chart, $data_gastos_chart, 'Desglose de Gastos');

// 3. Ventas por Día 
$pdf->BarChart($col1, $row2, $w_chart, $h_chart, $data_dias, 'Ventas por Dia');

// 4. Horas Pico 
$pdf->BarChart($col2, $row2, $w_chart, $h_chart, $data_horas, 'Horas Pico');

// 5. Top Clientes 
$pdf->BarChart($col1, $row3, $w_chart, $h_chart, $data_clientes, 'Top Clientes (Ventas $)');

// 6. Ranking Vendedores
$pdf->BarChart($col2, $row3, $w_chart, $h_chart, $data_vend, 'Ranking Vendedores');

// UNA SOLA SALIDA FINAL PARA EVITAR EL ERROR 500
$pdf->Output('I', 'Reporte_Gerencial_El10.pdf');
?>