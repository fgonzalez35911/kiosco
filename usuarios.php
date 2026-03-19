<?php
// usuarios.php - GESTIÓN DE EQUIPO (ESTÁNDAR VANGUARD PRO - REPARADO 100%)
session_start();

ini_set('display_errors', 0); 
error_reporting(E_ALL);

$rutas_db = [__DIR__ . '/db.php', __DIR__ . '/includes/db.php', 'db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- MOTOR AJAX: COMPROBADOR DE USUARIOS EN TIEMPO REAL ---
if (isset($_GET['action']) && $_GET['action'] == 'check_user') {
    $base = preg_replace('/[^a-z0-9]/', '', strtolower($_GET['u']));
    $user_final = $base;
    $count = 1;
    while (true) {
        $stmt = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = ?");
        $stmt->execute([$user_final]);
        if ($stmt->rowCount() == 0) break;
        $user_final = $base . $count;
        $count++;
    }
    echo $user_final;
    exit;
}

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$rol_usuario_actual = $_SESSION['rol'] ?? 3;
$es_admin = ($rol_usuario_actual <= 2);

if (!$es_admin && !in_array('config_gestionar_usuarios', $permisos)) {
    header("Location: dashboard.php"); exit; 
}

// CONFIGURACIÓN DEL LOCAL
$conf = $conexion->query("SELECT nombre_negocio, direccion_local, cuit, logo_url, color_barra_nav, telefono_whatsapp FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);

// FIRMA AUTORIDAD (Dinámico: El usuario con el rol de mayor jerarquía)
$stmtFirma = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id ORDER BY r.id ASC LIMIT 1");
$dueno_firma = $stmtFirma ? $stmtFirma->fetch(PDO::FETCH_ASSOC) : false;

// --- 1. PROCESAR ALTA ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_usuario'])) {
    if (!$es_admin && !in_array('config_gestionar_usuarios', $permisos)) die("Sin permiso para crear.");
    $nombre = trim($_POST['nombre']); $user = trim($_POST['usuario']);
    $email = trim($_POST['email']); $whatsapp = trim($_POST['whatsapp']);
    $pass = $_POST['password']; $rol = $_POST['id_rol'];

    $check = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = ?");
    $check->execute([$user]);
    if ($check->rowCount() > 0) { header("Location: usuarios.php?err=duplicado"); exit; } 
    else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        if ($conexion->prepare("INSERT INTO usuarios (nombre_completo, usuario, email, whatsapp, password, id_rol, activo) VALUES (?, ?, ?, ?, ?, ?, 1)")->execute([$nombre, $user, $email, $whatsapp, $hash, $rol])) {
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'USUARIO_NUEVO', ?, NOW())")->execute([$_SESSION['usuario_id'], "Usuario Creado: $user"]);
            header("Location: usuarios.php?msg=creado"); exit;
        }
    }
}

// --- 2. PROCESAR EDICIÓN ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['editar_usuario'])) {
    if (!$es_admin && !in_array('config_gestionar_usuarios', $permisos)) die("Sin permiso para editar.");
    $id_upd = intval($_POST['id_usuario_edit']); $nombre = trim($_POST['nombre']);
    $email = trim($_POST['email']); $whatsapp = trim($_POST['whatsapp']);
    $rol = intval($_POST['id_rol']); $estado = intval($_POST['activo'] ?? 1);

    $sql = "UPDATE usuarios SET nombre_completo=?, email=?, whatsapp=?, id_rol=?, activo=? WHERE id=?";
    $params = [$nombre, $email, $whatsapp, $rol, $estado, $id_upd];
    
    if(!empty($_POST['password'])) {
        $sql = "UPDATE usuarios SET nombre_completo=?, email=?, whatsapp=?, id_rol=?, activo=?, password=? WHERE id=?";
        $params = [$nombre, $email, $whatsapp, $rol, $estado, password_hash($_POST['password'], PASSWORD_DEFAULT), $id_upd];
    }
    $conexion->prepare($sql)->execute($params);
    $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'USUARIO_EDITADO', ?, NOW())")->execute([$_SESSION['usuario_id'], "Usuario Editado ID $id_upd"]);
    header("Location: usuarios.php?msg=editado"); exit;
}

// --- 3. PROCESAR ELIMINACIÓN ---
if (isset($_GET['borrar'])) {
    if (!$es_admin && !in_array('config_gestionar_usuarios', $permisos)) die("Sin permiso para eliminar.");
    $id_borrar = intval($_GET['borrar']);
    if ($id_borrar == $_SESSION['usuario_id'] || $id_borrar == 1) { header("Location: usuarios.php?err=" . ($id_borrar == 1 ? "admin" : "self")); exit; }
    
    try {
        $stmt = $conexion->prepare("DELETE FROM usuarios WHERE id = ?");
        $resultado = $stmt->execute([$id_borrar]);
        
        if ($resultado && $stmt->rowCount() > 0) {
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'USUARIO_ELIMINADO', ?, NOW())")->execute([$_SESSION['usuario_id'], "Usuario ID $id_borrar Eliminado."]);
            header("Location: usuarios.php?msg=eliminado"); exit;
        } else {
            header("Location: usuarios.php?err=relacion"); exit;
        }
    } catch (Throwable $e) {
        header("Location: usuarios.php?err=relacion"); exit;
    }
}

// --- 4. FILTROS Y DATOS PARA LA TABLA ---
$buscar = trim($_GET['buscar'] ?? '');
$f_rol = $_GET['f_rol'] ?? '';
$f_estado = $_GET['f_estado'] ?? '';

$sql = "SELECT u.*, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id WHERE 1=1";
$params = [];
if (!empty($buscar)) { $sql .= " AND (u.nombre_completo LIKE ? OR u.usuario LIKE ?)"; $params[] = "%$buscar%"; $params[] = "%$buscar%"; }
if ($f_rol !== '') { $sql .= " AND u.id_rol = ?"; $params[] = $f_rol; }
if ($f_estado !== '') { $sql .= " AND u.activo = ?"; $params[] = $f_estado; }
$sql .= " ORDER BY u.id_rol ASC, u.nombre_completo ASC";

$stmt = $conexion->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

$roles_db = $conexion->query("SELECT * FROM roles")->fetchAll(PDO::FETCH_ASSOC);

// KPIs Globales
$total_users = $conexion->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
$total_activos = $conexion->query("SELECT COUNT(*) FROM usuarios WHERE activo = 1")->fetchColumn();
$total_roles = count($roles_db);

$nombre_negocio = strtoupper($conf['nombre_negocio'] ?? 'EMPRESA');
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=0&data=" . urlencode((isset($_SERVER['HTTPS'])?"https":"http")."://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]");

include 'includes/layout_header.php'; 

// --- BANNER DINÁMICO (SÓLO 1 BOTÓN DE REPORTE) ---
$titulo = "Gestión de Equipo";
$subtitulo = "Administración de accesos y perfiles del plantel.";
$icono_bg = "bi-people";

$botones = [];
if($es_admin || in_array('descargar_pdf', $permisos)) {
    $botones[] = ['texto' => 'REPORTE PDF', 'link' => 'javascript:abrirModalDescargas()', 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-4 shadow-sm'];
}

$widgets = [
    ['label' => 'Total Equipo', 'valor' => $total_users, 'icono' => 'bi-person-badge', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Activos', 'valor' => $total_activos, 'icono' => 'bi-check-all', 'border' => 'border-success', 'icon_bg' => 'bg-success bg-opacity-20'],
    ['label' => 'Roles Creados', 'valor' => $total_roles, 'icono' => 'bi-diagram-3', 'border' => 'border-info', 'icon_bg' => 'bg-info bg-opacity-20']
];

include 'includes/componente_banner.php'; 
?>

<style>
    /* CONGELAR PRIMERA COLUMNA EN MÓVILES */
    @media (max-width: 768px) {
        .tabla-movil-ajustada { border-collapse: separate; border-spacing: 0; }
        .tabla-movil-ajustada td, .tabla-movil-ajustada th { padding: 0.6rem 0.4rem !important; font-size: 0.75rem !important; white-space: nowrap; }
        .tabla-movil-ajustada th:first-child, .tabla-movil-ajustada td:first-child {
            position: sticky; left: 0; background-color: #fff; z-index: 2; border-right: 2px solid #e9ecef; box-shadow: 2px 0 5px rgba(0,0,0,0.05);
        }
        .tabla-movil-ajustada thead th:first-child { background-color: #f8f9fa; z-index: 3; }
        .table-hover>tbody>tr:hover>td:first-child { background-color: #f2f2f2; }
    }
</style>

<div class="container-fluid container-md pb-5 mt-n4 px-2 px-md-3" style="position: relative; z-index: 20;">
    
    <?php if(isset($_GET['err'])): ?>
        <div class='alert alert-warning border-0 shadow-sm fw-bold mb-3 small rounded-4'><i class='bi bi-exclamation-triangle me-2'></i>
            <?php 
                if($_GET['err'] == 'self') echo "No puedes eliminar tu propia cuenta."; 
                elseif($_GET['err'] == 'admin') echo "El SuperAdmin no puede ser eliminado.";
                elseif($_GET['err'] == 'duplicado') echo "Ese nombre de usuario ya existe en el sistema. Elegí otro.";
                elseif($_GET['err'] == 'relacion') echo "No se puede eliminar el usuario porque tiene historial en el sistema. Te recomendamos editarlo y ponerlo como Inactivo.";
            ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border-left: 5px solid #ff9800 !important;">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="row g-2 align-items-center mb-0">
                <input type="hidden" name="f_rol" value="<?php echo htmlspecialchars($f_rol); ?>">
                <input type="hidden" name="f_estado" value="<?php echo htmlspecialchars($f_estado); ?>">
                <div class="col-md-8 col-12 text-center text-md-start">
                    <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador de Personal</h6>
                    <p class="small mb-0 opacity-75 d-none d-md-block">Busque por nombre o alias de usuario (@).</p>
                </div>
                <div class="col-md-4 col-12 text-end mt-2 mt-md-0">
                    <div class="input-group input-group-sm">
                        <input type="text" name="buscar" class="form-control border-0 fw-bold shadow-none" placeholder="Nombre o usuario..." value="<?php echo htmlspecialchars($buscar); ?>">
                        <button class="btn btn-dark px-3 shadow-none border-0" type="submit"><i class="bi bi-arrow-right-circle-fill"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="alert py-2 small mb-3 text-center fw-bold border-0 shadow-sm rounded-3" style="background-color: #e9f2ff; color: #102A57;">
        <i class="bi bi-hand-index-thumb-fill me-1"></i> Toca o haz clic en un registro para ver su Ficha de Personal
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3">
                <form method="GET" class="d-flex flex-wrap gap-2 align-items-end flex-grow-1 w-100">
                    <input type="hidden" name="buscar" value="<?php echo htmlspecialchars($buscar); ?>">
                    <div style="min-width: 140px; flex: 1;">
                        <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Rol</label>
                        <select name="f_rol" class="form-select form-select-sm border-light-subtle fw-bold">
                            <option value="">Todos los rangos</option>
                            <?php foreach($roles_db as $r): ?>
                                <option value="<?php echo $r['id']; ?>" <?php echo ($f_rol == $r['id']) ? 'selected' : ''; ?>><?php echo strtoupper($r['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="min-width: 140px; flex: 1;">
                        <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Estado</label>
                        <select name="f_estado" class="form-select form-select-sm border-light-subtle fw-bold">
                            <option value="">Todos</option>
                            <option value="1" <?php echo ($f_estado === '1')?'selected':''; ?>>Activos</option>
                            <option value="0" <?php echo ($f_estado === '0')?'selected':''; ?>>Inactivos</option>
                        </select>
                    </div>
                    <div class="d-flex gap-2 w-100 w-sm-auto mt-2 mt-sm-0">
                        <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3 flex-fill flex-sm-grow-0" style="height: 31px;">
                            <i class="bi bi-funnel-fill me-1"></i> FILTRAR
                        </button>
                        <a href="usuarios.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3 flex-fill flex-sm-grow-0 text-center" style="height: 31px; line-height: 1.5;">
                            <i class="bi bi-trash3-fill me-1"></i> LIMPIAR
                        </a>
                    </div>
                </form>

                <div class="d-flex gap-2 w-100 w-lg-auto justify-content-end">
                    <a href="roles.php" class="btn btn-outline-dark fw-bold rounded-pill px-3 shadow-sm flex-fill flex-lg-grow-0 text-center">
                        <i class="bi bi-shield-lock me-1"></i> ROLES
                    </a>
                    <?php if($es_admin || in_array('config_gestionar_usuarios', $permisos)): ?>
                        <button type="button" class="btn btn-success fw-bold rounded-pill px-4 shadow-sm flex-fill flex-lg-grow-0" data-bs-toggle="modal" data-bs-target="#modalAlta">
                            <i class="bi bi-person-plus-fill me-1"></i> NUEVO
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card card-custom border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 tabla-movil-ajustada">
                <thead class="bg-light small text-uppercase text-muted">
                    <tr>
                        <th class="ps-4 py-3">Miembro</th>
                        <th>Rango</th>
                        <th>Contacto</th>
                        <th>Estado</th>
                        <th class="text-end pe-4">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($usuarios)): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">No se encontraron usuarios con los filtros aplicados.</td></tr>
                    <?php else: ?>
                        <?php foreach($usuarios as $user): 
                            $foto = !empty($user['foto_perfil']) ? 'uploads/'.$user['foto_perfil'] : 'img/no-image.png';
                            // FIX DEFINITIVO: ENCRIPTAMOS EN BASE 64 PARA NO ROMPER EL HTML
                            $json_base64 = base64_encode(json_encode($user));
                        ?>
                        <tr data-usuario="<?php echo $json_base64; ?>" onclick="verFichaUsuario(this)" style="cursor:pointer;">
                            <td class="ps-4">
                                <div class="d-flex align-items-center">
                                    <img src="<?php echo $foto; ?>" class="avatar-circle me-2" style="width:35px; height:35px; border-radius:50%; object-fit:cover;">
                                    <div>
                                        <div class="fw-bold text-dark lh-1 mb-1"><?php echo htmlspecialchars($user['nombre_completo']); ?></div>
                                        <div class="text-muted small">@<?php echo htmlspecialchars($user['usuario']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge <?php echo $user['id_rol'] == 1 ? 'bg-danger' : 'bg-primary'; ?> bg-opacity-10 text-<?php echo $user['id_rol'] == 1 ? 'danger' : 'primary'; ?> rounded-pill border" style="font-size: 0.65rem;"><?php echo strtoupper($user['nombre_rol']); ?></span></td>
                            <td>
                                <div class="small fw-bold text-dark"><i class="bi bi-whatsapp text-success me-1"></i><?php echo htmlspecialchars($user['whatsapp'] ?: '-'); ?></div>
                                <div class="text-muted small" style="font-size: 0.7rem;"><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($user['email'] ?: 'Sin email'); ?></div>
                            </td>
                            <td>
                                <span class="fw-bold small <?php echo $user['activo'] == 1 ? 'text-success' : 'text-danger'; ?>">
                                    <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i> <?php echo $user['activo'] == 1 ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex justify-content-end gap-2">
                                    <?php if($es_admin || in_array('config_gestionar_usuarios', $permisos)): ?>
                                        <button type="button" onclick="event.stopPropagation(); abrirEditar(this)" class="btn btn-sm btn-light text-warning rounded-circle shadow-sm" title="Editar">
                                            <i class="bi bi-pencil-fill"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if($es_admin || in_array('config_gestionar_usuarios', $permisos)): ?>
                                        <a href="usuarios.php?borrar=<?php echo $user['id']; ?>" class="btn btn-sm btn-light text-danger rounded-circle shadow-sm" onclick="event.stopPropagation(); confirmarEliminacion(event, this.href)" title="Eliminar">
                                            <i class="bi bi-trash-fill"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAlta" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header bg-primary text-white py-3" style="border-radius: 20px 20px 0 0;">
                <h6 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2"></i> NUEVO INTEGRANTE</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                    <div class="mb-3"><label class="small fw-bold">Nombre y Apellido</label><input type="text" name="nombre" id="alta_nombre" class="form-control" placeholder="Ej: Federico González" required></div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="small fw-bold">Usuario <small class="text-muted">(Autogenerado)</small></label><input type="text" name="usuario" id="alta_usuario" class="form-control" required></div>
                        <div class="col-6"><label class="small fw-bold">Contraseña</label><input type="password" name="password" class="form-control" required></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="small fw-bold">Correo Electrónico</label><input type="email" name="email" class="form-control" placeholder="ejemplo@correo.com"></div>
                        <div class="col-6"><label class="small fw-bold">WhatsApp</label><input type="text" name="whatsapp" class="form-control" placeholder="+54 9..."></div>
                    </div>
                    <div class="mb-4">
                        <label class="small fw-bold">Rol en el Sistema</label>
                        <select name="id_rol" class="form-select" required>
                            <option value="">Seleccione un rol...</option>
                            <?php foreach($roles_db as $r): ?><option value="<?php echo $r['id']; ?>"><?php echo $r['nombre']; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="crear_usuario" class="btn btn-primary w-100 fw-bold py-2 rounded-pill">REGISTRAR USUARIO</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header bg-dark text-white py-3" style="border-radius: 20px 20px 0 0;">
                <h6 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i> EDITAR PERFIL</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>"><input type="hidden" name="id_usuario_edit" id="edit_id">
                    <div class="mb-3"><label class="small fw-bold">Nombre y Apellido</label><input type="text" name="nombre" id="edit_nombre" class="form-control" required></div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="small fw-bold text-muted">Usuario</label><input type="text" name="usuario" id="edit_usuario" class="form-control bg-light" readonly></div>
                        <div class="col-6"><label class="small fw-bold text-danger">Contraseña</label><input type="password" name="password" class="form-control" placeholder="Vacío para no cambiar"></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="small fw-bold">Correo Electrónico</label><input type="email" name="email" id="edit_email" class="form-control"></div>
                        <div class="col-6"><label class="small fw-bold">WhatsApp</label><input type="text" name="whatsapp" id="edit_whatsapp" class="form-control"></div>
                    </div>
                    <div class="row g-2 mb-4">
                        <div class="col-6">
                            <label class="small fw-bold">Rol</label>
                            <select name="id_rol" id="edit_rol" class="form-select" required>
                                <?php foreach($roles_db as $r): ?><option value="<?php echo $r['id']; ?>"><?php echo $r['nombre']; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold">Estado</label>
                            <select name="activo" id="edit_activo" class="form-select">
                                <option value="1">Activo</option>
                                <option value="0">Inactivo</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="editar_usuario" class="btn btn-dark w-100 fw-bold py-2 rounded-pill">GUARDAR CAMBIOS</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDescargas" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-danger text-white border-0 py-3">
                <h6 class="modal-title fw-bold"><i class="bi bi-cloud-download-fill me-2"></i> Exportación</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <button onclick="generarReportePDF()" class="btn border border-2 border-dark text-dark w-100 fw-bold py-3 rounded-pill shadow-sm" data-bs-dismiss="modal" style="background: #f8f9fa;">
                    <i class="bi bi-file-earmark-pdf me-2 fs-5 align-middle"></i> DIRECTORIO PDF
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<script>
    const miLocal = <?php echo json_encode($conf); ?>;
    const datosFirma = <?php echo json_encode($dueno_firma); ?>;
    
    // AUTOCOMPLETADO USUARIO 
    document.getElementById('alta_nombre').addEventListener('blur', function() {
        let nombreCompleto = this.value.trim();
        if(nombreCompleto.length < 3) return;
        let limpio = nombreCompleto.normalize("NFD").replace(/[\u0300-\u036f]/g, "");
        let partes = limpio.split(/\s+/);
        let baseUser = partes.length >= 2 ? partes[0].charAt(0) + partes[1] : partes[0];
        if(baseUser !== "") {
            fetch('usuarios.php?action=check_user&u=' + encodeURIComponent(baseUser))
            .then(r => r.text())
            .then(userLibre => { document.getElementById('alta_usuario').value = userLibre; });
        }
    });

    // LÓGICA SEGURA PARA LEER DATOS
    function obtenerDatosFila(btnElement) {
        let tr = btnElement.closest('tr');
        let dataString = tr.getAttribute('data-usuario');
        return JSON.parse(atob(dataString)); 
    }

    // ACCIONES DE EDICIÓN Y ELIMINACIÓN
    function abrirEditar(btnElement) {
        let user = obtenerDatosFila(btnElement);
        document.getElementById('edit_id').value = user.id;
        document.getElementById('edit_nombre').value = user.nombre_completo;
        document.getElementById('edit_usuario').value = user.usuario;
        document.getElementById('edit_email').value = user.email || '';
        document.getElementById('edit_whatsapp').value = user.whatsapp || '';
        document.getElementById('edit_rol').value = user.id_rol;
        document.getElementById('edit_activo').value = user.activo;
        
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditar')).show();
    }

    function confirmarEliminacion(e, url) {
        e.preventDefault();
        Swal.fire({
            title: '¿Eliminar Usuario?',
            text: "Se borrará su acceso y credenciales del sistema.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) window.location.href = url;
        });
    }

            // --- MODAL TIPO CREDENCIAL / ID CARD ---
    function verFichaUsuario(trElement) {
        let dataString = trElement.getAttribute('data-usuario');
        if (!dataString) return;
        
        let u = JSON.parse(atob(dataString)); 

        // BLOQUEO DE SEGURIDAD SI NO HAY FOTO DE PERFIL
        if (!u.foto_perfil || u.foto_perfil.trim() === '') {
            Swal.fire({
                icon: 'warning',
                title: 'Falta Foto de Perfil',
                text: 'El empleado no tiene foto asignada. Modificá el perfil primero para generar la credencial oficial.',
                confirmButtonColor: '#102A57'
            });
            return;
        }

        let foto = 'uploads/' + u.foto_perfil;
        
        // RUTAS EXACTAS DE VALIDACIÓN Y DESCARGA
        let linkPdfPublico = window.location.origin + "/credencial_pdf.php?id=" + u.id;
        let linkValidacion = window.location.origin + "/validar_credencial.php?id=" + u.id;
        let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=0&data=` + encodeURIComponent(linkValidacion);
        
        // DATOS DE LA FIRMA AUTORIZADA
        let idAutoridad = datosFirma ? datosFirma.id : null;
        let nombreAutoridad = datosFirma && datosFirma.nombre_completo ? datosFirma.nombre_completo.toUpperCase() : 'GERENCIA';
        let firmaAutoridad = idAutoridad ? `img/firmas/usuario_${idAutoridad}.png` : `img/firmas/firma_admin.png`;
        let rolAutoridad = datosFirma && datosFirma.nombre_rol ? datosFirma.nombre_rol.toUpperCase() : 'DIRECCIÓN';

        let btnWaHTML = `<button class="btn btn-sm btn-success fw-bold shadow-sm" onclick="mandarWA('${u.nombre_completo}', '${u.whatsapp || ''}', '${linkPdfPublico}')"><i class="bi bi-whatsapp"></i> WA</button>`;
        // PATRÓN DE ÍCONOS
        let iconosArray = ['🧉', '💊', '🛒', '🇦🇷', '⚽', '🏪', '⭐', '🥟', '🏥', '🥐', '🥩', '🍷', '🎸', '🚌'];
        let posiciones = [
            {t:'-2', l:'5', s:'6'}, {t:'4', l:'35', s:'10'}, {t:'12', l:'-2', s:'8'}, {t:'15', l:'45', s:'5'},
            {t:'25', l:'8', s:'12'}, {t:'28', l:'42', s:'7'}, {t:'38', l:'-2', s:'9'}, {t:'42', l:'48', s:'6'},
            {t:'52', l:'2', s:'11'}, {t:'58', l:'40', s:'8'}, {t:'68', l:'-1', s:'5'}, {t:'72', l:'45', s:'10'},
            {t:'80', l:'10', s:'7'}, {t:'22', l:'38', s:'9'}, {t:'5', l:'20', s:'6'}, {t:'35', l:'25', s:'8'},
            {t:'48', l:'22', s:'12'}, {t:'65', l:'20', s:'7'}, {t:'82', l:'30', s:'9'}, {t:'-3', l:'25', s:'11'},
            {t:'18', l:'20', s:'5'}, {t:'75', l:'35', s:'8'}, {t:'85', l:'40', s:'6'}, {t:'10', l:'50', s:'9'},
            {t:'30', l:'15', s:'6'}, {t:'55', l:'28', s:'10'}, {t:'40', l:'15', s:'5'}, {t:'85', l:'5', s:'12'},
            {t:'60', l:'10', s:'8'}, {t:'20', l:'50', s:'7'}
        ];
        let iconosBg = '';
        posiciones.forEach((pos, index) => {
            let icono = iconosArray[index % iconosArray.length];
            iconosBg += `<div style="position: absolute; top: ${pos.t}mm; left: ${pos.l}mm; font-size: ${pos.s}px; opacity: 0.12; z-index: 1;">${icono}</div>`;
        });

        // FORMATEO DE DATOS SEGUROS
        let fechaEmision = u.fecha_creacion ? u.fecha_creacion.split(' ')[0].split('-').reverse().join('/') : new Date().toLocaleDateString('es-AR');
        let fechaNacimiento = u.fecha_nacimiento ? u.fecha_nacimiento.split('-').reverse().join('/') : 'S/D';
        let domCompleto = (u.domicilio || 'S/D') + (u.codigo_postal ? ` (CP ${u.codigo_postal})` : '');
        let nombreUsr = u.nombre_completo ? u.nombre_completo.toUpperCase() : 'S/D';
        let aliasUsr = u.usuario ? u.usuario.toLowerCase() : 's/d';
        let rolUsr = u.nombre_rol ? u.nombre_rol.toUpperCase() : 'S/D';

        let html = `
        <div style="display: flex; justify-content: center; background: transparent; padding-top: 10px;">
            <div id="credencial-preview" style="width: 54mm; height: 86mm; background: #ffffff; position: relative; overflow: hidden; font-family: 'Arial', sans-serif; box-sizing: border-box; border: 1px solid #ddd; text-align: left; transform: scale(1.2); transform-origin: top center; margin-bottom: 20px;">
                
                ${iconosBg}
                <img src="img/sol_de_mayo.png" style="position: absolute; top: 15mm; left: -5mm; width: 64mm; height: 64mm; opacity: 0.15; z-index: 1; object-fit: contain;" onerror="this.style.display='none'">

                <div style="position: absolute; top: -20mm; left: -15mm; width: 84mm; height: 45mm; background-color: #74ACDF; border-radius: 50%; z-index: 2;"></div>
                <div style="position: absolute; bottom: -20mm; left: -15mm; width: 84mm; height: 35mm; background-color: #74ACDF; border-radius: 50%; z-index: 2;"></div>

                <div style="position: absolute; top: 3.5mm; left: 0; width: 100%; text-align: center; z-index: 10; color: #ffffff; font-size: 4.5px; font-weight: 900; letter-spacing: 1.5px; text-shadow: 0.5px 0.5px 1px rgba(0,0,0,0.3);">
                    CREDENCIAL DE IDENTIFICACIÓN
                </div>

                <div style="position: absolute; top: 7.5mm; left: 0; width: 100%; text-align: center; z-index: 10;">
                    <img src="${foto}" style="width: 18mm; height: 18mm; border-radius: 50%; border: 1.5px solid #fff; object-fit: cover; box-shadow: 0 2px 4px rgba(0,0,0,0.2); background: #fff;">
                    <div style="margin-top: 1mm; font-size: 6px; font-weight: 900; color: #000; text-transform: uppercase; letter-spacing: -0.2px; line-height: 1;">${nombreUsr}</div>
                    <div style="font-size: 4px; color: #555; font-weight: bold; margin-top: 0.5mm; line-height: 1;">@${aliasUsr}</div>
                    <div style="font-size: 4px; color: #102A57; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; margin-top: 0.5mm; line-height: 1; background: rgba(255,255,255,0.8); display: inline-block; padding: 0.3mm 1.5mm; border-radius: 0.5mm;">${rolUsr}</div>
                </div>

                <div style="position: absolute; top: 33mm; left: 3mm; width: 48mm; z-index: 10;">
                    <div style="background: rgba(255,255,255,0.95); border: 0.5px solid #74ACDF; border-radius: 1mm; padding: 1mm; box-shadow: 0 1px 3px rgba(0,0,0,0.1); font-size: 3.2px; line-height: 1.2; text-align: left; color: #333;">
                        
                        <div style="display: flex; gap: 0.5mm; margin-bottom: 0.5mm;">
                            <div style="flex: 1; background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm;"><span style="font-weight: bold; color: #102A57;">ID:</span> EMP-${String(u.id).padStart(4, '0')}</div>
                            <div style="flex: 1; background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm;"><span style="font-weight: bold; color: #102A57;">DNI:</span> ${u.dni || 'S/D'}</div>
                            <div style="flex: 1; background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm;"><span style="font-weight: bold; color: #102A57;">CUIL:</span> ${u.cuil || 'S/D'}</div>
                        </div>
                        
                        <div style="display: flex; gap: 0.5mm; margin-bottom: 0.5mm;">
                            <div style="flex: 1; background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm;"><span style="font-weight: bold; color: #102A57;">NAC:</span> ${fechaNacimiento}</div>
                            <div style="flex: 1; background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm;"><span style="font-weight: bold; color: #102A57;">TALLA:</span> ${u.talla_uniforme || 'S/D'}</div>
                            <div style="flex: 1; background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm;"><span style="font-weight: bold; color: #102A57;">E.C:</span> ${u.estado_civil || 'S/D'}</div>
                        </div>

                        <div style="display: flex; gap: 0.5mm; margin-bottom: 0.5mm;">
                            <div style="flex: 1; background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm;"><span style="font-weight: bold; color: #102A57;">TEL:</span> ${u.whatsapp || 'S/D'}</div>
                            <div style="flex: 1.5; background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm; white-space: nowrap; overflow: hidden;"><span style="font-weight: bold; color: #102A57;">EMAIL:</span> ${u.email || 'S/D'}</div>
                        </div>

                        <div style="background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm; margin-bottom: 0.5mm;">
                            <span style="font-weight: bold; color: #102A57;">DOM:</span> ${domCompleto}
                        </div>

                        <div style="display: flex; gap: 0.5mm; margin-bottom: 0.5mm; color: #d32f2f;">
                            <div style="width: 30%; background: #ffebee; padding: 0.3mm; border-radius: 0.5mm;"><span style="font-weight: bold;">GS:</span> ${u.grupo_sanguineo || 'S/D'}</div>
                            <div style="width: 70%; background: #ffebee; padding: 0.3mm; border-radius: 0.5mm; white-space: nowrap; overflow: hidden;"><span style="font-weight: bold;">ALERGIAS:</span> ${u.alergias ? u.alergias.toUpperCase() : 'NINGUNA'}</div>
                        </div>

                        <div style="background: #ffebee; padding: 0.3mm; border-radius: 0.5mm; color: #d32f2f;">
                            <span style="font-weight: bold;">EMERGENCIA:</span> ${u.contacto_emergencia || 'S/D'}
                        </div>

                    </div>
                </div>

                <div style="position: absolute; bottom: 14mm; left: 0; width: 100%; text-align: center; z-index: 10;">
                    <div style="font-size: 3.2px; color: #333; font-weight: bold; margin-bottom: 0.5mm;">VALIDADA DESDE: ${fechaEmision}</div>
                    
                    <img src="${firmaAutoridad}" style="max-height: 16mm; position: relative; margin-bottom: -3.5mm; z-index: 2;" onerror="this.style.display='none'">
                    
                    <div style="border-top: 1px solid #102A57; width: 38mm; margin: 0 auto; position: relative; z-index: 1;"></div>
                    
                    <div style="font-size: 5px; font-weight: 900; color: #102A57; padding-top: 0.8mm; line-height: 1; margin-bottom: 0;">${nombreAutoridad}</div>
                    <div style="margin-top: -0.5mm;"><span style="font-size: 3.5px; color: #fff; background: #102A57; display: inline-block; padding: 0.2mm 2mm; border-radius: 0.5mm; letter-spacing: 0.5px; font-weight: bold; line-height: 1;">${rolAutoridad}</span></div>
                </div>

                <img src="img/malvinas.png" style="position: absolute; bottom: 1.5mm; left: 3mm; width: 12mm; height: auto; opacity: 0.9; z-index: 10;" onerror="this.style.display='none'">
                
                <div style="position: absolute; bottom: 1.5mm; left: 50%; transform: translateX(-50%); text-align: center; z-index: 10;">
                    <img src="${qrUrl}" style="width: 11mm; height: 11mm; background: #fff; padding: 0.5mm; border-radius: 1mm; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                </div>

                <div style="position: absolute; bottom: 2.5mm; right: 3mm; display: flex; align-items: flex-end; justify-content: center; z-index: 10; opacity: 0.9; width: 12mm;">
                    <img src="img/estrella.png" style="width: 3.5mm; height: auto; margin-bottom: 0.8mm; margin-right: -0.8mm; z-index: 1;" onerror="this.style.display='none'">
                    <img src="img/estrella.png" style="width: 5.5mm; height: auto; z-index: 2;" onerror="this.style.display='none'">
                    <img src="img/estrella.png" style="width: 3.5mm; height: auto; margin-bottom: 0.8mm; margin-left: -0.8mm; z-index: 1;" onerror="this.style.display='none'">
                </div>

            </div>
        </div>
        <div class="d-flex justify-content-center gap-2 mt-4 pt-3 border-top no-print w-100">
            <button class="btn btn-sm btn-danger fw-bold shadow-sm" onclick="window.open('${linkPdfPublico}', '_blank')"><i class="bi bi-file-earmark-pdf-fill"></i> PDF</button>
            ${btnWaHTML}
            <button class="btn btn-sm btn-primary fw-bold shadow-sm" onclick="mandarMail(${u.id}, '${u.email || ''}')"><i class="bi bi-envelope"></i> EMAIL</button>
        </div>
        `;
        
        Swal.fire({ html: html, width: 330, showConfirmButton: false, showCloseButton: true, padding: '15px' });
    }


        // --- EMAIL QUE CLONA LA CREDENCIAL DE 54x86mm EXACTA ---
    function mandarMail(id, emailActual) {
        Swal.fire({
            title: 'Enviar Credencial', text: 'Confirmá el correo electrónico:',
            input: 'email', inputValue: emailActual, inputPlaceholder: 'empleado@correo.com',
            showCancelButton: true, confirmButtonText: 'Enviar', confirmButtonColor: '#102A57'
        }).then((r) => {
            if(r.isConfirmed && r.value) {
                let correoDestino = r.value;
                Swal.fire({ title: 'Procesando Tarjeta CR80...', text: 'Generando formato exacto para impresión.', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); }});
                
                // Pedimos el HTML "crudo" de la credencial al archivo PHP
                fetch(window.location.origin + "/credencial_pdf.php?id=" + id + "&raw=1")
                .then(res => res.text())
                .then(html => {
                    // Creamos un contenedor invisible en la pantalla
                    let container = document.createElement('div');
                    container.innerHTML = html;
                    container.style.position = 'absolute';
                    container.style.left = '-9999px';
                    document.body.appendChild(container);

                    // Configuración exacta CR80
                    const opt = {
                        margin: 0,
                        filename: 'Credencial_Oficial.pdf',
                        image: { type: 'jpeg', quality: 1 },
                        html2canvas: { scale: 4, useCORS: true },
                        jsPDF: { unit: 'mm', format: [54, 86], orientation: 'portrait' }
                    };

                    // Generamos el Blob (PDF) desde ese contenedor invisible
                    html2pdf().set(opt).from(container.firstElementChild).output('blob').then(function(blob) {
                        Swal.fire({ title: 'Enviando Email...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); }});
                        
                        let fData = new FormData(); 
                        fData.append('id', id); 
                        fData.append('email', correoDestino);
                        fData.append('pdf_file', blob, 'Credencial_EMP.pdf');
                        
                        fetch('acciones/enviar_email_credencial.php', { method: 'POST', body: fData })
                        .then(res => res.json())
                        .then(d => {
                            document.body.removeChild(container); // Limpiamos la basura invisible
                            if(d.status === 'success') Swal.fire('¡Enviado!', 'La credencial oficial fue entregada.', 'success');
                            else Swal.fire('Error', d.msg, 'error');
                        }).catch(e => Swal.fire('Error', e.message, 'error'));
                    });
                });
            }
        });
    }

    
    function mandarWA(nombre, telefono, link) {
        let telLimpio = telefono.replace(/[^0-9]/g, '');
        if(!telLimpio) {
            Swal.fire({
                title: 'WhatsApp no registrado',
                text: 'Ingresá el número al que querés enviarlo:',
                input: 'text',
                inputPlaceholder: 'Ej: 5491123456789',
                showCancelButton: true,
                confirmButtonText: 'Abrir Chat',
                confirmButtonColor: '#25D366'
            }).then((r) => {
                if(r.isConfirmed && r.value) {
                    abrirWaLink(nombre, r.value.replace(/[^0-9]/g, ''), link);
                }
            });
        } else {
            abrirWaLink(nombre, telLimpio, link);
        }
    }

    function abrirWaLink(nombre, tel, link) {
        let msj = `¡Hola ${nombre}!\nSe ha generado o actualizado tu credencial de acceso para el sistema de *${miLocal.nombre_negocio}*.\n\n📄 Ver credencial digital:\n${link}`;
        window.open(`https://wa.me/${tel}?text=${encodeURIComponent(msj)}`, '_blank');
    }
    
    // --- EMAIL QUE ADJUNTA EL PDF CLONADO ---
    

    function abrirModalDescargas() { new bootstrap.Modal(document.getElementById('modalDescargas')).show(); }

    // Alertas POST
    const up = new URLSearchParams(window.location.search);
    if(up.get('msg') === 'creado') Swal.fire({ icon: 'success', title: 'Usuario Registrado', timer: 1500, showConfirmButton: false });
    if(up.get('msg') === 'editado') Swal.fire({ icon: 'success', title: 'Perfil Actualizado', timer: 1500, showConfirmButton: false });
    if(up.get('msg') === 'eliminado') Swal.fire({ icon: 'success', title: 'Usuario Eliminado', timer: 1500, showConfirmButton: false });
</script>

<?php include 'includes/layout_footer.php'; ?>