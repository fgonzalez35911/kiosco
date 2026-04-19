<?php
// admin_pedidos_whatsapp.php - VERSIÓN PREMIUM (DISEÑO CLONADO DE GASTOS)
require_once 'includes/layout_header.php';
require_once 'includes/db.php';

// 1. BLINDAJES
try { $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } catch(Exception $e){}
try { $conexion->exec("ALTER TABLE pedidos_whatsapp MODIFY COLUMN estado VARCHAR(30) DEFAULT 'pendiente'"); } catch(Exception $e){}
try { $conexion->exec("ALTER TABLE pedidos_whatsapp ADD COLUMN IF NOT EXISTS motivo_cancelacion VARCHAR(255) NULL"); } catch(Exception $e){}
try { $conexion->exec("UPDATE productos SET stock_reservado = 0 WHERE stock_reservado IS NULL"); } catch(Exception $e){}

// 2. LÓGICA DE PROCESAMIENTO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $id = intval($_POST['id_pedido']);
    $accion = $_POST['accion'];
    $motivo = $_POST['motivo'] ?? '';
    $fecha_retiro = $_POST['fecha_retiro'] ?? '';

    $stmt = $conexion->prepare("SELECT p.*, c.email as cliente_email FROM pedidos_whatsapp p LEFT JOIN clientes c ON p.id_cliente = c.id WHERE p.id = ?");
    $stmt->execute([$id]);
    $pedido = $stmt->fetch(PDO::FETCH_OBJ);

    if ($pedido) {
        $id_us = $_SESSION['usuario_id'] ?? 1;
        $c_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        $rubro = $c_rubro['tipo_negocio'] ?? 'kiosco';
        $fecha_php = date('Y-m-d H:i:s'); 

        if ($pedido->estado === 'pendiente') {
            if ($accion === 'aprobar') {
                $conexion->beginTransaction();
                try {
                    $detalles = $conexion->prepare("SELECT * FROM pedidos_whatsapp_detalle WHERE id_pedido = ?");
                    $detalles->execute([$id]);
                    while ($item = $detalles->fetch(PDO::FETCH_OBJ)) {
                        $upd = $conexion->prepare("UPDATE productos SET stock_actual = COALESCE(stock_actual,0) - ?, stock_reservado = COALESCE(stock_reservado,0) - ? WHERE id = ?");
                        $upd->execute([$item->cantidad, $item->cantidad, $item->id_producto]);
                    }
                    $conexion->prepare("UPDATE pedidos_whatsapp SET estado = 'aprobado', fecha_retiro = ? WHERE id = ?")->execute([$fecha_retiro, $id]);
                    $conexion->commit();
                    echo "<script>location.href='acciones/enviar_email_pedido.php?id=$id&status=aprobado';</script>";
                    exit;
                } catch (Exception $e) { 
                    $conexion->rollBack(); 
                    die("Error al Aprobar: " . $e->getMessage());
                }
            } else if ($accion === 'rechazar') {
                $conexion->beginTransaction();
                try {
                    $detalles = $conexion->prepare("SELECT * FROM pedidos_whatsapp_detalle WHERE id_pedido = ?");
                    $detalles->execute([$id]);
                    while ($item = $detalles->fetch(PDO::FETCH_OBJ)) {
                        $conexion->prepare("UPDATE productos SET stock_reservado = COALESCE(stock_reservado,0) - ? WHERE id = ?")->execute([$item->cantidad, $item->id_producto]);
                    }
                    $conexion->prepare("UPDATE pedidos_whatsapp SET estado = 'rechazado', motivo_cancelacion = ? WHERE id = ?")->execute([$motivo, $id]);
                    $conexion->commit();
                    echo "<script>location.href='acciones/enviar_email_pedido.php?id=$id&status=rechazado';</script>";
                    exit;
                } catch (Exception $e) { $conexion->rollBack(); }
            }
        } else if ($pedido->estado === 'aprobado') {
            if ($accion === 'entregado') {
                $conexion->beginTransaction();
                try {
                    $conexion->prepare("UPDATE pedidos_whatsapp SET estado = 'entregado' WHERE id = ?")->execute([$id]);
                    
                    $stmtC = $conexion->prepare("SELECT id FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta' ORDER BY id DESC LIMIT 1");
                    $stmtC->execute([$id_us]);
                    $caja_abierta = $stmtC->fetchColumn();
                    if(!$caja_abierta) $caja_abierta = $conexion->query("SELECT id FROM cajas_sesion WHERE estado = 'abierta' ORDER BY id DESC LIMIT 1")->fetchColumn();
                    $caja_val = $caja_abierta ? $caja_abierta : null;
                    $id_cli = !empty($pedido->id_cliente) ? $pedido->id_cliente : 1;
                    
                    $stmtV = $conexion->prepare("INSERT INTO ventas (id_caja_sesion, id_usuario, id_cliente, fecha, total, metodo_pago, estado, tipo_negocio) VALUES (?, ?, ?, ?, ?, 'Efectivo', 'completada', ?)");
                    $stmtV->execute([$caja_val, $id_us, $id_cli, $fecha_php, $pedido->total, $rubro]);
                    $id_venta = $conexion->lastInsertId();
                    
                    $detalles = $conexion->prepare("SELECT d.*, p.precio_costo FROM pedidos_whatsapp_detalle d JOIN productos p ON d.id_producto = p.id WHERE d.id_pedido = ?");
                    $detalles->execute([$id]);
                    $insDet = $conexion->prepare("INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, precio_historico, costo_historico, subtotal, tipo_negocio) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    
                    $ganancia_tot = 0;
                    while ($d = $detalles->fetch(PDO::FETCH_OBJ)) {
                        $costo = floatval($d->precio_costo ?? 0);
                        $ganancia_tot += ($d->precio_unitario - $costo) * $d->cantidad;
                        $insDet->execute([$id_venta, $d->id_producto, $d->cantidad, $d->precio_unitario, $costo, $d->subtotal, $rubro]);
                    }
                    try { $conexion->exec("UPDATE ventas SET ganancia = $ganancia_tot WHERE id = $id_venta"); } catch(Exception $e){}
                    $conexion->commit();
                    echo "<script>location.href='admin_pedidos_whatsapp.php?msg=EstadoActualizado';</script>";
                    exit;
                } catch (Exception $e) { $conexion->rollBack(); die("Error al Entregar: " . $e->getMessage()); }
            } else if ($accion === 'extender') {
                $conexion->prepare("UPDATE pedidos_whatsapp SET fecha_retiro = ? WHERE id = ?")->execute([$fecha_retiro, $id]);
                echo "<script>location.href='admin_pedidos_whatsapp.php?msg=EstadoActualizado';</script>";
                exit;
            } else if ($accion === 'liberar') {
                $conexion->beginTransaction();
                try {
                    $detalles = $conexion->prepare("SELECT * FROM pedidos_whatsapp_detalle WHERE id_pedido = ?");
                    $detalles->execute([$id]);
                    while ($item = $detalles->fetch(PDO::FETCH_OBJ)) {
                        $conexion->prepare("UPDATE productos SET stock_actual = COALESCE(stock_actual,0) + ? WHERE id = ?")->execute([$item->cantidad, $item->id_producto]);
                    }
                    $conexion->prepare("UPDATE pedidos_whatsapp SET estado = 'no_retirado', motivo_cancelacion = ? WHERE id = ?")->execute([$motivo, $id]);
                    $conexion->commit();
                    echo "<script>location.href='acciones/enviar_email_pedido.php?id=$id&status=no_retirado';</script>";
                    exit;
                } catch (Exception $e) { $conexion->rollBack(); }
            }
        }
    }
}

// 3. FILTROS Y BÚSQUEDA
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-1 month'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$f_est = $_GET['estado_filtro'] ?? '';
$buscar = trim($_GET['buscar'] ?? '');

$cond = ["DATE(p.fecha_pedido) >= ?", "DATE(p.fecha_pedido) <= ?"];
$params = [$desde, $hasta];

if($f_est !== '') { $cond[] = "p.estado = ?"; $params[] = $f_est; }
if(!empty($buscar)) { $cond[] = "(p.nombre_cliente LIKE ? OR p.id = ?)"; array_push($params, "%$buscar%", intval($buscar)); }

$sql = "SELECT p.*, c.email as cliente_email FROM pedidos_whatsapp p LEFT JOIN clientes c ON p.id_cliente = c.id WHERE " . implode(" AND ", $cond) . " ORDER BY p.fecha_pedido DESC";
$stmt = $conexion->prepare($sql);
$stmt->execute($params);
$pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-cargar detalles para el Modal y calcular Widgets
$total_recaudado = 0;
$count_pendientes = 0;
$count_aprobados = 0;

$stmtDet = $conexion->prepare("SELECT d.*, pr.descripcion as descripcion_prod FROM pedidos_whatsapp_detalle d JOIN productos pr ON d.id_producto = pr.id WHERE d.id_pedido = ?");

foreach ($pedidos as &$p) {
    if ($p['estado'] === 'entregado') $total_recaudado += $p['total'];
    if ($p['estado'] === 'pendiente') $count_pendientes++;
    if ($p['estado'] === 'aprobado') $count_aprobados++;
    
    $stmtDet->execute([$p['id']]);
    $p['detalles'] = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
}
unset($p);

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$usuario_id = $_SESSION['usuario_id'] ?? 1;

// --- DEFINICIÓN DEL BANNER DINÁMICO ---
$titulo = "Pedidos Tienda Web";
$subtitulo = "Gestión y entrega de reservas.";
$icono_bg = "bi-shop-window";

$query_filtros = !empty($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : "desde=$desde&hasta=$hasta";
$botones = [
    ['texto' => 'Reporte PDF', 'link' => "reporte_pedidos_web.php?$query_filtros", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-3 px-md-4 py-2 shadow-sm', 'target' => '_blank']
];

$widgets = [
    ['label' => 'Recaudado (Entregados)', 'valor' => '$'.number_format($total_recaudado, 0), 'icono' => 'bi-cash-stack', 'border' => 'border-success', 'icon_bg' => 'bg-success bg-opacity-20'],
    ['label' => 'Aprobados (En espera)', 'valor' => $count_aprobados, 'icono' => 'bi-box-seam', 'border' => 'border-primary', 'icon_bg' => 'bg-primary bg-opacity-20'],
    ['label' => 'Pendientes', 'valor' => $count_pendientes, 'icono' => 'bi-exclamation-circle', 'border' => 'border-warning', 'icon_bg' => 'bg-warning bg-opacity-20 text-dark']
];

include 'includes/componente_banner.php'; 
?>

<style>
    /* CSS MAGIA RESPONSIVA: TABLAS A TARJETAS SIN SCROLL HORIZONTAL */
    .table-pedidos th { background-color: #f4f6f9; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #dee2e6; }
    .table-pedidos td { vertical-align: middle; padding: 12px 15px; font-size: 0.95rem; border-bottom: 1px solid #f0f0f0; }
    .table-pedidos tbody tr:hover { background-color: #f8f9fa; }
    .badge { font-weight: 600; padding: 0.4em 0.6em; border-radius: 6px; }
    
    @media (max-width: 768px) {
        .table-responsive { overflow-x: hidden !important; }
        .table-pedidos thead { display: none; }
        .table-pedidos tbody tr { 
            display: flex; flex-direction: column; background: #fff; 
            border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); 
            margin-bottom: 15px; padding: 15px; border: 1px solid #dee2e6; 
            cursor: pointer;
        }
        .table-pedidos tbody td { 
            display: flex; justify-content: space-between; align-items: center; 
            border: none; padding: 6px 0 !important; font-size: 0.9rem; text-align: right !important; 
        }
        .table-pedidos tbody td::before { 
            content: attr(data-label); font-weight: 800; font-size: 0.75rem; 
            color: #adb5bd; text-transform: uppercase; text-align: left; 
        }
        .td-acciones { 
            justify-content: flex-end !important; gap: 8px; margin-top: 10px; 
            padding-top: 12px !important; border-top: 1px dashed #dee2e6 !important; 
        }
        .td-acciones::before { display: none; }
        .td-acciones button { padding: 8px 16px; font-size: 1rem; border-radius: 8px; }
    }
</style>

<div class="container-fluid container-md mt-n4 px-2 px-md-3" style="position: relative; z-index: 20;">
    
    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border: none !important; border-left: 5px solid #ff9800 !important;">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="row g-2 align-items-center mb-0">
                <input type="hidden" name="desde" value="<?php echo htmlspecialchars($desde); ?>">
                <input type="hidden" name="hasta" value="<?php echo htmlspecialchars($hasta); ?>">
                <input type="hidden" name="estado_filtro" value="<?php echo htmlspecialchars($f_est); ?>">
                <div class="col-md-8 col-12 text-center text-md-start">
                    <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador Rápido</h6>
                    <p class="small mb-0 opacity-75 d-none d-md-block">Busca un pedido por Nombre de Cliente o Número de ID.</p>
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
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Estado</label>
                    <select name="estado_filtro" class="form-select form-select-sm border-light-subtle fw-bold">
                        <option value="">Todos los estados</option>
                        <option value="pendiente" <?php echo ($f_est == 'pendiente') ? 'selected' : ''; ?>>⏳ Pendientes</option>
                        <option value="aprobado" <?php echo ($f_est == 'aprobado') ? 'selected' : ''; ?>>✅ Aprobados</option>
                        <option value="entregado" <?php echo ($f_est == 'entregado') ? 'selected' : ''; ?>>🛍️ Entregados</option>
                        <option value="rechazado" <?php echo ($f_est == 'rechazado') ? 'selected' : ''; ?>>❌ Rechazados</option>
                        <option value="no_retirado" <?php echo ($f_est == 'no_retirado') ? 'selected' : ''; ?>>🚫 No Retirados</option>
                    </select>
                </div>
                <div class="flex-grow-0 d-flex gap-2 mt-2 mt-md-0">
                    <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;">
                        <i class="bi bi-funnel-fill me-1"></i> FILTRAR
                    </button>
                    <a href="admin_pedidos_whatsapp.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;">
                        <i class="bi bi-trash3-fill me-1"></i> LIMPIAR
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="alert py-2 small mb-4 text-center fw-bold border-0 shadow-sm rounded-3" style="background-color: #e9f2ff; color: #102A57;">
        <i class="bi bi-hand-index-thumb-fill me-1"></i> Toca o haz clic en un registro para ver el comprobante detallado del pedido
    </div>

    <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-5">
        <div class="card-body p-0 table-responsive">
            <table class="table table-pedidos align-middle mb-0 text-nowrap w-100">
                <thead class="bg-light">
                    <tr>
                        <th>ID</th><th>Fecha</th><th>Cliente</th><th>Total</th><th>Estado</th><th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($pedidos as $p): 
                        $b64Data = base64_encode(json_encode($p));
                    ?>
                    <tr onclick="verTicketPedido('<?php echo $b64Data; ?>')" style="cursor:pointer;">
                        <td data-label="ID Pedido" class="text-muted fw-bold">#<?php echo $p['id']; ?></td>
                        <td data-label="Fecha">
                            <div class="fw-bold"><?php echo date('d/m/Y', strtotime($p['fecha_pedido'])); ?></div>
                            <small class="text-muted d-none d-md-block"><?php echo date('H:i', strtotime($p['fecha_pedido'])); ?> hs</small>
                        </td>
                        <td data-label="Cliente">
                            <div class="fw-bold text-dark text-wrap" style="max-width: 200px;"><?php echo htmlspecialchars($p['nombre_cliente']); ?></div>
                        </td>
                        <td data-label="Total" class="fw-bold text-success fs-5">$<?php echo number_format($p['total'], 0); ?></td>
                        <td data-label="Estado">
                            <?php
                                $badge_class = 'bg-secondary';
                                if($p['estado'] == 'pendiente') $badge_class = 'bg-warning text-dark';
                                if($p['estado'] == 'aprobado') $badge_class = 'bg-primary';
                                if($p['estado'] == 'entregado') $badge_class = 'bg-success';
                                if($p['estado'] == 'rechazado') $badge_class = 'bg-danger';
                                if($p['estado'] == 'no_retirado') $badge_class = 'bg-dark';
                            ?>
                            <span class="badge <?php echo $badge_class; ?> fs-6"><?php echo strtoupper($p['estado']); ?></span>
                        </td>
                        <td class="text-center td-acciones" onclick="event.stopPropagation();">
                            <?php if($p['estado'] == 'pendiente'): ?>
                                <button class="btn btn-sm btn-success shadow-sm" onclick="procesar(<?php echo $p['id']; ?>, 'aprobar')" title="Aprobar"><i class="bi bi-check-lg"></i></button>
                                <button class="btn btn-sm btn-danger shadow-sm ms-1" onclick="procesar(<?php echo $p['id']; ?>, 'rechazar')" title="Rechazar"><i class="bi bi-x-lg"></i></button>
                            <?php endif; ?>

                            <?php if($p['estado'] == 'aprobado'): ?>
                                <button class="btn btn-sm btn-success shadow-sm" onclick="procesarAprobado(<?php echo $p['id']; ?>, 'entregado')" title="Marcar Entregado"><i class="bi bi-bag-check-fill"></i></button>
                                <button class="btn btn-sm btn-warning text-dark shadow-sm ms-1" onclick="procesarAprobado(<?php echo $p['id']; ?>, 'extender')" title="Extender Plazo"><i class="bi bi-clock-history"></i></button>
                                <button class="btn btn-sm btn-danger shadow-sm ms-1" onclick="procesarAprobado(<?php echo $p['id']; ?>, 'liberar')" title="Cancelar y Liberar"><i class="bi bi-arrow-counterclockwise"></i></button>
                            <?php endif; ?>
                            
                            <?php if($p['estado'] == 'entregado' || $p['estado'] == 'rechazado' || $p['estado'] == 'no_retirado'): ?>
                                <span class="text-muted small fw-bold"><i class="bi bi-lock-fill"></i> CERRADO</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
<?php
// Buscamos estrictamente al usuario que tenga el rol "dueño"
$stmtDueño = $conexion->query("SELECT u.id, u.nombre_completo, u.usuario, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
$dueño = $stmtDueño->fetch(PDO::FETCH_OBJ);
$id_dueño = $dueño->id ?? 1;
$nombre_dueño = !empty($dueño->nombre_completo) ? strtoupper($dueño->nombre_completo) : strtoupper($dueño->usuario ?? 'DUEÑO');
$rol_dueño = strtoupper($dueño->nombre_rol ?? 'DUEÑO');
$texto_firma = $nombre_dueño . " | " . $rol_dueño;

// Buscamos la firma específica de ese usuario
$ruta_adm = "img/firmas/usuario_" . $id_dueño . ".png";
$firma_admin_b64 = file_exists($ruta_adm) ? 'data:image/png;base64,' . base64_encode(file_get_contents($ruta_adm)) : '';
?>
const miLocal = <?php echo json_encode($conf); ?>;
const firmaAdminB64 = "<?php echo $firma_admin_b64; ?>";
const textoFirmaDin = "<?php echo $texto_firma; ?>";

function verTicketPedido(b64Data) {
    let p = JSON.parse(atob(b64Data));
    let ts = Date.now();
    let montoF = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(p.total);
    let fechaF = new Date(p.fecha_pedido).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    let linkPdfPublico = window.location.origin + window.location.pathname.replace('admin_pedidos_whatsapp.php', '') + "ticket_pedido_pdf.php?id=" + p.id;
    let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdfPublico);
    let logoHtml = miLocal.logo_url ? `<img src="${miLocal.logo_url}?v=${ts}" style="max-height: 50px; margin-bottom: 10px;">` : '';
    
    // MAGIA: Traemos la firma y texto dinámico del dueño
    let firmaHtml = firmaAdminB64 
        ? `<img src="${firmaAdminB64}" style="max-height: 80px; margin-bottom: -25px;"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${textoFirmaDin}</small>` 
        : `<div style="border-top:1px solid #000; width:100%; margin-top:35px;"></div><small style="font-size:9px; font-weight:bold;">${textoFirmaDin}</small>`;

    // Generar HTML de los detalles de productos
    let detallesHtml = '';
    p.detalles.forEach(d => {
        let subF = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(d.subtotal);
        detallesHtml += `<div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px; border-bottom:1px dotted #e0e0e0; padding-bottom:3px;">
            <span><b>${parseFloat(d.cantidad)}x</b> ${d.descripcion_prod.substring(0, 22)}</span>
            <span style="font-weight:bold;">${subF}</span>
        </div>`;
    });

    let estadoColor = p.estado === 'entregado' ? '#198754' : (p.estado === 'aprobado' ? '#0d6efd' : '#dc3545');

    let ticketHTML = `
        <div id="printTicket" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
            <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                ${logoHtml}
                <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${miLocal.nombre_negocio}</h4>
                <small style="color: #666;">${miLocal.direccion_local}</small>
            </div>
            <div style="text-align: center; margin-bottom: 15px;">
                <h5 style="font-weight: 900; color: ${estadoColor}; letter-spacing: 1px; margin:0;">COMPROBANTE PEDIDO</h5>
                <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">OP #${p.id} - ${p.estado.toUpperCase()}</span>
            </div>
            <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                <div style="margin-bottom: 4px;"><strong>FECHA:</strong> ${fechaF}</div>
                <div style="margin-bottom: 4px;"><strong>CLIENTE:</strong> ${p.nombre_cliente.toUpperCase()}</div>
                <div><strong>CONTACTO:</strong> ${p.email_cliente || p.telefono_cliente || 'N/A'}</div>
            </div>
            <div style="margin-bottom: 15px;">
                <strong style="border-bottom: 1px solid #ccc; display:block; margin-bottom:8px; font-size:13px;">DETALLE DE PRODUCTOS:</strong>
                ${detallesHtml}
            </div>
            <div style="background: ${estadoColor}15; border-left: 4px solid ${estadoColor}; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <span style="font-size: 1.1em; font-weight:800;">TOTAL:</span>
                <span style="font-size: 1.15em; font-weight:900; color: ${estadoColor};">${montoF}</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
                <div style="width: 45%; text-align: center;">
                    ${firmaHtml}
                </div>
                <div style="width: 45%; text-align: center;">
                    <a href="${linkPdfPublico}" target="_blank">
                        <img src="${qrUrl}" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;">
                    </a>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3 no-print">
            <button class="btn btn-sm btn-outline-dark fw-bold" onclick="window.open('${linkPdfPublico}', '_blank')"><i class="bi bi-file-pdf"></i> PDF</button>
            <button class="btn btn-sm btn-success fw-bold" onclick="mandarWAPedido('${p.id}', '${p.nombre_cliente}', '${montoF}', '${linkPdfPublico}')"><i class="bi bi-whatsapp"></i> WA</button>
            <button class="btn btn-sm btn-primary fw-bold" onclick="mandarMailPedido('${p.id}', '${p.email_cliente || ''}')"><i class="bi bi-envelope"></i> EMAIL</button>
        </div>
    `;
    Swal.fire({ html: ticketHTML, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });
}

function mandarWAPedido(id, cliente, monto, link) {
    let msj = `Hola *${cliente}*!\nTe enviamos el comprobante de tu pedido Nro #${id} por el valor de *${monto}*.\n📄 Ver y descargar PDF: ${link}`;
    window.open(`https://wa.me/?text=${encodeURIComponent(msj)}`, '_blank');
}

function mandarMailPedido(id, emailActual) {
    Swal.fire({ 
        title: 'Enviar Comprobante', 
        text: 'Ingrese el correo electrónico del cliente:',
        input: 'email', 
        inputValue: emailActual,
        showCancelButton: true,
        confirmButtonText: 'ENVIAR AHORA',
        confirmButtonColor: '#102A57'
    }).then((r) => {
        if(r.isConfirmed && r.value) {
            Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            
            let fData = new FormData(); 
            fData.append('id', id); 
            fData.append('email', r.value);
            
            fetch('acciones/enviar_email_comprobante_pedido.php', { method: 'POST', body: fData })
            .then(res => res.text()) // Leemos como texto primero para atrapar errores
            .then(text => {
                try {
                    let d = JSON.parse(text);
                    Swal.fire(d.status === 'success' ? 'Enviado con éxito' : 'Error', d.msg || '', d.status);
                } catch(e) {
                    console.error("Respuesta cruda del servidor:", text);
                    if(text.includes('404')) {
                        Swal.fire('Archivo no encontrado', 'No se encontró el archivo enviar_email_comprobante_pedido.php en la carpeta acciones.', 'error');
                    } else {
                        Swal.fire('Error Interno', 'El servidor falló al generar el PDF. Revisa la consola (F12).', 'error');
                    }
                }
            })
            .catch(() => { 
                Swal.fire('Error de Red', 'Hubo un problema de conexión a internet.', 'error'); 
            });
        }
    });
}

function procesar(id, accion) {
    let titulo = accion === 'aprobar' ? '¿Aprobar Pedido?' : '❌ Rechazar Pedido';
    let html = accion === 'aprobar' ? 
        '<div class="text-start"><label class="mb-1 text-muted small fw-bold">Fecha/Hora de Retiro:</label><input type="datetime-local" id="f_retiro" class="form-control"></div>' : 
        `<div class="text-start"><label class="fw-bold mb-1 text-muted small">Motivo de rechazo:</label>
        <select id="motivo_canc" class="form-select mb-2"><option value="Falta de stock físico">Falta de stock físico</option><option value="Precios desactualizados">Precios desactualizados</option><option value="No podemos prepararlo a tiempo">No podemos prepararlo a tiempo</option></select></div>`;

    Swal.fire({
        title: titulo, html: html, icon: accion === 'aprobar' ? 'info' : 'warning',
        showCancelButton: true, confirmButtonText: 'Confirmar', confirmButtonColor: '#102A57',
        preConfirm: () => {
            if(accion === 'aprobar') {
                const f = document.getElementById('f_retiro').value;
                if(!f) return Swal.showValidationMessage('Elegí una fecha de retiro');
                return { fecha: f, motivo: '' };
            } else {
                return { fecha: '', motivo: document.getElementById('motivo_canc').value };
            }
        }
    }).then((result) => { if (result.isConfirmed) enviarFormulario(id, accion, result.value.fecha, result.value.motivo); });
}

function procesarAprobado(id, accion) {
    let titulo = ''; let texto = ''; let html = '';
    if (accion === 'entregado') { titulo = '¿Marcar como Entregado?'; texto = 'El dinero ingresará a la caja automáticamente.'; } 
    else if (accion === 'liberar') { titulo = '🚫 Cancelar Reserva'; html = `<div class="text-start"><label class="fw-bold mb-1 text-muted small">Motivo:</label><select id="motivo_canc_lib" class="form-select mb-2"><option value="El cliente no pasó a retirar el pedido">El cliente no pasó a retirar el pedido</option><option value="El cliente avisó que ya no lo quiere">El cliente avisó que ya no lo quiere</option></select></div>`; } 
    else if (accion === 'extender') { titulo = 'Alargar Plazo'; html = '<div class="text-start"><label class="fw-bold mb-1 text-muted small">Nueva fecha límite:</label><input type="datetime-local" id="f_retiro_ext" class="form-control"></div>'; }

    Swal.fire({
        title: titulo, text: texto, html: html, icon: 'question',
        showCancelButton: true, confirmButtonText: 'Confirmar', confirmButtonColor: '#102A57',
        preConfirm: () => {
            if(accion === 'extender') {
                const f = document.getElementById('f_retiro_ext').value;
                if(!f) return Swal.showValidationMessage('Elegí una nueva fecha');
                return { fecha: f, motivo: '' };
            } else if (accion === 'liberar') { return { fecha: '', motivo: document.getElementById('motivo_canc_lib').value }; }
            return { fecha: '', motivo: '' };
        }
    }).then((result) => { if (result.isConfirmed) enviarFormulario(id, accion, result.value.fecha, result.value.motivo); });
}

function enviarFormulario(id, accion, fecha, motivo) {
    let f = document.createElement('form'); f.method = 'POST';
    f.innerHTML = `<input type="hidden" name="id_pedido" value="${id}"><input type="hidden" name="accion" value="${accion}"><input type="hidden" name="fecha_retiro" value="${fecha}"><input type="hidden" name="motivo" value="${motivo}">`;
    document.body.appendChild(f); f.submit();
}
</script>

<?php if (isset($_GET['msg'])): ?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        let msg = "<?php echo $_GET['msg']; ?>";
        if(msg === 'EmailEnviado') { Swal.fire({icon: 'success', title: 'Notificación Enviada', text: 'Se notificó al cliente.'}); } 
        else if(msg === 'EstadoActualizado') { Swal.fire({icon: 'success', title: 'Operación Exitosa', text: 'El sistema fue actualizado.'}); }
        window.history.replaceState({}, document.title, window.location.pathname);
    });
</script>
<?php endif; ?>
<?php require_once 'includes/layout_footer.php'; ?>