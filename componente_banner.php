<?php
// includes/componente_banner.php
// Versión: Lluvia de Iconos Refinada (4 en móvil / 50 en PC - Ultra Tenue)

$iconosVentas = [
    'bi-cart4', 'bi-bag-check', 'bi-tag', 'bi-cash-stack', 'bi-credit-card', 
    'bi-box-seam', 'bi-truck', 'bi-receipt', 'bi-shop', 'bi-wallet2', 
    'bi-percent', 'bi-basket', 'bi-barcode', 'bi-coin', 'bi-piggy-bank',
    'bi-calculator', 'bi-cart-check', 'bi-currency-dollar', 'bi-envelope-paper',
    'bi-gift', 'bi-house-heart', 'bi-lightning-charge', 'bi-patch-check', 'bi-qr-code-scan'
];
?>
<style>
    /* BANNER PRINCIPAL */
    .header-blue { 
        width: 100vw; 
        margin-left: calc(-50vw + 50%); 
        background: <?php echo $color_sistema ?? '#102A57'; ?> !important; 
        padding: 35px 0; 
        position: relative; 
        overflow: hidden; 
        z-index: 10; 
        border-radius: 0 !important; 
    }
    
    /* CAPA DE ICONOS FLOTANTES */
    .icon-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
        z-index: 0;
    }

    .floating-icon {
        position: absolute;
        color: rgba(255, 255, 255, 0.04); /* ULTRA TENUE (4% opacidad) */
        animation: floatAnim var(--duration) infinite ease-in-out alternate;
        z-index: 0;
    }

    /* OCULTAR ICONOS EN MÓVIL POR DEFECTO */
    @media (max-width: 768px) {
        .floating-icon { display: none; }
        .icon-visible-mobile { display: block !important; }
    }

    @keyframes floatAnim {
        0% { transform: translate(0, 0) rotate(0deg); }
        100% { transform: translate(var(--move-x), var(--move-y)) rotate(15deg); }
    }

    /* ICONO GRANDE DE FONDO SECCIÓN */
    .bg-icon-large { 
        position: absolute; 
        right: -15px; 
        top: 50%; 
        transform: translateY(-50%) rotate(-10deg); 
        font-size: 14rem; 
        color: rgba(255,255,255,0.12); 
        pointer-events: none; 
        z-index: 1; 
    }

    /* WIDGETS Y RESPONSIVE */
    @media (max-width: 768px) {
        .row-widgets { display: flex !important; overflow-x: auto; flex-wrap: nowrap; gap: 8px; padding: 0 15px 10px 15px; margin-left: -15px; margin-right: -15px; -webkit-overflow-scrolling: touch; }
        .row-widgets .col-banner { min-width: 155px; flex: 0 0 auto; }
        .header-widget { padding: 8px !important; margin-bottom: 0 !important; border-radius: 10px !important; }
        .widget-label { font-size: 0.55rem !important; margin-bottom: 2px !important; }
        .widget-value { font-size: 0.85rem !important; }
        .icon-box { width: 28px !important; height: 28px !important; font-size: 0.8rem !important; }
        .scroll-arrow { display: block !important; text-align: center; color: white; margin-top: 5px; font-size: 0.75rem; }
    }
    
    .scroll-arrow { display: none; }
    @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.2; } }
    .blink { animation: blink 1.2s infinite; }
</style>

<div class="header-blue">
    <div class="icon-overlay">
        <?php for($i=0; $i<50; $i++): 
            $icon = $iconosVentas[array_rand($iconosVentas)];
            $top = rand(0, 90);
            $left = rand(0, 90);
            $size = rand(15, 30) / 10;
            $dur = rand(10, 20);
            $moveX = rand(-25, 25) . "px";
            $moveY = rand(-25, 25) . "px";
            
            // Los primeros 4 iconos del bucle serán los únicos visibles en móvil
            $claseMovil = ($i < 4) ? 'icon-visible-mobile' : '';
        ?>
            <i class="bi <?php echo $icon; ?> floating-icon <?php echo $claseMovil; ?>" 
               style="top: <?php echo $top; ?>%; 
                      left: <?php echo $left; ?>%; 
                      font-size: <?php echo $size; ?>rem; 
                      --duration: <?php echo $dur; ?>s; 
                      --move-x: <?php echo $moveX; ?>; 
                      --move-y: <?php echo $moveY; ?>;">
            </i>
        <?php endfor; ?>
    </div>

    <i class="bi <?php echo $icono_bg; ?> bg-icon-large"></i>
    
    <div class="container position-relative" style="z-index: 5;">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-3 text-center text-md-start">
            <div class="mb-3 mb-md-0">
                <h2 class="font-cancha mb-0 text-white"><?php echo $titulo; ?></h2>
                <p class="opacity-75 mb-0 text-white small d-none d-md-block"><?php echo $subtitulo; ?></p>
            </div>
            
            <div class="d-flex gap-2 justify-content-center">
                <?php if(!empty($botones)): foreach($botones as $btn): ?>
                    <a href="<?php echo $btn['link']; ?>" 
                       target="<?php echo $btn['target'] ?? '_self'; ?>" 
                       class="<?php echo $btn['class']; ?>" 
                       style="font-size: 0.75rem; padding: 3px 15px; letter-spacing: 0.5px; min-width: 90px; display: inline-flex; align-items: center; justify-content: center;">
                        <?php if(!empty($btn['icono'])): ?>
                            <i class="bi <?php echo $btn['icono']; ?> me-2" style="font-size: 0.85rem;"></i>
                        <?php endif; ?>
                        <?php echo $btn['texto']; ?>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="row row-widgets g-2">
            <?php if(!empty($widgets)): foreach($widgets as $w): ?>
                <div class="col-12 col-md-4 col-banner">
                    <div class="header-widget <?php echo $w['border'] ?? ''; ?>">
                        <div>
                            <div class="widget-label"><?php echo $w['label']; ?></div>
                            <div class="widget-value text-white"><?php echo $w['valor']; ?></div>
                        </div>
                        <div class="icon-box <?php echo $w['icon_bg'] ?? 'bg-white bg-opacity-10'; ?> text-white">
                            <i class="bi <?php echo $w['icono']; ?>"></i>
                        </div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="scroll-arrow">
            DESLIZA <i class="bi bi-chevron-right blink"></i>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const wrapperFiltros = document.getElementById('wrapperFiltros');
    const btnToggleFiltros = document.querySelector('.btn-toggle-filters');
    if (!wrapperFiltros || document.getElementById('btnGridDesk')) return; 
    
    const pageName = window.location.pathname.split("/").pop().split("?")[0];
    const userId = <?php echo isset($_SESSION['usuario_id']) ? $_SESSION['usuario_id'] : '0'; ?>;
    const storageKey = `vista_lista_${userId}_${pageName}`;
    const prefGuardada = localStorage.getItem(storageKey);
    const iniciarEnLista = (prefGuardada === 'true');

    const switchDesktop = `
        <div class="d-none d-md-flex align-items-center bg-white border rounded-pill p-1 shadow-sm ms-auto" style="min-width: max-content; height: 38px;">
            <div class="btn-group" role="group">
                <input type="radio" class="btn-check btn-check-grid" name="btnradioVistaDesk" id="btnGridDesk" autocomplete="off" ${!iniciarEnLista ? 'checked' : ''}>
                <label class="btn btn-outline-primary border-0 rounded-pill btn-sm px-3 fw-bold m-0 d-flex align-items-center" for="btnGridDesk"><i class="bi bi-grid-fill me-1"></i> Tarjetas</label>
                <input type="radio" class="btn-check btn-check-list" name="btnradioVistaDesk" id="btnListDesk" autocomplete="off" ${iniciarEnLista ? 'checked' : ''}>
                <label class="btn btn-outline-primary border-0 rounded-pill btn-sm px-3 fw-bold m-0 d-flex align-items-center" for="btnListDesk"><i class="bi bi-list-ul me-1"></i> Lista</label>
            </div>
        </div>
    `;
    wrapperFiltros.insertAdjacentHTML('beforeend', switchDesktop);
    
    if (btnToggleFiltros && btnToggleFiltros.parentElement) {
        const switchMobile = `
            <div class="d-flex d-md-none align-items-center bg-white border rounded-pill p-1 shadow-sm ms-2" style="flex-shrink: 0; height: 38px;">
                <div class="btn-group" role="group">
                    <input type="radio" class="btn-check btn-check-grid" name="btnradioVistaMob" id="btnGridMob" autocomplete="off" ${!iniciarEnLista ? 'checked' : ''}>
                    <label class="btn btn-outline-primary border-0 rounded-pill btn-sm px-3 fw-bold m-0 d-flex align-items-center" for="btnGridMob"><i class="bi bi-grid-fill"></i></label>
                    <input type="radio" class="btn-check btn-check-list" name="btnradioVistaMob" id="btnListMob" autocomplete="off" ${iniciarEnLista ? 'checked' : ''}>
                    <label class="btn btn-outline-primary border-0 rounded-pill btn-sm px-3 fw-bold m-0 d-flex align-items-center" for="btnListMob"><i class="bi bi-list-ul"></i></label>
                </div>
            </div>
        `;
        btnToggleFiltros.parentElement.insertAdjacentHTML('beforeend', switchMobile);
    }

    function actualizarVista(esLista) {
        localStorage.setItem(storageKey, esLista);
        document.querySelectorAll('.btn-check-list').forEach(b => b.checked = esLista);
        document.querySelectorAll('.btn-check-grid').forEach(b => b.checked = !esLista);
        const gridBox = document.getElementById('gridProductos') || document.querySelector('.row.g-4:not(.no-grid)');
        const listaBox = document.getElementById('listaCategorias') || document.getElementById('vistaListaGenerica');
        if (gridBox && listaBox) {
            if (esLista) { gridBox.classList.add('d-none'); listaBox.classList.remove('d-none'); }
            else { gridBox.classList.remove('d-none'); listaBox.classList.add('d-none'); }
        }
    }
    document.querySelectorAll('.btn-check-grid').forEach(btn => btn.addEventListener('change', () => actualizarVista(false)));
    document.querySelectorAll('.btn-check-list').forEach(btn => btn.addEventListener('change', () => actualizarVista(true)));
    setTimeout(() => { actualizarVista(iniciarEnLista); }, 50);
});
</script>