<?php
// dashboard.php - VERSIÓN DINÁMICA TOTAL
require_once 'includes/layout_header.php'; 
require_once 'includes/db.php';
$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);

$id_user = $_SESSION['usuario_id'];
$rol_usuario = $_SESSION['rol'] ?? 3; 
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = ($rol_usuario <= 2);

$hoy = date('Y-m-d');

$rubro_actual = $conf['tipo_negocio'] ?? 'kiosco';

// 1. Suma de Ventas Brutas hoy (Aislado)
$resVentas = $conexion->prepare("SELECT COALESCE(SUM(total),0) as total, COUNT(*) as cantidad FROM ventas WHERE id_usuario = ? AND DATE(fecha) = ? AND estado = 'completada' AND (tipo_negocio = ? OR tipo_negocio IS NULL)");
$resVentas->execute([$id_user, $hoy, $rubro_actual]);
$datosVentas = $resVentas->fetch(PDO::FETCH_ASSOC);

// 2. Suma de Devoluciones hoy (Aislado)
$resDevs = $conexion->prepare("SELECT COALESCE(SUM(monto_devuelto),0) as total_dev FROM devoluciones WHERE id_usuario = ? AND DATE(fecha) = ? AND (tipo_negocio = ? OR tipo_negocio IS NULL)");
$resDevs->execute([$id_user, $hoy, $rubro_actual]);
$totalDevueltoHoy = $resDevs->fetch(PDO::FETCH_ASSOC)['total_dev'];

$venta_neta = $datosVentas['total'] - $totalDevueltoHoy;

$estado_caja = $conexion->prepare("SELECT id FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta'");
$estado_caja->execute([$id_user]);
$estado_caja = $estado_caja->fetch() ? 'ABIERTA' : 'CERRADA';

// ALERTAS
$alertas_stock = 0; $alertas_vencimiento = 0; $alertas_cumple = 0;

if($es_admin || in_array('ver_productos', $permisos) || in_array('ver_clientes', $permisos)) {
    if ($es_admin || in_array('ver_productos', $permisos)) {
        $alertas_stock = $conexion->query("SELECT COUNT(p.id) FROM productos p JOIN categorias c ON p.id_categoria = c.id WHERE p.stock_actual <= p.stock_minimo AND p.activo = 1 AND p.tipo != 'combo' AND (p.tipo_negocio = '$rubro_actual' OR p.tipo_negocio IS NULL)")->fetchColumn();
        $dias = $conexion->query("SELECT dias_alerta_vencimiento FROM configuracion WHERE id=1")->fetchColumn() ?: 30;
        $alertas_vencimiento = $conexion->prepare("SELECT COUNT(*) FROM productos WHERE activo=1 AND fecha_vencimiento IS NOT NULL AND fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL ? DAY) AND (tipo_negocio = ? OR tipo_negocio IS NULL)");
        $alertas_vencimiento->execute([$dias, $rubro_actual]);
        $alertas_vencimiento = $alertas_vencimiento->fetchColumn();
    }
    
    if ($es_admin || in_array('ver_clientes', $permisos)) {
        $res_col = $conexion->query("SHOW COLUMNS FROM clientes LIKE 'fecha_nacimiento'");
        if($res_col && $res_col->rowCount() > 0) {
            $q_cumple = $conexion->query("SELECT COUNT(*) FROM clientes WHERE MONTH(fecha_nacimiento)=MONTH(CURDATE()) AND DAY(fecha_nacimiento)=DAY(CURDATE()) AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)");
            $alertas_cumple = $q_cumple ? $q_cumple->fetchColumn() : 0;
        }
    }
}

// --- ALERTA DE TRANSFERENCIAS PENDIENTES (6 HORAS) ---
$alertas_transferencias = 0;
if ($es_admin || in_array('ver_transferencias', $permisos)) {
    $stmtT = $conexion->prepare("SELECT COUNT(*) FROM ventas WHERE metodo_pago = 'Transferencia' AND estado = 'pendiente_transferencia' AND fecha <= DATE_SUB(NOW(), INTERVAL 6 HOUR) AND (tipo_negocio = ? OR tipo_negocio IS NULL)");
    $stmtT->execute([$rubro_actual]);
    $alertas_transferencias = $stmtT->fetchColumn();
}

// --- CONEXIÓN REAL CON ALERTA DE STOCK GLOBAL ---
$alerta_html = "";

if ($alertas_transferencias > 0) {
    $alerta_html .= '
    <div class="alert shadow-sm rounded-4 border-0 d-flex align-items-center mb-4 mt-2 animate__animated animate__headShake" style="background-color: #fff3cd; border-left: 5px solid #ffc107 !important;">
        <i class="bi bi-clock-history fs-3 me-3 text-warning"></i>
        <div>
            <strong class="d-block text-dark">¡TRANSFERENCIAS PENDIENTES!</strong>
            <span class="small text-dark">Tenés <b>'.$alertas_transferencias.'</b> pago(s) en espera de impacto desde hace más de 6 horas. <a href="ver_transferencias_ia.php" class="alert-link text-decoration-underline text-primary">Verificar cuenta bancaria</a></span>
        </div>
    </div>';
}
if (($conf['stock_use_global'] ?? 0) == 1) {
    $limite_global = intval($conf['stock_global_valor'] ?? 5);
    $stmtS = $conexion->prepare("SELECT COUNT(*) FROM productos WHERE stock_actual <= ? AND activo = 1 AND tipo != 'combo' AND (tipo_negocio = ? OR tipo_negocio IS NULL)");
    $stmtS->execute([$limite_global, $rubro_actual]);
    $cant_critica = $stmtS->fetchColumn();

    if ($cant_critica > 0) {
        $alerta_html = '
        <div class="alert alert-danger shadow-sm rounded-4 border-0 d-flex align-items-center mb-4 mt-2 animate__animated animate__headShake">
            <i class="bi bi-exclamation-triangle-fill fs-3 me-3"></i>
            <div>
                <strong class="d-block">¡ALERTA DE SUMINISTROS!</strong>
                <span class="small">Tenés <b>'.$cant_critica.'</b> productos con stock igual o menor a <b>'.$limite_global.'</b> unidades. <a href="productos.php?filtro=bajo_stock" class="alert-link text-decoration-underline">Ver lista crítica</a></span>
            </div>
        </div>';
    }
}
?>

<?php echo $alerta_html; ?>

<div class="row g-3 mb-4 mt-2">
    <div class="col-6 col-md-4 col-xl-2">
        <a href="reportes.php?filtro=hoy" class="widget-stat">
            <span class="stat-label">Ventas Hoy</span>
            <div class="stat-value">
            <?php if($es_admin || in_array('finanzas_ver_dashboard', $permisos)): ?>
                $<?php echo number_format($venta_neta, 0, ',', '.'); ?>
                <?php if($totalDevueltoHoy > 0): ?>
                    <div style="font-size: 0.65rem; opacity: 0.7; font-weight: normal; line-height: 1;">
                        (Bruto: $<?php echo number_format($datosVentas['total'], 0); ?> - Dev: $<?php echo number_format($totalDevueltoHoy, 0); ?>)
                    </div>
                <?php endif; ?>
            <?php else: ?>
                *****
            <?php endif; ?>
            </div>
            <i class="bi bi-currency-dollar stat-icon"></i>
        </a>
    </div>
    
    <div class="col-6 col-md-4 col-xl-2">
        <a href="historial_ventas.php?desde=<?php echo $hoy; ?>&hasta=<?php echo $hoy; ?>" class="widget-stat">
            <span class="stat-label">Tickets</span>
            <div class="stat-value"><?php echo $datosVentas['cantidad']; ?></div>
            <i class="bi bi-receipt stat-icon"></i>
        </a>
    </div>

    <div class="col-6 col-md-4 col-xl-2">
        <a href="historial_cajas.php" class="widget-stat <?php echo $estado_caja=='ABIERTA'?'border-verde':'border-rojo bg-rojo-suave'; ?>">
            <span class="stat-label">Caja</span>
            <div class="stat-value <?php echo $estado_caja=='ABIERTA'?'text-verde':'text-rojo'; ?>"><?php echo $estado_caja; ?></div>
            <i class="bi bi-shop stat-icon"></i>
        </a>
    </div>

    <?php if($es_admin || in_array('ver_productos', $permisos)): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <a href="productos.php?filtro=bajo_stock" class="widget-stat <?php echo $alertas_stock>0?'border-rojo':''; ?>">
            <span class="stat-label">Stock Bajo</span>
            <div class="stat-value <?php echo $alertas_stock>0?'text-rojo':''; ?>"><?php echo $alertas_stock; ?></div>
            <i class="bi bi-box-seam stat-icon"></i>
        </a>
    </div>
    <?php endif; ?>

    <?php if($es_admin || in_array('ver_clientes', $permisos)): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <a href="clientes.php?filtro=cumple" class="widget-stat <?php echo $alertas_cumple>0?'border-amarillo':''; ?>">
            <span class="stat-label">Cumpleaños</span>
            <div class="stat-value <?php echo $alertas_cumple>0?'text-amarillo':''; ?>"><?php echo $alertas_cumple; ?></div>
            <i class="bi bi-gift stat-icon"></i>
        </a>
    </div>
    <?php endif; ?>

    <?php if($es_admin || in_array('ver_productos', $permisos)): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <a href="productos.php?filtro=vencimientos" class="widget-stat <?php echo $alertas_vencimiento>0?'border-rojo':''; ?>">
            <span class="stat-label">Vencimientos</span>
            <div class="stat-value <?php echo $alertas_vencimiento>0?'text-rojo':''; ?>"><?php echo $alertas_vencimiento; ?></div>
            <i class="bi bi-calendar-x stat-icon"></i>
        </a>
    </div>
    <?php endif; ?>
</div>

<a href="ventas.php" class="d-block text-decoration-none mb-5">
    <div class="p-4 rounded-4 shadow-sm text-white d-flex align-items-center justify-content-between position-relative overflow-hidden" 
         style="background: linear-gradient(135deg, #1a3c75 0%, #0d254e 100%); border: 2px solid #75AADB;">
        
        <div class="position-relative z-1">
            <h1 class="font-cancha m-0">IR A PUNTO DE VENTA</h1>
            <div class="opacity-75"><?php echo $conf['label_punto_venta']; ?></div>
            <br><br>
        </div>

        <i class="bi bi-cart4 display-3 position-relative z-1"></i>
        <i class="bi bi-bag-check-fill position-absolute top-50 start-50 translate-middle" style="font-size: 8rem; opacity: 0.05; color: white;"></i>
    </div>
</a>

<?php if($es_admin || in_array('stock_ver_productos', $permisos) || in_array('stock_gestionar_combos', $permisos) || in_array('clientes_ver', $permisos) || in_array('proveedores_ver', $permisos) || in_array('mkt_ver_sorteos', $permisos)): ?>
<h5 class="font-cancha border-bottom pb-2 mb-3 text-secondary"><?php echo $conf['label_seccion_1']; ?></h5>
<div class="row g-3 mb-4 row-cols-3 row-cols-md-4 row-cols-lg-6">
    <div class="col">
        <a href="productos.php" class="card-menu">
            <div class="icon-box-lg icon-azul"><i class="bi bi-box-seam"></i></div>
            <span class="menu-title">Productos</span><span class="menu-sub"><?php echo $conf['label_productos']; ?></span>
        </a>
    </div>
    <div class="col">
        <a href="historial_ventas.php" class="card-menu">
            <div class="icon-box-lg icon-celeste"><i class="bi bi-receipt-cutoff"></i></div>
            <span class="menu-title">Tickets</span><span class="menu-sub">Historial Ventas</span>
        </a>
    </div>
    <div class="col">
        <a href="combos.php" class="card-menu">
            <div class="icon-box-lg icon-amarillo"><i class="bi bi-stars"></i></div>
            <span class="menu-title">Combos</span><span class="menu-sub"><?php echo $conf['label_combos']; ?></span>
        </a>
    </div>
    <div class="col">
        <a href="clientes.php" class="card-menu">
            <div class="icon-box-lg icon-celeste"><i class="bi bi-people-fill"></i></div>
            <span class="menu-title">Clientes</span><span class="menu-sub"><?php echo $conf['label_clientes']; ?></span>
        </a>
    </div>
    <div class="col">
        <a href="proveedores.php" class="card-menu">
            <div class="icon-box-lg icon-verde"><i class="bi bi-truck"></i></div>
            <span class="menu-title">Proveedores</span><span class="menu-sub"><?php echo $conf['label_proveedores']; ?></span>
        </a>
    </div>
    <div class="col">
        <a href="sorteos.php" class="card-menu">
            <div class="icon-box-lg icon-violeta"><i class="bi bi-ticket-perforated-fill"></i></div>
            <span class="menu-title">Sorteos</span><span class="menu-sub"><?php echo $conf['label_sorteos']; ?></span>
        </a>
    </div>
</div>
<?php endif; ?>

<?php if($es_admin || in_array('finanzas_ver_historial_cajas', $permisos) || in_array('finanzas_registrar_gasto', $permisos) || in_array('stock_aumento_masivo', $permisos) || in_array('mkt_gestionar_cupones', $permisos) || in_array('mkt_revista_digital', $permisos)): ?>
<h5 class="font-cancha border-bottom pb-2 mb-3 text-secondary"><?php echo $conf['label_seccion_2']; ?></h5>
<div class="row g-3 mb-4 row-cols-3 row-cols-md-4 row-cols-lg-6">
    <div class="col">
        <a href="ver_recaudacion.php" class="card-menu">
            <div class="icon-box-lg icon-azul"><i class="bi bi-safe-fill"></i></div>
            <span class="menu-title">Recaudación</span><span class="menu-sub"><?php echo $conf['label_recaudacion']; ?></span>
        </a>
    </div>
    <div class="col">
        <a href="gastos.php" class="card-menu">
            <div class="icon-box-lg icon-rojo"><i class="bi bi-cash-stack"></i></div>
            <span class="menu-title">Gastos</span><span class="menu-sub"><?php echo $conf['label_gastos']; ?></span>
        </a>
    </div>
    <div class="col">
        <a href="mermas.php" class="card-menu">
            <div class="icon-box-lg icon-rojo"><i class="bi bi-trash3-fill"></i></div>
            <span class="menu-title">Mermas</span><span class="menu-sub"><?php echo $conf['label_mermas']; ?></span>
        </a>
    </div>
    <div class="col">
        <a href="precios_masivos.php" class="card-menu">
            <div class="icon-box-lg icon-rojo"><i class="bi bi-graph-up-arrow"></i></div>
            <span class="menu-title">Aumentos</span><span class="menu-sub"><?php echo $conf['label_aumentos']; ?></span>
        </a>
    </div>
    <div class="col">
        <a href="gestionar_cupones.php" class="card-menu">
            <div class="icon-box-lg icon-verde"><i class="bi bi-ticket-perforated"></i></div>
            <span class="menu-title">Cupones</span><span class="menu-sub"><?php echo $conf['label_cupones']; ?></span>
        </a>
    </div>
    <div class="col">
        <a href="revista_builder.php" class="card-menu">
            <div class="icon-box-lg icon-violeta"><i class="bi bi-magic"></i></div>
            <span class="menu-title">Revista</span><span class="menu-sub"><?php echo $conf['label_revista']; ?></span>
        </a>
    </div>
</div>
<?php endif; ?>

<?php if($es_admin || in_array('finanzas_ver_dashboard', $permisos) || in_array('config_ver_panel', $permisos) || in_array('config_gestionar_usuarios', $permisos) || in_array('config_ver_auditoria', $permisos)): ?>
<h5 class="font-cancha border-bottom pb-2 mb-3 text-secondary"><?php echo $conf['label_seccion_3']; ?></h5>
<div class="row g-3 mb-5 row-cols-3 row-cols-md-4 row-cols-lg-6">
    <div class="col">
        <a href="reportes.php" class="card-menu">
            <div class="icon-box-lg icon-azul"><i class="bi bi-bar-chart-fill"></i></div>
            <span class="menu-title">Reportes</span><span class="menu-sub"><?php echo $conf['label_reportes']; ?></span>
        </a>
    </div>
    <div class="col">
        <a href="configuracion.php" class="card-menu">
            <div class="icon-box-lg icon-amarillo"><i class="bi bi-sliders"></i></div>
            <span class="menu-title">Configurar</span><span class="menu-sub"><?php echo $conf['label_config']; ?></span>
        </a>
    </div>
    <div class="col">
        <a href="usuarios.php" class="card-menu">
            <div class="icon-box-lg icon-azul"><i class="bi bi-shield-lock"></i></div>
            <span class="menu-title">Usuarios</span><span class="menu-sub"><?php echo $conf['label_usuarios']; ?></span>
        </a>
    </div>
    <div class="col">
        <a href="auditoria.php" class="card-menu">
            <div class="icon-box-lg icon-rojo"><i class="bi bi-eye"></i></div>
            <span class="menu-title">Auditoría</span><span class="menu-sub"><?php echo $conf['label_auditoria']; ?></span>
        </a>
    </div>
     <div class="col">
        <a href="importador_maestro.php" class="card-menu">
            <div class="icon-box-lg icon-verde"><i class="bi bi-file-earmark-spreadsheet-fill"></i></div>
            <span class="menu-title">Importador</span><span class="menu-sub"><?php echo $conf['label_importador']; ?></span>
        </a>
    </div>
    <div class="col">
        <a href="generar_backup.php" class="card-menu">
            <div class="icon-box-lg icon-verde"><i class="bi bi-database-down"></i></div>
            <span class="menu-title">Backup</span><span class="menu-sub"><?php echo $conf['label_respaldo']; ?></span>
        </a>
    </div>
   
</div>
<?php endif; ?>

<?php require_once 'includes/layout_footer.php'; ?>