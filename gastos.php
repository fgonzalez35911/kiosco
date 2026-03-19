<?php
// gastos.php - VERSIÓN RESTAURADA Y REPARADA (LÓGICA ORIGINAL)
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- 1. CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$rol = $_SESSION['rol'] ?? 3;
$es_admin = ($rol <= 2);

if (!$es_admin && !in_array('finanzas_registrar_gasto', $permisos)) {
    header("Location: dashboard.php"); exit; 
}

$usuario_id = $_SESSION['usuario_id'];
$stmt = $conexion->prepare("SELECT id FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta'");
$stmt->execute([$usuario_id]);
$caja = $stmt->fetch(PDO::FETCH_ASSOC);
$id_caja_sesion = $caja['id'] ?? null;

$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

// --- 2. REGISTRO DE GASTO ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!$es_admin && !in_array('finanzas_registrar_gasto', $permisos)) { die("Sin permiso para registrar salidas de dinero."); }
    if (!$id_caja_sesion) { die("Error: Caja cerrada."); }
    
    $desc = $_POST['descripcion'];
    $monto = $_POST['monto'];
    $cat = $_POST['categoria'];
    
    $stmt = $conexion->prepare("INSERT INTO gastos (descripcion, monto, categoria, fecha, id_usuario, id_caja_sesion, tipo_negocio) VALUES (?, ?, ?, NOW(), ?, ?, ?)");
    $stmt->execute([$desc, $monto, $cat, $usuario_id, $id_caja_sesion, $rubro_actual]);
    $id_gasto_nuevo = $conexion->lastInsertId();

    try {
        $detalles_audit = "Gasto registrado (#$id_gasto_nuevo) en '$cat' por $$monto. Detalle: $desc";
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha, tipo_negocio) VALUES (?, 'GASTO', ?, NOW(), ?)")->execute([$usuario_id, $detalles_audit, $rubro_actual]);
    } catch (Exception $e) { }

    header("Location: gastos.php?msg=ok"); exit;
}

// --- 3. FILTROS Y CONSULTA UNIFICADA (GASTOS + MERMAS) ---
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-2 months'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$f_cat = $_GET['categoria_filtro'] ?? '';
$f_usu = $_GET['id_usuario'] ?? '';
$buscar = trim($_GET['buscar'] ?? '');

// Filtros para Gastos
$condG = ["DATE(g.fecha) >= ?", "DATE(g.fecha) <= ?", "(g.tipo_negocio = '$rubro_actual' OR g.tipo_negocio IS NULL)"];
$paramsG = [$desde, $hasta];
if($f_cat !== '' && $f_cat !== 'Mermas') { $condG[] = "g.categoria = ?"; $paramsG[] = $f_cat; }
if($f_usu !== '') { $condG[] = "g.id_usuario = ?"; $paramsG[] = $f_usu; }
if(!empty($buscar)) { $condG[] = "(g.descripcion LIKE ? OR g.id = ?)"; array_push($paramsG, "%$buscar%", intval($buscar)); }

// Filtros para Mermas
$condM = ["DATE(m.fecha) >= ?", "DATE(m.fecha) <= ?", "m.motivo NOT LIKE 'Devolución #%'", "(m.tipo_negocio = '$rubro_actual' OR m.tipo_negocio IS NULL)"];
$paramsM = [$desde, $hasta];

if($f_usu !== '') { $condM[] = "m.id_usuario = ?"; $paramsM[] = $f_usu; }
if($f_cat !== '' && $f_cat !== 'Mermas') { $condM[] = "1=0"; } 
if(!empty($buscar)) { $condM[] = "(m.motivo LIKE ? OR m.id = ?)"; array_push($paramsM, "%$buscar%", intval($buscar)); }

$sql = "(SELECT g.id, g.descripcion, g.monto, g.categoria, g.fecha, u.usuario, u.nombre_completo, 'gasto' as tipo_registro, '' as producto_nom, 0 as cantidad, r.nombre as nombre_rol, u.id as id_op 
         FROM gastos g 
         JOIN usuarios u ON g.id_usuario = u.id 
         JOIN roles r ON u.id_rol = r.id
         WHERE " . implode(" AND ", $condG) . ")
        UNION 
        (SELECT m.id, m.motivo as descripcion, (m.cantidad * p.precio_costo) as monto, 'Mermas' as categoria, m.fecha, u.usuario, u.nombre_completo, 'merma' as tipo_registro, p.descripcion as producto_nom, m.cantidad, r.nombre as nombre_rol, u.id as id_op 
         FROM mermas m 
         JOIN usuarios u ON m.id_usuario = u.id 
         JOIN roles r ON u.id_rol = r.id
         JOIN productos p ON m.id_producto = p.id
         WHERE " . implode(" AND ", $condM) . ")
        ORDER BY fecha DESC";

$stmtG = $conexion->prepare($sql);
$stmtG->execute(array_merge($paramsG, $paramsM));
$movimientos = $stmtG->fetchAll(PDO::FETCH_ASSOC);

$totalFiltrado = 0;
foreach ($movimientos as &$g) {
    $totalFiltrado += $g['monto'];
    $g['info_extra_titulo'] = '';   
    $g['info_extra_nombre'] = '';   

    if (($g['categoria'] == 'Fidelizacion' || $g['categoria'] == 'Fidelización') && preg_match('/Cliente #(\d+)/', $g['descripcion'], $matches)) {
        $stmtC = $conexion->prepare("SELECT nombre FROM clientes WHERE id = ?");
        $stmtC->execute([$matches[1]]);
        if ($row = $stmtC->fetch(PDO::FETCH_ASSOC)) { $g['info_extra_titulo'] = 'BENEFICIARIO'; $g['info_extra_nombre'] = $row['nombre']; }
    }
    elseif ($g['categoria'] == 'Devoluciones' && preg_match('/Ticket #(\d+)/', $g['descripcion'], $matches)) {
        $stmtV = $conexion->prepare("SELECT c.nombre FROM ventas v JOIN clientes c ON v.id_cliente = c.id WHERE v.id = ?");
        $stmtV->execute([$matches[1]]);
        if ($row = $stmtV->fetch(PDO::FETCH_ASSOC)) { $g['info_extra_titulo'] = 'CLIENTE ORIG.'; $g['info_extra_nombre'] = $row['nombre']; }
    }
}

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf['color_barra_nav'] ?? '#102A57';

$ruta_firma = "img/firmas/firma_admin.png";
if (!file_exists($ruta_firma) && file_exists("img/firmas/usuario_{$usuario_id}.png")) {
    $ruta_firma = "img/firmas/usuario_{$usuario_id}.png";
}

require_once 'includes/layout_header.php'; ?>

<?php
// --- DEFINICIÓN DEL BANNER DINÁMICO ---
$titulo = "Gastos y Retiros";
$subtitulo = $conf['label_gastos'] ?? 'Control operativo de caja.';
$icono_bg = "bi-wallet2";

$query_filtros = !empty($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : "desde=$desde&hasta=$hasta";
$botones = [
    ['texto' => 'Reporte PDF', 'link' => "reporte_gastos.php?$query_filtros", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-3 px-md-4 py-2 shadow-sm', 'target' => '_blank']
];

$widgets = [
    ['label' => 'Total Egresos', 'valor' => '$'.number_format($totalFiltrado, 0), 'icono' => 'bi-cash-stack', 'border' => 'border-danger', 'icon_bg' => 'bg-danger bg-opacity-20'],
    ['label' => 'Estado Caja', 'valor' => $id_caja_sesion ? 'OPERATIVA' : 'LECTURA', 'icono' => 'bi-shield-check', 'border' => 'border-info', 'icon_bg' => 'bg-info bg-opacity-20'],
    ['label' => 'Movimientos', 'valor' => count($movimientos), 'icono' => 'bi-clock-history', 'icon_bg' => 'bg-white bg-opacity-10']
];

include 'includes/componente_banner.php'; 
?>

<style>
    @media (max-width: 768px) {
        .tabla-movil-ajustada td, .tabla-movil-ajustada th { padding: 0.4rem 0.2rem !important; font-size: 0.75rem !important; }
        .tabla-movil-ajustada .fw-bold { font-size: 0.8rem !important; }
    }
</style>

<div class="container-fluid container-md mt-n4 px-2 px-md-3" style="position: relative; z-index: 20;">
    
    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border: none !important; border-left: 5px solid #ff9800 !important;">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="row g-2 align-items-center mb-0">
                <input type="hidden" name="desde" value="<?php echo htmlspecialchars($desde); ?>">
                <input type="hidden" name="hasta" value="<?php echo htmlspecialchars($hasta); ?>">
                <input type="hidden" name="categoria_filtro" value="<?php echo htmlspecialchars($f_cat); ?>">
                <input type="hidden" name="id_usuario" value="<?php echo htmlspecialchars($f_usu); ?>">
                <div class="col-md-8 col-12 text-center text-md-start">
                    <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador Rápido</h6>
                    <p class="small mb-0 opacity-75 d-none d-md-block">Busca un registro por detalle, motivo o número de OP.</p>
                </div>
                <div class="col-md-4 col-12 text-end mt-2 mt-md-0">
                    <div class="input-group input-group-sm">
                        <input type="text" name="buscar" class="form-control border-0 fw-bold shadow-none" placeholder="Buscar..." value="<?php echo htmlspecialchars($buscar); ?>">
                        <button class="btn btn-dark px-3 shadow-none border-0" type="submit" style="border: none !important;"><i class="bi bi-arrow-right-circle-fill"></i></button>
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
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Categoría</label>
                    <select name="categoria_filtro" class="form-select form-select-sm border-light-subtle fw-bold">
                        <option value="">Todas</option>
                        <option value="Proveedores" <?php echo ($f_cat == 'Proveedores') ? 'selected' : ''; ?>>🚚 Proveedores</option>
                        <option value="Servicios" <?php echo ($f_cat == 'Servicios') ? 'selected' : ''; ?>>💡 Servicios</option>
                        <option value="Alquiler" <?php echo ($f_cat == 'Alquiler') ? 'selected' : ''; ?>>🏠 Alquiler</option>
                        <option value="Sueldos" <?php echo ($f_cat == 'Sueldos') ? 'selected' : ''; ?>>👥 Sueldos</option>
                        <option value="Retiro" <?php echo ($f_cat == 'Retiro') ? 'selected' : ''; ?>>💸 Retiro Ganancias</option>
                        <option value="Insumos" <?php echo ($f_cat == 'Insumos') ? 'selected' : ''; ?>>🧻 Insumos</option>
                        <option value="Mermas" <?php echo ($f_cat == 'Mermas') ? 'selected' : ''; ?>>📉 Mermas (Stock)</option>
                        <option value="Otros" <?php echo ($f_cat == 'Otros') ? 'selected' : ''; ?>>📦 Otros</option>
                    </select>
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
                    <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;">
                        <i class="bi bi-funnel-fill me-1"></i> FILTRAR
                    </button>
                    <a href="gastos.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;">
                        <i class="bi bi-trash3-fill me-1"></i> LIMPIAR
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="alert py-2 small mb-4 text-center fw-bold border-0 shadow-sm rounded-3" style="background-color: #e9f2ff; color: #102A57;">
        <i class="bi bi-hand-index-thumb-fill me-1"></i> Toca o haz clic en un registro para ver el comprobante
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white fw-bold py-3 text-danger"><i class="bi bi-dash-circle-fill me-2"></i> Nuevo Retiro</div>
                <div class="card-body bg-light rounded-bottom">
                    <form method="POST" id="formGasto">
                        <div class="mb-3"><label class="small fw-bold text-muted uppercase">Monto ($)</label><div class="input-group"><span class="input-group-text bg-white border-end-0 text-danger fw-bold">$</span><input type="number" step="0.01" name="monto" class="form-control form-control-lg fw-bold border-start-0 text-danger" required></div></div>
                        <div class="mb-3"><label class="small fw-bold text-muted uppercase">Descripción</label><input type="text" name="descripcion" class="form-control" required placeholder="Detalle del gasto"></div>
                        <div class="mb-4"><label class="small fw-bold text-muted uppercase">Categoría</label><select name="categoria" class="form-select"><option value="Proveedores">🚚 Proveedores</option><option value="Servicios">💡 Servicios</option><option value="Alquiler">🏠 Alquiler</option><option value="Sueldos">👥 Sueldos</option><option value="Retiro">💸 Retiro Ganancias</option><option value="Insumos">🧻 Insumos</option><option value="Otros">📦 Otros</option></select></div>
                        <button type="submit" class="btn btn-danger w-100 fw-bold py-2 shadow-sm rounded-pill">REGISTRAR SALIDA</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8 px-1 px-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 w-100 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 text-center tabla-movil-ajustada">
                        <thead class="bg-light small text-uppercase text-muted"><tr><th class="ps-4 py-3 text-start">Fecha</th><th class="text-start">Detalle</th><th class="text-end pe-4">Monto</th></tr></thead>
                        <tbody>
                            <?php foreach($movimientos as $g): 
                                $jsonData = htmlspecialchars(json_encode($g), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr style="cursor:pointer;" onclick="<?php echo $g['tipo_registro'] == 'gasto' ? "verTicket($jsonData)" : "verMerma($jsonData)"; ?>">
                                <td class="ps-4 text-start"><div class="fw-bold"><?php echo date('d/m/Y', strtotime($g['fecha'])); ?></div><small class="text-muted"><?php echo date('H:i', strtotime($g['fecha'])); ?> hs</small></td>
                                <td class="text-start">
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($g['descripcion']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($g['usuario']); ?></small>
                                </td>
                                <td class="text-end text-danger fw-bold pe-4">-$<?php echo number_format($g['monto'], 0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
<?php
$firmas_base64 = [];
$usuarios_lista_js = $conexion->query("SELECT id FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
foreach($usuarios_lista_js as $u) {
    $r = "img/firmas/usuario_{$u['id']}.png";
    if(file_exists($r)) { $firmas_base64[$u['id']] = 'data:image/png;base64,' . base64_encode(file_get_contents($r)); }
}
$ruta_adm = "img/firmas/firma_admin.png";
$firma_admin_b64 = file_exists($ruta_adm) ? 'data:image/png;base64,' . base64_encode(file_get_contents($ruta_adm)) : '';
?>
const miLocal = <?php echo json_encode($conf); ?>;
const firmasB64 = <?php echo json_encode($firmas_base64); ?>;
const firmaAdminB64 = "<?php echo $firma_admin_b64; ?>";

function verTicket(gasto) {
    let ts = Date.now();
    let montoF = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(gasto.monto);
    let fechaF = new Date(gasto.fecha).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    let linkPdfPublico = window.location.origin + window.location.pathname.replace('gastos.php', '') + "ticket_gasto_pdf.php?id=" + gasto.id;
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
            ${gasto.info_extra_nombre ? `<div class="mb-3 text-center p-2 border rounded bg-light"><small class="fw-bold d-block">${gasto.info_extra_titulo}:</small><b>${gasto.info_extra_nombre}</b></div>` : ''}
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
                <div style="width: 45%; text-align: center;">
                    <a href="${linkPdfPublico}" target="_blank">
                        <img src="${qrUrl}" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;">
                    </a>
                </div>
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

function verMerma(merma) {
    let ts = Date.now();
    let montoF = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(merma.monto);
    let fechaF = new Date(merma.fecha).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    let linkPdfPublico = window.location.origin + window.location.pathname.replace('gastos.php', '') + "ticket_merma_pdf.php?id=" + merma.id;
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
                <span style="font-size: 1.15em; font-weight:900; color: #dc3545; white-space: nowrap;">-${montoF}</span>
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

function mandarWAMerma(producto, monto, link) {
    let msj = `Se registró una Merma (pérdida) de *${producto}* por un valor de costo de *${monto}*.\n📄 Ver comprobante: ${link}`;
    window.open(`https://wa.me/?text=${encodeURIComponent(msj)}`, '_blank');
}

function mandarMailMerma(id) {
    Swal.fire({ title: 'Enviar Comprobante', text: 'Ingrese el correo del destinatario:', input: 'email', showCancelButton: true }).then((r) => {
        if(r.isConfirmed && r.value) {
            Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            let fData = new FormData(); fData.append('id', id); fData.append('email', r.value);
            fetch('acciones/enviar_email_merma.php', { method: 'POST', body: fData })
            .then(res => res.json()).then(d => { Swal.fire(d.status === 'success' ? 'Enviado' : 'Error', d.msg || '', d.status); });
        }
    });
}

function mandarWAGasto(cat, monto, link) {
    let msj = `Se registró Gasto de *${cat}* por *${monto}*.\n📄 Ticket: ${link}`;
    window.open(`https://wa.me/?text=${encodeURIComponent(msj)}`, '_blank');
}

function mandarMailGasto(id) {
    Swal.fire({ 
        title: 'Enviar Ticket', 
        text: 'Ingrese el correo electrónico del destinatario:',
        input: 'email', 
        showCancelButton: true,
        confirmButtonText: 'ENVIAR AHORA',
        cancelButtonText: 'CANCELAR'
    }).then((r) => {
        if(r.isConfirmed && r.value) {
            // Mostramos el "Enviando..."
            Swal.fire({ 
                title: 'Enviando...', 
                allowOutsideClick: false, 
                didOpen: () => { Swal.showLoading(); } 
            });

            let fData = new FormData(); 
            fData.append('id', id); 
            fData.append('email', r.value);

            fetch('acciones/enviar_email_gasto.php', { method: 'POST', body: fData })
            .then(res => res.json())
            .then(d => { 
                // Mostramos el resultado final (Éxito o Error)
                Swal.fire(
                    d.status === 'success' ? 'Enviado con éxito' : 'Error al enviar', 
                    d.msg || '', 
                    d.status
                ); 
            })
            .catch(error => {
                Swal.fire('Error', 'Hubo un problema de conexión con el servidor.', 'error');
            });
        }
    });
}
document.getElementById('formGasto')?.addEventListener('submit', function(e) {
    const cajaAbierta = <?php echo $id_caja_sesion ? 'true' : 'false'; ?>;
    if (!cajaAbierta) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Caja Cerrada',
            text: 'Debes abrir la caja antes de registrar un gasto manual.',
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'ENTENDIDO'
        });
    }
});
</script>
<?php include 'includes/layout_footer.php'; ?>