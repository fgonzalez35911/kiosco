<?php
// auditoria.php - VERSIÓN DE EMERGENCIA (RESTAURADA Y MEJORADA VISUALMENTE)
session_start();

// 1. ZONA HORARIA
date_default_timezone_set('America/Argentina/Buenos_Aires');

// CONEXIÓN DB
$rutas_db = [__DIR__ . '/db.php', __DIR__ . '/includes/db.php', 'db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- 2. FILTROS ---
$f_inicio = $_GET['f_inicio'] ?? date('Y-m-d', strtotime('-1 month'));
$f_fin    = $_GET['f_fin'] ?? date('Y-m-d');
$f_user   = $_GET['f_user'] ?? '';
$f_accion = $_GET['f_accion'] ?? '';

// --- 3. CONSULTA SEGURA (SIN JOINS EXTRAS QUE ROMPEN) ---
$sql_aud = "SELECT a.id, a.fecha, a.id_usuario, a.accion, a.detalles, u.usuario 
            FROM auditoria a 
            JOIN usuarios u ON a.id_usuario = u.id 
            WHERE DATE(a.fecha) BETWEEN ? AND ?";

$params_aud = [$f_inicio, $f_fin];

if(!empty($f_user)) { 
    $sql_aud .= " AND a.id_usuario = ?"; 
    $params_aud[] = $f_user; 
}
if(!empty($f_accion)) { 
    $sql_aud .= " AND a.accion LIKE ?"; 
    $params_aud[] = "%$f_accion%"; 
}

// Ordenamos por fecha descendente directo en SQL para evitar errores de PHP
$sql_aud .= " ORDER BY a.fecha DESC";

$st_aud = $conexion->prepare($sql_aud);
$st_aud->execute($params_aud);
$logs_todos = $st_aud->fetchAll(PDO::FETCH_ASSOC);

// --- 4. PAGINACIÓN ---
$total_regs = count($logs_todos);
$pag = isset($_GET['pag']) ? (int)$_GET['pag'] : 1;
$reg_x_pag = 100;
$inicio_limit = ($pag - 1) * $reg_x_pag;
$logs = array_slice($logs_todos, $inicio_limit, $reg_x_pag);

// --- 5. ENRIQUECIMIENTO DE DATOS (NUEVO) ---
// Recorremos solo los 100 logs que se van a mostrar para buscar sus detalles reales
foreach ($logs as &$l) {
    $l['rich_data'] = null;

    // A. SI ES UNA VENTA (Buscamos el ID y traemos el detalle real)
    // Detectamos si dice "VENTA" y tiene el formato "Venta #123"
    if ((strpos(strtoupper($l['accion']), 'VENTA') !== false) && preg_match('/Venta #(\d+)/', $l['detalles'], $m)) {
        $idVenta = $m[1];
        
        // 1. Buscamos la cabecera de la venta (Total, Cliente, Pago)
        $sqlV = "SELECT v.fecha, v.total, v.metodo_pago, v.descuento_manual, v.descuento_monto_cupon, c.nombre as nombre_cliente 
                 FROM ventas v 
                 LEFT JOIN clientes c ON v.id_cliente = c.id 
                 WHERE v.id = ?";
        $stmtV = $conexion->prepare($sqlV);
        $stmtV->execute([$idVenta]);
        $ventaInfo = $stmtV->fetch(PDO::FETCH_ASSOC);

        if ($ventaInfo) {
            // 2. Buscamos los productos de esa venta
            $sqlD = "SELECT d.cantidad, d.subtotal, p.descripcion 
                     FROM detalle_ventas d 
                     LEFT JOIN productos p ON d.id_producto = p.id 
                     WHERE d.id_venta = ?";
            $stmtD = $conexion->prepare($sqlD);
            $stmtD->execute([$idVenta]);
            $items = $stmtD->fetchAll(PDO::FETCH_ASSOC);

            // Guardamos todo en el log para que el Ticket lo use
            $l['rich_data'] = [
                'tipo' => 'venta',
                'cabecera' => $ventaInfo,
                'items' => $items,
                'id_real' => $idVenta
            ];
        }
    }
}
unset($l); // Importante para cerrar el bucle

// --- 5. DATOS EXTRA ---
$hoy = date('Y-m-d');
$movs_hoy = $conexion->query("SELECT COUNT(*) FROM auditoria WHERE DATE(fecha) = '$hoy'")->fetchColumn();
$crit_hoy = $conexion->query("SELECT COUNT(*) FROM auditoria WHERE DATE(fecha) = '$hoy' AND (accion LIKE '%ELIMIN%' OR accion LIKE '%BAJA%' OR accion LIKE '%INFLACION%')")->fetchColumn();
$usuarios_filtro = $conexion->query("SELECT id, usuario FROM usuarios ORDER BY usuario ASC")->fetchAll(PDO::FETCH_ASSOC);

function getIconoReal($accion) {
    $a = strtoupper($accion);
    if(strpos($a, 'VENTA') !== false) return '<i class="bi bi-cart-check-fill text-success"></i>';
    if(strpos($a, 'GASTO') !== false || strpos($a, 'EGRESO') !== false) return '<i class="bi bi-cash-stack text-danger"></i>';
    if(strpos($a, 'PRODUCTO') !== false || strpos($a, 'CANJE') !== false) return '<i class="bi bi-box-seam text-primary"></i>';
    if(strpos($a, 'ELIMINAR') !== false || strpos($a, 'BAJA') !== false) return '<i class="bi bi-trash3-fill text-danger"></i>';
    return '<i class="bi bi-info-circle text-muted"></i>';
}
?>

<?php include 'includes/layout_header.php'; ?>

<style>
    .header-blue { background-color: #102A57; color: white; padding: 40px 0; border-radius: 0 0 30px 30px; position: relative; overflow: hidden; margin-bottom: 25px; }
    .bg-icon-large { position: absolute; top: 50%; right: 20px; transform: translateY(-50%) rotate(-10deg); font-size: 10rem; opacity: 0.1; color: white; pointer-events: none; }
    .stat-card { border: none; border-radius: 15px; padding: 12px 18px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); background: white; display: flex; align-items: center; justify-content: space-between; height: 100%; }
    .card-custom { border: none; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); background: white; }
    .ticket-view { background: #fff; padding: 25px; border-radius: 2px; font-family: 'Courier New', monospace; border-top: 10px solid #102A57; box-shadow: 0 10px 30px rgba(0,0,0,0.3); color: #000; }
</style>

<div class="header-blue">
    <i class="bi bi-shield-lock bg-icon-large"></i>
    <div class="container position-relative">
        <h2 class="fw-bold mb-4">Auditoría del Sistema</h2>
        <div class="row g-3">
            <div class="col-md-4"><div class="stat-card"><div><small class="text-muted fw-bold d-block text-uppercase">Movimientos Hoy</small><h4 class="mb-0 fw-bold text-dark"><?php echo $movs_hoy; ?></h4></div><i class="bi bi-activity text-primary fs-2 opacity-50"></i></div></div>
            <div class="col-md-4"><div class="stat-card"><div><small class="text-muted fw-bold d-block text-uppercase">Críticos Hoy</small><h4 class="mb-0 fw-bold text-danger"><?php echo $crit_hoy; ?></h4></div><i class="bi bi-exclamation-triangle-fill text-danger fs-2 opacity-50"></i></div></div>
            <div class="col-md-4"><div class="stat-card"><div><small class="text-muted fw-bold d-block text-uppercase">Resultados Filtro</small><h4 class="mb-0 fw-bold text-dark"><?php echo number_format($total_regs, 0, '', '.'); ?></h4></div><i class="bi bi-funnel-fill text-dark fs-2 opacity-50"></i></div></div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <div class="card card-custom mb-4">
        <div class="card-body p-3">
            <form method="GET" id="formAudit" class="row g-2 align-items-end">
                <div class="col-md-2"><label class="small fw-bold text-muted">Desde</label><input type="date" name="f_inicio" class="form-control form-control-sm" value="<?php echo $f_inicio; ?>"></div>
                <div class="col-md-2"><label class="small fw-bold text-muted">Hasta</label><input type="date" name="f_fin" class="form-control form-control-sm" value="<?php echo $f_fin; ?>"></div>
                <div class="col-md-2">
                    <select name="f_user" class="form-select form-select-sm">
                        <option value="">Todos los Usuarios</option>
                        <?php foreach($usuarios_filtro as $uf): ?>
                            <option value="<?php echo $uf['id']; ?>" <?php echo ($f_user == $uf['id'])?'selected':''; ?>><?php echo $uf['usuario']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <input type="text" name="f_accion" id="inputAccion" class="form-control" placeholder="Buscador..." value="<?php echo $f_accion; ?>">
                        <button class="btn btn-dark fw-bold" type="button" data-bs-toggle="modal" data-bs-target="#modalFiltroRapido">RÁPIDO</button>
                    </div>
                </div>
                <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100 fw-bold rounded-pill">BUSCAR</button></div>
            </form>
        </div>
    </div>

    <div class="card card-custom overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-center" style="font-size: 0.85rem;">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">Fecha/Hora</th>
                        <th>Usuario</th>
                        <th>Acción</th>
                        <th>Resumen</th>
                        <th class="pe-4 text-end">Ticket</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($logs)): ?>
                        <tr><td colspan="5" class="py-4 text-muted">Sin registros.</td></tr>
                    <?php endif; ?>
                    <?php foreach($logs as $log): ?>
                    <tr style="cursor:pointer" onclick='verTicketAuditoria(<?php echo json_encode($log); ?>)'>
                        <td class="ps-4 fw-bold"><?php echo date('d/m H:i', strtotime($log['fecha'])); ?></td>
                        <td><span class="badge bg-light text-dark border">@<?php echo $log['usuario']; ?></span></td>
                        <td class="fw-bold"><?php echo getIconoReal($log['accion']); ?> <?php echo strtoupper($log['accion']); ?></td>
                        <td class="text-muted small text-start"><?php echo htmlspecialchars(substr($log['detalles'], 0, 85)); ?>...</td>
                        <td class="pe-4 text-end">
                            <button type="button" class="btn btn-sm btn-outline-primary border-0 rounded-pill">
                                <i class="bi bi-receipt fs-5"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalFiltroRapido" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 py-3 bg-light"><h6 class="modal-title fw-bold">FILTROS</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-3">
                <div class="d-grid gap-2">
                    <button class="btn btn-outline-success btn-sm fw-bold" onclick="pegarYBuscar('VENTA')">VENTAS</button>
                    <button class="btn btn-outline-danger btn-sm fw-bold" onclick="pegarYBuscar('GASTO')">GASTOS</button>
                    <button class="btn btn-warning btn-sm fw-bold" onclick="pegarYBuscar('CANJE')">CANJES</button>
                    <button class="btn btn-light btn-sm fw-bold border" onclick="pegarYBuscar('')">LIMPIAR</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function pegarYBuscar(val) {
        document.getElementById('inputAccion').value = val;
        bootstrap.Modal.getInstance(document.getElementById('modalFiltroRapido')).hide();
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        setTimeout(() => { document.getElementById('formAudit').submit(); }, 150);
    }

    // --- FUNCIÓN DEL TICKET MEJORADO ---
    function verTicketAuditoria(log) {
        // Formato de fecha argentina
        let fechaObj = new Date(log.fecha);
        let fechaF = fechaObj.toLocaleString('es-AR', { 
            hour: '2-digit', minute: '2-digit', 
            day: '2-digit', month: '2-digit', year: 'numeric' 
        });

        let contenidoCentral = '';
        let pieTicket = '';

        // --- CASO 1: ES UNA VENTA REAL (Tenemos los datos de la DB) ---
        if (log.rich_data && log.rich_data.tipo === 'venta') {
            let v = log.rich_data.cabecera;
            let items = log.rich_data.items;
            
            // Formatear Total
            let totalF = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(v.total);

            // Armamos la lista de productos
            let htmlItems = '<div style="margin: 15px 0; border-top: 1px dotted #000; padding-top: 5px;">';
            items.forEach(item => {
                let precioItem = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(item.subtotal);
                // Si el producto fue borrado de la base de datos, mostramos "Producto Eliminado"
                let nombreProd = item.descripcion ? item.descripcion : 'ITEM ELIMINADO';
                
                htmlItems += `
                    <div style="display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 3px;">
                        <span>${parseFloat(item.cantidad)}x ${nombreProd}</span>
                        <span>${precioItem}</span>
                    </div>`;
            });
            htmlItems += '</div>';

            // Mostrar si hubo descuentos
            if (parseFloat(v.descuento_manual) > 0 || parseFloat(v.descuento_monto_cupon) > 0) {
                htmlItems += `<div style="text-align:right; font-size:10px; color:green; margin-bottom:5px;">(Incluye Descuentos aplicados)</div>`;
            }

            // Bloque central con datos reales
            contenidoCentral = `
                <table style="width: 100%; font-size: 12px; margin-bottom: 10px;">
                    <tr>
                        <td style="color:#666;">CLIENTE:</td>
                        <td style="text-align:right; font-weight:bold;">${v.nombre_cliente ? v.nombre_cliente.toUpperCase() : 'CONSUMIDOR FINAL'}</td>
                    </tr>
                    <tr>
                        <td style="color:#666;">PAGO:</td>
                        <td style="text-align:right; font-weight:bold;">${v.metodo_pago.toUpperCase()}</td>
                    </tr>
                </table>
                
                ${htmlItems}
                
                <div style="border-top: 2px dashed #000; margin-top: 10px; padding-top: 5px; display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size: 1.2em; font-weight:bold;">TOTAL:</span>
                    <span style="font-size: 1.4em; font-weight:bold; color: #dc3545;">${totalF}</span>
                </div>
            `;
            pieTicket = `ID VENTA ORIGINAL: #${log.rich_data.id_real}`;
        }
        
        // --- CASO 2: CUALQUIER OTRA COSA (Texto plano mejorado) ---
        else {
            let raw = log.detalles;
            let htmlDetalle = `<div style="padding:10px; background:#f9f9f9; border-radius:5px; font-size:11px; line-height:1.4; color:#333;">${raw}</div>`;
            
            // Si es un cambio de precio (tiene la flechita ->), lo hacemos bonito
            if (raw.includes('->')) {
                let partes = raw.split('->');
                // Intentamos separar el "Antes" y el "Después"
                let valorAntes = partes[0].split(':').pop().trim();
                let valorDespues = partes[1].trim();
                
                htmlDetalle = `
                    <div style="text-align:center; padding:15px; border: 1px solid #eee; border-radius:5px;">
                        <small class="text-muted text-uppercase" style="font-size:10px;">Modificación de Valor</small><br>
                        <div style="display:flex; justify-content:center; align-items:center; gap:10px; margin-top:5px;">
                            <span style="text-decoration: line-through; color: #999;">${valorAntes}</span>
                            <i class="bi bi-arrow-right"></i>
                            <span style="font-weight:bold; color: #198754; font-size:1.3em;">${valorDespues}</span>
                        </div>
                    </div>
                    <div style="font-size:10px; color:#666; margin-top:10px; text-align:center;">
                        ${raw.split(':')[0]}
                    </div>
                `;
            }

            contenidoCentral = htmlDetalle;
            pieTicket = 'REGISTRO DE SISTEMA';
        }

        // --- RENDERIZADO FINAL DEL MODAL (SWAL) ---
        Swal.fire({
            background: '#fff',
            width: 380,
            html: `
                <div style="font-family: 'Courier New', monospace; text-align: left; color: #000;">
                    <div style="text-align: center; border-bottom: 2px dashed #000; padding-bottom: 10px; margin-bottom: 15px;">
                        <h4 style="font-weight: 900; margin: 0; text-transform:uppercase; letter-spacing:-0.5px;">${log.accion}</h4>
                        <small style="font-size:11px;">${fechaF}</small><br>
                        <span style="background:#000; color:#fff; padding:2px 6px; border-radius:3px; font-size:10px; display:inline-block; margin-top:4px;">STAFF: ${log.usuario.toUpperCase()}</span>
                    </div>

                    ${contenidoCentral}

                    <div style="border-top: 1px dashed #ccc; margin-top: 20px; padding-top: 10px; text-align: center; font-size: 10px; color:#999;">
                        AUDITORÍA INT. #${log.id} <br> ${pieTicket}
                    </div>
                </div>
            `,
            showCloseButton: true,
            showConfirmButton: false
        });
    }
</script>

<?php include 'includes/layout_footer.php'; ?>