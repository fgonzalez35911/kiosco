<?php
// Archivo: firma_externa.php
// Propósito: Permitir la firma remota de solicitudes de trabajo mediante token.

include 'conexion.php';

$token = trim($_GET['token'] ?? '');
$mensaje_error = "";
$pedido = null;

// 1. Validar el Token y obtener datos del pedido
if (empty($token)) {
    $mensaje_error = "Token de acceso no proporcionado.";
} else {
    try {
        $stmt = $pdo->prepare("SELECT p.*, d.nombre as nombre_destino 
                               FROM pedidos_trabajo p
                               LEFT JOIN destinos_internos d ON p.id_destino_interno = d.id_destino
                               WHERE p.token_firma = :token");
        $stmt->execute([':token' => $token]);
        
        $pedido = $stmt->fetch(PDO::FETCH_OBJ); 

        if (!$pedido) {
            $mensaje_error = "El enlace es inválido o ya fue utilizado.";
        } 
    } catch (PDOException $e) {
        $mensaje_error = "Error de base de datos: " . $e->getMessage();
    }
}

// 2. Procesar la Firma (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pedido) {
    $firma_base64 = $_POST['firma_base64'] ?? '';
    $nombre_firmante_post = trim($_POST['nombre_firmante'] ?? '');
    $id_pedido = $pedido->id_pedido;

    if (!empty($firma_base64)) {
        try {
            $upload_dir = 'uploads/firmas_pedidos/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $data = explode(',', $firma_base64);
            $encoded_image = (count($data) > 1) ? $data[1] : $data[0];
            $decoded_image = base64_decode($encoded_image);

            $filename = 'remota_' . $id_pedido . '_' . time() . '.png';
            $ruta_completa = $upload_dir . $filename;

            if (file_put_contents($ruta_completa, $decoded_image)) {
                $pdo->beginTransaction();

                $sql_update = "UPDATE pedidos_trabajo 
                               SET firma_solicitante_path = :path, 
                                   solicitante_real_nombre = :nombre,
                                   estado_pedido = 'pendiente_encargado',
                                   token_firma = NULL 
                               WHERE id_pedido = :id";
                
                $stmt_upd = $pdo->prepare($sql_update);
                $stmt_upd->execute([
                    ':path' => $ruta_completa,
                    ':nombre' => !empty($nombre_firmante_post) ? $nombre_firmante_post : $pedido->solicitante_real_nombre,
                    ':id' => $id_pedido
                ]);

                $pdo->commit();
                
                // 1. AUTO-GENERAR EL PDF EN SEGUNDO PLANO (BLINDADO)
                try {
                    $es_llamada_interna = true;
                    $id_pedido = $id_pedido; 
                    $modo = 'inicial';
                    
                    if (ob_get_level()) ob_end_clean();
                    
                    ob_start();
                    include __DIR__ . '/generar_pedido_pdf.php';
                    ob_end_clean();
                } catch (Throwable $e_pdf) {
                    error_log("Error al actualizar PDF físico tras firma: " . $e_pdf->getMessage());
                }
                // 2. ENVIAR CORREO CON EL PDF (Usando la variable generada en el include)
                try {
                    include_once 'envio_correo_hostinger.php';
                    
                    // Aseguramos que el nombre del archivo esté disponible
                    if (!isset($pdf_filename)) {
                        $num_limpio = str_replace('/', '-', $pedido->numero_orden);
                        $pdf_filename = "Pedido_Trabajo_" . $num_limpio . "_Inicial.pdf";
                    }
                    
                    $enlace_pdf_publico = "https://federicogonzalez.net/logistica/pdfs_publicos/" . rawurlencode($pdf_filename);
                    
                    $asunto = "Copia Firmada: Pedido N° " . $pedido->numero_orden;
                    $cuerpo = "
                    <div style='font-family: Arial, sans-serif; padding: 20px; color: #333; max-width: 600px; border: 1px solid #eee;'>
                        <h2 style='color: #102A57; border-bottom: 2px solid #102A57; padding-bottom: 10px;'>Documento Conformado</h2>
                        <p>La solicitud de trabajo ha sido firmada exitosamente.</p>
                        <p style='background: #f9f9f9; padding: 15px; border-left: 4px solid #102A57;'><strong>Detalle:</strong> {$pedido->titulo_pedido}</p>
                        <p>Puede descargar y visualizar el comprobante oficial en formato PDF haciendo clic en el siguiente enlace:</p>
                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='{$enlace_pdf_publico}' style='background-color: #d9534f; color: white; padding: 15px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;'>VER PDF FIRMADO</a>
                        </div>
                        <hr style='border: 0; border-top: 1px solid #eee; margin-top: 30px;'>
                        <p style='font-size: 11px; color: #999; text-align: center;'>Sistema de Gestión Logística - Policlínica Actis / IOSFA</p>
                    </div>";

                    $correos = explode(',', $pedido->solicitante_email);
                    foreach ($correos as $correo_dest) {
                        $correo_dest = trim($correo_dest);
                        if (!empty($correo_dest) && filter_var($correo_dest, FILTER_VALIDATE_EMAIL)) {
                            enviarCorreoNativo($correo_dest, $asunto, $cuerpo);
                        }
                    }
                } catch (Exception $e_mail) {
                    error_log("Error en envío de mail post-firma: " . $e_mail->getMessage());
                }
                
                // RESPUESTA JSON FINAL (Garantiza que SweetAlert reciba el OK)
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Firma y PDF registrados correctamente.']);
                exit;
            } else {
                echo json_encode(['status' => 'error', 'message' => 'No se pudo guardar el archivo de firma.']);
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Firma Digital de Solicitud - Logística</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --blue-premium: #102A57; }
        body { background-color: #f4f7f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .header-banner { background-color: var(--blue-premium); color: white; padding: 40px; border-radius: 0 0 20px 20px; margin-bottom: 30px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .card-premium { border: none; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); background: rgba(255, 255, 255, 0.9); }
        .signature-wrapper { border: 2px dashed #ced4da; border-radius: 10px; background: #fff; position: relative; height: 250px; }
        canvas { width: 100%; height: 100%; touch-action: none; cursor: crosshair; }
        .btn-premium { background-color: var(--blue-premium); color: white; border: none; padding: 12px 25px; border-radius: 8px; font-weight: 600; transition: all 0.3s; }
        .btn-premium:hover { background-color: #0d2145; color: #e0e0e0; transform: translateY(-2px); }
        .detail-row { border-bottom: 1px solid #f0f0f0; padding: 10px 0; }
        .detail-label { font-weight: bold; color: #555; font-size: 0.9rem; }
    </style>
</head>
<body>

<div class="header-banner text-center">
    <img src="https://federicogonzalez.net/logistica/assets/img/logo_premium.png" alt="Logo" style="max-height: 80px; margin-bottom: 1rem;" onerror="this.style.display='none'">
    <h1 class="display-6 fw-bold">Conformidad de Solicitud</h1>
    <p class="lead mb-0">Sistema de Gestión Logística - Policlínica Actis</p>
</div>

<div class="container mb-5">
    <?php if ($mensaje_error): ?>
        <script>
            Swal.fire({
                icon: 'error',
                title: 'Enlace no válido',
                text: '<?php echo $mensaje_error; ?>',
                confirmButtonColor: '#102A57'
            });
        </script>
        <div class="text-center mt-5">
            <i class="fas fa-exclamation-triangle fa-5x text-muted mb-4"></i>
            <h2 class="text-secondary"><?php echo $mensaje_error; ?></h2>
            <p class="text-muted">Si cree que esto es un error, contacte al Departamento de Logística.</p>
        </div>
    <?php else: ?>
        <div class="row justify-content-center">
            <div class="col-lg-8">
                
                <div class="card card-premium mb-4">
                    <div class="card-body p-4">
                        <h4 class="card-title text-primary mb-4 border-bottom pb-2">
                            <i class="fas fa-info-circle me-2"></i>Detalles del Pedido N° <?php echo htmlspecialchars($pedido->numero_orden); ?>
                        </h4>
                        
                        <div class="detail-row row">
                            <div class="col-sm-4 detail-label text-uppercase">Título:</div>
                            <div class="col-sm-8 fw-bold"><?php echo htmlspecialchars($pedido->titulo_pedido); ?></div>
                        </div>
                        <div class="detail-row row">
                            <div class="col-sm-4 detail-label text-uppercase">Destino:</div>
                            <div class="col-sm-8"><?php echo htmlspecialchars($pedido->nombre_destino); ?></div>
                        </div>
                        <?php 
                        $area_val = trim($pedido->area_solicitante ?? '');
                        if (!empty($area_val) && strtoupper($area_val) !== 'N/A' && strtoupper($area_val) !== 'N/D'): 
                        ?>
                        <div class="detail-row row">
                            <div class="col-sm-4 detail-label text-uppercase">Área:</div>
                            <div class="col-sm-8"><?php echo htmlspecialchars($area_val); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="detail-row row">
                            <div class="col-sm-4 detail-label text-uppercase">Prioridad:</div>
                            <div class="col-sm-8">
                                <span class="badge <?php echo ($pedido->prioridad == 'urgente') ? 'bg-danger' : 'bg-primary'; ?>">
                                    <?php echo strtoupper($pedido->prioridad); ?>
                                </span>
                            </div>
                        </div>
                        <div class="mt-3">
                            <p class="detail-label text-uppercase mb-1">Descripción del trabajo:</p>
                            <div class="p-3 bg-light rounded italic" style="border-left: 4px solid var(--blue-premium);">
                                <?php echo nl2br(htmlspecialchars($pedido->descripcion_sintomas)); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card card-premium shadow-lg">
                    <div class="card-body p-5 text-center">
                        <h4 class="text-primary mb-4"><i class="fas fa-file-contract me-2"></i>Conformidad de Solicitud</h4>
                        
                        <div id="contenedor-inicio-firma">
                            <p class="text-muted mb-3">Verifique o corrija su nombre completo antes de firmar:</p>
                            
                            <div class="input-group mb-4 shadow-sm">
                                <span class="input-group-text bg-white text-primary border-end-0"><i class="fas fa-user-edit"></i></span>
                                <input type="text" id="nombre_firmante" class="form-control border-start-0 ps-0 form-control-lg fw-bold text-dark" value="<?php echo htmlspecialchars($pedido->solicitante_real_nombre); ?>" placeholder="Escriba su nombre completo">
                            </div>

                            <button type="button" class="btn btn-premium btn-lg w-100 py-3 shadow" data-bs-toggle="modal" data-bs-target="#modalFirma">
                                <i class="fas fa-signature me-2"></i> PROCEDER CON LA FIRMA
                            </button>
                        </div>

                        <div id="seccion-confirmacion-final" style="display:none;">
                            <p class="small text-success fw-bold mb-3"><i class="fas fa-check-circle"></i> FIRMA CAPTURADA CORRECTAMENTE</p>
                            <div class="mb-4">
                                <img id="img-preview" src="" class="img-fluid shadow-sm" style="max-height: 150px; border: 2px solid #102A57; border-radius: 10px; background: #fff;">
                            </div>
                            <button type="button" class="btn btn-success btn-lg w-100 py-3 fw-bold shadow mb-3" onclick="enviarSolicitudDefinitiva()">
                                <i class="fas fa-paper-plane me-2"></i> CONFIRMAR Y ENVIAR SOLICITUD
                            </button>
                            <button type="button" class="btn btn-outline-secondary px-4 rounded-pill fw-bold shadow-sm" style="border-width: 2px;" data-bs-toggle="modal" data-bs-target="#modalFirma">
                                <i class="fas fa-undo-alt me-2"></i>Corregir firma
                            </button>
                        </div>
                        
                        <input type="hidden" id="firma_base64">
                    </div>
                </div>

                <div class="modal fade" id="modalFirma" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg">
                            <div class="modal-header bg-light">
                                <h5 class="modal-title text-primary fw-bold"><i class="fas fa-pen-nib me-2"></i> Panel de Firma Digital</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-4">
                                <p class="text-center text-muted small mb-3">Firme con su dedo o puntero sobre la línea gris.</p>
                                <div class="signature-wrapper shadow-sm" style="height: 350px; position: relative; background: #fff; border: 1px solid #ddd; border-radius: 8px;">
                                    <canvas id="canvas-modal" style="width: 100%; height: 100%; cursor: crosshair; touch-action: none;"></canvas>
                                    <div style="position: absolute; bottom: 80px; left: 10%; right: 10%; border-top: 2px solid #ced4da; text-align: center; pointer-events: none;">
                                        <span style="background: white; padding: 0 15px; color: #adb5bd; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 3px;">Firme aquí</span>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer bg-light justify-content-between">
                                <button type="button" class="btn btn-outline-danger px-4" id="btn-limpiar">
                                    <i class="fas fa-eraser me-1"></i> Limpiar
                                </button>
                                <button type="button" class="btn btn-success px-5 fw-bold" id="btn-guardar-modal">
                                    <i class="fas fa-save me-1"></i> GUARDAR FIRMA
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modalElement = document.getElementById('modalFirma');
    const canvas = document.getElementById('canvas-modal');
    const inputFirma = document.getElementById('firma_base64');
    const imgPreview = document.getElementById('img-preview');
    const contenedorInicio = document.getElementById('contenedor-inicio-firma');
    const seccionFinal = document.getElementById('seccion-confirmacion-final');
    
    let signaturePad;

    modalElement.addEventListener('shown.bs.modal', function () {
        if (!signaturePad) {
            signaturePad = new SignaturePad(canvas, {
                backgroundColor: 'rgba(255, 255, 255, 0)',
                penColor: 'rgb(16, 42, 87)'
            });
        }
        resizeCanvas();
    });

    function resizeCanvas() {
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext("2d").scale(ratio, ratio);
        if (signaturePad) signaturePad.clear();
    }

    const btnLimpiar = document.getElementById('btn-limpiar');
    const accionLimpiar = function(e) {
        e.preventDefault();
        signaturePad.clear();
    };
    btnLimpiar.addEventListener('click', accionLimpiar);
    btnLimpiar.addEventListener('touchstart', accionLimpiar, { passive: false });

    const btnGuardar = document.getElementById('btn-guardar-modal');
    const accionGuardar = function(e) {
        e.preventDefault(); // Anula el doble clic fantasma en celulares
        
        if (signaturePad.isEmpty()) {
            return Swal.fire({ icon: 'warning', title: 'Atención', text: 'El panel está vacío. Por favor firme.', confirmButtonColor: '#102A57' });
        }

        const base64 = signaturePad.toDataURL();
        inputFirma.value = base64;
        imgPreview.src = base64;

        contenedorInicio.style.display = 'none';
        seccionFinal.style.display = 'block';

        const modalInstance = bootstrap.Modal.getInstance(modalElement) || new bootstrap.Modal(modalElement);
        modalInstance.hide();
        
        Swal.fire({
            icon: 'success',
            title: 'Firma capturada',
            text: 'Revise la vista previa y presione el botón verde de enviar.',
            confirmButtonColor: '#102A57'
        });
    };
    btnGuardar.addEventListener('click', accionGuardar);
    btnGuardar.addEventListener('touchstart', accionGuardar, { passive: false });

    window.enviarSolicitudDefinitiva = function() {
        const base64 = inputFirma.value;
        if (!base64) return;

        Swal.fire({
            title: '¿Confirmar envío?',
            text: "Se registrará su firma de conformidad.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#102A57',
            confirmButtonText: 'Sí, enviar ahora',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

                const nombreActualizado = document.getElementById('nombre_firmante').value;

                fetch('firma_externa.php?token=<?php echo $token; ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'firma_base64=' + encodeURIComponent(base64) + '&nombre_firmante=' + encodeURIComponent(nombreActualizado)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        // Reemplazamos la interfaz para mostrar los botones finales
                        document.getElementById('seccion-confirmacion-final').innerHTML = `
                            <div class="alert alert-success mt-4 p-4 shadow-sm border-success border-2 text-start" style="border-radius: 15px; background-color: #f8fff9;">
                                <h4 class="alert-heading fw-bold text-success mb-3 text-center"><i class="fas fa-check-circle me-2"></i>¡Firma Registrada Exitosamente!</h4>
                                <p class="text-dark mb-4 text-center">La solicitud ha sido procesada y enviada a Logística.</p>
                                <hr class="mb-4">
                                <div class="d-grid gap-3">
                                    <a href="https://federicogonzalez.net/logistica/pdfs_publicos/Pedido_Trabajo_<?php echo str_replace('/', '-', $pedido->numero_orden); ?>_Inicial.pdf" target="_blank" class="btn btn-danger btn-lg fw-bold shadow-sm">
                                        <i class="fas fa-file-pdf me-2"></i> Ver / Descargar PDF
                                    </a>
                                    <a href="https://federicogonzalez.net/logistica/confirmacion_ok.php" class="btn btn-primary btn-lg fw-bold shadow-sm" style="background-color: #102A57; border-color: #102A57;">
                                        <i class="fas fa-sign-out-alt me-2"></i> Finalizar y Salir
                                    </a>
                                </div>
                            </div>
                        `;
                        Swal.fire({ icon: 'success', title: '¡Excelente!', text: 'Su conformidad se guardó correctamente.', confirmButtonColor: '#102A57' });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                    }
                })
                .catch(() => {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudo conectar con el servidor.' });
                });
            }
        });
    };
});
</script>
</body>
</html>