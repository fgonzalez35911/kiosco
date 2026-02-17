<?php
// perfil.php - VERSIÓN RESTAURADA AL 100% CON DISEÑO INSTITUCIONAL
session_start();

// 1. TU BUSCADOR DE DB ORIGINAL (INTACTO)
$rutas_db = [__DIR__ . '/db.php', __DIR__ . '/includes/db.php', 'db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$id_usuario = $_SESSION['usuario_id'];
// Mantenemos tu lógica de roles original
$rol_usuario = (isset($_SESSION['rol']) && is_numeric($_SESSION['rol'])) ? $_SESSION['rol'] : 3;
$mensaje = '';

// 2. PROCESAR GUARDADO DE DATOS (RESTAURADO SIN CAMBIOS)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_datos'])) {
    $nombre = trim($_POST['nombre']);
    $email = $_POST['email'] ?? '';
    $whatsapp = $_POST['whatsapp'] ?? '';
    
    if(isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] == 0) {
        $carpeta = "uploads/"; 
        if (!file_exists($carpeta)) { mkdir($carpeta, 0777, true); }
        $ext = pathinfo($_FILES['foto_perfil']['name'], PATHINFO_EXTENSION);
        $nombre_foto = "user_" . $id_usuario . "_" . time() . "." . $ext;
        
        if(move_uploaded_file($_FILES['foto_perfil']['tmp_name'], $carpeta . $nombre_foto)) {
            $sql = "UPDATE usuarios SET nombre_completo=?, email=?, whatsapp=?, foto_perfil=? WHERE id=?";
            $conexion->prepare($sql)->execute([$nombre, $email, $whatsapp, $nombre_foto, $id_usuario]);
            $_SESSION['foto_perfil'] = $nombre_foto; 
        }
    } else {
        $sql = "UPDATE usuarios SET nombre_completo=?, email=?, whatsapp=? WHERE id=?";
        $conexion->prepare($sql)->execute([$nombre, $email, $whatsapp, $id_usuario]);
    }
    $_SESSION['nombre'] = $nombre;
    header("Location: perfil.php?msg=datos_ok"); exit;
}

// 3. CAMBIAR CONTRASEÑA (RESTAURADO SIN CAMBIOS)
if (isset($_POST['btn_pass'])) {
    $actual = $_POST['pass_actual'];
    $nueva = $_POST['pass_nueva'];
    $stmt = $conexion->prepare("SELECT password FROM usuarios WHERE id = ?");
    $stmt->execute([$id_usuario]);
    $pass_hash = $stmt->fetchColumn();

    if (password_verify($actual, $pass_hash)) {
        $hash = password_hash($nueva, PASSWORD_DEFAULT);
        $conexion->prepare("UPDATE usuarios SET password = ? WHERE id = ?")->execute([$hash, $id_usuario]);
        header("Location: perfil.php?msg=pass_ok"); exit;
    } else { $mensaje = 'error_pass'; }
}

// 4. OBTENER DATOS REALES (DINÁMICO CON JOIN PARA EL RANGO)
$stmt = $conexion->prepare("SELECT u.*, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id WHERE u.id = ?");
$stmt->execute([$id_usuario]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

// Lógica de Foto original
$foto_url = "img/no-image.png";
if (!empty($u['foto_perfil'])) {
    if (file_exists("uploads/" . $u['foto_perfil'])) $foto_url = "uploads/" . $u['foto_perfil'];
    elseif (file_exists("img/usuarios/" . $u['foto_perfil'])) $foto_url = "img/usuarios/" . $u['foto_perfil'];
}

// Lógica de Firma original
$ruta_firma = ($u['id_rol'] <= 2) ? "img/firmas/firma_admin.png" : "img/firmas/usuario_{$id_usuario}.png";
$tiene_firma = file_exists($ruta_firma);
$firma_img = $tiene_firma ? $ruta_firma . "?t=" . time() : "";

// KPIs para los widgets (Ventas mes y Movimientos hoy)
$mis_ventas_mes = $conexion->query("SELECT SUM(total) FROM ventas WHERE id_usuario = $id_usuario AND MONTH(fecha) = MONTH(CURRENT_DATE()) AND estado = 'completada'")->fetchColumn() ?: 0;
$mis_movs_hoy = $conexion->query("SELECT COUNT(*) FROM auditoria WHERE id_usuario = $id_usuario AND DATE(fecha) = CURRENT_DATE()")->fetchColumn();
?>

<?php include 'includes/layout_header.php'; ?>

<style>
    /* CSS ADAPTADO DE COMBOS.PHP */
    .header-blue {
        background-color: #102A57; color: white; padding: 40px 0; margin-bottom: 30px;
        border-radius: 0 0 30px 30px; box-shadow: 0 4px 15px rgba(16, 42, 87, 0.25);
        position: relative; overflow: hidden;
    }
    .bg-icon-large {
        position: absolute; top: 50%; right: 20px; transform: translateY(-50%) rotate(-10deg);
        font-size: 10rem; opacity: 0.1; color: white; pointer-events: none;
    }
    .stat-card {
        border: none; border-radius: 15px; padding: 15px 20px;
        box-shadow: 0 4px 10px rgba(0,0,0,0.05); background: white; height: 100%;
        display: flex; align-items: center; justify-content: space-between; transition: 0.2s;
    }
    .stat-card:hover { transform: translateY(-3px); }
    .card-custom { border: none; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); background: white; margin-bottom: 20px; }
    .avatar-preview { width: 130px; height: 130px; object-fit: cover; border-radius: 50%; border: 4px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.1); cursor: pointer; }
    
    #modalFirma { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.95); }
    .canvas-container { position: relative; width: 95%; max-width: 600px; height: 40vh; margin: 20vh auto; background: white; border-radius: 15px; overflow: hidden; }
    canvas { width: 100%; height: 100%; touch-action: none; cursor: crosshair; }
    .btn-cerrar-modal { position: absolute; top: 15px; right: 15px; background: #dc3545; color: white; border-radius: 50%; width: 40px; height: 40px; border: none; z-index: 20; display: flex; align-items: center; justify-content: center; }
</style>

<div class="header-blue">
    <i class="bi bi-person-circle bg-icon-large"></i>
    <div class="container position-relative">
        <h2 class="fw-bold mb-4">Configuración de Perfil</h2>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="stat-card">
                    <div><small class="text-muted fw-bold">RANGO</small><h4 class="mb-0 fw-bold text-dark"><?php echo $u['nombre_rol']; ?></h4></div>
                    <i class="bi bi-shield-check fs-1 text-primary opacity-50"></i>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div><small class="text-muted fw-bold">VENTAS MES</small><h4 class="mb-0 fw-bold text-success">$<?php echo number_format($mis_ventas_mes, 0, ',', '.'); ?></h4></div>
                    <i class="bi bi-cash-stack fs-1 text-success opacity-50"></i>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div><small class="text-muted fw-bold">ACTIVIDAD HOY</small><h4 class="mb-0 fw-bold text-info"><?php echo $mis_movs_hoy; ?></h4></div>
                    <i class="bi bi-activity fs-1 text-info opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <div class="row g-4">
        <div class="col-md-6">
            <div class="card card-custom h-100">
                <div class="card-header bg-primary text-white py-3 fw-bold">
                    <i class="bi bi-person-vcard me-2"></i> Mis Datos de Usuario
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="text-center mb-4">
                            <div class="position-relative d-inline-block">
                                <img src="<?php echo $foto_url; ?>" id="imgPreview" class="avatar-preview" onclick="document.getElementById('inputFoto').click()">
                                <div class="position-absolute bottom-0 end-0 bg-white rounded-circle p-1 shadow-sm" style="width: 35px; height: 35px;">
                                    <i class="bi bi-camera-fill text-primary" style="font-size: 1.2rem; line-height: 25px; cursor: pointer;" onclick="document.getElementById('inputFoto').click()"></i>
                                </div>
                            </div>
                            <input type="file" name="foto_perfil" id="inputFoto" class="d-none" accept="image/*" onchange="previewImage(event)">
                        </div>

                        <div class="row g-2 mb-3 text-start">
                            <div class="col-md-6">
                                <label class="fw-bold small text-muted">Nombre Completo</label>
                                <input type="text" name="nombre" class="form-control" value="<?php echo htmlspecialchars($u['nombre_completo']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold small text-muted">Usuario (Login)</label>
                                <input type="text" class="form-control bg-light text-muted" value="<?php echo htmlspecialchars($u['usuario']); ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold small text-muted">Email</label>
                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($u['email'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold small text-muted">WhatsApp</label>
                                <input type="text" name="whatsapp" class="form-control" value="<?php echo htmlspecialchars($u['whatsapp'] ?? ''); ?>">
                            </div>
                        </div>
                        <button type="submit" name="btn_datos" class="btn btn-primary w-100 fw-bold py-2 shadow-sm rounded-pill">GUARDAR CAMBIOS</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card card-custom mb-4">
                <div class="card-header bg-dark text-white py-3 fw-bold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-pen me-2"></i> Firma Digital</span>
                    <span class="badge <?php echo $tiene_firma ? 'bg-success' : 'bg-warning text-dark'; ?> rounded-pill">
                        <?php echo $tiene_firma ? 'Activa' : 'Faltante'; ?>
                    </span>
                </div>
                <div class="card-body text-center">
                    <div class="mb-3 p-3 border rounded bg-light d-flex align-items-center justify-content-center" style="min-height: 120px;">
                        <?php if($tiene_firma): ?>
                            <img src="<?php echo $firma_img; ?>" class="img-fluid" style="max-height: 100px;">
                        <?php else: ?>
                            <p class="text-muted small mb-0">No hay firma registrada.</p>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-outline-dark w-100 py-2 fw-bold rounded-pill" onclick="abrirModalFirma()">
                        <i class="bi bi-pencil-square me-2"></i>ACTUALIZAR FIRMA
                    </button>
                </div>
            </div>

            <div class="card card-custom border-top border-4 border-danger">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="fw-bold mb-0 text-danger"><i class="bi bi-shield-lock me-2"></i> Seguridad</h5>
                </div>
                <div class="card-body">
                    <?php if($mensaje == 'error_pass') echo "<div class='alert alert-danger py-2 small fw-bold mb-3'>Contraseña actual incorrecta.</div>"; ?>
                    <form method="POST">
                        <div class="mb-2">
                            <label class="small fw-bold text-muted">Contraseña Actual</label>
                            <input type="password" name="pass_actual" class="form-control" required>
                        </div>
                        <div class="row g-2 align-items-end">
                            <div class="col-8">
                                <label class="small fw-bold text-muted">Nueva Contraseña</label>
                                <input type="password" name="pass_nueva" class="form-control" required placeholder="Mínimo 4 caracteres">
                            </div>
                            <div class="col-4">
                                <button type="submit" name="btn_pass" class="btn btn-danger w-100 fw-bold rounded-pill">CAMBIAR</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="modalFirma">
    <div class="canvas-container">
        <button class="btn-cerrar-modal" onclick="cerrarModalFirma()"><i class="bi bi-x-lg"></i></button>
        <canvas id="signature-pad"></canvas>
        <div style="position: absolute; top:50%; width:100%; border-bottom: 2px dashed #ccc; pointer-events:none;"></div>
        <div class="p-3 bg-white border-top d-flex justify-content-center gap-2" style="position: absolute; bottom: 0; width: 100%;">
            <button class="btn btn-outline-secondary px-4 fw-bold" onclick="signaturePad.clear()">BORRAR</button>
            <button class="btn btn-success px-5 fw-bold shadow" onclick="guardarFirma()">GUARDAR</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
<script>
    function previewImage(event) {
        var reader = new FileReader();
        reader.onload = function(){ document.getElementById('imgPreview').src = reader.result; }
        reader.readAsDataURL(event.target.files[0]);
    }

    // LÓGICA DE FIRMA ORIGINAL
    var modal = document.getElementById('modalFirma');
    var canvas = document.getElementById('signature-pad');
    var signaturePad = new SignaturePad(canvas, { backgroundColor: 'rgba(255,255,255,0)', penColor: 'rgb(0, 0, 0)' });

    function abrirModalFirma() {
        modal.style.display = 'block';
        setTimeout(() => {
            var ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext("2d").scale(ratio, ratio);
            signaturePad.clear();
        }, 150);
    }
    function cerrarModalFirma() { modal.style.display = 'none'; }

    function guardarFirma() {
        if (signaturePad.isEmpty()) return Swal.fire('Atención', 'Debes firmar antes de guardar.', 'warning');
        var dataURL = signaturePad.toDataURL('image/png');
        
        // Mantenemos tu doble ruta original
        fetch('guardar_firma.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'imgBase64=' + encodeURIComponent(dataURL)
        })
        .then(r => r.json())
        .then(data => {
            if(data.status === 'success') {
                cerrarModalFirma();
                Swal.fire('Guardado', 'Firma actualizada.', 'success').then(() => location.reload());
            } else { throw new Error(); }
        })
        .catch(() => {
            fetch('acciones/guardar_firma.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'imgBase64=' + encodeURIComponent(dataURL)
            }).then(r=>r.json()).then(d=>{ if(d.status==='success'){ location.reload(); } });
        });
    }

    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('msg') === 'datos_ok') Swal.fire('Éxito', 'Datos actualizados correctamente.', 'success');
    if(urlParams.get('msg') === 'pass_ok') Swal.fire('Éxito', 'Contraseña cambiada.', 'success');
</script>

<?php include 'includes/layout_footer.php'; ?>
