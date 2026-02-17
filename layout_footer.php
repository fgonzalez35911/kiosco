<?php
// includes/layout_footer.php - FOOTER LIMPIO (Sin conflictos de teclas)
?>
</div> <style>
    .footer-10 {
        background-color: var(--azul-fuerte); /* Azul Oscuro */
        color: white;
        margin-top: 60px;
        padding-top: 40px;
        padding-bottom: 20px;
        border-top: 5px solid var(--celeste-afa);
        font-size: 0.9rem;
    }
    
    .footer-title {
        font-family: 'Oswald', sans-serif;
        text-transform: uppercase;
        color: var(--celeste-afa);
        margin-bottom: 15px;
        letter-spacing: 1px;
    }

    .footer-link {
        color: rgba(255,255,255,0.7);
        text-decoration: none;
        display: block;
        margin-bottom: 8px;
        transition: 0.2s;
    }
    .footer-link:hover {
        color: white;
        transform: translateX(5px);
    }
    .footer-link i { margin-right: 8px; color: var(--celeste-afa); }

    .copyright-bar {
        border-top: 1px solid rgba(255,255,255,0.1);
        margin-top: 30px;
        padding-top: 20px;
        font-size: 0.8rem;
        color: rgba(255,255,255,0.5);
    }
</style>

<footer class="footer-10 mt-auto">
    <div class="container">
        <div class="row g-4 justify-content-between">
            
            <div class="col-lg-5 col-md-6">
                <h5 class="footer-title">EL 10 POS <span class="fs-6 opacity-50 text-white">SYSTEM</span></h5>
                <p class="text-white-50">
                    Sistema de gestión integral diseñado para campeones del mundo. 
                    Controlá tu cancha, cuidá a tu hinchada y ganá el partido económico.
                </p>
                <div class="d-flex gap-3 mt-3">
                    <a href="#" class="text-white fs-5"><i class="bi bi-whatsapp"></i></a>
                    <a href="#" class="text-white fs-5"><i class="bi bi-instagram"></i></a>
                    <a href="#" class="text-white fs-5"><i class="bi bi-facebook"></i></a>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <h5 class="footer-title">EL VAR (SOPORTE)</h5>
                <ul class="list-unstyled">
                    <li><a href="#" class="footer-link"><i class="bi bi-bug"></i> Reportar Error</a></li>
                    <li><a href="#" class="footer-link"><i class="bi bi-question-circle"></i> Manual de Reglas</a></li>
                    <li><a href="#" class="footer-link"><i class="bi bi-headset"></i> Llamar al Árbitro</a></li>
                    <li><a href="auditoria.php" class="footer-link"><i class="bi bi-eye"></i> Ver Repetición (Logs)</a></li>
                </ul>
            </div>

            <div class="col-lg-3 col-md-6">
                <h5 class="footer-title">ESTADO</h5>
                <ul class="list-unstyled text-white-50 small">
                    <li class="mb-2">
                        <i class="bi bi-circle-fill text-success me-2"></i> Sistema Online
                    </li>
                    <li class="mb-2">
                        <i class="bi bi-database me-2"></i> DB Conectada
                    </li>
                    <li class="mb-2">
                        <i class="bi bi-server me-2"></i> Ver. 10.1 (Campeón)
                    </li>
                    <li class="mt-3">
                        <i class="bi bi-clock me-1"></i> <span id="reloj-footer">--:--</span>
                    </li>
                </ul>
            </div>
        </div>

        <div class="copyright-bar d-flex flex-column flex-md-row justify-content-between align-items-center">
            <div>
                &copy; <?php echo date('Y'); ?> <strong>EL 10 POS</strong>. Hecho con pasión en Argentina <img src="https://upload.wikimedia.org/wikipedia/commons/1/1a/Flag_of_Argentina.svg" alt="ARG" width="20" style="vertical-align: text-top; border:1px solid #555;">
            </div>
            <div class="mt-2 mt-md-0">
                <small class="text-white-50">🛡️ Copyright © 2026. Desarrollado por Federico González, IT & Senior Developer 👨‍💻. Todos los derechos reservados.</small>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    // Reloj Global
    function updateGlobalClock() {
        const now = new Date();
        const timeString = now.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'America/Argentina/Buenos_Aires' });
        
        const elHeader = document.getElementById('reloj-global'); 
        if(elHeader) elHeader.textContent = timeString;

        const elFooter = document.getElementById('reloj-footer'); 
        if(elFooter) elFooter.textContent = timeString;
    }
    setInterval(updateGlobalClock, 1000); updateGlobalClock();
</script>

</body>
</html>