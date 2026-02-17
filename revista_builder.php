<?php
// revista_builder.php - VERSIÓN FINAL CORREGIDA: IMPRESIÓN LIMPIA (SIN ENCABEZADOS DE NAVEGADOR)
session_start();
require_once 'includes/db.php';
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// 1. PRODUCTOS
$sql = "SELECT * FROM productos WHERE activo = 1 ORDER BY descripcion ASC";
$productos = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// 2. CONFIGURACIÓN
$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);

// 3. DATOS SMART
$url_tienda = "https://federicogonzalez.net/kiosco/tienda.php";
$url_registro = "https://federicogonzalez.net/kiosco/registro_cliente.php";
$logo_url = !empty($conf['logo_url']) ? $conf['logo_url'] : 'https://cdn-icons-png.flaticon.com/512/3594/3594450.png';

$smart_data = [
    'nombre' => htmlspecialchars($conf['nombre_negocio'], ENT_QUOTES),
    'direccion' => htmlspecialchars($conf['direccion_local'] ?? $conf['direccion'] ?? 'Dirección', ENT_QUOTES),
    'whatsapp' => htmlspecialchars($conf['telefono_whatsapp'] ?? $conf['telefono'] ?? 'WhatsApp', ENT_QUOTES),
    'logo' => $logo_url,
    'url_tienda' => $url_tienda,
    'qr_img' => "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($url_registro)
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revista Ofertas</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@300;400;700;900&family=Bebas+Neue&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    
    <style>
        /* Línea 61: Cambiá 296 por 297 */
        :root { --yaguar-yellow: #ffc400; --yaguar-red: #d50000; --a4-w: 210mm; --a4-h: 296.7mm; }
        /* PASO 2: AJUSTE DE ALTURAS */
        body { 
    background-color: #e9ecef; 
    font-family: 'Roboto Condensed', sans-serif; 
    height: 100vh; 
    overflow: hidden; 
    user-select: none; 
    margin: 0;
    display: flex; /* Agregamos esto */
    flex-direction: column; /* Agregamos esto */
}

       /* 3. Buscá '#app-container' y reemplazalo por esto */
#app-container { 
    display: flex; 
    height: calc(100vh - 56px); /* El 56px es la altura del header */
    position: relative;
    margin-top: 0; 
}
        
        /* SIDEBAR */
        #sidebar { width: 340px; background: white; border-right: 1px solid #ccc; display: flex; flex-direction: column; z-index: 2000; }
        .nav-tabs .nav-link { border-radius: 0; color: #555; font-weight: bold; cursor: pointer; }
        .nav-tabs .nav-link.active { border-top: 4px solid var(--yaguar-red); color: var(--yaguar-red); background: #f8f9fa; }
        .tab-content { flex-grow: 1; overflow-y: auto; background: #f8f9fa; padding: 15px; }
        .tool-item { padding: 8px; background: #fff; border: 1px solid #ddd; margin-bottom: 5px; cursor: grab; display: flex; align-items: center; border-radius: 4px; font-size: 14px; color: #333; }
        .tool-item:hover { background: #f0f0f0; border-color: #bbb; }
        .tool-item img { width: 40px; height: 40px; object-fit: contain; margin-right: 10px; background: white; border: 1px solid #eee; display: block; }

        /* WORKSPACE */
        #workspace-container { flex-grow: 1; display: flex; flex-direction: column; background-color: #525659; position: relative; }
        #toolbar-top { 
    height: 50px; 
    background: #343a40; 
    color: white; 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
    padding: 0 20px; 
    margin-top: 0; /* CAMBIADO DE 20px A 0 */
    z-index: 1001;
}
        #canvas-scroll { flex-grow: 1; overflow: auto; display: flex; justify-content: center; padding: 50px; background-image: radial-gradient(#666 1px, transparent 1px); background-size: 20px 20px; }

        /* PAGINA A4 */
        .page-wrapper { margin-bottom: 50px; position: relative; transition: all 0.2s; }
        .page-header-ui { background: #fff; color: #333; padding: 5px 10px; font-size: 12px; display: flex; justify-content: space-between; width: var(--a4-w); margin: 0 auto; border: 1px solid #ccc; border-bottom: none; font-weight: bold; border-top-left-radius: 4px; border-top-right-radius: 4px; }
        .page { width: var(--a4-w); min-height: var(--a4-h); background: white; position: relative; overflow: visible; box-shadow: 0 5px 15px rgba(0,0,0,0.5); transform-origin: top center; margin: 0 auto; }
        
        .page::after { content: "--- FIN RECOMENDADO A4 ---"; position: absolute; top: var(--a4-h); left: 0; width: 100%; border-bottom: 2px dashed red; color: red; font-size: 10px; text-align: right; pointer-events: none; z-index: 100; opacity: 0.6; box-sizing: border-box;}

        /* SELECCIONES */
        .page.active-page { outline: 4px solid var(--yaguar-yellow); box-shadow: 0 0 20px rgba(255, 196, 0, 0.6); z-index: 10; }
        .sec-col.active-col { box-shadow: inset 0 0 0 3px var(--yaguar-yellow); background-color: rgba(255, 196, 0, 0.1); }

        /* ELEMENTOS FLOTANTES */
        .float-item { position: absolute; cursor: grab; border: 1px dashed transparent; z-index: 200; min-width: 20px; min-height: 20px; }
        .float-item:hover { outline: 2px dashed #0d6efd; cursor: move; }
        .float-item.redimensionando { border: 2px solid #0d6efd !important; z-index: 5000; box-shadow: 0 0 10px rgba(13, 110, 253, 0.3); }
        .resize-handle { width: 14px; height: 14px; background: #0d6efd; position: absolute; bottom: -7px; right: -7px; cursor: se-resize; display: none; border-radius: 50%; border: 2px solid white; z-index: 6000; box-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .float-item.redimensionando .resize-handle { display: block; }
        .banner-img { width: 100%; height: 100%; object-fit: cover; object-position: 50% 50%; cursor: grab; transition: transform 0.1s; }
        .banner-img:active { cursor: grabbing; }

        /* MENU CONTEXTUAL */
        #context-menu { position: fixed; display: none; background: #fff; color: #333; border: 1px solid #ccc; border-radius: 4px; box-shadow: 0 5px 15px rgba(0,0,0,0.2); z-index: 9999; width: 220px; padding: 5px 0; }
        .ctx-item { padding: 8px 15px; cursor: pointer; display: flex; align-items: center; font-size: 14px; }
        .ctx-item:hover { background: #f0f0f0; }
        .ctx-divider { height: 1px; background: #eee; margin: 5px 0; }
        .ctx-header { padding: 5px 15px; font-size: 11px; color: #888; font-weight: bold; background: #f9f9f9; border-bottom: 1px solid #eee; }

        /* SECCIONES (FILAS) */
        .section-row { position: relative; width: 100%; border: 1px dashed transparent; transition: 0.1s; overflow: visible; }
        .section-row:hover { border: 1px dashed #0d6efd; z-index: 10; }
        
        .row-resizer { position: absolute; bottom: -5px; left: 0; width: 100%; height: 15px; cursor: ns-resize; display: none; align-items: center; justify-content: center; z-index: 50; }
        .section-row:hover .row-resizer { display: flex; }
        
        .sec-tools { position: absolute; right: -32px; top: 0; width: 30px; display: none; flex-direction: column; gap: 3px; z-index: 9999; }
        .section-row:hover .sec-tools { display: flex; }
        
        .btn-sec { width: 28px; height: 28px; background: white; border: 1px solid #999; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 14px; border-radius: 4px; box-shadow: 1px 1px 3px rgba(0,0,0,0.1); color: #333; }
        .btn-sec:hover { background: #0d6efd; color: white; border-color: #0d6efd; }
        .btn-sec.del { background: #fff; color: #dc3545; border-color: #dc3545; }
        .btn-sec.del:hover { background: #dc3545; color: white; }

        .header-bg-tools { position: absolute; top: 5px; right: 5px; background: rgba(255,255,255,0.9); padding: 4px; border-radius: 4px; display: none; gap: 5px; z-index: 50; border: 1px solid #ccc; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .section-row:hover .header-bg-tools { display: flex; }

        /* GRILLAS */
        .sec-bg { width: 100%; height: 100%; position: absolute; top: 0; left: 0; z-index: 0; }
        .sec-grid { display: flex; flex-wrap: wrap; width: 100%; height: 100%; position: relative; z-index: 5; }
        .sec-col { border: 1px dashed #ddd; flex: 1; position: relative; display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100%; min-height: 50px; cursor: pointer; overflow: hidden; }
        .sec-col:hover { background: #fcfcfc; }
        .sec-col.filled { border: none; background: white; }
        
        /* TARJETA PRODUCTO */
        .card-prod { width: 100%; height: 100%; padding: 4px; display: flex; flex-direction: column; pointer-events: none; align-items: center; position: relative; justify-content: center; }
        .badge-off { position: absolute; top: 0; right: 0; background: var(--yaguar-red); color: white; font-size: 10px; font-weight: 900; padding: 2px 5px; z-index: 5; }
        .prod-img { flex: 0 0 auto; max-width: 90%; max-height: 90px; object-fit: contain; margin-bottom: 3px; }
        .prod-title { text-align: center; font-size: 12px; font-weight: 700; line-height: 1.1; margin-bottom: 2px; text-transform: uppercase; color: #333; max-height: 28px; overflow: hidden; }
        .prod-price-box { background: var(--yaguar-yellow); color: var(--yaguar-red); font-weight: 900; font-size: 1.4rem; transform: rotate(-2deg); padding: 0 6px; border-radius: 4px; box-shadow: 2px 2px 4px rgba(0,0,0,0.2); }
        .btn-del-prod { position: absolute; top: 0; left: 0; background: red; color: white; border: none; width: 20px; height: 20px; font-size: 12px; cursor: pointer; display: none; z-index: 10; pointer-events: auto; }
        .sec-col:hover .btn-del-prod { display: block; }

        /* --- CLASES CRÍTICAS PARA PDF --- */
        body.generating-pdf { background: white !important; }
        body.generating-pdf #sidebar, body.generating-pdf #toolbar-top, body.generating-pdf .sec-tools, body.generating-pdf .header-bg-tools, body.generating-pdf .resize-handle, body.generating-pdf .btn-del-prod, body.generating-pdf .page-header-ui, body.generating-pdf .row-resizer, body.generating-pdf .no-print { display: none !important; }
        body.generating-pdf .page { margin: 0 !important; box-shadow: none !important; border: none !important; outline: none !important; transform: none !important; }
        body.generating-pdf .page::after { display: none !important; }
        body.generating-pdf .section-row { border: none !important; }
        body.generating-pdf .sec-col { border: none !important; background: transparent !important; }
        body.generating-pdf .float-item { border: none !important; }

   


/* El sidebar original tiene 2000, bajalo a 1500 */
#sidebar { 
    width: 340px; 
    background: white; 
    border-right: 1px solid #ccc; 
    display: flex; 
    flex-direction: column; 
    z-index: 1050; /* Un poco más bajo que el menú desplegable */
    height: 100%;
}

      
/* --- ESTO REEMPLAZA TODO EL LÍO DE ABAJO --- */
.navbar-container {
    height: 56px;
    position: relative;
    z-index: 3000;
}

#toolbar-top { 
    height: 50px; 
    background: #343a40; 
    color: white; 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
    padding: 0 20px; 
    z-index: 1001;
}

@media print {
    .page-header-ui, .page::after, .no-print, .navbar-container, .sec-tools, .row-resizer { 
        display: none !important; 
    }
    @page { margin: 0 !important; size: A4; }
    body { margin: 0 !important; padding: 0 !important; height: auto !important; overflow: visible !important; }
    .page { 
        width: 210mm !important; 
        height: 297mm !important; 
        padding: 0 !important; /* ACÁ ESTABA EL ERROR: AHORA ES CERO */
        margin: 0 !important;
        box-shadow: none !important;
        transform: none !important;
    }
}
/* --- REGLA DEFINITIVA PARA QUE EL PDF SALGA LIMPIO Y EN UNA HOJA --- */
.pdf-clean .page-header-ui { 
    display: none !important; /* ESTO BORRA EL TÍTULO 'HOJA A4' Y EL BOTÓN 'ELIMINAR' */
}

.pdf-clean #canvas-scroll { 
    display: block !important; 
    padding: 0 !important; 
    margin: 0 !important; 
    background: white !important; 
}

.pdf-clean .page-wrapper { 
    margin: 0 !important; 
    padding: 0 !important; 
    border: none !important;
    height: 296.7mm !important;
}

.pdf-clean .page { 
    transform: none !important; 
    margin: 0 !important; 
    width: 210mm !important; 
    height: 296.7mm !important; 
    box-shadow: none !important; 
    border: none !important; 
    position: relative !important; 
    left: 0 !important; 
    top: 0 !important;
    display: block !important; /* Asegura que la hoja SEA VISIBLE */
    overflow: hidden !important; 
}
</style>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

</head>
<body>
<div class="no-print">
    <div class="navbar-container no-print">
    <?php include 'includes/layout_header.php'; ?>
</div>
</div>

<div id="context-menu">
    <div class="ctx-header">ACCIONES</div>
    <div class="ctx-item ctx-general" onclick="menuAction('resize_mode')"><i class="bi bi-arrows-angle-expand text-primary me-2"></i> Redimensionar</div>
    <div class="ctx-item ctx-general" onclick="menuAction('delete')"><i class="bi bi-trash text-danger me-2"></i> Eliminar</div>
    <div class="ctx-item ctx-text-only" onclick="menuAction('edit_text')"><i class="bi bi-pencil me-2"></i> Editar Texto</div>
    <div class="ctx-item ctx-text-only" onclick="menuAction('color')"><i class="bi bi-palette me-2"></i> Color <input type="color" id="ctx-color" style="opacity:0; width:1px; position:absolute;"></div>
    <div class="ctx-divider ctx-banner-only"></div>
    <div class="ctx-header ctx-banner-only">IMAGEN (Zoom: Rueda | Mover: Arrastrar)</div>
    <div class="ctx-item ctx-banner-only" onclick="menuAction('fit_cover')"><i class="bi bi-aspect-ratio me-2"></i> Resetear (Rellenar)</div>
    <div class="ctx-item ctx-banner-only" onclick="menuAction('fit_contain')"><i class="bi bi-box me-2"></i> Ajustar Completa</div>
    <div class="ctx-item ctx-banner-only" onclick="menuAction('delete_banner')"><i class="bi bi-trash text-danger me-2"></i> Quitar Imagen</div>
</div>

<div id="app-container">
    <div id="sidebar" class="no-print">
        <div class="p-3 bg-light border-bottom"><h5 class="m-0 fw-bold text-dark">CONSTRUCTOR</h5></div>
        <ul class="nav nav-tabs nav-fill">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-struct">Bloques</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-decor">Decorar</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-prods">Productos</a></li>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade show active" id="tab-struct">
                <div class="alert alert-warning small p-2 fw-bold text-center">
                    A4 RECOMENDADO:<br>Header + 4 Filas (210px) + Footer
                </div>
                <p class="text-muted small mt-2 fw-bold">PRESETS</p>
                <div class="d-grid gap-2">
                    <button class="btn btn-outline-dark text-start" onclick="addPreset('header')"><i class="bi bi-window-dock"></i> Header (130px)</button>
                    <button class="btn btn-outline-dark text-start" onclick="addPreset('footer')"><i class="bi bi-window-sidebar"></i> Footer (130px)</button>
                </div>
                <p class="text-muted small mt-3 fw-bold">GRILLAS (210px)</p>
                <div class="row g-2">
                    <div class="col-6"><button class="btn btn-light border w-100" onclick="addSection('grid', 2)">2 Cols</button></div>
                    <div class="col-6"><button class="btn btn-light border w-100" onclick="addSection('grid', 3)">3 Cols</button></div>
                    <div class="col-6"><button class="btn btn-light border w-100" onclick="addSection('grid', 4)">4 Cols</button></div>
                    <div class="col-6"><button class="btn btn-light border w-100" onclick="addSection('grid', 1)">1 Banner</button></div>
                </div>
            </div>

            <div class="tab-pane fade" id="tab-decor">
                <div class="alert alert-info small p-2 mb-2"><small>1. Selecciona columna.<br>2. Subí imagen.</small></div>
                <div class="tool-item" draggable="true" ondragstart="dragStart(event, 'text', 'TÍTULO', '3rem')"><i class="bi bi-type-h1 me-2"></i> Título</div>
                <div class="tool-item" draggable="true" ondragstart="dragStart(event, 'text', 'Texto', '1rem')"><i class="bi bi-fonts me-2"></i> Texto</div>
                <div class="tool-item" draggable="true" ondragstart="dragStart(event, 'sticker', '¡OFERTA!')"><i class="bi bi-stars text-danger me-2"></i> Sticker</div>
                <hr>
                <div class="tool-item" draggable="true" ondragstart="dragStart(event, 'smart_data', 'direccion')"><i class="bi bi-geo-alt text-danger me-2"></i> Dirección</div>
                <div class="tool-item" draggable="true" ondragstart="dragStart(event, 'smart_data', 'whatsapp')"><i class="bi bi-whatsapp text-success me-2"></i> WhatsApp</div>
                <div class="tool-item" draggable="true" ondragstart="dragStart(event, 'smart_data', 'web')"><i class="bi bi-globe text-primary me-2"></i> Link Tienda</div>
                <div class="tool-item" draggable="true" ondragstart="dragStart(event, 'smart_qr')"><i class="bi bi-qr-code me-2"></i> QR Registro</div>
                <div class="tool-item" draggable="true" ondragstart="dragStart(event, 'smart_logo')"><i class="bi bi-image me-2"></i> Logo</div>
                <hr>
                <label class="small fw-bold">Subir Imagen / Banner:</label>
                <input type="file" class="form-control form-control-sm" accept="image/*" onchange="uploadImg(this)">
            </div>

            <div class="tab-pane fade" id="tab-prods">
                <input type="text" class="form-control mb-2" placeholder="Buscar..." onkeyup="filterProds(this)">
                <div id="prods-list">
                    <?php foreach($productos as $p): 
                        $precioFloat = (float)$p['precio_venta'];
                        $precioDisplay = number_format($precioFloat, 0, ',', '.');
                        $esOferta = (!empty($p['precio_oferta']) && $p['precio_oferta'] > 0) ? 1 : 0;
                    ?>
                    <div class="tool-item prod-source" draggable="true" 
                         data-nombre="<?php echo htmlspecialchars($p['descripcion'], ENT_QUOTES); ?>"
                         data-precio="<?php echo $precioFloat; ?>"
                         data-img="<?php echo $p['imagen_url']; ?>"
                         data-oferta="<?php echo $esOferta; ?>" 
                         ondragstart="dragProd(event)">
                        <img src="<?php echo $p['imagen_url'] ?: 'img/no-image.png'; ?>">
                        <div style="line-height:1.2; width:100%;">
                            <div class="small fw-bold text-truncate"><?php echo $p['descripcion']; ?></div>
                            <div class="text-danger small fw-bold">$<?php echo $precioDisplay; ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <div class="p-3 border-top d-grid gap-2">
               
               <button onclick="descargarRevistaPDF()" class="btn btn-success w-100 fw-bold mt-2">
    <i class="bi bi-file-earmark-pdf"></i> DESCARGAR PDF DIRECTO
</button>
        </div>
    </div>

    <div id="workspace-container" onclick="deselectAll(event)">
        <div id="toolbar-top" class="no-print">
            <div class="fw-bold text-white">EDITOR VISUAL A4</div>
            <div>
                <button class="btn btn-sm btn-light border" onclick="zoom(-0.1)">-</button>
                <button class="btn btn-sm btn-light border" onclick="zoom(0.1)">+</button>
            </div>
            <button class="btn btn-sm btn-warning fw-bold text-dark border" onclick="addPage()">+ HOJA</button>
        </div>
        <div id="canvas-scroll">
            <div id="pages-container"></div>
        </div>
    </div>
</div>

<script>
    const SMART = <?php echo json_encode($smart_data); ?>;
    let currentZoom = 0.7; 
    let activeElement = null;
    let activePage = null;
    let activeCol = null;

    document.addEventListener("DOMContentLoaded", () => { addPage(); applyZoom(); });

    function setActivePage(pageElement) {
        if(!pageElement) return;
        activePage = pageElement;
        document.querySelectorAll('.page').forEach(p => p.classList.remove('active-page'));
        pageElement.classList.add('active-page');
    }

    function setActiveCol(colElement, ev) {
        if(ev) ev.stopPropagation();
        activeCol = colElement;
        document.querySelectorAll('.sec-col').forEach(c => c.classList.remove('active-col'));
        colElement.classList.add('active-col');
        const p = colElement.closest('.page');
        if(p) setActivePage(p);
    }

    function getTargetPage() {
        if (activePage && document.body.contains(activePage)) return activePage;
        const pages = document.querySelectorAll('.page');
        if (pages.length > 0) { const last = pages[pages.length-1]; setActivePage(last); return last; }
        return null;
    }

    // --- PDF FUNCTION CORREGIDA ---
    function downloadDirectPDF() {
        window.scrollTo(0,0);
        
        // 1. Clonar
        const element = document.getElementById('pages-container');
        const clone = element.cloneNode(true);
        
        // 2. Preparar CLON (Limpiar zoom y bordes)
        clone.style.width = '210mm'; // Ancho A4 exacto
        clone.style.position = 'absolute'; clone.style.top = '0'; clone.style.left = '0'; clone.style.zIndex = '99999'; clone.style.background = 'white';
        
        // Limpiar interfaz del clon
        clone.querySelectorAll('.page-header-ui, .sec-tools, .row-resizer, .resize-handle, .btn-del-prod').forEach(e => e.remove());
        clone.querySelectorAll('.page').forEach(p => {
            p.style.transform = 'none'; // CRÍTICO: Quitar zoom
            p.style.margin = '0';
            p.style.boxShadow = 'none';
            p.style.border = 'none';
            p.classList.remove('active-page');
            p.style.height = '296mm'; 
        });
        clone.querySelectorAll('.sec-col').forEach(c => c.classList.remove('active-col'));
        clone.querySelectorAll('.section-row').forEach(r => r.style.border = 'none');

        // 3. Inyectar clon
        document.body.appendChild(clone);
        document.body.classList.add('generating-pdf'); // Ocultar lo original

        const opt = {
        margin: 0,
        filename: 'Revista_Ofertas.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { 
            scale: 2, 
            useCORS: true, 
            letterRendering: true,
            scrollY: 0,
            scrollX: 0
        },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

        // 5. Generar con pequeña pausa para renderizado
        setTimeout(() => {
            html2pdf().set(opt).from(clone).save().then(() => {
                document.body.classList.remove('generating-pdf');
                if(document.body.contains(clone)) document.body.removeChild(clone);
            }).catch(err => {
                console.error(err);
                document.body.classList.remove('generating-pdf');
                if(document.body.contains(clone)) document.body.removeChild(clone);
                alert('Error al generar PDF. Por favor use la opción IMPRIMIR -> Guardar como PDF.');
            });
        }, 800);
    }

    // --- CONTEXT MENU Y LOGICA ---
    document.addEventListener('contextmenu', function(e) {
        const target = e.target.closest('.float-item, .card-prod, .banner-img');
        if (target && target.classList.contains('banner-img')) {
            e.preventDefault(); showContext(e, target, 'banner');
        } else if (target) {
            e.preventDefault(); showContext(e, target, 'float');
        }
    });

    const ctxMenu = document.getElementById('context-menu');
    const colorPicker = document.getElementById('ctx-color');

    function showContext(e, el, type) {
        activeElement = el;
        document.querySelectorAll('.ctx-general').forEach(i => i.style.display = (type==='float') ? 'flex' : 'none');
        document.querySelectorAll('.ctx-text-only').forEach(i => i.style.display = (el.dataset.type === 'text') ? 'flex' : 'none');
        document.querySelectorAll('.ctx-banner-only').forEach(i => i.style.display = (type==='banner') ? 'flex' : 'none');
        ctxMenu.style.display = 'block'; ctxMenu.style.left = e.pageX + 'px'; ctxMenu.style.top = e.pageY + 'px';
    }

    function menuAction(action) {
        if (!activeElement) return;
        ctxMenu.style.display = 'none';
        if (action === 'resize_mode') { document.querySelectorAll('.redimensionando').forEach(x => x.classList.remove('redimensionando')); activeElement.classList.add('redimensionando'); }
        else if (action === 'delete') { if(confirm('¿Eliminar?')) activeElement.remove(); } 
        else if (action === 'edit_text') { activeElement.contentEditable = true; activeElement.focus(); activeElement.onblur = () => { activeElement.contentEditable = false; }; }
        else if (action === 'color') { colorPicker.click(); colorPicker.oninput = () => { activeElement.style.color = colorPicker.value; }; }
        else if (action === 'fit_cover') { activeElement.style.objectFit = 'cover'; activeElement.style.objectPosition = '50% 50%'; activeElement.style.transform = 'scale(1)'; }
        else if (action === 'fit_contain') { activeElement.style.objectFit = 'contain'; activeElement.style.transform = 'scale(1)'; }
        else if (action === 'delete_banner') { activeElement.closest('.sec-col').classList.remove('filled'); activeElement.remove(); }
    }

    function deselectAll(e) {
        if (e && (e.target.closest('.resize-handle') || e.target.closest('#context-menu') || e.target.closest('.sec-tools') || e.target.closest('.header-bg-tools') || e.target.tagName === 'INPUT' || e.target.tagName === 'BUTTON')) return;
        if (e && !e.target.closest('.sec-col')) { document.querySelectorAll('.sec-col').forEach(c => c.classList.remove('active-col')); activeCol = null; }
        if (e && !e.target.closest('.float-item')) { document.querySelectorAll('.redimensionando').forEach(x => x.classList.remove('redimensionando')); }
    }

    function uploadImg(i) {
        if(i.files[0]) {
            const r = new FileReader();
            r.onload = (e) => { 
                const imgUrl = e.target.result;
                if (activeCol) {
                    const img = document.createElement('img'); img.src = imgUrl; img.className = 'banner-img'; img.setAttribute('ondragstart', 'return false;'); 
                    activeCol.innerHTML = ''; activeCol.appendChild(img); activeCol.classList.add('filled'); initBannerInteraction(img);
                } else {
                    createFloat(getTargetPage(), `<img src="${imgUrl}" style="width:100%;height:100%;object-fit:contain;">`, 50, 50, 'img', '150px');
                }
            };
            r.readAsDataURL(i.files[0]); i.value = '';
        }
    }

    function initBannerInteraction(img) {
        let scale = 1, panning = false, startX = 0, startY = 0;
        img.addEventListener('wheel', (e) => { e.preventDefault(); scale += e.deltaY * -0.001; if (scale < 0.5) scale = 0.5; if (scale > 3) scale = 3; img.style.transform = `scale(${scale})`; });
        img.addEventListener('mousedown', (e) => {
            if (e.button !== 0) return; e.preventDefault();
            const currentPos = getComputedStyle(img).objectPosition.split(' ');
            let posX = parseFloat(currentPos[0]) || 50; let posY = parseFloat(currentPos[1]) || 50;
            startX = e.clientX; startY = e.clientY; panning = true; img.style.cursor = 'grabbing';
            const onMouseMove = (ev) => {
                if (!panning) return;
                posX += (ev.clientX - startX) * 0.1; posY += (ev.clientY - startY) * 0.1;
                if(posX < 0) posX = 0; if(posX > 100) posX = 100; if(posY < 0) posY = 0; if(posY > 100) posY = 100;
                img.style.objectPosition = `${posX}% ${posY}%`; startX = ev.clientX; startY = ev.clientY;
            };
            const onMouseUp = () => { panning = false; img.style.cursor = 'grab'; document.removeEventListener('mousemove', onMouseMove); document.removeEventListener('mouseup', onMouseUp); };
            document.addEventListener('mousemove', onMouseMove); document.addEventListener('mouseup', onMouseUp);
        });
    }

    function initDrag(el) {
        let isDown = false, offX, offY;
        el.addEventListener('mousedown', (e) => {
            if (e.target.classList.contains('resize-handle')) return;
            if (e.button !== 0 || el.isContentEditable) return; 
            isDown = true; offX = e.clientX - el.offsetLeft * currentZoom; offY = e.clientY - el.offsetTop * currentZoom;
            const p = el.closest('.page'); if(p) setActivePage(p);
        });
        window.addEventListener('mouseup', () => isDown = false);
        window.addEventListener('mousemove', (e) => {
            if(!isDown) return; e.preventDefault();
            el.style.left = (e.clientX - offX) / currentZoom + 'px'; el.style.top = (e.clientY - offY) / currentZoom + 'px';
        });
        const h = document.createElement('div'); h.className = 'resize-handle'; el.appendChild(h);
        h.addEventListener('mousedown', (e) => {
            e.stopPropagation(); e.preventDefault();
            let startX = e.clientX, startW = el.offsetWidth;
            if (el.dataset.type === 'text' || el.dataset.type === 'sticker') {
                const inner = el.querySelector('.inner-content > div') || el.querySelector('.inner-content');
                if (inner && inner.style.fontSize) { el.style.fontSize = inner.style.fontSize; inner.style.fontSize = 'inherit'; }
            }
            let startFontSize = parseFloat(window.getComputedStyle(el).fontSize) || 16;
            const onMove = (ev) => {
                let diff = (ev.clientX - startX) / currentZoom;
                if(el.dataset.type === 'text' || el.dataset.type === 'sticker') { let newSize = startFontSize + (diff / 3); if(newSize < 10) newSize = 10; el.style.fontSize = newSize + 'px'; el.style.width = 'auto'; } 
                else { let newW = startW + diff; if(newW < 20) newW = 20; el.style.width = newW + 'px'; el.style.height = 'auto'; }
            };
            const onUp = () => { document.removeEventListener('mousemove', onMove); document.removeEventListener('mouseup', onUp); };
            document.addEventListener('mousemove', onMove); document.addEventListener('mouseup', onUp);
        });
    }

    // --- FUNCIONES DE CONSTRUCCION ---
    function addPreset(type) {
    const p = getTargetPage();
    const div = document.createElement('div'); div.className = 'section-row';
    div.style.height = (type === 'footer') ? '132px' : '110px';div.style.height = (type === 'footer') ? '132px' : '110px';
    if(type==='header') div.style.marginBottom = '5px'; else div.style.marginTop = '0';
    const bgColor = (type==='header'?'#ffc400':'#f1f1f1');
    const borderB = (type==='header'?'5px solid #d50000':'none');
    let footerBar = (type === 'footer') ? '<div style="position:absolute; top:0; left:0; width:100%; height:4px; background:#d50000; z-index:1;"></div>' : '';
    div.innerHTML = `${footerBar}<div class="sec-bg" style="background:${bgColor}; border-bottom:${borderB}; width:100%; height:100%; position:absolute; top:0; left:0; z-index:0;"></div><div class="sec-tools no-print"><button class="btn-sec" onclick="moveRow(this, -1)">▲</button><button class="btn-sec" onclick="moveRow(this, 1)">▼</button><button class="btn-sec del" onclick="this.closest('.section-row').remove()">X</button></div>`;
    div.style.position = 'relative';
    if(type === 'header') {
        createFloat(div, `<img src="${SMART.logo}" style="width:100%;height:100%;object-fit:contain;">`, 15, 5, 'img', '90px');
        createFloat(div, `<div style="font-size:32px; font-weight:900; color:#d50000;">${SMART.nombre}</div>`, 130, 5, 'text');
        createFloat(div, `<div style="font-size:16px; font-weight:bold; background:white; padding:2px 10px;">OFERTAS IMPERDIBLES</div>`, 130, 50, 'text');
    } else if(type === 'footer') {
        createFloat(div, `<img src="${SMART.logo}" style="width:100%;height:100%;object-fit:contain;">`, 20, 25, 'img', '80px');
        createFloat(div, `<div style="font-size:14px; font-weight:900; color:#333;">CONTACTANOS</div>`, 130, 35, 'text');
        createFloat(div, `<div style="font-weight:bold; color:#555; font-size:11px;"><i class="bi bi-geo-alt-fill text-danger"></i> ${SMART.direccion}</div>`, 130, 56, 'text');
        createFloat(div, `<div style="font-weight:bold; color:green; font-size:11px;"><i class="bi bi-whatsapp"></i> ${SMART.whatsapp}</div>`, 132, 75 , 'text');
        createFloat(div, `<div style="background:#d50000; color:white; font-weight:900; font-size:11px; padding:4px 8px; transform:rotate(-3deg);">¡ESCANEÁ Y GANÁ!</div>`, 530,54, 'sticker');
        createFloat(div, `<img src="${SMART.qr_img}" style="width:100%; height:100%;">`, 650, 24, 'qr', '80px');
    }
    initRowResize(div); p.appendChild(div);
}

    function addSection(type, cols) {
    if(type === 'grid') {
        const p = getTargetPage();
        const div = document.createElement('div'); div.className = 'section-row';
        // ALTURA FIJA PARA FILAS DE PRODUCTOS
        div.style.height = '220px'; 
        div.innerHTML = `
        <div class="sec-tools no-print">
            <button class="btn-sec" onclick="moveRow(this, -1)" title="Subir">▲</button>
            <button class="btn-sec" onclick="moveRow(this, 1)" title="Bajar">▼</button>
            <button class="btn-sec del" onclick="this.closest('.section-row').remove()" title="Borrar">X</button>
        </div>`;
        const grid = document.createElement('div'); grid.className = 'sec-grid';
        for(let i=0; i<cols; i++) {
            const col = document.createElement('div'); col.className = 'sec-col'; col.style.width = (100/cols)+'%';
            col.setAttribute('onclick', 'setActiveCol(this, event)'); 
            col.ondrop = (ev) => dropProd(ev, col); col.ondragover = (ev) => ev.preventDefault();
            grid.appendChild(col);
        }
        div.appendChild(grid); initRowResize(div); p.appendChild(div); div.scrollIntoView({behavior:'smooth'});
    }
}

    function createFloat(parent, html, x, y, type='text', width='auto') {
        const el = document.createElement('div'); el.className = 'float-item';
        el.style.left = x + 'px'; el.style.top = y + 'px'; el.style.width = width; el.dataset.type = type;
        const inner = document.createElement('div'); inner.className = 'inner-content'; inner.style.width = '100%'; inner.style.height = '100%';
        if (type === 'qr' || type === 'img') {
            if(html.startsWith('<img')) inner.innerHTML = html;
            else inner.innerHTML = `<img src="${html}" style="width:100%;height:100%;object-fit:contain;pointer-events:none;" onerror="this.onerror=null;this.src='https://cdn-icons-png.flaticon.com/512/3594/3594450.png'">`;
        } else inner.innerHTML = html;
        el.appendChild(inner); initDrag(el); parent.appendChild(el);
    }

    function dragStart(ev, type, val1, val2) {
        ev.dataTransfer.setData("type", type); ev.dataTransfer.setData("val1", val1); ev.dataTransfer.setData("val2", val2);
    }

    function dragProd(ev) {
        const t = ev.target.closest('.prod-source');
        ev.dataTransfer.setData("type", "prod");
        ev.dataTransfer.setData("data", JSON.stringify({
            name: t.dataset.nombre, price: t.dataset.precio, img: t.dataset.img, oferta: t.dataset.oferta 
        }));
    }

    function dropOnPage(ev) {
        ev.preventDefault();
        const type = ev.dataTransfer.getData("type");
        if(type==='prod') return; 
        
        let target = ev.target;
        if(target.classList.contains('page')) { setActivePage(target); const s = document.createElement('div'); s.className='section-row'; s.style.height='100px'; target.appendChild(s); target = s; }
        if(target.classList.contains('float-item') || target.classList.contains('inner-content') || target.tagName==='IMG') target = target.closest('.section-row') || target.parentElement;
        if(target.classList.contains('sec-bg')) target = target.parentElement;

        const rect = target.getBoundingClientRect();
        const x = (ev.clientX - rect.left) / currentZoom;
        const y = (ev.clientY - rect.top) / currentZoom;
        if(type === 'text') createFloat(target, `<div style="font-size:${ev.dataTransfer.getData('val2')||'16px'}; font-weight:bold;">${ev.dataTransfer.getData('val1')}</div>`, x, y, 'text');
        if(type === 'sticker') createFloat(target, `<div style="background:red; color:white; padding:5px; font-weight:900; transform:rotate(-5deg); text-align:center;">${ev.dataTransfer.getData('val1')}</div>`, x, y, 'sticker');
        if(type === 'smart_data') {
            const key = ev.dataTransfer.getData('val1');
            let html = '';
            if(key === 'direccion') html = `<div><i class="bi bi-geo-alt-fill text-danger"></i> ${SMART.direccion}</div>`;
            if(key === 'whatsapp') html = `<div><i class="bi bi-whatsapp text-success"></i> ${SMART.whatsapp}</div>`;
            if(key === 'web') html = `<div style="color:blue;">${SMART.url_tienda}</div>`;
            if(html) createFloat(target, `<div style="font-weight:bold; font-size:14px;">${html}</div>`, x, y, 'text');
        }
        if(type === 'smart_qr') createFloat(target, SMART.qr_img, x, y, 'qr', '100px');
        if(type === 'smart_logo') createFloat(target, SMART.logo, x, y, 'img', '100px');
    }

    function dropProd(ev, col) {
        ev.preventDefault(); ev.stopPropagation();
        if(ev.dataTransfer.getData("type") !== 'prod') return;
        const data = JSON.parse(ev.dataTransfer.getData("data"));
        let precio = parseFloat(data.price);
        if(isNaN(precio)) precio = 0;
        const precioF = precio.toLocaleString('es-AR');
        const displayOferta = (data.oferta == 1) ? 'block' : 'none';
        col.innerHTML = `
            <div class="card-prod">
                <div class="badge-off" style="display: ${displayOferta};">OFERTA</div>
                <img src="${data.img}" class="prod-img">
                <div class="prod-title">${data.name}</div>
                <div class="prod-price-box">$${precioF}</div>
                <button class="btn-del-prod" onclick="this.closest('.sec-col').innerHTML=''; this.closest('.sec-col').classList.remove('filled')">X</button>
            </div>`;
        col.classList.add('filled');
    }

    function addPage() {
        const div = document.createElement('div'); div.className='page-wrapper';
        div.innerHTML = `<div class="page-header-ui"><span>HOJA A4</span><button class="btn btn-sm btn-danger py-0" onclick="this.closest('.page-wrapper').remove()">Eliminar</button></div><div class="page" onmousedown="setActivePage(this)" ondrop="dropOnPage(event)" ondragover="event.preventDefault()"></div>`;
        document.getElementById('pages-container').appendChild(div);
        const newPage = div.querySelector('.page'); setActivePage(newPage); applyZoom();
    }
    
    function zoom(d) { currentZoom+=d; if(currentZoom<0.4) currentZoom=0.4; applyZoom(); }
    function applyZoom() { document.querySelectorAll('.page').forEach(p => p.style.transform = `scale(${currentZoom})`); document.querySelectorAll('.page-wrapper').forEach(w => w.style.height = (296 * currentZoom + 40) + 'mm'); }
    function filterProds(i) { document.querySelectorAll('.tool-item.prod-source').forEach(e => e.style.display = e.innerText.toLowerCase().includes(i.value.toLowerCase()) ? 'flex' : 'none'); }
    function setBg(inp) {
        if (inp.files[0]) {
            const r = new FileReader();
            r.onload = (e) => { const t = inp.closest('.section-row').querySelector('.sec-bg'); t.style.backgroundImage = `url(${e.target.result})`; t.style.backgroundSize='cover'; };
            r.readAsDataURL(inp.files[0]);
        }
    }
    function initRowResize(row) {
        const resizer = document.createElement('div'); resizer.className = 'row-resizer'; resizer.innerHTML='<div class="resizer-bar"></div>'; row.appendChild(resizer);
        resizer.addEventListener('mousedown', (e) => {
            e.preventDefault(); e.stopPropagation();
            let startY = e.clientY, startH = row.offsetHeight;
            row.style.minHeight = '0px'; 
            row.style.height = startH + 'px'; 
            const onMove = (ev) => {
                let newH = startH + (ev.clientY - startY)/currentZoom;
                if(newH < 10) newH = 10; 
                row.style.height = newH + 'px';
            };
            const onUp = () => { document.removeEventListener('mousemove', onMove); document.removeEventListener('mouseup', onUp); };
            document.addEventListener('mousemove', onMove); document.addEventListener('mouseup', onUp);
        });
    }
    
    function moveRow(btn, dir) { 
        const r = btn.closest('.section-row'); 
        if(dir===-1 && r.previousElementSibling) r.parentNode.insertBefore(r, r.previousElementSibling);
        if(dir===1 && r.nextElementSibling) r.parentNode.insertBefore(r.nextElementSibling, r);
    }
    
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#context-menu')) ctxMenu.style.display = 'none';
    });
    
    function descargarRevistaPDF() {
    window.scrollTo(0, 0);
    document.getElementById('canvas-scroll').scrollTo(0, 0);
    document.body.classList.add('pdf-clean');
    const element = document.getElementById('pages-container');
    const opt = {
        margin: 0,
        filename: 'Revista_Ofertas.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { 
            scale: 2, 
            useCORS: true,
            scrollY: 0,
            scrollX: 0,
            width: 794 // Ancho exacto A4 en píxeles
        },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    html2pdf().set(opt).from(element).save().then(() => {
        document.body.classList.remove('pdf-clean');
    });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>