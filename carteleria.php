<?php
// carteleria.php - VERSIÓN FINAL RESPONSIVA (FIX VIEWPORT)
session_start();
if (file_exists('db.php')) require_once 'db.php';
elseif (file_exists('includes/db.php')) require_once 'includes/db.php';

$permisos = $_SESSION['permisos'] ?? [];
$rol = $_SESSION['rol'] ?? 3;
if (!in_array('imprimir_carteles', $permisos) && $rol > 2) { header("Location: dashboard.php"); exit; }

// 1. RECUPERAR EL LOGO DEL NEGOCIO (Consulta a Configuración)
$stmtConfig = $conexion->query("SELECT logo_url FROM configuracion WHERE id=1");
$configData = $stmtConfig->fetch(PDO::FETCH_ASSOC);
$logo_url = $configData['logo_url'] ?? ''; 

$busqueda = $_GET['q'] ?? '';
$where = "activo = 1";
if($busqueda) $where .= " AND (descripcion LIKE '%$busqueda%' OR codigo_barras LIKE '%$busqueda%')";

// Forzamos FETCH_OBJ para asegurar compatibilidad
$productos = $conexion->query("SELECT * FROM productos WHERE $where ORDER BY descripcion ASC LIMIT 50")->fetchAll(PDO::FETCH_OBJ);
?>
<?php include 'includes/layout_header.php'; ?>

<style>
    /* Estilos específicos para impresión y etiquetas */
    @media print {
        /* Ocultar elementos del layout nuevo al imprimir */
        .header-section, nav, .navbar, .no-print, footer { display: none !important; }
        .etiqueta { border: 2px solid #000 !important; break-inside: avoid; page-break-inside: avoid; }
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    }

    /* Estilo Base de la Etiqueta */
    .etiqueta {
        border: 2px solid #000; 
        padding: 15px; 
        margin-bottom: 20px;
        text-align: center; 
        background: #fff; 
        border-radius: 8px;
        position: relative; 
        overflow: hidden;   
        z-index: 1;
    }

    /* ESTILO MARCA DE AGUA (LOGO) */
    .marca-agua-img {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-20deg); 
        width: 80%;       
        opacity: 0.15;    
        z-index: -1;      
        pointer-events: none; 
        filter: grayscale(100%); 
    }

    .etiqueta-contenido {
        position: relative;
        z-index: 2;
    }

    .precio-grande { font-size: 3rem; font-weight: 900; line-height: 1; color: #000; }
    /* Ajuste responsivo para el precio en celulares muy chicos */
    @media (max-width: 400px) { .precio-grande { font-size: 2.5rem; } }
    
    .nombre-prod { font-size: 1.2rem; font-weight: bold; height: 3em; overflow: hidden; text-transform: uppercase; }
    .codigo { font-family: monospace; font-size: 0.9rem; letter-spacing: 2px; background: rgba(255,255,255,0.8); padding: 2px; border-radius: 4px; display: inline-block; }
</style>

    <div class="container mt-4">
        <div class="card shadow-sm mb-4 no-print border-0">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="m-0 fw-bold text-primary"><i class="bi bi-printer"></i> Generador de Cartelería</h4>
                    <button onclick="window.print()" class="btn btn-success fw-bold btn-lg"><i class="bi bi-printer-fill"></i> IMPRIMIR</button>
                </div>
                
                <form method="GET" class="d-flex gap-2">
                    <input type="text" name="q" class="form-control" placeholder="Buscar productos..." value="<?php echo $busqueda; ?>">
                    <button type="submit" class="btn btn-primary">Buscar</button>
                </form>
            </div>
        </div>

        <div class="row" id="areaImpresion">
            <?php foreach($productos as $p): ?>
            <div class="col-12 col-sm-6 col-lg-4 col-xl-3 mb-3"> 
                <div class="etiqueta h-100 d-flex flex-column justify-content-between">
                    
                    <?php if(!empty($logo_url) && file_exists($logo_url)): ?>
                        <img src="<?php echo $logo_url; ?>" class="marca-agua-img" alt="Logo Fondo">
                    <?php endif; ?>

                    <div class="etiqueta-contenido">
                        <div class="nombre-prod mb-2"><?php echo htmlspecialchars($p->descripcion); ?></div>
                        <div class="text-muted small mb-1 fw-bold">PRECIO DE CONTADO</div>
                        <div class="precio-grande">$<?php echo number_format($p->precio_venta, 0, ',', '.'); ?></div>
                        <div class="codigo mt-2"><?php echo $p->codigo_barras ?: 'SIN CODIGO'; ?></div>
                        
                        <div class="mt-2 text-center opacity-50">
                            <i class="bi bi-qr-code-scan fs-3"></i>
                        </div>
                    </div>

                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if(empty($productos)): ?>
            <div class="alert alert-info text-center no-print">Usa el buscador para encontrar productos.</div>
        <?php endif; ?>
    </div>

    <?php include 'includes/layout_footer.php'; ?>