<?php
// historial_cajas.php - AUDITORÍA INTERACTIVA (VERSIÓN FINAL LIMPIA)
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$rol = $_SESSION['rol'] ?? 3;
$es_admin = ($rol <= 2);

if (!$es_admin && !in_array('ver_historial_cajas', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

// === A. MOTOR DE AUDITORÍA RÁPIDA (AJAX) ===
if (isset($_GET['ajax_detalle'])) {
    header('Content-Type: application/json');
    $id_ses = intval($_GET['ajax_detalle']);
    
    try {
        $stmtC = $conexion->prepare("SELECT c.*, u.nombre_completo FROM cajas_sesion c JOIN usuarios u ON c.id_usuario = u.id WHERE c.id = ?");
        $stmtC->execute([$id_ses]);
        $caja = $stmtC->fetch(PDO::FETCH_ASSOC);
        if (!$caja) { echo json_encode(['status' => 'error']); exit; }

        $stmtV = $conexion->prepare("SELECT metodo_pago, SUM(total) as monto FROM ventas WHERE id_caja_sesion = ? AND estado='completada' GROUP BY metodo_pago");
        $stmtV->execute([$id_ses]);
        $ventas = $stmtV->fetchAll(PDO::FETCH_ASSOC);

        $stmtG = $conexion->prepare("SELECT descripcion, monto, categoria FROM gastos WHERE id_caja_sesion = ?");
        $stmtG->execute([$id_ses]);
        $gastos = $stmtG->fetchAll(PDO::FETCH_ASSOC);
        
        $total_gastos_calc = 0;
        foreach($gastos as $g) { $total_gastos_calc += floatval($g['monto']); }

        echo json_encode([
            'status' => 'success',
            'caja' => $caja,
            'total_gastos' => $total_gastos_calc,
            'ventas' => $ventas,
            'gastos' => $gastos
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); exit;
    }
}

// === B. LÓGICA DE CARGA NORMAL ===
$color_sistema = '#102A57';
$conf = [];
$rubro_actual = 'kiosco';
try {
    $resConf = $conexion->query("SELECT * FROM configuracion WHERE id=1");
    if ($resConf) {
        $conf = $resConf->fetch(PDO::FETCH_ASSOC);
        if (isset($conf['color_barra_nav'])) $color_sistema = $conf['color_barra_nav'];
        if (isset($conf['tipo_negocio'])) $rubro_actual = $conf['tipo_negocio'];
    }
} catch (Exception $e) { }

// OBTENER USUARIOS PARA EL FILTRO
$stmtUsu = $conexion->query("SELECT id, usuario FROM usuarios ORDER BY usuario ASC");
$usuarios_lista = $stmtUsu->fetchAll(PDO::FETCH_ASSOC);

// OBTENER CLIENTES PARA EL FILTRO
$stmtCli = $conexion->query("SELECT id, nombre FROM clientes WHERE (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL) ORDER BY nombre ASC");
$clientes_lista = $stmtCli->fetchAll(PDO::FETCH_ASSOC);

// FILTROS
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-2 months'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$buscar = $_GET['buscar'] ?? '';
$f_cliente = $_GET['id_cliente'] ?? '';
$f_usuario = $_GET['id_usuario'] ?? '';

$condiciones = ["DATE(c.fecha_apertura) >= ?", "DATE(c.fecha_apertura) <= ?", "(c.tipo_negocio = '$rubro_actual' OR c.tipo_negocio IS NULL)"];
$parametros = [$desde, $hasta];

if (!empty($buscar)) {
    if (is_numeric($buscar)) {
        $condiciones[] = "c.id = ?";
        $parametros[] = intval($buscar);
    } else {
        $condiciones[] = "u.usuario LIKE ?";
        $parametros[] = "%$buscar%";
    }
}
if ($f_cliente !== '') { 
    $condiciones[] = "EXISTS (SELECT 1 FROM ventas v WHERE v.id_caja_sesion = c.id AND v.id_cliente = ?)"; 
    $parametros[] = $f_cliente; 
}
if ($f_usuario !== '') { $condiciones[] = "c.id_usuario = ?"; $parametros[] = $f_usuario; }

// --- MOTOR DE PAGINACIÓN ---
$pagina_actual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$limite_por_pagina = 30;
$offset = ($pagina_actual - 1) * $limite_por_pagina;

$sql_count = "SELECT COUNT(*) FROM cajas_sesion c JOIN usuarios u ON c.id_usuario = u.id WHERE " . implode(" AND ", $condiciones);
$stmt_count = $conexion->prepare($sql_count);
$stmt_count->execute($parametros);
$total_registros = $stmt_count->fetchColumn();
$total_paginas = ceil($total_registros / $limite_por_pagina);

$sql = "SELECT c.*, u.usuario, u.nombre_completo FROM cajas_sesion c JOIN usuarios u ON c.id_usuario = u.id WHERE " . implode(" AND ", $condiciones) . " ORDER BY c.id DESC LIMIT $limite_por_pagina OFFSET $offset";
$stmt = $conexion->prepare($sql);
$stmt->execute($parametros);
$cajas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cálculo Global (sin límite)
$sql_calc = "SELECT total_ventas, diferencia, estado FROM cajas_sesion c WHERE " . implode(" AND ", $condiciones);
$stmt_calc = $conexion->prepare($sql_calc);
$stmt_calc->execute($parametros);
$cajas_calc = $stmt_calc->fetchAll(PDO::FETCH_ASSOC);

$total_ventas_hist = 0; $dif_neta = 0; $cajas_con_error = 0;
foreach($cajas_calc as $c_tot) {
    if($c_tot['estado'] == 'cerrada') {
        $total_ventas_hist += floatval($c_tot['total_ventas']);
        $dif_neta += floatval($c_tot['diferencia']);
        if(abs(floatval($c_tot['diferencia'])) > 0.01) $cajas_con_error++;
    }
}
foreach($cajas as $c) {
    if($c['estado'] == 'cerrada') {
        $total_ventas_hist += floatval($c['total_ventas']);
        $dif_neta += floatval($c['diferencia']);
        if(abs(floatval($c['diferencia'])) > 0.01) $cajas_con_error++;
    }
}

$query_filtros = $_SERVER['QUERY_STRING'] ? $_SERVER['QUERY_STRING'] : "desde=$desde&hasta=$hasta";

include 'includes/layout_header.php'; 
?>

<?php
// --- BANNER DINÁMICO ---
$titulo = "Historial de Cajas";
$subtitulo = "Auditoría microscópica de cierres y control de diferencias.";
$icono_bg = "bi-clock-history";

$botones = [
    ['texto' => 'Reporte PDF', 'link' => "reporte_cajas.php?$query_filtros", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-3 px-md-4 py-2 shadow-sm', 'target' => '_blank']
];

$widgets = [
    ['label' => 'Ventas Filtradas', 'valor' => '$'.number_format($total_ventas_hist, 0, ',', '.'), 'icono' => 'bi-cash-stack', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Balance de Diferencia', 'valor' => '$'.number_format($dif_neta, 2, ',', '.'), 'icono' => 'bi-intersect', 'border' => ($dif_neta < 0 ? 'border-danger' : 'border-success'), 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Alertas de Error', 'valor' => $cajas_con_error, 'icono' => 'bi-exclamation-octagon', 'border' => 'border-danger', 'icon_bg' => 'bg-danger bg-opacity-20']
];

include 'includes/componente_banner.php'; 
?>

<div class="container mt-n4 pb-5" style="position: relative; z-index: 20;">
    
    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border-left: 5px solid #ff9800 !important;">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="row g-2 align-items-center mb-0">
                <input type="hidden" name="desde" value="<?php echo htmlspecialchars($desde); ?>">
                <input type="hidden" name="hasta" value="<?php echo htmlspecialchars($hasta); ?>">
                <?php if($f_cliente) echo '<input type="hidden" name="id_cliente" value="'.htmlspecialchars($f_cliente).'">'; ?>
                <?php if($f_usuario) echo '<input type="hidden" name="id_usuario" value="'.htmlspecialchars($f_usuario).'">'; ?>
                
                <div class="col-md-8 col-12 text-center text-md-start">
                    <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador Rápido</h6>
                    <p class="small mb-0 opacity-75 d-none d-md-block">Ingrese el número de sesión o nombre del operador.</p>
                </div>
                <div class="col-md-4 col-12 text-end mt-2 mt-md-0">
                    <div class="input-group input-group-sm">
                        <input type="text" name="buscar" class="form-control border-0 fw-bold shadow-none" placeholder="N° de Caja o Nombre..." value="<?php echo htmlspecialchars($buscar); ?>">
                        <button class="btn btn-dark px-3 shadow-none border-0" type="submit"><i class="bi bi-arrow-right-circle-fill"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100">
                <div class="flex-grow-1" style="min-width: 120px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Desde</label>
                    <input type="date" name="desde" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $desde; ?>">
                </div>
                <div class="flex-grow-1" style="min-width: 120px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Hasta</label>
                    <input type="date" name="hasta" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $hasta; ?>">
                </div>
                <div class="flex-grow-1" style="min-width: 120px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Cliente</label>
                    <select name="id_cliente" class="form-select form-select-sm border-light-subtle fw-bold">
                        <option value="">Todos</option>
                        <?php foreach($clientes_lista as $cli): ?>
                            <option value="<?php echo $cli['id']; ?>" <?php echo ($f_cliente == $cli['id']) ? 'selected' : ''; ?>><?php echo strtoupper($cli['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex-grow-1" style="min-width: 120px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Responsable</label>
                    <select name="id_usuario" class="form-select form-select-sm border-light-subtle fw-bold">
                        <option value="">Todos</option>
                        <?php foreach($usuarios_lista as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo ($f_usuario == $u['id']) ? 'selected' : ''; ?>><?php echo strtoupper($u['usuario']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex-grow-0 d-flex gap-2 mt-2 mt-md-0">
                    <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;">
                        <i class="bi bi-funnel-fill"></i> FILTRAR
                    </button>
                    <a href="historial_cajas.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;">
                        <i class="bi bi-trash3-fill"></i> LIMPIAR
                    </a>
                </div>
            </form>
        </div>
    </div>

    <style>
        @media (max-width: 768px) {
            .tabla-movil-ajustada td, .tabla-movil-ajustada th {
                padding: 0.4rem 0.2rem !important;
                font-size: 0.75rem !important;
                white-space: nowrap;
            }
            .tabla-movil-ajustada .badge { font-size: 0.65rem !important; }
            .tabla-movil-ajustada .small { font-size: 0.65rem !important; }
            .tabla-movil-ajustada .fw-bold { font-size: 0.75rem !important; }
        }
    </style>

    <div class="alert py-2 small mb-3 text-center fw-bold border-0 shadow-sm rounded-3" style="background-color: #e9f2ff; color: #102A57;">
        <i class="bi bi-hand-index-thumb-fill me-1"></i> Toca o haz clic en un registro para ver la auditoría
    </div>

    <div class="card card-custom border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 tabla-movil-ajustada">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="ps-4 py-3">Sesión</th>
                        <th>Apertura / Cierre</th>
                        <th class="d-none d-md-table-cell">Responsable</th>
                        <th class="text-end d-none d-md-table-cell">Ventas</th>
                        <th class="text-end">Diferencia</th>
                        <th class="text-end pe-4 d-none d-md-table-cell">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($cajas)): ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted">No se encontraron registros.</td></tr>
                    <?php endif; ?>
                    <?php foreach($cajas as $c): 
                        $dif = floatval($c['diferencia']);
                        $apertura = date('d/m/y', strtotime($c['fecha_apertura'])) . ' <small class="text-muted">' . date('H:i', strtotime($c['fecha_apertura'])) . ' hs</small>';
                        $cierre = $c['fecha_cierre'] ? date('d/m/y', strtotime($c['fecha_cierre'])) . ' <small class="text-muted">' . date('H:i', strtotime($c['fecha_cierre'])) . ' hs</small>' : '<span class="badge bg-warning text-dark">PENDIENTE</span>';
                        
                        if ($c['estado'] == 'abierta') {
                            $color_dif = 'text-primary';
                            $texto_dif = 'ABIERTA';
                        } else {
                            if (abs($dif) < 0.01) {
                                $color_dif = 'text-success';
                                $texto_dif = 'OK ($0,00)';
                            } else {
                                $color_dif = 'text-danger';
                                $texto_dif = ($dif > 0 ? '+' : '') . '$' . number_format($dif, 2, ',', '.');
                            }
                        }
                    ?>
                    <tr style="cursor: pointer;" onclick="abrirAuditoria(<?php echo $c['id']; ?>)">
                        <td class="ps-4">
                            <div class="fw-bold text-dark">#CJ-<?php echo str_pad($c['id'], 6, '0', STR_PAD_LEFT); ?></div>
                            <div class="d-block d-md-none mt-1"><span class="badge bg-light text-dark border fw-bold"><?php echo htmlspecialchars($c['usuario']); ?></span></div>
                        </td>
                        <td>
                            <div class="fw-bold text-dark"><i class="bi bi-box-arrow-in-right text-success me-1"></i><?php echo $apertura; ?></div>
                            <div class="fw-bold text-dark mt-1"><i class="bi bi-box-arrow-left text-danger me-1"></i><?php echo $cierre; ?></div>
                        </td>
                        <td class="d-none d-md-table-cell">
                            <div class="small fw-bold text-uppercase text-muted"><i class="bi bi-person-circle me-1"></i><?php echo htmlspecialchars($c['usuario']); ?></div>
                        </td>
                        <td class="text-end fw-bold text-muted d-none d-md-table-cell">$<?php echo number_format($c['total_ventas'], 2, ',', '.'); ?></td>
                        <td class="text-end fw-bold <?php echo $color_dif; ?> fs-6"><?php echo $texto_dif; ?></td>
                        <td class="text-end pe-4 d-none d-md-table-cell"><button class="btn btn-sm btn-outline-primary rounded-pill px-3">AUDITAR</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (isset($total_paginas) && $total_paginas > 1): 
            $query_params = $_GET; unset($query_params['pagina']);
            $qs = http_build_query($query_params); $qs = $qs ? "&$qs" : "";
        ?>
        <div class="card-footer bg-white border-top py-3">
            <nav>
                <ul class="pagination justify-content-center mb-0 shadow-sm">
                    <li class="page-item <?= ($pagina_actual <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link fw-bold" href="?pagina=<?= $pagina_actual - 1 ?><?= $qs ?>">Anterior</a>
                    </li>
                    <?php 
                    $inicio_pag = max(1, $pagina_actual - 2);
                    $fin_pag = min($total_paginas, $pagina_actual + 2);
                    for($i = $inicio_pag; $i <= $fin_pag; $i++): 
                    ?>
                        <li class="page-item <?= ($i == $pagina_actual) ? 'active' : '' ?>">
                            <a class="page-link fw-bold" href="?pagina=<?= $i ?><?= $qs ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($pagina_actual >= $total_paginas) ? 'disabled' : '' ?>">
                        <a class="page-link fw-bold" href="?pagina=<?= $pagina_actual + 1 ?><?= $qs ?>">Siguiente</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const miLocal = <?php echo json_encode($conf ?? []); ?>;

function format(n) { return parseFloat(n).toLocaleString('es-AR', { minimumFractionDigits: 2 }); }

function abrirAuditoria(id) {
    Swal.fire({
        title: 'Cargando Detalle...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    fetch(`historial_cajas.php?ajax_detalle=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                const c = data.caja;
                const gastosTotal = data.total_gastos;
                const esAbierta = (c.estado === 'abierta');
                const esperado = parseFloat(c.monto_inicial) + parseFloat(c.total_ventas) - gastosTotal;
                const dif = esAbierta ? 0 : parseFloat(c.diferencia || 0);

                let linkPdfPublico = window.location.origin + "/ticket_caja_pdf.php?id=" + c.id;
                let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdfPublico);
                let logoHtml = miLocal.logo_url ? `<img src="${miLocal.logo_url}" style="max-height: 50px; margin-bottom: 10px;">` : '';
                
                let aclaracionOp = c.nombre_completo ? (c.nombre_completo).toUpperCase() : 'OPERADOR AUTORIZADO';
                let rutaFirmaOp = c.id_usuario ? `img/firmas/usuario_${c.id_usuario}.png?v=${Date.now()}` : `img/firmas/firma_admin.png?v=${Date.now()}`;
                let firmaHtml = `<img src="${rutaFirmaOp}" style="max-height: 80px; margin-bottom: -25px;" onerror="this.style.display='none'"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${aclaracionOp}</small>`;

                let difColor = esAbierta ? '#666' : (Math.abs(dif) < 0.01 ? '#198754' : '#dc3545');
                let difText = esAbierta ? 'CAJA ABIERTA' : (dif < 0 ? `-$${format(Math.abs(dif))}` : `+$${format(dif)}`);

                let ventasHtml = data.ventas.map(v => `<div style="display:flex; justify-content:space-between; margin-bottom:3px;"><span>${v.metodo_pago.toUpperCase()}</span><strong>$${format(v.monto)}</strong></div>`).join('');
                if(data.ventas.length === 0) ventasHtml = `<div style="text-align:center; color:#666; font-size:11px;">Sin ventas registradas</div>`;

                let gastosHtml = data.gastos.map(g => `<div style="display:flex; justify-content:space-between; margin-bottom:3px;"><span>${g.descripcion.substring(0,20)}</span><strong style="color:#dc3545;">-$${format(g.monto)}</strong></div>`).join('');
                if(data.gastos.length === 0) gastosHtml = `<div style="text-align:center; color:#666; font-size:11px;">Sin gastos registrados</div>`;

                let ticketHTML = `
                    <div id="printTicket" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
                        <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                            ${logoHtml}
                            <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${miLocal.nombre_negocio || 'EMPRESA'}</h4>
                            <small style="color: #666;">${miLocal.direccion_local || ''}</small>
                        </div>
                        <div style="text-align: center; margin-bottom: 15px;">
                            <h5 style="font-weight: 900; color: #102A57; letter-spacing: 1px; margin:0;">COMPROBANTE DE CAJA</h5>
                            <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">SESIÓN #CJ-${String(c.id).padStart(6, '0')}</span>
                        </div>
                        
                        <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 12px;">
                            <div style="margin-bottom: 4px;"><strong>APERTURA:</strong> ${new Date(c.fecha_apertura).toLocaleString('es-AR')}</div>
                            <div style="margin-bottom: 4px;"><strong>CIERRE:</strong> ${esAbierta ? 'PENDIENTE' : new Date(c.fecha_cierre).toLocaleString('es-AR')}</div>
                            <div><strong>OPERADOR:</strong> ${aclaracionOp}</div>
                        </div>

                        <div style="margin-bottom: 15px; font-size: 13px;">
                            <strong style="border-bottom: 1px solid #ccc; display:block; margin-bottom:5px;">BALANCE EFECTIVO:</strong>
                            <div style="display:flex; justify-content:space-between;"><span>Efectivo Inicial:</span><span>$${format(c.monto_inicial)}</span></div>
                            <div style="display:flex; justify-content:space-between;"><span>Ventas Efectivo (+):</span><span style="color:#198754;">+$${format(c.total_ventas)}</span></div>
                            <div style="display:flex; justify-content:space-between;"><span>Egresos Caja (-):</span><span style="color:#dc3545;">-$${format(gastosTotal)}</span></div>
                            <div style="display:flex; justify-content:space-between; font-weight:bold; background:#eee; padding:2px; margin-top:4px;"><span>ESPERADO EN CAJA:</span><span>$${format(esperado)}</span></div>
                            <div style="display:flex; justify-content:space-between; font-weight:bold; background:#102A57; color:#fff; padding:2px; margin-top:2px;"><span>DECLARADO FÍSICO:</span><span>${esAbierta ? 'PENDIENTE' : '$'+format(c.monto_final)}</span></div>
                        </div>

                        <div style="background: ${difColor}15; border-left: 4px solid ${difColor}; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                            <span style="font-size: 1em; font-weight:800; color:${difColor}">DIFERENCIA:</span>
                            <span style="font-size: 1.1em; font-weight:900; color: ${difColor};">${difText}</span>
                        </div>

                        <div style="margin-bottom: 15px; font-size: 12px;">
                            <strong style="border-bottom: 1px solid #ccc; display:block; margin-bottom:5px;">VENTAS POR MÉTODO:</strong>
                            ${ventasHtml}
                        </div>

                        <div style="margin-bottom: 15px; font-size: 12px;">
                            <strong style="border-bottom: 1px solid #ccc; display:block; margin-bottom:5px;">DETALLE DE EGRESOS:</strong>
                            ${gastosHtml}
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
                            <div style="width: 45%; text-align: center;">${firmaHtml}</div>
                            <div style="width: 45%; text-align: center;">
                                <a href="${linkPdfPublico}" target="_blank">
                                    <img src="${qrUrl}" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;">
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3 no-print">
                        <button class="btn btn-sm btn-outline-dark fw-bold" onclick="window.open('${linkPdfPublico}', '_blank')">PDF</button>
                        <button class="btn btn-sm btn-success fw-bold" onclick="mandarWACaja('${c.id}', '${difText}', '${linkPdfPublico}')"><i class="bi bi-whatsapp"></i> WA</button>
                        <button class="btn btn-sm btn-primary fw-bold" onclick="mandarMailCaja(${c.id})"><i class="bi bi-envelope-fill"></i> EMAIL</button>
                    </div>
                `;

                Swal.fire({ html: ticketHTML, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });
            } else {
                Swal.fire('Error', 'No se pudo cargar el detalle.', 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Problema de conexión.', 'error'));
}

function mandarWACaja(id, difText, link) {
    let msj = `Comprobante de Caja *#CJ-${String(id).padStart(6,'0')}*.\nDiferencia registrada: *${difText}*.\n📄 Ver comprobante: ${link}`;
    window.open(`https://wa.me/?text=${encodeURIComponent(msj)}`, '_blank');
}

function mandarMailCaja(id) {
    Swal.fire({ 
        title: 'Enviar Comprobante', 
        text: 'Ingrese el correo del destinatario:',
        input: 'email', 
        showCancelButton: true,
        confirmButtonText: 'ENVIAR',
        cancelButtonText: 'CANCELAR'
    }).then((r) => {
        if(r.isConfirmed && r.value) {
            Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            let fData = new FormData(); fData.append('id', id); fData.append('email', r.value);
            fetch('acciones/enviar_email_caja.php', { method: 'POST', body: fData })
            .then(res => res.json())
            .then(d => { Swal.fire(d.status === 'success' ? 'Enviado' : 'Error', d.msg || '', d.status); })
            .catch(() => Swal.fire('Error', 'Hubo un problema de conexión al enviar.', 'error'));
        }
    });
}
</script>

<?php include 'includes/layout_footer.php'; ?>