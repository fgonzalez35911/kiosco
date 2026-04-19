<?php
// includes/layout_footer.php - FOOTER DINÁMICO Y PROFESIONAL

// Utilizamos las variables globales definidas previamente en layout_header.php
$color_footer = $color_sistema ?? '#102A57';
$nombre_negocio_footer = $nombre_negocio ?? 'SISTEMA DE GESTIÓN';
?>
</div> <style>
    /* FORZAR FOOTER ABAJO (Elimina el espacio en blanco) */
    html, body {
        height: 100%;
        display: flex;
        flex-direction: column;
        margin: 0;
    }
    
    /* El contenedor principal empuja el footer hacia el fondo */
    .container, .container-fluid, .main-content {
        flex: 1 0 auto;
    }

    .footer-dinamico {
        flex-shrink: 0;
        background-color: <?php echo $color_footer; ?>;
        color: white;
        margin-top: 60px;
        padding-top: 40px;
        padding-bottom: 20px;
        border-top: 5px solid rgba(255, 255, 255, 0.15); /* Borde sutil, no más celeste AFA */
        font-size: 0.9rem;
        width: 100%;
    }
    
    .footer-title {
        font-family: 'Roboto', sans-serif;
        text-transform: uppercase;
        color: #ffffff;
        margin-bottom: 15px;
        letter-spacing: 1px;
        font-weight: 900;
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
    .footer-link i { margin-right: 8px; color: rgba(255,255,255,0.5); }

    .copyright-bar {
        border-top: 1px solid rgba(255,255,255,0.1);
        margin-top: 30px;
        padding-top: 20px;
        font-size: 0.8rem;
        color: rgba(255,255,255,0.5);
    }
</style>

<footer class="footer-dinamico mt-auto">
    <div class="container">
        <div class="row g-4 justify-content-between">
            
            <div class="col-lg-5 col-md-6">
                <h5 class="footer-title"><?php echo htmlspecialchars(strtoupper($nombre_negocio)); ?> <span class="fs-6 opacity-50 text-white">POS</span></h5>
                <p class="text-white-50">
                    Sistema de control operativo y gestión integral. 
                    Administración centralizada y segura para <strong><?php echo htmlspecialchars($nombre_negocio); ?></strong>.
                </p>
                <div class="d-flex gap-3 mt-3">
                    <a href="#" class="text-white fs-5"><i class="bi bi-whatsapp"></i></a>
                    <a href="#" class="text-white fs-5"><i class="bi bi-instagram"></i></a>
                    <a href="#" class="text-white fs-5"><i class="bi bi-facebook"></i></a>
                </div>
            </div>

            <?php
            // Obtenemos el teléfono de la configuración para WhatsApp (limpiamos símbolos)
            $stmtF = $conexion->query("SELECT telefono_whatsapp FROM configuracion WHERE id=1");
            $conf_footer = $stmtF ? $stmtF->fetch(PDO::FETCH_ASSOC) : ['telefono_whatsapp' => ''];
            $tel_admin_limpio = preg_replace('/[^0-9]/', '', $conf_footer['telefono_whatsapp'] ?? '');
            ?>
            <div class="col-lg-3 col-md-6">
                <h5 class="footer-title">SOPORTE Y AYUDA</h5>
                <ul class="list-unstyled">
                    <li><a href="#" onclick="reportarError(); return false;" class="footer-link"><i class="bi bi-bug"></i> Reportar Error</a></li>
                    <li><a href="manual.php" target="_blank" class="footer-link"><i class="bi bi-book"></i> Manual de Usuario</a></li>
                    <li><a href="#" onclick="contactarSoporte(); return false;" class="footer-link"><i class="bi bi-headset"></i> Contactar Soporte</a></li>
                    <li><a href="auditoria.php" class="footer-link"><i class="bi bi-shield-check"></i> Auditoría de Sistema</a></li>
                </ul>
            </div>

            <div class="col-lg-3 col-md-6">
                <h5 class="footer-title">ESTADO DEL SISTEMA</h5>
                <ul class="list-unstyled text-white-50 small">
                    <li class="mb-2">
                        <i class="bi bi-circle-fill text-success me-2"></i> Operativo
                    </li>
                    <li class="mb-2">
                        <i class="bi bi-database-check me-2"></i> Base de Datos Conectada
                    </li>
                    <li class="mb-2">
                        <i class="bi bi-server me-2"></i> Última Versión
                    </li>
                    <li class="mt-3">
                        <i class="bi bi-clock me-1"></i> <span id="reloj-footer">--:--</span>
                    </li>
                </ul>
            </div>
        </div>

        <div class="copyright-bar d-flex flex-column flex-md-row justify-content-between align-items-center">
            <div>
                &copy; <?php echo date('Y'); ?> <strong><?php echo htmlspecialchars(strtoupper($nombre_negocio)); ?></strong>. Todos los derechos reservados.
            </div>
            <div class="mt-2 mt-md-0">
                <small class="text-white-50">Vanguard POS - Plataforma de Control</small>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    // Reloj Global Dinámico
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
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    /* Ajustes para que el buscador encaje perfecto con tu diseño Premium */
    .select2-container .select2-selection--single {
        height: 38px !important;
        border: 1px solid #dee2e6 !important;
        border-right: none !important;
        border-radius: 0.375rem 0 0 0.375rem !important;
        display: flex;
        align-items: center;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 36px !important;
    }
    .select2-selection__rendered {
        font-weight: bold !important;
        color: #102A57 !important;
    }
</style>

<script>
    $(document).ready(function() {
        // Inicializamos el buscador en el desplegable
        $('#select_clientes').select2({
            placeholder: "Escribí para buscar...",
            allowClear: false
        });
    });
</script>
<script>
function reportarError() {
    Swal.fire({
        title: 'Reportar un Error',
        html: `
            <p class="text-muted small mb-3">Describí el problema. Se enviará un WhatsApp al administrador.</p>
            <textarea id="error_txt" class="form-control shadow-sm mb-3" rows="4" placeholder="Ej: No me deja cobrar con la tarjeta Visa..."></textarea>
            <div class="form-check text-start bg-light p-2 rounded border">
                <input class="form-check-input ms-1" type="checkbox" id="enviar_mail" checked>
                <label class="form-check-label ms-2 small fw-bold text-dark" for="enviar_mail">
                    Enviar también copia de seguridad por correo
                </label>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-send"></i> Enviar Reporte',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#102A57',
        preConfirm: () => {
            const txt = document.getElementById('error_txt').value;
            const mail = document.getElementById('enviar_mail').checked;
            if(!txt) return Swal.showValidationMessage('Debes describir el error');
            return { txt, mail };
        }
    }).then((res) => {
        if(res.isConfirmed) {
            const texto = res.value.txt;
            const urlActual = window.location.pathname.split('/').pop();
            const mensajeWa = `🚨 *ALERTA DE ERROR - Vanguard POS* 🚨%0A*Pantalla:* ${urlActual}%0A*Reporte:* ${texto}`;
            
            // Si el tilde está marcado, enviamos el correo silencioso en segundo plano
            if(res.value.mail) {
                let formData = new FormData();
                formData.append('mensaje', texto);
                formData.append('url', urlActual);
                fetch('ajax_enviar_error.php', { method: 'POST', body: formData });
            }
            
            // Abrimos WhatsApp instantáneamente
            window.open(`https://wa.me/<?php echo $tel_admin_limpio; ?>?text=${mensajeWa}`, '_blank');
        }
    });
}

function contactarSoporte() {
    Swal.fire({
        title: 'Centro de Soporte',
        html: `
            <div class="d-flex flex-column gap-3 mt-3">
                <a href="https://wa.me/<?php echo $tel_admin_limpio; ?>" target="_blank" class="btn btn-success fw-bold p-3 text-start shadow-sm rounded-4"><i class="bi bi-whatsapp fs-4 me-3 align-middle"></i> Chat Directo (Urgencias)</a>
                <a href="mailto:info@federicogonzalez.net?subject=Consulta Soporte - Vanguard POS" class="btn btn-primary fw-bold p-3 text-start shadow-sm rounded-4"><i class="bi bi-envelope fs-4 me-3 align-middle"></i> Enviar Correo (Administrativo)</a>
                <a href="https://anydesk.com/es/downloads" target="_blank" class="btn btn-danger fw-bold p-3 text-start shadow-sm rounded-4"><i class="bi bi-pc-display fs-4 me-3 align-middle"></i> AnyDesk (Soporte Remoto)</a>
            </div>
        `,
        showConfirmButton: false,
        showCloseButton: true,
        width: '450px'
    });
}
</script>

</body>
</html>