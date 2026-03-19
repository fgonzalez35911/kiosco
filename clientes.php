<?php
// clientes.php - VERSIÓN SEGURA (FIX UNIQUE KEYS + DISEÑO VANGUARD PRO)
session_start();
error_reporting(E_ALL); 
ini_set('display_errors', 1);

// 1. CONEXIÓN
$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$permisos = $_SESSION['permisos'] ?? [];
$es_admin = ($_SESSION['rol'] <= 2);

if (!$es_admin && !in_array('clientes_ver', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

// 2. LÓGICA DE ACCIONES (CON PROTECCIÓN NULL PARA MYSQL)
$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['reset_pass'])) {
    $nombre    = trim($_POST['nombre'] ?? '');
    
    // TRUCO DE PROTECCIÓN: Evitar strings vacíos para que MySQL no salte error de UNIQUE KEY
    $dni       = !empty(trim($_POST['dni'] ?? '')) ? trim($_POST['dni']) : null;
    $telefono  = !empty(trim($_POST['telefono'] ?? '')) ? trim($_POST['telefono']) : null;
    $email     = !empty(trim($_POST['email'] ?? '')) ? trim($_POST['email']) : null;
    $direccion = !empty(trim($_POST['direccion'] ?? '')) ? trim($_POST['direccion']) : null;
    $fecha_nac = !empty($_POST['fecha_nacimiento']) ? $_POST['fecha_nacimiento'] : null;
    $user_form = !empty(trim($_POST['usuario_form'] ?? '')) ? trim($_POST['usuario_form']) : null;
    $limite    = floatval($_POST['limite'] ?? 0);
    $id_edit   = $_POST['id_edit'] ?? '';

    if ($id_edit && !$es_admin && !in_array('clientes_editar', $permisos)) die("Sin permiso para editar.");
    if (!$id_edit && !$es_admin && !in_array('clientes_crear', $permisos)) die("Sin permiso para crear.");

    if (!empty($nombre)) {
        try {
            if ($id_edit) {
                $sql = "UPDATE clientes SET nombre=?, telefono=?, whatsapp=?, email=?, direccion=?, dni=?, dni_cuit=?, limite_credito=?, fecha_nacimiento=?, usuario=? WHERE id=?";
                $conexion->prepare($sql)->execute([$nombre, $telefono, $telefono, $email, $direccion, $dni, $dni, $limite, $fecha_nac, $user_form, $id_edit]);
            } else {
                $sql = "INSERT INTO clientes (nombre, dni, dni_cuit, telefono, whatsapp, email, fecha_nacimiento, limite_credito, usuario, direccion, fecha_registro, saldo_deudor, puntos_acumulados, tipo_negocio) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0, 0, ?)";
                $conexion->prepare($sql)->execute([$nombre, $dni, $dni, $telefono, $telefono, $email, $fecha_nac, $limite, $user_form, $direccion, $rubro_actual]);
            }
            header("Location: clientes.php?msg=ok"); exit;
        } catch (Exception $e) { die("Error Crítico DB: " . $e->getMessage()); }
    }
}

if (isset($_GET['borrar'])) {
    if (!$es_admin && !in_array('clientes_eliminar', $permisos)) die("Sin permiso para eliminar.");
    $id_b = intval($_GET['borrar']);
    try {
        $conexion->prepare("UPDATE ventas SET id_cliente = 1 WHERE id_cliente = ?")->execute([$id_b]);
        $conexion->prepare("DELETE FROM movimientos_cc WHERE id_cliente = ?")->execute([$id_b]);
        $conexion->prepare("DELETE FROM clientes WHERE id = ?")->execute([$id_b]);
        header("Location: clientes.php?msg=eliminado"); exit;
    } catch (Exception $e) { header("Location: clientes.php?error=db"); exit; }
}

$buscar = $_GET['buscar'] ?? ''; 
$estado = $_GET['estado'] ?? '';
$filtro_esp = $_GET['filtro'] ?? '';
$query_filtros = "buscar=" . urlencode($buscar) . "&estado=" . urlencode($estado) . "&filtro=" . urlencode($filtro_esp);

$cond = [];
if (isset($_GET['filtro'])) {
    if ($_GET['filtro'] == 'cumple') {
        $cond[] = "MONTH(c.fecha_nacimiento) = MONTH(CURDATE()) AND DAY(c.fecha_nacimiento) = DAY(CURDATE())";
    }
    if ($_GET['filtro'] == 'puntos') {
        $cond[] = "c.puntos_acumulados > 0";
    }
}
if (isset($_GET['estado'])) {
    if ($_GET['estado'] == 'deuda') $cond[] = "(SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'debe') - (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'haber') > 0.1";
    if ($_GET['estado'] == 'aldia') $cond[] = "(SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'debe') - (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'haber') <= 0.1";
}
if (!empty($buscar)) {
    $cond[] = "(c.nombre LIKE '%$buscar%' OR c.dni LIKE '%$buscar%')";
}
$cond[] = "(c.tipo_negocio = '$rubro_actual' OR c.tipo_negocio IS NULL)";

$where_clause = (count($cond) > 0) ? " WHERE " . implode(" AND ", $cond) : "";

$sql = "SELECT c.*, 
        (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'debe') - 
        (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'haber') as saldo_calculado,
        (SELECT MAX(fecha) FROM ventas WHERE id_cliente = c.id) as ultima_venta_fecha
        FROM clientes c $where_clause ORDER BY c.nombre ASC";

$clientes_query = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$clientes_json = [];
$totalDeudaCalle = 0; $cntDeudores = 0; $cntAlDia = 0;
$totalClientes = count($clientes_query);

foreach($clientes_query as $c) {
    $saldo = floatval($c['saldo_calculado']);
    if($saldo > 0.1) { $totalDeudaCalle += $saldo; $cntDeudores++; } else { $cntAlDia++; }
    
    $clientes_json[$c['id']] = [
        'id' => $c['id'], 'nombre' => htmlspecialchars($c['nombre']), 'dni' => $c['dni'] ?? '',
        'email' => $c['email'] ?? '', 'fecha_nacimiento' => $c['fecha_nacimiento'], 'telefono' => $c['telefono'] ?? '',
        'limite' => $c['limite_credito'], 'deuda' => $saldo, 'puntos' => $c['puntos_acumulados'],
        'usuario' => $c['usuario'] ?? '', 'direccion' => $c['direccion'] ?? '',
        'ultima_venta' => $c['ultima_venta_fecha'] ? date('d/m/Y', strtotime($c['ultima_venta_fecha'])) : 'Nunca'
    ];
}

$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav'];
    }
} catch (Exception $e) { }

$conf_color_sis = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf_color_sis['color_barra_nav'] ?? '#102A57';
require_once 'includes/layout_header.php';
?>

<style>
    .cliente-row { transition: all 0.3s ease; border-radius: 12px; margin-bottom: 8px; }
    .cliente-row:hover { background-color: #f8faff !important; transform: scale(1.002); box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    .avatar-wrapper { position: relative; }
    .status-dot { position: absolute; bottom: 0; right: 0; width: 12px; height: 12px; border: 2px solid #fff; border-radius: 50%; }
    .btn-action-custom { width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; border-radius: 10px; transition: all 0.2s; background: #f1f4f9; border: none; }
    .btn-action-custom:hover { transform: translateY(-2px); }
    
    @media (max-width: 768px) {
        .tabla-movil-ajustada td, .tabla-movil-ajustada th { padding: 0.4rem 0.2rem !important; white-space: nowrap; }
        .tabla-movil-ajustada .fw-bold { font-size: 0.8rem !important; }
        .tabla-movil-ajustada .small { font-size: 0.65rem !important; }
        .avatar-wrapper .bg-primary { width: 35px !important; height: 35px !important; font-size: 0.9rem !important; border-radius: 10px !important; }
        .status-dot { width: 10px; height: 10px; }
    }
</style>

<?php
$titulo = "Cartera de Clientes";
$subtitulo = "Gestión de cuentas y fidelización";
$icono_bg = "bi-people-fill";

$botones = [
    ['texto' => 'NUEVO CLIENTE', 'link' => 'javascript:abrirModalCrearSA2()', 'icono' => 'bi-person-plus-fill', 'class' => 'btn btn-light text-success fw-bold rounded-pill px-4 py-2 shadow-sm me-2 d-inline-flex align-items-center'],
    ['texto' => 'REPORTE PDF', 'link' => "reporte_clientes.php?$query_filtros", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-4 py-2 shadow-sm d-inline-flex align-items-center', 'target' => '_blank']
];

$widgets = [
    ['label' => 'Total Clientes', 'valor' => $totalClientes, 'icono' => 'bi-people', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Deuda en Calle', 'valor' => '$'.number_format($totalDeudaCalle, 0, ',', '.'), 'icono' => 'bi-graph-down-arrow', 'border' => 'border-danger', 'icon_bg' => 'bg-danger bg-opacity-20'],
    ['label' => 'Clientes al Día', 'valor' => $cntAlDia, 'icono' => 'bi-shield-check', 'border' => 'border-success', 'icon_bg' => 'bg-success bg-opacity-20']
];

include 'includes/componente_banner.php'; 
?>

<div class="container-fluid container-md mt-n4 px-3 mb-5" style="position: relative; z-index: 20;">

    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border-left: 5px solid #ff9800 !important;">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="row g-2 align-items-center mb-0">
                <input type="hidden" name="estado" value="<?php echo htmlspecialchars($estado); ?>">
                <input type="hidden" name="filtro" value="<?php echo htmlspecialchars($filtro_esp); ?>">
                
                <div class="col-md-8 col-12 text-center text-md-start">
                    <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador Rápido</h6>
                    <p class="small mb-0 opacity-75 d-none d-md-block">Busca un cliente por su nombre o su DNI.</p>
                </div>
                <div class="col-md-4 col-12 text-end mt-2 mt-md-0">
                    <div class="input-group input-group-sm">
                        <input type="text" name="buscar" id="buscador" class="form-control border-0 fw-bold shadow-none" placeholder="Buscar cliente..." value="<?php echo htmlspecialchars($buscar); ?>" onkeyup="filtrarClientesLocal()">
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
                
                <div class="flex-grow-1" style="min-width: 150px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Estado de Cuenta</label>
                    <select name="estado" class="form-select form-select-sm border-light-subtle fw-bold">
                        <option value="">Todos los Estados</option>
                        <option value="deuda" <?php echo ($estado == 'deuda') ? 'selected' : ''; ?>>Con Deuda</option>
                        <option value="aldia" <?php echo ($estado == 'aldia') ? 'selected' : ''; ?>>Al Día</option>
                    </select>
                </div>
                
                <div class="flex-grow-1" style="min-width: 150px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Filtros Especiales</label>
                    <select name="filtro" class="form-select form-select-sm border-light-subtle fw-bold">
                        <option value="">Sin filtro extra</option>
                        <option value="cumple" <?php echo ($filtro_esp == 'cumple') ? 'selected' : ''; ?>>🎂 Cumpleaños Hoy</option>
                        <option value="puntos" <?php echo ($filtro_esp == 'puntos') ? 'selected' : ''; ?>>💎 Con Puntos Acumulados</option>
                    </select>
                </div>

                <div class="flex-grow-0 d-flex gap-2 mt-2 mt-md-0">
                    <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;">
                        <i class="bi bi-funnel-fill me-1"></i> FILTRAR
                    </button>
                    <a href="clientes.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;">
                        <i class="bi bi-trash3-fill me-1"></i> LIMPIAR
                    </a>
                    <button type="button" class="btn btn-dark btn-sm fw-bold rounded-3 shadow-sm px-3 ms-1" style="height: 31px; display: flex; align-items: center;" onclick="toggleVista()" id="btnVistaToggle">
                        <i class="bi bi-list-ul me-1"></i> LISTA
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="alert py-2 small mb-3 text-center fw-bold border-0 shadow-sm rounded-3" style="background-color: #e9f2ff; color: #102A57;">
        <i class="bi bi-hand-index-thumb-fill me-1"></i> Toca o haz clic en un cliente para ver su perfil y opciones
    </div>

    <div class="row g-3" id="gridProductos">
        <?php foreach($clientes_query as $c): 
            $deuda = floatval($c['saldo_calculado']);
        ?>
        <div class="col-12 col-md-6 col-lg-3 item-grid" data-nombre="<?php echo strtolower($c['nombre'] . ' ' . ($c['dni'] ?? '')); ?>">
            <div class="card border-0 shadow-sm rounded-4 h-100 p-3 text-center" style="cursor: pointer; transition: transform 0.2s;" onclick="verResumen(<?php echo $c['id']; ?>)">
                <div class="mx-auto mb-3 position-relative" style="width: 70px; height: 70px;">
                    <div class="bg-primary text-white d-flex align-items-center justify-content-center fw-bold shadow-sm h-100 w-100" style="border-radius:20px; font-size: 1.5rem;">
                        <?php echo strtoupper(substr($c['nombre'], 0, 1) . substr(strrchr($c['nombre'], " "), 1, 1)); ?>
                    </div>
                    <div class="position-absolute bottom-0 end-0 <?php echo $deuda > 0.1 ? 'bg-danger' : 'bg-success'; ?>" style="width: 18px; height: 18px; border: 3px solid #fff; border-radius: 50%;"></div>
                </div>
                <h5 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($c['nombre']); ?></h5>
                <p class="text-muted small mb-2">DNI: <?php echo $c['dni'] ?: '---'; ?></p>
                
                <div class="d-flex justify-content-between align-items-center mt-auto border-top pt-3">
                    <div class="text-start">
                        <small class="text-muted d-block" style="font-size: 10px; text-transform: uppercase;">Estado Cuenta</small>
                        <div class="<?php echo $deuda > 0.1 ? 'text-danger' : 'text-success'; ?> fw-bold fs-6">$<?php echo number_format($deuda, 2, ',', '.'); ?></div>
                    </div>
                    <div class="text-end">
                        <small class="text-muted d-block" style="font-size: 10px; text-transform: uppercase;">Puntos Bonus</small>
                        <div class="text-warning-dark fw-bold fs-6"><i class="bi bi-gem"></i> <?php echo number_format($c['puntos_acumulados'], 0); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div id="vistaListaGenerica" class="d-none">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-transparent">
            <div class="table-responsive px-0 pb-2">
                <table class="table align-middle mb-0 tabla-movil-ajustada" style="border-collapse: separate; border-spacing: 0 8px;">
                    <thead>
                        <tr class="text-muted" style="font-size: 0.65rem; letter-spacing: 1px;">
                            <th class="ps-4 border-0 text-uppercase" style="width: 30%;">Cliente</th>
                            <th class="border-0 text-uppercase d-none d-md-table-cell" style="width: 15%;">Acceso/DNI</th>
                            <th class="border-0 text-uppercase text-center" style="width: 15%;">Puntos</th>
                            <th class="border-0 text-uppercase" style="width: 15%;">Balance</th>
                            <th class="border-0 text-uppercase d-none d-md-table-cell" style="width: 15%;">Movimiento</th>
                            <th class="text-end pe-4 border-0 text-uppercase d-none d-md-table-cell" style="width: 10%;">Gestión</th>
                        </tr>
                    </thead>
                    <tbody>
                <?php foreach($clientes_query as $c): 
                    $deuda = floatval($c['saldo_calculado']);
                    $limite = floatval($c['limite_credito']);
                ?>
                <tr class="cliente-row bg-white shadow-sm item-lista" data-nombre="<?php echo strtolower($c['nombre'] . ' ' . ($c['dni'] ?? '')); ?>" onclick="verResumen(<?php echo $c['id']; ?>)">
                    <td class="ps-2 ps-md-4 py-2 py-md-3" style="border-radius: 15px 0 0 15px;">
                        <div class="d-flex align-items-center">
                            <div class="avatar-wrapper me-3">
                                <div class="bg-primary text-white d-flex align-items-center justify-content-center fw-bold shadow-sm" style="width:48px; height:48px; border-radius:14px; font-size: 1.1rem;">
                                    <?php echo strtoupper(substr($c['nombre'], 0, 1) . substr(strrchr($c['nombre'], " "), 1, 1)); ?>
                                </div>
                                <div class="status-dot <?php echo $deuda > 0.1 ? 'bg-danger' : 'bg-success'; ?>"></div>
                            </div>
                            <div>
                                <div class="fw-bold text-dark mb-0" style="font-size: 0.95rem;"><?php echo htmlspecialchars($c['nombre']); ?></div>
                                <div class="text-muted small"><i class="bi bi-whatsapp me-1"></i><?php echo $c['telefono'] ?: 'Sin teléfono'; ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <div class="d-flex flex-column">
                            <span class="fw-bold text-secondary" style="font-size: 0.8rem;"><?php echo $c['dni'] ?: '---'; ?></span>
                            <span class="text-primary small fw-medium">@<?php echo $c['usuario'] ?: 'invitado'; ?></span>
                        </div>
                    </td>
                    <td class="text-center">
                        <div class="d-inline-block px-2 px-md-3 py-1 rounded-pill bg-warning bg-opacity-10 text-warning-dark fw-bold" style="font-size: 0.8rem;">
                            <i class="bi bi-gem me-1"></i><?php echo number_format($c['puntos_acumulados'], 0); ?>
                        </div>
                    </td>
                    <td style="border-radius: 0;">
                        <div class="p-1 p-md-2 rounded-3 <?php echo $deuda > 0.1 ? 'bg-danger bg-opacity-10' : 'bg-success bg-opacity-10'; ?>" style="max-width: 140px;">
                            <div class="<?php echo $deuda > 0.1 ? 'text-danger' : 'text-success'; ?> fw-black mb-0" style="font-size: 0.95rem;">
                                $<?php echo number_format($deuda, 2, ',', '.'); ?>
                            </div>
                            <div class="text-muted" style="font-size: 0.55rem; text-transform: uppercase;">Crédito: $<?php echo number_format($limite, 0); ?></div>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <div class="text-dark small fw-bold"><i class="bi bi-calendar3 me-1 text-muted"></i><?php echo $c['ultima_venta_fecha'] ? date('d M, Y', strtotime($c['ultima_venta_fecha'])) : 'Sin compras'; ?></div>
                    </td>
                    <td class="text-end pe-4 d-none d-md-table-cell" style="border-radius: 0 15px 15px 0;">
                        <div class="d-flex justify-content-end gap-2">
                            <?php if($es_admin || in_array('clientes_ver_cc', $permisos)): ?>
                            <a href="cuenta_cliente.php?id=<?php echo $c['id']; ?>" onclick="event.stopPropagation();" class="btn-action-custom text-primary" title="Cuenta"><i class="bi bi-wallet2"></i></a>
                            <?php endif; ?>
                            <?php if($es_admin || in_array('clientes_editar', $permisos)): ?>
                            <button class="btn-action-custom text-warning" onclick="event.stopPropagation(); editar(<?php echo $c['id']; ?>)" title="Editar"><i class="bi bi-pencil-square"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalResumen" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-md"><div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
    <div class="modal-header bg-primary text-white border-0 py-3">
        <h5 class="modal-title fw-bold"><i class="bi bi-person-badge me-2"></i>Perfil del Cliente</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body p-0">
        <div class="text-center py-4 bg-light border-bottom">
            <div class="bg-white shadow-sm d-inline-flex align-items-center justify-content-center mb-2" style="width:90px; height:90px; border-radius:24px; font-size:2.2rem; font-weight:900; color:#102A57; border: 3px solid #fff;" id="modal-avatar-res"></div>
            <h4 class="fw-bold mb-0" id="modal-nombre"></h4>
            <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3" id="modal-user"></span>
        </div>
        
        <div class="p-4">
            <div class="row g-3 mb-4 text-center">
                <div class="col-6">
                    <div class="p-3 rounded-4 bg-danger bg-opacity-10 border border-danger border-opacity-10">
                        <div class="small text-danger fw-bold text-uppercase" style="font-size:0.65rem;">Saldo Deudor</div>
                        <div class="h4 fw-black text-danger mb-0" id="modal-deuda"></div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="p-3 rounded-4 bg-warning bg-opacity-10 border border-warning border-opacity-10">
                        <div class="small text-warning-dark fw-bold text-uppercase" style="font-size:0.65rem;">Puntos Bonus</div>
                        <div class="h4 fw-black text-warning-dark mb-0" id="modal-puntos"></div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-6">
                    <label class="text-muted small fw-bold text-uppercase d-block mb-1" style="font-size:0.6rem;">Identificación</label>
                    <div class="fw-bold text-dark"><i class="bi bi-card-heading me-2 text-primary"></i><span id="modal-dni"></span></div>
                </div>
                <div class="col-6">
                    <label class="text-muted small fw-bold text-uppercase d-block mb-1" style="font-size:0.6rem;">WhatsApp</label>
                    <a href="#" id="modal-tel-link" target="_blank" class="text-decoration-none">
                        <div class="fw-bold text-dark"><i class="bi bi-whatsapp me-2 text-success"></i><span id="modal-tel"></span></div>
                    </a>
                </div>
                <div class="col-12">
                    <label class="text-muted small fw-bold text-uppercase d-block mb-1" style="font-size:0.6rem;">Correo Electrónico</label>
                    <a href="#" id="modal-email-link" class="text-decoration-none">
                        <div class="fw-bold text-dark"><i class="bi bi-envelope-at me-2 text-primary"></i><span id="modal-email"></span></div>
                    </a>
                </div>
                <div class="col-12">
                    <label class="text-muted small fw-bold text-uppercase d-block mb-1" style="font-size:0.6rem;">Dirección Física</label>
                    <a href="#" id="modal-dir-link" target="_blank" class="text-decoration-none">
                        <div class="fw-bold text-dark"><i class="bi bi-geo-alt me-2 text-danger"></i><span id="modal-dir"></span></div>
                    </a>
                </div>
                <div class="col-6">
                    <label class="text-muted small fw-bold text-uppercase d-block mb-1" style="font-size:0.6rem;">Cumpleaños</label>
                    <div class="fw-bold text-dark"><i class="bi bi-cake2 me-2 text-info"></i><span id="modal-nac"></span></div>
                </div>
                <div class="col-6 text-end">
                    <label class="text-muted small fw-bold text-uppercase d-block mb-1" style="font-size:0.6rem;">Última Compra</label>
                    <div class="fw-bold text-dark" id="modal-ultima"></div>
                </div>
            </div>

            <div class="d-grid mt-4 pt-3 border-top gap-2">
                <a href="#" id="btn-ir-cuenta" class="btn btn-primary fw-bold shadow-sm py-3 rounded-pill">
                    <i class="bi bi-wallet2 me-2"></i>GESTIONAR CUENTA CORRIENTE
                </a>
                <div class="d-flex justify-content-center gap-2 mt-2">
                    <?php if($es_admin || in_array('clientes_editar', $permisos)): ?>
                    <button type="button" id="btn-edit-mob" class="btn btn-outline-warning fw-bold px-4 rounded-pill flex-grow-1"><i class="bi bi-pencil-square me-1"></i> EDITAR</button>
                    <?php endif; ?>
                    <?php if($es_admin || in_array('clientes_eliminar', $permisos)): ?>
                    <button type="button" id="btn-del-mob" class="btn btn-outline-danger fw-bold px-4 rounded-pill flex-grow-1"><i class="bi bi-trash3-fill me-1"></i> BORRAR</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div></div></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    var clientesDB = <?php echo json_encode($clientes_json); ?>;

    let vistaActual = localStorage.getItem('vista_clientes') || 'grid';
    
    function toggleVista() {
        if (vistaActual === 'grid') {
            vistaActual = 'lista';
            document.getElementById('gridProductos').classList.add('d-none');
            document.getElementById('vistaListaGenerica').classList.remove('d-none');
            document.getElementById('btnVistaToggle').innerHTML = '<i class="bi bi-grid-fill me-1"></i> GRID';
        } else {
            vistaActual = 'grid';
            document.getElementById('vistaListaGenerica').classList.add('d-none');
            document.getElementById('gridProductos').classList.remove('d-none');
            document.getElementById('btnVistaToggle').innerHTML = '<i class="bi bi-list-ul me-1"></i> LISTA';
        }
        localStorage.setItem('vista_clientes', vistaActual);
    }
    
    document.addEventListener('DOMContentLoaded', () => {
        // 1. Restaurar la vista (Grid/Lista)
        if (vistaActual === 'lista') {
            document.getElementById('gridProductos').classList.add('d-none');
            document.getElementById('vistaListaGenerica').classList.remove('d-none');
            document.getElementById('btnVistaToggle').innerHTML = '<i class="bi bi-grid-fill me-1"></i> GRID';
        }

        // 2. Leer la URL para mostrar los mensajes de éxito con SweetAlert2
        const params = new URLSearchParams(window.location.search);
        
        if (params.get('msg') === 'ok') {
            Swal.fire({
                icon: 'success',
                title: '¡Excelente!',
                text: 'Los datos del cliente se guardaron correctamente.',
                confirmButtonColor: '#102A57',
                timer: 2500,
                showConfirmButton: false
            }).then(() => {
                // Limpia la URL para que no vuelva a salir si recargás la página
                window.history.replaceState({}, document.title, window.location.pathname);
            });
        } else if (params.get('msg') === 'eliminado') {
            Swal.fire({
                icon: 'success',
                title: '¡Eliminado!',
                text: 'El cliente fue borrado del sistema.',
                confirmButtonColor: '#102A57',
                timer: 2500,
                showConfirmButton: false
            }).then(() => {
                window.history.replaceState({}, document.title, window.location.pathname);
            });
        }
    });

    function abrirModalCrearSA2(item = null) {
        let isEdit = item !== null;
        let idEdit = isEdit ? item.id : '';
        let nombre = isEdit ? item.nombre : '';
        let dni = isEdit ? item.dni : '';
        let telefono = isEdit ? item.telefono : '';
        let email = isEdit ? item.email : '';
        let direccion = isEdit ? item.direccion : '';
        let fecha_nac = isEdit ? item.fecha_nacimiento : '';
        let limite = isEdit ? item.limite : '0';
        let usuario = isEdit ? item.usuario : '';

        let formHtml = `
            <form id="sa2-form-cliente" action="clientes.php" method="POST" class="text-start">
                <input type="hidden" name="id_edit" value="${idEdit}">
                <div class="row g-2">
                    <div class="col-12 mb-2">
                        <label class="small fw-bold text-muted">Nombre Completo *</label>
                        <input type="text" name="nombre" id="sa2-nombre" class="form-control" value="${nombre}" required onkeyup="generarUsuarioSA2()">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small fw-bold text-muted">DNI / CUIT</label>
                        <input type="number" name="dni" class="form-control" value="${dni}">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small fw-bold text-muted">Usuario Web (Auto)</label>
                        <input type="text" name="usuario_form" id="sa2-usuario" class="form-control bg-light text-primary fw-bold" value="${usuario}">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small fw-bold text-muted">WhatsApp (Ej: 54911...)</label>
                        <input type="text" name="telefono" class="form-control" value="${telefono}">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small fw-bold text-muted">Email (Opcional)</label>
                        <input type="email" name="email" class="form-control" value="${email}">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small fw-bold text-muted">Fecha de Nacimiento</label>
                        <input type="date" name="fecha_nacimiento" class="form-control" value="${fecha_nac}">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small fw-bold text-muted">Límite Fiado ($)</label>
                        <input type="number" step="0.01" name="limite" class="form-control text-danger fw-bold" value="${limite}">
                    </div>
                    <div class="col-12 mb-2">
                        <label class="small fw-bold text-muted">Dirección Física</label>
                        <input type="text" name="direccion" class="form-control" value="${direccion}">
                    </div>
                </div>
            </form>
        `;

        Swal.fire({
            title: isEdit ? '<i class="bi bi-person-lines-fill text-primary"></i> Editar Cliente' : '<i class="bi bi-person-plus-fill text-success"></i> Nuevo Cliente',
            html: formHtml,
            showCancelButton: true,
            confirmButtonText: 'Guardar Datos',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#102A57',
            width: '600px',
            preConfirm: () => {
                const form = document.getElementById('sa2-form-cliente');
                if (!form.nombre.value.trim()) {
                    Swal.showValidationMessage('El nombre es obligatorio');
                    return false;
                }
                
                form.style.display = 'none';
                document.body.appendChild(form);
                form.submit();
                return false; 
            }
        });
    }

    function generarUsuarioSA2() {
        let nombreVal = document.getElementById('sa2-nombre').value.trim().toLowerCase();
        nombreVal = nombreVal.replace(/ñ/g, 'n').replace(/Ñ/g, 'n'); 
        let partes = nombreVal.split(' ').filter(p => p.length > 0);
        let userSugerido = '';
        if (partes.length === 1) {
            userSugerido = partes[0];
        } else if (partes.length >= 2) {
            userSugerido = partes[0].charAt(0) + partes[partes.length - 1]; 
        }
        userSugerido = userSugerido.normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]/g, '');
        
        let userField = document.getElementById('sa2-usuario');
        if(userField.value === '' || userField.getAttribute('data-auto') === '1') {
            userField.value = userSugerido;
            userField.setAttribute('data-auto', '1');
        }
    }

    function editar(id) { 
        var d = clientesDB[id]; 
        abrirModalCrearSA2(d);
    }

    function verResumen(id) { 
        var d = clientesDB[id]; 
        $('#modal-nombre').text(d.nombre); 
        $('#modal-dni').text(d.dni || '---'); 
        $('#modal-avatar-res').text(d.nombre.substring(0,2).toUpperCase()); 
        $('#modal-deuda').text('$' + Number(d.deuda).toLocaleString('es-AR', {minimumFractionDigits: 2})); 
        $('#modal-puntos').text(Number(d.puntos).toLocaleString('es-AR')); 
        $('#modal-user').text('@' + (d.usuario || 'invitado'));
        
        let tel = d.telefono || '';
        $('#modal-tel').text(tel || 'No registrado');
        if(tel) {
            let telLimpio = tel.replace(/\D/g, ''); 
            $('#modal-tel-link').attr('href', 'https://wa.me/' + telLimpio).show();
        } else { $('#modal-tel-link').hide(); }

        let email = d.email || '';
        $('#modal-email').text(email || 'Sin correo');
        if(email) {
            $('#modal-email-link').attr('href', 'mailto:' + email).show();
        } else { $('#modal-email-link').hide(); }

        let dir = d.direccion || '';
        $('#modal-dir').text(dir || 'Sin dirección');
        if(dir) {
            $('#modal-dir-link').attr('href', 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(dir)).show();
        } else { $('#modal-dir-link').hide(); }
        
        if(d.fecha_nacimiento && d.fecha_nacimiento !== '0000-00-00') {
            let fecha = new Date(d.fecha_nacimiento + 'T00:00:00');
            $('#modal-nac').text(fecha.toLocaleDateString('es-AR', {day: '2-digit', month: 'long'}));
        } else {
            $('#modal-nac').text('No registrada');
        }

        $('#modal-ultima').text(d.ultima_venta || 'Sin actividad');
        
        $('#btn-ir-cuenta').attr('href', 'cuenta_cliente.php?id=' + d.id); 
        $('#btn-edit-mob').attr('onclick', `$('#modalResumen').modal('hide'); setTimeout(()=>editar(${d.id}), 400);`);
        $('#btn-del-mob').attr('onclick', `$('#modalResumen').modal('hide'); setTimeout(()=>borrarCliente(${d.id}), 400);`);
        $('#modalResumen').modal('show');
    }
    
    function filtrarClientesLocal() { 
        var val = $('#buscador').val().toUpperCase();
        $('.item-grid').each(function(){ $(this).toggle($(this).data('nombre').toUpperCase().indexOf(val) > -1); });
        $('.item-lista').each(function(){ $(this).toggle($(this).data('nombre').toUpperCase().indexOf(val) > -1); });
    }
    
    function borrarCliente(id) { Swal.fire({ title: '¿Eliminar cliente?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, borrar', confirmButtonColor: '#d33' }).then((r) => { if (r.isConfirmed) window.location.href = 'clientes.php?borrar=' + id; }); }
</script>
<?php include 'includes/layout_footer.php'; ?>