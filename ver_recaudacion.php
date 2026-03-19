<?php
// ver_recaudacion.php - TABLERO DE RECAUDACIÓN PREMIUM ESTANDARIZADO (CON TICKETS)
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$rol = $_SESSION['rol'] ?? 3;
$es_admin = ($rol <= 2);

if (!$es_admin) { header("Location: dashboard.php"); exit; }

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);

// 1. RECEPCIÓN DE FILTROS
$desde = $_GET['desde'] ?? date('Y-m-d');
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$f_usu = $_GET['id_usuario'] ?? '';
$buscar = trim($_GET['buscar'] ?? '');

// ============================================================================
// 2. MOTOR DE DATOS (CONSULTAS A LA BASE DE DATOS)
// ============================================================================

// A. VENTAS DE PRODUCTOS (Trae Cliente, Cajero y Rol)
$condV = ["DATE(v.fecha) >= ?", "DATE(v.fecha) <= ?", "v.estado = 'completada'"];
$paramsV = [$desde, $hasta];
if($f_usu !== '') { $condV[] = "v.id_usuario = ?"; $paramsV[] = $f_usu; }
if(!empty($buscar)) { 
    $condV[] = "(v.codigo_ticket LIKE ? OR v.metodo_pago LIKE ? OR c.nombre LIKE ?)"; 
    array_push($paramsV, "%$buscar%", "%$buscar%", "%$buscar%"); 
}

$stmtV = $conexion->prepare("SELECT v.*, u.nombre_completo as cajero, u.id as id_op, r.nombre as nombre_rol, c.nombre as cliente 
                             FROM ventas v 
                             LEFT JOIN usuarios u ON v.id_usuario = u.id 
                             LEFT JOIN roles r ON u.id_rol = r.id 
                             LEFT JOIN clientes c ON v.id_cliente = c.id 
                             WHERE " . implode(" AND ", $condV) . " ORDER BY v.fecha DESC");
$stmtV->execute($paramsV);
$ventas = $stmtV->fetchAll(PDO::FETCH_ASSOC);

$ingresoVentas = 0; $metodos_pago = [];
foreach($ventas as $v) {
    $ingresoVentas += (float)$v['total'];
    $m = !empty($v['metodo_pago']) ? strtoupper($v['metodo_pago']) : 'EFECTIVO';
    if(!isset($metodos_pago[$m])) $metodos_pago[$m] = 0;
    $metodos_pago[$m] += (float)$v['total'];
}

// B. SORTEOS
$condS = ["DATE(st.fecha_compra) >= ?", "DATE(st.fecha_compra) <= ?", "st.pagado = 1"];
$paramsS = [$desde, $hasta];
if(!empty($buscar)) { $condS[] = "(s.titulo LIKE ? OR c.nombre LIKE ? OR st.numero_ticket LIKE ?)"; array_push($paramsS, "%$buscar%", "%$buscar%", "%$buscar%"); }
$stmtS = $conexion->prepare("SELECT st.id, st.fecha_compra, st.numero_ticket, s.titulo, s.precio_ticket, c.nombre as cliente FROM sorteo_tickets st JOIN sorteos s ON st.id_sorteo = s.id LEFT JOIN clientes c ON st.id_cliente = c.id WHERE " . implode(" AND ", $condS) . " ORDER BY st.fecha_compra DESC");
$stmtS->execute($paramsS);
$tickets_sorteo = $stmtS->fetchAll(PDO::FETCH_ASSOC);

$ingresoSorteos = 0; foreach($tickets_sorteo as $t) { $ingresoSorteos += (float)$t['precio_ticket']; }

// C. GASTOS
$condG = ["DATE(g.fecha) >= ?", "DATE(g.fecha) <= ?"];
$paramsG = [$desde, $hasta];
if($f_usu !== '') { $condG[] = "g.id_usuario = ?"; $paramsG[] = $f_usu; }
if(!empty($buscar)) { $condG[] = "(g.descripcion LIKE ? OR g.categoria LIKE ?)"; array_push($paramsG, "%$buscar%", "%$buscar%"); }

$stmtG = $conexion->prepare("SELECT g.id, g.descripcion, g.monto, g.categoria, g.fecha, u.usuario, u.nombre_completo, r.nombre as nombre_rol, u.id as id_op FROM gastos g JOIN usuarios u ON g.id_usuario = u.id JOIN roles r ON u.id_rol = r.id WHERE " . implode(" AND ", $condG));
$stmtG->execute($paramsG);
$gastos = $stmtG->fetchAll(PDO::FETCH_ASSOC);

$totalGastos = 0; foreach($gastos as &$g) { 
    $totalGastos += (float)$g['monto'];
    $g['info_extra_titulo'] = ''; $g['info_extra_nombre'] = '';
}

// D. MERMAS
$stmtM = $conexion->prepare("SELECT m.id, m.cantidad, m.motivo as descripcion, p.precio_costo, m.fecha, p.descripcion as producto_nom, u.usuario, u.nombre_completo, r.nombre as nombre_rol, u.id as id_op FROM mermas m JOIN productos p ON m.id_producto = p.id JOIN usuarios u ON m.id_usuario = u.id JOIN roles r ON u.id_rol = r.id WHERE DATE(m.fecha) BETWEEN ? AND ?");
$stmtM->execute([$desde, $hasta]);
$mermas = $stmtM->fetchAll(PDO::FETCH_ASSOC);

$totalMermas = 0; foreach($mermas as &$m) { 
    $m['monto'] = ((float)$m['cantidad'] * (float)$m['precio_costo']);
    $totalMermas += $m['monto']; 
}

// CÁLCULOS FINALES
$recaudacionTotal = $ingresoVentas + $ingresoSorteos;
$totalEgresos = $totalGastos + $totalMermas;
$ingresoNeto = $recaudacionTotal - $totalEgresos;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte de Recaudación</title>
</head>
<body class="bg-light">
    
    <?php include 'includes/layout_header.php'; ?>

    <?php
    $titulo = "Tablero de Recaudación";
    $subtitulo = "Análisis de ingresos por Productos y Sorteos.";
    $icono_bg = "bi-cash-coin";

    $query_filtros = !empty($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : "desde=$desde&hasta=$hasta";
    $botones = [
        ['texto' => 'Reporte PDF', 'link' => "reporte_recaudacion.php?$query_filtros", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-3 px-md-4 py-2 shadow-sm', 'target' => '_blank']
    ];

    $widgets = [
        ['label' => 'Caja Neta', 'valor' => '$'.number_format($ingresoNeto, 0, ',', '.'), 'icono' => 'bi-piggy-bank', 'border' => 'border-success', 'icon_bg' => 'bg-success bg-opacity-20'],
        ['label' => 'Total Bruto', 'valor' => '$'.number_format($recaudacionTotal, 0, ',', '.'), 'icono' => 'bi-wallet2', 'icon_bg' => 'bg-white bg-opacity-10'],
        ['label' => 'Total Egresos', 'valor' => '-$'.number_format($totalEgresos, 0, ',', '.'), 'icono' => 'bi-graph-down-arrow', 'border' => 'border-danger', 'icon_bg' => 'bg-danger bg-opacity-20']
    ];

    include 'includes/componente_banner.php'; 
    ?>

    <div class="container-fluid container-md pb-5 mt-n4 px-2 px-md-3" style="position: relative; z-index: 20;">

        <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border-left: 5px solid #ff9800 !important;">
            <div class="card-body p-2 p-md-3">
                <form method="GET" class="row g-2 align-items-center mb-0">
                    <input type="hidden" name="desde" value="<?php echo htmlspecialchars($desde); ?>">
                    <input type="hidden" name="hasta" value="<?php echo htmlspecialchars($hasta); ?>">
                    <input type="hidden" name="id_usuario" value="<?php echo htmlspecialchars($f_usu); ?>">
                    
                    <div class="col-md-8 col-12 text-center text-md-start">
                        <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador Rápido</h6>
                        <p class="small mb-0 opacity-75 d-none d-md-block">Busque por ticket, método de pago, cliente o título de sorteo.</p>
                    </div>
                    <div class="col-md-4 col-12 text-end mt-2 mt-md-0">
                        <div class="input-group input-group-sm">
                            <input type="text" name="buscar" class="form-control border-0 fw-bold shadow-none" placeholder="Buscar..." value="<?php echo htmlspecialchars($buscar); ?>">
                            <button class="btn btn-dark px-3 shadow-none border-0" type="submit"><i class="bi bi-arrow-right-circle-fill"></i></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-3">
                <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100">
                    <input type="hidden" name="buscar" value="<?php echo htmlspecialchars($buscar); ?>">
                    <div class="flex-grow-1" style="min-width: 120px;">
                        <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Desde</label>
                        <input type="date" name="desde" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $desde; ?>">
                    </div>
                    <div class="flex-grow-1" style="min-width: 120px;">
                        <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Hasta</label>
                        <input type="date" name="hasta" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $hasta; ?>">
                    </div>
                    <div class="flex-grow-1" style="min-width: 140px;">
                        <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Operador</label>
                        <select name="id_usuario" class="form-select form-select-sm border-light-subtle fw-bold">
                            <option value="">Todos</option>
                            <?php 
                            $usuarios_lista = $conexion->query("SELECT id, usuario FROM usuarios ORDER BY usuario ASC")->fetchAll(PDO::FETCH_ASSOC);
                            foreach($usuarios_lista as $usu): ?>
                                <option value="<?php echo $usu['id']; ?>" <?php echo ($f_usu == $usu['id']) ? 'selected' : ''; ?>><?php echo strtoupper($usu['usuario']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex-grow-0 d-flex gap-2 mt-2 mt-md-0">
                        <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;"><i class="bi bi-funnel-fill me-1"></i> FILTRAR</button>
                        <a href="ver_recaudacion.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;"><i class="bi bi-trash3-fill me-1"></i> LIMPIAR</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="alert py-2 small mb-4 text-center fw-bold border-0 shadow-sm rounded-3" style="background-color: #e9f2ff; color: #102A57;">
            <i class="bi bi-hand-index-thumb-fill me-1"></i> Toca o haz clic en los registros para ver sus comprobantes y tickets
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm p-3 border-start border-success border-4 bg-white">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center">
                        <div class="text-center text-md-start mb-2 mb-md-0">
                            <small class="text-muted fw-bold d-block text-uppercase"><i class="bi bi-check-circle-fill text-success me-1"></i> CAJA NETA (LO QUE QUEDA LÍMPIO)</small>
                            <span class="h1 fw-bold text-dark m-0">$<?php echo number_format($ingresoNeto, 2, ',', '.'); ?></span>
                        </div>
                        <div class="text-center text-md-end p-2 bg-light rounded-3 border">
                            <div class="small fw-bold text-success">Ingresos Brutos: +$<?php echo number_format($recaudacionTotal, 2, ',', '.'); ?></div>
                            <div class="small fw-bold text-danger">Total Egresos: -$<?php echo number_format($totalEgresos, 2, ',', '.'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card border-0 shadow-sm p-3 h-100 border-start border-primary border-4">
                    <small class="text-muted fw-bold d-block text-uppercase">Ventas de Productos</small>
                    <span class="h3 fw-bold text-primary">$<?php echo number_format($ingresoVentas, 2, ',', '.'); ?></span>
                    <small class="text-muted"><?php echo count($ventas); ?> tickets emitidos</small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm p-3 h-100 border-start border-info border-4">
                    <small class="text-muted fw-bold d-block text-uppercase">Ingresos por Sorteos</small>
                    <span class="h3 fw-bold text-info">$<?php echo number_format($ingresoSorteos, 2, ',', '.'); ?></span>
                    <small class="text-muted"><?php echo count($tickets_sorteo); ?> tickets pagados</small>
                </div>
            </div>
        </div>

        <div class="accordion shadow-sm rounded-4 overflow-hidden" id="acordeonRecaudacion">
            
            <div class="accordion-item border-0 border-bottom">
                <h2 class="accordion-header">
                    <button class="accordion-button fw-bold text-primary" type="button" data-bs-toggle="collapse" data-bs-target="#colVentas">
                        <i class="bi bi-shop me-2"></i> Detalle de Ventas ($<?php echo number_format($ingresoVentas, 2, ',', '.'); ?>)
                    </button>
                </h2>
                <div id="colVentas" class="accordion-collapse collapse show" data-bs-parent="#acordeonRecaudacion">
                    <div class="accordion-body p-0">
                        <div class="p-3 bg-light border-bottom d-flex gap-3 flex-wrap">
                            <strong class="text-muted me-2">Medios de Pago:</strong>
                            <?php if(empty($metodos_pago)): echo "<span class='text-muted'>Sin ventas</span>"; endif; ?>
                            <?php foreach($metodos_pago as $mp => $monto): ?>
                                <span class="badge bg-secondary fs-6"><?php echo $mp; ?>: $<?php echo number_format($monto, 2, ',', '.'); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small text-muted text-uppercase">
                                    <tr><th class="ps-4">Fecha</th><th>Ticket / Ref</th><th>Cliente</th><th>Método</th><th>Cajero</th><th class="text-end pe-4">Monto</th></tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($ventas)): ?>
                                        <tr><td colspan="6" class="text-center py-4 text-muted">No hay ventas en este rango.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($ventas as $v): 
                                            // Lógica asegurada para que NUNCA muestre S/N
                                            $num_ticket = !empty($v['codigo_ticket']) ? $v['codigo_ticket'] : str_pad($v['id'], 6, '0', STR_PAD_LEFT);
                                            $cliente_nom = !empty($v['cliente']) ? $v['cliente'] : 'Consumidor Final';
                                            
                                            $jsonV = htmlspecialchars(json_encode([
                                                'id' => $v['id'],
                                                'fecha' => $v['fecha'],
                                                'ticket' => $num_ticket,
                                                'cliente' => $cliente_nom,
                                                'metodo' => $v['metodo_pago'] ?: 'Efectivo',
                                                'cajero' => $v['cajero'] ?: 'Sistema',
                                                'id_op' => $v['id_op'],
                                                'nombre_rol' => $v['nombre_rol'],
                                                'total' => $v['total']
                                            ]), ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <tr style="cursor:pointer;" onclick="verTicketVenta(<?php echo $jsonV; ?>)">
                                            <td class="ps-4"><?php echo date('d/m/Y H:i', strtotime($v['fecha'])); ?></td>
                                            <td class="fw-bold text-primary">#<?php echo $num_ticket; ?></td>
                                            <td><?php echo htmlspecialchars($cliente_nom); ?></td>
                                            <td><span class="badge bg-light text-dark border"><?php echo $v['metodo_pago'] ?: 'Efectivo'; ?></span></td>
                                            <td><?php echo htmlspecialchars($v['cajero'] ?: 'Sistema'); ?></td>
                                            <td class="text-end pe-4 fw-bold text-success">+$<?php echo number_format($v['total'], 2, ',', '.'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="accordion-item border-0 border-bottom">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed fw-bold text-info" type="button" data-bs-toggle="collapse" data-bs-target="#colSorteos">
                        <i class="bi bi-ticket-perforated me-2"></i> Detalle de Sorteos ($<?php echo number_format($ingresoSorteos, 2, ',', '.'); ?>)
                    </button>
                </h2>
                <div id="colSorteos" class="accordion-collapse collapse" data-bs-parent="#acordeonRecaudacion">
                    <div class="accordion-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small text-muted text-uppercase">
                                    <tr><th class="ps-4">Fecha</th><th>Sorteo</th><th>Nro Ticket</th><th>Cliente</th><th class="text-end pe-4">Pagado</th></tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($tickets_sorteo)): ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted">No se vendieron tickets en este rango.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($tickets_sorteo as $ts): ?>
                                        <tr>
                                            <td class="ps-4"><?php echo date('d/m/Y H:i', strtotime($ts['fecha_compra'])); ?></td>
                                            <td class="fw-bold"><?php echo $ts['titulo']; ?></td>
                                            <td><span class="badge bg-dark">#<?php echo str_pad($ts['numero_ticket'], 3, '0', STR_PAD_LEFT); ?></span></td>
                                            <td><?php echo $ts['cliente'] ?: 'Anónimo'; ?></td>
                                            <td class="text-end pe-4 fw-bold text-success">+$<?php echo number_format($ts['precio_ticket'], 2, ',', '.'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="accordion-item border-0">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed fw-bold text-danger" type="button" data-bs-toggle="collapse" data-bs-target="#colGastos">
                        <i class="bi bi-graph-down-arrow me-2"></i> Detalle de Egresos y Gastos (-$<?php echo number_format($totalEgresos, 2, ',', '.'); ?>)
                    </button>
                </h2>
                <div id="colGastos" class="accordion-collapse collapse" data-bs-parent="#acordeonRecaudacion">
                    <div class="accordion-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small text-muted text-uppercase">
                                    <tr><th class="ps-4">Fecha</th><th>Categoría</th><th>Descripción</th><th class="text-end pe-4">Monto</th></tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($gastos) && empty($mermas)): ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">No hay gastos ni mermas en este rango.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($gastos as $g): 
                                            $jsonG = htmlspecialchars(json_encode($g), ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <tr style="cursor:pointer;" onclick="verTicketGasto(<?php echo $jsonG; ?>)">
                                            <td class="ps-4"><?php echo date('d/m/Y H:i', strtotime($g['fecha'])); ?></td>
                                            <td><span class="badge bg-light text-dark border"><?php echo $g['categoria']; ?></span></td>
                                            <td><?php echo htmlspecialchars($g['descripcion']); ?></td>
                                            <td class="text-end pe-4 fw-bold text-danger">-$<?php echo number_format($g['monto'], 2, ',', '.'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php foreach($mermas as $m): 
                                            $jsonM = htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <tr class="bg-danger bg-opacity-10" style="cursor:pointer;" onclick="verTicketMerma(<?php echo $jsonM; ?>)">
                                            <td class="ps-4"><?php echo date('d/m/Y H:i', strtotime($m['fecha'])); ?></td>
                                            <td><span class="badge bg-danger">Mermas (Stock)</span></td>
                                            <td><small>Costo de</small> <?php echo floatval($m['cantidad']); ?> unid. de <?php echo htmlspecialchars($m['producto_nom']); ?></td>
                                            <td class="text-end pe-4 fw-bold text-danger">-$<?php echo number_format($m['monto'], 2, ',', '.'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

<script>
<?php
// Preparamos las firmas de los usuarios para inyectarlas al modal
$firmas_base64 = [];
$usuarios_lista_js = $conexion->query("SELECT id FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
foreach($usuarios_lista_js as $u) {
    $r = "img/firmas/usuario_{$u['id']}.png";
    if(file_exists($r)) { $firmas_base64[$u['id']] = 'data:image/png;base64,' . base64_encode(file_get_contents($r)); }
}
?>
const miLocal = <?php echo json_encode($conf); ?>;
const firmasB64 = <?php echo json_encode($firmas_base64); ?>;

// ==========================================
// 1. TICKET EMERGENTE DE VENTA (ESTILO GASTOS)
// ==========================================
function verTicketVenta(venta) {
    let ts = Date.now();
    let montoF = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(venta.total);
    let fechaF = new Date(venta.fecha).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    
    // QR apunta al PDF Oficial
    let linkPdfPublico = window.location.origin + window.location.pathname.replace('ver_recaudacion.php', '') + "ticket_digital.php?id=" + venta.id;
    let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdfPublico);
    let logoHtml = miLocal.logo_url ? `<img src="${miLocal.logo_url}?v=${ts}" style="max-height: 50px; margin-bottom: 10px;">` : '';
    
    let aclaracionOp = venta.cajero ? (venta.cajero + " | " + (venta.nombre_rol || 'CAJERO')).toUpperCase() : 'OPERADOR AUTORIZADO';
    let firmaSeleccionada = (venta.id_op && firmasB64[venta.id_op]) ? firmasB64[venta.id_op] : '';
    let firmaHtml = firmaSeleccionada ? `<img src="${firmaSeleccionada}" style="max-height: 80px; margin-bottom: -25px;"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${aclaracionOp}</small>` : `<div style="border-top:1px solid #000; width:100%; margin-top:35px;"></div><small style="font-size:9px; font-weight:bold;">${aclaracionOp}</small>`;

    let ticketHTML = `
        <div id="printTicket" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
            <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                ${logoHtml}
                <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${miLocal.nombre_negocio}</h4>
                <small style="color: #666;">${miLocal.direccion_local}</small>
            </div>
            <div style="text-align: center; margin-bottom: 15px;">
                <h5 style="font-weight: 900; color: #198754; letter-spacing: 1px; margin:0;">COMPROBANTE DE VENTA</h5>
                <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">OP / TICKET #${venta.ticket}</span>
            </div>
            <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                <div style="margin-bottom: 4px;"><strong>FECHA:</strong> ${fechaF}</div>
                <div style="margin-bottom: 4px;"><strong>CLIENTE:</strong> ${venta.cliente}</div>
                <div style="margin-bottom: 4px;"><strong>MÉTODO:</strong> ${venta.metodo}</div>
                <div><strong>CAJERO:</strong> ${aclaracionOp}</div>
            </div>
            <div style="margin-bottom: 15px; font-size: 13px;">
                <strong style="border-bottom: 1px solid #ccc; display:block; margin-bottom:5px;">ESTADO:</strong>
                Cobrado y Registrado en Caja.
            </div>
            <div style="background: #19875410; border-left: 4px solid #198754; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <span style="font-size: 1.1em; font-weight:800;">TOTAL NETO:</span>
                <span style="font-size: 1.15em; font-weight:900; color: #198754;">+${montoF}</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
                <div style="width: 45%; text-align: center;">${firmaHtml}</div>
                <div style="width: 45%; text-align: center;">
                    <a href="${linkPdfPublico}" target="_blank"><img src="${qrUrl}" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;"></a>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-between gap-2 mt-4 border-top pt-3 no-print">
            <button class="btn btn-sm btn-dark fw-bold w-50" onclick="window.open('ticket.php?id=${venta.id}', 'pop-up', 'width=300,height=600')"><i class="bi bi-printer"></i> TÉRMICO</button>
            <button class="btn btn-sm btn-outline-success fw-bold w-50" onclick="window.open('${linkPdfPublico}', '_blank')"><i class="bi bi-file-pdf"></i> PDF (A4)</button>
        </div>
        <div class="d-flex justify-content-center gap-2 mt-2 no-print">
            <button class="btn btn-sm btn-success fw-bold w-50" onclick="mandarWAVenta('${venta.ticket}', '${montoF}', '${linkPdfPublico}')"><i class="bi bi-whatsapp"></i> WhatsApp</button>
            <button class="btn btn-sm btn-primary fw-bold w-50" onclick="mandarMailVenta(${venta.id})"><i class="bi bi-envelope"></i> Email</button>
        </div>
    `;
    Swal.fire({ html: ticketHTML, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });
}

function mandarWAVenta(ticket, monto, link) { window.open(`https://wa.me/?text=${encodeURIComponent(`Hola! Te enviamos tu comprobante de compra #${ticket} por un total de ${monto}.\n📄 Ver comprobante online: ${link}`)}`, '_blank'); }
function mandarMailVenta(id) {
    Swal.fire({ 
        title: 'Enviar Ticket de Venta', 
        text: '¿A qué correo querés enviarlo?', 
        input: 'email', 
        inputPlaceholder: 'destino@correo.com',
        showCancelButton: true,
        confirmButtonText: 'Enviar',
        confirmButtonColor: '#102A57'
    }).then((r) => {
        if(r.isConfirmed && r.value) {
            Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            
            let fData = new FormData();
            fData.append('id', id);
            fData.append('email', r.value);

            fetch('acciones/enviar_email_venta.php', { method: 'POST', body: fData })
            .then(res => res.json())
            .then(d => { 
                Swal.fire(d.status === 'success' ? 'Enviado con éxito' : 'Error al enviar', d.msg || '', d.status); 
            })
            .catch(error => {
                Swal.fire('Error', 'Hubo un problema de conexión con el servidor.', 'error');
            });
        }
    });
}

// ==========================================
// 2. TICKET EMERGENTE DE GASTOS
// ==========================================
function verTicketGasto(gasto) {
    let ts = Date.now();
    let montoF = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(gasto.monto);
    let fechaF = new Date(gasto.fecha).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    let linkPdfPublico = window.location.origin + window.location.pathname.replace('ver_recaudacion.php', '') + "ticket_gasto_pdf.php?id=" + gasto.id;
    let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdfPublico);
    let logoHtml = miLocal.logo_url ? `<img src="${miLocal.logo_url}?v=${ts}" style="max-height: 50px; margin-bottom: 10px;">` : '';
    
    let aclaracionOp = gasto.nombre_completo ? (gasto.nombre_completo + " | " + gasto.nombre_rol).toUpperCase() : 'OPERADOR AUTORIZADO';
    let firmaSeleccionada = (gasto.id_op && firmasB64[gasto.id_op]) ? firmasB64[gasto.id_op] : '';
    let firmaHtml = firmaSeleccionada ? `<img src="${firmaSeleccionada}" style="max-height: 80px; margin-bottom: -25px;"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${aclaracionOp}</small>` : `<div style="border-top:1px solid #000; width:100%; margin-top:35px;"></div><small style="font-size:9px; font-weight:bold;">${aclaracionOp}</small>`;

    let ticketHTML = `
        <div id="printTicket" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
            <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                ${logoHtml}
                <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${miLocal.nombre_negocio}</h4>
                <small style="color: #666;">${miLocal.direccion_local}</small>
            </div>
            <div style="text-align: center; margin-bottom: 15px;">
                <h5 style="font-weight: 900; color: #dc3545; letter-spacing: 1px; margin:0;">COMPROBANTE GASTO</h5>
                <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">OP #${gasto.id}</span>
            </div>
            <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                <div style="margin-bottom: 4px;"><strong>FECHA:</strong> ${fechaF}</div>
                <div style="margin-bottom: 4px;"><strong>CATEGORÍA:</strong> ${gasto.categoria}</div>
                <div><strong>OPERADOR:</strong> ${aclaracionOp}</div>
            </div>
            <div style="margin-bottom: 15px; font-size: 13px;">
                <strong style="border-bottom: 1px solid #ccc; display:block; margin-bottom:5px;">DETALLE:</strong>
                ${gasto.descripcion}
            </div>
            <div style="background: #dc354510; border-left: 4px solid #dc3545; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <span style="font-size: 1.1em; font-weight:800;">TOTAL:</span>
                <span style="font-size: 1.15em; font-weight:900; color: #dc3545;">-${montoF}</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
                <div style="width: 45%; text-align: center;">${firmaHtml}</div>
                <div style="width: 45%; text-align: center;"><a href="${linkPdfPublico}" target="_blank"><img src="${qrUrl}" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;"></a></div>
            </div>
        </div>
        <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3 no-print">
            <button class="btn btn-sm btn-outline-dark fw-bold" onclick="window.open('${linkPdfPublico}', '_blank')">PDF</button>
            <button class="btn btn-sm btn-success fw-bold" onclick="mandarWAGasto('${gasto.categoria}', '${montoF}', '${linkPdfPublico}')">WA</button>
            <button class="btn btn-sm btn-primary fw-bold" onclick="mandarMailGasto(${gasto.id})">EMAIL</button>
        </div>
    `;
    Swal.fire({ html: ticketHTML, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });
}

function mandarWAGasto(cat, monto, link) { window.open(`https://wa.me/?text=${encodeURIComponent(`Se registró Gasto de *${cat}* por *${monto}*.\n📄 Ticket: ${link}`)}`, '_blank'); }
function mandarMailGasto(id) {
    Swal.fire({ title: 'Enviar Ticket', text: 'Correo del destinatario:', input: 'email', showCancelButton: true }).then((r) => {
        if(r.isConfirmed && r.value) {
            Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            let fData = new FormData(); fData.append('id', id); fData.append('email', r.value);
            fetch('acciones/enviar_email_gasto.php', { method: 'POST', body: fData }).then(res => res.json()).then(d => { Swal.fire(d.status === 'success' ? 'Enviado con éxito' : 'Error al enviar', d.msg || '', d.status); });
        }
    });
}

// ==========================================
// 3. TICKET EMERGENTE DE MERMAS
// ==========================================
function verTicketMerma(merma) {
    let ts = Date.now();
    let montoF = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(merma.monto);
    let fechaF = new Date(merma.fecha).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    let linkPdfPublico = window.location.origin + window.location.pathname.replace('ver_recaudacion.php', '') + "ticket_merma_pdf.php?id=" + merma.id;
    let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdfPublico);
    let logoHtml = miLocal.logo_url ? `<img src="${miLocal.logo_url}?v=${ts}" style="max-height: 50px; margin-bottom: 10px;">` : '';
    
    let aclaracionOp = merma.nombre_completo ? (merma.nombre_completo + " | " + merma.nombre_rol).toUpperCase() : 'OPERADOR AUTORIZADO';
    let firmaSeleccionada = (merma.id_op && firmasB64[merma.id_op]) ? firmasB64[merma.id_op] : '';
    let firmaHtml = firmaSeleccionada ? `<img src="${firmaSeleccionada}" style="max-height: 80px; margin-bottom: -25px;"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${aclaracionOp}</small>` : `<div style="border-top:1px solid #000; width:100%; margin-top:35px;"></div><small style="font-size:9px; font-weight:bold;">${aclaracionOp}</small>`;

    let ticketHTML = `
        <div id="printTicket" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
            <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                ${logoHtml}
                <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${miLocal.nombre_negocio}</h4>
                <small style="color: #666;">${miLocal.direccion_local}</small>
            </div>
            <div style="text-align: center; margin-bottom: 15px;">
                <h5 style="font-weight: 900; color: #dc3545; letter-spacing: 1px; margin:0;">COMPROBANTE MERMA</h5>
                <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">OP #${merma.id}</span>
            </div>
            <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                <div style="margin-bottom: 4px;"><strong>FECHA:</strong> ${fechaF}</div>
                <div style="margin-bottom: 4px;"><strong>PRODUCTO:</strong> ${merma.producto_nom.toUpperCase()}</div>
                <div style="margin-bottom: 4px;"><strong>CANTIDAD:</strong> ${parseFloat(merma.cantidad)} u.</div>
                <div><strong>OPERADOR:</strong> ${aclaracionOp}</div>
            </div>
            <div style="margin-bottom: 15px; font-size: 13px;">
                <strong style="border-bottom: 1px solid #ccc; display:block; margin-bottom:5px;">DETALLE:</strong>
                ${merma.descripcion}
            </div>
            <div style="background: #dc354510; border-left: 4px solid #dc3545; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <span style="font-size: 1.1em; font-weight:800;">TOTAL:</span>
                <span style="font-size: 1.15em; font-weight:900; color: #dc3545;">-${montoF}</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
                <div style="width: 45%; text-align: center;">${firmaHtml}</div>
                <div style="width: 45%; text-align: center;"><img src="${qrUrl}" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;"></div>
            </div>
        </div>
        <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3 no-print">
            <button class="btn btn-sm btn-outline-dark fw-bold" onclick="window.open('${linkPdfPublico}', '_blank')">PDF</button>
            <button class="btn btn-sm btn-success fw-bold" onclick="mandarWAMerma('${merma.producto_nom}', '${montoF}', '${linkPdfPublico}')">WA</button>
            <button class="btn btn-sm btn-primary fw-bold" onclick="mandarMailMerma(${merma.id})">EMAIL</button>
        </div>
    `;
    Swal.fire({ html: ticketHTML, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });
}

function mandarWAMerma(producto, monto, link) { window.open(`https://wa.me/?text=${encodeURIComponent(`Se registró una Merma de *${producto}* por un costo de *${monto}*.\n📄 Ver comprobante: ${link}`)}`, '_blank'); }
function mandarMailMerma(id) {
    Swal.fire({ title: 'Enviar Comprobante', text: 'Correo del destinatario:', input: 'email', showCancelButton: true }).then((r) => {
        if(r.isConfirmed && r.value) {
            Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            let fData = new FormData(); fData.append('id', id); fData.append('email', r.value);
            fetch('acciones/enviar_email_merma.php', { method: 'POST', body: fData }).then(res => res.json()).then(d => { Swal.fire(d.status === 'success' ? 'Enviado' : 'Error', d.msg || '', d.status); });
        }
    });
}
</script>
    
<?php include 'includes/layout_footer.php'; ?>
</body>
</html>