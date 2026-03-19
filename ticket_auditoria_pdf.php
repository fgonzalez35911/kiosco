<?php
// ticket_auditoria_pdf.php - FIX CACHÉ Y FIX TAMAÑO TICKET
require_once 'includes/db.php';

$id_audit = $_GET['id'] ?? null;
if (!$id_audit) die("ID de auditoría no proporcionado.");

$stmt = $conexion->prepare("SELECT a.*, u.usuario, u.nombre_completo FROM auditoria a JOIN usuarios u ON a.id_usuario = u.id WHERE a.id = ?");
$stmt->execute([$id_audit]);
$log = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$log) die("Registro no encontrado.");

$conf = $conexion->query("SELECT nombre_negocio, direccion_local, cuit, logo_url FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);

$fechaF = date('d/m/Y H:i', strtotime($log['fecha']));
$accion = strtoupper($log['accion']);

$colorHeader = '#102A57';
if(strpos($accion, 'ELIMIN') !== false || strpos($accion, 'BAJA') !== false) $colorHeader = '#dc3545';
if(strpos($accion, 'VENTA') !== false || strpos($accion, 'PAGO') !== false) $colorHeader = '#198754';
if(strpos($accion, 'INFLACION') !== false || strpos($accion, 'GASTO') !== false) $colorHeader = '#fd7e14';

$ruta_firma = "img/firmas/usuario_" . $log['id_usuario'] . ".png";
if (!file_exists($ruta_firma)) $ruta_firma = "img/firmas/firma_admin.png";

// ANTI-CACHÉ ABSOLUTO (Cambia cada segundo, obliga al navegador a bajar la imagen real)
$v_cache = time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprobante de Auditoría #<?php echo $log['id']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body { background-color: #f4f6f9; display: flex; justify-content: center; padding: 20px; font-family: 'Inter', sans-serif; }
        
        /* Diseño para la vista Web */
        .ticket-container { background: #fff; width: 100%; max-width: 400px; padding: 30px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); margin: 0 auto; }
        .logo { max-height: 70px; margin-bottom: 15px; }
        .dashed-line { border-bottom: 2px dashed #ccc; margin: 20px 0; }
        .detalle-box { background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid #eee; font-size: 0.9rem; }
        
        /* Diseño EXCLUSIVO para el PDF (Fuerza el formato Ticketera 80mm) */
        .impresion-ticket {
            width: 280px !important;
            padding: 10px !important;
            margin: 0 auto !important;
            background: white !important;
            box-shadow: none !important;
            border-radius: 0 !important;
        }
    </style>
</head>
<body>

    <div>
        <div class="ticket-container" id="ticketPDF">
            <div class="text-center">
                <?php if(!empty($conf['logo_url'])): ?>
                    <img src="<?php echo $conf['logo_url']; ?>?v=<?php echo $v_cache; ?>" class="logo">
                <?php endif; ?>
                <h4 class="fw-bold text-uppercase mb-0" style="font-size: 1.2rem;"><?php echo $conf['nombre_negocio']; ?></h4>
                <p class="text-muted small mb-0">CUIT: <?php echo $conf['cuit'] ?: 'S/N'; ?></p>
                <p class="text-muted small"><?php echo $conf['direccion_local']; ?></p>
            </div>

            <div class="dashed-line"></div>

            <div class="text-center mb-3">
                <h5 class="fw-bold mb-1" style="color: <?php echo $colorHeader; ?>; font-size: 1.1rem;">REGISTRO DE SISTEMA</h5>
                <span class="badge bg-light text-dark border">AUDIT #<?php echo $log['id']; ?></span>
            </div>

            <div class="detalle-box mb-4">
                <div class="d-flex justify-content-between mb-1"><strong>FECHA:</strong> <span><?php echo $fechaF; ?></span></div>
                <div class="d-flex justify-content-between mb-1"><strong>ACCIÓN:</strong> <span style="color: <?php echo $colorHeader; ?>; font-weight: bold;"><?php echo $accion; ?></span></div>
                <div class="d-flex justify-content-between"><strong>OPERADOR:</strong> <span><?php echo strtoupper($log['usuario']); ?></span></div>
            </div>

            <div class="mb-4" style="font-size: 0.85rem; line-height: 1.5;">
                <strong class="d-block border-bottom pb-1 mb-2">DETALLE DEL MOVIMIENTO:</strong>
                <?php echo str_replace('|', '<br>', htmlspecialchars($log['detalles'])); ?>
            </div>

            <div class="dashed-line"></div>

            <div class="text-center mt-4" style="display:flex; flex-direction:column; align-items:center;">
                <?php if(file_exists($ruta_firma)): ?>
                    <img src="<?php echo $ruta_firma; ?>?v=<?php echo $v_cache; ?>" style="max-height: 65px; margin-bottom: -10px; position: relative; z-index: 10;">
                <?php endif; ?>
                <div class="border-top border-dark mx-auto pt-1" style="width: 150px; position: relative; z-index: 1;">
                    <small class="fw-bold" style="font-size: 0.7rem;"><?php echo strtoupper($log['nombre_completo'] ?: 'FIRMA AUTORIZADA'); ?></small>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mt-4 justify-content-center">
            <button onclick="descargarPDF()" class="btn btn-danger fw-bold shadow-sm px-4 rounded-pill">
                <i class="bi bi-file-earmark-pdf-fill me-2"></i> DESCARGAR TICKET
            </button>
            <?php 
                $url_actual = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
                $mensajeWA = urlencode("Hola! Te comparto el comprobante de auditoría #{$log['id']}: $url_actual");
            ?>
            <a href="https://wa.me/?text=<?php echo $mensajeWA; ?>" target="_blank" class="btn btn-success fw-bold shadow-sm px-4 rounded-pill">
                <i class="bi bi-whatsapp me-2"></i> COMPARTIR
            </a>
        </div>
    </div>

    <script>
        function descargarPDF() {
            const elemento = document.getElementById('ticketPDF');
            
            // Transformamos temporalmente la caja al ancho exacto de ticketera térmica
            elemento.classList.add('impresion-ticket');
            elemento.classList.remove('ticket-container');

            const opciones = {
                margin:       [2, 2, 2, 2],
                filename:     'Ticket_Auditoria_#<?php echo $log['id']; ?>.pdf',
                image:        { type: 'jpeg', quality: 1 },
                html2canvas:  { scale: 2, useCORS: true },
                // 80mm de ancho, 200mm de alto
                jsPDF:        { unit: 'mm', format: [80, 200], orientation: 'portrait' }
            };

            html2pdf().set(opciones).from(elemento).save().then(() => {
                // Devolvemos el diseño a su estado web normal
                elemento.classList.remove('impresion-ticket');
                elemento.classList.add('ticket-container');
            });
        }
    </script>
</body>
</html>