<?php
// reportes.php - SISTEMA GERENCIAL (PARTE 1)
session_start();

ini_set('display_errors', 0); 
error_reporting(E_ALL);

require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);

if (!$es_admin && !in_array('ver_reportes', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

// 1. FILTROS Y CONFIGURACIÓN BÁSICA
$inicio = $_GET['f_inicio'] ?? date('Y-m-01');
$fin = $_GET['f_fin'] ?? date('Y-m-d');
$trigger = $_GET['set_rango'] ?? $_GET['filtro'] ?? '';

if($trigger) {
    if($trigger == 'hoy') { $inicio = date('Y-m-d'); $fin = date('Y-m-d'); }
    elseif($trigger == 'ayer') { $inicio = date('Y-m-d', strtotime("-1 days")); $fin = date('Y-m-d', strtotime("-1 days")); }
    elseif($trigger == 'mes') { $inicio = date('Y-m-01'); $fin = date('Y-m-t'); }
}

$id_usuario = (isset($_GET['id_usuario']) && $_GET['id_usuario'] !== '') ? intval($_GET['id_usuario']) : '';
$metodo = isset($_GET['metodo']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['metodo']) : '';

$conf = $conexion->query("SELECT * FROM configuracion LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf['color_barra_nav'] ?? '#102A57';
$nombre_negocio = strtoupper($conf['nombre_negocio'] ?? 'SISTEMA DE GESTIÓN');

// 2. CONSULTA DE VENTAS
try {
    $sql = "SELECT v.*, u.usuario as vendedor, c.nombre as cliente_nombre, c.dni_cuit,
            (
                SELECT SUM(d.cantidad * COALESCE(NULLIF(d.costo_historico,0), p.precio_costo, 0))
                FROM detalle_ventas d 
                LEFT JOIN productos p ON d.id_producto = p.id
                WHERE d.id_venta = v.id
            ) as costo_total_venta
            FROM ventas v 
            LEFT JOIN usuarios u ON v.id_usuario = u.id 
            LEFT JOIN clientes c ON v.id_cliente = c.id
            WHERE v.fecha BETWEEN '$inicio 00:00:00' AND '$fin 23:59:59' AND v.estado = 'completada'";

    if ($id_usuario) $sql .= " AND v.id_usuario = " . intval($id_usuario);
    if ($metodo) $sql .= " AND v.metodo_pago = " . $conexion->quote($metodo);
    $sql .= " ORDER BY v.fecha DESC";

    $ventas = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { die("Error crítico: " . $e->getMessage()); }

// 3. GASTOS Y RETIROS
$gastos_operativos = 0; $retiros_dueno = 0;
try {
    $sqlG = "SELECT categoria, SUM(monto) as total FROM gastos WHERE fecha BETWEEN '$inicio 00:00:00' AND '$fin 23:59:59' GROUP BY categoria";
    $resG = $conexion->query($sqlG)->fetchAll(PDO::FETCH_ASSOC);
    foreach($resG as $rg) {
        if($rg['categoria'] == 'Retiro') $retiros_dueno += $rg['total'];
        else $gastos_operativos += $rg['total'];
    }
} catch (Exception $e) {}

// 4. CÁLCULOS FINANCIEROS (KPIs)
$ingresos_ventas = 0; $costo_mercaderia = 0;
foreach($ventas as $v) {
    $ingresos_ventas += $v['total'];
    $costo_mercaderia += $v['costo_total_venta'];
}
$utilidad_bruta = $ingresos_ventas - $costo_mercaderia;
$utilidad_neta = $utilidad_bruta - $gastos_operativos;
$caja_final = $utilidad_neta - $retiros_dueno;
$margen_p = ($ingresos_ventas > 0) ? ($utilidad_neta / $ingresos_ventas) * 100 : 0;

// 5. NUEVAS ESTADÍSTICAS AVANZADAS PARA EL REPORTE
$sqlPagos = "SELECT metodo_pago, COUNT(id) as cant_operaciones, SUM(total) as monto FROM ventas WHERE fecha BETWEEN '$inicio 00:00:00' AND '$fin 23:59:59' AND estado = 'completada' GROUP BY metodo_pago ORDER BY monto DESC";
$stats_pagos = $conexion->query($sqlPagos)->fetchAll(PDO::FETCH_ASSOC);

$sqlHoras = "SELECT HOUR(fecha) as hora, COUNT(id) as cant, SUM(total) as monto FROM ventas WHERE fecha BETWEEN '$inicio 00:00:00' AND '$fin 23:59:59' AND estado = 'completada' GROUP BY HOUR(fecha) ORDER BY cant DESC LIMIT 5";
$stats_horas = $conexion->query($sqlHoras)->fetchAll(PDO::FETCH_ASSOC);

$sqlClientes = "SELECT c.nombre, c.dni_cuit, COUNT(v.id) as compras, SUM(v.total) as gastado FROM ventas v JOIN clientes c ON v.id_cliente = c.id WHERE v.fecha BETWEEN '$inicio 00:00:00' AND '$fin 23:59:59' AND v.estado = 'completada' AND v.id_cliente > 1 GROUP BY c.id ORDER BY gastado DESC LIMIT 5";
$stats_clientes = $conexion->query($sqlClientes)->fetchAll(PDO::FETCH_ASSOC);

$sqlTop = "SELECT p.descripcion, p.codigo_barras, SUM(d.cantidad) as cant, SUM(d.subtotal) as recaudado FROM detalle_ventas d JOIN ventas v ON d.id_venta = v.id JOIN productos p ON d.id_producto = p.id
           WHERE v.fecha BETWEEN '$inicio 00:00:00' AND '$fin 23:59:59' AND v.estado = 'completada' GROUP BY p.id ORDER BY cant DESC LIMIT 10";
$top_productos = $conexion->query($sqlTop)->fetchAll(PDO::FETCH_ASSOC);

$usuarios_db = $conexion->query("SELECT * FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);

// 6. FIRMA Y DATOS EXCLUSIVOS DEL DUEÑO (Ignora al Admin)
$stmtFirma = $conexion->query("SELECT id, nombre_completo FROM usuarios WHERE id_rol = 2 LIMIT 1");
$dueno_firma = $stmtFirma->fetch(PDO::FETCH_ASSOC);

// Si por alguna razón no hay rol 2 creado, usa el 1 como respaldo de emergencia
if(!$dueno_firma) {
    $stmtFirma = $conexion->query("SELECT id, nombre_completo FROM usuarios WHERE id_rol = 1 LIMIT 1");
    $dueno_firma = $stmtFirma->fetch(PDO::FETCH_ASSOC);
}

$nombre_gerencia = $dueno_firma ? strtoupper($dueno_firma['nombre_completo']) : 'FIRMA AUTORIZADA';
$ruta_firma_pdf = "";
if ($dueno_firma) {
    $ruta_posible = "img/firmas/usuario_" . $dueno_firma['id'] . ".png";
    if (file_exists($ruta_posible)) {
        $ruta_firma_pdf = $ruta_posible . "?v=" . time(); // Rompe el caché de PDF
    }
}

// 7. ID ÚNICO DEL REPORTE PARA EL QR
$report_id = "RPT-" . strtoupper(substr(md5(time() . $inicio), 0, 8));
$qr_data = urlencode("Validador Fiscal | Reporte: $report_id | Emitido: " . date('Y-m-d H:i') . " | Neto: $" . number_format($utilidad_neta, 2, '.', ''));
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=$qr_data";

// INICIO DEL HTML VISUAL
include 'includes/layout_header.php'; 
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden;">
    <i class="bi bi-graph-up-arrow bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Reporte de Gestión</h2>
                <p class="opacity-75 mb-0 text-white small">Análisis detallado de rendimiento, costos y utilidades.</p>
            </div>
            <div class="d-flex gap-2">
            <?php if($es_admin || in_array('descargar_excel', $permisos)): ?>
            <button onclick="exportarExcelPro()" class="btn btn-success fw-bold rounded-pill px-4 shadow-sm">
                <i class="bi bi-file-earmark-excel me-2"></i> EXCEL
            </button>
            <?php endif; ?>

            <?php if($es_admin || in_array('descargar_pdf', $permisos)): ?>
            <button onclick="generarReporteCorporativo()" class="btn btn-danger fw-bold rounded-pill px-4 shadow-sm">
                <i class="bi bi-file-earmark-pdf me-2"></i> INFORME GERENCIAL (PDF)
            </button>
            <?php endif; ?>
        </div>
        </div>

        <div class="row g-3">
            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Ingresos Brutos</div>
                        <div class="widget-value text-white">$<?php echo number_format($ingresos_ventas, 0, ',', '.'); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Egresos Totales</div>
                        <div class="widget-value text-white">$<?php echo number_format($costo_mercaderia + $gastos_operativos, 0, ',', '.'); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Utilidad Neta</div>
                        <div class="widget-value text-white">$<?php echo number_format($utilidad_neta, 0, ',', '.'); ?> <small class="fs-6 opacity-75">(<?php echo number_format($margen_p, 1); ?>%)</small></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Caja Real</div>
                        <div class="widget-value text-white">$<?php echo number_format($caja_final, 0, ',', '.'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <div class="card border-0 shadow-sm p-4 mb-4 mt-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="small fw-bold">RANGO DE FECHAS</label>
                <div class="input-group">
                    <input type="date" name="f_inicio" class="form-control" value="<?php echo $inicio; ?>">
                    <input type="date" name="f_fin" class="form-control" value="<?php echo $fin; ?>">
                </div>
            </div>
            <div class="col-md-3">
                <label class="small fw-bold">CAJERO</label>
                <select name="id_usuario" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach($usuarios_db as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo ($id_usuario == $u['id']) ? 'selected' : ''; ?>><?php echo $u['usuario']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><a href="?set_rango=hoy" class="btn btn-outline-secondary w-100 btn-sm">Hoy</a></div>
            <div class="col-md-3"><button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-funnel-fill"></i> ACTUALIZAR</button></div>
        </form>
    </div>

    <div class="row g-4">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm p-4">
                <h5 class="fw-bold mb-4 text-secondary">Detalle de Operaciones</h5>
                <div class="table-responsive" style="max-height: 500px;">
                    <table class="table table-hover align-middle mb-0" id="tabla-export">
                        <thead class="bg-light">
                            <tr class="text-uppercase small fw-bold text-muted">
                                <th>Ticket</th><th>Fecha</th><th>Vendedor</th><th>Cliente</th><th>Pago</th><th class="text-end">Venta</th><th class="text-end">Costo</th><th class="text-end">Margen</th><th class="text-center">Ver</th>
                            </tr>
                        </thead>
                        <tbody class="small">
                            <?php foreach($ventas as $v): 
                                $total_v = (float)($v['total'] ?? 0);
                                $costo_v = (float)($v['costo_total_venta'] ?? 0);
                                $margen_v = $total_v - $costo_v;
                            ?>
                            <tr>
                                <td class="fw-bold">#<?php echo $v['id']; ?></td>
                                <td><?php echo date('d/m/y H:i', strtotime($v['fecha'])); ?></td>
                                <td><?php echo $v['vendedor']; ?></td>
                                <td><?php echo !empty($v['cliente_nombre']) ? $v['cliente_nombre'] : 'Consumidor Final'; ?></td>
                                <td><span class="badge bg-light text-dark border"><?php echo $v['metodo_pago']; ?></span></td>
                                <td class="text-end fw-bold">$<?php echo number_format($total_v, 0, ',', '.'); ?></td>
                                <td class="text-end text-muted">$<?php echo number_format($costo_v, 0, ',', '.'); ?></td>
                                <td class="text-end fw-bold text-success">$<?php echo number_format($margen_v, 0, ',', '.'); ?></td>
                                <td class="text-end">
                                    <a href="ticket.php?id=<?php echo $v['id']; ?>" onclick="window.open(this.href, 'TicketView', 'width=350,height=600'); return false;" class="btn btn-sm btn-light text-primary rounded-circle"><i class="bi bi-eye-fill"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm p-4 h-100">
                <h5 class="fw-bold mb-4 text-warning"><i class="bi bi-trophy-fill me-2"></i> Más Vendidos</h5>
                <ul class="list-group list-group-flush">
                    <?php foreach($top_productos as $tp): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-3">
                        <span class="small text-uppercase fw-bold text-dark"><?php echo $tp['descripcion']; ?></span>
                        <span class="badge bg-primary rounded-pill"><?php echo intval($tp['cant']); ?> un.</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<div style="position: absolute; left: -9999px; top: 0; width: 210mm;">
    <div id="reporteCorporativo" style="background: white; width: 210mm; padding: 10mm; font-family: 'Arial', sans-serif; color: #333; box-sizing: border-box;">
        
        <div style="border-bottom: 3px solid #102A57; padding-bottom: 10px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: flex-end;">
            <div style="width: 50%;">
                <?php if(!empty($conf['logo_url'])): ?><img src="<?php echo $conf['logo_url']; ?>" style="max-height: 50px; margin-bottom: 10px;"><br><?php endif; ?>
                <h2 style="margin: 0; color: #102A57; font-size: 20px; text-transform: uppercase; letter-spacing: 1px;"><?php echo $nombre_negocio; ?></h2>
                <div style="font-size: 10px; color: #666; margin-top: 5px;">
                    <strong>CUIT:</strong> <?php echo $conf['cuit'] ?? 'No registrado'; ?> | <strong>Dirección:</strong> <?php echo $conf['direccion_local'] ?? 'No registrada'; ?>
                </div>
            </div>
            <div style="width: 50%; text-align: right;">
                <h3 style="margin: 0; color: #dc3545; font-size: 16px; text-transform: uppercase;">INFORME FINANCIERO Y AUDITORÍA</h3>
                <div style="font-size: 10px; color: #444; margin-top: 8px; line-height: 1.4;">
                    <strong>ID DOCUMENTO:</strong> <?php echo $report_id; ?><br>
                    <strong>FECHA DE EMISIÓN:</strong> <?php echo date('d/m/Y H:i'); ?><br>
                    <strong>PERÍODO ANALIZADO:</strong> <?php echo date('d/m/Y', strtotime($inicio)) . ' al ' . date('d/m/Y', strtotime($fin)); ?>
                </div>
            </div>
        </div>

        <h4 style="background: #102A57; color: white; padding: 6px 10px; margin: 0 0 10px 0; font-size: 12px; text-transform: uppercase;">1. Resumen Ejecutivo de Operaciones</h4>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 11px;">
            <tr>
                <td style="padding: 8px; border: 1px solid #ddd; background: #f8f9fa; width: 25%;">
                    <div style="color: #666; font-size: 9px; font-weight: bold; text-transform: uppercase;">Ingresos Brutos</div>
                    <div style="font-size: 16px; font-weight: bold; color: #102A57;">$<?php echo number_format($ingresos_ventas, 2, ',', '.'); ?></div>
                </td>
                <td style="padding: 8px; border: 1px solid #ddd; background: #f8f9fa; width: 25%;">
                    <div style="color: #666; font-size: 9px; font-weight: bold; text-transform: uppercase;">Costo de Mercadería</div>
                    <div style="font-size: 16px; font-weight: bold; color: #dc3545;">-$<?php echo number_format($costo_mercaderia, 2, ',', '.'); ?></div>
                </td>
                <td style="padding: 8px; border: 1px solid #ddd; background: #f8f9fa; width: 25%;">
                    <div style="color: #666; font-size: 9px; font-weight: bold; text-transform: uppercase;">Utilidad Bruta</div>
                    <div style="font-size: 16px; font-weight: bold; color: #198754;">$<?php echo number_format($utilidad_bruta, 2, ',', '.'); ?></div>
                </td>
                <td style="padding: 8px; border: 1px solid #ddd; background: #f8f9fa; width: 25%;">
                    <div style="color: #666; font-size: 9px; font-weight: bold; text-transform: uppercase;">Margen Operativo</div>
                    <div style="font-size: 16px; font-weight: bold; color: #0d6efd;"><?php echo number_format($margen_p, 2); ?>%</div>
                </td>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ddd;">
                    <div style="color: #666; font-size: 9px; font-weight: bold; text-transform: uppercase;">Gastos Operativos</div>
                    <div style="font-size: 13px; font-weight: bold; color: #dc3545;">-$<?php echo number_format($gastos_operativos, 2, ',', '.'); ?></div>
                </td>
                <td style="padding: 8px; border: 1px solid #ddd;">
                    <div style="color: #666; font-size: 9px; font-weight: bold; text-transform: uppercase;">Retiros / Div. Dueño</div>
                    <div style="font-size: 13px; font-weight: bold; color: #dc3545;">-$<?php echo number_format($retiros_dueno, 2, ',', '.'); ?></div>
                </td>
                <td style="padding: 8px; border: 1px solid #ddd; background: #e8f4fd;" colspan="2">
                    <div style="color: #102A57; font-size: 11px; font-weight: bold; text-transform: uppercase;">RESULTADO NETO FINANCIERO (CAJA REAL)</div>
                    <div style="font-size: 18px; font-weight: 900; color: #102A57;">$<?php echo number_format($caja_final, 2, ',', '.'); ?></div>
                </td>
            </tr>
        </table>

        <div style="display: flex; gap: 15px; margin-bottom: 15px;">
            
            <div style="width: 50%;">
                <h4 style="background: #eee; color: #333; padding: 5px 8px; border-left: 3px solid #102A57; margin: 0 0 8px 0; font-size: 11px; text-transform: uppercase;">2A. Distribución de Pagos</h4>
                <table style="width: 100%; border-collapse: collapse; font-size: 9px;">
                    <thead><tr style="border-bottom: 2px solid #ccc;">
                        <th style="text-align: left; padding: 4px;">Método</th>
                        <th style="text-align: center; padding: 4px;">Oper.</th>
                        <th style="text-align: right; padding: 4px;">Monto Total</th>
                        <th style="text-align: right; padding: 4px;">%</th>
                    </tr></thead>
                    <tbody>
                        <?php 
                        foreach($stats_pagos as $sp): 
                            $porcentaje_p = ($ingresos_ventas > 0) ? ($sp['monto'] / $ingresos_ventas) * 100 : 0;
                        ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 4px; font-weight: bold;"><?php echo strtoupper($sp['metodo_pago']); ?></td>
                            <td style="text-align: center; padding: 4px;"><?php echo $sp['cant_operaciones']; ?></td>
                            <td style="text-align: right; padding: 4px; color: #198754;">$<?php echo number_format($sp['monto'], 2, ',', '.'); ?></td>
                            <td style="text-align: right; padding: 4px; width: 35%;">
                                <div style="display: flex; align-items: center; justify-content: flex-end;">
                                    <span style="margin-right: 4px;"><?php echo number_format($porcentaje_p, 1); ?>%</span>
                                    <div style="width: 30px; background: #e0e0e0; height: 5px; border-radius: 2px;"><div style="width: <?php echo $porcentaje_p; ?>%; background: #102A57; height: 100%; border-radius: 2px;"></div></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($stats_pagos)): ?><tr><td colspan="4" style="text-align: center; padding: 8px; color: #999;">Sin datos en el período</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="width: 50%;">
                <h4 style="background: #eee; color: #333; padding: 5px 8px; border-left: 3px solid #dc3545; margin: 0 0 8px 0; font-size: 11px; text-transform: uppercase;">2B. Horas Pico (Demanda)</h4>
                <table style="width: 100%; border-collapse: collapse; font-size: 9px;">
                    <thead><tr style="border-bottom: 2px solid #ccc;">
                        <th style="text-align: left; padding: 4px;">Franja Horaria</th>
                        <th style="text-align: center; padding: 4px;">Volumen</th>
                        <th style="text-align: right; padding: 4px;">Recaudación</th>
                    </tr></thead>
                    <tbody>
                        <?php 
                        foreach($stats_horas as $sh): 
                            $hora_formato = str_pad($sh['hora'], 2, '0', STR_PAD_LEFT) . ':00 - ' . str_pad($sh['hora'], 2, '0', STR_PAD_LEFT) . ':59';
                            $pct_bar = ($sh['cant'] / max(1, count($ventas))) * 100;
                        ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 4px; font-weight: bold;"><i style="color: #dc3545;">⏱</i> <?php echo $hora_formato; ?></td>
                            <td style="padding: 4px; text-align: center;">
                                <?php echo $sh['cant']; ?> ops.
                                <div style="width: 100%; background: #ffebee; height: 3px; border-radius: 2px; margin-top: 2px;"><div style="width: <?php echo min(100, $pct_bar*2); ?>%; background: #dc3545; height: 100%; border-radius: 2px;"></div></div>
                            </td>
                            <td style="text-align: right; padding: 4px; font-weight: bold; color: #333;">$<?php echo number_format($sh['monto'], 0, ',', '.'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($stats_horas)): ?><tr><td colspan="3" style="text-align: center; padding: 8px; color: #999;">Sin datos en el período</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

        <div style="display: flex; gap: 15px; margin-bottom: 15px;">
            
            <div style="width: 50%;">
                <h4 style="background: #102A57; color: white; padding: 5px 8px; margin: 0 0 8px 0; font-size: 11px; text-transform: uppercase;">3A. Top 10 Productos</h4>
                <table style="width: 100%; border-collapse: collapse; font-size: 9px;">
                    <thead><tr style="background: #f8f9fa; border-bottom: 1px solid #ddd;">
                        <th style="text-align: left; padding: 4px;">Artículo / Descripción</th>
                        <th style="text-align: center; padding: 4px;">Unid.</th>
                        <th style="text-align: right; padding: 4px;">Aportado ($)</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach($top_productos as $tp): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 4px;"><?php echo substr($tp['descripcion'], 0, 30); ?>...</td>
                            <td style="text-align: center; padding: 4px; font-weight: bold;"><?php echo floatval($tp['cant']); ?></td>
                            <td style="text-align: right; padding: 4px; color: #198754;">$<?php echo number_format($tp['recaudado'], 2, ',', '.'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="width: 50%;">
                <h4 style="background: #102A57; color: white; padding: 5px 8px; margin: 0 0 8px 0; font-size: 11px; text-transform: uppercase;">3B. Mejores Clientes</h4>
                <table style="width: 100%; border-collapse: collapse; font-size: 9px;">
                    <thead><tr style="background: #f8f9fa; border-bottom: 1px solid #ddd;">
                        <th style="text-align: left; padding: 4px;">Razón Social / Nombre</th>
                        <th style="text-align: center; padding: 4px;">Visitas</th>
                        <th style="text-align: right; padding: 4px;">Volumen ($)</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach($stats_clientes as $sc): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 4px;">
                                <strong><?php echo strtoupper(substr($sc['nombre'], 0, 25)); ?></strong><br>
                                <span style="color: #666; font-size: 8px;">CUIT/DNI: <?php echo $sc['dni_cuit'] ?? 'S/D'; ?></span>
                            </td>
                            <td style="text-align: center; padding: 4px; font-weight: bold;"><?php echo $sc['compras']; ?></td>
                            <td style="text-align: right; padding: 4px; font-weight: bold; color: #102A57;">$<?php echo number_format($sc['gastado'], 2, ',', '.'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($stats_clientes)): ?><tr><td colspan="3" style="text-align: center; padding: 10px; color: #999;">No hay registros.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

        <div style="margin-top: 15px; padding-top: 10px; border-top: 2px dashed #ccc; display: flex; justify-content: space-between; align-items: flex-end; page-break-inside: avoid;">
            <div style="width: 55%; font-size: 8px; color: #666; text-align: justify; line-height: 1.3;">
                <strong>DECLARACIÓN JURADA INTERNA:</strong> Los datos contenidos en el presente reporte han sido extraídos directamente de la base de datos transaccional del sistema y reflejan el estado real y exacto de las operaciones en el período indicado. Documento estrictamente confidencial. Prohibida su reproducción sin autorización de gerencia.
                <br><br>
                <em>Generado por: <?php echo $_SESSION['usuario_nombre'] ?? 'Administrador'; ?> a través del Módulo Gerencial.</em>
            </div>
            <div style="width: 25%; text-align: center; position: relative;">
                <?php if(!empty($ruta_firma_pdf)): ?>
                    <img src="<?php echo $ruta_firma_pdf; ?>" style="max-height: 45px; display: block; margin: 0 auto -5px auto; position: relative; z-index: 2;">
                <?php else: ?>
                    <div style="height: 40px;"></div>
                <?php endif; ?>
                <div style="border-top: 1.5px solid #000; width: 90%; margin: 0 auto; position: relative; z-index: 1; padding-top: 4px;"></div>
                <div style="font-size: 9px; font-weight: bold; text-transform: uppercase;"><?php echo $nombre_gerencia; ?></div>
                <div style="font-size: 8px; color: #666;">RESPONSABLE / GERENCIA</div>
            </div>
            <div style="width: 15%; text-align: right;">
                <img src="<?php echo $qr_url; ?>" style="width: 65px; height: 65px; border: 1px solid #ddd; padding: 2px; border-radius: 4px;">
                <div style="font-size: 7px; margin-top: 3px; font-weight: bold; color: #999; text-align: center;">ESCANEAR PARA<br>VALIDAR ORIGEN</div>
            </div>
        </div>

    </div>
</div>

<script>
function exportarExcelPro() {
    let table = document.getElementById("tabla-export");
    let rows = Array.from(table.querySelectorAll("tr"));
    let csvContent = "\uFEFF"; 
    rows.forEach(row => {
        let cols = Array.from(row.querySelectorAll("th, td")).map(cell => {
            let text = cell.innerText.replace(/\./g, "").replace("$", "").trim();
            return `"${text}"`;
        });
        csvContent += cols.join(";") + "\r\n";
    });
    let blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" });
    let link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = "Reporte_Completo_El10.csv";
    link.click();
}

function generarReporteCorporativo() {
    const elemento = document.getElementById('reporteCorporativo');
    
    // Configuración ajustada para evitar la hoja en blanco (A4)
    const opt = {
        margin:       [0, 0, 0, 0], // Sin márgenes extras para controlar todo desde el div
        filename:     'Reporte_Gerencial_Financiero.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true, scrollY: 0 },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    Swal.fire({
        title: 'Generando Reporte Oficial...',
        text: 'Procesando datos financieros, gráficos y cruces de información.',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    html2pdf().set(opt).from(elemento).save().then(() => {
        Swal.close();
        Swal.fire({
            icon: 'success',
            title: 'Reporte Descargado',
            text: 'El documento corporativo se guardó en tu computadora exitosamente.',
            confirmButtonColor: '#102A57'
        });
    }).catch(err => {
        Swal.close();
        Swal.fire('Error', 'Hubo un problema al generar el PDF.', 'error');
    });
}
</script>

<?php include 'includes/layout_footer.php'; ?>