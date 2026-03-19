<?php
// reportes.php - SISTEMA GERENCIAL VANGUARD PRO (AUDITORÍA EXTREMA PREMIUM)
session_start();
ini_set('display_errors', 0); 
error_reporting(E_ALL);

require_once 'includes/db.php';

$tablas = ['ventas', 'gastos', 'devoluciones', 'mermas', 'productos', 'proveedores', 'combos', 'clientes', 'sorteos'];
foreach($tablas as $t) {
    try { $conexion->exec("ALTER TABLE $t ADD COLUMN tipo_negocio VARCHAR(50) NULL"); } catch(Exception $e){}
}

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }


$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);
if (!$es_admin && !in_array('finanzas_ver_dashboard', $permisos)) { header("Location: dashboard.php"); exit; }

// ==============================================================================
// FUNCIÓN BLINDADA ANTIFALLOS (Evita Error 500 si falta una tabla)
// ==============================================================================
function obtenerDatoSeguro($conexion, $sql, $es_unico = false) {
    try {
        $stmt = @$conexion->query($sql);
        if (!$stmt) return $es_unico ? [] : [];
        $res = $es_unico ? $stmt->fetch(PDO::FETCH_ASSOC) : $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $res ?: ($es_unico ? [] : []);
    } catch (Exception $e) { return $es_unico ? [] : []; }
}

// --- AJAX: OBTENER DATOS DEL TICKET ---
if (isset($_GET['ajax_get_ticket'])) {
    header('Content-Type: application/json');
    $id_t = intval($_GET['ajax_get_ticket']);
    $stmt = $conexion->prepare("SELECT v.*, u.usuario as vendedor, u.id as id_usuario, u.nombre_completo, r.nombre as nombre_rol, c.nombre as cliente, c.telefono, c.email FROM ventas v JOIN usuarios u ON v.id_usuario = u.id JOIN roles r ON u.id_rol = r.id LEFT JOIN clientes c ON v.id_cliente = c.id WHERE v.id = ?");
    $stmt->execute([$id_t]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmtDet = $conexion->prepare("SELECT dv.*, p.descripcion FROM detalle_ventas dv JOIN productos p ON dv.id_producto = p.id WHERE dv.id_venta = ?");
    $stmtDet->execute([$id_t]);
    $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
    $conf = obtenerDatoSeguro($conexion, "SELECT * FROM configuracion WHERE id=1", true);
    echo json_encode(['venta' => $venta, 'detalles' => $items, 'conf' => $conf]);
    exit;
}

// 1. FILTROS BÁSICOS
$inicio = $_GET['f_inicio'] ?? date('Y-m-01');
$fin = $_GET['f_fin'] ?? date('Y-m-d');
$trigger = $_GET['set_rango'] ?? '';
$buscar = trim($_GET['buscar'] ?? '');

if($trigger) {
    if($trigger == 'hoy') { $inicio = date('Y-m-d'); $fin = date('Y-m-d'); }
    elseif($trigger == 'ayer') { $inicio = date('Y-m-d', strtotime("-1 days")); $fin = date('Y-m-d', strtotime("-1 days")); }
    elseif($trigger == 'mes') { $inicio = date('Y-m-01'); $fin = date('Y-m-t'); }
}
$rango_sql = "BETWEEN '$inicio 00:00:00' AND '$fin 23:59:59'";
$id_usuario = (isset($_GET['id_usuario']) && $_GET['id_usuario'] !== '') ? intval($_GET['id_usuario']) : '';
$metodo = isset($_GET['metodo']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['metodo']) : '';

$conf = obtenerDatoSeguro($conexion, "SELECT * FROM configuracion LIMIT 1", true);
$nombre_negocio = strtoupper($conf['nombre_negocio'] ?? 'SISTEMA DE GESTIÓN');
$rubro_actual = $conf['tipo_negocio'] ?? 'kiosco';

// ==============================================================================
// 2. EXTRACCIÓN MASIVA DE DATOS OMNIDIRECCIONAL
// ==============================================================================
$sqlV = "SELECT v.*, u.usuario as vendedor, c.nombre as cliente_nombre, 
        (SELECT SUM(d.cantidad * COALESCE(NULLIF(d.costo_historico,0), p.precio_costo, 0)) FROM detalle_ventas d LEFT JOIN productos p ON d.id_producto = p.id WHERE d.id_venta = v.id) as costo_total_venta
        FROM ventas v LEFT JOIN usuarios u ON v.id_usuario = u.id LEFT JOIN clientes c ON v.id_cliente = c.id WHERE v.fecha $rango_sql AND v.estado = 'completada'";
if ($id_usuario) $sqlV .= " AND v.id_usuario = " . $id_usuario;
if ($metodo) $sqlV .= " AND v.metodo_pago = " . $conexion->quote($metodo);
if (!empty($buscar)) { $sqlV .= is_numeric($buscar) ? " AND v.id = " . intval($buscar) : " AND c.nombre LIKE " . $conexion->quote("%$buscar%"); }
$ventas = obtenerDatoSeguro($conexion, $sqlV);

$resG = obtenerDatoSeguro($conexion, "SELECT categoria, SUM(monto) as total FROM gastos WHERE fecha $rango_sql GROUP BY categoria");
$gastos_operativos = 0; $retiros_dueno = 0; $detalle_gastos = [];
foreach($resG as $rg) {
    if(in_array(strtoupper($rg['categoria']), ['RETIRO', 'DIVIDENDOS', 'RETIRO DUEÑO'])) $retiros_dueno += $rg['total'];
    else { $gastos_operativos += $rg['total']; $detalle_gastos[$rg['categoria']] = $rg['total']; }
}

$resDev = obtenerDatoSeguro($conexion, "SELECT COUNT(*) as c, SUM(monto_devuelto) as tot FROM devoluciones WHERE fecha $rango_sql", true);
$resMer = obtenerDatoSeguro($conexion, "SELECT COUNT(*) as c, SUM(m.cantidad * p.precio_costo) as tot FROM mermas m JOIN productos p ON m.id_producto = p.id WHERE m.fecha $rango_sql", true);

$resCajas = obtenerDatoSeguro($conexion, "SELECT COUNT(*) as aperturas, SUM(diferencia) as dif_total FROM cajas WHERE fecha_apertura $rango_sql", true);

$resInv = obtenerDatoSeguro($conexion, "SELECT COUNT(id) as total_sku, SUM(IF(activo=1,1,0)) as activos, SUM(IF(stock_actual<=stock_minimo,1,0)) as criticos, SUM(stock_actual*precio_costo) as cap_inv, SUM(stock_actual*precio_venta) as val_mer FROM productos", true);
$resProv = obtenerDatoSeguro($conexion, "SELECT COUNT(id) as c FROM proveedores", true);
$resCombos = obtenerDatoSeguro($conexion, "SELECT COUNT(id) as c FROM combos WHERE activo=1", true);
$resInflacion = obtenerDatoSeguro($conexion, "SELECT COUNT(*) as actualizaciones, SUM(porcentaje) as porcentaje_acumulado FROM actualizaciones_precios WHERE fecha $rango_sql", true);
$resCli = obtenerDatoSeguro($conexion, "SELECT COUNT(id) as total, SUM(puntos) as puntos_adeudados FROM clientes WHERE id>1", true);
$resCanjes = obtenerDatoSeguro($conexion, "SELECT COUNT(*) as cant, SUM(puntos_usados) as pts_consumidos FROM historial_puntos WHERE tipo='canje' AND fecha $rango_sql", true);
$resCupones = obtenerDatoSeguro($conexion, "SELECT COUNT(*) as cant, SUM(monto_descuento) as descuento_total FROM historial_cupones WHERE fecha_uso $rango_sql", true);
$resSorteos = obtenerDatoSeguro($conexion, "SELECT COUNT(*) as cant FROM sorteos WHERE fecha_creacion $rango_sql", true);

$ingresos_brutos = 0; $costo_cmv = 0;
foreach($ventas as $v) { $ingresos_brutos += $v['total']; $costo_cmv += $v['costo_total_venta']; }
$devoluciones = floatval($resDev['tot'] ?? 0);
$mermas = floatval($resMer['tot'] ?? 0);

$ventas_netas = $ingresos_brutos - $devoluciones;
$utilidad_bruta = $ventas_netas - $costo_cmv;
$ebitda = $utilidad_bruta - $gastos_operativos;
$ebit = $ebitda - $mermas;
$caja_libre = $ebit - $retiros_dueno;

$margen_bruto = ($ventas_netas > 0) ? ($utilidad_bruta / $ventas_netas) * 100 : 0;
$margen_neto = ($ventas_netas > 0) ? ($ebit / $ventas_netas) * 100 : 0;

// SCORE VANGUARD (Salud Financiera 0-1000)
$score = 500; 
if($margen_neto > 15) $score += 200; elseif($margen_neto > 5) $score += 100; else $score -= 150;
if($ingresos_brutos > 0 && ($devoluciones/$ingresos_brutos) < 0.02) $score += 100; else $score -= 50;
if($ingresos_brutos > 0 && ($mermas/$ingresos_brutos) < 0.02) $score += 100; else $score -= 50;
$score = max(300, min(999, $score));
$riesgo = ($score >= 800) ? 'BAJO RIESGO' : (($score >= 600) ? 'MODERADO' : 'ALTO RIESGO');
$color_score = ($score >= 800) ? '#198754' : (($score >= 600) ? '#ffc107' : '#dc3545');

// ESTADÍSTICAS FRONTEND & PDF
$stats_pagos = obtenerDatoSeguro($conexion, "SELECT metodo_pago, COUNT(id) as c, SUM(total) as monto FROM ventas WHERE fecha $rango_sql AND estado = 'completada' GROUP BY metodo_pago ORDER BY monto DESC");
$stats_horas = obtenerDatoSeguro($conexion, "SELECT HOUR(fecha) as hora, COUNT(id) as cant, SUM(total) as monto FROM ventas WHERE fecha $rango_sql AND estado = 'completada' GROUP BY HOUR(fecha) ORDER BY hora ASC");
$stats_clientes = obtenerDatoSeguro($conexion, "SELECT c.nombre, COUNT(v.id) as compras, SUM(v.total) as gastado FROM ventas v JOIN clientes c ON v.id_cliente = c.id WHERE v.fecha $rango_sql AND v.estado = 'completada' AND v.id_cliente > 1 GROUP BY c.id ORDER BY gastado DESC LIMIT 5");
$top_productos = obtenerDatoSeguro($conexion, "SELECT p.descripcion, SUM(d.cantidad) as cant, SUM(d.subtotal) as rec FROM detalle_ventas d JOIN ventas v ON d.id_venta = v.id JOIN productos p ON d.id_producto = p.id WHERE v.fecha $rango_sql AND v.estado = 'completada' GROUP BY p.id ORDER BY cant DESC LIMIT 5");
$usuarios_db = obtenerDatoSeguro($conexion, "SELECT * FROM usuarios ORDER BY usuario ASC");


// ==============================================================================
// RENDERIZADO DE GRÁFICOS PARA PDF (URLs de QuickChart seguras)
// ==============================================================================
// 1. Gauge Score
$chartScore = "https://quickchart.io/chart?c=".urlencode("{type:'radialGauge',data:{datasets:[{data:[".$score."],backgroundColor:'".$color_score."'}]},options:{domain:[300,1000],centerPercentage:80,centerArea:{text:'".$score."/1000',fontColor:'#333',fontSize:30}}}")."&w=300&h=200";

// 2. Doughnut Pagos
$lP=[]; $vP=[]; foreach($stats_pagos as $sp){ $lP[]="'".substr(strtoupper($sp['metodo_pago']?:'OTROS'),0,10)."'"; $vP[]=$sp['monto']; }
if(empty($lP)){$lP[]="'Sin Datos'";$vP[]=1;}
$chartPagos = "https://quickchart.io/chart?c=".urlencode("{type:'doughnut',data:{labels:[".implode(',',$lP)."],datasets:[{data:[".implode(',',$vP)."],backgroundColor:['#102A57','#198754','#ffc107','#dc3545','#0dcaf0']}]},options:{plugins:{legend:{position:'right'}}}}")."&w=400&h=250";

// 3. Doughnut Gastos
$lG=[]; $vG=[]; foreach($detalle_gastos as $cat=>$monto){ $lG[]="'".substr(strtoupper($cat),0,10)."'"; $vG[]=$monto; }
if(empty($lG)){$lG[]="'Sin Egresos'";$vG[]=1;}
$chartGastos = "https://quickchart.io/chart?c=".urlencode("{type:'doughnut',data:{labels:[".implode(',',$lG)."],datasets:[{data:[".implode(',',$vG)."],backgroundColor:['#dc3545','#fd7e14','#ffc107','#6c757d','#343a40']}]},options:{plugins:{legend:{position:'right'}}}}")."&w=400&h=250";

// 4. Barras Horas
$lH=[]; $vH=[]; foreach($stats_horas as $sh){ $lH[]="'".$sh['hora']."h'"; $vH[]=$sh['cant']; }
if(empty($lH)){$lH[]="'0h'";$vH[]=0;}
$chartHoras = "https://quickchart.io/chart?c=".urlencode("{type:'bar',data:{labels:[".implode(',',$lH)."],datasets:[{label:'Op.',data:[".implode(',',$vH)."],backgroundColor:'#102A57'}]},options:{plugins:{legend:{display:false}}}}")."&w=500&h=200";

$stmtFirma = @$conexion->query("SELECT id, nombre_completo FROM usuarios WHERE id_rol = 2 LIMIT 1");
$dueno_firma = $stmtFirma ? $stmtFirma->fetch(PDO::FETCH_ASSOC) : false;
$nombre_gerencia = $dueno_firma ? strtoupper($dueno_firma['nombre_completo']) : 'GERENCIA';
$ruta_firma_pdf = ($dueno_firma && file_exists("img/firmas/usuario_" . $dueno_firma['id'] . ".png")) ? "img/firmas/usuario_" . $dueno_firma['id'] . ".png" : "";

$report_id = "VZ-" . date('ym') . "-" . strtoupper(substr(md5(time()), 0, 6));

// HTML VISUAL
$conf_color_sis = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf_color_sis['color_barra_nav'] ?? '#102A57';
require_once 'includes/layout_header.php';
$titulo = "Reporte de Gestión"; $subtitulo = "Análisis detallado de rendimiento, costos y utilidades."; $icono_bg = "bi-graph-up-arrow";
$botones = [['texto' => 'DESCARGAS', 'link' => 'javascript:abrirModalDescargas()', 'icono' => 'bi-cloud-download-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-4 shadow-sm']];
$widgets = [
    ['label' => 'Ingresos Brutos', 'valor' => '$'.number_format($ingresos_brutos, 0, ',', '.'), 'icono' => 'bi-cash', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Egresos Totales', 'valor' => '$'.number_format($costo_cmv + $gastos_operativos, 0, ',', '.'), 'icono' => 'bi-arrow-down-circle', 'border' => 'border-danger', 'icon_bg' => 'bg-danger bg-opacity-20'],
    ['label' => 'Utilidad Neta', 'valor' => '$'.number_format($ebit, 0, ',', '.'), 'icono' => 'bi-graph-up', 'border' => 'border-success', 'icon_bg' => 'bg-success bg-opacity-20'],
    ['label' => 'Caja Real', 'valor' => '$'.number_format($caja_libre, 0, ',', '.'), 'icono' => 'bi-safe', 'border' => 'border-warning', 'icon_bg' => 'bg-warning bg-opacity-20']
];
include 'includes/componente_banner.php'; 
?>
<style>@media (min-width: 992px) { div:has(> .header-widget) { width: 25% !important; flex: 0 0 25% !important; max-width: 25% !important; } }</style>
<script>document.addEventListener("DOMContentLoaded", function() { document.querySelectorAll('.header-widget').forEach(function(widget) { let col = widget.closest('[class*="col-"]'); if (col) { col.classList.remove('col-md-4', 'col-lg-4'); col.classList.add('col-md-6', 'col-lg-3'); }});});</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<div class="container-fluid mt-n4 pb-5 px-2 px-md-4" style="position: relative; z-index: 20;">
    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border-left: 5px solid #ff9800 !important;">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="row g-2 align-items-center mb-0">
                <input type="hidden" name="f_inicio" value="<?php echo htmlspecialchars($inicio); ?>"><input type="hidden" name="f_fin" value="<?php echo htmlspecialchars($fin); ?>"><input type="hidden" name="id_usuario" value="<?php echo htmlspecialchars($id_usuario); ?>"><input type="hidden" name="metodo" value="<?php echo htmlspecialchars($metodo); ?>">
                <div class="col-md-8 col-12 text-center text-md-start">
                    <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador de Operaciones</h6>
                    <p class="small mb-0 opacity-75 d-none d-md-block">Ingrese el número de ticket o nombre del cliente para buscar.</p>
                </div>
                <div class="col-md-4 col-12 text-end">
                    <div class="input-group input-group-sm">
                        <input type="text" name="buscar" class="form-control border-0 fw-bold shadow-none" placeholder="N° Ticket o Cliente..." value="<?php echo htmlspecialchars($buscar); ?>">
                        <button class="btn btn-dark px-3 shadow-none border-0" type="submit"><i class="bi bi-arrow-right-circle-fill"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100">
                <input type="hidden" name="buscar" value="<?php echo htmlspecialchars($buscar); ?>">
                <div class="flex-grow-1" style="min-width: 120px;"><label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Desde</label><input type="date" name="f_inicio" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $inicio; ?>"></div>
                <div class="flex-grow-1" style="min-width: 120px;"><label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Hasta</label><input type="date" name="f_fin" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $fin; ?>"></div>
                <div class="flex-grow-1" style="min-width: 140px;"><label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Cajero</label><select name="id_usuario" class="form-select form-select-sm border-light-subtle fw-bold"><option value="">Todos</option><?php foreach($usuarios_db as $u): ?><option value="<?php echo $u['id']; ?>" <?php echo ($id_usuario == $u['id']) ? 'selected' : ''; ?>><?php echo strtoupper($u['usuario']); ?></option><?php endforeach; ?></select></div>
                <div class="flex-grow-1" style="min-width: 140px;"><label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Método</label><select name="metodo" class="form-select form-select-sm border-light-subtle fw-bold"><option value="">Todos</option><option value="Efectivo" <?php echo ($metodo == 'Efectivo')?'selected':''; ?>>Efectivo</option><option value="mercadopago" <?php echo ($metodo == 'mercadopago')?'selected':''; ?>>MercadoPago</option><option value="Transferencia" <?php echo ($metodo == 'Transferencia')?'selected':''; ?>>Transferencia</option><option value="Debito" <?php echo ($metodo == 'Debito')?'selected':''; ?>>Débito</option><option value="Credito" <?php echo ($metodo == 'Credito')?'selected':''; ?>>Crédito</option></select></div>
                <div class="flex-grow-0 d-flex gap-2 mt-2 mt-md-0">
                    <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;"><i class="bi bi-funnel-fill me-1"></i> FILTRAR</button>
                    <a href="?set_rango=hoy" class="btn btn-warning text-dark btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px; display: flex; align-items: center;">HOY</a>
                    <a href="reportes.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;"><i class="bi bi-trash3-fill me-1"></i> LIMPIAR</a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-list-task text-primary me-2"></i> Operaciones</h6>
                    <span class="badge bg-primary rounded-pill"><?php echo count($ventas); ?> Ops</span>
                </div>
                <div class="table-responsive">
                    <style>.tabla-movil-ajustada th:first-child,.tabla-movil-ajustada td:first-child{position:sticky;left:0;background-color:#fff;z-index:2;border-right:2px solid #e9ecef;}.tabla-movil-ajustada thead th:first-child{background-color:#f8f9fa;z-index:3;}</style>
                    <table class="table table-hover align-middle mb-0 tabla-movil-ajustada" id="tabla-export">
                        <thead class="bg-light small text-uppercase text-muted"><tr><th class="ps-4">Ticket/Fecha</th><th>Cliente/Pago</th><th class="d-none d-md-table-cell">Vendedor</th><th class="text-end">Venta</th><th class="text-end d-none d-md-table-cell">Margen</th><th class="text-end pe-4">Acción</th></tr></thead>
                        <tbody>
                            <?php if(!empty($ventas)): foreach($ventas as $v): $total_v = (float)($v['total'] ?? 0); $costo_v = (float)($v['costo_total_venta'] ?? 0); $margen_v = $total_v - $costo_v; ?>
                                <tr onclick="verDetalleOperacion(<?php echo $v['id']; ?>)" style="cursor:pointer;">
                                    <td class="ps-4"><div class="fw-bold text-primary">#<?php echo $v['id']; ?></div><small class="text-muted"><?php echo date('d/m/y H:i', strtotime($v['fecha'])); ?></small></td>
                                    <td><div class="fw-bold text-dark text-truncate" style="max-width: 150px;"><?php echo !empty($v['cliente_nombre']) ? $v['cliente_nombre'] : 'C. Final'; ?></div><span class="badge bg-light text-dark border"><?php echo $v['metodo_pago']; ?></span></td>
                                    <td class="d-none d-md-table-cell text-muted small"><?php echo $v['vendedor']; ?></td>
                                    <td class="text-end fw-bold text-dark">$<?php echo number_format($total_v, 0, ',', '.'); ?></td>
                                    <td class="text-end d-none d-md-table-cell"><div class="small fw-bold text-success">+$<?php echo number_format($margen_v, 0, ',', '.'); ?></div></td>
                                    <td class="text-end pe-4"><a href="ticket.php?id=<?php echo $v['id']; ?>" onclick="event.stopPropagation(); window.open(this.href, 'TicketView', 'width=350,height=600'); return false;" class="btn btn-sm btn-outline-dark fw-bold rounded-pill shadow-sm"><i class="bi bi-printer-fill"></i></a></td>
                                </tr>
                            <?php endforeach; else: ?><tr><td colspan="6" class="text-center py-5 text-muted">Sin operaciones.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white py-3 border-0"><h6 class="fw-bold mb-0 text-warning"><i class="bi bi-trophy-fill me-2"></i> Más Vendidos</h6></div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <?php foreach($top_productos as $tp): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-4 py-3"><span class="small text-uppercase fw-bold text-dark text-truncate pe-2"><?php echo $tp['descripcion']; ?></span><span class="badge bg-primary rounded-pill"><?php echo intval($tp['cant']); ?> un.</span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDescargas" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-dark text-white border-0 py-3">
                <h6 class="modal-title fw-bold"><i class="bi bi-archive-fill me-2"></i> Exportación</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <?php if($es_admin || in_array('finanzas_descargar_reportes', $permisos)): ?>
                    <button onclick="exportarExcelPro()" class="btn btn-success w-100 fw-bold py-3 mb-3 rounded-pill shadow-sm"><i class="bi bi-file-earmark-excel me-2 fs-5 align-middle"></i> EXPORTAR EXCEL</button>
                <?php endif; ?>
                <?php if($es_admin || in_array('finanzas_descargar_reportes', $permisos)): ?>
                    <button onclick="generarReporteVeraz()" class="btn border border-2 border-dark text-dark w-100 fw-bold py-3 rounded-pill shadow-sm" data-bs-dismiss="modal" style="background: #f8f9fa;">
                        <i class="bi bi-file-earmark-pdf me-2 fs-5 align-middle"></i> INFORME GERENCIAL
                    </button>
                    <small class="text-muted mt-2 d-block fw-bold" style="font-size: 0.7rem;">Auditoría Estilo Veraz</small>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    .pdf-page { background: white; width: 210mm; padding: 15px 30px; font-family: 'Helvetica', 'Arial', sans-serif; color: #333; box-sizing: border-box; }
    .pdf-header { border-bottom: 3px solid #102A57; padding-bottom: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-end; }
    .pdf-title { color: #102A57; font-size: 14px; text-transform: uppercase; border-left: 4px solid #102A57; padding-left: 10px; margin-bottom: 15px; font-weight: bold; background: #f8f9fa; padding: 5px 10px; }
    .pdf-table { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 15px; }
    .pdf-table th { background: #f4f4f4; border-bottom: 2px solid #ccc; padding: 6px; text-align: left; color: #555; }
    .pdf-table td { border-bottom: 1px solid #eee; padding: 6px; }
    .pdf-box { background: #f8f9fa; border: 1px solid #ddd; border-radius: 6px; padding: 15px; text-align: center; }
    .pdf-box h4 { margin: 0 0 5px 0; font-size: 10px; color: #666; text-transform: uppercase; }
    .pdf-box .val { font-size: 18px; font-weight: 900; color: #102A57; }
</style>

<div style="position: absolute; left: -9999px; top: 0; width: 210mm;">
    <div id="reporteVeraz" style="background: #e9ecef; padding: 20px;">
        
        <div class="pdf-page salto-pagina">
            <div class="pdf-header">
                <div style="width: 50%;">
                    <?php if(!empty($conf['logo_url'])): ?><img src="<?php echo $conf['logo_url']; ?>?v=<?php echo time(); ?>" style="max-height: 50px; margin-bottom: 5px;"><br><?php endif; ?>
                    <h2 style="margin: 0; color: #102A57; font-size: 18px; font-weight: 900;"><?php echo $nombre_negocio; ?></h2>
                    <div style="font-size: 9px; color: #666;">CUIT: <?php echo $conf['cuit'] ?? 'S/D'; ?> | Dir: <?php echo $conf['direccion_local'] ?? 'S/D'; ?></div>
                </div>
                <div style="width: 50%; text-align: right;">
                    <div style="background: #dc3545; color: white; display: inline-block; padding: 4px 8px; font-weight: bold; font-size: 11px; border-radius: 4px; margin-bottom: 5px;">AUDITORÍA Y RIESGO</div>
                    <div style="font-size: 9px; color: #444; line-height: 1.4;">
                        ID: <strong><?php echo $report_id; ?></strong><br>
                        PERÍODO: <strong><?php echo date('d/m/Y', strtotime($inicio)); ?> al <?php echo date('d/m/Y', strtotime($fin)); ?></strong><br>
                        EMISIÓN: <?php echo date('d/m/Y H:i'); ?>
                    </div>
                </div>
            </div>

            <div class="pdf-title">1. Salud Comercial (Vanguard Score)</div>
            <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 20px;">
                <div style="width: 40%; text-align: center;">
                    <img src="<?php echo $chartScore; ?>" style="max-width: 100%; height: 120px;">
                </div>
                <div style="width: 60%;">
                    <div style="font-size: 11px; text-align: justify; color: #555; background: #fdfdfd; padding: 15px; border-left: 4px solid <?php echo $color_score; ?>;">
                        <strong>Nivel de Riesgo: <span style="color: <?php echo $color_score; ?>;"><?php echo $riesgo; ?></span></strong><br>
                        El Score evalúa la eficiencia operativa. Un puntaje alto demuestra alta retención de ganancias (Margen Neto), bajo nivel de devoluciones y un control estricto sobre las mermas de inventario.
                    </div>
                </div>
            </div>

            <div class="pdf-title">2. Estado de Resultados (P&L)</div>
            <table class="pdf-table">
                <tr><th style="width: 50%;">CONCEPTO</th><th style="text-align: right;">IMPORTE</th><th style="text-align: right;">ANÁLISIS VERT.</th></tr>
                <tr><td>(+) INGRESOS BRUTOS POR VENTAS</td><td style="text-align: right; font-weight: bold;">$<?php echo number_format($ingresos_brutos, 2, ',', '.'); ?></td><td style="text-align: right;">-</td></tr>
                <tr><td style="color: #dc3545;">(-) Devoluciones y Reintegros</td><td style="text-align: right; color: #dc3545;">-$<?php echo number_format($devoluciones, 2, ',', '.'); ?></td><td style="text-align: right;"><?php echo number_format(($ingresos_brutos>0?$devoluciones/$ingresos_brutos*100:0),2); ?>%</td></tr>
                <tr style="background: #e8f4fd;"><td style="font-weight: bold; color: #102A57;">(=) VENTAS NETAS REALES</td><td style="text-align: right; font-weight: bold; color: #102A57;">$<?php echo number_format($ventas_netas, 2, ',', '.'); ?></td><td style="text-align: right; font-weight: bold; color: #102A57;">100.0%</td></tr>
                <tr><td style="color: #dc3545;">(-) Costo de Mercadería Vendida (CMV)</td><td style="text-align: right; color: #dc3545;">-$<?php echo number_format($costo_cmv, 2, ',', '.'); ?></td><td style="text-align: right;"><?php echo number_format(($ventas_netas>0?$costo_cmv/$ventas_netas*100:0),2); ?>%</td></tr>
                <tr><td style="font-weight: bold;">(=) UTILIDAD BRUTA</td><td style="text-align: right; font-weight: bold;">$<?php echo number_format($utilidad_bruta, 2, ',', '.'); ?></td><td style="text-align: right; font-weight: bold;"><?php echo number_format($margen_bruto, 2); ?>%</td></tr>
                <tr><td style="color: #dc3545;">(-) Gastos Operativos Totales</td><td style="text-align: right; color: #dc3545;">-$<?php echo number_format($gastos_operativos, 2, ',', '.'); ?></td><td style="text-align: right;"><?php echo number_format(($ventas_netas>0?$gastos_operativos/$ventas_netas*100:0),2); ?>%</td></tr>
                <tr><td style="color: #dc3545;">(-) Mermas y Pérdidas Físicas</td><td style="text-align: right; color: #dc3545;">-$<?php echo number_format($mermas, 2, ',', '.'); ?></td><td style="text-align: right;"><?php echo number_format(($ventas_netas>0?$mermas/$ventas_netas*100:0),2); ?>%</td></tr>
                <tr style="background: #e8fdf2;"><td style="font-weight: bold; color: #198754;">(=) UTILIDAD NETA OPERATIVA (EBIT)</td><td style="text-align: right; font-weight: bold; color: #198754;">$<?php echo number_format($ebit, 2, ',', '.'); ?></td><td style="text-align: right; font-weight: bold; color: #198754;"><?php echo number_format($margen_neto, 2); ?>%</td></tr>
                <tr><td style="color: #666; font-style: italic;">(-) Retiros Dueño / Socios</td><td style="text-align: right; color: #666;">-$<?php echo number_format($retiros_dueno, 2, ',', '.'); ?></td><td style="text-align: right;">-</td></tr>
                <tr style="background: #102A57; color: white;"><td style="font-weight: bold; font-size: 12px; padding: 8px;">(=) FLUJO DE CAJA LIBRE</td><td colspan="2" style="text-align: right; font-weight: bold; font-size: 14px; padding: 8px;">$<?php echo number_format($caja_libre, 2, ',', '.'); ?></td></tr>
            </table>
        </div>

        <div class="pdf-page salto-pagina">
            <div class="pdf-header">
                <div style="width: 50%;">
                    <?php if(!empty($conf['logo_url'])): ?><img src="<?php echo $conf['logo_url']; ?>?v=<?php echo time(); ?>" style="max-height: 50px; margin-bottom: 5px;"><br><?php endif; ?>
                    <h2 style="margin: 0; color: #102A57; font-size: 18px; font-weight: 900;"><?php echo $nombre_negocio; ?></h2>
                </div>
                <div style="width: 50%; text-align: right; font-size: 9px; color: #444;">ID: <strong><?php echo $report_id; ?></strong><br>Página 2</div>
            </div>

            <div class="pdf-title">3. Análisis de Liquidez y Pagos</div>
            <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 20px;">
                <div style="width: 50%;">
                    <table class="pdf-table">
                        <tr><th>MÉTODO DE PAGO</th><th style="text-align: center;">TRX</th><th style="text-align: right;">MONTO ($)</th></tr>
                        <?php foreach($stats_pagos as $sp): ?>
                        <tr>
                            <td><strong><?php echo strtoupper($sp['metodo_pago'] ?: 'OTROS'); ?></strong></td>
                            <td style="text-align: center;"><?php echo $sp['c']; ?></td>
                            <td style="text-align: right;">$<?php echo number_format($sp['monto'], 2, ',', '.'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <div style="width: 50%; text-align: center;">
                    <img src="<?php echo $chartPagos; ?>" style="max-width: 100%; height: 160px;">
                </div>
            </div>

            <div class="pdf-title">4. Desglose de Gastos Operativos</div>
            <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 20px;">
                <div style="width: 50%;">
                    <table class="pdf-table">
                        <tr><th>CATEGORÍA DE GASTO</th><th style="text-align: right;">MONTO EROGADO ($)</th></tr>
                        <?php foreach($detalle_gastos as $cat => $monto): ?>
                        <tr><td><?php echo strtoupper($cat); ?></td><td style="text-align: right;">$<?php echo number_format($monto, 2, ',', '.'); ?></td></tr>
                        <?php endforeach; if(empty($detalle_gastos)): ?><tr><td colspan="2" style="text-align:center;">Sin egresos registrados</td></tr><?php endif; ?>
                    </table>
                </div>
                <div style="width: 50%; text-align: center;">
                    <img src="<?php echo $chartGastos; ?>" style="max-width: 100%; height: 160px;">
                </div>
            </div>

            <div class="pdf-title">5. Auditoría de Cajas (Tesorería)</div>
            <div style="display: flex; gap: 15px;">
                <div style="flex: 1;" class="pdf-box">
                    <h4>Aperturas / Turnos</h4>
                    <div class="val"><?php echo number_format($resCajas['aperturas']??0, 0); ?></div>
                </div>
                <div style="flex: 1;" class="pdf-box">
                    <h4>Descuadre Total (Faltantes)</h4>
                    <div class="val" style="color: <?php echo (($resCajas['dif_total']??0)<0)?'#dc3545':'#198754'; ?>;">$<?php echo number_format($resCajas['dif_total']??0, 2, ',', '.'); ?></div>
                </div>
            </div>
        </div>

        <div class="pdf-page salto-pagina">
            <div class="pdf-header">
                <div style="width: 50%;">
                    <?php if(!empty($conf['logo_url'])): ?><img src="<?php echo $conf['logo_url']; ?>?v=<?php echo time(); ?>" style="max-height: 50px; margin-bottom: 5px;"><br><?php endif; ?>
                    <h2 style="margin: 0; color: #102A57; font-size: 18px; font-weight: 900;"><?php echo $nombre_negocio; ?></h2>
                </div>
                <div style="width: 50%; text-align: right; font-size: 9px; color: #444;">ID: <strong><?php echo $report_id; ?></strong><br>Página 3</div>
            </div>

            <div class="pdf-title">6. Marketing, Clientes y Fidelización</div>
            <table class="pdf-table">
                <tr><th style="width: 70%;">INDICADOR COMERCIAL</th><th style="text-align: right;">VALOR</th></tr>
                <tr><td><strong>Cartera Activa:</strong> Clientes registrados en sistema</td><td style="text-align: right;"><?php echo number_format($resCli['total']??0, 0, ',', '.'); ?> personas</td></tr>
                <tr><td style="color: #dc3545;"><strong>Riesgo Pasivo:</strong> Puntos adeudados a clientes (No canjeados)</td><td style="text-align: right; color: #dc3545; font-weight: bold;"><?php echo number_format($resCli['puntos_adeudados']??0, 0, ',', '.'); ?> pts</td></tr>
                <tr><td><strong>Destrucción de Pasivo:</strong> Puntos canjeados en el período</td><td style="text-align: right;"><?php echo number_format($resCanjes['pts_consumidos']??0, 0, ',', '.'); ?> pts</td></tr>
                <tr><td><strong>Promociones:</strong> Uso de cupones de descuento</td><td style="text-align: right;"><?php echo number_format($resCupones['cant']??0, 0, ',', '.'); ?> usos</td></tr>
                <tr><td style="color: #dc3545;"><strong>Costo Promocional:</strong> Dinero cedido en descuentos</td><td style="text-align: right; color: #dc3545;">-$<?php echo number_format($resCupones['descuento_total']??0, 2, ',', '.'); ?></td></tr>
            </table>

            <div class="pdf-title">7. Auditoría de Inventario y Activos</div>
            <div style="display: flex; gap: 15px; margin-bottom: 20px;">
                <div style="flex: 1;" class="pdf-box">
                    <h4>Capital Inmovilizado</h4>
                    <div class="val" style="color: #dc3545;">$<?php echo number_format($resInv['cap_inv']??0, 2, ',', '.'); ?></div>
                </div>
                <div style="flex: 1;" class="pdf-box">
                    <h4>Valor de Mercado</h4>
                    <div class="val" style="color: #198754;">$<?php echo number_format($resInv['val_mer']??0, 2, ',', '.'); ?></div>
                </div>
                <div style="flex: 1;" class="pdf-box">
                    <h4>Lucro Cesante</h4>
                    <div class="val">$<?php echo number_format(($resInv['val_mer']??0)-($resInv['cap_inv']??0), 2, ',', '.'); ?></div>
                </div>
            </div>
            
            <table class="pdf-table">
                <tr><th style="width: 70%;">INDICADOR LOGÍSTICO</th><th style="text-align: right;">VALOR</th></tr>
                <tr><td><strong>SKUs Totales:</strong> Artículos distintos en base de datos</td><td style="text-align: right;"><?php echo number_format($resInv['total_sku']??0, 0, ',', '.'); ?></td></tr>
                <tr><td style="color: #dc3545;"><strong>Alerta Logística:</strong> Productos con stock crítico o cero</td><td style="text-align: right; color: #dc3545; font-weight: bold;"><?php echo number_format($resInv['criticos']??0, 0, ',', '.'); ?> ítems</td></tr>
                <tr><td><strong>Red Comercial:</strong> Proveedores activos registrados</td><td style="text-align: right;"><?php echo number_format($resProv['c']??0, 0, ',', '.'); ?> prov.</td></tr>
                <tr><td><strong>Riesgo Inflacionario:</strong> Actualizaciones masivas de precios ejecutadas</td><td style="text-align: right; font-weight: bold;"><?php echo number_format($resInflacion['actualizaciones']??0, 0, ',', '.'); ?> eventos</td></tr>
            </table>
        </div>

        <div class="pdf-page">
            <div class="pdf-header">
                <div style="width: 50%;">
                    <?php if(!empty($conf['logo_url'])): ?><img src="<?php echo $conf['logo_url']; ?>?v=<?php echo time(); ?>" style="max-height: 50px; margin-bottom: 5px;"><br><?php endif; ?>
                    <h2 style="margin: 0; color: #102A57; font-size: 18px; font-weight: 900;"><?php echo $nombre_negocio; ?></h2>
                </div>
                <div style="width: 50%; text-align: right; font-size: 9px; color: #444;">ID: <strong><?php echo $report_id; ?></strong><br>Página 4</div>
            </div>

            <div class="pdf-title">8. Rendimiento y Comportamiento (Ventas)</div>
            <div style="text-align: center; margin-bottom: 20px; border: 1px solid #ddd; padding: 10px; border-radius: 6px;">
                <div style="font-size: 10px; font-weight: bold; color: #102A57; margin-bottom: 5px;">MAPA DE CALOR: OPERACIONES POR HORA</div>
                <img src="<?php echo $chartHoras; ?>" style="max-width: 100%; height: 160px;">
            </div>

            <div style="display: flex; gap: 20px; margin-bottom: 30px;">
                <div style="width: 50%;">
                    <div style="font-size: 10px; font-weight: bold; color: #102A57; margin-bottom: 5px;">TOP 5 PRODUCTOS</div>
                    <table class="pdf-table">
                        <tr><th>ARTÍCULO</th><th style="text-align: right;">CANT</th></tr>
                        <?php foreach($top_productos as $tp): ?>
                        <tr><td><?php echo substr($tp['descripcion'], 0, 30); ?></td><td style="text-align: right; font-weight: bold;"><?php echo intval($tp['cant']); ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <div style="width: 50%;">
                    <div style="font-size: 10px; font-weight: bold; color: #102A57; margin-bottom: 5px;">TOP 5 CLIENTES (LTV)</div>
                    <table class="pdf-table">
                        <tr><th>CLIENTE</th><th style="text-align: right;">APORTE</th></tr>
                        <?php foreach($stats_clientes as $sc): ?>
                        <tr><td><?php echo substr($sc['nombre'], 0, 25); ?></td><td style="text-align: right; font-weight: bold; color: #198754;">$<?php echo number_format($sc['gastado'], 0, ',', '.'); ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

            <div style="margin-top: 40px; padding-top: 15px; border-top: 2px solid #102A57; display: flex; justify-content: space-between; align-items: flex-end;">
                <div style="width: 50%; font-size: 9px; color: #555; text-align: justify;">
                    <strong>CERTIFICACIÓN DE INTEGRIDAD:</strong><br>
                    La información de este reporte ha sido extraída sin alteraciones de la base de datos Vanguard Pro. Los Score y KPIs son calculados por algoritmos internos. Documento confidencial.
                </div>
                <div style="width: 25%; text-align: center;">
                    <?php if(!empty($ruta_firma_pdf) && file_exists($ruta_firma_pdf)): ?><img src="<?php echo $ruta_firma_pdf; ?>" style="max-height: 50px; margin-bottom: -5px;"><br><?php endif; ?>
                    <div style="border-top: 1px solid #000; width: 90%; margin: 0 auto; padding-top: 2px;"></div>
                    <div style="font-size: 10px; font-weight: bold;"><?php echo $nombre_gerencia; ?></div>
                    <div style="font-size: 8px; color: #666;">DIRECCIÓN GENERAL</div>
                </div>
                <div style="width: 20%; text-align: right;">
                    <img src="<?php echo $qr_url; ?>" style="width: 75px; height: 75px; border: 1px solid #ccc; padding: 2px;">
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function abrirModalDescargas() { new bootstrap.Modal(document.getElementById('modalDescargas')).show(); }

function exportarExcelPro() {
    let table = document.getElementById("tabla-export"); let rows = Array.from(table.querySelectorAll("tr")); let csvContent = "\uFEFF"; 
    rows.forEach(row => {
        let cols = Array.from(row.querySelectorAll("th, td")).map((cell, index) => {
            if(index === 5) return ""; let text = cell.innerText.replace(/\./g, "").replace("$", "").replace("TICKET", "").trim(); return `"${text}"`;
        });
        csvContent += cols.join(";") + "\r\n";
    });
    let blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" }); let link = document.createElement("a"); link.href = URL.createObjectURL(blob); link.download = "Reporte_Operaciones.csv"; link.click();
}

function generarReporteVeraz() {
    const elemento = document.getElementById('reporteVeraz');
    const opt = { 
        margin: 0, 
        filename: 'Auditoria_Vanguard_Pro.pdf', 
        image: { type: 'jpeg', quality: 0.98 }, 
        html2canvas: { scale: 2, useCORS: true, scrollY: 0 }, 
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' } 
    };
    Swal.fire({ title: 'Ejecutando Auditoría...', text: 'Generando gráficos y KPIs...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
    html2pdf().set(opt).from(elemento).save().then(() => { Swal.fire({ icon: 'success', title: 'Completado', text: 'El reporte nivel Big Four ha sido descargado.', confirmButtonColor: '#102A57' }); });
}
//... (Mantené acá las funciones mandarWAOperacion y mandarMailOperacion que tenías, no las toqué) ...
function verDetalleOperacion(id) {
    Swal.fire({ title: 'Cargando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
    fetch(`reportes.php?ajax_get_ticket=${id}`).then(r => r.json()).then(data => {
        const v = data.venta; 
        const conf = data.conf; 
        let itemsHtml = '';
        data.detalles.forEach(d => {
            itemsHtml += `<div style="display:flex; justify-content:space-between; margin-bottom: 5px;">
                <span>${parseFloat(d.cantidad)}x ${d.descripcion.substring(0, 22)}</span>
                <b>$${parseFloat(d.subtotal).toFixed(2)}</b>
            </div>`;
        });

        let montoF = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(v.total);
        let linkPdfPublico = window.location.origin + "/ticket_operacion_pdf.php?id=" + v.id;
        let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdfPublico);
        let logoHtml = conf.logo_url ? `<img src="${conf.logo_url}?v=${Date.now()}" style="max-height: 50px; margin-bottom: 10px;">` : '';
        
        let nombreOpe = v.nombre_completo ? v.nombre_completo : v.vendedor;
        let rolOpe = v.nombre_rol ? v.nombre_rol : 'VENDEDOR';
        let aclaracionOp = (nombreOpe + " | " + rolOpe).toUpperCase();
        let rutaFirmaOp = v.id_usuario ? `img/firmas/usuario_${v.id_usuario}.png?v=${Date.now()}` : `img/firmas/firma_admin.png?v=${Date.now()}`;
        let firmaHtml = `<img src="${rutaFirmaOp}" style="max-height: 80px; margin-bottom: -25px;" onerror="this.style.display='none'"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${aclaracionOp}</small>`;

        const html = `
            <div id="printTicket" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
                <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                    ${logoHtml}
                    <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${conf.nombre_negocio}</h4>
                    <small style="color: #666;">${conf.direccion_local}</small>
                </div>
                <div style="text-align: center; margin-bottom: 15px;">
                    <h5 style="font-weight: 900; color: #102A57; letter-spacing: 1px; margin:0;">COMPROBANTE DE OPERACIÓN</h5>
                    <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">TICKET #${v.id}</span>
                </div>
                <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                    <div style="margin-bottom: 4px;"><strong>FECHA:</strong> ${v.fecha}</div>
                    <div style="margin-bottom: 4px;"><strong>CLIENTE:</strong> ${v.cliente || 'C. Final'}</div>
                    <div style="margin-bottom: 4px;"><strong>VENDEDOR:</strong> ${aclaracionOp}</div>
                    <div><strong>MÉTODO:</strong> ${v.metodo_pago}</div>
                </div>
                <div style="margin-bottom: 15px; font-size: 13px;">
                    <strong style="border-bottom: 1px solid #ccc; display:block; margin-bottom:5px;">DETALLE DE COMPRA:</strong>
                    ${itemsHtml}
                </div>
                <div style="background: #102A5710; border-left: 4px solid #102A57; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                    <span style="font-size: 1.1em; font-weight:800;">TOTAL:</span>
                    <span style="font-size: 1.15em; font-weight:900; color: #102A57;">${montoF}</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
                    <div style="width: 45%; text-align: center;">${firmaHtml}</div>
                    <div style="width: 45%; text-align: center;">
                        <a href="${linkPdfPublico}" target="_blank"><img src="${qrUrl}" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;"></a>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3 no-print">
                <button class="btn btn-sm btn-outline-dark fw-bold" onclick="window.open('${linkPdfPublico}', '_blank')"><i class="bi bi-file-pdf"></i> PDF</button>
                <button class="btn btn-sm btn-success fw-bold" onclick="mandarWAOperacion('${v.id}', '${montoF}', '${linkPdfPublico}')"><i class="bi bi-whatsapp"></i> WA</button>
                <button class="btn btn-sm btn-primary fw-bold" onclick="mandarMailOperacion(${v.id})"><i class="bi bi-envelope"></i> EMAIL</button>
            </div>
        `;
        Swal.fire({ html: html, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });
    });
}
function mandarWAOperacion(idTicket, monto, link) {
    let msj = `¡Hola! Aquí tenés el comprobante de tu compra por *${monto}* (Ref: Ticket #${idTicket}).\n📄 Ver ticket oficial: ${link}`;
    window.open(`https://wa.me/?text=${encodeURIComponent(msj)}`, '_blank');
}
function mandarMailOperacion(id) {
    Swal.fire({ title: 'Enviar Comprobante', text: 'Ingrese el correo electrónico del cliente:', input: 'email', showCancelButton: true, confirmButtonText: 'ENVIAR AHORA', cancelButtonText: 'CANCELAR', confirmButtonColor: '#102A57' }).then((r) => {
        if(r.isConfirmed && r.value) {
            Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            let fData = new FormData(); fData.append('id', id); fData.append('email', r.value);
            fetch('acciones/enviar_email_operacion.php', { method: 'POST', body: fData }).then(res => res.json()).then(d => { Swal.fire(d.status === 'success' ? 'Comprobante Enviado' : 'Error al enviar', d.msg || '', d.status === 'success' ? 'success' : 'error'); });
        }
    });
}
</script>
<?php include 'includes/layout_footer.php'; ?>