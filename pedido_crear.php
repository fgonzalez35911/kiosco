<?php
// Archivo: pedido_crear.php (COMPLETO Y FUNCIONAL vFinal - Con Selección Dinámica)
// *** MODIFICADO (v4) PARA FONDO DE FIRMA TRANSPARENTE ***
// *** MODIFICADO (v5) POR GEMINI PARA MOSTRAR MODAL DE ÉXITO EN LUGAR DE REDIRIGIR A PDF ***
// *** MODIFICADO (v6) SELECCIÓN DINÁMICA: DESTINO -> ÁREA (Prioridad Actis) ***

include 'acceso_protegido.php'; // Incluye sesión y $pdo

// 1. Verificar Permiso:
include_once 'funciones_permisos.php';
if (!isset($_SESSION['usuario_id']) || !tiene_permiso('acceso_pedidos_crear', $pdo)) {
    $_SESSION['action_error_message'] = "No tiene permiso para crear pedidos.";
    header("Location: dashboard.php");
    exit();
}

// 2. Obtener datos del Usuario logueado (Quien crea el pedido)
$id_usuario_logueado = $_SESSION['usuario_id'];
$nombre_usuario_logueado = $_SESSION['usuario_nombre'];
$firma_usuario_logueado_path = null;

// 3. Inicializar variables
$mensaje = '';
$alerta_tipo = '';
$areas = [];
$destinos = [];
// Variables para repopular en caso de error POST
$titulo_pedido_prev = $_POST['titulo_pedido'] ?? '';
$selected_area = $_POST['id_area'] ?? '';
$selected_destino = $_POST['id_destino_interno'] ?? '';
$selected_prioridad = $_POST['prioridad'] ?? 'rutina';
$fecha_req_prev = $_POST['fecha_requerida'] ?? '';
$desc_sint_prev = $_POST['descripcion_sintomas'] ?? '';
$solic_real_prev = $_POST['solicitante_real_nombre'] ?? '';
$solic_telefono_prev = $_POST['solicitante_telefono'] ?? '';

// --- INICIO MODIFICACIÓN GEMINI (v5): Capturar variables de éxito de la Sesión ---
$show_success_modal = $_SESSION['pedido_creado_exito'] ?? false;
$nuevo_pedido_id_modal = $_SESSION['pedido_creado_id'] ?? 0;
$nuevo_pedido_numero_modal = $_SESSION['pedido_creado_numero'] ?? 'N/A';
$nuevo_pedido_titulo_modal = $_SESSION['pedido_creado_titulo'] ?? 'Pedido';

// Limpiar las variables de sesión para que el modal no se muestre de nuevo al recargar
if ($show_success_modal) {
    unset($_SESSION['pedido_creado_exito']);
    unset($_SESSION['pedido_creado_id']);
    unset($_SESSION['pedido_creado_numero']);
    unset($_SESSION['pedido_creado_titulo']);
}
// --- FIN MODIFICACIÓN GEMINI (v5) ---


// --- Bloque Try-Catch para operaciones críticas de carga ---
try {
    // 4. Obtener firma del usuario logueado desde la BD
    $stmt_firma = $pdo->prepare("SELECT firma_imagen_path FROM usuarios WHERE id_usuario = :id");
    $stmt_firma->execute([':id' => $id_usuario_logueado]);
    $firma_rel_path = $stmt_firma->fetchColumn();
    if ($firma_rel_path) {
         $ruta_completa_firma = 'uploads/firmas/' . $firma_rel_path;
         if (file_exists($ruta_completa_firma)) {
            $firma_usuario_logueado_path = $firma_rel_path;
         } else {
            error_log("Archivo de firma no encontrado para usuario {$id_usuario_logueado}: {$ruta_completa_firma}");
         }
    }

    // 5. Cargar Destinos (MODIFICADO: Priorizando Actis y luego alfabético)
    // El CASE WHEN asigna 0 si contiene 'Actis', de lo contrario 1, ordenando primero los 0.
    $sql_destinos = "SELECT id_destino, nombre, firma_remota FROM destinos_internos 
                    ORDER BY CASE WHEN nombre LIKE '%Actis%' THEN 0 ELSE 1 END, nombre ASC";
    $destinos = $pdo->query($sql_destinos)->fetchAll(PDO::FETCH_ASSOC);

    // Las áreas se cargarán por AJAX, iniciamos vacío por seguridad o cargamos todas si falla JS
    // $areas = $pdo->query("SELECT id_area, nombre FROM areas ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC); 
    // Comentamos la carga masiva de áreas para forzar el uso del select dinámico, 
    // pero si quisieras un fallback podrías descomentarlo.

} catch (PDOException $e) {
    $mensaje = "Error crítico al preparar el formulario: " . $e->getMessage();
    $alerta_tipo = 'danger';
    error_log("Error carga pedido_crear: " . $e->getMessage());
}


// --- 7. Lógica POST para guardar el pedido ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $solicitante_real_nombre_post = trim($_POST['solicitante_real_nombre'] ?? '');
    $solicitante_telefono_post = trim($_POST['solicitante_telefono'] ?? '');
    $solicitante_email_post = trim($_POST['email_solicitante_externo'] ?? '');
    $copia_email_post = trim($_POST['email_copia_externo'] ?? ''); // correo CC
    $ruta_firma_solicitante_guardada = null;
    
    // --- INICIO: Procesar Firma Base64 ---
    $firma_base64 = $_POST['firma_solicitante_base64'] ?? '';
    
    // Detectar si el destino seleccionado requiere firma remota (consultamos a la BD para seguridad)
    $es_firma_remota = false;
    $id_destino_post = (int)($_POST['id_destino_interno'] ?? 0);
    if ($id_destino_post > 0) {
        $stmt_check_remota = $pdo->prepare("SELECT firma_remota FROM destinos_internos WHERE id_destino = :id");
        $stmt_check_remota->execute([':id' => $id_destino_post]);
        $es_firma_remota = ($stmt_check_remota->fetchColumn() == 1);
    }

    if (!empty($firma_base64)) {
        $upload_dir_firmas = 'uploads/firmas_pedidos/';
        if (!is_dir($upload_dir_firmas)) {
            if (!mkdir($upload_dir_firmas, 0777, true)) {
                $mensaje = "Error crítico: No se pudo crear el directorio de firmas.";
                $alerta_tipo = 'danger';
                goto end_post_logic; 
            }
        }

        $data = explode(',', $firma_base64);
        $encoded_image = (count($data) > 1) ? $data[1] : $data[0];
        $decoded_image = base64_decode($encoded_image);

        if ($decoded_image === false) {
            $mensaje = "Error: El formato de la firma enviada es inválido.";
            $alerta_tipo = 'danger';
            goto end_post_logic;
        }

        $nombre_solicitante_limpio = preg_replace("/[^a-zA-Z0-9]/", "", str_replace(" ", "_", $solicitante_real_nombre_post));
        $filename = 'solic_' . $nombre_solicitante_limpio . '_' . time() . '.png';
        $ruta_completa_firma = $upload_dir_firmas . $filename;

        if (file_put_contents($ruta_completa_firma, $decoded_image)) {
            $ruta_firma_solicitante_guardada = $ruta_completa_firma; 
        } else {
            $mensaje = "Error: No se pudo guardar el archivo de la firma en el servidor.";
            $alerta_tipo = 'danger';
            goto end_post_logic;
        }
    } else {
         // Si NO hay firma, pero el destino NO es remoto, tiramos error.
         // Si es remoto, permitimos que pase porque se firmará después.
         if (!$es_firma_remota) {
             $mensaje = "Error: La firma del solicitante es obligatoria para este destino.";
             $alerta_tipo = 'danger';
             goto end_post_logic;
         }
    }
    
    // Revalidar/recuperar datos del POST
    $titulo_pedido_post = trim($_POST['titulo_pedido'] ?? '');
    $id_area_post = (int)($_POST['id_area'] ?? 0);
    $id_destino_interno_post = (int)($_POST['id_destino_interno'] ?? 0);
    $prioridad_post = trim($_POST['prioridad'] ?? 'rutina');
    $fecha_requerida_post = empty($_POST['fecha_requerida']) ? null : $_POST['fecha_requerida'];
    $descripcion_sintomas_post = trim($_POST['descripcion_sintomas'] ?? '');

    // Validación básica
    if (empty($titulo_pedido_post) || empty($prioridad_post) || empty($descripcion_sintomas_post) || empty($solicitante_real_nombre_post)) {
        $mensaje = "Error: Faltan campos obligatorios (Título, Área, Prioridad, Descripción, Solicitante).";
        $alerta_tipo = 'danger';
        // Repopular
        $titulo_pedido_prev = $titulo_pedido_post;
        $selected_area = $id_area_post;
        $selected_destino = $id_destino_interno_post;
        $selected_prioridad = $prioridad_post;
        $fecha_req_prev = $fecha_requerida_post;
        $desc_sint_prev = $descripcion_sintomas_post;
        $solic_real_prev = $solicitante_real_nombre_post;
        $solic_telefono_prev = $solicitante_telefono_post;
    } else {
         // ---> OBTENER NOMBRE DEL ÁREA SELECCIONADA
         $nombre_area_seleccionada = 'N/A';
         // Como ahora cargamos por AJAX, consultamos directamente a la BD por el ID recibido
         try {
             $stmt_area_name = $pdo->prepare("SELECT nombre FROM areas WHERE id_area = :id");
             $stmt_area_name->execute([':id' => $id_area_post]);
             $nombre_area_seleccionada = $stmt_area_name->fetchColumn() ?: 'N/A';
         } catch (PDOException $e) { }
         // ---> FIN OBTENER NOMBRE ÁREA <---

        $token_firma = null;
        $estado_final = 'pendiente_encargado';

        if ($es_firma_remota) {
            $token_firma = bin2hex(random_bytes(32));
            $estado_final = 'pendiente_firma_remota';
        }

        $pdo->beginTransaction();
        try {
            
            $numero_orden_generado = generar_nuevo_numero_orden($pdo);
            
            $sql_insert = "INSERT INTO pedidos_trabajo
                        (numero_orden, titulo_pedido, id_solicitante, id_auxiliar, id_area, area_solicitante, id_destino_interno, prioridad, fecha_requerida, descripcion_sintomas, solicitante_real_nombre, solicitante_telefono, solicitante_email, token_firma, fecha_emision, estado_pedido, firma_solicitante_path)
                    VALUES
                        (:num_orden, :titulo_ped, :id_solic, :id_aux, :id_area, :area_nombre, :id_dest, :prio, :fecha_req, :descrip, :solic_real, :solic_tel, :email_solic, :token, NOW(), :estado, :firma_solic_path)";

            $stmt_insert = $pdo->prepare($sql_insert);
            
            $stmt_insert->execute([
                ':num_orden' => $numero_orden_generado,
                ':titulo_ped' => $titulo_pedido_post,
                ':id_solic' => $id_usuario_logueado,
                ':id_aux' => $id_usuario_logueado,
                ':id_area' => ($id_area_post > 0) ? $id_area_post : null,
                ':area_nombre' => $nombre_area_seleccionada,
                ':id_dest' => $id_destino_interno_post > 0 ? $id_destino_interno_post : null,
                ':prio' => $prioridad_post,
                ':fecha_req' => $fecha_requerida_post,
                ':descrip' => $descripcion_sintomas_post,
                ':solic_real' => $solicitante_real_nombre_post,
                ':solic_tel' => empty($solicitante_telefono_post) ? null : $solicitante_telefono_post,
                ':email_solic' => $es_firma_remota ? trim($solicitante_email_post . ',' . $copia_email_post, ',') : null,
                ':token' => $token_firma,
                ':estado' => $estado_final,
                ':firma_solic_path' => $ruta_firma_solicitante_guardada
            ]);

            $id_nuevo_pedido = $pdo->lastInsertId();

            // --- Enviar Notificación al/los Encargado(s) ---
            $sql_encargados = "SELECT id_usuario FROM usuarios WHERE rol = 'encargado' AND activo = 1";
            $stmt_encargados = $pdo->query($sql_encargados);
            $encargados_ids = $stmt_encargados->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($encargados_ids)) {
                $mensaje_notif = "Nuevo pedido ({$numero_orden_generado}: {$titulo_pedido_post}) del área {$nombre_area_seleccionada} requiere aprobación.";
                $url_notif = "encargado_pedidos_lista.php?highlight_pedido={$id_nuevo_pedido}";

                $sql_insert_notif = "INSERT INTO notificaciones (id_usuario_destino, mensaje, url, tipo, leida, fecha_creacion)
                                     VALUES (:id_destino, :mensaje, :url, 'pedido_nuevo', 0, NOW())";
                $stmt_notif = $pdo->prepare($sql_insert_notif);

                foreach ($encargados_ids as $id_encargado) {
                    if ($id_encargado != $id_usuario_logueado) { // Evitar auto-notificación
                        $stmt_notif->execute([
                            ':id_destino' => $id_encargado,
                            ':mensaje' => $mensaje_notif,
                            ':url' => $url_notif
                        ]);
                    }
                }
                 error_log("Notificación de pedido #{$id_nuevo_pedido} enviada a encargados.");
            } else {
                 error_log("Advertencia: No se encontraron encargados activos para notificar el pedido #{$id_nuevo_pedido}.");
            }
            // --- Fin Notificación ---
$pdo->commit();

            // --- GENERACIÓN AUTOMÁTICA DEL PDF (Sincronización Silenciosa) ---
            try {
                $es_llamada_interna = true;
                $id_pedido = $id_nuevo_pedido;
                $modo = 'inicial';
                
                // Limpiamos cualquier salida previa
                if (ob_get_level()) ob_end_clean();
                
                ob_start();
                // Usamos __DIR__ para que PHP no se pierda buscando el archivo
                include __DIR__ . '/generar_pedido_pdf.php';
                ob_end_clean();
            } catch (Throwable $e_pdf) {
                error_log("Error crítico en generación silenciosa PDF: " . $e_pdf->getMessage());
            }
            
            // --- LÓGICA DE ENVÍO DE CORREO PARA FIRMA REMOTA ---
            if ($es_firma_remota && !empty($solicitante_email_post)) {
                include_once 'envio_correo_hostinger.php';
                
                // URL pública para que el usuario firme
                $enlace_firma = "https://federicogonzalez.net/logistica/firma_externa.php?token=" . $token_firma;
                
                $asunto = "Solicitud de Firma: Pedido N° " . $numero_orden_generado;
                
                $cuerpo = "
                <div style='font-family: Arial, sans-serif; padding: 20px; color: #333; max-width: 600px; border: 1px solid #eee;'>
                    <h2 style='color: #102A57; border-bottom: 2px solid #102A57; padding-bottom: 10px;'>Solicitud de Firma Digital</h2>
                    <p>Estimado/a <strong>$solicitante_real_nombre_post</strong>,</p>
                    <p>Se ha generado un nuevo pedido de trabajo para su dependencia con el <strong>N° de Orden: $numero_orden_generado</strong>.</p>
                    <p style='background: #f9f9f9; padding: 15px; border-left: 4px solid #102A57;'>
                        <strong>Título:</strong> $titulo_pedido_post<br><br>
                        <strong>Descripción del trabajo:</strong><br>" . nl2br(htmlspecialchars($descripcion_sintomas_post)) . "
                    </p>
                    <p>Para que la Policlínica Actis pueda procesar esta solicitud, requerimos su firma de conformidad ingresando al siguiente enlace seguro:</p>
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='$enlace_firma' style='background-color: #102A57; color: white; padding: 15px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;'>FIRMAR SOLICITUD AQUÍ</a>
                    </div>
                    <p style='font-size: 12px; color: #666;'>Si no puede visualizar el botón, copie y pegue este enlace en su navegador:<br>$enlace_firma</p>
                    <hr style='border: 0; border-top: 1px solid #eee; margin-top: 30px;'>
                    <p style='font-size: 11px; color: #999; text-align: center;'>Sistema de Gestión Logística - Policlínica Actis / IOSFA</p>
                </div>";

                // Enviar el correo principal a la Jefa (con el link para firmar)
                $envio_status = enviarCorreoNativo($solicitante_email_post, $asunto, $cuerpo);
                if ($envio_status !== true) {
                    error_log("Error SMTP al enviar firma remota principal: " . $envio_status);
                }
                
                // Enviar copia informativa a Romina (si se completó el campo)
                if (!empty($copia_email_post)) {
                    $asunto_cc = "COPIA INFORMATIVA: Pedido N° " . $numero_orden_generado;
                    $cuerpo_cc = "
                    <div style='font-family: Arial, sans-serif; padding: 20px; color: #333; max-width: 600px; border: 1px solid #eee;'>
                        <h2 style='color: #102A57; border-bottom: 2px solid #102A57; padding-bottom: 10px;'>Copia Informativa del Pedido</h2>
                        <p>Le informamos que se ha generado un nuevo pedido de trabajo con el <strong>N° de Orden: $numero_orden_generado</strong>.</p>
                        <p style='background: #f9f9f9; padding: 15px; border-left: 4px solid #102A57;'>
                            <strong>Título:</strong> $titulo_pedido_post<br><br>
                            <strong>Descripción del trabajo:</strong><br>" . nl2br(htmlspecialchars($descripcion_sintomas_post)) . "
                        </p>
                        <p>El enlace para la firma de conformidad fue enviado al correo principal ($solicitante_email_post). Usted recibe este correo únicamente como copia informativa.</p>
                        <hr style='border: 0; border-top: 1px solid #eee; margin-top: 30px;'>
                        <p style='font-size: 11px; color: #999; text-align: center;'>Sistema de Gestión Logística - Policlínica Actis / IOSFA</p>
                    </div>";
                    
                    $envio_cc_status = enviarCorreoNativo($copia_email_post, $asunto_cc, $cuerpo_cc);
                    if ($envio_cc_status !== true) {
                        error_log("Error SMTP al enviar copia CC: " . $envio_cc_status);
                    }
                }
            }

            // --- INICIO MODIFICACIÓN GEMINI (v5): Establecer variables de sesión para el modal ---
            $_SESSION['pedido_creado_exito'] = true;
            $_SESSION['pedido_creado_id'] = $id_nuevo_pedido;
            $_SESSION['pedido_creado_numero'] = $numero_orden_generado;
            $_SESSION['pedido_creado_titulo'] = $titulo_pedido_post;

            // Redirigir de vuelta a la misma página para mostrar el modal
            header("Location: pedido_crear.php");
            // --- FIN MODIFICACIÓN GEMINI (v5) ---
            exit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() == '23000') {
                 $mensaje = "Error al guardar: Posible duplicado. Intente recargar la página.";
            } else {
                 $mensaje = "Error de base de datos al guardar el pedido: " . $e->getMessage();
            }
            $alerta_tipo = 'danger';
            error_log("Error DB al guardar pedido: " . $e->getMessage());
             // Repopular
            $titulo_pedido_prev = $titulo_pedido_post;
            $selected_area = $id_area_post;
            $selected_destino = $id_destino_interno_post;
            $selected_prioridad = $prioridad_post;
            $fecha_req_prev = $fecha_requerida_post;
            $desc_sint_prev = $descripcion_sintomas_post;
            $solic_real_prev = $solicitante_real_nombre_post;
            $solic_telefono_prev = $solicitante_telefono_post;
        }
    }
    
    end_post_logic:
    // Esta etiqueta permite saltar aquí si la firma falla
}

// Incluir el encabezado (navbar) después de la lógica principal
include 'navbar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Pedido de Trabajo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

    <style>
        .signature-pad-container {
            border: 2px dashed #ccc;
            border-radius: 0.375rem;
            position: relative;
            height: 200px;
            overflow: hidden; 
            background-color: #fff; 
        }
        #signature-canvas {
            width: 100%;
            height: 100%;
            cursor: crosshair; 
        }
        .signature-pad-actions {
           margin-top: 10px;
        }
        .signature-pad-container.disabled {
            opacity: 0.7;
            background-color: #f8f9fa;
            border-style: solid;
        }
    </style>
    
</head>
<body>

<div class="container mt-4 mb-5">
    <h1 class="mb-4"><i class="fas fa-file-signature me-2"></i> Crear Pedido de Trabajo</h1>

    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow-sm">
                <div class="card-body p-4">

                    <?php if ($mensaje): ?>
                        <div class="alert alert-<?php echo $alerta_tipo; ?> alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($mensaje); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php $disableForm = ($alerta_tipo === 'danger' && empty($_POST) && (empty($destinos))); ?>
                    
                    <form action="pedido_crear.php" method="POST" id="pedido-form" novalidate <?php if ($disableForm) echo ' class="pe-none opacity-50"'; ?>>

                        <div class="row mb-3 bg-light p-3 rounded border">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">N° Orden</label>
                                <input type="text" class="form-control" value="(Se generará al guardar)" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Fecha Emisión</label>
                                <input type="text" class="form-control" value="<?php echo date('d/m/Y'); ?>" readonly>
                            </div>
                        </div>

                        <h5 class="text-primary mt-4"><i class="fas fa-map-pin me-1"></i> 1. Ubicación y Prioridad</h5>
                        <hr>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="id_destino_interno" class="form-label fw-bold">1° Destino / Sede (*)</label>
                                <select class="form-select" id="id_destino_interno" name="id_destino_interno" required <?php if ($disableForm) echo ' disabled'; ?>>
                                     <option value="">-- Seleccione Destino --</option>
                                     <?php if (!empty($destinos)): ?>
                                         <?php foreach ($destinos as $destino): ?>
                                            <option value="<?php echo $destino['id_destino']; ?>" data-remota="<?php echo $destino['firma_remota']; ?>" <?php echo ($selected_destino == $destino['id_destino']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($destino['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                     <?php else: ?>
                                        <option value="" disabled>Error al cargar destinos</option>
                                     <?php endif; ?>
                                </select>
                                <small class="text-muted">La sede principal o lugar macro.</small>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="id_area" class="form-label fw-bold">2° Área Solicitante (*)</label>
                                <select class="form-select" id="id_area" name="id_area" disabled>
                                    <option value="">-- Primero elija Destino --</option>
                                </select>
                                <small class="text-muted">Se cargará al elegir destino.</small>
                            </div>
                        </div>
                        <div class="row mb-3">
                             <div class="col-md-6">
                                <label class="form-label fw-bold">Prioridad (*)</label>
                                <div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="prioridad" id="prioridad_rutina" value="rutina" <?php echo ($selected_prioridad == 'rutina') ? 'checked' : ''; ?> <?php if ($disableForm) echo ' disabled'; ?>>
                                        <label class="form-check-label" for="prioridad_rutina">Rutina</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="prioridad" id="prioridad_importante" value="importante" <?php echo ($selected_prioridad == 'importante') ? 'checked' : ''; ?> <?php if ($disableForm) echo ' disabled'; ?>>
                                        <label class="form-check-label" for="prioridad_importante">Importante</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="prioridad" id="prioridad_urgente" value="urgente" <?php echo ($selected_prioridad == 'urgente') ? 'checked' : ''; ?> <?php if ($disableForm) echo ' disabled'; ?>>
                                        <label class="form-check-label text-danger fw-bold" for="prioridad_urgente">URGENTE</label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="fecha_requerida" class="form-label">Fecha Requerida (Opcional)</label>
                                <input type="date" class="form-control" id="fecha_requerida" name="fecha_requerida" value="<?php echo htmlspecialchars($fecha_req_prev); ?>" <?php if ($disableForm) echo ' disabled'; ?>>
                                <small class="text-muted">¿Para cuándo necesita que esté resuelto?</small>
                            </div>
                        </div>


                        <h5 class="text-primary mt-4"><i class="fas fa-pencil-alt me-1"></i> 2. Detalles del Pedido</h5>
                        <hr>
                        <div class="mb-3">
                            <label for="titulo_pedido" class="form-label fw-bold">Título Resumido del Pedido (*)</label>
                            <input type="text" class="form-control" id="titulo_pedido" name="titulo_pedido"
                                   value="<?php echo htmlspecialchars($titulo_pedido_prev); ?>"
                                   placeholder="Ej: Cambiar tomacorriente Laboratorio" required maxlength="255" <?php if ($disableForm) echo ' disabled'; ?>>
                            <small class="text-muted">Un título breve que describa el trabajo principal.</small>
                        </div>
                        <div class="mb-3">
                            <label for="descripcion_sintomas" class="form-label fw-bold">Descripción de Síntomas / Pedido Completa (*)</label>
                            <textarea class="form-control" id="descripcion_sintomas" name="descripcion_sintomas" rows="5"
                                      placeholder="Sea lo más descriptivo posible. Ej: El tomacorriente N° 3 del laboratorio (pared oeste) echa chispas al enchufar equipamiento." required <?php if ($disableForm) echo ' disabled'; ?>><?php echo htmlspecialchars($desc_sint_prev); ?></textarea>
                        </div>
                        
                        <h5 class="text-primary mt-4"><i class="fas fa-signature me-1"></i> 3. Conformidad del Solicitante</h5>
                        <hr>
                        
                        <div id="contenedor-firma-presencial" class="row mb-3">
                            <div class="col-12">
                                <label class="form-label fw-bold text-success"><i class="fas fa-pen-fancy me-1"></i> Firma Presencial de conformidad (*):</label>
                                <div class="signature-pad-container" id="signature-pad-wrapper">
                                    <canvas id="signature-canvas"></canvas>
                                </div>
                                <div class="signature-pad-actions">
                                    <button type="button" class="btn btn-sm btn-outline-danger" id="clear-signature-button" title="Limpiar Firma">
                                        <i class="fas fa-times"></i> Limpiar
                                    </button>
                                    <button type="button" class="btn btn-sm btn-success" id="confirm-signature-button" title="Confirmar Firma">
                                        <i class="fas fa-check"></i> Confirmar Firma
                                    </button>
                                </div>
                                <small class="text-muted">El solicitante está presente y firma en el dispositivo.</small>
                            </div>
                        </div>

                        <div id="contenedor-firma-remota" class="row mb-3" style="display: none;">
                            <div class="col-12">
                                <div class="alert alert-warning border-warning shadow-sm">
                                    <h6 class="alert-heading fw-bold"><i class="fas fa-paper-plane me-2"></i> Firma Remota Activada</h6>
                                    <p class="small mb-2">Este destino requiere una firma externa. Al enviar el pedido, se enviará un enlace al correo del solicitante para su firma digital.</p>
                                    <hr>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="email_solicitante_externo" class="form-label fw-bold">1. Correo de quien FIRMA (Ej: Jefe) (*)</label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-white border-warning text-warning"><i class="fas fa-envelope"></i></span>
                                                <input type="text" class="form-control border-warning" id="email_solicitante_externo" name="email_solicitante_externo" placeholder="correo.jefe@ejemplo.com">
                                            </div>
                                            <small class="text-muted" style="font-size: 0.75rem;">Recibirá el enlace para firmar.</small>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="email_copia_externo" class="form-label fw-bold">2. Enviar copia CC a (Opcional)</label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-white border-secondary text-secondary"><i class="fas fa-copy"></i></span>
                                                <input type="text" class="form-control border-secondary" id="email_copia_externo" name="email_copia_externo" placeholder="correo.asistente@ejemplo.com">
                                            </div>
                                            <small class="text-muted" style="font-size: 0.75rem;">Solo recibirá un aviso informativo.</small>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-12 text-end">
                                            <button type="button" class="btn btn-warning fw-bold px-5 shadow-sm" id="confirmar-remota-button">
                                                <i class="fas fa-check-circle me-1"></i> Confirmar Correos
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <input type="hidden" name="firma_solicitante_base64" id="firma_solicitante_base64">

                        <div id="aclaracion-container" style="display: none;">
                            <h5 class="text-primary mt-4"><i class="fas fa-user-edit me-1"></i> 4. Datos del Solicitante</h5>
                            <hr>
                            <div class="row">
                                <div class="col-md-7">
                                    <label for="solicitante_real_nombre" class="form-label fw-bold">Aclaración Solicitante (*)</label>
                                    <input type="text" class="form-control form-control-lg" id="solicitante_real_nombre" name="solicitante_real_nombre"
                                           value="<?php echo htmlspecialchars($solic_real_prev); ?>"
                                           placeholder="Aclaración de quien firmó" required <?php if ($disableForm) echo ' disabled'; ?>>
                                    <small class="text-muted">Debe coincidir con la persona que firmó.</small>
                                </div>
                                <div class="col-md-5">
                                    <label for="solicitante_telefono" class="form-label fw-bold">Teléfono (WhatsApp) (Opcional)</label>
                                    <input type="tel" class="form-control form-control-lg" id="solicitante_telefono" name="solicitante_telefono"
                                           value="<?php echo htmlspecialchars($solic_telefono_prev); ?>"
                                           placeholder="Ej: 54911..." <?php if ($disableForm) echo ' disabled'; ?>>
                                    <small class="text-muted">Incluir cód. de país (Ej: 54) y área.</small>
                                </div>
                            </div>
                        </div>
                        
                        <div id="registrado-por-container" style="display: none;">
                            <h5 class="text-primary mt-4"><i class="fas fa-user-check me-1"></i> 5. Registrado por (Logística)</h5>
                            <hr>
                             <div class="row align-items-center bg-light p-3 rounded border mb-4">
                                 <div class="col-md-8">
                                     <p class="mb-1"><strong>Usuario Logística:</strong></p>
                                     <h4><?php echo htmlspecialchars($nombre_usuario_logueado); ?></h4>
                                     <small class="text-muted">ID: <?php echo $id_usuario_logueado; ?></small>
                                 </div>
                                 <div class="col-md-4 text-center">
                                     <label class="form-label fw-bold">Firma:</label>
                                     <div style="height: 70px; border-bottom: 1px solid #ccc; display: flex; align-items: center; justify-content: center; background-color: #fff; border-radius: .25rem;">
                                         <?php if ($firma_usuario_logueado_path): ?>
                                             <img src="uploads/firmas/<?php echo htmlspecialchars($firma_usuario_logueado_path); ?>" alt="Firma" style="max-height: 60px; max-width: 100%;">
                                         <?php else: ?>
                                             <span class="text-muted fst-italic">(Sin firma registrada)</span>
                                         <?php endif; ?>
                                     </div>
                                     <p class="small text-muted mt-1">Aclaración</p>
                                 </div>
                             </div>
                         </div>
                        
                        <div id="enviar-container" class="text-end mt-4" style="display: none;">
                            <button type="button" id="btn-enviar-final" class="btn btn-primary btn-lg" <?php if ($disableForm) echo ' disabled'; ?>>
                                <i class="fas fa-paper-plane me-2"></i> Enviar Pedido
                            </button>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="pedidoSuccessModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-success border-5">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i> ¡Solicitud Creada Exitosamente!</h5>
      </div>
      <div class="modal-body">
        <p class="lead">La solicitud de trabajo ha sido registrada con éxito.</p>
        <div class="alert alert-info">
            <strong>N° Orden:</strong> <span class="fw-bold fs-5" id="modalPedidoNumero"></span><br>
            <strong>Título:</strong> <span id="modalPedidoTitulo"></span>
        </div>
        <p>El pedido ha sido enviado al Encargado para su revisión y aprobación.</p>
      </div>
      <div class="modal-footer justify-content-between">
        <a href="dashboard.php" class="btn btn-primary">
            <i class="fas fa-home me-1"></i> Ir al Inicio
        </a>
        <a href="#" id="modalPdfButton" target="_blank" class="btn btn-success">
            <i class="fas fa-file-pdf me-1"></i> Ver Solicitud Creada
        </a>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="notificationToastContainer" style="z-index: 1080;"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/signature_pad/1.5.3/signature_pad.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Verificación de seguridad para jQuery
        if (typeof $ === 'undefined') return;

        // 1. Inicializar Selectores
        const selectDestino = $('#id_destino_interno').select2({ theme: 'bootstrap-5', placeholder: 'Buscar Destino...' });
        const selectArea = $('#id_area').select2({ theme: 'bootstrap-5', placeholder: 'Seleccione Área...' });

        // 2. Lógica de Cambio de Destino
        selectDestino.on('change', function() {
            const $opt = $(this).find(':selected');
            const esRemoto = $opt.attr('data-remota') == "1";
            const idDestino = $(this).val();

            if (esRemoto) {
                $('#contenedor-firma-presencial').hide();
                $('#contenedor-firma-remota').fadeIn();
                $('#aclaracion-container, #registrado-por-container, #enviar-container').hide();
            } else {
                $('#contenedor-firma-remota').hide();
                $('#contenedor-firma-presencial').fadeIn();
            }

            // Carga de Áreas AJAX
            selectArea.empty().append('<option value="">Cargando...</option>').prop('disabled', true);
            if (idDestino) {
                $.ajax({
                    url: 'ajax_obtener_areas.php',
                    type: 'GET',
                    data: { id_destino: idDestino },
                    dataType: 'json',
                    success: function(areas) {
                        selectArea.empty();
                        if (areas.length > 0) {
                            selectArea.append('<option value="">-- Seleccione Área --</option>');
                            const areaPrevia = "<?php echo $selected_area; ?>";
                            $.each(areas, function(i, area) {
                                let sel = (area.id_area == areaPrevia) ? ' selected' : '';
                                selectArea.append('<option value="' + area.id_area + '"' + sel + '>' + area.nombre + '</option>');
                            });
                            selectArea.prop('disabled', false);
                        } else {
                            selectArea.append('<option value="">No hay áreas en este destino</option>');
                            selectArea.prop('disabled', true);
                        }
                    }
                });
            }
        });

        // 3. Botón Confirmar Datos (Modo Remoto)
        $('#confirmar-remota-button').on('click', function() {
            const email = $('#email_solicitante_externo').val().trim();
            if (email === '' || !email.includes('@')) {
                Swal.fire({ icon: 'warning', title: 'Atención', text: 'Ingrese un correo válido.', confirmButtonColor: '#102A57' });
                return;
            }
            $('#aclaracion-container, #registrado-por-container, #enviar-container').fadeIn();
            $(this).prop('disabled', true).html('<i class="fas fa-check"></i> Datos Listos');
            $('#solicitante_real_nombre').focus();
        });

        // 4. Inicializar Firma Presencial
        const canvas = document.getElementById('signature-canvas');
        if (canvas) {
            const signaturePad = new SignaturePad(canvas);
            $('#clear-signature-button').on('click', function(e) { e.preventDefault(); signaturePad.clear(); });
            $('#confirm-signature-button').on('click', function(e) {
                e.preventDefault();
                if (signaturePad.isEmpty()) {
                    Swal.fire({ icon: 'warning', title: 'Atención', text: 'El solicitante debe firmar.', confirmButtonColor: '#102A57' });
                    return;
                }
                document.getElementById('firma_solicitante_base64').value = signaturePad.toDataURL();
                $('#aclaracion-container, #registrado-por-container, #enviar-container').fadeIn();
                signaturePad.off();
                $('#signature-pad-wrapper').addClass('disabled');
                $(this).hide();
                $('#clear-signature-button').hide();
            });
        }

        // 5. ENVÍO FINAL (Botón Enviar)
        $(document).on('click', '#btn-enviar-final', function(e) {
            e.preventDefault();
            const esRemoto = $('#id_destino_interno').find(':selected').attr('data-remota') == "1";
            const area = $('#id_area').val();
            const aclaracion = $('#solicitante_real_nombre').val().trim();
            const email = $('#email_solicitante_externo').val().trim();
            const firma = $('#firma_solicitante_base64').val();

            if (esRemoto && (email === '' || !email.includes('@'))) {
                return Swal.fire({ icon: 'error', title: 'Faltan Datos', text: 'Debe ingresar un correo válido.', confirmButtonColor: '#102A57' });
            }
            if (!esRemoto && (firma === '' || firma === null)) {
                return Swal.fire({ icon: 'error', title: 'Faltan Datos', text: 'Debe firmar y confirmar la firma.', confirmButtonColor: '#102A57' });
            }
            // Solo exigir área si el selector está habilitado (tiene opciones)
            if ($('#id_area').prop('disabled') === false && (!area || area == "0" || area == "")) {
                return Swal.fire({ icon: 'error', title: 'Faltan Datos', text: 'Seleccione el Área solicitante.', confirmButtonColor: '#102A57' });
            }
            if (aclaracion === '') {
                return Swal.fire({ icon: 'error', title: 'Faltan Datos', text: 'Complete la aclaración.', confirmButtonColor: '#102A57' });
            }

            $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i> Enviando...');
            $('#pedido-form')[0].submit();
        });

        // Persistencia inicial
        if (selectDestino.val() !== "") { selectDestino.trigger('change'); }

        // Modal de éxito
        <?php if ($show_success_modal): ?>
            const successModal = new bootstrap.Modal(document.getElementById('pedidoSuccessModal'));
            document.getElementById('modalPedidoNumero').textContent = <?php echo json_encode($nuevo_pedido_numero_modal); ?>;
            document.getElementById('modalPedidoTitulo').textContent = <?php echo json_encode($nuevo_pedido_titulo_modal); ?>;
            document.getElementById('modalPdfButton').href = `generar_pedido_pdf.php?id=<?php echo $nuevo_pedido_id_modal; ?>`;
            successModal.show();
        <?php endif; ?>
    });
</script>
<?php include 'footer.php'; ?>
</body>
</html>