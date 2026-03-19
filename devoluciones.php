<?php
// devoluciones.php - VERSIÓN REPARADA AL 100%
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$user_id = $_SESSION['usuario_id'];
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = ($_SESSION['rol'] <= 2);

if (!$es_admin && !in_array('ver_devoluciones', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

// --- LÓGICA DE FIRMA INTELIGENTE (Para el Dueño) ---
// 1. Datos de quien opera actualmente
$u_op = $conexion->prepare("SELECT usuario FROM usuarios WHERE id = ?");
$u_op->execute([$user_id]);
$operadorRow = $u_op->fetch(PDO::FETCH_ASSOC);

// 2. Datos del Dueño para la Firma (Buscamos al usuario con rol 'dueño')
$u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol 
                             FROM usuarios u 
                             JOIN roles r ON u.id_rol = r.id 
                             WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
$ownerRow = $u_owner->fetch(PDO::FETCH_ASSOC);

// Si no hay dueño, usamos los datos del operador actual
$firmante = $ownerRow ? $ownerRow : ['nombre_completo' => 'RESPONSABLE', 'nombre_rol' => 'AUTORIZADO', 'id' => $user_id];

// 3. Definir la ruta de la firma física
$firmaReal = ""; 
if($ownerRow && file_exists("img/firmas/usuario_{$ownerRow['id']}.png")) {
    $firmaReal = "img/firmas/usuario_{$ownerRow['id']}.png";
} elseif(file_exists("img/firmas/firma_admin.png")) {
    $firmaReal = "img/firmas/firma_admin.png";
}

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf['color_barra_nav'] ?? '#102A57';
$rubro_actual = $conf['tipo_negocio'] ?? 'kiosco';

// OBTENER USUARIOS Y CLIENTES PARA EL FILTRO
$usuarios_lista = $conexion->query("SELECT id, usuario FROM usuarios ORDER BY usuario ASC")->fetchAll(PDO::FETCH_ASSOC);
$clientes_lista = $conexion->query("SELECT id, nombre FROM clientes WHERE (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL) ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- FILTROS ---
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-2 months'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$buscar = $_GET['buscar'] ?? '';
$f_cliente = $_GET['id_cliente'] ?? '';
$f_usuario = $_GET['id_usuario'] ?? '';

// --- PROCESAMIENTO AJAX PARA TICKETS ---
if (isset($_GET['ajax_get_ticket'])) {
    header('Content-Type: application/json');
    $id_t = intval($_GET['ajax_get_ticket']);
    $stmt = $conexion->prepare("SELECT v.*, u.usuario, c.nombre as cliente FROM ventas v JOIN usuarios u ON v.id_usuario = u.id JOIN clientes c ON v.id_cliente = c.id WHERE v.id = ?");
    $stmt->execute([$id_t]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmtDet = $conexion->prepare("SELECT dv.*, p.descripcion FROM detalle_ventas dv JOIN productos p ON dv.id_producto = p.id WHERE dv.id_venta = ?");
    $stmtDet->execute([$id_t]);
    $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
    $stmtDevs = $conexion->prepare("SELECT d.*, u.usuario as operador, u.nombre_completo, r.nombre as nombre_rol, u.id as id_op 
                                 FROM devoluciones d 
                                 JOIN usuarios u ON d.id_usuario = u.id 
                                 JOIN roles r ON u.id_rol = r.id 
                                 WHERE d.id_venta_original = ?");
    $stmtDevs->execute([$id_t]);
    $info_devs = $stmtDevs->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['venta' => $venta, 'detalles' => $items, 'info_historial' => $info_devs, 'conf' => $conf]);
    exit;
}

// --- PROCESAR REINTEGRO ---
if (isset($_POST['accion']) && $_POST['accion'] == 'confirmar_reintegro') {
    header('Content-Type: application/json');
    try {
        $conexion->beginTransaction();
        $stmt = $conexion->prepare("INSERT INTO devoluciones (id_venta_original, id_producto, cantidad, monto_devuelto, motivo, fecha, id_usuario, tipo_negocio) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)");
        $stmt->execute([$_POST['id_v'], $_POST['id_p'], $_POST['cant'], $_POST['monto'], $_POST['motivo'], $user_id, $rubro_actual]);
        if ($_POST['motivo'] === 'Reingreso') {
            $conexion->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?")->execute([$_POST['cant'], $_POST['id_p']]);
        } else {
            $conexion->prepare("INSERT INTO mermas (id_producto, cantidad, motivo, fecha, id_usuario, tipo_negocio) VALUES (?, ?, ?, NOW(), ?, ?)")->execute([$_POST['id_p'], $_POST['cant'], "Devolución #".$_POST['id_v'], $user_id, $rubro_actual]);
        }
        $stmtCaja = $conexion->prepare("SELECT id FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta'");
        $stmtCaja->execute([$user_id]);
        $caja = $stmtCaja->fetch(PDO::FETCH_ASSOC);
        if ($caja) {
            $descG = "Reintegro Ticket #" . $_POST['id_v'] . " (" . $_POST['cant'] . " u.)";
            $conexion->prepare("INSERT INTO gastos (descripcion, monto, categoria, fecha, id_usuario, id_caja_sesion, tipo_negocio) VALUES (?, ?, 'Devoluciones', NOW(), ?, ?, ?)")->execute([$descG, $_POST['monto'], $user_id, $caja['id'], $rubro_actual]);
        
        }
        $conexion->commit(); 
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) { 
        if ($conexion->inTransaction()) $conexion->rollBack(); 
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); 
    }
    exit;
}

// --- CONSULTAS ---
$condV = ["v.estado='completada'", "DATE(v.fecha) >= ?", "DATE(v.fecha) <= ?", "(v.tipo_negocio = '$rubro_actual' OR v.tipo_negocio IS NULL)"];
$paramsV = [$desde, $hasta];
if(!empty($buscar)) { if(is_numeric($buscar)) { $condV[] = "v.id = ?"; $paramsV[] = intval($buscar); } else { $condV[] = "c.nombre LIKE ?"; $paramsV[] = "%$buscar%"; } }
if($f_cliente !== '') { $condV[] = "v.id_cliente = ?"; $paramsV[] = $f_cliente; }

// Paginación Lado Izquierdo (Ventas)
$pag_v = isset($_GET['pag_v']) ? max(1, (int)$_GET['pag_v']) : 1;
$limit_v = 15;
$offset_v = ($pag_v - 1) * $limit_v;
$sqlCountV = "SELECT COUNT(*) FROM ventas v LEFT JOIN clientes c ON v.id_cliente = c.id WHERE " . implode(" AND ", $condV);
$stmtCountV = $conexion->prepare($sqlCountV); $stmtCountV->execute($paramsV);
$tot_v = $stmtCountV->fetchColumn(); $tot_pag_v = ceil($tot_v / $limit_v);

$sqlV = "SELECT v.id, v.total, v.fecha, c.nombre, 
            (SELECT COUNT(*) FROM devoluciones d WHERE d.id_venta_original = v.id) as tiene_dev 
         FROM ventas v LEFT JOIN clientes c ON v.id_cliente = c.id 
         WHERE " . implode(" AND ", $condV) . " ORDER BY v.fecha DESC LIMIT $limit_v OFFSET $offset_v";
$stmtV = $conexion->prepare($sqlV); $stmtV->execute($paramsV); $ventas_lista = $stmtV->fetchAll(PDO::FETCH_ASSOC);

$condH = ["DATE(d.fecha) >= ?", "DATE(d.fecha) <= ?", "(d.tipo_negocio = '$rubro_actual' OR d.tipo_negocio IS NULL)"];
$paramsH = [$desde, $hasta];
if(!empty($buscar)) { 
    if(is_numeric($buscar)) { $condH[] = "v.id = ?"; $paramsH[] = intval($buscar); } 
    else { $condH[] = "c.nombre LIKE ?"; $paramsH[] = "%$buscar%"; } 
}
if($f_cliente !== '') { $condH[] = "v.id_cliente = ?"; $paramsH[] = $f_cliente; }
if($f_usuario !== '') { $condH[] = "d.id_usuario = ?"; $paramsH[] = $f_usuario; }

// Paginación Lado Derecho (Historial Devoluciones)
$pag_h = isset($_GET['pag_h']) ? max(1, (int)$_GET['pag_h']) : 1;
$limit_h = 20;
$offset_h = ($pag_h - 1) * $limit_h;
$sqlCountH = "SELECT COUNT(*) FROM devoluciones d JOIN ventas v ON d.id_venta_original = v.id LEFT JOIN clientes c ON v.id_cliente = c.id WHERE " . implode(" AND ", $condH);
$stmtCountH = $conexion->prepare($sqlCountH); $stmtCountH->execute($paramsH);
$tot_h = $stmtCountH->fetchColumn(); $tot_pag_h = ceil($tot_h / $limit_h);

$sqlH = "SELECT d.*, v.id as ticket_id, p.descripcion as producto, c.nombre as cliente FROM devoluciones d JOIN ventas v ON d.id_venta_original = v.id JOIN productos p ON d.id_producto = p.id LEFT JOIN clientes c ON v.id_cliente = c.id WHERE " . implode(" AND ", $condH) . " ORDER BY d.fecha DESC LIMIT $limit_h OFFSET $offset_h";
$stmtH = $conexion->prepare($sqlH); $stmtH->execute($paramsH); $historial = $stmtH->fetchAll(PDO::FETCH_ASSOC);

// Cálculo Global Total de Devoluciones (sin límite)
$sqlH_tot = "SELECT SUM(d.monto_devuelto) FROM devoluciones d JOIN ventas v ON d.id_venta_original = v.id LEFT JOIN clientes c ON v.id_cliente = c.id WHERE " . implode(" AND ", $condH);
$stmtH_tot = $conexion->prepare($sqlH_tot); $stmtH_tot->execute($paramsH);
$totalReintegros = $stmtH_tot->fetchColumn() ?: 0;

$query_filtros = !empty($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : "desde=$desde&hasta=$hasta";

require_once 'includes/layout_header.php'; ?>

<?php
// --- DEFINICIÓN DEL BANNER DINÁMICO ---
$titulo = "Devoluciones";
$subtitulo = "Gestión de reintegros y stock operativo";
$icono_bg = "bi-arrow-counterclockwise";

$query_filtros = !empty($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : "desde=$desde&hasta=$hasta";
$botones = [
    ['texto' => 'Reporte PDF', 'link' => "reporte_devoluciones.php?$query_filtros", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-3 px-md-4 py-2 shadow-sm', 'target' => '_blank']
];

$widgets = [
    ['label' => 'Items Devueltos', 'valor' => count($historial), 'icono' => 'bi-arrow-return-left', 'border' => 'border-warning', 'icon_bg' => 'bg-warning bg-opacity-20'],
    ['label' => 'Total Reintegros', 'valor' => '$'.number_format($totalReintegros, 0), 'icono' => 'bi-currency-dollar', 'border' => 'border-success', 'icon_bg' => 'bg-success bg-opacity-20'],
    ['label' => 'Ventas Filtradas', 'valor' => count($ventas_lista), 'icono' => 'bi-receipt', 'icon_bg' => 'bg-white bg-opacity-10']
];

include 'includes/componente_banner.php'; 
?>

<style>
    /* TICKET ROOP (MODO OPERACION) */
    .ticket-real { font-family: 'Courier New', Courier, monospace; font-size: 12px; color: #000; text-align: left; width: 100%; max-width: 290px; margin: 0 auto; }
    .ticket-real .centrado { text-align: center; }
    .ticket-real .linea { border-top: 1px dashed #000; margin: 5px 0; }
    .ticket-real .negrita { font-weight: bold; }
    .ticket-tachado { text-decoration: line-through; opacity: 0.5; }
    @media (max-width: 768px) { .lista-scroll { max-height: 180px; overflow-y: auto; } }
</style>

<div class="container mt-n4 pb-5" style="position: relative; z-index: 20;">
    
    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border-left: 5px solid #ff9800 !important;">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="row g-2 align-items-center mb-0">
                <input type="hidden" name="desde" value="<?php echo htmlspecialchars($desde); ?>">
                <input type="hidden" name="hasta" value="<?php echo htmlspecialchars($hasta); ?>">
                <?php if($f_cliente) echo '<input type="hidden" name="id_cliente" value="'.htmlspecialchars($f_cliente).'">'; ?>
                <?php if($f_usuario) echo '<input type="hidden" name="id_usuario" value="'.htmlspecialchars($f_usuario).'">'; ?>
                
                <div class="col-md-8 col-12 text-center text-md-start">
                    <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador de Tickets</h6>
                    <p class="small mb-0 opacity-75 d-none d-md-block">Ingrese el número de ticket o nombre del cliente para localizar la venta.</p>
                </div>
                <div class="col-md-4 col-12 text-end mt-2 mt-md-0">
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
                <div class="flex-grow-1" style="min-width: 120px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Desde</label>
                    <input type="date" name="desde" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $desde; ?>">
                </div>
                <div class="flex-grow-1" style="min-width: 120px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Hasta</label>
                    <input type="date" name="hasta" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $hasta; ?>">
                </div>
                <div class="flex-grow-1" style="min-width: 140px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Cliente</label>
                    <select name="id_cliente" class="form-select form-select-sm border-light-subtle fw-bold">
                        <option value="">Todos</option>
                        <?php foreach($clientes_lista as $cli): ?>
                            <option value="<?php echo $cli['id']; ?>" <?php echo ($f_cliente == $cli['id']) ? 'selected' : ''; ?>><?php echo strtoupper($cli['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex-grow-1" style="min-width: 140px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Operador</label>
                    <select name="id_usuario" class="form-select form-select-sm border-light-subtle fw-bold">
                        <option value="">Todos</option>
                        <?php foreach($usuarios_lista as $usu): ?>
                            <option value="<?php echo $usu['id']; ?>" <?php echo ($f_usuario == $usu['id']) ? 'selected' : ''; ?>><?php echo strtoupper($usu['usuario']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex-grow-0 d-flex gap-2 mt-2 mt-md-0">
                    <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;">
                        <i class="bi bi-funnel-fill me-1"></i> FILTRAR
                    </button>
                    <a href="devoluciones.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;">
                        <i class="bi bi-trash3-fill me-1"></i> LIMPIAR
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white py-3 border-0"><h6 class="fw-bold mb-0">Seleccionar Venta</h6></div>
                <div class="list-group list-group-flush lista-scroll">
                    <?php foreach($ventas_lista as $v): ?>
                        <button onclick="verTicket(<?php echo $v['id']; ?>, 'operacion')" class="list-group-item list-group-item-action py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="fw-bold">#<?php echo $v['id']; ?></span>
                                    <?php if($v['tiene_dev'] > 0): ?>
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:0.6rem;">PARCIAL/DEVUELTO</span>
                                    <?php endif; ?>
                                    <small class="d-block text-muted"><?php echo substr($v['nombre'] ?? 'C. Final', 0, 18); ?></small>
                                </div>
                                <div class="fw-bold text-primary">$<?php echo number_format($v['total'], 0); ?></div>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($tot_pag_v > 1): 
                    $qp = $_GET; unset($qp['pag_v']); $qs = http_build_query($qp); $qs = $qs ? "&$qs" : "";
                ?>
                <div class="card-footer bg-white border-top py-2 d-flex justify-content-center">
                    <div class="btn-group btn-group-sm shadow-sm" role="group">
                        <a href="?pag_v=<?= $pag_v - 1 ?><?= $qs ?>" class="btn btn-light border <?= ($pag_v <= 1) ? 'disabled' : '' ?>"><i class="bi bi-chevron-left"></i></a>
                        <span class="btn btn-light fw-bold disabled border"><?= $pag_v ?> / <?= $tot_pag_v ?></span>
                        <a href="?pag_v=<?= $pag_v + 1 ?><?= $qs ?>" class="btn btn-light border <?= ($pag_v >= $tot_pag_v) ? 'disabled' : '' ?>"><i class="bi bi-chevron-right"></i></a>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-dark py-3 border-0"><h6 class="fw-bold mb-0 text-white">HISTORIAL DE DEVOLUCIONES</h6></div>
                <div class="list-group list-group-flush">
                    <?php foreach($historial as $h): ?>
                        <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center p-3" style="cursor: pointer;" onclick="verTicket(<?php echo $h['ticket_id']; ?>, 'ver')">
                            <div><div class="fw-bold text-dark"><?php echo $h['producto']; ?></div><small class="text-muted">Ticket #<?php echo $h['ticket_id']; ?> | <?php echo $h['cliente'] ?? 'C. Final'; ?></small></div>
                            <div class="text-end"><div class="fw-bold text-danger">-$<?php echo number_format($h['monto_devuelto'], 0); ?></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($tot_pag_h > 1): 
                    $qp = $_GET; unset($qp['pag_h']); $qs = http_build_query($qp); $qs = $qs ? "&$qs" : "";
                ?>
                <div class="card-footer bg-white border-top py-3">
                    <ul class="pagination pagination-sm justify-content-center mb-0 shadow-sm">
                        <li class="page-item <?= ($pag_h <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link fw-bold" href="?pag_h=<?= $pag_h - 1 ?><?= $qs ?>">Ant.</a>
                        </li>
                        <?php 
                        $ini_h = max(1, $pag_h - 2); $fin_h = min($tot_pag_h, $pag_h + 2);
                        for($i = $ini_h; $i <= $fin_h; $i++): 
                        ?>
                            <li class="page-item <?= ($i == $pag_h) ? 'active' : '' ?>">
                                <a class="page-link fw-bold" href="?pag_h=<?= $i ?><?= $qs ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= ($pag_h >= $tot_pag_h) ? 'disabled' : '' ?>">
                            <a class="page-link fw-bold" href="?pag_h=<?= $pag_h + 1 ?><?= $qs ?>">Sig.</a>
                        </li>
                    </ul>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<?php
// Usamos la firma inteligente definida arriba
$ruta_firma_js = $firmaReal;
?>
<script>
const miFirma = "<?php echo $ruta_firma_js; ?>";

function verTicket(id, modo) {
    Swal.fire({ title: 'Cargando...', didOpen: () => { Swal.showLoading(); } });
    fetch(`devoluciones.php?ajax_get_ticket=${id}`).then(r => r.json()).then(data => {
        const v = data.venta; 
        const conf = data.conf; 
        
        if (modo === 'ver') {
            // --- MODO HISTORIAL: TICKET PREMIUM ESTILO GASTOS ---
            let itemsH = '';
            let totalDevuelto = 0;
            
            data.detalles.forEach(d => {
                const dev = data.info_historial.find(h => h.id_producto == d.id_producto);
                if (dev) {
                    itemsH += `<div style="display:flex; justify-content:space-between; margin-bottom: 5px;">
                        <span>${parseFloat(dev.cantidad)}x ${d.descripcion.substring(0, 22)}</span>
                        <b>-$${parseFloat(dev.monto_devuelto).toFixed(2)}</b>
                    </div>`;
                    totalDevuelto += parseFloat(dev.monto_devuelto);
                }
            });

            let montoF = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(totalDevuelto);
            let linkPdfPublico = window.location.origin + "/ticket_devolucion_pdf.php?id=" + v.id;
            let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdfPublico);
            let logoHtml = conf.logo_url ? `<img src="${conf.logo_url}?v=${Date.now()}" style="max-height: 50px; margin-bottom: 10px;">` : '';
            
            let devInfo = data.info_historial[0] || {};
            // Aclaración: Nombre Real + Rol del operador
            let aclaracionOp = devInfo.nombre_completo ? (devInfo.nombre_completo + " | " + devInfo.nombre_rol).toUpperCase() : 'OPERADOR AUTORIZADO';
            // Firma del Operador: 80px y sobre la linea
            let rutaFirmaOp = devInfo.id_op ? `img/firmas/usuario_${devInfo.id_op}.png?v=${Date.now()}` : `img/firmas/firma_admin.png?v=${Date.now()}`;
            let firmaHtml = `<img src="${rutaFirmaOp}" style="max-height: 80px; margin-bottom: -25px;" onerror="this.style.display='none'"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${aclaracionOp}</small>`;

            let linkPdfReintegro = window.location.origin + "/ticket_devolucion_pdf.php?id=" + v.id;

            const html = `
                <div id="printTicket" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
                    <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                        ${logoHtml}
                        <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${conf.nombre_negocio}</h4>
                        <small style="color: #666;">${conf.direccion_local}</small>
                    </div>
                    <div style="text-align: center; margin-bottom: 15px;">
                        <h5 style="font-weight: 900; color: #dc3545; letter-spacing: 1px; margin:0;">COMPROBANTE REINTEGRO</h5>
                        <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">TICKET ORIGEN #${v.id}</span>
                    </div>
                    <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                        <div style="margin-bottom: 4px;"><strong>FECHA VENTA:</strong> ${v.fecha}</div>
                        <div style="margin-bottom: 4px;"><strong>CLIENTE:</strong> ${v.cliente || 'C. Final'}</div>
                        <div><strong>OPERADOR DEV:</strong> ${aclaracionOp}</div>
                    </div>
                    <div style="margin-bottom: 15px; font-size: 13px;">
                        <strong style="border-bottom: 1px solid #ccc; display:block; margin-bottom:5px;">DETALLE DE REINTEGRO:</strong>
                        ${itemsH}
                    </div>
                    <div style="background: #dc354510; border-left: 4px solid #dc3545; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                        <span style="font-size: 1.1em; font-weight:800;">TOTAL:</span>
                        <span style="font-size: 1.15em; font-weight:900; color: #dc3545;">-${montoF}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
                        <div style="width: 45%; text-align: center;">${firmaHtml}</div>
                        <div style="width: 45%; text-align: center;">
                            <a href="${linkPdfPublico}" target="_blank"><img src="${qrUrl}" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;"></a>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3 no-print">
                    <button class="btn btn-sm btn-outline-dark fw-bold" onclick="window.open('${linkPdfReintegro}', '_blank')">PDF</button>
                    <button class="btn btn-sm btn-success fw-bold" onclick="mandarWADev('${v.id}', '${montoF}', '${linkPdfReintegro}')">WA</button>
                    <button class="btn btn-sm btn-primary fw-bold" onclick="mandarMailDevolucion(${v.id})">EMAIL</button>
                </div>
            `;
            Swal.fire({ html: html, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });

        } else {
            // --- MODO OPERACIÓN: SELECCIONAR QUÉ DEVOLVER (No se toca) ---
            let itemsH = '';
            data.detalles.forEach(d => {
                const dev = data.info_historial.find(h => h.id_producto == d.id_producto);
                itemsH += `<div class="${dev ? 'ticket-tachado' : ''} mb-3 p-2 rounded" style="background-color: #f8f9fa; border: 1px solid #eee;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-size: 12px; font-weight: 600;">${parseFloat(d.cantidad)}x ${d.descripcion.substring(0, 22)}</span>
                        <b style="font-size: 13px;">$${parseFloat(d.subtotal).toFixed(0)}</b>
                    </div>
                    ${(!dev && modo === 'operacion') ? `<div class="text-end mt-2"><button class="btn btn-outline-danger btn-sm fw-bold rounded-pill px-3 py-1 shadow-sm" style="font-size: 11px;" onclick="confirmar(${v.id}, ${d.id_producto}, ${d.cantidad}, ${d.subtotal}, '${d.descripcion.replace(/'/g, "\\'")}')"><i class="bi bi-arrow-return-left"></i> DEVOLVER</button></div>` : (dev && modo === 'operacion' ? `<div class="text-end mt-2"><span class="badge bg-secondary" style="font-size: 9px;">YA DEVUELTO</span></div>` : '')}
                </div>`;
            });
            const html = `<div class="ticket-real"><div class="centrado">${conf.logo_url ? `<img src="${conf.logo_url}" style="max-width:80px; filter:grayscale(100%); mb-1">` : ''}<h3>${conf.nombre_negocio}</h3><div class="linea"></div><p class="negrita">TICKET ORIGINAL #000${v.id}</p><p>${v.fecha}</p></div><div class="linea"></div><div>Cliente: ${v.cliente || 'C. Final'}</div><div class="linea"></div>${itemsH}<div class="linea"></div><div style="text-align:right;" class="negrita">TOTAL VENTA: $${parseFloat(v.total).toFixed(0)}</div></div>`;
            Swal.fire({ html: html, showConfirmButton: false, showCloseButton: true, width: '340px' });
        }
    });
}

function confirmar(idV, idP, cant, monto, nombre) {
    Swal.fire({
        title: 'Confirmar', text: `Devolver $${monto} de ${nombre}?`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'SÍ, DEVOLVER AHORA',
        html: `<div class="mt-3 text-start"><label class="small fw-bold">MOTIVO:</label><select id="m_f" class="form-select border-primary mt-1 shadow-sm"><option value="Reingreso">✅ Volver al Stock</option><option value="Merma">❌ Producto Dañado</option></select></div>`,
        preConfirm: () => { return document.getElementById('m_f').value; }
    }).then((res) => {
        if (res.isConfirmed) {
            const f = new FormData(); f.append('accion', 'confirmar_reintegro'); f.append('id_v', idV); f.append('id_p', idP); f.append('cant', cant); f.append('monto', monto); f.append('motivo', res.value);
            fetch('devoluciones.php', { method: 'POST', body: f }).then(r => r.json()).then(d => { if(d.status === 'success') location.reload(); });
        }
    });
}

function mandarWADev(idTicket, monto, link) {
    let msj = `Se registró un reintegro de *${monto}* (Ref: Ticket #${idTicket}).\n📄 Ver ticket original: ${link}`;
    window.open(`https://wa.me/?text=${encodeURIComponent(msj)}`, '_blank');
}
function mandarMailDevolucion(id) {
    Swal.fire({ 
        title: 'Enviar Comprobante', 
        text: 'Ingrese el correo electrónico del cliente:',
        input: 'email', 
        showCancelButton: true,
        confirmButtonText: 'ENVIAR AHORA',
        cancelButtonText: 'CANCELAR'
    }).then((r) => {
        if(r.isConfirmed && r.value) {
            Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            let fData = new FormData(); 
            fData.append('id', id); 
            fData.append('email', r.value);
            
            fetch('acciones/enviar_email_devolucion.php', { method: 'POST', body: fData })
            .then(res => res.json())
            .then(d => { 
                Swal.fire(d.status === 'success' ? 'Comprobante Enviado' : 'Error al enviar', d.msg || '', d.status); 
            });
        }
    });
}
</script>
<?php require_once 'includes/layout_footer.php'; ?>