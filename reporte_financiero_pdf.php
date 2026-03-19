<?php
// reporte_financiero_pdf.php - AUDITORÍA CORPORATIVA INTEGRAL (VANGUARD PRO)
require_once 'includes/db.php';

// 1. CARGAR FPDF
$rutas_fpdf = ['fpdf/fpdf.php', 'includes/fpdf/fpdf.php', '../fpdf/fpdf.php'];
$fpdf_loaded = false;
foreach ($rutas_fpdf as $ruta) {
    if (file_exists($ruta)) { require_once $ruta; $fpdf_loaded = true; break; }
}
if (!$fpdf_loaded) die("Error crítico: Motor FPDF no encontrado en el sistema.");

// 2. FUNCIÓN DE EXTRACCIÓN SEGURA (Evita que el PDF explote si falta una tabla)
function safeQuery($conn, $sql, $fetchMode = 'all') {
    try {
        $stmt = $conn->query($sql);
        if(!$stmt) return ($fetchMode == 'single' ? [] : []);
        if($fetchMode == 'single') return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if($fetchMode == 'pairs') return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch(Exception $e) { return []; }
}

// 3. CONFIGURACIÓN Y FILTROS
$config = safeQuery($conexion, "SELECT * FROM configuracion WHERE id = 1", 'single');
$empresa = $config['nombre_negocio'] ?? 'VANGUARD ENTERPRISE';
$cuit = $config['cuit'] ?? 'S/D';
$logo_url = $config['logo_url'] ?? '';

$f_inicio = $_GET['f_inicio'] ?? date('Y-m-01');
$f_fin = $_GET['f_fin'] ?? date('Y-m-d');
$rango_sql = "BETWEEN '$f_inicio 00:00:00' AND '$f_fin 23:59:59'";

// ============================================================================
// MOTOR DE CÁLCULOS FINANCIEROS Y OPERATIVOS (BIG DATA)
// ============================================================================

// A. VENTAS BRUTAS Y COSTOS (CMV)
$sqlVentas = "SELECT COUNT(*) as cant, SUM(v.total) as venta_bruta,
              SUM((SELECT SUM(d.cantidad * COALESCE(NULLIF(d.costo_historico,0), p.precio_costo)) 
                   FROM detalle_ventas d JOIN productos p ON d.id_producto = p.id 
                   WHERE d.id_venta = v.id)) as costo_cmv
              FROM ventas v WHERE v.fecha $rango_sql AND v.estado = 'completada'";
$resVentas = safeQuery($conexion, $sqlVentas, 'single');
$ingresos_brutos = floatval($resVentas['venta_bruta'] ?? 0);
$costos_cmv = floatval($resVentas['costo_cmv'] ?? 0);
$cant_tickets = intval($resVentas['cant'] ?? 0);
$ticket_promedio = ($cant_tickets > 0) ? $ingresos_brutos / $cant_tickets : 0;

// B. DEVOLUCIONES (Riesgo Operativo)
$resDev = safeQuery($conexion, "SELECT SUM(monto_devuelto) as total, COUNT(*) as cant FROM devoluciones WHERE fecha $rango_sql", 'single');
$devoluciones = floatval($resDev['total'] ?? 0);
$cant_devoluciones = intval($resDev['cant'] ?? 0);

// C. MERMAS (Pérdidas Físicas)
$resMermas = safeQuery($conexion, "SELECT SUM(m.cantidad * p.precio_costo) as costo_perdido, COUNT(*) as cant FROM mermas m JOIN productos p ON m.id_producto = p.id WHERE m.fecha $rango_sql", 'single');
$mermas_costo = floatval($resMermas['costo_perdido'] ?? 0);

// D. GASTOS Y RETIROS
$lista_gastos = safeQuery($conexion, "SELECT categoria, SUM(monto) as total FROM gastos WHERE fecha $rango_sql GROUP BY categoria ORDER BY total DESC");
$gastos_operativos = 0; $retiros_dueno = 0; $data_gastos_chart = [];
foreach($lista_gastos as $g) {
    if(strtoupper($g['categoria']) == 'RETIRO' || strtoupper($g['categoria']) == 'DIVIDENDOS') { $retiros_dueno += $g['total']; } 
    else { $gastos_operativos += $g['total']; $data_gastos_chart[$g['categoria']] = $g['total']; }
}

// E. MÉTODOS DE PAGO (Liquidez)
$lista_metodos = safeQuery($conexion, "SELECT metodo_pago, SUM(total) as total FROM ventas WHERE fecha $rango_sql AND estado = 'completada' GROUP BY metodo_pago ORDER BY total DESC");
$data_metodos_chart = []; foreach($lista_metodos as $m) $data_metodos_chart[$m['metodo_pago'] ?: 'Otros'] = $m['total'];

// F. ESTADO DE ACTIVOS (Inventario Circulante Total - Histórico no aplica, es foto actual)
$resInv = safeQuery($conexion, "SELECT SUM(stock_actual * precio_costo) as capital_invertido, SUM(stock_actual * precio_venta) as valor_mercado, COUNT(id) as total_items FROM productos WHERE activo = 1", 'single');
$capital_inventario = floatval($resInv['capital_invertido'] ?? 0);
$valor_mercado_inventario = floatval($resInv['valor_mercado'] ?? 0);
$lucro_cesante_potencial = $valor_mercado_inventario - $capital_inventario;

// G. FIDELIDAD Y CLIENTES (Puntos y Saldos)
$resCli = safeQuery($conexion, "SELECT SUM(puntos) as total_puntos, COUNT(id) as cant_clientes FROM clientes WHERE id > 1", 'single');
$pasivo_puntos = floatval($resCli['total_puntos'] ?? 0);

// H. OTRAS MÉTRICAS (Seguras)
$data_dias = safeQuery($conexion, "SELECT DAYNAME(fecha), SUM(total) FROM ventas WHERE fecha $rango_sql AND estado='completada' GROUP BY DAYOFWEEK(fecha)", 'pairs');
$data_horas = safeQuery($conexion, "SELECT CONCAT(HOUR(fecha), ':00'), COUNT(*) FROM ventas WHERE fecha $rango_sql AND estado='completada' GROUP BY HOUR(fecha)", 'pairs');
$data_clientes = safeQuery($conexion, "SELECT c.nombre, SUM(v.total) FROM ventas v JOIN clientes c ON v.id_cliente=c.id WHERE v.fecha $rango_sql AND c.id > 1 AND v.estado='completada' GROUP BY c.id ORDER BY SUM(v.total) DESC LIMIT 5", 'pairs');
$data_vend = safeQuery($conexion, "SELECT u.usuario, SUM(v.total) FROM ventas v JOIN usuarios u ON v.id_usuario=u.id WHERE v.fecha $rango_sql AND v.estado='completada' GROUP BY u.id ORDER BY SUM(v.total) DESC", 'pairs');
$data_cat_stock = safeQuery($conexion, "SELECT c.nombre, SUM(p.stock_actual * p.precio_costo) FROM productos p JOIN categorias c ON p.id_categoria=c.id WHERE p.activo=1 GROUP BY c.id ORDER BY SUM(p.stock_actual * p.precio_costo) DESC LIMIT 6", 'pairs');

// --- CÁLCULOS DEL P&L (Estado de Resultados Real) ---
$ventas_netas = $ingresos_brutos - $devoluciones;
$utilidad_bruta = $ventas_netas - $costos_cmv;
$ebitda = $utilidad_bruta - $gastos_operativos; // Asumiendo no hay depreciación de bienes de uso en este sistema
$utilidad_operativa = $ebitda - $mermas_costo; // Descontamos las mermas acá
$flujo_caja_libre = $utilidad_operativa - $retiros_dueno;

$margen_bruto_pct = ($ventas_netas > 0) ? ($utilidad_bruta / $ventas_netas) * 100 : 0;
$margen_neto_pct = ($ventas_netas > 0) ? ($utilidad_operativa / $ventas_netas) * 100 : 0;

// ============================================================================
// CLASE PDF CORPORATIVA
// ============================================================================
class PDF_Corporativo extends FPDF {
    public $empresa; public $cuit; public $rango; public $logo;

    function RoundedRect($x, $y, $w, $h, $r, $style = '') {
        $k = $this->k; $hp = $this->h;
        if($style=='F') $op='f'; elseif($style=='FD' || $style=='DF') $op='B'; else $op='S';
        $MyArc = 4/3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m',($x+$r)*$k,($hp-$y)*$k ));
        $xc = $x+$w-$r; $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l', $xc*$k,($hp-$y)*$k ));
        $this->_Arc($xc + $r*$MyArc, $yc - $r, $xc + $r, $yc - $r*$MyArc, $xc + $r, $yc);
        $xc = $x+$w-$r; $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',($x+$w)*$k,($hp-$yc)*$k));
        $this->_Arc($xc + $r, $yc + $r*$MyArc, $xc + $r*$MyArc, $yc + $r, $xc, $yc + $r);
        $xc = $x+$r; $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',$xc*$k,($hp-($y+$h))*$k));
        $this->_Arc($xc - $r*$MyArc, $yc + $r, $xc - $r, $yc + $r*$MyArc, $xc - $r, $yc);
        $xc = $x+$r; $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l',($x)*$k,($hp-$yc)*$k));
        $this->_Arc($xc - $r, $yc - $r*$MyArc, $xc - $r*$MyArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }
    function _Arc($x1, $y1, $x2, $y2, $x3, $y3){
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x1*$this->k, ($h-$y1)*$this->k, $x2*$this->k, ($h-$y2)*$this->k, $x3*$this->k, ($h-$y3)*$this->k));
    }

    function Header() {
        $this->SetFillColor(16, 42, 87); // Azul Vanguard
        $this->Rect(0, 0, 210, 35, 'F');
        if(!empty($this->logo) && file_exists($this->logo)) {
            $this->Image($this->logo, 10, 5, 0, 25);
        }
        $this->SetXY(40, 8); $this->SetFont('Arial', 'B', 16); $this->SetTextColor(255);
        $this->Cell(0, 8, utf8_decode(strtoupper($this->empresa)), 0, 1, 'L');
        $this->SetXY(40, 16); $this->SetFont('Arial', '', 9); $this->SetTextColor(200);
        $this->Cell(0, 5, utf8_decode("DOCUMENTO CONFIDENCIAL - AUDITORÍA FINANCIERA INTEGRAL"), 0, 1, 'L');
        $this->SetXY(40, 21); $this->Cell(0, 5, utf8_decode("CUIT: " . $this->cuit . " | Emisión: " . date('d/m/Y H:i')), 0, 1, 'L');
        
        $this->SetXY(140, 10); $this->SetFont('Arial', 'B', 9); $this->SetFillColor(255,255,255); $this->SetTextColor(16, 42, 87);
        $this->RoundedRect(140, 8, 60, 18, 2, 'F');
        $this->SetXY(140, 10); $this->Cell(60, 5, "PERIODO AUDITADO", 0, 1, 'C');
        $this->SetFont('Arial', '', 8); $this->SetXY(140, 16);
        $this->Cell(60, 5, utf8_decode($this->rango), 0, 1, 'C');
        $this->Ln(15);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(128);
        $this->Cell(0, 10, utf8_decode('Reporte Corporativo Vanguard Pro - Generado automáticamente. Página ') . $this->PageNo() . ' de {nb}', 0, 0, 'C');
    }

    function Card($x, $y, $w, $h, $title, $value, $subtitle, $color_hex = [16,42,87]) {
        $this->SetFillColor(240, 240, 240); $this->RoundedRect($x+1, $y+1, $w, $h, 3, 'F');
        $this->SetFillColor(255, 255, 255); $this->RoundedRect($x, $y, $w, $h, 3, 'F');
        $this->SetFillColor($color_hex[0], $color_hex[1], $color_hex[2]); $this->RoundedRect($x, $y, 3, $h, 2, 'F');
        $this->SetXY($x+5, $y+3); $this->SetFont('Arial', 'B', 8); $this->SetTextColor(100); $this->Cell($w-5, 5, utf8_decode(strtoupper($title)), 0, 1);
        $this->SetXY($x+5, $y+9); $this->SetFont('Arial', 'B', 13); $this->SetTextColor(20); $this->Cell($w-5, 8, utf8_decode($value), 0, 1);
        $this->SetXY($x+5, $y+18); $this->SetFont('Arial', '', 7); $this->SetTextColor(120); $this->Cell($w-5, 4, utf8_decode($subtitle), 0, 1);
    }

    function SectionTitle($title) {
        $this->Ln(5);
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(16, 42, 87);
        $this->Cell(0, 8, utf8_decode(strtoupper($title)), 0, 1, 'L');
        $this->SetLineWidth(0.4);
        $this->SetDrawColor(16, 42, 87);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(4);
    }

    function TechBox($title, $text) {
        $this->Ln(2);
        $this->SetFillColor(245, 248, 252);
        $this->SetDrawColor(200, 210, 220);
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(16, 42, 87);
        $this->Cell(0, 6, utf8_decode(" GLOSARIO TÉCNICO: " . $title), 'LTR', 1, 'L', true);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(80, 80, 80);
        $this->MultiCell(0, 5, utf8_decode($text), 'LBR', 'L', true);
        $this->Ln(2);
    }

    function ChartImage($type, $x, $y, $w, $h, $data, $titulo, $colors) {
        $this->SetXY($x, $y);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(33);
        $this->Cell($w, 6, utf8_decode($titulo), 0, 1, 'C');

        if(empty($data)) return;
        $labels = []; $values = [];
        foreach($data as $lbl => $val) {
            $labels[] = "'" . substr(str_replace(["'", "\""], "", $lbl), 0, 15) . "'";
            $values[] = round($val, 2);
        }

        $config = [
            'type' => $type,
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'data' => $values,
                    'backgroundColor' => $colors,
                    'borderWidth' => ($type == 'pie' || $type == 'doughnut') ? 1 : 0
                ]]
            ],
            'options' => [
                'plugins' => ['legend' => ['display' => ($type == 'pie' || $type == 'doughnut'), 'position' => 'right']]
            ]
        ];

        // API de QuickChart (URL Encode de un JSON plano para evitar problemas de URL longa)
        $chartJson = str_replace(['"labels":[', '"datasets":['], ['labels:[', 'datasets:['], json_encode($config));
        $url = "https://quickchart.io/chart?c=" . urlencode($chartJson) . "&w=" . ($w*3) . "&h=" . (($h-10)*3) . "&format=png";
        
        // Error silenciado por si no hay internet
        @$this->Image($url, $x, $y + 8, $w, $h - 8, 'PNG');
    }
}

// INICIALIZACIÓN
$pdf = new PDF_Corporativo();
$pdf->AliasNbPages();
$pdf->empresa = $empresa;
$pdf->cuit = $cuit;
$pdf->logo = $logo_url;
$pdf->rango = date('d/m/Y', strtotime($f_inicio))." - ".date('d/m/Y', strtotime($f_fin));

// ============================================================================
// PÁGINA 1: DASHBOARD Y ESTADO DE RESULTADOS INTEGRAL
// ============================================================================
$pdf->AddPage();

$pdf->SectionTitle("1. Resumen Ejecutivo (Dashboards)");
$y_cards = $pdf->GetY() + 2;
$pdf->Card(10, $y_cards, 45, 22, 'Ventas Brutas', '$ '.number_format($ingresos_brutos,0,',','.'), $cant_tickets.' Operaciones', [41, 128, 185]);
$pdf->Card(58, $y_cards, 45, 22, 'Utilidad Bruta', '$ '.number_format($utilidad_bruta,0,',','.'), 'Margen: '.number_format($margen_bruto_pct,1).'%', [243, 156, 18]);
$pdf->Card(106, $y_cards, 45, 22, 'Costo Mermas', '$ '.number_format($mermas_costo,0,',','.'), 'Pérdidas operativas', [231, 76, 60]);
$pdf->Card(154, $y_cards, 45, 22, 'Utilidad Neta', '$ '.number_format($utilidad_operativa,0,',','.'), 'EBIT final', [39, 174, 96]);

$pdf->SetY($y_cards + 28);
$pdf->SectionTitle("2. Estado de Resultados (P&L Account)");

$pdf->SetFillColor(240, 240, 240); $pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(120, 8, utf8_decode("CONCEPTO FINANCIERO"), 0, 0, 'L', true);
$pdf->Cell(35, 8, "IMPORTE ($)", 0, 0, 'R', true);
$pdf->Cell(35, 8, "ANÁLISIS VERT.", 0, 1, 'R', true);

$pdf->SetFont('Arial', '', 9);
// Ingresos
$pdf->Cell(120, 7, utf8_decode(" (+) INGRESOS BRUTOS POR VENTAS"), 'B');
$pdf->Cell(35, 7, number_format($ingresos_brutos, 2, ',', '.'), 'B', 0, 'R');
$pdf->Cell(35, 7, "-", 'B', 1, 'R');

$pdf->SetTextColor(192, 57, 43);
$pdf->Cell(120, 7, utf8_decode(" (-) Devoluciones y Reintegros Comerciales"), 'B');
$pdf->Cell(35, 7, number_format($devoluciones, 2, ',', '.'), 'B', 0, 'R');
$pdf->Cell(35, 7, ($ingresos_brutos>0?number_format(($devoluciones/$ingresos_brutos)*100,1):0)." %", 'B', 1, 'R');

$pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(16, 42, 87); $pdf->SetFillColor(245, 248, 252);
$pdf->Cell(120, 7, utf8_decode(" (=) VENTAS NETAS REALES"), 'B', 0, 'L', true);
$pdf->Cell(35, 7, number_format($ventas_netas, 2, ',', '.'), 'B', 0, 'R', true);
$pdf->Cell(35, 7, "100.0 %", 'B', 1, 'R', true);

$pdf->SetFont('Arial', '', 9); $pdf->SetTextColor(192, 57, 43);
$pdf->Cell(120, 7, utf8_decode(" (-) Costo de Mercadería Vendida (CMV)"), 'B');
$pdf->Cell(35, 7, number_format($costos_cmv, 2, ',', '.'), 'B', 0, 'R');
$pdf->Cell(35, 7, ($ventas_netas>0?number_format(($costos_cmv/$ventas_netas)*100,1):0)." %", 'B', 1, 'R');

$pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(16, 42, 87);
$pdf->Cell(120, 7, utf8_decode(" (=) UTILIDAD BRUTA"), 'B', 0, 'L', true);
$pdf->Cell(35, 7, number_format($utilidad_bruta, 2, ',', '.'), 'B', 0, 'R', true);
$pdf->Cell(35, 7, number_format($margen_bruto_pct, 1)." %", 'B', 1, 'R', true);

$pdf->SetFont('Arial', '', 9); $pdf->SetTextColor(192, 57, 43);
$pdf->Cell(120, 7, utf8_decode(" (-) Gastos Operativos (Fijos y Variables)"), 'B');
$pdf->Cell(35, 7, number_format($gastos_operativos, 2, ',', '.'), 'B', 0, 'R');
$pdf->Cell(35, 7, ($ventas_netas>0?number_format(($gastos_operativos/$ventas_netas)*100,1):0)." %", 'B', 1, 'R');

$pdf->Cell(120, 7, utf8_decode(" (-) Mermas (Pérdidas Físicas de Inventario)"), 'B');
$pdf->Cell(35, 7, number_format($mermas_costo, 2, ',', '.'), 'B', 0, 'R');
$pdf->Cell(35, 7, ($ventas_netas>0?number_format(($mermas_costo/$ventas_netas)*100,1):0)." %", 'B', 1, 'R');

$pdf->SetFont('Arial', 'B', 10); $pdf->SetTextColor(39, 174, 96); $pdf->SetFillColor(235, 250, 240);
$pdf->Cell(120, 9, utf8_decode(" (=) UTILIDAD NETA OPERATIVA (EBIT)"), 'B', 0, 'L', true);
$pdf->Cell(35, 9, number_format($utilidad_operativa, 2, ',', '.'), 'B', 0, 'R', true);
$pdf->Cell(35, 9, number_format($margen_neto_pct, 1)." %", 'B', 1, 'R', true);

$pdf->SetFont('Arial', 'I', 9); $pdf->SetTextColor(100);
$pdf->Cell(120, 7, utf8_decode(" (-) Retiros Personales de Socios / Dividendos"), 0);
$pdf->Cell(35, 7, number_format($retiros_dueno, 2, ',', '.'), 0, 0, 'R');
$pdf->Cell(35, 7, "", 0, 1, 'R');

$pdf->SetFont('Arial', 'B', 11); $pdf->SetTextColor(255); $pdf->SetFillColor(44, 62, 80);
$pdf->Cell(120, 10, utf8_decode(" (=) FLUJO DE CAJA LIBRE DEL PERÍODO"), 0, 0, 'L', true);
$pdf->Cell(70, 10, "$ ".number_format($flujo_caja_libre, 2, ',', '.'), 0, 1, 'R', true);

$pdf->TechBox("ESTADO DE RESULTADOS & CMV", "El CMV (Costo de Mercadería Vendida) representa cuánto le costó a la empresa adquirir los productos que efectivamente se vendieron. La Utilidad Operativa (EBIT) es la ganancia real del negocio antes de impuestos, demostrando si la operación comercial por sí sola es rentable, independientemente de los retiros del dueño.");

// ============================================================================
// PÁGINA 2: ANÁLISIS DE LIQUIDEZ Y GASTOS
// ============================================================================
$pdf->AddPage();
$pdf->SectionTitle("3. Análisis de Liquidez y Riesgo Operativo");

$y_start = $pdf->GetY();

// A. Métodos de pago
$pdf->SetXY(10, $y_start);
$pdf->SetFont('Arial', 'B', 9); $pdf->SetFillColor(240); $pdf->SetTextColor(33);
$pdf->Cell(90, 7, utf8_decode("Estructura de Ingresos (Cobranzas)"), 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 8);
foreach($data_metodos_chart as $metodo => $val) {
    $porc = ($ingresos_brutos > 0) ? ($val/$ingresos_brutos)*100 : 0;
    $pdf->SetX(10);
    $pdf->Cell(45, 6, utf8_decode(strtoupper($metodo)), 1);
    $pdf->Cell(25, 6, "$ ".number_format($val, 0, ',', '.'), 1, 0, 'R');
    $pdf->Cell(20, 6, number_format($porc, 1)."%", 1, 1, 'R');
}

// B. Gastos Operativos
$pdf->SetXY(110, $y_start);
$pdf->SetFont('Arial', 'B', 9); $pdf->SetFillColor(240);
$pdf->Cell(90, 7, utf8_decode("Desglose de Gastos Operativos"), 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 8);
$limit = 0;
foreach($data_gastos_chart as $cat => $val) {
    if($limit++ > 6) break; // Limitar filas
    $porc = ($gastos_operativos > 0) ? ($val/$gastos_operativos)*100 : 0;
    $pdf->SetX(110);
    $pdf->Cell(45, 6, utf8_decode(strtoupper($cat)), 1);
    $pdf->Cell(25, 6, "$ ".number_format($val, 0, ',', '.'), 1, 0, 'R');
    $pdf->Cell(20, 6, number_format($porc, 1)."%", 1, 1, 'R');
}

$pdf->Ln(5);
$pdf->TechBox("RIESGO DE LIQUIDEZ Y MEDIOS DE PAGO", "Monitorear la proporción de Efectivo vs. Medios Electrónicos (Transferencias, Tarjetas) es vital para la conciliación bancaria. Un alto volumen electrónico reduce el riesgo de robo físico (seguridad de caja), pero puede aumentar la carga impositiva y demoras en la disponibilidad de liquidez inmediata según los plazos de acreditación.");

$pdf->Ln(5);
// Gráficos de esta página
$y_charts = $pdf->GetY();
$colores_pie = ['#3498db','#e74c3c','#2ecc71','#f1c40f','#9b59b6','#34495e'];
$pdf->ChartImage('pie', 10, $y_charts, 90, 60, $data_metodos_chart, "Composición de Ingresos", $colores_pie);
$pdf->ChartImage('doughnut', 110, $y_charts, 90, 60, $data_gastos_chart, "Distribución de Gastos", $colores_pie);

// ============================================================================
// PÁGINA 3: ACTIVOS, INVENTARIO Y PATRIMONIO
// ============================================================================
$pdf->AddPage();
$pdf->SectionTitle("4. Estado de Activos e Inmovilización de Capital");

// Tarjetas de Inventario
$y_cards = $pdf->GetY();
$pdf->Card(10, $y_cards, 60, 22, 'Capital Invertido (Costo)', '$ '.number_format($capital_inventario,0,',','.'), 'Dinero inmovilizado en stock', [142, 68, 173]);
$pdf->Card(75, $y_cards, 60, 22, 'Valor de Mercado (PVP)', '$ '.number_format($valor_mercado_inventario,0,',','.'), 'Ingreso bruto potencial', [41, 128, 185]);
$pdf->Card(140, $y_cards, 60, 22, 'Lucro Cesante / Margen', '$ '.number_format($lucro_cesante_potencial,0,',','.'), 'Ganancia al liquidar stock', [39, 174, 96]);

$pdf->SetY($y_cards + 28);
$pdf->SetFont('Arial', 'B', 9); $pdf->SetFillColor(240); $pdf->SetTextColor(33);
$pdf->Cell(0, 7, utf8_decode("Concentración de Capital por Categoría (Top 6)"), 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 8);
foreach($data_cat_stock as $cat => $val) {
    $porc = ($capital_inventario > 0) ? ($val/$capital_inventario)*100 : 0;
    $pdf->Cell(120, 6, utf8_decode(strtoupper($cat)), 1);
    $pdf->Cell(40, 6, "$ ".number_format($val, 0, ',', '.'), 1, 0, 'R');
    $pdf->Cell(30, 6, number_format($porc, 1)."%", 1, 1, 'R');
}

$pdf->Ln(2);
$pdf->TechBox("ACTIVO CIRCULANTE E INMOVILIZACIÓN", "El Inventario es su principal Activo Circulante. Un capital invertido demasiado alto respecto a las ventas mensuales indica sobre-stock e inmovilización financiera (el dinero está en los estantes perdiendo valor frente a la inflación). El objetivo gerencial es aumentar la rotación: vender rápido con el menor stock posible almacenado.");

$pdf->Ln(5);
$pdf->ChartImage('bar', 55, $pdf->GetY(), 100, 60, $data_cat_stock, "Inversion en Stock por Categoria", "['#8e44ad']");

// ============================================================================
// PÁGINA 4: COMPORTAMIENTO COMERCIAL
// ============================================================================
$pdf->AddPage();
$pdf->SectionTitle("5. Mapeo Comercial y Tendencias (Data Analytics)");

$y_charts = $pdf->GetY();
$pdf->ChartImage('bar', 10, $y_charts, 90, 60, $data_dias, "Volumen de Ventas por Dia", "['#2980b9']");
$pdf->ChartImage('bar', 110, $y_charts, 90, 60, $data_horas, "Mapa de Calor: Horas Pico", "['#e67e22']");

$pdf->SetY($y_charts + 65);
$pdf->SetFont('Arial', 'B', 9); $pdf->SetFillColor(240); $pdf->SetTextColor(33);

// Top Vendedores
$pdf->SetXY(10, $pdf->GetY());
$y_list = $pdf->GetY();
$pdf->Cell(90, 7, utf8_decode("Rendimiento del Personal (Top Vendedores)"), 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 8);
foreach($data_vend as $vend => $val) {
    $pdf->SetX(10);
    $pdf->Cell(60, 6, utf8_decode(strtoupper($vend)), 1);
    $pdf->Cell(30, 6, "$ ".number_format($val, 0, ',', '.'), 1, 1, 'R');
}

// Top Clientes
$pdf->SetXY(110, $y_list);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(90, 7, utf8_decode("Clientes de Mayor Impacto (LTV)"), 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 8);
foreach($data_clientes as $cli => $val) {
    $pdf->SetX(110);
    $pdf->Cell(60, 6, utf8_decode(strtoupper($cli)), 1);
    $pdf->Cell(30, 6, "$ ".number_format($val, 0, ',', '.'), 1, 1, 'R');
}

$pdf->Ln(5);
$pdf->SetX(10);
$pdf->TechBox("PROGRAMA DE FIDELIZACIÓN (PASIVO NO EXIGIBLE)", "El sistema registra un total acumulado de " . number_format($pasivo_puntos, 0) . " puntos en manos de los clientes. Contablemente, los puntos no canjeados representan un 'Pasivo Comercial' latente, ya que la empresa tiene una obligación futura de entregar productos o descuentos a cambio de los mismos.");

// ============================================================================
// FINALIZAR Y ENVIAR AL NAVEGADOR
// ============================================================================
$pdf->Output('I', 'Auditoria_Vanguard_Pro_'.date('Ymd').'.pdf');
?>