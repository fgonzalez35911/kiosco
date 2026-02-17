<?php
// admin_revista.php - VERSIÓN PREMIUM AZUL + MENÚ FIXED
session_start();
require_once 'includes/db.php';

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

    $stmt_actual = $conexion->query("SELECT img_tapa, img_contratapa FROM revista_config WHERE id=1");
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
            WHERE id=1";
    
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
            $stmt = $conexion->prepare("INSERT INTO revista_paginas (nombre_referencia, posicion, imagen_url, boton_texto, boton_link) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nombre, $posicion, $ruta, $btn_txt, $btn_link]);
            $mensaje = '✅ Página agregada.'; $tipo_mensaje = 'success';
        }
    }
}

// 3. BORRAR
if (isset($_GET['borrar'])) {
    $id = (int)$_GET['borrar'];
    $conexion->query("DELETE FROM revista_paginas WHERE id=$id");
    header("Location: admin_revista.php?msg=del"); exit;
}

// CARGA DE DATOS
$revista_cfg = $conexion->query("SELECT * FROM revista_config WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];
$paginas = $conexion->query("SELECT * FROM revista_paginas ORDER BY posicion ASC")->fetchAll(PDO::FETCH_ASSOC);
$total_paginas = count($paginas);
$fuente_actual = $revista_cfg['fuente_global'] ?? 'Poppins';
// Carga de estados para los widgets
$estado_tapa = !empty($revista_cfg['img_tapa']) ? 'LISTA' : 'VACÍA';
$estado_cierre = !empty($revista_cfg['img_contratapa']) ? 'LISTO' : 'VACÍO';
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

    <?php include 'includes/layout_header.php'; ?></div>

    <div class="header-blue" style="background-color: <?php echo $color_sistema; ?> !important;">
    <i class="bi bi-palette-fill bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Diseño de Revista</h2>
                <p class="opacity-75 mb-0 text-white small">Personalización visual y gestión de publicidad digital.</p>
            </div>
            <a href="revista.php" target="_blank" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm">
                <i class="bi bi-eye-fill me-2"></i> VER REVISTA
            </a>
        </div>

        <div class="row g-3">
            <div class="col-6 col-md-3">
                <a href="#lista_paginas" class="header-widget">
                    <div>
                        <div class="widget-label">Páginas Especiales</div>
                        <div class="widget-value text-white"><?php echo $total_paginas; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white">
                        <i class="bi bi-layers"></i>
                    </div>
                </a>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Tipografía Global</div>
                        <div class="widget-value text-white"><?php echo $fuente_actual; ?></div>
                    </div>
                    <div class="icon-box bg-success bg-opacity-20 text-white">
                        <i class="bi bi-fonts"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Estado Portada</div>
                        <div class="widget-value text-white"><?php echo $estado_tapa; ?></div>
                    </div>
                    <div class="icon-box bg-warning bg-opacity-20 text-white">
                        <i class="bi bi-front"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget border-info">
                    <div>
                        <div class="widget-label">Estado Cierre</div>
                        <div class="widget-value text-white" style="font-size: 1.1rem;"><?php echo $estado_cierre; ?></div>
                    </div>
                    <div class="icon-box bg-info bg-opacity-20 text-white">
                        <i class="bi bi-back"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

    <div class="container pb-5">
        
        <?php if($mensaje): ?>
            <script>Swal.fire({ icon: '<?php echo $tipo_mensaje; ?>', title: '<?php echo $mensaje; ?>', timer: 2000, showConfirmButton: false });</script>
        <?php endif; ?>

        <div class="row g-4">
            
            <div class="col-12 col-lg-5">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="accion" value="guardar_config">
                    
                    <h5 class="section-title">Estética General</h5>
                    
                    <div class="card card-custom mb-4">
                        <div class="card-body">
                            <label class="form-label">Tipografía de la Revista</label>
                            <select name="fuente_global" class="form-select border-0 bg-light fw-bold">
                                <?php $f = $fuente_actual; ?>
                                <option value="Poppins" <?php echo ($f=='Poppins')?'selected':''; ?>>Poppins (Moderna)</option>
                                <option value="Roboto" <?php echo ($f=='Roboto')?'selected':''; ?>>Roboto (Clásica)</option>
                                <option value="Anton" <?php echo ($f=='Anton')?'selected':''; ?>>Anton (Impacto)</option>
                            </select>
                        </div>
                    </div>

                    <h5 class="section-title">Estructura Principal</h5>

                    <div class="card card-custom mb-3 shadow-sm border-start border-primary border-4">
                        <div class="card-header bg-white py-3 fw-bold text-primary">
                            <i class="bi bi-image me-2"></i> 1. PORTADA (TAPA)
                        </div>
                        <div class="card-body">
                            <div class="mb-3 text-center bg-light p-2 rounded">
                                <?php if(!empty($revista_cfg['img_tapa'])): ?>
                                    <img src="<?php echo $revista_cfg['img_tapa']; ?>?v=<?php echo time(); ?>" class="preview-img">
                                <?php else: ?>
                                    <div class="preview-img d-flex align-items-center justify-content-center text-muted">Sin imagen de tapa</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Subir nueva Imagen</label>
                                <input type="file" name="img_tapa" class="form-control form-control-sm">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Oscuridad de Fondo (Overlay)</label>
                                <input type="range" name="tapa_overlay" class="form-range" min="0" max="0.9" step="0.1" value="<?php echo $revista_cfg['tapa_overlay'] ?? '0.4'; ?>">
                            </div>

                            <div class="row g-2">
                                <div class="col-8">
                                    <label class="form-label">Título Principal</label>
                                    <input type="text" name="titulo_tapa" class="form-control" value="<?php echo $revista_cfg['titulo_tapa'] ?? ''; ?>">
                                </div>
                                <div class="col-4">
                                    <label class="form-label">Color</label>
                                    <input type="color" name="tapa_tit_color" class="form-control form-control-color w-100" value="<?php echo $revista_cfg['tapa_tit_color'] ?? '#ffde00'; ?>">
                                </div>
                                <div class="col-8">
                                    <label class="form-label">Subtítulo</label>
                                    <input type="text" name="subtitulo_tapa" class="form-control" value="<?php echo $revista_cfg['subtitulo_tapa'] ?? ''; ?>">
                                </div>
                                <div class="col-4">
                                    <label class="form-label">Color</label>
                                    <input type="color" name="tapa_sub_color" class="form-control form-control-color w-100" value="<?php echo $revista_cfg['tapa_sub_color'] ?? '#ffffff'; ?>">
                                </div>
                            </div>

                            <div class="mt-3 p-2 bg-light rounded border">
                                <label class="form-label mb-1 d-block">Fondo del Logo Superior</label>
                                <div class="input-group input-group-sm">
                                    <input type="color" name="tapa_banner_color" class="form-control form-control-color" value="<?php echo $revista_cfg['tapa_banner_color'] ?? '#ffffff'; ?>">
                                    <select name="tapa_banner_opacity" class="form-select">
                                        <option value="0" <?php echo (($revista_cfg['tapa_banner_opacity']??'')=='0')?'selected':''; ?>>Invisible</option>
                                        <option value="0.9" <?php echo (($revista_cfg['tapa_banner_opacity']??'')=='0.9')?'selected':''; ?>>Visible</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card card-custom mb-4 shadow-sm border-start border-secondary border-4">
                        <div class="card-header bg-white py-3 fw-bold text-secondary">
                            <i class="bi bi-door-closed me-2"></i> 2. CONTRATAPA (FINAL)
                        </div>
                        <div class="card-body">
                            <div class="mb-3 text-center bg-light p-2 rounded">
                                <?php if(!empty($revista_cfg['img_contratapa'])): ?>
                                    <img src="<?php echo $revista_cfg['img_contratapa']; ?>?v=<?php echo time(); ?>" class="preview-img">
                                <?php else: ?>
                                    <div class="preview-img d-flex align-items-center justify-content-center text-muted">Sin imagen de contratapa</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Imagen de Cierre</label>
                                <input type="file" name="img_contratapa" class="form-control form-control-sm">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Título Despedida</label>
                                <input type="text" name="contratapa_titulo" class="form-control" value="<?php echo $revista_cfg['contratapa_titulo'] ?? ''; ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Mensaje Final</label>
                                <textarea name="contratapa_texto" class="form-control" rows="2"><?php echo $revista_cfg['contratapa_texto'] ?? ''; ?></textarea>
                            </div>

                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label">Fondo (Color)</label>
                                    <input type="color" name="contratapa_bg_color" class="form-control form-control-color w-100" value="<?php echo $revista_cfg['contratapa_bg_color'] ?? '#222222'; ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Texto (Color)</label>
                                    <input type="color" name="contratapa_texto_color" class="form-control form-control-color w-100" value="<?php echo $revista_cfg['contratapa_texto_color'] ?? '#ffffff'; ?>">
                                </div>
                            </div>
                            
                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" name="mostrar_qr" value="1" id="mqr" <?php echo ($revista_cfg['mostrar_qr'] ?? 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold text-dark" for="mqr">Mostrar Código QR al Final</label>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 fw-bold py-3 shadow hover-zoom">
                        <i class="bi bi-save-fill me-2"></i> GUARDAR TODO EL DISEÑO
                    </button>
                </form>
            </div>

            <div class="col-12 col-lg-7">
                <h5 class="section-title">Contenido de la Revista</h5>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="accion" value="nueva_pagina">
                    
                    <div class="card card-custom mb-4 border-top border-success border-4">
                        <div class="card-header bg-white py-3 text-success fw-bold">
                            <i class="bi bi-plus-circle-fill me-2"></i> Agregar Página de Publicidad / Especial
                        </div>
                        <div class="card-body bg-light">
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label class="form-label">Referencia Interna</label>
                                    <input type="text" name="nombre" class="form-control shadow-sm" placeholder="Ej: Publicidad Coca-Cola" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Posición (Orden)</label>
                                    <input type="number" name="posicion" class="form-control shadow-sm" required value="10">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">Imagen de la Página (HD Recomendado)</label>
                                    <input type="file" name="imagen" class="form-control shadow-sm" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Texto del Botón</label>
                                    <input type="text" name="btn_txt" class="form-control shadow-sm" placeholder="Ej: Comprar Ahora">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Enlace (URL)</label>
                                    <input type="text" name="btn_link" class="form-control shadow-sm" placeholder="https://...">
                                </div>
                                <div class="col-12 text-end">
                                    <button type="submit" class="btn btn-success px-5 fw-bold shadow">
                                        <i class="bi bi-plus-lg me-2"></i> CARGAR PÁGINA
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

                <div id="lista_paginas" class="card card-custom shadow-sm">
                    <div class="card-header bg-white py-3 fw-bold d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-list-ul me-2"></i> Índice de Páginas</span>
                        <span class="badge bg-primary rounded-pill"><?php echo count($paginas); ?> Páginas</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light small text-uppercase text-muted">
                                <tr>
                                    <th class="ps-3" width="80">Orden</th>
                                    <th>Imagen</th>
                                    <th>Referencia</th>
                                    <th class="text-end pe-3">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(!empty($paginas)): ?>
                                    <?php foreach($paginas as $p): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-primary">#<?php echo $p['posicion']; ?></td>
                                        <td>
                                            <img src="<?php echo $p['imagen_url']; ?>" class="rounded shadow-sm" style="height:50px; width:70px; object-fit:cover; border: 1px solid #ddd;">
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?php echo $p['nombre_referencia']; ?></div>
                                            <?php if($p['boton_link']): ?>
                                                <small class="text-muted"><i class="bi bi-link-45deg"></i> Link activo</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3">
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
        </div>
    </div>

    <script>
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

        // Notificación de éxito al borrar
        const urlParams = new URLSearchParams(window.location.search);
        if(urlParams.get('msg') === 'del') {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Página eliminada', showConfirmButton: false, timer: 3000 });
        }
    </script>

    <?php include 'includes/layout_footer.php'; ?>
</body>
</html>