<?php
// ver_encuestas.php - PANEL DE CONTROL DE OPINIONES (DISEÑO PREMIUM)
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. CONEXIÓN
$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);

// Candado: Acceso a la página
if (!$es_admin && !in_array('ver_encuestas', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

// 2. CONSTRUIR FILTROS
$where = "WHERE (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)";
$params = [];

// A. Filtro por Fecha
$fecha = $_GET['fecha'] ?? '';
if (!empty($fecha)) {
    $where .= " AND DATE(fecha) = ?";
    $params[] = $fecha;
}

// B. Filtro por Estrellas
$estrellas = $_GET['estrellas'] ?? '';
if (!empty($estrellas)) {
    $where .= " AND nivel = ?";
    $params[] = $estrellas;
}

// C. Filtro por Tipo de Cliente
$tipo = $_GET['tipo'] ?? '';
if ($tipo === 'anonimo') {
    $where .= " AND cliente_nombre = 'Anónimo'";
} elseif ($tipo === 'cliente') {
    $where .= " AND cliente_nombre != 'Anónimo'";
}

// D. Buscador de Texto (Buscador Rápido)
$buscar = trim($_GET['buscar'] ?? '');
if (!empty($buscar)) {
    $where .= " AND (cliente_nombre LIKE ? OR comentario LIKE ?)";
    $params[] = "%$buscar%";
    $params[] = "%$buscar%";
}

// 3. CONSULTAS SQL DINÁMICAS Y WIDGETS
try {
    // 1. Lista de resultados
    $sql = "SELECT * FROM encuestas $where ORDER BY fecha DESC LIMIT 100";
    $stmt = $conexion->prepare($sql);
    $stmt->execute($params);
    $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Widget Promedio
    $sqlAvg = "SELECT AVG(nivel) FROM encuestas $where";
    $stmtAvg = $conexion->prepare($sqlAvg);
    $stmtAvg->execute($params);
    $promedio = $stmtAvg->fetchColumn() ?: 0;

    // 3. Widget Total
    $sqlCount = "SELECT COUNT(*) FROM encuestas $where";
    $stmtCount = $conexion->prepare($sqlCount);
    $stmtCount->execute($params);
    $total = $stmtCount->fetchColumn();

    // 4. Widget Clientes Felices (4 o 5 estrellas) - Reutilizamos params
    // Truco: Agregamos la condición de felicidad al WHERE existente para contar solo esos
    $sqlHappy = "SELECT COUNT(*) FROM encuestas $where AND nivel >= 4";
    $stmtHappy = $conexion->prepare($sqlHappy);
    $stmtHappy->execute($params);
    $felices = $stmtHappy->fetchColumn();

} catch (Exception $e) {
    $lista = []; $total = 0; $promedio = 0; $felices = 0;
}
$conf_color_sis = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf_color_sis['color_barra_nav'] ?? '#102A57';
require_once 'includes/layout_header.php';

// --- BANNER DINÁMICO ESTANDARIZADO ---
$titulo = "Gestión de Opiniones";
$subtitulo = "Lo que dicen tus clientes sobre el servicio.";
$icono_bg = "bi-chat-quote-fill";

$botones = [
    ['texto' => 'VER FORMULARIO', 'link' => 'encuesta.php', 'icono' => 'bi-eye-fill', 'class' => 'btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm', 'target' => '_blank'],
    ['texto' => 'REPORTE PDF', 'link' => 'reporte_encuestas.php?'.$_SERVER['QUERY_STRING'], 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-4 shadow-sm ms-2', 'target' => '_blank']
];

$widgets = [
    ['label' => 'Opiniones (Filtro)', 'valor' => $total, 'icono' => 'bi-chat-left-text', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Calificación Promedio', 'valor' => number_format((float)$promedio, 1) . ' / 5', 'icono' => 'bi-star-half', 'icon_bg' => 'bg-warning bg-opacity-20'],
    ['label' => 'Clientes Felices', 'valor' => $felices, 'icono' => 'bi-emoji-smile', 'border' => 'border-info', 'icon_bg' => 'bg-success bg-opacity-20']
];

include 'includes/componente_banner.php'; 
?>

<div class="container-fluid container-md mt-n4 px-3 mb-5" style="position: relative; z-index: 20;">
    
    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border: none !important; border-left: 5px solid #ff9800 !important;">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="row g-2 align-items-center mb-0">
                <input type="hidden" name="fecha" value="<?php echo htmlspecialchars($fecha); ?>">
                <input type="hidden" name="estrellas" value="<?php echo htmlspecialchars($estrellas); ?>">
                <input type="hidden" name="tipo" value="<?php echo htmlspecialchars($tipo); ?>">
                
                <div class="col-md-8 col-12 text-center text-md-start">
                    <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador Rápido</h6>
                    <p class="small mb-0 opacity-75 d-none d-md-block">Busca una opinión por nombre de cliente o comentario.</p>
                </div>
                <div class="col-md-4 col-12 text-end mt-2 mt-md-0">
                    <div class="input-group input-group-sm">
                        <input type="text" name="buscar" class="form-control border-0 fw-bold shadow-none" placeholder="Buscar opinión..." value="<?php echo htmlspecialchars($buscar); ?>">
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
                
                <div class="flex-grow-1" style="min-width: 140px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Fecha</label>
                    <input type="date" name="fecha" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo htmlspecialchars($fecha); ?>">
                </div>
                
                <div class="flex-grow-1" style="min-width: 150px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Calificación</label>
                    <select name="estrellas" class="form-select form-select-sm border-light-subtle fw-bold">
                        <option value="">Todas las estrellas</option>
                        <option value="5" <?php if($estrellas=='5') echo 'selected'; ?>>5 - Excelente (😍)</option>
                        <option value="4" <?php if($estrellas=='4') echo 'selected'; ?>>4 - Buena (🙂)</option>
                        <option value="3" <?php if($estrellas=='3') echo 'selected'; ?>>3 - Normal (😐)</option>
                        <option value="2" <?php if($estrellas=='2') echo 'selected'; ?>>2 - Regular (☹️)</option>
                        <option value="1" <?php if($estrellas=='1') echo 'selected'; ?>>1 - Mala (😡)</option>
                    </select>
                </div>
                
                <div class="flex-grow-1" style="min-width: 150px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Origen</label>
                    <select name="tipo" class="form-select form-select-sm border-light-subtle fw-bold">
                        <option value="">Todos los orígenes</option>
                        <option value="anonimo" <?php if($tipo=='anonimo') echo 'selected'; ?>>Clientes Anónimos</option>
                        <option value="cliente" <?php if($tipo=='cliente') echo 'selected'; ?>>Clientes Identificados</option>
                    </select>
                </div>

                <div class="flex-grow-0 d-flex gap-2 mt-2 mt-md-0">
                    <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;">
                        <i class="bi bi-funnel-fill me-1"></i> FILTRAR
                    </button>
                    <a href="ver_encuestas.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;">
                        <i class="bi bi-trash3-fill me-1"></i> LIMPIAR
                    </a>
                </div>
            </form>
        </div>
    </div>

    <h5 class="fw-bold mb-3 text-secondary">Últimas Opiniones</h5>
    
    <?php if (count($lista) > 0): ?>
        <?php foreach ($lista as $row): 
            // Determinar color del borde según nota
            $bordeClass = 'review-mid';
            if($row['nivel'] >= 4) $bordeClass = 'review-good';
            if($row['nivel'] <= 2) $bordeClass = 'review-bad';
            
            // Inicial del nombre
            $inicial = substr($row['cliente_nombre'], 0, 1);
        ?>
            <div class="card review-card <?php echo $bordeClass; ?> p-3">
                <div class="d-flex flex-column flex-md-row gap-3 align-items-md-center">
                    
                    <div class="d-flex align-items-center gap-3" style="min-width: 250px;">
                        <div class="avatar-circle shadow-sm">
                            <?php echo strtoupper($inicial); ?>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">
                                <?php echo htmlspecialchars($row['cliente_nombre']); ?>
                            </h6>
                            <small class="text-muted">
                                <i class="bi bi-calendar3"></i> <?php echo date('d/m/Y H:i', strtotime($row['fecha'])); ?>
                            </small>
                        </div>
                    </div>

                    <div class="flex-grow-1">
                        <div class="mb-1">
                            <?php 
                            for($i=0; $i<$row['nivel']; $i++) echo '<i class="bi bi-star-fill text-warning fs-5"></i> ';
                            for($i=$row['nivel']; $i<5; $i++) echo '<i class="bi bi-star-fill text-muted opacity-25 fs-5"></i> ';
                            ?>
                            <span class="fw-bold ms-2 text-muted small">
                                <?php 
                                    if($row['nivel']==5) echo "Excelente";
                                    elseif($row['nivel']==4) echo "Muy Buena";
                                    elseif($row['nivel']==3) echo "Normal";
                                    elseif($row['nivel']==2) echo "Regular";
                                    elseif($row['nivel']==1) echo "Mala";
                                ?>
                            </span>
                        </div>
                        
                        <?php if (!empty($row['comentario'])): ?>
                            <div class="bg-light p-2 rounded border border-light text-dark fst-italic">
                                "<?php echo htmlspecialchars($row['comentario']); ?>"
                            </div>
                        <?php else: ?>
                            <small class="text-muted opacity-50">Sin comentario escrito.</small>
                        <?php endif; ?>
                    </div>

                    <div class="text-end" style="min-width: 150px;">
                        <?php if (!empty($row['contacto'])): 
                            $wa = preg_replace('/[^0-9]/', '', $row['contacto']);
                        ?>
                            <a href="https://wa.me/<?php echo $wa; ?>" target="_blank" class="btn btn-success btn-sm fw-bold rounded-pill shadow-sm w-100">
                                <i class="bi bi-whatsapp"></i> Contactar
                            </a>
                            <div class="small text-muted mt-1 text-center" style="font-size: 0.75rem;">
                                <?php echo htmlspecialchars($row['contacto']); ?>
                            </div>
                        <?php else: ?>
                            <span class="badge bg-secondary opacity-25 rounded-pill w-100">Sin contacto</span>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="text-center py-5">
            <div class="opacity-25 mb-3">
                <i class="bi bi-inbox-fill display-1 text-secondary"></i>
            </div>
            <h4 class="fw-bold text-muted">No se encontraron opiniones</h4>
            <p class="text-muted">Intenta cambiar los filtros de búsqueda.</p>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php include 'includes/layout_footer.php'; ?>