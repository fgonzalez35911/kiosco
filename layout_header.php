<?php
// includes/layout_header.php - LOGO DINÁMICO + DISEÑO CAMISETA SUPLENTE
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// 1. CONEXIÓN Y DATOS DE USUARIO
if(!isset($nombre_mostrar) || !isset($rol_usuario) || !isset($logo_url)) {
    $ruta_db = file_exists('includes/db.php') ? 'includes/db.php' : (file_exists('../includes/db.php') ? '../includes/db.php' : 'db.php');
    require_once $ruta_db;
    
    $id_user = $_SESSION['usuario_id'];
    
    // Datos Usuario
    $stmtUser = $conexion->prepare("SELECT nombre_completo, usuario, id_rol FROM usuarios WHERE id = ?");
    $stmtUser->execute([$id_user]);
    $datosUsuario = $stmtUser->fetch(PDO::FETCH_ASSOC);
    $nombre_mostrar = !empty($datosUsuario['nombre_completo']) ? $datosUsuario['nombre_completo'] : $datosUsuario['usuario'];
    $rol_usuario = $datosUsuario['id_rol'] ?? 3;

    // 2. DATOS DE CONFIGURACIÓN (LOGO)
    $stmtConfig = $conexion->query("SELECT logo_url, nombre_negocio FROM configuracion WHERE id=1");
    $configData = $stmtConfig->fetch(PDO::FETCH_ASSOC);
    $logo_url = $configData['logo_url'] ?? '';
    $nombre_negocio = $configData['nombre_negocio'] ?? 'EL 10 POS';
}
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title><?php echo htmlspecialchars($nombre_negocio); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;700&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;700&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="css/estilo_premium.css?v=<?php echo time(); ?>">
    <style>
        :root {
            --azul-fuerte: #1a3c75;    /* Azul Camiseta Suplente */
            --celeste-claro: #e3f2fd;  /* Celeste muy suave */
            --celeste-afa: #75AADB;    /* Celeste Bandera */
            --blanco: #ffffff;
            --negro: #212529;
            --gris-fondo: #f8f9fa;
        }
        body { background-color: var(--gris-fondo); font-family: 'Roboto', sans-serif; padding-top: 85px; padding-bottom: 40px; }
        .font-cancha, h1, h2, h3, h4, .navbar-brand { font-family: 'Oswald', sans-serif; text-transform: uppercase; letter-spacing: 0.5px; }
        
    
    


        /* NAVBAR */
        .navbar-10 { background-color: var(--blanco); border-bottom: 4px solid var(--celeste-afa); box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        
        /* LOGO EN NAVBAR */
        .navbar-brand { font-size: 1.5rem; color: var(--azul-fuerte) !important; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .logo-navbar { height: 45px; width: auto; max-width: 150px; object-fit: contain; }

        /* LINKS MENÚ */
        .nav-link { font-family: 'Oswald', sans-serif; font-size: 1.05rem; color: #555 !important; padding: 8px 15px !important; transition: 0.2s; border-radius: 5px; }
        .nav-link:hover, .nav-link.active { color: var(--azul-fuerte) !important; background: var(--celeste-claro); }
        
        /* USUARIO */
        .user-badge { background: #333; color: white; padding: 6px 15px; border-radius: 50px; font-weight: 500; font-size: 0.9rem; cursor: pointer; transition: 0.2s; }
        .user-badge:hover { background: var(--celeste-afa); }

        /* WIDGETS ESTADÍSTICAS */
        .widget-stat {
            background: white; border: 1px solid #e1e4e8; border-left: 5px solid var(--celeste-afa);
            border-radius: 10px; padding: 15px; height: 100%; position: relative; overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.03); transition: transform 0.2s, box-shadow 0.2s;
            text-decoration: none; display: block;
        }
        .widget-stat:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(117, 170, 219, 0.2); border-color: var(--celeste-afa); }
        .stat-label { font-size: 0.75rem; text-transform: uppercase; color: #6c757d; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 5px; display: block; }
        .stat-value { font-family: 'Oswald', sans-serif; font-size: 2rem; color: var(--azul-fuerte); font-weight: 500; line-height: 1; }
        .stat-icon { position: absolute; right: 10px; bottom: 5px; font-size: 2.5rem; color: var(--celeste-afa); opacity: 0.15; }

        /* ALERTA COLORES */
        .border-rojo { border-left-color: #dc3545 !important; } .text-rojo { color: #dc3545 !important; }
        .border-verde { border-left-color: #198754 !important; } .text-verde { color: #198754 !important; }
        .border-amarillo { border-left-color: #ffc107 !important; } .text-amarillo { color: #ffc107 !important; }

        /* ACCESOS DIRECTOS */
        .card-menu {
            background: white; border: 1px solid #eee; border-radius: 12px; padding: 20px 15px;
            height: 100%; display: flex; flex-direction: column; align-items: center; text-align: center;
            text-decoration: none; color: #333; box-shadow: 0 2px 5px rgba(0,0,0,0.02); transition: 0.2s;
        }
        .card-menu:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-color: var(--celeste-afa); }
        .icon-box-lg { width: 55px; height: 55px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; margin-bottom: 12px; transition: 0.2s; }
        .menu-title { font-family: 'Oswald', sans-serif; font-weight: 600; font-size: 1.1rem; color: var(--azul-fuerte); text-transform: uppercase; }
        .menu-sub { font-size: 0.85rem; color: #777; font-weight: 500; }

        /* Colores Iconos */
        .icon-azul { background: #e3f2fd; color: #0d47a1; } .icon-verde { background: #e8f5e9; color: #1b5e20; }
        .icon-rojo { background: #ffebee; color: #b71c1c; } .icon-celeste { background: #e1f5fe; color: #0288d1; }
        .icon-amarillo { background: #fff8e1; color: #f57f17; } .icon-violeta { background: #f3e5f5; color: #7b1fa2; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-xl navbar-10 fixed-top">
    <div class="container-fluid px-4">
        <a class="navbar-brand" href="dashboard.php">
            <?php if(!empty($logo_url)): ?>
                <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logo" class="logo-navbar">
            <?php endif; ?>
            <span><?php echo htmlspecialchars($nombre_negocio); ?></span>
        </a>
        
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav mx-auto mb-2 mb-xl-0 gap-1">
                <li class="nav-item"><a class="nav-link <?php echo $current_page=='dashboard.php'?'active':''; ?>" href="dashboard.php">INICIO</a></li>
                
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">CAJA</a>
                    <ul class="dropdown-menu shadow-sm">
                        <li><a class="dropdown-item" href="ventas.php"><i class="bi bi-cart4 text-success"></i> Nueva Venta</a></li>
                        <li><a class="dropdown-item" href="cierre_caja.php"><i class="bi bi-calculator"></i> Cerrar Caja</a></li>
                        <li><a class="dropdown-item" href="historial_cajas.php"><i class="bi bi-clock-history text-primary"></i> Historial de Cajas</a></li>
<li><a class="dropdown-item" href="historial_ventas.php"><i class="bi bi-receipt text-info"></i> Historial de Ventas</a></li>

                        <?php if($rol_usuario <= 2): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="devoluciones.php"><i class="bi bi-arrow-counterclockwise text-danger"></i> Devoluciones</a></li>
                        
                        <?php endif; ?>
                    </ul>
                </li>
                

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">INVENTARIO</a>
                    <ul class="dropdown-menu shadow-sm">
                        <li><a class="dropdown-item" href="productos.php"><i class="bi bi-box-seam text-primary"></i> Productos</a></li>
                        <li><a class="dropdown-item" href="combos.php"><i class="bi bi-stars text-warning"></i> Pack de Oferta</a></li>
                        <li><a class="dropdown-item" href="carteleria.php" target="_blank"><i class="bi bi-upc-scan"></i> Imprimir Etiquetas</a></li>
                        <?php if($rol_usuario <= 2): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="proveedores.php"><i class="bi bi-truck"></i> Proveedores</a></li>
                        <li><a class="dropdown-item" href="bienes_uso.php"><i class="bi bi-hdd-network"></i> Activos / Bienes</a></li>
                        <?php endif; ?>
                    </ul>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">CLUB DEL 10</a>
                    <ul class="dropdown-menu shadow-sm">
                        <li><a class="dropdown-item" href="clientes.php"><i class="bi bi-people-fill text-info"></i> Clientes</a></li>
                        <li><a class="dropdown-item" href="canje_puntos.php"><i class="bi bi-gift-fill text-danger"></i> Canje de Puntos</a></li>
                        <li><a class="dropdown-item" href="ver_encuestas.php"><i class="bi bi-chat-quote"></i> Encuestas</a></li>
                        <?php if($rol_usuario <= 2): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="gestionar_premios.php"><i class="bi bi-trophy text-warning"></i> Configurar Premios</a></li>
                        <li><a class="dropdown-item" href="cartel_qr.php" target="_blank"><i class="bi bi-qr-code"></i> Autoregistro (QR)</a></li>
                        <?php endif; ?>
                    </ul>
                </li>

                <?php if($rol_usuario <= 2): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">FINANZAS</a>
                    <ul class="dropdown-menu shadow-sm">
                        <li><a class="dropdown-item" href="gastos.php"><i class="bi bi-cash-stack text-danger"></i> Gastos</a></li>
                        <li><a class="dropdown-item" href="mermas.php"><i class="bi bi-trash3"></i> Mermas</a></li>
                        <li><a class="dropdown-item" href="precios_masivos.php"><i class="bi bi-graph-up-arrow text-primary"></i> Inflación (Precios)</a></li>
                    </ul>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">MARKETING</a>
                    <ul class="dropdown-menu shadow-sm">
                        <li><a class="dropdown-item" href="gestionar_cupones.php"><i class="bi bi-ticket-perforated text-success"></i> Cupones</a></li>
                        <li><a class="dropdown-item" href="sorteos.php"><i class="bi bi-ticket-detailed-fill text-danger"></i> Sorteos y Rifas</a></li>
                        <li><a class="dropdown-item" href="admin_revista.php"><i class="bi bi-newspaper"></i> Gestor Revistas</a></li>
                        <li><a class="dropdown-item" href="revista_builder.php"><i class="bi bi-magic"></i> Constructor Builder</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="tienda.php" target="_blank"><i class="bi bi-shop text-primary"></i> Ir a Tienda Online</a></li>
                    </ul>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">ADMIN</a>
                    <ul class="dropdown-menu shadow-sm">
                        <li><a class="dropdown-item" href="reportes.php"><i class="bi bi-bar-chart-fill text-primary"></i> Reportes</a></li>
                        <li><a class="dropdown-item" href="configuracion.php"><i class="bi bi-sliders"></i> Configuración Global</a></li>
                        <li><a class="dropdown-item" href="usuarios.php"><i class="bi bi-shield-lock"></i> Usuarios y Roles</a></li>
                        <li><a class="dropdown-item" href="auditoria.php"><i class="bi bi-eye text-danger"></i> Auditores / Sistema</a></li>
                        <li><a class="dropdown-item" href="restaurar_sistema.php"><i class="bi bi-clock-history text-info"></i> Restaurar Backups</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>

            <div class="dropdown ms-xl-3 mt-3 mt-xl-0">
                <div class="user-badge dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="bi bi-person-circle"></i> 
                    <?php echo htmlspecialchars(explode(' ', $nombre_mostrar)[0]); ?>
                </div>
                <ul class="dropdown-menu dropdown-menu-end shadow mt-2">
                    <li><h6 class="dropdown-header">Usuario Activo</h6></li>
                    <li><a class="dropdown-item" href="perfil.php"><i class="bi bi-person-gear"></i> Mi Ficha</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-power"></i> Cerrar Sesión</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<div class="container fade-in">