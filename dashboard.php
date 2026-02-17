<?php
// dashboard.php - INICIO LIMPIO
require_once 'includes/layout_header.php'; 

$id_user = $_SESSION['usuario_id'];
$rol_usuario = $_SESSION['rol'] ?? 3;

// DATOS
$hoy = date('Y-m-d');

// Consulta unificada: Suma ventas de mostrador y rifas completadas del usuario hoy
$resVentas = $conexion->prepare("SELECT COALESCE(SUM(total),0) as total, COUNT(*) as cantidad FROM ventas WHERE id_usuario = ? AND DATE(fecha) = ? AND estado = 'completada'");
$resVentas->execute([$id_user, $hoy]);
$datosVentas = $resVentas->fetch(PDO::FETCH_ASSOC);

$estado_caja = $conexion->prepare("SELECT id FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta'");
$estado_caja->execute([$id_user]);
$estado_caja = $estado_caja->fetch() ? 'ABIERTA' : 'CERRADA';

// ALERTAS
$alertas_stock = 0; $alertas_vencimiento = 0; $alertas_cumple = 0;
// Solo calculamos alertas si es Admin/Dueño para no cargar al empleado
if($rol_usuario <= 2) {
    $alertas_stock = $conexion->query("SELECT COUNT(p.id) FROM productos p JOIN categorias c ON p.id_categoria = c.id WHERE p.stock_actual <= p.stock_minimo AND p.activo = 1 AND p.tipo != 'combo'")->fetchColumn();
    $dias = $conexion->query("SELECT dias_alerta_vencimiento FROM configuracion WHERE id=1")->fetchColumn() ?: 30;
    $alertas_vencimiento = $conexion->prepare("SELECT COUNT(*) FROM productos WHERE activo=1 AND fecha_vencimiento IS NOT NULL AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)");
    $alertas_vencimiento->execute([$dias]);
    $alertas_vencimiento = $alertas_vencimiento->fetchColumn();
    
    $res_col = $conexion->query("SHOW COLUMNS FROM clientes LIKE 'fecha_nacimiento'");
    if($res_col && $res_col->rowCount() > 0) {
        $q_cumple = $conexion->query("SELECT COUNT(*) FROM clientes WHERE MONTH(fecha_nacimiento)=MONTH(CURDATE()) AND DAY(fecha_nacimiento)=DAY(CURDATE())");
        $alertas_cumple = $q_cumple ? $q_cumple->fetchColumn() : 0;
    }
}
?>

<div class="row g-3 mb-4 mt-2">
    <div class="col-6 col-md-4 col-xl-2">
        <a href="reportes.php?filtro=hoy" class="widget-stat">
            <span class="stat-label">Ventas Hoy</span>
            <div class="stat-value">
                <?php if($rol_usuario <= 2): ?>
                    $<?php echo number_format($datosVentas['total'],0,',','.'); ?>
                <?php else: ?>
                    *****
                <?php endif; ?>
            </div>
            <i class="bi bi-currency-dollar stat-icon"></i>
        </a>
    </div>
    
    <div class="col-6 col-md-4 col-xl-2">
        <a href="reportes.php?filtro=hoy" class="widget-stat">
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

    <div class="col-6 col-md-4 col-xl-2">
        <a href="productos.php?filtro=bajo_stock" class="widget-stat <?php echo $alertas_stock>0?'border-rojo':''; ?>">
            <span class="stat-label">Stock Bajo</span>
            <div class="stat-value <?php echo $alertas_stock>0?'text-rojo':''; ?>"><?php echo $alertas_stock; ?></div>
            <i class="bi bi-box-seam stat-icon"></i>
        </a>
    </div>

    <?php if($rol_usuario <= 2): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <a href="clientes.php?filtro=cumple" class="widget-stat <?php echo $alertas_cumple>0?'border-amarillo':''; ?>">
            <span class="stat-label">Cumpleaños</span>
            <div class="stat-value <?php echo $alertas_cumple>0?'text-amarillo':''; ?>"><?php echo $alertas_cumple; ?></div>
            <i class="bi bi-gift stat-icon"></i>
        </a>
    </div>
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
            <div class="opacity-75">Facturar / Cobrar (El Gol)</div>
            <br><br>
        </div>

        <i class="bi bi-cart4 display-3 position-relative z-1"></i>
        <i class="bi bi-trophy-fill position-absolute top-50 start-50 translate-middle" style="font-size: 15rem; opacity: 0.05; color: white;"></i>
    </div>
</a>

<h5 class="font-cancha border-bottom pb-2 mb-3 text-secondary">JUGADAS DIARIAS</h5>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg-3">
        <a href="productos.php" class="card-menu">
            <div class="icon-box-lg icon-azul"><i class="bi bi-box-seam"></i></div>
            <span class="menu-title">Productos</span><span class="menu-sub">EL PLANTEL</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="combos.php" class="card-menu">
            <div class="icon-box-lg icon-amarillo"><i class="bi bi-stars"></i></div>
            <span class="menu-title">Combos</span><span class="menu-sub">JUGADAS PREPARADAS</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="clientes.php" class="card-menu">
            <div class="icon-box-lg icon-celeste"><i class="bi bi-people-fill"></i></div>
            <span class="menu-title">Clientes</span><span class="menu-sub">LA HINCHADA</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="proveedores.php" class="card-menu">
            <div class="icon-box-lg icon-verde"><i class="bi bi-truck"></i></div>
            <span class="menu-title">Proveedores</span><span class="menu-sub">REFUERZOS</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="devoluciones.php" class="card-menu">
            <div class="icon-box-lg icon-rojo"><i class="bi bi-arrow-counterclockwise"></i></div>
            <span class="menu-title">Devoluciones</span><span class="menu-sub">EXPULSIONES</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="historial_cajas.php" class="card-menu">
            <div class="icon-box-lg icon-azul"><i class="bi bi-clock-history"></i></div>
            <span class="menu-title">Historial Caja</span><span class="menu-sub">EL REPECHAJE</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="cierre_caja.php" class="card-menu">
            <div class="icon-box-lg icon-amarillo"><i class="bi bi-calculator"></i></div>
            <span class="menu-title">Cerrar Caja</span><span class="menu-sub">FIN DEL PARTIDO</span>
        </a>
    </div>
</div>

<?php if($rol_usuario <= 2): ?>
<h5 class="font-cancha border-bottom pb-2 mb-3 text-secondary">FINANZAS</h5>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg-3">
        <a href="ver_recaudacion.php" class="card-menu">
            <div class="icon-box-lg icon-azul"><i class="bi bi-safe-fill"></i></div>
            <span class="menu-title">Recaudación Real</span><span class="menu-sub">EL TESORO</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="gastos.php" class="card-menu">
            <div class="icon-box-lg icon-rojo"><i class="bi bi-cash-stack"></i></div>
            <span class="menu-title">Gastos</span><span class="menu-sub">TARJETAS AMARILLAS</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="precios_masivos.php" class="card-menu">
            <div class="icon-box-lg icon-rojo"><i class="bi bi-graph-up-arrow"></i></div>
            <span class="menu-title">Aumentos</span><span class="menu-sub">MERCADO DE PASES</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="mermas.php" class="card-menu">
            <div class="icon-box-lg icon-rojo"><i class="bi bi-trash3"></i></div>
            <span class="menu-title">Mermas</span><span class="menu-sub">BAJAS DEL EQUIPO</span>
        </a>
    </div>
</div>

<h5 class="font-cancha border-bottom pb-2 mb-3 text-secondary">EL CLUB DEL 10</h5>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg-3">
        <a href="canje_puntos.php" class="card-menu">
            <div class="icon-box-lg icon-celeste"><i class="bi bi-gift-fill"></i></div>
            <span class="menu-title">Canje de Puntos</span><span class="menu-sub">PREMIOS SOCIOS</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="ver_encuestas.php" class="card-menu">
            <div class="icon-box-lg icon-azul"><i class="bi bi-chat-quote"></i></div>
            <span class="menu-title">Encuestas</span><span class="menu-sub">VOZ DEL HINCHA</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="gestionar_premios.php" class="card-menu">
            <div class="icon-box-lg icon-amarillo"><i class="bi bi-trophy"></i></div>
            <span class="menu-title">Configurar Premios</span><span class="menu-sub">EL PALMARÉS</span>
        </a>
    </div>
</div>

<h5 class="font-cancha border-bottom pb-2 mb-3 text-secondary">MARKETING</h5>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg-3">
        <a href="revista_builder.php" class="card-menu">
            <div class="icon-box-lg icon-violeta"><i class="bi bi-magic"></i></div>
            <span class="menu-title">Revista Builder</span><span class="menu-sub">PIZARRA TÁCTICA</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="gestionar_cupones.php" class="card-menu">
            <div class="icon-box-lg icon-verde"><i class="bi bi-ticket-perforated"></i></div>
            <span class="menu-title">Cupones</span><span class="menu-sub">ENTRADAS BONIFICADAS</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="sorteos.php" class="card-menu">
            <div class="icon-box-lg icon-violeta"><i class="bi bi-ticket-perforated-fill"></i></div>
            <span class="menu-title">Sorteos y Rifas</span><span class="menu-sub">EL PRODE</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="tienda.php" target="_blank" class="card-menu">
            <div class="icon-box-lg icon-celeste"><i class="bi bi-shop"></i></div>
            <span class="menu-title">Tienda Online</span><span class="menu-sub">MERCHANDISING</span>
        </a>
    </div>
</div>

<h5 class="font-cancha border-bottom pb-2 mb-3 text-secondary">ADMINISTRACIÓN</h5>
<div class="row g-3 mb-5">
    <div class="col-6 col-md-4 col-lg-3">
        <a href="reportes.php" class="card-menu">
            <div class="icon-box-lg icon-azul"><i class="bi bi-bar-chart-fill"></i></div>
            <span class="menu-title">Reportes</span><span class="menu-sub">RESULTADOS</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="configuracion.php" class="card-menu">
            <div class="icon-box-lg icon-amarillo"><i class="bi bi-sliders"></i></div>
            <span class="menu-title">Configuración</span><span class="menu-sub">REGLAMENTO</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="usuarios.php" class="card-menu">
            <div class="icon-box-lg icon-azul"><i class="bi bi-shield-lock"></i></div>
            <span class="menu-title">Usuarios</span><span class="menu-sub">CUERPO TÉCNICO</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="auditoria.php" class="card-menu">
            <div class="icon-box-lg icon-rojo"><i class="bi bi-eye"></i></div>
            <span class="menu-title">Auditoría</span><span class="menu-sub">EL VAR</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-lg-3">
        <a href="restaurar_sistema.php" class="card-menu">
            <div class="icon-box-lg icon-azul"><i class="bi bi-bootstrap-reboot"></i></div>
            <span class="menu-title">Restaurar Backups</span><span class="menu-sub">VOLVER AL PASADO</span>
        </a>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/layout_footer.php'; ?>