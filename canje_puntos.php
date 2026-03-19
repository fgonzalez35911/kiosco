<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);

if (!$es_admin && !in_array('ver_canje_puntos', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

$stmtCaja = $conexion->query("SELECT id FROM cajas_sesion WHERE estado = 'abierta' ORDER BY id DESC LIMIT 1");
$caja = $stmtCaja->fetch(PDO::FETCH_ASSOC);
$id_caja_sesion = $caja ? $caja['id'] : 0;

// --- PROCESAMIENTO AJAX PARA PREMIOS DEL CLIENTE ---
if (isset($_GET['ajax_get_premios'])) {
    header('Content-Type: application/json');
    $id_cli = intval($_GET['ajax_get_premios']);
    $stmt = $conexion->prepare("SELECT id, nombre, puntos_acumulados FROM clientes WHERE id = ?");
    $stmt->execute([$id_cli]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $premios = $conexion->query("SELECT * FROM premios WHERE activo = 1 AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL) ORDER BY puntos_necesarios ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['cliente' => $cliente, 'premios' => $premios]);
    exit;
}

$mensaje_sweet = '';
$resultados_busqueda = [];
$cliente_seleccionado = null;

$stmtW1 = $conexion->query("SELECT COUNT(*) FROM premios WHERE activo = 1 AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)");
$totalPremios = $stmtW1->fetchColumn();

$stmtW2 = $conexion->query("SELECT COUNT(*) FROM auditoria WHERE accion = 'CANJE' AND DATE(fecha) = CURDATE() AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)");
$canjesHoy = $stmtW2->fetchColumn();

$stmtW3 = $conexion->query("SELECT SUM(puntos_acumulados) FROM clientes WHERE (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)");
$puntosTotales = $stmtW3->fetchColumn() ?: 0;

$topClientes = $conexion->query("SELECT * FROM clientes WHERE puntos_acumulados > 0 AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL) ORDER BY puntos_acumulados DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// OBTENER CONFIGURACIÓN ACTUAL Y FIRMAS
$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$ratio_actual = $conf['dinero_por_punto'] ?? 100;

$u_op = $conexion->prepare("SELECT usuario, nombre_completo FROM usuarios WHERE id = ?");
$u_op->execute([$_SESSION['usuario_id']]);
$operadorRow = $u_op->fetch(PDO::FETCH_ASSOC);

$u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
$ownerRow = $u_owner->fetch(PDO::FETCH_ASSOC);

// FILTROS DE VISTA
$stmtMin = $conexion->query("SELECT MIN(puntos_necesarios) FROM premios WHERE activo = 1 AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)");
$min_puntos_canje = $stmtMin->fetchColumn() ?: 0;

$f_puntos = $_GET['rango_puntos'] ?? '';
$cond_ranking = " WHERE puntos_acumulados > 0 AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL) ";
$params_ranking = [];

if ($f_puntos == 'canjeables') {
    $cond_ranking .= " AND puntos_acumulados >= $min_puntos_canje ";
} elseif ($f_puntos == 'vip') {
    $cond_ranking .= " AND puntos_acumulados >= 2000 "; // Ejemplo: Clientes con más de 2000 pts
}

$topClientes = $conexion->query("SELECT * FROM clientes $cond_ranking ORDER BY puntos_acumulados DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// 2. LÓGICA DE BÚSQUEDA

if (isset($_GET['q']) && !empty($_GET['q'])) {
    $q = trim($_GET['q']);
    $term = "%$q%";
    $sql = "SELECT * FROM clientes WHERE (nombre LIKE ? OR dni LIKE ? OR dni_cuit LIKE ? OR id = ?) AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL) LIMIT 20";
    $stmt = $conexion->prepare($sql);
    $stmt->execute([$term, $term, $term, $q]);
    $resultados_busqueda = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (isset($_GET['id_cliente'])) {
    $stmt = $conexion->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt->execute([$_GET['id_cliente']]);
    $cliente_seleccionado = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (isset($_POST['canjear']) && $cliente_seleccionado) {
    if (!$es_admin && !in_array('crear_canje', $permisos)) die("Sin permiso.");
    $id_cliente = $_POST['id_cliente'];
    $id_premio = $_POST['id_premio'];
    
    try {
        $conexion->beginTransaction();
        
        $stmtC = $conexion->prepare("SELECT puntos_acumulados FROM clientes WHERE id = ?");
        $stmtC->execute([$id_cliente]);
        $pts_actuales = $stmtC->fetchColumn();
        
        $stmtP = $conexion->prepare("SELECT * FROM premios WHERE id = ?");
        $stmtP->execute([$id_premio]);
        $premio = $stmtP->fetch(PDO::FETCH_ASSOC);
        
        if ($pts_actuales >= $premio['puntos_necesarios']) {
            $nuevo_saldo = $pts_actuales - $premio['puntos_necesarios'];
            $conexion->prepare("UPDATE clientes SET puntos_acumulados = ? WHERE id = ?")->execute([$nuevo_saldo, $id_cliente]);
            
            $txt_log = "";
            $detalle_receta = ""; 

            if ($premio['es_cupon'] == 1) {
                $monto = $premio['monto_dinero'];
                $conexion->prepare("UPDATE clientes SET saldo_favor = saldo_favor + ? WHERE id = ?")->execute([$monto, $id_cliente]);
                $txt_log = "Canje Cupón $$monto";
            } else {
                $costo_gasto = 0; 
                
                if ($premio['tipo_articulo'] == 'producto' && !empty($premio['id_articulo'])) {
                    $conexion->prepare("UPDATE productos SET stock_actual = stock_actual - 1 WHERE id = ?")->execute([$premio['id_articulo']]);
                    $stmtProd = $conexion->prepare("SELECT precio_costo, descripcion FROM productos WHERE id = ?");
                    $stmtProd->execute([$premio['id_articulo']]);
                    $prodData = $stmtProd->fetch(PDO::FETCH_ASSOC);
                    $costo_gasto = $prodData['precio_costo'];
                    $detalle_receta = " (Producto: " . $prodData['descripcion'] . ")";
                } 
                elseif ($premio['tipo_articulo'] == 'combo' && !empty($premio['id_articulo'])) {
                    $stmtItems = $conexion->prepare("SELECT ci.id_producto, ci.cantidad, p.precio_costo, p.descripcion FROM combo_items ci JOIN productos p ON ci.id_producto = p.id WHERE ci.id_combo = ?");
                    $stmtItems->execute([$premio['id_articulo']]);
                    $items_combo = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
                    $detalle_receta = " (Incluye: ";
                    foreach($items_combo as $item) {
                        $conexion->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?")->execute([$item['cantidad'], $item['id_producto']]);
                        $costo_gasto += ($item['precio_costo'] * $item['cantidad']);
                        $detalle_receta .= $item['descripcion'] . " x" . floatval($item['cantidad']) . ", ";
                    }
                    $detalle_receta = rtrim($detalle_receta, ", ") . ")";
                }

                $txt_log = "Canje Producto: " . $premio['nombre'];

                if ($costo_gasto > 0 && $id_caja_sesion > 0) {
                    $desc_gasto = "Costo Canje Fidelización: " . $premio['nombre'] . $detalle_receta . " | Cliente: " . $cliente_seleccionado['nombre'];
                    $conexion->prepare("INSERT INTO gastos (descripcion, monto, categoria, fecha, id_usuario, id_caja_sesion) VALUES (?, ?, 'Fidelizacion', NOW(), ?, ?)")->execute([$desc_gasto, $costo_gasto, $_SESSION['usuario_id'], $id_caja_sesion]);
                }
                $conexion->prepare("UPDATE premios SET stock = stock - 1 WHERE id = ?")->execute([$id_premio]);
            }
            
            $detalle_audit = $txt_log . $detalle_receta . " (-" . $premio['puntos_necesarios'] . " pts) | Cliente: " . $cliente_seleccionado['nombre'];
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha, tipo_negocio) VALUES (?, 'CANJE', ?, NOW(), ?)")->execute([$_SESSION['usuario_id'], $detalle_audit, $rubro_actual]);
            
            $conexion->commit();
            header("Location: canje_puntos.php?exito=1");
            exit;
        } else { throw new Exception("Puntos insuficientes."); }
    } catch (Exception $e) {
        if($conexion->inTransaction()) $conexion->rollBack();
        $mensaje_sweet = "Swal.fire('Error', '".$e->getMessage()."', 'error');";
    }
}

$premios = $conexion->query("SELECT * FROM premios WHERE activo = 1 AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL) ORDER BY puntos_necesarios ASC")->fetchAll(PDO::FETCH_ASSOC);

$titulo = "Centro de Fidelización";
$subtitulo = "Gestioná los puntos y recompensas de tus clientes.";
$icono_bg = "bi-gift-fill";

$query_filtros = !empty($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : "";
$botones = [
    ['texto' => 'Reporte PDF', 'link' => "reporte_canje_puntos.php?$query_filtros", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-3 px-md-4 py-2 shadow-sm', 'target' => '_blank']
];

if ($cliente_seleccionado) {
    array_unshift($botones, ['texto' => 'VOLVER', 'link' => "canje_puntos.php", 'icono' => 'bi-arrow-left', 'class' => 'btn btn-outline-light fw-bold rounded-pill px-4']);
}

$widgets = [
    ['label' => 'Premios Activos', 'valor' => $totalPremios, 'icono' => 'bi-award', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Canjes Hoy', 'valor' => $canjesHoy, 'icono' => 'bi-check-circle', 'border' => 'border-success', 'icon_bg' => 'bg-success bg-opacity-20'],
    ['label' => 'Valor Punto', 'valor' => '$'.number_format($ratio_actual, 2), 'icono' => 'bi-currency-dollar', 'border' => 'border-info', 'icon_bg' => 'bg-info bg-opacity-20']
];

$conf_color_sis = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf_color_sis['color_barra_nav'] ?? '#102A57';
require_once 'includes/layout_header.php';
include 'includes/componente_banner.php'; 
?>

<style>
    @media (max-width: 768px) {
        .tabla-movil-ajustada td, .tabla-movil-ajustada th { padding: 0.5rem 0.3rem !important; font-size: 0.75rem !important; }
        .tabla-movil-ajustada .fw-bold { font-size: 0.8rem !important; }
        .prize-card { margin-bottom: 10px; }
    }
    .prize-card { transition: all 0.3s ease; border-radius: 20px; border: 2px solid #eee; }
    .prize-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); border-color: #102A57; }
    .grayscale { filter: grayscale(1); }
    .client-card-header { background: #102A57; color: white; padding: 60px 20px; border-radius: 20px 20px 0 0; }
</style>

<div class="container pb-5 mt-n4" style="position: relative; z-index: 20;">

    <?php if (!$cliente_seleccionado): ?>
        <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border: none !important; border-left: 5px solid #ff9800 !important;">
            <div class="card-body p-2 p-md-3">
                <form method="GET" class="row g-2 align-items-center mb-0">
                    <input type="hidden" name="rango_puntos" value="<?php echo htmlspecialchars($f_puntos); ?>">
                    <div class="col-md-8 col-12 text-center text-md-start">
                        <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Localizar Cliente</h6>
                        <p class="small mb-0 opacity-75 d-none d-md-block">Buscá por nombre o DNI para iniciar un canje de puntos.</p>
                    </div>
                    <div class="col-md-4 col-12 text-end mt-2 mt-md-0">
                        <div class="input-group input-group-sm">
                            <input type="text" name="q" class="form-control border-0 fw-bold shadow-none" placeholder="Nombre o DNI..." value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>">
                            <button class="btn btn-dark px-3 shadow-none border-0" type="submit" style="border: none !important;"><i class="bi bi-arrow-right-circle-fill"></i></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-2 p-md-3">
                <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100">
                    <input type="hidden" name="q" value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>">
                    <div class="flex-grow-1" style="min-width: 180px;">
                        <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Nivel de Puntos</label>
                        <select name="rango_puntos" class="form-select form-select-sm border-light-subtle fw-bold">
                            <option value="">Todos con puntos</option>
                            <option value="canjeables" <?php echo ($f_puntos == 'canjeables') ? 'selected' : ''; ?>>Próximos a canjear (>= <?php echo $min_puntos_canje; ?> pts)</option>
                            <option value="vip" <?php echo ($f_puntos == 'vip') ? 'selected' : ''; ?>>Clientes VIP (>2000 pts)</option>
                        </select>
                    </div>
                    <div class="flex-grow-0 d-flex gap-2 mt-2 mt-md-0">
                        <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;">
                            <i class="bi bi-funnel-fill me-1"></i> FILTRAR
                        </button>
                        <a href="canje_puntos.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;">
                            <i class="bi bi-trash3-fill me-1"></i> LIMPIAR
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="alert py-2 small mb-4 text-center fw-bold border-0 shadow-sm rounded-3" style="background-color: #e9f2ff; color: #102A57;">
            <i class="bi bi-info-circle-fill me-1"></i> Seleccioná un cliente del ranking o usá el buscador para ver sus premios
        </div>

        <div class="row g-4 mb-5">
            <div class="col-lg-6">
                <?php if (!empty($resultados_busqueda)): ?>
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                        <div class="card-header bg-white py-3 fw-bold border-0">Resultados encontrados</div>
                        <div class="list-group list-group-flush">
                            <?php foreach ($resultados_busqueda as $cli): ?>
                                <a href="javascript:void(0)" onclick="abrirOpcionesCanje(<?php echo $cli['id']; ?>)" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                                    <div>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($cli['nombre']); ?></div>
                                        <small class="text-muted">DNI: <?php echo $cli['dni']; ?></small>
                                    </div>
                                    <span class="badge bg-warning text-dark rounded-pill px-3"><?php echo $cli['puntos_acumulados']; ?> pts</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-3 fw-bold border-0"><i class="bi bi-trophy text-warning me-2"></i> Ranking de Fidelidad</div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 tabla-movil-ajustada">
                            <tbody>
                                <?php foreach($topClientes as $tc): ?>
                                    <tr onclick="abrirOpcionesCanje(<?php echo $tc['id']; ?>)" style="cursor:pointer">
                                        <td class="ps-4 py-3"><b><?php echo htmlspecialchars($tc['nombre']); ?></b></td>
                                        <td class="text-end pe-4"><span class="badge bg-warning text-dark rounded-pill"><?php echo $tc['puntos_acumulados']; ?> pts</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6 mb-4">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100">
                    <div class="card-header bg-dark py-3 border-0"><h6 class="fw-bold mb-0 text-white">HISTORIAL DE CANJES RECIENTES</h6></div>
                    <div class="card-body p-0" style="max-height: 500px; overflow-y: auto;">
                        <div class="list-group list-group-flush">
                            <?php 
                            $sql_canjes = "SELECT a.id, u.usuario as operador, u.nombre_completo, r.nombre as nombre_rol, a.detalles, a.fecha, a.id_usuario FROM auditoria a LEFT JOIN usuarios u ON a.id_usuario = u.id LEFT JOIN roles r ON u.id_rol = r.id WHERE a.accion = 'CANJE' AND (a.tipo_negocio = '$rubro_actual' OR a.tipo_negocio IS NULL) ORDER BY a.fecha DESC LIMIT 50";
                            $res_canjes = $conexion->query($sql_canjes);
                            $ultimos_canjes = $res_canjes ? $res_canjes->fetchAll(PDO::FETCH_ASSOC) : [];
                            
                            if(empty($ultimos_canjes)): 
                            ?>
                                <div class="text-center text-muted p-4 small">No hay canjes recientes.</div>
                            <?php else: foreach($ultimos_canjes as $canje): 
                                $detalle_texto = $canje['detalles'];
                                $cliente_nombre = "Cliente General";
                                $pts = "0";
                                if (strpos($detalle_texto, '| Cliente:') !== false) {
                                    $partes = explode('| Cliente:', $detalle_texto);
                                    $detalle_texto = trim($partes[0]);
                                    $cliente_nombre = trim($partes[1]);
                                }
                                if (preg_match('/\(-(\d+)\s*pts\)/', $detalle_texto, $matches)) {
                                    $pts = $matches[1];
                                }
                                $aclaracionOp = !empty($canje['nombre_completo']) ? strtoupper($canje['nombre_completo'] . " | " . ($canje['nombre_rol'] ?? 'OPERADOR')) : strtoupper(($canje['operador'] ?? 'SISTEMA') . " | OPERADOR");
                                $rutaFirmaOp = "img/firmas/firma_admin.png";
                                if (!empty($canje['id_usuario']) && file_exists("img/firmas/usuario_" . $canje['id_usuario'] . ".png")) {
                                    $rutaFirmaOp = "img/firmas/usuario_" . $canje['id_usuario'] . ".png";
                                }
                            ?>
                                <div class="list-group-item list-group-item-action p-3" style="cursor: pointer;" onclick='abrirModalCanje(<?php echo json_encode([
                                    "id" => $canje['id'],
                                    "fecha" => date('d/m/Y H:i', strtotime($canje['fecha'])),
                                    "cliente" => $cliente_nombre,
                                    "operador" => $aclaracionOp,
                                    "detalles" => $detalle_texto,
                                    "pts" => $pts,
                                    "firma" => $rutaFirmaOp
                                ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div>
                                            <div class="fw-bold text-dark" style="font-size: 0.85rem;"><?php echo htmlspecialchars($detalle_texto); ?></div>
                                            <small class="text-muted"><i class="bi bi-calendar3 me-1"></i> <?php echo date('d/m H:i', strtotime($canje['fecha'])); ?> | <i class="bi bi-person me-1"></i> <?php echo htmlspecialchars($cliente_nombre); ?></small>
                                        </div>
                                        <div class="text-end"><div class="fw-bold text-primary">-<?php echo $pts; ?> pts</div></div>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card border-0 shadow rounded-4 overflow-hidden">
                    <div class="client-card-header text-center pb-5">
                        <i class="bi bi-person-circle display-1"></i>
                        <h4 class="fw-bold mt-2"><?php echo htmlspecialchars($cliente_seleccionado['nombre']); ?></h4>
                        <div class="badge bg-white bg-opacity-25 rounded-pill px-3">Cliente #<?php echo $cliente_seleccionado['id']; ?></div>
                    </div>
                    <div class="card-body text-center" style="margin-top: -40px;">
                        <div class="card border-0 shadow-sm mb-4 rounded-4">
                            <div class="card-body py-4">
                                <small class="fw-bold text-muted text-uppercase" style="letter-spacing: 1px;">Puntos Disponibles</small>
                                <div class="display-4 fw-bold text-warning"><?php echo number_format($cliente_seleccionado['puntos_acumulados']); ?></div>
                            </div>
                        </div>
                        <div class="text-muted small">
                            <i class="bi bi-info-circle me-1"></i> 
                            Los puntos se descuentan automáticamente al confirmar el canje.
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
                    <?php foreach($premios as $p): 
                        $alcanza = $cliente_seleccionado['puntos_acumulados'] >= $p['puntos_necesarios'];
                    ?>
                    <div class="col">
                        <div class="card prize-card h-100 <?php echo $alcanza ? 'border-success' : 'opacity-50 grayscale'; ?>">
                            <div class="card-body text-center d-flex flex-column p-4">
                                <div class="mb-3">
                                    <div class="bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 70px; height: 70px;">
                                        <i class="bi <?php echo $p['es_cupon'] ? 'bi-ticket-perforated' : 'bi-gift'; ?> h2 text-primary mb-0"></i>
                                    </div>
                                </div>
                                <h6 class="fw-bold text-dark"><?php echo htmlspecialchars($p['nombre']); ?></h6>
                                <div class="mt-auto">
                                    <h4 class="fw-bold text-success mb-3"><?php echo number_format($p['puntos_necesarios']); ?> <small style="font-size: 0.9rem;">pts</small></h4>
                                    <?php if($alcanza): ?>
                                        <button onclick="canjear(<?php echo $p['id']; ?>, '<?php echo addslashes($p['nombre']); ?>', <?php echo $p['puntos_necesarios']; ?>)" class="btn btn-success w-100 rounded-pill fw-bold py-2 shadow-sm">CANJEAR AHORA</button>
                                    <?php else: ?>
                                        <div class="bg-danger bg-opacity-10 text-danger small fw-bold py-2 rounded-pill">Faltan <?php echo number_format($p['puntos_necesarios'] - $cliente_seleccionado['puntos_acumulados']); ?> pts</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<form id="formCanje" method="POST">
    <input type="hidden" name="canjear" value="1">
    <input type="hidden" name="id_cliente" id="c_id" value="">
    <input type="hidden" name="id_premio" id="p_id">
</form>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    <?php echo $mensaje_sweet; ?>
    if(new URLSearchParams(window.location.search).get('exito') === '1') {
        Swal.fire({
            title: '¡Canje Exitoso!',
            text: 'Los puntos han sido descontados y el premio asignado correctamente.',
            icon: 'success',
            confirmButtonColor: '#102A57'
        });
    }
    const configLocal = <?php echo json_encode($conexion->query("SELECT nombre_negocio, cuit, direccion_local, logo_url FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: []); ?>;

    function abrirModalCanje(data) {
        let ts = Date.now();
        let logoHtml = configLocal.logo_url ? `<img src="${configLocal.logo_url}?v=${ts}" style="max-height: 50px; margin-bottom: 10px;">` : '';
        
        let ticketHTML = `
            <div id="printTicketCanje" style="font-family: 'Courier New', Courier, monospace; text-align: left; color: #000; padding: 10px; border: 2px dashed #ccc; border-radius: 5px; background: #fff;">
                <div style="text-align: center; border-bottom: 1px dashed #000; padding-bottom: 10px; margin-bottom: 10px;">
                    ${logoHtml}
                    <h4 style="font-weight: bold; margin: 0; text-transform: uppercase;">${configLocal.nombre_negocio || 'MI NEGOCIO'}</h4>
                    <small>CUIT: ${configLocal.cuit || 'S/N'}<br>${configLocal.direccion_local || ''}</small>
                </div>
                <div style="text-align: center; margin-bottom: 15px;">
                    <h5 style="font-weight: bold; margin:0; text-transform: uppercase;">COMPROBANTE DE CANJE</h5>
                    <span style="font-size: 12px;">TICKET #${data.id}</span>
                </div>
                <div style="font-size: 13px; margin-bottom: 10px;">
                    <strong>FECHA:</strong> ${data.fecha}<br>
                    <strong>CLIENTE:</strong> ${data.cliente.toUpperCase()}<br>
                    <strong>OPERADOR:</strong> ${data.operador.toUpperCase()}
                </div>
                <div style="border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 10px 0; margin-bottom: 15px; font-size: 13px;">
                    <strong>DETALLE DE PUNTOS:</strong><br>
                    ${data.detalles}
                </div>
                <div style="display: flex; justify-content: center; align-items: flex-end; margin-top: 40px;">
                    <div style="text-align: center; width: 80%;">
                        <div style="border-top: 1px solid #000; width: 100%;"></div>
                        <small style="font-size: 10px; font-weight: bold; margin-top: 3px; text-transform: uppercase;">${data.nombre_completo} | FIRMA OPERADOR</small>
                    </div>
                </div>
            </div>
            
            <div class="d-flex flex-wrap gap-2 mt-4 justify-content-center no-print">
                <a href="ticket_canje_puntos_pdf.php?id=${data.id}" target="_blank" class="btn btn-outline-dark fw-bold rounded-pill px-3 py-2 flex-grow-1">
                    <i class="bi bi-file-earmark-pdf-fill me-1"></i> PDF
                </a>
                <button onclick="mandarWACanje(${data.id}, '${data.cliente}')" class="btn btn-success fw-bold rounded-pill px-3 py-2 flex-grow-1">
                    <i class="bi bi-whatsapp me-1"></i> WA
                </button>
                <button onclick="mandarMailCanje(${data.id})" class="btn btn-primary fw-bold rounded-pill px-3 py-2 flex-grow-1" style="background-color: #102A57; border: none;">
                    <i class="bi bi-envelope-paper me-1"></i> EMAIL
                </button>
            </div>
        `;

        Swal.fire({
            html: ticketHTML,
            width: 400,
            showConfirmButton: false,
            showCloseButton: true,
            background: '#fff'
        });
    }

    function mandarWACanje(id, cliente) {
        let link = window.location.origin + window.location.pathname.replace('canje_puntos.php', '') + "ticket_canje_puntos_pdf.php?id=" + id;
        let msj = `Hola ${cliente}! Acá tenés el comprobante de tu canje de puntos (Ticket #${id}). Podés verlo en este enlace: ${link}`;
        window.open(`https://wa.me/?text=${encodeURIComponent(msj)}`, '_blank');
    }

    function mandarMailCanje(id) {
        Swal.fire({ 
            title: 'Enviar Comprobante', 
            text: 'Ingrese el correo electrónico del cliente:',
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
                
                fetch('acciones/enviar_email_canje.php', { method: 'POST', body: fData })
                .then(res => res.json())
                .then(d => {
                    if(d.status === 'success') Swal.fire('¡Enviado!', 'Correo enviado con éxito.', 'success');
                    else Swal.fire('Error', d.msg || 'No se pudo enviar', 'error');
                }).catch(() => Swal.fire('Error', 'Problema de red.', 'error'));
            }
        });
    }

    const confData = <?php echo json_encode([
        'nombre_negocio' => $conf['nombre_negocio'] ?? 'MI NEGOCIO',
        'direccion_local' => $conf['direccion_local'] ?? '',
        'logo_url' => $conf['logo_url'] ?? ''
    ]); ?>;

    function abrirModalCanje(data) {
        let linkPdfReintegro = window.location.origin + window.location.pathname.replace('canje_puntos.php', '') + "ticket_canje_puntos_pdf.php?id=" + data.id;
        let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdfReintegro);
        let logoHtml = confData.logo_url ? `<img src="${confData.logo_url}?v=${Date.now()}" style="max-height: 50px; margin-bottom: 10px;">` : '';
        
        let firmaHtml = `<img src="${data.firma}?v=${Date.now()}" style="max-height: 80px; margin-bottom: -25px;" onerror="this.style.display='none'"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${data.operador}</small>`;

        const html = `
            <div id="printTicket" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
                <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                    ${logoHtml}
                    <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${confData.nombre_negocio}</h4>
                    <small style="color: #666;">${confData.direccion_local}</small>
                </div>
                <div style="text-align: center; margin-bottom: 15px;">
                    <h5 style="font-weight: 900; color: #102A57; letter-spacing: 1px; margin:0;">COMPROBANTE DE CANJE</h5>
                    <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">TICKET ORIGEN #${data.id}</span>
                </div>
                <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                    <div style="margin-bottom: 4px;"><strong>FECHA CANJE:</strong> ${data.fecha}</div>
                    <div style="margin-bottom: 4px;"><strong>CLIENTE:</strong> ${data.cliente}</div>
                    <div><strong>OPERADOR:</strong> ${data.operador}</div>
                </div>
                <div style="margin-bottom: 15px; font-size: 13px;">
                    <strong style="border-bottom: 1px solid #ccc; display:block; margin-bottom:5px;">DETALLE DE CANJE:</strong>
                    <div style="display:flex; justify-content:space-between; margin-bottom: 5px;">
                        <span>${data.detalles.replace(/\(-\d+\s*pts\)/g, '')}</span>
                    </div>
                </div>
                <div style="background: #102A5710; border-left: 4px solid #102A57; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                    <span style="font-size: 1.1em; font-weight:800;">PUNTOS:</span>
                    <span style="font-size: 1.15em; font-weight:900; color: #102A57;">-${data.pts} pts</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
                    <div style="width: 45%; text-align: center;">${firmaHtml}</div>
                    <div style="width: 45%; text-align: center;">
                        <a href="${linkPdfReintegro}" target="_blank"><img src="${qrUrl}" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;"></a>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3 no-print">
                <button class="btn btn-sm btn-outline-dark fw-bold" onclick="window.open('${linkPdfReintegro}', '_blank')">PDF</button>
                <button class="btn btn-sm btn-success fw-bold" onclick="mandarWACanje('${data.id}', '${data.pts}', '${linkPdfReintegro}', '${data.cliente}')">WA</button>
                <button class="btn btn-sm btn-primary fw-bold" style="background-color:#102A57; border-color:#102A57;" onclick="mandarMailCanje(${data.id})">EMAIL</button>
            </div>
        `;
        Swal.fire({ html: html, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });
    }

    function mandarWACanje(idTicket, pts, link, cliente) {
        let msj = `Hola ${cliente}! Se registró un canje por *${pts} puntos* (Ref: Ticket #${idTicket}).\n📄 Ver ticket de canje: ${link}`;
        window.open(`https://wa.me/?text=${encodeURIComponent(msj)}`, '_blank');
    }

    function mandarMailCanje(id) {
        Swal.fire({ 
            title: 'Enviar Comprobante', 
            text: 'Ingrese el correo electrónico del cliente:',
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
                
                fetch('acciones/enviar_email_canje.php', { method: 'POST', body: fData })
                .then(res => res.json())
                .then(d => { 
                    Swal.fire(d.status === 'success' ? 'Comprobante Enviado' : 'Error al enviar', d.msg || '', d.status); 
                });
            }
        });
    }

    function canjear(id_premio, nom, pts, id_cliente) {
        Swal.fire({
            title: '¿Confirmar Canje?',
            text: `Vas a canjear ${pts} puntos por: ${nom}`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'SÍ, CANJEAR',
            cancelButtonText: 'CANCELAR',
            confirmButtonColor: '#198754',
            cancelButtonColor: '#6c757d',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('p_id').value = id_premio;
                document.getElementById('c_id').value = id_cliente;
                document.getElementById('formCanje').submit();
            }
        });
    }

    function abrirOpcionesCanje(id_cliente) {
        Swal.fire({ title: 'Cargando recompensas...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
        fetch(`canje_puntos.php?ajax_get_premios=${id_cliente}`)
        .then(r => r.json())
        .then(data => {
            const cli = data.cliente;
            const premios = data.premios;
            
            if(!cli) {
                Swal.fire('Error', 'No se pudo cargar el cliente.', 'error');
                return;
            }

            let cardsHtml = '<div class="row row-cols-1 row-cols-md-2 g-3 mt-1 text-start">';
            
            premios.forEach(p => {
                let alcanza = parseInt(cli.puntos_acumulados) >= parseInt(p.puntos_necesarios);
                let btnHtml = alcanza 
                    ? `<button onclick="Swal.close(); setTimeout(() => canjear(${p.id}, '${p.nombre.replace(/'/g, "\\'")}', ${p.puntos_necesarios}, ${cli.id}), 300);" class="btn btn-success w-100 rounded-pill fw-bold py-2 shadow-sm" style="font-size: 0.85rem;">CANJEAR AHORA</button>` 
                    : `<div class="bg-danger bg-opacity-10 text-danger small fw-bold py-2 rounded-pill text-center">Faltan ${p.puntos_necesarios - cli.puntos_acumulados} pts</div>`;
                
                let opacityClass = alcanza ? 'border-success' : 'opacity-50 grayscale';
                let iconClass = p.es_cupon == 1 ? 'bi-ticket-perforated' : 'bi-gift';
                
                cardsHtml += `
                <div class="col">
                    <div class="card h-100 ${opacityClass}" style="border: 2px solid #eee; border-radius: 15px; transition: all 0.3s ease;">
                        <div class="card-body text-center p-3 d-flex flex-column">
                            <div class="mb-2">
                                <div class="bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 50px; height: 50px;">
                                    <i class="bi ${iconClass} fs-4 text-primary mb-0"></i>
                                </div>
                            </div>
                            <h6 class="fw-bold text-dark text-truncate" style="font-size: 0.9rem;" title="${p.nombre}">${p.nombre}</h6>
                            <div class="mt-auto pt-2">
                                <h4 class="fw-bold text-success mb-2">${p.puntos_necesarios} <small style="font-size: 0.7rem;">pts</small></h4>
                                ${btnHtml}
                            </div>
                        </div>
                    </div>
                </div>`;
            });
            
            cardsHtml += '</div>';

            let modalHtml = `
                <div class="text-center mb-2">
                    <i class="bi bi-person-circle display-4 text-primary"></i>
                    <h4 class="fw-bold mt-2 mb-0">${cli.nombre}</h4>
                    <div class="badge bg-warning text-dark rounded-pill px-3 mt-2 mb-2" style="font-size: 1.1rem; border: 1px solid #ff9800;">
                        <i class="bi bi-star-fill me-1"></i> ${cli.puntos_acumulados} Puntos Disponibles
                    </div>
                </div>
                <div style="max-height: 55vh; overflow-y: auto; overflow-x: hidden; padding: 5px;" class="custom-scrollbar">
                    ${cardsHtml}
                </div>
                <style>
                    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
                    .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
                    .custom-scrollbar::-webkit-scrollbar-thumb { background: #102A57; border-radius: 10px; }
                    .grayscale { filter: grayscale(1); }
                </style>
            `;

            Swal.fire({
                html: modalHtml,
                width: 650,
                showConfirmButton: false,
                showCloseButton: true,
                background: '#fff',
                padding: '1.5rem'
            });
        }).catch(() => Swal.fire('Error', 'Problema de conexión al cargar premios.', 'error'));
    }
</script>

<?php include 'includes/layout_footer.php'; ?>