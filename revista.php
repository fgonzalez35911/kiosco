<?php
// revista.php - VERSIÓN FINAL (FIX BOTÓN CELULAR + FOTOS CHICAS)
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'includes/db.php';

// 1. DATA
$conf_sis = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
try {
    $stmt_rev = $conexion->query("SELECT * FROM revista_config WHERE id=1");
    $conf_rev = $stmt_rev ? $stmt_rev->fetch(PDO::FETCH_ASSOC) : [];
} catch(Exception $e) { $conf_rev = []; }

// Ads
$paginas_especiales = [];
try {
    $stmt_esp = $conexion->query("SELECT * FROM revista_paginas WHERE activa=1 ORDER BY posicion ASC");
    if($stmt_esp) {
        $rows = $stmt_esp->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $r) { $paginas_especiales[] = $r; }
    }
} catch(Exception $e) {}

// 2. PRODUCTOS
$sql_prod = "SELECT p.*, c.nombre as categoria, cb.fecha_inicio, cb.fecha_fin, cb.es_ilimitado 
        FROM productos p 
        JOIN categorias c ON p.id_categoria = c.id 
        LEFT JOIN combos cb ON p.codigo_barras = cb.codigo_barras
        WHERE p.activo = 1 AND (p.stock_actual > 0 OR p.tipo = 'combo') 
        ORDER BY c.nombre ASC, p.descripcion ASC";
$raw_productos = $conexion->query($sql_prod)->fetchAll(PDO::FETCH_ASSOC);

$productos_por_cat = [];
foreach ($raw_productos as $p) {
    // Si el producto es tipo combo, lo mandamos a una categoría especial "🎁 OFERTAS"
    $cat_final = ($p['tipo'] == 'combo') ? '🎁 OFERTAS' : $p['categoria'];
    $productos_por_cat[$cat_final][] = $p;
}

// Mapa de Navegación Dinámico
$mapa_botones = [];
$contador_paginas = 1; // La portada es 0, el contenido arranca en 1
$cat_idx_temp = 0;
foreach($productos_por_cat as $cat => $items) {
    $mapa_botones[$cat] = $contador_paginas;
    $chunks = array_chunk($items, 4); 
    $contador_paginas += count($chunks);
    // Sumamos la página de publicidad solo si existe una para esta categoría
    if(isset($paginas_especiales[$cat_idx_temp])) {
        $contador_paginas++; 
    }
    $cat_idx_temp++;
}
// Guardamos la posición de la contratapa
$pagina_final = $contador_paginas;

// Visuales
$nombre_negocio = $conf_sis['nombre_negocio'] ?? 'Kiosco';
$logo_url = $conf_sis['logo_url'] ?? ''; 
$direccion = $conf_sis['direccion'] ?? '';
$telefono = $conf_sis['telefono'] ?? ''; 
// LÓGICA WHATSAPP: Si existe el especial de pedidos, usa ese. Si no, usa el general.
$telefono_wa = !empty($conf_sis['whatsapp_pedidos']) ? $conf_sis['whatsapp_pedidos'] : ($telefono ?? '');
// Variables Contratapa
$ct_img = $conf_rev['img_contratapa'] ?? '';
$ct_tit = $conf_rev['contratapa_titulo'] ?? '¡GRACIAS!';
$ct_txt = $conf_rev['contratapa_texto'] ?? '';
$ct_bg  = $conf_rev['contratapa_bg_color'] ?? '#222222';
$ct_col = $conf_rev['contratapa_texto_color'] ?? '#ffffff';
$ct_ov  = str_replace(',', '.', $conf_rev['contratapa_overlay'] ?? '0.5');
$ver_qr = $conf_rev['mostrar_qr'] ?? 1;

$img_tapa = $conf_rev['img_tapa'];
$tapa_ov = str_replace(',', '.', $conf_rev['tapa_overlay'] ?? '0.4');
$fuente = $conf_rev['fuente_global'] ?? 'Poppins';
$tapa_banner_bg = $conf_rev['tapa_banner_color'] ?? '#ffffff';
$tapa_banner_op = $conf_rev['tapa_banner_opacity'] ?? '0.9';

$tit_tapa = $conf_rev['titulo_tapa'] ?? 'CATÁLOGO';
$sub_tapa = $conf_rev['subtitulo_tapa'] ?? 'INTERACTIVO';
$tapa_tit_col = $conf_rev['tapa_tit_color'] ?? '#ffde00';
$tapa_sub_col = $conf_rev['tapa_sub_color'] ?? '#ffffff';

$colores_cat = ['#e60023', '#007bff', '#28a745', '#fd7e14', '#6610f2', '#6f42c1'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title>Revista - <?php echo $nombre_negocio; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Bebas+Neue&family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
    .swal2-container { z-index: 20001 !important; }
    
    
    <style>
        :root { --dark-bg: #1a1a1a; --header-h: 60px; --footer-h: 70px; }
        
        body { 
            margin: 0; padding: 0; background-color: var(--dark-bg); 
            height: 100vh; width: 100vw; overflow: hidden;
            display: flex; flex-direction: column; 
            font-family: '<?php echo $fuente; ?>', sans-serif; 
        }

        /* HEADER */
        .top-bar-container {
            height: var(--header-h); flex-shrink: 0;
            background: rgba(0,0,0,0.95); border-bottom: 1px solid #333;
            display: flex; align-items: center; padding: 0 15px; gap: 10px;
            overflow-x: auto; white-space: nowrap; scrollbar-width: none;
            padding-top: env(safe-area-inset-top); 
            z-index: 1000;
        }
        .nav-pill {
            color: white; border: 1px solid #555; padding: 5px 15px; border-radius: 20px;
            font-size: 0.8rem; cursor: pointer; text-transform: uppercase;
        }
        .nav-pill.active { background: white; color: black; border-color: white; }
        .nav-pill.salir { border-color: #dc3545; color: #dc3545; }

        /* AREA CENTRAL */
        .stage-center {
            flex-grow: 1; 
            display: flex; align-items: center; justify-content: center;
            position: relative; width: 100%; overflow: hidden;
            padding: 10px 0;
        }

        @media (max-width: 768px) {
            .stage-center {
                align-items: flex-start !important; 
                padding-top: 15px !important; 
            }
        }
        
        .book-wrapper { box-shadow: 0 15px 40px rgba(0,0,0,0.6); }

        /* DOCK (BOTONES) - MODIFICADO: VERTICAL A LA DERECHA */
        /* DOCK (BOTONES) - ESCRITORIO: VERTICAL A LA DERECHA */
        .dock-container {
            position: fixed; 
            bottom: 20px;
            right: 20px; 
            left: auto;
            transform: none; 
            z-index: 9100;
            display: flex; 
            flex-direction: column; /* Vertical por defecto */
            align-items: center; gap: 20px;
            background: rgba(20, 20, 20, 0.95); backdrop-filter: blur(10px);
            padding: 20px 12px;
            border-radius: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.7);
            border: 1px solid rgba(255,255,255,0.2);
            opacity: 0.3; 
            transition: opacity 0.3s;
        }
        .dock-container:hover { opacity: 1; }
        
        .dock-btn {
            background: none; border: none; color: white; font-size: 1.6rem;
            position: relative; transition: transform 0.2s; display: flex; align-items: center; justify-content: center;
        }
        .dock-btn:active { transform: scale(0.8); }
        
        /* Divisor por defecto (horizontal visualmente en columna vertical) */
        .dock-divider { width: 25px; height: 1px; background: rgba(255,255,255,0.3); margin: 5px 0; }
        
        /* MÓVIL: HORIZONTAL ABAJO */
        @media (max-width: 768px) {
            .dock-container {
                right: 50%; /* Centrado horizontal */
                transform: translateX(50%); /* Corregir centrado */
                bottom: 20px;
                left: auto;
                flex-direction: row; /* Horizontal */
                padding: 12px 35px; /* Padding estilo barra */
                border-radius: 50px;
                width: auto;
                opacity: 1; /* Siempre visible en móvil para que no se pierda */
            }
            /* El divisor se vuelve vertical */
            .dock-divider { width: 1px; height: 25px; margin: 0 5px; }
        }

        .cart-badge {
            position: absolute; top: -5px; right: -8px;
            background: #ffde00; color: black; font-size: 0.7rem; font-weight: 800;
            width: 20px; height: 20px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.5);
        }

        /* MODAL POR ENCIMA DE TODO (FIX Z-INDEX) */
        .modal { z-index: 10000 !important; }
        .modal-backdrop { z-index: 9999 !important; }
        .cart-badge {
            position: absolute; top: -5px; right: -8px;
            background: #ffde00; color: black; font-size: 0.7rem; font-weight: 800;
            width: 20px; height: 20px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.5);
        }

        /* PÁGINAS */
        .page { background: #fff !important; width: 100%; height: 100%; overflow: hidden; border: 1px solid #ccc; display: flex; flex-direction: column; }
        
        /* PORTADA */
        .page-cover { 
            background-color: #222 !important;
            /* Aquí imprimimos la ruta exacta de la base de datos + el time() para que se actualice si la cambias */
            background-image: linear-gradient(rgba(0,0,0,<?php echo $tapa_ov; ?>), rgba(0,0,0,<?php echo $tapa_ov; ?>)), url('<?php echo $img_tapa; ?>?v=<?php echo time(); ?>');
            background-position: center center !important;
            background-repeat: no-repeat !important;
            display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;
        }
        .logo-box { 
            background: <?php echo $tapa_banner_bg; ?>; 
            opacity: <?php echo $tapa_banner_op; ?>; 
            padding: 20px; border-radius: 15px; margin-bottom: 20px; 
            box-shadow: 0 5px 20px rgba(0,0,0,0.5);
        }
        .cover-title { 
            font-family: 'Bebas Neue'; font-size: 4rem; line-height: 0.9; 
            color: <?php echo $tapa_tit_col; ?>; 
            text-shadow: 3px 3px 0 rgba(0,0,0,0.8);
        }
        .cover-sub {
            font-size: 1.2rem; margin-top: 10px; font-weight: bold; 
            color: <?php echo $tapa_sub_col; ?>; 
            text-transform: uppercase; text-shadow: 1px 1px 2px rgba(0,0,0,0.8);
        }

        /* PRODUCTOS */
        .cat-header { height: 40px; line-height: 40px; text-align: center; background: #222; color: white; font-family: 'Anton'; font-size: 1.2rem; flex-shrink: 0; }

        /* GRILLA CORREGIDA PARA QUE NO SE ACHIQUEN FOTOS */
        .products-grid {
            flex-grow: 1; display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr; /* USAMOS 1fr PARA QUE SE REPARTA IGUAL */
            gap: 2px; padding: 2px;
        }

        .prod-card { 
            border: 1px solid #eee; display: flex; flex-direction: column; 
            position: relative; height: 100% !important; /* FORZAR ALTURA */
            overflow: hidden; background: white; 
        }
        
        .prod-img-box { 
            flex: 1; /* Ocupa todo el espacio vertical disponible */
            display: flex; align-items: center; justify-content: center; 
            padding: 10px; overflow: hidden; position: relative; 
        }
        .prod-img { 
            width: 100%; height: 100%; 
            object-fit: contain; /* Asegura que la foto se vea entera */
        }

        .prod-info-box { 
            height: 65px; background: #f9f9f9; border-top: 1px solid #eee; 
            padding: 4px; text-align: center; display: flex; flex-direction: column; justify-content: center; flex-shrink: 0; 
        }
        .prod-title { font-size: 0.75rem; font-weight: bold; color: #444; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.2; }
        .prod-price { font-family: 'Bebas Neue'; font-size: 1.5rem; color: #d63031; line-height: 1; }

        /* BOTÓN AGREGAR (FIX CLIC MÓVIL) */
        /* BOTÓN AGREGAR MEJORADO (MÁS GRANDE Y ENCIMA DE TODO) */
        .btn-plus {
            position: absolute; top: 10px; right: 10px;
            width: 45px; height: 45px; /* Agrandamos de 35 a 45 para mejor puntería */
            border-radius: 50%;
            background: #28a745; color: white; border: none;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3); 
            z-index: 2000; /* Z-Index alto para que nada lo tape */
            cursor: pointer; font-size: 1.4rem;
        }
        .btn-plus:active { transform: scale(0.9); background: #218838; }

        /* SCROLL CARRITO ESCRITORIO */
        #cartList {
            max-height: 50vh; /* Bajamos un poco a 50vh para asegurar que entre en notebooks */
            overflow-y: auto !important; /* Forzamos la barra sí o sí */
            padding-right: 5px;
            width: 100%; /* Asegura que ocupe el ancho */
            display: block; /* Asegura comportamiento de bloque */
        }
        /* Estilo del scroll */
        #cartList::-webkit-scrollbar { width: 8px; }
        #cartList::-webkit-scrollbar-thumb { background: #999; border-radius: 4px; }
        #cartList::-webkit-scrollbar-track { background: #f1f1f1; }

        /* FLECHAS PC */
        .nav-arrow { position: absolute; top: 50%; transform: translateY(-50%); font-size: 2rem; color: white; cursor: pointer; z-index: 100; background: rgba(0,0,0,0.3); width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
        .nav-prev { left: 30px; } .nav-next { right: 30px; }
        @media (max-width: 768px) { .nav-arrow { display: none; } }
        
        /* FIX: CARRITO CON FONDO BLANCO SÓLIDO */
        .modal-content { 
            background-color: #ffffff !important; 
            color: #333 !important;
            border-radius: 15px;
            opacity: 1 !important; /* Asegura que no sea transparente */
            box-shadow: 0 20px 50px rgba(0,0,0,0.8); /* Sombra fuerte para resaltar */
            border: none;
        }
        .cart-item { padding: 10px 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .swal2-container { z-index: 100000 !important; }

        /* --- FIX TOTAL CARRITO (ESTILO TIENDA) --- */
    #modalC { z-index: 10000 !important; } /* Encima de todo */
    
    #modalC .modal-content {
        background-color: #ffffff !important;
        color: #333 !important;
        border-radius: 15px !important;
        border: none !important;
        text-align: left !important; /* Arregla textos centrados por la revista */
        box-shadow: 0 10px 40px rgba(0,0,0,0.5) !important;
    }
    
    #modalC .modal-header, 
    #modalC .modal-body, 
    #modalC .modal-footer {
        background: transparent !important;
        border-color: #eee !important;
        padding: 15px 20px !important;
        display: block !important; /* Resetea el flex extraño de la revista */
    }

    #modalC .modal-header { display: flex !important; justify-content: space-between; align-items: center; }
    
    /* Arreglo del scroll de productos */
    #cartList {
        max-height: 40vh;
        overflow-y: auto !important;
        display: block !important;
        margin-bottom: 10px;
    }

    /* Arreglo de botones del footer (Vaciar y Enviar) */
    #modalC .modal-footer .botones-container {
        display: flex !important;
        flex-direction: row !important; /* Los pone uno al lado del otro */
        gap: 10px !important;
        width: 100% !important;
        margin-top: 10px;
    }
    
    /* Inputs del formulario */
    #modalC input.form-control {
        background: #fff !important;
        color: #333 !important;
        border: 1px solid #ccc !important;
    }
    </style>
</head>
<body>

    <nav class="top-bar-container">
        <div class="nav-pill salir" onclick="window.location.href='tienda.php'">Salir</div>
        <div class="nav-pill" onclick="pFlip.flip(0)">🏠 Portada</div>
        <?php foreach($mapa_botones as $cat => $pag): ?>
            <div class="nav-pill" onclick="pFlip.flip(<?php echo $pag; ?>)"><?php echo $cat; ?></div>
        <?php endforeach; ?>
        <div class="nav-pill" onclick="pFlip.flip(<?php echo $pagina_final; ?>)">📖 Final</div>
    </nav>

    <div class="stage-center">
        <div class="nav-arrow nav-prev" id="btnP"><i class="bi bi-chevron-left"></i></div>
        <div class="nav-arrow nav-next" id="btnN"><i class="bi bi-chevron-right"></i></div>

        <div id="flipbook" class="book-wrapper">
            
            <div class="page page-cover" data-density="hard" style="position: relative; background: #222; overflow: hidden;">
                
                <img src="<?php echo $img_tapa; ?>" 
                     style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; z-index: 0; border: none;">
                
                <div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,<?php echo str_replace(',','.',$tapa_ov); ?>); z-index: 1;"></div>

                <div style="position: relative; z-index: 2; width: 100%; display: flex; flex-direction: column; align-items: center;">
                    <div class="logo-box">
                        <?php if(!empty($logo_url)): ?>
                            <img src="<?php echo $logo_url; ?>" style="max-height:80px;">
                        <?php else: ?>
                            <i class="bi bi-shop" style="font-size:3rem;"></i>
                        <?php endif; ?>
                    </div>
                    <div class="cover-title"><?php echo $tit_tapa; ?></div>
                    <div class="cover-sub"><?php echo $sub_tapa; ?></div>
                </div>
            </div>

            

            <?php 
            $cat_idx = 0;
            foreach($productos_por_cat as $cat_nombre => $items): 
                $color_actual = $colores_cat[$cat_idx % count($colores_cat)];
                $cat_idx++;
                $chunks = array_chunk($items, 4); 
                foreach($chunks as $chunk):
            ?>
                <div class="page">
                    <div class="cat-header" style="background: <?php echo $color_actual; ?>;"><?php echo $cat_nombre; ?></div>
                    <div class="products-grid">
                        <?php foreach($chunk as $p): 
    // Detectamos si hay oferta
    $tiene_oferta = (!empty($p['precio_oferta']) && $p['precio_oferta'] > 0);
    $precio_final = $tiene_oferta ? $p['precio_oferta'] : $p['precio_venta'];
?>
    <div class="prod-card">
        <div style="position:absolute; top:10px; left:10px; z-index:10;">
            <?php if($p['tipo'] === 'combo'): ?>
                <?php if($p['es_ilimitado'] == 1): ?>
                    <div style="background:#ffc107; color:black; font-size:0.65rem; padding:2px 6px; border-radius:4px; font-weight:bold; box-shadow:0 2px 4px rgba(0,0,0,0.2);">🔥 ÚLTIMA OFERTA</div>
                <?php else: ?>
                    <div style="background:#0dcaf0; color:black; font-size:0.6rem; padding:2px 6px; border-radius:4px; font-weight:bold; box-shadow:0 2px 4px rgba(0,0,0,0.2);">
                        ⏳ <?php echo date('d/m', strtotime($p['fecha_inicio'])); ?> - <?php echo date('d/m', strtotime($p['fecha_fin'])); ?>
                    </div>
                <?php endif; ?>
            <?php elseif($tiene_oferta): ?>
                <div style="background:#dc3545; color:white; font-size:0.7rem; padding:2px 6px; border-radius:4px; font-weight:bold;">OFERTA</div>
            <?php endif; ?>
        </div>

        <button class="btn-plus" 
    ontouchstart="event.stopPropagation(); addCart(<?php echo $p['id']; ?>, '<?php echo addslashes($p['descripcion']); ?>', <?php echo $precio_final; ?>, <?php echo $p['stock_actual']; ?>); return false;"
    onmousedown="event.stopPropagation();"
    onclick="event.stopPropagation(); addCart(<?php echo $p['id']; ?>, '<?php echo addslashes($p['descripcion']); ?>', <?php echo $precio_final; ?>, <?php echo $p['stock_actual']; ?>);">

            <i class="bi bi-plus-lg"></i>
        </button>
        
        <div class="prod-img-box">
            <img src="<?php echo $p['imagen_url'] ?: 'img/no-image.png'; ?>" class="prod-img">
        </div>
        
        <div class="prod-info-box">
            <div class="prod-title"><?php echo $p['descripcion']; ?></div>
            
            <?php if($tiene_oferta): ?>
                <div style="line-height:1;">
                    <s style="color:#999; font-size:0.8rem;">$<?php echo number_format($p['precio_venta'], 0); ?></s>
                    <div class="prod-price" style="color:#dc3545;">$<?php echo number_format($p['precio_oferta'], 0); ?></div>
                </div>
            <?php else: ?>
                <div class="prod-price">$<?php echo number_format($p['precio_venta'], 0); ?></div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
                        <?php for($k=count($chunk); $k<4; $k++): ?>
                            <div class="prod-card" style="visibility:hidden; border:none; height:100%;"></div>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endforeach; if(isset($paginas_especiales[$cat_idx-1])): $ad = $paginas_especiales[$cat_idx-1]; ?>
                <div class="page" style="background:black; justify-content:center; align-items:center;">
                    <img src="<?php echo $ad['imagen_url']; ?>" style="width:100%; height:100%; object-fit:cover;">
                </div>
            <?php endif; endforeach; ?>

            <div class="page page-cover" data-density="hard" style="background-color: <?php echo $ct_bg; ?> !important; position: relative;">
                
                <?php if(!empty($ct_img)): ?>
                    <img src="<?php echo $ct_img; ?>?v=<?php echo time(); ?>" 
                         style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; z-index: 0; border: none;">
                    <div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,<?php echo $ct_ov; ?>); z-index: 1;"></div>
                <?php endif; ?>

                <div style="position: relative; z-index: 2; width: 100%; height:100%; display: flex; flex-direction: column; align-items: center; justify-content:center; padding: 20px; text-align: center;">
                    
                    <h2 style="color:<?php echo $ct_col; ?>; font-family:'Anton'; font-size: 3.5rem; margin-bottom: 10px; text-shadow: 2px 2px 4px rgba(0,0,0,0.8);">
                        <?php echo $ct_tit; ?>
                    </h2>
                    
                    <?php if(!empty($ct_txt)): ?>
                        <p style="color:<?php echo $ct_col; ?>; font-size: 1.2rem; margin-bottom: 30px; font-weight: 300; max-width: 80%;">
                            <?php echo nl2br($ct_txt); ?>
                        </p>
                    <?php endif; ?>

                    <?php if($ver_qr == 1): ?>
                        <div style="background:white; padding:15px; border-radius:15px; box-shadow: 0 10px 25px rgba(0,0,0,0.5);">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode("https://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']); ?>" width="140">
                        </div>
                        <div style="color:<?php echo $ct_col; ?>; margin-top: 10px; font-size: 0.9rem; opacity: 0.8;">Escaneá para llevar</div>
                    <?php endif; ?>

                    <div style="color:<?php echo $ct_col; ?>; margin-top: 40px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; font-size:0.9rem;">
                        <?php echo $nombre_negocio; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="dock-container">
        <button class="dock-btn" onclick="share()"><i class="bi bi-share"></i></button>
        <div class="dock-divider"></div>
        <button class="dock-btn" onclick="openCart()">
            <i class="bi bi-cart4"></i>
            <span class="cart-badge" id="badgeCount">0</span>
        </button>
    </div>

    <div class="modal fade" id="modalC" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                
                <div class="modal-header border-bottom-0">
                    <h5 class="modal-title fw-bold text-dark">Tu Pedido</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body pt-0">
                    <div id="cartList"></div>
                    
                    <div class="d-flex justify-content-between fw-bold fs-5 mb-3 border-top pt-3">
                        <span class="text-dark">Total:</span>
                        <span class="text-danger" id="cartTotal">$0</span>
                    </div>

                    <div class="bg-light p-3 rounded-3 mb-2" style="text-align: left; border: 1px solid #eee;">
                        <h6 class="fw-bold mb-2 small text-dark"><i class="bi bi-person-vcard"></i> Datos para el envío</h6>
                        
                        <div class="mb-2">
                            <label class="form-label small fw-bold text-danger mb-0">* Nombre</label>
                            <input type="text" id="cli_nombre" class="form-control form-control-sm" placeholder="Tu nombre...">
                        </div>
                        
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small mb-0 text-muted">Teléfono</label>
                                <input type="tel" id="cli_tel" class="form-control form-control-sm" placeholder="Opcional...">
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-0 text-muted">Email</label>
                                <input type="email" id="cli_email" class="form-control form-control-sm" placeholder="Opcional...">
                            </div>
                        </div>
                        <div class="mt-2">
                            <label class="form-label small mb-0 text-muted">Dirección</label>
                            <input type="text" id="cli_dir" class="form-control form-control-sm" placeholder="Calle, Altura, Localidad...">
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-top-0 pt-0">
                    <div class="botones-container">
                        <button class="btn btn-outline-danger w-50 rounded-pill" onclick="clearCart()">Vaciar</button>

                        <button class="btn btn-success w-50 fw-bold rounded-pill" onclick="sendWA()">
                            <i class="bi bi-whatsapp"></i> ENVIAR
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/page-flip/dist/js/page-flip.browser.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        let pFlip;
        let cart = [];
        let modalCart;

        function initBook() {
            const el = document.getElementById('flipbook');
            const wrapper = document.querySelector('.book-wrapper');
            const centerStage = document.querySelector('.stage-center');
            
            const availableH = centerStage.clientHeight - 20; 
            const availableW = centerStage.clientWidth - 20;

            let pageH = availableH;
            let pageW = (pageH * 0.7); 

            if (pageW > availableW) {
                pageW = availableW;
                pageH = pageW / 0.7;
            }

            // ESCRITORIO
            if(window.innerWidth > 768) {
                pageW = 450; 
                pageH = 650;
            }

            pFlip = new St.PageFlip(el, { 
                width: pageW, 
                height: pageH,
                size: 'fixed',
                minWidth: 300, maxWidth: 1000, 
                minHeight: 400, maxHeight: 1200,
                showCover: true, mobileScrollSupport: false 
            });

            pFlip.loadFromHTML(document.querySelectorAll('.page'));
            
            // ESTO ES LO QUE EVITA EL SALTO VERTICAL Y CENTRA EL FINAL
            wrapper.style.transition = 'transform 0.5s cubic-bezier(0.25, 0.8, 0.25, 1)';

            const updatePos = (idx) => {
                if(window.innerWidth > 768) {
                    const totalPages = pFlip.getPageCount();
                    
                    // CASO 1: PORTADA (Mover a la izquierda para centrar)
                    if (idx === 0) {
                        wrapper.style.transform = `translateX(-${pageW/2}px)`;
                    }
                    // CASO 2: FINAL / CONTRATAPA (Mover a la derecha para centrar)
                    else if (idx >= totalPages - 1) {
                        wrapper.style.transform = `translateX(${pageW/2}px)`;
                    }
                    // CASO 3: INTERIOR (Libro abierto centrado)
                    else {
                        wrapper.style.transform = 'translateX(0)';
                    }
                }
            };

            pFlip.on('flip', (e) => updatePos(e.data));
            
            // Ejecutamos con un mínimo delay para asegurar que no salte al cargar
            setTimeout(() => updatePos(0), 50);
        }

        // CARRO
        function initCart() {
            modalCart = new bootstrap.Modal(document.getElementById('modalC'));
            try {
                let r = localStorage.getItem('carrito_kiosco');
                if(r) {
                    cart = JSON.parse(r);
                    cart = cart.filter(i => i && !isNaN(parseFloat(i.precio)) && !isNaN(parseInt(i.cant)));
                }
            } catch(e) { cart=[]; localStorage.removeItem('carrito_kiosco'); }
            updBadge();
        }

        function addCart(id, n, p, s) {
    let ex = cart.find(i => i.id == id);
    if(ex) {
        if(ex.cant + 1 > s) {
            const Toast = Swal.mixin({toast: true, position: 'top', showConfirmButton: false, timer: 2000, background: '#dc3545', color: '#fff'});
            Toast.fire({icon: 'error', title: 'Sin stock suficiente ('+s+' disp.)'});
            return;
        }
        ex.cant++;
    } else { 
        if(s <= 0) return;
        cart.push({id, nombre:n, precio:p, cant:1, stock_max:s}); 
    }
    save(); updBadge();
    const Toast = Swal.mixin({toast: true, position: 'top', showConfirmButton: false, timer: 1000, background: '#28a745', color: '#fff'});
    Toast.fire({icon: 'success', title: '¡Agregado!'});
}


        function openCart() {
            let html = '', tot=0;
            if(cart.length==0) html='<p class="text-center text-muted">Vacío</p>';
            else {
                cart.forEach((i,x) => {
                    let s = i.precio*i.cant; tot+=s;
                    html += `<div class="cart-item">
                        <div style="flex:1"><div class="fw-bold small">${i.nombre}</div><div class="small text-muted">$${i.precio}</div></div>
                        <div class="d-flex gap-2 align-items-center">
                            <button class="btn btn-sm btn-light border" onclick="mod(${x},-1)">-</button>
                            <span class="fw-bold small">${i.cant}</span>
                            <button class="btn btn-sm btn-light border" onclick="mod(${x},1)">+</button>
                        </div>
                        <div class="fw-bold ms-3">$${s}</div>
                    </div>`;
                });
            }
            document.getElementById('cartList').innerHTML = html;
            document.getElementById('cartTotal').innerText = '$'+tot;
            modalCart.show();
        }

        function mod(x, v) {
    let item = cart[x];
    if(v > 0 && item.cant + v > item.stock_max) {
        const Toast = Swal.mixin({toast: true, position: 'top', showConfirmButton: false, timer: 2000, background: '#dc3545', color: '#fff'});
        Toast.fire({icon: 'error', title: 'Límite de stock: ' + item.stock_max});
        return;
    }
    item.cant += v;
    if(item.cant <= 0) cart.splice(x, 1);
    save(); updBadge(); openCart();
}
        function clearCart() {
    Swal.fire({
        title: '¿Vaciar pedido?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e60023',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'SÍ, VACIAR',
        cancelButtonText: 'VOLVER'
    }).then((result) => {
        if (result.isConfirmed) {
            cart = [];
            save();
            updBadge();
            // Actualizar modal si está abierto
            if(document.getElementById('cartList')) {
                document.getElementById('cartList').innerHTML = '<p class="text-center text-muted py-3">Tu carrito está vacío</p>';
                document.getElementById('cartTotal').innerText = '$0';
            }
            Swal.fire({ toast: true, position: 'top', icon: 'success', title: 'Carrito vacío', showConfirmButton: false, timer: 1500 });
        }
    });
}

        function save() { localStorage.setItem('carrito_kiosco', JSON.stringify(cart)); }
        function updBadge() { document.getElementById('badgeCount').innerText = cart.reduce((s,i)=>s+i.cant,0); }
        
        function sendWA() {
            if(cart.length == 0) return Swal.fire('Carrito Vacío', 'Agrega productos antes de enviar.', 'warning');
            
            // 1. VALIDAR NOMBRE (Obligatorio)
            let cli_nom = document.getElementById('cli_nombre').value.trim();
            if(cli_nom === '') {
                return Swal.fire({icon: 'warning', title: 'Falta tu nombre', text: 'Por favor escribí tu nombre para saber quién sos.', confirmButtonColor: '#28a745'});
            }

            // 2. OBTENER DATOS OPCIONALES
            let cli_tel = document.getElementById('cli_tel').value.trim();
            let cli_email = document.getElementById('cli_email').value.trim();
            let cli_dir = document.getElementById('cli_dir').value.trim();
            
            // 3. ARMAR MENSAJE DETALLADO
            let msg = `Hola *<?php echo $nombre_negocio; ?>*! 👋%0A`;
            msg += `Quiero realizar el siguiente pedido desde la *Revista Digital*:%0A%0A`;
            
            let tot = 0;
            cart.forEach(i => { 
                let sub = i.precio * i.cant;
                tot += sub;
                // Emojis y negritas para cada ítem
                msg += `🔹 *${i.cant}x* ${i.nombre} ($${sub})%0A`; 
            });
            
            msg += `%0A💰 *TOTAL: $${tot}*%0A`;
            msg += `--------------------------------%0A`;
            msg += `👤 *Cliente:* ${cli_nom}%0A`;
            
            if(cli_tel) msg += `📱 *Tel:* ${cli_tel}%0A`;
            if(cli_email) msg += `📧 *Email:* ${cli_email}%0A`;
            if(cli_dir) msg += `📍 *Dirección:* ${cli_dir}%0A`;
            
            msg += `--------------------------------%0A`;
            msg += `Espero confirmación. ¡Gracias!`;

            // 4. USAR EL NÚMERO CONFIGURADO EN EL PASO 1
            let telefonoDestino = "<?php echo $telefono_wa; ?>";
            
            window.open(`https://wa.me/${telefonoDestino}?text=${msg}`, '_blank');
        }
        function share() {
            if(navigator.share) navigator.share({url:window.location.href});
            else { navigator.clipboard.writeText(window.location.href); Swal.fire('Link copiado'); }
        }

        document.addEventListener('DOMContentLoaded', () => {
            initBook(); initCart();
            document.getElementById('btnP').addEventListener('click',()=>pFlip.flipPrev());
            document.getElementById('btnN').addEventListener('click',()=>pFlip.flipNext());
            // FIX: Evitar recarga cuando se esconde la barra del navegador en el celular
        let anchoPrevio = window.innerWidth;
        
        window.addEventListener('resize', () => {
            // Solo recargamos si cambió el ANCHO (rotación de pantalla), no el ALTO (scroll)
            if (window.innerWidth !== anchoPrevio) {
                anchoPrevio = window.innerWidth;
                setTimeout(() => { location.reload() }, 500);
            }
        });
        });
    </script>
</body>
</html>