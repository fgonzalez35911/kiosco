<?php
// admin_revista.php - VERSIÓN PREMIUM AZUL + MENÚ FIXED
session_start();
require_once 'includes/db.php';

$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

$mensaje = '';
$tipo_mensaje = '';

// --- LÓGICA DE BASE DE DATOS (MANTENIDA 100% INTACTA) ---
try {
    $cols = [
        "tapa_overlay DECIMAL(3,2) DEFAULT '0.4'",
        "tapa_tit_color VARCHAR(20) DEFAULT '#ffde00'",
        "tapa_sub_color VARCHAR(20) DEFAULT '#ffffff'",
        "fuente_global VARCHAR(50) DEFAULT 'Poppins'",
        "img_tapa VARCHAR(255) DEFAULT ''",
        "tapa_banner_color VARCHAR(20) DEFAULT '#ffffff'",
        "tapa_banner_opacity DECIMAL(3,2) DEFAULT '0.90'"
    ];
    $conexion->exec("CREATE TABLE IF NOT EXISTS revista_config (id INT PRIMARY KEY)");
    $conexion->exec("INSERT INTO revista_config (id) VALUES (1) ON DUPLICATE KEY UPDATE id=id");
    
    foreach($cols as $col) {
        try { $conexion->exec("ALTER TABLE revista_config ADD COLUMN $col"); } catch(Exception $e){}
    }
    $conexion->exec("CREATE TABLE IF NOT EXISTS revista_paginas (id INT PRIMARY KEY AUTO_INCREMENT, nombre_referencia VARCHAR(100), posicion INT DEFAULT 5, imagen_url VARCHAR(255), boton_texto VARCHAR(50), boton_link VARCHAR(255), activa TINYINT DEFAULT 1)");
    
    // PARCHE RUBROS: Asegurar que exista un registro independiente de diseño por cada rubro
    try { $conexion->exec("ALTER TABLE revista_config ADD COLUMN tipo_negocio VARCHAR(50) DEFAULT 'kiosco'"); } catch(Exception $e){}
    
    $existe_cfg = $conexion->query("SELECT COUNT(*) FROM revista_config WHERE tipo_negocio = '$rubro_actual'")->fetchColumn();
    if ($existe_cfg == 0) {
        $max_id = $conexion->query("SELECT COALESCE(MAX(id), 0) FROM revista_config")->fetchColumn();
        $nuevo_id = $max_id + 1;
        $conexion->exec("INSERT INTO revista_config (id, tipo_negocio) VALUES ($nuevo_id, '$rubro_actual')");
    }
    
} catch(Exception $e) {}

// 1. GUARDAR CONFIGURACIÓN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_config') {
    $titulo = $_POST['titulo_tapa'] ?? '';
    $subtitulo = $_POST['subtitulo_tapa'] ?? '';
    $tapa_color = $_POST['tapa_banner_color'] ?? '#ffffff';
    $tapa_opac = $_POST['tapa_banner_opacity'] ?? '0.9';
    $tapa_overlay = $_POST['tapa_overlay'] ?? '0.4';
    $tapa_tit_color = $_POST['tapa_tit_color'] ?? '#ffde00';
    $tapa_sub_color = $_POST['tapa_sub_color'] ?? '#ffffff';
    $fuente_global = $_POST['fuente_global'] ?? 'Poppins';
    $ct_titulo = $_POST['contratapa_titulo'] ?? '';
    $ct_texto = $_POST['contratapa_texto'] ?? '';
    $ct_bg = $_POST['contratapa_bg_color'] ?? '#222222';
    $ct_txt_col = $_POST['contratapa_texto_color'] ?? '#ffffff';
    $ct_overlay = $_POST['contratapa_overlay'] ?? '0.5';
    $ct_qr = isset($_POST['mostrar_qr']) ? 1 : 0;

    $stmt_actual = $conexion->query("SELECT img_tapa, img_contratapa FROM revista_config WHERE tipo_negocio = '$rubro_actual'");
    $actual = $stmt_actual->fetch(PDO::FETCH_ASSOC);
    
    $ruta_tapa = $actual['img_tapa'] ?? '';
    if (!empty($_FILES['img_tapa']['name'])) {
        $dir = 'img/revista/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $nombre = time() . '_tapa_' . basename($_FILES['img_tapa']['name']);
        if(move_uploaded_file($_FILES['img_tapa']['tmp_name'], $dir . $nombre)) $ruta_tapa = $dir . $nombre;
    }

    $ruta_contra = $actual['img_contratapa'] ?? '';
    if (!empty($_FILES['img_contratapa']['name'])) {
        $dir = 'img/revista/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $nombre = time() . '_contra_' . basename($_FILES['img_contratapa']['name']);
        if(move_uploaded_file($_FILES['img_contratapa']['tmp_name'], $dir . $nombre)) $ruta_contra = $dir . $nombre;
    }

    $sql = "UPDATE revista_config SET 
            titulo_tapa=?, subtitulo_tapa=?,
            tapa_banner_color=?, tapa_banner_opacity=?,
            img_tapa=?, tapa_overlay=?, tapa_tit_color=?, tapa_sub_color=?,
            fuente_global=?,
            contratapa_titulo=?, contratapa_texto=?, img_contratapa=?,
            contratapa_bg_color=?, contratapa_texto_color=?, contratapa_overlay=?, mostrar_qr=?
            WHERE tipo_negocio = '$rubro_actual'";
    
    $stmt = $conexion->prepare($sql);
    if($stmt->execute([
        $titulo, $subtitulo, $tapa_color, $tapa_opac, $ruta_tapa, $tapa_overlay, $tapa_tit_color, $tapa_sub_color, $fuente_global,
        $ct_titulo, $ct_texto, $ruta_contra, $ct_bg, $ct_txt_col, $ct_overlay, $ct_qr
    ])) {
        $mensaje = '✅ Configuración guardada.';
        $tipo_mensaje = 'success';
    } else {
        $mensaje = '❌ Error al guardar.';
        $tipo_mensaje = 'danger';
    }
}

// 2. AGREGAR PÁGINA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'nueva_pagina') {
    $nombre = $_POST['nombre']; $posicion = (int)$_POST['posicion']; 
    $btn_txt = $_POST['btn_txt'] ?? ''; $btn_link = $_POST['btn_link'] ?? '';
    
    if (!empty($_FILES['imagen']['name'])) {
        $dir = 'img/revista/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $ruta = $dir . time() . '_ads_' . basename($_FILES['imagen']['name']);
        
        if(move_uploaded_file($_FILES['imagen']['tmp_name'], $ruta)) {
            $stmt = $conexion->prepare("INSERT INTO revista_paginas (nombre_referencia, posicion, imagen_url, boton_texto, boton_link, tipo_negocio) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nombre, $posicion, $ruta, $btn_txt, $btn_link, $rubro_actual]);
            $mensaje = '✅ Página agregada.'; $tipo_mensaje = 'success';
        }
    }
}

// 3. EDITAR PÁGINA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'editar_pagina') {
    $id_pag = (int)$_POST['id_pagina'];
    $nombre = $_POST['nombre']; 
    $posicion = (int)$_POST['posicion']; 
    $btn_txt = $_POST['btn_txt'] ?? ''; 
    $btn_link = $_POST['btn_link'] ?? '';
    
    $sql = "UPDATE revista_paginas SET nombre_referencia=?, posicion=?, boton_texto=?, boton_link=? WHERE id=?";
    $params = [$nombre, $posicion, $btn_txt, $btn_link, $id_pag];

    if (!empty($_FILES['imagen']['name'])) {
        $dir = 'img/revista/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $ruta = $dir . time() . '_ads_' . basename($_FILES['imagen']['name']);
        if(move_uploaded_file($_FILES['imagen']['tmp_name'], $ruta)) {
            $sql = "UPDATE revista_paginas SET nombre_referencia=?, posicion=?, boton_texto=?, boton_link=?, imagen_url=? WHERE id=?";
            $params = [$nombre, $posicion, $btn_txt, $btn_link, $ruta, $id_pag];
        }
    }
    $conexion->prepare($sql)->execute($params);
    header("Location: admin_revista.php?msg=edit"); exit;
}

// 4. BORRAR
if (isset($_GET['borrar'])) {
    $id = (int)$_GET['borrar'];
    $conexion->query("DELETE FROM revista_paginas WHERE id=$id");
    header("Location: admin_revista.php?msg=del"); exit;
}

// CARGA DE DATOS
$revista_cfg = $conexion->query("SELECT * FROM revista_config WHERE tipo_negocio = '$rubro_actual'")->fetch(PDO::FETCH_ASSOC) ?: [];
$paginas = $conexion->query("SELECT * FROM revista_paginas WHERE (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL) ORDER BY posicion ASC")->fetchAll(PDO::FETCH_ASSOC);
$total_paginas = count($paginas);
$fuente_actual = $revista_cfg['fuente_global'] ?? 'Poppins';
// Carga de estados para los widgets
$estado_tapa = !empty($revista_cfg['img_tapa']) ? 'LISTA' : 'VACÍA';
$estado_cierre = !empty($revista_cfg['img_contratapa']) ? 'LISTO' : 'VACÍO';
// OBTENER COLOR SEGURO (ESTÁNDAR PREMIUM)
$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav'];
    }
} catch (Exception $e) { }

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel de Revista | Admin</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    
</head>
<body class="bg-light">

    <?php include 'includes/layout_header.php'; ?> 

    <?php
    // --- DEFINICIÓN DEL BANNER DINÁMICO ESTANDARIZADO ---
    $titulo = "Diseño de Revista";
    $subtitulo = "Personalización visual y gestión de publicidad digital.";
    $icono_bg = "bi-palette-fill";

    $botones = [
        ['texto' => 'VER REVISTA', 'link' => "revista.php", 'icono' => 'bi-eye-fill', 'class' => 'btn btn-light text-primary border border-2 fw-bold rounded-pill px-4 shadow-sm', 'target' => '_blank']
    ];

    // Calculamos cuántas páginas tienen enlaces activos
    $enlaces_activos = 0;
    foreach($paginas as $p) { if(!empty($p['boton_link'])) $enlaces_activos++; }

    $widgets = [
        ['label' => 'Páginas Extra', 'valor' => $total_paginas, 'icono' => 'bi-layers', 'icon_bg' => 'bg-white bg-opacity-10', 'extra' => 'href="#lista_paginas"'],
        ['label' => 'Tipografía', 'valor' => $fuente_actual, 'icono' => 'bi-fonts', 'icon_bg' => 'bg-success bg-opacity-20'],
        ['label' => 'Enlaces Activos', 'valor' => $enlaces_activos, 'icono' => 'bi-link-45deg', 'border' => 'border-warning', 'icon_bg' => 'bg-warning bg-opacity-20']
    ];

    include 'includes/componente_banner.php';
    ?>

    <div class="container-fluid mt-n4 pb-5 px-2 px-md-4" style="position: relative; z-index: 20;">
        
        <?php if($mensaje): ?>
            <script>Swal.fire({ icon: '<?php echo $tipo_mensaje; ?>', title: '<?php echo $mensaje; ?>', timer: 2500, showConfirmButton: false });</script>
        <?php endif; ?>

        <form id="form_diseno" method="POST" enctype="multipart/form-data" class="mb-5">
            <input type="hidden" name="accion" value="guardar_config">
            
            <div class="mb-3 px-1">
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-gear-fill text-primary me-2"></i> Estructura de la Revista</h5>
            </div>

            <div class="row g-4 align-items-stretch">
                
                <div class="col-md-6 col-lg-4">
                    <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-primary border-4">
                        <div class="card-header bg-white py-3 fw-bold text-primary border-0">
                            <i class="bi bi-image me-2"></i> 1. PORTADA (TAPA)
                        </div>
                        <div class="card-body">
                            <div class="mb-3 text-center bg-light p-2 rounded-3 border border-light-subtle">
                                <?php if(!empty($revista_cfg['img_tapa'])): ?>
                                    <img src="<?php echo $revista_cfg['img_tapa']; ?>?v=<?php echo time(); ?>" class="rounded shadow-sm" style="max-height: 120px; object-fit: contain;">
                                <?php else: ?>
                                    <div class="d-flex align-items-center justify-content-center text-muted" style="height: 120px;">Sin imagen de tapa</div>
                                <?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label class="small fw-bold text-muted text-uppercase mb-1">Subir nueva Imagen</label>
                                <input type="file" name="img_tapa" class="form-control form-control-sm shadow-sm">
                            </div>
                            <div class="mb-3">
                                <label class="small fw-bold text-muted text-uppercase mb-1">Oscuridad de Fondo (Overlay)</label>
                                <input type="range" name="tapa_overlay" class="form-range" min="0" max="0.9" step="0.1" value="<?php echo $revista_cfg['tapa_overlay'] ?? '0.4'; ?>">
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-8">
                                    <label class="small fw-bold text-muted text-uppercase mb-1">Título Principal</label>
                                    <input type="text" name="titulo_tapa" class="form-control form-control-sm shadow-sm fw-bold" value="<?php echo htmlspecialchars($revista_cfg['titulo_tapa'] ?? ''); ?>">
                                </div>
                                <div class="col-4">
                                    <label class="small fw-bold text-muted text-uppercase mb-1">Color</label>
                                    <input type="color" name="tapa_tit_color" class="form-control form-control-color form-control-sm w-100 shadow-sm" value="<?php echo $revista_cfg['tapa_tit_color'] ?? '#ffde00'; ?>">
                                </div>
                                <div class="col-8">
                                    <label class="small fw-bold text-muted text-uppercase mb-1">Subtítulo</label>
                                    <input type="text" name="subtitulo_tapa" class="form-control form-control-sm shadow-sm fw-bold" value="<?php echo htmlspecialchars($revista_cfg['subtitulo_tapa'] ?? ''); ?>">
                                </div>
                                <div class="col-4">
                                    <label class="small fw-bold text-muted text-uppercase mb-1">Color</label>
                                    <input type="color" name="tapa_sub_color" class="form-control form-control-color form-control-sm w-100 shadow-sm" value="<?php echo $revista_cfg['tapa_sub_color'] ?? '#ffffff'; ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-secondary border-4">
                        <div class="card-header bg-white py-3 fw-bold text-secondary border-0">
                            <i class="bi bi-door-closed me-2"></i> 2. CONTRATAPA (FINAL)
                        </div>
                        <div class="card-body">
                            <div class="mb-3 text-center bg-light p-2 rounded-3 border border-light-subtle">
                                <?php if(!empty($revista_cfg['img_contratapa'])): ?>
                                    <img src="<?php echo $revista_cfg['img_contratapa']; ?>?v=<?php echo time(); ?>" class="rounded shadow-sm" style="max-height: 120px; object-fit: contain;">
                                <?php else: ?>
                                    <div class="d-flex align-items-center justify-content-center text-muted" style="height: 120px;">Sin imagen de contratapa</div>
                                <?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label class="small fw-bold text-muted text-uppercase mb-1">Imagen de Cierre</label>
                                <input type="file" name="img_contratapa" class="form-control form-control-sm shadow-sm">
                            </div>
                            <div class="mb-3">
                                <label class="small fw-bold text-muted text-uppercase mb-1">Título Despedida</label>
                                <input type="text" name="contratapa_titulo" class="form-control form-control-sm shadow-sm fw-bold" value="<?php echo htmlspecialchars($revista_cfg['contratapa_titulo'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="small fw-bold text-muted text-uppercase mb-1">Mensaje Final</label>
                                <textarea name="contratapa_texto" class="form-control form-control-sm shadow-sm fw-bold" rows="2"><?php echo htmlspecialchars($revista_cfg['contratapa_texto'] ?? ''); ?></textarea>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="small fw-bold text-muted text-uppercase mb-1">Fondo (Color)</label>
                                    <input type="color" name="contratapa_bg_color" class="form-control form-control-color form-control-sm w-100 shadow-sm" value="<?php echo $revista_cfg['contratapa_bg_color'] ?? '#222222'; ?>">
                                </div>
                                <div class="col-6">
                                    <label class="small fw-bold text-muted text-uppercase mb-1">Texto (Color)</label>
                                    <input type="color" name="contratapa_texto_color" class="form-control form-control-color form-control-sm w-100 shadow-sm" value="<?php echo $revista_cfg['contratapa_texto_color'] ?? '#ffffff'; ?>">
                                </div>
                            </div>
                            <div class="form-check form-switch mt-2 bg-light p-2 rounded-3 border">
                                <input class="form-check-input ms-1" type="checkbox" name="mostrar_qr" value="1" id="mqr" <?php echo ($revista_cfg['mostrar_qr'] ?? 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold text-dark ms-2 small" for="mqr">Mostrar Código QR al Final</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-12 col-lg-4">
                    <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-info border-4">
                        <div class="card-header bg-white py-3 fw-bold text-info border-0">
                            <i class="bi bi-palette me-2"></i> 3. ESTÉTICA GLOBAL
                        </div>
                        <div class="card-body">
                            <div class="mb-4">
                                <label class="small fw-bold text-muted text-uppercase mb-1">Tipografía de la Revista</label>
                                <select name="fuente_global" class="form-select form-select-sm shadow-sm fw-bold">
                                    <?php $f = $fuente_actual; ?>
                                    <option value="Poppins" <?php echo ($f=='Poppins')?'selected':''; ?>>Poppins (Moderna)</option>
                                    <option value="Roboto" <?php echo ($f=='Roboto')?'selected':''; ?>>Roboto (Clásica)</option>
                                    <option value="Anton" <?php echo ($f=='Anton')?'selected':''; ?>>Anton (Impacto)</option>
                                </select>
                            </div>
                            
                            <div class="p-3 bg-light rounded-4 border border-light-subtle">
                                <label class="small fw-bold text-muted text-uppercase mb-2 d-block border-bottom pb-2">Fondo del Logo Superior</label>
                                <div class="row g-2 align-items-center">
                                    <div class="col-4">
                                        <input type="color" name="tapa_banner_color" class="form-control form-control-color form-control-sm w-100 shadow-sm" value="<?php echo $revista_cfg['tapa_banner_color'] ?? '#ffffff'; ?>">
                                    </div>
                                    <div class="col-8">
                                        <select name="tapa_banner_opacity" class="form-select form-select-sm shadow-sm fw-bold">
                                            <option value="0" <?php echo (($revista_cfg['tapa_banner_opacity']??'')=='0')?'selected':''; ?>>Invisible</option>
                                            <option value="0.9" <?php echo (($revista_cfg['tapa_banner_opacity']??'')=='0.9')?'selected':''; ?>>Visible</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            
            </form>

        <hr class="my-5 text-muted opacity-25">

        <div class="d-flex justify-content-between align-items-center mb-3 px-1">
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-file-earmark-richtext text-success me-2"></i> Páginas y Publicidad</h5>
        </div>

        <div class="row g-4">
            
            <div class="col-md-5 col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 h-100 border-top border-success border-4">
                    <div class="card-header bg-white py-3 text-success fw-bold border-0">
                        <i class="bi bi-plus-circle-fill me-2"></i> Agregar Página
                    </div>
                    <div class="card-body bg-light rounded-bottom">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="accion" value="nueva_pagina">
                            <div class="mb-3">
                                <label class="small fw-bold text-muted text-uppercase mb-1">Referencia Interna</label>
                                <input type="text" name="nombre" class="form-control form-control-sm shadow-sm fw-bold" placeholder="Ej: Publicidad Coca-Cola" required>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-12">
                                    <label class="small fw-bold text-muted text-uppercase mb-1">Imagen (HD Recomendado)</label>
                                    <input type="file" name="imagen" class="form-control form-control-sm shadow-sm" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="small fw-bold text-muted text-uppercase mb-1">Posición (Orden de vista)</label>
                                <input type="number" name="posicion" class="form-control form-control-sm shadow-sm fw-bold" required value="10">
                            </div>
                            <div class="mb-3">
                                <label class="small fw-bold text-muted text-uppercase mb-1">Texto del Botón</label>
                                <input type="text" name="btn_txt" class="form-control form-control-sm shadow-sm" placeholder="Ej: Comprar Ahora">
                            </div>
                            <div class="mb-4">
                                <label class="small fw-bold text-muted text-uppercase mb-1">Enlace (URL)</label>
                                <input type="text" name="btn_link" class="form-control form-control-sm shadow-sm" placeholder="https://...">
                            </div>
                            <button type="submit" class="btn btn-success w-100 fw-bold py-2 shadow-sm rounded-pill">
                                <i class="bi bi-plus-lg me-2"></i> CARGAR PÁGINA
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-7 col-lg-8">
                <div id="lista_paginas" class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                    <div class="card-header bg-white py-3 fw-bold d-flex justify-content-between align-items-center border-bottom-0">
                        <span class="text-dark"><i class="bi bi-list-ul text-primary me-2"></i> Índice de Páginas</span>
                        <span class="badge bg-primary rounded-pill"><?php echo count($paginas); ?> Páginas</span>
                    </div>
                    <div class="table-responsive">
                        <style>
                            @media (max-width: 768px) {
                                .tabla-movil-ajustada td, .tabla-movil-ajustada th { padding: 0.5rem 0.3rem !important; font-size: 0.75rem !important; }
                                .tabla-movil-ajustada .fw-bold { font-size: 0.85rem !important; }
                            }
                        </style>
                        <table class="table table-hover align-middle mb-0 tabla-movil-ajustada">
                            <thead class="bg-light small text-uppercase text-muted">
                                <tr>
                                    <th class="ps-4" width="80">Orden</th>
                                    <th>Imagen</th>
                                    <th>Referencia</th>
                                    <th class="text-end pe-4">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(!empty($paginas)): ?>
                                    <?php foreach($paginas as $p): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-primary fs-6">#<?php echo $p['posicion']; ?></td>
                                        <td>
                                            <img src="<?php echo $p['imagen_url']; ?>" class="rounded shadow-sm" style="height:50px; width:70px; object-fit:cover; border: 1px solid #ddd;">
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($p['nombre_referencia']); ?></div>
                                            <?php if($p['boton_link']): ?>
                                                <small class="text-muted"><i class="bi bi-link-45deg"></i> Link activo</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-4 text-nowrap">
                                            <button onclick='abrirModalEditarPagina(<?php echo json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' class="btn btn-sm btn-outline-primary border-0 rounded-circle shadow-sm me-1">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            <button onclick="confirmarBorrado(<?php echo $p['id']; ?>)" class="btn btn-sm btn-outline-danger border-0 rounded-circle shadow-sm">
                                                <i class="bi bi-trash3-fill"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted">No has cargado páginas adicionales todavía.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div> <div class="mt-4 text-center text-md-end" style="position: sticky; bottom: 20px; z-index: 100; pointer-events: none;">
            <button type="submit" form="form_diseno" class="btn btn-primary btn-lg fw-bold shadow-lg rounded-pill px-5 py-3 border border-2 border-white" style="pointer-events: auto;">
                <i class="bi bi-save-fill me-2"></i> GUARDAR DISEÑO
            </button>
        </div>

    </div>

    <div class="modal fade" id="modalEditarPagina" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" enctype="multipart/form-data" class="modal-content rounded-4 border-0 shadow-lg">
                <input type="hidden" name="accion" value="editar_pagina">
                <input type="hidden" name="id_pagina" id="edit_id_pagina">
                
                <div class="modal-header bg-primary text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i> Editar Página</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light rounded-bottom">
                    <div class="mb-3">
                        <label class="small fw-bold text-muted text-uppercase mb-1">Referencia Interna</label>
                        <input type="text" name="nombre" id="edit_nombre" class="form-control form-control-sm shadow-sm fw-bold" required>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted text-uppercase mb-1">Nueva Imagen (Opcional)</label>
                        <input type="file" name="imagen" class="form-control form-control-sm shadow-sm">
                        <small class="text-muted d-block mt-1" style="font-size: 11px;">Dejá vacío para conservar la imagen actual.</small>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-12">
                            <label class="small fw-bold text-muted text-uppercase mb-1">Posición (Orden de vista)</label>
                            <input type="number" name="posicion" id="edit_posicion" class="form-control form-control-sm shadow-sm fw-bold" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted text-uppercase mb-1">Texto del Botón</label>
                        <input type="text" name="btn_txt" id="edit_btn_txt" class="form-control form-control-sm shadow-sm">
                    </div>
                    <div class="mb-4">
                        <label class="small fw-bold text-muted text-uppercase mb-1">Enlace (URL)</label>
                        <input type="text" name="btn_link" id="edit_btn_link" class="form-control form-control-sm shadow-sm">
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-sm rounded-pill">
                        <i class="bi bi-save-fill me-2"></i> GUARDAR CAMBIOS
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModalEditarPagina(pag) {
            document.getElementById('edit_id_pagina').value = pag.id;
            document.getElementById('edit_nombre').value = pag.nombre_referencia;
            document.getElementById('edit_posicion').value = pag.posicion;
            document.getElementById('edit_btn_txt').value = pag.boton_texto || '';
            document.getElementById('edit_btn_link').value = pag.boton_link || '';
            
            var myModal = new bootstrap.Modal(document.getElementById('modalEditarPagina'));
            myModal.show();
        }

        function confirmarBorrado(id) {
            Swal.fire({
                title: '¿Eliminar página?',
                text: "Esta página se quitará de la revista permanentemente.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, borrar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = "admin_revista.php?borrar=" + id;
                }
            })
        }

        // Notificaciones
        const urlParams = new URLSearchParams(window.location.search);
        if(urlParams.get('msg') === 'del') {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Página eliminada', showConfirmButton: false, timer: 3000 });
        } else if (urlParams.get('msg') === 'edit') {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Página actualizada', showConfirmButton: false, timer: 3000 });
        }
    </script>

    <?php include 'includes/layout_footer.php'; ?>
</body>
</html>