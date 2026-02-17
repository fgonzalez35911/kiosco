<?php
// ver_encuestas.php - PANEL DE CONTROL DE OPINIONES (DISEÑO PREMIUM)
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. CONEXIÓN
$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// 2. CONSTRUIR FILTROS
$where = "WHERE 1=1";
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
// OBTENER COLOR SEGURO (ESTÁNDAR PREMIUM)
$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_principal FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_principal'])) $color_sistema = $dataC['color_principal'];
    }
} catch (Exception $e) { }
?>

<?php include 'includes/layout_header.php'; ?></div>

<div class="header-blue" style="background-color: <?php echo $color_sistema; ?> !important;">
    <i class="bi bi-chat-quote-fill bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Gestión de Opiniones</h2>
                <p class="opacity-75 mb-0 text-white small">Lo que dicen tus clientes sobre el servicio.</p>
            </div>
            <a href="encuesta.php" target="_blank" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm">
                <i class="bi bi-eye-fill me-2"></i> VER FORMULARIO
            </a>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Opiniones (Filtro)</div>
                        <div class="widget-value text-white"><?php echo $total; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white">
                        <i class="bi bi-chat-left-text"></i>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Calificación Promedio</div>
                        <div class="widget-value text-white"><?php echo number_format($promedio, 1); ?> <small style="font-size: 1rem; opacity: 0.6;">/ 5</small></div>
                    </div>
                    <div class="icon-box bg-warning bg-opacity-20 text-white">
                        <i class="bi bi-star-half"></i>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="header-widget border-info">
                    <div>
                        <div class="widget-label">Clientes Felices</div>
                        <div class="widget-value text-white"><?php echo $felices; ?></div>
                    </div>
                    <div class="icon-box bg-success bg-opacity-20 text-white">
                        <i class="bi bi-emoji-smile"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    
    <div class="card border-0 shadow-sm rounded-4 mb-4 mt-3">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">📅 Fecha</label>
                    <input type="date" name="fecha" class="form-control" value="<?php echo $fecha; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">⭐ Calificación</label>
                    <select name="estrellas" class="form-select">
                        <option value="">Todas</option>
                        <option value="5" <?php if($estrellas=='5') echo 'selected'; ?>>5 - Excelente (😍)</option>
                        <option value="4" <?php if($estrellas=='4') echo 'selected'; ?>>4 - Buena (🙂)</option>
                        <option value="3" <?php if($estrellas=='3') echo 'selected'; ?>>3 - Normal (😐)</option>
                        <option value="2" <?php if($estrellas=='2') echo 'selected'; ?>>2 - Regular (☹️)</option>
                        <option value="1" <?php if($estrellas=='1') echo 'selected'; ?>>1 - Mala (😡)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">👤 Origen</label>
                    <select name="tipo" class="form-select">
                        <option value="">Todos</option>
                        <option value="anonimo" <?php if($tipo=='anonimo') echo 'selected'; ?>>Anónimos</option>
                        <option value="cliente" <?php if($tipo=='cliente') echo 'selected'; ?>>Clientes Identificados</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-dark w-100 fw-bold rounded-3"><i class="bi bi-filter"></i> FILTRAR</button>
                    <?php if($fecha || $estrellas || $tipo): ?>
                        <a href="ver_encuestas.php" class="btn btn-outline-secondary rounded-3" title="Limpiar"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
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