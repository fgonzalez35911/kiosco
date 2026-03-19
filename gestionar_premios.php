<?php
// gestionar_premios.php - VERSIÓN PREMIUM CON FILTROS, EDICIÓN Y REPORTE
session_start();
require_once 'includes/db.php';

// SEGURIDAD
// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);

// Candado: Acceso a la página
if (!$es_admin && !in_array('ver_premios', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

$mensaje = '';

// 1. CARGAR PRODUCTOS Y COMBOS PARA EL SELECTOR (Original)
$prods_db = $conexion->query("SELECT id, descripcion FROM productos WHERE activo=1 AND tipo != 'combo' AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL) ORDER BY descripcion")->fetchAll(PDO::FETCH_ASSOC);
$combos_db = $conexion->query("SELECT id, nombre FROM combos WHERE activo=1 AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL) ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// AUTOPATCH: Agregar columna id_usuario si no existe
try { $conexion->query("ALTER TABLE premios ADD COLUMN id_usuario INT(11) NULL DEFAULT 1"); } catch(Exception $e) {}

// 2. LÓGICA DE AGREGAR PREMIO (Original corregida)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['agregar'])) {
    if (!$es_admin && !in_array('crear_premio', $permisos)) die("Sin permiso para crear premios.");
    try {
        $nombre = $_POST['nombre'];
        $puntos = $_POST['puntos'];
        $stock = $_POST['stock'];
        $es_cupon = isset($_POST['es_cupon']) && $_POST['es_cupon'] == "1" ? 1 : 0;
        $monto = $_POST['monto_dinero'] ?? 0;
        $tipo_articulo = $_POST['tipo_articulo'] ?? 'ninguno';
        $id_articulo = null;

        if ($es_cupon == 1) {
            $tipo_articulo = 'ninguno'; $id_articulo = null;
        } else {
            if ($tipo_articulo == 'producto') {
                $id_articulo = !empty($_POST['id_articulo_prod']) ? $_POST['id_articulo_prod'] : null;
            } elseif ($tipo_articulo == 'combo') {
                $id_articulo = !empty($_POST['id_articulo_combo']) ? $_POST['id_articulo_combo'] : null;
            }
        }

        $sql = "INSERT INTO premios (nombre, puntos_necesarios, stock, es_cupon, monto_dinero, activo, id_articulo, tipo_articulo, id_usuario, tipo_negocio) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)";
        $conexion->prepare($sql)->execute([$nombre, $puntos, $stock, $es_cupon, $monto, $id_articulo, $tipo_articulo, $_SESSION['usuario_id'], $rubro_actual]);
        header("Location: gestionar_premios.php?msg=creado"); exit;
    } catch (Exception $e) { $mensaje = '<div class="alert alert-danger">Error: '.$e->getMessage().'</div>'; }
}

// 3. LÓGICA DE EDICIÓN (NUEVO CON AUDITORÍA INTELIGENTE)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit') {
    if (!$es_admin && !in_array('editar_premio', $permisos)) die("Sin permiso para editar premios.");
    try {
        $id = $_POST['id_premio'];
        $nombre = $_POST['nombre'];
        $puntos = $_POST['puntos'];
        $stock = $_POST['stock'];
        $monto = $_POST['monto_dinero'] ?? 0;
        
        // Obtener datos viejos para auditoría
        $stmtOld = $conexion->prepare("SELECT nombre, puntos_necesarios, stock, monto_dinero FROM premios WHERE id = ?");
        $stmtOld->execute([$id]);
        $old = $stmtOld->fetch(PDO::FETCH_ASSOC);

        // Detección de cambios
        $cambios = [];
        if($old['nombre'] != $nombre) $cambios[] = "Nombre: " . $old['nombre'] . " -> " . $nombre;
        if($old['puntos_necesarios'] != $puntos) $cambios[] = "Puntos: " . $old['puntos_necesarios'] . " -> " . $puntos;
        if($old['stock'] != $stock) $cambios[] = "Stock: " . $old['stock'] . " -> " . $stock;
        if(floatval($old['monto_dinero']) != floatval($monto)) $cambios[] = "Monto: $" . floatval($old['monto_dinero']) . " -> $" . floatval($monto);

        // Update real
        $sql = "UPDATE premios SET nombre=?, puntos_necesarios=?, stock=?, monto_dinero=? WHERE id=?";
        $conexion->prepare($sql)->execute([$nombre, $puntos, $stock, $monto, $id]);
        
        // Registro inteligente
        if(!empty($cambios)) {
            $detalles = "Premio Editado: " . $old['nombre'] . " | " . implode(" | ", $cambios);
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha, tipo_negocio) VALUES (?, 'PREMIO_EDITADO', ?, NOW(), ?)")->execute([$_SESSION['usuario_id'], $detalles, $rubro_actual]);
        }
        
        header("Location: gestionar_premios.php?msg=edit"); exit;
    } catch (Exception $e) { $mensaje = '<div class="alert alert-danger">Error al editar.</div>'; }
}

// 4. LÓGICA DE BORRADO (NUEVO CON AUDITORÍA EXHAUSTIVA)
if (isset($_GET['borrar'])) {
    if (!$es_admin && !in_array('eliminar_premio', $permisos)) die("Sin permiso para eliminar premios.");
    $id_borrar = intval($_GET['borrar']);
    
    // Rescatar todos los datos del premio antes de que desaparezca
    $stmtP = $conexion->prepare("SELECT nombre, puntos_necesarios, stock, es_cupon, monto_dinero FROM premios WHERE id = ?");
    $stmtP->execute([$id_borrar]);
    $premio = $stmtP->fetch(PDO::FETCH_ASSOC);
    
    if ($premio) {
        $conexion->prepare("DELETE FROM premios WHERE id = ?")->execute([$id_borrar]);
        
        $tipo_txt = $premio['es_cupon'] ? "Cupón de $".floatval($premio['monto_dinero']) : "Mercadería";
        $detalles = "Premio Eliminado: " . $premio['nombre'] . " | Costo: " . $premio['puntos_necesarios'] . " pts | Stock: " . $premio['stock'] . " | Tipo: " . $tipo_txt;
        
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha, tipo_negocio) VALUES (?, 'PREMIO_ELIMINADO', ?, NOW(), ?)")->execute([$_SESSION['usuario_id'], $detalles, $rubro_actual]);
    }
    header("Location: gestionar_premios.php?msg=borrado"); exit;
}

// 5. FILTROS Y LISTADO (Estandarizado)
$desde_p = $_GET['desde_p'] ?? '0';
$hasta_p = $_GET['hasta_p'] ?? '999999';
$buscar = trim($_GET['buscar'] ?? '');

$condiciones = ["p.puntos_necesarios BETWEEN ? AND ?", "(p.tipo_negocio = '$rubro_actual' OR p.tipo_negocio IS NULL)"];
$parametros = [$desde_p, $hasta_p];

if (!empty($buscar)) {
    $condiciones[] = "(p.nombre LIKE ? OR p.id = ?)";
    $parametros[] = "%$buscar%";
    $parametros[] = intval($buscar);
}

$sqlLista = "SELECT p.*, 
             u.nombre_completo as creador_nombre, u.usuario as creador_usuario, r.nombre as creador_rol,
             CASE 
                WHEN p.tipo_articulo = 'producto' THEN (SELECT descripcion FROM productos WHERE id = p.id_articulo)
                WHEN p.tipo_articulo = 'combo' THEN (SELECT nombre FROM combos WHERE id = p.id_articulo)
                ELSE NULL 
             END as nombre_vinculo
             FROM premios p 
             LEFT JOIN usuarios u ON p.id_usuario = u.id
             LEFT JOIN roles r ON u.id_rol = r.id
             WHERE " . implode(" AND ", $condiciones) . " 
             ORDER BY p.puntos_necesarios ASC";
$stmtL = $conexion->prepare($sqlLista);
$stmtL->execute($parametros);
$lista = $stmtL->fetchAll(PDO::FETCH_ASSOC);

try {
    $conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { }

$titulo = "Catálogo de Premios";
$subtitulo = "Gestioná los regalos para el canje de puntos.";
$icono_bg = "bi-gift-fill";

$query_filtros = !empty($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : "desde_p=$desde_p&hasta_p=$hasta_p";
$botones = [
    ['texto' => 'Reporte PDF', 'link' => "reporte_premios.php?$query_filtros", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-3 px-md-4 py-2 shadow-sm', 'target' => '_blank']
];

$widgets = [
    ['label' => 'Total Premios', 'valor' => count($lista), 'icono' => 'bi-list-check', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Stock Físico', 'valor' => array_sum(array_column(array_filter($lista, function($i) { return !$i['es_cupon']; }), 'stock')), 'icono' => 'bi-box-seam', 'border' => 'border-warning', 'icon_bg' => 'bg-warning bg-opacity-20'],
    ['label' => 'Cupones ($)', 'valor' => count(array_filter($lista, function($i) { return $i['es_cupon']; })), 'icono' => 'bi-ticket-perforated', 'border' => 'border-success', 'icon_bg' => 'bg-success bg-opacity-20']
];

$conf_color_sis = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf_color_sis['color_barra_nav'] ?? '#102A57';
require_once 'includes/layout_header.php';
include 'includes/componente_banner.php'; 
?>

<style>
    @media (max-width: 768px) {
        .tabla-movil-ajustada td, .tabla-movil-ajustada th { padding: 0.4rem 0.2rem !important; font-size: 0.7rem !important; }
        .tabla-movil-ajustada .fw-bold { font-size: 0.75rem !important; }
        .btn-accion-movil { padding: 0.2rem 0.4rem !important; font-size: 0.75rem !important; }
        /* Ocultar columnas menos relevantes en móvil para evitar scroll horizontal */
        .tabla-movil-ajustada th:nth-child(2),
        .tabla-movil-ajustada td:nth-child(2) { display: none !important; }
    }
</style>

<div class="container-fluid container-md mt-n4 px-2 px-md-3" style="position: relative; z-index: 20;">
    
    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border: none !important; border-left: 5px solid #ff9800 !important;">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="row g-2 align-items-center mb-0">
                <input type="hidden" name="desde_p" value="<?php echo htmlspecialchars($desde_p); ?>">
                <input type="hidden" name="hasta_p" value="<?php echo htmlspecialchars($hasta_p); ?>">
                <div class="col-md-8 col-12 text-center text-md-start">
                    <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador Rápido</h6>
                    <p class="small mb-0 opacity-75 d-none d-md-block">Busca un premio por nombre o ID en el catálogo.</p>
                </div>
                <div class="col-md-4 col-12 text-end mt-2 mt-md-0">
                    <div class="input-group input-group-sm">
                        <input type="text" name="buscar" class="form-control border-0 fw-bold shadow-none" placeholder="Nombre o ID..." value="<?php echo htmlspecialchars($buscar); ?>">
                        <button class="btn btn-dark px-3 shadow-none border-0" type="submit" style="border: none !important;"><i class="bi bi-arrow-right-circle-fill"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100">
                <input type="hidden" name="buscar" value="<?php echo htmlspecialchars($buscar); ?>">
                <div class="flex-grow-1" style="min-width: 140px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Puntos Mínimos</label>
                    <input type="number" name="desde_p" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $desde_p; ?>">
                </div>
                <div class="flex-grow-1" style="min-width: 140px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Puntos Máximos</label>
                    <input type="number" name="hasta_p" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $hasta_p; ?>">
                </div>
                <div class="flex-grow-0 d-flex gap-2 mt-2 mt-md-0">
                    <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;">
                        <i class="bi bi-funnel-fill me-1"></i> FILTRAR
                    </button>
                    <a href="gestionar_premios.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;">
                        <i class="bi bi-trash3-fill me-1"></i> LIMPIAR
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="alert py-2 small mb-4 text-center fw-bold border-0 shadow-sm rounded-3" style="background-color: #e9f2ff; color: #102A57;">
        <i class="bi bi-hand-index-thumb-fill me-1"></i> Toca o haz clic en un registro para ver detalles
    </div>

    <div class="row g-4 mx-0 mb-5">
        <?php if($es_admin || in_array('crear_premio', $permisos)): ?>
        <div class="col-md-4 px-1 px-md-3">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-bold py-3 border-bottom-0 text-primary"><i class="bi bi-plus-circle-fill me-2"></i> Nuevo Premio</div>
                <div class="card-body bg-light rounded-bottom">
                    <?php echo $mensaje; ?>
                    <form method="POST">
                        <input type="hidden" name="agregar" value="1">
                        <div class="mb-3">
                            <label class="small fw-bold text-muted text-uppercase">Nombre</label>
                            <input type="text" name="nombre" class="form-control form-control-lg fw-bold shadow-sm" placeholder="Ej: Coca Cola" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="small fw-bold text-muted text-uppercase">Puntos</label><input type="number" name="puntos" class="form-control" required></div>
                            <div class="col-6"><label class="small fw-bold text-muted text-uppercase">Stock</label><input type="number" name="stock" class="form-control" value="10"></div>
                        </div>
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body p-3">
                                <label class="small fw-bold text-muted mb-2">TIPO DE PREMIO</label>
                                <div class="form-check mb-2"><input class="form-check-input" type="radio" name="tipo_premio_radio" id="radioStock" checked onchange="toggleTipoPremio()"><label class="form-check-label" for="radioStock">Mercadería (Descuenta Stock)</label></div>
                                <div id="divArticulos" class="ms-3 mb-3 border-start ps-3 border-3 border-primary">
                                    <select name="tipo_articulo" class="form-select form-select-sm mb-2" id="selectTipoArt" onchange="cargarListaArticulos()">
                                        <option value="ninguno">-- Sin vinculación --</option>
                                        <option value="producto">Producto Individual</option>
                                        <option value="combo">Combo / Pack</option>
                                    </select>
                                    <select name="id_articulo_prod" id="selProd" class="form-select form-select-sm" style="display:none;"><option value="">Seleccionar Producto...</option><?php foreach($prods_db as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo $p['descripcion']; ?></option><?php endforeach; ?></select>
                                    <select name="id_articulo_combo" id="selCombo" class="form-select form-select-sm" style="display:none;"><option value="">Seleccionar Combo...</option><?php foreach($combos_db as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo $c['nombre']; ?></option><?php endforeach; ?></select>
                                </div>
                                <div class="form-check"><input class="form-check-input" type="radio" name="tipo_premio_radio" id="checkCupon" onchange="toggleTipoPremio()"><label class="form-check-label fw-bold text-success" for="checkCupon">Dinero en Cuenta ($)</label></div>
                                <div id="divMonto" style="display:none;" class="mt-2 ms-3"><div class="input-group input-group-sm"><span class="input-group-text bg-success text-white fw-bold">$</span><input type="number" step="0.01" name="monto_dinero" class="form-control text-success fw-bold" placeholder="500"></div></div>
                                <input type="hidden" name="es_cupon" id="hiddenEsCupon" value="0">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-sm">GUARDAR</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-md-8 px-1 px-md-3">
            <div class="card card-custom border-0 shadow-sm h-100 w-100">
                <div class="card-header bg-white fw-bold py-3 border-bottom d-flex justify-content-between">
                    <span><i class="bi bi-list-check me-2 text-primary"></i> Premios Disponibles</span>
                    <span class="badge bg-light text-muted border"><?php echo count($lista); ?> items</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 tabla-movil-ajustada">
                        <thead class="bg-light small text-uppercase text-muted">
                            <tr><th class="ps-4">Premio</th><th>Vinculación</th><th>Costo Puntos</th><th class="text-end pe-4">Acciones</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($lista as $p): $jsonData = htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8'); ?>
                            <tr style="cursor:pointer;" onclick='verDetallePremio(<?php echo $jsonData; ?>)'>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark">#<?php echo $p['id']; ?> - <?php echo htmlspecialchars($p['nombre']); ?></div>
                                    <div class="small text-muted"><i class="bi bi-person-fill"></i> <?php echo htmlspecialchars($p['creador_usuario'] ?? 'Sistema'); ?></div>
                                    <?php if($p['es_cupon']): ?><span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 small mt-1">$<?php echo number_format($p['monto_dinero'], 0); ?></span><?php endif; ?>
                                </td>
                                <td><?php if(!$p['es_cupon']): ?>
                                    <span class="badge bg-info bg-opacity-10 text-primary border border-info border-opacity-25"><?php echo $p['nombre_vinculo'] ?: 'Sin vincular'; ?></span>
                                    <div class="small text-muted mt-1">Stock: <?php echo $p['stock']; ?></div>
                                <?php else: ?>-<?php endif; ?></td>
                                <td><span class="badge bg-warning bg-opacity-20 text-dark rounded-pill px-3 py-2"><i class="bi bi-star-fill me-1"></i> <?php echo number_format($p['puntos_necesarios'], 0, ',', '.'); ?></span></td>
                                <td class="text-end pe-4">
                                    <?php if($es_admin || in_array('editar_premio', $permisos)): ?>
                                        <button onclick='event.stopPropagation(); editarPremio(<?php echo $jsonData; ?>)' class="btn btn-sm btn-outline-primary border-0 rounded-circle me-1"><i class="bi bi-pencil-square"></i></button>
                                    <?php endif; ?>

                                    <?php if($es_admin || in_array('eliminar_premio', $permisos)): ?>
                                        <button onclick="event.stopPropagation(); confirmarBorrado(<?php echo $p['id']; ?>)" class="btn btn-sm btn-outline-danger border-0 rounded-circle"><i class="bi bi-trash3-fill"></i></button>
                                    <?php endif; ?>
                                </td>
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
function toggleTipoPremio() {
    const esDinero = document.getElementById('checkCupon').checked;
    document.getElementById('divMonto').style.display = esDinero ? 'block' : 'none';
    document.getElementById('divArticulos').style.display = esDinero ? 'none' : 'block';
    document.getElementById('hiddenEsCupon').value = esDinero ? 1 : 0;
}
function cargarListaArticulos() {
    const tipo = document.getElementById('selectTipoArt').value;
    document.getElementById('selProd').style.display = (tipo === 'producto') ? 'block' : 'none';
    document.getElementById('selCombo').style.display = (tipo === 'combo') ? 'block' : 'none';
}

<?php
$u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
$ownerRow = $u_owner->fetch(PDO::FETCH_ASSOC);
$nombreOp = $ownerRow ? ($ownerRow['nombre_completo'] ?: 'SISTEMA') : 'SISTEMA';
$rolOp = $ownerRow ? ($ownerRow['nombre_rol'] ?: 'ADMINISTRADOR') : 'ADMINISTRADOR';
$aclaracionOp = strtoupper($nombreOp . " | " . $rolOp);

$rutaFirmaOp = "img/firmas/firma_admin.png";
if ($ownerRow && file_exists("img/firmas/usuario_{$ownerRow['id']}.png")) {
    $rutaFirmaOp = "img/firmas/usuario_{$ownerRow['id']}.png";
}
$firmaBase64 = file_exists($rutaFirmaOp) ? 'data:image/png;base64,' . base64_encode(file_get_contents($rutaFirmaOp)) : '';
?>
const confData = <?php echo json_encode([
    'nombre_negocio' => $conf['nombre_negocio'] ?? 'MI NEGOCIO',
    'direccion_local' => $conf['direccion_local'] ?? '',
    'logo_url' => $conf['logo_url'] ?? '',
    'cuit' => $conf['cuit'] ?? 'S/N'
]); ?>;

function verDetallePremio(p) {
    let ts = Date.now();
    let logoHtml = confData.logo_url ? `<img src="${confData.logo_url}?v=${ts}" style="max-height: 50px; margin-bottom: 10px;">` : '';
    let tipoTxt = p.es_cupon == 1 ? 'CUPÓN DE DINERO' : 'ARTÍCULO FÍSICO';
    let vinculoTxt = p.es_cupon == 1 ? `Monto a favor: $${p.monto_dinero}` : `Stock: ${p.stock} u. | Vínculo: ${p.nombre_vinculo || 'General'}`;

    let linkPdf = window.location.origin + window.location.pathname.replace('gestionar_premios.php', '') + "ticket_premio_pdf.php?id=" + p.id;
    let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdf);
    
    let creadorNombre = p.creador_nombre ? p.creador_nombre : (p.creador_usuario ? p.creador_usuario : 'SISTEMA');
    let creadorRol = p.creador_rol ? p.creador_rol : 'OPERADOR';
    let creadorAclaracion = (creadorNombre + ' | ' + creadorRol).toUpperCase();
    let firmaSrc = p.id_usuario ? `img/firmas/usuario_${p.id_usuario}.png` : `img/firmas/firma_admin.png`;
    let firmaHtml = `<img src="${firmaSrc}?v=${ts}" style="max-height: 80px; margin-bottom: -25px;" onerror="this.src='img/firmas/firma_admin.png'"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${creadorAclaracion}</small>`;

    const html = `
        <div id="printTicket" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
            <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                ${logoHtml}
                <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${confData.nombre_negocio}</h4>
                <small style="color: #666;">${confData.direccion_local}</small>
            </div>
            <div style="text-align: center; margin-bottom: 15px;">
                <h5 style="font-weight: 900; color: #102A57; letter-spacing: 1px; margin:0;">FICHA DE PREMIO</h5>
                <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">ID REF #${p.id}</span>
            </div>
            <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                <div style="margin-bottom: 4px;"><strong>NOMBRE:</strong> ${p.nombre.toUpperCase()}</div>
                <div style="margin-bottom: 4px;"><strong>TIPO:</strong> ${tipoTxt}</div>
                <div><strong>DETALLE:</strong> ${vinculoTxt}</div>
            </div>
            <div style="background: #102A5710; border-left: 4px solid #102A57; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <span style="font-size: 1.1em; font-weight:800;">COSTO:</span>
                <span style="font-size: 1.15em; font-weight:900; color: #102A57;">${p.puntos_necesarios} pts</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
                <div style="width: 45%; text-align: center;">${firmaHtml}</div>
                <div style="width: 45%; text-align: center;">
                    <a href="${linkPdf}" target="_blank"><img src="${qrUrl}" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;"></a>
                    <div style="font-size: 9px; margin-top: 5px;">ESCANEAR QR</div>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3 no-print">
            <button class="btn btn-sm btn-outline-dark fw-bold flex-grow-1" onclick="window.open('${linkPdf}', '_blank')"><i class="bi bi-file-earmark-pdf-fill me-1"></i> PDF</button>
            <button class="btn btn-sm btn-success fw-bold flex-grow-1" onclick="mandarWAPremio('${p.id}', '${p.nombre.replace(/'/g, "\\'")}', '${p.puntos_necesarios}', '${linkPdf}')"><i class="bi bi-whatsapp me-1"></i> WA</button>
            <button class="btn btn-sm btn-primary fw-bold flex-grow-1" style="background-color:#102A57; border-color:#102A57;" onclick="mandarMailPremio(${p.id})"><i class="bi bi-envelope-paper me-1"></i> EMAIL</button>
        </div>
    `;
    Swal.fire({ html: html, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });
}

function mandarWAPremio(id, nombre, pts, link) {
    let msj = `¡Mirá este premio de nuestro catálogo! 🎁\n*${nombre}* por solo *${pts} puntos*.\nFicha técnica: ${link}`;
    window.open(`https://wa.me/?text=${encodeURIComponent(msj)}`, '_blank');
}

function mandarMailPremio(id) {
    Swal.fire({ 
        title: 'Enviar Ficha del Premio', 
        text: 'Ingrese el correo electrónico de destino:',
        input: 'email', 
        showCancelButton: true,
        confirmButtonText: 'ENVIAR AHORA',
        cancelButtonText: 'CANCELAR',
        confirmButtonColor: '#102A57'
    }).then((r) => {
        if(r.isConfirmed && r.value) {
            Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            let fData = new FormData(); 
            fData.append('id', id); 
            fData.append('email', r.value);
            
            fetch('acciones/enviar_email_premio.php', { method: 'POST', body: fData })
            .then(res => res.json())
            .then(d => { 
                Swal.fire(d.status === 'success' ? 'Ficha Enviada' : 'Error al enviar', d.msg || '', d.status); 
            });
        }
    });
}

function editarPremio(p) {
    Swal.fire({
        title: 'Editar Premio',
        showCancelButton: true, confirmButtonText: 'Guardar',
        html: `
            <div class="text-start">
                <label class="small fw-bold">Nombre</label><input id="edit-nombre" class="form-control mb-3" value="${p.nombre}">
                <label class="small fw-bold">Puntos</label><input type="number" id="edit-puntos" class="form-control mb-3" value="${p.puntos_necesarios}">
                <label class="small fw-bold">Stock</label><input type="number" id="edit-stock" class="form-control mb-3" value="${p.stock}">
                ${p.es_cupon ? `<label class="small fw-bold">Monto ($)</label><input type="number" id="edit-monto" class="form-control" value="${p.monto_dinero}">` : ''}
            </div>`,
        preConfirm: () => {
            return {
                action: 'edit', id_premio: p.id,
                nombre: document.getElementById('edit-nombre').value,
                puntos: document.getElementById('edit-puntos').value,
                stock: document.getElementById('edit-stock').value,
                monto_dinero: p.es_cupon ? document.getElementById('edit-monto').value : 0
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form'); form.method = 'POST';
            for (const key in result.value) { const input = document.createElement('input'); input.type = 'hidden'; input.name = key; input.value = result.value[key]; form.appendChild(input); }
            document.body.appendChild(form); form.submit();
        }
    });
}

function confirmarBorrado(id) {
    Swal.fire({ title: '¿Eliminar?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Eliminar' }).then((r) => { if(r.isConfirmed) window.location.href = "gestionar_premios.php?borrar=" + id; });
}

const urlParams = new URLSearchParams(window.location.search);
if(urlParams.get('msg') === 'creado') Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Creado', timer: 2500, showConfirmButton: false });
if(urlParams.get('msg') === 'edit') Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Actualizado', timer: 2500, showConfirmButton: false });
</script>
<?php include 'includes/layout_footer.php'; ?>