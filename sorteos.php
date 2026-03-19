<?php
// sorteos.php - VERSIÓN ESTANDARIZADA VANGUARD PRO
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);
if (!$es_admin && !in_array('mkt_ver_sorteos', $permisos)) { header("Location: dashboard.php"); exit; }

// --- PARCHE AUTOMÁTICO DE BASE DE DATOS ---
try {
    $conexion->query("ALTER TABLE sorteos ADD COLUMN id_usuario INT(11) DEFAULT NULL AFTER estado");
    $conexion->query("UPDATE sorteos SET id_usuario = 1 WHERE id_usuario IS NULL");
} catch (Exception $e) { /* La columna ya existe, continuamos. */ }

$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

// 1. PROCESAR CREACIÓN DE SORTEO (Estado inicial: pendiente)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_sorteo'])) {
    if (!$es_admin && !in_array('mkt_crear_sorteo', $permisos)) die("Sin permiso para crear sorteos.");
    $titulo = $_POST['titulo'];
    $precio = $_POST['precio'];
    $cant = $_POST['cantidad'];
    $fecha = $_POST['fecha'];
    $descripcion = $_POST['descripcion'] ?? '';
    
    $stmt = $conexion->prepare("INSERT INTO sorteos (titulo, descripcion, precio_ticket, cantidad_tickets, fecha_sorteo, estado, id_usuario, tipo_negocio) VALUES (?, ?, ?, ?, ?, 'pendiente', ?, ?)");
    $stmt->execute([$titulo, $descripcion, $precio, $cant, $fecha, $_SESSION['usuario_id'], $rubro_actual]);
    $idSorteo = $conexion->lastInsertId();
    
    if(isset($_POST['premios_simulados']) && !empty($_POST['premios_simulados'])) {
        $premios = json_decode($_POST['premios_simulados'], true);
        if(is_array($premios)) {
            $stmtPremio = $conexion->prepare("INSERT INTO sorteo_premios (id_sorteo, posicion, tipo, id_producto, descripcion_externa, costo_externo) VALUES (?, ?, ?, ?, ?, ?)");
            $pos = 1;
            foreach($premios as $p) {
                $tipo = ($p['tipo'] === 'manual') ? 'externo' : 'interno';
                $idProd = ($p['tipo'] !== 'manual') ? $p['id'] : NULL;
                $desc = ($p['tipo'] === 'manual') ? $p['nombre'] : NULL;
                $stmtPremio->execute([$idSorteo, $pos, $tipo, $idProd, $desc, $p['costo']]);
                $pos++;
            }
        }
    }
    $d_aud = "Sorteo Creado: " . $titulo . " | Precio Tkt: $" . $precio; 
    $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha, tipo_negocio) VALUES (?, 'SORTEO_NUEVO', ?, NOW(), ?)")->execute([$_SESSION['usuario_id'], $d_aud, $rubro_actual]);
    header("Location: detalle_sorteo.php?id=$idSorteo&msg=creado"); exit;
}

// 2. FILTROS VANGUARD PRO Y CONSULTAS
$desde = $_GET['desde'] ?? date('Y-01-01');
$hasta = $_GET['hasta'] ?? date('Y-12-31');
$buscar = trim($_GET['buscar'] ?? '');
$f_usu = $_GET['id_usuario'] ?? '';

$cond = ["DATE(s.fecha_sorteo) >= ?", "DATE(s.fecha_sorteo) <= ?", "(s.tipo_negocio = '$rubro_actual' OR s.tipo_negocio IS NULL)"];
$params = [$desde, $hasta];

if (!empty($buscar)) {
    $cond[] = "s.titulo LIKE ?";
    $params[] = "%$buscar%";
}
if ($f_usu !== '') {
    $cond[] = "s.id_usuario = ?";
    $params[] = $f_usu;
}

$sqlSorteos = "SELECT s.*, u.usuario as creador FROM sorteos s LEFT JOIN usuarios u ON s.id_usuario = u.id WHERE " . implode(" AND ", $cond) . " ORDER BY FIELD(s.estado, 'pendiente', 'activo', 'finalizado', 'cancelado'), s.fecha_sorteo DESC";
$stmtS = $conexion->prepare($sqlSorteos);
$stmtS->execute($params);
$sorteos = $stmtS->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Productos para el simulador (Traemos Costo, Público y Código de Barras)
$sqlProds = "SELECT p.id, p.descripcion, p.tipo, p.precio_costo, p.precio_venta, p.precio_oferta, p.codigo_barras,
            (SELECT COALESCE(SUM(prod_hijo.precio_costo * ci.cantidad), 0) FROM combo_items ci JOIN combos c ON c.id = ci.id_combo JOIN productos prod_hijo ON ci.id_producto = prod_hijo.id WHERE c.codigo_barras = p.codigo_barras) as costo_combo_calculado
            FROM productos p WHERE p.activo = 1 AND (p.tipo_negocio = '$rubro_actual' OR p.tipo_negocio IS NULL) ORDER BY p.descripcion ASC";
$rawProds = $conexion->query($sqlProds)->fetchAll(PDO::FETCH_ASSOC);
$prods_simulador = [];
foreach($rawProds as $p) {
    $p['costo_real'] = ($p['tipo'] === 'combo' && $p['costo_combo_calculado'] > 0) ? $p['costo_combo_calculado'] : $p['precio_costo'];
    $p['precio_publico'] = (floatval($p['precio_oferta']) > 0) ? $p['precio_oferta'] : $p['precio_venta'];
    $prods_simulador[] = $p;
}

// KPIs PARA WIDGETS
$total_sorteos = count($sorteos);
$activas = 0; foreach($sorteos as $s) { if($s['estado'] == 'activo') $activas++; }
$total_vendidos = $conexion->query("SELECT COUNT(*) FROM sorteo_tickets WHERE (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)")->fetchColumn() ?: 0;
$historial_ganadores = $conexion->query("SELECT id, titulo, ganadores_json FROM sorteos WHERE estado = 'finalizado' AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL) ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

$color_sistema = '#102A57';
try { $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1"); if ($resColor) { $dataC = $resColor->fetch(PDO::FETCH_ASSOC); if (isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav']; } } catch (Exception $e) { }

include 'includes/layout_header.php'; 

// --- DEFINICIÓN DEL BANNER DINÁMICO ESTANDARIZADO ---
$titulo = "Sorteos y Rifas";
$subtitulo = "Gestión profesional con control de stock y caja.";
$icono_bg = "bi-ticket-perforated";

$query_filtros = "desde=$desde&hasta=$hasta&id_usuario=$f_usu&buscar=$buscar";
$botones = [
    ['texto' => 'Reporte PDF', 'link' => "reporte_sorteos.php?$query_filtros", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-3 px-md-4 py-2 shadow-sm', 'target' => '_blank']
];

$widgets = [
    ['label' => 'Campañas', 'valor' => $total_sorteos, 'icono' => 'bi-collection', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Rifas Activas', 'valor' => $activas, 'icono' => 'bi-check-circle', 'icon_bg' => 'bg-success bg-opacity-20'],
    ['label' => 'Tickets Vendidos', 'valor' => $total_vendidos, 'icono' => 'bi-ticket-detailed', 'border' => 'border-warning', 'icon_bg' => 'bg-warning bg-opacity-20']
];

include 'includes/componente_banner.php'; 
?>

<div class="container-fluid mt-n4 pb-5 px-2 px-md-4" style="position: relative; z-index: 20;">
    
    <style>
        .filter-bar.sticky-desktop { position: sticky; top: 65px; z-index: 1000; background: #fff; margin-bottom: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        @media (max-width: 768px) { 
            .filter-bar.sticky-desktop { top: 10px; position: relative; padding: 10px; } 
            .filter-content-wrapper { display: none; flex-direction: column; gap: 10px; padding-top: 15px; }
            .filter-content-wrapper.show { display: flex; }
            .search-group, .filter-select { width: 100% !important; }
        }
        @media (min-width: 769px) {
            .filter-content-wrapper { display: flex !important; width: 100%; gap: 10px; align-items: center; }
            .btn-toggle-filters { display: none; }
        }
    </style>

    <?php $usuarios_lista = $conexion->query("SELECT id, usuario FROM usuarios ORDER BY usuario ASC")->fetchAll(PDO::FETCH_ASSOC); ?>

    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border-left: 5px solid #ff9800 !important;">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="row g-2 align-items-center mb-0">
                <input type="hidden" name="desde" value="<?php echo htmlspecialchars($desde); ?>">
                <input type="hidden" name="hasta" value="<?php echo htmlspecialchars($hasta); ?>">
                <input type="hidden" name="id_usuario" value="<?php echo htmlspecialchars($f_usu); ?>">
                
                <div class="col-md-8 col-12 text-center text-md-start">
                    <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador Rápido</h6>
                    <p class="small mb-0 opacity-75 d-none d-md-block">Ingresá el nombre de la campaña o rifa para encontrarla.</p>
                </div>
                <div class="col-md-4 col-12 text-end">
                    <div class="input-group input-group-sm">
                        <input type="text" name="buscar" class="form-control border-0 fw-bold shadow-none" placeholder="Buscar sorteo..." value="<?php echo htmlspecialchars($buscar); ?>">
                        <button class="btn btn-dark px-3 shadow-none border-0" type="submit"><i class="bi bi-arrow-right-circle-fill"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100" id="wrapperFiltros">
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
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Responsable</label>
                    <select name="id_usuario" class="form-select form-select-sm border-light-subtle fw-bold">
                        <option value="">Todos</option>
                        <?php foreach($usuarios_lista as $usu): ?>
                            <option value="<?php echo $usu['id']; ?>" <?php echo ($f_usu == $usu['id']) ? 'selected' : ''; ?>><?php echo strtoupper($usu['usuario']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex-grow-0 d-flex gap-2 mt-2 mt-md-0">
                    <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;">
                        <i class="bi bi-funnel-fill me-1"></i> FILTRAR
                    </button>
                    <a href="sorteos.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;">
                        <i class="bi bi-trash3-fill me-1"></i> LIMPIAR
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 mt-2 px-1 gap-3">
        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-stars text-primary me-2"></i> Listado de Campañas</h5>
        <div class="d-flex gap-2">
            <button class="btn btn-info btn-sm text-white fw-bold shadow-sm rounded-pill px-3 py-1" data-bs-toggle="modal" data-bs-target="#modalGanadores">
                <i class="bi bi-trophy-fill me-1"></i> GANADORES
            </button>
            <?php if($es_admin || in_array('mkt_crear_sorteo', $permisos)): ?>
            <button class="btn btn-primary btn-sm fw-bold shadow-sm rounded-pill px-3 py-1" data-bs-toggle="modal" data-bs-target="#modalNuevoSorteo">
                <i class="bi bi-plus-circle-fill me-1"></i> NUEVA RIFA
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4 pb-5" id="gridProductos">
        <?php if(count($sorteos) === 0): ?>
            <div class="col-12 text-center py-5 text-muted">
                <i class="bi bi-ticket-perforated opacity-25" style="font-size: 5rem;"></i>
                <h5 class="mt-3">No hay sorteos creados con estos filtros.</h5>
            </div>
        <?php endif; ?>
        
        <?php foreach($sorteos as $s): 
            $vendidos = $conexion->query("SELECT COUNT(*) FROM sorteo_tickets WHERE id_sorteo = {$s['id']}")->fetchColumn();
            $progreso = ($s['cantidad_tickets'] > 0) ? ($vendidos / $s['cantidad_tickets']) * 100 : 0;
            
            if($s['estado'] == 'activo') { $color_borde = '#198754'; $badge = 'bg-success'; } 
            elseif($s['estado'] == 'pendiente') { $color_borde = '#ffc107'; $badge = 'bg-warning text-dark'; } 
            else { $color_borde = '#6c757d'; $badge = 'bg-secondary'; }
        ?>
        <div class="col-12 col-md-6 col-xl-4 item-grid">
            <div class="card card-sorteo h-100 shadow-sm rounded-4 border-0" style="border-top: 5px solid <?php echo $color_borde; ?> !important; overflow: hidden; background: #fff;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between mb-3 align-items-center">
                        <span class="badge <?php echo $badge; ?> rounded-pill px-3 py-2 fw-bold shadow-sm"><?php echo strtoupper($s['estado']); ?></span>
                        <small class="text-muted fw-bold"><i class="bi bi-calendar-event"></i> <?php echo date('d/m/y', strtotime($s['fecha_sorteo'])); ?></small>
                    </div>
                    <h5 class="fw-bold text-dark mb-1 text-truncate" style="font-family: 'Oswald', sans-serif; font-size: 1.3rem;"><?php echo htmlspecialchars($s['titulo']); ?></h5>
                    <div class="small text-muted mb-3"><i class="bi bi-person-fill"></i> Op: <strong><?php echo $s['creador'] ? strtoupper($s['creador']) : 'S/D'; ?></strong></div>
                    
                    <div class="d-flex align-items-end mb-3">
                        <h2 class="text-primary fw-bold mb-0 me-2">$<?php echo number_format($s['precio_ticket'], 0, ',', '.'); ?></h2>
                        <span class="text-muted fw-bold pb-1">/ TICKET</span>
                    </div>
                    
                    <div class="mt-4 p-3 bg-light rounded-3">
                        <div class="d-flex justify-content-between small fw-bold mb-2">
                            <span class="text-dark">Vendidos: <?php echo $vendidos; ?></span>
                            <span class="text-muted">Total: <?php echo $s['cantidad_tickets']; ?></span>
                        </div>
                        <div class="progress shadow-sm" style="height: 10px; border-radius: 5px;">
                            <div class="progress-bar" role="progressbar" style="width: <?php echo $progreso; ?>%; background-color: <?php echo $color_borde; ?>"></div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-white border-top p-3 text-center">
                    <a href="detalle_sorteo.php?id=<?php echo $s['id']; ?>" class="btn btn-outline-primary w-100 rounded-pill fw-bold shadow-sm"><i class="bi bi-gear-fill me-1"></i> ADMINISTRAR</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div id="vistaListaGenerica" class="d-none mt-2 pb-5">
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 tabla-movil-ajustada">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="ps-4">SORTEO</th>
                            <th>ESTADO / FECHA</th>
                            <th class="text-center d-none d-md-table-cell">PROGRESO</th>
                            <th class="text-end pe-4">ACCIÓN</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($sorteos) === 0): ?>
                            <tr><td colspan="4" class="text-center py-5 text-muted">No hay sorteos creados en este filtro.</td></tr>
                        <?php else: ?>
                            <?php foreach($sorteos as $s): 
                                $vendidos = $conexion->query("SELECT COUNT(*) FROM sorteo_tickets WHERE id_sorteo = {$s['id']}")->fetchColumn();
                                $progreso = ($s['cantidad_tickets'] > 0) ? ($vendidos / $s['cantidad_tickets']) * 100 : 0;
                                
                                if($s['estado'] == 'activo') { $badge = 'bg-success'; $color_bar = 'bg-success'; } 
                                elseif($s['estado'] == 'pendiente') { $badge = 'bg-warning text-dark'; $color_bar = 'bg-warning'; } 
                                else { $badge = 'bg-secondary'; $color_bar = 'bg-secondary'; }
                            ?>
                            <tr onclick="window.location.href='detalle_sorteo.php?id=<?php echo $s['id']; ?>'" style="cursor: pointer;">
                                <td class="ps-4 py-3">
                                    <div class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($s['titulo']); ?></div>
                                    <div class="text-primary fw-bold mt-1">$<?php echo number_format($s['precio_ticket'], 0, ',', '.'); ?> <span class="small text-muted fw-normal">/ticket</span></div>
                                </td>
                                <td>
                                    <span class="badge <?php echo $badge; ?> fw-bold px-2 py-1 mb-1 shadow-sm"><?php echo strtoupper($s['estado']); ?></span>
                                    <div class="small text-muted"><i class="bi bi-calendar-event me-1"></i><?php echo date('d/m/y', strtotime($s['fecha_sorteo'])); ?></div>
                                </td>
                                <td class="text-center d-none d-md-table-cell" style="width: 25%;">
                                    <div class="small fw-bold text-dark mb-1"><?php echo $vendidos; ?> / <?php echo $s['cantidad_tickets']; ?></div>
                                    <div class="progress mx-auto shadow-sm" style="height: 6px; border-radius: 5px; max-width: 150px;">
                                        <div class="progress-bar <?php echo $color_bar; ?>" style="width: <?php echo $progreso; ?>%"></div>
                                    </div>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="detalle_sorteo.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-primary border-0 rounded-circle shadow-sm" onclick="event.stopPropagation();"><i class="bi bi-gear-fill"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalGanadores" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content rounded-4 border-0">
        <div class="modal-header bg-dark text-white border-0 py-3"><h5 class="modal-title fw-bold">Últimos Ganadores</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead class="bg-light small uppercase"><tr><th class="ps-4">Sorteo</th><th>Ganador</th><th>Premio</th><th class="pe-4 text-end">WA</th></tr></thead>
            <tbody><?php foreach($historial_ganadores as $hg): $ganadores = json_decode($hg['ganadores_json'], true); if(is_array($ganadores)): foreach($ganadores as $g): 
                $num_wa = preg_replace('/[^0-9]/', '', $g['telefono'] ?? '');
            ?>
                <tr><td class="ps-4"><small class="fw-bold"><?php echo $hg['titulo']; ?></small></td><td><div class="fw-bold"><?php echo $g['cliente']; ?></div><small class="text-muted">Ticket #<?php echo $g['ticket']; ?></small></td><td><span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25"><?php echo $g['premio']; ?></span></td><td class="pe-4 text-end"><?php if(!empty($num_wa)): ?><a href="https://wa.me/<?php echo $num_wa; ?>?text=<?php echo urlencode("¡Hola ".$g['cliente']."! 🏆 Sos el ganador del sorteo ".$hg['titulo'].". Ganaste: ".$g['premio'].". Te esperamos en la tienda 🎉"); ?>" target="_blank" class="btn btn-sm btn-success rounded-circle shadow-sm"><i class="bi bi-whatsapp"></i></a><?php else: ?>-<?php endif; ?></td></tr>
            <?php endforeach; endif; endforeach; ?></tbody>
        </table></div></div>
    </div></div>
</div>

<div class="modal fade" id="modalNuevoSorteo" tabindex="-1">
    <div class="modal-dialog modal-xl"><form method="POST" class="modal-content rounded-4 border-0" id="formCrearSorteo">
        <input type="hidden" name="premios_simulados" id="input_premios_simulados">
        <div class="modal-header border-0 bg-light"><h5 class="modal-title fw-bold">Nueva Rifa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row">
            <div class="col-md-4 border-end">
                <h6 class="text-muted fw-bold mb-3 small uppercase">1. Configuración</h6>
                <div class="mb-3"><label class="form-label fw-bold">Título</label><input type="text" name="titulo" class="form-control" required></div>
                <div class="mb-3"><label class="form-label fw-bold">Descripción</label><textarea name="descripcion" class="form-control" rows="2"></textarea></div>
                <div class="row">
                    <div class="col-6 mb-3"><label class="form-label fw-bold">Precio Ticket</label><input type="number" name="precio" id="sim_precio" class="form-control" required step="0.01" oninput="calcularSimulacion()"></div>
                    <div class="col-6 mb-3"><label class="form-label fw-bold">Cant. Tickets</label><input type="number" name="cantidad" id="sim_cantidad" class="form-control" value="100" required oninput="calcularSimulacion()"></div>
                </div>
                <div class="mb-3"><label class="form-label fw-bold">Fecha Sorteo</label><input type="date" name="fecha" class="form-control" required></div>
            </div>
            <div class="col-md-8">
                <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="text-primary fw-bold m-0 small uppercase"><i class="bi bi-gift"></i> Premios y Simulador</h6><button type="button" class="btn btn-sm btn-outline-success fw-bold" onclick="agregarFilaPremio()"><i class="bi bi-plus-lg"></i> Agregar</button></div>
                <div class="simulador-box"><div id="contenedor_premios"></div></div>
                <div id="resultado_sim" class="resultado-simulacion bg-light text-muted">Defina precio y premios...</div>
                <div class="mt-3 small row">
                    <div class="col-6 text-center border-end"><span class="d-block text-muted">Ingreso Bruto</span><span class="fs-5 fw-bold text-dark" id="txt_recaudacion">$0</span></div>
                    <div class="col-6 text-center"><span class="d-block text-muted">Costo Stock</span><span class="fs-5 fw-bold text-danger" id="txt_costos">$0</span></div>
                </div>
            </div>
        </div></div>
        <div class="modal-footer border-0"><button type="submit" name="crear_sorteo" class="btn btn-primary w-100 rounded-pill fw-bold" onclick="prepararEnvio()">GUARDAR COMO PENDIENTE</button></div>
    </form></div>
</div>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
const productosSimulador = <?php echo json_encode($prods_simulador); ?>;
let contadorPremios = 0;
// Motor oculto para buscar por código de barras sin mostrarlo en el texto
function matchPorCodigoOTexto(params, data) {
    if ($.trim(params.term) === '') return data;
    if (typeof data.text === 'undefined') return null;
    let busqueda = params.term.toLowerCase();
    let textoVisible = data.text.toLowerCase();
    let codigoBarra = data.element ? (data.element.getAttribute('data-codigo') || '').toLowerCase() : '';
    
    // Busca coincidencias en el nombre o en el código de barras oculto
    if (textoVisible.indexOf(busqueda) > -1 || codigoBarra.indexOf(busqueda) > -1) {
        return data;
    }
    return null;
}

function agregarFilaPremio() {
    contadorPremios++;
    let optionsHtml = '<option value="0" data-costo="0" data-codigo="">Buscar o escanear...</option>';
    productosSimulador.forEach(p => { 
        let costo = parseFloat(p.costo_real).toLocaleString('es-AR');
        let publico = parseFloat(p.precio_publico).toLocaleString('es-AR');
        let cb = p.codigo_barras || ''; // Guardamos el código de barras de forma invisible
        
        // ELIMINAMOS EL CÓDIGO DE BARRAS DEL TEXTO VISIBLE
        optionsHtml += `<option value="${p.id}" data-costo="${p.costo_real}" data-codigo="${cb}" data-nombre="${p.descripcion}">${(p.tipo === 'combo') ? '[PACK] ' : ''}${p.descripcion} (Público: $${publico} | Costo: $${costo})</option>`; 
    });
    
    const html = `<div class="fila-premio d-flex gap-2 align-items-center mb-2" id="fila_premio_${contadorPremios}">
        <div class="fw-bold text-secondary" style="width:25px;">#${contadorPremios}</div>
        <div style="width:100px;">
            <select class="form-select form-select-sm tipo-premio" onchange="cambiarTipoPremio(${contadorPremios}, this)">
                <option value="interno">Stock</option>
                <option value="manual">Manual</option>
            </select>
        </div>
        <div class="flex-grow-1" id="div_prod_${contadorPremios}" style="min-width: 200px;">
            <select class="form-select form-select-sm select-prod" id="select_prod_${contadorPremios}" onchange="actualizarCosto(${contadorPremios}, this)">
                ${optionsHtml}
            </select>
        </div>
        <div class="flex-grow-1 d-none" id="div_manual_${contadorPremios}">
            <input type="text" class="form-control form-control-sm input-manual" placeholder="Escriba el premio manual...">
        </div>
        <div class="input-group input-group-sm" style="width:160px;">
            <span class="input-group-text bg-light border-end-0 fw-bold" title="Costo Real">$</span>
            <input type="number" step="0.01" class="form-control costo-item border-start-0 text-dark fw-bold" id="costo_${contadorPremios}" value="0" oninput="calcularSimulacion()" readonly>
        </div>
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarFila(${contadorPremios})"><i class="bi bi-trash"></i></button>
    </div>`;
    
    document.getElementById('contenedor_premios').insertAdjacentHTML('beforeend', html);
    
    // Inicializamos el buscador con nuestro "motor" oculto
    $(`#select_prod_${contadorPremios}`).select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#modalNuevoSorteo'),
        width: '100%',
        placeholder: "Escribe o escanea...",
        matcher: matchPorCodigoOTexto
    });
    
    calcularSimulacion();
}
function cambiarTipoPremio(id, select) {
    const divProd = document.getElementById(`div_prod_${id}`); const divManual = document.getElementById(`div_manual_${id}`); const inputCosto = document.getElementById(`costo_${id}`);
    if(select.value === 'manual') { divProd.classList.add('d-none'); divManual.classList.remove('d-none'); inputCosto.readOnly = false; inputCosto.value = 0; } 
    else { divProd.classList.remove('d-none'); divManual.classList.add('d-none'); inputCosto.readOnly = true; inputCosto.value = 0; }
    calcularSimulacion();
}
function actualizarCosto(id, select) { document.getElementById(`costo_${id}`).value = select.options[select.selectedIndex].getAttribute('data-costo'); calcularSimulacion(); }
function eliminarFila(id) { document.getElementById(`fila_premio_${id}`).remove(); calcularSimulacion(); }
function calcularSimulacion() {
    let precio = parseFloat(document.getElementById('sim_precio').value) || 0; let cantidad = parseInt(document.getElementById('sim_cantidad').value) || 0;
    let recaudacion = precio * cantidad; let costoTotal = 0;
    document.querySelectorAll('.costo-item').forEach(el => { costoTotal += parseFloat(el.value) || 0; });
    let ganancia = recaudacion - costoTotal;
    document.getElementById('txt_recaudacion').innerText = '$' + recaudacion.toLocaleString();
    document.getElementById('txt_costos').innerText = '$' + costoTotal.toLocaleString();
    let divRes = document.getElementById('resultado_sim');
    divRes.className = 'resultado-simulacion ' + (ganancia >= 0 ? 'ganancia' : 'perdida');
    divRes.innerHTML = (ganancia >= 0 ? 'Ganancia' : 'Pérdida') + ` Estimada: $${ganancia.toLocaleString()}`;
}
function prepararEnvio() {
    let premios = [];
    document.querySelectorAll('[id^="fila_premio_"]').forEach(row => {
        let tipo = row.querySelector('.tipo-premio').value; let obj = { tipo: tipo, costo: row.querySelector('.costo-item').value };
        if(tipo === 'interno') { let sel = row.querySelector('.select-prod'); obj.id = sel.value; obj.nombre = sel.options[sel.selectedIndex].text; } 
        else { obj.nombre = row.querySelector('.input-manual').value; }
        premios.push(obj);
    });
    document.getElementById('input_premios_simulados').value = JSON.stringify(premios);
}
document.addEventListener("DOMContentLoaded", () => { if(contadorPremios === 0) agregarFilaPremio(); });
</script>
<style>.simulador-box { max-height: 250px; overflow-y: auto; padding: 10px; border: 1px solid #eee; border-radius: 10px; margin-bottom: 10px; }.resultado-simulacion { padding: 10px; border-radius: 8px; text-align: center; font-weight: bold; }.ganancia { background: #d1e7dd; color: #0f5132; }.perdida { background: #f8d7da; color: #842029; }</style>
<?php include 'includes/layout_footer.php'; ?>